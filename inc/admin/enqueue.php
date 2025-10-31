<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * CHANGE LOG
 * 2025-10-30 • FIX: Load admin.js on Tools→PPA Testbed by correcting screen id to
 *              'tools_page_ppa-testbed' and retaining fallback via ?page=ppa-testbed.    # CHANGED:
 * 2025-10-30 • Enqueue external admin script (assets/js/admin.js) on both main UI (?page=postpress-ai)
 *              and Tools→PPA Testbed; cache-bust via filemtime; localize `ppaAdmin` (ajaxurl, nonce);
 *              keep lightweight window.PPA config and existing testbed logic.
 * 2025-10-29 • Add safe, centralized enqueue for admin assets; conditionally load ppa-testbed.js
 *              on the PPA Testbed screen only; expose minimal window.PPA config (ajaxUrl).
 */

defined('ABSPATH') || exit;

if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue() {                                                                                 // CHANGED:
        // Minimal config available early for any inline bootstrap
        $base_cfg = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        );

        wp_register_script('ppa-admin-config', false, array(), false, true);
        wp_enqueue_script('ppa-admin-config');
        wp_add_inline_script('ppa-admin-config', 'window.PPA = ' . wp_json_encode($base_cfg) . ';', 'before');

        // Detect current admin screen (robust with fallback to $_GET['page'])
        $screen     = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id  = $screen ? $screen->id : '';
        $page_param = isset($_GET['page']) ? (string) $_GET['page'] : '';

        // Screen IDs
        $slug_main_ui = 'toplevel_page_postpress-ai';         // top-level Composer page
        $slug_testbed = 'tools_page_ppa-testbed';             // Tools → PPA Testbed                         # CHANGED:

        // Booleans for where to enqueue
        $is_main_ui = ($screen_id === $slug_main_ui) || ($page_param === 'postpress-ai');
        $is_testbed = ($screen_id === $slug_testbed) || ($page_param === 'ppa-testbed');                      // CHANGED:

        // Enqueue external admin JS (both main UI and Testbed)
        if ($is_main_ui || $is_testbed) {
            $plugin_root_dir = dirname(dirname(__DIR__));
            $admin_js_file   = $plugin_root_dir . '/assets/js/admin.js';
            if (defined('PPA_PLUGIN_URL')) {
                $admin_js_url = rtrim(PPA_PLUGIN_URL, '/') . '/assets/js/admin.js';
            } else {
                $admin_js_url = plugins_url('assets/js/admin.js', $plugin_root_dir . '/postpress-ai.php');
            }
            $admin_js_ver = file_exists($admin_js_file) ? (string) filemtime($admin_js_file) : '2.1.0';

            wp_register_script('ppa-admin', $admin_js_url, array(), $admin_js_ver, true);
            wp_enqueue_script('ppa-admin');

            wp_localize_script('ppa-admin', 'ppaAdmin', array(
                'ajaxurl' => $base_cfg['ajaxurl'],
                'nonce'   => wp_create_nonce('ppa-admin'),
            ));
        }

        // (Placeholders) Composer assets on main UI if needed later
        if ($is_main_ui) {
            // wp_enqueue_style(...); wp_enqueue_script(...);
        }

        // Optional: small helper for Testbed page (kept as-is)
        if ($is_testbed) {
            $handle = 'ppa-testbed';
            $src    = (defined('PPA_PLUGIN_URL')
                        ? rtrim(PPA_PLUGIN_URL, '/') . '/inc/admin/ppa-testbed.js'
                        : plugins_url('inc/admin/ppa-testbed.js', $plugin_root_dir . '/postpress-ai.php'));
            wp_register_script($handle, $src, array(), '2.1.0', true);
            wp_enqueue_script($handle);
        }
    }
}
