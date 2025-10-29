<?php
/**
 * Plugin Name: PostPress AI
 * Plugin URI:  https://techwithwayne.com/
 * Description: Minimal PostPress AI skeleton (rebuild). Add features file-by-file.
 * Version:     0.1.0
 * Author:      Tech With Wayne
 */

defined( 'ABSPATH' ) || exit;

// Constants
if ( ! defined( 'PPA_PLUGIN_DIR' ) ) {
    define( 'PPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_URL' ) ) {
    define( 'PPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Light debug
if ( function_exists( 'error_log' ) ) {
    error_log( 'PPA: postpress-ai plugin loaded' );
}

// Include helpers and ajax stubs (if present)
if ( file_exists( PPA_PLUGIN_DIR . 'inc/helpers.php' ) ) {
    require_once PPA_PLUGIN_DIR . 'inc/helpers.php';
}
if ( file_exists( PPA_PLUGIN_DIR . 'inc/ajax/marker.php' ) ) {
    require_once PPA_PLUGIN_DIR . 'inc/ajax/marker.php';
}
if ( file_exists( PPA_PLUGIN_DIR . 'inc/ajax/preview.php' ) ) {
    require_once PPA_PLUGIN_DIR . 'inc/ajax/preview.php';
}
if ( file_exists( PPA_PLUGIN_DIR . 'inc/ajax/store.php' ) ) {
    require_once PPA_PLUGIN_DIR . 'inc/ajax/store.php';
}
if ( file_exists( PPA_PLUGIN_DIR . 'inc/admin/composer.php' ) ) {
    require_once PPA_PLUGIN_DIR . 'inc/admin/composer.php';
}
// Load admin enqueue helpers
if ( file_exists( PPA_PLUGIN_DIR . 'inc/admin/enqueue.php' ) ) {
    require_once PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';
}


// Admin menu
add_action( 'admin_menu', function() {
    add_menu_page(
        'PostPress AI',
        'PostPress AI',
        'manage_options',
        'postpress-ai',
        'ppa_render_composer_page'
    );
} );

// Enqueue admin assets and localize AJAX
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'postpress-ai' ) === false ) {
        return;
    }
    // --- Version rotators for JS & CSS (use filemtime fallback to time) ---
$ppa_js_file  = PPA_PLUGIN_DIR . 'assets/js/admin.js';
$ppa_css_file = PPA_PLUGIN_DIR . 'assets/css/admin.css';

$ppa_js_ver  = file_exists( $ppa_js_file )  ? filemtime( $ppa_js_file )  : time();
$ppa_css_ver = file_exists( $ppa_css_file ) ? filemtime( $ppa_css_file ) : time();

wp_enqueue_script( 'ppa-admin-js', PPA_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $ppa_js_ver, true );

wp_localize_script(
    'ppa-admin-js',
    'PPA_AJAX',
    array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'ppa_admin_nonce' ),
    )
);

wp_enqueue_style( 'ppa-admin-css', PPA_PLUGIN_URL . 'assets/css/admin.css', array(), $ppa_css_ver );

    wp_localize_script(
    'ppa-admin-js',
    'PPA_AJAX',
    array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ppa_admin_nonce'),
    )
);
    wp_localize_script( 'ppa-admin-js', 'PPA_AJAX', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'ppa_admin_nonce' ),
    ) );
} );

// AJAX endpoint
add_action( 'wp_ajax_ppa_preview', function() {
    if ( ! check_ajax_referer( 'ppa_admin_nonce', '_ajax_nonce', false ) ) {
        wp_send_json( array( 'ok' => false, 'error' => 'invalid-nonce' ) );
    }

    if ( function_exists( 'PPA\\Ajax\\generate_preview' ) ) {
        $res = call_user_func( 'PPA\\Ajax\\generate_preview', $_POST );
        if ( is_wp_error( $res ) ) {
            wp_send_json( array( 'ok' => false, 'error' => $res->get_error_code(), 'message' => $res->get_error_message() ) );
        }
        wp_send_json( $res );
    } else {
        wp_send_json( array( 'ok' => false, 'error' => 'missing-handler-generate_preview' ) );
    }
} );

add_action( 'wp_ajax_ppa_save_draft', function() {
    if ( ! check_ajax_referer( 'ppa_admin_nonce', '_ajax_nonce', false ) ) {
        wp_send_json( array( 'ok' => false, 'error' => 'invalid-nonce' ) );
    }

    if ( function_exists( 'PPA\\Ajax\\save_draft' ) ) {
        $res = call_user_func( 'PPA\\Ajax\\save_draft', $_POST );
        wp_send_json( $res );
    } else {
        wp_send_json( array( 'ok' => false, 'error' => 'missing-handler-save_draft' ) );
    }
});

// Composer page callback

// Health check for WP-CLI
function ppa_health_check() {
    return array(
        'ok'      => true,
        'plugin'  => 'postpress-ai',
        'message' => 'skeleton loaded',
    );
}
