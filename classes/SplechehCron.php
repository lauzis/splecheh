<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_Cron {

	const HOOK        = 'splecheh_bg_spell_check';
	const LOCK_KEY    = 'splecheh_bg_running';
	const SUMMARY_KEY = 'splecheh_bg_summary';

	/**
	 * Merges custom cron schedules into WordPress schedules.
	 */
	public static function cron_schedules( array $schedules ): array {
		$custom = [
			'splecheh_1min'  => [ 'interval' => 60,    'display' => __( 'Every 1 minute', 'splecheh' ) ],
			'splecheh_5min'  => [ 'interval' => 300,   'display' => __( 'Every 5 minutes', 'splecheh' ) ],
			'splecheh_10min' => [ 'interval' => 600,   'display' => __( 'Every 10 minutes', 'splecheh' ) ],
			'splecheh_15min' => [ 'interval' => 900,   'display' => __( 'Every 15 minutes', 'splecheh' ) ],
			'splecheh_30min' => [ 'interval' => 1800,  'display' => __( 'Every 30 minutes', 'splecheh' ) ],
			'splecheh_1h'    => [ 'interval' => 3600,  'display' => __( 'Every 1 hour', 'splecheh' ) ],
			'splecheh_2h'    => [ 'interval' => 7200,  'display' => __( 'Every 2 hours', 'splecheh' ) ],
			'splecheh_4h'    => [ 'interval' => 14400, 'display' => __( 'Every 4 hours', 'splecheh' ) ],
			'splecheh_8h'    => [ 'interval' => 28800, 'display' => __( 'Every 8 hours', 'splecheh' ) ],
			'splecheh_12h'   => [ 'interval' => 43200, 'display' => __( 'Every 12 hours', 'splecheh' ) ],
			'splecheh_24h'   => [ 'interval' => 86400, 'display' => __( 'Every 24 hours', 'splecheh' ) ],
		];
		return array_merge( $schedules, $custom );
	}

	/**
	 * Syncs the scheduled cron event with the current settings.
	 * Call after settings are saved or on plugin activation.
	 */
	public static function sync(): void {
		$enabled  = (bool) get_option( '_splecheh_bg_enabled' );
		$interval = (string) get_option( '_splecheh_bg_interval', 'splecheh_1h' );

		$next = wp_next_scheduled( self::HOOK );

		if ( ! $enabled ) {
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
	 * Processes one batch of posts that need a spell check.
	 * Skips if a previous batch is still running (transient lock).
	 */
	public static function run_batch(): void {
		Splecheh_Logs::addLog( 'cron', 'BG spell check triggered.', [] );

		if ( get_transient( self::LOCK_KEY ) ) {
			Splecheh_Logs::addLog( 'cron', 'BG spell check skipped: a previous run is still in progress.', [] );
			return;
		}
		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$batch_size    = max( 1, (int) get_option( '_splecheh_bg_batch_size', 50 ) );
			$enabled_types = splecheh_get_enabled_post_types();

			if ( empty( $enabled_types ) ) {
				Splecheh_Logs::addLog( 'cron', 'BG spell check skipped: no post types enabled.', [] );
				return;
			}

			$post_ids     = self::query_posts_needing_check( $enabled_types, $batch_size );
			$issues_found = 0;

			foreach ( $post_ids as $post_id ) {
				$result = Splecheh_SpellCheckReport::run( $post_id );
				if ( is_wp_error( $result ) ) {
					Splecheh_Logs::addLog( 'cron', "BG spell check failed for post {$post_id}", [ 'error' => $result->get_error_message() ] );
				} else {
					$issues_found += count( $result['errors'] );
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
					'BG spell check: %d processed, %d issues, %d pending.',
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
	 * Returns IDs of posts that have never been checked or are outdated.
	 *
	 * @param string[] $post_types
	 * @return int[]
	 */
	private static function query_posts_needing_check( array $post_types, int $limit ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params       = array_merge( $post_types, [ $limit ] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			     ON pm.post_id = p.ID AND pm.meta_key = '_splecheh_checked_at'
			 WHERE p.post_type IN ($placeholders)
			   AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR p.post_modified_gmt > pm.meta_value)
			 ORDER BY p.post_modified ASC
			 LIMIT %d",
			...$params
		);
		// phpcs:enable

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Counts posts that still need a spell check.
	 *
	 * @param string[] $post_types
	 */
	private static function count_posts_needing_check( array $post_types ): int {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			     ON pm.post_id = p.ID AND pm.meta_key = '_splecheh_checked_at'
			 WHERE p.post_type IN ($placeholders)
			   AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR p.post_modified_gmt > pm.meta_value)",
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
		return (bool) get_option( '_splecheh_bg_enabled' );
	}
}
