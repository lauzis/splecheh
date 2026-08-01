<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splecheh's logging entry point.
 *
 * The implementation lives in the shared lauzis/wp-plugin-packages package; this class is
 * a thin facade that keeps Splecheh's own API, so the call sites throughout the
 * plugin are unchanged.
 */
class Splecheh_Logs {

	/** Main log stream — also the log filename prefix. */
	private const SLUG = 'splecheh';

	/** Separate audit stream for auto-applied content fixes. */
	private const AUTO_APPLY_CHANNEL = 'auto-apply';

	/**
	 * Returns the shared logger, or null when the wp-plugin-packages package is not
	 * installed. Splecheh already treats a missing vendor/ as a supported state
	 * (it shows an admin notice), so logging degrades to a no-op rather than a
	 * fatal.
	 *
	 * @return \Lauzis\WpPackages\Logs\Logger|null
	 */
	private static function logger() {
		if ( ! class_exists( 'WpPackages_Registry' ) ) {
			return null;
		}

		return WpPackages_Registry::logger(
			self::SLUG,
			[
				'dir'     => SPLECHEH_LOG_PATH,
				'enabled' => static function (): bool {
					return ! function_exists( 'splecheh_logs_enabled' ) || splecheh_logs_enabled();
				},
			]
		);
	}

	public static function addLog( string $action, string $message, array $context = [] ): void {
		$logger = self::logger();

		if ( $logger ) {
			$logger->add( $action, $message, $context );
		}
	}

	/**
	 * Writes an entry to the separate auto-apply audit log (auto-apply-YYYY-MM-DD.log),
	 * kept apart from the main splecheh-*.log files so auto-applied content fixes can be
	 * tracked on their own. Gated by the same "Enable Logs" setting as addLog().
	 *
	 * These entries carry no action label, so the line is "[timestamp] message".
	 */
	public static function addAutoApplyLog( string $message, array $context = [] ): void {
		$logger = self::logger();

		if ( $logger ) {
			$logger->add( '', $message, $context, self::AUTO_APPLY_CHANNEL );
		}
	}

	/** Deletes the main log files. The auto-apply audit stream is left intact. */
	public static function clearLogs(): void {
		$logger = self::logger();

		if ( $logger ) {
			$logger->clear();
		}
	}

	/** Total number of entries across the main log files. */
	public static function getLogCount(): int {
		$logger = self::logger();

		return $logger ? $logger->count() : 0;
	}

	/**
	 * Main log filenames, newest first. Returns basenames — callers resolve them
	 * against SPLECHEH_LOG_PATH.
	 *
	 * @return string[]
	 */
	public static function getLogFiles(): array {
		$logger = self::logger();
		if ( ! $logger ) {
			return [];
		}

		return array_column( $logger->files(), 'name' );
	}
}
