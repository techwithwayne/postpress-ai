<?php
/**
 * PostPress AI — Admin Menu Bootstrap
 *
 * ========= CHANGE LOG =========
 * 2026-01-21: FIX: Settings page now renders by calling PPA_Admin_Settings::render_page() after include.        // CHANGED:
 * 2026-01-21: ADD: Account submenu added + renderer (include account.php, call ppa_render_account(), fallback). // CHANGED:
 * 2026-01-21: HARDEN: Composer renderer now supports both "file echoes UI" and "file defines a render function". // CHANGED:
 * 2026-01-21: CLEAN: Remove success/noise logs; log only on failures.                                          // CHANGED:
 *
 * 2025-12-28: ADD: Custom SVG dashicon for PostPress AI menu; position set to 3 (high priority).  // CHANGED:
 * 2025-12-28: FIX: Remove duplicate "PostPress Composer" submenu entry.
 *             WP already auto-creates the first submenu for the parent slug via add_menu_page().              // CHANGED:
 *             We keep the rename of that auto submenu label, but do not add a second submenu item.           // CHANGED:
 *
 * 2025-12-28: FIX: Register the 'ppa_settings' settings group early on admin_init so options.php accepts option_page=ppa_settings
 *             even when settings.php is only included by the menu callback (prevents "allowed options list" error).            // CHANGED:
 *             (No Django/endpoints/CORS/auth changes. No layout/CSS changes. menu.php only.)                                  // CHANGED:
 *
 * Notes:
 * - Keep this file presentation-free. No echo except inside the explicit render callbacks.
 * - Admin assets are handled in inc/admin/enqueue.php (scoped by screen checks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize helper for simple PPA settings (string or array).
 * This is intentionally conservative: only supports scalar strings + nested arrays of strings.      // CHANGED:
 */
if ( ! function_exists( 'ppa_sanitize_setting_value' ) ) {                                         // CHANGED:
	function ppa_sanitize_setting_value( $value ) {                                                 // CHANGED:
		$value = wp_unslash( $value );                                                               // CHANGED:

		if ( is_array( $value ) ) {                                                                  // CHANGED:
			$out = array();                                                                          // CHANGED:
			foreach ( $value as $k => $v ) {                                                         // CHANGED:
				// Preserve keys, sanitize values recursively.                                        // CHANGED:
				$out[ $k ] = ppa_sanitize_setting_value( $v );                                       // CHANGED:
			}                                                                                        // CHANGED:
			return $out;                                                                             // CHANGED:
		}                                                                                            // CHANGED:

		return sanitize_text_field( (string) $value );                                               // CHANGED:
	}                                                                                                // CHANGED:
}                                                                                                    // CHANGED:

/**
 * Settings API bootstrap (critical for options.php saves).
 *
 * WHY:
 * - The Settings page form posts to options.php with option_page=ppa_settings.
 * - If register_setting('ppa_settings', ...) has NOT run by admin_init, WP rejects the save with:
 *   "Error: The ppa_settings options page is not in the allowed options list."
 * - settings.php is currently included by the Settings menu callback (late), so it may miss admin_init on save.  // CHANGED:
 *
 * FIX:
 * - Register the relevant setting(s) here on admin_init (early), without touching settings.php.
 * - This is WP-only, does not impact Django/endpoints/CORS/auth/etc.                                                  // CHANGED:
 */
if ( ! function_exists( 'ppa_register_settings_api_bootstrap' ) ) {                                 // CHANGED:
	function ppa_register_settings_api_bootstrap() {                                                 // CHANGED:
		if ( ! is_admin() ) {                                                                       // CHANGED:
			return;                                                                                 // CHANGED:
		}                                                                                            // CHANGED:

		// Only admins can hit options.php successfully anyway, but keep this tight.                 // CHANGED:
		if ( ! current_user_can( 'manage_options' ) ) {                                             // CHANGED:
			return;                                                                                 // CHANGED:
		}                                                                                            // CHANGED:

		// Avoid overriding an existing registration if settings.php (or another file) registers first.  // CHANGED:
		global $wp_registered_settings;                                                             // CHANGED:
		if ( ! is_array( $wp_registered_settings ) ) {                                              // CHANGED:
			$wp_registered_settings = array();                                                      // CHANGED:
		}                                                                                            // CHANGED:

		// Most common pattern: a single option for the license key.                                 // CHANGED:
		if ( ! isset( $wp_registered_settings['ppa_license_key'] ) ) {                              // CHANGED:
			register_setting(                                                                       // CHANGED:
				'ppa_settings',                                                                     // CHANGED: option_group (must match settings_fields('ppa_settings'))
				'ppa_license_key',                                                                  // CHANGED: option_name
				array(                                                                              // CHANGED:
					'type'              => 'string',                                                // CHANGED:
					'sanitize_callback' => 'ppa_sanitize_setting_value',                            // CHANGED:
					'default'           => '',                                                      // CHANGED:
				)                                                                                   // CHANGED:
			);                                                                                       // CHANGED:
		}                                                                                            // CHANGED:

		// Alternate pattern: store settings as an array under one option named same-ish as the group.   // CHANGED:
		// This is harmless if unused, and prevents edge cases where the form uses ppa_settings[...].    // CHANGED:
		if ( ! isset( $wp_registered_settings['ppa_settings'] ) ) {                                  // CHANGED:
			register_setting(                                                                       // CHANGED:
				'ppa_settings',                                                                     // CHANGED:
				'ppa_settings',                                                                     // CHANGED:
				array(                                                                              // CHANGED:
					'type'              => 'array',                                                 // CHANGED:
					'sanitize_callback' => 'ppa_sanitize_setting_value',                            // CHANGED:
					'default'           => array(),                                                 // CHANGED:
				)                                                                                   // CHANGED:
			);                                                                                       // CHANGED:
		}                                                                                            // CHANGED:
	}                                                                                                // CHANGED:
	add_action( 'admin_init', 'ppa_register_settings_api_bootstrap', 0 );                            // CHANGED: priority 0 = early
}                                                                                                    // CHANGED:

/**
 * Register the top-level "PostPress AI" menu and route to the Composer renderer.
 * Also adds:
 *  - Submenu "PostPress Composer" (renames the default submenu label).
 *  - Submenu "Settings" (admin-only).
 *  - Submenu "Account" (admin-only).                                                                // CHANGED:
 *  - Submenu "Testbed" (hidden unless PPA_ENABLE_TESTBED === true).
 */
if ( ! function_exists( 'ppa_register_admin_menu' ) ) {
	function ppa_register_admin_menu() {
		$capability_composer = 'edit_posts';
		$capability_admin    = 'manage_options'; // Settings + Account + Testbed are admin-only.     // CHANGED:
		$menu_slug           = 'postpress-ai';

		// Custom SVG icon for PostPress AI                                                         // CHANGED:
		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
  <path d="M3 2h8.5c3.59 0 6.5 2.91 6.5 6.5S15.09 15 11.5 15H8v3H3V2z" fill="#ff8c00" opacity="0.2"/>
  <g stroke="#ff8c00" stroke-width="1.2" stroke-linecap="round" fill="none">
    <circle cx="12" cy="7" r="1.5"/>
    <circle cx="7" cy="11" r="1.2"/>
    <path d="M12 8.5 L12 10 L10 12 L7 12"/>
    <path d="M7 11 L7 9.5"/>
  </g>
</svg>'; // CHANGED:
		$menu_icon = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );                      // CHANGED:

		// Top-level menu (Composer)
		add_menu_page(
			__( 'PostPress AI', 'postpress-ai' ),
			__( 'PostPress AI', 'postpress-ai' ),
			$capability_composer,
			$menu_slug,
			'ppa_render_composer',
			$menu_icon,                                                                              // CHANGED:
			3                                                                                        // CHANGED: position 3 = high priority (right after Dashboard)
		);

		// Rename the auto-generated first submenu to "PostPress Composer"
		// NOTE: WP auto-creates this first submenu for the parent slug; do NOT add a duplicate.      // CHANGED:
		global $submenu;
		if ( isset( $submenu[ $menu_slug ][0] ) ) {
			$submenu[ $menu_slug ][0][0] = __( 'PostPress Composer', 'postpress-ai' );
		}

		// Settings submenu (admin-only)                                                             // CHANGED:
		add_submenu_page(
			$menu_slug,
			__( 'PostPress AI Settings', 'postpress-ai' ),
			__( 'Settings', 'postpress-ai' ),
			$capability_admin,
			'postpress-ai-settings',
			'ppa_render_settings'
		);

		// Account submenu (admin-only)                                                               // CHANGED:
		add_submenu_page(
			$menu_slug,
			__( 'PostPress AI Account', 'postpress-ai' ),
			__( 'Account', 'postpress-ai' ),
			$capability_admin,
			'postpress-ai-account',
			'ppa_render_account'
		);

		// Testbed submenu (admin-only AND gated)                                                     // CHANGED:
		$testbed_enabled = ( defined( 'PPA_ENABLE_TESTBED' ) && true === PPA_ENABLE_TESTBED );       // CHANGED:
		if ( $testbed_enabled ) {
			add_submenu_page(
				$menu_slug,
				__( 'PPA Testbed', 'postpress-ai' ),
				__( 'Testbed', 'postpress-ai' ),
				$capability_admin,
				'postpress-ai-testbed',
				'ppa_render_testbed'
			);
		}

		// Remove any legacy Tools→Testbed to avoid duplicates (harmless if not present).             // CHANGED:
		remove_submenu_page( 'tools.php', 'ppa-testbed' );
		remove_submenu_page( 'tools.php', 'postpress-ai-testbed' );
	}
	add_action( 'admin_menu', 'ppa_register_admin_menu', 9 );
}

/**
 * Composer renderer (main UI).
 * Includes inc/admin/composer.php if present.
 *
 * HARDEN:
 * - Some composer.php versions echo the UI directly.
 * - Other versions only define a function/class and expect a caller.
 * This function now supports both patterns safely.                                                   // CHANGED:
 */
if ( ! function_exists( 'ppa_render_composer' ) ) {
	function ppa_render_composer() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		$root = defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ); // CHANGED:
		$composer = $root . 'inc/admin/composer.php';                                                                       // CHANGED:

		if ( file_exists( $composer ) ) {
			ob_start();                                                                                                      // CHANGED:
			require $composer;                                                                                               // CHANGED:
			$out = ob_get_clean();                                                                                           // CHANGED:

			// If the file echoed UI, print it and we’re done.
			if ( is_string( $out ) && '' !== trim( $out ) ) {                                                                // CHANGED:
				echo $out;                                                                                                   // CHANGED:
				return;                                                                                                      // CHANGED:
			}                                                                                                                // CHANGED:

			// If the file defines a renderer, call it.
			$candidates = array(                                                                                              // CHANGED:
				'ppa_render_composer_ui',                                                                                    // CHANGED:
				'ppa_composer_render',                                                                                       // CHANGED:
				'postpress_ai_render_composer',                                                                              // CHANGED:
			);                                                                                                               // CHANGED:
			foreach ( $candidates as $fn ) {                                                                                  // CHANGED:
				if ( function_exists( $fn ) ) {                                                                              // CHANGED:
					call_user_func( $fn );                                                                                   // CHANGED:
					return;                                                                                                  // CHANGED:
				}                                                                                                            // CHANGED:
			}                                                                                                                // CHANGED:

			// If nothing rendered, fall through to a safe message.
			error_log( 'PPA: composer.php included but produced no output and no known render function exists.' );            // CHANGED:
		} else {
			error_log( 'PPA: composer.php missing at ' . $composer );                                                         // CHANGED:
		}

		echo '<div class="wrap"><h1>PostPress Composer</h1><p>'
			. esc_html__( 'Composer UI did not render. Ensure inc/admin/composer.php either echoes UI or defines ppa_render_composer_ui().', 'postpress-ai' )
			. '</p></div>';
	}
}

/**
 * Settings renderer (submenu).
 *
 * IMPORTANT:
 * - settings.php does NOT render on include (by design, to avoid "headers already sent").
 * - It defines PPA_Admin_Settings and calls ::init().
 * - We MUST call ::render_page() here or the Settings screen will be blank.                              // CHANGED:
 */
if ( ! function_exists( 'ppa_render_settings' ) ) {
	function ppa_render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		$root = defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ); // CHANGED:
		$settings = $root . 'inc/admin/settings.php';                                                                         // CHANGED:

		if ( file_exists( $settings ) ) {
			ob_start();                                                                                                        // CHANGED:
			require $settings;                                                                                                 // CHANGED:
			$out = ob_get_clean();                                                                                             // CHANGED:

			// If settings.php echoed UI (it shouldn't), honor it and exit.
			if ( is_string( $out ) && '' !== trim( $out ) ) {                                                                  // CHANGED:
				echo $out;                                                                                                     // CHANGED:
				return;                                                                                                        // CHANGED:
			}                                                                                                                  // CHANGED:

			// Correct behavior: call the renderer.
			if ( class_exists( 'PPA_Admin_Settings' ) && method_exists( 'PPA_Admin_Settings', 'render_page' ) ) {              // CHANGED:
				PPA_Admin_Settings::render_page();                                                                             // CHANGED:
				return;                                                                                                        // CHANGED:
			}                                                                                                                  // CHANGED:

			error_log( 'PPA: settings.php loaded but PPA_Admin_Settings::render_page() not available.' );                      // CHANGED:
		} else {
			error_log( 'PPA: settings.php missing at ' . $settings );                                                          // CHANGED:
		}

		echo '<div class="wrap"><h1>PostPress AI Settings</h1><p>'
			. esc_html__( 'Settings UI not available. Ensure inc/admin/settings.php defines PPA_Admin_Settings::render_page().', 'postpress-ai' )
			. '</p></div>';
	}
}

/**
 * Account renderer (submenu).
 * Supports:
 * - account.php echoing UI
 * - account.php defining ppa_render_account()
 * - fallback placeholder
 */
if ( ! function_exists( 'ppa_render_account' ) ) {                                                     // CHANGED:
	function ppa_render_account() {                                                                      // CHANGED:
		if ( ! current_user_can( 'manage_options' ) ) {                                                   // CHANGED:
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );    // CHANGED:
		}                                                                                                 // CHANGED:

		$root = defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ); // CHANGED:
		$account = $root . 'inc/admin/account.php';                                                       // CHANGED:

		if ( file_exists( $account ) ) {                                                                  // CHANGED:
			ob_start();                                                                                   // CHANGED:
			require $account;                                                                             // CHANGED:
			$out = ob_get_clean();                                                                        // CHANGED:

			if ( is_string( $out ) && '' !== trim( $out ) ) {                                             // CHANGED:
				echo $out;                                                                                // CHANGED:
				return;                                                                                   // CHANGED:
			}                                                                                             // CHANGED:

			if ( function_exists( 'ppa_render_account_page' ) ) {                                         // CHANGED:
				ppa_render_account_page();                                                                // CHANGED:
				return;                                                                                   // CHANGED:
			}                                                                                             // CHANGED:

			if ( function_exists( 'ppa_render_account' ) && __FUNCTION__ !== 'ppa_render_account' ) {     // CHANGED (safety, should never happen)
				ppa_render_account();                                                                     // CHANGED:
				return;                                                                                   // CHANGED:
			}                                                                                             // CHANGED:

			// If file exists but didn’t render, log once (failure only).
			error_log( 'PPA: account.php included but produced no output and no renderer found.' );        // CHANGED:
		} else {
			error_log( 'PPA: account.php missing at ' . $account );                                       // CHANGED:
		}

		echo '<div class="wrap"><h1>PostPress AI Account</h1><p>'
			. esc_html__( 'Account page is connected. Next step: wire usage, sites, and upgrade links.', 'postpress-ai' )
			. '</p></div>';
	}                                                                                                     // CHANGED:
}                                                                                                         // CHANGED:

/**
 * Testbed renderer (submenu).
 * Looks for one of the known filenames, falls back to minimal stub if absent.
 */
if ( ! function_exists( 'ppa_render_testbed' ) ) {
	function ppa_render_testbed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'postpress-ai' ) );
		}

		$base = ( defined( 'PPA_PLUGIN_DIR' ) ? trailingslashit( PPA_PLUGIN_DIR ) : trailingslashit( dirname( __FILE__, 3 ) ) ) . 'inc/admin/'; // CHANGED:

		$candidates = array(
			$base . 'ppa-testbed.php',
			$base . 'testbed.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require $file;
				return;
			}
		}

		// Fallback UI — no inline JS/CSS; centralized enqueue provides styles/scripts.
		error_log( 'PPA: testbed UI not found in inc/admin/ — using fallback markup' ); // failure only
		?>
		<div class="wrap ppa-testbed-wrap">
			<h1><?php echo esc_html__( 'Testbed', 'postpress-ai' ); ?></h1>
			<p class="ppa-hint">
				<?php echo esc_html__( 'This is the PostPress AI Testbed. Use it to send preview/draft requests to the backend.', 'postpress-ai' ); ?>
			</p>

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
