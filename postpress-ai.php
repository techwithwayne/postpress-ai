<?php
/**
 * Plugin Name: PostPress AI
 * Description: Secure server-to-server AI content preview & store via Django (PostPress AI). Adds a Composer screen and server-side AJAX proxy to your Django backend.
 * Author: Tech With Wayne
 * Version: 2.1.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Text Domain: postpress-ai
 *
 * @package PostPressAI
 */

/**
 * PostPress AI — Plugin Bootstrap
 *
 * CHANGE LOG
 * 2025-11-03 — Wire class-based frontend shortcode and remove legacy function version.             # CHANGED:
 *              - require_once inc/shortcodes/class-ppa-shortcodes.php                             # CHANGED:
 *              - \PPA\Shortcodes\PPAShortcodes::init() registers [postpress_ai_preview]           # CHANGED:
 *              - Drop old wp_enqueue_scripts + ppa_render_public_preview (used missing JS)         # CHANGED:
 * 2025-11-03 — Frontend shortcode [postpress_ai_preview] + public asset registration.                 # CHANGED:
 *              - No inline JS/CSS; registers/enqueues assets/js/shortcode-preview.js.               # CHANGED:
 *              - Shortcode renders a minimal, accessible form + preview pane (no keys client-side). # CHANGED:
 * 2025-10-30 — Enqueue fix: also load admin assets on Testbed screen (tools_page_ppa-testbed)
 *              and when ?page=ppa-testbed; previously only Composer screen was recognized.   # CHANGED:
 * 2025-10-19 — Restore proper admin page registration + access for Admin/Editor/Author.      # CHANGED
 * - Registers a top-level menu with capability 'edit_posts' (covers admin/editor/author).    # CHANGED
 * - Uses render callback ppa_render_composer() to include inc/admin/composer.php.            # CHANGED
 * - Defers admin assets to admin_enqueue_scripts and scopes to our screen only.              # CHANGED
 * - Loads AJAX handlers on 'init' so admin-ajax.php works.                                   # CHANGED
 * - Adds detailed debug logs (error_log('PPA: ...')).                                        # CHANGED
 *
 * Notes:
 * - Do not echo output here; only register hooks. The UI is rendered by composer.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Paths
if ( ! defined( 'PPA_PLUGIN_DIR' ) ) {
    define( 'PPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PPA_PLUGIN_URL' ) ) {
    define( 'PPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Debug: bootstrap loaded
error_log( 'PPA: bootstrap loaded; dir=' . PPA_PLUGIN_DIR ); // CHANGED

/**
 * Register admin menu (visible to admins, editors, authors).
 * Capability 'edit_posts' intentionally chosen to include those roles.
 */
add_action( 'admin_menu', function () {
    $capability = 'edit_posts'; // admin/editor/author
    $menu_slug  = 'postpress-ai'; // keep slug stable + predictable

    add_menu_page(
        __( 'PostPress AI', 'postpress-ai' ),
        __( 'PostPress AI', 'postpress-ai' ),
        $capability,
        $menu_slug,
        'ppa_render_composer',
        'dashicons-welcome-widgets-menus',
        65
    );

    error_log( 'PPA: admin_menu registered (slug=' . $menu_slug . ', cap=' . $capability . ')' ); // CHANGED
}, 9 );

/**
 * Render callback — includes the composer UI (no capability checks here;
 * WP has already enforced 'edit_posts' for us).
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {
    function ppa_render_composer() {
        if ( ! is_admin() ) {
            return;
        }
        $composer = PPA_PLUGIN_DIR . 'inc/admin/composer.php';
        if ( file_exists( $composer ) ) {
            error_log( 'PPA: including composer.php' ); // CHANGED
            require $composer;
        } else {
            error_log( 'PPA: composer.php missing at ' . $composer ); // CHANGED
            echo '<div class="wrap"><h1>PostPress AI</h1><p>Composer UI not found.</p></div>';
        }
    }
}

/**
 * Enqueue admin assets (Composer + Testbed screens).
 */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
    // Resolve current screen safely
    $screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $screen_id  = $screen ? $screen->id : '';
    $page_param = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';

    // Our known screens:
    $composer_id = 'toplevel_page_postpress-ai';
    $testbed_id  = 'tools_page_ppa-testbed';                          // CHANGED

    // Recognize both Composer and Testbed (with querystring fallback)            // CHANGED
    $is_composer = ( $screen_id === $composer_id ) || ( $page_param === 'postpress-ai' );
    $is_testbed  = ( $screen_id === $testbed_id )  || ( $page_param === 'ppa-testbed' );

    $enqueue = PPA_PLUGIN_DIR . 'inc/admin/enqueue.php';
    if ( file_exists( $enqueue ) ) {
        require_once $enqueue; // expected to define ppa_admin_enqueue()
        if ( function_exists( 'ppa_admin_enqueue' ) && ( $is_composer || $is_testbed ) ) {   // CHANGED
            error_log( 'PPA: enqueue assets for ' . $hook_suffix . ' (composer=' . (int) $is_composer . ', testbed=' . (int) $is_testbed . ')' ); // CHANGED
            ppa_admin_enqueue();
        }
    } else {
        error_log( 'PPA: enqueue.php not found; skipping assets' );
    }
}, 10 );

/**
 * AJAX handlers — load early so admin-ajax.php can find them.
 */
add_action( 'init', function () {
    $ajax_dir = PPA_PLUGIN_DIR . 'inc/ajax/';

    foreach ( array( 'preview.php', 'store.php', 'marker.php' ) as $file ) {
        $path = $ajax_dir . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
            error_log( 'PPA: ajax loaded ' . $file ); // CHANGED
        }
    }
}, 11 );

/**
 * ===== PPA TESTBED SUBMENU BLOCK (non-invasive; appended) =====
 * - Adds submenu under the existing top-level "PostPress AI" menu.
 * - Capability: 'edit_posts' (Admin/Editor/Author).
 * - Renders a tiny UI with Preview (no write) + Save Draft (creates WP draft).
 * - Uses admin-ajax actions: ppa_preview / ppa_store (JSON body).
 * - Safe guards to avoid re-definition on repeated loads.
 */
if ( function_exists('add_action') && ! function_exists('ppa_render_testbed') ) {

    add_action('admin_menu', function () {
        $parent_slug = 'postpress-ai';   // Your existing top-level slug
        $capability  = 'edit_posts';
        $menu_slug   = 'ppa-testbed';

        // Register as a submenu under "PostPress AI"
        add_submenu_page(
            $parent_slug,
            __('PPA Testbed', 'postpress-ai'),
            __('PPA Testbed', 'postpress-ai'),
            $capability,
            $menu_slug,
            'ppa_render_testbed'
        );

        error_log('PPA: admin_menu submenu registered (slug=' . $menu_slug . ')'); // breadcrumb
    }, 20);

    /**
     * Render the Testbed screen. Minimal inline JS (temporary) for immediate testing.
     * We can move this JS into an admin asset in a later one-file turn.
     */
    function ppa_render_testbed() {
        if ( ! current_user_can('edit_posts') ) {
            wp_die(__('You do not have permission to access this page.', 'postpress-ai'));
        }

        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <div class="wrap">
            <h1>PostPress AI — Testbed</h1>
            <p>This panel calls the same AJAX actions the plugin uses:
               <code>ppa_preview</code> (no write) and <code>ppa_store</code> (Save Draft).</p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ppaTbTitle">Title</label></th>
                        <td><input id="ppaTbTitle" type="text" class="regular-text" value="Hello from Testbed"></td>
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
        </div>

        <script>
        (function(){
            const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            const logBox  = document.getElementById('ppaTbLog');
            const tIn     = document.getElementById('ppaTbTitle');
            const cIn     = document.getElementById('ppaTbContent');

            function log(msg){ logBox.textContent = (typeof msg === 'string') ? msg : JSON.stringify(msg, null, 2); }

            async function postJSON(action, payload){
                const res = await fetch(ajaxUrl + '?action=' + encodeURIComponent(action), {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify(payload || {})
                });
                const txt = await res.text();
                try { return JSON.parse(txt); } catch(e){ return {ok:false, raw:txt}; }
            }

            document.getElementById('ppaTbPreview').addEventListener('click', async function(){
                log('Working...');
                const data = await postJSON('ppa_preview', {
                    title: tIn.value,
                    content: cIn.value,
                    status: 'draft'
                });
                log(data);
            });

            document.getElementById('ppaTbDraft').addEventListener('click', async function(){
                log('Working...');
                const data = await postJSON('ppa_store', {
                    title: tIn.value,
                    content: cIn.value,
                    status: 'draft',
                    mode: 'draft'
                });
                log(data);
            });
        })();
        </script>
        <?php
    }
}
/* ===== end PPA TESTBED SUBMENU BLOCK ===== */

/**
 * ===== FRONTEND SHORTCODE (class-based) =============================================          # CHANGED:
 * Register [postpress_ai_preview] via PPAShortcodes.                                            # CHANGED:
 * PPAShortcodes handles asset registration/enqueue and rendering (no inline JS/CSS).            # CHANGED:
 */
add_action( 'plugins_loaded', function () {                                                       // CHANGED:
    $shortcode_file = PPA_PLUGIN_DIR . 'inc/shortcodes/class-ppa-shortcodes.php';                 // CHANGED:
    if ( file_exists( $shortcode_file ) ) {                                                       // CHANGED:
        require_once $shortcode_file;                                                             // CHANGED:
        if ( class_exists( '\PPA\Shortcodes\PPAShortcodes' ) ) {                                  // CHANGED:
            \PPA\Shortcodes\PPAShortcodes::init();                                                // CHANGED:
            error_log( 'PPA: PPAShortcodes wired (class-based shortcode active)' );               // CHANGED:
        } else {
            error_log( 'PPA: PPAShortcodes class missing after require (check namespace)' );      // CHANGED:
        }
    } else {
        error_log( 'PPA: shortcode file missing at ' . $shortcode_file );                         // CHANGED:
    }
}, 12 );                                                                                          // CHANGED:

/**
 * LEGACY FRONTEND SHORTCODE (REMOVED)                                                          # CHANGED:
 * The previous function-based shortcode used assets/js/shortcode-preview.js which               # CHANGED:
 * does not exist. Replaced by class-based implementation that enqueues assets/js/ppa-frontend.js# CHANGED:
 * No keys are exposed client-side; server-side proxy remains authoritative.                     # CHANGED:
 */

// (legacy wp_enqueue_scripts + ppa_render_public_preview removed)                               // CHANGED:
