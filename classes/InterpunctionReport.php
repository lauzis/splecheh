<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_InterpunctionReport {

	const DEFAULT_PROMPT = 'You are a professional {language} editor. Your only task is to fix the punctuation and capitalization of the provided text. Keep the original text content exactly as is. Respond with only a JSON array, no other text, where each item is {"original": "...", "fixed": "...", "explanation": "..."} for every input sentence, in the same order as given. The input sentences are given as a JSON array.';

	/**
	 * Runs the interpunction check on a post, saves the JSON report, and updates post meta.
	 * If a chunk fails partway through (see Splecheh_InterpunctionBackend::check()), the
	 * chunks that already succeeded are still saved as a partial report — rather than
	 * discarding that work — with chunks_processed < chunks_total, and the failure is
	 * still returned so the caller knows the check didn't fully complete.
	 *
	 * @return array|WP_Error Report array on success, WP_Error on failure.
	 */
	public static function run( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'splecheh' ) );
		}

		if ( splecheh_interpunction_require_spellcheck_clean_enabled() && ! Splecheh_SpellCheckReport::is_clean( $post_id ) ) {
			return new WP_Error(
				'spellcheck_not_clean',
				__( 'Spell Check must be up to date with zero unresolved issues before Interpunction Check can run for this post.', 'splecheh' )
			);
		}

		$language = splecheh_get_language_code( $post_id );

		$sentences      = self::split_content_into_sentences( $post->post_content, splecheh_ignore_shortcodes_enabled() );
		$sentence_count = count( $sentences );
		$chunks_total   = Splecheh_InterpunctionBackend::count_chunks( $sentence_count );

		if ( empty( $sentences ) ) {
			return self::finish_run( $post_id, $post, $language, [], 0, $chunks_total, 0.0, $sentence_count );
		}

		$started_at = microtime( true );
		$results    = Splecheh_InterpunctionBackend::check( $sentences, $language );
		$duration   = microtime( true ) - $started_at;

		if ( is_wp_error( $results ) ) {
			$partial = self::extract_partial_progress( $results );
			if ( $partial === null ) {
				return $results; // Nothing succeeded (or not chunked) — nothing to save.
			}

			$save_result = self::finish_run( $post_id, $post, $language, $partial['results'], $partial['chunks_processed'], $chunks_total, $duration, $sentence_count );
			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			return $results; // Report saved (partial), but the check still didn't complete.
		}

		return self::finish_run( $post_id, $post, $language, $results, $chunks_total, $chunks_total, $duration, $sentence_count );
	}

	/**
	 * Pulls the partial-progress data attached by Splecheh_InterpunctionBackend::check()
	 * to a chunk failure, if any. Returns null when there's nothing to salvage (e.g. a
	 * single-call, non-chunked failure), in which case the caller should save nothing.
	 *
	 * @return array{results: array, chunks_processed: int}|null
	 */
	public static function extract_partial_progress( WP_Error $error ): ?array {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return null;
		}
		return [
			'results'          => $data['results'],
			'chunks_processed' => (int) ( $data['chunks_processed'] ?? 0 ),
		];
	}

	/**
	 * Builds, saves, and returns the report for a (possibly partial) set of results.
	 *
	 * @param array[] $results
	 * @return array|WP_Error
	 */
	private static function finish_run( int $post_id, WP_Post $post, string $language, array $results, int $chunks_processed, int $chunks_total, float $duration_seconds = 0.0, int $sentence_count = 0 ) {
		$issues = self::filter_ignored_sentences( $post_id, self::build_issues( $results ) );

		$report = [
			'post_id'          => $post_id,
			'post_title'       => $post->post_title,
			'checked_at'       => gmdate( 'c' ),
			'language'         => $language,
			'provider'         => Splecheh_InterpunctionBackend::get_type(),
			'model'            => Splecheh_InterpunctionBackend::get_model_label(),
			'sentence_count'   => $sentence_count,
			'chunks_processed' => $chunks_processed,
			'chunks_total'     => $chunks_total,
			'duration_seconds' => round( $duration_seconds, 2 ),
			'issues'           => $issues,
		];

		$result = self::save_report( $post_id, $report );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $report;
	}

	/**
	 * Splits a post's HTML content into sentences, one block-level chunk at a time,
	 * so a sentence is never assembled across a block boundary (a header run into the
	 * next paragraph, one list item into the next, …). Each block's plain text is
	 * split independently and the results are concatenated in document order. This is
	 * the tree-based path both the real Interpunction Check and the Settings "Test"
	 * button use, replacing the old "flatten the whole post, then split" approach —
	 * see issue #62.
	 *
	 * @return string[]
	 */
	public static function split_content_into_sentences( string $content, bool $ignore_shortcodes ): array {
		if ( $ignore_shortcodes ) {
			$content = Splecheh_SpellCheckReport::strip_shortcodes( $content );
		}

		$sentences = [];
		foreach ( Splecheh_ContentSplitter::plain_texts( $content ) as $block_text ) {
			foreach ( self::split_into_sentences( $block_text ) as $sentence ) {
				$sentences[] = $sentence;
			}
		}
		return $sentences;
	}

	/**
	 * Splits plain text into sentences on typical sentence-ending punctuation.
	 *
	 * @return string[]
	 */
	public static function split_into_sentences( string $text ): array {
		if ( $text === '' ) {
			return [];
		}
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [];
		return array_values(
			array_filter(
				array_map( 'trim', $sentences ),
				static function ( string $sentence ): bool {
					return $sentence !== '';
				}
			)
		);
	}

	/**
	 * Keeps only the backend's per-sentence results whose fixed text differs from
	 * the original, since an unchanged sentence isn't an issue to review. Also used
	 * by the Settings page "Test Interpunction Check" button, so it only surfaces
	 * sentences the LLM actually changed.
	 */
	public static function build_issues( array $results ): array {
		$issues = [];
		foreach ( $results as $result ) {
			$original = trim( (string) ( $result['original'] ?? '' ) );
			$fixed    = trim( (string) ( $result['fixed'] ?? '' ) );
			if ( $original === '' || $fixed === '' || $fixed === $original ) {
				continue;
			}
			$issues[] = [
				'original'    => $original,
				'fixed'       => $fixed,
				'explanation' => trim( (string) ( $result['explanation'] ?? '' ) ),
			];
		}
		return $issues;
	}

	/**
	 * Removes issues for sentences ignored for this post ("Ignore in post").
	 *
	 * Per-post only, deliberately: unlike a misspelled word, a flagged sentence is
	 * specific enough that it is never going to recur verbatim in another post, so a
	 * global ignore list of sentences carried no value — it was removed in 0.25.1.
	 */
	private static function filter_ignored_sentences( int $post_id, array $issues ): array {
		$ignored = (array) get_post_meta( $post_id, '_splecheh_interpunction_ignored_sentences', true );

		if ( empty( $ignored ) ) {
			return $issues;
		}

		return array_values(
			array_filter(
				$issues,
				function ( $issue ) use ( $ignored ) {
					return ! in_array( $issue['original'], $ignored, true );
				}
			)
		);
	}

	/**
	 * Returns the URL for a post's interpunction check report, or empty string if none.
	 */
	public static function get_report_url( int $post_id ): string {
		$uuid = get_post_meta( $post_id, '_splecheh_interpunction_report_uuid', true );
		if ( ! $uuid ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		return $upload_dir['baseurl'] . '/splecheh-interpunction/' . $uuid . '.json';
	}

	/**
	 * Returns the absolute filesystem path to a post's saved report JSON, or empty string if none.
	 */
	public static function get_report_path( int $post_id ): string {
		$uuid = get_post_meta( $post_id, '_splecheh_interpunction_report_uuid', true );
		if ( ! $uuid ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/splecheh-interpunction/' . $uuid . '.json';
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
	 * Manually marks a post's interpunction check as complete: resolves every
	 * remaining issue in the saved report (so the unresolved issue count becomes 0)
	 * and pushes checked_at an hour into the future, so the post reads as up to date
	 * even if it's resaved again shortly after (e.g. later in the same editing
	 * session) without immediately being flagged outdated again. Used when an editor
	 * has reviewed a post and decided the remaining flagged sentences don't need
	 * fixing, without having to dismiss each one individually.
	 *
	 * @return array|WP_Error Updated report on success, WP_Error if no report exists.
	 */
	public static function mark_complete( int $post_id ) {
		$report = self::get_report( $post_id );
		if ( ! $report ) {
			return new WP_Error( 'no_report', __( 'No interpunction check report found for this post yet.', 'splecheh' ) );
		}

		foreach ( $report['issues'] as &$issue ) {
			$issue['resolved'] = true;
		}
		unset( $issue );

		if ( ! self::update_report( $post_id, $report ) ) {
			return new WP_Error( 'write_failed', __( 'Could not update interpunction check report.', 'splecheh' ) );
		}

		update_post_meta( $post_id, '_splecheh_interpunction_checked_at', gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ) );
		update_post_meta( $post_id, '_splecheh_interpunction_version', SPLECHEH_VERSION );

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
			update_post_meta( $post_id, '_splecheh_interpunction_issue_count', self::count_unresolved_in_report( $report ) );
			self::sync_chunk_progress_meta( $post_id, $report );
		}
		return $saved;
	}

	/**
	 * Aggregates data for the dashboard widget across all published posts of the enabled
	 * post types: total unresolved issues, distinct posts with at least one unresolved
	 * issue, and sentences ignored per post. Mirrors
	 * Splecheh_SpellCheckReport::get_dashboard_summary(), minus a global ignore list —
	 * interpunction ignores are per-post only (see filter_ignored_sentences()).
	 *
	 * Counted with two aggregate queries off the `_splecheh_interpunction_issue_count`
	 * meta (the same pattern Splecheh_InterpunctionCron uses), not by opening every
	 * post's report file: the dashboard renders on every admin page load, and a site
	 * with a few hundred checked posts would otherwise pay a few hundred file reads and
	 * JSON decodes for three numbers. The meta is kept in step with the reports by
	 * save_report() and update_report(), so it reflects Details-page edits immediately.
	 *
	 * @return array{unresolved_count: int, posts_with_issues: int, ignored_sentences: int}
	 */
	public static function get_dashboard_summary(): array {
		global $wpdb;

		$empty = [
			'unresolved_count'  => 0,
			'posts_with_issues' => 0,
			'ignored_sentences' => 0,
		];

		$enabled_types = splecheh_get_enabled_post_types();
		if ( empty( $enabled_types ) ) {
			return $empty;
		}

		$placeholders = implode( ',', array_fill( 0, count( $enabled_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS posts_with_issues,
				        COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 ) AS unresolved_count
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_splecheh_interpunction_issue_count'
				   AND CAST( pm.meta_value AS UNSIGNED ) > 0
				   AND p.post_status = 'publish'
				   AND p.post_type IN ($placeholders)",
				...$enabled_types
			),
			ARRAY_A
		);

		// Ignored sentences are a serialized array per post, so the rows have to be
		// unserialized to be counted — there are only ever as many rows as there are
		// posts someone has ignored a sentence in.
		$ignored_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_splecheh_interpunction_ignored_sentences'
				   AND p.post_status = 'publish'
				   AND p.post_type IN ($placeholders)",
				...$enabled_types
			)
		);
		// phpcs:enable

		$ignored_sentences = 0;
		foreach ( (array) $ignored_rows as $row ) {
			$ignored_sentences += count( (array) maybe_unserialize( $row ) );
		}

		return [
			'unresolved_count'  => (int) ( $totals['unresolved_count'] ?? 0 ),
			'posts_with_issues' => (int) ( $totals['posts_with_issues'] ?? 0 ),
			'ignored_sentences' => $ignored_sentences,
		];
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
		if ( empty( $report['issues'] ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $report['issues'] as $issue ) {
			if ( empty( $issue['resolved'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Replaces the first occurrence of $original found in $content with $fixed, or
	 * returns null when the sentence can't be located — so the caller can report a
	 * fix that didn't go through instead of silently marking it resolved.
	 *
	 * Matched case-insensitively, and whitespace-flexibly: the report's sentence text
	 * comes from Splecheh_ContentSplitter, which collapses every run of whitespace to a
	 * single space, so a source paragraph containing "word<newline>word" or a stray
	 * double space never matches the report's sentence literally. Each whitespace run in
	 * the sentence is therefore matched as \s+ against the raw content. The replacement
	 * text is inserted verbatim (which also normalizes the whitespace it replaces).
	 *
	 * A sentence broken up by inline markup (<strong>, <a>) is handled by a third stage,
	 * apply_fix_within_markup(), which edits the words in place instead of writing plain
	 * text over the tags. Anything that still can't be placed is reported to the caller
	 * as not applied rather than silently dropped.
	 */
	public static function apply_fix( string $content, string $original, string $fixed ): ?string {
		$original = trim( $original );
		if ( $original === '' ) {
			return null;
		}

		$pos = mb_stripos( $content, $original );
		if ( $pos !== false ) {
			return mb_substr( $content, 0, $pos ) . $fixed . mb_substr( $content, $pos + mb_strlen( $original ) );
		}

		$words = preg_split( '/\s+/u', $original, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return null;
		}

		$pattern = '/' . implode( '\s+', array_map( static fn( string $word ): string => preg_quote( $word, '/' ), $words ) ) . '/iu';

		if ( preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) === 1 ) {
			// preg_match offsets are byte offsets — pair them with substr(), not mb_substr().
			$offset = $matches[0][1];

			return substr( $content, 0, $offset ) . $fixed . substr( $content, $offset + strlen( $matches[0][0] ) );
		}

		return self::apply_fix_within_markup( $content, $words, $original, $fixed );
	}

	/**
	 * Applies a fix to a sentence that inline markup runs through — a link, <strong>,
	 * <em> — where the sentence exists in the rendered text but never as one string in
	 * the source, so the plain searches above can't find it.
	 *
	 * Writing the fixed sentence over the whole region would delete the markup, so the
	 * region's text is rewritten around the tags instead. Tags can interrupt the sentence
	 * anywhere, including in the middle of a word — `six years old</a>, kids` splits the
	 * token "old," in half — so the region is located character by character, allowing
	 * tags between any two characters.
	 *
	 * The fixed text is then distributed back over the text runs by aligning it with the
	 * original (LCS), which decides where each run's slice of the new text ends. That
	 * keeps a comma the model added outside the </a> it was typed after, rather than
	 * pushing it into the link.
	 *
	 * @param string[] $words The original sentence's words, already split on whitespace.
	 */
	private static function apply_fix_within_markup( string $content, array $words, string $original, string $fixed ): ?string {
		// The alignment below is O(n×m); a sentence is short, but don't let a pathological
		// "sentence" from a malformed report turn a page load into a minute of DP.
		if ( strlen( $original ) > 2000 || strlen( $fixed ) > 2000 ) {
			return null;
		}

		$pattern = self::markup_tolerant_pattern( $words );
		if ( $pattern === null || preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return null;
		}

		$start    = $matches[0][1];
		$region   = $matches[0][0];
		$segments = preg_split( '/(<[^>]*>)/', $region, -1, PREG_SPLIT_DELIM_CAPTURE );

		// The visible text of the region, and where each text run starts and ends in it.
		$plain  = '';
		$spans  = [];
		foreach ( $segments as $index => $segment ) {
			if ( $segment === '' || strncmp( $segment, '<', 1 ) === 0 ) {
				continue;
			}
			$spans[ $index ] = [ strlen( $plain ), strlen( $segment ) ];
			$plain          .= $segment;
		}

		if ( $plain === '' ) {
			return null;
		}

		$map = self::align_positions( $plain, $fixed );

		foreach ( $spans as $index => $span ) {
			[ $offset, $length ] = $span;
			$from                = $map[ $offset ];
			$to                  = $map[ $offset + $length ];
			$slice               = substr( $fixed, $from, $to - $from );

			// A text run that had words and now has none means the fix deleted the whole
			// content of a tag — leaving <em></em> behind and throwing away whatever the
			// emphasis was for. Refuse the fix and let it be reported instead.
			if ( trim( $segments[ $index ] ) !== '' && trim( $slice ) === '' ) {
				return null;
			}

			$segments[ $index ] = $slice;
		}

		$candidate = substr( $content, 0, $start ) . implode( '', $segments ) . substr( $content, $start + strlen( $region ) );

		// The remaining guards, because the alternative to catching a bad edit here is
		// finding it in a published post: every tag must have survived byte-for-byte, and
		// the text that replaced the region must add up to exactly the fixed sentence.
		if ( ! self::markup_survived( $content, $candidate ) ) {
			return null;
		}

		$rewritten = '';
		foreach ( $spans as $index => $span ) {
			$rewritten .= $segments[ $index ];
		}

		return $rewritten === $fixed ? $candidate : null;
	}

	/**
	 * Builds a pattern matching the sentence in raw HTML with tags allowed between any
	 * two characters, and any whitespace run matching any run of whitespace and tags.
	 *
	 * @param string[] $words
	 */
	private static function markup_tolerant_pattern( array $words ): ?string {
		if ( empty( $words ) ) {
			return null;
		}

		$tags  = '(?:<[^>]*>)*';
		$parts = [];

		foreach ( $words as $word ) {
			$characters = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
			if ( $characters === false || $characters === [] ) {
				return null;
			}

			$parts[] = implode( $tags, array_map( static fn( string $character ): string => preg_quote( $character, '/' ), $characters ) );
		}

		// Between words: whitespace and tags in any order, at least one whitespace.
		return '/' . implode( $tags . '\s(?:\s|<[^>]*>)*', $parts ) . '/iu';
	}

	/**
	 * Aligns two nearly-identical strings and returns, for every byte offset in $from
	 * (including the end), the matching offset in $to. Longest-common-subsequence based,
	 * so an inserted comma or a capitalised letter shifts the offsets after it without
	 * dragging the rest of the mapping out of place.
	 *
	 * @return int[] Offsets into $to, indexed by offset into $from.
	 */
	private static function align_positions( string $from, string $to ): array {
		$n = strlen( $from );
		$m = strlen( $to );

		$lcs = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$lcs[ $i ][ $j ] = strcasecmp( $from[ $i ], $to[ $j ] ) === 0
					? $lcs[ $i + 1 ][ $j + 1 ] + 1
					: max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
			}
		}

		$map = [];
		$i   = 0;
		$j   = 0;

		while ( $i < $n && $j < $m ) {
			$map[ $i ] = $j;

			if ( strcasecmp( $from[ $i ], $to[ $j ] ) === 0 ) {
				$i++;
				$j++;
			} elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
				$i++; // Character dropped by the fix.
			} else {
				$j++; // Character added by the fix.
			}
		}

		// Anything left over on either side maps to the end, so the slices still add up.
		while ( $i <= $n ) {
			$map[ $i ] = $m;
			$i++;
		}

		return $map;
	}

	/**
	 * Guards the in-place edit above: the rewrite must not have added, removed or altered
	 * a single tag.
	 */
	private static function markup_survived( string $before, string $after ): bool {
		preg_match_all( '/<[^>]*>/', $before, $before_tags );
		preg_match_all( '/<[^>]*>/', $after, $after_tags );

		return $before_tags[0] === $after_tags[0];
	}

	/**
	 * Maps a report's issue entries into the shape the Details page's JS uses to
	 * redraw the issues table in place (e.g. after an AJAX re-run).
	 *
	 * @param array[] $issues
	 * @return array[]
	 */
	public static function format_issues_for_details( array $issues ): array {
		return array_map(
			static function ( array $issue ): array {
				return [
					'original'    => $issue['original'],
					'fixed'       => $issue['fixed'],
					'explanation' => $issue['explanation'],
					'resolved'    => ! empty( $issue['resolved'] ),
					'diff'        => self::diff_highlight( $issue['original'], $issue['fixed'] ),
				];
			},
			$issues
		);
	}

	/**
	 * Renders $fixed as HTML with the words that differ from $original wrapped in
	 * <strong>, so a reviewer can see at a glance what an LLM actually changed
	 * (e.g. just a capitalization or a missing comma) without re-reading the whole
	 * sentence. Word-level diff via LCS — good enough for the small, mostly-similar
	 * original/fixed pairs Interpunction Check deals with; not meant as a general
	 * text-diff tool. Already escapes its output — safe to echo directly.
	 */
	public static function diff_highlight( string $original, string $fixed ): string {
		$orig_words  = preg_split( '/(\s+)/u', trim( $original ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$fixed_words = preg_split( '/(\s+)/u', trim( $fixed ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( $orig_words === false || $fixed_words === false ) {
			return esc_html( $fixed );
		}

		$common_fixed_indices = array_flip( self::lcs_word_indices( $orig_words, $fixed_words ) );

		$html = '';
		foreach ( $fixed_words as $i => $word ) {
			if ( trim( $word ) === '' ) {
				$html .= $word;
				continue;
			}
			$escaped = esc_html( $word );
			$html   .= isset( $common_fixed_indices[ $i ] ) ? $escaped : '<strong>' . $escaped . '</strong>';
		}

		return $html;
	}

	/**
	 * Returns the indices into $b of a longest common subsequence between word
	 * arrays $a and $b (classic O(n*m) DP LCS) — used by diff_highlight() to know
	 * which words in the fixed text are unchanged from the original.
	 *
	 * @param string[] $a
	 * @param string[] $b
	 * @return int[]
	 */
	private static function lcs_word_indices( array $a, array $b ): array {
		$a = array_values( $a );
		$b = array_values( $b );
		$n = count( $a );
		$m = count( $b );

		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = $a[ $i ] === $b[ $j ]
					? $dp[ $i + 1 ][ $j + 1 ] + 1
					: max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}

		$indices = [];
		$i       = 0;
		$j       = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$indices[] = $j;
				$i++;
				$j++;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				$i++;
			} else {
				$j++;
			}
		}

		return $indices;
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

		$dir = $upload_dir['basedir'] . '/splecheh-interpunction';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Prevent directory listing.
			file_put_contents( $dir . '/index.php', '<?php // silence is golden' );
		}

		$uuid = wp_generate_uuid4();
		$path = $dir . '/' . $uuid . '.json';

		// Remove old report file if it exists.
		$old_uuid = get_post_meta( $post_id, '_splecheh_interpunction_report_uuid', true );
		if ( $old_uuid && file_exists( $dir . '/' . $old_uuid . '.json' ) ) {
			@unlink( $dir . '/' . $old_uuid . '.json' );
		}

		$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( file_put_contents( $path, $json ) === false ) {
			return new WP_Error( 'write_failed', __( 'Could not write interpunction check report.', 'splecheh' ) );
		}

		update_post_meta( $post_id, '_splecheh_interpunction_report_uuid', $uuid );
		update_post_meta( $post_id, '_splecheh_interpunction_checked_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, '_splecheh_interpunction_version', SPLECHEH_VERSION );
		update_post_meta( $post_id, '_splecheh_interpunction_issue_count', self::count_unresolved_in_report( $report ) );
		self::sync_chunk_progress_meta( $post_id, $report );

		return true;
	}

	/**
	 * Mirrors a report's chunks_processed/chunks_total into post meta, so the list
	 * table can show progress without reading the report JSON per row. Missing keys
	 * (reports saved before this field existed) clear the meta rather than writing 0s,
	 * so old reports read back as "unknown" rather than falsely "0/0 complete".
	 */
	private static function sync_chunk_progress_meta( int $post_id, array $report ): void {
		if ( isset( $report['chunks_processed'], $report['chunks_total'] ) ) {
			update_post_meta( $post_id, '_splecheh_interpunction_chunks_processed', (int) $report['chunks_processed'] );
			update_post_meta( $post_id, '_splecheh_interpunction_chunks_total', (int) $report['chunks_total'] );
		} else {
			delete_post_meta( $post_id, '_splecheh_interpunction_chunks_processed' );
			delete_post_meta( $post_id, '_splecheh_interpunction_chunks_total' );
		}
	}
}
