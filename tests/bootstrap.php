<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * These are lightweight unit tests that stub the minimal WordPress
 * functions/classes used by the classes under test, rather than booting a
 * full WordPress test environment.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors     = [];
		private array $error_data = [];

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( $code === '' ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( $data !== '' ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_message( string $code = '' ): string {
			if ( $code === '' ) {
				$code = array_key_first( $this->errors ) ?? '';
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( string $code = '' ) {
			if ( $code === '' ) {
				$code = array_key_first( $this->error_data ) ?? '';
			}
			return $this->error_data[ $code ] ?? null;
		}
	}
}

require_once dirname( __DIR__ ) . '/classes/SpellCheckReport.php';
