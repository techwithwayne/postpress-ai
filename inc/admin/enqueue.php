<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * CHANGE LOG
 * 2025-10-29 • Add safe, centralized enqueue for admin assets; conditionally load ppa-testbed.js
 *              on the PPA Testbed screen only; expose minimal window.PPA config (ajaxUrl).      # CHANGED:
 */

defined('ABSPATH') || exit;

/**
 * Central admin enqueue for PostPress AI.
 *
 * This file is required by postpress-ai.php and is expected to define ppa_admin_enqueue().
 * Keep this function idempotent and cheap to call on any admin page.
 *
 * IMPORTANT:
 * - Do NOT leak secrets (e.g., shared keys) to the browser.
 * - Only load heavy assets on our own screens.
 */
if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue() {                                                                       // CHANGED:
        // Basic config available to our admin scripts
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
        );

        // Lightweight runtime detection of our admin pages
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';

        // Our slugs/ids
        $slug_top     = 'toplevel_page_postpress-ai';   // main Composer page
        $slug_testbed = 'postpress-ai_page_ppa-testbed';// appended Testbed submenu page

        // Always ensure a config handle exists (no external file; tiny inline only)
        wp_register_script('ppa-admin-config', false, array(), false, true);
        wp_enqueue_script('ppa-admin-config');
        wp_add_inline_script('ppa-admin-config', 'window.PPA = ' . wp_json_encode($cfg) . ';', 'before');

        // === Composer assets (if you already register/enqueue them elsewhere, this remains harmless) ===
        if ($screen_id === $slug_top) {                                                                   // CHANGED:
            /**
             * Placeholders for composer assets — retain your existing registrations if present.
             * Example:
             * wp_enqueue_style('ppa-admin-composer', PPA_PLUGIN_URL . 'assets/css/composer.css', array(), '2.1.0');
             * wp_enqueue_script('ppa-admin-composer', PPA_PLUGIN_URL . 'assets/js/composer.js', array('jquery'), '2.1.0', true);
             */
        }

        // === Testbed asset (Preview + Save Draft button logic) ===
        if ($screen_id === $slug_testbed || (isset($_GET['page']) && $_GET['page'] === 'ppa-testbed')) { // CHANGED:
            $handle = 'ppa-testbed';
            $src    = PPA_PLUGIN_URL . 'inc/admin/ppa-testbed.js';
            wp_register_script($handle, $src, array(), '2.1.0', true);
            wp_enqueue_script($handle);
        }
    }
}
