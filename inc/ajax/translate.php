<?php
/**
 * PostPress AI — AJAX: Translate Preview (WP proxy → Django /translate/)
 *
 * Purpose:
 * - Handles admin-ajax.php?action=ppa_translate_preview
 * - Forwards the already-generated preview content to Django /translate/
 * - Returns translated preview payload (HTML and/or JSON) back to the JS layer
 *
 * Security:
 * - Admin-only by default (logged-in)
 * - Capability check: edit_posts (safe for authors/editors using Composer)
 *
 * Notes:
 * - We DO NOT generate a new article here. This is translation only.
 * - We pass through payload shapes (original_json or original_html) to Django.
 * - Django is expected to enforce auth, rate-limit, and server-side caching keyed by (draft_hash, lang, mode).
 */

namespace PPA\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_read_json_body' ) ) {
	/**
	 * Read JSON body from php://input.
	 *
	 * @return array
	 */
	function ppa_read_json_body() {
		$raw = file_get_contents( 'php://input' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\ppa_get_django_base_url' ) ) {
	/**
	 * Try to find Django base URL from known plugin constants/options.
	 * Keep this flexible to avoid coupling to one naming scheme.
	 *
	 * @return string
	 */
	function ppa_get_django_base_url() {
		// Preferred constants (if your plugin defines them).
		if ( defined( 'PPA_DJANGO_URL' ) && is_string( PPA_DJANGO_URL ) && PPA_DJANGO_URL ) {
			return rtrim( PPA_DJANGO_URL, '/' );
		}
		if ( defined( 'PPA_SERVER_URL' ) && is_string( PPA_SERVER_URL ) && PPA_SERVER_URL ) {
			return rtrim( PPA_SERVER_URL, '/' );
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
	 * Matches your Settings rule: Connection Key (legacy) if present, else License Key.
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

if ( ! function_exists( __NAMESPACE__ . '\\translate_preview' ) ) {
	/**
	 * AJAX handler: ppa_translate_preview
	 *
	 * Input (JSON):
	 * - lang (string) e.g. "es"
	 * - mode (string) "strict" (future: "natural")
	 * - draft_hash (string) stable hash of the generated draft
	 * - original_json (object|null) structured preview contract if available
	 * - original_html (string|null) fallback if structured JSON not available
	 *
	 * Output:
	 * - Pass-through JSON from Django (expected to include ok:true + html + normalized fields)
	 */
	function translate_preview() {
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

		// Build payload for Django. We pass through both shapes; Django decides what to use.
		$payload = array(
			'lang'         => $lang,
			'mode'         => $mode ? $mode : 'strict',
			'draft_hash'   => $drafthash,
			'site_url'     => home_url(),
			'original_json'=> isset( $body['original_json'] ) ? $body['original_json'] : null,
			'original_html'=> isset( $body['original_html'] ) ? (string) $body['original_html'] : null,
		);

		$endpoint = rtrim( $django, '/' ) . '/translate/';

		// Send request to Django.
		$args = array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'      => 'application/json',
				// Send multiple header names so Django can accept whichever it already supports.
				'Authorization'     => 'Bearer ' . $key,
				'X-PPA-Key'         => $key,
				'X-PostPress-Key'   => $key,
				'X-PPA-Site'        => home_url(),
			),
			'body'    => wp_json_encode( $payload ),
		);

		$res = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $res ) ) {
			wp_send_json(
				array(
					'ok'    => false,
					'error' => 'upstream_request_failed',
					'msg'   => $res->get_error_message(),
				),
				502
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );

		$decoded = json_decode( $raw, true );

		// If upstream returns non-JSON, surface safely.
		if ( ! is_array( $decoded ) ) {
			wp_send_json(
				array(
					'ok'    => false,
					'error' => 'upstream_invalid_json',
					'code'  => $code,
				),
				502
			);
		}

		// Preserve upstream HTTP status if it's an error.
		if ( $code >= 400 ) {
			$decoded['ok']   = false;
			$decoded['code'] = $code;
			wp_send_json( $decoded, $code );
		}

		// Success pass-through
		if ( ! isset( $decoded['ok'] ) ) {
			$decoded['ok'] = true;
		}

		wp_send_json( $decoded, 200 );
	}
}

// Register handler (admin only).
add_action( 'wp_ajax_ppa_translate_preview', __NAMESPACE__ . '\\translate_preview' );
