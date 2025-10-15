<?php
/**
 * PPA Controller — Server-side AJAX proxy for PostPress AI
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-controller.php
 *
 * CHANGE LOG
 * -----------
 * 2025-10-12 • Added server-side preview and store proxy endpoints to forward requests
 *            • Uses X-PPA-Key header when calling the Django service
 *            • Returns Django JSON through WP's ajax response (wp_send_json_*)
 *            • Minimal capability check: current_user_can('edit_posts')
 *            • Non-invasive: does not alter plugin settings or DB
 * // CHANGED:
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Controller' ) ) {

	class PPA_Controller {

		/**
		 * Register AJAX hooks.
		 * Only admin (logged-in) endpoints are required for preview/store.
		 */
		public static function init() {
			// Logged-in admin AJAX endpoints
			add_action( 'wp_ajax_ppa_preview', array( __CLASS__, 'ajax_preview' ) ); // CHANGED:
			add_action( 'wp_ajax_ppa_store', array( __CLASS__, 'ajax_store' ) );     // CHANGED:
		}

		/**
		 * Server-side proxy to Django /preview/ endpoint.
		 * Expects JSON body (php://input) or a POST 'payload' param.
		 */
		public static function ajax_preview() {
			// Capability guard
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			}

			// Read incoming payload (prefer raw JSON)
			$body = file_get_contents( 'php://input' );
			if ( empty( $body ) ) {
				$body = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '{}';
			}

			$django_base = rtrim( get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' ), '/' ); // CHANGED:
			$django_url  = $django_base . '/preview/'; // CHANGED:
			$shared_key  = get_option( 'ppa_shared_key', '' ); // CHANGED:

			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-PPA-Key'    => $shared_key, // CHANGED:
				),
				'body'    => $body,
				'timeout' => 30,
			);

			$response = wp_remote_post( $django_url, $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'error'  => 'request_failed',
						'detail' => $response->get_error_message(),
					),
					500
				);
			}

			$code      = intval( wp_remote_retrieve_response_code( $response ) );
			$resp_body = wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				// If Django returned non-JSON, pass it back in a 'raw' key
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			// Normal: pass through Django JSON as success payload
			wp_send_json_success( $json, $code );
		}

		/**
		 * Server-side proxy to Django /store/ endpoint.
		 * Accepts the final payload from admin and forwards to Django for persistence.
		 */
		public static function ajax_store() {
			// Capability guard
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			}

			$body = file_get_contents( 'php://input' );
			if ( empty( $body ) ) {
				$body = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '{}';
			}

			$django_base = rtrim( get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' ), '/' ); // CHANGED:
			$django_url  = $django_base . '/store/'; // CHANGED:
			$shared_key  = get_option( 'ppa_shared_key', '' ); // CHANGED:

			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-PPA-Key'    => $shared_key, // CHANGED:
				),
				'body'    => $body,
				'timeout' => 30,
			);

			$response = wp_remote_post( $django_url, $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'error'  => 'request_failed',
						'detail' => $response->get_error_message(),
					),
					500
				);
			}

			$code      = intval( wp_remote_retrieve_response_code( $response ) );
			$resp_body = wp_remote_retrieve_body( $response );

			$json = json_decode( $resp_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_success( array( 'raw' => $resp_body ), $code );
			}

			wp_send_json_success( $json, $code );
		}
	} // end class PPA_Controller

	// Initialize hooks (non-invasive)
	add_action( 'init', array( 'PPA_Controller', 'init' ) ); // CHANGED:
}
