set +H
cd /home/u3007-tenkoaygp3je/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai || exit 1

stamp="$(date +%Y%m%d-%H%M%S)"
mkdir -p .ppa-backups/"$stamp"

cp inc/class-postpress-ai-remote-drafts.php .ppa-backups/"$stamp"/class-postpress-ai-remote-drafts.php.bak
cp assets/js/postpress-ai-remote-drafts.js .ppa-backups/"$stamp"/postpress-ai-remote-drafts.js.bak

cat > inc/class-postpress-ai-remote-drafts.php <<'PHP'
<?php
/**
 * Remote drafts + connected sites REST API.
 *
 * - Exposes a sites list for the Composer dropdown.
 * - Handles "save draft to remote site" from the Composer.
 * - Provides endpoints for the backend (Django) to create drafts directly.
 * - Self-heals source-site registration when local site_id is missing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostPress_AI_Remote_Drafts {

	/**
	 * Bootstrap.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'wp_ajax_ppa_remote_draft_from_composer', [ __CLASS__, 'ajax_remote_draft_from_composer' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {

		register_rest_route(
			'postpress-ai/v1',
			'/sites',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_sites' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		register_rest_route(
			'postpress-ai/v1',
			'/remote-draft-from-composer',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_remote_draft_from_composer' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		register_rest_route(
			'postpress-ai/v1',
			'/site-handshake',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_site_handshake' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'postpress-ai/v1',
			'/remote-draft',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_remote_draft' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * GET /postpress-ai/v1/sites
	 *
	 * Returns current site + other active sites for this license from backend.
	 */
	public static function rest_get_sites( WP_REST_Request $request ) {
		$sites = self::get_connected_sites_payload();

		if ( is_wp_error( $sites ) ) {
			$status = 500;
			$data   = $sites->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}

			return new WP_Error( $sites->get_error_code(), $sites->get_error_message(), [ 'status' => $status ] );
		}

		return rest_ensure_response( $sites );
	}

	/**
	 * Normalize params from REST requests and synthetic WP_REST_Request instances.
	 */
	protected static function get_request_params( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) && ! empty( $params ) ) {
			return $params;
		}

		$body = $request->get_body();
		if ( is_string( $body ) && '' !== trim( $body ) ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}

		$body_params = $request->get_body_params();
		if ( is_array( $body_params ) && ! empty( $body_params ) ) {
			return $body_params;
		}

		$params = $request->get_params();
		return is_array( $params ) ? $params : [];
	}

	/**
	 * POST /postpress-ai/v1/remote-draft-from-composer
	 *
	 * Browser -> plugin -> backend -> remote site.
	 */
	public static function rest_remote_draft_from_composer( WP_REST_Request $request ) {
		$params         = self::get_request_params( $request );
		$target_site_id = isset( $params['target_site_id'] ) ? trim( sanitize_text_field( (string) $params['target_site_id'] ) ) : '';

		if ( '' === $target_site_id || 'current' === $target_site_id ) {
			return new WP_Error( 'bad_target', __( 'Invalid target site selected.', 'postpress-ai' ), [ 'status' => 400 ] );
		}

		$target_site = self::get_connected_site_by_id( $target_site_id );
		if ( is_wp_error( $target_site ) ) {
			$status = 500;
			$data   = $target_site->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}

			return new WP_Error( $target_site->get_error_code(), $target_site->get_error_message(), [ 'status' => $status ] );
		}

		$target_label = self::get_site_identity_string( $target_site );

		$post = [
			'post_title'   => isset( $params['post_title'] ) ? (string) $params['post_title'] : '',
			'post_content' => isset( $params['post_content'] ) ? (string) $params['post_content'] : '',
			'post_excerpt' => isset( $params['post_excerpt'] ) ? (string) $params['post_excerpt'] : '',
			'post_type'    => isset( $params['post_type'] ) ? (string) $params['post_type'] : 'post',
			'meta'         => isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : [],
		];

		$license_key = trim( (string) get_option( 'postpress_ai_license_key' ) );
		if ( '' === $license_key ) {
			return new WP_Error(
				'missing_site_info',
				sprintf( __( 'Remote draft to %s failed: this site is not fully registered with PostPress AI.', 'postpress-ai' ), $target_label ),
				[
					'status' => 400,
					'target' => $target_site,
				]
			);
		}

		$source_site_id = trim( (string) get_option( 'postpress_ai_site_id' ) );
		if ( '' === $source_site_id ) {
			self::ensure_source_site_registration();
			$source_site_id = trim( (string) get_option( 'postpress_ai_site_id' ) );
		}

		if ( '' === $source_site_id ) {
			return new WP_Error(
				'missing_site_info',
				sprintf( __( 'Remote draft to %s failed: this site is not fully registered with PostPress AI.', 'postpress-ai' ), $target_label ),
				[
					'status' => 400,
					'target' => $target_site,
				]
			);
		}

		$backend_response = self::backend_request(
			'POST',
			'/remote-drafts/create/',
			[
				'license_key'    => $license_key,
				'source_site_id' => $source_site_id,
				'target_site_id' => $target_site_id,
				'post'           => $post,
			]
		);

		if ( is_wp_error( $backend_response ) ) {
			$status = 500;
			$data   = $backend_response->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}

			return new WP_Error(
				'remote_draft_failed',
				sprintf( __( 'Remote draft to %s failed: %s', 'postpress-ai' ), $target_label, $backend_response->get_error_message() ),
				[
					'status' => $status,
					'target' => $target_site,
				]
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $backend_response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'remote_draft_failed',
				sprintf( __( 'Remote draft to %s failed: invalid response from PostPress AI backend.', 'postpress-ai' ), $target_label ),
				[
					'status' => 500,
					'target' => $target_site,
				]
			);
		}

		if ( isset( $body['status'] ) && 'ok' !== strtolower( (string) $body['status'] ) ) {
			$message = isset( $body['message'] ) ? trim( (string) $body['message'] ) : '';
			if ( '' === $message ) {
				$message = __( 'Unknown backend response.', 'postpress-ai' );
			}

			return new WP_Error(
				'remote_draft_failed',
				sprintf( __( 'Remote draft to %s failed: %s', 'postpress-ai' ), $target_label, $message ),
				[
					'status'  => 500,
					'target'  => $target_site,
					'backend' => $body,
				]
			);
		}

		$body['target'] = $target_site;
		if ( empty( $body['message'] ) ) {
			$body['message'] = sprintf( __( 'Draft sent to %s.', 'postpress-ai' ), $target_label );
		}

		return rest_ensure_response( $body );
	}

	/**
	 * POST /postpress-ai/v1/site-handshake
	 *
	 * Backend confirms site + sets site_id and site_token.
	 * Accept both header-style and legacy body-style tokens.
	 */
	public static function rest_site_handshake( WP_REST_Request $request ) {
		$params = self::get_request_params( $request );
		$token  = self::extract_handshake_token( $request, $params );

		if ( ! self::verify_backend_token( $token ) ) {
			return new WP_Error( 'forbidden', __( 'Invalid backend token.', 'postpress-ai' ), [ 'status' => 403 ] );
		}

		$site_id    = isset( $params['site_id'] ) ? sanitize_text_field( (string) $params['site_id'] ) : '';
		$site_token = isset( $params['site_token'] ) ? sanitize_text_field( (string) $params['site_token'] ) : '';

		if ( '' === $site_id || '' === $site_token ) {
			return new WP_Error(
				'bad_request',
				__( 'Missing site_id or site_token.', 'postpress-ai' ),
				[ 'status' => 400 ]
			);
		}

		update_option( 'postpress_ai_site_id', $site_id );
		update_option( 'postpress_ai_site_token', $site_token );

		return [
			'ok'       => true,
			'status'   => 'ok',
			'site_id'  => $site_id,
			'version'  => defined( 'POSTPRESS_AI_PLUGIN_VERSION' ) ? POSTPRESS_AI_PLUGIN_VERSION : '',
			'wp'       => get_bloginfo( 'version' ),
			'home_url' => home_url(),
		];
	}

	/**
	 * POST /postpress-ai/v1/remote-draft
	 *
	 * Backend -> site: actually create the WordPress draft here.
	 */
	protected static function extract_remote_draft_token( WP_REST_Request $request, array $payload ) {
		$auth_header = (string) $request->get_header( 'authorization' );

		if ( $auth_header && stripos( $auth_header, 'bearer ' ) === 0 ) {
			$token = trim( substr( $auth_header, 7 ) );
			if ( '' !== $token ) {
				return $token;
			}
		}

		$candidates = [
			(string) $request->get_header( 'x-postpress-ai-remote-draft' ),
			(string) $request->get_header( 'x-ppa-remote-draft-token' ),
			isset( $payload['remote_draft_token'] ) ? (string) $payload['remote_draft_token'] : '',
			isset( $payload['postpress_ai_site_token'] ) ? (string) $payload['postpress_ai_site_token'] : '',
		];

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	public static function rest_remote_draft( WP_REST_Request $request ) {
		$payload      = self::get_request_params( $request );
		$token        = self::extract_remote_draft_token( $request, $payload );
		$stored_token = get_option( 'postpress_ai_site_token' );

		if ( '' === $token ) {
			return new WP_Error( 'forbidden', __( 'Missing remote draft token.', 'postpress-ai' ), [ 'status' => 403 ] );
		}

		if ( ! $stored_token || ! hash_equals( (string) $stored_token, (string) $token ) ) {
			return new WP_Error( 'forbidden', __( 'Invalid remote draft token.', 'postpress-ai' ), [ 'status' => 403 ] );
		}

		$postarr = [
			'post_title'   => wp_strip_all_tags( isset( $payload['post_title'] ) ? $payload['post_title'] : '' ),
			'post_content' => isset( $payload['post_content'] ) ? $payload['post_content'] : '',
			'post_excerpt' => isset( $payload['post_excerpt'] ) ? $payload['post_excerpt'] : '',
			'post_type'    => isset( $payload['post_type'] ) ? $payload['post_type'] : 'post',
			'post_status'  => 'draft',
		];

		$author_id = (int) get_option( 'postpress_ai_default_remote_author' );
		if ( $author_id && get_user_by( 'id', $author_id ) ) {
			$postarr['post_author'] = $author_id;
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'remote_draft_failed',
				$post_id->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		if ( ! empty( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			foreach ( $payload['meta'] as $key => $value ) {
				update_post_meta(
					$post_id,
					sanitize_key( $key ),
					is_scalar( $value ) ? $value : wp_json_encode( $value )
				);
			}
		}

		$edit_link = get_edit_post_link( $post_id, '' );

		return [
			'status'    => 'ok',
			'post_id'   => $post_id,
			'edit_link' => $edit_link,
		];
	}

	/**
	 * Read AJAX nonce from request fields or headers.
	 */
	protected static function get_ajax_nonce() {
		$nonce = '';

		if ( isset( $_POST['nonce'] ) ) {
			$nonce = (string) wp_unslash( $_POST['nonce'] );
		} elseif ( isset( $_REQUEST['nonce'] ) ) {
			$nonce = (string) wp_unslash( $_REQUEST['nonce'] );
		}

		if ( '' === $nonce ) {
			if ( isset( $_SERVER['HTTP_X_PPA_NONCE'] ) ) {
				$nonce = (string) wp_unslash( $_SERVER['HTTP_X_PPA_NONCE'] );
			} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				$nonce = (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
			}
		}

		return trim( (string) $nonce );
	}

	/**
	 * Parse JSON body for admin-ajax, with POST fallback.
	 */
	protected static function get_ajax_json_params() {
		$raw = file_get_contents( 'php://input' );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}

		$fallback = [];
		$skip = [ 'action', 'nonce', '_wpnonce', '_ajax_nonce', '_wp_http_referer' ];

		foreach ( $_POST as $k => $v ) {
			$kk = (string) $k;
			if ( in_array( $kk, $skip, true ) ) {
				continue;
			}

			if ( is_array( $v ) ) {
				$arr = [];
				foreach ( $v as $vv ) {
					if ( is_scalar( $vv ) ) {
						$arr[] = (string) wp_unslash( $vv );
					}
				}
				$fallback[ $kk ] = $arr;
			} elseif ( is_scalar( $v ) ) {
				$fallback[ $kk ] = (string) wp_unslash( $v );
			}
		}

		return $fallback;
	}

	/**
	 * Browser -> admin-ajax -> existing remote relay handler.
	 */
	public static function ajax_remote_draft_from_composer() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json(
				[
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to do that.', 'postpress-ai' ),
					'data'    => [ 'status' => 403 ],
				],
				403
			);
		}

		$nonce = self::get_ajax_nonce();
		$valid = false;

		if ( '' !== $nonce ) {
			$valid =
				wp_verify_nonce( $nonce, 'ppa_admin_nonce' ) ||
				wp_verify_nonce( $nonce, 'ppa-admin' ) ||
				wp_verify_nonce( $nonce, 'wp_rest' );
		}

		if ( ! $valid ) {
			wp_send_json(
				[
					'code'    => 'invalid_nonce',
					'message' => __( 'Invalid nonce.', 'postpress-ai' ),
					'data'    => [ 'status' => 403 ],
				],
				403
			);
		}

		$params = self::get_ajax_json_params();

		$request = new WP_REST_Request( 'POST', '/postpress-ai/v1/remote-draft-from-composer' );
		$request->set_header( 'content-type', 'application/json; charset=utf-8' );

		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}

		if ( ! empty( $params ) ) {
			$encoded = wp_json_encode( $params );
			if ( is_string( $encoded ) && '' !== $encoded ) {
				$request->set_body( $encoded );
			}
		}

		$response = self::rest_remote_draft_from_composer( $request );

		if ( is_wp_error( $response ) ) {
			$status = 500;
			$edata  = $response->get_error_data();
			if ( is_array( $edata ) && isset( $edata['status'] ) ) {
				$status = (int) $edata['status'];
			}

			wp_send_json(
				[
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
					'data'    => $edata,
				],
				$status
			);
		}

		if ( $response instanceof WP_REST_Response ) {
			wp_send_json( $response->get_data(), $response->get_status() );
		}

		wp_send_json( $response, 200 );
	}

	/**
	 * Ensure the current site has a local site_id.
	 *
	 * This is intentionally best-effort and safe to call repeatedly.
	 */
	protected static function ensure_source_site_registration() {
		$license_key = trim( (string) get_option( 'postpress_ai_license_key' ) );
		$site_id     = trim( (string) get_option( 'postpress_ai_site_id' ) );

		if ( '' === $license_key || '' !== $site_id ) {
			return true;
		}

		$resp = self::backend_request(
			'POST',
			'/license/register-site/',
			[
				'license_key' => $license_key,
				'site_url'    => home_url(),
				'site_name'   => get_bloginfo( 'name' ),
			]
		);

		if ( is_wp_error( $resp ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return false;
		}

		$site_id = trim( (string) get_option( 'postpress_ai_site_id' ) );
		if ( '' !== $site_id ) {
			return true;
		}

		if (
			isset( $body['data'] ) &&
			is_array( $body['data'] ) &&
			isset( $body['data']['site'] ) &&
			is_array( $body['data']['site'] ) &&
			! empty( $body['data']['site']['id'] )
		) {
			update_option( 'postpress_ai_site_id', (string) $body['data']['site']['id'] );
			return true;
		}

		if (
			isset( $body['site'] ) &&
			is_array( $body['site'] ) &&
			! empty( $body['site']['id'] )
		) {
			update_option( 'postpress_ai_site_id', (string) $body['site']['id'] );
			return true;
		}

		return false;
	}

	/**
	 * Return a normalized domain string for a site URL.
	 */
	protected static function get_site_domain( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = preg_replace( '#^https?://#i', '', $url );
			$host = preg_replace( '#/.*$#', '', (string) $host );
		}

		return strtolower( trim( (string) $host ) );
	}

	/**
	 * Build a human-safe identity string for UI and errors.
	 */
	protected static function get_site_identity_string( array $site ) {
		$label = isset( $site['label'] ) ? trim( (string) $site['label'] ) : '';
		if ( '' !== $label ) {
			return $label;
		}

		$name    = isset( $site['name'] ) ? trim( (string) $site['name'] ) : '';
		$domain  = isset( $site['domain'] ) ? trim( (string) $site['domain'] ) : '';
		$site_id = isset( $site['site_id'] ) ? trim( (string) $site['site_id'] ) : '';

		$parts = [];
		if ( '' !== $name ) {
			$parts[] = $name;
		}
		if ( '' !== $domain ) {
			$parts[] = $domain;
		}

		$identity = implode( ' — ', array_slice( $parts, 0, 2 ) );
		if ( '' === $identity ) {
			$identity = __( 'selected site', 'postpress-ai' );
		}

		if ( '' !== $site_id ) {
			$identity .= sprintf( ' (Site ID %s)', $site_id );
		}

		return $identity;
	}

	/**
	 * Normalize one site row for UI consumption.
	 */
	protected static function normalize_site_record( array $site, $is_current = false ) {
		$site_id = isset( $site['site_id'] ) ? trim( (string) $site['site_id'] ) : '';
		$name    = isset( $site['name'] ) ? sanitize_text_field( (string) $site['name'] ) : '';
		$url     = isset( $site['url'] ) ? esc_url_raw( (string) $site['url'] ) : '';
		$domain  = self::get_site_domain( $url );
		$status  = isset( $site['status'] ) ? strtolower( trim( (string) $site['status'] ) ) : 'active';

		if ( '' === $name ) {
			$name = ( '' !== $domain ) ? $domain : $url;
		}

		$label = $name;
		if ( '' !== $domain ) {
			$label .= ' — ' . $domain;
		}
		if ( '' !== $site_id ) {
			$label .= sprintf( ' (Site ID %s)', $site_id );
		}

		return [
			'site_id'    => $site_id,
			'name'       => $name,
			'url'        => $url,
			'domain'     => $domain,
			'status'     => ( '' !== $status ) ? $status : 'active',
			'label'      => $label,
			'is_current' => (bool) $is_current,
		];
	}

	/**
	 * Resolve the full connected-sites payload for the composer.
	 */
	protected static function get_connected_sites_payload() {
		$license_key = trim( (string) get_option( 'postpress_ai_license_key' ) );

		if ( '' === $license_key ) {
			return new WP_Error( 'no_license', 'License key not configured.', [ 'status' => 400 ] );
		}

		self::ensure_source_site_registration();

		$backend_response = self::backend_request(
			'GET',
			'/license/sites/',
			[ 'license_key' => $license_key ]
		);

		if ( is_wp_error( $backend_response ) ) {
			return new WP_Error( 'backend_error', $backend_response->get_error_message(), [ 'status' => 500 ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $backend_response ), true );

		$sites = [];
		if (
			is_array( $body ) &&
			isset( $body['data'] ) &&
			is_array( $body['data'] ) &&
			isset( $body['data']['sites'] ) &&
			is_array( $body['data']['sites'] )
		) {
			$sites = $body['data']['sites'];
		}

		$current_site_url      = home_url();
		$current_site_url_norm = untrailingslashit( strtolower( (string) $current_site_url ) );
		$current_site_id       = trim( (string) get_option( 'postpress_ai_site_id' ) );

		$current_site_name = get_bloginfo( 'name' );
		if ( ! is_string( $current_site_name ) || '' === trim( $current_site_name ) ) {
			$current_site_name = preg_replace( '#^https?://#', '', (string) home_url() );
		}

		$resolve_site_title = function( $url ) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' === $url ) {
				return '';
			}

			$cache_key = 'ppa_site_title_' . md5( strtolower( $url ) );
			$cached    = get_transient( $cache_key );
			if ( is_string( $cached ) ) {
				return ( '__none__' === $cached ) ? '' : $cached;
			}

			$endpoint = untrailingslashit( $url ) . '/wp-json';
			$resp     = wp_remote_get(
				$endpoint,
				[
					'timeout'     => 6,
					'redirection' => 3,
					'headers'     => [ 'Accept' => 'application/json' ],
				]
			);

			if ( is_wp_error( $resp ) ) {
				set_transient( $cache_key, '__none__', HOUR_IN_SECONDS );
				return '';
			}

			$status = (int) wp_remote_retrieve_response_code( $resp );
			if ( $status < 200 || $status >= 300 ) {
				set_transient( $cache_key, '__none__', HOUR_IN_SECONDS );
				return '';
			}

			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $json ) && ! empty( $json['name'] ) ) {
				$title = sanitize_text_field( (string) $json['name'] );
				set_transient( $cache_key, $title, 12 * HOUR_IN_SECONDS );
				return $title;
			}

			set_transient( $cache_key, '__none__', HOUR_IN_SECONDS );
			return '';
		};

		$result   = [];
		$result[] = self::normalize_site_record(
			[
				'site_id' => $current_site_id,
				'name'    => $current_site_name,
				'url'     => $current_site_url,
				'status'  => 'active',
			],
			true
		);

		foreach ( $sites as $site ) {
			$remote_id  = isset( $site['id'] ) ? trim( (string) $site['id'] ) : '';
			$remote_url = isset( $site['url'] ) ? (string) $site['url'] : '';

			if ( '' === $remote_id || '' === $remote_url ) {
				continue;
			}

			$site_url_norm = untrailingslashit( strtolower( (string) $remote_url ) );
			if ( $site_url_norm === $current_site_url_norm ) {
				continue;
			}

			if ( '' !== $current_site_id && $remote_id === $current_site_id ) {
				continue;
			}

			$status = isset( $site['status'] ) ? strtolower( trim( (string) $site['status'] ) ) : 'active';
			if ( 'active' !== $status ) {
				continue;
			}

			$resolved_name = $resolve_site_title( $remote_url );
			if ( '' === $resolved_name ) {
				$resolved_name = isset( $site['name'] ) ? (string) $site['name'] : '';
			}

			$result[] = self::normalize_site_record(
				[
					'site_id' => $remote_id,
					'name'    => $resolved_name,
					'url'     => $remote_url,
					'status'  => $status,
				],
				false
			);
		}

		return array_values( $result );
	}

	/**
	 * Resolve one connected target site by Site ID.
	 */
	protected static function get_connected_site_by_id( $target_site_id ) {
		$target_site_id = trim( (string) $target_site_id );
		if ( '' === $target_site_id ) {
			return new WP_Error( 'bad_target', __( 'Invalid target site selected.', 'postpress-ai' ), [ 'status' => 400 ] );
		}

		$sites = self::get_connected_sites_payload();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}

		foreach ( $sites as $site ) {
			if ( ! empty( $site['is_current'] ) ) {
				continue;
			}

			if ( isset( $site['site_id'] ) && (string) $site['site_id'] === $target_site_id ) {
				return $site;
			}
		}

		return new WP_Error(
			'bad_target',
			sprintf( __( 'Selected target site could not be resolved for Site ID %s.', 'postpress-ai' ), $target_site_id ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build a backend URL that works whether POSTPRESS_AI_API_BASE is root or already includes /postpress-ai.
	 */
	protected static function build_backend_url( $path ) {
		$base = defined( 'POSTPRESS_AI_API_BASE' ) ? (string) POSTPRESS_AI_API_BASE : '';
		$base = trim( $base );

		if ( '' === $base ) {
			return '';
		}

		$base = rtrim( $base, '/' );
		$path = '/' . ltrim( (string) $path, '/' );

		if ( preg_match( '#/postpress-ai$#', $base ) ) {
			if ( 0 === strpos( $path, '/postpress-ai/' ) ) {
				return $base . substr( $path, strlen( '/postpress-ai' ) );
			}
			return $base . $path;
		}

		if ( 0 !== strpos( $path, '/postpress-ai/' ) ) {
			$path = '/postpress-ai' . $path;
		}

		return $base . $path;
	}

	/**
	 * Reduce ugly HTML/backend junk into a human-safe message.
	 */
	protected static function compact_backend_error_message( $code, $body ) {
		$code = (int) $code;
		$body = is_string( $body ) ? trim( $body ) : '';

		if ( '' !== $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				foreach ( [ 'message', 'detail', 'error' ] as $key ) {
					if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
						$body = $decoded[ $key ];
						break;
					}
				}
			}
		}

		$text = wp_strip_all_tags( html_entity_decode( (string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		$text = trim( (string) $text );

		if ( $code === 502 ) {
			if ( '' === $text || stripos( $text, 'cloudflare' ) !== false || stripos( $text, 'bad gateway' ) !== false ) {
				return 'PostPress AI backend is temporarily unavailable (502).';
			}
		}

		if ( $code === 503 && '' === $text ) {
			return 'PostPress AI backend is temporarily unavailable (503).';
		}

		if ( $code === 504 && '' === $text ) {
			return 'PostPress AI backend timed out (504).';
		}

		if ( '' === $text ) {
			return sprintf( 'PostPress AI backend returned HTTP %d.', $code );
		}

		if ( strlen( $text ) > 220 ) {
			$text = substr( $text, 0, 217 ) . '...';
		}

		return sprintf( 'PostPress AI backend returned HTTP %d: %s', $code, $text );
	}

	/**
	 * Helper: call backend (Django).
	 */
	protected static function backend_request( $method, $path, $data = [] ) {
		$url = self::build_backend_url( $path );
		if ( '' === $url ) {
			return new WP_Error( 'no_api_base', 'POSTPRESS_AI_API_BASE is not defined.' );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		];

		if ( 'GET' === strtoupper( $method ) ) {
			$url = add_query_arg( $data, $url );
		} else {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			$msg  = self::compact_backend_error_message( $code, $body );

			return new WP_Error(
				'backend_http_error',
				$msg,
				[
					'status'        => $code,
					'backend_code'  => $code,
					'backend_raw'   => $body,
					'backend_msg'   => $msg,
				]
			);
		}

		return $response;
	}

	/**
	 * Pull handshake token from the widest safe set of headers/body keys.
	 */
	protected static function extract_handshake_token( WP_REST_Request $request, array $params ) {
		$candidates = [
			(string) $request->get_header( 'x-postpress-ai-handshake' ),
			(string) $request->get_header( 'x-ppa-wp-shared-secret' ),
			(string) $request->get_header( 'x-ppa-shared-secret' ),
			(string) $request->get_header( 'x-shared-secret' ),
			(string) $request->get_header( 'x-api-key' ),
			isset( $params['backend_token'] ) ? (string) $params['backend_token'] : '',
			isset( $params['shared_secret'] ) ? (string) $params['shared_secret'] : '',
			isset( $params['ppa_shared_key'] ) ? (string) $params['ppa_shared_key'] : '',
			isset( $params['ppa_wp_shared_secret'] ) ? (string) $params['ppa_wp_shared_secret'] : '',
		];

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Verify backend shared token for handshake.
	 */
	protected static function verify_backend_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return false;
		}

		$known = [];

		if ( defined( 'POSTPRESS_AI_BACKEND_SHARED_SECRET' ) && POSTPRESS_AI_BACKEND_SHARED_SECRET ) {
			$known[] = (string) POSTPRESS_AI_BACKEND_SHARED_SECRET;
		}

		$opt_backend_secret = get_option( 'postpress_ai_backend_shared_secret' );
		if ( ! empty( $opt_backend_secret ) ) {
			$known[] = (string) $opt_backend_secret;
		}

		if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
			$known[] = (string) PPA_SHARED_KEY;
		}

		$opt_ppa_shared_key = get_option( 'ppa_shared_key' );
		if ( ! empty( $opt_ppa_shared_key ) ) {
			$known[] = (string) $opt_ppa_shared_key;
		}

		if ( defined( 'PPA_WP_SHARED_SECRET' ) && PPA_WP_SHARED_SECRET ) {
			$known[] = (string) PPA_WP_SHARED_SECRET;
		}

		$opt_wp_shared_secret = get_option( 'ppa_wp_shared_secret' );
		if ( ! empty( $opt_wp_shared_secret ) ) {
			$known[] = (string) $opt_wp_shared_secret;
		}

		$known = array_values( array_unique( array_filter( array_map( 'strval', $known ) ) ) );

		if ( empty( $known ) ) {
			return false;
		}

		foreach ( $known as $secret ) {
			if ( hash_equals( $secret, $token ) ) {
				return true;
			}
		}

		return false;
	}
}

// Bootstrap.
PostPress_AI_Remote_Drafts::init();

PHP

cat > assets/js/postpress-ai-remote-drafts.js <<'JS'
(function($, win, doc){
  'use strict';

  function cleanUrl(url){
    return String(url || '').replace(/^https?:\/\//, '').replace(/\/$/, '');
  }

  function getDomain(url){
    var cleaned = cleanUrl(url);
    return cleaned.split('/')[0] || '';
  }

  function ucFirst(value){
    value = String(value || '').trim();
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : '';
  }

  function buildLabel(site){
    var name   = String(site.name || '').trim() || 'Unnamed site';
    var domain = String(site.domain || getDomain(site.url || '')).trim();
    var siteId = String(site.site_id || '').trim();

    var label = name;
    if(domain){
      label += ' — ' + domain;
    }
    if(siteId){
      label += ' (Site ID ' + siteId + ')';
    }

    return label;
  }

  function buildMeta(site){
    var bits   = [];
    var domain = String(site.domain || getDomain(site.url || '')).trim();
    var siteId = String(site.site_id || '').trim();
    var status = ucFirst(String(site.status || 'active').toLowerCase());

    if(domain) bits.push(domain);
    if(siteId) bits.push('Site ID ' + siteId);
    if(status) bits.push(status);

    return bits.join(' · ');
  }

  function normalizeSite(raw){
    raw = raw || {};

    var url    = String(raw.url || raw.link || '').trim();
    var domain = String(raw.domain || getDomain(url)).trim();

    var site = {
      value: raw.is_current ? 'current' : String(raw.site_id || '').trim(),
      site_id: String(raw.site_id || '').trim(),
      name: String(raw.name || raw.title || domain || url || 'Unnamed site').trim(),
      url: url,
      domain: domain,
      status: String(raw.status || 'active').trim().toLowerCase() || 'active',
      is_current: !!raw.is_current,
      label: String(raw.label || '').trim(),
      link: url || (domain ? ('https://' + domain + '/') : '')
    };

    if(!site.label){
      site.label = buildLabel(site);
    }

    site.meta = buildMeta(site);
    return site;
  }

  function isComposer(){
    return !!doc.getElementById('ppa-composer');
  }

  function getCurrentSite($select){
    return normalizeSite({
      is_current: true,
      site_id: $select.attr('data-current-site-id') || '',
      name: $select.attr('data-current-name') || 'This site',
      url: $select.attr('data-current-url') || win.location.origin,
      status: 'active',
      label: $select.attr('data-current-label') || ''
    });
  }

  function setOptionData($opt, site){
    if(!$opt || !$opt.length || !site) return;

    $opt
      .attr('data-site-id', site.site_id || '')
      .attr('data-site-name', site.name || '')
      .attr('data-site-url', site.url || '')
      .attr('data-site-domain', site.domain || '')
      .attr('data-site-status', site.status || 'active')
      .attr('data-site-label', site.label || '')
      .attr('data-site-link', site.link || '')
      .text(site.label || site.name || site.domain || 'Site');
  }

  function siteFromOption($opt, fallbackCurrent){
    if(!$opt || !$opt.length){
      return fallbackCurrent || null;
    }

    var site = normalizeSite({
      is_current: ($opt.val() || 'current') === 'current',
      site_id: $opt.attr('data-site-id') || '',
      name: $opt.attr('data-site-name') || '',
      url: $opt.attr('data-site-url') || $opt.attr('data-site-link') || '',
      domain: $opt.attr('data-site-domain') || '',
      status: $opt.attr('data-site-status') || 'active',
      label: $opt.attr('data-site-label') || ''
    });

    site.value = String($opt.val() || 'current');

    if(site.value === 'current' && fallbackCurrent){
      return fallbackCurrent;
    }

    return site;
  }

  function setHeader(site){
    if(!site) return;

    var $link = $('#ppa-target-site-link');
    var $url  = $('#ppa-target-site-url');
    var $meta = $('#ppa-target-site-meta');

    if($link.length){
      $link.text(site.name || site.label || '');
      if(site.link){
        $link
          .attr('href', site.link)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer');
      }
    }

    var metaText = site.meta || buildMeta(site);

    if($meta.length){
      $meta.text(metaText).show();
      if($url.length){
        $url.text('').hide();
      }
    } else if($url.length){
      $url.text(metaText).show();
    }
  }

  function getNonce(){
    if(win.wpApiSettings && win.wpApiSettings.nonce) return win.wpApiSettings.nonce;
    var el = doc.getElementById('ppa-composer');
    if(el && el.getAttribute('data-ppa-nonce')) return el.getAttribute('data-ppa-nonce');
    return '';
  }

  function parseJsonResponse(resp){
    return resp.text().then(function(text){
      var data = {};

      if(text){
        try {
          data = JSON.parse(text);
        } catch (e) {
          data = { message: text };
        }
      }

      if(!resp.ok){
        var message = (data && data.message) ? data.message : ('HTTP ' + resp.status);
        var error = new Error(message);
        error.status = resp.status;
        error.response = data;
        throw error;
      }

      return data;
    });
  }

  function apiGetSites(){
    if(win.wp && win.wp.apiFetch){
      return win.wp.apiFetch({ path: '/postpress-ai/v1/sites' });
    }

    return fetch('/wp-json/postpress-ai/v1/sites', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': getNonce() }
    }).then(parseJsonResponse);
  }

  function apiRemoteDraft(payload, targetSiteId){
    var body = {
      target_site_id: targetSiteId,
      post_title: payload.post_title,
      post_content: payload.post_content,
      post_excerpt: payload.post_excerpt,
      post_type: payload.post_type,
      meta: payload.meta || {}
    };

    if(win.wp && win.wp.apiFetch){
      return win.wp.apiFetch({
        path: '/postpress-ai/v1/remote-draft-from-composer',
        method: 'POST',
        data: body
      });
    }

    return fetch('/wp-json/postpress-ai/v1/remote-draft-from-composer', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': getNonce()
      },
      body: JSON.stringify(body)
    }).then(parseJsonResponse);
  }

  function collectPayload(){
    var subject = ($('#ppa-subject').val() || '').trim();
    var title   = ($('#ppa-title').val() || subject).trim();
    var excerpt = ($('#ppa-excerpt').val() || '').trim();

    var pane = doc.getElementById('ppa-preview-pane');
    var html = pane ? (pane.innerHTML || '').trim() : '';

    return {
      post_title: title,
      post_content: html,
      post_excerpt: excerpt,
      post_type: 'post',
      meta: {}
    };
  }

  function showMsg(text){
    var $msg = $('#ppa-toolbar-msg');
    if($msg.length){
      $msg.text(text || '');
    }
  }

  function extractErrorMessage(err){
    if(!err) return 'Unknown error.';
    if(typeof err === 'string') return err;
    if(err.message) return err.message;
    if(err.response && err.response.message) return err.response.message;
    if(err.data && err.data.message) return err.data.message;

    try {
      return JSON.stringify(err);
    } catch (e) {
      return 'Unknown error.';
    }
  }

  $(function(){
    if(!isComposer()) return;

    var $select = $('#postpress-ai-target-site');
    if(!$select.length) return;

    var current = getCurrentSite($select);
    var confirmedTargets = {};

    var $currentOpt = $select.find('option[value="current"]').first();
    setOptionData($currentOpt, current);
    setHeader(current);

    $select.off('change.ppaRemoteDrafts').on('change.ppaRemoteDrafts', function(){
      var site = siteFromOption($(this).find('option:selected'), current);
      setHeader(site || current);
    });

    apiGetSites()
      .then(function(sites){
        if(!Array.isArray(sites)) return;

        var currentHost = getDomain(win.location.origin);

        $select.find('option').not('[value="current"]').remove();

        sites.forEach(function(raw){
          var site = normalizeSite(raw);

          if(site.is_current){
            current = site;
            current.value = 'current';
            setOptionData($currentOpt, current);
            return;
          }

          if(!site.site_id) return;
          if(site.domain && site.domain === currentHost) return;

          var $opt = $('<option>').val(site.site_id);
          setOptionData($opt, site);
          $opt.appendTo($select);
        });

        setHeader(siteFromOption($select.find('option:selected'), current) || current);
      })
      .catch(function(err){
        console.warn('PPA remote drafts: /sites failed', err);
      });

    $(doc).off('click.ppaRemoteDrafts').on('click.ppaRemoteDrafts', '#ppa-draft, #postpress-ai-save-draft, #ppa-store', function(e){
      var targetValue = String($select.val() || 'current');

      if(targetValue === 'current'){
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();

      var site = siteFromOption($select.find('option:selected'), current);
      if(!site || !site.site_id){
        showMsg('Remote save failed. Selected target site could not be resolved for Site ID ' + targetValue + '.');
        return false;
      }

      var payload = collectPayload();

      if(!payload.post_content){
        showMsg('Generate Preview first, then Save Draft (Store).');
        return false;
      }

      if(!confirmedTargets[targetValue]){
        var approved = win.confirm('Save this draft to ' + site.label + '?');
        if(!approved){
          showMsg('Remote save canceled for ' + site.label + '.');
          return false;
        }
        confirmedTargets[targetValue] = true;
      }

      showMsg('Saving draft to ' + site.label + '...');

      apiRemoteDraft(payload, targetValue)
        .then(function(resp){
          var message = (resp && resp.message) ? resp.message : ('Draft saved to ' + site.label + '.');
          showMsg(message);

          var editLink = '';
          if(resp && resp.remote_post && resp.remote_post.edit_link){
            editLink = resp.remote_post.edit_link;
          } else if(resp && resp.edit_link){
            editLink = resp.edit_link;
          }

          if(editLink){
            win.open(editLink, '_blank', 'noopener');
          }
        })
        .catch(function(err){
          var message = extractErrorMessage(err);
          console.warn('PPA remote drafts: remote save failed', err);
          showMsg('Remote save to ' + site.label + ' failed: ' + message);
        });

      return false;
    });

    console.log('PPA remote drafts: explicit target identity ready');
  });

})(jQuery, window, document);

JS

php -l inc/class-postpress-ai-remote-drafts.php

diff -u .ppa-backups/"$stamp"/class-postpress-ai-remote-drafts.php.bak inc/class-postpress-ai-remote-drafts.php | sed -n '1,220p'
diff -u .ppa-backups/"$stamp"/postpress-ai-remote-drafts.js.bak assets/js/postpress-ai-remote-drafts.js | sed -n '1,220p'
