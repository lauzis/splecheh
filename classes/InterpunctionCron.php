<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_InterpunctionCron {

	const HOOK        = 'splecheh_bg_interpunction_check';
	const LOCK_KEY    = 'splecheh_interpunction_bg_running';
	const SUMMARY_KEY = 'splecheh_interpunction_bg_summary';

	/**
	 * Syncs the scheduled cron event with the current settings.
	 * Call after settings are saved or on plugin activation. Also clears the
	 * event when the Interpunction Check feature itself is disabled, even if
	 * the background toggle is still on, so a re-enable of the feature alone
	 * doesn't silently resurrect a stale schedule.
	 */
	public static function sync(): void {
		$enabled  = (bool) get_option( '_splecheh_interpunction_bg_enabled' );
		$interval = (string) get_option( '_splecheh_interpunction_bg_interval', 'splecheh_10min' );

		$next = wp_next_scheduled( self::HOOK );

		if ( ! $enabled || ! splecheh_interpunction_enabled() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		if ( $next ) {
			$event = wp_get_scheduled_event( self::HOOK );
			if ( $event && $event->schedule === $interval ) {
				return;
			}
			wp_clear_scheduled_hook( self::HOOK );
		}

		wp_schedule_event( time(), $interval, self::HOOK );
	}

	/**
	 * Clears the cron event and any running lock. Call on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Processes one batch of posts that need an interpunction check.
	 * Skips if a previous batch is still running (transient lock).
	 */
	public static function run_batch(): void {
		Splecheh_Logs::addLog( 'cron', 'BG interpunction check triggered.', [] );

		if ( get_transient( self::LOCK_KEY ) ) {
			Splecheh_Logs::addLog( 'cron', 'BG interpunction check skipped: a previous run is still in progress.', [] );
			return;
		}
		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$batch_size    = max( 1, (int) get_option( '_splecheh_interpunction_bg_batch_size', 1 ) );
			$enabled_types = splecheh_get_enabled_post_types();

			if ( empty( $enabled_types ) ) {
				Splecheh_Logs::addLog( 'cron', 'BG interpunction check skipped: no post types enabled.', [] );
				return;
			}

			$post_ids     = self::query_posts_needing_check( $enabled_types, $batch_size );
			$issues_found = 0;

			foreach ( $post_ids as $post_id ) {
				$result = Splecheh_InterpunctionReport::run( $post_id );
				if ( is_wp_error( $result ) ) {
					Splecheh_Logs::addLog( 'cron', "BG interpunction check failed for post {$post_id}", [ 'error' => $result->get_error_message() ] );
				} else {
					$issues_found += count( $result['issues'] );
				}
			}

			$posts_pending = self::count_posts_needing_check( $enabled_types );

			update_option(
				self::SUMMARY_KEY,
				[
					'last_run'      => gmdate( 'c' ),
					'issues_found'  => $issues_found,
					'posts_pending' => $posts_pending,
				],
				false
			);

			Splecheh_Logs::addLog(
				'cron',
				sprintf(
					'BG interpunction check: %d processed, %d issues, %d pending.',
					count( $post_ids ),
					$issues_found,
					$posts_pending
				),
				[]
			);
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Builds the JOIN/condition needed to also require Spell Check to be clean
	 * (up to date, zero unresolved issues) before a post is eligible, when that
	 * setting is enabled. Returns empty pieces (no-op) when it's disabled.
	 *
	 * Essential, not just an optimization: with the default batch size of 1, if
	 * this filtering happened only inside Splecheh_InterpunctionReport::run(),
	 * the cron would keep re-selecting the same oldest post every tick — even
	 * though it always fails the Spell Check gate — and never make progress on
	 * any other post.
	 *
	 * @return array{join: string, condition: string}
	 */
	private static function spellcheck_clean_sql_parts(): array {
		if ( ! splecheh_interpunction_require_spellcheck_clean_enabled() ) {
			return [ 'join' => '', 'condition' => '' ];
		}

		global $wpdb;

		return [
			'join'      => "INNER JOIN {$wpdb->postmeta} pm_sc_checked ON ( pm_sc_checked.post_id = p.ID AND pm_sc_checked.meta_key = '_splecheh_checked_at' )
			 INNER JOIN {$wpdb->postmeta} pm_sc_issues ON ( pm_sc_issues.post_id = p.ID AND pm_sc_issues.meta_key = '_splecheh_issue_count' )",
			'condition' => " AND pm_sc_checked.meta_value >= p.post_modified_gmt AND CAST( pm_sc_issues.meta_value AS UNSIGNED ) = 0",
		];
	}

	/**
	 * Returns IDs of posts that have never been interpunction-checked or are outdated.
	 *
	 * @param string[] $post_types
	 * @return int[]
	 */
	private static function query_posts_needing_check( array $post_types, int $limit ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$spellcheck   = self::spellcheck_clean_sql_parts();
		$params       = array_merge( $post_types, [ $limit ] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			     ON pm.post_id = p.ID AND pm.meta_key = '_splecheh_interpunction_checked_at'
			 {$spellcheck['join']}
			 WHERE p.post_type IN ($placeholders)
			   AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR p.post_modified_gmt > pm.meta_value)
			   {$spellcheck['condition']}
			 ORDER BY p.post_modified ASC
			 LIMIT %d",
			...$params
		);
		// phpcs:enable

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Counts posts that still need an interpunction check.
	 *
	 * @param string[] $post_types
	 */
	private static function count_posts_needing_check( array $post_types ): int {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$spellcheck   = self::spellcheck_clean_sql_parts();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			     ON pm.post_id = p.ID AND pm.meta_key = '_splecheh_interpunction_checked_at'
			 {$spellcheck['join']}
			 WHERE p.post_type IN ($placeholders)
			   AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR p.post_modified_gmt > pm.meta_value)
			   {$spellcheck['condition']}",
			...$post_types
		);
		// phpcs:enable

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Returns the stored background task summary.
	 *
	 * @return array{last_run: string|null, issues_found: int, posts_pending: int}
	 */
	public static function get_summary(): array {
		$stored = get_option( self::SUMMARY_KEY, [] );
		return array_merge(
			[ 'last_run' => null, 'issues_found' => 0, 'posts_pending' => 0 ],
			(array) $stored
		);
	}

	/**
	 * Returns true if the background task is currently enabled.
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( '_splecheh_interpunction_bg_enabled' );
	}
}
