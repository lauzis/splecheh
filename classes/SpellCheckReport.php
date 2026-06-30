<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_SpellCheckReport {

	/**
	 * Runs spell check on a post, saves the JSON report, and updates post meta.
	 *
	 * @return array|WP_Error Report array on success, WP_Error on failure.
	 */
	public static function run( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'splecheh' ) );
		}

		$language = splecheh_get_language_code( $post_id );

		// Strip HTML and decode entities for plain-text spell checking.
		$plain_text = wp_strip_all_tags( $post->post_content );
		$plain_text = html_entity_decode( $plain_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain_text = trim( $plain_text );

		if ( $plain_text === '' ) {
			$errors = [];
		} else {
			$errors = self::spellcheck( $plain_text, $language );
			if ( is_wp_error( $errors ) ) {
				return $errors;
			}
			$errors = self::filter_ignored_words( $post_id, $errors, $language );
		}

		$report = [
			'post_id'    => $post_id,
			'post_title' => $post->post_title,
			'checked_at' => gmdate( 'c' ),
			'language'   => $language,
			'errors'     => $errors,
		];

		$result = self::save_report( $post_id, $report );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $report;
	}

	/**
	 * Returns the language code to use for the spell checker.
	 */
	public static function get_language(): string {
		if ( function_exists( 'carbon_get_theme_option' ) ) {
			$saved = carbon_get_theme_option( 'splecheh_language' );
			if ( ! empty( $saved ) ) {
				return (string) $saved;
			}
		}
		// Fall back to WordPress locale, converting en_US → en.
		$locale = get_locale();
		$parts  = explode( '_', $locale );
		return $parts[0];
	}

	/**
	 * Returns the URL for a post's spell check report, or empty string if none.
	 */
	public static function get_report_url( int $post_id ): string {
		$uuid = get_post_meta( $post_id, '_splecheh_report_uuid', true );
		if ( ! $uuid ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		return $upload_dir['baseurl'] . '/splecheh/' . $uuid . '.json';
	}

	/**
	 * Removes errors for words ignored for this post (post meta) or globally for its language.
	 */
	private static function filter_ignored_words( int $post_id, array $errors, string $language ): array {
		$post_ignored   = (array) get_post_meta( $post_id, '_splecheh_ignored_words', true );
		$global_ignored = Splecheh_IgnoreList::get_words( $language );
		$ignored        = array_map( 'mb_strtolower', array_merge( $post_ignored, $global_ignored ) );

		if ( empty( $ignored ) ) {
			return $errors;
		}

		return array_values(
			array_filter(
				$errors,
				function ( $error ) use ( $ignored ) {
					return ! in_array( mb_strtolower( $error['word'] ), $ignored, true );
				}
			)
		);
	}

	/**
	 * Returns the absolute filesystem path to a post's saved report JSON, or empty string if none.
	 */
	public static function get_report_path( int $post_id ): string {
		$uuid = get_post_meta( $post_id, '_splecheh_report_uuid', true );
		if ( ! $uuid ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/splecheh/' . $uuid . '.json';
	}

	/**
	 * Reads and decodes a post's saved report JSON.
	 */
	public static function get_report( int $post_id ): ?array {
		$path = self::get_report_path( $post_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Overwrites a post's saved report JSON in place (does not change the report's UUID or checked-at meta).
	 */
	public static function update_report( int $post_id, array $report ): bool {
		$path = self::get_report_path( $post_id );
		if ( ! $path ) {
			return false;
		}
		$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		return file_put_contents( $path, $json ) !== false;
	}

	/**
	 * Counts the unresolved issues in a post's saved report.
	 */
	public static function count_unresolved( int $post_id ): int {
		$report = self::get_report( $post_id );
		if ( ! $report || empty( $report['errors'] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $report['errors'] as $error ) {
			if ( empty( $error['resolved'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Replaces the specific occurrence of $word found inside $excerpt within $content.
	 * Falls back to the first whole-word occurrence in $content if the excerpt can't be located
	 * (e.g. it was extracted from stripped/decoded text and no longer matches the raw HTML).
	 */
	public static function replace_occurrence( string $content, string $word, string $excerpt, string $replacement ): string {
		$pattern = '/\b' . preg_quote( $word, '/' ) . '\b/ui';

		if ( $excerpt !== '' ) {
			$pos = mb_stripos( $content, $excerpt );
			if ( $pos !== false ) {
				$segment     = mb_substr( $content, $pos, mb_strlen( $excerpt ) );
				$new_segment = preg_replace( $pattern, $replacement, $segment, 1 );
				if ( $new_segment !== null && $new_segment !== $segment ) {
					return mb_substr( $content, 0, $pos ) . $new_segment . mb_substr( $content, $pos + mb_strlen( $excerpt ) );
				}
			}
		}

		$result = preg_replace( $pattern, $replacement, $content, 1 );
		return $result !== null ? $result : $content;
	}

	/**
	 * Runs the spell checker on plain text and returns an array of error entries.
	 *
	 * @return array|WP_Error
	 */
	private static function spellcheck( string $text, string $language ) {
		try {
			if ( extension_loaded( 'pspell' ) ) {
				$checker = new \PhpSpellcheck\Spellchecker\PHPPspell();
			} else {
				$checker = \PhpSpellcheck\Spellchecker\Aspell::create();
			}
			$finder       = new \PhpSpellcheck\MisspellingFinder( $checker );
			$misspellings = $finder->find( $text, [ $language ] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'spellcheck_failed', $e->getMessage() );
		}

		$errors = [];
		$seen   = [];
		foreach ( $misspellings as $m ) {
			$word = $m->getWord();
			// De-duplicate: only report each misspelled word once.
			if ( isset( $seen[ $word ] ) ) {
				continue;
			}
			$seen[ $word ] = true;
			$errors[]      = [
				'word'        => $word,
				'suggestions' => array_slice( $m->getSuggestions(), 0, 5 ),
				'excerpt'     => self::extract_sentence( $text, $word ),
			];
		}

		return $errors;
	}

	/**
	 * Finds the first sentence in $text that contains $word and returns it.
	 */
	private static function extract_sentence( string $text, string $word ): string {
		// Split into sentences on typical sentence-ending punctuation.
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [];
		foreach ( $sentences as $sentence ) {
			if ( mb_stripos( $sentence, $word ) !== false ) {
				return trim( $sentence );
			}
		}
		// Fallback: return a short excerpt around the word.
		$pos = mb_stripos( $text, $word );
		if ( $pos === false ) {
			return '';
		}
		$start = max( 0, $pos - 50 );
		return trim( mb_substr( $text, $start, 100 ) );
	}

	/**
	 * Saves the report JSON to the uploads folder and updates post meta.
	 *
	 * @return true|WP_Error
	 */
	private static function save_report( int $post_id, array $report ) {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
		}

		$dir = $upload_dir['basedir'] . '/splecheh';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Prevent directory listing.
			file_put_contents( $dir . '/index.php', '<?php // silence is golden' );
		}

		$uuid = wp_generate_uuid4();
		$path = $dir . '/' . $uuid . '.json';

		// Remove old report file if it exists.
		$old_uuid = get_post_meta( $post_id, '_splecheh_report_uuid', true );
		if ( $old_uuid && file_exists( $dir . '/' . $old_uuid . '.json' ) ) {
			@unlink( $dir . '/' . $old_uuid . '.json' );
		}

		$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( file_put_contents( $path, $json ) === false ) {
			return new WP_Error( 'write_failed', __( 'Could not write spell check report.', 'splecheh' ) );
		}

		update_post_meta( $post_id, '_splecheh_report_uuid', $uuid );
		update_post_meta( $post_id, '_splecheh_checked_at', gmdate( 'Y-m-d H:i:s' ) );

		return true;
	}
}
