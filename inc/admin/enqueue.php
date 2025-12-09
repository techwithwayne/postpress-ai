<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2025-12-09.1 • Enqueue ppa-admin-core.js before admin.js so shared helpers live in PPAAdmin.core; no behavior change. # CHANGED:
 * 2025-11-22 • Enqueue admin-preview-spinner.js on Composer screen after admin.js for preview/generate spinner. # CHANGED:
 * 2025-11-13 • Force https scheme for admin/testbed asset URLs to avoid 301s/mixed content.                    # CHANGED:
 * 2025-11-11 • Map $hook → screen id fallback to improve reliability under WP-CLI and edge admin contexts.      # CHANGED:
 * 2025-11-10 • CLI-safe: avoid get_current_screen() under WP-CLI to prevent fatals during wp eval.             # CHANGED:
 * 2025-11-10 • Aggressive purge: scan registry and dequeue/deregister ANY handle whose src matches our assets. # CHANGED:
 * 2025-11-10 • FORCE fresh versions: purge then re-register so filemtime ?ver wins.                             # CHANGED:
 * 2025-11-10 • ACCEPT $hook param for admin_enqueue_scripts compatibility.                                      # (prev)
 * 2025-11-10 • ADD wpNonce to window.PPA/ppaAdmin; cache-bust ppa-testbed.js by filemtime.                      # (prev)
 * 2025-11-08 • Cache-busted admin.css/js via filemtime; expose cssVer/jsVer on window.PPA; depend admin.js on config. # (prev)
 */

defined('ABSPATH') || exit;

if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue($hook = '') {                                                                              // (kept)
        // ---------------------------------------------------------------------
        // Resolve plugin paths/URLs once
        // ---------------------------------------------------------------------
        $plugin_root_dir  = dirname(dirname(__DIR__));  // .../wp-content/plugins/postpress-ai
        $plugin_main_file = $plugin_root_dir . '/postpress-ai.php';

        // Asset rel paths
        $admin_core_js_rel = 'assets/js/ppa-admin-core.js';                                                               // CHANGED:
        $admin_js_rel      = 'assets/js/admin.js';
        $admin_css_rel     = 'assets/css/admin.css';
        $testbed_js_rel    = 'inc/admin/ppa-testbed.js';
        $admin_spinner_rel = 'assets/js/admin-preview-spinner.js';                                                       // CHANGED:

        // Asset files (for filemtime)
        $admin_core_js_file = $plugin_root_dir . '/' . $admin_core_js_rel;                                               // CHANGED:
        $admin_js_file      = $plugin_root_dir . '/' . $admin_js_rel;
        $admin_css_file     = $plugin_root_dir . '/' . $admin_css_rel;
        $testbed_js_file    = $plugin_root_dir . '/' . $testbed_js_rel;
        $admin_spinner_file = $plugin_root_dir . '/' . $admin_spinner_rel;                                               // CHANGED:

        // Asset URLs (prefer PPA_PLUGIN_URL if defined)
        if (defined('PPA_PLUGIN_URL')) {
            $base_url          = rtrim(PPA_PLUGIN_URL, '/');
            $admin_core_js_url = $base_url . '/' . $admin_core_js_rel;                                                   // CHANGED:
            $admin_js_url      = $base_url . '/' . $admin_js_rel;
            $admin_css_url     = $base_url . '/' . $admin_css_rel;
            $testbed_js_url    = $base_url . '/' . $testbed_js_rel;
            $admin_spinner_url = $base_url . '/' . $admin_spinner_rel;                                                   // CHANGED:
        } else {
            $admin_core_js_url = plugins_url($admin_core_js_rel, $plugin_main_file);                                     // CHANGED:
            $admin_js_url      = plugins_url($admin_js_rel,  $plugin_main_file);
            $admin_css_url     = plugins_url($admin_css_rel, $plugin_main_file);
            $testbed_js_url    = plugins_url($testbed_js_rel, $plugin_main_file);
            $admin_spinner_url = plugins_url($admin_spinner_rel, $plugin_main_file);                                     // CHANGED:
        }

        // Force HTTPS scheme for all admin/testbed asset URLs                                                     // CHANGED:
        if (function_exists('set_url_scheme')) {                                                                   // CHANGED:
            $admin_core_js_url = set_url_scheme($admin_core_js_url, 'https');                                     // CHANGED:
            $admin_js_url      = set_url_scheme($admin_js_url, 'https');                                          // CHANGED:
            $admin_css_url     = set_url_scheme($admin_css_url, 'https');                                         // CHANGED:
            $testbed_js_url    = set_url_scheme($testbed_js_url, 'https');                                        // CHANGED:
            $admin_spinner_url = set_url_scheme($admin_spinner_url, 'https');                                     // CHANGED:
        }                                                                                                         // CHANGED:

        // Versions (cache-bust by file mtime, safe fallback to time())
        $admin_core_js_ver = file_exists($admin_core_js_file) ? (string) filemtime($admin_core_js_file) : (string) time(); // CHANGED:
        $admin_js_ver      = file_exists($admin_js_file)      ? (string) filemtime($admin_js_file)      : (string) time();
        $admin_css_ver     = file_exists($admin_css_file)     ? (string) filemtime($admin_css_file)     : (string) time();
        $testbed_js_ver    = file_exists($testbed_js_file)    ? (string) filemtime($testbed_js_file)    : (string) time();
        $admin_spinner_ver = file_exists($admin_spinner_file) ? (string) filemtime($admin_spinner_file) : (string) time(); // CHANGED:

        // ---------------------------------------------------------------------
        // Determine target screens — CLI-safe (no get_current_screen() under WP-CLI)
        // ---------------------------------------------------------------------
        $is_cli    = defined('WP_CLI') && WP_CLI;                                                                   // (kept)
        $screen    = null;
        $screen_id = '';
        if (!$is_cli && function_exists('get_current_screen')) {                                                    // (kept)
            try {
                $screen = get_current_screen();
            } catch (Throwable $e) {
                $screen = null;
            }                                                                                                       // (kept)
            $screen_id = $screen ? $screen->id : '';
        }
        $page_param = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';

        // Screen IDs
        $slug_main_ui         = 'toplevel_page_postpress-ai';      // top-level Composer page
        $slug_testbed_legacy  = 'tools_page_ppa-testbed';          // legacy Tools screen id
        $slug_testbed_newmenu = 'postpress-ai_page_ppa-testbed';   // submenu under our top-level

        // NEW: derive screen id from $hook if get_current_screen() didn’t give us one                         // CHANGED:
        $hook_id = is_string($hook) ? $hook : '';                                                          // CHANGED:
        if (!$screen_id && $hook_id) {                                                                     // CHANGED:
            if ($hook_id === $slug_main_ui) {                                                              // CHANGED:
                $screen_id = $slug_main_ui;                                                                // CHANGED:
            } elseif ($hook_id === $slug_testbed_newmenu || $hook_id === $slug_testbed_legacy) {           // CHANGED:
                $screen_id = $hook_id;                                                                     // CHANGED:
            }
        }                                                                                                   // CHANGED:

        // Booleans for where to enqueue
        $is_main_ui = ($screen_id === $slug_main_ui) || ($page_param === 'postpress-ai');
        $is_testbed = in_array($screen_id, array($slug_testbed_newmenu, $slug_testbed_legacy), true) || ($page_param === 'ppa-testbed');

        // ---------------------------------------------------------------------
        // Build shared config (window.PPA) — exposed before admin.js
        // ---------------------------------------------------------------------
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => esc_url_raw(rest_url()),
            'page'    => $page_param,
            'cssVer'  => $admin_css_ver,
            'jsVer'   => $admin_js_ver,
            'wpNonce' => wp_create_nonce('wp_rest'),
        );

        wp_register_script('ppa-admin-config', false, array(), null, true);
        wp_enqueue_script('ppa-admin-config');
        wp_add_inline_script('ppa-admin-config', 'window.PPA = ' . wp_json_encode($cfg) . ';', 'before');

        // ---------------------------------------------------------------------
        // Aggressive purge of any previously registered/enqueued duplicates
        // (works even if other code used different handles)
        // ---------------------------------------------------------------------
        $purge_by_rel = function ($rel_path, $type) {                                                                 // (kept)
            if ($type === 'script') {
                $ws = wp_scripts();
                if ($ws && !empty($ws->registered)) {
                    foreach ($ws->registered as $h => $dep) {
                        $src = isset($dep->src) ? (string) $dep->src : '';
                        if ($src && strpos($src, $rel_path) !== false) {
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
                        if ($src && strpos($src, $rel_path) !== false) {
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
        $purge_by_rel($admin_core_js_rel, 'script');                                                                    // CHANGED:
        $purge_by_rel($admin_js_rel,      'script');                                                                    // (kept)
        $purge_by_rel($admin_css_rel,     'style');                                                                     // (kept)
        $purge_by_rel($testbed_js_rel,    'script');                                                                    // (kept)
        $purge_by_rel($admin_spinner_rel, 'script');                                                                    // CHANGED:

        // ---------------------------------------------------------------------
        // Enqueue assets on main UI and Testbed (CLI will generally skip)
        // ---------------------------------------------------------------------
        if ($is_main_ui || $is_testbed) {
            // CSS
            wp_register_style('ppa-admin-css', $admin_css_url, array(), $admin_css_ver, 'all');
            wp_enqueue_style('ppa-admin-css');

            // JS core helpers — must load after config so window.PPA is present                           // CHANGED:
            wp_register_script(                                                                            // CHANGED:
                'ppa-admin-core',                                                                          // CHANGED:
                $admin_core_js_url,                                                                        // CHANGED:
                array('jquery', 'ppa-admin-config'),                                                       // CHANGED:
                $admin_core_js_ver,                                                                        // CHANGED:
                true                                                                                       // CHANGED:
            );                                                                                             // CHANGED:
            wp_enqueue_script('ppa-admin-core');                                                           // CHANGED:

            // JS — depend on config + core so window.PPA and PPAAdmin.core exist before admin.js runs
            wp_register_script(
                'ppa-admin',
                $admin_js_url,
                array('jquery', 'ppa-admin-config', 'ppa-admin-core'),                                     // CHANGED:
                $admin_js_ver,
                true
            );
            wp_localize_script('ppa-admin', 'ppaAdmin', array(
                'ajaxurl' => $cfg['ajaxUrl'],
                'nonce'   => wp_create_nonce('ppa-admin'),
                'cssVer'  => $cfg['cssVer'],
                'jsVer'   => $cfg['jsVer'],
                'wpNonce' => $cfg['wpNonce'],
            ));
            wp_enqueue_script('ppa-admin');

            // Composer-only preview spinner (after admin.js)
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

            // Testbed-only helper JS
            if ($is_testbed) {
                wp_register_script('ppa-testbed', $testbed_js_url, array('ppa-admin-config'), $testbed_js_ver, true);
                wp_enqueue_script('ppa-testbed');
            }
        }

        // (Placeholders) Composer assets on main UI if needed later
        if ($is_main_ui) {
            // wp_enqueue_style(...); wp_enqueue_script(...);
        }
    }
}
