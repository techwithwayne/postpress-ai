<?php
/**
 * PostPress AI — Composer Screen (Admin UI)
 * Path: inc/admin/composer.php
 *
 * ========= CHANGE LOG =========
 * 2026-01-24 — FIX: Make Word Count <input> tag bullet-proof by keeping it as a single, uninterrupted tag line (prevents accidental comment/markup injection that can render attributes as text). // CHANGED:
 *            — FIX: Remove trailing HTML comment marker from helper line (keep markup clean).                                               // CHANGED:
 *            — KEEP: Word Count defaults (default 800, min 300, max 1200) with user-visible helper.                                        // CHANGED:
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

$ppa_current_site_name = get_bloginfo( 'name' );

if ( ! is_string( $ppa_current_site_name ) || '' === trim( $ppa_current_site_name ) ) {
    $ppa_current_site_name = preg_replace( '#^https?://#', '', home_url() );
}

$ppa_current_site_url_display = preg_replace( '#^https?://#', '', home_url() );
$ppa_current_site_link        = home_url( '/' );

if ( function_exists( 'set_url_scheme' ) ) {
    $ppa_current_site_link = set_url_scheme( $ppa_current_site_link, 'https' );
}

// CHANGED: Centralized option lists (markup-only; does NOT affect CSS).
// Values are stable machine slugs; labels are translated display strings.

// =====================================================
// GENRE OPTIONS
// =====================================================

// Genre options (grouped; rendered with <optgroup>)
$ppa_genre_groups = [
	'__ungrouped__' => [
		'Auto' => 'Auto',
	],

	'Core' => [
		'Blog Post' => 'Blog Post',
		'Explainer' => 'Explainer',
		'Overview' => 'Overview',
		'Background' => 'Background',
		'News' => 'News',
		'Opinion' => 'Opinion',
		'Editorial' => 'Editorial',
		'Thought Leadership' => 'Thought Leadership',
		'Commentary' => 'Commentary',
		'Myth vs Fact' => 'Myth vs Fact',
		'Story' => 'Story',
		'Interview' => 'Interview',
		'Profile' => 'Profile',
	],

	'How-to & Educational' => [
		'How-to' => 'How-to',
		'Guide' => 'Guide',
		'Tutorial' => 'Tutorial',
		'Walkthrough' => 'Walkthrough',
		'Quickstart' => 'Quickstart',
		'Getting Started' => 'Getting Started',
		'Checklist' => 'Checklist',
		'Cheat Sheet' => 'Cheat Sheet',
		'Best Practices' => 'Best Practices',
		'Mistakes to Avoid' => 'Mistakes to Avoid',
		'Troubleshooting' => 'Troubleshooting',
		'FAQ' => 'FAQ',
		'Q&A' => 'Q&A',
		'Glossary' => 'Glossary',
		'Template' => 'Template',
		'Playbook' => 'Playbook',
		'Framework' => 'Framework',
		'SOP' => 'SOP',
	],

	'Lists & Curated' => [
		'Examples' => 'Examples',
		'Ideas' => 'Ideas',
		'Listicle' => 'Listicle',
		'Resource List' => 'Resource List',
		'Roundup' => 'Roundup',
		'Tool List' => 'Tool List',
	],

	'Evaluation & Decision' => [
		'Alternatives' => 'Alternatives',
		'Buying Guide' => 'Buying Guide',
		'Comparison' => 'Comparison',
		'Decision Guide' => 'Decision Guide',
		'Pros and Cons' => 'Pros and Cons',
		'Recommendations' => 'Recommendations',
		'Review' => 'Review',
	],

	'Product & Company' => [
		'Announcement' => 'Announcement',
		'Case Study' => 'Case Study',
		'Changelog' => 'Changelog',
		'Company Update' => 'Company Update',
		'Customer Story' => 'Customer Story',
		'Feature Spotlight' => 'Feature Spotlight',
		'Press Release' => 'Press Release',
		'Product Overview' => 'Product Overview',
		'Product Update' => 'Product Update',
		'Policy Statement' => 'Policy Statement',
		'Release Notes' => 'Release Notes',
		'Success Story' => 'Success Story',
		'Testimonial Roundup' => 'Testimonial Roundup',
		'Use Case' => 'Use Case',
	],

	'Marketing & Sales' => [
		'Ad Copy' => 'Ad Copy',
		'Cold Email' => 'Cold Email',
		'Competitive Teardown' => 'Competitive Teardown',
		'Drip Sequence' => 'Drip Sequence',
		'Email Newsletter' => 'Email Newsletter',
		'Follow-up Email' => 'Follow-up Email',
		'Landing Page Copy' => 'Landing Page Copy',
		'Nurture Sequence' => 'Nurture Sequence',
		'Objection Handling' => 'Objection Handling',
		'Pricing Page Copy' => 'Pricing Page Copy',
		'Sales Email' => 'Sales Email',
		'Sales Page Copy' => 'Sales Page Copy',
		'Welcome Email' => 'Welcome Email',
	],

	'SEO & Strategy' => [
		'Advanced Guide' => 'Advanced Guide',
		'Beginner Guide' => 'Beginner Guide',
		'Cluster Post' => 'Cluster Post',
		'Local Guide' => 'Local Guide',
		'Pillar Page' => 'Pillar Page',
	],

	'Research & Longform' => [
		'Benchmark Report' => 'Benchmark Report',
		'Ebook' => 'Ebook',
		'Industry Trends' => 'Industry Trends',
		'Market Analysis' => 'Market Analysis',
		'Report' => 'Report',
		'Survey Results' => 'Survey Results',
		'Whitepaper' => 'Whitepaper',
	],

	'Social Media' => [
		'Carousel Copy' => 'Carousel Copy',
		'Short Social Caption' => 'Short Social Caption',
		'Social Post' => 'Social Post',
		'Thread' => 'Thread',
	],

	'Audio & Video' => [
		'Podcast Outline' => 'Podcast Outline',
		'Podcast Script' => 'Podcast Script',
		'Presentation Outline' => 'Presentation Outline',
		'Short Video Script' => 'Short Video Script',
		'Video Script' => 'Video Script',
		'Webinar Outline' => 'Webinar Outline',
	],
];

// Flattened legacy map (safety)
$ppa_genre_options = [];
foreach ($ppa_genre_groups as $g => $opts) {
	foreach ($opts as $val => $label) {
		$ppa_genre_options[$val] = $label;
	}
}


// =====================================================
// TONE OPTIONS
// =====================================================

// Tone options (grouped; rendered with <optgroup>)
$ppa_tone_groups = [
	'__ungrouped__' => [
		'Auto' => 'Auto',
	],

	'Approach & Delivery' => [
		'Clear' => 'Clear',
		'Concise' => 'Concise',
		'Conversational' => 'Conversational',
		'Direct' => 'Direct',
		'Minimal' => 'Minimal',
		'Straightforward' => 'Straightforward',
		'Structured' => 'Structured',
	],

	'Authority & Expertise' => [
		'Analytical' => 'Analytical',
		'Authoritative' => 'Authoritative',
		'Expert' => 'Expert',
		'Insightful' => 'Insightful',
		'Professional' => 'Professional',
		'Technical' => 'Technical',
		'Thoughtful' => 'Thoughtful',
	],

	'Emotional Tone' => [
		'Calm' => 'Calm',
		'Empathetic' => 'Empathetic',
		'Encouraging' => 'Encouraging',
		'Inspirational' => 'Inspirational',
		'Positive' => 'Positive',
		'Reassuring' => 'Reassuring',
		'Warm' => 'Warm',
	],

	'Marketing & Persuasion' => [
		'Bold' => 'Bold',
		'Confident' => 'Confident',
		'Luxury' => 'Luxury',
		'Persuasive' => 'Persuasive',
		'Premium' => 'Premium',
		'Urgent' => 'Urgent',
	],

	'Personality' => [
		'Casual' => 'Casual',
		'Friendly' => 'Friendly',
		'Helpful' => 'Helpful',
		'Humorous' => 'Humorous',
		'Playful' => 'Playful',
		'Storytelling' => 'Storytelling',
	],

	'Safety & Neutrality' => [
		'Balanced' => 'Balanced',
		'Cautious' => 'Cautious',
		'Diplomatic' => 'Diplomatic',
		'Neutral' => 'Neutral',
		'Objective' => 'Objective',
	],
];

// Flattened legacy map (safety)
$ppa_tone_options = [];
foreach ($ppa_tone_groups as $g => $opts) {
	foreach ($opts as $val => $label) {
		$ppa_tone_options[$val] = $label;
	}
}

?>
<!-- (No inline CSS; centralized enqueue supplies admin-composer.css and admin.js) -->

<div
	class="wrap ppa-composer-wrap"
	id="ppa-composer"
	data-ppa-nonce="<?php echo esc_attr( $ppa_nonce ); ?>"
	data-ppa-view="<?php echo esc_attr( $ppa_view ); ?>"
	data-ppa-site-url="<?php echo esc_url( $ppa_site_url ); ?>"
>



<div class="ppa-composer-top-target" id="ppa-composer-top-target" aria-live="polite">
    <div class="ppa-composer-top-target__copy">
        <span class="ppa-composer-top-target__eyebrow">
            <?php echo esc_html__( 'Draft to', 'postpress-ai' ); ?>
        </span>

        <a
            id="ppa-target-site-link"
            class="ppa-composer-top-target__name"
            href="<?php echo esc_url( $ppa_current_site_link ); ?>"
            target="_blank"
            rel="noopener noreferrer"
        >
            <?php echo esc_html( $ppa_current_site_name ); ?>
        </a>

        <span id="ppa-target-site-url" class="ppa-composer-top-target__url">
            <?php echo esc_html( $ppa_current_site_url_display ); ?>
        </span>
    </div>

    <div class="ppa-composer-top-target__control">
        <label class="screen-reader-text" for="postpress-ai-target-site">
            <?php esc_html_e( 'Draft to', 'postpress-ai' ); ?>
        </label>

        <select
            id="postpress-ai-target-site"
            name="postpress_ai_target_site"
            data-current-name="<?php echo esc_attr( $ppa_current_site_name ); ?>"
            data-current-url="<?php echo esc_attr( $ppa_current_site_url_display ); ?>"
            data-current-link="<?php echo esc_url( $ppa_current_site_link ); ?>"
        >
            <option
                value="current"
                data-site-name="<?php echo esc_attr( $ppa_current_site_name ); ?>"
                data-site-url="<?php echo esc_attr( $ppa_current_site_url_display ); ?>"
                data-site-link="<?php echo esc_url( $ppa_current_site_link ); ?>"
            >
                <?php
                printf(
                    esc_html__( 'This site (%s)', 'postpress-ai' ),
                    esc_html( $ppa_current_site_url_display )
                );
                ?>
            </option>
            <!-- Remote sites will be appended via JavaScript. -->
        </select>
    </div>
</div>

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

<div class="ppa-form-group ppa-half">
	<label for="ppa-industry"><?php echo esc_html__( 'Industry', 'postpress-ai' ); ?></label>
	<select id="ppa-industry" name="ppa-industry">
		<option value=""><?php echo esc_html__( 'Select an industry…', 'postpress-ai' ); ?></option>
		<option value="Accounting / Bookkeeping">Accounting / Bookkeeping</option>
		<option value="Advertising & Marketing">Advertising & Marketing</option>
		<option value="Aerospace">Aerospace</option>
		<option value="Agency (Creative / Marketing)">Agency (Creative / Marketing)</option>
		<option value="Architecture">Architecture</option>
		<option value="Automotive">Automotive</option>
		<option value="Beauty / Salon / Spa">Beauty / Salon / Spa</option>
		<option value="Childcare">Childcare</option>
		<option value="Cleaning Services">Cleaning Services</option>
		<option value="Coaching / Consulting">Coaching / Consulting</option>
		<option value="Construction">Construction</option>
		<option value="Creator / Influencer">Creator / Influencer</option>
		<option value="Dentistry">Dentistry</option>
		<option value="Ecommerce">Ecommerce</option>
		<option value="Education / Courses">Education / Courses</option>
		<option value="Entertainment / Media">Entertainment / Media</option>
		<option value="Event Planning">Event Planning</option>
		<option value="Farming">Farming</option>
		<option value="Fashion">Fashion</option>
		<option value="Film & Video">Film & Video</option>
		<option value="Finance / Investing">Finance / Investing</option>
		<option value="Fitness / Gym">Fitness / Gym</option>
		<option value="Food & Beverage / Restaurant">Food & Beverage / Restaurant</option>
		<option value="Healthcare / Medical">Healthcare / Medical</option>
		<option value="Home Services (Plumbing/HVAC/Electric)">Home Services (Plumbing/HVAC/Electric)</option>
		<option value="Hospitality / Hotels">Hospitality / Hotels</option>
		<option value="Insurance">Insurance</option>
		<option value="IT Services / MSP">IT Services / MSP</option>
		<option value="Law / Legal">Law / Legal</option>
		<option value="Local Services">Local Services</option>
		<option value="Manufacturing">Manufacturing</option>
		<option value="Nonprofit">Nonprofit</option>
		<option value="Photography / Video">Photography / Video</option>
		<option value="Professional Services">Professional Services</option>
		<option value="Real Estate (Commercial)">Real Estate (Commercial)</option>
		<option value="Real Estate (Residential)">Real Estate (Residential)</option>
		<option value="Recruiting / HR">Recruiting / HR</option>
		<option value="Retail (Brick & Mortar)">Retail (Brick & Mortar)</option>
		<option value="SaaS / Software">SaaS / Software</option>
		<option value="Security Services">Security Services</option>
		<option value="Software Development">Software Development</option>
		<option value="Travel">Travel</option>
		<option value="Wellness / Therapy">Wellness / Therapy</option>
		<option value="Other">Other</option>		
	</select>
	<p class="description"><?php echo esc_html__( 'Optional. Adds context so the AI uses better examples, language, and objections.', 'postpress-ai' ); ?></p>
</div>		
		
		


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
					<?php foreach ( $ppa_genre_groups as $group_label => $options ) : ?>
	<?php if ( '__ungrouped__' === $group_label ) : ?>
		<?php foreach ( $options as $val => $label ) : ?>
			<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	<?php else : ?>
		<optgroup label="<?php echo esc_attr( $group_label ); ?>">
			<?php foreach ( $options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</optgroup>
	<?php endif; ?>
<?php endforeach; ?>
</select>
			</div>
				


			<div class="ppa-form-group">
	<label for="ppa-tone"><?php echo esc_html__( 'Tone', 'postpress-ai' ); ?></label>
	<select id="ppa-tone">

	<?php foreach ( $ppa_tone_groups as $group_label => $options ) : ?>

		<?php if ( '__ungrouped__' === $group_label ) : ?>

			<?php foreach ( $options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>

		<?php else : ?>

			<optgroup label="<?php echo esc_attr( $group_label ); ?>">
				<?php foreach ( $options as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</optgroup>

		<?php endif; ?>

	<?php endforeach; ?>

	</select>
</div>

			<div class="ppa-form-group">
				<label for="ppa-word-count"><?php echo esc_html__( 'Word Count', 'postpress-ai' ); ?></label>

				<?php
				// CHANGED: Keep this <input> as one uninterrupted tag line.
				// Why: the prior breakage pattern was “comment/markup ended up inside the tag”, causing attributes to render as visible text.
				// Keeping it single-line prevents accidental inline comment insertion between attributes.
				?>

				<input type="number" id="ppa-word-count" min="300" max="1200" step="50" inputmode="numeric" value="800" placeholder="800" data-default="800" data-min="300" data-max="1200" /><?php // CHANGED: ?>
			</div>
		</div>

		<?php /* One unified helper block spanning under the 3 dropdown/inputs */ ?>
		<p class="ppa-inline-help ppa-inline-help--span">
			<?php echo esc_html__( 'Auto chooses the best-fit genre + tone from your subject + audience. Word count default: 800 (minimum: 300, max: 1,200).', 'postpress-ai' ); ?><?php // CHANGED: ?>
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

			<div class="ppa-form-group">
				<label><?php echo esc_html__( 'Featured Image', 'postpress-ai' ); ?></label>
				<input type="hidden" id="ppa-thumbnail-id" value="">
				<div id="ppa-thumbnail-preview" style="display:none;margin-bottom:8px;">
					<img id="ppa-thumbnail-img" src="" alt="" style="max-width:200px;height:auto;display:block;border-radius:3px;">
				</div>
				<button type="button" id="ppa-thumbnail-btn" class="button">
					<?php echo esc_html__( 'Set Featured Image', 'postpress-ai' ); ?>
				</button>
				<button type="button" id="ppa-thumbnail-remove" class="button" style="display:none;margin-left:6px;">
					<?php echo esc_html__( 'Remove', 'postpress-ai' ); ?>
				</button>
			</div>
		</details>

		<div class="ppa-actions" role="group" aria-label="<?php echo esc_attr__( 'Composer actions', 'postpress-ai' ); ?>">


			<!-- Legacy buttons kept hidden for compatibility (admin.js may still reference IDs defensively) -->

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
