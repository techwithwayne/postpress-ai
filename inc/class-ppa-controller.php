<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
 * 2025-11-09 • Security & robustness: POST-only, nonce check from headers, constants override,               // CHANGED:
 *              URL/headers sanitization, filters for URL/headers/args, safer JSON handling.                  // CHANGED:
 * 2025-11-08 • Post-process /store/: create local WP post (draft/publish) and inject id/permalink/edit_link. // CHANGED:
 *            • Only create locally when Django indicates success (HTTP 2xx and ok).                          // CHANGED:
 *            • Set post_author to current user; avoid reinjecting if already present.                        // CHANGED:
 *            • Defensive JSON handling across payload/result.                                                // CHANGED:
 * 2025-10-12 • Initial proxy endpoints to Django (preview/store).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		/**
		 * Register AJAX hooks (admin-only).
		 */
		public static function init() {
			add_action( 'wp_ajax_ppa_preview', array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_ppa_store',   array( __CLASS__, 'ajax_store' ) );
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * Internals
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * Enforce POST method; send 405 if not.
		 */
		private static function must_post() {                                                                       // CHANGED:
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
			if ( 'POST' !== $method ) {
				wp_send_json_error( array( 'error' => 'method_not_allowed' ), 405 );
			}
		}

		/**
		 * Verify nonce from headers (X-PPA-Nonce or X-WP-Nonce); 403 if invalid/missing.
		 */
		private static function verify_nonce_or_forbid() {                                                          // CHANGED:
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
				wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			}
		}

		/**
		 * Read raw JSON body with fallback to posted 'payload'.
		 *
		 * @return array{raw:string,json:array}
		 */
		private static function read_json_body() {                                                                  // CHANGED:
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

		/**
		 * Resolve Django base URL with constant/option + filter; sanitized, no trailing slash.
		 *
		 * @return string
		 */
		private static function django_base() {                                                                      // CHANGED:
			$base = '';
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {
				$base = (string) PPA_DJANGO_URL;
			} else {
				$base = (string) get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
			}
			$base = untrailingslashit( esc_url_raw( $base ) );
			/**
			 * Filter the Django base URL used by the controller.
			 *
			 * @param string $base
			 */
			$base = (string) apply_filters( 'ppa_django_base_url', $base );                                         // CHANGED:
			return $base;
		}

		/**
		 * Resolve shared key from constant or option. Never echo/log this.
		 *
		 * @return string
		 */
		private static function shared_key() {                                                                       // CHANGED:
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
				return (string) PPA_SHARED_KEY;
			}
			return (string) get_option( 'ppa_shared_key', '' );
		}

		/**
		 * Build wp_remote_post() args; headers are filterable.
		 *
		 * @param string $raw_json
		 * @return array
		 */
		private static function build_args( $raw_json ) {                                                            // CHANGED:
			$headers = array(
				'Content-Type' => 'application/json; charset=utf-8',
				'X-PPA-Key'    => self::shared_key(),
				'User-Agent'   => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
			);
			/**
			 * Filter the outgoing headers for Django proxy requests.
			 *
			 * @param array  $headers
			 * @param string $endpoint  Either 'preview' or 'store'
			 */
			$headers = (array) apply_filters( 'ppa_outgoing_headers', $headers, self::$endpoint );                  // CHANGED:

			$args = array(
				'headers' => $headers,
				'body'    => (string) $raw_json,
				'timeout' => 30,
			);
			/**
			 * Filter the full wp_remote_post() args.
			 *
			 * @param array  $args
			 * @param string $endpoint  Either 'preview' or 'store'
			 */
			$args = (array) apply_filters( 'ppa_outgoing_request_args', $args, self::$endpoint );                   // CHANGED:
			return $args;
		}

		/**
		 * Current endpoint label used by filters (preview|store).
		 *
		 * @var string
		 */
		private static $endpoint = 'preview';                                                                        // CHANGED:

		/* ─────────────────────────────────────────────────────────────────────
		 * Endpoints
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * Proxy to Django /preview/.
		 */
		public static function ajax_preview() {
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			}
			self::must_post();                                                                                       // CHANGED:
			self::verify_nonce_or_forbid();                                                                           // CHANGED:

			$payload = self::read_json_body();                                                                        // CHANGED:
			$base    = self::django_base();                                                                           // CHANGED:
			self::$endpoint = 'preview';                                                                              // CHANGED:

			$django_url = $base . '/preview/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                           // CHANGED:

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'error'  => 'request_failed',
						'detail' => $response->get_error_message(),
					),
				 500
				);
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			wp_send_json_success( $json, $code );
		}

		/**
		 * Proxy to Django /store/.
		 * On success, also create a local WP post and inject links for the UI.
		 */
		public static function ajax_store() {
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			}
			self::must_post();                                                                                       // CHANGED:
			self::verify_nonce_or_forbid();                                                                           // CHANGED:

			$payload = self::read_json_body();                                                                        // CHANGED:
			$base    = self::django_base();                                                                           // CHANGED:
			self::$endpoint = 'store';                                                                                // CHANGED:

			$django_url = $base . '/store/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                           // CHANGED:

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'error'  => 'request_failed',
						'detail' => $response->get_error_message(),
					),
				 500
				);
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// ---------- Local WP create + link injection (kept; hardened) ----------------
			try {
				// Only proceed to local create if Django looks successful
				$dj_ok = ( $code >= 200 && $code < 300 );
				if ( $dj_ok && is_array( $json ) && array_key_exists( 'ok', $json ) ) {
					$dj_ok = (bool) $json['ok'];
				}
				if ( ! $dj_ok ) {
					wp_send_json_success( $json, $code );
				}

				$payload_json = $payload['json']; // assoc array already parsed              // CHANGED:

				// Prefer client payload; fallback to Django result/body
				$result  = ( isset( $json['result'] ) && is_array( $json['result'] ) ) ? $json['result'] : ( is_array( $json ) ? $json : array() );

				// If Django already provided links/ID, keep them (avoid duplicate create)
				$already_has_links = (
					( isset( $json['id'] ) && $json['id'] ) ||
					( isset( $json['permalink'] ) && $json['permalink'] ) ||
					( isset( $json['edit_link'] ) && $json['edit_link'] ) ||
					( isset( $result['id'] ) && $result['id'] ) ||
					( isset( $result['permalink'] ) && $result['permalink'] ) ||
					( isset( $result['edit_link'] ) && $result['edit_link'] )
				);
				if ( $already_has_links ) {
					wp_send_json_success( $json, $code );
				}

				$title   = sanitize_text_field( $payload_json['title']   ?? ( $result['title']   ?? '' ) );           // CHANGED:
				$content =                         $payload_json['content'] ?? ( $result['content'] ?? ( $result['html'] ?? '' ) ); // CHANGED:
				$excerpt = sanitize_text_field( $payload_json['excerpt'] ?? ( $result['excerpt'] ?? '' ) );           // CHANGED:
				$slug    = sanitize_title(      $payload_json['slug']    ?? ( $result['slug']    ?? '' ) );           // CHANGED:
				$status  = sanitize_key(        $payload_json['status']  ?? ( $result['status']  ?? 'draft' ) );      // CHANGED:

				$target_sites = (array) ( $payload_json['target_sites'] ?? array() );                                 // CHANGED:
				$wants_local  = in_array( 'draft',   $target_sites, true )
				             || in_array( 'publish', $target_sites, true )
				             || in_array( $status, array( 'draft', 'publish', 'pending' ), true );

				if ( $wants_local ) {
					$post_status = in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ? $status : 'draft';

					$postarr = array(
						'post_title'   => $title,
						'post_content' => $content, // keep HTML from AI
						'post_excerpt' => $excerpt,
						'post_status'  => $post_status,
						'post_type'    => 'post',
						'post_author'  => get_current_user_id(),
					);
					if ( $slug ) {
						$postarr['post_name'] = $slug;
					}

					$post_id = wp_insert_post( wp_slash( $postarr ), true );

					if ( ! is_wp_error( $post_id ) && $post_id ) {
						// Optional terms from payload
						if ( ! empty( $payload_json['tags'] ) ) {
							$tags = array_map( 'sanitize_text_field', (array) $payload_json['tags'] );
							wp_set_post_terms( $post_id, $tags, 'post_tag', false );
						}
						if ( ! empty( $payload_json['categories'] ) ) {
							$cats = array_map( 'intval', (array) $payload_json['categories'] );
							wp_set_post_terms( $post_id, $cats, 'category', false );
						}

						$edit = get_edit_post_link( $post_id, '' );
						$view = get_permalink( $post_id );

						// Inject for tolerant clients (top-level and result)
						$json['id']        = $post_id;
						$json['edit_link'] = $edit;
						$json['permalink'] = $view;

						if ( isset( $json['result'] ) && is_array( $json['result'] ) ) {
							$json['result']['id']        = $post_id;
							$json['result']['edit_link'] = $edit;
							$json['result']['permalink'] = $view;
						}
					} else {
						$warn = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert_failed';
						$json['warning'] = array( 'type' => 'wp_insert_post_failed', 'message' => $warn );
					}
				}
			} catch ( \Throwable $e ) {
				$json['warning'] = array( 'type' => 'local_store_exception', 'message' => $e->getMessage() );
			}
			// ---------- /hardened local create -------------------------------------------

			wp_send_json_success( $json, $code );
		}
	} // end class PPA_Controller

	// Initialize hooks.
	add_action( 'init', array( 'PPA_Controller', 'init' ) );
}
