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
 * 2026-01-23 — FIX: Bulletproof translate proxy: register a guaranteed callable wp_ajax handler that ALWAYS returns JSON.  # CHANGED:
 *            — FIX: Eliminates wp_ajax_0 / invalid-json caused by missing namespaced callback at runtime.               # CHANGED:
 *
 * 2026-01-23 — FIX: Make AJAX includes single-source + deterministic so translate_preview is always defined.          # CHANGED:
 *            — FIX: Remove duplicate translate.php include path (debugging was unreliable).                           # CHANGED:
 *
 * 2026-01-23 — ADD: WP AJAX endpoints for per-user default output language (user_meta).                               # CHANGED:
 *            — UX: Allows "Set default" without WP options or wp-config edits (customer-safe).                        # CHANGED:
 *
 * 2026-01-22 — ADD: Default Django base URL constant + ppa_django_base_url filter for customer sites
 *              where WP options are intentionally absent (no wp-config edits allowed).                                # CHANGED:
 *
 * 2025-12-28 — HARDEN: Arm admin-post fallback only when the incoming request action matches our settings actions.
 *              This reduces debug.log noise and avoids attaching extra hooks on unrelated admin-post requests.
 * 2025-12-28 — HARDEN: Detect admin-post via $pagenow OR PHP_SELF basename for stacks where $pagenow isn't set yet.
 * 2025-12-28 — FIX: Admin-post fallback handlers for Settings actions.
 *
 * 2025-11-11 — Fix syntax error in includes block; keep enqueue + ver overrides.
 * 2025-11-10 — Simplify admin enqueue; force filemtime ver for key assets.
 * 2025-11-08 — Prefer controller class for AJAX; fallback to inc/ajax/* only if controller not found; always load marker.php.
 * 2025-11-04 — Centralize requires; init logging & shortcode; remove inline JS/CSS.
 * 2025-12-25 — LOAD: inc/admin/settings.php so Settings submenu + licensing actions register.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ---------------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------------- */
if ( ! defined( 'PPA_PLUGIN_FILE' ) ) {                      // CHANGED:
	define( 'PPA_PLUGIN_FILE', __FILE__ );                   // CHANGED:
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
if ( ! defined( 'PPA_VERSION' ) ) {                          // CHANGED:
	define( 'PPA_VERSION', PPA_PLUGIN_VER );                 // CHANGED:
}

/**
 * CHANGED: Customer-safe default backend URL.
 */
if ( ! defined( 'PPA_SERVER_URL' ) ) {                                                             // CHANGED:
	define( 'PPA_SERVER_URL', 'https://apps.techwithwayne.com/postpress-ai' );                     // CHANGED:
}                                                                                                  // CHANGED:

/**
 * CHANGED: Provide a canonical filter that returns the resolved Django base URL.
 */
add_filter( 'ppa_django_base_url', function( $base ) {                                              // CHANGED:
	if ( ( ! is_string( $base ) || trim( $base ) === '' ) ) {                                      // CHANGED:
		if ( defined( 'PPA_DJANGO_URL' ) && is_string( PPA_DJANGO_URL ) && trim( PPA_DJANGO_URL ) !== '' ) { // CHANGED:
			return rtrim( trim( PPA_DJANGO_URL ), '/' );                                            // CHANGED:
		}                                                                                           // CHANGED:
		if ( defined( 'PPA_SERVER_URL' ) && is_string( PPA_SERVER_URL ) && trim( PPA_SERVER_URL ) !== '' ) { // CHANGED:
			return rtrim( trim( PPA_SERVER_URL ), '/' );                                            // CHANGED:
		}                                                                                           // CHANGED:
		return '';                                                                                  // CHANGED:
	}                                                                                               // CHANGED:
	return rtrim( trim( (string) $base ), '/' );                                                    // CHANGED:
}, 10, 1 );                                                                                         // CHANGED:

/** ---------------------------------------------------------------------------------
 * CHANGED: Per-user default output language (customer-safe, no WP options required)
 * -------------------------------------------------------------------------------- */
add_action( 'wp_ajax_ppa_get_default_language', function () {                                       // CHANGED:
	if ( ! is_user_logged_in() ) {
		wp_send_json( array( 'ok' => false, 'error' => 'not_logged_in' ), 401 );
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'insufficient_permissions' ), 403 );
	}

	$user_id = get_current_user_id();
	$lang    = get_user_meta( $user_id, 'ppa_default_output_language', true );
	$lang    = is_string( $lang ) ? trim( $lang ) : '';

	$allowed = array(
		'original','en','es','fr','de','it','pt','nl','sv','no','da','fi','pl','cs','hu','tr','el','ru','uk','ar','he','hi','bn','th','vi','id','ms','tl','zh','zh-TW','ja','ko',
	);
	if ( $lang === '' || ! in_array( $lang, $allowed, true ) ) {
		$lang = 'original';
	}

	wp_send_json( array( 'ok' => true, 'lang' => $lang ), 200 );
} );                                                                                                // CHANGED:

add_action( 'wp_ajax_ppa_set_default_language', function () {                                       // CHANGED:
	if ( ! is_user_logged_in() ) {
		wp_send_json( array( 'ok' => false, 'error' => 'not_logged_in' ), 401 );
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'insufficient_permissions' ), 403 );
	}

	$lang = '';
	$raw  = file_get_contents( 'php://input' );
	if ( is_string( $raw ) && trim( $raw ) !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['lang'] ) ) {
			$lang = (string) $decoded['lang'];
		}
	}
	if ( $lang === '' && isset( $_POST['lang'] ) ) {
		$lang = (string) wp_unslash( $_POST['lang'] );
	}
	$lang = sanitize_text_field( $lang );

	$allowed = array(
		'original','en','es','fr','de','it','pt','nl','sv','no','da','fi','pl','cs','hu','tr','el','ru','uk','ar','he','hi','bn','th','vi','id','ms','tl','zh','zh-TW','ja','ko',
	);
	if ( $lang === '' || ! in_array( $lang, $allowed, true ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'invalid_lang' ), 400 );
	}

	$user_id = get_current_user_id();
	update_user_meta( $user_id, 'ppa_default_output_language', $lang );

	wp_send_json( array( 'ok' => true, 'lang' => $lang ), 200 );
} );                                                                                                // CHANGED:

/** ---------------------------------------------------------------------------------
 * CHANGED: Bulletproof Translate Preview proxy (ALWAYS JSON)
 *
 * Why:
 * - Your runtime is showing translate.php is included but the namespaced function is NOT defined.
 * - That causes admin-ajax to print "0" (wp_ajax_0) which JS reads as invalid JSON.
 * - This handler is guaranteed callable (defined here) and runs at priority 0.
 * - Even if the legacy callback is broken, this one exits first via wp_send_json().
 * -------------------------------------------------------------------------------- */

/**
 * CHANGED: Read JSON body from php://input safely.
 */
if ( ! function_exists( 'ppa_translate__read_json_body' ) ) {                                        // CHANGED:
	function ppa_translate__read_json_body() {                                                     // CHANGED:
		$raw = file_get_contents( 'php://input' );                                                 // CHANGED:
		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {                                        // CHANGED:
			return array();                                                                        // CHANGED:
		}                                                                                          // CHANGED:
		$decoded = json_decode( $raw, true );                                                      // CHANGED:
		return is_array( $decoded ) ? $decoded : array();                                          // CHANGED:
	}                                                                                              // CHANGED:
}                                                                                                  // CHANGED:

/**
 * CHANGED: Resolve auth key (Connection Key if present, else License Key).
 */
if ( ! function_exists( 'ppa_translate__get_auth_key' ) ) {                                         // CHANGED:
	function ppa_translate__get_auth_key() {                                                       // CHANGED:
		// Legacy connection key first.                                                            // CHANGED:
		foreach ( array( 'ppa_connection_key', 'postpress_ai_connection_key' ) as $k ) {            // CHANGED:
			$v = get_option( $k, '' );                                                             // CHANGED:
			if ( is_string( $v ) && trim( $v ) !== '' ) {                                          // CHANGED:
				return trim( $v );                                                                 // CHANGED:
			}                                                                                      // CHANGED:
		}                                                                                          // CHANGED:
		// License key fallback.                                                                   // CHANGED:
		foreach ( array( 'ppa_license_key', 'postpress_ai_license_key', 'ppa_activation_key', 'postpress_ai_activation_key' ) as $k ) { // CHANGED:
			$v = get_option( $k, '' );                                                             // CHANGED:
			if ( is_string( $v ) && trim( $v ) !== '' ) {                                          // CHANGED:
				return trim( $v );                                                                 // CHANGED:
			}                                                                                      // CHANGED:
		}                                                                                          // CHANGED:
		return '';                                                                                 // CHANGED:
	}                                                                                              // CHANGED:
}                                                                                                  // CHANGED:

/**
 * CHANGED: Force IPv4 when calling our PythonAnywhere host (prevents some IPv6 route hangs).
 */
if ( ! function_exists( 'ppa_translate__force_ipv4_for_url' ) ) {                                   // CHANGED:
	function ppa_translate__force_ipv4_for_url( $handle, $r, $url ) {                              // CHANGED:
		if ( ! is_string( $url ) || $url === '' ) {                                                // CHANGED:
			return;                                                                                // CHANGED:
		}                                                                                          // CHANGED:
		if ( strpos( $url, 'apps.techwithwayne.com' ) === false ) {                                // CHANGED:
			return;                                                                                // CHANGED:
		}                                                                                          // CHANGED:
		if ( defined( 'CURL_IPRESOLVE_V4' ) && function_exists( 'curl_setopt' ) ) {                // CHANGED:
			@curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );                         // CHANGED:
		}                                                                                          // CHANGED:
	}                                                                                              // CHANGED:
}                                                                                                  // CHANGED:

/**
 * CHANGED: Guaranteed callable AJAX handler for translation.
 * Runs at priority 0 so it exits before any broken callback can output "0".
 */
if ( ! function_exists( 'ppa_translate_preview_proxy' ) ) {                                         // CHANGED:
	function ppa_translate_preview_proxy() {                                                       // CHANGED:
		$start = microtime( true );                                                                // CHANGED:

		// Must be logged in (admin-only AJAX).                                                     // CHANGED:
		if ( ! is_user_logged_in() ) {                                                             // CHANGED:
			wp_send_json( array( 'ok' => false, 'error' => 'not_logged_in' ), 401 );               // CHANGED:
		}                                                                                          // CHANGED:
		if ( ! current_user_can( 'edit_posts' ) ) {                                                // CHANGED:
			wp_send_json( array( 'ok' => false, 'error' => 'insufficient_permissions' ), 403 );   // CHANGED:
		}                                                                                          // CHANGED:

		$body = ppa_translate__read_json_body();                                                   // CHANGED:

		$lang      = isset( $body['lang'] ) ? sanitize_text_field( (string) $body['lang'] ) : '';  // CHANGED:
		$mode      = isset( $body['mode'] ) ? sanitize_text_field( (string) $body['mode'] ) : 'strict'; // CHANGED:
		$drafthash = isset( $body['draft_hash'] ) ? sanitize_text_field( (string) $body['draft_hash'] ) : ''; // CHANGED:

		if ( $lang === '' || $lang === 'original' ) {                                              // CHANGED:
			wp_send_json( array( 'ok' => false, 'error' => 'missing_or_invalid_lang' ), 400 );     // CHANGED:
		}                                                                                          // CHANGED:

		$django = (string) apply_filters( 'ppa_django_base_url', '' );                              // CHANGED:
		$django = is_string( $django ) ? rtrim( trim( $django ), '/' ) : '';                       // CHANGED:
		if ( $django === '' ) {                                                                    // CHANGED:
			wp_send_json( array( 'ok' => false, 'error' => 'missing_django_url' ), 500 );          // CHANGED:
		}                                                                                          // CHANGED:

		$key = ppa_translate__get_auth_key();                                                      // CHANGED:
		if ( $key === '' ) {                                                                       // CHANGED:
			wp_send_json( array( 'ok' => false, 'error' => 'missing_auth_key' ), 500 );            // CHANGED:
		}                                                                                          // CHANGED:

		$payload = array(                                                                          // CHANGED:
			'lang'          => $lang,                                                              // CHANGED:
			'mode'          => ( $mode ? $mode : 'strict' ),                                       // CHANGED:
			'draft_hash'    => $drafthash,                                                         // CHANGED:
			'site_url'      => home_url(),                                                         // CHANGED:
			'original_json' => isset( $body['original_json'] ) ? $body['original_json'] : null,    // CHANGED:
			'original_html' => isset( $body['original_html'] ) ? (string) $body['original_html'] : null, // CHANGED:
		);                                                                                         // CHANGED:

		$endpoint = $django . '/translate/';                                                       // CHANGED:

		// Force IPv4 for this one request.                                                        // CHANGED:
		add_action( 'http_api_curl', 'ppa_translate__force_ipv4_for_url', 10, 3 );                 // CHANGED:

		$args = array(                                                                             // CHANGED:
			'timeout' => 120,                                                                      // CHANGED:
			'headers' => array(                                                                    // CHANGED:
				'Content-Type'  => 'application/json',                                             // CHANGED:
				'Authorization' => 'Bearer ' . $key,                                               // CHANGED:
				'X-PPA-Key'     => $key,                                                           // CHANGED:
				'X-PPA-Site'    => home_url(),                                                     // CHANGED:
			),                                                                                     // CHANGED:
			'body'    => wp_json_encode( $payload ),                                               // CHANGED:
		);                                                                                         // CHANGED:

		$res = wp_remote_post( $endpoint, $args );                                                 // CHANGED:

		// Remove hook immediately so we don’t affect other WP HTTP calls.                          // CHANGED:
		remove_action( 'http_api_curl', 'ppa_translate__force_ipv4_for_url', 10 );                 // CHANGED:

		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );                        // CHANGED:

		if ( is_wp_error( $res ) ) {                                                               // CHANGED:
			wp_send_json(                                                                          // CHANGED:
				array(                                                                             // CHANGED:
					'ok'         => false,                                                         // CHANGED:
					'error'      => 'upstream_request_failed',                                     // CHANGED:
					'msg'        => $res->get_error_message(),                                     // CHANGED:
					'endpoint'   => $endpoint,                                                     // CHANGED:
					'elapsed_ms' => $elapsed_ms,                                                   // CHANGED:
				),                                                                                // CHANGED:
				502                                                                               // CHANGED:
			);                                                                                    // CHANGED:
		}                                                                                          // CHANGED:

		$code = (int) wp_remote_retrieve_response_code( $res );                                    // CHANGED:
		$raw  = (string) wp_remote_retrieve_body( $res );                                          // CHANGED:

		$decoded = json_decode( $raw, true );                                                      // CHANGED:

		// Always return JSON to JS (prevents "invalid_json").                                      // CHANGED:
		if ( ! is_array( $decoded ) ) {                                                            // CHANGED:
			$out = array(                                                                          // CHANGED:
				'ok'         => false,                                                             // CHANGED:
				'error'      => 'upstream_invalid_json',                                           // CHANGED:
				'code'       => $code,                                                             // CHANGED:
				'elapsed_ms' => $elapsed_ms,                                                       // CHANGED:
			);                                                                                     // CHANGED:
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {                                              // CHANGED:
				$out['raw_len']     = strlen( $raw );                                              // CHANGED:
				$out['raw_snippet'] = substr( $raw, 0, 240 );                                      // CHANGED:
			}                                                                                      // CHANGED:
			wp_send_json( $out, 502 );                                                             // CHANGED:
		}                                                                                          // CHANGED:

		// Preserve upstream error code but still JSON.                                             // CHANGED:
		if ( $code >= 400 ) {                                                                      // CHANGED:
			$decoded['ok']         = false;                                                        // CHANGED:
			$decoded['code']       = $code;                                                        // CHANGED:
			$decoded['elapsed_ms'] = $elapsed_ms;                                                  // CHANGED:
			wp_send_json( $decoded, $code );                                                       // CHANGED:
		}                                                                                          // CHANGED:

		if ( ! isset( $decoded['ok'] ) ) {                                                         // CHANGED:
			$decoded['ok'] = true;                                                                 // CHANGED:
		}                                                                                          // CHANGED:
		$decoded['elapsed_ms'] = $elapsed_ms;                                                      // CHANGED:

		wp_send_json( $decoded, 200 );                                                             // CHANGED:
	}                                                                                              // CHANGED:
}                                                                                                  // CHANGED:

// CHANGED: Register bulletproof handler FIRST (priority 0) so broken callbacks can’t output "0".
add_action( 'wp_ajax_ppa_translate_preview', 'ppa_translate_preview_proxy', 0 );                     // CHANGED:

/** ---------------------------------------------------------------------------------
 * AJAX handlers — SINGLE SOURCE OF TRUTH
 * Load early so admin-ajax.php can always find handlers.
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {                                                         // CHANGED:
	$controller = PPA_PLUGIN_DIR . 'inc/class-ppa-controller.php';                                   // CHANGED:
	$ajax_dir   = PPA_PLUGIN_DIR . 'inc/ajax/';                                                      // CHANGED:

	if ( file_exists( $controller ) ) {                                                              // CHANGED:
		require_once $controller;                                                                    // CHANGED:
	} else {                                                                                        // CHANGED:
		foreach ( array( 'preview.php', 'store.php', 'translate.php' ) as $file ) {                   // CHANGED:
			$path = $ajax_dir . $file;                                                               // CHANGED:
			if ( file_exists( $path ) ) { require_once $path; }                                      // CHANGED:
		}                                                                                            // CHANGED:
	}                                                                                                // CHANGED:

	$marker = $ajax_dir . 'marker.php';                                                              // CHANGED:
	if ( file_exists( $marker ) ) { require_once $marker; }                                          // CHANGED:
}, 8 );                                                                                              // CHANGED:

/** ---------------------------------------------------------------------------------
 * Includes (single source of truth) — Admin UI + enqueue + shortcodes + logging
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	$admin_menu = PPA_PLUGIN_DIR . 'inc/admin/menu.php';
	if ( file_exists( $admin_menu ) ) { require_once $admin_menu; }

	$admin_settings = PPA_PLUGIN_DIR . 'inc/admin/settings.php';
	if ( file_exists( $admin_settings ) ) { require_once $admin_settings; }

	$admin_enqueue = PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';
	if ( file_exists( $admin_enqueue ) ) { require_once $admin_enqueue; }

	$shortcodes = PPA_PLUGIN_DIR . 'inc/shortcodes/class-ppa-shortcodes.php';
	if ( file_exists( $shortcodes ) ) {
		require_once $shortcodes;
		\PPA\Shortcodes\PPAShortcodes::init();
	}

	$logging = PPA_PLUGIN_DIR . 'inc/logging/class-ppa-logging.php';
	if ( file_exists( $logging ) ) {
		require_once $logging;
		\PPA\Logging\PPALogging::init();
	}
}, 9 );

/** ---------------------------------------------------------------------------------
 * Admin-post fallback handlers (Settings actions)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	if ( ! is_admin() ) {
		return;
	}

	$pagenow  = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	$php_self = isset( $_SERVER['PHP_SELF'] ) ? basename( (string) $_SERVER['PHP_SELF'] ) : '';
	if ( 'admin-post.php' !== $pagenow && 'admin-post.php' !== $php_self ) {
		return;
	}

	$req_action = '';
	if ( isset( $_REQUEST['action'] ) ) {
		$req_action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
	}

	$action_map = array(
		'ppa_test_connectivity' => 'handle_test_connectivity',
		'ppa_license_verify'    => 'handle_license_verify',
		'ppa_license_activate'  => 'handle_license_activate',
		'ppa_license_deactivate'=> 'handle_license_deactivate',
	);

	if ( '' === $req_action || ! isset( $action_map[ $req_action ] ) ) {
		return;
	}

	$settings_file = PPA_PLUGIN_DIR . 'inc/admin/settings.php';

	$dispatch = function ( $method, $action ) use ( $settings_file ) {
		if ( file_exists( $settings_file ) ) {
			require_once $settings_file;
		}

		if ( class_exists( 'PPA_Admin_Settings' ) && is_callable( array( 'PPA_Admin_Settings', $method ) ) ) {
			call_user_func( array( 'PPA_Admin_Settings', $method ) );
			exit;
		}

		error_log( 'PPA: admin-post fallback could not dispatch ' . $action . ' → ' . $method );
		wp_die(
			esc_html__( 'PostPress AI settings handler missing. Please reinstall or contact support.', 'postpress-ai' ),
			'PostPress AI',
			array( 'response' => 500 )
		);
	};

	$method = $action_map[ $req_action ];

	add_action( 'admin_post_' . $req_action, function () use ( $dispatch, $method, $req_action ) {
		$dispatch( $method, $req_action );
	}, 0 );

	error_log( 'PPA: admin-post fallback armed (action=' . $req_action . ')' );
}, 7 );

/** ---------------------------------------------------------------------------------
 * Admin enqueue — delegate to inc/admin/enqueue.php (single source of truth)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	if ( function_exists( 'ppa_admin_enqueue' ) ) {
		remove_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 10 );
		remove_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 99 );
		add_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 99 );
	}
}, 10 );

/** ---------------------------------------------------------------------------------
 * Admin asset cache-busting (ver=filemtime) — enforce for admin handles/SRCs
 * -------------------------------------------------------------------------------- */
add_action( 'admin_init', function () {
	add_filter( 'style_loader_src', function ( $src, $handle ) {
		if ( 'ppa-admin-css' !== $handle ) { return $src; }
		$file = PPA_PLUGIN_DIR . 'assets/css/admin.css';
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 10, 2 );

	add_filter( 'script_loader_src', function ( $src, $handle ) {
		if ( 'ppa-admin' !== $handle && 'ppa-testbed' !== $handle ) { return $src; }
		$file = ( 'ppa-admin' === $handle )
			? ( PPA_PLUGIN_DIR . 'assets/js/admin.js' )
			: ( PPA_PLUGIN_DIR . 'inc/admin/ppa-testbed.js' );
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 10, 2 );

	add_filter( 'script_loader_src', function ( $src, $handle ) {
		if ( strpos( (string) $src, 'postpress-ai/assets/js/admin.js' ) === false ) {
			return $src;
		}
		$file = PPA_PLUGIN_DIR . 'assets/js/admin.js';
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 999, 2 );
}, 9 );

/** ---------------------------------------------------------------------------------
 * Public asset cache-busting (ver=filemtime) — handles registered by shortcode
 * -------------------------------------------------------------------------------- */
add_action( 'init', function () {
	add_filter( 'style_loader_src', function ( $src, $handle ) {
		if ( 'ppa-frontend' !== $handle ) { return $src; }
		$file = PPA_PLUGIN_DIR . 'assets/css/ppa-frontend.css';
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 10, 2 );

	add_filter( 'script_loader_src', function ( $src, $handle ) {
		if ( 'ppa-frontend' !== $handle ) { return $src; }
		$file = PPA_PLUGIN_DIR . 'assets/js/ppa-frontend.js';
		$ver  = ( file_exists( $file ) ? (string) filemtime( $file ) : PPA_VERSION );
		$src  = remove_query_arg( 'ver', $src );
		return add_query_arg( 'ver', $ver, $src );
	}, 10, 2 );
}, 12 );
