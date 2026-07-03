<?php
/**
 * Plugin Name: Splecheh - WordPress spellcheck plugin
 * Plugin URI:  https://github.com/lauzis/splecheh
 * Description: Run spell check on all articles and post types to find spelling errors.
 * Version:     0.19.1
 * Author:      Aivars Lauzis
 * Text Domain: splecheh
 * License:     MIT
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLECHEH_VERSION', '0.19.1' );
define( 'SPLECHEH_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPLECHEH_LOG_PATH', SPLECHEH_DIR . 'logs' );
define( 'SPLECHEH_PLUGIN_FILE', __FILE__ );

require_once SPLECHEH_DIR . 'classes/Logs.php';
require_once SPLECHEH_DIR . 'classes/Notification.php';
require_once SPLECHEH_DIR . 'classes/NotificationManager.php';
require_once SPLECHEH_DIR . 'classes/IgnoreList.php';
require_once SPLECHEH_DIR . 'classes/SpellCheckReport.php';
require_once SPLECHEH_DIR . 'classes/SplechehCron.php';
require_once SPLECHEH_DIR . 'classes/InterpunctionIgnoreList.php';
require_once SPLECHEH_DIR . 'classes/InterpunctionBackend.php';
require_once SPLECHEH_DIR . 'classes/InterpunctionReport.php';

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
add_action( 'admin_enqueue_scripts', 'splecheh_enqueue_interpunction_assets' );
add_action( 'admin_enqueue_scripts', 'splecheh_enqueue_interpunction_details_assets' );
add_action( 'admin_enqueue_scripts', 'splecheh_enqueue_settings_assets' );
add_action( 'wp_ajax_splecheh_dismiss_notification', [ 'Splecheh_NotificationManager', 'handle_dismiss' ] );
add_action( 'wp_ajax_splecheh_run', 'splecheh_ajax_run_spellcheck' );
add_action( 'wp_ajax_splecheh_bulk_run', 'splecheh_ajax_bulk_run' );
add_action( 'wp_ajax_splecheh_run_now', 'splecheh_ajax_run_now' );
add_action( 'wp_ajax_splecheh_details_rerun', 'splecheh_ajax_details_rerun' );
add_action( 'wp_ajax_splecheh_fix_word', 'splecheh_ajax_fix_word' );
add_action( 'wp_ajax_splecheh_ignore_in_post', 'splecheh_ajax_ignore_in_post' );
add_action( 'wp_ajax_splecheh_ignore_always', 'splecheh_ajax_ignore_always' );
add_action( 'wp_ajax_splecheh_interpunction_run', 'splecheh_ajax_interpunction_run' );
add_action( 'wp_ajax_splecheh_interpunction_bulk_run', 'splecheh_ajax_interpunction_bulk_run' );
add_action( 'wp_ajax_splecheh_interpunction_details_rerun', 'splecheh_ajax_interpunction_details_rerun' );
add_action( 'wp_ajax_splecheh_interpunction_fix', 'splecheh_ajax_interpunction_fix' );
add_action( 'wp_ajax_splecheh_interpunction_ignore_in_post', 'splecheh_ajax_interpunction_ignore_in_post' );
add_action( 'wp_ajax_splecheh_interpunction_ignore_always', 'splecheh_ajax_interpunction_ignore_always' );
add_action( 'wp_ajax_splecheh_interpunction_test', 'splecheh_ajax_interpunction_test' );
add_action( 'wp_ajax_splecheh_interpunction_test_search_posts', 'splecheh_ajax_interpunction_test_search_posts' );
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

	if ( splecheh_interpunction_enabled() ) {
		add_submenu_page(
			'splecheh',
			__( 'Interpunction Check', 'splecheh' ),
			__( 'Interpunction Check', 'splecheh' ),
			'edit_posts',
			'splecheh-interpunction',
			'splecheh_page_interpunction_check'
		);
	}

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

	// Hidden page (not shown in the menu): per-post interpunction check details, opened in a new tab from the Interpunction Check table.
	add_submenu_page(
		null,
		__( 'Interpunction Check Details', 'splecheh' ),
		__( 'Interpunction Check Details', 'splecheh' ),
		'edit_posts',
		'splecheh-interpunction-details',
		'splecheh_page_interpunction_details'
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

				\Carbon_Fields\Field::make( 'checkbox', 'splecheh_invalidate_on_version_change', __( 'Invalidate Spell Check on Plugin Version Change', 'splecheh' ) )
					->set_help_text( __( 'When enabled, a report is also considered outdated if it was generated by a different plugin version than the one currently active, in addition to the existing "post edited since last check" rule.', 'splecheh' ) ),

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

				\Carbon_Fields\Field::make( 'separator', 'splecheh_interpunction_separator', __( 'Interpunction Check', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'checkbox', 'splecheh_interpunction_enabled', __( 'Enable Interpunction Check', 'splecheh' ) )
					->set_help_text( __( 'When enabled, an "Interpunction Check" page is added to the menu that uses an LLM to check punctuation and capitalization, sentence by sentence.', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'select', 'splecheh_interpunction_type', __( 'Type', 'splecheh' ) )
					->set_options(
						[
							'commandline' => __( 'Commandline - Local model', 'splecheh' ),
							'openai'      => __( 'OpenAI', 'splecheh' ),
							'claude'      => __( 'Claude', 'splecheh' ),
							'gemini'      => __( 'Gemini', 'splecheh' ),
						]
					)
					->set_default_value( 'commandline' )
					->set_help_text( __( 'How the interpunction check request is made.', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'text', 'splecheh_interpunction_command', __( 'Commandline Command', 'splecheh' ) )
					->set_help_text( __( 'Shell command to run for the "Commandline - Local model" type, e.g. "claude -p". The JSON parameter — {language, prompt, sentences} — is always appended as a single shell-escaped trailing argument; the command string itself does not support {prompt}-style placeholders. Must print a JSON array of {original, fixed, explanation} on stdout. See bin/interpunction-check.sh for the reference contract. Keeps API keys out of WordPress: the script owns its own credentials.', 'splecheh' ) )
					->set_conditional_logic(
						[
							[
								'field' => 'splecheh_interpunction_type',
								'value' => 'commandline',
							],
						]
					),

				\Carbon_Fields\Field::make( 'text', 'splecheh_interpunction_endpoint', __( 'Endpoint', 'splecheh' ) )
					->set_help_text( __( 'Optional: override the default API endpoint for the selected provider.', 'splecheh' ) )
					->set_conditional_logic(
						[
							[
								'field'   => 'splecheh_interpunction_type',
								'value'   => 'commandline',
								'compare' => '!=',
							],
						]
					),

				\Carbon_Fields\Field::make( 'text', 'splecheh_interpunction_access_key', __( 'Access Key', 'splecheh' ) )
					->set_help_text( __( 'API token used to authenticate with the selected provider. Not needed (or stored) for the Commandline type.', 'splecheh' ) )
					->set_conditional_logic(
						[
							[
								'field'   => 'splecheh_interpunction_type',
								'value'   => 'commandline',
								'compare' => '!=',
							],
						]
					),

				\Carbon_Fields\Field::make( 'textarea', 'splecheh_interpunction_prompt', __( 'Prompt', 'splecheh' ) )
					->set_default_value( Splecheh_InterpunctionReport::DEFAULT_PROMPT )
					->set_help_text( __( 'Instruction sent to the LLM. Use {language} as a placeholder for the post\'s language. Must tell the LLM to output only a JSON array of {original, fixed, explanation} objects, one per input sentence and in the same order — see the default value for the expected wording.', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'checkbox', 'splecheh_interpunction_test_all_sentences', __( 'Test All Sentences', 'splecheh' ) )
					->set_help_text( __( 'When enabled, the Test button sends every sentence of the selected post instead of just the first few. Has no effect on the canned example (always 3 sentences).', 'splecheh' ) ),

				\Carbon_Fields\Field::make( 'text', 'splecheh_interpunction_test_sentence_limit', __( 'Test Sentence Limit', 'splecheh' ) )
					->set_default_value( (string) Splecheh_InterpunctionBackend::DEFAULT_TEST_MAX_SENTENCES )
					->set_attribute( 'type', 'number' )
					->set_attribute( 'min', '1' )
					->set_help_text( __( 'How many of the selected post\'s sentences the Test button sends to the LLM. Ignored when "Test All Sentences" is enabled.', 'splecheh' ) )
					->set_conditional_logic(
						[
							[
								'field' => 'splecheh_interpunction_test_all_sentences',
								'value' => false,
							],
						]
					),

				\Carbon_Fields\Field::make( 'html', 'splecheh_interpunction_test' )
					->set_html( 'splecheh_render_interpunction_test_field' ),
			]
		);
}

/**
 * Renders the "Test Interpunction Check" button and expandable request/result
 * sections on the Settings page.
 */
function splecheh_render_interpunction_test_field(): string {
	ob_start();
	?>
	<p>
		<label for="splecheh-interpunction-test-post"><?php esc_html_e( 'Test against a post/page (optional)', 'splecheh' ); ?></label><br>
		<input
			type="text"
			id="splecheh-interpunction-test-post"
			class="regular-text"
			autocomplete="off"
			placeholder="<?php esc_attr_e( 'Search by title… leave empty to use the built-in example sentences', 'splecheh' ); ?>"
		>
		<input type="hidden" id="splecheh-interpunction-test-post-id" value="">
	</p>

	<button type="button" id="splecheh-interpunction-test-button" class="button">
		<?php esc_html_e( 'Test Interpunction Check', 'splecheh' ); ?>
	</button>
	<span class="spinner" style="float: none; display: none; vertical-align: middle;"></span>

	<div id="splecheh-interpunction-test-message" class="notice" style="display: none; margin-top: 10px;"><p></p></div>

	<details style="margin-top: 10px;">
		<summary><?php esc_html_e( 'Example request', 'splecheh' ); ?></summary>
		<pre id="splecheh-interpunction-test-request" style="white-space: pre-wrap;"></pre>
	</details>

	<details style="margin-top: 10px;">
		<summary><?php esc_html_e( 'Result', 'splecheh' ); ?></summary>
		<pre id="splecheh-interpunction-test-result" style="white-space: pre-wrap;"></pre>
	</details>
	<?php
	return (string) ob_get_clean();
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
 * Whether a report should also be considered outdated when its stored plugin
 * version differs from the current one. Defaults to disabled when Carbon
 * Fields isn't loaded yet.
 */
function splecheh_invalidate_on_version_change_enabled(): bool {
	if ( ! function_exists( 'carbon_get_theme_option' ) ) {
		return false;
	}
	return (bool) carbon_get_theme_option( 'splecheh_invalidate_on_version_change' );
}

/**
 * Whether the Interpunction Check feature (and its menu page) is enabled.
 * Defaults to disabled when Carbon Fields isn't loaded yet.
 */
function splecheh_interpunction_enabled(): bool {
	if ( ! function_exists( 'carbon_get_theme_option' ) ) {
		return false;
	}
	return (bool) carbon_get_theme_option( 'splecheh_interpunction_enabled' );
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

function splecheh_page_interpunction_check(): void {
	require_once SPLECHEH_DIR . 'templates/interpunction-check.php';
}

function splecheh_page_interpunction_details(): void {
	require_once SPLECHEH_DIR . 'templates/interpunction-details.php';
}

add_action( 'wp_dashboard_setup', 'splecheh_register_dashboard_widget' );

function splecheh_register_dashboard_widget(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'splecheh_dashboard_widget',
		__( 'Splecheh Spell Check', 'splecheh' ),
		'splecheh_render_dashboard_widget'
	);
}

function splecheh_render_dashboard_widget(): void {
	require_once SPLECHEH_DIR . 'templates/dashboard-widget.php';
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
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'splecheh_run' ),
			'runNowNonce'  => wp_create_nonce( 'splecheh_run_now' ),
			'bulkRunNonce' => wp_create_nonce( 'splecheh_bulk_run' ),
			'i18n'         => [
				'upToDate'    => __( 'Up to date', 'splecheh' ),
				'viewReport'  => __( 'View Report', 'splecheh' ),
				'noErrors'    => __( 'No spelling errors found.', 'splecheh' ),
				'errorsFound' => __( 'spelling error(s) found.', 'splecheh' ),
				'running'     => __( 'Running…', 'splecheh' ),
				'selectRows'  => __( 'Select at least one post.', 'splecheh' ),
				'bulkChecked' => __( 'post(s) checked.', 'splecheh' ),
				'bulkFailed'  => __( 'failed.', 'splecheh' ),
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

function splecheh_enqueue_interpunction_assets( string $hook ): void {
	if ( $hook !== 'splecheh_page_splecheh-interpunction' ) {
		return;
	}
	wp_enqueue_script(
		'splecheh-interpunction',
		plugins_url( 'assets/js/interpunction.js', SPLECHEH_PLUGIN_FILE ),
		[],
		SPLECHEH_VERSION,
		true
	);
	wp_localize_script(
		'splecheh-interpunction',
		'splechehInterpunctionCheck',
		[
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'splecheh_interpunction_run' ),
			'bulkRunNonce' => wp_create_nonce( 'splecheh_interpunction_bulk_run' ),
			'i18n'         => [
				'upToDate'    => __( 'Up to date', 'splecheh' ),
				'viewReport'  => __( 'View Report', 'splecheh' ),
				'noIssues'    => __( 'No interpunction issues found.', 'splecheh' ),
				'issuesFound' => __( 'interpunction issue(s) found.', 'splecheh' ),
				'selectRows'  => __( 'Select at least one post.', 'splecheh' ),
				'bulkChecked' => __( 'post(s) checked.', 'splecheh' ),
				'bulkFailed'  => __( 'failed.', 'splecheh' ),
			],
		]
	);
}

function splecheh_enqueue_settings_assets(): void {
	$page = isset( $_GET['page'] ) ? (string) wp_unslash( $_GET['page'] ) : '';
	if ( $page !== 'crb_carbon_fields_container_settings.php' ) {
		return;
	}

	wp_enqueue_style( 'wp-jquery-ui-dialog' );

	wp_enqueue_script(
		'splecheh-interpunction-test',
		plugins_url( 'assets/js/interpunction-test.js', SPLECHEH_PLUGIN_FILE ),
		[ 'jquery-ui-autocomplete' ],
		SPLECHEH_VERSION,
		true
	);
	wp_localize_script(
		'splecheh-interpunction-test',
		'splechehInterpunctionTest',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'splecheh_interpunction_test' ),
			'i18n'    => [
				'testing'       => __( 'Testing…', 'splecheh' ),
				'requestFailed' => __( 'Test request failed. Please try again.', 'splecheh' ),
			],
		]
	);
}

function splecheh_enqueue_interpunction_details_assets(): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page !== 'splecheh-interpunction-details' ) {
		return;
	}

	wp_enqueue_script(
		'splecheh-interpunction-details',
		plugins_url( 'assets/js/interpunction-details.js', SPLECHEH_PLUGIN_FILE ),
		[],
		SPLECHEH_VERSION,
		true
	);
	wp_localize_script(
		'splecheh-interpunction-details',
		'splechehInterpunctionDetails',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'splecheh_interpunction_details' ),
			'postId'  => isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0,
			'i18n'    => [
				'selectAction'  => __( 'Select a bulk action.', 'splecheh' ),
				'selectRows'    => __( 'Select at least one issue.', 'splecheh' ),
				'fixedTextReq'  => __( 'Enter the fixed text before applying.', 'splecheh' ),
				'requestFailed' => __( 'Request failed. Please try again.', 'splecheh' ),
				'resolved'      => __( 'Resolved', 'splecheh' ),
				'issuesFixed'   => __( 'issue(s) fixed.', 'splecheh' ),
				'issuesUpdated' => __( 'issue(s) updated.', 'splecheh' ),
				'rerun'         => __( 'Re-run Interpunction Check', 'splecheh' ),
				'rerunning'     => __( 'Running…', 'splecheh' ),
				'noIssues'      => __( 'No interpunction issues found.', 'splecheh' ),
				'issuesFound'   => __( 'interpunction issue(s) found.', 'splecheh' ),
				'fix'           => __( 'Fix', 'splecheh' ),
				'ignoreInPost'  => __( 'Ignore in post', 'splecheh' ),
				'ignoreAlways'  => __( 'Ignore always', 'splecheh' ),
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

function splecheh_ajax_bulk_run(): void {
	check_ajax_referer( 'splecheh_bulk_run', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : [];
	$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

	if ( empty( $post_ids ) ) {
		wp_send_json_error( __( 'No posts selected.', 'splecheh' ), 400 );
	}

	Splecheh_Logs::addLog( 'spellcheck', 'Bulk spell check started for ' . count( $post_ids ) . ' post(s)', [ 'post_ids' => $post_ids ] );

	require_once SPLECHEH_DIR . 'classes/SpellCheckListTable.php';

	$results       = [];
	$success_count = 0;
	$failure_count = 0;

	foreach ( $post_ids as $post_id ) {
		$result = splecheh_run_spellcheck_for_post( $post_id );

		if ( is_wp_error( $result ) ) {
			$failure_count++;
			$results[ $post_id ] = [
				'success'  => false,
				'message'  => $result->get_error_message(),
				'docs_url' => (string) ( $result->get_error_data( 'missing_wordlist' )['docs_url'] ?? '' ),
			];
			continue;
		}

		$success_count++;
		$results[ $post_id ] = [
			'success'              => true,
			'error_count'          => count( $result['errors'] ),
			'report_url'           => Splecheh_SpellCheckReport::get_report_url( $post_id ),
			'actions_html'         => Splecheh_SpellCheckListTable::render_actions_html( $post_id ),
			'checked_at_formatted' => get_date_from_gmt(
				gmdate( 'Y-m-d H:i:s' ),
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
			),
		];
	}

	if ( $failure_count > 0 ) {
		Splecheh_Logs::addLog(
			'spellcheck',
			"Bulk spell check completed with {$failure_count} failure(s) out of " . count( $post_ids ) . ' post(s)',
			[
				'success' => $success_count,
				'failed'  => $failure_count,
			]
		);
	} else {
		Splecheh_Logs::addLog(
			'spellcheck',
			'Bulk spell check completed for ' . count( $post_ids ) . ' post(s)',
			[ 'success' => $success_count ]
		);
	}

	wp_send_json_success(
		[
			'results'       => $results,
			'success_count' => $success_count,
			'failure_count' => $failure_count,
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

/**
 * Runs the interpunction check for a single post and logs the outcome. Shared by the
 * per-row "Run Now"/"Re-run" action and the Details page's re-run button.
 *
 * @return array|WP_Error Report array on success, WP_Error on failure.
 */
function splecheh_run_interpunction_check_for_post( int $post_id ) {
	Splecheh_Logs::addLog( 'interpunction', 'Interpunction check started for post ' . $post_id, [] );

	$result = Splecheh_InterpunctionReport::run( $post_id );
	if ( is_wp_error( $result ) ) {
		Splecheh_Logs::addLog( 'interpunction', 'Interpunction check failed for post ' . $post_id, [ 'error' => $result->get_error_message() ] );
		return $result;
	}

	Splecheh_Logs::addLog( 'interpunction', 'Interpunction check completed for post ' . $post_id, [ 'issues' => count( $result['issues'] ) ] );

	return $result;
}

function splecheh_ajax_interpunction_run(): void {
	check_ajax_referer( 'splecheh_interpunction_run', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) || ! splecheh_interpunction_enabled() ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id ) {
		wp_send_json_error( __( 'Invalid post ID.', 'splecheh' ), 400 );
	}

	$result = splecheh_run_interpunction_check_for_post( $post_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	require_once SPLECHEH_DIR . 'classes/InterpunctionListTable.php';

	wp_send_json_success(
		[
			'post_id'              => $post_id,
			'issue_count'          => count( $result['issues'] ),
			'report_url'           => Splecheh_InterpunctionReport::get_report_url( $post_id ),
			'actions_html'         => Splecheh_InterpunctionListTable::render_actions_html( $post_id ),
			'checked_at_formatted' => get_date_from_gmt(
				gmdate( 'Y-m-d H:i:s' ),
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
			),
		]
	);
}

function splecheh_ajax_interpunction_bulk_run(): void {
	check_ajax_referer( 'splecheh_interpunction_bulk_run', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) || ! splecheh_interpunction_enabled() ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : [];
	$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

	if ( empty( $post_ids ) ) {
		wp_send_json_error( __( 'No posts selected.', 'splecheh' ), 400 );
	}

	Splecheh_Logs::addLog( 'interpunction', 'Bulk interpunction check started for ' . count( $post_ids ) . ' post(s)', [ 'post_ids' => $post_ids ] );

	require_once SPLECHEH_DIR . 'classes/InterpunctionListTable.php';

	$results       = [];
	$success_count = 0;
	$failure_count = 0;

	foreach ( $post_ids as $post_id ) {
		$result = splecheh_run_interpunction_check_for_post( $post_id );

		if ( is_wp_error( $result ) ) {
			$failure_count++;
			$results[ $post_id ] = [
				'success' => false,
				'message' => $result->get_error_message(),
			];
			continue;
		}

		$success_count++;
		$results[ $post_id ] = [
			'success'              => true,
			'issue_count'          => count( $result['issues'] ),
			'report_url'           => Splecheh_InterpunctionReport::get_report_url( $post_id ),
			'actions_html'         => Splecheh_InterpunctionListTable::render_actions_html( $post_id ),
			'checked_at_formatted' => get_date_from_gmt(
				gmdate( 'Y-m-d H:i:s' ),
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
			),
		];
	}

	if ( $failure_count > 0 ) {
		Splecheh_Logs::addLog(
			'interpunction',
			"Bulk interpunction check completed with {$failure_count} failure(s) out of " . count( $post_ids ) . ' post(s)',
			[
				'success' => $success_count,
				'failed'  => $failure_count,
			]
		);
	} else {
		Splecheh_Logs::addLog(
			'interpunction',
			'Bulk interpunction check completed for ' . count( $post_ids ) . ' post(s)',
			[ 'success' => $success_count ]
		);
	}

	wp_send_json_success(
		[
			'results'       => $results,
			'success_count' => $success_count,
			'failure_count' => $failure_count,
		]
	);
}

/**
 * Runs the "Test Interpunction Check" button on the Settings page: builds a fixed
 * example payload from the currently saved settings and canned sentences, sends it
 * through the configured provider, and returns both the payload and the outcome.
 */
function splecheh_ajax_interpunction_test(): void {
	check_ajax_referer( 'splecheh_interpunction_test', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'splecheh' ) ], 403 );
	}

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$payload = Splecheh_InterpunctionBackend::build_test_payload( $post_id );

	Splecheh_Logs::addLog( 'interpunction', 'Interpunction test started', [] );

	try {
		$result = Splecheh_InterpunctionBackend::check( $payload['sentences'], $payload['language'] );
	} catch ( \Throwable $exception ) {
		Splecheh_Logs::addLog( 'interpunction', 'Interpunction test failed', [ 'error' => $exception->getMessage() ] );
		wp_send_json_error(
			[
				'payload' => $payload,
				'message' => $exception->getMessage(),
			]
		);
	}

	if ( is_wp_error( $result ) ) {
		Splecheh_Logs::addLog( 'interpunction', 'Interpunction test failed', [ 'error' => $result->get_error_message() ] );
		wp_send_json_error(
			[
				'payload' => $payload,
				'message' => $result->get_error_message(),
			]
		);
	}

	Splecheh_Logs::addLog( 'interpunction', 'Interpunction test completed', [] );

	wp_send_json_success(
		[
			'payload' => $payload,
			'result'  => Splecheh_InterpunctionReport::build_issues( $result ),
		]
	);
}

/**
 * Powers the autocomplete field on the "Test Interpunction Check" button: searches
 * titles across the post types enabled for checking, for the user to pick a real
 * post/page to test against instead of the canned example sentences.
 */
function splecheh_ajax_interpunction_test_search_posts(): void {
	check_ajax_referer( 'splecheh_interpunction_test', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'splecheh' ) ], 403 );
	}

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	if ( $search === '' ) {
		wp_send_json_success( [] );
	}

	$query = new WP_Query(
		[
			's'                      => $search,
			'post_type'              => splecheh_get_enabled_post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
			'orderby'                => 'relevance',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]
	);

	$results = array_map(
		static function ( WP_Post $post ): array {
			return [
				'id'    => $post->ID,
				'label' => $post->post_title !== '' ? $post->post_title : '(no title)',
			];
		},
		$query->posts
	);

	wp_send_json_success( $results );
}

function splecheh_ajax_interpunction_details_rerun(): void {
	check_ajax_referer( 'splecheh_interpunction_details', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! splecheh_interpunction_enabled() ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$result = splecheh_run_interpunction_check_for_post( $post_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success(
		[
			'issues' => Splecheh_InterpunctionReport::format_issues_for_details( $result['issues'] ),
		]
	);
}

/**
 * Reads post_id/report from the request for the Interpunction Details page AJAX actions.
 *
 * @return array{0: int, 1: array}|null Null and a JSON error response are sent on failure.
 */
function splecheh_get_interpunction_details_request_context(): ?array {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'splecheh' ), 403 );
	}

	$report = Splecheh_InterpunctionReport::get_report( $post_id );
	if ( ! $report ) {
		wp_send_json_error( __( 'Report not found.', 'splecheh' ), 404 );
	}

	return [ $post_id, $report ];
}

function splecheh_ajax_interpunction_fix(): void {
	check_ajax_referer( 'splecheh_interpunction_details', 'nonce' );

	[ $post_id, $report ] = splecheh_get_interpunction_details_request_context();

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
		$index      = isset( $item['index'] ) ? absint( $item['index'] ) : -1;
		$fixed_text = isset( $item['fixed'] ) ? sanitize_textarea_field( wp_unslash( $item['fixed'] ) ) : '';

		if ( $fixed_text === '' || ! isset( $report['issues'][ $index ] ) || ! empty( $report['issues'][ $index ]['resolved'] ) ) {
			continue;
		}

		$issue   = $report['issues'][ $index ];
		$content = Splecheh_InterpunctionReport::apply_fix( $content, $issue['original'], $fixed_text );

		$report['issues'][ $index ]['resolved'] = true;
		$report['issues'][ $index ]['fixed']    = $fixed_text;
		$fixed++;
	}

	if ( $fixed > 0 ) {
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $content,
			]
		);
		Splecheh_InterpunctionReport::update_report( $post_id, $report );
		Splecheh_Logs::addLog( 'interpunction', "Fixed {$fixed} sentence(s) in post {$post_id}", [] );
	}

	wp_send_json_success(
		[
			'fixed'            => $fixed,
			'unresolved_count' => Splecheh_InterpunctionReport::count_unresolved( $post_id ),
		]
	);
}

function splecheh_ajax_interpunction_ignore_in_post(): void {
	check_ajax_referer( 'splecheh_interpunction_details', 'nonce' );

	[ $post_id, $report ] = splecheh_get_interpunction_details_request_context();

	$indices = isset( $_POST['indices'] ) ? json_decode( wp_unslash( $_POST['indices'] ), true ) : null;
	if ( ! is_array( $indices ) || empty( $indices ) ) {
		wp_send_json_error( __( 'Invalid request.', 'splecheh' ), 400 );
	}

	$ignored_sentences = (array) get_post_meta( $post_id, '_splecheh_interpunction_ignored_sentences', true );
	$count             = 0;

	foreach ( $indices as $index ) {
		$index = absint( $index );
		if ( ! isset( $report['issues'][ $index ] ) || ! empty( $report['issues'][ $index ]['resolved'] ) ) {
			continue;
		}

		$original = $report['issues'][ $index ]['original'];
		if ( ! in_array( $original, $ignored_sentences, true ) ) {
			$ignored_sentences[] = $original;
		}
		$report['issues'][ $index ]['resolved'] = true;
		$count++;
	}

	if ( $count > 0 ) {
		update_post_meta( $post_id, '_splecheh_interpunction_ignored_sentences', $ignored_sentences );
		Splecheh_InterpunctionReport::update_report( $post_id, $report );
	}

	wp_send_json_success(
		[
			'ignored'          => $count,
			'unresolved_count' => Splecheh_InterpunctionReport::count_unresolved( $post_id ),
		]
	);
}

function splecheh_ajax_interpunction_ignore_always(): void {
	check_ajax_referer( 'splecheh_interpunction_details', 'nonce' );

	[ $post_id, $report ] = splecheh_get_interpunction_details_request_context();

	$indices = isset( $_POST['indices'] ) ? json_decode( wp_unslash( $_POST['indices'] ), true ) : null;
	if ( ! is_array( $indices ) || empty( $indices ) ) {
		wp_send_json_error( __( 'Invalid request.', 'splecheh' ), 400 );
	}

	$language = splecheh_get_language_code( $post_id );
	$count    = 0;

	foreach ( $indices as $index ) {
		$index = absint( $index );
		if ( ! isset( $report['issues'][ $index ] ) || ! empty( $report['issues'][ $index ]['resolved'] ) ) {
			continue;
		}

		Splecheh_InterpunctionIgnoreList::add_sentence( $language, $report['issues'][ $index ]['original'] );
		$report['issues'][ $index ]['resolved'] = true;
		$count++;
	}

	if ( $count > 0 ) {
		Splecheh_InterpunctionReport::update_report( $post_id, $report );
	}

	wp_send_json_success(
		[
			'ignored'          => $count,
			'unresolved_count' => Splecheh_InterpunctionReport::count_unresolved( $post_id ),
		]
	);
}
