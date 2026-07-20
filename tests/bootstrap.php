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
define( 'SPLECHEH_VERSION', '0.14.0-test' );
define( 'SPLECHEH_LOG_PATH', sys_get_temp_dir() . '/splecheh-tests/logs' );

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

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
		$string = preg_replace( '/<(script|style)[^>]*?>.*?<\/\\1>/si', '', $string ) ?? $string;
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string ) ?? $string;
		}
		return trim( $string );
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

		public function add_data( $data, string $code = '' ): void {
			if ( $code === '' ) {
				$code = array_key_first( $this->errors ) ?? '';
			}
			$this->error_data[ $code ] = $data;
		}
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		return $GLOBALS['__splecheh_test_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : [] );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['__splecheh_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( $GLOBALS['__splecheh_test_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	#[\AllowDynamicProperties]
	class WP_Post {
		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ) {
		return $GLOBALS['__splecheh_test_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'splecheh_invalidate_on_version_change_enabled' ) ) {
	function splecheh_invalidate_on_version_change_enabled(): bool {
		return $GLOBALS['__splecheh_test_options']['invalidate_on_version_change'] ?? false;
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		return array_key_exists( $meta_key, $GLOBALS['__splecheh_test_post_meta'][ $object_id ] ?? [] );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return [
			'basedir' => sys_get_temp_dir() . '/splecheh-tests',
			'baseurl' => 'http://example.test/wp-content/uploads',
			'error'   => false,
		];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $dir ): bool {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf( 'test-uuid-%s', bin2hex( random_bytes( 8 ) ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['__splecheh_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['__splecheh_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'en_US';
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback ): bool {
		$GLOBALS['__splecheh_test_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $tag ): bool {
		unset( $GLOBALS['__splecheh_test_filters'][ $tag ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		foreach ( $GLOBALS['__splecheh_test_filters'][ $tag ] ?? [] as $callback ) {
			$value = $callback( $value );
		}
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/classes/Logs.php';
require_once dirname( __DIR__ ) . '/classes/AutoApplyList.php';
require_once dirname( __DIR__ ) . '/classes/TermIgnoreList.php';
require_once dirname( __DIR__ ) . '/classes/SpellCheckReport.php';
require_once dirname( __DIR__ ) . '/classes/InterpunctionIgnoreList.php';
require_once dirname( __DIR__ ) . '/classes/InterpunctionReport.php';
require_once dirname( __DIR__ ) . '/classes/InterpunctionBackend.php';
