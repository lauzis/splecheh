<?php

use Symfony\Component\Process\Process;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_InterpunctionBackend {

	/**
	 * Default timeout (in seconds) for the Commandline Interpunction Check process,
	 * so a stuck/hanging command fails fast with a clear error instead of hanging
	 * the request indefinitely. Overridable per site via the "Command Timeout"
	 * Settings field and the `splecheh_interpunction_command_timeout` filter — see
	 * get_command_timeout().
	 */
	const DEFAULT_COMMAND_TIMEOUT = 60;

	/**
	 * How many seconds below the plugin's own process timeout the bundled
	 * tools/llm-wrapper.php is told to give up (see with_wrapper_timeout()), so a
	 * slow provider surfaces the wrapper's real error rather than being killed
	 * mid-call by the process timeout.
	 */
	const WRAPPER_TIMEOUT_MARGIN = 5;

	/**
	 * Canned sentences used by the Settings page "Test Interpunction Check" button,
	 * chosen to contain obvious punctuation/capitalization issues.
	 *
	 * @var string[]
	 */
	const TEST_SENTENCES = [
		'the quick brown fox jumps over the lazy dog',
		'is this correct  ,she asked',
		'we visited paris,london and berlin last summer',
	];

	/**
	 * Default cap on how many of a chosen post's sentences are sent to the "Test
	 * Interpunction Check" button, so picking a long post doesn't turn the test into
	 * a slow/expensive full check. Overridable per-request via build_test_payload()'s
	 * $sentence_limit argument (e.g. the Settings page's own "Test all sentences"
	 * checkbox) — this is never saved, it only affects that one test run.
	 */
	const DEFAULT_TEST_MAX_SENTENCES = 5;

	/**
	 * Default number of sentences sent per LLM call. A real post can have far more
	 * sentences than the "Test" button's canned/limited sample, and per-sentence
	 * generation time (especially for a CLI/local model) doesn't leave much headroom
	 * for one giant single-call prompt within any reasonable timeout — so a post's
	 * sentences are chunked and checked over several calls, merging the results.
	 * Filterable via `splecheh_interpunction_chunk_size`; 0 or less disables chunking
	 * (send everything in a single call, the old behavior).
	 */
	const DEFAULT_CHUNK_SIZE = 5;

	/**
	 * Builds the example payload used by the Settings page "Test Interpunction Check"
	 * button: the currently configured language and prompt, plus either the canned test
	 * sentences or, when $post_id is given and has content, that post's own sentences
	 * (same language detection and text preparation as a real check).
	 *
	 * @param int      $post_id        Post to test against, or 0 for the canned example.
	 * @param int|null $sentence_limit Max sentences to send from the post; null uses
	 *                                 DEFAULT_TEST_MAX_SENTENCES, 0 means no limit.
	 */
	public static function build_test_payload( int $post_id = 0, ?int $sentence_limit = null ): array {
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$language  = splecheh_get_language_code( $post_id );
				$sentences = Splecheh_InterpunctionReport::split_content_into_sentences( $post->post_content, splecheh_ignore_shortcodes_enabled() );

				$limit = $sentence_limit ?? self::DEFAULT_TEST_MAX_SENTENCES;
				if ( $limit > 0 ) {
					$sentences = array_slice( $sentences, 0, $limit );
				}

				if ( ! empty( $sentences ) ) {
					return [
						'language'   => $language,
						'prompt'     => str_replace( '{language}', $language, self::get_prompt() ),
						'sentences'  => $sentences,
						'post_id'    => $post_id,
						'post_title' => $post->post_title,
					];
				}
			}
		}

		$language = Splecheh_SpellCheckReport::get_language();

		return [
			'language'  => $language,
			'prompt'    => str_replace( '{language}', $language, self::get_prompt() ),
			'sentences' => self::TEST_SENTENCES,
		];
	}

	/**
	 * Sends $sentences to the configured provider and returns the parsed
	 * per-sentence {original, fixed, explanation} results, in the same order.
	 *
	 * @param string[]    $sentences
	 * @param string|null $command_override For the Commandline type only: run this
	 *                                       command instead of the saved Commandline
	 *                                       Command, without changing the saved setting.
	 *                                       Used by the Settings page "Test" button to
	 *                                       try a different provider/model (e.g. a local
	 *                                       Ollama model vs `claude`) for one run. Ignored
	 *                                       for other types.
	 * @return array|WP_Error
	 */
	public static function check( array $sentences, string $language, ?string $command_override = null ) {
		$chunk_size = self::resolve_chunk_size();

		if ( $chunk_size <= 0 || count( $sentences ) <= $chunk_size ) {
			return self::check_batch( $sentences, $language, $command_override );
		}

		$chunks  = array_chunk( $sentences, $chunk_size );
		$results = [];

		foreach ( $chunks as $index => $chunk ) {
			$chunk_result = self::check_batch( $chunk, $language, $command_override );
			if ( is_wp_error( $chunk_result ) ) {
				// Lets the caller (InterpunctionReport::run()) save whatever chunks
				// already succeeded instead of discarding them, and show how far the
				// check got (e.g. "6/11 chunks") rather than a plain failure.
				$chunk_result->add_data(
					[
						'results'          => $results,
						'chunks_processed' => $index,
						'chunks_total'     => count( $chunks ),
					]
				);
				return $chunk_result;
			}
			array_push( $results, ...$chunk_result );
		}

		return $results;
	}

	/**
	 * How many chunks a post with $sentence_count sentences would be split into,
	 * using the currently configured chunk size. 0 sentences need 0 chunks;
	 * chunking disabled (chunk size <= 0) always means a single chunk.
	 */
	public static function count_chunks( int $sentence_count ): int {
		if ( $sentence_count <= 0 ) {
			return 0;
		}
		$chunk_size = self::resolve_chunk_size();
		return $chunk_size <= 0 ? 1 : (int) ceil( $sentence_count / $chunk_size );
	}

	private static function resolve_chunk_size(): int {
		return (int) apply_filters( 'splecheh_interpunction_chunk_size', self::get_chunk_size() );
	}

	/**
	 * Sends a single batch of sentences to the configured provider in one call.
	 *
	 * @param string[] $sentences
	 * @return array|WP_Error
	 */
	private static function check_batch( array $sentences, string $language, ?string $command_override ) {
		$prompt = str_replace( '{language}', $language, self::get_prompt() );

		switch ( self::get_type() ) {
			case 'openai':
				return self::check_openai( $sentences, $prompt );
			case 'claude':
				return self::check_claude( $sentences, $prompt );
			case 'gemini':
				return self::check_gemini( $sentences, $prompt );
			case 'commandline':
			default:
				return self::check_commandline( $sentences, $language, $prompt, $command_override );
		}
	}

	public static function get_type(): string {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return 'commandline';
		}
		$type = (string) carbon_get_theme_option( 'splecheh_interpunction_type' );
		return $type !== '' ? $type : 'commandline';
	}

	/**
	 * How many sentences are sent per LLM call — see DEFAULT_CHUNK_SIZE for why.
	 * Also filterable via `splecheh_interpunction_chunk_size`, which takes
	 * precedence over this Settings field.
	 */
	public static function get_chunk_size(): int {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return self::DEFAULT_CHUNK_SIZE;
		}
		$value = carbon_get_theme_option( 'splecheh_interpunction_chunk_size' );
		return $value !== '' && $value !== null ? (int) $value : self::DEFAULT_CHUNK_SIZE;
	}

	/**
	 * How long (in seconds) a Commandline Interpunction Check call may take before it
	 * is killed — see DEFAULT_COMMAND_TIMEOUT. Zero/blank/negative values fall back to
	 * the default rather than disabling the timeout, since an unbounded call would hang
	 * the whole request. Also filterable via `splecheh_interpunction_command_timeout`,
	 * which takes precedence over this Settings field.
	 */
	public static function get_command_timeout(): float {
		$timeout = (float) self::DEFAULT_COMMAND_TIMEOUT;

		if ( function_exists( 'carbon_get_theme_option' ) ) {
			$value = carbon_get_theme_option( 'splecheh_interpunction_command_timeout' );
			if ( $value !== '' && $value !== null && (float) $value > 0 ) {
				$timeout = (float) $value;
			}
		}

		return (float) apply_filters( 'splecheh_interpunction_command_timeout', $timeout );
	}

	public static function get_prompt(): string {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return Splecheh_InterpunctionReport::DEFAULT_PROMPT;
		}
		$prompt = (string) carbon_get_theme_option( 'splecheh_interpunction_prompt' );
		return $prompt !== '' ? $prompt : Splecheh_InterpunctionReport::DEFAULT_PROMPT;
	}

	/**
	 * Returns the configured Commandline Command, with "--provider ollama --model
	 * <selection>" appended automatically when the "Local Model" dropdown is set to
	 * something other than its default ("use the command as typed"). Lets Settings
	 * offer a simple model picker on top of tools/llm-wrapper.php without needing a
	 * second free-text field to keep in sync by hand.
	 */
	public static function get_command(): string {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return '';
		}
		$command = trim( (string) carbon_get_theme_option( 'splecheh_interpunction_command' ) );
		$model   = trim( (string) carbon_get_theme_option( 'splecheh_interpunction_local_model' ) );

		if ( $command !== '' && $model !== '' ) {
			$command .= ' --provider ollama --model ' . $model;
		}

		return $command;
	}

	/**
	 * Best-effort label for "which model produced this check", based on the
	 * currently configured provider — saved into each report so it's visible
	 * later which model/command generated a given set of fixes. Reflects the
	 * saved Settings, not any per-request override (e.g. the Settings page
	 * Test button's command override, which is never saved to a report).
	 */
	public static function get_model_label(): string {
		switch ( self::get_type() ) {
			case 'openai':
				return (string) apply_filters( 'splecheh_interpunction_openai_model', 'gpt-4o-mini' );
			case 'claude':
				return (string) apply_filters( 'splecheh_interpunction_claude_model', 'claude-3-5-haiku-latest' );
			case 'gemini':
				return (string) apply_filters( 'splecheh_interpunction_gemini_model', 'gemini-1.5-flash' );
			case 'commandline':
			default:
				return self::extract_model_label( self::get_command() );
		}
	}

	/**
	 * Pulls a "--model <name>" value out of a Commandline Command string (as used
	 * by tools/llm-wrapper.php's --model flag, only meaningful for --provider
	 * ollama). Without one, a command that's clearly tools/llm-wrapper.php is
	 * using whichever CLI its --provider flag names (claude/gemini/codex only
	 * take a model implicitly via their own config, not a --model flag) — that's
	 * a cleaner label than the full path/flags. Anything else falls back to the
	 * command string itself (e.g. a plain "claude -p"), so it still shows
	 * something meaningful instead of nothing.
	 */
	public static function extract_model_label( string $command ): string {
		if ( preg_match( '/--model[= ]+(\S+)/', $command, $matches ) ) {
			return $matches[1];
		}
		if ( strpos( $command, 'llm-wrapper.php' ) !== false ) {
			if ( preg_match( '/--provider[= ]+(\S+)/', $command, $matches ) ) {
				return $matches[1];
			}
			return 'claude';
		}
		return trim( $command );
	}

	private static function get_endpoint( string $default ): string {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return $default;
		}
		$endpoint = (string) carbon_get_theme_option( 'splecheh_interpunction_endpoint' );
		return $endpoint !== '' ? $endpoint : $default;
	}

	private static function get_access_key(): string {
		return function_exists( 'carbon_get_theme_option' ) ? (string) carbon_get_theme_option( 'splecheh_interpunction_access_key' ) : '';
	}

	/**
	 * Appends "--timeout <seconds>" to a bundled tools/llm-wrapper.php command that
	 * doesn't already carry one, so the "Command Timeout" Settings field is the single
	 * place controlling how long a call may take. Without this the wrapper would keep
	 * its own built-in default (55s for the CLI providers) and silently cap whatever
	 * the Settings field says.
	 *
	 * The wrapper is given WRAPPER_TIMEOUT_MARGIN seconds less than the process
	 * timeout so it gives up first and reports the provider's real error, instead of
	 * being killed mid-call. A --timeout written into the command by hand always wins.
	 */
	public static function with_wrapper_timeout( string $command, float $timeout ): string {
		if ( strpos( $command, 'llm-wrapper.php' ) === false || preg_match( '/--timeout[= ]/', $command ) ) {
			return $command;
		}

		$wrapper_timeout = max( 1, (int) round( $timeout ) - self::WRAPPER_TIMEOUT_MARGIN );

		return $command . ' --timeout ' . $wrapper_timeout;
	}

	/**
	 * Calls the locally-configured shell command, appending {language, prompt, sentences}
	 * as a single shell-escaped JSON parameter and expecting a JSON array of
	 * {original, fixed, explanation} on stdout. Keeps API keys out of WordPress: the
	 * script owns its own credentials. See bin/interpunction-check.sh for a reference
	 * implementation of this contract.
	 *
	 * Runs via Symfony Process (rather than a raw proc_open/stream_get_contents loop)
	 * with an explicit timeout, so a command that blocks on stdin or fills a pipe
	 * buffer fails fast with a clear WP_Error instead of hanging the request.
	 *
	 * @param string[]    $sentences
	 * @param string|null $command Overrides the configured command; used by tests.
	 * @return array|WP_Error
	 */
	private static function check_commandline( array $sentences, string $language, string $prompt, ?string $command = null ) {
		$command = trim( $command ?? self::get_command() );
		if ( $command === '' ) {
			return new WP_Error( 'interpunction_no_command', __( 'No commandline command is configured for the Interpunction Check.', 'splecheh' ) );
		}

		$payload = (string) wp_json_encode(
			[
				'language'  => $language,
				'prompt'    => $prompt,
				'sentences' => $sentences,
			]
		);

		$timeout = self::get_command_timeout();
		$command = self::with_wrapper_timeout( $command, $timeout );

		Splecheh_Logs::addLog( 'interpunction', 'Interpunction commandline started', [ 'command' => $command, 'timeout' => $timeout ] );

		try {
			$process = Process::fromShellCommandline( $command . ' ' . escapeshellarg( $payload ) );
			$process->setTimeout( $timeout );
			$exit_code = $process->run();
		} catch ( \Throwable $exception ) {
			Splecheh_Logs::addLog( 'interpunction', 'Interpunction commandline failed', [ 'command' => $command, 'error' => $exception->getMessage() ] );
			return new WP_Error( 'interpunction_command_failed', $exception->getMessage() );
		}

		if ( $exit_code !== 0 ) {
			$error_message = sprintf(
				/* translators: 1: exit code, 2: stderr output */
				__( 'Interpunction commandline exited with code %1$d: %2$s', 'splecheh' ),
				$exit_code,
				trim( $process->getErrorOutput() )
			);
			Splecheh_Logs::addLog( 'interpunction', 'Interpunction commandline failed', [ 'command' => $command, 'error' => $error_message ] );
			return new WP_Error( 'interpunction_command_failed', $error_message );
		}

		return self::parse_results( $process->getOutput() );
	}

	/**
	 * Minimal OpenAI Chat Completions call. Kept as a small wp_remote_post request rather
	 * than a composer SDK so the report/UI layer doesn't depend on a specific client library.
	 *
	 * @param string[] $sentences
	 * @return array|WP_Error
	 */
	private static function check_openai( array $sentences, string $prompt ) {
		$access_key = self::get_access_key();
		if ( $access_key === '' ) {
			return new WP_Error( 'interpunction_no_access_key', __( 'No OpenAI access key is configured for the Interpunction Check.', 'splecheh' ) );
		}

		$response = wp_remote_post(
			self::get_endpoint( 'https://api.openai.com/v1/chat/completions' ),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $access_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'            => apply_filters( 'splecheh_interpunction_openai_model', 'gpt-4o-mini' ),
						'messages'         => [
							[
								'role'    => 'system',
								'content' => $prompt . ' ' . self::response_format_instructions(),
							],
							[
								'role'    => 'user',
								'content' => (string) wp_json_encode( $sentences ),
							],
						],
						'response_format'  => [ 'type' => 'json_object' ],
					]
				),
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return self::parse_results( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
	}

	/**
	 * Minimal Anthropic Messages API call.
	 *
	 * @param string[] $sentences
	 * @return array|WP_Error
	 */
	private static function check_claude( array $sentences, string $prompt ) {
		$access_key = self::get_access_key();
		if ( $access_key === '' ) {
			return new WP_Error( 'interpunction_no_access_key', __( 'No Claude access key is configured for the Interpunction Check.', 'splecheh' ) );
		}

		$response = wp_remote_post(
			self::get_endpoint( 'https://api.anthropic.com/v1/messages' ),
			[
				'headers' => [
					'x-api-key'         => $access_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'      => apply_filters( 'splecheh_interpunction_claude_model', 'claude-3-5-haiku-latest' ),
						'max_tokens' => 4096,
						'system'     => $prompt . ' ' . self::response_format_instructions(),
						'messages'   => [
							[
								'role'    => 'user',
								'content' => (string) wp_json_encode( $sentences ),
							],
						],
					]
				),
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return self::parse_results( (string) ( $data['content'][0]['text'] ?? '' ) );
	}

	/**
	 * Minimal Gemini generateContent call.
	 *
	 * @param string[] $sentences
	 * @return array|WP_Error
	 */
	private static function check_gemini( array $sentences, string $prompt ) {
		$access_key = self::get_access_key();
		if ( $access_key === '' ) {
			return new WP_Error( 'interpunction_no_access_key', __( 'No Gemini access key is configured for the Interpunction Check.', 'splecheh' ) );
		}

		$model    = apply_filters( 'splecheh_interpunction_gemini_model', 'gemini-1.5-flash' );
		$endpoint = self::get_endpoint( "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent" );

		$response = wp_remote_post(
			add_query_arg( 'key', $access_key, $endpoint ),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'contents'          => [
							[ 'parts' => [ [ 'text' => (string) wp_json_encode( $sentences ) ] ] ],
						],
						'systemInstruction' => [
							'parts' => [ [ 'text' => $prompt . ' ' . self::response_format_instructions() ] ],
						],
					]
				),
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return self::parse_results( (string) ( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' ) );
	}

	private static function response_format_instructions(): string {
		return 'Respond with only a JSON array, no other text, where each item is {"original": "...", "fixed": "...", "explanation": "..."} for every input sentence, in the same order as given. The input sentences are given as a JSON array.';
	}

	/**
	 * Parses a provider's response text into the {original, fixed, explanation}[] shape.
	 *
	 * @return array|WP_Error
	 */
	private static function parse_results( string $text ) {
		$text = trim( $text );
		// Some providers wrap JSON in a fenced code block; strip it if present.
		$text = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );

		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'interpunction_invalid_response', __( 'The interpunction check response was not valid JSON.', 'splecheh' ) );
		}

		return $data;
	}
}
