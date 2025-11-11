<?php
/**
 * CHANGE LOG
 * 2025-11-09 — Remove hardcoded <link> CSS fallback; centralized enqueue owns styles.        // CHANGED:
 * 2025-11-09 — Update H1 to "PostPress Composer" for menu consistency.                      // CHANGED:
 * 2025-11-08 — Add versioned external CSS fallback (?ver=filemtime) to bust cache.
 * 2025-11-08 — Strip inline <style>; rely on assets/css/admin.css.
 * 2025-11-08 — Add #ppa-toolbar-msg live region for notices from admin.js.
 * 2025-10-31 — Removed inline <script>; events handled by admin.js.
 * 2025-10-19 — Added "Preview" heading to right pane.
 */

if (!defined('ABSPATH')) { exit; }

// Optional debug trace (safe; no secrets)
error_log('PPA: composer.php rendering at ' . date('c'));

// Nonce for AJAX headers (validated server-side where applicable)
$ppa_nonce    = wp_create_nonce('ppa-admin');
$current_user = wp_get_current_user();

// Styles are enqueued centrally in inc/admin/enqueue.php; no local <link> fallback.          // CHANGED:
?>
<!-- (No inline CSS; centralized enqueue supplies admin.css and admin.js) -->                <!-- CHANGED -->

<div class="wrap ppa-composer-wrap" id="ppa-composer" data-ppa-nonce="<?php echo esc_attr($ppa_nonce); ?>">

    <div class="ppa-form-panel" aria-label="PostPress AI Composer">
        <h1>PostPress Composer</h1>                                                          <!-- CHANGED: -->
        <p class="ppa-hint">
            Signed in as <strong><?php echo esc_html($current_user->display_name ?: $current_user->user_login); ?></strong>
        </p>

        <!-- Live notice region consumed by admin.js -->
        <div id="ppa-toolbar-msg" class="ppa-notice" role="status" aria-live="polite"></div>

        <div class="ppa-form-group">
            <label for="ppa-subject">Subject / Title</label>
            <input type="text" id="ppa-subject" placeholder="What is this post about?">
        </div>

        <div class="ppa-inline">
            <div class="ppa-form-group">
                <label for="ppa-genre">Genre</label>
                <select id="ppa-genre">
                    <option value="">Auto</option>
                    <option value="howto">How-to</option>
                    <option value="listicle">Listicle</option>
                    <option value="news">News</option>
                    <option value="review">Review</option>
                </select>
            </div>
            <div class="ppa-form-group">
                <label for="ppa-tone">Tone</label>
                <select id="ppa-tone">
                    <option value="">Auto</option>
                    <option value="casual">Casual</option>
                    <option value="friendly">Friendly</option>
                    <option value="professional">Professional</option>
                    <option value="technical">Technical</option>
                </select>
            </div>
            <div class="ppa-form-group">
                <label for="ppa-word-count">Word Count</label>
                <input type="number" id="ppa-word-count" min="300" step="100" placeholder="e.g. 1200">
            </div>
        </div>

        <div class="ppa-form-group">
            <label for="ppa-brief">Optional brief / extra instructions</label>
            <textarea id="ppa-brief" rows="6" placeholder="Any details, links, or constraints you want the AI to follow."></textarea>
        </div>

        <div class="ppa-actions" role="group" aria-label="Composer actions">
            <button id="ppa-preview" class="ppa-btn" type="button">Preview</button>
            <button id="ppa-draft" class="ppa-btn ppa-btn-secondary" type="button">Save to Draft</button>
            <button id="ppa-publish" class="ppa-btn ppa-btn-secondary" type="button">Publish</button>
            <span class="ppa-note">Preview uses the AI backend. “Save to Draft” creates a draft in WordPress. “Publish” publishes immediately.</span>
        </div>
    </div>

    <div class="ppa-preview-panel" aria-label="Preview panel">
        <h1>Preview</h1>
        <div id="ppa-preview-pane" aria-live="polite"><em>(Preview will appear here once generated.)</em></div>
    </div>
</div>
