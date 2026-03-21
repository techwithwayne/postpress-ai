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
	 * Per-request debug state for temporary remote draft instrumentation.
	 *
	 * @var array
	 */
	protected static $debug_request_context = [];

	/**
	 * Whether the shutdown logger has been registered for this PHP request.
	 *
	 * @var bool
	 */
	protected static $debug_shutdown_registered = false;

	/**
	 * Bootstrap.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'wp_ajax_ppa_remote_draft_from_composer', [ __CLASS__, 'ajax_remote_draft_from_composer' ] );
	}

	/**
	 * Start request-scoped debug logging for the remote draft flow.
	 */
	protected static function begin_debug_request( $flow, array $context = [] ) {
		if ( ! empty( self::$debug_request_context['active'] ) ) {
			return self::$debug_request_context['rid'];
		}

		$rid = 'ppa-rd-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );

		self::$debug_request_context = [
			'active'     => true,
			'flow'       => (string) $flow,
			'rid'        => $rid,
			'started_at' => microtime( true ),
		];

		self::register_debug_shutdown_handler();
		self::debug_log( 'request_started', $context );

		return $rid;
	}

	/**
	 * Whether temporary remote draft debug logging is active for this request.
	 */
	protected static function is_debug_request_active() {
		return ! empty( self::$debug_request_context['active'] );
	}

	/**
	 * Register a shutdown logger once per PHP request.
	 */
	protected static function register_debug_shutdown_handler() {
		if ( self::$debug_shutdown_registered ) {
			return;
		}

		register_shutdown_function( [ __CLASS__, 'debug_shutdown_handler' ] );
		self::$debug_shutdown_registered = true;
	}

	/**
	 * Shutdown logger so fatal/timeout style failures still leave a breadcrumb.
	 */
	public static function debug_shutdown_handler() {
		if ( ! self::is_debug_request_active() ) {
			return;
		}

		$elapsed_ms = 0;
		if ( isset( self::$debug_request_context['started_at'] ) ) {
			$elapsed_ms = (int) round( ( microtime( true ) - (float) self::$debug_request_context['started_at'] ) * 1000 );
		}

		$last_error  = error_get_last();
		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];

		if ( is_array( $last_error ) && isset( $last_error['type'] ) && in_array( (int) $last_error['type'], $fatal_types, true ) ) {
			self::debug_log(
				'shutdown_fatal',
				[
					'elapsed_ms'        => $elapsed_ms,
					'error_type'        => isset( $last_error['type'] ) ? (int) $last_error['type'] : null,
					'error_message'     => isset( $last_error['message'] ) ? (string) $last_error['message'] : '',
					'error_file'        => isset( $last_error['file'] ) ? (string) $last_error['file'] : '',
					'error_line'        => isset( $last_error['line'] ) ? (int) $last_error['line'] : 0,
					'connection_status' => function_exists( 'connection_status' ) ? (int) connection_status() : null,
				]
			);
		} else {
			self::debug_log(
				'shutdown',
				[
					'elapsed_ms'        => $elapsed_ms,
					'connection_status' => function_exists( 'connection_status' ) ? (int) connection_status() : null,
				]
			);
		}
	}

	/**
	 * Write one structured debug log line.
	 */
	protected static function debug_log( $stage, array $context = [] ) {
		if ( ! self::is_debug_request_active() ) {
			return;
		}

		$payload = [
			'flow'    => isset( self::$debug_request_context['flow'] ) ? self::$debug_request_context['flow'] : '',
			'context' => self::debug_sanitize_for_log( $context ),
		];

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			$json = print_r( $payload, true );
		}

		error_log(
			sprintf(
				'[PostPress AI Remote Draft][%s][%s] %s',
				isset( self::$debug_request_context['rid'] ) ? self::$debug_request_context['rid'] : 'no-rid',
				(string) $stage,
				(string) $json
			)
		);
	}

	/**
	 * Sanitize values before they hit the PHP error log.
	 */
	protected static function debug_sanitize_for_log( $value, $key = '' ) {
		$key_lower   = strtolower( (string) $key );
		$secret_keys = [
			'authorization',
			'backend_token',
			'license_key',
			'nonce',
			'password',
			'ppa_shared_key',
			'ppa_wp_shared_secret',
			'remote_draft_token',
			'secret',
			'shared_secret',
			'site_token',
			'token',
			'x_ppa_nonce',
			'x_wp_nonce',
		];

		foreach ( $secret_keys as $secret_key ) {
			if ( '' !== $key_lower && false !== strpos( $key_lower, $secret_key ) ) {
				return '[redacted]';
			}
		}

		if ( is_array( $value ) ) {
			$sanitized = [];
			foreach ( $value as $child_key => $child_value ) {
				$sanitized[ $child_key ] = self::debug_sanitize_for_log( $child_value, (string) $child_key );
			}
			return $sanitized;
		}

		if ( is_object( $value ) ) {
			return [ 'object_class' => get_class( $value ) ];
		}

		if ( is_bool( $value ) || is_null( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return self::debug_summarize_text( $value );
		}

		return $value;
	}

	/**
	 * Shorten long log strings and collapse whitespace.
	 */
	protected static function debug_summarize_text( $text, $limit = 220 ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( (string) $text );

		if ( strlen( $text ) > $limit ) {
			$text = substr( $text, 0, $limit - 3 ) . '...';
		}

		return $text;
	}

	/**
	 * Summarize remote draft params without dumping whole HTML payloads.
	 */
	protected static function debug_summarize_params( array $params ) {
		$post_content = isset( $params['post_content'] ) ? (string) $params['post_content'] : '';
		$title        = isset( $params['post_title'] ) ? (string) $params['post_title'] : '';
		$excerpt      = isset( $params['post_excerpt'] ) ? (string) $params['post_excerpt'] : '';
		$meta         = ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) ? $params['meta'] : [];

		return [
			'keys'                          => array_keys( $params ),
			'target_site_id'                => isset( $params['target_site_id'] ) ? (string) $params['target_site_id'] : '',
			'post_type'                     => isset( $params['post_type'] ) ? (string) $params['post_type'] : '',
			'post_title_len'                => strlen( $title ),
			'post_title_preview'            => self::debug_summarize_text( wp_strip_all_tags( $title ), 120 ),
			'post_excerpt_len'              => strlen( $excerpt ),
			'post_content_len'              => strlen( $post_content ),
			'post_content_preview'          => self::debug_summarize_text( wp_strip_all_tags( $post_content ), 180 ),
			'post_content_has_preview_wrap' => ( false !== strpos( $post_content, 'class="ppa-preview"' ) ),
			'post_content_has_outline_label'=> ( false !== stripos( $post_content, '>Outline<' ) || false !== stripos( $post_content, 'Outline</' ) ),
			'post_content_has_body_heading' => ( false !== stripos( $post_content, '>Body<' ) || false !== stripos( $post_content, 'Body</' ) ),
			'post_content_has_meta_heading' => ( false !== stripos( $post_content, '>Meta<' ) || false !== stripos( $post_content, 'Meta</' ) ),
			'meta_keys'                     => array_keys( $meta ),
			'meta_count'                    => count( $meta ),
		];
	}

	/**
	 * Summarize backend relay payload for safe logging.
	 */
	protected static function debug_summarize_backend_payload( array $data ) {
		$summary = [
			'keys'                => array_keys( $data ),
			'license_key_present' => ! empty( $data['license_key'] ),
			'source_site_id'      => isset( $data['source_site_id'] ) ? (string) $data['source_site_id'] : '',
			'target_site_id'      => isset( $data['target_site_id'] ) ? (string) $data['target_site_id'] : '',
		];

		if ( isset( $data['post'] ) && is_array( $data['post'] ) ) {
			$summary['post'] = self::debug_summarize_params( $data['post'] );
		}

		return $summary;
	}

	/**
	 * Summarize a wp_remote_request response for logs.
	 */
	protected static function debug_summarize_http_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return [
				'type'       => 'wp_error',
				'code'       => $response->get_error_code(),
				'message'    => $response->get_error_message(),
				'error_data' => self::debug_sanitize_for_log( $response->get_error_data() ),
			];
		}

		$body = wp_remote_retrieve_body( $response );

		return [
			'type'         => is_array( $response ) ? 'http_response_array' : gettype( $response ),
			'http_code'    => (int) wp_remote_retrieve_response_code( $response ),
			'http_message' => wp_remote_retrieve_response_message( $response ),
			'body_len'     => strlen( (string) $body ),
			'body_snippet' => self::debug_summarize_text( wp_strip_all_tags( (string) $body ), 300 ),
			'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
		];
	}

	/**
	 * Summarize REST/AJAX response objects for logs.
	 */
	protected static function debug_summarize_response_value( $response ) {
		if ( is_wp_error( $response ) ) {
			return [
				'type'       => 'wp_error',
				'code'       => $response->get_error_code(),
				'message'    => $response->get_error_message(),
				'error_data' => self::debug_sanitize_for_log( $response->get_error_data() ),
			];
		}

		if ( $response instanceof WP_REST_Response ) {
			return [
				'type'   => 'wp_rest_response',
				'status' => $response->get_status(),
				'data'   => self::debug_sanitize_for_log( $response->get_data() ),
			];
		}

		if ( is_array( $response ) ) {
			return [
				'type' => 'array',
				'data' => self::debug_sanitize_for_log( $response ),
			];
		}

		return [
			'type'  => gettype( $response ),
			'value' => self::debug_sanitize_for_log( $response ),
		];
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
		self::debug_log( 'rest_remote_draft_from_composer_enter', [ 'request_route' => $request->get_route() ] );

		$params = self::get_request_params( $request );
		self::debug_log( 'rest_remote_draft_from_composer_params', self::debug_summarize_params( $params ) );

		$target_site_id = isset( $params['target_site_id'] ) ? trim( sanitize_text_field( (string) $params['target_site_id'] ) ) : '';
		self::debug_log( 'rest_remote_draft_from_composer_target_site_id', [ 'target_site_id' => $target_site_id ] );

		if ( '' === $target_site_id || 'current' === $target_site_id ) {
			self::debug_log( 'rest_remote_draft_from_composer_invalid_target', [ 'target_site_id' => $target_site_id ] );
			return new WP_Error( 'bad_target', __( 'Invalid target site selected.', 'postpress-ai' ), [ 'status' => 400 ] );
		}

		$target_site = self::get_connected_site_by_id( $target_site_id );
		self::debug_log( 'rest_remote_draft_from_composer_target_site_lookup', self::debug_summarize_response_value( $target_site ) );
		if ( is_wp_error( $target_site ) ) {
			$status = 500;
			$data   = $target_site->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}

			return new WP_Error( $target_site->get_error_code(), $target_site->get_error_message(), [ 'status' => $status ] );
		}

		$target_label = self::get_site_identity_string( $target_site );
		self::debug_log(
			'rest_remote_draft_from_composer_target_site_resolved',
			[
				'target_site_id' => $target_site_id,
				'target_label'   => $target_label,
				'target_site'    => $target_site,
			]
		);

		$post = [
			'post_title'   => isset( $params['post_title'] ) ? (string) $params['post_title'] : '',
			'post_content' => isset( $params['post_content'] ) ? (string) $params['post_content'] : '',
			'post_excerpt' => isset( $params['post_excerpt'] ) ? (string) $params['post_excerpt'] : '',
			'post_type'    => isset( $params['post_type'] ) ? (string) $params['post_type'] : 'post',
			'meta'         => isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : [],
		];
		self::debug_log( 'rest_remote_draft_from_composer_post_summary', self::debug_summarize_params( $post ) );

		$license_key = trim( (string) get_option( 'postpress_ai_license_key' ) );
		self::debug_log( 'rest_remote_draft_from_composer_license_state', [ 'license_key_present' => ( '' !== $license_key ) ] );
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
		self::debug_log( 'rest_remote_draft_from_composer_source_site_before_heal', [ 'source_site_id' => $source_site_id ] );
		if ( '' === $source_site_id ) {
			self::debug_log( 'rest_remote_draft_from_composer_source_site_self_heal_start' );
			self::ensure_source_site_registration();
			$source_site_id = trim( (string) get_option( 'postpress_ai_site_id' ) );
			self::debug_log( 'rest_remote_draft_from_composer_source_site_self_heal_end', [ 'source_site_id' => $source_site_id ] );
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

		self::debug_log(
			'rest_remote_draft_from_composer_backend_request_before',
			[
				'path'    => '/remote-drafts/create/',
				'payload' => self::debug_summarize_backend_payload(
					[
						'license_key'    => $license_key,
						'source_site_id' => $source_site_id,
						'target_site_id' => $target_site_id,
						'post'           => $post,
					]
				),
			]
		);

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

		self::debug_log( 'rest_remote_draft_from_composer_backend_request_after', self::debug_summarize_response_value( $backend_response ) );

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

		$backend_raw_body = wp_remote_retrieve_body( $backend_response );
		self::debug_log(
			'rest_remote_draft_from_composer_backend_body_before_decode',
			[
				'body_len'     => strlen( (string) $backend_raw_body ),
				'body_snippet' => self::debug_summarize_text( wp_strip_all_tags( (string) $backend_raw_body ), 300 ),
			]
		);

		$body = json_decode( $backend_raw_body, true );
		self::debug_log( 'rest_remote_draft_from_composer_backend_body_after_decode', [ 'json_decode_success' => is_array( $body ) ] );

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

			self::debug_log( 'rest_remote_draft_from_composer_backend_non_ok_body', [ 'body' => $body ] );

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

		self::debug_log( 'rest_remote_draft_from_composer_success', [ 'body' => $body ] );

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
		$skip     = [ 'action', 'nonce', '_wpnonce', '_ajax_nonce', '_wp_http_referer' ];

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
		self::begin_debug_request(
			'ajax_remote_draft_from_composer',
			[
				'action'         => isset( $_REQUEST['action'] ) ? (string) wp_unslash( $_REQUEST['action'] ) : '',
				'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
				'uri'            => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
			]
		);

		try {
			self::debug_log(
				'ajax_auth_check',
				[
					'is_user_logged_in' => is_user_logged_in(),
					'can_edit_posts'    => current_user_can( 'edit_posts' ),
				]
			);

			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				self::debug_log( 'ajax_forbidden' );
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

			self::debug_log(
				'ajax_nonce_check',
				[
					'nonce_present' => ( '' !== $nonce ),
					'nonce_valid'   => (bool) $valid,
				]
			);

			if ( ! $valid ) {
				self::debug_log( 'ajax_invalid_nonce' );
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
			self::debug_log( 'ajax_params_parsed', self::debug_summarize_params( $params ) );

			self::debug_log( 'ajax_before_synthetic_rest_request' );
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

			self::debug_log( 'ajax_before_rest_remote_draft_from_composer' );
			$response = self::rest_remote_draft_from_composer( $request );
			self::debug_log( 'ajax_after_rest_remote_draft_from_composer', self::debug_summarize_response_value( $response ) );

			if ( is_wp_error( $response ) ) {
				$status = 500;
				$edata  = $response->get_error_data();
				if ( is_array( $edata ) && isset( $edata['status'] ) ) {
					$status = (int) $edata['status'];
				}

				self::debug_log(
					'ajax_sending_error_json',
					[
						'status'  => $status,
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
						'data'    => $edata,
					]
				);

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
				self::debug_log( 'ajax_sending_wp_rest_response', [ 'status' => $response->get_status() ] );
				wp_send_json( $response->get_data(), $response->get_status() );
			}

			self::debug_log( 'ajax_sending_array_response', [ 'status' => 200 ] );
			wp_send_json( $response, 200 );
		} catch ( Throwable $e ) {
			self::debug_log(
				'ajax_throwable',
				[
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				]
			);

			wp_send_json(
				[
					'code'    => 'remote_draft_exception',
					'message' => __( 'Remote draft failed before WordPress could finish the request.', 'postpress-ai' ),
					'data'    => [ 'status' => 500 ],
				],
				500
			);
		}
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

		$identity = implode( ' - ', array_slice( $parts, 0, 2 ) );
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
			$label .= ' - ' . $domain;
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
			self::debug_log( 'backend_request_missing_api_base', [ 'path' => $path ] );
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

		self::debug_log(
			'backend_request_before_wp_remote_request',
			[
				'method'          => $args['method'],
				'path'            => $path,
				'url'             => $url,
				'timeout'         => $args['timeout'],
				'body_len'        => isset( $args['body'] ) ? strlen( (string) $args['body'] ) : 0,
				'payload_summary' => is_array( $data ) ? self::debug_summarize_backend_payload( $data ) : [],
			]
		);

		$response = wp_remote_request( $url, $args );
		self::debug_log( 'backend_request_after_wp_remote_request', self::debug_summarize_http_response( $response ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			$msg  = self::compact_backend_error_message( $code, $body );

			self::debug_log(
				'backend_request_non_2xx',
				[
					'http_code'    => $code,
					'compact_msg'  => $msg,
					'body_len'     => strlen( (string) $body ),
					'body_snippet' => self::debug_summarize_text( wp_strip_all_tags( (string) $body ), 300 ),
				]
			);

			return new WP_Error(
				'backend_http_error',
				$msg,
				[
					'status'       => $code,
					'backend_code' => $code,
					'backend_raw'  => $body,
					'backend_msg'  => $msg,
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