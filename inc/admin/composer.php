<?php
/**
 * CHANGE LOG
 * 2025-10-31 — Removed inline <script> block; events now handled by assets/js/admin.js. // CHANGED
 * 2025-10-19 — Added "Preview" heading to right pane to match page heading. // CHANGED
 * 2025-10-19 — Cleaned UI; dark two-column layout with Subject, Genre, Tone, Word Count,
 *               Optional brief, and actions (Preview, Save Draft, Publish).
 * - Capability checks are delegated to the menu registration in postpress-ai.php.
 * - Inline JS is a defensive fallback; main wiring should live in assets/js/admin.js.
 */

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

error_log( 'PPA: composer.php rendering at ' . date( 'c' ) ); // CHANGED

// Nonce for AJAX (header X-PPA-Nonce) — validated server-side where applicable
$ppa_nonce = wp_create_nonce( 'ppa-admin' );

// Optional: pull current user data (for default author display or hints)
$current_user = wp_get_current_user();

// NOTE: Window.PPA base config is injected by enqueues via wp_add_inline_script.
// This template intentionally avoids inline <script> to comply with our “assets-only” policy. // CHANGED

?>
<div class="wrap ppa-composer-wrap" id="ppa-composer" data-ppa-nonce="<?php echo esc_attr( $ppa_nonce ); ?>">
    <style>
        /* Fallback styles — primary styles should live in assets/css/admin.css */
        .ppa-composer-wrap { display:grid; grid-template-columns: 1fr 1fr; gap:24px; }
        .ppa-form-panel, .ppa-preview-panel { background:#121212; color:#f1f1f1; padding:20px; border-radius:12px; }
        .ppa-form-group { margin-bottom:16px; }
        .ppa-form-group label { display:block; font-weight:600; margin-bottom:6px; }
        .ppa-form-group input[type="text"],
        .ppa-form-group input[type="number"],
        .ppa-form-group textarea,
        .ppa-form-group select {
            width:100%; background:#1e1e1e; color:#f1f1f1; border:1px solid #2a2a2a; border-radius:8px; padding:10px;
        }
        .ppa-actions { display:flex; align-items:center; gap:10px; margin-top:12px; }
        .ppa-btn { background:#ff6c00; color:#121212; border:none; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:700; }
        .ppa-btn-secondary { background:#2a2a2a; color:#f1f1f1; }
        .ppa-note { display:block; font-size:12px; color:#9aa0a6; margin-top:6px; }
        .ppa-preview-panel h1 { margin-top:0; }
        .ppa-inline { display:flex; gap:10px; }
        .ppa-inline .ppa-form-group { flex:1; }
        .ppa-hint { font-size:12px; color:#9aa0a6; }
    </style>

    <div class="ppa-form-panel" aria-label="PostPress AI Composer">
        <h1>PostPress AI — Composer</h1>
        <p class="ppa-hint">Signed in as <strong><?php echo esc_html( $current_user->display_name ?: $current_user->user_login ); ?></strong></p>

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
            <button id="ppa-save-draft" class="ppa-btn ppa-btn-secondary" type="button">Save to Draft</button>
            <button id="ppa-publish" class="ppa-btn ppa-btn-secondary" type="button">Publish</button>
            <span class="ppa-note">Preview uses the AI backend. “Save to Draft” will create a draft in WordPress. “Publish” will publish immediately.</span>
        </div>
    </div>

    <div class="ppa-preview-panel">
        <h1>Preview</h1> <!-- Added heading to match left panel --> <!-- CHANGED -->
        <div id="ppa-preview-pane" aria-live="polite"><em style="color:#666;">(Preview will appear here once generated.)</em></div>
    </div>
</div>

<?php /* Inline script removed on 2025-10-31 — handled by assets/js/admin.js. // CHANGED */ ?>
