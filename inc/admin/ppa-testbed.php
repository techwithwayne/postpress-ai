<?php
/**
 * PostPress AI — Testbed Template
 * Path: inc/admin/ppa-testbed.php
 *
 * ========= CHANGE LOG =========
 * 2025-11-15: Add Debug Headers button wired to ppa-testbed.js / ppa_debug_headers for Django diagnostics.   // CHANGED:
 * 2025-11-10: New dedicated Testbed template. No inline assets; IDs aligned with JS;          // CHANGED:
 *             aria-live regions for status/output; minimal, translatable strings.             // CHANGED:
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Current user (optional display)
$current_user = wp_get_current_user();
?>
<div class="wrap ppa-testbed-wrap">
    <h1><?php echo esc_html__( 'Testbed', 'postpress-ai' ); ?></h1>

    <p class="ppa-hint">
        <?php
        /* translators: %s: current user display name */
        printf( esc_html__( 'Signed in as %s.', 'postpress-ai' ), esc_html( $current_user->display_name ?: $current_user->user_login ) );
        ?>
    </p>

    <!-- Live status region (consumed by ppa-testbed.js) -->
    <div id="ppa-testbed-status" class="ppa-notice" role="status" aria-live="polite"></div> <!-- CHANGED: -->

    <div class="ppa-form-group">
        <label for="ppa-testbed-input"><?php echo esc_html__( 'Payload (JSON or brief text)', 'postpress-ai' ); ?></label>
        <textarea id="ppa-testbed-input" rows="8" placeholder="<?php echo esc_attr__( 'Enter JSON for advanced control or plain text for a quick brief…', 'postpress-ai' ); ?>"></textarea> <!-- CHANGED: -->
        <p class="ppa-hint">
            <?php echo esc_html__( 'Tip: Paste JSON for structured testing. If plain text is provided, it will be sent as a brief.', 'postpress-ai' ); ?>
        </p>
    </div>

    <div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Testbed actions', 'postpress-ai' ); ?>">
        <button id="ppa-testbed-preview" class="ppa-btn" type="button">
            <?php echo esc_html__( 'Preview', 'postpress-ai' ); ?>
        </button> <!-- CHANGED: -->

        <button id="ppa-testbed-store" class="ppa-btn ppa-btn-secondary" type="button">
            <?php echo esc_html__( 'Save to Draft', 'postpress-ai' ); ?>
        </button> <!-- CHANGED: -->

        <button id="ppa-testbed-debug" class="ppa-btn ppa-btn-secondary" type="button"> <!-- CHANGED: -->
            <?php echo esc_html__( 'Debug Headers', 'postpress-ai' ); ?> <!-- CHANGED: -->
        </button> <!-- CHANGED: -->

        <span class="ppa-note">
            <?php echo esc_html__( 'Preview calls the AI backend. “Save to Draft” creates a WordPress draft. “Debug Headers” shows what Django actually receives.', 'postpress-ai' ); ?> <!-- CHANGED: -->
        </span>
    </div>

    <h2 class="screen-reader-text">
        <?php echo esc_html__( 'Response Output', 'postpress-ai' ); ?>
    </h2>
    <pre
        id="ppa-testbed-output"
        aria-live="polite"
        aria-label="<?php echo esc_attr__( 'Preview, store, or debug headers response output', 'postpress-ai' ); ?>"></pre> <!-- CHANGED: -->
</div>
