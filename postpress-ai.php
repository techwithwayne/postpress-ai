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
 * 2025-11-10 — Simplify admin enqueue: delegate screen checks to ppa_admin_enqueue() and          # CHANGED:
 *              remove duplicate gating here; hook once after includes load.                       # CHANGED:
 * 2025-11-09 — Recognize new Testbed screen id 'postpress-ai_page_ppa-testbed'; sanitize $_GET['page']; // CHANGED:
 *              keep legacy 'tools_page_ppa-testbed' and query fallback.                                   // CHANGED:
 * 2025-11-08 — Add PPA_PLUGIN_FILE; add PPA_VERSION alias to PPA_PLUGIN_VER for consistency;             // CHANGED:
 *              keep centralized enqueue wiring; minor tidy of cache-bust fallbacks to use PPA_VERSION.   // CHANGED:
 * 2025-11-08 — Prefer controller class for AJAX (includes inc/class-ppa-controller.php on plugins_loaded);
 *              fallback to inc/ajax/{preview.php,store.php} only if controller not found; always load marker.php.
 * 2025-11-04 — Repair fatal: remove stray/duplicated blocks, complete cache-buster, centralize requires, init logging & shortcode.
 * 2025-11-04 — Scope admin enqueue to Composer/Testbed screens only.
 * 2025-11-04 — Load AJAX handlers early.
 * 2025-11-04 — Add robust asset version rotator (filemtime fallback to PPA_PLUGIN_VER).
 * 2025-11-04 — No inline JS/CSS in templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ---------------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------------- */
if ( ! defined( 'PPA_PLUGIN_FILE' ) ) {                     // CHANGED:
	define( 'PPA_PLUGIN_FILE', __FILE__ );                  // CHANGED:
}
if ( ! defined( 'PPA_PLUGIN_DIR' ) ) {
	define( 'PPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_URL' ) ) {
	define( 'PPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_VER' ) ) {
	define( 'PPA_PLUGIN_VER', '2.1.0' );
}
if ( ! defined( 'PPA_VERSION' ) ) {                         // CHANGED:
	// Alias for consistency in new code paths; keeps older PPA_PLUGIN_VER usages valid.  // CHANGED:
	define( 'PPA_VERSION', PPA_PLUGIN_VER );               // CHANGED:
}

/** ---------------------------------------------------------------------------------
 * Includes (single source of truth)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	// Admin UI (menu + composer renderer)
	$admin_menu = PPA_PLUGIN_DIR . 'inc/admin/menu.php';
	if ( file_exists( $admin_menu ) ) { require_once $admin_menu; }

	// Admin enqueue helpers
	$admin_enqueue = PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';
	if ( file_exists( $admin_enqueue ) ) { require_once $admin_enqueue; }

	// Frontend shortcode
	$shortcodes = PPA_PLUGIN_DIR . 'inc/shortcodes/class-ppa-shortcodes.php';
	if ( file_exists( $shortcodes ) ) { require_once $shortcodes; \PPA\Shortcodes\PPAShortcodes::init(); }

	// Logging module
	$logging = PPA_PLUGIN_DIR . 'inc/logging/class-ppa-logging.php';
	if ( file_exists( $logging ) ) { require_once $logging; \PPA\Logging\PPALogging::init(); }
}, 9 );

/** ---------------------------------------------------------------------------------
 * AJAX handlers — load early so admin-ajax.php can find them
 * Prefer controller class; fallback to legacy inc/ajax/* files if missing.
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	$controller = PPA_PLUGIN_DIR . 'inc/class-ppa-controller.php';
	$ajax_dir   = PPA_PLUGIN_DIR . 'inc/ajax/';

	if ( file_exists( $controller ) ) {
		// Preferred path: class registers wp_ajax_* hooks internally.
		require_once $controller;
	} else {
		// Fallback path: legacy handlers directly define wp_ajax_* callbacks.
		foreach ( array( 'preview.php', 'store.php' ) as $file ) {
			$path = $ajax_dir . $file;
			if ( file_exists( $path ) ) { require_once $path; }
		}
	}

	// marker.php is always loaded (no controller equivalent).
	$marker = $ajax_dir . 'marker.php';
	if ( file_exists( $marker ) ) { require_once $marker; }
}, 8 );

/** ---------------------------------------------------------------------------------
 * Admin enqueue — delegate to inc/admin/enqueue.php (single source of truth)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {                                                     // CHANGED:
	// After includes above (priority 9), this runs and attaches the enqueue if present.     // CHANGED:
	if ( function_exists( 'ppa_admin_enqueue' ) ) {                                           // CHANGED:
		add_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 10 );                      // CHANGED:
	}                                                                                         // CHANGED:
}, 10 );                                                                                       // CHANGED:

/** ---------------------------------------------------------------------------------
 * Public asset cache-busting (ver=filemtime) — handles registered by shortcode
 * -------------------------------------------------------------------------------- */
add_action( 'init', function () {
	// Styles
	add_filter( 'style_loader_src', function ( $src, $handle ) {
		if ( 'ppa-frontend' !== $handle ) { return $src; }
		$file = PPA_PLUGIN_DIR . 'assets/css/ppa-frontend.css';
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );  // CHANGED:
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 10, 2 );

	// Scripts
	add_filter( 'script_loader_src', function ( $src, $handle ) {
		if ( 'ppa-frontend' !== $handle ) { return $src; }
		$file = PPA_PLUGIN_DIR . 'assets/js/ppa-frontend.js';
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );  // CHANGED:
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 10, 2 );
}, 12 );

/** ---------------------------------------------------------------------------------
 * Top-level admin menu & Composer render (kept in inc/admin/menu.php)
 * --------------------------------------------------------------------------------
 * The actual UI lives in inc/admin/composer.php and is loaded by the menu renderer.
 * This file should not echo HTML; keeping bootstrap clean prevents accidental fatals.
 * -------------------------------------------------------------------------------- */
