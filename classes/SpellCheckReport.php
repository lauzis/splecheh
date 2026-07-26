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
			$errors = self::apply_auto_fixes( $post, $errors, $language );
			$errors = self::filter_term_ignored( $errors, $language );
		}

		// Whitespace issues are found in the raw content, not the plain text the
		// spell checker sees — prepare_text() collapses the very runs we're looking
		// for. Re-read the post so a content-rewriting auto-apply fix just above is
		// reflected. Merged before the per-post ignore filter so "Ignore in post"
		// works for these rows too.
		$saved  = get_post( $post_id );
		$errors = array_merge(
			$errors,
			self::find_whitespace_issues( $saved ? $saved->post_content : $post->post_content )
		);

		$errors = self::filter_ignored_words( $post_id, $errors, $language );

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
	 * Finds runs of two or more spaces/tabs between two words in the post's visible
	 * text, reported as `type => 'whitespace'` error entries alongside the spelling
	 * ones. HTML collapses whitespace runs when rendering, so these are invisible to
	 * readers but still noise in the source — and they also break Interpunction
	 * Check's sentence matching, since the splitter normalizes them away.
	 *
	 * Runs on the raw content (prepare_text() would have collapsed them already), with
	 * everything that isn't visible prose masked out first — tags and their attributes,
	 * HTML comments (which carry the block delimiters), shortcodes, and <pre>/<code>
	 * blocks, where spacing is deliberate. Only runs sitting *between* two words on the
	 * same line count, so markup indentation and blank lines are never flagged.
	 *
	 * Each distinct word pair is reported once, matching how spelling errors are
	 * de-duplicated per word.
	 *
	 * @return array[] Error entries in the same shape as the spelling ones.
	 */
	public static function find_whitespace_issues( string $content ): array {
		$masked = self::mask_non_prose( $content );

		if ( preg_match_all( '/[^\s\x00]+[ \t]{2,}[^\s\x00]+/u', $masked, $matches, PREG_OFFSET_CAPTURE ) < 1 ) {
			return [];
		}

		$issues = [];
		$seen   = [];

		foreach ( $matches[0] as $match ) {
			// Offsets come from the mask, which is byte-for-byte aligned with $content.
			$pair = substr( $content, $match[1], strlen( $match[0] ) );
			$key  = mb_strtolower( $pair );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$issues[] = [
				'type'        => 'whitespace',
				'word'        => $pair,
				'suggestions' => [ (string) preg_replace( '/[ \t]{2,}/u', ' ', $pair ) ],
				'excerpt'     => self::whitespace_excerpt( $content, $match[1], strlen( $match[0] ) ),
			];
		}

		return $issues;
	}

	/**
	 * Replaces everything that isn't visible prose with NUL bytes, preserving length
	 * (and therefore every offset) so a match in the mask can be sliced straight out of
	 * the original content.
	 */
	private static function mask_non_prose( string $content ): string {
		$patterns = [
			'#<(pre|code)\b[^>]*>.*?</\1>#is', // Deliberate spacing — never flagged.
			'#<!--.*?-->#s',                   // Block delimiters and other comments.
			'#<[^>]*>#s',                      // Tags, with their attribute whitespace.
			'#\[[^\]\[]*\]#s',                 // Shortcodes.
		];

		foreach ( $patterns as $pattern ) {
			$content = (string) preg_replace_callback(
				$pattern,
				static function ( array $match ): string {
					return str_repeat( "\x00", strlen( $match[0] ) );
				},
				$content
			);
		}

		return $content;
	}

	/**
	 * Builds a readable, tag-free excerpt around a whitespace run, keeping the run
	 * itself intact so the Details page can show what is actually there.
	 */
	private static function whitespace_excerpt( string $content, int $offset, int $length, int $padding = 60 ): string {
		$start   = max( 0, $offset - $padding );
		$segment = substr( $content, $start, ( $offset - $start ) + $length + $padding );

		// Drop partial tags introduced by slicing mid-markup, then the whole ones.
		$segment = (string) preg_replace( [ '#^[^<>]*>#s', '#<[^<>]*$#s' ], '', $segment );
		$segment = wp_strip_all_tags( $segment );

		// Collapse only the whitespace around the segment's edges — line breaks and
		// indentation from the markup — never the run being reported.
		return trim( (string) preg_replace( '/[\r\n]+[ \t]*/u', ' ', $segment ) );
	}

	/**
	 * Whether an error entry describes a whitespace run rather than a misspelled word.
	 * Entries saved before whitespace checking existed have no 'type' at all.
	 */
	public static function is_whitespace_error( array $error ): bool {
		return ( $error['type'] ?? 'spelling' ) === 'whitespace';
	}

	/**
	 * Renders an error's word for display, making whitespace runs visible with ␣
	 * markers — otherwise a "double space" row would look identical to a correct one,
	 * since the browser collapses the spaces. Escapes its own output.
	 */
	public static function render_word( array $error ): string {
		if ( ! self::is_whitespace_error( $error ) ) {
			return esc_html( $error['word'] );
		}

		return self::mark_whitespace( esc_html( $error['word'] ) );
	}

	/**
	 * Renders an error's example sentence: the flagged word in bold for spelling, the
	 * whitespace run marked with ␣ for whitespace. Escapes its own output.
	 */
	public static function render_excerpt( array $error ): string {
		if ( ! self::is_whitespace_error( $error ) ) {
			return self::highlight_word( $error['excerpt'], $error['word'] );
		}

		return self::mark_whitespace( esc_html( $error['excerpt'] ) );
	}

	/**
	 * Renders the badge naming what kind of issue an entry is. Escapes its own output.
	 */
	public static function render_type_badge( array $error ): string {
		if ( self::is_whitespace_error( $error ) ) {
			return '<span class="splecheh-badge splecheh-badge--outdated">' . esc_html__( 'whitespace', 'splecheh' ) . '</span>';
		}

		return '<span class="splecheh-badge">' . esc_html__( 'spelling', 'splecheh' ) . '</span>';
	}

	/**
	 * Replaces runs of two or more spaces/tabs in already-escaped HTML with visible ␣
	 * markers, one per character, so the reader can count what's actually in the source.
	 */
	private static function mark_whitespace( string $escaped_html ): string {
		$result = preg_replace_callback(
			'/[ \t]{2,}/u',
			static function ( array $match ): string {
				return '<span class="splecheh-whitespace-marker">' . str_repeat( '␣', strlen( $match[0] ) ) . '</span>';
			},
			$escaped_html
		);

		return $result !== null ? $result : $escaped_html;
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
	 *
	 * Now backed by the shared tree-based splitter (Splecheh_ContentSplitter): the
	 * content is broken into block-level chunks and their plain texts are joined with
	 * a single space, so adjoining sentences/paragraphs are never glued into one word
	 * (e.g. "sakāmo.</p><p>Var" stays "sakāmo. Var") — the same guarantee the old
	 * block-closing-tag regex gave, but via one code path shared with Interpunction
	 * Check instead of two hand-maintained flattening rules.
	 */
	public static function prepare_text( string $content, bool $ignore_shortcodes ): string {
		if ( $ignore_shortcodes ) {
			$content = self::strip_shortcodes( $content );
		}

		$blocks = Splecheh_ContentSplitter::plain_texts( $content );
		$text   = implode( ' ', $blocks );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
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
	 * Applies the global, language-scoped auto-apply list to a post's spell check:
	 * for each flagged word that has a saved word→replacement pair for the post's
	 * language, writes the replacement into the post content (every occurrence),
	 * drops the error from the report, and records the correction in the separate
	 * auto-apply audit log. Applied on each run/re-run/cron pass, not retroactively.
	 *
	 * The language is resolved by splecheh_get_language_code() (Polylang → WPML →
	 * Settings/locale fallback), so the list only ever touches posts in its language.
	 *
	 * @param array[] $errors
	 * @return array[] Errors that were not auto-applied.
	 */
	private static function apply_auto_fixes( WP_Post $post, array $errors, string $language ): array {
		$pairs = Splecheh_AutoApplyList::get_pairs( $language );
		if ( empty( $pairs ) ) {
			return $errors;
		}

		$content   = $post->post_content;
		$remaining = [];
		$applied   = [];

		foreach ( $errors as $error ) {
			$key = mb_strtolower( $error['word'] );
			if ( ! isset( $pairs[ $key ] ) ) {
				$remaining[] = $error;
				continue;
			}

			$replacement = $pairs[ $key ];
			$content     = self::replace_all_occurrences( $content, $error['word'], $replacement );
			$applied[]   = [
				'word'        => $error['word'],
				'replacement' => $replacement,
			];
		}

		if ( ! empty( $applied ) ) {
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $content,
				]
			);

			foreach ( $applied as $fix ) {
				Splecheh_Logs::addAutoApplyLog(
					sprintf( 'Auto-applied fix in post %d: "%s" → "%s"', $post->ID, $fix['word'], $fix['replacement'] ),
					[ 'language' => $language ]
				);
			}
		}

		return $remaining;
	}

	/**
	 * Removes errors for words ignored for this post (post meta) or globally for its language.
	 *
	 * Both the per-post and the global ignore list are language-scoped: the global
	 * list is looked up by the post's resolved language code (Polylang → WPML →
	 * Settings/locale fallback via splecheh_get_language_code()), so a word ignored
	 * for one language never suppresses it in a post of another language.
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
	 * Drops errors that are part of a globally-ignored, language-scoped multi-word
	 * term (e.g. "Steam Deck", "Lego Batman"). Aspell flags each word of such a term
	 * separately ("Steam", "Deck"); an error is dropped when the flagged word is one
	 * of a listed term's words AND the word's sentence (the report excerpt) contains
	 * every word of that term. Partial appearances of a term are still flagged.
	 *
	 * The term list is looked up by the post's resolved language code, the same way
	 * as the ignore/auto-apply lists, so a term never suppresses words in another
	 * language.
	 *
	 * @param array[] $errors
	 * @return array[]
	 */
	private static function filter_term_ignored( array $errors, string $language ): array {
		$terms = Splecheh_TermIgnoreList::get_terms( $language );
		if ( empty( $terms ) ) {
			return $errors;
		}

		return array_values(
			array_filter(
				$errors,
				static function ( array $error ) use ( $terms ): bool {
					return ! self::is_term_ignored( (string) $error['word'], (string) $error['excerpt'], $terms );
				}
			)
		);
	}

	/**
	 * Whether a flagged word in a given sentence should be suppressed by one of the
	 * listed terms: the word must be a whole-word part of the term, and the sentence
	 * must contain every word of that term (whole-word, case-insensitive) so that a
	 * partial term (only "Steam", not "Steam Deck") is still flagged.
	 *
	 * @param string[] $terms
	 */
	public static function is_term_ignored( string $word, string $sentence, array $terms ): bool {
		$word = trim( $word );
		if ( $word === '' ) {
			return false;
		}

		foreach ( $terms as $term ) {
			$term_words = preg_split( '/\s+/u', trim( (string) $term ) ) ?: [];
			$term_words = array_values( array_filter( $term_words, static fn( $w ) => $w !== '' ) );
			if ( count( $term_words ) < 2 ) {
				// A term must span at least two words to be meaningful here; a single-word
				// entry belongs on the plain ignore list, not the term list.
				continue;
			}

			// The flagged word must itself be one of the term's words.
			if ( ! self::contains_whole_word( $term, $word ) ) {
				continue;
			}

			// Every word of the term must appear in the sentence for it to count as complete.
			$complete = true;
			foreach ( $term_words as $term_word ) {
				if ( ! self::contains_whole_word( $sentence, $term_word ) ) {
					$complete = false;
					break;
				}
			}

			if ( $complete ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $needle appears as a whole word (case-insensitive) in $haystack, using
	 * the same word-boundary matching as the highlighting/replacement logic elsewhere.
	 */
	private static function contains_whole_word( string $haystack, string $needle ): bool {
		if ( $needle === '' ) {
			return false;
		}
		return preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/ui', $haystack ) === 1;
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
	 *
	 * Returns null when the word can't be found at all, so the caller can report a fix
	 * that never reached the post instead of marking the issue resolved anyway.
	 */
	public static function replace_occurrence( string $content, string $word, string $excerpt, string $replacement ): ?string {
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

		if ( preg_match( $pattern, $content ) !== 1 ) {
			return null;
		}

		$result = preg_replace( $pattern, $replacement, $content, 1 );
		return $result !== null ? $result : null;
	}

	/**
	 * Replaces the first literal occurrence of a flagged whitespace pair ("word  word")
	 * with $replacement, or returns null if it isn't there any more.
	 *
	 * Deliberately a literal search rather than replace_occurrence()'s \b…\b pattern:
	 * the pair was sliced straight out of the raw content, and its edges are whatever
	 * characters happened to sit around the whitespace run — quotes, brackets, digits —
	 * which word boundaries can't anchor to.
	 */
	public static function replace_whitespace_run( string $content, string $pair, string $replacement ): ?string {
		if ( $pair === '' ) {
			return null;
		}

		$pos = mb_strpos( $content, $pair );
		if ( $pos === false ) {
			return null;
		}

		return mb_substr( $content, 0, $pos ) . $replacement . mb_substr( $content, $pos + mb_strlen( $pair ) );
	}

	/**
	 * Re-reads a post from storage, re-splits it, and returns the keys of $fixes whose
	 * text is not actually present in the saved content. Shared by both checks' fix
	 * handlers (like is_outdated() above).
	 *
	 * Checking our own string replacement isn't enough: wp_update_post() runs the
	 * content through kses and whatever other plugins hook content filters, so the only
	 * trustworthy answer to "did the fix land?" is what came back out of the database.
	 * Compared against the splitter's plain text (entities decoded, whitespace
	 * collapsed) rather than raw HTML, so a fix isn't reported as lost just because the
	 * stored markup escapes it differently.
	 *
	 * @param array<int|string, string> $fixes Fix text, keyed by report entry index.
	 * @return array<int|string> Keys of the fixes that are missing from the saved post.
	 */
	public static function find_unapplied_fixes( int $post_id, array $fixes ): array {
		if ( empty( $fixes ) ) {
			return [];
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array_keys( $fixes );
		}

		$saved   = implode( "\n", Splecheh_ContentSplitter::plain_texts( $post->post_content ) );
		$missing = [];

		foreach ( $fixes as $index => $text ) {
			$needle = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
			if ( $needle === '' || mb_stripos( $saved, $needle ) === false ) {
				$missing[] = $index;
			}
		}

		return $missing;
	}

	/**
	 * Replaces every whole-word, case-insensitive occurrence of $word in $content
	 * with $replacement. Used by the auto-apply list, which fixes a word everywhere
	 * it appears in the post rather than a single occurrence.
	 */
	public static function replace_all_occurrences( string $content, string $word, string $replacement ): string {
		if ( $word === '' ) {
			return $content;
		}
		$pattern = '/\b' . preg_quote( $word, '/' ) . '\b/ui';
		$result  = preg_replace( $pattern, $replacement, $content );
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
	 * HTML-escapes $suggestion and wraps the part that differs from $word in <strong>,
	 * keeping any shared prefix/suffix plain. A lightweight diff for short, single-word
	 * spelling suggestions — not a general-purpose text diff.
	 */
	public static function diff_highlight( string $word, string $suggestion ): string {
		$word_len       = mb_strlen( $word );
		$suggestion_len = mb_strlen( $suggestion );

		$max_prefix = min( $word_len, $suggestion_len );
		$prefix_len = 0;
		while ( $prefix_len < $max_prefix && mb_substr( $word, $prefix_len, 1 ) === mb_substr( $suggestion, $prefix_len, 1 ) ) {
			++$prefix_len;
		}

		$max_suffix = min( $word_len, $suggestion_len ) - $prefix_len;
		$suffix_len = 0;
		while (
			$suffix_len < $max_suffix
			&& mb_substr( $word, $word_len - $suffix_len - 1, 1 ) === mb_substr( $suggestion, $suggestion_len - $suffix_len - 1, 1 )
		) {
			++$suffix_len;
		}

		$prefix = mb_substr( $suggestion, 0, $prefix_len );
		$middle = mb_substr( $suggestion, $prefix_len, $suggestion_len - $prefix_len - $suffix_len );
		$suffix = mb_substr( $suggestion, $suggestion_len - $suffix_len );

		if ( $middle === '' ) {
			return esc_html( $suggestion );
		}

		return esc_html( $prefix ) . '<strong>' . esc_html( $middle ) . '</strong>' . esc_html( $suffix );
	}

	/**
	 * Builds the read-only "Suggestion" column markup for one error: every suggestion for
	 * $word, comma-separated, with the part that differs from $word in bold.
	 *
	 * @param string[] $suggestions
	 */
	public static function render_suggestions( array $suggestions, string $word ): string {
		if ( empty( $suggestions ) ) {
			return '';
		}

		return implode(
			', ',
			array_map(
				static function ( string $suggestion ) use ( $word ): string {
					return self::diff_highlight( $word, $suggestion );
				},
				$suggestions
			)
		);
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
					'word'            => $error['word'],
					'wordHtml'        => self::render_word( $error ),
					'typeHtml'        => self::render_type_badge( $error ),
					'isWhitespace'    => self::is_whitespace_error( $error ),
					'excerpt'         => self::render_excerpt( $error ),
					'suggestion'      => $error['suggestions'][0] ?? '',
					'suggestionsHtml' => self::render_suggestions( $error['suggestions'] ?? [], $error['word'] ),
					'resolved'        => ! empty( $error['resolved'] ),
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
		$pattern = '/\b' . preg_quote( $word, '/' ) . '\b/ui';

		// Split into sentences on typical sentence-ending punctuation.
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [];
		foreach ( $sentences as $sentence ) {
			if ( preg_match( $pattern, $sentence ) === 1 ) {
				return trim( $sentence );
			}
		}
		// Fallback: return a short excerpt around the word.
		if ( preg_match( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return '';
		}
		// PREG_OFFSET_CAPTURE gives a byte offset; convert to a character offset for mb_substr().
		$pos   = mb_strlen( substr( $text, 0, $matches[0][1] ) );
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

	/**
	 * Whether a post's Spell Check is up to date and has zero unresolved issues.
	 * Used to gate Interpunction Check on Spell Check being clean first — see
	 * splecheh_interpunction_require_spellcheck_clean_enabled().
	 */
	public static function is_clean( int $post_id ): bool {
		$checked_at = get_post_meta( $post_id, '_splecheh_checked_at', true );
		if ( ! $checked_at || ! metadata_exists( 'post', $post_id, '_splecheh_issue_count' ) ) {
			return false;
		}

		if ( (int) get_post_meta( $post_id, '_splecheh_issue_count', true ) > 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$stored_version = get_post_meta( $post_id, '_splecheh_version', true );

		return ! self::is_outdated(
			$checked_at,
			$post->post_modified_gmt,
			$stored_version !== '' ? (string) $stored_version : null,
			splecheh_invalidate_on_version_change_enabled()
		);
	}
}
