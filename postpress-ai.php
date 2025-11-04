<?php
/**
 * Plugin Name: PostPress AI
 * Description: Secure server-to-server AI content preview & store via Django (PostPress AI). Adds a Composer screen and server-side AJAX proxy to your Django backend.
 * Author: Tech With Wayne
 * Version: 2.1.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Text Domain: postpress-ai
 *
 * @package PostPressAI
 */

/**
 * CHANGE LOG
 * 2025-11-04 — Repair fatal: remove stray/duplicated blocks, complete cache-buster, centralize requires, init logging & shortcode.   # CHANGED:
 * 2025-11-04 — Scope admin enqueue to Composer/Testbed screens only.                                                                # CHANGED:
 * 2025-11-04 — Load AJAX handlers on init (preview/store/marker).                                                                  # CHANGED:
 * 2025-11-04 — Add robust asset version rotator (filemtime fallback to PPA_PLUGIN_VER).                                           # CHANGED:
 * 2025-11-04 — No inline JS/CSS in templates (enforced by structure; Testbed UI JS lives in assets).                              # CHANGED:
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ---------------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------------- */
if ( ! defined( 'PPA_PLUGIN_DIR' ) ) {                        // CHANGED:
	define( 'PPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );   // CHANGED:
}                                                              // CHANGED:
if ( ! defined( 'PPA_PLUGIN_URL' ) ) {                        // CHANGED:
	define( 'PPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );    // CHANGED:
}                                                              // CHANGED:
if ( ! defined( 'PPA_PLUGIN_VER' ) ) {                        // CHANGED:
	define( 'PPA_PLUGIN_VER', '2.1.0' );                       // CHANGED:
}                                                              // CHANGED:

/** ---------------------------------------------------------------------------------
 * Includes (single source of truth)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {                                                                               // CHANGED:
	// Admin UI (menu + composer renderer)                                                                                 // CHANGED:
	$admin_menu = PPA_PLUGIN_DIR . 'inc/admin/menu.php';                                                                   // CHANGED:
	if ( file_exists( $admin_menu ) ) { require_once $admin_menu; }                                                       // CHANGED:

	// Admin enqueue helpers                                                                                                // CHANGED:
	$admin_enqueue = PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';                                                             // CHANGED:
	if ( file_exists( $admin_enqueue ) ) { require_once $admin_enqueue; }                                                  // CHANGED:

	// Frontend shortcode                                                                                                   // CHANGED:
	$shortcodes = PPA_PLUGIN_DIR . 'inc/shortcodes/class-ppa-shortcodes.php';                                              // CHANGED:
	if ( file_exists( $shortcodes ) ) { require_once $shortcodes; \PPA\Shortcodes\PPAShortcodes::init(); }                 // CHANGED:

	// Logging module                                                                                                       // CHANGED:
	$logging = PPA_PLUGIN_DIR . 'inc/logging/class-ppa-logging.php';                                                       // CHANGED:
	if ( file_exists( $logging ) ) { require_once $logging; \PPA\Logging\PPALogging::init(); }                             // CHANGED:
}, 9 );                                                                                                                    // CHANGED:

/** ---------------------------------------------------------------------------------
 * Admin enqueue — scope strictly to our screens
 * -------------------------------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {                                                          // CHANGED:
	$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;                                  // CHANGED:
	$screen_id  = $screen ? $screen->id : '';                                                                              // CHANGED:
	$page_param = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';                                                   // CHANGED:

	$composer_id = 'toplevel_page_postpress-ai';                                                                           // CHANGED:
	$testbed_id  = 'tools_page_ppa-testbed';                                                                               // CHANGED:

	$is_composer = ( $screen_id === $composer_id ) || ( $page_param === 'postpress-ai' );                                  // CHANGED:
	$is_testbed  = ( $screen_id === $testbed_id )  || ( $page_param === 'ppa-testbed' );                                   // CHANGED:

	if ( ! ( $is_composer || $is_testbed ) ) {                                                                             // CHANGED:
		return;                                                                                                            // CHANGED:
	}                                                                                                                      // CHANGED:

	if ( function_exists( 'ppa_admin_enqueue' ) ) {                                                                         // CHANGED:
		ppa_admin_enqueue();                                                                                               // CHANGED:
	}                                                                                                                      // CHANGED:
}, 10 );                                                                                                                   // CHANGED:

/** ---------------------------------------------------------------------------------
 * AJAX handlers — load early so admin-ajax.php can find them
 * -------------------------------------------------------------------------------- */
add_action( 'init', function () {                                                                                          // CHANGED:
	$ajax_dir = PPA_PLUGIN_DIR . 'inc/ajax/';                                                                              // CHANGED:
	foreach ( array( 'preview.php', 'store.php', 'marker.php' ) as $file ) {                                               // CHANGED:
		$path = $ajax_dir . $file;                                                                                         // CHANGED:
		if ( file_exists( $path ) ) { require_once $path; }                                                                // CHANGED:
	}                                                                                                                      // CHANGED:
}, 11 );                                                                                                                   // CHANGED:

/** ---------------------------------------------------------------------------------
 * Public asset cache-busting (ver=filemtime) — handles registered by shortcode
 * -------------------------------------------------------------------------------- */
add_action( 'init', function () {                                                                                          // CHANGED:
	// Styles                                                                                                              // CHANGED:
	add_filter( 'style_loader_src', function ( $src, $handle ) {                                                           // CHANGED:
		if ( 'ppa-frontend' !== $handle ) { return $src; }                                                                 // CHANGED:
		$file = PPA_PLUGIN_DIR . 'assets/css/ppa-frontend.css';                                                            // CHANGED:
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_PLUGIN_VER );                                   // CHANGED:
		$src  = remove_query_arg( 'ver', $src );                                                                           // CHANGED:
		return add_query_arg( 'ver', $ver, $src );                                                                         // CHANGED:
	}, 10, 2 );                                                                                                            // CHANGED:

	// Scripts                                                                                                             // CHANGED:
	add_filter( 'script_loader_src', function ( $src, $handle ) {                                                          // CHANGED:
		if ( 'ppa-frontend' !== $handle ) { return $src; }                                                                 // CHANGED:
		$file = PPA_PLUGIN_DIR . 'assets/js/ppa-frontend.js';                                                              // CHANGED:
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_PLUGIN_VER );                                   // CHANGED:
		$src  = remove_query_arg( 'ver', $src );                                                                           // CHANGED:
		return add_query_arg( 'ver', $ver, $src );                                                                         // CHANGED:
	}, 10, 2 );                                                                                                            // CHANGED:
}, 12 );                                                                                                                   // CHANGED:

/** ---------------------------------------------------------------------------------
 * Top-level admin menu & Composer render (kept in inc/admin/menu.php)
 * --------------------------------------------------------------------------------
 * The actual UI lives in inc/admin/composer.php and is loaded by the menu renderer.
 * This file should not echo HTML; keeping bootstrap clean prevents accidental fatals.
 * -------------------------------------------------------------------------------- */
