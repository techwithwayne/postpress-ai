<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
 * 2025-11-13 • Tighten Django parity: pass X-PPA-View and nonce headers through, force X-Requested-With,         // CHANGED:
 *              normalize WP-side error payloads to {ok,error,code,meta}, and set endpoint earlier for logs.      // CHANGED:
 * 2025-11-11 • Preview: guarantee result.html on the WP proxy by deriving from content/text/brief if missing.    // CHANGED:
 *              - New helpers: looks_like_html(), text_to_html(), derive_preview_html().                           // CHANGED:
 *              - No secrets logged; response shape preserved.                                                     // CHANGED:
 * 2025-11-10 • Add shared-key guard (server_misconfig 500), Accept header, and minimal                          // CHANGED:
 *              endpoint logging without secrets or payloads. Keep response shape stable.                         // CHANGED:
 * 2025-11-09 • Security & robustness: POST-only, nonce check from headers, constants override,                   // CHANGED:
 *              URL/headers sanitization, filters for URL/headers/args, safer JSON handling.                      // CHANGED:
 * 2025-11-08 • Post-process /store/: create local WP post (draft/publish) and inject id/permalink/               // CHANGED:
 *              edit_link. Only create locally when Django indicates success (HTTP 2xx and ok).                   // CHANGED:
 *              Set post_author to current user; avoid reinjecting if already present.                            // CHANGED:
 *              Defensive JSON handling across payload/result.                                                    // CHANGED:
 * 2025-10-12 • Initial proxy endpoints to Django (preview/store).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		/**
		 * Current endpoint label used by filters/logging (preview|store).
		 *
		 * @var string
		 */
		private static $endpoint = 'preview';                                                                            // CHANGED:

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
		 * Build a Django-like error payload for WP-side failures.
		 *
		 * NOTE: This lives inside the "data" wrapper when using wp_send_json_error.
		 *
		 * @param string $error_code
		 * @param int    $http_status
		 * @param array  $meta_extra
		 * @return array
		 */
		private static function error_payload( $error_code, $http_status, $meta_extra = array() ) {                      // CHANGED:
			$http_status = (int) $http_status;                                                                           // CHANGED:
			$meta_base   = array(                                                                                        // CHANGED:
				'source'   => 'wp_proxy',                                                                               // CHANGED:
				'endpoint' => self::$endpoint,                                                                          // CHANGED:
			);                                                                                                           // CHANGED:
			if ( ! is_array( $meta_extra ) ) {                                                                           // CHANGED:
				$meta_extra = array();                                                                                   // CHANGED:
			}                                                                                                            // CHANGED:
			return array(                                                                                                // CHANGED:
				'ok'    => false,                                                                                       // CHANGED:
				'error' => (string) $error_code,                                                                        // CHANGED:
				'code'  => $http_status,                                                                                // CHANGED:
				'meta'  => array_merge( $meta_base, $meta_extra ),                                                      // CHANGED:
			);                                                                                                           // CHANGED:
		}

		/**
		 * Enforce POST method; send 405 if not.
		 */
		private static function must_post() {                                                                            // CHANGED:
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
			if ( 'POST' !== $method ) {
				wp_send_json_error(
					self::error_payload(
						'method_not_allowed',
						405,
						array( 'reason' => 'non_post' )
					),
					405
				);
			}
		}

		/**
		 * Verify nonce from headers (X-PPA-Nonce or X-WP-Nonce); 403 if invalid/missing.
		 */
		private static function verify_nonce_or_forbid() {                                                               // CHANGED:
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
					self::error_payload(
						'forbidden',
						403,
						array( 'reason' => 'nonce_invalid_or_missing' )
					),
					403
				);
			}
		}

		/**
		 * Read raw JSON body with fallback to posted 'payload'.
		 *
		 * @return array{raw:string,json:array}
		 */
		private static function read_json_body() {                                                                       // CHANGED:
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
		private static function django_base() {                                                                          // CHANGED:
			$base = '';
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {
				$base = (string) PPA_DJANGO_URL;
			} else {
				$base = (string) get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
			}
			$base = untrailingslashit( esc_url_raw( $base ) );
			/** @param string $base */
			$base = (string) apply_filters( 'ppa_django_base_url', $base );                                             // CHANGED:
			return $base;
		}

		/**
		 * Resolve shared key from constant or option. Never echo/log this.
		 *
		 * @return string
		 */
		private static function shared_key() {                                                                           // CHANGED:
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
				return (string) PPA_SHARED_KEY;
			}
			return (string) get_option( 'ppa_shared_key', '' );
		}

		/**
		 * Hard-require a non-empty shared key; stops with 500 if missing.
		 */
		private static function require_shared_key_or_500() {                                                            // CHANGED:
			$key = self::shared_key();
			if ( '' === trim( (string) $key ) ) {
				// Do not leak configuration details to the client.
				wp_send_json_error(
					self::error_payload(
						'server_misconfig',
						500,
						array( 'reason' => 'shared_key_missing' )
					),
					500
				);
			}
			return $key;
		}

		/**
		 * Build wp_remote_post() args; headers are filterable.
		 *
		 * @param string $raw_json
		 * @return array
		 */
		private static function build_args( $raw_json ) {                                                                // CHANGED:
			$headers = array(
				'Content-Type'   => 'application/json; charset=utf-8',
				'Accept'         => 'application/json; charset=utf-8',                                                   // CHANGED:
				'X-PPA-Key'      => self::require_shared_key_or_500(),                                                   // CHANGED:
				'User-Agent'     => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-Requested-With' => 'XMLHttpRequest',                                                                  // CHANGED:
			);

			// Pass-through select client headers (no secrets): X-PPA-View + nonce.                                     // CHANGED:
			$incoming = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();                         // CHANGED:

			$view = '';                                                                                                  // CHANGED:
			if ( isset( $_SERVER['HTTP_X_PPA_VIEW'] ) ) {                                                                // CHANGED:
				$view = (string) $_SERVER['HTTP_X_PPA_VIEW'];                                                            // CHANGED:
			} elseif ( isset( $incoming['X-PPA-View'] ) ) {                                                              // CHANGED:
				$view = (string) $incoming['X-PPA-View'];                                                                // CHANGED:
			}                                                                                                            // CHANGED:
			if ( $view !== '' ) {                                                                                        // CHANGED:
				$headers['X-PPA-View'] = $view;                                                                          // CHANGED:
			}                                                                                                            // CHANGED:

			$nonce = '';                                                                                                 // CHANGED:
			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {                                                               // CHANGED:
				$nonce = (string) $_SERVER['HTTP_X_PPA_NONCE'];                                                          // CHANGED:
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {                                                          // CHANGED:
				$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];                                                           // CHANGED:
			} elseif ( isset( $incoming['X-PPA-Nonce'] ) ) {                                                             // CHANGED:
				$nonce = (string) $incoming['X-PPA-Nonce'];                                                              // CHANGED:
			} elseif ( isset( $incoming['X-WP-Nonce'] ) ) {                                                              // CHANGED:
				$nonce = (string) $incoming['X-WP-Nonce'];                                                               // CHANGED:
			}                                                                                                            // CHANGED:
			if ( $nonce !== '' ) {                                                                                       // CHANGED:
				$headers['X-PPA-Nonce'] = $nonce;                                                                        // CHANGED:
			}                                                                                                            // CHANGED:

			/**
			 * Filter the outgoing headers for Django proxy requests.
			 *
			 * @param array  $headers
			 * @param string $endpoint  Either 'preview' or 'store'
			 */
			$headers = (array) apply_filters( 'ppa_outgoing_headers', $headers, self::$endpoint );                      // CHANGED:

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
			$args = (array) apply_filters( 'ppa_outgoing_request_args', $args, self::$endpoint );                       // CHANGED:
			return $args;
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * HTML helpers (preview fallback to guarantee result.html)
		 * ──────────────────────────────────────────────────────────────────── */

		/** @return bool */
		private static function looks_like_html( $s ) {                                                                   // CHANGED:
			$s = (string) $s;
			if ( $s === '' ) { return false; }
			$sn = strtolower( ltrim( $s ) );
			return ( strpos( $s, '<' ) !== false && strpos( $s, '>' ) !== false )
				|| str_starts_with( $sn, '<!doctype' )
				|| str_starts_with( $sn, '<html' )
				|| str_starts_with( $sn, '<p' )
				|| str_starts_with( $sn, '<h' )
				|| str_starts_with( $sn, '<ul' )
				|| str_starts_with( $sn, '<ol' )
				|| str_starts_with( $sn, '<div' )
				|| str_starts_with( $sn, '<section' );
		}

		/** @return string */
		private static function text_to_html( $txt ) {                                                                    // CHANGED:
			$txt = (string) $txt;
			if ( $txt === '' ) { return ''; }
			$txt  = str_replace( array("\r\n","\r"), "\n", $txt );
			$safe = esc_html( $txt );
			$parts = array_filter( explode( "\n\n", $safe ), 'strlen' );
			if ( empty( $parts ) ) {
				return '<p>' . str_replace( "\n", '<br>', $safe ) . '</p>';
			}
			$out = '';
			foreach ( $parts as $p ) {
				$out .= '<p>' . str_replace( "\n", '<br>', $p ) . '</p>';
			}
			return $out;
		}

		/**
		 * Build preview HTML from available fields if result.html is missing.
		 *
		 * @param array $result  Django 'result' block (may contain content/html)
		 * @param array $payload Original request payload (may contain content/text/brief)
		 * @return string
		 */
		private static function derive_preview_html( $result, $payload ) {                                               // CHANGED:
			$result  = is_array( $result )  ? $result  : array();
			$payload = is_array( $payload ) ? $payload : array();

			$content = (string) ( $result['content']  ?? $payload['content'] ?? '' );
			if ( $content !== '' ) {
				return self::looks_like_html( $content ) ? $content : self::text_to_html( $content );
			}

			$text = (string) ( $payload['text'] ?? $payload['brief'] ?? '' );
			if ( $text !== '' ) {
				return self::text_to_html( $text );
			}

			return '';
		}

		/* ─────────────────────────────────────────────────────────────────────
		 * Endpoints
		 * ──────────────────────────────────────────────────────────────────── */

		/**
		 * Proxy to Django /preview/.
		 */
		public static function ajax_preview() {
			self::$endpoint = 'preview';                                                                                  // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					self::error_payload(
						'forbidden',
						403,
						array( 'reason' => 'capability_missing' )
					),
					403
				);
			}
			self::must_post();                                                                                           // CHANGED:
			self::verify_nonce_or_forbid();                                                                              // CHANGED:

			$payload = self::read_json_body();                                                                           // CHANGED:
			$base    = self::django_base();                                                                              // CHANGED:

			$django_url = $base . '/preview/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                              // CHANGED:

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: preview request_failed' );                                                              // CHANGED:
				wp_send_json_error(
					self::error_payload(
						'request_failed',
						500,
						array( 'detail' => $response->get_error_message() )
					),
					500
				);
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: preview http ' . $code . ' (non-json)' );                                              // CHANGED:
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// Guarantee result.html for tolerant clients
			if ( is_array( $json ) ) {                                                                                   // CHANGED:
				$res  = ( isset( $json['result'] ) && is_array( $json['result'] ) ) ? $json['result'] : array();         // CHANGED:
				$html = (string) ( $res['html'] ?? '' );                                                                 // CHANGED:
				if ( $html === '' ) {                                                                                    // CHANGED:
					$derived = self::derive_preview_html( $res, $payload['json'] );                                      // CHANGED:
					if ( $derived !== '' ) {                                                                             // CHANGED:
						if ( ! isset( $json['result'] ) || ! is_array( $json['result'] ) ) {
							$json['result'] = array();                                                                   // CHANGED:
						}
						$json['result']['html'] = $derived;                                                              // CHANGED:
					}
				}
			}

			error_log( 'PPA: preview http ' . $code );                                                                   // CHANGED:
			wp_send_json_success( $json, $code );
		}

		/**
		 * Proxy to Django /store/.
		 * On success, also create a local WP post and inject links for the UI.
		 */
		public static function ajax_store() {
			self::$endpoint = 'store';                                                                                    // CHANGED:

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					self::error_payload(
						'forbidden',
						403,
						array( 'reason' => 'capability_missing' )
					),
					403
				);
			}
			self::must_post();                                                                                           // CHANGED:
			self::verify_nonce_or_forbid();                                                                              // CHANGED:

			$payload = self::read_json_body();                                                                           // CHANGED:
			$base    = self::django_base();                                                                              // CHANGED:

			$django_url = $base . '/store/';

			$response = wp_remote_post( $django_url, self::build_args( $payload['raw'] ) );                              // CHANGED:

			if ( is_wp_error( $response ) ) {
				error_log( 'PPA: store request_failed' );                                                                // CHANGED:
				wp_send_json_error(
					self::error_payload(
						'request_failed',
						500,
						array( 'detail' => $response->get_error_message() )
					),
					500
				);
			}

			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'PPA: store http ' . $code . ' (non-json)' );                                                // CHANGED:
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// ---------- Local WP create + link injection (kept; hardened) ----------------
			try {
				$dj_ok = ( $code >= 200 && $code < 300 );
				if ( $dj_ok && is_array( $json ) && array_key_exists( 'ok', $json ) ) {
					$dj_ok = (bool) $json['ok'];
				}
				if ( ! $dj_ok ) {
					error_log( 'PPA: store http ' . $code . ' (no local create)' );                                     // CHANGED:
					wp_send_json_success( $json, $code );
				}

				$payload_json = $payload['json']; // assoc array already parsed                                         // CHANGED:
				$result  = ( isset( $json['result'] ) && is_array( $json['result'] ) )
					? $json['result']
					: ( is_array( $json ) ? $json : array() );

				$already_has_links = (
					( isset( $json['id'] ) && $json['id'] ) ||
					( isset( $json['permalink'] ) && $json['permalink'] ) ||
					( isset( $json['edit_link'] ) && $json['edit_link'] ) ||
					( isset( $result['id'] ) && $result['id'] ) ||
					( isset( $result['permalink'] ) && $result['permalink'] ) ||
					( isset( $result['edit_link'] ) && $result['edit_link'] )
				);
				if ( $already_has_links ) {
					error_log( 'PPA: store http ' . $code . ' (links present)' );                                       // CHANGED:
					wp_send_json_success( $json, $code );
				}

				$title   = sanitize_text_field( $payload_json['title']   ?? ( $result['title']   ?? '' ) );            // CHANGED:
				$content =                         $payload_json['content'] ?? ( $result['content'] ?? ( $result['html'] ?? '' ) ); // CHANGED:
				$excerpt = sanitize_text_field( $payload_json['excerpt'] ?? ( $result['excerpt'] ?? '' ) );            // CHANGED:
				$slug    = sanitize_title(      $payload_json['slug']    ?? ( $result['slug']    ?? '' ) );            // CHANGED:
				$status  = sanitize_key(        $payload_json['status']  ?? ( $result['status']  ?? 'draft' ) );       // CHANGED:

				$target_sites = (array) ( $payload_json['target_sites'] ?? array() );                                  // CHANGED:
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
							// Parity: also mirror into result.meta for clients that read meta only
							if ( ! isset( $json['result']['meta'] ) || ! is_array( $json['result']['meta'] ) ) {
								$json['result']['meta'] = array();
							}
							$json['result']['meta']['id']        = $post_id;                                             // CHANGED:
							$json['result']['meta']['edit_link'] = $edit;                                                // CHANGED:
							$json['result']['meta']['permalink'] = $view;                                                // CHANGED:
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

			error_log( 'PPA: store http ' . $code );                                                                    // CHANGED:
			wp_send_json_success( $json, $code );
		}
	} // end class PPA_Controller

	// Initialize hooks.
	add_action( 'init', array( 'PPA_Controller', 'init' ) );
}
