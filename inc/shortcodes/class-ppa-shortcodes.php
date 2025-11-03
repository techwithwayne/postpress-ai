<?php
// /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/inc/shortcodes/class-ppa-shortcodes.php
/**
 * PostPress AI — Frontend Shortcodes
 *
 * ========= CHANGE LOG =========
 * 2025-11-03: New file. Adds [postpress_ai_preview] shortcode scaffold with asset hooks.     // CHANGED:
 *             - No inline JS/CSS; enqueues 'ppa-frontend' script/style when shortcode used.  // CHANGED:
 *             - Progressive HTML markup with data-attrs for JS to enhance.                   // CHANGED:
 *             - Uses admin-ajax endpoint action=ppa_preview (frontend public preview).       // CHANGED:
 *             - Defensive namespace + static init() registration.                            // CHANGED:
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
	} // CHANGED:

	/**
	 * Register all shortcodes.                                                                // CHANGED:
	 */
	public static function register_shortcodes() { // CHANGED:
		add_shortcode( 'postpress_ai_preview', [ __CLASS__, 'render_preview' ] ); // CHANGED:
	} // CHANGED:

	/**
	 * Ensure assets are registered; enqueue only when shortcode is present.                   // CHANGED:
	 */
	private static function ensure_assets() { // CHANGED:
		$plug_url = plugins_url( '', dirname( __DIR__, 2 ) . '/postpress-ai.php' ); // CHANGED:
		$ver      = defined( 'PPA_PLUGIN_VER' ) ? PPA_PLUGIN_VER : 'dev';           // CHANGED:

		// Register (do not enqueue globally).                                                  // CHANGED:
		if ( ! wp_script_is( 'ppa-frontend', 'registered' ) ) { // CHANGED:
			wp_register_script(
				'ppa-frontend',
				$plug_url . '/assets/js/ppa-frontend.js',   // to be added in a later turn            // CHANGED:
				[ 'wp-i18n' ],                               // keep deps minimal                       // CHANGED:
				$ver,
				true
			);
		}
		if ( ! wp_style_is( 'ppa-frontend', 'registered' ) ) { // CHANGED:
			wp_register_style(
				'ppa-frontend',
				$plug_url . '/assets/css/ppa-frontend.css', // optional; may 404 until added          // CHANGED:
				[],
				$ver
			);
		}
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

		// Markup contract:                                                                     // CHANGED:
		// - .ppa-frontend wraps the widget                                                     // CHANGED:
		// - .ppa-frontend__form holds inputs (prefilled by data- attrs; JS may render UI)      // CHANGED:
		// - .ppa-frontend__preview renders returned HTML                                       // CHANGED:
		// - .ppa-frontend__msg for notices                                                      // CHANGED:
		ob_start(); // CHANGED:
		?>
<div class="ppa-frontend" <?php echo $attrs_html; // phpcs:ignore ?>>
	<div class="ppa-frontend__form" aria-live="polite">
		<!-- Frontend JS enhances this area; no inline scripts. -->                             
		<form class="ppa-frontend__form-inner" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php?action=ppa_preview' ) ); ?>">
			<label>
				<span>Subject</span>
				<input type="text" name="subject" value="<?php echo esc_attr( $atts['subject'] ); ?>" />
			</label>
			<label>
				<span>Brief</span>
				<textarea name="brief" rows="3"><?php echo esc_textarea( $atts['brief'] ); ?></textarea>
			</label>
			<div class="ppa-frontend__row">
				<label>
					<span>Genre</span>
					<input type="text" name="genre" value="<?php echo esc_attr( $atts['genre'] ); ?>" />
				</label>
				<label>
					<span>Tone</span>
					<input type="text" name="tone" value="<?php echo esc_attr( $atts['tone'] ); ?>" />
				</label>
				<label>
					<span>Word Count</span>
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
