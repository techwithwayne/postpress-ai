<?php
/**
 * PostPress AI — Admin Asset Loader (deprecated shim)
 * Path: inc/class-ppa-admin.php
 *
 * ========= CHANGE LOG =========
 * 2025-11-10: Deprecate legacy asset enqueues; delegate to centralized loader in               // CHANGED:
 *             inc/admin/enqueue.php. Stop adding admin_enqueue_scripts hook.                  // CHANGED:
 *             Provide no-op enqueue() and defensive remove_action in init().                 // CHANGED:
 * 2025-11-08: Add cache-busted CSS/JS (filemtime), limit to plugin screen, expose PPA cfg.
 */

namespace PPA\Admin; // CHANGED:

if (!defined('ABSPATH')) { // CHANGED:
    exit;                  // CHANGED:
}                          // CHANGED:

class Admin { // CHANGED:
    /**
     * Bootstrap (deprecated for assets).
     * No longer attaches admin_enqueue_scripts; centralized loader owns assets.               // CHANGED:
     */
    public static function init() { // CHANGED:
        // If an older version already attached this class's enqueue, remove it defensively.   // CHANGED:
        remove_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 10);                    // CHANGED:
        // Intentionally DO NOT add any enqueue hooks here.                                    // CHANGED:
    }                                                                                          // CHANGED:

    /**
     * Back-compat only: previous screen check helper.
     * Retained to avoid fatals if referenced externally.                                      // CHANGED:
     */
    protected static function is_plugin_screen(): bool { // CHANGED:
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';             // CHANGED:
        if ($page === 'postpress-ai') {                                                       // CHANGED:
            return true;                                                                      // CHANGED:
        }
        if (function_exists('get_current_screen')) {                                          // CHANGED:
            $screen = get_current_screen();                                                   // CHANGED:
            if ($screen && (strpos((string) ($screen->id ?? ''), 'postpress-ai') !== false)) {// CHANGED:
                return true;                                                                  // CHANGED:
            }
        }
        return false;                                                                         // CHANGED:
    }                                                                                         // CHANGED:

    /**
     * Deprecated no-op. Assets are now enqueued exclusively by inc/admin/enqueue.php.         // CHANGED:
     */
    public static function enqueue($hook_suffix = null) { // CHANGED:
        if (defined('WP_DEBUG') && WP_DEBUG) {                                                // CHANGED:
            error_log('PPA: Admin::enqueue() is deprecated; assets load via inc/admin/enqueue.php'); // CHANGED:
        }                                                                                     // CHANGED:
        return;                                                                               // CHANGED:
    }                                                                                         // CHANGED:
}                                                                                             // CHANGED:

// Bootstrap (kept for compatibility)                                                          // CHANGED:
Admin::init();                                                                                // CHANGED:
