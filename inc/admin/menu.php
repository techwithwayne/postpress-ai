<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * ========= CHANGE LOG =========
 * 2025-11-08: Add submenus under the top-level:                                            # CHANGED:
 *             - Rename default submenu to “PostPress Composer” (same slug as parent).      # CHANGED:
 *             - Add “Testbed” submenu (slug: ppa-testbed) under PostPress AI.              # CHANGED:
 *             - Remove legacy Tools→Testbed to avoid duplicates.                           # CHANGED:
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

		// Rename the auto-generated first submenu to “PostPress Composer”                # CHANGED:
		global $submenu;                                                                 // CHANGED:
		if ( isset( $submenu[ $menu_slug ][0] ) ) {                                      // CHANGED:
			$submenu[ $menu_slug ][0][0] = __( 'PostPress Composer', 'postpress-ai' );   // CHANGED:
		}                                                                                // CHANGED:

		// Explicitly ensure the Composer submenu exists with the same slug as parent    # CHANGED:
		add_submenu_page(                                                                // CHANGED:
			$menu_slug,                                                                  // parent
			__( 'PostPress Composer', 'postpress-ai' ),                                  // page title
			__( 'PostPress Composer', 'postpress-ai' ),                                  // menu title
			$capability,
			$menu_slug,                                                                  // same slug as parent
			'ppa_render_composer'
		);

		// Testbed submenu under PostPress AI                                            # CHANGED:
		add_submenu_page(                                                                // CHANGED:
			$menu_slug,                                                                  // parent = PostPress AI
			__( 'PPA Testbed', 'postpress-ai' ),                                         // page title
			__( 'Testbed', 'postpress-ai' ),                                             // menu title
			$capability,
			'ppa-testbed',                                                                // slug
			'ppa_render_testbed'                                                          // callback
		);

		// Remove any legacy Tools→Testbed to avoid duplicates                           # CHANGED:
		remove_submenu_page( 'tools.php', 'ppa-testbed' );                                // CHANGED:

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
if ( ! function_exists( 'ppa_render_testbed' ) ) {                                          // CHANGED:
	function ppa_render_testbed() {                                                          // CHANGED:
		if ( ! current_user_can( 'edit_posts' ) ) {                                         // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) ); // CHANGED:
		}                                                                                    // CHANGED:

		$base = trailingslashit( plugin_dir_path( dirname( __FILE__, 2 ) ) ) . 'inc/admin/'; // CHANGED:
		$candidates = array(                                                                 // CHANGED:
			$base . 'testbed.php',                                                           // CHANGED:
			$base . 'ppa-testbed.php',                                                       // CHANGED:
		);                                                                                   // CHANGED:
		foreach ( $candidates as $file ) {                                                   // CHANGED:
			if ( file_exists( $file ) ) {                                                    // CHANGED:
				error_log( 'PPA: including ' . basename( $file ) );                          // CHANGED:
				require $file;                                                               // CHANGED:
				return;                                                                      // CHANGED:
			}                                                                                // CHANGED:
		}                                                                                    // CHANGED:

		error_log( 'PPA: testbed UI not found in inc/admin/' );                               // CHANGED:
		echo '<div class="wrap"><h1>PPA Testbed</h1><p>'                                      // CHANGED:
		   . esc_html__( 'Testbed UI not found. Provide inc/admin/testbed.php.', 'postpress-ai' ) // CHANGED:
		   . '</p></div>';                                                                    // CHANGED:
	}                                                                                        // CHANGED:
}
