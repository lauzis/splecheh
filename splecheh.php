<?php
/**
 * Plugin Name: Splecheh - WordPress spellcheck plugin
 * Plugin URI:  https://github.com/lauzis/splecheh
 * Description: Run spell check on all articles and post types to find spelling errors.
 * Version:     0.3.0
 * Author:      Aivars Lauzis
 * Text Domain: splecheh
 * License:     MIT
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLECHEH_VERSION', '0.3.0' );
define( 'SPLECHEH_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPLECHEH_LOG_PATH', SPLECHEH_DIR . 'logs' );
define( 'SPLECHEH_PLUGIN_FILE', __FILE__ );

require_once SPLECHEH_DIR . 'classes/Logs.php';
require_once SPLECHEH_DIR . 'classes/Notification.php';
require_once SPLECHEH_DIR . 'classes/NotificationManager.php';
require_once SPLECHEH_DIR . 'classes/SpellCheckReport.php';
require_once SPLECHEH_DIR . 'classes/SplechehCron.php';

add_filter( 'cron_schedules', [ 'Splecheh_Cron', 'cron_schedules' ] );
add_action( Splecheh_Cron::HOOK, [ 'Splecheh_Cron', 'run_batch' ] );

// Bootstrap Carbon Fields after all themes/plugins are loaded.
add_action(
	'after_setup_theme',
	function (): void {
		if ( ! file_exists( SPLECHEH_DIR . 'vendor/autoload.php' ) ) {
			return;
		}
		require_once SPLECHEH_DIR . 'vendor/autoload.php';
		\Carbon_Fields\Carbon_Fields::boot();

		if ( ! \Composer\InstalledVersions::isInstalled( 'tigitz/php-spellchecker' ) ) {
			Splecheh_NotificationManager::register(
				new Splecheh_Notification(
					'missing-php-spellchecker',
					sprintf(
						/* translators: %s: composer require command */
						__( '<strong>Splecheh:</strong> The spell-check library is missing. Run <code>%s</code> in your plugin directory to install it.', 'splecheh' ),
						'composer require tigitz/php-spellchecker'
					),
					'error',
					'one-time'
				)
			);
		}
	}
);

// Set defaults on first activation and clear cron on deactivation.
register_activation_hook( __FILE__, 'splecheh_activate' );
register_deactivation_hook( __FILE__, 'splecheh_deactivate' );

function splecheh_activate(): void {
	// Carbon Fields stores theme_options fields under _{field_name} in wp_options.
	add_option( '_splecheh_post_types', [ 'post', 'page' ] );

	if ( ! file_exists( SPLECHEH_LOG_PATH ) ) {
		wp_mkdir_p( SPLECHEH_LOG_PATH );
		file_put_contents( SPLECHEH_LOG_PATH . '/index.php', '<?php // silence is golden' );
	}
	Splecheh_Logs::addLog( 'plugin', 'Plugin activated', [ 'version' => SPLECHEH_VERSION ] );
	Splecheh_Cron::sync();
}

function splecheh_deactivate(): void {
	Splecheh_Cron::deactivate();
}

add_action( 'admin_notices', [ 'Splecheh_NotificationManager', 'render' ] );
add_action( 'admin_enqueue_scripts', [ 'Splecheh_NotificationManager', 'enqueue_assets' ] );
add_action( 'admin_enqueue_scripts', 'splecheh_enqueue_spellcheck_assets' );
add_action( 'wp_ajax_splecheh_dismiss_notification', [ 'Splecheh_NotificationManager', 'handle_dismiss' ] );
add_action( 'wp_ajax_splecheh_run', 'splecheh_ajax_run_spellcheck' );
add_action( 'wp_ajax_splecheh_run_now', 'splecheh_ajax_run_now' );
add_action( 'carbon_fields_theme_options_container_saved', 'splecheh_sync_bg_cron' );

function splecheh_sync_bg_cron(): void {
	Splecheh_Cron::sync();
}

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

	// Default language: first part of WordPress locale (e.g. "en" from "en_US").
	$locale           = get_locale();
	$default_language = explode( '_', $locale )[0];

	\Carbon_Fields\Container::make( 'theme_options', __( 'Settings', 'splecheh' ) )
		->set_page_parent( 'splecheh' )
		->add_fields(
			[
				\Carbon_Fields\Field::make( 'set', 'splecheh_post_types', __( 'Post Types to Spellcheck', 'splecheh' ) )
					->set_options( $options )
					->set_help_text( __( 'Select which post types should be checked for spelling errors. Posts and Pages are enabled by default.', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'text', 'splecheh_language', __( 'Spell Check Language', 'splecheh' ) )
					->set_default_value( $default_language )
					->set_help_text(
						sprintf(
							/* translators: %s: default locale code */
							__( 'Language code for the spell checker (e.g. "en", "fr", "de"). Defaults to the WordPress site language (%s).', 'splecheh' ),
							$default_language
						)
					),

				\Carbon_Fields\Field::make( 'separator', 'splecheh_bg_separator', __( 'Background Spell Check', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'checkbox', 'splecheh_bg_enabled', __( 'Enable Background Spell Check', 'splecheh' ) )
					->set_help_text( __( 'When enabled, spell check runs automatically in the background according to the schedule below.', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'select', 'splecheh_bg_interval', __( 'Schedule Interval', 'splecheh' ) )
					->set_options(
						[
							'splecheh_1min'  => __( 'Every 1 minute', 'splecheh' ),
							'splecheh_5min'  => __( 'Every 5 minutes', 'splecheh' ),
							'splecheh_10min' => __( 'Every 10 minutes', 'splecheh' ),
							'splecheh_15min' => __( 'Every 15 minutes', 'splecheh' ),
							'splecheh_30min' => __( 'Every 30 minutes', 'splecheh' ),
							'splecheh_1h'    => __( 'Every 1 hour', 'splecheh' ),
							'splecheh_2h'    => __( 'Every 2 hours', 'splecheh' ),
							'splecheh_4h'    => __( 'Every 4 hours', 'splecheh' ),
							'splecheh_8h'    => __( 'Every 8 hours', 'splecheh' ),
							'splecheh_12h'   => __( 'Every 12 hours', 'splecheh' ),
							'splecheh_24h'   => __( 'Every 24 hours', 'splecheh' ),
						]
					)
					->set_default_value( 'splecheh_1h' )
					->set_help_text( __( 'How often the background spell check should run.', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'text', 'splecheh_bg_batch_size', __( 'Batch Size', 'splecheh' ) )
					->set_default_value( '50' )
					->set_help_text( __( 'Number of posts to check per background run. Default: 50.', 'splecheh' ) ),
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
	require_once SPLECHEH_DIR . 'templates/spell-check.php';
}

function splecheh_page_help(): void {
	require_once SPLECHEH_DIR . 'templates/help.php';
}

function splecheh_page_logs(): void {
	require_once SPLECHEH_DIR . 'templates/logs.php';
}

function splecheh_enqueue_spellcheck_assets( string $hook ): void {
	if ( $hook !== 'toplevel_page_splecheh' ) {
		return;
	}
	wp_enqueue_script(
		'splecheh-spellcheck',
		plugins_url( 'assets/js/spellcheck.js', SPLECHEH_PLUGIN_FILE ),
		[],
		SPLECHEH_VERSION,
		true
	);
	wp_localize_script(
		'splecheh-spellcheck',
		'splechehCheck',
		[
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'splecheh_run' ),
			'runNowNonce' => wp_create_nonce( 'splecheh_run_now' ),
			'i18n'       => [
				'upToDate'    => __( 'Up to date', 'splecheh' ),
				'viewReport'  => __( 'View Report', 'splecheh' ),
				'noErrors'    => __( 'No spelling errors found.', 'splecheh' ),
				'errorsFound' => __( 'spelling error(s) found.', 'splecheh' ),
				'running'     => __( 'Running…', 'splecheh' ),
			],
		]
	);
}

function splecheh_ajax_run_spellcheck(): void {
	check_ajax_referer( 'splecheh_run', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id ) {
		wp_send_json_error( __( 'Invalid post ID.', 'splecheh' ), 400 );
	}

	$result = Splecheh_SpellCheckReport::run( $post_id );
	if ( is_wp_error( $result ) ) {
		Splecheh_Logs::addLog( 'spellcheck', 'Spell check failed for post ' . $post_id, [ 'error' => $result->get_error_message() ] );
		wp_send_json_error( $result->get_error_message() );
	}

	Splecheh_Logs::addLog( 'spellcheck', 'Spell check completed for post ' . $post_id, [ 'errors' => count( $result['errors'] ) ] );

	wp_send_json_success(
		[
			'post_id'             => $post_id,
			'error_count'         => count( $result['errors'] ),
			'report_url'          => Splecheh_SpellCheckReport::get_report_url( $post_id ),
			'checked_at_formatted' => get_date_from_gmt(
				gmdate( 'Y-m-d H:i:s' ),
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
			),
		]
	);
}

function splecheh_ajax_run_now(): void {
	check_ajax_referer( 'splecheh_run_now', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	Splecheh_Cron::run_batch();

	$summary = Splecheh_Cron::get_summary();

	wp_send_json_success(
		[
			'last_run'      => $summary['last_run']
				? get_date_from_gmt(
					gmdate( 'Y-m-d H:i:s', strtotime( $summary['last_run'] ) ),
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
				)
				: '',
			'issues_found'  => (int) $summary['issues_found'],
			'posts_pending' => (int) $summary['posts_pending'],
		]
	);
}
