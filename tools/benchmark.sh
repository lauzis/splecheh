#!/usr/bin/env bash
#
# Benchmarks tools/llm-wrapper.php across providers/models using the same
# canned example sentences as the Settings page "Test Interpunction Check"
# button, so you can compare speed/quality (e.g. claude vs a local qwen
# model) side by side from the terminal.
#
# Usage:
#   tools/benchmark.sh [target ...]
#
# Each target is one of:
#   claude
#   ollama:<model>
#
# Default targets (if none given): claude, ollama:qwen2.5:7b
#
# Each ollama target is warmed (loaded into memory) before its timed run, so
# results measure generation speed rather than model-load time — Ollama only
# keeps one model resident by default, so back-to-back different models would
# otherwise evict each other and skew whichever runs second.
#
# Examples:
#   tools/benchmark.sh
#   tools/benchmark.sh claude ollama:qwen2.5:7b ollama:qwen2.5:32b
#   tools/benchmark.sh ollama:qwen2.5:3b ollama:qwen2.5:7b

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WRAPPER="${SCRIPT_DIR}/llm-wrapper.php"
OLLAMA_HOST_URL="${SPLECHEH_OLLAMA_HOST:-http://127.0.0.1:11434}"

# Same canned sentences as Splecheh_InterpunctionBackend::TEST_SENTENCES and
# a prompt matching the Settings page default, so results are comparable to
# what the "Test Interpunction Check" button would show.
PAYLOAD='{"language":"en","prompt":"You are a professional en editor. Your only task is to fix the punctuation and capitalization of the provided text. Keep the original text content exactly as is. Output only the corrected text.","sentences":["the quick brown fox jumps over the lazy dog","is this correct  ,she asked","we visited paris,london and berlin last summer"]}'

TARGETS=("$@")
if [ ${#TARGETS[@]} -eq 0 ]; then
	TARGETS=(claude "ollama:qwen2.5:7b")
fi

SUMMARY=()

for target in "${TARGETS[@]}"; do
	provider="${target%%:*}"
	model=""
	if [[ "$target" == *:* ]]; then
		model="${target#*:}"
	fi

	args=(--provider "$provider")
	if [ -n "$model" ]; then
		args+=(--model "$model")
	fi

	if [ "$provider" = "ollama" ] && [ -n "$model" ]; then
		echo "=== ${target} (warming) ==="
		curl -fsS --max-time 300 "${OLLAMA_HOST_URL}/api/generate" \
			-H 'Content-Type: application/json' \
			-d "$(printf '{"model":"%s"}' "$model")" \
			>/dev/null || echo "warm-up request failed, timing a cold load instead" >&2
	fi

	echo "=== ${target} ==="
	start=$(date +%s.%N)
	if output=$(php "$WRAPPER" "${args[@]}" "$PAYLOAD" 2>&1); then
		status="ok"
	else
		status="FAILED"
	fi
	end=$(date +%s.%N)
	elapsed=$(awk -v a="$start" -v b="$end" 'BEGIN { printf "%.1f", b - a }')

	echo "$output"
	echo "--- ${elapsed}s (${status}) ---"
	echo

	SUMMARY+=("$(printf '%-24s %8ss  %s' "$target" "$elapsed" "$status")")
done

echo "=== Summary ==="
for line in "${SUMMARY[@]}"; do
	echo "$line"
done
