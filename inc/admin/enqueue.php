<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2025-12-27 • FIX: Detect Settings screen reliably and enqueue admin-settings.css there.                  # CHANGED:
 * 2025-12-27 • FIX: Enforce UNBREAKABLE RULE — Settings loads ONLY admin-settings.css (NOT admin.css).    # CHANGED:
 * 2025-12-27 • FIX: Expand "is our page" gating so Settings gets CSS while keeping JS only on main/testbed.# CHANGED:
 *
 * 2025-12-10.2 • Enqueue ppa-admin-editor.js (editor helpers) between core and admin.js; no behavior change.      # CHANGED:
 * 2025-12-20.2 • Register modular admin modules (ppa-admin-*.js) and enqueue only when non-empty; keep admin.js as boot. # CHANGED:
 * 2025-12-09.1 • Enqueue ppa-admin-core.js before admin.js so shared helpers live in PPAAdmin.core; no behavior change. # CHANGED:
 * 2025-11-22 • Enqueue admin-preview-spinner.js on Composer screen after admin.js for preview/generate spinner.  # CHANGED:
 * 2025-11-13 • Force https scheme for admin/testbed asset URLs to avoid 301s/mixed content.                      # CHANGED:
 * 2025-11-11 • Map $hook → screen id fallback to improve reliability under WP-CLI and edge admin contexts.      # CHANGED:
 * 2025-11-10 • CLI-safe: avoid get_current_screen() under WP-CLI to prevent fatals during wp eval.             # CHANGED:
 * 2025-11-10 • Aggressive purge: scan registry and dequeue/deregister ANY handle whose src matches our assets. # CHANGED:
 * 2025-11-10 • FORCE fresh versions: purge then re-register so filemtime ?ver wins.                             # CHANGED:
 * 2025-11-10 • ACCEPT $hook param for admin_enqueue_scripts compatibility.                                      # (prev)
 * 2025-11-10 • ADD wpNonce to window.PPA/ppaAdmin; cache-bust ppa-testbed.js by filemtime.                      # (prev)
 * 2025-11-08 • Cache-busted admin.css/js via filemtime; expose cssVer/jsVer on window.PPA; depend admin.js on config. # (prev)
 */

defined('ABSPATH') || exit;

/**
 * Dev-only switch:
 * - Default OFF (no Testbed UI, no Testbed assets)
 * - Enable by adding to wp-config.php:
 *     define('PPA_ENABLE_TESTBED', true);
 */
if (!defined('PPA_ENABLE_TESTBED')) {                                                               // CHANGED:
    define('PPA_ENABLE_TESTBED', false);                                                            // CHANGED:
}                                                                                                    // CHANGED:

if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue($hook = '') {
        // ---------------------------------------------------------------------
        // Resolve plugin paths/URLs once
        // ---------------------------------------------------------------------
        $plugin_root_dir  = dirname(dirname(__DIR__));  // .../wp-content/plugins/postpress-ai
        $plugin_main_file = $plugin_root_dir . '/postpress-ai.php';

        // Asset rel paths
        $admin_core_js_rel           = 'assets/js/ppa-admin-core.js';
        $admin_editor_js_rel         = 'assets/js/ppa-admin-editor.js';
        $admin_api_js_rel            = 'assets/js/ppa-admin-api.js';
        $admin_payloads_js_rel       = 'assets/js/ppa-admin-payloads.js';
        $admin_notices_js_rel        = 'assets/js/ppa-admin-notices.js';
        $admin_generate_view_js_rel  = 'assets/js/ppa-admin-generate-view.js';
        $admin_comp_preview_js_rel   = 'assets/js/ppa-admin-composer-preview.js';
        $admin_comp_generate_js_rel  = 'assets/js/ppa-admin-composer-generate.js';
        $admin_comp_store_js_rel     = 'assets/js/ppa-admin-composer-store.js';
        $admin_js_rel                = 'assets/js/admin.js';
        $admin_css_rel               = 'assets/css/admin.css';
        $admin_settings_css_rel      = 'assets/css/admin-settings.css';                                                // CHANGED:
        $testbed_js_rel              = 'inc/admin/ppa-testbed.js';
        $admin_spinner_rel           = 'assets/js/admin-preview-spinner.js';

        // Asset files (for filemtime)
        $admin_core_js_file           = $plugin_root_dir . '/' . $admin_core_js_rel;
        $admin_editor_js_file         = $plugin_root_dir . '/' . $admin_editor_js_rel;
        $admin_api_js_file            = $plugin_root_dir . '/' . $admin_api_js_rel;
        $admin_payloads_js_file       = $plugin_root_dir . '/' . $admin_payloads_js_rel;
        $admin_notices_js_file        = $plugin_root_dir . '/' . $admin_notices_js_rel;
        $admin_generate_view_js_file  = $plugin_root_dir . '/' . $admin_generate_view_js_rel;
        $admin_comp_preview_js_file   = $plugin_root_dir . '/' . $admin_comp_preview_js_rel;
        $admin_comp_generate_js_file  = $plugin_root_dir . '/' . $admin_comp_generate_js_rel;
        $admin_comp_store_js_file     = $plugin_root_dir . '/' . $admin_comp_store_js_rel;
        $admin_js_file                = $plugin_root_dir . '/' . $admin_js_rel;
        $admin_css_file               = $plugin_root_dir . '/' . $admin_css_rel;
        $admin_settings_css_file      = $plugin_root_dir . '/' . $admin_settings_css_rel;                                     // CHANGED:
        $testbed_js_file              = $plugin_root_dir . '/' . $testbed_js_rel;
        $admin_spinner_file           = $plugin_root_dir . '/' . $admin_spinner_rel;

        // Asset URLs (prefer PPA_PLUGIN_URL if defined)
        if (defined('PPA_PLUGIN_URL')) {
            $base_url                    = rtrim(PPA_PLUGIN_URL, '/');
            $admin_core_js_url           = $base_url . '/' . $admin_core_js_rel;
            $admin_editor_js_url         = $base_url . '/' . $admin_editor_js_rel;
            $admin_api_js_url            = $base_url . '/' . $admin_api_js_rel;
            $admin_payloads_js_url       = $base_url . '/' . $admin_payloads_js_rel;
            $admin_notices_js_url        = $base_url . '/' . $admin_notices_js_rel;
            $admin_generate_view_js_url  = $base_url . '/' . $admin_generate_view_js_rel;
            $admin_comp_preview_js_url   = $base_url . '/' . $admin_comp_preview_js_rel;
            $admin_comp_generate_js_url  = $base_url . '/' . $admin_comp_generate_js_rel;
            $admin_comp_store_js_url     = $base_url . '/' . $admin_comp_store_js_rel;
            $admin_js_url                = $base_url . '/' . $admin_js_rel;
            $admin_css_url               = $base_url . '/' . $admin_css_rel;
            $admin_settings_css_url      = $base_url . '/' . $admin_settings_css_rel;                                          // CHANGED:
            $testbed_js_url              = $base_url . '/' . $testbed_js_rel;
            $admin_spinner_url           = $base_url . '/' . $admin_spinner_rel;
        } else {
            $admin_core_js_url           = plugins_url($admin_core_js_rel,   $plugin_main_file);
            $admin_editor_js_url         = plugins_url($admin_editor_js_rel, $plugin_main_file);
            $admin_api_js_url            = plugins_url($admin_api_js_rel,           $plugin_main_file);
            $admin_payloads_js_url       = plugins_url($admin_payloads_js_rel,      $plugin_main_file);
            $admin_notices_js_url        = plugins_url($admin_notices_js_rel,       $plugin_main_file);
            $admin_generate_view_js_url  = plugins_url($admin_generate_view_js_rel, $plugin_main_file);
            $admin_comp_preview_js_url   = plugins_url($admin_comp_preview_js_rel,  $plugin_main_file);
            $admin_comp_generate_js_url  = plugins_url($admin_comp_generate_js_rel, $plugin_main_file);
            $admin_comp_store_js_url     = plugins_url($admin_comp_store_js_rel,    $plugin_main_file);
            $admin_js_url                = plugins_url($admin_js_rel,        $plugin_main_file);
            $admin_css_url               = plugins_url($admin_css_rel,       $plugin_main_file);
            $admin_settings_css_url      = plugins_url($admin_settings_css_rel, $plugin_main_file);                                            // CHANGED:
            $testbed_js_url              = plugins_url($testbed_js_rel,      $plugin_main_file);
            $admin_spinner_url           = plugins_url($admin_spinner_rel,   $plugin_main_file);
        }

        // Force HTTPS scheme for all admin/testbed asset URLs                                                     // CHANGED:
        if (function_exists('set_url_scheme')) {                                                                    // CHANGED:
            $admin_core_js_url           = set_url_scheme($admin_core_js_url, 'https');                               // CHANGED:
            $admin_editor_js_url         = set_url_scheme($admin_editor_js_url, 'https');                             // CHANGED:
            $admin_api_js_url           = set_url_scheme($admin_api_js_url, 'https');                         // CHANGED:
            $admin_payloads_js_url      = set_url_scheme($admin_payloads_js_url, 'https');                    // CHANGED:
            $admin_notices_js_url       = set_url_scheme($admin_notices_js_url, 'https');                     // CHANGED:
            $admin_generate_view_js_url = set_url_scheme($admin_generate_view_js_url, 'https');               // CHANGED:
            $admin_comp_preview_js_url  = set_url_scheme($admin_comp_preview_js_url, 'https');                // CHANGED:
            $admin_comp_generate_js_url = set_url_scheme($admin_comp_generate_js_url, 'https');               // CHANGED:
            $admin_comp_store_js_url    = set_url_scheme($admin_comp_store_js_url, 'https');                  // CHANGED:
            $admin_js_url                = set_url_scheme($admin_js_url, 'https');                                        // CHANGED:
            $admin_css_url               = set_url_scheme($admin_css_url, 'https');                                       // CHANGED:
            $admin_settings_css_url      = set_url_scheme($admin_settings_css_url, 'https');                              // CHANGED:
            $testbed_js_url              = set_url_scheme($testbed_js_url, 'https');                                      // CHANGED:
            $admin_spinner_url           = set_url_scheme($admin_spinner_url, 'https');                                   // CHANGED:
        }                                                                                                          // CHANGED:

        // Versions (cache-bust by file mtime, safe fallback to time())
        $admin_core_js_ver           = file_exists($admin_core_js_file)   ? (string) filemtime($admin_core_js_file)   : (string) time(); // CHANGED:
        $admin_editor_js_ver         = file_exists($admin_editor_js_file) ? (string) filemtime($admin_editor_js_file) : (string) time(); // CHANGED:
        $admin_api_js_ver           = file_exists($admin_api_js_file)           ? (string) filemtime($admin_api_js_file)           : (string) time(); // CHANGED:
        $admin_payloads_js_ver      = file_exists($admin_payloads_js_file)      ? (string) filemtime($admin_payloads_js_file)      : (string) time(); // CHANGED:
        $admin_notices_js_ver       = file_exists($admin_notices_js_file)       ? (string) filemtime($admin_notices_js_file)       : (string) time(); // CHANGED:
        $admin_generate_view_js_ver = file_exists($admin_generate_view_js_file) ? (string) filemtime($admin_generate_view_js_file) : (string) time(); // CHANGED:
        $admin_comp_preview_js_ver  = file_exists($admin_comp_preview_js_file)  ? (string) filemtime($admin_comp_preview_js_file)  : (string) time(); // CHANGED:
        $admin_comp_generate_js_ver = file_exists($admin_comp_generate_js_file) ? (string) filemtime($admin_comp_generate_js_file) : (string) time(); // CHANGED:
        $admin_comp_store_js_ver    = file_exists($admin_comp_store_js_file)    ? (string) filemtime($admin_comp_store_js_file)    : (string) time(); // CHANGED:
        $admin_js_ver                = file_exists($admin_js_file)     ? (string) filemtime($admin_js_file)        : (string) time();
        $admin_css_ver               = file_exists($admin_css_file)    ? (string) filemtime($admin_css_file)       : (string) time();
        $admin_settings_css_ver      = file_exists($admin_settings_css_file) ? (string) filemtime($admin_settings_css_file) : (string) time(); // CHANGED:
        $testbed_js_ver              = file_exists($testbed_js_file)   ? (string) filemtime($testbed_js_file)      : (string) time();
        $admin_spinner_ver           = file_exists($admin_spinner_file)   ? (string) filemtime($admin_spinner_file)   : (string) time(); // CHANGED:

        // ---------------------------------------------------------------------
        // Determine whether this is our admin page
        // ---------------------------------------------------------------------
        $is_cli    = defined('WP_CLI') && WP_CLI;
        $screen    = null;
        $screen_id = '';
        if (!$is_cli && function_exists('get_current_screen')) {
            try {
                $screen = get_current_screen();
            } catch (Throwable $e) {
                $screen = null;
            }
            $screen_id = $screen ? $screen->id : '';
        }

        // Screen IDs / hooks
        $slug_main_ui         = 'toplevel_page_postpress-ai';                      // top-level Composer page
        $slug_settings        = 'postpress-ai_page_postpress-ai-settings';         // Settings submenu            # CHANGED:
        $slug_testbed         = 'postpress-ai_page_postpress-ai-testbed';

        // Fallback logic: $hook param is reliable, $screen_id can be empty under edge contexts
        $is_main_ui   = ($hook === $slug_main_ui)   || ($screen_id === $slug_main_ui)   || ($page_param === 'postpress-ai');
        $is_settings  = ($hook === $slug_settings)  || ($screen_id === $slug_settings)  || ($page_param === 'postpress-ai-settings'); // CHANGED:
        $is_testbed   = ($hook === $slug_testbed)   || ($screen_id === $slug_testbed)   || ($page_param === 'postpress-ai-testbed');

        // Our pages (used to gate purge + config)
        $is_ppa_page = ($is_main_ui || $is_settings || $is_testbed);                                                   // CHANGED:

        if (!$is_ppa_page) {                                                                                          // CHANGED:
            return;                                                                                                    // CHANGED:
        }                                                                                                              // CHANGED:

        // ---------------------------------------------------------------------
        // ROBUST SCOPE (LOCKED): treat any matching "postpress-ai" admin context as ours
        // This prevents brittle exact-matches from silently skipping config+nonce.
        // ---------------------------------------------------------------------
        $hook_str = is_string($hook) ? $hook : '';                                                             // CHANGED:
        $is_ppa_context = (                                                                                       // CHANGED:
            ($page_param && strpos($page_param, 'postpress-ai') === 0)                                            // CHANGED:
            || ($screen_id && strpos($screen_id, 'postpress-ai') !== false)                                       // CHANGED:
            || ($hook_str && strpos($hook_str, 'postpress-ai') !== false)                                         // CHANGED:
        );                                                                                                       // CHANGED:

        $is_main_ui  = $is_ppa_context && (
            ($hook_str === $slug_main_ui) || ($screen_id === $slug_main_ui) || ($page_param === 'postpress-ai')
        );

        $is_testbed  = (defined('PPA_ENABLE_TESTBED') && PPA_ENABLE_TESTBED) && (
            ($hook_str === $slug_testbed) || ($screen_id === $slug_testbed) || ($page_param === 'postpress-ai-testbed')
        );

        $is_settings = $is_ppa_context && (
            ($hook_str === $slug_settings) || ($screen_id === $slug_settings) || ($page_param === 'postpress-ai-settings')
        );

        if (!$is_ppa_context || (!$is_main_ui && !$is_testbed && !$is_settings)) {                               // CHANGED:
            return;
        }

        // ---------------------------------------------------------------------
        // Aggressive purge
        // ---------------------------------------------------------------------
        $rel_path = 'postpress-ai/';

        $purge_by_rel = function($needle, $type) use ($rel_path) {
            if ($type === 'script') {
                $sc = wp_scripts();
                if ($sc && !empty($sc->registered)) {
                    foreach ($sc->registered as $h => $dep) {
                        $src = isset($dep->src) ? (string) $dep->src : '';
                        if ($src && strpos($src, $rel_path) !== false && strpos($src, $needle) !== false) {
                            if (wp_script_is($h, 'enqueued')) {
                                wp_dequeue_script($h);
                            }
                            if (wp_script_is($h, 'registered')) {
                                wp_deregister_script($h);
                            }
                        }
                    }
                }
            } else {
                $st = wp_styles();
                if ($st && !empty($st->registered)) {
                    foreach ($st->registered as $h => $dep) {
                        $src = isset($dep->src) ? (string) $dep->src : '';
                        if ($src && strpos($src, $rel_path) !== false && strpos($src, $needle) !== false) {
                            if (wp_style_is($h, 'enqueued')) {
                                wp_dequeue_style($h);
                            }
                            if (wp_style_is($h, 'registered')) {
                                wp_deregister_style($h);
                            }
                        }
                    }
                }
            }
        };

        // Purge our known handles by rel needle
        $purge_by_rel($admin_core_js_rel,  'script');                                                                // CHANGED:
        $purge_by_rel($admin_editor_js_rel, 'script');                                                               // CHANGED:
        $purge_by_rel($admin_api_js_rel,           'script');  // CHANGED:
        $purge_by_rel($admin_payloads_js_rel,      'script');  // CHANGED:
        $purge_by_rel($admin_notices_js_rel,       'script');  // CHANGED:
        $purge_by_rel($admin_generate_view_js_rel, 'script');  // CHANGED:
        $purge_by_rel($admin_comp_preview_js_rel,  'script');  // CHANGED:
        $purge_by_rel($admin_comp_generate_js_rel, 'script');  // CHANGED:
        $purge_by_rel($admin_comp_store_js_rel,    'script');  // CHANGED:
        $purge_by_rel($admin_js_rel,        'script');                                                                 // (kept)
        $purge_by_rel($admin_css_rel,       'style');                                                                  // (kept)
        $purge_by_rel($admin_settings_css_rel, 'style');                                                               // CHANGED:
        $purge_by_rel($testbed_js_rel,      'script');                                                                 // (kept)
        $purge_by_rel($admin_spinner_rel,   'script');                                                                 // CHANGED:

        // ---------------------------------------------------------------------
        // Enqueue assets
        // ---------------------------------------------------------------------

        /**
         * CSS RULES:
         * - Settings loads ONLY admin-settings.css (no admin.css).                                      # CHANGED:
         * - Composer/Testbed use admin.css.                                                            # CHANGED:
         */

        if ($is_settings) {                                                                                           // CHANGED:
            // Settings CSS only (UNBREAKABLE RULE)
            wp_register_style('ppa-admin-settings-css', $admin_settings_css_url, array(), $admin_settings_css_ver, 'all'); // CHANGED:
            wp_enqueue_style('ppa-admin-settings-css');                                                               // CHANGED:
            return;                                                                                                   // CHANGED: no JS stack needed on Settings
        }                                                                                                             // CHANGED:

        // Composer + Testbed
        if ($is_main_ui || $is_testbed) {
            $cfg = array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => esc_url_raw(rest_url()),
                'page'    => $page_param,
                'jsVer'   => $admin_js_ver,
                'wpNonce' => wp_create_nonce('wp_rest'),
                'nonce'   => wp_create_nonce('ppa-admin'), // CHANGED:
            );

            // IMPORTANT: Use a real (empty) script src so WordPress reliably prints the tag and inline JS.            // CHANGED:
            $empty_src = 'data:text/javascript;base64,';                                                              // CHANGED:
            wp_register_script('ppa-admin-config', $empty_src, array(), null, true);                                  // CHANGED:
            wp_enqueue_script('ppa-admin-config');                                                                    // CHANGED:

            $inline = 'window.PPA = window.PPA || {}; window.PPA = Object.assign(window.PPA, ' . wp_json_encode($cfg) . ');';
            wp_add_inline_script('ppa-admin-config', $inline, 'before');

            wp_register_script(
                'ppa-admin-core',
                $admin_core_js_url,
                array('jquery', 'ppa-admin-config'),
                $admin_core_js_ver,
                true
            );
            wp_enqueue_script('ppa-admin-core');

            $active_modules = array();
            $maybe_enqueue = function($handle, $url, $deps, $ver, $file) use (&$active_modules) {
                wp_register_script($handle, $url, $deps, $ver, true);
                if ($file && file_exists($file) && filesize($file) > 0) {
                    $active_modules[] = $handle;
                    wp_enqueue_script($handle);
                }
            };

            $maybe_enqueue('ppa-admin-api',      $admin_api_js_url,      array('jquery','ppa-admin-config','ppa-admin-core'), $admin_api_js_ver,      $admin_api_js_file);
            $maybe_enqueue('ppa-admin-payloads', $admin_payloads_js_url, array('jquery','ppa-admin-config','ppa-admin-core'), $admin_payloads_js_ver, $admin_payloads_js_file);
            $maybe_enqueue('ppa-admin-notices',  $admin_notices_js_url,  array('jquery','ppa-admin-config','ppa-admin-core'), $admin_notices_js_ver,  $admin_notices_js_file);

            wp_register_script(
                'ppa-admin-editor',
                $admin_editor_js_url,
                array('jquery', 'ppa-admin-config', 'ppa-admin-core'),
                $admin_editor_js_ver,
                true
            );
            wp_enqueue_script('ppa-admin-editor');

            $maybe_enqueue('ppa-admin-generate-view',     $admin_generate_view_js_url, array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor'), $admin_generate_view_js_ver, $admin_generate_view_js_file);
            $maybe_enqueue('ppa-admin-composer-preview',  $admin_comp_preview_js_url,  array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor'), $admin_comp_preview_js_ver,  $admin_comp_preview_js_file);
            $maybe_enqueue('ppa-admin-composer-generate', $admin_comp_generate_js_url, array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor'), $admin_comp_generate_js_ver, $admin_comp_generate_js_file);
            $maybe_enqueue('ppa-admin-composer-store',    $admin_comp_store_js_url,    array('jquery','ppa-admin-config','ppa-admin-core','ppa-admin-editor'), $admin_comp_store_js_ver,    $admin_comp_store_js_file);

            wp_register_script(
                'ppa-admin',
                $admin_js_url,
                array_merge(array('jquery', 'ppa-admin-config', 'ppa-admin-core', 'ppa-admin-editor'), $active_modules),
                $admin_js_ver,
                true
            );
            wp_localize_script('ppa-admin', 'ppaAdmin', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ppa-admin'),
                'jsVer'   => $admin_js_ver,
                'wpNonce' => wp_create_nonce('wp_rest'),
            ));
            wp_enqueue_script('ppa-admin');

            if ($is_main_ui && file_exists($admin_spinner_file)) {
                wp_register_script(
                    'ppa-admin-preview-spinner',
                    $admin_spinner_url,
                    array('ppa-admin'),
                    $admin_spinner_ver,
                    true
                );
                wp_enqueue_script('ppa-admin-preview-spinner');
            }

            if ($is_testbed && file_exists($testbed_js_file)) {
                wp_register_script('ppa-testbed', $testbed_js_url, array('ppa-admin-config'), $testbed_js_ver, true);
                wp_enqueue_script('ppa-testbed');
            }
        }
    }
}

// Ensure hook is registered (some restore states lose this line).                                      # CHANGED:
add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 9);                                           # CHANGED:
