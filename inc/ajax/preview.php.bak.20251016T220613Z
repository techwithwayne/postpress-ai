<?php
namespace PPA\Ajax;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\generate_preview' ) ) {
    function generate_preview( $data = array() ) {
        if ( ! is_array( $data ) ) {
            $data = (array) $data;
        }

        $title = isset( $data['title'] ) ? $data['title'] : ( isset( $data['ppa_title'] ) ? $data['ppa_title'] : '' );
        if ( trim( $title ) === '' ) {
            return array( 'ok' => false, 'error' => 'missing-title' );
        }

        $html = '<h2>Preview: ' . esc_html( $title ) . '</h2><p>Auto-generated preview content (stub).</p>';
        return array( 'ok' => true, 'html' => $html );
    }
}
