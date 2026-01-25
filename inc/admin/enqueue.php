<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2026-01-25: FIX: Account screen now localizes PPAAccount (ajaxUrl + nonce + action) for admin-account.js. // CHANGED:
 *
 * 2026-01-21: FIX: Define $page_param safely (prevents Undefined variable warning).                         // CHANGED:
 * 2026-01-21: FIX: Enforce per-screen CSS isolation across ALL PPA pages (Composer/Settings/Account/Test). // CHANGED:
 * 2026-01-21: FIX: Composer now loads ONLY assets/css/admin-composer.css (gospel), not admin.css.          // CHANGED:
 * 2026-01-21: FIX: Testbed now loads ONLY assets/css/admin-testbed.css (when present), not admin.css.      // CHANGED:
 * 2026-01-21: ADD: Account page CSS/JS enqueue (only when on Account screen).                              // CHANGED:
 *
 * 2025-12-27 • FIX: Detect Settings screen reliably and enqueue admin-settings.css there.
 * 2025-12-27 • FIX: Enforce UNBREAKABLE RULE — Settings loads ONLY admin-settings.css (NOT admin.css).
 * 2025-12-27 • FIX: Expand "is our page" gating so Settings gets CSS while keeping JS only on main/testbed.
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
        // FIX: define $page_param BEFORE any usage (prevents warnings).                                // CHANGED:
        // ---------------------------------------------------------------------
        $page_param = '';                                                                            // CHANGED:
        if (isset($_GET['page'])) {                                                                  // CHANGED:
            $page_param = sanitize_key(wp_unslash($_GET['page']));                                   // CHANGED:
        }                                                                                            // CHANGED:

        // ---------------------------------------------------------------------
        // Resolve plugin paths/URLs once
        // ---------------------------------------------------------------------
        $plugin_root_dir  = dirname(dirname(__DIR__));  // .../wp-content/plugins/postpress-ai
        $plugin_main_file = $plugin_root_dir . '/postpress-ai.php';

        // ---------------------------------------------------------------------
        // Asset rel paths
        // ---------------------------------------------------------------------
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

        // NOTE: Per-screen CSS isolation is LOCKED.                                                     // CHANGED:
        $admin_settings_css_rel      = 'assets/css/admin-settings.css';                                 // CHANGED:
        $admin_composer_css_rel      = 'assets/css/admin-composer.css';                                  // CHANGED: gospel
        $admin_account_css_rel       = 'assets/css/admin-account.css';                                   // CHANGED:
        $admin_testbed_css_rel       = 'assets/css/admin-testbed.css';                                   // CHANGED:

        $testbed_js_rel              = 'inc/admin/ppa-testbed.js';
        $admin_spinner_rel           = 'assets/js/admin-preview-spinner.js';
        $admin_account_js_rel        = 'assets/js/admin-account.js';                                     // CHANGED:

        // ---------------------------------------------------------------------
        // Asset files (for filemtime)
        // ---------------------------------------------------------------------
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

        $admin_settings_css_file      = $plugin_root_dir . '/' . $admin_settings_css_rel;               // CHANGED:
        $admin_composer_css_file      = $plugin_root_dir . '/' . $admin_composer_css_rel;               // CHANGED:
        $admin_account_css_file       = $plugin_root_dir . '/' . $admin_account_css_rel;                // CHANGED:
        $admin_testbed_css_file       = $plugin_root_dir . '/' . $admin_testbed_css_rel;                // CHANGED:

        $testbed_js_file              = $plugin_root_dir . '/' . $testbed_js_rel;
        $admin_spinner_file           = $plugin_root_dir . '/' . $admin_spinner_rel;
        $admin_account_js_file        = $plugin_root_dir . '/' . $admin_account_js_rel;                 // CHANGED:

        // ---------------------------------------------------------------------
        // Asset URLs (prefer PPA_PLUGIN_URL if defined)
        // ---------------------------------------------------------------------
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

            $admin_settings_css_url      = $base_url . '/' . $admin_settings_css_rel;                    // CHANGED:
            $admin_composer_css_url      = $base_url . '/' . $admin_composer_css_rel;                    // CHANGED:
            $admin_account_css_url       = $base_url . '/' . $admin_account_css_rel;                     // CHANGED:
            $admin_testbed_css_url       = $base_url . '/' . $admin_testbed_css_rel;                     // CHANGED:

            $testbed_js_url              = $base_url . '/' . $testbed_js_rel;
            $admin_spinner_url           = $base_url . '/' . $admin_spinner_rel;
            $admin_account_js_url        = $base_url . '/' . $admin_account_js_rel;                      // CHANGED:
        } else {
            $admin_core_js_url           = plugins_url($admin_core_js_rel,        $plugin_main_file);
            $admin_editor_js_url         = plugins_url($admin_editor_js_rel,      $plugin_main_file);
            $admin_api_js_url            = plugins_url($admin_api_js_rel,         $plugin_main_file);
            $admin_payloads_js_url       = plugins_url($admin_payloads_js_rel,    $plugin_main_file);
            $admin_notices_js_url        = plugins_url($admin_notices_js_rel,     $plugin_main_file);
            $admin_generate_view_js_url  = plugins_url($admin_generate_view_js_rel, $plugin_main_file);
            $admin_comp_preview_js_url   = plugins_url($admin_comp_preview_js_rel,  $plugin_main_file);
            $admin_comp_generate_js_url  = plugins_url($admin_comp_generate_js_rel, $plugin_main_file);
            $admin_comp_store_js_url     = plugins_url($admin_comp_store_js_rel,    $plugin_main_file);
            $admin_js_url                = plugins_url($admin_js_rel,             $plugin_main_file);

            $admin_settings_css_url      = plugins_url($admin_settings_css_rel,   $plugin_main_file);    // CHANGED:
            $admin_composer_css_url      = plugins_url($admin_composer_css_rel,   $plugin_main_file);    // CHANGED:
            $admin_account_css_url       = plugins_url($admin_account_css_rel,    $plugin_main_file);    // CHANGED:
            $admin_testbed_css_url       = plugins_url($admin_testbed_css_rel,    $plugin_main_file);    // CHANGED:

            $testbed_js_url              = plugins_url($testbed_js_rel,           $plugin_main_file);
            $admin_spinner_url           = plugins_url($admin_spinner_rel,        $plugin_main_file);
            $admin_account_js_url        = plugins_url($admin_account_js_rel,     $plugin_main_file);    // CHANGED:
        }

        // Force HTTPS scheme for all admin/testbed asset URLs
        if (function_exists('set_url_scheme')) {
            $admin_core_js_url           = set_url_scheme($admin_core_js_url, 'https');
            $admin_editor_js_url         = set_url_scheme($admin_editor_js_url, 'https');
            $admin_api_js_url            = set_url_scheme($admin_api_js_url, 'https');
            $admin_payloads_js_url       = set_url_scheme($admin_payloads_js_url, 'https');
            $admin_notices_js_url        = set_url_scheme($admin_notices_js_url, 'https');
            $admin_generate_view_js_url  = set_url_scheme($admin_generate_view_js_url, 'https');
            $admin_comp_preview_js_url   = set_url_scheme($admin_comp_preview_js_url, 'https');
            $admin_comp_generate_js_url  = set_url_scheme($admin_comp_generate_js_url, 'https');
            $admin_comp_store_js_url     = set_url_scheme($admin_comp_store_js_url, 'https');
            $admin_js_url                = set_url_scheme($admin_js_url, 'https');

            $admin_settings_css_url      = set_url_scheme($admin_settings_css_url, 'https');             // CHANGED:
            $admin_composer_css_url      = set_url_scheme($admin_composer_css_url, 'https');             // CHANGED:
            $admin_account_css_url       = set_url_scheme($admin_account_css_url, 'https');              // CHANGED:
            $admin_testbed_css_url       = set_url_scheme($admin_testbed_css_url, 'https');              // CHANGED:

            $testbed_js_url              = set_url_scheme($testbed_js_url, 'https');
            $admin_spinner_url           = set_url_scheme($admin_spinner_url, 'https');
            $admin_account_js_url        = set_url_scheme($admin_account_js_url, 'https');               // CHANGED:
        }

        // Versions (cache-bust by file mtime, safe fallback to time())
        $admin_core_js_ver           = file_exists($admin_core_js_file)         ? (string) filemtime($admin_core_js_file)         : (string) time();
        $admin_editor_js_ver         = file_exists($admin_editor_js_file)       ? (string) filemtime($admin_editor_js_file)       : (string) time();
        $admin_api_js_ver            = file_exists($admin_api_js_file)          ? (string) filemtime($admin_api_js_file)          : (string) time();
        $admin_payloads_js_ver       = file_exists($admin_payloads_js_file)     ? (string) filemtime($admin_payloads_js_file)     : (string) time();
        $admin_notices_js_ver        = file_exists($admin_notices_js_file)      ? (string) filemtime($admin_notices_js_file)      : (string) time();
        $admin_generate_view_js_ver  = file_exists($admin_generate_view_js_file)? (string) filemtime($admin_generate_view_js_file): (string) time();
        $admin_comp_preview_js_ver   = file_exists($admin_comp_preview_js_file) ? (string) filemtime($admin_comp_preview_js_file) : (string) time();
        $admin_comp_generate_js_ver  = file_exists($admin_comp_generate_js_file)? (string) filemtime($admin_comp_generate_js_file): (string) time();
        $admin_comp_store_js_ver     = file_exists($admin_comp_store_js_file)   ? (string) filemtime($admin_comp_store_js_file)   : (string) time();
        $admin_js_ver                = file_exists($admin_js_file)              ? (string) filemtime($admin_js_file)              : (string) time();

        $admin_settings_css_ver      = file_exists($admin_settings_css_file)    ? (string) filemtime($admin_settings_css_file)    : (string) time(); // CHANGED:
        $admin_composer_css_ver      = file_exists($admin_composer_css_file)    ? (string) filemtime($admin_composer_css_file)    : (string) time(); // CHANGED:
        $admin_account_css_ver       = file_exists($admin_account_css_file)     ? (string) filemtime($admin_account_css_file)     : (string) time(); // CHANGED:
        $admin_testbed_css_ver       = file_exists($admin_testbed_css_file)     ? (string) filemtime($admin_testbed_css_file)     : (string) time(); // CHANGED:

        $testbed_js_ver              = file_exists($testbed_js_file)            ? (string) filemtime($testbed_js_file)            : (string) time();
        $admin_spinner_ver           = file_exists($admin_spinner_file)         ? (string) filemtime($admin_spinner_file)         : (string) time();
        $admin_account_js_ver        = file_exists($admin_account_js_file)      ? (string) filemtime($admin_account_js_file)      : (string) time(); // CHANGED:

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

        // Screen IDs / hooks (WP builds these)
        $slug_composer = 'toplevel_page_postpress-ai';                        // Composer (top-level)
        $slug_settings = 'postpress-ai_page_postpress-ai-settings';           // Settings submenu
        $slug_testbed  = 'postpress-ai_page_postpress-ai-testbed';            // Testbed submenu
        $slug_account  = 'postpress-ai_page_postpress-ai-account';            // Account submenu (if menu adds it) // CHANGED:

        $hook_str = is_string($hook) ? $hook : '';

        // Identify which PPA screen we are on (support both $hook, $screen_id, and ?page=)                 // CHANGED:
        $is_composer = ($hook_str === $slug_composer) || ($screen_id === $slug_composer) || ($page_param === 'postpress-ai'); // CHANGED:
        $is_settings = ($hook_str === $slug_settings) || ($screen_id === $slug_settings) || ($page_param === 'postpress-ai-settings'); // CHANGED:
        $is_testbed  = ($hook_str === $slug_testbed)  || ($screen_id === $slug_testbed)  || ($page_param === 'postpress-ai-testbed');  // CHANGED:
        $is_account  = ($hook_str === $slug_account)  || ($screen_id === $slug_account)  || ($page_param === 'postpress-ai-account');  // CHANGED:

        // Gate: do nothing on non-PPA screens
        $is_ppa_page = ($is_composer || $is_settings || $is_testbed || $is_account);                       // CHANGED:
        if (!$is_ppa_page) {
            return;
        }

        // Respect testbed toggle
        if ($is_testbed && !(defined('PPA_ENABLE_TESTBED') && PPA_ENABLE_TESTBED)) {                      // CHANGED:
            return;                                                                                         // CHANGED:
        }                                                                                                   // CHANGED:

        // ---------------------------------------------------------------------
        // Aggressive purge (only our assets)
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

        // Purge known scripts
        $purge_by_rel($admin_core_js_rel,          'script');
        $purge_by_rel($admin_editor_js_rel,        'script');
        $purge_by_rel($admin_api_js_rel,           'script');
        $purge_by_rel($admin_payloads_js_rel,      'script');
        $purge_by_rel($admin_notices_js_rel,       'script');
        $purge_by_rel($admin_generate_view_js_rel, 'script');
        $purge_by_rel($admin_comp_preview_js_rel,  'script');
        $purge_by_rel($admin_comp_generate_js_rel, 'script');
        $purge_by_rel($admin_comp_store_js_rel,    'script');
        $purge_by_rel($admin_js_rel,               'script');
        $purge_by_rel($testbed_js_rel,             'script');
        $purge_by_rel($admin_spinner_rel,          'script');
        $purge_by_rel($admin_account_js_rel,       'script');                                               // CHANGED:

        // Purge per-screen CSS (do NOT load cross-screen)
        $purge_by_rel($admin_settings_css_rel,     'style');                                                 // CHANGED:
        $purge_by_rel($admin_composer_css_rel,     'style');                                                 // CHANGED:
        $purge_by_rel($admin_account_css_rel,      'style');                                                 // CHANGED:
        $purge_by_rel($admin_testbed_css_rel,      'style');                                                 // CHANGED:

        // ---------------------------------------------------------------------
        // Enqueue CSS — ONE FILE PER SCREEN (LOCKED)
        // ---------------------------------------------------------------------

        if ($is_settings) {                                                                                  // CHANGED:
            wp_register_style('ppa-admin-settings-css', $admin_settings_css_url, array(), $admin_settings_css_ver, 'all'); // CHANGED:
            wp_enqueue_style('ppa-admin-settings-css');                                                       // CHANGED:
            return;                                                                                           // CHANGED: Settings needs no JS stack
        }                                                                                                     // CHANGED:

        if ($is_account) {                                                                                   // CHANGED:
            wp_register_style('ppa-admin-account-css', $admin_account_css_url, array(), $admin_account_css_ver, 'all'); // CHANGED:
            wp_enqueue_style('ppa-admin-account-css');                                                       // CHANGED:

            // Account JS (only on Account)
            if (file_exists($admin_account_js_file)) {                                                       // CHANGED:
                wp_register_script('ppa-admin-account', $admin_account_js_url, array('jquery'), $admin_account_js_ver, true); // CHANGED:

                // Provide the minimal config that admin-account.js needs: ajaxUrl + nonce + action.         // CHANGED:
                // This keeps the Account screen self-contained (no dependency on window.PPA or ppaAdmin).   // CHANGED:
                $account_cfg = array(                                                                        // CHANGED:
                    'ajaxUrl' => admin_url('admin-ajax.php'),                                                 // CHANGED:
                    'nonce'   => wp_create_nonce('ppa-admin'),                                                // CHANGED:
                    'action'  => 'ppa_account_status',                                                        // CHANGED:
                    'site'    => esc_url_raw(home_url('/')),                                                  // CHANGED:
                    'page'    => $page_param,                                                                 // CHANGED:
                    'jsVer'   => $admin_account_js_ver,                                                       // CHANGED:
                );                                                                                            // CHANGED:
                wp_localize_script('ppa-admin-account', 'PPAAccount', $account_cfg);                           // CHANGED:

                wp_enqueue_script('ppa-admin-account');                                                      // CHANGED:
            }                                                                                                // CHANGED:
            return;                                                                                           // CHANGED:
        }                                                                                                     // CHANGED:

        if ($is_testbed) {                                                                                   // CHANGED:
            // Prefer dedicated testbed CSS if present
            if (file_exists($admin_testbed_css_file)) {                                                      // CHANGED:
                wp_register_style('ppa-admin-testbed-css', $admin_testbed_css_url, array(), $admin_testbed_css_ver, 'all'); // CHANGED:
                wp_enqueue_style('ppa-admin-testbed-css');                                                   // CHANGED:
            }                                                                                                // CHANGED:
            // Continue into JS stack below
        }                                                                                                     // CHANGED:

        if ($is_composer) {                                                                                  // CHANGED:
            // Composer MUST use gospel CSS file ONLY.
            wp_register_style('ppa-admin-composer-css', $admin_composer_css_url, array(), $admin_composer_css_ver, 'all'); // CHANGED:
            wp_enqueue_style('ppa-admin-composer-css');                                                      // CHANGED:
            // Continue into JS stack below
        }                                                                                                     // CHANGED:

        // ---------------------------------------------------------------------
        // Enqueue JS stack (Composer + Testbed only)
        // ---------------------------------------------------------------------
        if ($is_composer || $is_testbed) {
            $cfg = array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => esc_url_raw(rest_url()),
                'page'    => $page_param,
                'jsVer'   => $admin_js_ver,
                'wpNonce' => wp_create_nonce('wp_rest'),
                'nonce'   => wp_create_nonce('ppa-admin'),
            );

            // Use a real (empty) script src so WordPress reliably prints the tag + inline JS.
            $empty_src = 'data:text/javascript;base64,';
            wp_register_script('ppa-admin-config', $empty_src, array(), null, true);
            wp_enqueue_script('ppa-admin-config');

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

            if ($is_composer && file_exists($admin_spinner_file)) {
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

// Ensure hook is registered (some restore states lose this line).
add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 9);
