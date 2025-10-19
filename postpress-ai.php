<?php
/**
 * PostPress AI — Plugin Bootstrap
 *
 * CHANGE LOG
 * 2025-10-19 — Restore proper admin page registration + access for Admin/Editor/Author. # CHANGED
 * - Registers a top-level menu with capability 'edit_posts' (covers admin/editor/author). # CHANGED
 * - Uses render callback ppa_render_composer() to include inc/admin/composer.php at the right time. # CHANGED
 * - Defers admin assets to admin_enqueue_scripts and scopes to our screen only. # CHANGED
 * - Loads AJAX handlers on 'init' so admin-ajax.php works. # CHANGED
 * - Adds detailed debug logs (error_log('PPA: ...')). # CHANGED
 *
 * Notes:
 * - Do not echo output here; only register hooks. The UI is rendered by composer.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Paths
if ( ! defined( 'PPA_PLUGIN_DIR' ) ) {
    define( 'PPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_URL' ) ) {
    define( 'PPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Debug: bootstrap loaded
error_log( 'PPA: bootstrap loaded; dir=' . PPA_PLUGIN_DIR ); // CHANGED

/**
 * Register admin menu (visible to admins, editors, authors).
 * Capability 'edit_posts' intentionally chosen to include those roles.
 */
add_action( 'admin_menu', function () {
    $capability = 'edit_posts'; // admin/editor/author
    $menu_slug  = 'postpress-ai'; // keep slug stable + predictable

    add_menu_page(
        __( 'PostPress AI', 'postpress-ai' ),
        __( 'PostPress AI', 'postpress-ai' ),
        $capability,
        $menu_slug,
        'ppa_render_composer',
        'dashicons-welcome-widgets-menus',
        65
    );

    error_log( 'PPA: admin_menu registered (slug=' . $menu_slug . ', cap=' . $capability . ')' ); // CHANGED
}, 9 );

/**
 * Render callback — includes the composer UI (no capability checks here;
 * WP has already enforced 'edit_posts' for us).
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {
    function ppa_render_composer() {
        if ( ! is_admin() ) {
            return;
        }
        $composer = PPA_PLUGIN_DIR . 'inc/admin/composer.php';
        if ( file_exists( $composer ) ) {
            error_log( 'PPA: including composer.php' ); // CHANGED
            require $composer;
        } else {
            error_log( 'PPA: composer.php missing at ' . $composer ); // CHANGED
            echo '<div class="wrap"><h1>PostPress AI</h1><p>Composer UI not found.</p></div>';
        }
    }
}

/**
 * Enqueue admin assets (only on our screen).
 */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
    // Only load on our page to keep admin clean
    $screen = get_current_screen();
    $is_our_screen = $screen && ( $screen->id === 'toplevel_page_postpress-ai' );

    $enqueue = PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';
    if ( file_exists( $enqueue ) ) {
        require_once $enqueue; // expected to define ppa_admin_enqueue()
        if ( function_exists( 'ppa_admin_enqueue' ) && $is_our_screen ) {
            error_log( 'PPA: enqueue assets for ' . $hook_suffix ); // CHANGED
            ppa_admin_enqueue();
        }
    } else {
        error_log( 'PPA: enqueue.php not found; skipping assets' ); // CHANGED
    }
}, 10 );

/**
 * AJAX handlers — load early so admin-ajax.php can find them.
 */
add_action( 'init', function () {
    $ajax_dir = PPA_PLUGIN_DIR . 'inc/ajax/';

    foreach ( array( 'preview.php', 'store.php', 'marker.php' ) as $file ) {
        $path = $ajax_dir . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
            error_log( 'PPA: ajax loaded ' . $file ); // CHANGED
        }
    }
}, 11 );
