<?php
namespace PPA\Ajax;

defined( 'ABSPATH' ) || exit;

// Define namespaced marker() if not present to avoid redeclare fatal
if ( ! function_exists( __NAMESPACE__ . '\marker' ) ) {
    function marker() {
        if ( function_exists( 'error_log' ) ) {
            error_log( 'PPA\\Ajax\\marker() called' );
        }
        return 'marker-ok';
    }
}
