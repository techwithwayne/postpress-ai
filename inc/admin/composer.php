<?php
/**
 * PostPress AI — Composer Screen (Admin UI)
 * Path: inc/admin/composer.php
 *
 * ========= CHANGE LOG =========
 * 2026-01-24 — FIX: Remove invalid HTML comments inside the Word Count <input> tag (was rendering attributes as text). // CHANGED:
 *            — ADD: Word Count defaults wired cleanly (default 800, min 300, max 1200) with user-visible helper.       // CHANGED:
 *
 * 2026-01-22 — UI: Expand Genre + Tone dropdown options (markup only; no CSS changes).                 // CHANGED:
 *            — Copy: Helper note now references Genre + Tone (still one unified block).               // CHANGED:
 *
 * 2026-01-21 — UI: Consolidate Genre/Tone/Word Count helper text into ONE spanning helper block.            // CHANGED:
 *            — UI: Move “Show Outline” checkbox into Preview header, right-aligned beside “Preview”.       // CHANGED:
 *            — NOTE: Composer CSS is “gospel” — markup-only changes here; CSS additions (if needed) later. // CHANGED:
 *
 * 2026-01-09 — HARDEN: Add safe Composer DOM data attrs (data-ppa-view, data-ppa-site-url) for admin.js parity. // CHANGED:
 *            - NO secrets exposed (no license key in HTML).                                                            // CHANGED:
 *            - NO UI/CSS changes.                                                                                       // CHANGED:
 *
 * 2026-01-07 — FIX: Match locked Composer button labels: “Generate Preview” + “Save Draft (Store)”. // CHANGED:
 * 2026-01-07: FIX: Composer button labels align with product + JS behavior:
 *            - "Generate Preview" (calls /generate/ pipeline and renders preview)
 *            - "Save Draft (Store)" (stores as WP draft via /store/ pipeline)                         // CHANGED:
 *            Remove outdated in-UI copy mentioning "Generate Preview" and "Publish".                    // CHANGED:
 *
 * 2026-01-02 — CLEAN: Remove routine composer render debug.log line (keep logs for real failures only). // CHANGED:
 *
 * 2025-11-11 — Add Advanced fields (#ppa-title, #ppa-excerpt, #ppa-slug) for admin.js autofill/store parity.
 * 2025-11-10 — UI polish: make Preview primary (accent) button; localize H1 text.
 * 2025-11-09 — Remove hardcoded <link> CSS fallback; centralized enqueue owns styles.
 * 2025-11-09 — Update H1 to "PostPress Composer" for menu consistency.
 * 2025-11-08 — Strip inline <style>; rely on assets/css/admin.css (legacy).
 * 2025-11-08 — Add #ppa-toolbar-msg live region for notices from admin.js.
 * 2025-10-31 — Removed inline <script>; events handled by admin.js.
 * 2025-10-19 — Added "Preview" heading to right pane.
 *
 * Notes:
 * - No inline CSS; centralized enqueue supplies admin-composer.css on the Composer screen only.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Nonce for AJAX headers (validated server-side where applicable)
$ppa_nonce    = wp_create_nonce( 'ppa-admin' );
$current_user = wp_get_current_user();

// CHANGED: Safe DOM attrs for admin.js parity (NO secrets). Keep this in-sync with JS expectations.
$ppa_view = 'composer'; // CHANGED:
$ppa_site_url = home_url( '/' ); // CHANGED:
if ( function_exists( 'set_url_scheme' ) ) { // CHANGED:
	$ppa_site_url = set_url_scheme( $ppa_site_url, 'https' ); // CHANGED:
} // CHANGED:

// CHANGED: Centralized option lists (markup-only; does NOT affect CSS).
// Values are stable machine slugs; labels are translated display strings. // CHANGED:
$ppa_genre_options = array( // CHANGED:
	''               => __( 'Auto', 'postpress-ai' ), // CHANGED:
	'howto'          => __( 'How-to', 'postpress-ai' ), // CHANGED:
	'listicle'       => __( 'Listicle', 'postpress-ai' ), // CHANGED:
	'news'           => __( 'News', 'postpress-ai' ), // CHANGED:
	'review'         => __( 'Review', 'postpress-ai' ), // CHANGED:
	'background'     => __( 'Background', 'postpress-ai' ), // CHANGED:
	'opinion'        => __( 'Opinion', 'postpress-ai' ), // CHANGED:
	'comparison'     => __( 'Comparison', 'postpress-ai' ), // CHANGED:
	'case_study'     => __( 'Case Study', 'postpress-ai' ), // CHANGED:
	'faq'            => __( 'FAQ', 'postpress-ai' ), // CHANGED:
	'tutorial'       => __( 'Tutorial', 'postpress-ai' ), // CHANGED:
	'guide'          => __( 'Guide', 'postpress-ai' ), // CHANGED:
	'checklist'      => __( 'Checklist', 'postpress-ai' ), // CHANGED:
	'troubleshoot'   => __( 'Troubleshooting', 'postpress-ai' ), // CHANGED:
	'announcement'   => __( 'Announcement', 'postpress-ai' ), // CHANGED:
	'product_update' => __( 'Product Update', 'postpress-ai' ), // CHANGED:
	'newsletter'     => __( 'Email Newsletter', 'postpress-ai' ), // CHANGED:
	'landing_page'   => __( 'Landing Page Copy', 'postpress-ai' ), // CHANGED:
	'social_post'    => __( 'Social Post', 'postpress-ai' ), // CHANGED:
	'video_script'   => __( 'Video Script', 'postpress-ai' ), // CHANGED:
	'podcast_outline'=> __( 'Podcast Outline', 'postpress-ai' ), // CHANGED:
); // CHANGED:

$ppa_tone_options = array( // CHANGED:
	''               => __( 'Auto', 'postpress-ai' ), // CHANGED:
	'casual'         => __( 'Casual', 'postpress-ai' ), // CHANGED:
	'friendly'       => __( 'Friendly', 'postpress-ai' ), // CHANGED:
	'professional'   => __( 'Professional', 'postpress-ai' ), // CHANGED:
	'technical'      => __( 'Technical', 'postpress-ai' ), // CHANGED:
	'confident'      => __( 'Confident', 'postpress-ai' ), // CHANGED:
	'calm'           => __( 'Calm', 'postpress-ai' ), // CHANGED:
	'clear'          => __( 'Clear', 'postpress-ai' ), // CHANGED:
	'direct'         => __( 'Direct', 'postpress-ai' ), // CHANGED:
	'conversational' => __( 'Conversational', 'postpress-ai' ), // CHANGED:
	'helpful'        => __( 'Helpful', 'postpress-ai' ), // CHANGED:
	'empathetic'     => __( 'Empathetic', 'postpress-ai' ), // CHANGED:
	'encouraging'    => __( 'Encouraging', 'postpress-ai' ), // CHANGED:
	'authoritative'  => __( 'Authoritative', 'postpress-ai' ), // CHANGED:
	'educational'    => __( 'Educational', 'postpress-ai' ), // CHANGED:
	'persuasive'     => __( 'Persuasive', 'postpress-ai' ), // CHANGED:
	'storytelling'   => __( 'Storytelling', 'postpress-ai' ), // CHANGED:
	'inspirational'  => __( 'Inspirational', 'postpress-ai' ), // CHANGED:
	'humorous'       => __( 'Humorous', 'postpress-ai' ), // CHANGED:
	'urgent'         => __( 'Urgent', 'postpress-ai' ), // CHANGED:
	'cautious'       => __( 'Cautious', 'postpress-ai' ), // CHANGED:
	'luxury'         => __( 'Luxury', 'postpress-ai' ), // CHANGED:
	'minimal'        => __( 'Minimal', 'postpress-ai' ), // CHANGED:
	'bold'           => __( 'Bold', 'postpress-ai' ), // CHANGED:
	'warm'           => __( 'Warm', 'postpress-ai' ), // CHANGED:
	'neutral'        => __( 'Neutral', 'postpress-ai' ), // CHANGED:
); // CHANGED:

?>
<!-- (No inline CSS; centralized enqueue supplies admin-composer.css and admin.js) -->

<div
	class="wrap ppa-composer-wrap"
	id="ppa-composer"
	data-ppa-nonce="<?php echo esc_attr( $ppa_nonce ); ?>"
	data-ppa-view="<?php echo esc_attr( $ppa_view ); ?>"
	data-ppa-site-url="<?php echo esc_url( $ppa_site_url ); ?>"
>

	<div class="ppa-form-panel" aria-label="<?php echo esc_attr__( 'PostPress AI Composer', 'postpress-ai' ); ?>">
		<h1><?php echo esc_html__( 'PostPress Composer', 'postpress-ai' ); ?></h1>
		<p class="ppa-hint">
			<?php
			/* translators: %s: current user display name */
			printf(
				esc_html__( 'Signed in as %s.', 'postpress-ai' ),
				esc_html( $current_user->display_name ?: $current_user->user_login )
			);
			?>
		</p>

		<!-- Live notice region consumed by admin.js -->
		<div id="ppa-toolbar-msg" class="ppa-notice" role="status" aria-live="polite"></div>

		<div class="ppa-form-group">
			<label for="ppa-subject"><?php echo esc_html__( 'Subject / Title', 'postpress-ai' ); ?></label>
			<input type="text" id="ppa-subject" placeholder="<?php echo esc_attr__( 'What is this post about?', 'postpress-ai' ); ?>">
		</div>

		<div class="ppa-form-group">
			<label for="ppa-audience"><?php echo esc_html__( 'Target audience', 'postpress-ai' ); ?></label>
			<input
				type="text"
				id="ppa-audience"
				placeholder="<?php echo esc_attr__( 'e.g. busy small business owners in Iowa', 'postpress-ai' ); ?>"
			/>
		</div>

		<div class="ppa-inline">
			<div class="ppa-form-group">
				<label for="ppa-genre"><?php echo esc_html__( 'Genre', 'postpress-ai' ); ?></label>
				<select id="ppa-genre">
					<?php foreach ( $ppa_genre_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ppa-form-group">
				<label for="ppa-tone"><?php echo esc_html__( 'Tone', 'postpress-ai' ); ?></label>
				<select id="ppa-tone">
					<?php foreach ( $ppa_tone_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ppa-form-group">
				<label for="ppa-word-count"><?php echo esc_html__( 'Word Count (Preview)', 'postpress-ai' ); ?></label>

				<?php
				// IMPORTANT: Do NOT place HTML comments inside the <input ...> tag. It breaks parsing and prints attributes as text. // CHANGED:
				?>

				<input
					type="number"
					id="ppa-word-count"
					min="300"
					max="1200"
					step="50"
					inputmode="numeric"
					value="800"
					placeholder="800"
					data-default="800"
					data-min="300"
					data-max="1200"
				/>
			</div>
		</div>

		<?php /* One unified helper block spanning under the 3 dropdown/inputs */ ?>
		<p class="ppa-inline-help ppa-inline-help--span">
			<?php echo esc_html__( 'Auto chooses the best-fit genre + tone from your subject + audience. Word count default: 800 (minimum: 300, max: 1,200).', 'postpress-ai' ); ?> <!-- CHANGED: -->
		</p>

		<div class="ppa-form-group">
			<label for="ppa-brief"><?php echo esc_html__( 'Optional brief / extra instructions', 'postpress-ai' ); ?></label>
			<textarea id="ppa-brief" rows="6" placeholder="<?php echo esc_attr__( 'Any details, links, or constraints you want the AI to follow.', 'postpress-ai' ); ?>"></textarea>
		</div>

		<!-- Advanced (optional) fields wired to admin.js autofill/store -->
		<details class="ppa-advanced">
			<summary><?php echo esc_html__( 'Advanced (optional)', 'postpress-ai' ); ?></summary>

			<div class="ppa-form-group">
				<label for="ppa-title"><?php echo esc_html__( 'Title (override)', 'postpress-ai' ); ?></label>
				<input type="text" id="ppa-title" placeholder="<?php echo esc_attr__( 'Auto-filled after Generate Preview', 'postpress-ai' ); ?>">
			</div>

			<div class="ppa-form-group">
				<label for="ppa-excerpt"><?php echo esc_html__( 'Excerpt (optional)', 'postpress-ai' ); ?></label>
				<textarea id="ppa-excerpt" rows="3" placeholder="<?php echo esc_attr__( 'Auto-filled after Generate Preview', 'postpress-ai' ); ?>"></textarea>
			</div>

			<div class="ppa-form-group">
				<label for="ppa-slug"><?php echo esc_html__( 'Slug (optional)', 'postpress-ai' ); ?></label>
				<input type="text" id="ppa-slug" placeholder="<?php echo esc_attr__( 'auto-generated-from-title', 'postpress-ai' ); ?>">
			</div>
		</details>

		<div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Composer actions', 'postpress-ai' ); ?>">

			<!-- Legacy buttons kept hidden for compatibility (admin.js may still reference IDs defensively) -->
			<button id="ppa-preview" class="ppa-btn ppa-btn-primary" type="button" style="display:none !important;">
				<?php echo esc_html__( 'Preview', 'postpress-ai' ); ?>
			</button>

			<button id="ppa-generate" class="ppa-btn ppa-btn-secondary" type="button">
				<?php echo esc_html__( 'Generate Preview', 'postpress-ai' ); ?>
			</button>

			<button id="ppa-draft" class="ppa-btn ppa-btn-secondary" type="button">
				<?php echo esc_html__( 'Save Draft (Store)', 'postpress-ai' ); ?>
			</button>

			<button id="ppa-publish" class="ppa-btn ppa-btn-secondary" type="button" style="display:none !important;">
				<?php echo esc_html__( 'Publish', 'postpress-ai' ); ?>
			</button>

			<span class="ppa-note">
				<?php
				echo esc_html__(
					'“Generate Preview” talks to the AI backend and shows the draft + SEO meta on the right. “Save Draft (Store)” saves it as a WordPress draft.',
					'postpress-ai'
				);
				?>
			</span>
		</div>
	</div>

	<div class="ppa-preview-panel" aria-label="<?php echo esc_attr__( 'Preview panel', 'postpress-ai' ); ?>">
		<div class="ppa-preview-header">
			<h1><?php echo esc_html__( 'Preview', 'postpress-ai' ); ?></h1>

			<!-- Header tools (NEW layout): language inline, LEFT of outline -->
			<div class="ppa-preview-tools" style="margin-left:auto;display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<!-- Output Language (NEW) -->
				<label class="ppa-output-language" for="ppa-output-language" style="display:inline-flex;align-items:center;gap:8px;">
					<span style="font-size:12px;opacity:.9;">
						<?php echo esc_html__( 'Language', 'postpress-ai' ); ?>
					</span>

					<select id="ppa-output-language" name="ppa_output_language" disabled
							style="width:170px;max-width:170px;">
						<option value="original" selected><?php echo esc_html__( 'Original', 'postpress-ai' ); ?></option>
						<!-- Options populated by assets/js/admin.js in Step 2 -->
					</select>
				</label>

				<label class="ppa-outline-toggle" for="ppa-show-outline" style="display:inline-flex;align-items:center;gap:8px;">
					<input type="checkbox" id="ppa-show-outline" />
					<span><?php echo esc_html__( 'Show Outline', 'postpress-ai' ); ?></span>
				</label>
			</div>

			<!-- Helper text stays, but does NOT steal header width -->
			<span id="ppa-output-language-help" class="ppa-output-language-help"
				  style="display:block;width:100%;margin-top:6px;opacity:.8;font-size:12px;">
				<?php echo esc_html__( 'Generate Preview first.', 'postpress-ai' ); ?>
			</span>
		</div>

		<div id="ppa-preview-pane" aria-live="polite">
			<em><?php echo esc_html__( '(Preview will appear here once generated.)', 'postpress-ai' ); ?></em>
		</div>
	</div>

</div>
