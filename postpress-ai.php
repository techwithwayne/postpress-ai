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
 * 2026-01-24 — FIX: Translate polling: persist original_html per draft_hash and re-inject it on poll requests (job_id present) so Django no longer returns 400 Missing original_html. # CHANGED:
 *            — HARDEN: Proxy forwards job_id to Django and aligns timeout with backend budget (300s).                                              # CHANGED:
 *            — HARDEN: If a poll request arrives without cached original_html, fail fast with a clear JSON error instead of looping forever.       # CHANGED:
 *
 * 2026-01-23 — HARDEN: Translate proxy ALWAYS returns JSON + safe debug details (even when WP_DEBUG is off).     # CHANGED:
 *            — HARDEN: Add header parity (X-PostPress-Key + Expect:"") to match known-working probes.           # CHANGED:
 *            — HARDEN: Safer Content-Type retrieval + include HTTP code + elapsed_ms on ALL error paths.        # CHANGED:
 *            — FIX: Register guaranteed callable wp_ajax handler that does NOT depend on namespaced callbacks.   # CHANGED:
 *            — FIX: Add late “final binder” to prevent hook overwrites (admin_init @ 9999).                      # CHANGED:
 *
 * 2026-01-23 — ADD: WP AJAX endpoints for per-user default output language (user_meta).
 * 2026-01-22 — ADD: Default Django base URL constant + ppa_django_base_url filter.
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
 * Customer-safe default backend URL.
 */
if ( ! defined( 'PPA_SERVER_URL' ) ) {                                                             // CHANGED:
	define( 'PPA_SERVER_URL', 'https://apps.techwithwayne.com/postpress-ai' );                     // CHANGED:
}                                                                                                  // CHANGED:

/**
 * Canonical filter that returns the resolved Django base URL.
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
 * Per-user default output language (customer-safe, no WP options required)
 * -------------------------------------------------------------------------------- */
add_action( 'wp_ajax_ppa_get_default_language', function () {
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
} );

add_action( 'wp_ajax_ppa_set_default_language', function () {
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
} );

/** ---------------------------------------------------------------------------------
 * Bulletproof Translate Preview proxy (ALWAYS JSON)
 * -------------------------------------------------------------------------------- */

/**
 * Read JSON body from php://input safely.
 */
if ( ! function_exists( 'ppa_translate__read_json_body' ) ) {
	function ppa_translate__read_json_body() {
		$raw = file_get_contents( 'php://input' );
		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

/**
 * Resolve auth key (Connection Key if present, else License Key).
 */
if ( ! function_exists( 'ppa_translate__get_auth_key' ) ) {
	function ppa_translate__get_auth_key() {
		foreach ( array( 'ppa_connection_key', 'postpress_ai_connection_key' ) as $k ) {
			$v = get_option( $k, '' );
			if ( is_string( $v ) && trim( $v ) !== '' ) {
				return trim( $v );
			}
		}
		foreach ( array( 'ppa_license_key', 'postpress_ai_license_key', 'ppa_activation_key', 'postpress_ai_activation_key' ) as $k ) {
			$v = get_option( $k, '' );
			if ( is_string( $v ) && trim( $v ) !== '' ) {
				return trim( $v );
			}
		}
		return '';
	}
}

/**
 * Force IPv4 for our PythonAnywhere host (prevents some IPv6 route hangs).
 */
if ( ! function_exists( 'ppa_translate__force_ipv4_for_url' ) ) {
	function ppa_translate__force_ipv4_for_url( $handle, $r, $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return;
		}
		if ( strpos( $url, 'apps.techwithwayne.com' ) === false ) {
			return;
		}
		if ( defined( 'CURL_IPRESOLVE_V4' ) && function_exists( 'curl_setopt' ) ) {
			@curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
	}
}

/**
 * Minimal logger (kept quiet unless WP_DEBUG true).
 */
if ( ! function_exists( 'ppa_translate__log' ) ) {                                                    // CHANGED:
	function ppa_translate__log( $msg ) {                                                             // CHANGED:
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {                                                     // CHANGED:
			error_log( 'PPA: [translate] ' . (string) $msg );                                           // CHANGED:
		}                                                                                               // CHANGED:
	}                                                                                                   // CHANGED:
}                                                                                                       // CHANGED:

/**
 * Stable transient keys for translation state.
 * We scope keys by (blog_id + home_url) so multisite / cloned sites don't collide.                         // CHANGED:
 */
if ( ! function_exists( 'ppa_translate__tkey' ) ) {                                                     // CHANGED:
	function ppa_translate__tkey( $kind, $id ) {                                                       // CHANGED:
		$kind = is_string( $kind ) ? $kind : 'x';                                                      // CHANGED:
		$id   = is_string( $id ) ? $id : '';                                                           // CHANGED:
		$blog = function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : '0';       // CHANGED:
		$site = function_exists( 'home_url' ) ? (string) home_url() : '';                               // CHANGED:
		return 'ppa_tr_' . $kind . '_' . md5( $blog . '|' . $site . '|' . $kind . '|' . $id );         // CHANGED:
	}                                                                                                   // CHANGED:
}                                                                                                       // CHANGED:

/**
 * Guaranteed callable AJAX handler for translation.
 */
if ( ! function_exists( 'ppa_translate_preview_proxy' ) ) {
	function ppa_translate_preview_proxy() {
		$start = microtime( true );

		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 320 ); }                         // CHANGED:

		if ( ! is_user_logged_in() ) {
			wp_send_json( array( 'ok' => false, 'error' => 'not_logged_in' ), 401 );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'insufficient_permissions' ), 403 );
		}

		$body = ppa_translate__read_json_body();

		$lang      = isset( $body['lang'] ) ? sanitize_text_field( (string) $body['lang'] ) : '';
		$mode      = isset( $body['mode'] ) ? sanitize_text_field( (string) $body['mode'] ) : 'strict';
		$drafthash = isset( $body['draft_hash'] ) ? sanitize_text_field( (string) $body['draft_hash'] ) : '';
		$job_id    = isset( $body['job_id'] ) ? sanitize_text_field( (string) $body['job_id'] ) : '';  // CHANGED:

		if ( $lang === '' || $lang === 'original' ) {
			wp_send_json( array( 'ok' => false, 'error' => 'missing_or_invalid_lang' ), 400 );
		}

		$django = (string) apply_filters( 'ppa_django_base_url', '' );
		$django = is_string( $django ) ? rtrim( trim( $django ), '/' ) : '';
		if ( $django === '' ) {
			wp_send_json( array( 'ok' => false, 'error' => 'missing_django_url' ), 500 );
		}

		$key = ppa_translate__get_auth_key();
		if ( $key === '' ) {
			wp_send_json( array( 'ok' => false, 'error' => 'missing_auth_key' ), 500 );
		}

		// CHANGED: Persist and re-inject original_html for poll requests so Django can continue the job.
		$is_poll           = ( is_string( $job_id ) && trim( $job_id ) !== '' );                        // CHANGED:
		$original_html     = isset( $body['original_html'] ) ? (string) $body['original_html'] : '';   // CHANGED:
		$original_json     = isset( $body['original_json'] ) ? $body['original_json'] : null;          // CHANGED:
		$has_original_html = ( is_string( $original_html ) && trim( $original_html ) !== '' );          // CHANGED:
		$ttl               = 2 * HOUR_IN_SECONDS;                                                       // CHANGED:
		$injected          = false;                                                                     // CHANGED:

		// Store original_html by draft_hash whenever present (start call).
		if ( $has_original_html && is_string( $drafthash ) && trim( $drafthash ) !== '' ) {             // CHANGED:
			set_transient( ppa_translate__tkey( 'hash', $drafthash ), $original_html, $ttl );           // CHANGED:
		}

		// Store original_html by job_id if job_id is already known on request (rare but safe).
		if ( $has_original_html && $is_poll ) {                                                         // CHANGED:
			set_transient( ppa_translate__tkey( 'job', $job_id ), $original_html, $ttl );               // CHANGED:
		}

		// Poll requests intentionally omit original_html from the client.
		// Re-inject from transient so Django doesn't 400 "Missing original_html".                        // CHANGED:
		if ( $is_poll && ! $has_original_html ) {                                                        // CHANGED:
			$cached = get_transient( ppa_translate__tkey( 'job', $job_id ) );                           // CHANGED:
			if ( ! is_string( $cached ) || trim( $cached ) === '' ) {                                   // CHANGED:
				$mapped_hash = get_transient( ppa_translate__tkey( 'job2hash', $job_id ) );             // CHANGED:
				if ( is_string( $mapped_hash ) && trim( $mapped_hash ) !== '' ) {                       // CHANGED:
					$cached = get_transient( ppa_translate__tkey( 'hash', trim( $mapped_hash ) ) );     // CHANGED:
				}                                                                                       // CHANGED:
			}                                                                                           // CHANGED:
			if ( ( ! is_string( $cached ) || trim( $cached ) === '' ) && $drafthash !== '' ) {          // CHANGED:
				$cached = get_transient( ppa_translate__tkey( 'hash', $drafthash ) );                   // CHANGED:
			}                                                                                           // CHANGED:

			if ( is_string( $cached ) && trim( $cached ) !== '' ) {                                      // CHANGED:
				$original_html     = $cached;                                                           // CHANGED:
				$has_original_html = true;                                                              // CHANGED:
				$injected          = true;                                                              // CHANGED:
			} else {
				// Fail fast with a clear error; otherwise the UI will loop forever.                    // CHANGED:
				wp_send_json(
					array(
						'ok'        => false,
						'error'     => 'missing_original_html',
						'message'   => 'Poll request missing original_html and no cached copy was found for this draft_hash/job_id.',
						'draft_hash'=> $drafthash,
						'job_id'    => $job_id,
					),
					400
				);
			}
		}

		$payload = array(
			'lang'          => $lang,
			'mode'          => ( $mode ? $mode : 'strict' ),
			'draft_hash'    => $drafthash,
			'job_id'        => $job_id,                                                                  // CHANGED:
			'site_url'      => home_url(),
			'original_json' => $original_json,                                                           // CHANGED:
			'original_html' => $has_original_html ? $original_html : null,                                // CHANGED:
			'meta'          => array(                                                                     // CHANGED:
				'is_poll'          => $is_poll,                                                           // CHANGED:
				'injected_original'=> $injected,                                                          // CHANGED:
			),
		);

		$endpoint = $django . '/translate/';

		add_action( 'http_api_curl', 'ppa_translate__force_ipv4_for_url', 10, 3 );

		$encoded = wp_json_encode( $payload );                                                           // CHANGED:
		if ( ! is_string( $encoded ) || $encoded === '' ) {                                               // CHANGED:
			remove_action( 'http_api_curl', 'ppa_translate__force_ipv4_for_url', 10 );                    // CHANGED:
			wp_send_json(
				array(
					'ok'    => false,
					'error' => 'payload_encode_failed',
				),
				500
			);
		}

		$args = array(
			'timeout' => 300,                                                                             // CHANGED:
			'headers' => array(
				'Content-Type'    => 'application/json',
				'Authorization'   => 'Bearer ' . $key,
				'X-PPA-Key'       => $key,
				'X-PostPress-Key' => $key,              // CHANGED: parity with other endpoints/probes
				'X-PPA-Site'      => home_url(),
				'Expect'          => '',                // CHANGED: avoids 100-continue edge cases on some stacks
			),
			'body'    => $encoded,                                                                        // CHANGED:
		);

		$res = wp_remote_post( $endpoint, $args );

		remove_action( 'http_api_curl', 'ppa_translate__force_ipv4_for_url', 10 );

		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$err_code = $res->get_error_code();                                                     // CHANGED:
			$err_data = $res->get_error_data();                                                     // CHANGED:
			wp_send_json(
				array(
					'ok'          => false,
					'error'       => 'upstream_request_failed',
					'msg'         => $res->get_error_message(),
					'wp_err_code' => (string) $err_code,                                           // CHANGED:
					'wp_err_data' => is_scalar( $err_data ) ? (string) $err_data : $err_data,      // CHANGED:
					'endpoint'    => $endpoint,
					'elapsed_ms'  => $elapsed_ms,
				),
				502
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );

		// CHANGED: safer header retrieval (avoid “Array” or empty surprises).
		$content_type = '';                                                                          // CHANGED:
		$headers      = wp_remote_retrieve_headers( $res );                                          // CHANGED:
		if ( is_array( $headers ) && isset( $headers['content-type'] ) ) {                           // CHANGED:
			$content_type = (string) $headers['content-type'];                                      // CHANGED:
		}                                                                                             // CHANGED:

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			wp_send_json(
				array(
					'ok'           => false,
					'error'        => 'upstream_invalid_json',
					'code'         => $code,
					'content_type' => $content_type,
					'raw_len'      => strlen( $raw ),
					'raw_snippet'  => substr( $raw, 0, 420 ),
					'endpoint'     => $endpoint,
					'elapsed_ms'   => $elapsed_ms,
				),
				502
			);
		}

		// CHANGED: If Django returned a job_id, store job_id→hash mapping (and job_id→original_html if we have it).
		$up_job_id = '';                                                                              // CHANGED:
		if ( isset( $decoded['job_id'] ) ) {                                                          // CHANGED:
			$up_job_id = sanitize_text_field( (string) $decoded['job_id'] );                          // CHANGED:
		} elseif ( isset( $decoded['data']['job_id'] ) ) {                                            // CHANGED:
			$up_job_id = sanitize_text_field( (string) $decoded['data']['job_id'] );                  // CHANGED:
		}
		if ( is_string( $up_job_id ) && trim( $up_job_id ) !== '' && is_string( $drafthash ) && trim( $drafthash ) !== '' ) { // CHANGED:
			set_transient( ppa_translate__tkey( 'job2hash', $up_job_id ), $drafthash, $ttl );          // CHANGED:
			if ( $has_original_html ) {                                                                // CHANGED:
				set_transient( ppa_translate__tkey( 'job', $up_job_id ), $original_html, $ttl );       // CHANGED:
			}
		}

		if ( $code >= 400 ) {
			$decoded['ok']           = false;
			$decoded['code']         = $code;
			$decoded['content_type'] = $content_type;
			$decoded['elapsed_ms']   = $elapsed_ms;
			wp_send_json( $decoded, $code );
		}

		if ( ! isset( $decoded['ok'] ) ) {
			$decoded['ok'] = true;
		}
		$decoded['elapsed_ms']   = $elapsed_ms;
		$decoded['content_type'] = $content_type;

		wp_send_json( $decoded, 200 );
	}
}

/**
 * Register bulletproof handler FIRST (priority 0).
 */
add_action( 'wp_ajax_ppa_translate_preview', 'ppa_translate_preview_proxy', 0 );

/**
 * FINAL BINDER (customer-safe):
 * If something later removed/replaced our hook, this reasserts it at the end of admin bootstrap.
 *
 * Why admin_init?
 * - admin-ajax.php triggers admin_init in practice.
 * - This runs late (9999) so we win after other plugin code loads.
 */
add_action( 'admin_init', function () {                                                              // CHANGED:
	// Only care about admin context.
	if ( ! is_admin() ) {                                                                            // CHANGED:
		return;                                                                                      // CHANGED:
	}                                                                                                // CHANGED:

	// If our proxy is missing, the includes are broken — log and exit.
	if ( ! function_exists( 'ppa_translate_preview_proxy' ) ) {                                      // CHANGED:
		ppa_translate__log( 'FINAL BINDER: proxy missing (ppa_translate_preview_proxy not loaded).' ); // CHANGED:
		return;                                                                                      // CHANGED:
	}                                                                                                // CHANGED:

	// If already bound to our proxy, keep hands off.
	$bound = has_action( 'wp_ajax_ppa_translate_preview', 'ppa_translate_preview_proxy' );           // CHANGED:
	if ( ! empty( $bound ) ) {                                                                       // CHANGED:
		ppa_translate__log( 'FINAL BINDER: hook already bound to proxy (priority ' . $bound . ').' ); // CHANGED:
		return;                                                                                      // CHANGED:
	}                                                                                                // CHANGED:

	// Something overwrote/removed it — reassert "final truth" for THIS hook only.
	remove_all_actions( 'wp_ajax_ppa_translate_preview' );                                           // CHANGED:
	add_action( 'wp_ajax_ppa_translate_preview', 'ppa_translate_preview_proxy', 0 );                 // CHANGED:

	// Optional parity: nopriv hook (shouldn't be used from wp-admin, but keeps behavior deterministic).
	remove_all_actions( 'wp_ajax_nopriv_ppa_translate_preview' );                                    // CHANGED:
	add_action( 'wp_ajax_nopriv_ppa_translate_preview', 'ppa_translate_preview_proxy', 0 );          // CHANGED:

	ppa_translate__log( 'FINAL BINDER: re-bound wp_ajax_ppa_translate_preview -> proxy at priority 0.' ); // CHANGED:
}, 9999 );                                                                                            // CHANGED:

/** ---------------------------------------------------------------------------------
 * AJAX handlers — controller/legacy + marker (safe to load; bulletproof handler stays owner)
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	$controller = PPA_PLUGIN_DIR . 'inc/class-ppa-controller.php';
	$ajax_dir   = PPA_PLUGIN_DIR . 'inc/ajax/';

	if ( file_exists( $controller ) ) {
		require_once $controller;
	} else {
		foreach ( array( 'preview.php', 'store.php', 'translate.php' ) as $file ) {
			$path = $ajax_dir . $file;
			if ( file_exists( $path ) ) { require_once $path; }
		}
	}

	$marker = $ajax_dir . 'marker.php';
	if ( file_exists( $marker ) ) { require_once $marker; }
}, 8 );

/** ---------------------------------------------------------------------------------
 * Includes — Admin UI + enqueue + shortcodes + logging
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
 * Admin enqueue — delegate to inc/admin/enqueue.php
 * -------------------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
	if ( function_exists( 'ppa_admin_enqueue' ) ) {
		remove_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 10 );
		remove_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 99 );
		add_action( 'admin_enqueue_scripts', 'ppa_admin_enqueue', 99 );
	}
}, 10 );

/** ---------------------------------------------------------------------------------
 * Admin asset cache-busting (ver=filemtime)
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
 * Public asset cache-busting (ver=filemtime)
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
