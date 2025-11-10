<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * ========= CHANGE LOG =========
 * 2025-11-10: Fallback Testbed markup now uses IDs expected by ppa-testbed.js                // CHANGED:
 *             (ppa-testbed-input|preview|store|output|status). Prefer ppa-testbed.php        // CHANGED:
 *             over testbed.php when including templates. No inline assets added.            // CHANGED:
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
 *  - Submenu “Testbed”.
 */
if ( ! function_exists( 'ppa_register_admin_menu' ) ) {
	function ppa_register_admin_menu() {
		$capability = 'edit_posts';
		$menu_slug  = 'postpress-ai';

		// Top-level menu
		add_menu_page(
			__( 'PostPress AI', 'postpress-ai' ),
			__( 'PostPress AI', 'postpress-ai' ),
			$capability,
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
			$menu_slug,                                // parent
			__( 'PostPress Composer', 'postpress-ai' ),// page title
			__( 'PostPress Composer', 'postpress-ai' ),// menu title
			$capability,
			$menu_slug,                                // same slug as parent
			'ppa_render_composer'
		);

		// Testbed submenu under PostPress AI
		add_submenu_page(
			$menu_slug,                                // parent = PostPress AI
			__( 'PPA Testbed', 'postpress-ai' ),       // page title
			__( 'Testbed', 'postpress-ai' ),           // menu title
			$capability,
			'ppa-testbed',                              // slug
			'ppa_render_testbed'                       // callback
		);

		// Remove any legacy Tools→Testbed to avoid duplicates
		remove_submenu_page( 'tools.php', 'ppa-testbed' );

		error_log( 'PPA: admin_menu registered (slug=' . $menu_slug . ', cap=' . $capability . ')' );
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

		$composer = trailingslashit( plugin_dir_path( dirname( __FILE__, 2 ) ) ) . 'inc/admin/composer.php';
		// Above resolves to .../postpress-ai/inc/admin/composer.php

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
 * Testbed renderer (submenu).
 * Looks for one of the known filenames, falls back to minimal stub if absent.
 */
if ( ! function_exists( 'ppa_render_testbed' ) ) {
	function ppa_render_testbed() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		$base = trailingslashit( plugin_dir_path( dirname( __FILE__, 2 ) ) ) . 'inc/admin/';

		// Prefer the new template name first, then legacy.                                   // CHANGED:
		$candidates = array(                                                                  // CHANGED:
			$base . 'ppa-testbed.php',                                                        // CHANGED:
			$base . 'testbed.php',                                                            // CHANGED:
		);                                                                                    // CHANGED:

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
		<div class="wrap ppa-testbed-wrap">                                                   <!-- CHANGED: -->
			<h1><?php echo esc_html__( 'Testbed', 'postpress-ai' ); ?></h1>
			<p class="ppa-hint">
				<?php echo esc_html__( 'This is the PostPress AI Testbed. Use it to send preview/draft requests to the backend.', 'postpress-ai' ); ?>
			</p>

			<!-- Status area consumed by JS -->
			<div id="ppa-testbed-status" class="ppa-notice" role="status" aria-live="polite"></div> <!-- CHANGED: -->

			<div class="ppa-form-group">
				<label for="ppa-testbed-input"><?php echo esc_html__( 'Payload (JSON or brief text)', 'postpress-ai' ); ?></label> <!-- CHANGED: -->
				<textarea id="ppa-testbed-input" rows="8" placeholder="<?php echo esc_attr__( 'Enter JSON for advanced control or plain text for a quick brief…', 'postpress-ai' ); ?>"></textarea> <!-- CHANGED: -->
			</div>

			<div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Testbed actions', 'postpress-ai' ); ?>">
				<button id="ppa-testbed-preview" class="ppa-btn" type="button"><?php echo esc_html__( 'Preview', 'postpress-ai' ); ?></button> <!-- CHANGED: -->
				<button id="ppa-testbed-store" class="ppa-btn ppa-btn-secondary" type="button"><?php echo esc_html__( 'Save to Draft', 'postpress-ai' ); ?></button> <!-- CHANGED: -->
			</div>

			<h2 class="screen-reader-text"><?php echo esc_html__( 'Response Output', 'postpress-ai' ); ?></h2>
			<pre id="ppa-testbed-output" aria-live="polite" aria-label="<?php echo esc_attr__( 'Preview or store response output', 'postpress-ai' ); ?>"></pre> <!-- CHANGED: -->
		</div>
		<?php
		// End fallback markup
	}
}
