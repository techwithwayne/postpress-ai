<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * CHANGE LOG
 * 2025-11-08 • ADD: Cache-busted admin.css via filemtime(); expose cssVer/jsVer on window.PPA;            # CHANGED:
 *              ensure ppa-admin depends on ppa-admin-config so config is available first;                 # CHANGED:
 *              include restUrl + page in config; keep ppaAdmin localize (nonce, ajaxurl).                 # CHANGED:
 * 2025-10-30 • FIX: Load admin.js on Tools→PPA Testbed by correcting screen id to
 *              'tools_page_ppa-testbed' and retaining fallback via ?page=ppa-testbed.                     # (prev)
 * 2025-10-30 • Enqueue external admin script (assets/js/admin.js) on both main UI (?page=postpress-ai)
 *              and Tools→PPA Testbed; cache-bust via filemtime; localize `ppaAdmin` (ajaxurl, nonce);
 *              keep lightweight window.PPA config and existing testbed logic.                              # (prev)
 * 2025-10-29 • Add safe, centralized enqueue for admin assets; conditionally load ppa-testbed.js
 *              on the PPA Testbed screen only; expose minimal window.PPA config (ajaxUrl).                 # (prev)
 */

defined('ABSPATH') || exit;

if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue() {                                                                                 // CHANGED:
        // Detect current admin screen (robust with fallback to $_GET['page'])
        $screen     = function_exists('get_current_screen') ? get_current_screen() : null;                         // CHANGED:
        $screen_id  = $screen ? $screen->id : '';                                                                  // CHANGED:
        $page_param = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';                            // CHANGED:

        // Screen IDs
        $slug_main_ui = 'toplevel_page_postpress-ai';         // top-level Composer page
        $slug_testbed = 'tools_page_ppa-testbed';             // Tools → PPA Testbed

        // Booleans for where to enqueue
        $is_main_ui = ($screen_id === $slug_main_ui) || ($page_param === 'postpress-ai');
        $is_testbed = ($screen_id === $slug_testbed) || ($page_param === 'ppa-testbed');

        // Resolve plugin paths/URLs once                                                                              // CHANGED:
        $plugin_root_dir  = dirname(dirname(__DIR__));  // .../wp-content/plugins/postpress-ai                      // CHANGED:
        $plugin_main_file = $plugin_root_dir . '/postpress-ai.php';                                                 // CHANGED:

        // Asset paths                                                                                                 // CHANGED:
        $admin_js_rel  = 'assets/js/admin.js';                                                                       // CHANGED:
        $admin_css_rel = 'assets/css/admin.css';                                                                      // CHANGED:

        $admin_js_file  = $plugin_root_dir . '/' . $admin_js_rel;                                                    // CHANGED:
        $admin_css_file = $plugin_root_dir . '/' . $admin_css_rel;                                                   // CHANGED:

        // Asset URLs (prefer PPA_PLUGIN_URL if defined)                                                               // CHANGED:
        if (defined('PPA_PLUGIN_URL')) {                                                                              // CHANGED:
            $base_url      = rtrim(PPA_PLUGIN_URL, '/');                                                              // CHANGED:
            $admin_js_url  = $base_url . '/' . $admin_js_rel;                                                         // CHANGED:
            $admin_css_url = $base_url . '/' . $admin_css_rel;                                                        // CHANGED:
        } else {                                                                                                      // CHANGED:
            $admin_js_url  = plugins_url($admin_js_rel,  $plugin_main_file);                                          // CHANGED:
            $admin_css_url = plugins_url($admin_css_rel, $plugin_main_file);                                          // CHANGED:
        }

        // Versions (cache-bust by file mtime, safe fallback to time())                                                // CHANGED:
        $admin_js_ver  = file_exists($admin_js_file)  ? (string) filemtime($admin_js_file)  : (string) time();       // CHANGED:
        $admin_css_ver = file_exists($admin_css_file) ? (string) filemtime($admin_css_file) : (string) time();       // CHANGED:

        // Build shared config (window.PPA)                                                                            // CHANGED:
        $cfg = array(                                                                                                 // CHANGED:
            'ajaxUrl' => admin_url('admin-ajax.php'),                                                                 // CHANGED:
            'restUrl' => esc_url_raw(rest_url()),                                                                     // CHANGED:
            'page'    => $page_param,                                                                                // CHANGED:
            'cssVer'  => $admin_css_ver,                                                                              // CHANGED:
            'jsVer'   => $admin_js_ver,                                                                               // CHANGED:
        );                                                                                                            // CHANGED:

        // Early, global config. We ensure ppa-admin depends on this handle so config is ready first.                  // CHANGED:
        wp_register_script('ppa-admin-config', false, array(), null, true);                                           // CHANGED:
        wp_enqueue_script('ppa-admin-config');                                                                         // CHANGED:
        wp_add_inline_script('ppa-admin-config', 'window.PPA = ' . wp_json_encode($cfg) . ';', 'before');             // CHANGED:

        // Enqueue assets on main UI and Testbed                                                                       // CHANGED:
        if ($is_main_ui || $is_testbed) {                                                                             // CHANGED:
            // CSS                                                                                                     // CHANGED:
            wp_register_style('ppa-admin-css', $admin_css_url, array(), $admin_css_ver, 'all');                       // CHANGED:
            wp_enqueue_style('ppa-admin-css');                                                                         // CHANGED:

            // JS — depend on config so window.PPA is present before admin.js runs                                     // CHANGED:
            wp_register_script('ppa-admin', $admin_js_url, array('jquery', 'ppa-admin-config'), $admin_js_ver, true); // CHANGED:
            wp_localize_script('ppa-admin', 'ppaAdmin', array(                                                         // CHANGED:
                'ajaxurl' => $cfg['ajaxUrl'],                                                                          // CHANGED:
                'nonce'   => wp_create_nonce('ppa-admin'),                                                             // CHANGED:
                'cssVer'  => $cfg['cssVer'],                                                                           // CHANGED:
                'jsVer'   => $cfg['jsVer'],                                                                            // CHANGED:
            ));                                                                                                        // CHANGED:
            wp_enqueue_script('ppa-admin');                                                                            // CHANGED:
        }

        // Optional helper just for Testbed                                                                            // CHANGED:
        if ($is_testbed) {                                                                                             // CHANGED:
            $handle = 'ppa-testbed';                                                                                   // CHANGED:
            $src    = (defined('PPA_PLUGIN_URL')                                                                       // CHANGED:
                        ? rtrim(PPA_PLUGIN_URL, '/') . '/inc/admin/ppa-testbed.js'                                     // CHANGED:
                        : plugins_url('inc/admin/ppa-testbed.js', $plugin_main_file));                                 // CHANGED:
            wp_register_script($handle, $src, array('ppa-admin-config'), '2.1.0', true);                               // CHANGED:
            wp_enqueue_script($handle);                                                                                // CHANGED:
        }

        // (Placeholders) Composer assets on main UI if needed later                                                   // (kept)
        if ($is_main_ui) {
            // wp_enqueue_style(...); wp_enqueue_script(...);
        }
    }
}
