<?php
// /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/inc/shortcodes/class-ppa-shortcodes.php
/**
 * PostPress AI — Frontend Shortcodes
 *
 * ========= CHANGE LOG =========
 * 2025-11-03: Register assets on wp_enqueue_scripts so wp_*_is('ppa-frontend','registered')         // CHANGED:
 *             works before the first render; keep enqueue on render only.                           // CHANGED:
 * 2025-11-03: New file. Adds [postpress_ai_preview] shortcode scaffold with asset hooks.            // CHANGED:
 *             - No inline JS/CSS; enqueues 'ppa-frontend' script/style when shortcode used.        // CHANGED:
 *             - Progressive HTML markup with data-attrs for JS to enhance.                         // CHANGED:
 *             - Uses admin-ajax endpoint action=ppa_preview (frontend public preview).              // CHANGED:
 *             - Defensive namespace + static init() registration.                                   // CHANGED:
 * ==============================
 */

namespace PPA\Shortcodes; // CHANGED:

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

class PPAShortcodes { // CHANGED:

	/**
	 * Register hooks. Call PPAShortcodes::init() from the plugin bootstrap.                   // CHANGED:
	 */
	public static function init() { // CHANGED:
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ] ); // CHANGED:
		// Ensure assets are REGISTERED early on the front-end (not enqueued) so                  // CHANGED:
		// wp_script_is/wp_style_is(...,'registered') checks pass before rendering.               // CHANGED:
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_assets' ], 5 );         // CHANGED:
	} // CHANGED:

	/**
	 * Register all shortcodes.                                                                // CHANGED:
	 */
	public static function register_shortcodes() { // CHANGED:
		add_shortcode( 'postpress_ai_preview', [ __CLASS__, 'render_preview' ] ); // CHANGED:
	} // CHANGED:

	/**
	 * Compute plugin base URL for assets robustly.                                            // CHANGED:
	 * Avoids brittle paths if plugin folder name differs.                                     // CHANGED:
	 *
	 * @return string
	 */
	private static function plugin_base_url() { // CHANGED:
		// __DIR__ = .../inc/shortcodes ; go up two levels to plugin root.                       // CHANGED:
		$root_file = dirname( __DIR__, 2 ) . '/postpress-ai.php';                                // CHANGED:
		return plugins_url( '', $root_file );                                                    // CHANGED:
	} // CHANGED:

	/**
	 * Register (but do not enqueue) public assets so checks like wp_style_is(...,'registered') // CHANGED:
	 * succeed even before a shortcode render.                                                 // CHANGED:
	 */
	public static function register_frontend_assets() { // CHANGED:
		$plug_url = self::plugin_base_url();                                                   // CHANGED:
		$ver      = defined( 'PPA_PLUGIN_VER' ) ? PPA_PLUGIN_VER : 'dev';                      // CHANGED:

		if ( ! wp_script_is( 'ppa-frontend', 'registered' ) ) {                                // CHANGED:
			wp_register_script(
				'ppa-frontend',
				$plug_url . '/assets/js/ppa-frontend.js',
				[ 'wp-i18n' ],
				$ver,
				true
			);
		}
		if ( ! wp_style_is( 'ppa-frontend', 'registered' ) ) {                                 // CHANGED:
			wp_register_style(
				'ppa-frontend',
				$plug_url . '/assets/css/ppa-frontend.css',
				[],
				$ver
			);
		}
	} // CHANGED:

	/**
	 * (Kept) Ensure assets are registered before enqueue on render.                           // CHANGED:
	 */
	private static function ensure_assets() { // CHANGED:
		self::register_frontend_assets();                                                      // CHANGED:
	} // CHANGED:

	/**
	 * Shortcode: [postpress_ai_preview subject="" brief="" genre="" tone="" word_count=""]    // CHANGED:
	 *
	 * Renders a progressive-enhancement container. No inline JS/CSS per project rules.        // CHANGED:
	 * Frontend JS (ppa-frontend.js) will POST to admin-ajax.php?action=ppa_preview.           // CHANGED:
	 *
	 * @param array $atts Shortcode attributes.                                                // CHANGED:
	 * @return string HTML markup.                                                             // CHANGED:
	 */
	public static function render_preview( $atts ) { // CHANGED:
		self::ensure_assets();                          // CHANGED:
		wp_enqueue_script( 'ppa-frontend' );           // CHANGED:
		if ( wp_style_is( 'ppa-frontend', 'registered' ) ) {
			wp_enqueue_style( 'ppa-frontend' );        // CHANGED:
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
		); // CHANGED:

		// Build safe data attributes for the container.                                       // CHANGED:
		$data_attrs = [
			'data-ppa-action'    => esc_attr( 'ppa_preview' ),
			'data-ppa-ajaxurl'   => esc_url( admin_url( 'admin-ajax.php' ) ),
			'data-ppa-subject'   => esc_attr( $atts['subject'] ),
			'data-ppa-brief'     => esc_attr( $atts['brief'] ),
			'data-ppa-genre'     => esc_attr( $atts['genre'] ),
			'data-ppa-tone'      => esc_attr( $atts['tone'] ),
			'data-ppa-wordcount' => esc_attr( $atts['word_count'] ),
		]; // CHANGED:

		$attrs_html = '';
		foreach ( $data_attrs as $k => $v ) {           // CHANGED:
			$attrs_html .= sprintf( ' %s="%s"', $k, $v ); // CHANGED:
		} // CHANGED:

		ob_start(); // CHANGED:
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
		return trim( (string) ob_get_clean() ); // CHANGED:
	} // CHANGED:
} // CHANGED:

// phpcs:enable
