#!/usr/bin/env php
<?php
/**
 * Commandline wrapper for Splecheh's Interpunction Check that calls a locally
 * installed CLI (`claude`, `gemini`, or `codex`) or a local Ollama model,
 * instead of a hosted API.
 *
 * Set as the "Commandline Command" in Settings > Interpunction Check:
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php --provider ollama --model qwen2.5:7b
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php --provider gemini
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php --provider codex
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php --timeout 300
 *
 * Splecheh runs this with the configured command plus one appended argument:
 * a JSON object {"language": "...", "prompt": "...", "sentences": ["...", ...]}
 * (see README.md "Commandline contract"). This script must print a JSON
 * array [{"original": "...", "fixed": "...", "explanation": "..."}, ...] to
 * stdout, one item per input sentence in the same order, and exit 0.
 *
 * See tools/README.md for full setup instructions, including how to start
 * a local Ollama model with tools/local-model.sh.
 */

declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Process\Process;

// Absolute path to the claude CLI, overridable via the SPLECHEH_CLAUDE_BIN
// env var (set it in the PHP-FPM pool config, e.g. env[SPLECHEH_CLAUDE_BIN]).
// PHP-FPM workers don't inherit an interactive shell's PATH (e.g. ~/.local/bin),
// so a bare "claude" won't resolve unless PATH is set up for the pool.
define( 'CLAUDE_BIN', getenv( 'SPLECHEH_CLAUDE_BIN' ) ?: '/home/lauzis/.local/bin/claude' );

// Path to the gemini CLI (Gemini CLI), overridable via SPLECHEH_GEMINI_BIN
// for the same PHP-FPM PATH reason as CLAUDE_BIN above.
define( 'GEMINI_BIN', getenv( 'SPLECHEH_GEMINI_BIN' ) ?: 'gemini' );

// Path to the codex CLI (OpenAI Codex CLI), overridable via SPLECHEH_CODEX_BIN
// for the same PHP-FPM PATH reason as CLAUDE_BIN above.
define( 'CODEX_BIN', getenv( 'SPLECHEH_CODEX_BIN' ) ?: 'codex' );

// Ollama API base URL, overridable via SPLECHEH_OLLAMA_HOST (must match the
// host tools/local-model.sh starts the server on).
define( 'OLLAMA_HOST', rtrim( getenv( 'SPLECHEH_OLLAMA_HOST' ) ?: 'http://127.0.0.1:11434', '/' ) );

// Model used when --model is not passed on the command line, overridable via
// SPLECHEH_OLLAMA_MODEL. Pick a size that fits your hardware and can
// generate fast enough without a GPU — see tools/README.md for real numbers.
define( 'OLLAMA_MODEL_DEFAULT', getenv( 'SPLECHEH_OLLAMA_MODEL' ) ?: 'qwen2.5:7b' );

// How long to keep the model loaded in memory after this request, so the
// next Interpunction Check doesn't pay the multi-minute cold-load cost again.
// Overridable via SPLECHEH_OLLAMA_KEEP_ALIVE (Ollama duration syntax, e.g. "30m").
define( 'OLLAMA_KEEP_ALIVE', getenv( 'SPLECHEH_OLLAMA_KEEP_ALIVE' ) ?: '10m' );

/**
 * Pulls the JSON array out of a model's reply.
 *
 * Models routinely wrap the array in a ```json fence, and sometimes introduce it
 * with a sentence ("Here is the corrected JSON:") or add a closing remark. The old
 * approach only stripped a fence anchored at the very start and end of the reply, so
 * anything around it left the fence markers in place and the decode failed on output
 * that was perfectly usable.
 *
 * @return array|null Decoded array, or null when there is no array to be had.
 */
function extract_json_array( string $text ): ?array {
	$text = trim( $text );

	// A fenced block anywhere in the reply: take what's inside it.
	if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $text, $matches ) ) {
		$text = trim( $matches[1] );
	}

	$decoded = json_decode( $text, true );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}

	// Otherwise take the outermost [ … ] and try that, which drops any prose
	// the model wrapped around the array.
	$start = strpos( $text, '[' );
	$end   = strrpos( $text, ']' );
	if ( $start === false || $end === false || $end < $start ) {
		return null;
	}

	$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

	return is_array( $decoded ) ? $decoded : null;
}

/**
 * Describes an unusable reply for the error message: how long it was, its start and
 * its end. The tail matters — a reply cut off mid-sentence means the model was still
 * generating when the timeout hit, which is a completely different fix (raise the
 * timeout or lower the chunk size) from a reply that simply isn't JSON.
 */
function describe_bad_output( string $output ): string {
	$length = strlen( $output );
	$looks_truncated = strpos( $output, '[' ) !== false && strrpos( $output, ']' ) === false;

	$description = sprintf( '%d bytes returned', $length );
	if ( $looks_truncated ) {
		$description .= '; the array is never closed, so the reply looks cut off mid-generation'
			. ' — raise the timeout or lower the Sentence Chunk Size';
	}

	$description .= ' | starts: ' . substr( $output, 0, 300 );
	if ( $length > 600 ) {
		$description .= ' | ends: ' . substr( $output, -300 );
	}

	return $description;
}

function fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

/**
 * Splits argv into [flags, payload]: the payload is always the last argument
 * (appended by Splecheh's check_commandline()); everything before it is
 * --provider/--model/--timeout flags belonging to this wrapper. --timeout is
 * the simplest way to raise the timeout: it can be set directly in the
 * Commandline Command field, without needing PHP-FPM env vars.
 *
 * @param string[] $argv
 * @return array{0: array{provider: string, model: ?string, timeout: ?float}, 1: string}
 */
function parse_args( array $argv ): array {
	array_shift( $argv ); // script name
	$payload = array_pop( $argv ) ?? '';

	$options = [
		'provider' => 'claude',
		'model'    => null,
		'timeout'  => null,
	];

	for ( $i = 0, $count = count( $argv ); $i < $count; $i++ ) {
		$arg = $argv[ $i ];
		if ( $arg === '--provider' && isset( $argv[ $i + 1 ] ) ) {
			$options['provider'] = $argv[ ++$i ];
		} elseif ( str_starts_with( $arg, '--provider=' ) ) {
			$options['provider'] = substr( $arg, strlen( '--provider=' ) );
		} elseif ( $arg === '--model' && isset( $argv[ $i + 1 ] ) ) {
			$options['model'] = $argv[ ++$i ];
		} elseif ( str_starts_with( $arg, '--model=' ) ) {
			$options['model'] = substr( $arg, strlen( '--model=' ) );
		} elseif ( $arg === '--timeout' && isset( $argv[ $i + 1 ] ) ) {
			$options['timeout'] = (float) $argv[ ++$i ];
		} elseif ( str_starts_with( $arg, '--timeout=' ) ) {
			$options['timeout'] = (float) substr( $arg, strlen( '--timeout=' ) );
		}
	}

	return [ $options, $payload ];
}

[ $options, $payload_json ] = parse_args( $argv );

// *_TIMEOUT: --timeout on the command line wins over the SPLECHEH_*_TIMEOUT
// env vars, which win over these defaults. The defaults are only enough for
// small batches (a handful of sentences); a full post can easily need
// several minutes.
//
// When Splecheh runs this wrapper it appends --timeout itself, derived from the
// Settings > Interpunction Check > "Command Timeout (seconds)" field minus a few
// seconds, so this script reports a real error instead of being killed
// mid-request — these defaults then only apply when the wrapper is run by hand
// (or from tools/benchmark.sh).
define(
	'CLAUDE_TIMEOUT',
	$options['timeout'] ?? (float) ( getenv( 'SPLECHEH_CLAUDE_TIMEOUT' ) ?: 55 )
);

define(
	'GEMINI_TIMEOUT',
	$options['timeout'] ?? (float) ( getenv( 'SPLECHEH_GEMINI_TIMEOUT' ) ?: 55 )
);

define(
	'CODEX_TIMEOUT',
	$options['timeout'] ?? (float) ( getenv( 'SPLECHEH_CODEX_TIMEOUT' ) ?: 55 )
);

// Local CPU inference is much slower than a hosted API — see CLAUDE_TIMEOUT above.
define(
	'OLLAMA_TIMEOUT',
	$options['timeout'] ?? (float) ( getenv( 'SPLECHEH_OLLAMA_TIMEOUT' ) ?: 300 )
);

/**
 * Sends $instruction to the claude CLI via --print and returns its stdout.
 */
function run_claude( string $instruction ): string {
	// Array form (not fromShellCommandline): Process escapes each argument
	// itself, so no manual shell-escaping is needed here.
	//
	// The prompt must come directly after --print: --tools takes a variadic
	// list and would otherwise swallow the prompt string as a "tool name".
	// --tools '' disables tool use entirely (unlike --allowed-tools '', which
	// does not restrict anything when empty).
	$process = new Process(
		[
			CLAUDE_BIN,
			'--print',
			$instruction,
			'--tools',
			'',
		]
	);
	$process->setTimeout( CLAUDE_TIMEOUT );

	try {
		$process->run();
	} catch ( \Throwable $e ) {
		fail( 'claude CLI failed to run: ' . $e->getMessage() );
	}

	if ( ! $process->isSuccessful() ) {
		$stderr = trim( $process->getErrorOutput() );
		fail( $stderr !== '' ? $stderr : 'claude CLI exited with code ' . $process->getExitCode() );
	}

	return $process->getOutput();
}

/**
 * Sends $instruction to the gemini CLI (Gemini CLI) via -p/--prompt (headless
 * mode) and returns its stdout.
 *
 * Requires GEMINI_API_KEY (or GOOGLE_API_KEY) set in the environment PHP-FPM
 * runs under. Without it, Gemini CLI falls back to its interactive OAuth
 * browser login flow, which cannot complete in a real headless/cron context
 * (no browser, no cached token) — the request will hang or fail rather than
 * generate anything. A locally cached login from prior interactive use can
 * mask this during manual testing; don't rely on that for production.
 *
 * Note: unlike claude's --tools '', Gemini CLI has no documented flag to
 * disable tool use entirely (only --approval-mode/--yolo, which control
 * *how* tool calls are approved, not whether the model can attempt them).
 * A self-contained punctuation-fixing prompt shouldn't prompt the model to
 * reach for tools in the first place, but if this ever hangs in production,
 * that's the first thing to look at — non-interactive/no-TTY use is expected
 * to skip approval prompts rather than hang, but that isn't documented
 * explicitly either.
 */
function run_gemini( string $instruction ): string {
	$process = new Process(
		[
			GEMINI_BIN,
			'-p',
			$instruction,
		]
	);
	$process->setTimeout( GEMINI_TIMEOUT );

	try {
		$process->run();
	} catch ( \Throwable $e ) {
		fail( 'gemini CLI failed to run: ' . $e->getMessage() );
	}

	if ( ! $process->isSuccessful() ) {
		$stderr = trim( $process->getErrorOutput() );
		fail( $stderr !== '' ? $stderr : 'gemini CLI exited with code ' . $process->getExitCode() );
	}

	return $process->getOutput();
}

/**
 * Sends $instruction to the codex CLI (OpenAI Codex CLI) via `codex exec` and
 * returns its stdout. `codex exec` runs non-interactively by design (no
 * approval-prompt/hang risk) and defaults to a read-only sandbox — appropriate
 * here since this wrapper only needs text in, text out, never file access.
 *
 * Requires a one-time login as whichever user PHP-FPM runs as, before this
 * will work: `printenv OPENAI_API_KEY | codex login --with-api-key`. Unlike
 * Gemini's GEMINI_API_KEY, this isn't an env var read per-request — it's a
 * cached credential (keyring or file, see codex's cli_auth_credentials_store
 * config) that `codex exec` reuses. Without that login, expect an auth error
 * rather than a hang, since `codex exec` doesn't fall back to opening a browser.
 */
function run_codex( string $instruction ): string {
	$process = new Process(
		[
			CODEX_BIN,
			'exec',
			$instruction,
		]
	);
	$process->setTimeout( CODEX_TIMEOUT );

	try {
		$process->run();
	} catch ( \Throwable $e ) {
		fail( 'codex CLI failed to run: ' . $e->getMessage() );
	}

	if ( ! $process->isSuccessful() ) {
		$stderr = trim( $process->getErrorOutput() );
		fail( $stderr !== '' ? $stderr : 'codex CLI exited with code ' . $process->getExitCode() );
	}

	return $process->getOutput();
}

/**
 * Sends $instruction to a local Ollama model via its HTTP API and returns
 * the generated text. Requires `ollama serve` to already be running — see
 * tools/local-model.sh.
 */
function run_ollama( string $instruction, ?string $model ): string {
	$model = $model ?: OLLAMA_MODEL_DEFAULT;

	$ch = curl_init( OLLAMA_HOST . '/api/generate' );
	curl_setopt_array(
		$ch,
		[
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(
				[
					'model'      => $model,
					'prompt'     => $instruction,
					'stream'     => false,
					'keep_alive' => OLLAMA_KEEP_ALIVE,
				]
			),
			CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => OLLAMA_TIMEOUT,
		]
	);

	$body       = curl_exec( $ch );
	$curl_error = curl_error( $ch );
	$status     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( $body === false ) {
		fail( "Ollama request to " . OLLAMA_HOST . " failed: {$curl_error} (is 'tools/local-model.sh start' running?)" );
	}

	if ( $status !== 200 ) {
		fail( "Ollama returned HTTP {$status}: " . substr( (string) $body, 0, 500 ) );
	}

	$decoded = json_decode( (string) $body, true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['response'] ) ) {
		fail( 'Ollama response missing "response" field: ' . substr( (string) $body, 0, 500 ) );
	}

	return (string) $decoded['response'];
}

if ( $payload_json === '' ) {
	fail( 'Usage: ' . basename( __FILE__ ) . " [--provider claude|gemini|codex|ollama] [--model <name>] [--timeout <seconds>] '<json payload>'" );
}

if ( ! in_array( $options['provider'], [ 'claude', 'gemini', 'codex', 'ollama' ], true ) ) {
	fail( "Unknown provider '{$options['provider']}'. Expected 'claude', 'gemini', 'codex', or 'ollama'." );
}

$payload = json_decode( $payload_json, true );
if ( ! is_array( $payload ) ) {
	fail( 'Invalid JSON payload: ' . json_last_error_msg() );
}

$prompt    = (string) ( $payload['prompt'] ?? '' );
$sentences = $payload['sentences'] ?? [];

if ( ! is_array( $sentences ) || $sentences === [] ) {
	echo json_encode( [] );
	exit( 0 );
}

$instruction = $prompt
	. ' Respond with only a JSON array, no other text, where each item is'
	. ' {"original": "...", "fixed": "...", "explanation": "..."} for every input'
	. ' sentence, in the same order as given. The input sentences are given as a'
	. " JSON array below.\n\n" . json_encode( $sentences );

switch ( $options['provider'] ) {
	case 'ollama':
		$output = run_ollama( $instruction, $options['model'] );
		break;
	case 'gemini':
		$output = run_gemini( $instruction );
		break;
	case 'codex':
		$output = run_codex( $instruction );
		break;
	case 'claude':
	default:
		$output = run_claude( $instruction );
		break;
}

$output  = trim( $output );
$decoded = extract_json_array( $output );

if ( $decoded === null ) {
	fail( "{$options['provider']} did not return valid JSON: " . describe_bad_output( $output ) );
}

echo json_encode( $decoded );
