<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_InterpunctionReport {

	const DEFAULT_PROMPT = 'You are a professional {language} editor. Your only task is to fix the punctuation and capitalization of the provided text. Keep the original text content exactly as is. Output only the corrected text.';

	/**
	 * Runs the interpunction check on a post, saves the JSON report, and updates post meta.
	 *
	 * @return array|WP_Error Report array on success, WP_Error on failure.
	 */
	public static function run( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'splecheh' ) );
		}

		$language = splecheh_get_language_code( $post_id );

		$plain_text = Splecheh_SpellCheckReport::prepare_text( $post->post_content, splecheh_ignore_shortcodes_enabled() );
		$sentences  = self::split_into_sentences( $plain_text );

		if ( empty( $sentences ) ) {
			$issues = [];
		} else {
			$results = Splecheh_InterpunctionBackend::check( $sentences, $language );
			if ( is_wp_error( $results ) ) {
				return $results;
			}
			$issues = self::filter_ignored_sentences( $post_id, self::build_issues( $results ), $language );
		}

		$report = [
			'post_id'    => $post_id,
			'post_title' => $post->post_title,
			'checked_at' => gmdate( 'c' ),
			'language'   => $language,
			'issues'     => $issues,
		];

		$result = self::save_report( $post_id, $report );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $report;
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
	 * the original, since an unchanged sentence isn't an issue to review.
	 */
	private static function build_issues( array $results ): array {
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
	 * Removes issues for sentences ignored for this post (post meta) or globally for its language.
	 */
	private static function filter_ignored_sentences( int $post_id, array $issues, string $language ): array {
		$post_ignored   = (array) get_post_meta( $post_id, '_splecheh_interpunction_ignored_sentences', true );
		$global_ignored = Splecheh_InterpunctionIgnoreList::get_sentences( $language );
		$ignored        = array_merge( $post_ignored, $global_ignored );

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
	 * Replaces the first occurrence of $original found in $content with $fixed.
	 * Matched case-insensitively (HTML content may differ in whitespace/entities from
	 * the plain-text sentence the LLM saw), but the replacement text is inserted verbatim.
	 */
	public static function apply_fix( string $content, string $original, string $fixed ): string {
		$pos = mb_stripos( $content, $original );
		if ( $pos === false ) {
			return $content;
		}
		return mb_substr( $content, 0, $pos ) . $fixed . mb_substr( $content, $pos + mb_strlen( $original ) );
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
				];
			},
			$issues
		);
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

		return true;
	}
}
