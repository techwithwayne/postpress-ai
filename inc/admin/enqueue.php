<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2025-11-10 • ACCEPT $hook param for compatibility with admin_enqueue_scripts and silence extra-arg notices.  # CHANGED:
 * 2025-11-10 • ADD: Expose wpNonce (wp_rest) to window.PPA and ppaAdmin for X-WP-Nonce.                      # (prev)
 * 2025-11-09 • Rotate ppa-testbed.js by filemtime() (cache-bust like admin.css/js).                         # (prev)
 * 2025-11-08 • ADD: Cache-busted admin.css via filemtime(); expose cssVer/jsVer on window.PPA;              # (prev)
 *              ensure ppa-admin depends on ppa-admin-config so config is available first;                   # (prev)
 *              include restUrl + page in config; keep ppaAdmin localize (nonce, ajaxurl).                   # (prev)
 * 2025-10-30 • FIX: Load admin.js on Tools→PPA Testbed by correcting screen id to                          # (prev)
 *              'tools_page_ppa-testbed' and retaining fallback via ?page=ppa-testbed.                       # (prev)
 * 2025-10-30 • Enqueue external admin script (assets/js/admin.js) on both main UI (?page=postpress-ai)     # (prev)
 *              and Tools→PPA Testbed; cache-bust via filemtime; localize `ppaAdmin` (ajaxurl, nonce);       # (prev)
 *              keep lightweight window.PPA config and existing testbed logic.                               # (prev)
 * 2025-10-29 • Add safe, centralized enqueue for admin assets; conditionally load ppa-testbed.js            # (prev)
 *              on the PPA Testbed screen only; expose minimal window.PPA config (ajaxUrl).                  # (prev)
 */

defined('ABSPATH') || exit;

if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue($hook = '') {                                                            // CHANGED:
        // Detect current admin screen (robust with fallback to $_GET['page'])
        $screen     = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id  = $screen ? $screen->id : '';
        $page_param = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';

        // Screen IDs
        $slug_main_ui         = 'toplevel_page_postpress-ai';      // top-level Composer page
        $slug_testbed_legacy  = 'tools_page_ppa-testbed';          // legacy Tools screen id
        $slug_testbed_newmenu = 'postpress-ai_page_ppa-testbed';   // submenu under our top-level

        // Booleans for where to enqueue
        $is_main_ui = ($screen_id === $slug_main_ui) || ($page_param === 'postpress-ai');
        $is_testbed = in_array($screen_id, array($slug_testbed_newmenu, $slug_testbed_legacy), true) || ($page_param === 'ppa-testbed');

        // Resolve plugin paths/URLs once
        $plugin_root_dir  = dirname(dirname(__DIR__));  // .../wp-content/plugins/postpress-ai
        $plugin_main_file = $plugin_root_dir . '/postpress-ai.php';

        // Asset rel paths
        $admin_js_rel    = 'assets/js/admin.js';
        $admin_css_rel   = 'assets/css/admin.css';
        $testbed_js_rel  = 'inc/admin/ppa-testbed.js';

        // Asset files (for filemtime)
        $admin_js_file   = $plugin_root_dir . '/' . $admin_js_rel;
        $admin_css_file  = $plugin_root_dir . '/' . $admin_css_rel;
        $testbed_js_file = $plugin_root_dir . '/' . $testbed_js_rel;

        // Asset URLs (prefer PPA_PLUGIN_URL if defined)
        if (defined('PPA_PLUGIN_URL')) {
            $base_url       = rtrim(PPA_PLUGIN_URL, '/');
            $admin_js_url   = $base_url . '/' . $admin_js_rel;
            $admin_css_url  = $base_url . '/' . $admin_css_rel;
            $testbed_js_url = $base_url . '/' . $testbed_js_rel;
        } else {
            $admin_js_url   = plugins_url($admin_js_rel,  $plugin_main_file);
            $admin_css_url  = plugins_url($admin_css_rel, $plugin_main_file);
            $testbed_js_url = plugins_url($testbed_js_rel, $plugin_main_file);
        }

        // Versions (cache-bust by file mtime, safe fallback to time())
        $admin_js_ver   = file_exists($admin_js_file)   ? (string) filemtime($admin_js_file)   : (string) time();
        $admin_css_ver  = file_exists($admin_css_file)  ? (string) filemtime($admin_css_file)  : (string) time();
        $testbed_js_ver = file_exists($testbed_js_file) ? (string) filemtime($testbed_js_file) : (string) time();

        // Build shared config (window.PPA) — exposed before admin.js
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => esc_url_raw(rest_url()),
            'page'    => $page_param,
            'cssVer'  => $admin_css_ver,
            'jsVer'   => $admin_js_ver,
            'wpNonce' => wp_create_nonce('wp_rest'),
        );

        // Early, global config. Ensure admin depends on this handle so config is ready first.
        wp_register_script('ppa-admin-config', false, array(), null, true);
        wp_enqueue_script('ppa-admin-config');
        wp_add_inline_script('ppa-admin-config', 'window.PPA = ' . wp_json_encode($cfg) . ';', 'before'); // CHANGED:

        // Enqueue assets on main UI and Testbed
        if ($is_main_ui || $is_testbed) {
            // CSS
            wp_register_style('ppa-admin-css', $admin_css_url, array(), $admin_css_ver, 'all');
            wp_enqueue_style('ppa-admin-css');

            // JS — depend on config so window.PPA is present before admin.js runs
            wp_register_script('ppa-admin', $admin_js_url, array('jquery', 'ppa-admin-config'), $admin_js_ver, true);
            wp_localize_script('ppa-admin', 'ppaAdmin', array(
                'ajaxurl' => $cfg['ajaxUrl'],
                'nonce'   => wp_create_nonce('ppa-admin'),
                'cssVer'  => $cfg['cssVer'],
                'jsVer'   => $cfg['jsVer'],
                'wpNonce' => $cfg['wpNonce'],
            ));
            wp_enqueue_script('ppa-admin');
        }

        // Testbed-only helper JS (now cache-busted by filemtime)
        if ($is_testbed) {
            $handle = 'ppa-testbed';
            wp_register_script(
                $handle,
                $testbed_js_url,
                array('ppa-admin-config'),
                $testbed_js_ver,
                true
            );
            wp_enqueue_script($handle);
        }

        // (Placeholders) Composer assets on main UI if needed later
        if ($is_main_ui) {
            // wp_enqueue_style(...); wp_enqueue_script(...);
        }
    }
}
