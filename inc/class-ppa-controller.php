<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
 * 2026-02-19 • FIX: Forward whitelisted Account intent params (billing_portal) from WP → Django so Django can return a one-time Stripe Billing Portal session URL (no email login). // CHANGED:
 *            • HARDEN: Add a short WP transient cache for account_status to prevent Django rate-limit 'too many requests' during UI refresh/popup workflows (bypassed for billing_portal intent). // CHANGED:
 * 2026-02-20 • FIX: Accept PPA admin nonce via headers (X-PPA-Nonce / X-WP-Nonce) and support legacy 'ppa-admin' nonce action string for customer installs. // CHANGED:
 *            • FIX: Treat x-www-form-urlencoded POSTs with a JSON 'payload' field as valid JSON bodies (back-compat across JS bundles). // CHANGED:
 *            • FIX: Update default Django base URL fallback to apps.techwithwayne.com to prevent WP-theme 404 HTML being returned from postpressai.com when the base option is unset. // CHANGED:
 *
 *
 * 2026-01-26 • FIX: Account verify now forcibly bypasses Django verify caching (URL _ts + no-store headers). // CHANGED:
 *            • HARDEN: Adds explicit cache-bypass headers for Account verify only (no behavior change elsewhere). // CHANGED:
 *
 * 2026-01-25 • ADD: wp_ajax_ppa_account_status → Django /license/verify/ bridge for Account screen (plan/sites/tokens/links). // CHANGED:
 *            • LOCKED: WP → admin-ajax → PHP → Django only. No Stripe. No CORS/auth contract changes.                       // CHANGED:
 *
 * 2026-01-24 • FIX: Strip divider-only lines like '---' and '...' from AI output before rendering/saving.     // CHANGED:
 *            Applies to preview/generate/store + saved WP post content.                                       // CHANGED:
 *
 * 2026-01-24 • FIX: When saving a translated post, prefer translated title fields if present,            // CHANGED:
 *            and add a safe fallback that extracts a non-ASCII <h1>/<h2>/Markdown heading from content  // CHANGED:
 *            (prevents English title + translated body mismatch).                                       // CHANGED:
 *
 * 2026-01-22 • FIX: ajax_store() now creates/updates a LOCAL WordPress post (draft/publish) from Django result,  // CHANGED:
 *            and returns post_id + edit_link + permalink so the Composer can open the saved draft.              // CHANGED:
 *            No contract/CORS/auth changes.                                                                     // CHANGED:
 *
 * 2026-01-20 • ADD: WP → Django proxy for /generate/ and /store/ with robust error handling. // CHANGED:
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		public static $endpoint = '';

		public static function init() {

			add_action( 'wp_ajax_ppa_preview',        array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_ppa_store',          array( __CLASS__, 'ajax_store' ) );
			add_action( 'wp_ajax_ppa_debug_headers',  array( __CLASS__, 'ajax_debug_headers' ) );
			add_action( 'wp_ajax_ppa_generate',       array( __CLASS__, 'ajax_generate' ) );
			add_action( 'wp_ajax_ppa_account_status', array( __CLASS__, 'ajax_account_status' ) ); // CHANGED:
			add_action( 'wp_ajax_ppa_billing_portal_session', array( __CLASS__, 'ajax_billing_portal_session' ) ); // CHANGED:
		}

		private static function error_payload( $error_code, $http_status, $meta_extra = array() ) {
			$http_status = (int) $http_status;
			$meta_base   = array(
				'error_code'   => (string) $error_code,
				'http_status'  => $http_status,
				'endpoint'     => (string) self::$endpoint,
				'server_time'  => gmdate( 'c' ),
			);

			if ( is_array( $meta_extra ) && ! empty( $meta_extra ) ) {
				$meta_base = array_merge( $meta_base, $meta_extra );
			}

			return array(
				'ok'   => false,
				'meta' => $meta_base,
			);
		}

		private static function must_post() {
			if ( strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
				wp_send_json_error( self::error_payload( 'method_not_allowed', 405 ), 405 );
			}
		}

		private static function verify_nonce_or_forbid() {
			$nonce = '';

			// 1) Legacy: nonce passed in request fields (form posts / jQuery.ajax).
			if ( isset( $_POST['nonce'] ) ) {
				$nonce = (string) wp_unslash( $_POST['nonce'] );
			} elseif ( isset( $_REQUEST['nonce'] ) ) {
				$nonce = (string) wp_unslash( $_REQUEST['nonce'] );
			}

			// 2) Modern: nonce passed via headers (fetch JSON posts + our JS clients).
			// NOTE: PHP normalizes header names into $_SERVER['HTTP_*'].
			if ( '' === $nonce ) {
				if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {
					$nonce = (string) wp_unslash( $_SERVER['HTTP_X_PPA_NONCE'] );
				} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
					// We intentionally accept x-wp-nonce because several PPA JS modules set it.
					$nonce = (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
				}
			}

			$valid = false;
			if ( '' !== $nonce ) {
				// Back-compat: accept both the current action string and the older hyphenated one.
				$valid = ( wp_verify_nonce( $nonce, 'ppa_admin_nonce' ) || wp_verify_nonce( $nonce, 'ppa-admin' ) );
			}

			if ( ! $valid ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'nonce_invalid' ) ), 403 );
			}
		}

		private static function read_json_body() {
			// Read raw request bytes first (works for JSON POSTs).
			$raw = file_get_contents( 'php://input' );

			// ALSO support x-www-form-urlencoded requests that send JSON in a "payload" field.
			// This is important for customers because different JS bundles have used different
			// transport formats over time (and we want "Generate Preview" to just work).
			if ( isset( $_POST['payload'] ) && '' !== (string) $_POST['payload'] ) {
				$raw = (string) wp_unslash( $_POST['payload'] );
			}

			if ( ! is_string( $raw ) || '' === $raw ) {
				return array(
					'raw'  => '',
					'json' => null,
				);
			}

			$json = json_decode( $raw, true );
			if ( ! is_array( $json ) ) {
				$json = null;
			}

			return array(
				'raw'  => $raw,
				'json' => $json,
			);
		}

		private static function normalize_base_candidate( $base ) {
			$base = trim( (string) $base );
			$base = rtrim( $base, '/' );
			return $base;
		}

		private static function is_valid_base_url( $base ) {
			if ( '' === $base ) { return false; }
			if ( ! preg_match( '#^https?://#i', $base ) ) { return false; }
			$parts = wp_parse_url( $base );
			if ( ! is_array( $parts ) ) { return false; }
			if ( empty( $parts['host'] ) ) { return false; }
			return true;
		}

		private static function django_base() {
			$base = '';
			if ( defined( 'PPA_DJANGO_BASE_URL' ) ) {
				$base = (string) PPA_DJANGO_BASE_URL;
			}

			if ( '' === $base ) {
				$base = (string) get_option( 'ppa_django_base_url', '' );
			}

			$base = self::normalize_base_candidate( $base );

			if ( ! self::is_valid_base_url( $base ) ) {
				// Hard fallback to production (locked), but leave it changeable via constant/option.
				$base = 'https://apps.techwithwayne.com/postpress-ai';
			}

			// Ensure /postpress-ai path exists (Django root mounted here).
			if ( false === stripos( $base, '/postpress-ai' ) ) {
				$base = rtrim( $base, '/' ) . '/postpress-ai';
			}

			return rtrim( $base, '/' );
		}

		private static function activation_key() {
			// CHANGED: Support legacy option names so customer installs don't silently fail auth.
			// Some older installs stored the key as 'ppa_activation_key'.
			$key = (string) get_option( 'ppa_license_key', '' );
			$key = trim( $key );

			if ( '' === $key ) {
				$key = (string) get_option( 'ppa_activation_key', '' );
				$key = trim( $key );
			}

			// Ultra-legacy fallbacks (harmless if absent).
			if ( '' === $key ) {
				$key = (string) get_option( 'ppa_license', '' );
				$key = trim( $key );
			}

			return $key;
		}

		private static function require_activation_key_or_403() {
			$key = self::activation_key();
			if ( '' === $key ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'missing_license_key' ) ), 403 );
			}
			return $key;
		}

		private static function build_args( $json_body ) {
			$timeout = 25;

			$args = array(
				'timeout'   => $timeout,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => $json_body,
				'data_format' => 'body',
			);

			return $args;
		}

		// --------------------------
		// Auth helpers (WP → Django)
		// --------------------------

		private static function site_url_for_auth() { // CHANGED:
			$site_url = function_exists( 'home_url' ) ? home_url() : ( isset( $_SERVER['HTTP_HOST'] ) ? ( 'https://' . $_SERVER['HTTP_HOST'] ) : '' ); // CHANGED:
			return esc_url_raw( (string) $site_url ); // CHANGED:
		} // CHANGED:

		/**
		 * CHANGED: Ensure Django-bound payloads include license_key + site_url.
		 *
		 * Why this matters:
		 * Some deployments protect /preview/, /generate/, and /store/ behind the same
		 * license auth used by /license/verify/. If the browser payload doesn't include
		 * license_key/site_url, Django will return 401.
		 *
		 * We only inject if we have a stored key AND the payload is valid JSON.
		 *
		 * @param array $payload Return value from read_json_body()
		 * @return string Raw JSON string to send to Django.
		 */
		private static function ensure_payload_has_auth( $payload ) { // CHANGED:
			if ( ! is_array( $payload ) ) { // CHANGED:
				return '';
			}

			$raw  = isset( $payload['raw'] ) ? (string) $payload['raw'] : '';
			$json = ( isset( $payload['json'] ) && is_array( $payload['json'] ) ) ? $payload['json'] : null;

			// If we can't decode JSON, don't try to mutate it.
			if ( ! is_array( $json ) ) { // CHANGED:
				return $raw;
			}

			$key = self::activation_key(); // CHANGED:
			if ( '' === $key ) { // CHANGED:
				// No stored key → nothing to inject.
				return $raw;
			}

			$changed = false; // CHANGED:
			if ( ! isset( $json['license_key'] ) || '' === trim( (string) $json['license_key'] ) ) { // CHANGED:
				$json['license_key'] = (string) $key; // CHANGED:
				$changed = true; // CHANGED:
			}
			if ( ! isset( $json['site_url'] ) || '' === trim( (string) $json['site_url'] ) ) { // CHANGED:
				$json['site_url'] = (string) self::site_url_for_auth(); // CHANGED:
				$changed = true; // CHANGED:
			}

			if ( ! $changed ) { // CHANGED:
				return $raw;
			}

			$encoded = wp_json_encode( $json ); // CHANGED:
			return ( is_string( $encoded ) && '' !== $encoded ) ? $encoded : $raw; // CHANGED:
		} // CHANGED:


		// --------------------------
		// Content sanitization helpers
		// --------------------------

		private static function strip_divider_only_lines( $content ) {                                                    // CHANGED:
			$s = (string) $content;                                                                                        // CHANGED:
			if ( '' === $s ) {                                                                                             // CHANGED:
				return $s;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			$lines = preg_split( "/\\r\\n|\\r|\\n/", $s );                                                                 // CHANGED:
			if ( ! is_array( $lines ) ) {                                                                                  // CHANGED:
				return $s;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			$out = array();                                                                                                // CHANGED:
			foreach ( $lines as $line ) {                                                                                  // CHANGED:
				$trim = trim( (string) $line );                                                                            // CHANGED:
				// Remove lines that are purely divider tokens.                                                            // CHANGED:
				if ( $trim === '---' || $trim === '…' || $trim === '...' ) {                                               // CHANGED:
					continue;                                                                                              // CHANGED:
				}                                                                                                          // CHANGED:
				$out[] = (string) $line;                                                                                   // CHANGED:
			}                                                                                                              // CHANGED:

			return implode( "\n", $out );                                                                                  // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function sanitize_ai_fields_in_json( &$json ) {                                                     // CHANGED:
			if ( ! is_array( $json ) ) {                                                                                   // CHANGED:
				return;                                                                                                    // CHANGED:
			}                                                                                                              // CHANGED:

			// Normalize common keys we might receive from Django.                                                         // CHANGED:
			$paths = array(
				array( 'preview', 'html' ),
				array( 'preview', 'content' ),
				array( 'data', 'preview', 'html' ),
				array( 'data', 'preview', 'content' ),
				array( 'data', 'content' ),
				array( 'content' ),
				array( 'result', 'content' ),
				array( 'result', 'html' ),
			);                                                                                                             // CHANGED:

			foreach ( $paths as $path ) {                                                                                  // CHANGED:
				$ref =& $json;                                                                                             // CHANGED:
				$ok  = true;                                                                                               // CHANGED:
				foreach ( $path as $seg ) {                                                                                // CHANGED:
					if ( ! is_array( $ref ) || ! array_key_exists( $seg, $ref ) ) {                                        // CHANGED:
						$ok = false;                                                                                       // CHANGED:
						break;                                                                                             // CHANGED:
					}                                                                                                      // CHANGED:
					$ref =& $ref[ $seg ];                                                                                  // CHANGED:
				}                                                                                                          // CHANGED:
				if ( $ok && ( is_string( $ref ) || is_numeric( $ref ) ) ) {                                                // CHANGED:
					$ref = self::strip_divider_only_lines( (string) $ref );                                                // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function normalize_post_status( $status ) {                                                        // CHANGED:
			$s = strtolower( trim( (string) $status ) );                                                                   // CHANGED:
			if ( in_array( $s, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {                               // CHANGED:
				return $s;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:
			return 'draft';                                                                                                // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function safe_post_content( $content ) {                                                           // CHANGED:
			$c = (string) $content;                                                                                        // CHANGED:
			$c = self::strip_divider_only_lines( $c );                                                                     // CHANGED:
			return $c;                                                                                                     // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function resolve_int_post_id( $v ) {                                                               // CHANGED:
			if ( is_numeric( $v ) ) {                                                                                      // CHANGED:
				$pid = (int) $v;                                                                                           // CHANGED:
				return ( $pid > 0 ? $pid : 0 );                                                                            // CHANGED:
			}                                                                                                              // CHANGED:
			return 0;                                                                                                      // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function assign_terms_if_present( $post_id, $payload_json ) {                                      // CHANGED:
			if ( ! function_exists( 'wp_set_post_terms' ) ) {                                                              // CHANGED:
				return;                                                                                                    // CHANGED:
			}                                                                                                              // CHANGED:
			if ( ! is_array( $payload_json ) ) {                                                                           // CHANGED:
				return;                                                                                                    // CHANGED:
			}                                                                                                              // CHANGED:

			try {                                                                                                          // CHANGED:
				// Tags: supports array of strings.                                                                        // CHANGED:
				if ( isset( $payload_json['tags'] ) && is_array( $payload_json['tags'] ) ) {                               // CHANGED:
					$tags = array();                                                                                       // CHANGED:
					foreach ( $payload_json['tags'] as $t ) {                                                              // CHANGED:
						$t = sanitize_text_field( (string) $t );                                                           // CHANGED:
						if ( '' !== $t ) { $tags[] = $t; }                                                                 // CHANGED:
					}                                                                                                      // CHANGED:
					if ( ! empty( $tags ) ) {                                                                              // CHANGED:
						wp_set_post_terms( $post_id, $tags, 'post_tag', false );                                             // CHANGED:
					}                                                                                                       // CHANGED:
				}                                                                                                           // CHANGED:
				// Categories: supports array of ints/strings.                                                               // CHANGED:
				if ( isset( $payload_json['categories'] ) && is_array( $payload_json['categories'] ) ) {                    // CHANGED:
					$cats = array();                                                                                        // CHANGED:
					foreach ( $payload_json['categories'] as $c ) {                                                         // CHANGED:
						$cid = (int) $c;                                                                                    // CHANGED:
						if ( $cid > 0 ) { $cats[] = $cid; }                                                                 // CHANGED:
					}                                                                                                       // CHANGED:
					if ( ! empty( $cats ) ) {                                                                               // CHANGED:
						wp_set_post_categories( $post_id, $cats, false );                                                    // CHANGED:
					}                                                                                                       // CHANGED:
				}                                                                                                           // CHANGED:
			} catch ( Exception $e ) {                                                                                      // CHANGED:
				// Silent by design (must not block saving).                                                                 // CHANGED:
			}                                                                                                               // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Safe nested getter: supports dot paths like "translation.title".
		 *
		 * @param mixed  $arr
		 * @param string $path
		 * @return mixed|null
		 */
		private static function dig( $arr, $path ) {                                                                       // CHANGED:
			if ( ! is_array( $arr ) ) {                                                                                    // CHANGED:
				return null;                                                                                               // CHANGED:
			}                                                                                                              // CHANGED:
			$path = is_string( $path ) ? trim( $path ) : '';                                                               // CHANGED:
			if ( '' === $path ) {                                                                                          // CHANGED:
				return null;                                                                                               // CHANGED:
			}                                                                                                              // CHANGED:
			$cur  = $arr;                                                                                                  // CHANGED:
			$segs = explode( '.', $path );                                                                                 // CHANGED:
			foreach ( $segs as $seg ) {                                                                                    // CHANGED:
				$seg = (string) $seg;                                                                                      // CHANGED:
				if ( ! is_array( $cur ) || ! array_key_exists( $seg, $cur ) ) {                                            // CHANGED:
					return null;                                                                                           // CHANGED:
				}                                                                                                          // CHANGED:
				$cur = $cur[ $seg ];                                                                                       // CHANGED:
			}                                                                                                              // CHANGED:
			return $cur;                                                                                                   // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Pick first non-empty string-ish value.
		 *
		 * @param array $candidates
		 * @return string
		 */
		private static function first_nonempty_string( $candidates ) {                                                     // CHANGED:
			if ( ! is_array( $candidates ) ) {                                                                             // CHANGED:
				return '';                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:
			foreach ( $candidates as $v ) {                                                                                // CHANGED:
				if ( is_string( $v ) ) {                                                                                   // CHANGED:
					$s = trim( $v );                                                                                       // CHANGED:
					if ( '' !== $s ) { return $s; }                                                                        // CHANGED:
				} elseif ( is_numeric( $v ) ) {                                                                            // CHANGED:
					$s = trim( (string) $v );                                                                              // CHANGED:
					if ( '' !== $s ) { return $s; }                                                                        // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:
			return '';                                                                                                     // CHANGED:
		}                                                                                                                  // CHANGED:

		// -------------------------------
		// Stripe Billing Portal URL helpers
		// -------------------------------

		/**
		 * Validate that a URL is a ONE-TIME Stripe Billing Portal SESSION URL.
		 * - Session URLs look like: https://billing.stripe.com/p/session/...
		 * - Email-login URLs (/p/login/...) are forbidden by product rules.
		 *
		 * @param string $url
		 * @return bool
		 */
		private static function is_stripe_billing_portal_session_url( $url ) {                                           // CHANGED:
			$u = trim( (string) $url );                                                                                    // CHANGED:
			if ( '' === $u ) { return false; }                                                                             // CHANGED:
			if ( false !== stripos( $u, 'billing.stripe.com/p/login' ) ) { return false; }                                 // CHANGED:
			if ( false === stripos( $u, 'billing.stripe.com/p/session' ) ) { return false; }                               // CHANGED:
			if ( ! preg_match( '#^https?://#i', $u ) ) { return false; }                                                   // CHANGED:
			return true;                                                                                                   // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Detect a Stripe Billing Portal EMAIL-LOGIN URL (/p/login/...) so we can refuse it.
		 *
		 * @param string $url
		 * @return bool
		 */
		private static function is_stripe_billing_portal_login_url( $url ) {                                              // CHANGED:
			$u = trim( (string) $url );                                                                                    // CHANGED:
			if ( '' === $u ) { return false; }                                                                             // CHANGED:
			return ( false !== stripos( $u, 'billing.stripe.com/p/login' ) );                                               // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Recursively scan an array/object-ish structure to find the first Stripe Billing Portal SESSION URL.
		 *
		 * @param mixed $node
		 * @return string
		 */
		private static function find_first_stripe_billing_session_url( $node ) {                                          // CHANGED:
			if ( is_string( $node ) || is_numeric( $node ) ) {                                                             // CHANGED:
				$s = trim( (string) $node );                                                                               // CHANGED:
				if ( self::is_stripe_billing_portal_session_url( $s ) ) {                                                  // CHANGED:
					return $s;                                                                                             // CHANGED:
				}                                                                                                          // CHANGED:
				// Sometimes it's embedded inside a longer message string.                                                 // CHANGED:
				if ( preg_match( "#https?://billing\\.stripe\\.com/p/session/[^\\s\\\"']+#i", $s, $m ) ) {                 // CHANGED:
					$cand = trim( (string) $m[0] );                                                                        // CHANGED:
					if ( self::is_stripe_billing_portal_session_url( $cand ) ) { return $cand; }                           // CHANGED:
				}                                                                                                          // CHANGED:
				return '';                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:
			if ( is_array( $node ) ) {                                                                                     // CHANGED:
				foreach ( $node as $v ) {                                                                                  // CHANGED:
					$found = self::find_first_stripe_billing_session_url( $v );                                            // CHANGED:
					if ( '' !== $found ) { return $found; }                                                                // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:
			return '';                                                                                                     // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Inject the Billing Portal session URL into the common response envelopes so admin-account.js can consume it.
		 * Supports:
		 *  - { ok:true, data:{ license:{ links:{...} } } }
		 *  - { license:{ links:{...} } }
		 *
		 * @param array  $json
		 * @param string $url
		 * @return array
		 */
		private static function inject_billing_portal_session_link( $json, $url ) {                                       // CHANGED:
			if ( ! is_array( $json ) ) { return $json; }                                                                   // CHANGED:
			$u = trim( (string) $url );                                                                                    // CHANGED:
			if ( '' === $u ) { return $json; }                                                                             // CHANGED:

			// Primary: ok/data/license/links.                                                                             // CHANGED:
			if ( isset( $json['data'] ) && is_array( $json['data'] ) && isset( $json['data']['license'] ) && is_array( $json['data']['license'] ) ) { // CHANGED:
				if ( ! isset( $json['data']['license']['links'] ) || ! is_array( $json['data']['license']['links'] ) ) {  // CHANGED:
					$json['data']['license']['links'] = array();                                                           // CHANGED:
				}                                                                                                          // CHANGED:
				$json['data']['license']['links']['billing_portal'] = $u;                                                  // CHANGED:
				return $json;                                                                                              // CHANGED:
			}                                                                                                              // CHANGED:

			// Alternate: license/links at root.                                                                           // CHANGED:
			if ( isset( $json['license'] ) && is_array( $json['license'] ) ) {                                             // CHANGED:
				if ( ! isset( $json['license']['links'] ) || ! is_array( $json['license']['links'] ) ) {                  // CHANGED:
					$json['license']['links'] = array();                                                                   // CHANGED:
				}                                                                                                          // CHANGED:
				$json['license']['links']['billing_portal'] = $u;                                                          // CHANGED:
			}                                                                                                              // CHANGED:
			return $json;                                                                                                   // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Detects any non-ASCII character (covers Japanese and basically any translated language).
		 *
		 * @param string $s
		 * @return bool
		 */
		private static function has_non_ascii( $s ) {                                                                      // CHANGED:
			$s = (string) $s;                                                                                              // CHANGED:
			return ( 1 === preg_match( '/[^\x00-\x7F]/', $s ) );                                                           // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Best-effort: extract a heading-like title from content.
		 * Supports HTML <h1>/<h2> and Markdown "# Title".
		 *
		 * @param string $content
		 * @return string
		 */
		private static function extract_heading_title_from_content( $content ) {                                           // CHANGED:
			$c = (string) $content;                                                                                        // CHANGED:
			if ( '' === trim( $c ) ) {                                                                                    // CHANGED:
				return '';                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			// HTML h1/h2.                                                                                                 // CHANGED:
			$m = array();                                                                                                  // CHANGED:
			if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $c, $m ) && ! empty( $m[1] ) ) {                                  // CHANGED:
				$t = trim( wp_strip_all_tags( (string) $m[1] ) );                                                          // CHANGED:
				return $t;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:
			if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $c, $m ) && ! empty( $m[1] ) ) {                                  // CHANGED:
				$t = trim( wp_strip_all_tags( (string) $m[1] ) );                                                          // CHANGED:
				return $t;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			// Markdown: first line starting with # or ##.                                                                  // CHANGED:
			$m = array();                                                                                                  // CHANGED:
			if ( preg_match( '/^\s*#{1,2}\s+(.+)$/m', $c, $m ) && ! empty( $m[1] ) ) {                                     // CHANGED:
				$t = trim( (string) $m[1] );                                                                               // CHANGED:
				$t = preg_replace( '/\s+#+\s*$/', '', $t );                                                                // CHANGED:
				$t = trim( wp_strip_all_tags( (string) $t ) );                                                             // CHANGED:
				return $t;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			return '';                                                                                                     // CHANGED:
		}                                                                                                                  // CHANGED:

		/**
		 * Upsert a WP post from a Django store response.
		 *
		 * @param array $payload_json
		 * @return array
		 */
		private static function upsert_wp_post_from_django( $payload_json ) {                                              // CHANGED:
			if ( ! is_array( $payload_json ) ) {                                                                           // CHANGED:
				return array(                                                                                              // CHANGED:
					'ok'    => false,                                                                                      // CHANGED:
					'reason'=> 'payload_not_array',                                                                        // CHANGED:
				);                                                                                                         // CHANGED:
			}                                                                                                              // CHANGED:

			$post_id = 0;                                                                                                  // CHANGED:
			if ( isset( $payload_json['post_id'] ) ) {                                                                     // CHANGED:
				$post_id = self::resolve_int_post_id( $payload_json['post_id'] );                                          // CHANGED:
			}                                                                                                              // CHANGED:

			$title_candidates = array(                                                                                    // CHANGED:
				self::dig( $payload_json, 'translation.title' ),                                                           // CHANGED:
				self::dig( $payload_json, 'translation.post_title' ),                                                      // CHANGED:
				self::dig( $payload_json, 'post_title' ),                                                                  // CHANGED:
				self::dig( $payload_json, 'title' ),                                                                       // CHANGED:
			);                                                                                                             // CHANGED:
			$title = self::first_nonempty_string( $title_candidates );                                                     // CHANGED:

			$content_candidates = array(                                                                                   // CHANGED:
				self::dig( $payload_json, 'translation.content' ),                                                         // CHANGED:
				self::dig( $payload_json, 'translation.post_content' ),                                                    // CHANGED:
				self::dig( $payload_json, 'post_content' ),                                                                // CHANGED:
				self::dig( $payload_json, 'content' ),                                                                     // CHANGED:
				self::dig( $payload_json, 'html' ),                                                                        // CHANGED:
			);                                                                                                             // CHANGED:
			$content = self::first_nonempty_string( $content_candidates );                                                 // CHANGED:
			$content = self::safe_post_content( $content );                                                                // CHANGED:

			$status = 'draft';                                                                                             // CHANGED:
			if ( isset( $payload_json['post_status'] ) ) {                                                                 // CHANGED:
				$status = self::normalize_post_status( $payload_json['post_status'] );                                     // CHANGED:
			} elseif ( isset( $payload_json['status'] ) ) {                                                                // CHANGED:
				$status = self::normalize_post_status( $payload_json['status'] );                                          // CHANGED:
			}                                                                                                              // CHANGED:

			// If translation exists and title looks still English/ASCII while content is non-ASCII,
			// try extracting a heading from translated content to prevent mismatch.                                       // CHANGED:
			if ( '' === $title && '' !== $content ) {                                                                      // CHANGED:
				$title = self::extract_heading_title_from_content( $content );                                             // CHANGED:
			} else if ( '' !== $title && '' !== $content ) {                                                               // CHANGED:
				// If title is ASCII-only but content is non-ASCII, prefer heading extracted from content.                 // CHANGED:
				if ( ! self::has_non_ascii( $title ) && self::has_non_ascii( $content ) ) {                                // CHANGED:
					$alt = self::extract_heading_title_from_content( $content );                                           // CHANGED:
					if ( '' !== $alt ) {                                                                                   // CHANGED:
						$title = $alt;                                                                                     // CHANGED:
					}                                                                                                      // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:

			$postarr = array(                                                                                              // CHANGED:
				'post_title'   => $title,                                                                                  // CHANGED:
				'post_content' => $content,                                                                                // CHANGED:
				'post_status'  => $status,                                                                                 // CHANGED:
				'post_type'    => 'post',                                                                                  // CHANGED:
			);                                                                                                             // CHANGED:

			if ( $post_id > 0 ) {                                                                                          // CHANGED:
				$postarr['ID'] = $post_id;                                                                                 // CHANGED:
				$new_id = wp_update_post( $postarr, true );                                                                // CHANGED:
			} else {                                                                                                       // CHANGED:
				$new_id = wp_insert_post( $postarr, true );                                                                // CHANGED:
			}                                                                                                              // CHANGED:

			if ( is_wp_error( $new_id ) ) {                                                                                // CHANGED:
				return array(                                                                                              // CHANGED:
					'ok'     => false,                                                                                     // CHANGED:
					'reason' => 'wp_post_error',                                                                           // CHANGED:
					'detail' => $new_id->get_error_message(),                                                              // CHANGED:
				);                                                                                                         // CHANGED:
			}                                                                                                              // CHANGED:

			$post_id = (int) $new_id;                                                                                      // CHANGED:

			self::assign_terms_if_present( $post_id, $payload_json );                                                      // CHANGED:

			$edit_link = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $post_id, 'raw' ) : '';             // CHANGED:
			$permalink = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';                              // CHANGED:

			return array(                                                                                                  // CHANGED:
				'ok'        => true,                                                                                       // CHANGED:
				'post_id'    => $post_id,                                                                                  // CHANGED:
				'edit_link'  => (string) $edit_link,                                                                       // CHANGED:
				'permalink'  => (string) $permalink,                                                                       // CHANGED:
			);                                                                                                             // CHANGED:
		}                                                                                                                  // CHANGED:

		public static function ajax_preview() {
			self::$endpoint = 'preview';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/preview/';

			$response = wp_remote_post( $django_url, self::build_args( self::ensure_payload_has_auth( $payload ) ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				if ( $code >= 400 ) {
					wp_send_json_error( array( 'raw' => $resp_body ), $code );
				}
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// Strip divider-only lines before the UI renders it.                                                           // CHANGED:
			self::sanitize_ai_fields_in_json( $json );                                                                      // CHANGED:

			if ( $code >= 400 ) {
				wp_send_json_error( $json, $code );
			}

			wp_send_json_success( $json, $code );
		}

		public static function ajax_store() { // CHANGED:
			self::$endpoint = 'store'; // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) { // CHANGED:
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 ); // CHANGED:
			}

			self::must_post();              // CHANGED:
			self::verify_nonce_or_forbid(); // CHANGED:

			$payload = self::read_json_body(); // CHANGED:
			$base    = self::django_base(); // CHANGED:

			$django_url = $base . '/store/'; // CHANGED:

			$response = wp_remote_post( $django_url, self::build_args( self::ensure_payload_has_auth( $payload ) ) ); // CHANGED:

			if ( is_wp_error( $response ) ) { // CHANGED:
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 ); // CHANGED:
			}

			$code      = (int) wp_remote_retrieve_response_code( $response ); // CHANGED:
			$resp_body = (string) wp_remote_retrieve_body( $response ); // CHANGED:

			$json = json_decode( $resp_body, true ); // CHANGED:
			if ( json_last_error() !== JSON_ERROR_NONE ) { // CHANGED:
				if ( $code >= 400 ) { // CHANGED:
					wp_send_json_error( array( 'raw' => $resp_body ), $code ); // CHANGED:
				}
				wp_send_json_success( array( 'raw' => $resp_body ), $code ); // CHANGED:
			}

			// Strip divider-only lines before the UI renders it.                                                           // CHANGED:
			self::sanitize_ai_fields_in_json( $json );                                                                      // CHANGED:

			if ( $code >= 400 ) { // CHANGED:
				wp_send_json_error( $json, $code ); // CHANGED:
			}

			// CHANGED: Create/update a local WP post from Django store output.
			// We support multiple possible envelope shapes, but prefer the standard: { ok:true, data:{ ... post_title, post_content ... } }
			$payload_json = null; // CHANGED:
			if ( isset( $json['data'] ) && is_array( $json['data'] ) ) { // CHANGED:
				$payload_json = $json['data']; // CHANGED:
			} elseif ( isset( $json['result'] ) && is_array( $json['result'] ) ) { // CHANGED:
				$payload_json = $json['result']; // CHANGED:
			} elseif ( is_array( $json ) ) { // CHANGED:
				$payload_json = $json; // CHANGED:
			} // CHANGED:

			$up = self::upsert_wp_post_from_django( is_array( $payload_json ) ? $payload_json : array() ); // CHANGED:
			if ( isset( $up['ok'] ) && true === $up['ok'] ) { // CHANGED:
				// Attach post info to the response so the Composer can open it.                                           // CHANGED:
				if ( ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) { // CHANGED:
					$json['data'] = array(); // CHANGED:
				}
				$json['data']['wp_post'] = array( // CHANGED:
					'post_id'   => (int) $up['post_id'], // CHANGED:
					'edit_link' => (string) $up['edit_link'], // CHANGED:
					'permalink' => (string) $up['permalink'], // CHANGED:
				); // CHANGED:

				// CHANGED: Back-compat keys (some admin JS expects these at data root).
				$json['data']['post_id']   = (int) $up['post_id']; // CHANGED:
				$json['data']['edit_link'] = (string) $up['edit_link']; // CHANGED:
				$json['data']['permalink'] = (string) $up['permalink']; // CHANGED:
				// CHANGED: Also mirror these fields at the ROOT of the payload because wp_send_json_success wraps everything under { data: ... } and some older JS expects a single data layer (response.data.edit_link).
				$json['post_id']   = (int) $up['post_id']; // CHANGED:
				$json['edit_link'] = (string) $up['edit_link']; // CHANGED:
				$json['edit_url']  = (string) $up['edit_link']; // CHANGED:
				$json['permalink'] = (string) $up['permalink']; // CHANGED:
				if ( ! isset( $json['wp_post'] ) || ! is_array( $json['wp_post'] ) ) { // CHANGED:
					$json['wp_post'] = array(); // CHANGED:
				} // CHANGED:
				$json['wp_post']['post_id']   = (int) $up['post_id']; // CHANGED:
				$json['wp_post']['edit_link'] = (string) $up['edit_link']; // CHANGED:
				$json['wp_post']['edit_url']  = (string) $up['edit_link']; // CHANGED:
				$json['wp_post']['permalink'] = (string) $up['permalink']; // CHANGED:
			} // CHANGED:

			wp_send_json_success( $json, $code ); // CHANGED:
		}

		public static function ajax_generate() {
			self::$endpoint = 'generate';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/generate/';
			$response   = wp_remote_post( $django_url, self::build_args( self::ensure_payload_has_auth( $payload ) ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				if ( $code >= 400 ) {
					wp_send_json_error( array( 'raw' => $resp_body ), $code );                                            // CHANGED:
				}
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// Strip divider-only lines before the UI renders it.                                                           // CHANGED:
			self::sanitize_ai_fields_in_json( $json );                                                                      // CHANGED:

			if ( $code >= 400 ) {                                                                                         // CHANGED:
				wp_send_json_error( $json, $code );                                                                      // CHANGED:
			}

			wp_send_json_success( $json, $code );
		}

		public static function ajax_account_status() { // CHANGED:
			self::$endpoint = 'account_status'; // CHANGED:

			// Same capability gate as the Composer: editors/authors can see plan/usage.                                   // CHANGED:
			if ( ! current_user_can( 'edit_posts' ) ) { // CHANGED:
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 ); // CHANGED:
			}

			self::must_post();              // CHANGED:
			self::verify_nonce_or_forbid(); // CHANGED:

			// LOCKED: do NOT accept arbitrary license keys from the browser. Use stored activation key only.              // CHANGED:
			$license_key = self::require_activation_key_or_403(); // CHANGED:

			// Site URL for Option A license auth (Django normalizes; we send home_url).                                    // CHANGED:
			$site_url = function_exists( 'home_url' ) ? home_url() : ( isset( $_SERVER['HTTP_HOST'] ) ? ( 'https://' . $_SERVER['HTTP_HOST'] ) : '' ); // CHANGED:
			$site_url = esc_url_raw( (string) $site_url ); // CHANGED:

			$base = self::django_base(); // CHANGED:

			// Django license verify endpoint (under /postpress-ai).                                                        // CHANGED:
			$django_url = $base . '/license/verify/'; // CHANGED:

			// CHANGED: Force cache-bust for Account verify calls (Django response advertises cache_ttl_seconds=300).
			// We do it at the URL level (changes cache key in many setups) + explicit no-store headers.
			$cache_bust = (string) time(); // CHANGED:
			if ( function_exists( 'add_query_arg' ) ) { // CHANGED:
				$django_url = add_query_arg( array( '_ts' => $cache_bust ), $django_url ); // CHANGED:
			} else { // CHANGED:
				$django_url .= ( false === strpos( $django_url, '?' ) ? '?' : '&' ) . '_ts=' . rawurlencode( $cache_bust ); // CHANGED:
			} // CHANGED:

			// Always send license_key + site_url in the BODY for Option A (no shared key required).                        // CHANGED:
			$body_arr = array( // CHANGED:
				'license_key' => (string) $license_key, // CHANGED:
				'site_url'    => (string) $site_url,    // CHANGED:
			); // CHANGED:

			// CHANGED: Optional intent passthrough (whitelisted) so JS can request special link behaviors
			// (ex: one-time Stripe Billing Portal *session* URL) without changing the WP↔Django contract.
			$ppa_allowed = array( // CHANGED:
				'intent', // CHANGED:
				'link_intent', // CHANGED:
				'ppa_link_intent', // CHANGED:
				'billing_portal', // CHANGED:
				'create_portal_session', // CHANGED:
				'portal_session', // CHANGED:
				'include_links', // CHANGED:
				'force_links', // CHANGED:
			); // CHANGED:
			foreach ( $ppa_allowed as $ppa_k ) { // CHANGED:
				if ( isset( $_POST[ $ppa_k ] ) ) { // CHANGED:
					$ppa_v = trim( (string) wp_unslash( $_POST[ $ppa_k ] ) ); // CHANGED:
					if ( '' !== $ppa_v ) { // CHANGED:
						$body_arr[ $ppa_k ] = sanitize_text_field( $ppa_v ); // CHANGED:
					} // CHANGED:
				} // CHANGED:
			} // CHANGED:

			// CHANGED: Determine if this request is asking for a one-time Billing Portal session URL.
			$ppa_intent = ''; // CHANGED:
			if ( isset( $body_arr['intent'] ) ) { $ppa_intent = (string) $body_arr['intent']; } // CHANGED:
			if ( '' === $ppa_intent && isset( $body_arr['link_intent'] ) ) { $ppa_intent = (string) $body_arr['link_intent']; } // CHANGED:
			if ( '' === $ppa_intent && isset( $body_arr['ppa_link_intent'] ) ) { $ppa_intent = (string) $body_arr['ppa_link_intent']; } // CHANGED:
			$ppa_wants_billing_portal = false; // CHANGED:
			if ( 'billing_portal' === $ppa_intent ) { $ppa_wants_billing_portal = true; } // CHANGED:
			if ( isset( $body_arr['billing_portal'] ) || isset( $body_arr['create_portal_session'] ) || isset( $body_arr['portal_session'] ) ) { // CHANGED:
				$ppa_wants_billing_portal = true; // CHANGED:
			} // CHANGED:

			// CHANGED: Billing Portal SESSION links are minted ONLY when the backend sees an explicit intent.
			// Some deployments read intent flags from the QUERYSTRING (not just JSON body), so we redundantly
			// send the billing portal intent in BOTH places (body + query + headers). This is safe and only
			// applies when billing_portal intent is detected.                                                           // CHANGED:
			if ( $ppa_wants_billing_portal ) {                                                                            // CHANGED:
				// Ensure link flags are present even if JS only sent intent markers.                                      // CHANGED:
				if ( ! isset( $body_arr['include_links'] ) ) { $body_arr['include_links'] = '1'; }                        // CHANGED:
				if ( ! isset( $body_arr['force_links'] ) )   { $body_arr['force_links']   = '1'; }                        // CHANGED:
				if ( ! isset( $body_arr['billing_portal'] ) )        { $body_arr['billing_portal']        = '1'; }        // CHANGED:
				if ( ! isset( $body_arr['create_portal_session'] ) ) { $body_arr['create_portal_session'] = '1'; }        // CHANGED:
				if ( ! isset( $body_arr['portal_session'] ) )        { $body_arr['portal_session']        = '1'; }        // CHANGED:

				// Add intent flags to querystring (some servers ignore JSON body for link creation).                      // CHANGED:
				$ppa_qs = array(                                                                                          // CHANGED:
					'intent'                => 'billing_portal',                                                           // CHANGED:
					'link_intent'           => 'billing_portal',                                                           // CHANGED:
					'ppa_link_intent'       => 'billing_portal',                                                           // CHANGED:
					'include_links'         => '1',                                                                        // CHANGED:
					'force_links'           => '1',                                                                        // CHANGED:
					'billing_portal'        => '1',                                                                        // CHANGED:
					'create_portal_session' => '1',                                                                        // CHANGED:
					'portal_session'        => '1',                                                                        // CHANGED:
				);                                                                                                        // CHANGED:
				if ( function_exists( 'add_query_arg' ) ) {                                                               // CHANGED:
					$django_url = add_query_arg( $ppa_qs, $django_url );                                                   // CHANGED:
				} else {                                                                                                  // CHANGED:
					// Very old fallback.                                                                                  // CHANGED:
					$sep = ( false === strpos( $django_url, '?' ) ) ? '?' : '&';                                           // CHANGED:
					foreach ( $ppa_qs as $k => $v ) {                                                                     // CHANGED:
						$django_url .= $sep . rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );            // CHANGED:
						$sep = '&';                                                                                       // CHANGED:
					}                                                                                                     // CHANGED:
				}                                                                                                         // CHANGED:
			}                                                                                                              // CHANGED:

			// CHANGED: Short WP-side cache to avoid Django rate-limits ('Too many requests') during UI refresh/popup flows.
			// IMPORTANT: Bypass cache for billing_portal intent because the session URL is one-time.
			$ppa_cache_key = 'ppa_account_status_' . md5( (string) $license_key . '|' . (string) $site_url ); // CHANGED:
			if ( ! $ppa_wants_billing_portal ) { // CHANGED:
				$ppa_cached = get_transient( $ppa_cache_key ); // CHANGED:
				if ( is_array( $ppa_cached ) && isset( $ppa_cached['code'], $ppa_cached['json'] ) ) { // CHANGED:
					wp_send_json_success( $ppa_cached['json'], (int) $ppa_cached['code'] ); // CHANGED:
				} // CHANGED:
			} // CHANGED:

			$raw = wp_json_encode( $body_arr ); // CHANGED:
			if ( ! is_string( $raw ) || '' === $raw ) { // CHANGED:
				wp_send_json_error( self::error_payload( 'invalid_payload', 500, array( 'reason' => 'json_encode_failed' ) ), 500 ); // CHANGED:
			}

			// CHANGED: Build args once so we can safely append cache-bypass headers for Account only.
			$args = self::build_args( $raw ); // CHANGED:
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) { // CHANGED:
				$args['headers'] = array(); // CHANGED:
			} // CHANGED:

			// CHANGED: Explicit cache-bypass request headers (safe; does not reveal secrets).
			$args['headers']['Cache-Control']     = 'no-cache, no-store, max-age=0'; // CHANGED:
			$args['headers']['Pragma']            = 'no-cache'; // CHANGED:
			$args['headers']['Expires']           = '0'; // CHANGED:
			$args['headers']['X-PPA-Bypass-Cache']= '1'; // CHANGED:
			$args['headers']['X-PPA-Cache-Bust']  = $cache_bust; // CHANGED:

			// CHANGED: Extra explicit intent headers for billing portal session creation.
			// These are safe (no secrets) and help the Django side decide to mint a session URL.                           // CHANGED:
			if ( $ppa_wants_billing_portal ) {                                                                             // CHANGED:
				$args['headers']['X-PPA-Intent']         = 'billing_portal';                                               // CHANGED:
				$args['headers']['X-PPA-Link-Intent']    = 'billing_portal';                                               // CHANGED:
				$args['headers']['X-PPA-Portal-Session'] = '1';                                                           // CHANGED:
			}                                                                                                              // CHANGED:

			$response = wp_remote_post( $django_url, $args ); // CHANGED:

			if ( is_wp_error( $response ) ) { // CHANGED:
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 ); // CHANGED:
			}

			$code      = (int) wp_remote_retrieve_response_code( $response ); // CHANGED:
			$resp_body = (string) wp_remote_retrieve_body( $response ); // CHANGED:

			$json = json_decode( $resp_body, true ); // CHANGED:
			if ( json_last_error() !== JSON_ERROR_NONE ) { // CHANGED:
				// If Django returns HTML/text, pass raw back (and preserve >=400 semantics).                               // CHANGED:
				if ( $code >= 400 ) { // CHANGED:
					wp_send_json_error( array( 'raw' => $resp_body ), $code ); // CHANGED:
				}
				wp_send_json_success( array( 'raw' => $resp_body ), $code ); // CHANGED:
			}

			// No AI content fields expected here; do NOT sanitize. Keep contract as-is.                                     // CHANGED:

			// CHANGED: If this was a Billing Portal intent request, ensure the response includes a Stripe SESSION URL.
			// Why: Some backends may return the session URL in a different key; the UI expects it at license.links.billing_portal.
			// Rules:
			// - Must be https://billing.stripe.com/p/session/...
			// - Must NOT be /p/login/... (forbidden)
			// - Must NOT fall back to /sales for billing portal
			if ( $ppa_wants_billing_portal && is_array( $json ) ) {                                                         // CHANGED:
				$existing = '';                                                                                            // CHANGED:
				if ( isset( $json['data']['license']['links']['billing_portal'] ) ) {                                      // CHANGED:
					$existing = (string) $json['data']['license']['links']['billing_portal'];                              // CHANGED:
				} elseif ( isset( $json['license']['links']['billing_portal'] ) ) {                                        // CHANGED:
					$existing = (string) $json['license']['links']['billing_portal'];                                      // CHANGED:
				}                                                                                                          // CHANGED:
				$existing = trim( $existing );                                                                              // CHANGED:

				// Refuse forbidden login URLs.                                                                             // CHANGED:
				if ( self::is_stripe_billing_portal_login_url( $existing ) ) {                                              // CHANGED:
					$existing = '';                                                                                        // CHANGED:
				}                                                                                                          // CHANGED:

				// Refuse /sales fallbacks for billing portal.                                                              // CHANGED:
				if ( '' !== $existing && ( false !== stripos( $existing, '/sales' ) || false !== stripos( $existing, 'postpressai.com/sales' ) ) ) { // CHANGED:
					$existing = '';                                                                                        // CHANGED:
				}                                                                                                          // CHANGED:

				if ( self::is_stripe_billing_portal_session_url( $existing ) ) {                                            // CHANGED:
					$json = self::inject_billing_portal_session_link( $json, $existing );                                  // CHANGED:
				} else {                                                                                                   // CHANGED:
					$found = self::find_first_stripe_billing_session_url( $json );                                         // CHANGED:
					if ( '' !== $found ) {                                                                                 // CHANGED:
						$json = self::inject_billing_portal_session_link( $json, $found );                                 // CHANGED:
					}                                                                                                      // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:

			// CHANGED: Cache the decoded JSON briefly to protect Django from rapid refresh bursts.
			// IMPORTANT: Never cache portal *session* URLs (one-time links).
			if ( ! $ppa_wants_billing_portal ) { // CHANGED:
				$ppa_json_str = wp_json_encode( $json ); // CHANGED:
				$ppa_has_session = ( is_string( $ppa_json_str ) && false !== stripos( $ppa_json_str, 'billing.stripe.com/p/session' ) ); // CHANGED:
				if ( ! $ppa_has_session ) { // CHANGED:
					set_transient( $ppa_cache_key, array( 'code' => $code, 'json' => $json ), 4 ); // CHANGED:
				} // CHANGED:
			} // CHANGED:

			if ( $code >= 400 ) { // CHANGED:
				wp_send_json_error( $json, $code ); // CHANGED:
			}

			wp_send_json_success( $json, $code ); // CHANGED:
		} // CHANGED:

		public static function ajax_billing_portal_session() { // CHANGED:
			self::$endpoint = 'billing_portal_session'; // CHANGED:

			// Same capability gate as the Account screen.                                                               // CHANGED:
			if ( ! current_user_can( 'edit_posts' ) ) {                                                                  // CHANGED:
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 ); // CHANGED:
			}                                                                                                            // CHANGED:

			self::must_post();              // CHANGED:
			self::verify_nonce_or_forbid(); // CHANGED:

			// LOCKED: Use stored activation key only.                                                                    // CHANGED:
			$license_key = self::require_activation_key_or_403();                                                         // CHANGED:

			$site_url = function_exists( 'home_url' ) ? home_url() : ( isset( $_SERVER['HTTP_HOST'] ) ? ( 'https://' . $_SERVER['HTTP_HOST'] ) : '' ); // CHANGED:
			$site_url = esc_url_raw( (string) $site_url );                                                                // CHANGED:

			$base      = self::django_base();                                                                             // CHANGED:
			$django_url = $base . '/license/verify/';                                                                     // CHANGED:

			// Cache-bust and explicit billing intent via querystring.                                                    // CHANGED:
			$cache_bust = (string) time();                                                                                // CHANGED:
			$qs = array(                                                                                                  // CHANGED:
				'_ts'                   => $cache_bust,                                                                   // CHANGED:
				'intent'                => 'billing_portal',                                                              // CHANGED:
				'link_intent'           => 'billing_portal',                                                              // CHANGED:
				'ppa_link_intent'       => 'billing_portal',                                                              // CHANGED:
				'include_links'         => '1',                                                                           // CHANGED:
				'force_links'           => '1',                                                                           // CHANGED:
				'billing_portal'        => '1',                                                                           // CHANGED:
				'create_portal_session' => '1',                                                                           // CHANGED:
				'portal_session'        => '1',                                                                           // CHANGED:
			);                                                                                                            // CHANGED:
			if ( function_exists( 'add_query_arg' ) ) {                                                                   // CHANGED:
				$django_url = add_query_arg( $qs, $django_url );                                                          // CHANGED:
			} else {                                                                                                      // CHANGED:
				$sep = ( false === strpos( $django_url, '?' ) ) ? '?' : '&';                                              // CHANGED:
				foreach ( $qs as $k => $v ) {                                                                            // CHANGED:
					$django_url .= $sep . rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );               // CHANGED:
					$sep = '&';                                                                                          // CHANGED:
				}                                                                                                         // CHANGED:
			}                                                                                                             // CHANGED:

			// Body includes the same flags (some servers read POST JSON instead).                                        // CHANGED:
			$body_arr = array(                                                                                            // CHANGED:
				'license_key'           => (string) $license_key,                                                         // CHANGED:
				'site_url'              => (string) $site_url,                                                            // CHANGED:
				'intent'                => 'billing_portal',                                                              // CHANGED:
				'link_intent'           => 'billing_portal',                                                              // CHANGED:
				'ppa_link_intent'       => 'billing_portal',                                                              // CHANGED:
				'include_links'         => '1',                                                                           // CHANGED:
				'force_links'           => '1',                                                                           // CHANGED:
				'billing_portal'        => '1',                                                                           // CHANGED:
				'create_portal_session' => '1',                                                                           // CHANGED:
				'portal_session'        => '1',                                                                           // CHANGED:
			);                                                                                                            // CHANGED:

			$raw = wp_json_encode( $body_arr );                                                                           // CHANGED:
			if ( ! is_string( $raw ) || '' === $raw ) {                                                                   // CHANGED:
				wp_send_json_error( self::error_payload( 'invalid_payload', 500, array( 'reason' => 'json_encode_failed' ) ), 500 ); // CHANGED:
			}                                                                                                             // CHANGED:

			$args = self::build_args( $raw );                                                                             // CHANGED:
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {                                        // CHANGED:
				$args['headers'] = array();                                                                               // CHANGED:
			}                                                                                                             // CHANGED:

			// Cache-bypass headers + intent headers (safe; no secrets).                                                  // CHANGED:
			$args['headers']['Cache-Control']      = 'no-cache, no-store, max-age=0';                                     // CHANGED:
			$args['headers']['Pragma']             = 'no-cache';                                                          // CHANGED:
			$args['headers']['Expires']            = '0';                                                                 // CHANGED:
			$args['headers']['X-PPA-Bypass-Cache'] = '1';                                                                 // CHANGED:
			$args['headers']['X-PPA-Cache-Bust']   = $cache_bust;                                                         // CHANGED:
			$args['headers']['X-PPA-Intent']       = 'billing_portal';                                                    // CHANGED:
			$args['headers']['X-PPA-Link-Intent']  = 'billing_portal';                                                    // CHANGED:
			$args['headers']['X-PPA-Portal-Session'] = '1';                                                               // CHANGED:

			$response = wp_remote_post( $django_url, $args );                                                             // CHANGED:

			if ( is_wp_error( $response ) ) {                                                                             // CHANGED:
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 ); // CHANGED:
			}                                                                                                             // CHANGED:

			$code      = (int) wp_remote_retrieve_response_code( $response );                                              // CHANGED:
			$resp_body = (string) wp_remote_retrieve_body( $response );                                                   // CHANGED:
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );                                   // CHANGED:

			$json = json_decode( $resp_body, true );                                                                      // CHANGED:
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json ) ) {                                         // CHANGED:
				$payload = array(                                                                                         // CHANGED:
					'ok'         => false,                                                                                // CHANGED:
					'reason'     => 'invalid_json',                                                                        // CHANGED:
					'url'        => null,                                                                                 // CHANGED:
					'http_status'=> $code,                                                                                // CHANGED:
					'retry_after'=> ( $retry_after > 0 ? $retry_after : null ),                                            // CHANGED:
				);                                                                                                        // CHANGED:
				$status = ( $code >= 400 ? $code : 200 );                                                                 // CHANGED:
				wp_send_json_success( $payload, $status );                                                                // CHANGED:
			}                                                                                                             // CHANGED:

			// Try the expected location first.                                                                           // CHANGED:
			$existing = '';                                                                                                // CHANGED:
			if ( isset( $json['data']['license']['links']['billing_portal'] ) ) {                                         // CHANGED:
				$existing = (string) $json['data']['license']['links']['billing_portal'];                                 // CHANGED:
			} elseif ( isset( $json['license']['links']['billing_portal'] ) ) {                                           // CHANGED:
				$existing = (string) $json['license']['links']['billing_portal'];                                         // CHANGED:
			}                                                                                                             // CHANGED:
			$existing = trim( $existing );                                                                                 // CHANGED:

			// Refuse forbidden login URLs and /sales fallbacks.                                                          // CHANGED:
			if ( self::is_stripe_billing_portal_login_url( $existing ) ) { $existing = ''; }                              // CHANGED:
			if ( '' !== $existing && ( false !== stripos( $existing, '/sales' ) || false !== stripos( $existing, 'postpressai.com/sales' ) ) ) { // CHANGED:
				$existing = '';                                                                                           // CHANGED:
			}                                                                                                             // CHANGED:

			$url = '';                                                                                                    // CHANGED:
			if ( self::is_stripe_billing_portal_session_url( $existing ) ) {                                               // CHANGED:
				$url = $existing;                                                                                         // CHANGED:
			} else {                                                                                                      // CHANGED:
				$url = self::find_first_stripe_billing_session_url( $json );                                               // CHANGED:
			}                                                                                                             // CHANGED:

			if ( self::is_stripe_billing_portal_session_url( $url ) ) {                                                    // CHANGED:
				wp_send_json_success( array( 'ok' => true, 'url' => $url ), 200 );                                         // CHANGED:
			}                                                                                                             // CHANGED:

			// Rate-limited? Pass 429 through so the front-end can retry after Retry-After (or 15s).                      // CHANGED:
			$payload = array(                                                                                             // CHANGED:
				'ok'         => false,                                                                                    // CHANGED:
				'reason'     => 'missing_or_invalid_session_url',                                                         // CHANGED:
				'url'        => null,                                                                                     // CHANGED:
				'http_status'=> $code,                                                                                    // CHANGED:
				'retry_after'=> ( $retry_after > 0 ? $retry_after : null ),                                                // CHANGED:
			);                                                                                                            // CHANGED:
			$status = ( 429 === $code ? 429 : 200 );                                                                       // CHANGED:
			wp_send_json_success( $payload, $status );                                                                    // CHANGED:
		}                                                                                                                  // CHANGED:

		public static function ajax_debug_headers() {
			self::$endpoint = 'debug_headers';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}

			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/debug/headers/';

			$args = self::build_args( $payload['raw'] );
			unset( $args['body'] );

			$response = wp_remote_get( $django_url, $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				if ( $code >= 400 ) {
					wp_send_json_error( array( 'raw' => $resp_body ), $code );
				}
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			if ( $code >= 400 ) {
				wp_send_json_error( $json, $code );
			}

			wp_send_json_success( $json, $code );
		}
	}

	add_action( 'init', array( 'PPA_Controller', 'init' ) );
}