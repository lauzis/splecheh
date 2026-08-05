#!/usr/bin/env bash
#
# Starts/stops a local Ollama server for use with tools/llm-wrapper.php
# (--provider ollama), without depending on Ollama being installed as a
# systemd service.
#
# Usage:
#   tools/local-model.sh start [--model NAME] [--keep-alive DURATION]
#   tools/local-model.sh stop
#   tools/local-model.sh restart [--model NAME] [--keep-alive DURATION]
#   tools/local-model.sh status
#   tools/local-model.sh warm --model NAME [--keep-alive DURATION]
#
# "start" launches `ollama serve` in the background, waits for it to answer,
# and saves its PID so "stop" can find and kill it later. If a server is
# already answering on the target host (e.g. a systemd-managed instance),
# start/warm reuse it instead of spawning a second one, and stop leaves it
# alone (it prints a note instead of killing a process it doesn't own).
#
# Env overrides (matching the SPLECHEH_OLLAMA_* vars read by llm-wrapper.php):
#   SPLECHEH_OLLAMA_HOST         default: http://127.0.0.1:11434
#   SPLECHEH_OLLAMA_MODEL        default: qwen2.5:7b (used by "warm" and by
#                                 "start --model" when --model is omitted)
#   SPLECHEH_OLLAMA_KEEP_ALIVE   default: 10m

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_DIR="${SCRIPT_DIR}/.run"
PID_FILE="${RUN_DIR}/ollama.pid"
LOG_FILE="${RUN_DIR}/ollama.log"

HOST="${SPLECHEH_OLLAMA_HOST:-http://127.0.0.1:11434}"
DEFAULT_MODEL="${SPLECHEH_OLLAMA_MODEL:-qwen2.5:7b}"
KEEP_ALIVE="${SPLECHEH_OLLAMA_KEEP_ALIVE:-10m}"

MODEL=""
START_TIMEOUT=60

usage() {
	grep -E '^#( |$)' "${BASH_SOURCE[0]}" | sed -E 's/^# ?//' | sed -n '2,20p'
	exit 1
}

log() {
	echo "[local-model] $*"
}

require_ollama_binary() {
	if ! command -v ollama >/dev/null 2>&1; then
		echo "Error: 'ollama' is not on PATH. Install it from https://ollama.com first." >&2
		exit 1
	fi
}

is_up() {
	curl -fsS --max-time 3 "${HOST}/api/tags" >/dev/null 2>&1
}

pid_alive() {
	local pid="$1"
	[ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

owned_pid() {
	if [ -f "$PID_FILE" ]; then
		local pid
		pid="$(cat "$PID_FILE")"
		if pid_alive "$pid"; then
			echo "$pid"
			return 0
		fi
		rm -f "$PID_FILE"
	fi
	return 1
}

wait_until_up() {
	local waited=0
	while ! is_up; do
		sleep 1
		waited=$((waited + 1))
		if [ "$waited" -ge "$START_TIMEOUT" ]; then
			echo "Error: ollama serve did not respond at ${HOST} within ${START_TIMEOUT}s. See ${LOG_FILE}." >&2
			return 1
		fi
	done
}

warm_model() {
	local model="$1"
	log "Warming model '${model}' (keep_alive=${KEEP_ALIVE})..."
	curl -fsS --max-time "${OLLAMA_TIMEOUT:-300}" "${HOST}/api/generate" \
		-H 'Content-Type: application/json' \
		-d "$(printf '{"model":"%s","keep_alive":"%s"}' "$model" "$KEEP_ALIVE")" \
		>/dev/null
	log "Model '${model}' loaded."
}

cmd_start() {
	require_ollama_binary
	mkdir -p "$RUN_DIR"

	if owned_pid >/dev/null; then
		log "Already running (PID $(owned_pid), started by this script)."
	elif is_up; then
		log "Ollama is already running at ${HOST} (not started by this script, e.g. a system service) — reusing it."
	else
		log "Starting 'ollama serve' at ${HOST}..."
		OLLAMA_HOST="${HOST#http://}" nohup ollama serve >>"$LOG_FILE" 2>&1 &
		echo $! > "$PID_FILE"
		wait_until_up
		log "Started (PID $(cat "$PID_FILE")). Logs: ${LOG_FILE}"
	fi

	if [ -n "$MODEL" ]; then
		warm_model "$MODEL"
	fi
}

cmd_stop() {
	if owned_pid >/dev/null; then
		local pid
		pid="$(owned_pid)"
		log "Stopping ollama serve (PID ${pid})..."
		kill "$pid" 2>/dev/null || true
		for _ in $(seq 1 10); do
			pid_alive "$pid" || break
			sleep 1
		done
		if pid_alive "$pid"; then
			log "Still running after 10s, sending SIGKILL..."
			kill -9 "$pid" 2>/dev/null || true
		fi
		rm -f "$PID_FILE"
		log "Stopped."
	elif is_up; then
		log "Ollama at ${HOST} is running but wasn't started by this script (e.g. a system service)."
		log "Use 'systemctl stop ollama' (or however it was started) to stop it."
		exit 1
	else
		log "Not running."
	fi
}

cmd_status() {
	if owned_pid >/dev/null; then
		echo "Running, PID $(owned_pid) (started by this script), host ${HOST}."
	elif is_up; then
		echo "Running at ${HOST}, but not started by this script (e.g. a system service)."
	else
		echo "Not running (${HOST} not responding)."
		return 1
	fi
	curl -fsS --max-time 3 "${HOST}/api/ps" 2>/dev/null || true
}

cmd_warm() {
	if [ -z "$MODEL" ]; then
		MODEL="$DEFAULT_MODEL"
	fi
	if ! is_up; then
		echo "Error: ollama is not running at ${HOST}. Run '${0} start' first." >&2
		exit 1
	fi
	warm_model "$MODEL"
}

ACTION="${1:-}"
[ -n "$ACTION" ] && shift || true

while [ $# -gt 0 ]; do
	case "$1" in
		--model)
			MODEL="$2"; shift 2 ;;
		--model=*)
			MODEL="${1#--model=}"; shift ;;
		--keep-alive)
			KEEP_ALIVE="$2"; shift 2 ;;
		--keep-alive=*)
			KEEP_ALIVE="${1#--keep-alive=}"; shift ;;
		*)
			echo "Unknown argument: $1" >&2
			usage ;;
	esac
done

case "$ACTION" in
	start)
		cmd_start ;;
	stop)
		cmd_stop ;;
	restart)
		cmd_stop || true
		cmd_start ;;
	status)
		cmd_status ;;
	warm)
		cmd_warm ;;
	*)
		usage ;;
esac
