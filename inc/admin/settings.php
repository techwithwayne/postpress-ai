<?php
/**
 * PostPress AI — Admin Settings
 * Path: inc/admin/settings.php
 *
 * Provides:
 * - Settings submenu under PostPress AI.
 * - License key storage (admin-only) + license actions that call Django /license/* (server-side only).
 * - Test Connection action that calls Django /version/ and /health/ (server-side only).
 * - Display-only caching of last licensing response for admin visibility (no enforcement).
 *
 * Notes:
 * - Server URL is infrastructure; kept internally (constant/option) but NOT shown to end users.   // CHANGED:
 * - Connection Key is legacy; if present we use it, otherwise we use License Key as the auth key. // CHANGED:
 *
 * ========= CHANGE LOG =========
 * 2025-11-19: Initial settings screen & connectivity test (Django URL + shared key).                              // CHANGED:
 * 2025-12-25: Add license UI + admin-post handlers to call Django /license/* endpoints (server-side).            // CHANGED:
 * 2025-12-25: HARDEN: Settings screen + actions admin-only (manage_options).                                     // CHANGED:
 * 2025-12-25: UX: Simplify copy for creators; remove technical pipeline language.                                // CHANGED:
 * 2025-12-25: BRAND: Add stable wrapper classes (ppa-admin ppa-settings) for CSS parity with Composer.           // CHANGED:
 * 2025-12-25: CLEAN: Remove inline layout styles; use class hooks for styling later.                             // CHANGED:
 * 2025-12-25: UX: Render fields manually (no duplicate section headings); “grandma-friendly” labels.             // CHANGED:
 * 2025-12-25: FIX: Render notices inside Setup card so they never float outside the frame/grid.                  // CHANGED:
 * 2025-12-25: UX: Hide Server URL + Connection Key from UI; License Key is the only user-facing input.           // CHANGED:
 * 2025-12-25: AUTH: If Connection Key is empty, use License Key for X-PPA-Key (legacy-safe).                     // CHANGED:
 * 2025-12-25: UX GUARDRAILS: Disable Activate until key saved; show Active/Not active badge (pure PHP).           // CHANGED:
 * 2025-12-26: UX HARDEN: Persist "active on this site" locally after successful Activate; clear on Deactivate.    // CHANGED:
 *            (UI convenience only; Django remains authoritative.)                                                  // CHANGED:
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Admin_Settings' ) ) {

	/**
	 * Admin Settings for PostPress AI.
	 *
	 * Note: This file is self-initializing via PPA_Admin_Settings::init().
	 */
	class PPA_Admin_Settings {

		// ===== License option + transient (display-only) =====
		const OPT_LICENSE_KEY      = 'ppa_license_key';                                        // CHANGED:
		const OPT_ACTIVE_SITE      = 'ppa_license_active_site';                                // CHANGED:
		const TRANSIENT_LAST_LIC   = 'ppa_license_last_result';
		const LAST_LIC_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

		/**
		 * Centralized capability:
		 * Settings + licensing are admin-only.
		 */
		private static function cap() {
			return 'manage_options';
		}

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			// Admin menu entry under top-level "PostPress AI".
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );

			// Settings API registration.
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

			// Test Connection handler (admin-post).
			add_action( 'admin_post_ppa_test_connectivity', array( __CLASS__, 'handle_test_connectivity' ) );

			// Licensing handlers (admin-post, server-side only).
			add_action( 'admin_post_ppa_license_verify', array( __CLASS__, 'handle_license_verify' ) );
			add_action( 'admin_post_ppa_license_activate', array( __CLASS__, 'handle_license_activate' ) );
			add_action( 'admin_post_ppa_license_deactivate', array( __CLASS__, 'handle_license_deactivate' ) );
		}

		/**
		 * Add "Settings" submenu under the existing PostPress AI menu.
		 */
		public static function register_menu() {
			$parent_slug = 'postpress-ai'; // This matches the Composer screen slug.

			add_submenu_page(
				$parent_slug,
				__( 'PostPress AI Settings', 'postpress-ai' ),
				__( 'Settings', 'postpress-ai' ),
				self::cap(),
				'postpress-ai-settings',
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Register options and fields for the settings screen.
		 *
		 * Note:
		 * - We still register legacy options for backwards compatibility (sanitization + storage),
		 *   but we DO NOT render them in the UI anymore.                                          // CHANGED:
		 */
		public static function register_settings() {
			// (Legacy) Django URL is still supported (constant/option), but not rendered.          // CHANGED:
			register_setting(
				'ppa_settings',
				'ppa_django_url',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_django_url' ),
					'default'           => 'https://apps.techwithwayne.com/postpress-ai/',
				)
			);

			// (Legacy) Shared key is still supported, but not rendered.                            // CHANGED:
			register_setting(
				'ppa_settings',
				'ppa_shared_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_shared_key' ),
					'default'           => '',
				)
			);

			// License key storage (raw; masked display only).
			register_setting(
				'ppa_settings',
				self::OPT_LICENSE_KEY,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_license_key' ),
					'default'           => '',
				)
			);

			// (Legacy) Sections/fields kept registered for compatibility, but not rendered.
			add_settings_section(
				'ppa_settings_connection',
				__( 'Connection', 'postpress-ai' ),
				array( __CLASS__, 'section_connection_intro' ),
				'postpress-ai-settings'
			);

			add_settings_field(
				'ppa_django_url',
				__( 'Server URL', 'postpress-ai' ),
				array( __CLASS__, 'field_django_url' ),
				'postpress-ai-settings',
				'ppa_settings_connection'
			);

			add_settings_field(
				'ppa_shared_key',
				__( 'Connection Key', 'postpress-ai' ),
				array( __CLASS__, 'field_shared_key' ),
				'postpress-ai-settings',
				'ppa_settings_connection'
			);

			add_settings_section(
				'ppa_settings_license',
				__( 'License', 'postpress-ai' ),
				array( __CLASS__, 'section_license_intro' ),
				'postpress-ai-settings'
			);

			add_settings_field(
				self::OPT_LICENSE_KEY,
				__( 'License Key', 'postpress-ai' ),
				array( __CLASS__, 'field_license_key' ),
				'postpress-ai-settings',
				'ppa_settings_license'
			);
		}

		public static function sanitize_django_url( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			$value = esc_url_raw( $value );
			$value = untrailingslashit( $value );
			return $value;
		}

		public static function sanitize_shared_key( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$value = trim( $value );
			return $value;
		}

		public static function sanitize_license_key( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			$value = preg_replace( '/\s+/', '', $value );
			if ( strlen( $value ) > 200 ) {
				$value = substr( $value, 0, 200 );
			}
			return $value;
		}

		/**
		 * Render a Composer-parity notice (scoped to Settings CSS).
		 * IMPORTANT: We render notices INSIDE the Setup card so they never float outside the frame/grid.
		 *
		 * @param string $status ok|error
		 * @param string $message
		 */
		private static function render_notice( $status, $message ) {
			$status  = ( 'ok' === $status ) ? 'ok' : 'error';
			$message = is_string( $message ) ? trim( $message ) : '';
			if ( '' === $message ) {
				return;
			}

			$cls = ( 'ok' === $status ) ? 'ppa-notice ppa-notice--success' : 'ppa-notice ppa-notice--error';
			?>
			<div class="<?php echo esc_attr( $cls ); ?>">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}

		/**
		 * Local “active on this site” helper.
		 *
		 * IMPORTANT:
		 * - This is NOT enforcement. Django remains authoritative.
		 * - This is only used to reduce confusion in the UI (disable Activate + show badge)
		 *   after a successful Activate action.
		 */
		private static function is_active_on_this_site_option() {                               // CHANGED:
			$stored = get_option( self::OPT_ACTIVE_SITE, '' );                                   // CHANGED:
			$stored = is_string( $stored ) ? untrailingslashit( $stored ) : '';                  // CHANGED:
			$home   = untrailingslashit( home_url( '/' ) );                                      // CHANGED:
			return ( '' !== $stored && $stored === $home );                                      // CHANGED:
		}

		/**
		 * Determine “active on this site” from the cached last result (display-only).
		 *
		 * Why:
		 * - NO background calls to Django during page render.
		 * - NO JS.
		 * - We only reflect the most recent license action result we already cached.
		 *
		 * We support multiple possible shapes from Django without assuming exact keys.
		 *
		 * @param mixed $last
		 * @return string one of: active|inactive|unknown
		 */
		private static function derive_activation_state( $last ) {                               // CHANGED:
			// First priority: our local UI marker (set only after Django said OK).                 // CHANGED:
			if ( self::is_active_on_this_site_option() ) {                                       // CHANGED:
				return 'active';                                                                 // CHANGED:
			}                                                                                      // CHANGED:

			if ( ! is_array( $last ) ) {                                                         // CHANGED:
				return 'unknown';                                                                // CHANGED:
			}                                                                                      // CHANGED:

			// Common: { ok: true, data: { active: true } } or similar.
			$data = isset( $last['data'] ) && is_array( $last['data'] ) ? $last['data'] : array(); // CHANGED:

			$candidates = array(                                                                  // CHANGED:
				// boolean flags                                                                // CHANGED:
				'active',                                                                         // CHANGED:
				'is_active',                                                                      // CHANGED:
				'site_active',                                                                    // CHANGED:
				'activated',                                                                      // CHANGED:
				// status strings                                                               // CHANGED:
				'status',                                                                         // CHANGED:
				'activation_status',                                                              // CHANGED:
			);

			foreach ( $candidates as $k ) {                                                       // CHANGED:
				if ( array_key_exists( $k, $data ) ) {                                            // CHANGED:
					$v = $data[ $k ];                                                             // CHANGED:
					if ( is_bool( $v ) ) {                                                        // CHANGED:
						return $v ? 'active' : 'inactive';                                        // CHANGED:
					}                                                                              // CHANGED:
					if ( is_string( $v ) ) {                                                      // CHANGED:
						$vv = strtolower( trim( $v ) );                                           // CHANGED:
						if ( in_array( $vv, array( 'active', 'activated', 'on', 'enabled' ), true ) ) { // CHANGED:
							return 'active';                                                     // CHANGED:
						}                                                                          // CHANGED:
						if ( in_array( $vv, array( 'inactive', 'deactivated', 'off', 'disabled' ), true ) ) { // CHANGED:
							return 'inactive';                                                   // CHANGED:
						}                                                                          // CHANGED:
					}                                                                              // CHANGED:
				}                                                                                  // CHANGED:
			}

			// Another common: list of active sites and/or current site marked.
			if ( isset( $data['active_sites'] ) && is_array( $data['active_sites'] ) ) {          // CHANGED:
				$home = untrailingslashit( home_url( '/' ) );                                     // CHANGED:
				foreach ( $data['active_sites'] as $site ) {                                      // CHANGED:
					if ( is_string( $site ) && untrailingslashit( $site ) === $home ) {           // CHANGED:
						return 'active';                                                         // CHANGED:
					}                                                                              // CHANGED:
				}                                                                                  // CHANGED:
				// If list exists and we didn't match, it's likely inactive for this site.
				return 'inactive';                                                                // CHANGED:
			}

			return 'unknown';                                                                      // CHANGED:
		}

		// Legacy helpers (not used for UI now, but kept for compatibility).                      // CHANGED:
		public static function section_connection_intro() {
			?>
			<p class="ppa-help">
				<?php esc_html_e( 'These settings connect this site to PostPress AI.', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function field_django_url() {
			$value = get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
			?>
			<input type="url"
			       name="ppa_django_url"
			       id="ppa_django_url"
			       class="regular-text code"
			       value="<?php echo esc_attr( $value ); ?>"
			       placeholder="https://apps.techwithwayne.com/postpress-ai" />
			<p class="description">
				<?php esc_html_e( 'Where PostPress AI lives (the web address you were given).', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function field_shared_key() {
			$value = get_option( 'ppa_shared_key', '' );
			?>
			<input type="password"
			       name="ppa_shared_key"
			       id="ppa_shared_key"
			       class="regular-text"
			       value="<?php echo esc_attr( $value ); ?>"
			       autocomplete="off" />
			<p class="description">
				<?php esc_html_e( 'Secret key that links this site to your PostPress AI account.', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function section_license_intro() {
			?>
			<p class="ppa-help">
				<?php esc_html_e( 'Add your license key to turn PostPress AI on for this site.', 'postpress-ai' ); ?>
			</p>
			<?php
		}

		public static function field_license_key() {
			$raw    = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$masked = self::mask_secret( $raw );
			?>
			<input type="text"
			       name="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
			       id="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
			       class="regular-text"
			       value="<?php echo esc_attr( $raw ); ?>"
			       autocomplete="off"
			       placeholder="ppa_live_***************" />
			<p class="description">
				<?php esc_html_e( 'Saved key:', 'postpress-ai' ); ?>
				<code><?php echo esc_html( $masked ); ?></code>
			</p>
			<?php
		}

		/**
		 * Django base URL (infrastructure).
		 *
		 * Priority:
		 * 1) PPA_DJANGO_URL constant
		 * 2) ppa_django_url option
		 * 3) hard default
		 *
		 * NOTE: This is intentionally NOT shown in UI.                                           // CHANGED:
		 */
		private static function get_django_base_url() {
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {
				$base = (string) PPA_DJANGO_URL;
			} else {
				$base = (string) get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
				if ( '' === trim( $base ) ) {                                                     // CHANGED:
					$base = 'https://apps.techwithwayne.com/postpress-ai/';                         // CHANGED:
				}                                                                                   // CHANGED:
			}
			$base = esc_url_raw( $base );
			$base = untrailingslashit( $base );
			return $base;
		}

		/**
		 * Resolve the key used for X-PPA-Key.
		 *
		 * Legacy priority:
		 * 1) PPA_SHARED_KEY constant
		 * 2) ppa_shared_key option
		 * 3) ppa_shared_key filter
		 *
		 * New behavior:
		 * - If none of the above exist, use the License Key as the auth key.                     // CHANGED:
		 */
		private static function resolve_shared_key() {
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
				return trim( (string) PPA_SHARED_KEY );
			}

			$opt = get_option( 'ppa_shared_key', '' );
			if ( is_string( $opt ) ) {
				$opt = trim( $opt );
				if ( '' !== $opt ) {
					return $opt;
				}
			}

			$filtered = apply_filters( 'ppa_shared_key', '' );
			if ( is_string( $filtered ) ) {
				$filtered = trim( $filtered );
				if ( '' !== $filtered ) {
					return $filtered;
				}
			}

			// Fallback: use License Key for auth (simplified UX).                                 // CHANGED:
			$lic = self::get_license_key();                                                       // CHANGED:
			if ( '' !== $lic ) {                                                                  // CHANGED:
				return $lic;                                                                       // CHANGED:
			}                                                                                      // CHANGED:

			return '';
		}

		private static function get_license_key() {
			$key = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$key = self::sanitize_license_key( $key );
			return $key;
		}

		public static function handle_test_connectivity() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to perform this action.', 'postpress-ai' ) );
			}

			check_admin_referer( 'ppa-test-connectivity' );

			$base = self::get_django_base_url();
			$key  = self::resolve_shared_key();                                                   // CHANGED:

			if ( '' === $base ) {
				self::redirect_with_test_result( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ) ); // CHANGED:
			}

			if ( '' === $key ) {
				self::redirect_with_test_result( 'error', __( 'Please add your License Key first, then click Save.', 'postpress-ai' ) ); // CHANGED:
			}

			$headers = array(
				'Accept'           => 'application/json; charset=utf-8',
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-PPA-Key'        => $key,
				'X-PPA-View'       => 'settings',
				'X-Requested-With' => 'XMLHttpRequest',
			);

			$endpoints = array(
				'version' => trailingslashit( $base ) . 'version/',
				'health'  => trailingslashit( $base ) . 'health/',
			);

			$ok_count  = 0;
			$messages  = array();

			foreach ( $endpoints as $label => $url ) {
				$response = wp_remote_get(
					$url,
					array(
						'headers' => $headers,
						'timeout' => 15,
					)
				);

				if ( is_wp_error( $response ) ) {
					$messages[] = sprintf(
						__( '%1$s failed: %2$s', 'postpress-ai' ),
						ucfirst( $label ),
						$response->get_error_message()
					);
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					$ok_count++;
				} else {
					$messages[] = sprintf(
						__( '%1$s returned HTTP %2$d.', 'postpress-ai' ),
						ucfirst( $label ),
						$code
					);
				}
			}

			if ( 2 === $ok_count ) {
				self::redirect_with_test_result(
					'ok',
					__( 'Connected! This site can reach PostPress AI.', 'postpress-ai' )
				);
			}

			$msg = __( 'Not connected yet. Please double-check your License Key.', 'postpress-ai' ); // CHANGED:
			if ( ! empty( $messages ) ) {
				$msg .= ' ' . implode( ' ', $messages );
			}

			self::redirect_with_test_result( 'error', $msg );
		}

		public static function handle_license_verify() {
			self::handle_license_action_common( 'verify' );
		}

		public static function handle_license_activate() {
			self::handle_license_action_common( 'activate' );
		}

		public static function handle_license_deactivate() {
			self::handle_license_action_common( 'deactivate' );
		}

		private static function handle_license_action_common( $action ) {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to perform this action.', 'postpress-ai' ) );
			}

			$action = is_string( $action ) ? $action : '';
			if ( ! in_array( $action, array( 'verify', 'activate', 'deactivate' ), true ) ) {
				wp_die( esc_html__( 'Invalid action.', 'postpress-ai' ) );
			}

			check_admin_referer( 'ppa-license-' . $action );

			$base = self::get_django_base_url();
			$key  = self::resolve_shared_key();                                                   // CHANGED:
			$lic  = self::get_license_key();

			if ( '' === $base ) {
				self::redirect_with_license_result( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ), array() ); // CHANGED:
			}

			if ( '' === $lic ) {
				self::redirect_with_license_result( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ), array() ); // CHANGED:
			}

			if ( '' === $key ) {
				self::redirect_with_license_result( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ), array() ); // CHANGED:
			}

			$endpoint = trailingslashit( $base ) . 'license/' . $action . '/';

			$headers = array(
				'Accept'           => 'application/json; charset=utf-8',
				'Content-Type'     => 'application/json; charset=utf-8',
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-PPA-Key'        => $key,                                                         // CHANGED:
				'X-PPA-View'       => 'settings_license',
				'X-Requested-With' => 'XMLHttpRequest',
			);

			$payload = array(
				'license_key' => $lic,
				'site_url'    => home_url( '/' ),
			);

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => $headers,
					'timeout' => 20,
					'body'    => wp_json_encode( $payload ),
				)
			);

			$result = self::normalize_django_response( $response );
			self::cache_last_license_result( $result );

			// UI convenience marker:
			// Only set/clear if Django responded ok. This does NOT enforce licensing.              // CHANGED:
			if ( is_array( $result ) && isset( $result['ok'] ) && true === $result['ok'] ) {      // CHANGED:
				if ( 'activate' === $action ) {                                                   // CHANGED:
					update_option( self::OPT_ACTIVE_SITE, home_url( '/' ), false );               // CHANGED:
				} elseif ( 'deactivate' === $action ) {                                           // CHANGED:
					delete_option( self::OPT_ACTIVE_SITE );                                       // CHANGED:
				}                                                                                 // CHANGED:
			}                                                                                      // CHANGED:

			$notice = self::notice_from_license_result( ucfirst( $action ), $result );
			$status = ( isset( $result['ok'] ) && true === $result['ok'] ) ? 'ok' : 'error';

			self::redirect_with_license_result( $status, $notice, $result );
		}

		private static function normalize_django_response( $response ) {
			if ( is_wp_error( $response ) ) {
				return array(
					'ok'    => false,
					'error' => array(
						'type' => 'wp_http_error',
						'code' => 'request_failed',
						'hint' => $response->get_error_message(),
					),
					'ver'   => 'wp.ppa.v1',
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			$json = json_decode( $body, true );
			if ( ! is_array( $json ) ) {
				return array(
					'ok'    => false,
					'error' => array(
						'type'        => 'wp_parse_error',
						'code'        => 'invalid_json',
						'hint'        => 'PostPress AI did not return readable data.',
						'http_status' => $code,
						'body_prefix' => substr( $body, 0, 300 ),
					),
					'ver'   => 'wp.ppa.v1',
				);
			}

			$json['_http_status'] = $code;
			return $json;
		}

		private static function cache_last_license_result( $result ) {
			set_transient( self::TRANSIENT_LAST_LIC, $result, self::LAST_LIC_TTL_SECONDS );
		}

		private static function notice_from_license_result( $label, $result ) {
			if ( is_array( $result ) && isset( $result['ok'] ) && true === $result['ok'] ) {
				if ( 'Verify' === $label ) {
					return __( 'License looks good.', 'postpress-ai' );
				}
				if ( 'Activate' === $label ) {
					return __( 'This site is now activated.', 'postpress-ai' );
				}
				if ( 'Deactivate' === $label ) {
					return __( 'This site is now deactivated.', 'postpress-ai' );
				}
				return __( 'Done.', 'postpress-ai' );
			}

			$code = '';
			if ( is_array( $result ) && isset( $result['error']['code'] ) ) {
				$code = (string) $result['error']['code'];
			} elseif ( is_array( $result ) && isset( $result['error']['type'] ) ) {
				$code = (string) $result['error']['type'];
			}

			if ( '' !== $code ) {
				return sprintf( __( 'Something went wrong (%s).', 'postpress-ai' ), $code );
			}

			return __( 'Something went wrong.', 'postpress-ai' );
		}

		private static function mask_secret( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				return '(none)';
			}
			$len = strlen( $value );
			if ( $len <= 8 ) {
				return str_repeat( '*', $len );
			}
			return substr( $value, 0, 4 ) . str_repeat( '*', max( 0, $len - 8 ) ) . substr( $value, -4 );
		}

		private static function redirect_with_test_result( $status, $message ) {
			$status  = ( 'ok' === $status ) ? 'ok' : 'error';
			$message = is_string( $message ) ? $message : '';

			$url = add_query_arg(
				array(
					'page'         => 'postpress-ai-settings',
					'ppa_test'     => $status,
					'ppa_test_msg' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $url );
			exit;
		}

		private static function redirect_with_license_result( $status, $message, $result ) {
			$status  = ( 'ok' === $status ) ? 'ok' : 'error';
			$message = is_string( $message ) ? $message : '';

			$url = add_query_arg(
				array(
					'page'        => 'postpress-ai-settings',
					'ppa_lic'     => $status,
					'ppa_lic_msg' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $url );
			exit;
		}

		public static function render_page() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to access this page.', 'postpress-ai' ) );
			}

			$test_status = isset( $_GET['ppa_test'] ) ? sanitize_key( wp_unslash( $_GET['ppa_test'] ) ) : '';
			$test_msg    = isset( $_GET['ppa_test_msg'] ) ? wp_unslash( $_GET['ppa_test_msg'] ) : '';
			if ( is_string( $test_msg ) && '' !== $test_msg ) {
				$test_msg = rawurldecode( $test_msg );
			}

			$lic_status = isset( $_GET['ppa_lic'] ) ? sanitize_key( wp_unslash( $_GET['ppa_lic'] ) ) : '';
			$lic_msg    = isset( $_GET['ppa_lic_msg'] ) ? wp_unslash( $_GET['ppa_lic_msg'] ) : '';
			if ( is_string( $lic_msg ) && '' !== $lic_msg ) {
				$lic_msg = rawurldecode( $lic_msg );
			}

			$last = get_transient( self::TRANSIENT_LAST_LIC );

			$val_license = (string) get_option( self::OPT_LICENSE_KEY, '' );                      // CHANGED:
			$val_license = self::sanitize_license_key( $val_license );                             // CHANGED:

			// UX guardrails (pure PHP):
			// - Must save key before Activate.
			// - If active marker says active OR cached last result suggests active on this site, disable Activate + show badge. // CHANGED:
			$has_key          = ( '' !== $val_license );                                           // CHANGED:
			$activation_state = self::derive_activation_state( $last );                            // CHANGED:
			$is_active_here   = ( 'active' === $activation_state );                                // CHANGED:
			$is_inactive_here = ( 'inactive' === $activation_state );                              // CHANGED:
			?>
			<div class="wrap ppa-admin ppa-settings">
				<h1><?php esc_html_e( 'PostPress AI Settings', 'postpress-ai' ); ?></h1>

				<div class="ppa-card">
					<?php
					// Notices are intentionally rendered INSIDE this card to prevent “floating” outside the frame.
					if ( '' !== $test_status && '' !== $test_msg ) {
						self::render_notice( $test_status, $test_msg );
					}
					if ( '' !== $lic_status && '' !== $lic_msg ) {
						self::render_notice( $lic_status, $lic_msg );
					}
					?>

					<h2 class="title"><?php esc_html_e( 'Setup', 'postpress-ai' ); ?></h2>
					<p class="ppa-help"><?php esc_html_e( 'Paste your license key below, then click Save.', 'postpress-ai' ); ?></p> <!-- CHANGED: -->

					<form method="post" action="options.php">
						<?php settings_fields( 'ppa_settings' ); ?>

						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"><?php esc_html_e( 'License Key', 'postpress-ai' ); ?></label>
									</th>
									<td>
										<input type="text"
										       name="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
										       id="<?php echo esc_attr( self::OPT_LICENSE_KEY ); ?>"
										       class="regular-text"
										       value="<?php echo esc_attr( $val_license ); ?>"
										       autocomplete="off"
										       placeholder="ppa_live_***************" />
										<p class="description">
											<?php esc_html_e( 'Saved key:', 'postpress-ai' ); ?>
											<code><?php echo esc_html( self::mask_secret( $val_license ) ); ?></code>
										</p>
									</td>
								</tr>
							</tbody>
						</table>

						<?php submit_button( __( 'Save', 'postpress-ai' ) ); ?>
					</form>
				</div>

				<div class="ppa-card">
					<h2 class="title"><?php esc_html_e( 'License Actions', 'postpress-ai' ); ?></h2>
					<p class="ppa-help">
						<?php esc_html_e( 'Use these buttons to check or activate this site.', 'postpress-ai' ); ?> <!-- CHANGED: -->
					</p>

					<?php
					// Activation badge (display-only; local marker + cached result).                 // CHANGED:
					if ( $is_active_here ) :                                                         // CHANGED:
						?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--active"><?php esc_html_e( 'Active on this site', 'postpress-ai' ); ?></span></p>
						<?php
					elseif ( $is_inactive_here ) :                                                    // CHANGED:
						?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--inactive"><?php esc_html_e( 'Not active', 'postpress-ai' ); ?></span></p>
						<?php
					else :                                                                            // CHANGED:
						?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--unknown"><?php esc_html_e( 'Unknown (run Check License)', 'postpress-ai' ); ?></span></p>
						<?php
					endif;
					?>

					<div class="ppa-actions-row">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-verify' ); ?>
							<input type="hidden" name="action" value="ppa_license_verify" />
							<?php
							// Check License stays enabled (even if empty, handler will show friendly notice). // CHANGED:
							submit_button( __( 'Check License', 'postpress-ai' ), 'secondary', 'ppa_license_verify_btn', false ); // CHANGED:
							?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-activate' ); ?>
							<input type="hidden" name="action" value="ppa_license_activate" />
							<?php
							// Guardrails:
							// - Must save License Key first.
							// - If already active, disable Activate (prevents double-activation confusion).   // CHANGED:
							$disable_activate = ( ! $has_key ) || $is_active_here;                           // CHANGED:
							$attrs            = $disable_activate ? array( 'disabled' => 'disabled' ) : array(); // CHANGED:
							submit_button( __( 'Activate This Site', 'postpress-ai' ), 'primary', 'ppa_license_activate_btn', false, $attrs ); // CHANGED:
							?>
							<?php if ( ! $has_key ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first. Then you can activate.', 'postpress-ai' ); ?></p> <!-- CHANGED: -->
							<?php elseif ( $is_active_here ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'This site is already active.', 'postpress-ai' ); ?></p> <!-- CHANGED: -->
							<?php endif; ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-deactivate' ); ?>
							<input type="hidden" name="action" value="ppa_license_deactivate" />
							<?php
							// Deactivate stays enabled (per locked rules), but still requires a saved key in handler. // CHANGED:
							submit_button( __( 'Deactivate This Site', 'postpress-ai' ), 'delete', 'ppa_license_deactivate_btn', false ); // CHANGED:
							?>
						</form>
					</div>

					<h3><?php esc_html_e( 'Last response (optional)', 'postpress-ai' ); ?></h3>
					<p class="ppa-help"><?php esc_html_e( 'Only for troubleshooting if something fails.', 'postpress-ai' ); ?></p>
					<textarea readonly class="ppa-debug-box"><?php
						echo esc_textarea( $last ? wp_json_encode( $last, JSON_PRETTY_PRINT ) : 'No recent result yet.' );
					?></textarea>
				</div>

				<div class="ppa-card">
					<h2 class="title"><?php esc_html_e( 'Test Connection', 'postpress-ai' ); ?></h2>
					<p class="ppa-help">
						<?php esc_html_e( 'Click to make sure this site can reach PostPress AI.', 'postpress-ai' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ppa-test-connectivity' ); ?>
						<input type="hidden" name="action" value="ppa_test_connectivity" />
						<?php submit_button( __( 'Test Connection', 'postpress-ai' ), 'secondary', 'ppa_test_connectivity_btn', false ); ?>
					</form>
				</div>
			</div>
			<?php
		}
	}

	PPA_Admin_Settings::init();
}
