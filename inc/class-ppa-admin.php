<?php
# /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/inc/class-ppa-admin.php
namespace PPA\Admin; // single backslashes only

/*
CHANGE LOG
----------
2025-10-31 • Remove inline config injection from enqueue(); config now centralized via ppa_admin_enqueue.   # CHANGED:
2025-10-30 • Remove inline assets from Testbed render; rely on enqueued files (admin.js / ppa-testbed.js).  # CHANGED:
2025-10-29 • Add Tools → PPA Testbed admin page with Preview + Save Draft buttons (temporary inline code).  # CHANGED:
*/

defined('ABSPATH') || exit;

class PPA_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);                         # CHANGED:
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);                    # CHANGED:
    }

    /**
     * Local enqueue hook for this class.
     * We previously injected a global config here; that is now centralized in inc/admin/enqueue.php
     * via ppa_admin_enqueue(), so this method remains as a no-op for compatibility.             # CHANGED:
     */
    public function enqueue($hook) {
        // Intentionally empty. All admin assets and config are enqueued centrally
        // by ppa_admin_enqueue() in inc/admin/enqueue.php.                                   # CHANGED:
        return;
    }

    public function register_menu() {
        add_submenu_page(
            'tools.php',
            'PPA Testbed',
            'PPA Testbed',
            'edit_posts',
            'ppa-testbed',
            [$this, 'render_testbed']                                              # CHANGED:
        );
    }

    public function render_testbed() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to access this page.', 'postpress-ai'));
        }

        // Minimal admin UI — title/content inputs, buttons, and a log pane.
        // Page behavior lives in enqueued assets (ppa-testbed.js / admin.js).                   # CHANGED:
        ?>
        <div class="wrap">
            <h1>PostPress AI — Testbed</h1>
            <p>This panel calls the same AJAX actions your plugin uses (<code>ppa_preview</code> and <code>ppa_store</code>).
               Use it to verify behavior from inside WP-Admin.</p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ppaTbTitle">Title</label></th>
                        <td><input id="ppaTbTitle" type="text" class="regular-text" value="Hello from PPA Testbed"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ppaTbContent">Content</label></th>
                        <td><textarea id="ppaTbContent" class="large-text" rows="6">Body generated via Testbed.</textarea></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button class="button button-secondary" id="ppaTbPreview">Preview (no write)</button>
                <button class="button button-primary" id="ppaTbDraft">Save Draft (creates post)</button>
            </p>

            <h2>Response</h2>
            <div id="ppaTbLog" style="background:#fff;border:1px solid #ddd;padding:12px;min-height:120px;white-space:pre-wrap;"></div>

            <p><em>Note: This screen now uses enqueued scripts for all behavior (no inline JS).</em></p>   <!-- CHANGED -->
        </div>
        <?php
    }
}

// Bootstrap
new PPA_Admin();
