<?php
/**
 * CHANGE LOG
 * 2025-10-13 - v0.1.0
 * - CHANGED: New safe include to enqueue admin assets used by the Composer UI.
 * - CHANGED: Loads assets only when on Composer admin screens (screen id or page param).
 * - CHANGED: Localizes window.PPA (ajax_url, preview_action, store_action, plugin_version).
 * - CHANGED: Adds server-side error_log entries for quick verification.
 *
 * Author: Tech With Wayne / Assistant
 */

if ( ! defined( "ABSPATH" ) ) { exit; }

if ( ! function_exists( "ppa_admin_enqueue_assets" ) ) {
    function ppa_admin_enqueue_assets( $hook_suffix ) {
        if ( ! is_admin() ) { return; }
        $screen = null;
        if ( function_exists( "get_current_screen" ) ) { $screen = get_current_screen(); }
        $is_composer_screen = false;
        if ( $screen && isset( $screen->id ) ) {
            $composer_ids = array( "postpress-ai_page", "postpress-ai_page_composer", "postpress_ai", "postpress-ai", "postpress" );
            foreach ( $composer_ids as $frag ) {
                if ( false !== strpos( $screen->id, $frag ) ) { $is_composer_screen = true; break; }
            }
        }
        if ( ! $is_composer_screen && isset( $_GET["page"] ) ) {
            $page = sanitize_text_field( wp_unslash( $_GET["page"] ) );
            $composer_pages = array( "postpress-ai-composer", "postpress-ai_composer", "postpress-ai", "postpress_ai_composer" );
            if ( in_array( $page, $composer_pages, true ) ) { $is_composer_screen = true; }
        }
        if ( ! $is_composer_screen ) { return; }
        $possible_main = dirname( __FILE__, 3 ) . "/postpress-ai.php";
        if ( file_exists( $possible_main ) ) { $plugin_base_url = plugin_dir_url( $possible_main ); } else { $plugin_base_url = plugin_dir_url( __DIR__ . "/../../postpress-ai.php" ); }
        if ( defined( "PPA_PLUGIN_VERSION" ) ) { $version = PPA_PLUGIN_VERSION; } else { $js_file = WP_PLUGIN_DIR . "/postpress-ai/assets/js/admin.js"; $version = file_exists( $js_file ) ? (string) filemtime( $js_file ) : "1.0.0"; }
        wp_register_script( "ppa-admin", $plugin_base_url . "assets/js/admin.js", array( "jquery" ), $version, true );
        wp_enqueue_script( "ppa-admin" );
        wp_localize_script( "ppa-admin", "PPA", array( "ajax_url" => admin_url( "admin-ajax.php" ), "preview_action" => "ppa_preview", "store_action" => "ppa_store", "plugin_version" => $version ) );
        wp_enqueue_style( "ppa-admin-css", $plugin_base_url . "assets/css/admin.css", array(), $version );
        if ( function_exists( "error_log" ) ) { error_log( "PPA: ppa_admin_enqueue_assets enqueued assets (base=" . $plugin_base_url . ")" ); }
    }
    add_action( "admin_enqueue_scripts", "ppa_admin_enqueue_assets" );
}
