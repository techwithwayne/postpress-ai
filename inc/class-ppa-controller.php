<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
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

			$title   = isset( $result['title'] ) ? (string) $result['title'] : '';                                          // CHANGED:
			$content = isset( $result['content'] ) ? (string) $result['content'] : '';                                      // CHANGED:
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
				'post_title'   => wp_strip_all_tags( $title ),                                                              // CHANGED:
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

			if ( $code >= 400 ) {                                                                                         // CHANGED:
				wp_send_json_error( $json, $code );                                                                      // CHANGED:
			}

			/**
			 * CHANGED: At this point Django succeeded, but we MUST create/update the local WP post
			 * so the Composer can open the saved draft editor.
			 *
			 * We do NOT change contracts: we simply add post_id/edit_link/permalink to the returned JSON.
			 */
			$upsert = self::upsert_wp_post_from_django( $payload['json'], $json );                                          // CHANGED:
			if ( is_wp_error( $upsert ) ) {                                                                                // CHANGED:
				wp_send_json_error(                                                                                         // CHANGED:
					self::error_payload( 'wp_post_save_failed', 500, array( 'detail' => $upsert->get_error_message() ) ),   // CHANGED:
					500                                                                                                     // CHANGED:
				);                                                                                                          // CHANGED:
			}                                                                                                               // CHANGED:

			// Guarantee top-level link fields are present where the admin UI expects them.                                 // CHANGED:
			$json['post_id']   = $upsert['post_id'];                                                                       // CHANGED:
			$json['edit_link'] = $upsert['edit_link'];                                                                     // CHANGED:
			$json['permalink'] = $upsert['permalink'];                                                                     // CHANGED:

			// Also mirror into result for compatibility, but keep it non-breaking.                                         // CHANGED:
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

			if ( $code >= 400 ) {                                                                                         // CHANGED:
				wp_send_json_error( $json, $code );                                                                      // CHANGED:
			}

			wp_send_json_success( $json, $code );
		}

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
