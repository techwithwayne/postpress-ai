<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2025-12-27 • FIX: Ensure window.PPA includes the ppa-admin nonce (window.PPA.nonce) on JS-enabled screens. // CHANGED:
 *              This unblocks secure admin-ajax calls like action=ppa_usage_snapshot without weakening nonce rules. // CHANGED:
 * 2025-12-27 • FIX: Robust screen detection for PostPress AI pages so config+nonce always enqueue on Composer. // CHANGED:
 *              (Avoid brittle exact matches of $hook/$screen_id/page=)                                                // CHANGED:
 * 2025-12-27 • FIX: Write window.PPA via merge (Object.assign) so it won’t be clobbered by other scripts.           // CHANGED:
 * 2025-12-27 • FIX: Register ppa-admin-config with a real (empty) data: URL instead of src=false so inline config   // CHANGED:
 *              reliably prints in wp-admin across stacks.                                                             // CHANGED:
 * 2025-12-25 • ENFORCE UNBREAKABLE RULE: each PostPress AI admin screen loads ONLY its own CSS file (no admin.css fallback).
 * 2025-12-25 • Settings: load admin-settings.css ONLY (never admin.css).
 * 2025-12-25 • Composer: load admin-composer.css ONLY (never admin.css).
 * 2025-12-25 • Testbed: load only when PPA_ENABLE_TESTBED is true; CSS/JS only if files exist.
 * 2025-12-25 • Settings remains CSS-only (no JS stack, no inline config output).
 * 2025-12-21 • Scope purge + enqueues to PostPress AI screens only to avoid wp-admin side effects.
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

        // Per-screen CSS (modularization path)
        $admin_css_composer_rel      = 'assets/css/admin-composer.css';
        $admin_css_settings_rel      = 'assets/css/admin-settings.css';
        $admin_css_testbed_rel       = 'assets/css/admin-testbed.css';

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

        $admin_css_composer_file      = $plugin_root_dir . '/' . $admin_css_composer_rel;
        $admin_css_settings_file      = $plugin_root_dir . '/' . $admin_css_settings_rel;
        $admin_css_testbed_file       = $plugin_root_dir . '/' . $admin_css_testbed_rel;

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

            $admin_css_composer_url      = $base_url . '/' . $admin_css_composer_rel;
            $admin_css_settings_url      = $base_url . '/' . $admin_css_settings_rel;
            $admin_css_testbed_url       = $base_url . '/' . $admin_css_testbed_rel;

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

            $admin_css_composer_url      = plugins_url($admin_css_composer_rel, $plugin_main_file);
            $admin_css_settings_url      = plugins_url($admin_css_settings_rel, $plugin_main_file);
            $admin_css_testbed_url       = plugins_url($admin_css_testbed_rel,  $plugin_main_file);

            $testbed_js_url              = plugins_url($testbed_js_rel,      $plugin_main_file);
            $admin_spinner_url           = plugins_url($admin_spinner_rel,   $plugin_main_file);
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

            $admin_css_composer_url      = set_url_scheme($admin_css_composer_url, 'https');
            $admin_css_settings_url      = set_url_scheme($admin_css_settings_url, 'https');
            $admin_css_testbed_url       = set_url_scheme($admin_css_testbed_url, 'https');

            $testbed_js_url              = set_url_scheme($testbed_js_url, 'https');
            $admin_spinner_url           = set_url_scheme($admin_spinner_url, 'https');
        }

        // Versions (cache-bust by file mtime, safe fallback to time())
        $admin_core_js_ver           = file_exists($admin_core_js_file)   ? (string) filemtime($admin_core_js_file)   : (string) time();
        $admin_editor_js_ver         = file_exists($admin_editor_js_file) ? (string) filemtime($admin_editor_js_file) : (string) time();
        $admin_api_js_ver            = file_exists($admin_api_js_file)            ? (string) filemtime($admin_api_js_file)            : (string) time();
        $admin_payloads_js_ver       = file_exists($admin_payloads_js_file)       ? (string) filemtime($admin_payloads_js_file)       : (string) time();
        $admin_notices_js_ver        = file_exists($admin_notices_js_file)        ? (string) filemtime($admin_notices_js_file)        : (string) time();
        $admin_generate_view_js_ver  = file_exists($admin_generate_view_js_file)  ? (string) filemtime($admin_generate_view_js_file)  : (string) time();
        $admin_comp_preview_js_ver   = file_exists($admin_comp_preview_js_file)   ? (string) filemtime($admin_comp_preview_js_file)   : (string) time();
        $admin_comp_generate_js_ver  = file_exists($admin_comp_generate_js_file)  ? (string) filemtime($admin_comp_generate_js_file)  : (string) time();
        $admin_comp_store_js_ver     = file_exists($admin_comp_store_js_file)     ? (string) filemtime($admin_comp_store_js_file)     : (string) time();
        $admin_js_ver                = file_exists($admin_js_file)                ? (string) filemtime($admin_js_file)                : (string) time();

        $admin_css_composer_ver      = file_exists($admin_css_composer_file)      ? (string) filemtime($admin_css_composer_file)      : (string) time();
        $admin_css_settings_ver      = file_exists($admin_css_settings_file)      ? (string) filemtime($admin_css_settings_file)      : (string) time();
        $admin_css_testbed_ver       = file_exists($admin_css_testbed_file)       ? (string) filemtime($admin_css_testbed_file)       : (string) time();

        $testbed_js_ver              = file_exists($testbed_js_file)              ? (string) filemtime($testbed_js_file)              : (string) time();
        $admin_spinner_ver           = file_exists($admin_spinner_file)           ? (string) filemtime($admin_spinner_file)           : (string) time();

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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page_param = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

        // Screen IDs
        $slug_main_ui          = 'toplevel_page_postpress-ai';
        $slug_testbed          = 'postpress-ai_page_postpress-ai-testbed';
        $slug_settings         = 'postpress-ai_page_postpress-ai-settings';

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

        $purge_by_rel($admin_css_composer_rel,     'style');
        $purge_by_rel($admin_css_settings_rel,     'style');
        $purge_by_rel($admin_css_testbed_rel,      'style');

        if (defined('PPA_ENABLE_TESTBED') && PPA_ENABLE_TESTBED) {
            $purge_by_rel($testbed_js_rel,         'script');
        }
        $purge_by_rel($admin_spinner_rel,          'script');

        // ---------------------------------------------------------------------
        // CSS (one file per screen)
        // ---------------------------------------------------------------------
        if ($is_settings) {
            if (file_exists($admin_css_settings_file)) {
                wp_register_style('ppa-admin-settings-css', $admin_css_settings_url, array(), $admin_css_settings_ver, 'all');
                wp_enqueue_style('ppa-admin-settings-css');
            }
        } elseif ($is_main_ui) {
            if (file_exists($admin_css_composer_file)) {
                wp_register_style('ppa-admin-composer-css', $admin_css_composer_url, array(), $admin_css_composer_ver, 'all');
                wp_enqueue_style('ppa-admin-composer-css');
            }
        } elseif ($is_testbed) {
            if (file_exists($admin_css_testbed_file)) {
                wp_register_style('ppa-admin-testbed-css', $admin_css_testbed_url, array(), $admin_css_testbed_ver, 'all');
                wp_enqueue_style('ppa-admin-testbed-css');
            }
        }

        // Settings screen is CSS-only
        if ($is_settings) {
            return;
        }

        // ---------------------------------------------------------------------
        // Build shared config (window.PPA) — ONLY when JS stack is used
        // ---------------------------------------------------------------------
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

add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 20);
/**
 * FAILSAFE: Guarantee admin nonce is present for PostPress AI admin screens only.
 *
 * Injects:
 *   window.PPA = window.PPA || {};
 *   window.PPA.nonce = wp_create_nonce('ppa-admin');
 *
 * Scope: PostPress AI screens only. No capability weakening. No controller edits.
 */
add_action('admin_head', function () {
    // Only run inside wp-admin.
    if ( ! is_admin() ) {
        return;
    }

    // Screen guard (strict): only PostPress AI admin pages.
    if ( ! function_exists('get_current_screen') ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || empty($screen->id) ) {
        return;
    }

    // Scope to PostPress AI screens only.
    // Examples you likely have:
    // - postpress-ai_page_postpress-ai-settings
    // - postpress-ai_page_postpress-ai-composer
    // - (hidden) postpress-ai_page_postpress-ai-testbed
    $id = (string) $screen->id;

    if ( strpos($id, 'postpress-ai') === false ) {
        return;
    }

    $nonce = wp_create_nonce('ppa-admin');

    echo "<script>
window.PPA = window.PPA || {};
window.PPA.nonce = " . wp_json_encode($nonce) . ";
</script>";
}, 1);

