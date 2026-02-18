<?php
/**
 * PostPress AI — Admin Settings
 *
 * Provides:
 * - Settings UI renderer for PostPress AI.
 * - License key storage (admin-only) + license actions that call Django /license/* (server-side only).
 * - Test Connection action that calls Django /version/ and /health/ (server-side only).
 * - Display-only caching of last licensing response for admin visibility (no enforcement).
 *
 * Notes:
 * - Server URL is infrastructure; kept internally (constant/option) but NOT shown to end users.
 * - Connection Key is legacy; if present we use it, otherwise we use License Key as the auth key.
 *
 * ========= CHANGE LOG =========
 * 2026-01-25: HARDEN: Seed ppa_license_last_result for any wp-admin user (even before a license key is saved) to prevent editor/composer warnings; treat "no key" as unknown activation state. # CHANGED:
 *
 * 2026-01-24: HARDEN: Always seed ppa_license_last_result transient on admin load when missing (prevents “not set” warnings). # CHANGED:
 * 2026-01-24: FIX: Plan/Usage parsing now supports license.v1 nested payloads: license.sites.* and license.tokens.*. # CHANGED:
 * 2026-01-24: ADD: Render a Plan & Usage card on Settings using the parsed sites/tokens values (safe + admin-only).  # CHANGED:
 * 2026-01-24: HARDEN: Activation state now requires site-match; stale "active" is cleared + requires Check License.   # CHANGED:
 * 2026-01-24: ADD: License action requests now send Authorization + X-PPA-Site headers for parity + site binding.     # CHANGED:
 *
 * 2026-01-21: FIX: Ensure there is NO submit_button() shim in this file (prevents redeclare fatal).            # CHANGED:
 * 2026-01-21: FIX: Ensure Settings NEVER renders at include-time (prevents headers already sent).            # CHANGED:
 * 2026-01-21: FIX: seed_last_license_transient_if_missing() now supports older no-arg calls (compat).        # CHANGED:
 * 2026-01-21: FIX: Load wp-admin button helpers safely inside render_page() only when needed.               # CHANGED:
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PPA_Admin_Settings' ) ) {

	/**
	 * Admin Settings for PostPress AI.
	 */
	class PPA_Admin_Settings {

		// ===== License option + transient (display-only) =====
		const OPT_LICENSE_KEY      = 'ppa_license_key';
		const OPT_ACTIVE_SITE      = 'ppa_license_active_site';

		// Persisted state for enforcement (controller will use this next). No secrets stored.
		const OPT_LICENSE_STATE            = 'ppa_license_state';
		const OPT_LICENSE_LAST_ERROR_CODE  = 'ppa_license_last_error_code';
		const OPT_LICENSE_LAST_CHECKED_AT  = 'ppa_license_last_checked_at';

		// Persistent “top banner” (single message that sticks across refresh)
		const OPT_BANNER_TYPE       = 'ppa_settings_banner_type';
		const OPT_BANNER_MSG        = 'ppa_settings_banner_msg';
		const OPT_BANNER_LAST_AT    = 'ppa_settings_banner_last_at';

		// Persist last Test Connection result (so it’s not a flash-only notice)
		const OPT_CONN_STATE        = 'ppa_conn_state';
		const OPT_CONN_LAST_ERROR   = 'ppa_conn_last_error';
		const OPT_CONN_LAST_CHECKED = 'ppa_conn_last_checked_at';

		const TRANSIENT_LAST_LIC   = 'ppa_license_last_result';
		const LAST_LIC_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

		// Idempotency guards (this file may be included more than once depending on admin bootstrap).
		private static $booted              = false;
		private static $settings_registered = false;

		/**
		 * Centralized capability:
		 * Settings + licensing are admin-only.
		 */
		private static function cap() {
			return 'manage_options';
		}

		/**
		 * Tight logger for debug.log triage.
		 * Always prefixed with "PPA:" so you can grep cleanly.
		 *
		 * IMPORTANT:
		 * - Call this ONLY on failures. No "start/ok/http=200" chatter.
		 *
		 * @param string $msg
		 */
		private static function log( $msg ) {
			$msg = is_string( $msg ) ? trim( $msg ) : '';
			if ( '' === $msg ) {
				return;
			}
			error_log( 'PPA: ' . $msg );
		}

		/**
		 * Save the single persistent banner message (type+msg+timestamp).
		 *
		 * @param string $type ok|error
		 * @param string $msg
		 */
		private static function save_settings_banner( $type, $msg ) {
			$type = ( 'ok' === $type ) ? 'ok' : 'error';
			$msg  = is_string( $msg ) ? trim( $msg ) : '';
			if ( '' === $msg ) {
				return;
			}
			update_option( self::OPT_BANNER_TYPE, $type, false );
			update_option( self::OPT_BANNER_MSG,  $msg,  false );
			update_option( self::OPT_BANNER_LAST_AT, time(), false );
		}

		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			// Settings API registration.
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

			// CHANGED: Bullet-proof seeding of the last license transient so other admin screens never see "not set".
			add_action( 'admin_init', array( __CLASS__, 'maybe_seed_last_license_transient' ), 20 ); // CHANGED:

			// If this file loads after admin_init (e.g. via admin_menu), admin_init already ran.
			// Register settings immediately so options.php will accept option_page=ppa_settings.
			if ( did_action( 'admin_init' ) ) {
				self::register_settings();
				self::maybe_seed_last_license_transient(); // CHANGED:
			}

			// Test Connection handler (admin-post).
			add_action( 'admin_post_ppa_test_connectivity', array( __CLASS__, 'handle_test_connectivity' ) );

			// Licensing handlers (admin-post, server-side only).
			add_action( 'admin_post_ppa_license_verify', array( __CLASS__, 'handle_license_verify' ) );
			add_action( 'admin_post_ppa_license_activate', array( __CLASS__, 'handle_license_activate' ) );
			add_action( 'admin_post_ppa_license_deactivate', array( __CLASS__, 'handle_license_deactivate' ) );
		}

		/**
		 * CHANGED: Ensure ppa_license_last_result transient exists as a safe local snapshot.
		 *
		 * Why:
		 * - Some screens (and/or controller enforcement) may read the transient before Settings is visited.
		 * - We want a bullet-proof default so nothing emits "transient not set" warnings/noise.
		 * - This does NOT call Django. It only snapshots existing local options into the transient.
		 */
		public static function maybe_seed_last_license_transient() { // CHANGED:
			if ( ! is_admin() ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:
			// CHANGED: Do NOT require manage_options here.
			// Seeding is safe (no network calls, no secrets). This prevents editor/composer wp-admin screens
			// from hitting "transient not set" codepaths. # CHANGED:

			$existing = get_transient( self::TRANSIENT_LAST_LIC ); // CHANGED:
			if ( is_array( $existing ) ) { // CHANGED:
				return; // CHANGED:
			} // CHANGED:

			// CHANGED: Seed a local snapshot transient (no network calls). Safe even before a license key is saved.
			self::seed_last_license_transient_if_missing( null ); // CHANGED:
		} // CHANGED:

		/**
		 * Register options and fields for the settings screen.
		 *
		 * Note:
		 * - We still register legacy options for backwards compatibility (sanitization + storage),
		 *   but we DO NOT render them in the UI anymore.
		 */
		public static function register_settings() {
			if ( self::$settings_registered ) {
				return;
			}
			self::$settings_registered = true;

			// (Legacy) Django URL is still supported (constant/option), but not rendered.
			register_setting(
				'ppa_settings',
				'ppa_django_url',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_django_url' ),
					'default'           => 'https://apps.techwithwayne.com/postpress-ai/',
				)
			);

			// (Legacy) Shared key is still supported, but not rendered.
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

			// Strip control characters (invisible paste junk) + whitespace. Keep format permissive.
			$tmp   = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
			$value = is_string( $tmp ) ? $tmp : $value;

			$tmp   = preg_replace( '/\s+/', '', $value );
			$value = is_string( $tmp ) ? $tmp : $value;

			if ( strlen( $value ) > 200 ) {
				$value = substr( $value, 0, 200 );
			}
			return $value;
		}

		/**
		 * Render a Composer-parity notice (scoped to Settings CSS).
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
		 * Detect plan-limit site cap response.
		 *
		 * @param mixed $result
		 * @return bool
		 */
		private static function is_plan_limit_site_limit_reached( $result ) {
			if ( ! is_array( $result ) ) {
				return false;
			}

			$err = array();

			if ( isset( $result['error'] ) && is_array( $result['error'] ) ) {
				$err = $result['error'];
			} elseif ( isset( $result['data']['error'] ) && is_array( $result['data']['error'] ) ) {
				$err = $result['data']['error'];
			}

			$type = isset( $err['type'] ) ? strtolower( trim( (string) $err['type'] ) ) : '';
			$code = isset( $err['code'] ) ? strtolower( trim( (string) $err['code'] ) ) : '';

			return ( 'plan_limit' === $type && 'site_limit_reached' === $code );
		}

		private static function is_active_on_this_site_option() {
			$stored = get_option( self::OPT_ACTIVE_SITE, '' );
			$stored = is_string( $stored ) ? untrailingslashit( $stored ) : '';
			$home   = untrailingslashit( home_url( '/' ) );
			return ( '' !== $stored && $stored === $home );
		}

		/**
		 * Detect if a server result is clearly bound to a DIFFERENT site than this WP install.
		 * If so, we treat this as NOT active on this site (bullet-proof default).
		 *
		 * @param mixed $result
		 * @return bool
		 */
		private static function is_site_mismatch_result( $result ) {                                                        // CHANGED:
			if ( ! is_array( $result ) ) {                                                                                 // CHANGED:
				return false;                                                                                              // CHANGED:
			}                                                                                                              // CHANGED:
			if ( ! isset( $result['data']['activation']['site_url'] ) || ! is_string( $result['data']['activation']['site_url'] ) ) { // CHANGED:
				return false;                                                                                              // CHANGED:
			}                                                                                                              // CHANGED:
			$server_site = untrailingslashit( trim( (string) $result['data']['activation']['site_url'] ) );                // CHANGED:
			if ( '' === $server_site ) {                                                                                   // CHANGED:
				return false;                                                                                              // CHANGED:
			}                                                                                                              // CHANGED:
			$home = untrailingslashit( home_url( '/' ) );                                                                   // CHANGED:
			return ( $server_site !== $home );                                                                              // CHANGED:
		}                                                                                                                  // CHANGED:

		private static function derive_activation_state( $last ) {
			// CHANGED: If there is no saved license key, treat activation as unknown (not inactive).
			$lic = self::get_license_key();                                                                                // CHANGED:
			if ( '' === $lic ) {                                                                                           // CHANGED:
				return 'unknown';                                                                                          // CHANGED:
			}                                                                                                               // CHANGED:

			// Strongest signal: we explicitly stored that THIS site is active.
			if ( self::is_active_on_this_site_option() ) {                                                                  // CHANGED:
				return 'active';                                                                                           // CHANGED:
			}

			// Next best: last result (site-aware parsing below).
			$server_state = self::derive_activation_state_from_result_only( $last );                                        // CHANGED:
			if ( 'unknown' !== $server_state ) {                                                                           // CHANGED:
				return $server_state;                                                                                      // CHANGED:
			}

			// Persisted state is only a fallback. We DO NOT treat "active" as active-on-this-site unless OPT_ACTIVE_SITE matches.
			$persisted = get_option( self::OPT_LICENSE_STATE, 'unknown' );
			$persisted = is_string( $persisted ) ? strtolower( trim( $persisted ) ) : 'unknown';

			if ( 'inactive' === $persisted ) {                                                                             // CHANGED:
				return 'inactive';                                                                                         // CHANGED:
			}

			// If persisted says "active" but we don't have an active_site match, treat as unknown and require Check License.
			return 'unknown';                                                                                              // CHANGED:
		}

		private static function derive_activation_state_from_result_only( $result ) {
			if ( ! is_array( $result ) ) {
				return 'unknown';
			}

			$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
			$home = untrailingslashit( home_url( '/' ) );                                                                   // CHANGED:

			// If server explicitly reports activation state, trust it (but require site match if provided).
			if ( isset( $data['activation'] ) && is_array( $data['activation'] ) && array_key_exists( 'activated', $data['activation'] ) ) {
				$act = $data['activation'];                                                                                // CHANGED:

				$act_site = '';                                                                                            // CHANGED:
				if ( isset( $act['site_url'] ) && is_string( $act['site_url'] ) ) {                                        // CHANGED:
					$act_site = untrailingslashit( trim( (string) $act['site_url'] ) );                                   // CHANGED:
				}                                                                                                          // CHANGED:
				if ( '' !== $act_site && $act_site !== $home ) {                                                           // CHANGED:
					return 'inactive';                                                                                     // CHANGED:
				}                                                                                                          // CHANGED:

				$v = $act['activated'];
				if ( is_bool( $v ) ) {
					return $v ? 'active' : 'inactive';
				}
				if ( is_string( $v ) ) {
					$vv = strtolower( trim( $v ) );
					if ( in_array( $vv, array( 'true', '1', 'yes', 'on', 'active', 'activated' ), true ) ) {
						return 'active';
					}
					if ( in_array( $vv, array( 'false', '0', 'no', 'off', 'inactive', 'deactivated' ), true ) ) {
						return 'inactive';
					}
				}
			}

			// Older/alternate shapes (avoid ambiguous keys like "status" — that can be LICENSE status, not activation).
			$candidates = array( 'active', 'is_active', 'site_active', 'activated', 'activation_status' );                  // CHANGED:
			foreach ( $candidates as $k ) {
				if ( array_key_exists( $k, $data ) ) {
					$v = $data[ $k ];
					if ( is_bool( $v ) ) {
						return $v ? 'active' : 'inactive';
					}
					if ( is_string( $v ) ) {
						$vv = strtolower( trim( $v ) );
						if ( in_array( $vv, array( 'active', 'activated', 'on', 'enabled', 'true', '1', 'yes' ), true ) ) {
							return 'active';
						}
						if ( in_array( $vv, array( 'inactive', 'deactivated', 'off', 'disabled', 'false', '0', 'no' ), true ) ) {
							return 'inactive';
						}
					}
				}
			}

			if ( isset( $data['active_sites'] ) && is_array( $data['active_sites'] ) ) {
				foreach ( $data['active_sites'] as $site ) {
					if ( is_string( $site ) && untrailingslashit( $site ) === $home ) {
						return 'active';
					}
				}
				return 'inactive';
			}

			return 'unknown';
		}

		private static function extract_error_code( $result ) {
			if ( ! is_array( $result ) ) {
				return '';
			}

			$err = array();
			if ( isset( $result['error'] ) && is_array( $result['error'] ) ) {
				$err = $result['error'];
			} elseif ( isset( $result['data']['error'] ) && is_array( $result['data']['error'] ) ) {
				$err = $result['data']['error'];
			}

			$code = '';
			if ( isset( $err['code'] ) ) {
				$code = (string) $err['code'];
			} elseif ( isset( $err['type'] ) ) {
				$code = (string) $err['type'];
			}

			$code = strtolower( trim( $code ) );
			return $code;
		}

		private static function sync_persisted_state_from_cached_last_result( $last ) {
			if ( ! is_array( $last ) ) {
				return;
			}

			$ok = ( isset( $last['ok'] ) && true === $last['ok'] );
			if ( ! $ok ) {
				return;
			}

			$state = self::derive_activation_state_from_result_only( $last );
			if ( ! in_array( $state, array( 'active', 'inactive' ), true ) ) {
				return;
			}

			$data = isset( $last['data'] ) && is_array( $last['data'] ) ? $last['data'] : array();
			$act  = isset( $data['activation'] ) && is_array( $data['activation'] ) ? $data['activation'] : array();

			$home = untrailingslashit( home_url( '/' ) );

			// Bullet-proof: if server says activation belongs to a different site, clear local active marker + force inactive.
			if ( isset( $act['site_url'] ) && is_string( $act['site_url'] ) && '' !== trim( $act['site_url'] ) ) {        // CHANGED:
				$server_site = untrailingslashit( trim( (string) $act['site_url'] ) );                                    // CHANGED:
				if ( '' !== $server_site && $server_site !== $home ) {                                                     // CHANGED:
					delete_option( self::OPT_ACTIVE_SITE );                                                                // CHANGED:
					update_option( self::OPT_LICENSE_STATE, 'inactive', false );                                           // CHANGED:
					update_option( self::OPT_LICENSE_LAST_ERROR_CODE, 'site_mismatch', false );                           // CHANGED:
					update_option( self::OPT_LICENSE_LAST_CHECKED_AT, time(), false );                                    // CHANGED:
					return;                                                                                                // CHANGED:
				}                                                                                                          // CHANGED:
			}                                                                                                              // CHANGED:

			$cur_err = get_option( self::OPT_LICENSE_LAST_ERROR_CODE, '' );
			$cur_err = is_string( $cur_err ) ? $cur_err : '';
			if ( '' !== $cur_err ) {
				update_option( self::OPT_LICENSE_LAST_ERROR_CODE, '', false );
			}

			$cur_state = get_option( self::OPT_LICENSE_STATE, 'unknown' );
			$cur_state = is_string( $cur_state ) ? strtolower( trim( $cur_state ) ) : 'unknown';
			if ( $cur_state !== $state ) {
				update_option( self::OPT_LICENSE_STATE, $state, false );
			}

			if ( 'active' === $state ) {
				update_option( self::OPT_ACTIVE_SITE, home_url( '/' ), false );
			} else {
				delete_option( self::OPT_ACTIVE_SITE );
			}

			$existing_checked = (int) get_option( self::OPT_LICENSE_LAST_CHECKED_AT, 0 );
			$verified_ts      = 0;
			if ( isset( $act['last_verified_at'] ) && is_string( $act['last_verified_at'] ) && '' !== trim( $act['last_verified_at'] ) ) {
				$ts = strtotime( (string) $act['last_verified_at'] );
				if ( false !== $ts && $ts > 0 ) {
					$verified_ts = (int) $ts;
				}
			}
			if ( $verified_ts > 0 && $verified_ts > $existing_checked ) {
				update_option( self::OPT_LICENSE_LAST_CHECKED_AT, $verified_ts, false );
			}
		}

		private static function persist_license_state_from_action( $action, $result ) {
			$action = is_string( $action ) ? strtolower( trim( $action ) ) : '';
			$ok     = ( is_array( $result ) && isset( $result['ok'] ) && true === $result['ok'] );

			$state = self::derive_activation_state_from_result_only( $result );
			$err   = $ok ? '' : self::extract_error_code( $result );

			if ( $ok && 'activate' === $action ) {
				$state = 'active';
			} elseif ( $ok && 'deactivate' === $action ) {
				$state = 'inactive';
			}

			update_option( self::OPT_LICENSE_LAST_CHECKED_AT, time(), false );
			update_option( self::OPT_LICENSE_LAST_ERROR_CODE, $err, false );

			if ( in_array( $state, array( 'active', 'inactive', 'unknown' ), true ) ) {
				update_option( self::OPT_LICENSE_STATE, $state, false );
			}

			$home           = untrailingslashit( home_url( '/' ) );
			$server_site_ok = true;
			if ( is_array( $result ) && isset( $result['data']['activation']['site_url'] ) && is_string( $result['data']['activation']['site_url'] ) ) {
				$server_site = untrailingslashit( trim( (string) $result['data']['activation']['site_url'] ) );
				if ( '' !== $server_site && $server_site !== $home ) {
					$server_site_ok = false;
				}
			}

			// Bullet-proof: if server indicates a different site, treat as NOT active here.
			if ( $ok && ! $server_site_ok ) {                                                                              // CHANGED:
				update_option( self::OPT_LICENSE_LAST_ERROR_CODE, 'site_mismatch', false );                                 // CHANGED:
				update_option( self::OPT_LICENSE_STATE, 'inactive', false );                                                // CHANGED:
				delete_option( self::OPT_ACTIVE_SITE );                                                                     // CHANGED:
				return;                                                                                                     // CHANGED:
			}                                                                                                               // CHANGED:

			if ( $ok && 'verify' === $action ) {
				if ( 'active' === $state && $server_site_ok ) {
					update_option( self::OPT_ACTIVE_SITE, home_url( '/' ), false );
				} elseif ( 'inactive' === $state ) {
					delete_option( self::OPT_ACTIVE_SITE );
				}
			}

			if ( $ok && 'activate' === $action ) {
				if ( $server_site_ok ) {
					update_option( self::OPT_ACTIVE_SITE, home_url( '/' ), false );
				}
			} elseif ( $ok && 'deactivate' === $action ) {
				delete_option( self::OPT_ACTIVE_SITE );
			}

			if ( ! $ok ) {
				$revoke_codes = array( 'not_activated', 'invalid_license', 'expired', 'revoked', 'license_not_found', 'site_mismatch' ); // CHANGED:
				if ( in_array( $err, $revoke_codes, true ) ) {
					delete_option( self::OPT_ACTIVE_SITE );
					update_option( self::OPT_LICENSE_STATE, 'inactive', false );
				}
			}
		}

		private static function detect_connection_key_info() {
			$const = '';
			if ( defined( 'PPA_SHARED_KEY' ) && PPA_SHARED_KEY ) {
				$const = trim( (string) PPA_SHARED_KEY );
			}

			$opt = get_option( 'ppa_shared_key', '' );
			$opt = is_string( $opt ) ? trim( $opt ) : '';

			if ( '' !== $const ) {
				return array(
					'present' => true,
					'source'  => 'wp-config.php',
					'masked'  => self::mask_secret( $const ),
				);
			}

			if ( '' !== $opt ) {
				return array(
					'present' => true,
					'source'  => 'settings option',
					'masked'  => self::mask_secret( $opt ),
				);
			}

			return array( 'present' => false, 'source' => '', 'masked' => '' );
		}

		// Legacy helpers (kept registered, not shown in UI now).
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

		private static function get_django_base_url() {
			if ( defined( 'PPA_DJANGO_URL' ) && PPA_DJANGO_URL ) {
				$base = (string) PPA_DJANGO_URL;
			} else {
				$base = (string) get_option( 'ppa_django_url', 'https://apps.techwithwayne.com/postpress-ai/' );
				if ( '' === trim( $base ) ) {
					$base = 'https://apps.techwithwayne.com/postpress-ai/';
				}
			}
			$base = esc_url_raw( $base );
			$base = untrailingslashit( $base );
			return $base;
		}

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

			$lic = self::get_license_key();
			if ( '' !== $lic ) {
				return $lic;
			}

			return '';
		}

		private static function get_license_key() {
			$key = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$key = self::sanitize_license_key( $key );
			return $key;
		}

		private static function build_settings_banner( $has_key, $activation_state, $site_limit_reached ) {
			if ( $site_limit_reached ) {
				return array(
					'type' => 'error',
					'msg'  => __( 'Current status: Plan limit reached. Upgrade your plan or deactivate another site, then try again.', 'postpress-ai' ),
				);
			}

			$status_txt = __( 'Unknown (run Check License)', 'postpress-ai' );
			if ( 'active' === $activation_state ) {
				$status_txt = __( 'Active on this site', 'postpress-ai' );
			} elseif ( 'inactive' === $activation_state ) {
				$status_txt = __( 'Not active', 'postpress-ai' );
			} elseif ( ! $has_key ) {
				$status_txt = __( 'Not set up yet', 'postpress-ai' );
			}

			$stored_type = get_option( self::OPT_BANNER_TYPE, 'ok' );
			$stored_type = ( 'ok' === $stored_type ) ? 'ok' : 'error';
			$stored_msg  = get_option( self::OPT_BANNER_MSG, '' );
			$stored_msg  = is_string( $stored_msg ) ? trim( $stored_msg ) : '';

			$action_msg  = $stored_msg;
			$action_type = $stored_type;

			if ( '' === $action_msg ) {
				if ( ! $has_key ) {
					$action_msg  = __( 'Paste your license key, then click Save.', 'postpress-ai' );
					$action_type = 'error';
				} else {
					$lic_err = get_option( self::OPT_LICENSE_LAST_ERROR_CODE, '' );
					$lic_err = is_string( $lic_err ) ? strtolower( trim( $lic_err ) ) : '';
					if ( '' !== $lic_err ) {
						$action_msg  = sprintf( __( 'Needs attention (%s). Click “Check License” to refresh.', 'postpress-ai' ), $lic_err );
						$action_type = 'error';
					} else {
						$action_msg  = ( 'active' === $activation_state ) ? __( 'License looks good.', 'postpress-ai' ) : __( 'Click “Check License” to refresh.', 'postpress-ai' );
						$action_type = 'ok';
					}
				}
			}

			$ts1     = (int) get_option( self::OPT_LICENSE_LAST_CHECKED_AT, 0 );
			$ts2     = (int) get_option( self::OPT_CONN_LAST_CHECKED, 0 );
			$ts3     = (int) get_option( self::OPT_BANNER_LAST_AT, 0 );
			$last_ts = max( 0, $ts1, $ts2, $ts3 );

			$last_txt = '';
			if ( $last_ts > 0 ) {
				$last_txt = date_i18n( 'M j, Y g:ia', $last_ts );
			}

			$final = sprintf( __( 'Current status: %s. %s', 'postpress-ai' ), $status_txt, $action_msg );
			if ( '' !== $last_txt ) {
				$final .= ' ' . sprintf( __( '(Last checked: %s)', 'postpress-ai' ), $last_txt );
			}

			return array( 'type' => $action_type, 'msg' => $final );
		}

		public static function handle_test_connectivity() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You are not allowed to perform this action.', 'postpress-ai' ) );
			}

			check_admin_referer( 'ppa-test-connectivity' );

			$base = self::get_django_base_url();
			$key  = self::resolve_shared_key();

			if ( '' === $base ) {
				self::log( 'test_connectivity fail: missing base url' );
				update_option( self::OPT_CONN_STATE, 'error', false );
				update_option( self::OPT_CONN_LAST_ERROR, 'missing_base_url', false );
				update_option( self::OPT_CONN_LAST_CHECKED, time(), false );
				self::save_settings_banner( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ) );
				self::redirect_with_test_result( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ) );
			}

			if ( '' === $key ) {
				self::log( 'test_connectivity fail: missing key' );
				update_option( self::OPT_CONN_STATE, 'error', false );
				update_option( self::OPT_CONN_LAST_ERROR, 'missing_key', false );
				update_option( self::OPT_CONN_LAST_CHECKED, time(), false );
				self::save_settings_banner( 'error', __( 'Please add your License Key first, then click Save.', 'postpress-ai' ) );
				self::redirect_with_test_result( 'error', __( 'Please add your License Key first, then click Save.', 'postpress-ai' ) );
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

			$ok_count = 0;
			$messages = array();

			foreach ( $endpoints as $label => $url ) {
				$response = wp_remote_get(
					$url,
					array(
						'headers' => $headers,
						'timeout' => 15,
					)
				);

				if ( is_wp_error( $response ) ) {
					self::log( 'test_connectivity fail: ' . $label . ' wp_error: ' . $response->get_error_message() );
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
					self::log( 'test_connectivity fail: ' . $label . ' http=' . $code );
					$messages[] = sprintf(
						__( '%1$s returned HTTP %2$d.', 'postpress-ai' ),
						ucfirst( $label ),
						$code
					);
				}
			}

			if ( 2 === $ok_count ) {
				update_option( self::OPT_CONN_STATE, 'ok', false );
				update_option( self::OPT_CONN_LAST_ERROR, '', false );
				update_option( self::OPT_CONN_LAST_CHECKED, time(), false );
				self::save_settings_banner( 'ok', __( 'Connected! This site can reach PostPress AI.', 'postpress-ai' ) );

				self::redirect_with_test_result(
					'ok',
					__( 'Connected! This site can reach PostPress AI.', 'postpress-ai' )
				);
			}

			$msg = __( 'Not connected yet. Please double-check your License Key.', 'postpress-ai' );
			if ( ! empty( $messages ) ) {
				$msg .= ' ' . implode( ' ', $messages );
			}

			self::log( 'test_connectivity failed' );

			update_option( self::OPT_CONN_STATE, 'error', false );
			update_option( self::OPT_CONN_LAST_ERROR, 'not_connected', false );
			update_option( self::OPT_CONN_LAST_CHECKED, time(), false );
			self::save_settings_banner( 'error', $msg );

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
			$key  = self::resolve_shared_key();
			$lic  = self::get_license_key();

			if ( '' === $base ) {
				self::log( 'license_action fail: missing base url' );
				self::save_settings_banner( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ) );
				self::redirect_with_license_result( 'error', __( 'Missing server configuration. Please contact support.', 'postpress-ai' ), array() );
			}

			if ( '' === $lic ) {
				self::log( 'license_action fail: missing license key' );
				self::save_settings_banner( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ) );
				self::redirect_with_license_result( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ), array() );
			}

			if ( '' === $key ) {
				self::log( 'license_action fail: missing auth key' );
				self::save_settings_banner( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ) );
				self::redirect_with_license_result( 'error', __( 'Please paste your License Key first, then click Save.', 'postpress-ai' ), array() );
			}

			$endpoint = trailingslashit( $base ) . 'license/' . $action . '/';

			$site = esc_url_raw( home_url( '/' ) );                                                                          // CHANGED:

			$headers = array(
				'Accept'           => 'application/json; charset=utf-8',
				'Content-Type'     => 'application/json; charset=utf-8',
				'User-Agent'       => 'PostPressAI-WordPress/' . ( defined( 'PPA_VERSION' ) ? PPA_VERSION : 'dev' ),
				'X-PPA-Key'        => $key,
				'Authorization'    => 'Bearer ' . $key,                                                                      // CHANGED:
				'X-PPA-Site'       => $site,                                                                                // CHANGED:
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

			// Bullet-proof: if server response is bound to a different site, force a local error snapshot.
			if ( self::is_site_mismatch_result( $result ) ) {                                                               // CHANGED:
				self::log( 'license_action site_mismatch: action=' . $action );                                             // CHANGED:
				$result['ok']    = false;                                                                                  // CHANGED:
				$result['error'] = array(                                                                                  // CHANGED:
					'type' => 'activation',                                                                                // CHANGED:
					'code' => 'site_mismatch',                                                                              // CHANGED:
					'hint' => 'This license is activated for a different site. Deactivate it there, then run Check License here.', // CHANGED:
				);                                                                                                          // CHANGED:
				$result['_wp_site_mismatch'] = true;                                                                        // CHANGED:
			}                                                                                                               // CHANGED:

			self::cache_last_license_result( $result );
			self::persist_license_state_from_action( $action, $result );

			$http = ( is_array( $result ) && isset( $result['_http_status'] ) ) ? (int) $result['_http_status'] : 0;
			$ok   = ( is_array( $result ) && isset( $result['ok'] ) && true === $result['ok'] );
			if ( ! $ok ) {
				$err_code = '';
				if ( is_array( $result ) && isset( $result['error']['code'] ) ) {
					$err_code = (string) $result['error']['code'];
				} elseif ( is_array( $result ) && isset( $result['error']['type'] ) ) {
					$err_code = (string) $result['error']['type'];
				}
				$err_code = trim( $err_code );
				self::log( 'license_action failed: action=' . $action . ' http=' . $http . ( '' !== $err_code ? ' code=' . $err_code : '' ) );
			}

			$notice = self::notice_from_license_result( ucfirst( $action ), $result );
			$status = ( isset( $result['ok'] ) && true === $result['ok'] ) ? 'ok' : 'error';

			self::save_settings_banner( $status, $notice );

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
			// Clear, explicit message for the "site mismatch" safety case.
			if ( is_array( $result ) && isset( $result['error']['code'] ) && 'site_mismatch' === (string) $result['error']['code'] ) { // CHANGED:
				return __( 'This license is activated for a different site. Deactivate it there, then click “Check License” here.', 'postpress-ai' ); // CHANGED:
			}                                                                                                              // CHANGED:

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

			if ( self::is_plan_limit_site_limit_reached( $result ) ) {
				return __( 'Plan limit reached: your account has hit its site limit. Upgrade your plan or deactivate another site, then try again.', 'postpress-ai' );
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

		private static function safe_http_url( $url ) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' === $url ) {
				return '';
			}
			$parsed = wp_parse_url( $url );
			if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) ) {
				return '';
			}
			$scheme = strtolower( (string) $parsed['scheme'] );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return '';
			}
			return esc_url( $url );
		}

		/**
		 * Compatibility fix:
		 * Older codepaths called seed_last_license_transient_if_missing() with NO args.
		 * We now support both.
		 *
		 * @param mixed $last
		 * @return array
		 */
		private static function seed_last_license_transient_if_missing( $last = null ) { // CHANGED:
			if ( is_array( $last ) ) {
				return $last;
			}

			$state = get_option( self::OPT_LICENSE_STATE, 'unknown' );
			$state = is_string( $state ) ? strtolower( trim( $state ) ) : 'unknown';
			if ( ! in_array( $state, array( 'active', 'inactive', 'unknown' ), true ) ) {
				$state = 'unknown';
			}

			$err = get_option( self::OPT_LICENSE_LAST_ERROR_CODE, '' );
			$err = is_string( $err ) ? strtolower( trim( $err ) ) : '';

			$checked_at  = (int) get_option( self::OPT_LICENSE_LAST_CHECKED_AT, 0 );
			$checked_iso = ( $checked_at > 0 ) ? gmdate( 'c', $checked_at ) : '';

			$home          = untrailingslashit( home_url( '/' ) );
			$stored_active = get_option( self::OPT_ACTIVE_SITE, '' );
			$stored_active = is_string( $stored_active ) ? untrailingslashit( trim( $stored_active ) ) : '';

			// Bullet-proof: only consider "activated" true if the stored active site matches THIS home URL.
			$activated = ( '' !== $stored_active && $stored_active === $home );                                             // CHANGED:

			$masked = self::mask_secret( (string) get_option( self::OPT_LICENSE_KEY, '' ) );

			$seed = array(
				'ok'           => ( '' === $err && in_array( $state, array( 'active', 'inactive' ), true ) ),
				'data'         => array(
					'license'    => array(
						'status'     => $state,
						'key_masked' => $masked,
					),
					'activation' => array(
						'site_url'         => $home,
						'activated'        => (bool) $activated,
						'last_verified_at' => $checked_iso,
						'_source'          => 'local_snapshot',
					),
				),
				'ver'          => 'license.v1',
				'_http_status' => 0,
				'_local_seed'  => true,
			);

			if ( '' !== $err ) {
				$seed['ok']    = false;
				$seed['error'] = array(
					'type' => 'local_state',
					'code' => $err,
					'hint' => 'No recent server response cached. Run “Check License” to refresh.',
				);
			}

			// CHANGED: Use the same TTL as real server results for consistency.
			set_transient( self::TRANSIENT_LAST_LIC, $seed, self::LAST_LIC_TTL_SECONDS ); // CHANGED:
			return $seed;
		}

		// === BEGIN: Plan/Usage helpers ===

		private static function fmt_int( $n ) { // CHANGED:
			if ( null === $n ) {
				return '';
			}
			if ( ! is_numeric( $n ) ) {
				return '';
			}
			return number_format_i18n( (int) $n );
		}

		private static function fmt_slug( $s ) { // CHANGED:
			$s = is_string( $s ) ? trim( $s ) : '';
			if ( '' === $s ) {
				return __( '(unknown)', 'postpress-ai' );
			}
			return $s;
		}

		private static function extract_plan_usage_from_last( $last ) { // CHANGED:
			$out = array(
				'plan_slug'               => '',
				'status'                  => '',
				'max_sites'               => 0,
				'unlimited_sites'         => false,

				// Sites (new nested structure supported):
				'sites_used'              => 0,
				'sites_max'               => null,
				'sites_unlimited'         => false,

				'activation_live'         => false,

				// Tokens (new nested structure supported):
				'tokens_used'             => null,
				'tokens_limit'            => null,
				'tokens_remaining'        => null,
				'tokens_purchased_balance'=> null,
				'tokens_remaining_total'  => null,
				'tokens_period_start'     => '',
				'tokens_period_end'       => '',

				'account_url'             => '',
				'upgrade_url'             => '',
			);

			if ( ! is_array( $last ) ) {
				return $out;
			}

			$data = ( isset( $last['data'] ) && is_array( $last['data'] ) ) ? $last['data'] : array();
			$lic  = ( isset( $data['license'] ) && is_array( $data['license'] ) ) ? $data['license'] : array();
			$act  = ( isset( $data['activation'] ) && is_array( $data['activation'] ) ) ? $data['activation'] : array();

			// Basic plan fields.
			if ( isset( $lic['plan_slug'] ) ) {
				$out['plan_slug'] = is_string( $lic['plan_slug'] ) ? trim( $lic['plan_slug'] ) : (string) $lic['plan_slug'];
			}
			if ( isset( $lic['status'] ) ) {
				$out['status'] = is_string( $lic['status'] ) ? trim( $lic['status'] ) : (string) $lic['status'];
			}
			if ( isset( $lic['max_sites'] ) ) {
				$out['max_sites'] = (int) $lic['max_sites'];
			}
			if ( isset( $lic['unlimited_sites'] ) ) {
				$out['unlimited_sites'] = (bool) $lic['unlimited_sites'];
			}

			// Activation.
			if ( array_key_exists( 'activated', $act ) ) {
				$v = $act['activated'];
				if ( is_bool( $v ) ) {
					$out['activation_live'] = $v;
				} elseif ( is_string( $v ) ) {
					$vv                   = strtolower( trim( $v ) );
					$out['activation_live'] = in_array( $vv, array( '1', 'true', 'yes', 'on', 'active', 'activated' ), true );
				}
			}

			/**
			 * SITES (license.v1 supports: data.license.sites.used / max / unlimited)
			 */
			$sites_used = null;
			if ( isset( $lic['sites'] ) && is_array( $lic['sites'] ) ) { // CHANGED:
				if ( isset( $lic['sites']['used'] ) && is_numeric( $lic['sites']['used'] ) ) { // CHANGED:
					$sites_used = (int) $lic['sites']['used']; // CHANGED:
				}
				if ( isset( $lic['sites']['max'] ) && is_numeric( $lic['sites']['max'] ) ) { // CHANGED:
					$out['sites_max'] = (int) $lic['sites']['max']; // CHANGED:
				}
				if ( isset( $lic['sites']['unlimited'] ) ) { // CHANGED:
					$out['sites_unlimited'] = (bool) $lic['sites']['unlimited']; // CHANGED:
				}
			}

			// Back-compat site counting.
			if ( null === $sites_used && isset( $lic['active_sites'] ) && is_array( $lic['active_sites'] ) ) {
				$sites_used = count( $lic['active_sites'] );
			}
			if ( null === $sites_used && isset( $data['active_sites'] ) && is_array( $data['active_sites'] ) ) {
				$sites_used = count( $data['active_sites'] );
			}

			$numeric_keys = array( 'sites_used', 'site_count', 'used_sites', 'active_site_count' );
			if ( null === $sites_used ) {
				foreach ( $numeric_keys as $k ) {
					if ( isset( $lic[ $k ] ) && is_numeric( $lic[ $k ] ) ) {
						$sites_used = (int) $lic[ $k ];
						break;
					}
				}
			}
			if ( null === $sites_used && isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
				foreach ( $numeric_keys as $k ) {
					if ( isset( $data['usage'][ $k ] ) && is_numeric( $data['usage'][ $k ] ) ) {
						$sites_used = (int) $data['usage'][ $k ];
						break;
					}
				}
			}

			// If live activation but used count is missing/zero, treat as at least 1.
			if ( $out['activation_live'] ) {
				if ( null === $sites_used || $sites_used < 1 ) {
					$sites_used = 1;
				}
			}

			if ( null === $sites_used ) {
				$sites_used = 0;
			}
			$out['sites_used'] = (int) $sites_used;

			// Prefer nested sites.max/unlimited when present; otherwise fall back to top-level fields.
			if ( null !== $out['sites_max'] && $out['sites_max'] > 0 ) { // CHANGED:
				$out['max_sites'] = (int) $out['sites_max']; // CHANGED:
			} else {
				$out['sites_max'] = ( $out['max_sites'] > 0 ) ? (int) $out['max_sites'] : null; // CHANGED:
			}
			if ( $out['sites_unlimited'] ) { // CHANGED:
				$out['unlimited_sites'] = true; // CHANGED:
			} else {
				$out['sites_unlimited'] = (bool) $out['unlimited_sites']; // CHANGED:
			}

			/**
			 * TOKENS (license.v1 supports: data.license.tokens.monthly_limit / monthly_used / monthly_remaining / remaining_total / period.*)
			 */
			if ( isset( $lic['tokens'] ) && is_array( $lic['tokens'] ) ) { // CHANGED:
				$t = $lic['tokens']; // CHANGED:

				if ( isset( $t['monthly_used'] ) && is_numeric( $t['monthly_used'] ) ) { // CHANGED:
					$out['tokens_used'] = (int) $t['monthly_used']; // CHANGED:
				}
				if ( isset( $t['monthly_limit'] ) && is_numeric( $t['monthly_limit'] ) ) { // CHANGED:
					$out['tokens_limit'] = (int) $t['monthly_limit']; // CHANGED:
				}
				if ( isset( $t['monthly_remaining'] ) && is_numeric( $t['monthly_remaining'] ) ) { // CHANGED:
					$out['tokens_remaining'] = (int) $t['monthly_remaining']; // CHANGED:
				}
				if ( isset( $t['purchased_balance'] ) && is_numeric( $t['purchased_balance'] ) ) { // CHANGED:
					$out['tokens_purchased_balance'] = (int) $t['purchased_balance']; // CHANGED:
				}
				if ( isset( $t['remaining_total'] ) && is_numeric( $t['remaining_total'] ) ) { // CHANGED:
					$out['tokens_remaining_total'] = (int) $t['remaining_total']; // CHANGED:
				}

				if ( isset( $t['period'] ) && is_array( $t['period'] ) ) { // CHANGED:
					if ( isset( $t['period']['start'] ) && is_string( $t['period']['start'] ) ) { // CHANGED:
						$out['tokens_period_start'] = trim( $t['period']['start'] ); // CHANGED:
					}
					if ( isset( $t['period']['end'] ) && is_string( $t['period']['end'] ) ) { // CHANGED:
						$out['tokens_period_end'] = trim( $t['period']['end'] ); // CHANGED:
					}
				}
			}

			// Back-compat token keys if some older response used flat numeric keys.
			$tok_used_keys  = array( 'tokens_used', 'token_used', 'used_tokens' );
			$tok_limit_keys = array( 'tokens_limit', 'token_limit', 'max_tokens' );

			if ( null === $out['tokens_used'] ) {
				foreach ( $tok_used_keys as $k ) {
					if ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) {
						$out['tokens_used'] = (int) $data[ $k ];
						break;
					}
					if ( isset( $lic[ $k ] ) && is_numeric( $lic[ $k ] ) ) {
						$out['tokens_used'] = (int) $lic[ $k ];
						break;
					}
				}
			}
			if ( null === $out['tokens_limit'] ) {
				foreach ( $tok_limit_keys as $k ) {
					if ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) {
						$out['tokens_limit'] = (int) $data[ $k ];
						break;
					}
					if ( isset( $lic[ $k ] ) && is_numeric( $lic[ $k ] ) ) {
						$out['tokens_limit'] = (int) $lic[ $k ];
						break;
					}
				}
			}

			// Links (optional).
			if ( isset( $data['links'] ) && is_array( $data['links'] ) ) {
				if ( isset( $data['links']['account'] ) ) {
					$out['account_url'] = self::safe_http_url( $data['links']['account'] );
				}
				if ( isset( $data['links']['upgrade'] ) ) {
					$out['upgrade_url'] = self::safe_http_url( $data['links']['upgrade'] );
				}
			}

			return $out;
		}

		private static function render_plan_usage_card( $last ) { // CHANGED:
			$info = self::extract_plan_usage_from_last( $last ); // CHANGED:

			$plan_slug = self::fmt_slug( $info['plan_slug'] );
			$status    = is_string( $info['status'] ) ? trim( $info['status'] ) : '';
			$status    = ( '' !== $status ) ? $status : __( '(unknown)', 'postpress-ai' );

			$sites_used      = (int) $info['sites_used'];
			$sites_max       = $info['sites_max'];
			$sites_unlimited = (bool) $info['sites_unlimited'];

			$tokens_used            = $info['tokens_used'];
			$tokens_limit           = $info['tokens_limit'];
			$tokens_remaining       = $info['tokens_remaining'];
			$tokens_remaining_total = $info['tokens_remaining_total'];
			$tokens_period_start    = is_string( $info['tokens_period_start'] ) ? trim( $info['tokens_period_start'] ) : '';
			$tokens_period_end      = is_string( $info['tokens_period_end'] ) ? trim( $info['tokens_period_end'] ) : '';

			$sites_line = '';
			if ( $sites_unlimited ) {
				$sites_line = sprintf(
					/* translators: %s = used sites */
					__( '%s used (Unlimited)', 'postpress-ai' ),
					self::fmt_int( $sites_used )
				);
			} elseif ( null !== $sites_max && (int) $sites_max > 0 ) {
				$sites_line = sprintf(
					/* translators: 1: used sites 2: max sites */
					__( '%1$s of %2$s used', 'postpress-ai' ),
					self::fmt_int( $sites_used ),
					self::fmt_int( (int) $sites_max )
				);
			} else {
				$sites_line = sprintf(
					/* translators: %s = used sites */
					__( '%s used', 'postpress-ai' ),
					self::fmt_int( $sites_used )
				);
			}

			$token_line_main = __( 'Not available yet — click “Check License”.', 'postpress-ai' );
			if ( null !== $tokens_limit && is_numeric( $tokens_limit ) && (int) $tokens_limit > 0 ) {
				$token_line_main = sprintf(
					/* translators: 1: used 2: limit */
					__( '%1$s of %2$s used this month', 'postpress-ai' ),
					self::fmt_int( $tokens_used ),
					self::fmt_int( $tokens_limit )
				);
				if ( null !== $tokens_remaining && is_numeric( $tokens_remaining ) ) {
					$token_line_main .= ' — ' . sprintf(
						/* translators: %s = remaining tokens */
						__( '%s remaining', 'postpress-ai' ),
						self::fmt_int( $tokens_remaining )
					);
				}
			}

			$token_line_extra = '';
			if ( null !== $tokens_remaining_total && is_numeric( $tokens_remaining_total ) ) {
				$token_line_extra = sprintf(
					/* translators: %s = total remaining tokens */
					__( 'Total remaining: %s', 'postpress-ai' ),
					self::fmt_int( $tokens_remaining_total )
				);
			}

			$period_line = '';
			if ( '' !== $tokens_period_start && '' !== $tokens_period_end ) {
				$period_line = sprintf(
					/* translators: 1: period start 2: period end */
					__( 'Period: %1$s → %2$s', 'postpress-ai' ),
					esc_html( $tokens_period_start ),
					esc_html( $tokens_period_end )
				);
			}

			?>
			<div class="ppa-card ppa-card--plan"> <!-- # CHANGED: -->
				<h2 class="title"><?php esc_html_e( 'Plan & Usage', 'postpress-ai' ); ?></h2>
				<p class="ppa-help">
					<?php esc_html_e( 'These numbers come from your last license check. Click “Check License” to refresh.', 'postpress-ai' ); ?>
				</p>

				<table class="widefat striped" style="max-width: 820px;">
					<tbody>
						<tr>
							<th style="width: 220px;"><?php esc_html_e( 'Plan', 'postpress-ai' ); ?></th>
							<td><?php echo esc_html( $plan_slug ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'License Status', 'postpress-ai' ); ?></th>
							<td><?php echo esc_html( $status ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Sites', 'postpress-ai' ); ?></th>
							<td><?php echo esc_html( $sites_line ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Tokens', 'postpress-ai' ); ?></th>
							<td>
								<?php echo esc_html( $token_line_main ); ?>
								<?php if ( '' !== $token_line_extra ) : ?>
									<br><span class="description"><?php echo esc_html( $token_line_extra ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $period_line ) : ?>
									<br><span class="description"><?php echo esc_html( $period_line ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( '' !== $info['account_url'] || '' !== $info['upgrade_url'] ) : ?>
							<tr>
								<th><?php esc_html_e( 'Links', 'postpress-ai' ); ?></th>
								<td>
									<?php if ( '' !== $info['account_url'] ) : ?>
										<a href="<?php echo esc_url( $info['account_url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Account', 'postpress-ai' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( '' !== $info['upgrade_url'] ) : ?>
										<?php if ( '' !== $info['account_url'] ) : ?>
											&nbsp;•&nbsp;
										<?php endif; ?>
										<a href="<?php echo esc_url( $info['upgrade_url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Upgrade', 'postpress-ai' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		// === END: Plan/Usage helpers ===

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

			// FIX: Ensure WP admin button helpers exist (safe; no redeclare risk).
			if ( is_admin() && ! function_exists( 'submit_button' ) ) { // CHANGED:
				require_once ABSPATH . 'wp-admin/includes/template.php'; // CHANGED:
			}

			$last = get_transient( self::TRANSIENT_LAST_LIC );
			$last = self::seed_last_license_transient_if_missing( $last ); // CHANGED:
			self::sync_persisted_state_from_cached_last_result( $last );

			$val_license = (string) get_option( self::OPT_LICENSE_KEY, '' );
			$val_license = self::sanitize_license_key( $val_license );

			$has_key            = ( '' !== $val_license );
			$activation_state   = self::derive_activation_state( $last );
			$is_active_here     = ( 'active' === $activation_state );
			$is_inactive_here   = ( 'inactive' === $activation_state );
			$site_limit_reached = self::is_plan_limit_site_limit_reached( $last );

			$ck = self::detect_connection_key_info();

			$banner = self::build_settings_banner( $has_key, $activation_state, $site_limit_reached );

			?>
			<div class="wrap ppa-admin ppa-settings">
				<h1><?php esc_html_e( 'PostPress AI Settings', 'postpress-ai' ); ?></h1>

				<div class="ppa-card ppa-card--setup">
					<?php self::render_notice( $banner['type'], $banner['msg'] ); ?>

					<h2 class="title"><?php esc_html_e( 'Setup', 'postpress-ai' ); ?></h2>
					<p class="ppa-help"><?php esc_html_e( 'Paste your license key below, then click Save.', 'postpress-ai' ); ?></p>

					<?php if ( ! empty( $ck['present'] ) ) : ?>
						<p class="ppa-help">
							<strong><?php esc_html_e( 'Heads up:', 'postpress-ai' ); ?></strong>
							<?php echo esc_html( sprintf(
								__( 'A Connection Key is detected from %1$s (%2$s). Customers usually don’t need this. If Settings says “Not active” but Composer still works, this is why.', 'postpress-ai' ),
								(string) $ck['source'],
								(string) $ck['masked']
							) ); ?>
						</p>
					<?php endif; ?>

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

				<div class="ppa-card ppa-card--license">
					<h2 class="title"><?php esc_html_e( 'License Actions', 'postpress-ai' ); ?></h2>
					<p class="ppa-help">
						<?php esc_html_e( 'Use these buttons to check or activate this site.', 'postpress-ai' ); ?>
					</p>

					<?php if ( $is_active_here ) : ?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--active"><?php esc_html_e( 'Active on this site', 'postpress-ai' ); ?></span></p>
					<?php elseif ( $is_inactive_here ) : ?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--inactive"><?php esc_html_e( 'Not active', 'postpress-ai' ); ?></span></p>
					<?php else : ?>
						<p class="ppa-help"><strong><?php esc_html_e( 'Status:', 'postpress-ai' ); ?></strong> <span class="ppa-badge ppa-badge--unknown"><?php esc_html_e( 'Unknown (run Check License)', 'postpress-ai' ); ?></span></p>
					<?php endif; ?>

					<div class="ppa-actions-row">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-verify' ); ?>
							<input type="hidden" name="action" value="ppa_license_verify" />
							<?php
							$disable_verify = ( ! $has_key );
							$attrs_verify   = $disable_verify ? array( 'disabled' => 'disabled' ) : array();
							submit_button( __( 'Check License', 'postpress-ai' ), 'secondary', 'ppa_license_verify_btn', false, $attrs_verify );
							?>
							<?php if ( $disable_verify ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first.', 'postpress-ai' ); ?></p>
							<?php endif; ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-activate' ); ?>
							<input type="hidden" name="action" value="ppa_license_activate" />
							<?php
							$disable_activate = ( ! $has_key ) || $is_active_here || $site_limit_reached;
							$attrs            = $disable_activate ? array( 'disabled' => 'disabled' ) : array();
							submit_button( __( 'Activate This Site', 'postpress-ai' ), 'primary', 'ppa_license_activate_btn', false, $attrs );
							?>
							<?php if ( ! $has_key ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first. Then you can activate.', 'postpress-ai' ); ?></p>
							<?php elseif ( $is_active_here ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'This site is already active.', 'postpress-ai' ); ?></p>
							<?php elseif ( $site_limit_reached ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Plan limit reached. Upgrade your plan or deactivate another site, then try again.', 'postpress-ai' ); ?></p>
							<?php endif; ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ppa-action-form">
							<?php wp_nonce_field( 'ppa-license-deactivate' ); ?>
							<input type="hidden" name="action" value="ppa_license_deactivate" />
							<?php
							$disable_deactivate = ( ! $has_key ) || $is_inactive_here;
							$attrs_deactivate   = $disable_deactivate ? array( 'disabled' => 'disabled' ) : array();
							submit_button( __( 'Deactivate This Site', 'postpress-ai' ), 'delete', 'ppa_license_deactivate_btn', false, $attrs_deactivate );
							?>
							<?php if ( ! $has_key ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first.', 'postpress-ai' ); ?></p>
							<?php elseif ( $is_inactive_here ) : ?>
								<p class="description ppa-inline-help"><?php esc_html_e( 'This site is not active right now.', 'postpress-ai' ); ?></p>
							<?php endif; ?>
						</form>
					</div>

					<h3><?php esc_html_e( 'Last response (optional)', 'postpress-ai' ); ?></h3>
					<p class="ppa-help"><?php esc_html_e( 'Only for troubleshooting if something fails. Click “Check License” to refresh.', 'postpress-ai' ); ?></p>
					<textarea readonly class="ppa-debug-box"><?php
						echo esc_textarea( $last ? wp_json_encode( $last, JSON_PRETTY_PRINT ) : 'No recent result yet.' );
					?></textarea>
				</div>

				<div class="ppa-card ppa-card--test">
					<h2 class="title"><?php esc_html_e( 'Test Connection', 'postpress-ai' ); ?></h2>
					<p class="ppa-help">
						<?php esc_html_e( 'Click to make sure this site can reach PostPress AI.', 'postpress-ai' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ppa-test-connectivity' ); ?>
						<input type="hidden" name="action" value="ppa_test_connectivity" />
						<?php
						$disable_test = ( ! $has_key );
						$attrs_test   = $disable_test ? array( 'disabled' => 'disabled' ) : array();
						submit_button( __( 'Test Connection', 'postpress-ai' ), 'secondary', 'ppa_test_connectivity_btn', false, $attrs_test );
						?>
						<?php if ( $disable_test ) : ?>
							<p class="description ppa-inline-help"><?php esc_html_e( 'Save your license key first.', 'postpress-ai' ); ?></p>
						<?php endif; ?>
					</form>
				</div>

				<?php self::render_plan_usage_card( $last ); // CHANGED: ?>

			</div>
			<?php
		}
	}

	// Only initialize hooks. DO NOT render output during include.
	PPA_Admin_Settings::init();
}
