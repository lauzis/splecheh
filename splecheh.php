<?php
/**
 * Plugin Name: Splecheh - WordPress spellcheck plugin
 * Plugin URI:  https://github.com/lauzis/splecheh
 * Description: Run spell check on all articles and post types to find spelling errors.
 * Version:     0.12.0
 * Author:      Aivars Lauzis
 * Text Domain: splecheh
 * License:     MIT
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLECHEH_VERSION', '0.12.0' );
define( 'SPLECHEH_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPLECHEH_LOG_PATH', SPLECHEH_DIR . 'logs' );
define( 'SPLECHEH_PLUGIN_FILE', __FILE__ );

require_once SPLECHEH_DIR . 'classes/Logs.php';
require_once SPLECHEH_DIR . 'classes/Notification.php';
require_once SPLECHEH_DIR . 'classes/NotificationManager.php';
require_once SPLECHEH_DIR . 'classes/IgnoreList.php';
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
add_action( 'admin_enqueue_scripts', 'splecheh_enqueue_details_assets' );
add_action( 'wp_ajax_splecheh_dismiss_notification', [ 'Splecheh_NotificationManager', 'handle_dismiss' ] );
add_action( 'wp_ajax_splecheh_run', 'splecheh_ajax_run_spellcheck' );
add_action( 'wp_ajax_splecheh_run_now', 'splecheh_ajax_run_now' );
add_action( 'wp_ajax_splecheh_details_rerun', 'splecheh_ajax_details_rerun' );
add_action( 'wp_ajax_splecheh_fix_word', 'splecheh_ajax_fix_word' );
add_action( 'wp_ajax_splecheh_ignore_in_post', 'splecheh_ajax_ignore_in_post' );
add_action( 'wp_ajax_splecheh_ignore_always', 'splecheh_ajax_ignore_always' );
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

	if ( splecheh_logs_enabled() ) {
		add_submenu_page(
			'splecheh',
			__( 'Logs', 'splecheh' ),
			__( 'Logs', 'splecheh' ),
			'edit_posts',
			'splecheh-logs',
			'splecheh_page_logs'
		);
	}

	add_submenu_page(
		'splecheh',
		__( 'Ignore List', 'splecheh' ),
		__( 'Ignore List', 'splecheh' ),
		'edit_posts',
		'splecheh-ignore-list',
		'splecheh_page_ignore_list'
	);

	// Hidden page (not shown in the menu): per-post spell check details, opened in a new tab from the Spell Check table.
	add_submenu_page(
		null,
		__( 'Spell Check Details', 'splecheh' ),
		__( 'Spell Check Details', 'splecheh' ),
		'edit_posts',
		'splecheh-details',
		'splecheh_page_details'
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

				\Carbon_Fields\Field::make( 'checkbox', 'splecheh_ignore_shortcodes', __( 'Ignore Shortcodes', 'splecheh' ) )
					->set_default_value( true )
					->set_help_text( __( 'When enabled, shortcode literals (e.g. "[shortcode attr=\"value\"]") are excluded from spell checking.', 'splecheh' ) ),

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

				\Carbon_Fields\Field::make( 'separator', 'splecheh_logs_separator', __( 'Logs', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'checkbox', 'splecheh_logs_enabled', __( 'Enable Logs', 'splecheh' ) )
					->set_default_value( true )
					->set_help_text( __( 'When disabled, no new log entries are written and the Logs page is hidden from the menu.', 'splecheh' ) ),
			]
		);
}

/**
 * Whether logging is enabled.
 * Defaults to enabled when Carbon Fields isn't loaded yet.
 */
function splecheh_logs_enabled(): bool {
	if ( ! function_exists( 'carbon_get_theme_option' ) ) {
		return true;
	}
	return (bool) carbon_get_theme_option( 'splecheh_logs_enabled' );
}

/**
 * Whether shortcode literals should be excluded from spell checking.
 * Defaults to enabled when Carbon Fields isn't loaded yet.
 */
function splecheh_ignore_shortcodes_enabled(): bool {
	if ( ! function_exists( 'carbon_get_theme_option' ) ) {
		return true;
	}
	return (bool) carbon_get_theme_option( 'splecheh_ignore_shortcodes' );
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

function splecheh_page_details(): void {
	require_once SPLECHEH_DIR . 'templates/details.php';
}

function splecheh_page_ignore_list(): void {
	require_once SPLECHEH_DIR . 'templates/ignore-list.php';
}

/**
 * Returns the language code for a post: Polylang, then WPML, then the plugin's Settings language
 * (falling back to the WordPress site locale).
 */
function splecheh_get_language_code( int $post_id ): string {
	if ( function_exists( 'pll_get_post_language' ) ) {
		$lang = pll_get_post_language( $post_id );
		if ( ! empty( $lang ) ) {
			return (string) $lang;
		}
	}

	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$lang_info = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $lang_info ) && ! empty( $lang_info['language_code'] ) ) {
			return (string) $lang_info['language_code'];
		}
	}

	return Splecheh_SpellCheckReport::get_language();
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

function splecheh_enqueue_details_assets(): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page !== 'splecheh-details' ) {
		return;
	}

	wp_enqueue_script(
		'splecheh-details',
		plugins_url( 'assets/js/details.js', SPLECHEH_PLUGIN_FILE ),
		[],
		SPLECHEH_VERSION,
		true
	);
	wp_localize_script(
		'splecheh-details',
		'splechehDetails',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'splecheh_details' ),
			'postId'  => isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0,
			'i18n'    => [
				'selectAction'   => __( 'Select a bulk action.', 'splecheh' ),
				'selectRows'     => __( 'Select at least one issue.', 'splecheh' ),
				'replacementReq' => __( 'Enter a replacement word before fixing.', 'splecheh' ),
				'requestFailed'  => __( 'Request failed. Please try again.', 'splecheh' ),
				'resolved'       => __( 'Resolved', 'splecheh' ),
				'issuesFixed'    => __( 'issue(s) fixed.', 'splecheh' ),
				'issuesUpdated'  => __( 'issue(s) updated.', 'splecheh' ),
				'rerun'          => __( 'Re-run Spell Check', 'splecheh' ),
				'rerunning'      => __( 'Running…', 'splecheh' ),
				'noIssues'       => __( 'No spelling issues found.', 'splecheh' ),
				'issuesFound'    => __( 'spelling issue(s) found.', 'splecheh' ),
				'fix'            => __( 'Fix', 'splecheh' ),
				'ignoreInPost'   => __( 'Ignore in post', 'splecheh' ),
				'ignoreAlways'   => __( 'Ignore always', 'splecheh' ),
			],
		]
	);
}

/**
 * Runs the spell check for a single post and logs the outcome. Shared by the
 * per-row "Run Now"/"Re-run" action and the Details page's re-run button.
 *
 * @return array|WP_Error Report array on success, WP_Error on failure.
 */
function splecheh_run_spellcheck_for_post( int $post_id ) {
	Splecheh_Logs::addLog( 'spellcheck', 'Spell check started for post ' . $post_id, [] );

	$result = Splecheh_SpellCheckReport::run( $post_id );
	if ( is_wp_error( $result ) ) {
		Splecheh_Logs::addLog( 'spellcheck', 'Spell check failed for post ' . $post_id, [ 'error' => $result->get_error_message() ] );
		return $result;
	}

	Splecheh_Logs::addLog( 'spellcheck', 'Spell check completed for post ' . $post_id, [ 'errors' => count( $result['errors'] ) ] );

	return $result;
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

	$result = splecheh_run_spellcheck_for_post( $post_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			[
				'message'  => $result->get_error_message(),
				'docs_url' => (string) ( $result->get_error_data( 'missing_wordlist' )['docs_url'] ?? '' ),
			]
		);
	}

	require_once SPLECHEH_DIR . 'classes/SpellCheckListTable.php';

	wp_send_json_success(
		[
			'post_id'             => $post_id,
			'error_count'         => count( $result['errors'] ),
			'report_url'          => Splecheh_SpellCheckReport::get_report_url( $post_id ),
			'actions_html'        => Splecheh_SpellCheckListTable::render_actions_html( $post_id ),
			'checked_at_formatted' => get_date_from_gmt(
				gmdate( 'Y-m-d H:i:s' ),
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
			),
		]
	);
}

function splecheh_ajax_details_rerun(): void {
	check_ajax_referer( 'splecheh_details', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$result = splecheh_run_spellcheck_for_post( $post_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			[
				'message'  => $result->get_error_message(),
				'docs_url' => (string) ( $result->get_error_data( 'missing_wordlist' )['docs_url'] ?? '' ),
			]
		);
	}

	wp_send_json_success(
		[
			'errors' => Splecheh_SpellCheckReport::format_errors_for_details( $result['errors'] ),
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

/**
 * Reads post_id/report from the request for the Details page AJAX actions.
 *
 * @return array{0: int, 1: array}|null Null and a JSON error response are sent on failure.
 */
function splecheh_get_details_request_context(): ?array {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$report = Splecheh_SpellCheckReport::get_report( $post_id );
	if ( ! $report ) {
		wp_send_json_error( __( 'Report not found.', 'splecheh' ), 404 );
	}

	return [ $post_id, $report ];
}

function splecheh_ajax_fix_word(): void {
	check_ajax_referer( 'splecheh_details', 'nonce' );

	[ $post_id, $report ] = splecheh_get_details_request_context();

	$items = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null;
	if ( ! is_array( $items ) || empty( $items ) ) {
		wp_send_json_error( __( 'Invalid request.', 'splecheh' ), 400 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( __( 'Post not found.', 'splecheh' ), 404 );
	}

	$content = $post->post_content;
	$fixed   = 0;

	foreach ( $items as $item ) {
		$index       = isset( $item['index'] ) ? absint( $item['index'] ) : -1;
		$replacement = isset( $item['replacement'] ) ? sanitize_text_field( wp_unslash( $item['replacement'] ) ) : '';

		if ( $replacement === '' || ! isset( $report['errors'][ $index ] ) || ! empty( $report['errors'][ $index ]['resolved'] ) ) {
			continue;
		}

		$error   = $report['errors'][ $index ];
		$content = Splecheh_SpellCheckReport::replace_occurrence( $content, $error['word'], $error['excerpt'], $replacement );

		$report['errors'][ $index ]['resolved']  = true;
		$report['errors'][ $index ]['fixed_to']  = $replacement;
		$fixed++;
	}

	if ( $fixed > 0 ) {
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $content,
			]
		);
		Splecheh_SpellCheckReport::update_report( $post_id, $report );
		Splecheh_Logs::addLog( 'spellcheck', "Fixed {$fixed} word(s) in post {$post_id}", [] );
	}

	wp_send_json_success(
		[
			'fixed'            => $fixed,
			'unresolved_count' => Splecheh_SpellCheckReport::count_unresolved( $post_id ),
		]
	);
}

function splecheh_ajax_ignore_in_post(): void {
	check_ajax_referer( 'splecheh_details', 'nonce' );

	[ $post_id, $report ] = splecheh_get_details_request_context();

	$indices = isset( $_POST['indices'] ) ? json_decode( wp_unslash( $_POST['indices'] ), true ) : null;
	if ( ! is_array( $indices ) || empty( $indices ) ) {
		wp_send_json_error( __( 'Invalid request.', 'splecheh' ), 400 );
	}

	$ignored_words = (array) get_post_meta( $post_id, '_splecheh_ignored_words', true );
	$count         = 0;

	foreach ( $indices as $index ) {
		$index = absint( $index );
		if ( ! isset( $report['errors'][ $index ] ) || ! empty( $report['errors'][ $index ]['resolved'] ) ) {
			continue;
		}

		$word = mb_strtolower( $report['errors'][ $index ]['word'] );
		if ( ! in_array( $word, $ignored_words, true ) ) {
			$ignored_words[] = $word;
		}
		$report['errors'][ $index ]['resolved'] = true;
		$count++;
	}

	if ( $count > 0 ) {
		update_post_meta( $post_id, '_splecheh_ignored_words', $ignored_words );
		Splecheh_SpellCheckReport::update_report( $post_id, $report );
	}

	wp_send_json_success(
		[
			'ignored'          => $count,
			'unresolved_count' => Splecheh_SpellCheckReport::count_unresolved( $post_id ),
		]
	);
}

function splecheh_ajax_ignore_always(): void {
	check_ajax_referer( 'splecheh_details', 'nonce' );

	[ $post_id, $report ] = splecheh_get_details_request_context();

	$indices = isset( $_POST['indices'] ) ? json_decode( wp_unslash( $_POST['indices'] ), true ) : null;
	if ( ! is_array( $indices ) || empty( $indices ) ) {
		wp_send_json_error( __( 'Invalid request.', 'splecheh' ), 400 );
	}

	$language = splecheh_get_language_code( $post_id );
	$count    = 0;

	foreach ( $indices as $index ) {
		$index = absint( $index );
		if ( ! isset( $report['errors'][ $index ] ) || ! empty( $report['errors'][ $index ]['resolved'] ) ) {
			continue;
		}

		Splecheh_IgnoreList::add_word( $language, $report['errors'][ $index ]['word'] );
		$report['errors'][ $index ]['resolved'] = true;
		$count++;
	}

	if ( $count > 0 ) {
		Splecheh_SpellCheckReport::update_report( $post_id, $report );
	}

	wp_send_json_success(
		[
			'ignored'          => $count,
			'unresolved_count' => Splecheh_SpellCheckReport::count_unresolved( $post_id ),
		]
	);
}
