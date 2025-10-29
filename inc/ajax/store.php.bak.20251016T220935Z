<?php
namespace PPA\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Handle saving a draft post for PostPress AI.
 */
if ( ! function_exists( __NAMESPACE__ . '\save_draft' ) ) {
    /**
     * Save a draft post.
     *
     * @param array $data POST data from AJAX.
     * @return array JSON-friendly response.
     */
    function save_draft( $data = array() ) {
        // Capability check
        if ( ! current_user_can( 'edit_posts' ) ) {
            return array( 'ok' => false, 'error' => 'no-permission' );
        }

        // Nonce validation
        $nonce = isset( $data['_ajax_nonce'] ) ? sanitize_text_field( $data['_ajax_nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'ppa_admin_nonce' ) ) {
            return array( 'ok' => false, 'error' => 'invalid-nonce' );
        }

        $title  = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
        $prompt = isset( $data['prompt'] ) ? sanitize_textarea_field( $data['prompt'] ) : '';

        if ( $title === '' ) {
            return array( 'ok' => false, 'error' => 'missing-title' );
        }

        // Create draft post
        $postarr = array(
            'post_title'   => $title,
            'post_content' => $prompt,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        );

        $post_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $post_id ) ) {
            return array( 'ok' => false, 'error' => 'insert-failed', 'message' => $post_id->get_error_message() );
        }

        return array(
            'ok'      => true,
            'post_id' => $post_id,
            'title'   => $title,
            'prompt'  => $prompt,
        );
    }
}
