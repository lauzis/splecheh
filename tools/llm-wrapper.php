#!/usr/bin/env php
<?php
/**
 * Commandline wrapper for Splecheh's Interpunction Check that calls either
 * the locally installed `claude` CLI (Claude Code) or a local Ollama model,
 * instead of a hosted API.
 *
 * Set as the "Commandline Command" in Settings > Interpunction Check:
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php
 *   php /absolute/path/to/wp-content/plugins/splecheh/tools/llm-wrapper.php --provider ollama --model qwen2.5:7b
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

// CLAUDE_TIMEOUT/OLLAMA_TIMEOUT: --timeout on the command line wins over the
// SPLECHEH_*_TIMEOUT env vars, which win over these defaults. The default is
// only enough for small batches (a handful of sentences); a full post can
// easily need several minutes. Whatever value is used here should be kept
// slightly below Splecheh's own `splecheh_interpunction_command_timeout`
// filter (default 60s, also needs raising for real posts) so this script
// reports a real error instead of being killed mid-request.
define(
	'CLAUDE_TIMEOUT',
	$options['timeout'] ?? (float) ( getenv( 'SPLECHEH_CLAUDE_TIMEOUT' ) ?: 55 )
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
	fail( 'Usage: ' . basename( __FILE__ ) . " [--provider claude|ollama] [--model <name>] [--timeout <seconds>] '<json payload>'" );
}

if ( ! in_array( $options['provider'], [ 'claude', 'ollama' ], true ) ) {
	fail( "Unknown provider '{$options['provider']}'. Expected 'claude' or 'ollama'." );
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

$output = $options['provider'] === 'ollama'
	? run_ollama( $instruction, $options['model'] )
	: run_claude( $instruction );

$output = trim( $output );
$output = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $output );

$decoded = json_decode( $output, true );
if ( ! is_array( $decoded ) ) {
	fail( "{$options['provider']} did not return valid JSON: " . substr( $output, 0, 500 ) );
}

echo json_encode( $decoded );
