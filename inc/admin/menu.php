<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * CHANGE LOG
 * 2025-11-04 • New file. Restores the top-level "PostPress AI" admin menu and composer renderer.     # CHANGED:
 *             - Registers menu with capability 'edit_posts' (Admin/Editor/Author).                    # CHANGED:
 *             - Defines ppa_render_composer() (no inline JS/CSS; includes composer.php).             # CHANGED:
 *             - Defensive guards + breadcrumbs via error_log('PPA: ...').                            # CHANGED:
 *
 * Notes:
 * - Keep this file presentation-free. No echo except inside the explicit render callback.
 * - Admin assets are handled in inc/admin/enqueue.php (scoped by screen checks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the top-level "PostPress AI" menu and route to the Composer renderer.
 * We use a named function (not an anonymous closure) so it can be discovered/reused if needed.
 */
if ( ! function_exists( 'ppa_register_admin_menu' ) ) {                                           // CHANGED:
	function ppa_register_admin_menu() {                                                           // CHANGED:
		$capability = 'edit_posts'; // Admin/Editor/Author                                        // CHANGED:
		$menu_slug  = 'postpress-ai';                                                             // CHANGED:

		add_menu_page(
			__( 'PostPress AI', 'postpress-ai' ),
			__( 'PostPress AI', 'postpress-ai' ),
			$capability,
			$menu_slug,
			'ppa_render_composer',
			'dashicons-welcome-widgets-menus',
			65
		);

		error_log( 'PPA: admin_menu registered (slug=' . $menu_slug . ', cap=' . $capability . ')' ); // CHANGED:
	}                                                                                              // CHANGED:
	add_action( 'admin_menu', 'ppa_register_admin_menu', 9 );                                      // CHANGED:
}

/**
 * Render callback for the Composer screen.
 * Includes inc/admin/composer.php if present; otherwise prints a small, safe message.
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {                                                // CHANGED:
	function ppa_render_composer() {                                                               // CHANGED:
		if ( ! current_user_can( 'edit_posts' ) ) {                                               // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) ); // CHANGED:
		}                                                                                         // CHANGED:

		$composer = trailingslashit( plugin_dir_path( dirname( __FILE__, 2 ) ) ) . 'inc/admin/composer.php';
		// Above resolves to .../postpress-ai/inc/admin/composer.php                                 // CHANGED:

		if ( file_exists( $composer ) ) {                                                         // CHANGED:
			error_log( 'PPA: including composer.php' );                                           // CHANGED:
			require $composer;                                                                    // CHANGED:
			return;                                                                               // CHANGED:
		}                                                                                         // CHANGED:

		// Fallback UI (minimal, no inline assets beyond this safe notice).                        // CHANGED:
		error_log( 'PPA: composer.php missing at ' . $composer );                                 // CHANGED:
		echo '<div class="wrap"><h1>PostPress AI</h1><p>'
		   . esc_html__( 'Composer UI not found. Ensure inc/admin/composer.php exists.', 'postpress-ai' )
		   . '</p></div>';                                                                        // CHANGED:
	}                                                                                              // CHANGED:
}
