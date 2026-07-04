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

		$plain_text = self::prepare_text( $post->post_content, splecheh_ignore_shortcodes_enabled() );

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
	 * Strips HTML and decodes entities for plain-text spell checking, optionally
	 * removing shortcode literals first so they never surface as spelling errors.
	 */
	public static function prepare_text( string $content, bool $ignore_shortcodes ): string {
		if ( $ignore_shortcodes ) {
			$content = self::strip_shortcodes( $content );
		}

		// Replace block-level closing tags and <br> with a space before stripping tags,
		// otherwise wp_strip_all_tags() removes them and merges adjoining sentences/paragraphs
		// into a single word (e.g. "sakāmo.</p><p>Var" becomes "sakāmo.Var").
		$content = preg_replace( '#</(?:p|div|li|ul|ol|h[1-6]|blockquote|section|article|tr|table)\s*>|<br\s*/?>#i', ' ', $content );

		$text = wp_strip_all_tags( $content );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Removes WordPress-style shortcode literals — e.g. "[shortcode attr=\"value\"]" or
	 * "[shortcode]enclosed content[/shortcode]" — replacing each with a single space so
	 * surrounding words aren't merged together. Mirrors WordPress's own shortcode syntax
	 * (see get_shortcode_regex()) but matches any tag name, not just registered ones, since
	 * spell checking may run before the shortcode's owning plugin has registered it.
	 * Shortcodes escaped as "[[tag]]" are treated as literal text, not stripped.
	 */
	public static function strip_shortcodes( string $content ): string {
		$tag     = '[a-zA-Z][a-zA-Z0-9_-]*';
		$pattern = '/\[(\[?)(' . $tag . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/s';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $m ): string {
				if ( $m[1] === '[' && $m[6] === ']' ) {
					return substr( $m[0], 1, -1 );
				}
				return ' ';
			},
			$content
		);
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
	 * Manually marks a post's spell check as complete: resolves every remaining error
	 * in the saved report (so the unresolved issue count becomes 0) and pushes
	 * checked_at an hour into the future, so the post reads as up to date even if it's
	 * resaved again shortly after (e.g. later in the same editing session) without
	 * immediately being flagged outdated again. Used when an editor has reviewed a
	 * post and decided the remaining flagged words don't need fixing, without having
	 * to dismiss each one individually.
	 *
	 * @return array|WP_Error Updated report on success, WP_Error if no report exists.
	 */
	public static function mark_complete( int $post_id ) {
		$report = self::get_report( $post_id );
		if ( ! $report ) {
			return new WP_Error( 'no_report', __( 'No spell check report found for this post yet.', 'splecheh' ) );
		}

		foreach ( $report['errors'] as &$error ) {
			$error['resolved'] = true;
		}
		unset( $error );

		if ( ! self::update_report( $post_id, $report ) ) {
			return new WP_Error( 'write_failed', __( 'Could not update spell check report.', 'splecheh' ) );
		}

		update_post_meta( $post_id, '_splecheh_checked_at', gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ) );
		update_post_meta( $post_id, '_splecheh_version', SPLECHEH_VERSION );

		return $report;
	}

	/**
	 * Overwrites a post's saved report JSON in place (does not change the report's UUID or checked-at meta),
	 * and refreshes the stored unresolved issue count meta to match.
	 */
	public static function update_report( int $post_id, array $report ): bool {
		$path = self::get_report_path( $post_id );
		if ( ! $path ) {
			return false;
		}
		$json  = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$saved = file_put_contents( $path, $json ) !== false;
		if ( $saved ) {
			update_post_meta( $post_id, '_splecheh_issue_count', self::count_unresolved_in_report( $report ) );
		}
		return $saved;
	}

	/**
	 * Counts the unresolved issues in a post's saved report.
	 */
	public static function count_unresolved( int $post_id ): int {
		$report = self::get_report( $post_id );
		if ( ! $report ) {
			return 0;
		}
		return self::count_unresolved_in_report( $report );
	}

	/**
	 * Counts the unresolved issues in an already-loaded report array.
	 */
	private static function count_unresolved_in_report( array $report ): int {
		if ( empty( $report['errors'] ) ) {
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
	 * Aggregates data for the "at a glance" dashboard widget across all posts of the
	 * enabled post types: total unresolved issues, distinct posts with at least one
	 * unresolved issue, and ignored words (global ignore list plus per-post ignores).
	 *
	 * @return array{unresolved_count: int, posts_with_errors: int, ignored_words: int}
	 */
	public static function get_dashboard_summary(): array {
		$enabled_types     = splecheh_get_enabled_post_types();
		$unresolved_count  = 0;
		$posts_with_errors = 0;
		$ignored_words     = 0;

		if ( ! empty( $enabled_types ) ) {
			$checked_query = new WP_Query(
				[
					'post_type'      => $enabled_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_splecheh_report_uuid',
					'no_found_rows'  => true,
				]
			);

			foreach ( $checked_query->posts as $post_id ) {
				$unresolved = self::count_unresolved( $post_id );
				if ( $unresolved > 0 ) {
					$unresolved_count += $unresolved;
					$posts_with_errors++;
				}
			}

			$ignored_query = new WP_Query(
				[
					'post_type'      => $enabled_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_splecheh_ignored_words',
					'no_found_rows'  => true,
				]
			);

			foreach ( $ignored_query->posts as $post_id ) {
				$ignored_words += count( (array) get_post_meta( $post_id, '_splecheh_ignored_words', true ) );
			}
		}

		foreach ( Splecheh_IgnoreList::get_all() as $words ) {
			$ignored_words += count( (array) $words );
		}

		return [
			'unresolved_count'  => $unresolved_count,
			'posts_with_errors' => $posts_with_errors,
			'ignored_words'     => $ignored_words,
		];
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
	 * HTML-escapes $excerpt and wraps whole-word, case-insensitive occurrences of $word in <strong> tags.
	 * Escaping happens first so the returned markup is safe to echo directly.
	 */
	public static function highlight_word( string $excerpt, string $word ): string {
		$escaped_excerpt = esc_html( $excerpt );
		$escaped_word    = esc_html( $word );

		if ( $escaped_word === '' ) {
			return $escaped_excerpt;
		}

		$pattern = '/\b' . preg_quote( $escaped_word, '/' ) . '\b/ui';
		$result  = preg_replace( $pattern, '<strong>$0</strong>', $escaped_excerpt );

		return $result !== null ? $result : $escaped_excerpt;
	}

	/**
	 * Maps a report's error entries into the shape the Details page's JS uses to
	 * redraw the issues table in place (e.g. after an AJAX re-run), pre-escaping
	 * and highlighting the excerpt the same way the server-rendered rows do.
	 *
	 * @param array[] $errors
	 * @return array[]
	 */
	public static function format_errors_for_details( array $errors ): array {
		return array_map(
			static function ( array $error ): array {
				return [
					'word'       => $error['word'],
					'excerpt'    => self::highlight_word( $error['excerpt'], $error['word'] ),
					'suggestion' => $error['suggestions'][0] ?? '',
					'resolved'   => ! empty( $error['resolved'] ),
				];
			},
			$errors
		);
	}

	/**
	 * Runs the spell checker on plain text and returns an array of error entries.
	 *
	 * @return array|WP_Error
	 */
	private static function spellcheck( string $text, string $language, ?\PhpSpellcheck\Spellchecker\SpellcheckerInterface $checker = null ) {
		try {
			if ( ! $checker ) {
				$checker = extension_loaded( 'pspell' )
					? new \PhpSpellcheck\Spellchecker\PHPPspell()
					: \PhpSpellcheck\Spellchecker\Aspell::create();
			}

			if ( ! self::is_language_available( $checker, $language ) ) {
				return self::missing_wordlist_error( $language );
			}

			$finder       = new \PhpSpellcheck\MisspellingFinder( $checker );
			$misspellings = $finder->find( $text, [ $language ] );
		} catch ( \Throwable $e ) {
			if ( stripos( $e->getMessage(), 'word list' ) !== false ) {
				return self::missing_wordlist_error( $language );
			}
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
	 * Checks whether the checker has a wordlist installed for $language.
	 */
	private static function is_language_available( \PhpSpellcheck\Spellchecker\SpellcheckerInterface $checker, string $language ): bool {
		$language = strtolower( $language );
		foreach ( $checker->getSupportedLanguages() as $supported ) {
			$supported = strtolower( $supported );
			if ( $supported === $language || strpos( $supported, $language . '_' ) === 0 || strpos( $supported, $language . '-' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the friendly error shown when no wordlist is installed for a language:
	 * states the missing language, the install command, and a deep link to the docs.
	 */
	private static function missing_wordlist_error( string $language ): WP_Error {
		$docs_url = 'https://github.com/lauzis/splecheh#aspell-dependency';
		$message  = sprintf(
			/* translators: 1: language code, 2: apt-get install command, 3: documentation URL */
			__( 'No spellcheck word list is installed for language "%1$s". Install it with: %2$s — see %3$s for details.', 'splecheh' ),
			$language,
			sprintf( 'sudo apt-get update && sudo apt-get install aspell-%s', $language ),
			$docs_url
		);
		return new WP_Error(
			'missing_wordlist',
			$message,
			[
				'language' => $language,
				'docs_url' => $docs_url,
			]
		);
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
		update_post_meta( $post_id, '_splecheh_version', SPLECHEH_VERSION );
		update_post_meta( $post_id, '_splecheh_issue_count', self::count_unresolved_in_report( $report ) );

		return true;
	}

	/**
	 * Whether a saved report should be considered outdated: the post was edited
	 * after the check, or — when $check_version is true — the report's stored
	 * plugin version differs from the current one.
	 */
	public static function is_outdated( string $checked_at, string $post_modified_gmt, ?string $stored_version, bool $check_version, string $current_version = SPLECHEH_VERSION ): bool {
		$post_modified_ts = strtotime( $post_modified_gmt );
		$checked_ts       = strtotime( $checked_at . ' UTC' );

		if ( $post_modified_ts > $checked_ts ) {
			return true;
		}

		return $check_version && $stored_version !== $current_version;
	}
}
