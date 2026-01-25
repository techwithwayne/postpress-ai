<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
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
 *            No contract/CORS/auth changes. Minimal, useful behavior only.                                     // CHANGED:
 *
 * 2026-01-21 • FIX: Auth headers now include BOTH X-PPA-Key and Authorization: Bearer <key>.        // CHANGED:
 *            • FIX: Always send site identifier header X-PPA-Site to support site-bound activation. // CHANGED:
 *            • FIX: For Django HTTP >= 400, return wp_send_json_error (not success).                // CHANGED:
 *            • KEEP: Never leaks secrets in logs/responses.                                         // CHANGED:
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		private static $endpoint = 'preview';

		public static function init() {
			add_action( 'wp_ajax_ppa_preview',        array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_ppa_store',          array( __CLASS__, 'ajax_store' ) );
			add_action( 'wp_ajax_ppa_debug_headers',  array( __CLASS__, 'ajax_debug_headers' ) );
			add_action( 'wp_ajax_ppa_generate',       array( __CLASS__, 'ajax_generate' ) );
			add_action( 'wp_ajax_ppa_account_status', array( __CLASS__, 'ajax_account_status' ) ); // CHANGED:
		}

		private static function error_payload( $error_code, $http_status, $meta_extra = array() ) {
			$http_status = (int) $http_status;
			$meta_base   = array(
				'source'   => 'wp_proxy',
				'endpoint' => self::$endpoint,
			);
			if ( ! is_array( $meta_extra ) ) {
				$meta_extra = array();
			}
			return array(
				'ok'    => false,
				'error' => (string) $error_code,
				'code'  => $http_status,
				'meta'  => array_merge( $meta_base, $meta_extra ),
			);
		}

		private static function must_post() {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
			if ( 'POST' !== $method ) {
				wp_send_json_error(
					self::error_payload( 'method_not_allowed', 405, array( 'reason' => 'non_post' ) ),
					405
				);
			}
		}

		private static function verify_nonce_or_forbid() {
			$headers = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();
			$nonce   = '';

			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_PPA_NONCE'];
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];
			} elseif ( isset( $headers['X-PPA-Nonce'] ) ) {
				$nonce = (string) $headers['X-PPA-Nonce'];
			} elseif ( isset( $headers['X-WP-Nonce'] ) ) {
				$nonce = (string) $headers['X-WP-Nonce'];
			}

			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'ppa-admin' ) ) {
				wp_send_json_error(
					self::error_payload( 'forbidden', 403, array( 'reason' => 'nonce_invalid_or_missing' ) ),
					403
				);
			}
		}

		/**
		 * @return array{raw:string,json:array}
		 */
		private static function read_json_body() {
			$raw = file_get_contents( 'php://input' );
			if ( empty( $raw ) && isset( $_POST['payload'] ) ) {
				$raw = wp_unslash( (string) $_POST['payload'] );
			}
			$raw   = (string) $raw;
			$assoc = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $assoc ) ) {
				$assoc = array();
			}
			return array( 'raw' => $raw, 'json' => $assoc );
		}

		// -------------------------------
		// Django base URL (safe)
		// -------------------------------

		private static function normalize_base_candidate( $candidate ) {                                                  // CHANGED:
			$candidate = trim( (string) $candidate );                                                                     // CHANGED:
			if ( '' === $candidate ) {                                                                                    // CHANGED:
				return '';                                                                                                // CHANGED:
			}                                                                                                             // CHANGED:
			if ( ! preg_match( '#^https?://#i', $candidate ) ) {                                                           // CHANGED:
				$candidate = 'https://' . ltrim( $candidate, '/' );                                                       // CHANGED:
			}                                                                                                             // CHANGED:
			$candidate = untrailingslashit( esc_url_raw( $candidate ) );                                                  // CHANGED:
			return $candidate;                                                                                            // CHANGED:
		}                                                                                                                 // CHANGED:

		private static function is_valid_base_url( $base ) {                                                              // CHANGED:
			$base = trim( (string) $base );                                                                               // CHANGED:
			if ( '' === $base ) {                                                                                         // CHANGED:
				return false;                                                                                             // CHANGED:
			}                                                                                                             // CHANGED:
			if ( function_exists( 'wp_http_validate_url' ) ) {                                                            // CHANGED:
				return (bool) wp_http_validate_url( $base );                                                              // CHANGED:
			}                                                                                                             // CHANGED:
			return (bool) preg_match( '#^https?://[^/\s]+\.[^/\s]+#i', $base );                                           // CHANGED:
		}                                                                                                                 // CHANGED:

		private static function django_base() {                                                                           // CHANGED:
			$default = 'https://apps.techwithwayne.com/postpress-ai';                                                      // CHANGED:
			$candidates = array();                                                                                        // CHANGED:

			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {                                                         // CHANGED:
				$candidates[] = (string) PPA_DJANGO_URL;                                                                  // CHANGED:
			}                                                                                                             // CHANGED:
			$candidates[] = (string) get_option( 'ppa_django_url', '' );                                                  // CHANGED:
			$candidates[] = (string) $default;                                                                            // CHANGED:

			$base_pre_filter = '';                                                                                        // CHANGED:
			foreach ( $candidates as $cand ) {                                                                            // CHANGED:
				$norm = self::normalize_base_candidate( $cand );                                                          // CHANGED:
				if ( $norm && self::is_valid_base_url( $norm ) ) {                                                        // CHANGED:
					$base_pre_filter = $norm;                                                                             // CHANGED:
					break;                                                                                                // CHANGED:
				}                                                                                                         // CHANGED:
			}                                                                                                             // CHANGED:
			if ( '' === $base_pre_filter ) {                                                                              // CHANGED:
				$base_pre_filter = self::normalize_base_candidate( $default );                                            // CHANGED:
			}                                                                                                             // CHANGED:

			$filtered = (string) apply_filters( 'ppa_django_base_url', $base_pre_filter );                                 // CHANGED:
			$filtered = self::normalize_base_candidate( $filtered );                                                       // CHANGED:
			$base     = ( $filtered && self::is_valid_base_url( $filtered ) ) ? $filtered : $base_pre_filter;             // CHANGED:

			return $base;                                                                                                  // CHANGED:
		}                                                                                                                 // CHANGED:

		// -------------------------------
		// Activation key resolution (no shared key)
		// -------------------------------

		private static function activation_key() {
			// Highest priority: explicit activation key constant.
			if ( defined( 'PPA_ACTIVATION_KEY' ) && PPA_ACTIVATION_KEY ) {
				$k = trim( (string) PPA_ACTIVATION_KEY );
				if ( '' !== $k ) { return $k; }
			}

			// Primary storage for customer sites (if you save it separately).
			$k = get_option( 'ppa_activation_key', '' );
			if ( is_string( $k ) ) {
				$k = trim( (string) $k );
				if ( '' !== $k ) { return $k; }
			}

			// Common existing storage from your settings screen.
			$k = get_option( 'ppa_license_key', '' );
			if ( is_string( $k ) ) {
				$k = trim( (string) $k );
				if ( '' !== $k ) { return $k; }
			}

			// Optional legacy constant.
			if ( defined( 'PPA_LICENSE_KEY' ) && PPA_LICENSE_KEY ) {
				$k = trim( (string) PPA_LICENSE_KEY );
				if ( '' !== $k ) { return $k; }
			}

			// Optional: settings array storage.
			$settings = get_option( 'ppa_settings', array() );
			if ( is_array( $settings ) ) {
				if ( ! empty( $settings['license_key'] ) && is_string( $settings['license_key'] ) ) {
					$k = trim( (string) $settings['license_key'] );
					if ( '' !== $k ) { return $k; }
				}
				if ( ! empty( $settings['ppa_license_key'] ) && is_string( $settings['ppa_license_key'] ) ) {
					$k = trim( (string) $settings['ppa_license_key'] );
					if ( '' !== $k ) { return $k; }
				}
			}

			return '';
		}

		private static function require_activation_key_or_403() {
			$key = self::activation_key();
			if ( '' === trim( (string) $key ) ) {
				wp_send_json_error(
					self::error_payload( 'activation_key_missing', 403, array( 'reason' => 'missing_activation_key' ) ),
					403
				);
			}
			return $key;
		}

		/**
		 * Build wp_remote_* args; includes robust auth + site identifier.
		 */
		private static function build_args( $raw_json ) {
			$activation_key = self::require_activation_key_or_403();

			// Site identifier (helps backend bind activation to site, if required)
			$site = function_exists( 'home_url' ) ? home_url() : ( isset( $_SERVER['HTTP_HOST'] ) ? ('https://' . $_SERVER['HTTP_HOST']) : '' ); // CHANGED:
			$site = esc_url_raw( (string) $site );                                                                         // CHANGED:

			$headers = array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Accept'           => 'application/json; charset=utf-8',

				// AUTH: send in BOTH places to match whatever the backend expects.                                       // CHANGED:
				'X-PPA-Key'        => $activation_key,                                                                    // CHANGED:
				'Authorization'    => 'Bearer ' . $activation_key,                                                        // CHANGED:

				// Site binding: extremely common for activation key validation.                                          // CHANGED:
				'X-PPA-Site'       => $site,                                                                              // CHANGED:

				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-Requested-With' => 'XMLHttpRequest',
			);

			// Pass-through select client headers
			$incoming = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();

			$view = '';
			if ( isset( $_SERVER['HTTP_X_PPA_VIEW'] ) ) {
				$view = (string) $_SERVER['HTTP_X_PPA_VIEW'];
			} elseif ( isset( $incoming['X-PPA-View'] ) ) {
				$view = (string) $incoming['X-PPA-View'];
			}
			if ( $view !== '' ) {
				$headers['X-PPA-View'] = $view;
			}

			$nonce = '';
			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_PPA_NONCE'];
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];
			} elseif ( isset( $incoming['X-PPA-Nonce'] ) ) {
				$nonce = (string) $incoming['X-PPA-Nonce'];
			} elseif ( isset( $incoming['X-WP-Nonce'] ) ) {
				$nonce = (string) $incoming['X-WP-Nonce'];
			}
			if ( $nonce !== '' ) {
				$headers['X-PPA-Nonce'] = $nonce;
			}

			$headers = (array) apply_filters( 'ppa_outgoing_headers', $headers, self::$endpoint );

			// Re-lock auth + site headers so nothing can alter them.
			$headers['X-PPA-Key']     = $activation_key;                                                                   // CHANGED:
			$headers['Authorization'] = 'Bearer ' . $activation_key;                                                       // CHANGED:
			$headers['X-PPA-Site']    = $site;                                                                             // CHANGED:

			$args = array(
				'headers' => $headers,
				'body'    => (string) $raw_json,
				'timeout' => 90,
			);

			$args = (array) apply_filters( 'ppa_outgoing_request_args', $args, self::$endpoint );
			return $args;
		}

		// -------------------------------
		// AI OUTPUT SANITIZATION (remove --- / ...)
		// -------------------------------

		private static function strip_divider_only_lines( $text ) {                                                        // CHANGED:
			$text = (string) $text;                                                                                       // CHANGED:
			if ( '' === trim( $text ) ) {                                                                                 // CHANGED:
				return $text;                                                                                              // CHANGED:
			}                                                                                                              // CHANGED:

			// Normalize newlines.                                                                                          // CHANGED:
			$text = str_replace( array( "\r\n", "\r" ), "\n", $text );                                                      // CHANGED:

			// Remove literal <hr> tags (some providers output these).                                                      // CHANGED:
			$text = preg_replace( '~<hr\s*/?>~i', '', $text );                                                              // CHANGED:

			// Remove HTML paragraphs that contain ONLY divider tokens like --- or ...                                      // CHANGED:
			$text = preg_replace( '~<p[^>]*>\s*(?:\.{3,}|-{3,}|—{3,}|–{3,}|_{3,}|\*{3,}|&mdash;{3,}|&ndash;{3,})\s*</p>~i', '', $text ); // CHANGED:

			// Remove Markdown-style divider-only lines (---, ..., ___, ***).                                               // CHANGED:
			$text = preg_replace( '/^\s*(?:\.{3,}|-{3,}|—{3,}|–{3,}|_{3,}|\*{3,})\s*$/m', '', $text );                      // CHANGED:

			// Collapse excessive blank lines left behind.                                                                  // CHANGED:
			$text = preg_replace( "/\n{3,}/", "\n\n", $text );                                                              // CHANGED:

			return trim( $text );                                                                                          // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function sanitize_ai_fields_in_json( &$node ) {                                                     // CHANGED:
			// Only touch known content-ish keys.                                                                           // CHANGED:
			$targets = array( 'content', 'body', 'body_markdown', 'markdown', 'html', 'excerpt' );                          // CHANGED:

			if ( is_string( $node ) ) {                                                                                    // CHANGED:
				$node = self::strip_divider_only_lines( $node );                                                           // CHANGED:
				return;                                                                                                    // CHANGED:
			}                                                                                                              // CHANGED:

			if ( ! is_array( $node ) ) {                                                                                   // CHANGED:
				return;                                                                                                    // CHANGED:
			}                                                                                                              // CHANGED:

			foreach ( $node as $k => $v ) {                                                                                // CHANGED:
				if ( is_string( $k ) && in_array( $k, $targets, true ) && is_string( $v ) ) {                              // CHANGED:
					$node[ $k ] = self::strip_divider_only_lines( $v );                                                     // CHANGED:
					continue;                                                                                              // CHANGED:
				}                                                                                                          // CHANGED:
				if ( is_array( $v ) ) {                                                                                    // CHANGED:
					self::sanitize_ai_fields_in_json( $node[ $k ] );                                                        // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:
		}                                                                                                                  // CHANGED:

		// -------------------------------
		// LOCAL WP POST HELPERS (Store)
		// -------------------------------

		private static function normalize_post_status( $status ) {                                                         // CHANGED:
			$s = strtolower( trim( (string) $status ) );                                                                    // CHANGED:
			if ( '' === $s ) { return 'draft'; }                                                                            // CHANGED:
			$allowed = array( 'draft', 'publish', 'pending', 'private' );                                                    // CHANGED:
			if ( in_array( $s, $allowed, true ) ) { return $s; }                                                            // CHANGED:
			// Some callers use "published" or "public" etc; normalize conservatively.                                      // CHANGED:
			if ( 'published' === $s ) { return 'publish'; }                                                                 // CHANGED:
			return 'draft';                                                                                                 // CHANGED:
		}                                                                                                                   // CHANGED:

		private static function safe_post_content( $content_html ) {                                                        // CHANGED:
			// Keep it safe but preserve formatting. This strips scripts/unsafe tags.                                        // CHANGED:
			$c = (string) $content_html;                                                                                    // CHANGED:
			if ( '' === trim( $c ) ) { return ''; }                                                                         // CHANGED:
			if ( function_exists( 'wp_kses_post' ) ) {                                                                      // CHANGED:
				return wp_kses_post( $c );                                                                                  // CHANGED:
			}                                                                                                               // CHANGED:
			return $c;                                                                                                      // CHANGED:
		}                                                                                                                   // CHANGED:

		private static function resolve_int_post_id( $maybe ) {                                                             // CHANGED:
			$pid = (int) $maybe;                                                                                            // CHANGED:
			return ( $pid > 0 ) ? $pid : 0;                                                                                 // CHANGED:
		}                                                                                                                   // CHANGED:

		private static function assign_terms_if_present( $post_id, $payload_json ) {                                        // CHANGED:
			// Optional: if payload included tags/categories, set them. Safe no-op if missing.                               // CHANGED:
			if ( ! $post_id || ! is_array( $payload_json ) ) { return; }                                                    // CHANGED:
			try {                                                                                                           // CHANGED:
				// Tags: supports array of strings or CSV-like.                                                              // CHANGED:
				if ( isset( $payload_json['tags'] ) && is_array( $payload_json['tags'] ) ) {                                // CHANGED:
					$tags = array();                                                                                        // CHANGED:
					foreach ( $payload_json['tags'] as $t ) {                                                               // CHANGED:
						$t = trim( (string) $t );                                                                           // CHANGED:
						if ( '' !== $t ) { $tags[] = $t; }                                                                  // CHANGED:
					}                                                                                                       // CHANGED:
					if ( ! empty( $tags ) ) {                                                                               // CHANGED:
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
		}                                                                                                                   // CHANGED:

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

			// Markdown "# Title" or "## Title".                                                                            // CHANGED:
			if ( preg_match( '/^\s*#{1,2}\s+(.+?)\s*$/m', $c, $m ) && ! empty( $m[1] ) ) {                                 // CHANGED:
				$t = trim( wp_strip_all_tags( (string) $m[1] ) );                                                          // CHANGED:
				return $t;                                                                                                 // CHANGED:
			}                                                                                                              // CHANGED:

			return '';                                                                                                     // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function upsert_wp_post_from_django( $payload_json, $django_json ) {                                  // CHANGED:
			// Expects Django JSON like: { ok:true, provider:"django", result:{ title, content, excerpt, slug, status... } } // CHANGED:
			// Returns: array( 'post_id' => int, 'edit_link' => string, 'permalink' => string )                              // CHANGED:

			if ( ! is_array( $django_json ) ) {                                                                             // CHANGED:
				return new WP_Error( 'ppa_invalid_django_json', 'Invalid Django response JSON.' );                           // CHANGED:
			}                                                                                                               // CHANGED:

			$result = array();                                                                                              // CHANGED:
			if ( isset( $django_json['result'] ) && is_array( $django_json['result'] ) ) {                                  // CHANGED:
				$result = $django_json['result'];                                                                           // CHANGED:
			} else {
				// Some backends may return the result at the top level; allow that.                                         // CHANGED:
				$result = $django_json;                                                                                     // CHANGED:
			}

			// Content first (used for safe title fallback when translation/title fields are missing).                       // CHANGED:
			$content = isset( $result['content'] ) ? (string) $result['content'] : '';                                      // CHANGED:

			// TITLE: prefer translated title fields if present; then fall back to regular title.                            // CHANGED:
			$title = self::first_nonempty_string( array(                                                                     // CHANGED:
				self::dig( $result, 'title_translated' ),                                                                    // CHANGED:
				self::dig( $result, 'translated_title' ),                                                                    // CHANGED:
				self::dig( $result, 'translation.title' ),                                                                   // CHANGED:
				self::dig( $result, 'translated.title' ),                                                                    // CHANGED:
				self::dig( $result, 'i18n.title' ),                                                                          // CHANGED:
				( is_array( $payload_json ) ? self::dig( $payload_json, 'title_translated' ) : null ),                      // CHANGED:
				( is_array( $payload_json ) ? self::dig( $payload_json, 'translated_title' ) : null ),                      // CHANGED:
				( is_array( $payload_json ) ? self::dig( $payload_json, 'translation.title' ) : null ),                     // CHANGED:
				isset( $result['title'] ) ? $result['title'] : null,                                                         // CHANGED:
				( is_array( $payload_json ) && isset( $payload_json['title'] ) ) ? $payload_json['title'] : null,           // CHANGED:
			) );                                                                                                             // CHANGED:

			// If we ended up with an English/ASCII title but the content is clearly translated,                              // CHANGED:
			// try extracting a heading from the content (bullet-proof fallback).                                            // CHANGED:
			$title_from_content = self::extract_heading_title_from_content( $content );                                      // CHANGED:
			if ( '' === trim( $title ) ) {                                                                                  // CHANGED:
				$title = $title_from_content;                                                                               // CHANGED:
			} elseif ( ! self::has_non_ascii( $title ) && self::has_non_ascii( $content ) ) {                                // CHANGED:
				// Only override if the extracted heading looks non-ASCII (i.e., translated).                               // CHANGED:
				if ( '' !== trim( $title_from_content ) && self::has_non_ascii( $title_from_content ) ) {                   // CHANGED:
					$title = $title_from_content;                                                                           // CHANGED:
				}                                                                                                           // CHANGED:
			}                                                                                                               // CHANGED:

			$excerpt = isset( $result['excerpt'] ) ? (string) $result['excerpt'] : '';                                      // CHANGED:
			$slug    = isset( $result['slug'] ) ? (string) $result['slug'] : '';                                            // CHANGED:
			$status  = isset( $result['status'] ) ? (string) $result['status'] : ( isset( $payload_json['status'] ) ? (string) $payload_json['status'] : 'draft' ); // CHANGED:
			$status  = self::normalize_post_status( $status );                                                              // CHANGED:

			// Allow payload post_id to update an existing post (edit screen).                                               // CHANGED:
			$post_id = 0;                                                                                                   // CHANGED:
			if ( isset( $payload_json['post_id'] ) ) {                                                                       // CHANGED:
				$post_id = self::resolve_int_post_id( $payload_json['post_id'] );                                            // CHANGED:
			}

			// Build the post array.                                                                                         // CHANGED:
			$postarr = array(
				'post_type'    => 'post',                                                                                   // CHANGED:
				'post_status'  => $status,                                                                                  // CHANGED:
				'post_title'   => wp_strip_all_tags( (string) $title ),                                                     // CHANGED:
				'post_content' => self::safe_post_content( $content ),                                                      // CHANGED:
				'post_excerpt' => (string) $excerpt,                                                                        // CHANGED:
			);

			// Author: current user, if available.                                                                           // CHANGED:
			$uid = get_current_user_id();                                                                                   // CHANGED:
			if ( $uid > 0 ) { $postarr['post_author'] = $uid; }                                                             // CHANGED:

			// Slug: only set if provided; sanitize.                                                                         // CHANGED:
			$slug = trim( (string) $slug );                                                                                 // CHANGED:
			if ( '' !== $slug ) {                                                                                            // CHANGED:
				$postarr['post_name'] = sanitize_title( $slug );                                                            // CHANGED:
			}

			// Upsert.                                                                                                       // CHANGED:
			if ( $post_id > 0 && get_post( $post_id ) ) {                                                                    // CHANGED:
				$postarr['ID'] = $post_id;                                                                                  // CHANGED:
				$maybe_id = wp_update_post( $postarr, true );                                                               // CHANGED:
			} else {
				$maybe_id = wp_insert_post( $postarr, true );                                                               // CHANGED:
			}

			if ( is_wp_error( $maybe_id ) ) {                                                                               // CHANGED:
				return $maybe_id;                                                                                           // CHANGED:
			}

			$post_id = (int) $maybe_id;                                                                                     // CHANGED:

			// Optional tax assignment (tags/categories) from payload (if present).                                          // CHANGED:
			self::assign_terms_if_present( $post_id, ( is_array( $payload_json ) ? $payload_json : array() ) );            // CHANGED:

			// Links.                                                                                                        // CHANGED:
			$edit_link = '';                                                                                                // CHANGED:
			if ( function_exists( 'get_edit_post_link' ) ) {                                                                // CHANGED:
				$edit_link = (string) get_edit_post_link( $post_id, 'raw' );                                                // CHANGED:
			}                                                                                                               // CHANGED:
			if ( '' === $edit_link ) {                                                                                      // CHANGED:
				$edit_link = admin_url( 'post.php?post=' . $post_id . '&action=edit' );                                     // CHANGED:
			}                                                                                                               // CHANGED:

			$permalink = '';                                                                                                // CHANGED:
			if ( function_exists( 'get_permalink' ) ) {                                                                     // CHANGED:
				$permalink = (string) get_permalink( $post_id );                                                           // CHANGED:
			}                                                                                                               // CHANGED:

			return array(
				'post_id'   => $post_id,                                                                                    // CHANGED:
				'edit_link' => $edit_link,                                                                                  // CHANGED:
				'permalink' => $permalink,                                                                                  // CHANGED:
			);
		}                                                                                                                   // CHANGED:

		// -------------------------------
		// Endpoints
		// -------------------------------

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
			$response   = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( self::error_payload( 'request_failed', 500, array( 'detail' => $response->get_error_message() ) ), 500 );
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				// If Django returns HTML/text, pass raw back
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

		public static function ajax_store() {
			self::$endpoint = 'store';

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( self::error_payload( 'forbidden', 403, array( 'reason' => 'capability_missing' ) ), 403 );
			}
			self::must_post();
			self::verify_nonce_or_forbid();

			$payload = self::read_json_body();
			$base    = self::django_base();

			$django_url = $base . '/store/';
			$response   = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );

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

			// Strip divider-only lines before saving the WP post.                                                         // CHANGED:
			self::sanitize_ai_fields_in_json( $json );                                                                      // CHANGED:

			if ( $code >= 400 ) {                                                                                         // CHANGED:
				wp_send_json_error( $json, $code );                                                                      // CHANGED:
			}

			$upsert = self::upsert_wp_post_from_django( $payload['json'], $json );                                          // CHANGED:
			if ( is_wp_error( $upsert ) ) {                                                                                // CHANGED:
				wp_send_json_error(                                                                                         // CHANGED:
					self::error_payload( 'wp_post_save_failed', 500, array( 'detail' => $upsert->get_error_message() ) ),   // CHANGED:
					500                                                                                                     // CHANGED:
				);                                                                                                          // CHANGED:
			}                                                                                                               // CHANGED:

			$json['post_id']   = $upsert['post_id'];                                                                       // CHANGED:
			$json['edit_link'] = $upsert['edit_link'];                                                                     // CHANGED:
			$json['permalink'] = $upsert['permalink'];                                                                     // CHANGED:

			if ( isset( $json['result'] ) && is_array( $json['result'] ) ) {                                                // CHANGED:
				$json['result']['post_id']   = $upsert['post_id'];                                                         // CHANGED:
				$json['result']['edit_link'] = $upsert['edit_link'];                                                       // CHANGED:
				$json['result']['permalink'] = $upsert['permalink'];                                                       // CHANGED:
			}                                                                                                               // CHANGED:

			wp_send_json_success( $json, $code );
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
			$response   = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );

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

			// Always send license_key + site_url in the BODY for Option A (no shared key required).                        // CHANGED:
			$body_arr = array( // CHANGED:
				'license_key' => (string) $license_key, // CHANGED:
				'site_url'    => (string) $site_url,    // CHANGED:
			); // CHANGED:

			$raw = wp_json_encode( $body_arr ); // CHANGED:
			if ( ! is_string( $raw ) || '' === $raw ) { // CHANGED:
				wp_send_json_error( self::error_payload( 'invalid_payload', 500, array( 'reason' => 'json_encode_failed' ) ), 500 ); // CHANGED:
			}

			$response = wp_remote_post( $django_url, self::build_args( $raw ) ); // CHANGED:

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

			if ( $code >= 400 ) { // CHANGED:
				wp_send_json_error( $json, $code ); // CHANGED:
			}

			wp_send_json_success( $json, $code ); // CHANGED:
		} // CHANGED:

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
