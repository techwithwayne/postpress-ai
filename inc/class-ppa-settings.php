<?php
/**
 * PostPress AI — Legacy Settings Loader (compat stub)
 *
 * LOCATION
 * --------
 * /wp-content/plugins/postpress-ai/inc/class-ppa-settings.php
 *
 * WHY
 * ---
 * Some older builds/docs referenced this file.
 * Settings were moved to:
 * - /wp-content/plugins/postpress-ai/inc/admin/settings.php
 *
 * This stub prevents fatals on any site still including the legacy path.
 *
 * ========= CHANGE LOG =========
 * 2026-01-03 — ADD: Compatibility include wrapper; loads inc/admin/settings.php and boots settings. // CHANGED:
 */

defined( 'ABSPATH' ) || exit;

$ppa_new_settings = __DIR__ . '/admin/settings.php'; // CHANGED:

if ( file_exists( $ppa_new_settings ) ) { // CHANGED:
	require_once $ppa_new_settings; // CHANGED:

	// CHANGED: Safe boot (idempotent inside settings.php).
	if ( class_exists( 'PPA_Admin_Settings' ) && method_exists( 'PPA_Admin_Settings', 'init' ) ) { // CHANGED:
		PPA_Admin_Settings::init(); // CHANGED:
	} // CHANGED:
} else { // CHANGED:
	// CHANGED: Failure-only log. No secrets.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { // CHANGED:
		error_log( 'PPA: legacy settings loader missing inc/admin/settings.php' ); // CHANGED:
	} // CHANGED:
} // CHANGED:
