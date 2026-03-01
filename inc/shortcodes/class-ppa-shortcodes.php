<?php
// /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/inc/shortcodes/class-ppa-shortcodes.php
/**
 * PostPress AI — Frontend Shortcodes
 *
 * ========= CHANGE LOG =========
 * 2025-11-09: Add 'ppa-frontend-config' inline script that safely merges into window.PPA             // CHANGED:
 *             (ajaxUrl, restUrl, page, nonce). Make 'ppa-frontend' depend on that config.            // CHANGED:
 *             Keep early registration; enqueue only on render. No inline JS/CSS in HTML.             // CHANGED:
 * 2025-11-03: Register assets on wp_enqueue_scripts so wp_*_is('ppa-frontend','registered') works.
 * 2025-11-03: New file. Adds [postpress_ai_preview] shortcode scaffold with asset hooks.
 */

namespace PPA\Shortcodes; // keep namespace

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

class PPAShortcodes {

	/**
	 * Register hooks. Call PPAShortcodes::init() from the plugin bootstrap.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ] );
		// Register (not enqueue) public assets early so wp_*_is(...,'registered') works.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_assets' ], 5 );
	}

	/**
	 * Register all shortcodes.
	 */
	public static function register_shortcodes() {
		add_shortcode( 'postpress_ai_preview', [ __CLASS__, 'render_preview' ] );
	}

	/**
	 * Compute plugin base URL for assets robustly.
	 * Avoids brittle paths if plugin folder name differs.
	 *
	 * @return string
	 */
	private static function plugin_base_url() {
		// __DIR__ = .../inc/shortcodes ; go up two levels to plugin root.
		$root_file = dirname( __DIR__, 2 ) . '/postpress-ai.php';
		return plugins_url( '', $root_file );
	}

	/**
	 * Register (but do not enqueue) public assets so checks like wp_style_is(...,'registered')
	 * succeed even before a shortcode render.
	 */
	public static function register_frontend_assets() {
		$plug_url = self::plugin_base_url();
		$ver      = defined( 'PPA_PLUGIN_VER' ) ? PPA_PLUGIN_VER : 'dev';

		// Config carrier (inline only; no src)
		if ( ! wp_script_is( 'ppa-frontend-config', 'registered' ) ) {                                      // CHANGED:
			wp_register_script( 'ppa-frontend-config', false, [], null, true );                             // CHANGED:
		}                                                                                                   // CHANGED:

		// Main frontend JS depends on the config so window.PPA exists first.
		if ( ! wp_script_is( 'ppa-frontend', 'registered' ) ) {
			wp_register_script(
				'ppa-frontend',
				$plug_url . '/assets/js/ppa-frontend.js',
				[ 'ppa-frontend-config', 'wp-i18n' ],                                                      // CHANGED:
				$ver,
				true
			);
		}
		if ( ! wp_style_is( 'ppa-frontend', 'registered' ) ) {
			wp_register_style(
				'ppa-frontend',
				$plug_url . '/assets/css/ppa-frontend.css',
				[],
				$ver
			);
		}
	}

	/**
	 * Ensure assets are registered before enqueue on render.
	 */
	private static function ensure_assets() {
		self::register_frontend_assets();
	}

	/**
	 * Inject a minimal, safe window.PPA config for the frontend,
	 * merged (not overwritten) so admin screens and other code paths coexist.
	 */
	private static function inline_frontend_config() {                                                        // CHANGED:
		$cfg = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'restUrl' => esc_url_raw( rest_url() ),
			'page'    => 'shortcode',
			'nonce'   => wp_create_nonce( 'ppa-admin' ),
		];
		$js = '(function(w){var C=' . wp_json_encode( $cfg ) . ';w.PPA=w.PPA||{};for(var k in C){w.PPA[k]=C[k];}})(window);';
		// Ensure the carrier handle exists and then print the inline before main script.
		wp_enqueue_script( 'ppa-frontend-config' );                                                           // CHANGED:
		wp_add_inline_script( 'ppa-frontend-config', $js, 'before' );                                         // CHANGED:
	}

	/**
	 * Shortcode: [postpress_ai_preview subject="" brief="" genre="" tone="" word_count=""]
	 *
	 * Renders a progressive-enhancement container. No inline JS/CSS per project rules.
	 * Frontend JS (ppa-frontend.js) will POST to admin-ajax.php?action=ppa_preview.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML markup.
	 */
	public static function render_preview( $atts ) {
		self::ensure_assets();
		self::inline_frontend_config();                                                                       // CHANGED:
		wp_enqueue_script( 'ppa-frontend' );
		if ( wp_style_is( 'ppa-frontend', 'registered' ) ) {
			wp_enqueue_style( 'ppa-frontend' );
		}

		$atts = shortcode_atts(
			[
				'subject'    => '',
				'brief'      => '',
				'genre'      => '',
				'tone'       => '',
				'word_count' => '',
			],
			$atts,
			'postpress_ai_preview'
		);

		// Build safe data attributes for the container.
		$data_attrs = [
			'data-ppa-action'    => esc_attr( 'ppa_preview' ),
			'data-ppa-ajaxurl'   => esc_url( admin_url( 'admin-ajax.php' ) ),
			'data-ppa-subject'   => esc_attr( $atts['subject'] ),
			'data-ppa-brief'     => esc_attr( $atts['brief'] ),
			'data-ppa-genre'     => esc_attr( $atts['genre'] ),
			'data-ppa-tone'      => esc_attr( $atts['tone'] ),
			'data-ppa-wordcount' => esc_attr( $atts['word_count'] ),
		];

		$attrs_html = '';
		foreach ( $data_attrs as $k => $v ) {
			$attrs_html .= sprintf( ' %s="%s"', $k, $v );
		}

		ob_start();
		?>
<div class="ppa-frontend"<?php echo $attrs_html; // phpcs:ignore ?>>
	<div class="ppa-frontend__form" aria-live="polite">
		<!-- Frontend JS enhances this area; no inline scripts. -->
		<form class="ppa-frontend__form-inner" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php?action=ppa_preview' ) ); ?>">
			<label>
				<span><?php esc_html_e( 'Subject', 'postpress-ai' ); ?></span>
				<input type="text" name="subject" value="<?php echo esc_attr( $atts['subject'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Brief', 'postpress-ai' ); ?></span>
				<textarea name="brief" rows="3"><?php echo esc_textarea( $atts['brief'] ); ?></textarea>
			</label>
			<div class="ppa-frontend__row">
				<label>
					<span><?php esc_html_e( 'Genre', 'postpress-ai' ); ?></span>
					<input type="text" name="genre" value="<?php echo esc_attr( $atts['genre'] ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Tone', 'postpress-ai' ); ?></span>
					<input type="text" name="tone" value="<?php echo esc_attr( $atts['tone'] ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Word Count', 'postpress-ai' ); ?></span>
					<input type="number" name="word_count" value="<?php echo esc_attr( $atts['word_count'] ); ?>" min="0" step="50" />
				</label>
			</div>
			<button type="submit" class="ppa-frontend__btn"><?php esc_html_e( 'Preview', 'postpress-ai' ); ?></button>
		</form>
	</div>
	<div class="ppa-frontend__msg" role="status" aria-live="polite"></div>
	<div class="ppa-frontend__preview"></div>
</div>
		<?php
		return trim( (string) ob_get_clean() );
	}
}

// phpcs:enable
