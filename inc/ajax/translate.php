<?php
/**
 * PostPress AI — AJAX: Translate Preview (WP proxy → Django /translate/)
 *
 * Purpose:
 * - Handles admin-ajax.php?action=ppa_translate_preview
 * - Forwards ALREADY-GENERATED preview content to Django /translate/
 * - Returns translated preview payload (HTML and/or JSON) back to the JS layer
 *
 * Core Fix (2026-01-23):
 * - Bulletproof against large payloads (WAF/timeouts/empty reply) by sending minimal payload when needed.   // CHANGED:
 * - Adds deep debug fields when wp_remote_post() returns WP_Error (502) so we can see the REAL cause.      // CHANGED:
 * - Hardens JSON encoding/decoding so "invalid json" becomes a clean structured JSON error.                // CHANGED:
 *
 * Notes:
 * - Translation-only. No new article generation here.
 * - Django should ideally cache original content by draft_hash during /generate/ so translate can be hash-only.
 *
 * ========= CHANGE LOG =========
 * 2026-01-23
 * - FIX: Prevent upstream timeout / empty reply on large payloads:
 *        If original_html/original_json are too large, DO NOT send them; send hash-only.                  // CHANGED:
 * - HARDEN: Always return JSON from WP proxy, even when upstream returns HTML or encoding fails.          // CHANGED:
 * - DEBUG: Add payload sizing + wp_error code/message + raw snippet for upstream_invalid_json.            // CHANGED:
 * - HARDEN: Disable "Expect: 100-continue" behavior (some hosts/proxies choke on it).                    // CHANGED:
 *
 * 2026-01-24
 * - FIX: Poll requests were failing with "Missing original_html" (400) causing stalls (e.g., stuck at 6%). // CHANGED:
 *        Now the proxy persists original_html per draft_hash (transient) on the first request and re-injects it for polling requests (job_id present) when the client intentionally omits original_html. // CHANGED:
 *        Also stores job_id→draft_hash mapping once Django returns job_id, for resiliency.                 // CHANGED:
 */

namespace PPA\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_read_json_body' ) ) {
        /**
         * Read JSON body from php://input.
         * Falls back to $_POST if request isn't JSON.                                                        // CHANGED:
         *
         * @return array
         */
        function ppa_read_json_body() {
                $raw = file_get_contents( 'php://input' );
                if ( is_string( $raw ) && trim( $raw ) !== '' ) {
                        $decoded = json_decode( $raw, true );
                        if ( is_array( $decoded ) ) {
                                return $decoded;
                        }
                }

                // CHANGED: fallback for form-encoded requests
                if ( ! empty( $_POST ) && is_array( $_POST ) ) {
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        return wp_unslash( $_POST );
                }

                return array();
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_get_django_base_url' ) ) {
        /**
         * Try to find Django base URL from known plugin constants/options.
         *
         * @return string
         */
        function ppa_get_django_base_url() {
                // Preferred constants (if your plugin defines them).
                if ( defined( 'PPA_DJANGO_URL' ) && is_string( PPA_DJANGO_URL ) && trim( PPA_DJANGO_URL ) !== '' ) {
                        return rtrim( trim( PPA_DJANGO_URL ), '/' );
                }
                if ( defined( 'PPA_SERVER_URL' ) && is_string( PPA_SERVER_URL ) && trim( PPA_SERVER_URL ) !== '' ) {
                        return rtrim( trim( PPA_SERVER_URL ), '/' );
                }

                // Common option keys (defensive).
                $candidates = array(
                        'ppa_django_url',
                        'ppa_server_url',
                        'postpress_ai_django_url',
                        'postpress_ai_server_url',
                );

                foreach ( $candidates as $key ) {
                        $val = get_option( $key, '' );
                        if ( is_string( $val ) && trim( $val ) !== '' ) {
                                return rtrim( trim( $val ), '/' );
                        }
                }

                return '';
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_get_auth_key' ) ) {
        /**
         * Get auth key used for server-to-server requests.
         * Matches Settings rule: Connection Key (legacy) if present, else License Key.
         *
         * @return string
         */
        function ppa_get_auth_key() {
                // Legacy connection key first (if present).
                $connection_keys = array(
                        'ppa_connection_key',
                        'postpress_ai_connection_key',
                );

                foreach ( $connection_keys as $k ) {
                        $v = get_option( $k, '' );
                        if ( is_string( $v ) && trim( $v ) !== '' ) {
                                return trim( $v );
                        }
                }

                // License key fallback.
                $license_keys = array(
                        'ppa_license_key',
                        'postpress_ai_license_key',
                        'ppa_activation_key',
                        'postpress_ai_activation_key',
                );

                foreach ( $license_keys as $k ) {
                        $v = get_option( $k, '' );
                        if ( is_string( $v ) && trim( $v ) !== '' ) {
                                return trim( $v );
                        }
                }

                return '';
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_force_ipv4_for_url' ) ) {
        /**
         * Force IPv4 for WP HTTP API cURL handle when URL matches.
         * Prevents "timeout with 0 bytes received" when IPv6 route is broken upstream.
         *
         * @param resource $handle
         * @param array    $r
         * @param string   $url
         * @return void
         */
        function ppa_force_ipv4_for_url( $handle, $r, $url ) { // CHANGED:
                if ( ! is_string( $url ) || $url === '' ) {
                        return;
                }

                // Only affect our Django host.
                if ( strpos( $url, 'apps.techwithwayne.com' ) === false ) {
                        return;
                }

                if ( defined( 'CURL_IPRESOLVE_V4' ) && function_exists( 'curl_setopt' ) ) {
                        @curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_transient_key' ) ) {
        /**
         * Create a stable, site-scoped transient key for translation state.                                    // CHANGED:
         *
         * @param string $type  Key type: 'hash', 'job', 'job2hash'.                                            // CHANGED:
         * @param string $id    draft_hash or job_id.                                                           // CHANGED:
         * @return string
         */
        function ppa_transient_key( $type, $id ) { // CHANGED:
                $type = is_string( $type ) ? $type : 'hash';
                $id   = is_string( $id ) ? $id : '';
                $blog = function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : '0';
                $site = function_exists( 'home_url' ) ? (string) home_url() : '';
                $salt = $blog . '|' . $site . '|' . $type . '|' . $id;
                return 'ppa_tr_' . $type . '_' . md5( $salt );
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_store_original_for_hash' ) ) {
        /**
         * Persist original_html by draft_hash so poll requests can re-inject it.                               // CHANGED:
         *
         * @param string $draft_hash
         * @param string $original_html
         * @return void
         */
        function ppa_store_original_for_hash( $draft_hash, $original_html ) { // CHANGED:
                if ( ! is_string( $draft_hash ) || trim( $draft_hash ) === '' ) {
                        return;
                }
                if ( ! is_string( $original_html ) || trim( $original_html ) === '' ) {
                        return;
                }
                $ttl = 2 * HOUR_IN_SECONDS; // plenty; translation budget is minutes                                 // CHANGED:
                set_transient( ppa_transient_key( 'hash', $draft_hash ), $original_html, $ttl );
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_store_original_for_job' ) ) {
        /**
         * Persist original_html by job_id for resiliency.                                                      // CHANGED:
         *
         * @param string $job_id
         * @param string $original_html
         * @return void
         */
        function ppa_store_original_for_job( $job_id, $original_html ) { // CHANGED:
                if ( ! is_string( $job_id ) || trim( $job_id ) === '' ) {
                        return;
                }
                if ( ! is_string( $original_html ) || trim( $original_html ) === '' ) {
                        return;
                }
                $ttl = 2 * HOUR_IN_SECONDS; // CHANGED:
                set_transient( ppa_transient_key( 'job', $job_id ), $original_html, $ttl );
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_store_job_to_hash' ) ) {
        /**
         * Persist job_id → draft_hash mapping once Django returns job_id.                                     // CHANGED:
         *
         * @param string $job_id
         * @param string $draft_hash
         * @return void
         */
        function ppa_store_job_to_hash( $job_id, $draft_hash ) { // CHANGED:
                if ( ! is_string( $job_id ) || trim( $job_id ) === '' ) {
                        return;
                }
                if ( ! is_string( $draft_hash ) || trim( $draft_hash ) === '' ) {
                        return;
                }
                $ttl = 2 * HOUR_IN_SECONDS; // CHANGED:
                set_transient( ppa_transient_key( 'job2hash', $job_id ), $draft_hash, $ttl );
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_get_original_for_job_or_hash' ) ) {
        /**
         * Retrieve original_html for a poll request.                                                          // CHANGED:
         * Tries job_id storage first, then job_id→hash, then hash storage.                                    // CHANGED:
         *
         * @param string $job_id
         * @param string $draft_hash
         * @return string
         */
        function ppa_get_original_for_job_or_hash( $job_id, $draft_hash ) { // CHANGED:
                $job_id    = is_string( $job_id ) ? trim( $job_id ) : '';
                $draft_hash = is_string( $draft_hash ) ? trim( $draft_hash ) : '';

                // 1) direct job_id -> original_html
                if ( $job_id !== '' ) {
                        $v = get_transient( ppa_transient_key( 'job', $job_id ) );
                        if ( is_string( $v ) && trim( $v ) !== '' ) {
                                return $v;
                        }

                        // 2) job_id -> draft_hash mapping
                        $mapped = get_transient( ppa_transient_key( 'job2hash', $job_id ) );
                        if ( is_string( $mapped ) && trim( $mapped ) !== '' ) {
                                $draft_hash = trim( $mapped );
                        }
                }

                // 3) draft_hash -> original_html
                if ( $draft_hash !== '' ) {
                        $v2 = get_transient( ppa_transient_key( 'hash', $draft_hash ) );
                        if ( is_string( $v2 ) && trim( $v2 ) !== '' ) {
                                return $v2;
                        }
                }

                return '';
        }
}

if ( ! function_exists( __NAMESPACE__ . '\\translate_preview' ) ) {
        /**
         * AJAX handler: ppa_translate_preview
         */
        function translate_preview() { if (function_exists('set_time_limit')) { @set_time_limit(320); }
                $start = microtime( true );

                // Basic auth: must be logged-in.
                if ( ! is_user_logged_in() ) {
                        wp_send_json(
                                array(
                                        'ok'    => false,
                                        'error' => 'not_logged_in',
                                ),
                                401
                        );
                }

                // Capability: keep Composer usable for editors/authors, but not public.
                if ( ! current_user_can( 'edit_posts' ) ) {
                        wp_send_json(
                                array(
                                        'ok'    => false,
                                        'error' => 'insufficient_permissions',
                                ),
                                403
                        );
                }

                $body = ppa_read_json_body();

                $lang      = isset( $body['lang'] ) ? sanitize_text_field( (string) $body['lang'] ) : '';
                $mode      = isset( $body['mode'] ) ? sanitize_text_field( (string) $body['mode'] ) : 'strict';
                $drafthash = isset( $body['draft_hash'] ) ? sanitize_text_field( (string) $body['draft_hash'] ) : '';
                $job_id    = isset( $body['job_id'] ) ? sanitize_text_field( (string) $body['job_id'] ) : '';  // CHANGED: Async polling support

                // Validate required.
                if ( $lang === '' || $lang === 'original' ) {
                        wp_send_json(
                                array(
                                        'ok'    => false,
                                        'error' => 'missing_or_invalid_lang',
                                ),
                                400
                        );
                }

                $django = ppa_get_django_base_url();
                if ( $django === '' ) {
                        wp_send_json(
                                array(
                                        'ok'    => false,
                                        'error' => 'missing_django_url',
                                ),
                                500
                        );
                }

                $key = ppa_get_auth_key();
                if ( $key === '' ) {
                        wp_send_json(
                                array(
                                        'ok'    => false,
                                        'error' => 'missing_auth_key',
                                ),
                                500
                        );
                }

                // -------------------------------
                // CHANGED: Payload sizing guards
                // -------------------------------
                $max_bytes = 250000; // Bumped to 250KB.        // CHANGED:

                $original_html = null;
                if ( isset( $body['original_html'] ) ) {
                        $original_html = (string) $body['original_html'];
                }

                $original_json = null;
                if ( isset( $body['original_json'] ) ) {
                        $original_json = $body['original_json']; // could be array/object
                }

                // CHANGED: Detect poll requests and re-inject original_html when omitted.
                $is_poll              = ( is_string( $job_id ) && trim( $job_id ) !== '' ); // CHANGED:
                $has_original_request = ( is_string( $original_html ) && trim( $original_html ) !== '' ); // CHANGED:
                $injected_original    = false; // CHANGED:
                $injected_source      = ''; // CHANGED:

                // Store original immediately when present (start call).
                if ( $has_original_request && is_string( $drafthash ) && trim( $drafthash ) !== '' ) { // CHANGED:
                        ppa_store_original_for_hash( $drafthash, $original_html ); // CHANGED:
                }
                if ( $has_original_request && $is_poll ) { // CHANGED:
                        ppa_store_original_for_job( $job_id, $original_html ); // CHANGED:
                }

                // If this is a poll request and client intentionally omitted original_html,
                // pull it from the transient cache so Django doesn't throw "Missing original_html".             // CHANGED:
                if ( $is_poll && ! $has_original_request ) { // CHANGED:
                        $cached = ppa_get_original_for_job_or_hash( $job_id, $drafthash ); // CHANGED:
                        if ( is_string( $cached ) && trim( $cached ) !== '' ) { // CHANGED:
                                $original_html        = $cached; // CHANGED:
                                $has_original_request = true; // CHANGED:
                                $injected_original    = true; // CHANGED:
                                $injected_source      = 'transient'; // CHANGED:
                        }
                }

                $orig_html_len = ( is_string( $original_html ) ? strlen( $original_html ) : 0 );
                $orig_json_len = 0;

                // IMPORTANT: encoding large arrays can be expensive; only measure if it's not huge-looking.
                // If it's an array/object, we estimate size by encoding but cap time by only doing it if small-ish. // CHANGED:
                if ( is_string( $original_json ) ) {
                        $orig_json_len = strlen( $original_json );
                } elseif ( is_array( $original_json ) || is_object( $original_json ) ) {
                        $tmp = wp_json_encode( $original_json );
                        $orig_json_len = is_string( $tmp ) ? strlen( $tmp ) : 0;
                }

                $send_original = true;
                $drop_reason   = null;

                // CHANGED: Treat empty original_html as "missing" (do not send empty string).
                if ( ! $has_original_request && ( ! $original_json || $original_json === '' ) ) { // CHANGED:
                        $send_original = false; // CHANGED:
                        $drop_reason   = 'missing_original_payload'; // CHANGED:
                }

                // If either component is too large, drop them and rely on Django cache by draft_hash.            // CHANGED:
                if ( $send_original && ( $orig_html_len > $max_bytes || $orig_json_len > $max_bytes ) ) {
                        $send_original = false;
                        $drop_reason   = 'payload_too_large_send_hash_only';
                }

                // Build payload for Django.
                $payload = array(
                        'lang'       => $lang,
                        'mode'       => ( $mode ? $mode : 'strict' ),
                        'draft_hash' => $drafthash,
                        'job_id'     => $job_id,  // CHANGED: Pass job_id for async polling
                        'site_url'   => home_url(),

                        // CHANGED: tell Django what we did
                        'meta'       => array(
                                'send_original'        => $send_original,
                                'drop_reason'          => $drop_reason,
                                'orig_html_len'        => $orig_html_len,
                                'orig_json_len'        => $orig_json_len,
                                'max_bytes'            => $max_bytes,
                                'is_poll'              => $is_poll, // CHANGED:
                                'injected_original'    => $injected_original, // CHANGED:
                                'injected_source'      => $injected_source, // CHANGED:
                        ),
                );

                if ( $send_original ) { // CHANGED:
                        $payload['original_json'] = $original_json;
                        $payload['original_html'] = $original_html;
                } else {
                        $payload['original_json'] = null;
                        $payload['original_html'] = null;
                }

                $endpoint = rtrim( $django, '/' ) . '/translate/';

                // Force IPv4 for this request only.
                add_action( 'http_api_curl', __NAMESPACE__ . '\\ppa_force_ipv4_for_url', 10, 3 ); // CHANGED:

                $encoded = wp_json_encode( $payload ); // CHANGED:
                if ( ! is_string( $encoded ) || $encoded === '' ) { // CHANGED:
                        remove_action( 'http_api_curl', __NAMESPACE__ . '\\ppa_force_ipv4_for_url', 10 ); // CHANGED:
                        wp_send_json(
                                array(
                                        'ok'    => false,
                                        'error' => 'payload_encode_failed',
                                        'msg'   => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'json_encode_failed',
                                        'meta'  => $payload['meta'],
                                ),
                                500
                        );
                }

                // Send request to Django.
                $args = array(
                        'timeout' => 300, // 5 minutes (aligned with backend budget)                                 // CHANGED:
                        'headers' => array(
                                'Content-Type'    => 'application/json',
                                'Authorization'   => 'Bearer ' . $key,
                                'X-PPA-Key'       => $key,
                                'X-PPA-Site'      => home_url(),
                                'Expect'          => '', // CHANGED: prevent 100-continue behavior on some hosts
                        ),
                        'body'    => $encoded,
                );

                $res = wp_remote_post( $endpoint, $args );

                // remove hook immediately so we don't affect other requests.
                remove_action( 'http_api_curl', __NAMESPACE__ . '\\ppa_force_ipv4_for_url', 10 ); // CHANGED:

                if ( is_wp_error( $res ) ) {
                        $elapsed_ms   = (int) round( ( microtime( true ) - $start ) * 1000 );
                        $err_code     = $res->get_error_code();
                        $err_msg      = $res->get_error_message();
                        $err_data     = $res->get_error_data();
                        $payload_size = strlen( $encoded );

                        wp_send_json(
                                array(
                                        'ok'            => false,
                                        'error'         => 'upstream_request_failed',
                                        'wp_error'      => true,
                                        'wp_err_code'   => $err_code,
                                        'wp_err_msg'    => $err_msg,
                                        'wp_err_data'   => is_scalar( $err_data ) ? $err_data : ( $err_data ? wp_json_encode( $err_data ) : null ),
                                        'endpoint'      => $endpoint,       // safe (no secrets)
                                        'elapsed_ms'    => $elapsed_ms,
                                        'payload_bytes' => $payload_size,
                                        'meta'          => $payload['meta'], // includes orig sizes + whether we dropped originals
                                ),
                                502
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $res );
                $raw  = (string) wp_remote_retrieve_body( $res );
                $ct   = (string) wp_remote_retrieve_header( $res, 'content-type' ); // CHANGED:

                $decoded = json_decode( $raw, true );

                // If upstream returns non-JSON, surface safely with a snippet (for debugging).
                if ( ! is_array( $decoded ) ) {
                        wp_send_json(
                                array(
                                        'ok'           => false,
                                        'error'        => 'upstream_invalid_json',
                                        'code'         => $code,
                                        'content_type' => $ct,
                                        'raw_len'      => strlen( $raw ),
                                        'raw_snippet'  => substr( $raw, 0, 600 ),
                                        'meta'         => $payload['meta'],
                                ),
                                502
                        );
                }

                // CHANGED: If Django returned a job_id, persist job_id→hash and job_id→original_html for poll resiliency.
                $up_job_id = ''; // CHANGED:
                if ( isset( $decoded['job_id'] ) ) { // CHANGED:
                        $up_job_id = sanitize_text_field( (string) $decoded['job_id'] ); // CHANGED:
                } elseif ( isset( $decoded['data']['job_id'] ) ) { // CHANGED:
                        $up_job_id = sanitize_text_field( (string) $decoded['data']['job_id'] ); // CHANGED:
                }
                if ( $up_job_id !== '' && is_string( $drafthash ) && trim( $drafthash ) !== '' ) { // CHANGED:
                        ppa_store_job_to_hash( $up_job_id, $drafthash ); // CHANGED:
                        // Only store original_html if we have it.
                        if ( is_string( $original_html ) && trim( $original_html ) !== '' ) { // CHANGED:
                                ppa_store_original_for_job( $up_job_id, $original_html ); // CHANGED:
                        }
                }

                // Preserve upstream HTTP status if it's an error.
                if ( $code >= 400 ) {
                        $decoded['ok']   = false;
                        $decoded['code'] = $code;
                        $decoded['meta'] = $payload['meta']; // CHANGED:
                        wp_send_json( $decoded, $code );
                }

                // Success pass-through
                if ( ! isset( $decoded['ok'] ) ) {
                        $decoded['ok'] = true;
                }

                // CHANGED: include meta so JS/devtools can see whether we dropped original payload.
                if ( ! isset( $decoded['meta'] ) ) {
                        $decoded['meta'] = $payload['meta'];
                }

                wp_send_json( $decoded, 200 );
        }
}

// Register handler (admin only).
add_action( 'wp_ajax_ppa_translate_preview', __NAMESPACE__ . '\\translate_preview' );
