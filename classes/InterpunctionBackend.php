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
		$client = self::client( $command_override );

		if ( ! $client ) {
			return new WP_Error( 'interpunction_no_client', __( 'The shared LLM component is unavailable.', 'splecheh' ) );
		}

		$is_commandline = 'commandline' === $client->provider();
		$command        = $command_override ?? self::get_command();

		if ( $is_commandline ) {
			Splecheh_Logs::addLog(
				'interpunction',
				'Interpunction commandline started',
				[ 'command' => self::with_wrapper_timeout( $command, self::get_command_timeout() ), 'timeout' => self::get_command_timeout() ]
			);
		}

		// The provider call is shared; the prompt, the required response shape
		// and the parsing below are Splecheh's own.
		$text = $client->complete(
			$prompt . ' ' . self::response_format_instructions(),
			$sentences,
			[ 'language' => $language ]
		);

		if ( is_wp_error( $text ) ) {
			// Keep the historical wording: these lines are what operators grep for.
			Splecheh_Logs::addLog(
				'interpunction',
				$is_commandline ? 'Interpunction commandline failed' : 'Interpunction request failed',
				$is_commandline
					? [ 'command' => $command, 'error' => $text->get_error_message() ]
					: [ 'error' => $text->get_error_message() ]
			);

			return $text;
		}

		return self::parse_results( $text );
	}

	/**
	 * Builds the shared LLM client from this plugin's own settings.
	 *
	 * The settings stay where they are — Splecheh's field names and stored
	 * options are unchanged — and are handed to the component per call, so a
	 * settings change takes effect immediately.
	 *
	 * @param string|null $command_override Overrides the configured command; used by tests.
	 * @return \Lauzis\WpPackages\Llm\Client|null
	 */
	private static function client( ?string $command_override = null ) {
		if ( ! class_exists( '\\Lauzis\\WpPackages\\Llm\\Client' ) ) {
			if ( ! class_exists( 'WpPackages_Registry' ) ) {
				return null;
			}

			WpPackages_Registry::boot();

			if ( ! class_exists( '\\Lauzis\\WpPackages\\Llm\\Client' ) ) {
				return null;
			}
		}

		return new \Lauzis\WpPackages\Llm\Client(
			'splecheh',
			[
				'settings'    => [
					'llm_provider'   => self::get_type(),
					'llm_access_key' => self::get_access_key(),
					'llm_endpoint'   => self::get_raw_endpoint(),
					'llm_command'    => $command_override ?? self::get_command(),
					'llm_timeout'    => self::get_command_timeout(),
				],
				// Splecheh's documented wrapper contract sends the work under
				// "sentences"; scripts in the wild expect that key.
				'payload_key' => 'sentences',
				'models'      => [
					'openai' => (string) apply_filters( 'splecheh_interpunction_openai_model', 'gpt-4o-mini' ),
					'claude' => (string) apply_filters( 'splecheh_interpunction_claude_model', 'claude-3-5-haiku-latest' ),
					'gemini' => (string) apply_filters( 'splecheh_interpunction_gemini_model', 'gemini-1.5-flash' ),
				],
			]
		);
	}

	/** The configured endpoint override, empty when none is set. */
	private static function get_raw_endpoint(): string {
		return function_exists( 'carbon_get_theme_option' )
			? (string) carbon_get_theme_option( 'splecheh_interpunction_endpoint' )
			: '';
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

	private static function response_format_instructions(): string {
		return 'Respond with only a JSON array, no other text, where each item is {"original": "...", "fixed": "...", "explanation": "..."} for every input sentence, in the same order as given. The input sentences are given as a JSON array.';
	}

	/**
	 * Parses a provider's response text into the {original, fixed, explanation}[] shape.
	 *
	 * @return array|WP_Error
	 */
	private static function parse_results( string $text ) {
		$data = self::extract_json_array( $text );

		if ( $data === null ) {
			$detail = self::describe_bad_response( trim( $text ) );

			Splecheh_Logs::addLog( 'interpunction', 'Interpunction response was not valid JSON', [ 'response' => $detail ] );

			return new WP_Error(
				'interpunction_invalid_response',
				sprintf(
					/* translators: %s: description of what the provider returned instead */
					__( 'The interpunction check response was not valid JSON. %s', 'splecheh' ),
					$detail
				)
			);
		}

		return $data;
	}

	/**
	 * Pulls the JSON array out of a model's reply.
	 *
	 * Models routinely wrap the array in a ```json fence, and sometimes introduce it
	 * with a sentence ("Here is the corrected JSON:") or add a closing remark. Stripping
	 * only a fence anchored at the very start and end of the reply — as this used to —
	 * left the markers in place whenever anything surrounded them, failing on output
	 * that was perfectly usable.
	 *
	 * Mirrored in tools/llm-wrapper.php, which runs as its own process and so can't
	 * share this code.
	 *
	 * @return array|null Decoded array, or null when there is no array to be had.
	 */
	public static function extract_json_array( string $text ): ?array {
		if ( ! class_exists( '\\Lauzis\\WpPackages\\Llm\\Json' ) && class_exists( 'WpPackages_Registry' ) ) {
			WpPackages_Registry::boot();
		}

		return class_exists( '\\Lauzis\\WpPackages\\Llm\\Json' )
			? \Lauzis\WpPackages\Llm\Json::extract_array( $text )
			: null;
	}

	/**
	 * Describes an unusable reply for the error message and the log: how long it was,
	 * and its start. An unclosed array is called out separately — a reply cut off
	 * mid-generation means the provider was still writing when the timeout hit, which
	 * needs a different fix (raise Command Timeout, or lower Sentence Chunk Size) from
	 * a reply that simply isn't JSON.
	 */
	public static function describe_bad_response( string $text ): string {
		if ( $text === '' ) {
			return __( 'Nothing was returned at all.', 'splecheh' );
		}

		if ( strpos( $text, '[' ) !== false && strrpos( $text, ']' ) === false ) {
			return sprintf(
				/* translators: 1: byte count, 2: start of the response */
				__( '%1$d bytes returned and the array is never closed, so the reply was cut off mid-generation — raise the Command Timeout or lower the Sentence Chunk Size. Starts: %2$s', 'splecheh' ),
				strlen( $text ),
				substr( $text, 0, 200 )
			);
		}

		return sprintf(
			/* translators: 1: byte count, 2: start of the response */
			__( '%1$d bytes returned, starting: %2$s', 'splecheh' ),
			strlen( $text ),
			substr( $text, 0, 200 )
		);
	}
}
