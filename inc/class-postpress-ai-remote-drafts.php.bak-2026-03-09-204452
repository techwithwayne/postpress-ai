<?php
/**
 * Remote drafts + connected sites REST API.
 *
 * - Exposes a sites list for the Composer dropdown.
 * - Handles "save draft to remote site" from the Composer.
 * - Provides endpoints for the backend (Django) to create drafts directly.
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

        // For backend handshake (Django → site).
        register_rest_route(
            'postpress-ai/v1',
            '/site-handshake',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'rest_site_handshake' ],
                'permission_callback' => '__return_true',
            ]
        );

        // For backend → site remote draft creation.
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
        $license_key = get_option( 'postpress_ai_license_key' );

        if ( empty( $license_key ) ) {
            return new WP_Error( 'no_license', __( 'License key not configured.', 'postpress-ai' ), [ 'status' => 400 ] );
        }

        // Call backend to get connected sites for this license.
        $backend_response = self::backend_request(
            'GET',
            '/api/license/sites',
            [
                'license_key' => $license_key,
            ]
        );

        if ( is_wp_error( $backend_response ) ) {
            return new WP_Error(
                'backend_error',
                $backend_response->get_error_message(),
                [ 'status' => 500 ]
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $backend_response ), true );

        // Backend returns: {"ok": true, "data": {"license_key": "...", "sites": [...]}, ...}
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

        $current_site_url = home_url();
        $current_site_id  = get_option( 'postpress_ai_site_id' );

        $result   = [];
        $result[] = [
            'site_id' => 'current',
            'name'    => __( 'This site', 'postpress-ai' ),
            'url'     => $current_site_url,
        ];

        foreach ( $sites as $site ) {
            // Expecting: ['id', 'name', 'url', 'status']
            if ( empty( $site['id'] ) || empty( $site['url'] ) ) {
                continue;
            }

            if ( (string) $site['id'] === (string) $current_site_id ) {
                continue;
            }

            if ( isset( $site['status'] ) && 'active' !== $site['status'] ) {
                continue;
            }

            $result[] = [
                'site_id' => (string) $site['id'],
                'name'    => isset( $site['name'] ) ? (string) $site['name'] : (string) $site['url'],
                'url'     => (string) $site['url'],
            ];
        }

        return rest_ensure_response( $result );
    }

    /**
     * POST /postpress-ai/v1/remote-draft-from-composer
     *
     * Browser → plugin → backend → remote site.
     */
    public static function rest_remote_draft_from_composer( WP_REST_Request $request ) {
        $params         = $request->get_json_params();
        $target_site_id = isset( $params['target_site_id'] ) ? sanitize_text_field( $params['target_site_id'] ) : '';

        if ( empty( $target_site_id ) || 'current' === $target_site_id ) {
            return new WP_Error( 'bad_target', __( 'Invalid target site selected.', 'postpress-ai' ), [ 'status' => 400 ] );
        }

        $post = [
            'post_title'   => isset( $params['post_title'] ) ? (string) $params['post_title'] : '',
            'post_content' => isset( $params['post_content'] ) ? (string) $params['post_content'] : '',
            'post_excerpt' => isset( $params['post_excerpt'] ) ? (string) $params['post_excerpt'] : '',
            'post_type'    => isset( $params['post_type'] ) ? (string) $params['post_type'] : 'post',
            'meta'         => isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : [],
        ];

        $license_key    = get_option( 'postpress_ai_license_key' );
        $source_site_id = get_option( 'postpress_ai_site_id' );

        if ( empty( $license_key ) || empty( $source_site_id ) ) {
            return new WP_Error(
                'missing_site_info',
                __( 'This site is not fully registered with PostPress AI.', 'postpress-ai' ),
                [ 'status' => 400 ]
            );
        }

        $backend_response = self::backend_request(
            'POST',
            '/api/remote-drafts/create',
            [
                'license_key'    => $license_key,
                'source_site_id' => $source_site_id,
                'target_site_id' => $target_site_id,
                // Backend expects "post", not "payload".
                'post'           => $post,
            ]
        );

        if ( is_wp_error( $backend_response ) ) {
            return new WP_Error(
                'remote_draft_failed',
                $backend_response->get_error_message(),
                [ 'status' => 500 ]
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $backend_response ), true );

        if ( ! is_array( $body ) ) {
            return new WP_Error(
                'remote_draft_failed',
                __( 'Invalid response from PostPress AI backend.', 'postpress-ai' ),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( $body );
    }

    /**
     * POST /postpress-ai/v1/site-handshake
     *
     * Backend confirms site + sets site_id and site_token.
     * Auth: global backend shared secret in header.
     */
    public static function rest_site_handshake( WP_REST_Request $request ) {
        $token = $request->get_header( 'x-postpress-ai-handshake' );

        if ( ! self::verify_backend_token( $token ) ) {
            return new WP_Error( 'forbidden', __( 'Invalid backend token.', 'postpress-ai' ), [ 'status' => 403 ] );
        }

        $site_id    = isset( $request['site_id'] ) ? sanitize_text_field( $request['site_id'] ) : '';
        $site_token = isset( $request['site_token'] ) ? sanitize_text_field( $request['site_token'] ) : '';

        if ( empty( $site_id ) || empty( $site_token ) ) {
            return new WP_Error(
                'bad_request',
                __( 'Missing site_id or site_token.', 'postpress-ai' ),
                [ 'status' => 400 ] 
            );
        }

        update_option( 'postpress_ai_site_id', $site_id );
        update_option( 'postpress_ai_site_token', $site_token );

        return [
            // Add "ok" flag so Django's resp_json.get("ok") passes.
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
     * Backend → site: actually create the WordPress draft here.
     */
    public static function rest_remote_draft( WP_REST_Request $request ) {
        $auth_header = $request->get_header( 'authorization' );

        if ( ! $auth_header || stripos( $auth_header, 'bearer ' ) !== 0 ) {
            return new WP_Error( 'forbidden', __( 'Missing authorization header.', 'postpress-ai' ), [ 'status' => 403 ] );
        }

        $token        = trim( substr( $auth_header, 7 ) );
        $stored_token = get_option( 'postpress_ai_site_token' );

        if ( ! $stored_token || ! hash_equals( (string) $stored_token, (string) $token ) ) {
            return new WP_Error( 'forbidden', __( 'Invalid remote draft token.', 'postpress-ai' ), [ 'status' => 403 ] );
        }

        $payload = $request->get_json_params();

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
     * Helper: call backend (Django).
     *
     * NOTE: Adjust POSTPRESS_AI_API_BASE / auth to match your existing integration.
     */
    protected static function backend_request( $method, $path, $data = [] ) {
        if ( ! defined( 'POSTPRESS_AI_API_BASE' ) ) {
            return new WP_Error( 'no_api_base', 'POSTPRESS_AI_API_BASE is not defined.' );
        }

        $url = trailingslashit( POSTPRESS_AI_API_BASE ) . ltrim( $path, '/' );

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ( 'GET' === strtoupper( $method ) ) {
            $url = add_query_arg( $data, $url );
        } else {
            $args['body'] = wp_json_encode( $data );
        }

        /**
         * If you already have a standard auth header for backend calls,
         * add it here (e.g., Authorization: Bearer <token>).
         */

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            $msg  = sprintf(
                'Backend error (%d): %s',
                $code,
                $body ? $body : 'Unknown error'
            );

            return new WP_Error( 'backend_http_error', $msg );
        }

        return $response;
    }

    /**
     * Verify backend shared token for handshake.
     *
     * Replace POSTPRESS_AI_BACKEND_SHARED_SECRET with your constant/option.
     */
    protected static function verify_backend_token( $token ) {
        if ( empty( $token ) ) {
            return false;
        }

        if ( defined( 'POSTPRESS_AI_BACKEND_SHARED_SECRET' ) ) {
            return hash_equals(
                (string) POSTPRESS_AI_BACKEND_SHARED_SECRET,
                (string) $token
            );
        }

        // If you store this in an option instead of a constant, adjust here.
        $stored = get_option( 'postpress_ai_backend_shared_secret' );
        if ( empty( $stored ) ) {
            return false;
        }

        return hash_equals( (string) $stored, (string) $token );
    }
}

// Bootstrap.
PostPress_AI_Remote_Drafts::init();