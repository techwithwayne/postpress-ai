<?php
/**
 * PostPress AI — Composer Screen Markup
 * Path: inc/admin/composer.php
 *
 * CHANGE LOG
 * 2026-01-07 — FIX: Align Composer button labels to locked baseline:
 *              - “Generate Preview”
 *              - “Save Draft (Store)”
 *              Remove “Publish” + remove hidden “Preview” button from markup.                          // CHANGED:
 * 2026-01-07 — HARDEN: Remove inline style attributes (no inline CSS).                                 // CHANGED:
 * 2026-01-07 — CLEAN: Keep markup presentation-free; centralized enqueue owns CSS/JS.                  // CHANGED:
 *
 * 2026-01-02 — CLEAN: Remove routine composer render debug.log line (keep logs for real failures only).
 * 2025-11-11 — Add Advanced fields (#ppa-title, #ppa-excerpt, #ppa-slug) for admin.js autofill/store parity.
 * 2025-11-09 — Remove hardcoded <link> CSS fallback; centralized enqueue owns styles.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Nonce for AJAX headers (validated server-side where applicable)
$ppa_nonce    = wp_create_nonce( 'ppa-admin' );
$current_user = wp_get_current_user();

// Styles/scripts are enqueued centrally in inc/admin/enqueue.php; no local <link>/<script> fallback.
?>
<!-- (No inline CSS/JS; centralized enqueue supplies admin-composer.css + admin.js) -->

<div class="wrap ppa-composer-wrap" id="ppa-composer" data-ppa-nonce="<?php echo esc_attr( $ppa_nonce ); ?>">

    <div class="ppa-form-panel" aria-label="<?php echo esc_attr__( 'PostPress AI Composer', 'postpress-ai' ); ?>">
        <h1><?php echo esc_html__( 'PostPress Composer', 'postpress-ai' ); ?></h1>

        <p class="ppa-hint">
            <?php
            /* translators: %s: current user display name */
            printf(
                esc_html__( 'Signed in as %s.', 'postpress-ai' ),
                esc_html( $current_user->display_name ?: $current_user->user_login )
            );
            ?>
        </p>

        <!-- Live notice region consumed by admin.js -->
        <div id="ppa-toolbar-msg" class="ppa-notice" role="status" aria-live="polite"></div>

        <div class="ppa-form-group">
            <label for="ppa-subject"><?php echo esc_html__( 'Subject / Title', 'postpress-ai' ); ?></label>
            <input type="text" id="ppa-subject" placeholder="<?php echo esc_attr__( 'What is this post about?', 'postpress-ai' ); ?>">
        </div>

        <div class="ppa-form-group">
            <label for="ppa-audience"><?php echo esc_html__( 'Target audience', 'postpress-ai' ); ?></label>
            <input
                type="text"
                id="ppa-audience"
                placeholder="<?php echo esc_attr__( 'e.g. busy small business owners in Iowa', 'postpress-ai' ); ?>"
            />
        </div>

        <div class="ppa-inline">
            <div class="ppa-form-group">
                <label for="ppa-genre"><?php echo esc_html__( 'Genre', 'postpress-ai' ); ?></label>
                <select id="ppa-genre">
                    <option value=""><?php echo esc_html__( 'Auto', 'postpress-ai' ); ?></option>
                    <option value="howto"><?php echo esc_html__( 'How-to', 'postpress-ai' ); ?></option>
                    <option value="listicle"><?php echo esc_html__( 'Listicle', 'postpress-ai' ); ?></option>
                    <option value="news"><?php echo esc_html__( 'News', 'postpress-ai' ); ?></option>
                    <option value="review"><?php echo esc_html__( 'Review', 'postpress-ai' ); ?></option>
                </select>
            </div>

            <div class="ppa-form-group">
                <label for="ppa-tone"><?php echo esc_html__( 'Tone', 'postpress-ai' ); ?></label>
                <select id="ppa-tone">
                    <option value=""><?php echo esc_html__( 'Auto', 'postpress-ai' ); ?></option>
                    <option value="casual"><?php echo esc_html__( 'Casual', 'postpress-ai' ); ?></option>
                    <option value="friendly"><?php echo esc_html__( 'Friendly', 'postpress-ai' ); ?></option>
                    <option value="professional"><?php echo esc_html__( 'Professional', 'postpress-ai' ); ?></option>
                    <option value="technical"><?php echo esc_html__( 'Technical', 'postpress-ai' ); ?></option>
                </select>
            </div>

            <div class="ppa-form-group">
                <label for="ppa-word-count"><?php echo esc_html__( 'Word Count', 'postpress-ai' ); ?></label>
                <input type="number" id="ppa-word-count" min="300" step="100" placeholder="<?php echo esc_attr__( 'e.g. 1200', 'postpress-ai' ); ?>">
            </div>
        </div>

        <div class="ppa-form-group">
            <label for="ppa-brief"><?php echo esc_html__( 'Optional brief / extra instructions', 'postpress-ai' ); ?></label>
            <textarea id="ppa-brief" rows="6" placeholder="<?php echo esc_attr__( 'Any details, links, or constraints you want the AI to follow.', 'postpress-ai' ); ?>"></textarea>
        </div>

        <!-- Advanced (optional) fields wired to admin.js autofill/store -->
        <details class="ppa-advanced">
            <summary><?php echo esc_html__( 'Advanced (optional)', 'postpress-ai' ); ?></summary>

            <div class="ppa-form-group">
                <label for="ppa-title"><?php echo esc_html__( 'Title (override)', 'postpress-ai' ); ?></label>
                <input type="text" id="ppa-title" placeholder="<?php echo esc_attr__( 'Auto-filled after Generate Preview', 'postpress-ai' ); ?>"> <!-- CHANGED: copy -->
            </div>

            <div class="ppa-form-group">
                <label for="ppa-excerpt"><?php echo esc_html__( 'Excerpt (optional)', 'postpress-ai' ); ?></label>
                <textarea id="ppa-excerpt" rows="3" placeholder="<?php echo esc_attr__( 'Auto-filled after Generate Preview', 'postpress-ai' ); ?>"></textarea> <!-- CHANGED: copy -->
            </div>

            <div class="ppa-form-group">
                <label for="ppa-slug"><?php echo esc_html__( 'Slug (optional)', 'postpress-ai' ); ?></label>
                <input type="text" id="ppa-slug" placeholder="<?php echo esc_attr__( 'auto-generated-from-title', 'postpress-ai' ); ?>">
            </div>
        </details>

        <div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Composer actions', 'postpress-ai' ); ?>">
            <!-- IMPORTANT: Keep IDs stable for admin.js wiring -->
            <button id="ppa-generate" class="ppa-btn ppa-btn-primary" type="button"> <!-- CHANGED: label + primary -->
                <?php echo esc_html__( 'Generate Preview', 'postpress-ai' ); ?>      <!-- CHANGED -->
            </button>

            <button id="ppa-draft" class="ppa-btn ppa-btn-secondary" type="button"> <!-- CHANGED: label -->
                <?php echo esc_html__( 'Save Draft (Store)', 'postpress-ai' ); ?>   <!-- CHANGED -->
            </button>

            <span class="ppa-note">
                <?php
                echo esc_html__(
                    '“Generate Preview” talks to the AI backend and builds the preview + outline. “Save Draft (Store)” creates a WordPress draft from the generated content.',
                    'postpress-ai'
                ); // CHANGED:
                ?>
            </span>
        </div>
    </div>

    <div class="ppa-preview-panel" aria-label="<?php echo esc_attr__( 'Preview panel', 'postpress-ai' ); ?>">
        <h1><?php echo esc_html__( 'Preview', 'postpress-ai' ); ?></h1>
        <div id="ppa-preview-pane" aria-live="polite">
            <em><?php echo esc_html__( '(Preview will appear here once generated.)', 'postpress-ai' ); ?></em>
        </div>
    </div>
</div>
