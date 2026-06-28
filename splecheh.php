<?php
/**
 * Plugin Name: Splecheh - WordPress spellcheck plugin
 * Plugin URI:  https://github.com/lauzis/splecheh
 * Description: Run spell check on all articles and post types to find spelling errors.
 * Version:     0.1.0
 * Author:      Aivars Lauzis
 * Text Domain: splecheh
 * License:     MIT
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLECHEH_VERSION', '0.1.0' );
define( 'SPLECHEH_DIR', plugin_dir_path( __FILE__ ) );

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
		__( 'Settings', 'splecheh' ),
		__( 'Settings', 'splecheh' ),
		'edit_posts',
		'splecheh-settings',
		'splecheh_page_settings'
	);
}

function splecheh_page_spell_check(): void {
	echo '<div class="wrap"><h1>' . esc_html__( 'Spell Check', 'splecheh' ) . '</h1></div>';
}

function splecheh_page_help(): void {
	echo '<div class="wrap"><h1>' . esc_html__( 'Help', 'splecheh' ) . '</h1></div>';
}

function splecheh_page_settings(): void {
	echo '<div class="wrap"><h1>' . esc_html__( 'Settings', 'splecheh' ) . '</h1></div>';
}
