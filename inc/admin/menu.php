<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * ========= CHANGE LOG =========
 * 2025-12-27: FIX: Add Settings submenu (admin-only) under PostPress AI and route to settings.php include. // CHANGED:
 * 2025-12-27: FIX: Gate Testbed submenu behind PPA_ENABLE_TESTBED === true (hidden by default).            // CHANGED:
 * 2025-12-27: FIX: Normalize Testbed slug to "postpress-ai-testbed" to match screen detection/enqueue.     // CHANGED:
 * 2025-12-27: KEEP: Single menu registrar lives here; settings.php no longer registers its own submenu.     // CHANGED:
 *
 * 2025-11-11: Use PPA_PLUGIN_DIR for template resolution (more reliable than nested plugin_dir_path math).   // CHANGED:
 *             Keep capability 'edit_posts' and consistent permission messages.                               // CHANGED:
 *             Clarify/annotate legacy Tools→Testbed removal.                                                 // CHANGED:
 * 2025-11-10: Fallback Testbed markup now uses IDs expected by ppa-testbed.js                // (prev)
 *             (ppa-testbed-input|preview|store|output|status). Prefer ppa-testbed.php        // (prev)
 *             over testbed.php when including templates. No inline assets added.             // (prev)
 * 2025-11-09: Add self-contained markup fallback for Testbed when no template file is found;
 *             align H1 to "Testbed"; keep no-inline assets; centralized enqueue owns CSS/JS.
 * 2025-11-08: Add submenus under the top-level:
 *             - Rename default submenu to “PostPress Composer” (same slug as parent).
 *             - Add “Testbed” submenu (slug: ppa-testbed) under PostPress AI.
 *             - Remove legacy Tools→Testbed to avoid duplicates.
 * 2025-11-04: New file. Restores the top-level "PostPress AI" admin menu and composer renderer.
 *             - Registers menu with capability 'edit_posts' (Admin/Editor/Author).
 *             - Defines ppa_render_composer() (no inline JS/CSS; includes composer.php).
 *             - Defensive guards + breadcrumbs via error_log('PPA: ...').
 *
 * Notes:
 * - Keep this file presentation-free. No echo except inside the explicit render callbacks.
 * - Admin assets are handled in inc/admin/enqueue.php (scoped by screen checks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the top-level "PostPress AI" menu and route to the Composer renderer.
 * Also adds:
 *  - Submenu “PostPress Composer” (renames the default submenu label).
 *  - Submenu “Settings” (admin-only).
 *  - Submenu “Testbed” (hidden unless PPA_ENABLE_TESTBED === true).
 */
if ( ! function_exists( 'ppa_register_admin_menu' ) ) {
	function ppa_register_admin_menu() {
		$capability_composer = 'edit_posts';
		$capability_admin    = 'manage_options'; // Settings + Testbed are admin-only.                // CHANGED:
		$menu_slug           = 'postpress-ai';

		// Top-level menu (Composer)
		add_menu_page(
			__( 'PostPress AI', 'postpress-ai' ),
			__( 'PostPress AI', 'postpress-ai' ),
			$capability_composer,
			$menu_slug,
			'ppa_render_composer',
			'dashicons-welcome-widgets-menus',
			65
		);

		// Rename the auto-generated first submenu to “PostPress Composer”
		global $submenu;
		if ( isset( $submenu[ $menu_slug ][0] ) ) {
			$submenu[ $menu_slug ][0][0] = __( 'PostPress Composer', 'postpress-ai' );
		}

		// Explicitly ensure the Composer submenu exists with the same slug as parent
		add_submenu_page(
			$menu_slug,                                 // parent
			__( 'PostPress Composer', 'postpress-ai' ), // page title
			__( 'PostPress Composer', 'postpress-ai' ), // menu title
			$capability_composer,
			$menu_slug,                                 // same slug as parent
			'ppa_render_composer'
		);

		// Settings submenu (admin-only)                                                        // CHANGED:
		add_submenu_page(                                                                         // CHANGED:
			$menu_slug,                                                                           // CHANGED:
			__( 'PostPress AI Settings', 'postpress-ai' ),                                        // CHANGED:
			__( 'Settings', 'postpress-ai' ),                                                     // CHANGED:
			$capability_admin,                                                                    // CHANGED:
			'postpress-ai-settings',                                                              // CHANGED:
			'ppa_render_settings'                                                                 // CHANGED:
		);                                                                                        // CHANGED:

		// Testbed submenu (admin-only AND gated)                                                 // CHANGED:
		$testbed_enabled = ( defined( 'PPA_ENABLE_TESTBED' ) && true === PPA_ENABLE_TESTBED );    // CHANGED:
		if ( $testbed_enabled ) {                                                                // CHANGED:
			add_submenu_page(                                                                     // CHANGED:
				$menu_slug,                                                                       // CHANGED:
				__( 'PPA Testbed', 'postpress-ai' ),                                               // CHANGED:
				__( 'Testbed', 'postpress-ai' ),                                                   // CHANGED:
				$capability_admin,                                                                // CHANGED:
				'postpress-ai-testbed',                                                           // CHANGED: normalized slug
				'ppa_render_testbed'                                                              // CHANGED:
			);                                                                                    // CHANGED:
		}                                                                                        // CHANGED:

		// Remove any legacy Tools→Testbed to avoid duplicates (harmless if not present).         // CHANGED:
		remove_submenu_page( 'tools.php', 'ppa-testbed' );                                       // CHANGED:
		remove_submenu_page( 'tools.php', 'postpress-ai-testbed' );                              // CHANGED:

		error_log( 'PPA: admin_menu registered (slug=' . $menu_slug . ')' );
	}
	add_action( 'admin_menu', 'ppa_register_admin_menu', 9 );
}

/**
 * Composer renderer (main UI).
 * Includes inc/admin/composer.php if present; otherwise prints a small, safe message.
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {
	function ppa_render_composer() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		// Prefer constant defined by root loader; avoids nested plugin_dir_path drift.            // CHANGED:
		$composer = trailingslashit( PPA_PLUGIN_DIR ) . 'inc/admin/composer.php';                 // CHANGED:

		if ( file_exists( $composer ) ) {
			error_log( 'PPA: including composer.php' );
			require $composer;
			return;
		}

		// Fallback UI (minimal, no inline assets beyond this safe notice).
		error_log( 'PPA: composer.php missing at ' . $composer );
		echo '<div class="wrap"><h1>PostPress Composer</h1><p>'
			. esc_html__( 'Composer UI not found. Ensure inc/admin/composer.php exists.', 'postpress-ai' )
			. '</p></div>';
	}
}

/**
 * Settings renderer (submenu).
 * Includes inc/admin/settings.php; the settings file owns the UI rendering.
 */
if ( ! function_exists( 'ppa_render_settings' ) ) {                                             // CHANGED:
	function ppa_render_settings() {                                                            // CHANGED:
		if ( ! current_user_can( 'manage_options' ) ) {                                          // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) ); // CHANGED:
		}                                                                                        // CHANGED:

		$settings = trailingslashit( PPA_PLUGIN_DIR ) . 'inc/admin/settings.php';                 // CHANGED:

		if ( file_exists( $settings ) ) {                                                        // CHANGED:
			error_log( 'PPA: including settings.php' );                                           // CHANGED:
			require $settings;                                                                    // CHANGED:
			return;                                                                               // CHANGED:
		}                                                                                        // CHANGED:

		error_log( 'PPA: settings.php missing at ' . $settings );                                 // CHANGED:
		echo '<div class="wrap"><h1>PostPress AI Settings</h1><p>'                                 // CHANGED:
			. esc_html__( 'Settings UI not found. Ensure inc/admin/settings.php exists.', 'postpress-ai' ) // CHANGED:
			. '</p></div>';                                                                       // CHANGED:
	}                                                                                            // CHANGED:
}                                                                                                // CHANGED:

/**
 * Testbed renderer (submenu).
 * Looks for one of the known filenames, falls back to minimal stub if absent.
 */
if ( ! function_exists( 'ppa_render_testbed' ) ) {
	function ppa_render_testbed() {
		if ( ! current_user_can( 'manage_options' ) ) {                                          // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		$base = trailingslashit( PPA_PLUGIN_DIR ) . 'inc/admin/';                                 // CHANGED:

		// Prefer the new template name first, then legacy.
		$candidates = array(
			$base . 'ppa-testbed.php',
			$base . 'testbed.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				error_log( 'PPA: including ' . basename( $file ) );
				require $file;
				return;
			}
		}

		// Fallback UI — no inline JS/CSS; centralized enqueue provides styles/scripts.
		error_log( 'PPA: testbed UI not found in inc/admin/ — using fallback markup' );
		?>
		<div class="wrap ppa-testbed-wrap">
			<h1><?php echo esc_html__( 'Testbed', 'postpress-ai' ); ?></h1>
			<p class="ppa-hint">
				<?php echo esc_html__( 'This is the PostPress AI Testbed. Use it to send preview/draft requests to the backend.', 'postpress-ai' ); ?>
			</p>

			<!-- Status area consumed by JS -->
			<div id="ppa-testbed-status" class="ppa-notice" role="status" aria-live="polite"></div>

			<div class="ppa-form-group">
				<label for="ppa-testbed-input"><?php echo esc_html__( 'Payload (JSON or brief text)', 'postpress-ai' ); ?></label>
				<textarea id="ppa-testbed-input" rows="8" placeholder="<?php echo esc_attr__( 'Enter JSON for advanced control or plain text for a quick brief…', 'postpress-ai' ); ?>"></textarea>
			</div>

			<div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Testbed actions', 'postpress-ai' ); ?>">
				<button id="ppa-testbed-preview" class="ppa-btn" type="button"><?php echo esc_html__( 'Preview', 'postpress-ai' ); ?></button>
				<button id="ppa-testbed-store" class="ppa-btn ppa-btn-secondary" type="button"><?php echo esc_html__( 'Save to Draft', 'postpress-ai' ); ?></button>
			</div>

			<h2 class="screen-reader-text"><?php echo esc_html__( 'Response Output', 'postpress-ai' ); ?></h2>
			<pre id="ppa-testbed-output" aria-live="polite" aria-label="<?php echo esc_attr__( 'Preview or store response output', 'postpress-ai' ); ?>"></pre>
		</div>
		<?php
	}
}
