<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_InterpunctionBackend {

	/**
	 * Sends $sentences to the configured provider and returns the parsed
	 * per-sentence {original, fixed, explanation} results, in the same order.
	 *
	 * @param string[] $sentences
	 * @return array|WP_Error
	 */
	public static function check( array $sentences, string $language ) {
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
				return self::check_commandline( $sentences, $language, $prompt );
		}
	}

	public static function get_type(): string {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return 'commandline';
		}
		$type = (string) carbon_get_theme_option( 'splecheh_interpunction_type' );
		return $type !== '' ? $type : 'commandline';
	}

	public static function get_prompt(): string {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return Splecheh_InterpunctionReport::DEFAULT_PROMPT;
		}
		$prompt = (string) carbon_get_theme_option( 'splecheh_interpunction_prompt' );
		return $prompt !== '' ? $prompt : Splecheh_InterpunctionReport::DEFAULT_PROMPT;
	}

	private static function get_command(): string {
		return function_exists( 'carbon_get_theme_option' ) ? (string) carbon_get_theme_option( 'splecheh_interpunction_command' ) : '';
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
	 * Calls the locally-configured shell command, appending {language, prompt, sentences}
	 * as a single shell-escaped JSON parameter and expecting a JSON array of
	 * {original, fixed, explanation} on stdout. Keeps API keys out of WordPress: the
	 * script owns its own credentials. See bin/interpunction-check.sh for a reference
	 * implementation of this contract.
	 *
	 * @param string[] $sentences
	 * @return array|WP_Error
	 */
	private static function check_commandline( array $sentences, string $language, string $prompt ) {
		$command = trim( self::get_command() );
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

		$descriptors = [
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $command . ' ' . escapeshellarg( $payload ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'interpunction_command_failed', __( 'Could not start the interpunction commandline process.', 'splecheh' ) );
		}

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		if ( $exit_code !== 0 ) {
			return new WP_Error(
				'interpunction_command_failed',
				sprintf(
					/* translators: 1: exit code, 2: stderr output */
					__( 'Interpunction commandline exited with code %1$d: %2$s', 'splecheh' ),
					$exit_code,
					trim( $stderr )
				)
			);
		}

		return self::parse_results( $stdout );
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
