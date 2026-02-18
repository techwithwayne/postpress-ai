<?php
/**
 * PostPress AI — Account Screen
 *
 * ========= CHANGE LOG =========
 * 2026-01-15: ADD: New Account screen shell (token usage, account details, sites, upgrade/purchase CTAs).
 *            UI is intentionally self-contained; assets are isolated in assets/css/admin-account.css and assets/js/admin-account.js.
 *
 * 2026-01-25: HARDEN: Remove hardcoded payment/upgrade URLs from WP UI; render disabled placeholders by default. // CHANGED:
 *            ADD: Stable link targets (IDs + data attrs) for Django-provided links: upgrade, buy_tokens, billing_portal. // CHANGED:
 *            HARDEN: Buttons are safe-disabled (aria-disabled + tabindex) when link not available.              // CHANGED:
 *
 * 2026-01-26: UI: Add placeholders for license.v1 totals: tokens_remaining_total + sites.remaining.            // CHANGED:
 *            FIX: Prevent invalid duplicate class="" attributes on disabled buttons.                           // CHANGED:
 *            HARDEN: Wrap helper in function_exists to avoid redeclare fatals.                                 // CHANGED:
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Local, WP-known details (backend will overwrite/augment via AJAX when available).
$license_key   = get_option( 'ppa_license_key', '' );
$license_state = get_option( 'ppa_license_state', 'unknown' );
$site_url      = home_url( '/' );

$license_key   = is_string( $license_key ) ? trim( $license_key ) : '';
$license_state = is_string( $license_state ) ? strtolower( trim( $license_state ) ) : 'unknown';

// Mask license key for display (keep last 6).
$masked = '';
if ( $license_key !== '' ) {
    $last   = substr( $license_key, -6 );
    $masked = str_repeat( '•', max( 0, strlen( $license_key ) - 6 ) ) . $last;
    // Keep it readable.
    if ( strlen( $masked ) > 18 ) {
        $masked = '••••••••••••' . $last;
    }
}

// IMPORTANT:
// Django is authoritative for plan links. WordPress should not hardcode payment/upgrade destinations. // CHANGED:
// These placeholders are intentionally disabled until JS receives links from Django via /license/verify/ (license.v1). // CHANGED:
$upgrade_url        = ''; // CHANGED:
$buy_tokens_url     = ''; // CHANGED:
$billing_portal_url = ''; // CHANGED:

/**
 * Render safe "disabled" link attributes.
 *
 * IMPORTANT FIX (2026-01-26): this MUST NOT emit class="" because the <a> already has class="" in markup. // CHANGED:
 * Duplicate class attributes are invalid HTML and can break CSS/JS selectors in some browsers.            // CHANGED:
 */
if ( ! function_exists( 'ppa_disabled_btn_attrs' ) ) { // CHANGED:
    function ppa_disabled_btn_attrs() { // CHANGED:
        return 'href="#" aria-disabled="true" tabindex="-1"'; // CHANGED:
    }
}

?>

<div class="wrap ppa-admin ppa-account">
    <div class="ppa-header">
        <div class="ppa-header__left">
            <h1 class="ppa-title"><?php echo esc_html__( 'PostPress AI Account', 'postpress-ai' ); ?></h1>
            <p class="ppa-subtitle"><?php echo esc_html__( 'Track your usage, manage your plan, and keep your sites moving.', 'postpress-ai' ); ?></p>
        </div>
        <div class="ppa-header__right">
            <button type="button" class="button button-primary ppa-btn" id="ppa-account-refresh">
                <?php echo esc_html__( 'Refresh', 'postpress-ai' ); ?>
            </button>
        </div>
    </div>

    <div id="ppa-account-status" class="ppa-status" role="status" aria-live="polite">
        <span class="ppa-status__dot" aria-hidden="true"></span>
        <span class="ppa-status__text"><?php echo esc_html__( 'Ready.', 'postpress-ai' ); ?></span>
    </div>

    <div class="ppa-grid">
        <section class="ppa-card" aria-label="Account overview">
            <div class="ppa-card__head">
                <h2 class="ppa-card__title"><?php echo esc_html__( 'Overview', 'postpress-ai' ); ?></h2>
                <div class="ppa-pill" id="ppa-license-pill" data-state="<?php echo esc_attr( $license_state ); ?>">
                    <?php
                    if ( 'active' === $license_state ) {
                        echo esc_html__( 'License Active', 'postpress-ai' );
                    } elseif ( 'inactive' === $license_state ) {
                        echo esc_html__( 'License Inactive', 'postpress-ai' );
                    } else {
                        echo esc_html__( 'License Unknown', 'postpress-ai' );
                    }
                    ?>
                </div>
            </div>

            <dl class="ppa-kv">
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'Site', 'postpress-ai' ); ?></dt>
                    <dd><?php echo esc_html( $site_url ); ?></dd>
                </div>
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'License Key', 'postpress-ai' ); ?></dt>
                    <dd id="ppa-license-key"><?php echo $masked !== '' ? esc_html( $masked ) : esc_html__( '(not set)', 'postpress-ai' ); ?></dd>
                </div>
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'Plan', 'postpress-ai' ); ?></dt>
                    <dd id="ppa-plan-name">—</dd>
                </div>
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'Billing Email', 'postpress-ai' ); ?></dt>
                    <dd id="ppa-billing-email">—</dd>
                </div>
            </dl>
        </section>

        <section class="ppa-card" aria-label="Token usage">
            <div class="ppa-card__head">
                <h2 class="ppa-card__title"><?php echo esc_html__( 'Token Usage', 'postpress-ai' ); ?></h2>
                <div class="ppa-muted" id="ppa-tokens-period">—</div>
            </div>

            <div class="ppa-meter" role="img" aria-label="Token usage meter">
                <div class="ppa-meter__bar" id="ppa-tokens-bar" style="width:0%"></div>
            </div>

            <div class="ppa-usage">
                <div class="ppa-usage__big" id="ppa-tokens-used">—</div>
                <div class="ppa-usage__small">
                    <span id="ppa-tokens-limit">—</span>
                    <span class="ppa-usage__sep">•</span>
                    <span id="ppa-tokens-remaining">—</span>
                    <span class="ppa-usage__sep">•</span>
                    <span id="ppa-tokens-remaining-total">—</span> <!-- CHANGED: tokens_remaining_total (monthly remaining + purchased) -->
                </div>
            </div>

            <div class="ppa-card__actions">
                <?php
                // Disabled by default; JS can set href when Django returns license.links.buy_tokens. // CHANGED:
                if ( ! empty( $buy_tokens_url ) ) : // CHANGED:
                    ?>
                    <a
                        id="ppa-account-buy-tokens"
                        data-ppa-link="buy_tokens"
                        class="button ppa-btn ppa-btn--ghost"
                        href="<?php echo esc_url( $buy_tokens_url ); ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <?php echo esc_html__( 'Buy Tokens', 'postpress-ai' ); ?>
                    </a>
                    <?php
                else :
                    ?>
                    <a
                        id="ppa-account-buy-tokens"
                        data-ppa-link="buy_tokens"
                        class="button ppa-btn ppa-btn--ghost is-disabled"
                        <?php echo ppa_disabled_btn_attrs(); // CHANGED: no class="" emitted ?>
                    >
                        <?php echo esc_html__( 'Buy Tokens', 'postpress-ai' ); ?>
                    </a>
                    <?php
                endif;
                ?>
            </div>
        </section>

        <section class="ppa-card" aria-label="Sites">
            <div class="ppa-card__head">
                <h2 class="ppa-card__title"><?php echo esc_html__( 'Sites', 'postpress-ai' ); ?></h2>
                <div class="ppa-muted" id="ppa-sites-label">—</div>
            </div>

            <dl class="ppa-kv">
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'Active Sites', 'postpress-ai' ); ?></dt>
                    <dd id="ppa-sites-active">—</dd>
                </div>
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'Site Limit', 'postpress-ai' ); ?></dt>
                    <dd id="ppa-sites-limit">—</dd>
                </div>
                <div class="ppa-kv__row">
                    <dt><?php echo esc_html__( 'Remaining Sites', 'postpress-ai' ); ?></dt>
                    <dd id="ppa-sites-remaining">—</dd> <!-- CHANGED: sites.remaining (null for unlimited) -->
                </div>
            </dl>

            <div class="ppa-list" id="ppa-sites-list">
                <div class="ppa-list__empty"><?php echo esc_html__( 'Refresh to load your activated sites.', 'postpress-ai' ); ?></div>
            </div>
        </section>

        <section class="ppa-card" aria-label="Actions">
            <div class="ppa-card__head">
                <h2 class="ppa-card__title"><?php echo esc_html__( 'Actions', 'postpress-ai' ); ?></h2>
            </div>

            <div class="ppa-actions">
                <?php
                // Disabled by default; JS can set href when Django returns license.links.upgrade. // CHANGED:
                if ( ! empty( $upgrade_url ) ) : // CHANGED:
                    ?>
                    <a
                        id="ppa-account-upgrade"
                        data-ppa-link="upgrade"
                        class="button button-primary ppa-btn"
                        href="<?php echo esc_url( $upgrade_url ); ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <?php echo esc_html__( 'Upgrade Plan', 'postpress-ai' ); ?>
                    </a>
                    <?php
                else :
                    ?>
                    <a
                        id="ppa-account-upgrade"
                        data-ppa-link="upgrade"
                        class="button button-primary ppa-btn is-disabled"
                        <?php echo ppa_disabled_btn_attrs(); // CHANGED: no class="" emitted ?>
                    >
                        <?php echo esc_html__( 'Upgrade Plan', 'postpress-ai' ); ?>
                    </a>
                    <?php
                endif;

                // Disabled by default; JS can set href when Django returns license.links.billing_portal. // CHANGED:
                if ( ! empty( $billing_portal_url ) ) : // CHANGED:
                    ?>
                    <a
                        id="ppa-account-billing-portal"
                        data-ppa-link="billing_portal"
                        class="button ppa-btn ppa-btn--ghost"
                        href="<?php echo esc_url( $billing_portal_url ); ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <?php echo esc_html__( 'Billing Portal', 'postpress-ai' ); ?>
                    </a>
                    <?php
                else :
                    ?>
                    <a
                        id="ppa-account-billing-portal"
                        data-ppa-link="billing_portal"
                        class="button ppa-btn ppa-btn--ghost is-disabled"
                        <?php echo ppa_disabled_btn_attrs(); // CHANGED: no class="" emitted ?>
                    >
                        <?php echo esc_html__( 'Billing Portal', 'postpress-ai' ); ?>
                    </a>
                    <?php
                endif;
                ?>

                <a class="button ppa-btn ppa-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=postpress-ai-settings' ) ); ?>">
                    <?php echo esc_html__( 'Open Settings', 'postpress-ai' ); ?>
                </a>
            </div>

            <div class="ppa-note">
                <strong><?php echo esc_html__( 'Heads up:', 'postpress-ai' ); ?></strong>
                <?php echo esc_html__( 'If your numbers look off, hit Refresh. If it still doesn’t update, re-save Settings and try again.', 'postpress-ai' ); ?>
                <br />
                <span class="ppa-muted">
                    <?php echo esc_html__( 'Upgrade/Buy/Billing links appear only when your plan provides them.', 'postpress-ai' ); ?>
                </span>
            </div>
        </section>
    </div>

    <input type="hidden" id="ppa-account-site" value="<?php echo esc_attr( $site_url ); ?>" />
</div>
