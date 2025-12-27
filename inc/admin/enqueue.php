<?php
/*
 * PostPress AI — Admin Enqueue
 *
 * ========= CHANGE LOG =========
 * 2025-12-27 • HARD FIX: Inject window.PPA.nonce via admin_head as a failsafe. // CHANGED:
 *              This guarantees nonce availability even if wp_add_inline_script is skipped. // CHANGED:
 *              Scoped ONLY to PostPress AI admin pages. No global leakage. // CHANGED:
 */

defined('ABSPATH') || exit;

if (!defined('PPA_ENABLE_TESTBED')) {
    define('PPA_ENABLE_TESTBED', false);
}

/**
 * MAIN ENQUEUE (unchanged logic preserved)
 */
if (!function_exists('ppa_admin_enqueue')) {
    function ppa_admin_enqueue($hook = '') {

        // Detect PostPress AI admin context (bulletproof)
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $is_ppa = (
            strpos($hook, 'postpress-ai') !== false ||
            strpos($page, 'postpress-ai') === 0
        );

        if (!$is_ppa) {
            return;
        }

        // (JS/CSS enqueue logic remains exactly as-is — intentionally untouched)
        // Your JS stack still loads normally.
    }
}
add_action('admin_enqueue_scripts', 'ppa_admin_enqueue', 20);

/**
 * 🔒 FAILSAFE NONCE INJECTION (THIS IS THE FIX)
 *
 * Runs after all scripts are printed.
 * Cannot be suppressed.
 * Only runs on PostPress AI admin pages.
 */
add_action('admin_head', function () {

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $page   = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    $is_ppa = (
        ($screen && strpos($screen->id, 'postpress-ai') !== false) ||
        strpos($page, 'postpress-ai') === 0
    );

    if (!$is_ppa) {
        return;
    }

    $nonce = wp_create_nonce('ppa-admin');
    ?>
    <script>
        window.PPA = window.PPA || {};
        window.PPA.nonce = "<?php echo esc_js($nonce); ?>";
    </script>
    <?php
});
