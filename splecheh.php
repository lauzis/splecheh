<?php
/**
 * Plugin Name: Splecheh - WordPress spellcheck plugin
 * Plugin URI:  https://github.com/lauzis/splecheh
 * Description: Run spell check on all articles and post types to find spelling errors.
 * Version:     0.2.0
 * Author:      Aivars Lauzis
 * Text Domain: splecheh
 * License:     MIT
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLECHEH_VERSION', '0.2.0' );
define( 'SPLECHEH_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPLECHEH_LOG_PATH', SPLECHEH_DIR . 'logs' );
define( 'SPLECHEH_PLUGIN_FILE', __FILE__ );

require_once SPLECHEH_DIR . 'classes/Logs.php';
require_once SPLECHEH_DIR . 'classes/Notification.php';
require_once SPLECHEH_DIR . 'classes/NotificationManager.php';

// Bootstrap Carbon Fields after all themes/plugins are loaded.
add_action(
	'after_setup_theme',
	function (): void {
		if ( ! file_exists( SPLECHEH_DIR . 'vendor/autoload.php' ) ) {
			return;
		}
		require_once SPLECHEH_DIR . 'vendor/autoload.php';
		\Carbon_Fields\Carbon_Fields::boot();
	}
);

// Set defaults (post and page enabled) on first activation.
register_activation_hook( __FILE__, 'splecheh_activate' );

function splecheh_activate(): void {
	// Carbon Fields stores theme_options fields under _{field_name} in wp_options.
	add_option( '_splecheh_post_types', [ 'post', 'page' ] );

	if ( ! file_exists( SPLECHEH_LOG_PATH ) ) {
		wp_mkdir_p( SPLECHEH_LOG_PATH );
		file_put_contents( SPLECHEH_LOG_PATH . '/index.php', '<?php // silence is golden' );
	}
	Splecheh_Logs::addLog( 'plugin', 'Plugin activated', [ 'version' => SPLECHEH_VERSION ] );
}

add_action( 'admin_notices', [ 'Splecheh_NotificationManager', 'render' ] );
add_action( 'admin_enqueue_scripts', [ 'Splecheh_NotificationManager', 'enqueue_assets' ] );
add_action( 'wp_ajax_splecheh_dismiss_notification', [ 'Splecheh_NotificationManager', 'handle_dismiss' ] );

add_action( 'admin_menu', 'splecheh_register_menu' );

function splecheh_register_menu(): void {
	add_menu_page(
		__( 'Splecheh', 'splecheh' ),
		__( 'Splecheh', 'splecheh' ),
		'edit_posts',
		'splecheh',
		'splecheh_page_spell_check',
		'dashicons-editor-spellcheck',
		80
	);

	add_submenu_page(
		'splecheh',
		__( 'Spell Check', 'splecheh' ),
		__( 'Spell Check', 'splecheh' ),
		'edit_posts',
		'splecheh',
		'splecheh_page_spell_check'
	);

	add_submenu_page(
		'splecheh',
		__( 'Help', 'splecheh' ),
		__( 'Help', 'splecheh' ),
		'edit_posts',
		'splecheh-help',
		'splecheh_page_help'
	);

	add_submenu_page(
		'splecheh',
		__( 'Logs', 'splecheh' ),
		__( 'Logs', 'splecheh' ),
		'edit_posts',
		'splecheh-logs',
		'splecheh_page_logs'
	);
}

// Register the Settings page via Carbon Fields — replaces the manual submenu stub.
add_action( 'carbon_fields_register_fields', 'splecheh_register_settings_fields' );

function splecheh_register_settings_fields(): void {
	$post_types = get_post_types( [ 'public' => true ], 'objects' );
	$options    = [];
	foreach ( $post_types as $type ) {
		$options[ $type->name ] = $type->label;
	}

	\Carbon_Fields\Container::make( 'theme_options', __( 'Settings', 'splecheh' ) )
		->set_page_parent( 'splecheh' )
		->add_fields(
			[
				\Carbon_Fields\Field::make( 'set', 'splecheh_post_types', __( 'Post Types to Spellcheck', 'splecheh' ) )
					->set_options( $options )
					->set_help_text( __( 'Select which post types should be checked for spelling errors. Posts and Pages are enabled by default.', 'splecheh' ) ),
			]
		);
}

/**
 * Returns the post types currently enabled for spellchecking.
 * Falls back to post and page when Carbon Fields is not yet loaded.
 *
 * @return string[]
 */
function splecheh_get_enabled_post_types(): array {
	if ( ! function_exists( 'carbon_get_theme_option' ) ) {
		return [ 'post', 'page' ];
	}
	$types = carbon_get_theme_option( 'splecheh_post_types' );
	return ! empty( $types ) ? (array) $types : [ 'post', 'page' ];
}

function splecheh_page_spell_check(): void {
	echo '<div class="wrap"><h1>' . esc_html__( 'Spell Check', 'splecheh' ) . '</h1></div>';
}

function splecheh_page_help(): void {
	echo '<div class="wrap"><h1>' . esc_html__( 'Help', 'splecheh' ) . '</h1></div>';
}

function splecheh_page_logs(): void {
	require_once SPLECHEH_DIR . 'templates/logs.php';
}
