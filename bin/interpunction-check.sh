#!/usr/bin/env bash
#
# Reference implementation of the "Commandline" Interpunction Check contract.
#
# Splecheh invokes the command configured in Settings > Interpunction Check
# with a single argument: a JSON object of the shape
#   {"language": "en", "prompt": "...", "sentences": ["First sentence.", "..."]}
#
# The script must print a JSON array on stdout, one item per input sentence
# and in the same order, of the shape
#   [{"original": "...", "fixed": "...", "explanation": "..."}, ...]
# and exit 0. A non-zero exit code is treated as failure, with stderr shown
# as the error message.
#
# This is a dummy example: it just echoes each sentence back unchanged (no
# real LLM call). Replace the body below with a call to your local model or
# to whatever LLM CLI/API you use — this is also the place to keep API keys
# out of WordPress, since the script owns its own credentials.
#
# Requires: python3 (used here only to parse/build JSON; swap for jq or
# anything else your environment has).

set -euo pipefail

payload="${1:-}"

if [ -z "$payload" ]; then
	echo "Usage: $0 '<json payload>'" >&2
	exit 1
fi

python3 - "$payload" <<'PYEOF'
import json
import sys

payload = json.loads(sys.argv[1])
sentences = payload.get("sentences", [])

results = [
	{"original": sentence, "fixed": sentence, "explanation": ""}
	for sentence in sentences
]

print(json.dumps(results))
PYEOF
