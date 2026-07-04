# Interpunction Check tools

These scripts implement Splecheh's Commandline contract for the Interpunction
Check feature (Settings > Interpunction Check > Type = "Commandline - Local
model"). See the main [README.md](../README.md#commandline-contract) for the
contract itself.

- **`llm-wrapper.php`** — the command you point Settings > Interpunction Check
  > Commandline Command at. Sends each batch of sentences to either the
  `claude` CLI or a local Ollama model and returns the required JSON.
- **`local-model.sh`** — starts/stops a local Ollama server for
  `llm-wrapper.php --provider ollama` to talk to, without needing root or a
  systemd service.

## Option A: Claude Code CLI (default, no local model)

This is the existing default and needs no setup beyond having the `claude`
CLI installed and authenticated:

```
Commandline Command: php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php
```

If `claude` isn't on the PHP-FPM pool's `PATH` (it usually isn't — FPM
workers don't inherit your shell's `PATH`), set its absolute path via the
`SPLECHEH_CLAUDE_BIN` env var in the pool config, e.g.:

```ini
env[SPLECHEH_CLAUDE_BIN] = /home/youruser/.local/bin/claude
```

### Timeouts and chunking for real posts, not just test batches

The Settings page "Test" button and `tools/benchmark.sh` only send 2-3 canned
sentences, which is fast. A real post can have dozens of sentences, and each
one costs real generation time — measured on this project's dev machine, a
real 5-sentence batch via `claude` took 150-200s (much slower than short
canned test sentences), and a single call for a whole 50+ sentence post
consistently exceeded even a 300s timeout. So Splecheh sends a post's
sentences in **chunks** (`Splecheh_InterpunctionBackend::check()`) — several
calls per post instead of one, merging the results. Set the chunk size via
Settings > Interpunction Check > **Sentence Chunk Size** (default 5, 0
disables chunking), or the `splecheh_interpunction_chunk_size` filter, which
takes precedence over the Settings field. Lower it further if individual
calls still time out on your setup; raising it reduces the number of calls
but makes each one riskier.

Two timeouts must both be raised together to comfortably cover one chunk's
call, or the request gets killed before it finishes:

1. **`llm-wrapper.php`'s own timeout** — simplest is the `--timeout <seconds>`
   flag on the Commandline Command itself (no server config needed), e.g.
   `php .../tools/llm-wrapper.php --timeout 300`. Or set it via env var:
   `SPLECHEH_CLAUDE_TIMEOUT` (default 55s) for the `claude` provider,
   `SPLECHEH_OLLAMA_TIMEOUT` (default 300s) for the `ollama` provider —
   `--timeout` wins if both are set. Env vars go in the PHP-FPM pool config:

   ```ini
   env[SPLECHEH_CLAUDE_TIMEOUT] = 300
   ```

2. **Splecheh's own command timeout** — defaults to 60s, raised via the
   `splecheh_interpunction_command_timeout` filter (in a small mu-plugin, or
   your theme's `functions.php`):

   ```php
   add_filter( 'splecheh_interpunction_command_timeout', fn() => 300 ); // seconds
   ```

Keep `llm-wrapper.php`'s own timeout slightly **below** Splecheh's, so a
genuinely stuck request surfaces a clear "exceeded the timeout" error from
the wrapper itself instead of being killed by Splecheh first with a less
specific message. Note the total time for a whole post is roughly
`(sentences / chunk size) × time per chunk` — a 50-sentence post at 5 per
chunk and ~150-200s per chunk can still take 25+ minutes end to end; that's
an inherent cost of the model being slow per-sentence on CPU/CLI, not
something chunking alone fixes — see "Option B" below for faster local
models if that total time is a problem.

## Option B: Local model via Ollama

Use this if you'd rather run the check against a model on your own server
instead of calling out to `claude`.

### 1. Install Ollama and pull a model

```
curl -fsSL https://ollama.com/install.sh | sh
ollama pull qwen2.5:7b
```

**Pick a model that's actually fast enough, not just one that fits in RAM.**
Interpunction Check needs a real response per batch of sentences, and without
a GPU with enough VRAM to hold the model, generation runs on CPU and can be
far too slow to be usable. Measured on this project's dev machine (Intel
i7-9750H, 6c/12t, GTX 1650 4GB — too little VRAM to offload any layers of
these models, so 100% CPU-bound):

| Model | Disk | 2-sentence Interpunction Check request |
|---|---|---|
| `qwen2.5:7b` | ~5GB | **~15s** — practical |
| `qwen2.5:32b` | ~20GB | **>280s per request, ~8.6 sec/token** — not usable for this feature |

`qwen2.5:7b` is the default `--model` in both scripts. Bigger models are fine
for general chat where you don't mind waiting, but for Interpunction Check
(called synchronously per batch of sentences) stick to a size your CPU can
actually generate at a reasonable rate — benchmark on your own hardware
before committing to a model, since GPU VRAM and core count both matter a
lot here.

**These Qwen models are more proof-of-concept than production-ready.**
Benchmarking so far has focused on speed, using short English example
sentences — output quality on real content in smaller/less-common languages
hasn't been specifically verified and could be worse than `claude` or a
hosted API. Test against your own posts (Settings > Interpunction Check >
Test button, or `tools/benchmark.sh`) before relying on a local model for a
non-English site.

The official installer registers Ollama as a systemd service that
auto-starts on boot. If you'd rather control start/stop yourself (e.g. to
free RAM when Interpunction Check isn't running), disable that and use
`local-model.sh` instead:

```
sudo systemctl disable --now ollama
```

(If you skip this, `local-model.sh start` will detect the already-running
service and just reuse it — see below.)

### 2. Start the model server

```
tools/local-model.sh start --model qwen2.5:7b
```

This launches `ollama serve` in the background, waits for it to respond,
saves its PID to `tools/.run/ollama.pid`, and loads (`warm`s) the given
model into memory so the first real request isn't slowed down by a cold
load (which can take minutes for larger models on CPU).

If something is already answering on the target host (e.g. Ollama is still
running as a systemd service), `start` reuses it instead of spawning a
second server, and `stop` will refuse to kill it — use `systemctl stop
ollama` for that instead.

Other subcommands:

```
tools/local-model.sh status              # is it running, which PID, which models are loaded
tools/local-model.sh warm --model NAME   # pre-load an additional/different model
tools/local-model.sh restart --model NAME
tools/local-model.sh stop
```

### 3. Point Splecheh at it

```
Commandline Command: php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php --provider ollama --model qwen2.5:7b
```

### 4. Raise the command timeout

Local CPU inference is much slower than a hosted API — see "Timeouts for
real posts, not just test batches" above; you'll almost certainly need to
raise both `SPLECHEH_OLLAMA_TIMEOUT` and `splecheh_interpunction_command_timeout`
for anything beyond a couple of sentences.

### Switching models later

Change `--model` in the Commandline Command field and pull the new model if
you haven't already (`ollama pull <name>`); no restart of the server itself
is needed — Ollama loads whichever model a request asks for. Use
`local-model.sh warm --model NAME` beforehand if you want to avoid the cold
load on the first real check.

### Env var reference (all optional)

| Var | Used by | Default | Purpose |
|---|---|---|---|
| `SPLECHEH_CLAUDE_BIN` | `llm-wrapper.php` | `/home/lauzis/.local/bin/claude` | Path to the `claude` CLI |
| `SPLECHEH_CLAUDE_TIMEOUT` | `llm-wrapper.php` | `55` (seconds) | Max time to wait for a `claude` response — raise for real posts |
| `SPLECHEH_OLLAMA_HOST` | both scripts | `http://127.0.0.1:11434` | Ollama server URL |
| `SPLECHEH_OLLAMA_MODEL` | both scripts | `qwen2.5:7b` | Default model when `--model` is omitted |
| `SPLECHEH_OLLAMA_KEEP_ALIVE` | both scripts | `10m` | How long Ollama keeps the model loaded after a request |
| `SPLECHEH_OLLAMA_TIMEOUT` | `llm-wrapper.php` | `300` (seconds) | Max time to wait for an Ollama response |

Set these in the PHP-FPM pool config (`env[...] = ...`) so `llm-wrapper.php`
picks them up; `local-model.sh` reads them from your shell environment when
you run it directly.
