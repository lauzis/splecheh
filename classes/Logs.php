<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Splecheh_Logs {

	private static function ensure_log_dir(): bool {
		if ( ! file_exists( SPLECHEH_LOG_PATH ) ) {
			wp_mkdir_p( SPLECHEH_LOG_PATH );
			$index = SPLECHEH_LOG_PATH . '/index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, '<?php // silence is golden' );
			}
		}
		return is_dir( SPLECHEH_LOG_PATH ) && is_writable( SPLECHEH_LOG_PATH );
	}

	public static function addLog( string $action, string $message, array $context = [] ): void {
		if ( function_exists( 'splecheh_logs_enabled' ) && ! splecheh_logs_enabled() ) {
			return;
		}
		if ( ! self::ensure_log_dir() ) {
			return;
		}
		$file = SPLECHEH_LOG_PATH . '/splecheh-' . gmdate( 'Y-m-d' ) . '.log';
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [' . $action . '] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $context );
		}
		file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Writes an entry to the separate auto-apply audit log (auto-apply-YYYY-MM-DD.log),
	 * kept apart from the main splecheh-*.log files so auto-applied content fixes can be
	 * tracked on their own. Gated by the same "Enable Logs" setting as addLog().
	 */
	public static function addAutoApplyLog( string $message, array $context = [] ): void {
		if ( function_exists( 'splecheh_logs_enabled' ) && ! splecheh_logs_enabled() ) {
			return;
		}
		if ( ! self::ensure_log_dir() ) {
			return;
		}
		$file = SPLECHEH_LOG_PATH . '/auto-apply-' . gmdate( 'Y-m-d' ) . '.log';
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $context );
		}
		file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	public static function clearLogs(): void {
		foreach ( self::getLogFiles() as $file ) {
			@unlink( SPLECHEH_LOG_PATH . '/' . $file );
		}
	}

	public static function getLogCount(): int {
		return count( self::getLogFiles() );
	}

	public static function getLogFiles(): array {
		if ( ! is_dir( SPLECHEH_LOG_PATH ) ) {
			return [];
		}
		$files = glob( SPLECHEH_LOG_PATH . '/splecheh-*.log' );
		if ( ! $files ) {
			return [];
		}
		$names = array_map( 'basename', $files );
		rsort( $names );
		return $names;
	}
}
