<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * ========= CHANGE LOG =========
 * 2026-01-21: FIX: Settings page now renders by calling PPA_Admin_Settings::render_page() after include.        // CHANGED:
 * 2026-01-21: ADD: Account submenu added + renderer (include account.php, call ppa_render_account(), fallback). // CHANGED:
 * 2026-01-21: HARDEN: Composer renderer now supports both "file echoes UI" and "file defines a render function". // CHANGED:
 * 2026-01-21: CLEAN: Remove success/noise logs; log only on failures.                                          // CHANGED:
 * 2026-02-23: ADD: Videos submenu + renderer with dummy playlists/cards (no behavior change elsewhere).     // CHANGED:
 * 2026-02-23: FIX: Videos page now uses YouTube Unlisted IDs + real thumbnails + empty state.         // CHANGED:
 * 2026-02-24: CHANGE: Videos page is now read-only + playlist-fed via YouTube playlist feeds (no API key). // CHANGED:
 * 2026-02-24: FIX: Add refresh + clearer empty-feed handling (YouTube may omit unlisted/private).   // CHANGED:
 * 2026-02-24: UI: Remove YouTube playlist outbound link (no new-tab escape hatch).   // CHANGED:
 * 2026-02-24: UX: If feed is empty, offer 'Watch' in modal (no new tab).         // CHANGED:
 * 2026-02-24: FIX: Robust YouTube playlist parsing (yt:videoId tags + XML fallback) so cards reliably populate. // CHANGED:
 * 2026-02-24: FIX: Cache-bust playlist transient keys (v2 prefix) so fixes apply immediately. // CHANGED:
 * 2026-02-24: UX: Videos page now lists every video in a 3-column grid (per-video Watch buttons), no playlist-level buttons, no confusing disabled controls. // CHANGED:
 *
 * 2025-12-28: ADD: Custom SVG dashicon for PostPress AI menu; position set to 3 (high priority).  // CHANGED:
 * 2025-12-28: FIX: Remove duplicate "PostPress Composer" submenu entry.
 *             WP already auto-creates the first submenu for the parent slug via add_menu_page().              // CHANGED:
 *             We keep the rename of that auto submenu label, but do not add a second submenu item.           // CHANGED:
 *
 * 2025-12-28: FIX: Register the 'ppa_settings' settings group early on admin_init so options.php accepts option_page=ppa_settings
 *             even when settings.php is only included by the menu callback (prevents "allowed options list" error).            // CHANGED:
 *             (No Django/endpoints/CORS/auth changes. No layout/CSS changes. menu.php only.)                                  // CHANGED:
 *
 * Notes:
 * - Keep this file presentation-free. No echo except inside the explicit render callbacks.
 * - Admin assets are handled in inc/admin/enqueue.php (scoped by screen checks).
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Sanitize helper for simple PPA settings (string or array).
 * This is intentionally conservative: only supports scalar strings + nested arrays of strings.      // CHANGED:
 */
if ( ! function_exists( 'ppa_sanitize_setting_value' ) ) {                                         // CHANGED:
        function ppa_sanitize_setting_value( $value ) {                                                 // CHANGED:
                $value = wp_unslash( $value );                                                               // CHANGED:

                if ( is_array( $value ) ) {                                                                  // CHANGED:
                        $out = array();                                                                          // CHANGED:
                        foreach ( $value as $k => $v ) {                                                         // CHANGED:
                                // Preserve keys, sanitize values recursively.                                        // CHANGED:
                                $out[ $k ] = ppa_sanitize_setting_value( $v );                                       // CHANGED:
                        }                                                                                        // CHANGED:
                        return $out;                                                                             // CHANGED:
                }                                                                                            // CHANGED:

                return sanitize_text_field( (string) $value );                                               // CHANGED:
        }                                                                                                // CHANGED:
}                                                                                                    // CHANGED:

/**
 * Settings API bootstrap (critical for options.php saves).
 *
 * WHY:
 * - The Settings page form posts to options.php with option_page=ppa_settings.
 * - If register_setting('ppa_settings', ...) has NOT run by admin_init, WP rejects the save with:
 *   "Error: The ppa_settings options page is not in the allowed options list."
 * - settings.php is currently included by the Settings menu callback (late), so it may miss admin_init on save.  // CHANGED:
 *
 * FIX:
 * - Register the relevant setting(s) here on admin_init (early), without touching settings.php.
 * - This is WP-only, does not impact Django/endpoints/CORS/auth/etc.                                                  // CHANGED:
 */
if ( ! function_exists( 'ppa_register_settings_api_bootstrap' ) ) {                                 // CHANGED:
        function ppa_register_settings_api_bootstrap() {                                                 // CHANGED:
                if ( ! is_admin() ) {                                                                       // CHANGED:
                        return;                                                                                 // CHANGED:
                }                                                                                            // CHANGED:

                // Only admins can hit options.php successfully anyway, but keep this tight.                 // CHANGED:
                if ( ! current_user_can( 'manage_options' ) ) {                                             // CHANGED:
                        return;                                                                                 // CHANGED:
                }                                                                                            // CHANGED:

                // Avoid overriding an existing registration if settings.php (or another file) registers first.  // CHANGED:
                global $wp_registered_settings;                                                             // CHANGED:
                if ( ! is_array( $wp_registered_settings ) ) {                                              // CHANGED:
                        $wp_registered_settings = array();                                                      // CHANGED:
                }                                                                                            // CHANGED:

                // Most common pattern: a single option for the license key.                                 // CHANGED:
                if ( ! isset( $wp_registered_settings['ppa_license_key'] ) ) {                              // CHANGED:
                        register_setting(                                                                       // CHANGED:
                                'ppa_settings',                                                                     // CHANGED: option_group (must match settings_fields('ppa_settings'))
                                'ppa_license_key',                                                                  // CHANGED: option_name
                                array(                                                                              // CHANGED:
                                        'type'              => 'string',                                                // CHANGED:
                                        'sanitize_callback' => 'ppa_sanitize_setting_value',                            // CHANGED:
                                        'default'           => '',                                                      // CHANGED:
                                )                                                                                   // CHANGED:
                        );                                                                                       // CHANGED:
                }                                                                                            // CHANGED:

                // Alternate pattern: store settings as an array under one option named same-ish as the group.   // CHANGED:
                // This is harmless if unused, and prevents edge cases where the form uses ppa_settings[...].    // CHANGED:
                if ( ! isset( $wp_registered_settings['ppa_settings'] ) ) {                                  // CHANGED:
                        register_setting(                                                                       // CHANGED:
                                'ppa_settings',                                                                     // CHANGED:
                                'ppa_settings',                                                                     // CHANGED:
                                array(                                                                              // CHANGED:
                                        'type'              => 'array',                                                 // CHANGED:
                                        'sanitize_callback' => 'ppa_sanitize_setting_value',                            // CHANGED:
                                        'default'           => array(),                                                 // CHANGED:
                                )                                                                                   // CHANGED:
                        );                                                                                       // CHANGED:
                }                                                                                            // CHANGED:
        }                                                                                                // CHANGED:
        add_action( 'admin_init', 'ppa_register_settings_api_bootstrap', 0 );                            // CHANGED: priority 0 = early
}                                                                                                    // CHANGED:

/**
 * Register the top-level "PostPress AI" menu and route to the Composer renderer.
 * Also adds:
 *  - Submenu "PostPress Composer" (renames the default submenu label).
 *  - Submenu "Settings" (admin-only).
 *  - Submenu "Account" (admin-only).                                                                // CHANGED:
 *  - Submenu "Testbed" (hidden unless PPA_ENABLE_TESTBED === true).
 */
if ( ! function_exists( 'ppa_register_admin_menu' ) ) {
        function ppa_register_admin_menu() {
                $capability_composer = 'edit_posts';
                $capability_admin    = 'manage_options'; // Settings + Account + Testbed are admin-only.     // CHANGED:
                $menu_slug           = 'postpress-ai';

                // Custom SVG icon for PostPress AI                                                         // CHANGED:
                $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
  <path d="M3 2h8.5c3.59 0 6.5 2.91 6.5 6.5S15.09 15 11.5 15H8v3H3V2z" fill="#ff8c00" opacity="0.2"/>
  <g stroke="#ff8c00" stroke-width="1.2" stroke-linecap="round" fill="none">
    <circle cx="12" cy="7" r="1.5"/>
    <circle cx="7" cy="11" r="1.2"/>
    <path d="M12 8.5 L12 10 L10 12 L7 12"/>
    <path d="M7 11 L7 9.5"/>
  </g>
</svg>'; // CHANGED:
                $menu_icon = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );                      // CHANGED:

                // Top-level menu (Composer)
                add_menu_page(
                        __( 'PostPress AI', 'postpress-ai' ),
                        __( 'PostPress AI', 'postpress-ai' ),
                        $capability_composer,
                        $menu_slug,
                        'ppa_render_composer',
                        $menu_icon,                                                                              // CHANGED:
                        3                                                                                        // CHANGED: position 3 = high priority (right after Dashboard)
                );

                // Rename the auto-generated first submenu to "PostPress Composer"
                // NOTE: WP auto-creates this first submenu for the parent slug; do NOT add a duplicate.      // CHANGED:
                global $submenu;
                if ( isset( $submenu[ $menu_slug ][0] ) ) {
                        $submenu[ $menu_slug ][0][0] = __( 'PostPress Composer', 'postpress-ai' );
                }

                // Settings submenu (admin-only)                                                             // CHANGED:
                add_submenu_page(
                        $menu_slug,
                        __( 'PostPress AI Settings', 'postpress-ai' ),
                        __( 'Settings', 'postpress-ai' ),
                        $capability_admin,
                        'postpress-ai-settings',
                        'ppa_render_settings'
                );

                // Account submenu (admin-only)                                                               // CHANGED:
                add_submenu_page(
                        $menu_slug,
                        __( 'PostPress AI Account', 'postpress-ai' ),
                        __( 'Account', 'postpress-ai' ),
                        $capability_admin,
                        'postpress-ai-account',
                        'ppa_render_account'
                );

                // Videos submenu (admin-only)                                                                // CHANGED:
                add_submenu_page(                                                                            // CHANGED:
                        $menu_slug,                                                                                // CHANGED:
                        __( 'PostPress AI Videos', 'postpress-ai' ),                                                // CHANGED:
                        __( 'Videos', 'postpress-ai' ),                                                             // CHANGED:
                        $capability_admin,                                                                          // CHANGED:
                        'postpress-ai-videos',                                                                      // CHANGED:
                        'ppa_render_videos'                                                                         // CHANGED:
                );                                                                                            // CHANGED:

                // Testbed submenu (admin-only AND gated)                                                     // CHANGED:
                $testbed_enabled = ( defined( 'PPA_ENABLE_TESTBED' ) && true === PPA_ENABLE_TESTBED );       // CHANGED:
                if ( $testbed_enabled ) {
                        add_submenu_page(
                                $menu_slug,
                                __( 'PPA Testbed', 'postpress-ai' ),
                                __( 'Testbed', 'postpress-ai' ),
                                $capability_admin,
                                'postpress-ai-testbed',
                                'ppa_render_testbed'
                        );
                }

                // Remove any legacy Tools→Testbed to avoid duplicates (harmless if not present).             // CHANGED:
                remove_submenu_page( 'tools.php', 'ppa-testbed' );
                remove_submenu_page( 'tools.php', 'postpress-ai-testbed' );
        }
        add_action( 'admin_menu', 'ppa_register_admin_menu', 9 );
}

/**
 * Composer renderer (main UI).
 * Includes inc/admin/composer.php if present.
 *
 * HARDEN:
 * - Some composer.php versions echo the UI directly.
 * - Other versions only define a function/class and expect a caller.
 * This function now supports both patterns safely.                                                   // CHANGED:
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {
        function ppa_render_composer() {
                if ( ! current_user_can( 'edit_posts' ) ) {
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
                }

                $root = defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ); // CHANGED:
                $composer = $root . 'inc/admin/composer.php';                                                                       // CHANGED:

                if ( file_exists( $composer ) ) {
                        ob_start();                                                                                                      // CHANGED:
                        require $composer;                                                                                               // CHANGED:
                        $out = ob_get_clean();                                                                                           // CHANGED:

                        // If the file echoed UI, print it and we’re done.
                        if ( is_string( $out ) && '' !== trim( $out ) ) {                                                                // CHANGED:
                                echo $out;                                                                                                   // CHANGED:
                                return;                                                                                                      // CHANGED:
                        }                                                                                                                // CHANGED:


                        // If the file defines a renderer, call it.
                        $candidates = array(                                                                                              // CHANGED:
                                'ppa_render_composer_ui',                                                                                    // CHANGED:
                                'ppa_composer_render',                                                                                       // CHANGED:
                                'postpress_ai_render_composer',                                                                              // CHANGED:
                        );                                                                                                               // CHANGED:
                        foreach ( $candidates as $fn ) {                                                                                  // CHANGED:
                                if ( function_exists( $fn ) ) {                                                                              // CHANGED:
                                        call_user_func( $fn );                                                                                   // CHANGED:
                                        return;                                                                                                  // CHANGED:
                                }                                                                                                            // CHANGED:
                        }                                                                                                                // CHANGED:

                        // If nothing rendered, fall through to a safe message.
                        error_log( 'PPA: composer.php included but produced no output and no known render function exists.' );            // CHANGED:
                } else {
                        error_log( 'PPA: composer.php missing at ' . $composer );                                                         // CHANGED:
                }

                echo '<div class="wrap"><h1>PostPress Composer</h1><p>'
                        . esc_html__( 'Composer UI did not render. Ensure inc/admin/composer.php either echoes UI or defines ppa_render_composer_ui().', 'postpress-ai' )
                        . '</p></div>';
        }
}

/**
 * Settings renderer (submenu).
 *
 * IMPORTANT:
 * - settings.php does NOT render on include (by design, to avoid "headers already sent").
 * - It defines PPA_Admin_Settings and calls ::init().
 * - We MUST call ::render_page() here or the Settings screen will be blank.                              // CHANGED:
 */
if ( ! function_exists( 'ppa_render_settings' ) ) {
        function ppa_render_settings() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
                }

                $root = defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ); // CHANGED:
                $settings = $root . 'inc/admin/settings.php';                                                                         // CHANGED:

                if ( file_exists( $settings ) ) {
                        ob_start();                                                                                                        // CHANGED:
                        require $settings;                                                                                                 // CHANGED:
                        $out = ob_get_clean();                                                                                             // CHANGED:

                        // If settings.php echoed UI (it shouldn't), honor it and exit.
                        if ( is_string( $out ) && '' !== trim( $out ) ) {                                                                  // CHANGED:
                                echo $out;                                                                                                     // CHANGED:
                                return;                                                                                                        // CHANGED:
                        }                                                                                                                  // CHANGED:

                        // Correct behavior: call the renderer.
                        if ( class_exists( 'PPA_Admin_Settings' ) && method_exists( 'PPA_Admin_Settings', 'render_page' ) ) {              // CHANGED:
                                PPA_Admin_Settings::render_page();                                                                             // CHANGED:
                                return;                                                                                                        // CHANGED:
                        }                                                                                                                  // CHANGED:

                        error_log( 'PPA: settings.php loaded but PPA_Admin_Settings::render_page() not available.' );                      // CHANGED:
                } else {
                        error_log( 'PPA: settings.php missing at ' . $settings );                                                          // CHANGED:
                }

                echo '<div class="wrap"><h1>PostPress AI Settings</h1><p>'
                        . esc_html__( 'Settings UI not available. Ensure inc/admin/settings.php defines PPA_Admin_Settings::render_page().', 'postpress-ai' )
                        . '</p></div>';
        }
}

/**
 * Account renderer (submenu).
 * Supports:
 * - account.php echoing UI
 * - account.php defining ppa_render_account()
 * - fallback placeholder
 */
if ( ! function_exists( 'ppa_render_account' ) ) {                                                     // CHANGED:
        function ppa_render_account() {                                                                      // CHANGED:
                if ( ! current_user_can( 'manage_options' ) ) {                                                   // CHANGED:
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );    // CHANGED:
                }                                                                                                 // CHANGED:

                $root = defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ); // CHANGED:
                $account = $root . 'inc/admin/account.php';                                                       // CHANGED:

                if ( file_exists( $account ) ) {                                                                  // CHANGED:
                        ob_start();                                                                                   // CHANGED:
                        require $account;                                                                             // CHANGED:
                        $out = ob_get_clean();                                                                        // CHANGED:

                        if ( is_string( $out ) && '' !== trim( $out ) ) {                                             // CHANGED:
                                echo $out;                                                                                // CHANGED:
                                return;                                                                                   // CHANGED:
                        }                                                                                             // CHANGED:

                        if ( function_exists( 'ppa_render_account_page' ) ) {                                         // CHANGED:
                                ppa_render_account_page();                                                                // CHANGED:
                                return;                                                                                   // CHANGED:
                        }                                                                                             // CHANGED:

                        if ( function_exists( 'ppa_render_account' ) && __FUNCTION__ !== 'ppa_render_account' ) {     // CHANGED (safety, should never happen)
                                ppa_render_account();                                                                     // CHANGED:
                                return;                                                                                   // CHANGED:
                        }                                                                                             // CHANGED:

                        // If file exists but didn’t render, log once (failure only).
                        error_log( 'PPA: account.php included but produced no output and no renderer found.' );        // CHANGED:
                } else {
                        error_log( 'PPA: account.php missing at ' . $account );                                       // CHANGED:
                }

                echo '<div class="wrap"><h1>PostPress AI Account</h1><p>'
                        . esc_html__( 'Account page is connected. Next step: wire usage, sites, and upgrade links.', 'postpress-ai' )
                        . '</p></div>';
        }                                                                                                     // CHANGED:
}                                                                                                         // CHANGED:

/**
 * Videos renderer (submenu).
 *
 * v2 (READ-ONLY, PLAYLIST-FED):                                                                          // CHANGED:
 * - No "Add Video" form. No option storage. No admin-post add/delete handlers.                           // CHANGED:
 * - Videos are pulled from YouTube playlist feeds (no API key).                                          // CHANGED:
 * - Each left-rail category maps to a playlist_id and renders cards from the Atom feed.                  // CHANGED:
 * - Cached via transient per playlist to keep the page fast and avoid rate-limiting.                     // CHANGED:
 *
 * Feed format (no key):
 *   https://www.youtube.com/feeds/videos.xml?playlist_id=PLAYLIST_ID                                     // CHANGED:
 */
if ( ! function_exists( 'ppa_videos_get_playlists' ) ) {                                                     // CHANGED:
        function ppa_videos_get_playlists() {                                                                    // CHANGED:
                return array(                                                                                         // CHANGED:
                        'start-here'        => __( 'Start Here', 'postpress-ai' ),                                        // CHANGED:
                        'composer'          => __( 'Composer', 'postpress-ai' ),                                          // CHANGED:
                        'license-account'   => __( 'License & Account', 'postpress-ai' ),                                 // CHANGED:
                        'troubleshooting'   => __( 'Troubleshooting', 'postpress-ai' ),                                   // CHANGED:
                        'whats-new'         => __( 'What’s New', 'postpress-ai' ),                                        // CHANGED:
                );                                                                                                    // CHANGED:
        }                                                                                                         // CHANGED:
}                                                                                                             // CHANGED:

if ( ! function_exists( 'ppa_videos_get_playlist_ids' ) ) {                                                   // CHANGED:
        /**
         * Map playlist keys -> YouTube playlist IDs (Wayne-managed in YouTube).                                 // CHANGED:
         */                                                                                                       // CHANGED:
        function ppa_videos_get_playlist_ids() {                                                                   // CHANGED:
                $ids = array(                                                                                           // CHANGED:
                        'start-here'        => 'PLcsv-jPYfbsUztarZkce_uZZx9zge2UEs',                                        // CHANGED:
                        'composer'          => 'PLcsv-jPYfbsUNU5BP1WO3BViHQ8qOmWza',                                        // CHANGED:
                        'license-account'   => 'PLcsv-jPYfbsXFpn43vsvc8j314-kozxok',                                        // CHANGED:
                        'troubleshooting'   => 'PLcsv-jPYfbsXg4s-Gl1Mf-_U1656uuseM',                                        // CHANGED:
                        'whats-new'         => 'PLcsv-jPYfbsVIdu6DAjgmJm7-jZ5kytHt',                                        // CHANGED:
                );                                                                                                      // CHANGED:
                /**
                 * Filter: allow overrides without editing core files.
                 *
                 * @param array $ids key => playlist_id
                 */
                return apply_filters( 'ppa_videos_playlist_ids', $ids );                                             // CHANGED:
        }                                                                                                         // CHANGED:
}                                                                                                             // CHANGED:

if ( ! function_exists( 'ppa_videos_normalize_yt_id' ) ) {                                                    // CHANGED:
        /**
         * Normalize YouTube input (accept full URL or raw 11-char ID).                                           // CHANGED:
         * Returns '' when invalid.                                                                              // CHANGED:
         */
        function ppa_videos_normalize_yt_id( $raw ) {                                                            // CHANGED:
                $raw = trim( (string) $raw );                                                                         // CHANGED:
                if ( $raw === '' ) {                                                                                  // CHANGED:
                        return '';                                                                                        // CHANGED:
                }                                                                                                     // CHANGED:

                // Plain ID already (YouTube video IDs are 11 chars).                                                  // CHANGED:
                if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $raw ) ) {                                                  // CHANGED:
                        return $raw;                                                                                      // CHANGED:
                }                                                                                                     // CHANGED:

                $u = function_exists( 'wp_parse_url' ) ? wp_parse_url( $raw ) : parse_url( $raw );                    // CHANGED:
                if ( ! is_array( $u ) || empty( $u['host'] ) ) {                                                       // CHANGED:
                        // Last chance: pull an 11-char token from the string.                                            // CHANGED:
                        if ( preg_match( '/\b([A-Za-z0-9_-]{11})\b/', $raw, $m ) ) {                                   // CHANGED:
                                return (string) $m[1];                                                                        // CHANGED:
                        }                                                                                                 // CHANGED:
                        return '';                                                                                         // CHANGED:
                }                                                                                                     // CHANGED:

                $host = strtolower( (string) $u['host'] );                                                            // CHANGED:
                $path = isset( $u['path'] ) ? trim( (string) $u['path'], '/' ) : '';                                  // CHANGED:

                // youtu.be/VIDEOID                                                                                   // CHANGED:
                if ( strpos( $host, 'youtu.be' ) !== false && $path ) {                                               // CHANGED:
                        $seg   = explode( '/', $path );                                                                   // CHANGED:
                        $maybe = isset( $seg[0] ) ? (string) $seg[0] : '';                                                 // CHANGED:
                        return preg_match( '/^[A-Za-z0-9_-]{11}$/', $maybe ) ? $maybe : '';                                // CHANGED:
                }                                                                                                     // CHANGED:

                // youtube.com/* patterns                                                                              // CHANGED:
                if ( strpos( $host, 'youtube.com' ) !== false || strpos( $host, 'youtube-nocookie.com' ) !== false ) { // CHANGED:
                        $q = array();                                                                                      // CHANGED:
                        if ( ! empty( $u['query'] ) ) {                                                                    // CHANGED:
                                parse_str( (string) $u['query'], $q );                                                         // CHANGED:
                        }                                                                                                  // CHANGED:

                        // watch?v=VIDEOID                                                                                 // CHANGED:
                        if ( isset( $q['v'] ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $q['v'] ) ) {              // CHANGED:
                                return (string) $q['v'];                                                                       // CHANGED:
                        }                                                                                                  // CHANGED:

                        // embed/VIDEOID                                                                                    // CHANGED:
                        if ( $path && strpos( $path, 'embed/' ) === 0 ) {                                                   // CHANGED:
                                $maybe = substr( $path, 6 );                                                                    // CHANGED:
                                $maybe = explode( '/', (string) $maybe )[0];                                                    // CHANGED:
                                return preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $maybe ) ? (string) $maybe : '';           // CHANGED:
                        }                                                                                                  // CHANGED:

                        // shorts/VIDEOID                                                                                   // CHANGED:
                        if ( $path && strpos( $path, 'shorts/' ) === 0 ) {                                                  // CHANGED:
                                $maybe = substr( $path, 7 );                                                                    // CHANGED:
                                $maybe = explode( '/', (string) $maybe )[0];                                                    // CHANGED:
                                return preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $maybe ) ? (string) $maybe : '';           // CHANGED:
                        }                                                                                                  // CHANGED:

                        // live/VIDEOID                                                                                     // CHANGED:
                        if ( $path && strpos( $path, 'live/' ) === 0 ) {                                                    // CHANGED:
                                $maybe = substr( $path, 5 );                                                                    // CHANGED:
                                $maybe = explode( '/', (string) $maybe )[0];                                                    // CHANGED:
                                return preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $maybe ) ? (string) $maybe : '';           // CHANGED:
                        }                                                                                                  // CHANGED:
                }                                                                                                     // CHANGED:

                // Final fallback: pick an 11-char token that looks like a video ID.                               // CHANGED:
                if ( preg_match( '/\b([A-Za-z0-9_-]{11})\b/', $raw, $m ) ) {                                       // CHANGED:
                        return (string) $m[1];                                                                            // CHANGED:
                }                                                                                                     // CHANGED:

                return '';                                                                                             // CHANGED:
        }                                                                                                         // CHANGED:
}                                                                                                             // CHANGED:

if ( ! function_exists( 'ppa_videos_admin_url' ) ) {                                                         // CHANGED:
        function ppa_videos_admin_url( $playlist = 'start-here' ) {                                               // CHANGED:
                $playlist = sanitize_key( (string) $playlist );                                                        // CHANGED:
                return add_query_arg(                                                                                  // CHANGED:
                        array( 'page' => 'postpress-ai-videos', 'playlist' => $playlist ),                                 // CHANGED:
                        admin_url( 'admin.php' )                                                                           // CHANGED:
                );                                                                                                     // CHANGED:
        }                                                                                                         // CHANGED:
}                                                                                                             // CHANGED:



if ( ! function_exists( 'ppa_videos_fetch_playlist_videos' ) ) {                                              // CHANGED:
        /**
         * Fetch + parse a YouTube playlist feed into a small, UI-ready array.
         *
         * Feed format (no API key):
         *   https://www.youtube.com/feeds/videos.xml?playlist_id=PLAYLIST_ID                                         // CHANGED:
         *
         * @param string $playlist_id YouTube playlist id (PL...).
         * @param int    $max_items   Max entries to return.
         * @param bool   $force_refresh If true, bypass transient cache immediately.
         * @return array {items: array, error: string, cached: bool}
         */
        function ppa_videos_fetch_playlist_videos( $playlist_id, $max_items = 30, $force_refresh = false ) {          // CHANGED:
                $playlist_id   = trim( (string) $playlist_id );                                                       // CHANGED:
                $max_items     = (int) $max_items;                                                                    // CHANGED:
                $force_refresh = (bool) $force_refresh;                                                               // CHANGED:

                if ( $max_items < 1 ) { $max_items = 30; }                                                            // CHANGED:
                if ( $max_items > 80 ) { $max_items = 80; }                                                           // CHANGED:

                if ( $playlist_id === '' ) {                                                                          // CHANGED:
                        return array( 'items' => array(), 'error' => 'Missing playlist id.', 'cached' => false );      // CHANGED:
                }                                                                                                      // CHANGED:

                // Cache-bust prefix so fixes take effect immediately without waiting on old transients.               // CHANGED:
                $cache_key = 'ppa_videos_feed_v5_' . md5( $playlist_id . '|' . (string) $max_items );                  // CHANGED:
                if ( $force_refresh ) {                                                                               // CHANGED:
                        delete_transient( $cache_key );                                                               // CHANGED:
                }                                                                                                      // CHANGED:

                $cached = get_transient( $cache_key );                                                                // CHANGED:
                if ( is_array( $cached ) && isset( $cached['items'] ) && is_array( $cached['items'] ) ) {             // CHANGED:
                        $cached['cached'] = true;                                                                     // CHANGED:
                        return $cached;                                                                               // CHANGED:
                }                                                                                                      // CHANGED:

                // Load WP feed tools on-demand (admin-safe).                                                          // CHANGED:
                if ( ! function_exists( 'fetch_feed' ) ) {                                                            // CHANGED:
                        require_once ABSPATH . WPINC . '/feed.php';                                                   // CHANGED:
                }                                                                                                      // CHANGED:

                $url  = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . rawurlencode( $playlist_id );       // CHANGED:
                $feed = fetch_feed( $url );                                                                           // CHANGED:

                if ( is_wp_error( $feed ) ) {                                                                         // CHANGED:
                        $data = array(                                                                                // CHANGED:
                                'items'  => array(),                                                                  // CHANGED:
                                'error'  => (string) $feed->get_error_message(),                                      // CHANGED:
                                'cached' => false,                                                                    // CHANGED:
                        );                                                                                            // CHANGED:
                        // Short cache on error so we don't hammer YouTube if outbound is blocked.                     // CHANGED:
                        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );                                    // CHANGED:
                        return $data;                                                                                 // CHANGED:
                }                                                                                                      // CHANGED:

                $items = $feed->get_items( 0, $max_items );                                                           // CHANGED:
                $out   = array();                                                                                     // CHANGED:

                if ( is_array( $items ) ) {                                                                           // CHANGED:
                        foreach ( $items as $it ) {                                                                   // CHANGED:
                                if ( ! is_object( $it ) ) {                                                           // CHANGED:
                                        continue;                                                                     // CHANGED:
                                }                                                                                     // CHANGED:

                                $title = (string) $it->get_title();                                                   // CHANGED:
                                $link  = (string) $it->get_permalink();                                               // CHANGED:

                                // Prefer explicit yt:videoId tag (most reliable on playlist feeds).                   // CHANGED:
                                $yt_id = '';                                                                          // CHANGED:
                                if ( method_exists( $it, 'get_item_tags' ) ) {                                        // CHANGED:
                                        $tags = $it->get_item_tags( 'http://www.youtube.com/xml/schemas/2015', 'videoId' ); // CHANGED:
                                        if ( is_array( $tags ) && isset( $tags[0]['data'] ) ) {                       // CHANGED:
                                                $maybe = trim( (string) $tags[0]['data'] );                           // CHANGED:
                                                if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $maybe ) ) {                // CHANGED:
                                                        $yt_id = $maybe;                                              // CHANGED:
                                                }                                                                     // CHANGED:
                                        }                                                                             // CHANGED:
                                }                                                                                     // CHANGED:

                                // Fallback: parse from permalink.                                                     // CHANGED:
                                if ( $yt_id === '' ) {                                                                // CHANGED:
                                        $yt_id = ppa_videos_normalize_yt_id( $link );                                 // CHANGED:
                                }                                                                                     // CHANGED:

                                // Fallback: some parsers expose id like tag:youtube.com,2008:video:VIDEOID            // CHANGED:
                                if ( $yt_id === '' && method_exists( $it, 'get_id' ) ) {                              // CHANGED:
                                        $raw_id = (string) $it->get_id();                                              // CHANGED:
                                        if ( preg_match( '/video:([A-Za-z0-9_-]{11})/', $raw_id, $m ) ) {             // CHANGED:
                                                $yt_id = (string) $m[1];                                              // CHANGED:
                                        }                                                                             // CHANGED:
                                }                                                                                     // CHANGED:

                                if ( $yt_id === '' ) {                                                                // CHANGED:
                                        continue;                                                                     // CHANGED:
                                }                                                                                     // CHANGED:

                                $ts = 0;                                                                              // CHANGED:
                                if ( method_exists( $it, 'get_date' ) ) {                                              // CHANGED:
                                        $maybe_ts = $it->get_date( 'U' );                                              // CHANGED:
                                        $ts       = $maybe_ts ? (int) $maybe_ts : 0;                                   // CHANGED:
                                }                                                                                     // CHANGED:

                                $out[] = array(                                                                       // CHANGED:
                                        'yt'    => $yt_id,                                                            // CHANGED:
                                        'title' => $title !== '' ? wp_strip_all_tags( $title ) : __( 'Video', 'postpress-ai' ), // CHANGED:
                                        'ts'    => $ts,                                                               // CHANGED:
                                        'url'   => $link !== '' ? $link : ( 'https://www.youtube.com/watch?v=' . rawurlencode( $yt_id ) ), // CHANGED:
                                );                                                                                    // CHANGED:
                        }                                                                                             // CHANGED:
                }                                                                                                     // CHANGED:

                // If SimplePie produced zero parsable items, fallback to direct XML parse.                            // CHANGED:
                $final_error = '';                                                                                    // CHANGED:
                if ( empty( $out ) ) {                                                                                 // CHANGED:
                        $fallback_err = '';                                                                            // CHANGED:

                        $resp = wp_safe_remote_get(                                                                    // CHANGED:
                                $url,                                                                                 // CHANGED:
                                array(                                                                                // CHANGED:
                                        'timeout'     => 12,                                                          // CHANGED:
                                        'redirection' => 5,                                                           // CHANGED:
                                        'headers'     => array(                                                       // CHANGED:
                                                'User-Agent' => 'Mozilla/5.0 (WordPress; PostPress AI Videos)',       // CHANGED:
                                        ),                                                                             // CHANGED:
                                )                                                                                      // CHANGED:
                        );                                                                                             // CHANGED:

                        if ( is_wp_error( $resp ) ) {                                                                  // CHANGED:
                                $fallback_err = (string) $resp->get_error_message();                                  // CHANGED:
                        } else {                                                                                       // CHANGED:
                                $code = (int) wp_remote_retrieve_response_code( $resp );                              // CHANGED:
                                $body = (string) wp_remote_retrieve_body( $resp );                                    // CHANGED:

                                if ( 200 !== $code || '' === $body ) {                                                 // CHANGED:
                                        $fallback_err = 'HTTP ' . (string) $code;                                     // CHANGED:
                                } else {                                                                               // CHANGED:
                                        if ( function_exists( 'simplexml_load_string' ) ) {                           // CHANGED:
                                                $prev = libxml_use_internal_errors( true );                           // CHANGED:
                                                $xml  = @simplexml_load_string( $body );                              // CHANGED:

                                                if ( $xml instanceof SimpleXMLElement ) {                             // CHANGED:
                                                        $ns    = $xml->getNamespaces( true );                         // CHANGED:
                                                        $count = 0;                                                   // CHANGED:

                                                        if ( isset( $xml->entry ) ) {                                 // CHANGED:
                                                                foreach ( $xml->entry as $entry ) {                   // CHANGED:
                                                                        if ( $count >= $max_items ) { break; }         // CHANGED:

                                                                        $t  = isset( $entry->title ) ? (string) $entry->title : ''; // CHANGED:
                                                                        $ts = 0;                                       // CHANGED:
                                                                        if ( isset( $entry->published ) ) {            // CHANGED:
                                                                                $ts = (int) strtotime( (string) $entry->published ); // CHANGED:
                                                                        }                                              // CHANGED:

                                                                        $yt_id = '';                                   // CHANGED:
                                                                        if ( isset( $ns['yt'] ) ) {                     // CHANGED:
                                                                                $yt = $entry->children( $ns['yt'] );   // CHANGED:
                                                                                if ( isset( $yt->videoId ) ) {          // CHANGED:
                                                                                        $maybe = trim( (string) $yt->videoId ); // CHANGED:
                                                                                        if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $maybe ) ) { // CHANGED:
                                                                                                $yt_id = $maybe;      // CHANGED:
                                                                                        }                              // CHANGED:
                                                                                }                                      // CHANGED:
                                                                        }                                              // CHANGED:

                                                                        if ( $yt_id === '' ) {                          // CHANGED:
                                                                                continue;                              // CHANGED:
                                                                        }                                              // CHANGED:

                                                                        $link = 'https://www.youtube.com/watch?v=' . rawurlencode( $yt_id ); // CHANGED:
                                                                        $out[] = array(                                // CHANGED:
                                                                                'yt'    => $yt_id,                     // CHANGED:
                                                                                'title' => $t !== '' ? wp_strip_all_tags( $t ) : __( 'Video', 'postpress-ai' ), // CHANGED:
                                                                                'ts'    => $ts,                        // CHANGED:
                                                                                'url'   => $link,                      // CHANGED:
                                                                        );                                             // CHANGED:
                                                                        $count++;                                      // CHANGED:
                                                                }                                                     // CHANGED:
                                                        }                                                             // CHANGED:
                                                } else {                                                              // CHANGED:
                                                        $fallback_err = 'Unable to parse playlist feed XML.';          // CHANGED:
                                                }                                                                     // CHANGED:

                                                libxml_clear_errors();                                                // CHANGED:
                                                libxml_use_internal_errors( $prev );                                  // CHANGED:
                                        } else {                                                                       // CHANGED:
                                                $fallback_err = 'SimpleXML not available on server.';                 // CHANGED:
                                        }                                                                              // CHANGED:
                                }                                                                                      // CHANGED:
                        }                                                                                              // CHANGED:

                        if ( empty( $out ) && $fallback_err !== '' ) {                                                 // CHANGED:
                                $final_error = $fallback_err;                                                         // CHANGED:
                        }                                                                                              // CHANGED:
                }                                                                                                      // CHANGED:

                // Last-resort fallback: scrape playlist page HTML for video IDs + oEmbed for titles (no API key).      // CHANGED:
                if ( empty( $out ) ) {                                                                                 // CHANGED:
                        $html_url = 'https://www.youtube.com/playlist?list=' . rawurlencode( $playlist_id );            // CHANGED:
                        $resp2    = wp_safe_remote_get(                                                                // CHANGED:
                                $html_url,                                                                             // CHANGED:
                                array(                                                                                 // CHANGED:
                                        'timeout'     => 14,                                                           // CHANGED:
                                        'redirection' => 5,                                                            // CHANGED:
                                        'headers'     => array(                                                        // CHANGED:
                                                'User-Agent' => 'Mozilla/5.0 (WordPress; PostPress AI Videos)',        // CHANGED:
                                        ),                                                                             // CHANGED:
                                )                                                                                       // CHANGED:
                        );                                                                                              // CHANGED:

                        if ( ! is_wp_error( $resp2 ) ) {                                                               // CHANGED:
                                $code2 = (int) wp_remote_retrieve_response_code( $resp2 );                             // CHANGED:
                                $body2 = (string) wp_remote_retrieve_body( $resp2 );                                   // CHANGED:

                                if ( 200 === $code2 && $body2 !== '' ) {                                               // CHANGED:
                                        if ( preg_match_all( '/"videoId":"([A-Za-z0-9_-]{11})"/', $body2, $mm ) ) {    // CHANGED:
                                                $seen = array();                                                       // CHANGED:
                                                $ids  = array();                                                       // CHANGED:
                                                foreach ( (array) $mm[1] as $vid ) {                                   // CHANGED:
                                                        $vid = (string) $vid;                                          // CHANGED:
                                                        if ( isset( $seen[ $vid ] ) ) { continue; }                    // CHANGED:
                                                        $seen[ $vid ] = true;                                          // CHANGED:
                                                        $ids[] = $vid;                                                 // CHANGED:
                                                        if ( count( $ids ) >= $max_items ) { break; }                  // CHANGED:
                                                }                                                                     // CHANGED:

                                                foreach ( $ids as $vid ) {                                             // CHANGED:
                                                        $watch_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $vid ); // CHANGED:
                                                        $title     = __( 'Video', 'postpress-ai' );                    // CHANGED:

                                                        // Cache oEmbed lookup per video id.                            // CHANGED:
                                                        $o_key = 'ppa_videos_oembed_v1_' . md5( $vid );                // CHANGED:
                                                        $o     = get_transient( $o_key );                              // CHANGED:
                                                        if ( is_array( $o ) && isset( $o['title'] ) ) {                // CHANGED:
                                                                $title = (string) $o['title'];                          // CHANGED:
                                                        } else {                                                       // CHANGED:
                                                                $o_url = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( $watch_url ); // CHANGED:
                                                                $o_r   = wp_safe_remote_get(                            // CHANGED:
                                                                        $o_url,                                        // CHANGED:
                                                                        array(                                         // CHANGED:
                                                                                'timeout'     => 10,                   // CHANGED:
                                                                                'redirection' => 3,                    // CHANGED:
                                                                                'headers'     => array(                // CHANGED:
                                                                                        'User-Agent' => 'Mozilla/5.0 (WordPress; PostPress AI Videos)', // CHANGED:
                                                                                ),                                     // CHANGED:
                                                                        )                                              // CHANGED:
                                                                );                                                    // CHANGED:
                                                                if ( ! is_wp_error( $o_r ) ) {                          // CHANGED:
                                                                        $o_code = (int) wp_remote_retrieve_response_code( $o_r ); // CHANGED:
                                                                        $o_body = (string) wp_remote_retrieve_body( $o_r );      // CHANGED:
                                                                        if ( 200 === $o_code && $o_body !== '' ) {      // CHANGED:
                                                                                $j = json_decode( $o_body, true );      // CHANGED:
                                                                                if ( is_array( $j ) && ! empty( $j['title'] ) ) { // CHANGED:
                                                                                        $title = (string) $j['title'];  // CHANGED:
                                                                                        set_transient( $o_key, array( 'title' => $title ), 7 * DAY_IN_SECONDS ); // CHANGED:
                                                                                }                                     // CHANGED:
                                                                        }                                             // CHANGED:
                                                                }                                                     // CHANGED:
                                                        }                                                             // CHANGED:

                                                        $out[] = array(                                               // CHANGED:
                                                                'yt'    => $vid,                                      // CHANGED:
                                                                'title' => wp_strip_all_tags( (string) $title ),      // CHANGED:
                                                                'ts'    => 0,                                         // CHANGED:
                                                                'url'   => $watch_url,                                // CHANGED:
                                                        );                                                            // CHANGED:
                                                }                                                                     // CHANGED:
                                        }                                                                             // CHANGED:
                                }                                                                                      // CHANGED:
                        }                                                                                              // CHANGED:
                }                                                                                                      // CHANGED:

                $data = array( 'items' => $out, 'error' => $final_error, 'cached' => false );                          // CHANGED:
                $ttl  = empty( $out ) ? ( 5 * MINUTE_IN_SECONDS ) : ( 30 * MINUTE_IN_SECONDS );                        // CHANGED:
                set_transient( $cache_key, $data, $ttl );                                                              // CHANGED:
                return $data;                                                                                          // CHANGED:
        }                                                                                                              // CHANGED:
}                                                                                                                      // CHANGED:



if ( ! function_exists( 'ppa_render_videos' ) ) {                                                              // CHANGED:
        function ppa_render_videos() {                                                                                 // CHANGED:
                if ( ! current_user_can( 'manage_options' ) ) {                                                        // CHANGED:
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );      // CHANGED:
                }                                                                                                      // CHANGED:

                // Use WP native Thickbox for modal playback (no new tab).                                             // CHANGED:
                if ( function_exists( 'add_thickbox' ) ) {                                                             // CHANGED:
                        add_thickbox();                                                                                // CHANGED:
                }                                                                                                      // CHANGED:

                $playlists    = ppa_videos_get_playlists();                                                            // CHANGED:
                $playlist_ids = ppa_videos_get_playlist_ids();                                                         // CHANGED:

                // Active playlist (from UI rail).                                                                     // CHANGED:
                $active = isset( $_GET['playlist'] ) ? sanitize_key( (string) $_GET['playlist'] ) : 'start-here';      // CHANGED:
                if ( ! isset( $playlists[ $active ] ) ) {                                                              // CHANGED:
                        $active = 'start-here';                                                                        // CHANGED:
                }                                                                                                      // CHANGED:

                $active_pl_id = isset( $playlist_ids[ $active ] ) ? (string) $playlist_ids[ $active ] : '';            // CHANGED:
                $do_refresh   = isset( $_GET['ppa_videos_refresh'] ) && '1' === (string) $_GET['ppa_videos_refresh'];  // CHANGED:
                $feed_data    = ppa_videos_fetch_playlist_videos( $active_pl_id, 60, $do_refresh );                    // CHANGED:
                $feed_error   = isset( $feed_data['error'] ) ? (string) $feed_data['error'] : '';                      // CHANGED:
                $items        = isset( $feed_data['items'] ) && is_array( $feed_data['items'] ) ? $feed_data['items'] : array(); // CHANGED:
                ?>
                <div class="wrap ppa-videos-wrap">
                        <h1 class="ppa-videos-h1"><?php echo esc_html__( 'PostPress AI Videos', 'postpress-ai' ); ?></h1>
                        <p class="ppa-videos-sub"><?php echo esc_html__( 'Watch quick help videos for each part of PostPress AI. Choose a playlist on the left — the videos show on the right.', 'postpress-ai' ); ?></p>

                        <?php if ( $feed_error !== '' ) : ?>
                                <div class="notice notice-warning"><p><?php echo esc_html__( 'Videos are temporarily unavailable. Please try again.', 'postpress-ai' ); ?></p></div>
                                <!-- ppa_videos_feed_error: <?php echo esc_html( $feed_error ); ?> -->
                        <?php endif; ?>

                        <div class="ppa-videos-layout">
                                <aside class="ppa-videos-rail">
                                        <h2 class="ppa-videos-rail-title"><?php echo esc_html__( 'Playlists', 'postpress-ai' ); ?></h2>
                                        <ul class="ppa-videos-rail-list">
                                                <?php foreach ( $playlists as $key => $label ) : ?>
                                                        <?php
                                                                $url       = ppa_videos_admin_url( $key );
                                                                $is_active = ( $key === $active );
                                                        ?>
                                                        <li class="ppa-videos-rail-item<?php echo $is_active ? ' is-active' : ''; ?>">
                                                                <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
                                                        </li>
                                                <?php endforeach; ?>
                                        </ul>
                                </aside>

                                <section class="ppa-videos-grid">
                                        <header class="ppa-videos-grid-head">
                                                <h2 class="ppa-videos-grid-title"><?php echo esc_html( $playlists[ $active ] ); ?></h2>
                                                <p class="ppa-videos-grid-sub"><?php echo esc_html__( 'Click a video to watch it. Close the popup to come right back.', 'postpress-ai' ); ?></p>

                                                <div class="ppa-videos-refresh-row">
                                                        <a class="button button-secondary ppa-videos-refresh" href="<?php echo esc_url( add_query_arg( array( 'page' => 'postpress-ai-videos', 'playlist' => $active, 'ppa_videos_refresh' => '1' ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Refresh list', 'postpress-ai' ); ?></a>
                                                </div>
                                        </header>

                                        <div class="ppa-videos-cards">
                                                <?php if ( empty( $items ) ) : ?>
                                                        <div class="ppa-video-card">
                                                                <div class="ppa-video-thumb">
                                                                        <span class="ppa-video-thumb-label"><?php echo esc_html__( 'No videos yet', 'postpress-ai' ); ?></span>
                                                                        <span class="ppa-video-dur"> </span>
                                                                </div>
                                                                <div class="ppa-video-meta">
                                                                        <div class="ppa-video-title"><?php echo esc_html__( 'No videos were found in this playlist yet.', 'postpress-ai' ); ?></div>
                                                                        <div class="ppa-video-row">
                                                                                <span class="button ppa-video-watch" aria-disabled="true"><?php echo esc_html__( 'Coming soon', 'postpress-ai' ); ?></span>
                                                                        </div>
                                                                </div>
                                                        </div>
                                                <?php else : ?>
                                                        <?php foreach ( $items as $v ) : ?>
                                                                <?php
                                                                        $yt_id = isset( $v['yt'] ) ? (string) $v['yt'] : '';
                                                                        if ( $yt_id === '' ) { continue; }

                                                                        $thumb = 'https://i.ytimg.com/vi/' . rawurlencode( $yt_id ) . '/hqdefault.jpg';
                                                                        $embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $yt_id ) . '?autoplay=1&rel=0';
                                                                        $watch = add_query_arg(
                                                                                array(
                                                                                        'TB_iframe' => 'true',
                                                                                        'width'     => 960,
                                                                                        'height'    => 540,
                                                                                ),
                                                                                $embed
                                                                        );

                                                                        $title = isset( $v['title'] ) ? (string) $v['title'] : '';
                                                                        $title = $title !== '' ? $title : __( 'Video', 'postpress-ai' );
                                                                ?>
                                                                <div class="ppa-video-card">
                                                                        <div class="ppa-video-thumb">
                                                                                <img class="ppa-video-thumb-img" src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
                                                                                <span class="ppa-video-thumb-label"><?php echo esc_html__( 'Video', 'postpress-ai' ); ?></span>
                                                                        </div>
                                                                        <div class="ppa-video-meta">
                                                                                <div class="ppa-video-title"><?php echo esc_html( $title ); ?></div>
                                                                                <div class="ppa-video-row">
                                                                                        <a class="button button-secondary ppa-video-watch thickbox" href="<?php echo esc_url( $watch ); ?>"><?php echo esc_html__( 'Watch', 'postpress-ai' ); ?></a>
                                                                                </div>
                                                                        </div>
                                                                </div>
                                                        <?php endforeach; ?>
                                                <?php endif; ?>
                                        </div>
                                </section>
                        </div>
                </div>
                <?php
        }                                                                                                              // CHANGED:
}                                                                                                                      // CHANGED:



if ( ! function_exists( 'ppa_render_testbed' ) ) {
        function ppa_render_testbed() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
                }

                $base = ( defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ) ) . 'inc/admin/'; // CHANGED:

                $candidates = array(
                        $base . 'ppa-testbed.php',
                        $base . 'testbed.php',
                );

                foreach ( $candidates as $file ) {
                        if ( file_exists( $file ) ) {
                                require $file;
                                return;
                        }
                }

                // Fallback UI — no inline JS/CSS; centralized enqueue provides styles/scripts.
                error_log( 'PPA: testbed UI not found in inc/admin/ — using fallback markup' ); // failure only
                ?>
                <div class="wrap ppa-testbed-wrap">
                        <h1><?php echo esc_html__( 'Testbed', 'postpress-ai' ); ?></h1>
                        <p class="ppa-hint">
                                <?php echo esc_html__( 'This is the PostPress AI Testbed. Use it to send preview/draft requests to the backend.', 'postpress-ai' ); ?>
                        </p>

                        <div id="ppa-testbed-status" class="ppa-notice" role="status" aria-live="polite"></div>

                        <div class="ppa-form-group">
                                <label for="ppa-testbed-input"><?php echo esc_html__( 'Payload (JSON or brief text)', 'postpress-ai' ); ?></label>
                                <textarea id="ppa-testbed-input" rows="8" placeholder="<?php echo esc_attr__( 'Enter JSON for advanced control or plain text for a quick brief…', 'postpress-ai' ); ?>"></textarea>
                        </div>

                        <div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Testbed actions', 'postpress-ai' ); ?>">
                                <button id="ppa-testbed-preview" class="ppa-btn" type="button"><?php echo esc_html__( 'Preview', 'postpress-ai' ); ?></button>
                                <button id="ppa-testbed-store" class="ppa-btn ppa-btn-secondary" type="button"><?php echo esc_html__( 'Save to Draft', 'postpress-ai' ); ?></button>
                        </div>

                        <h2 class="screen-reader-text"><?php echo esc_html__( 'Response Output', 'postpress-ai' ); ?></h2>
                        <pre id="ppa-testbed-output" aria-live="polite" aria-label="<?php echo esc_attr__( 'Preview or store response output', 'postpress-ai' ); ?>"></pre>
                </div>
                <?php
        }
}