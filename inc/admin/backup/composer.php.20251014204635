<?php
/**
 * CHANGE LOG
 * 2025-10-12 - restored original composer UI from plugin archive
 * - CHANGED: Restored original server-rendered Composer UI content.
 * - CHANGED: Removed compatibility shim to return to original behavior.
 *
 * Author: Tech With Wayne / Assistant
 */

// CHANGED: Ensure file is loaded within WP
defined( 'ABSPATH' ) || exit; // CHANGED:

if ( ! function_exists( 'ppa_render_composer_page' ) ) {
    function ppa_render_composer_page() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        ?>
        <div class="ppa-composer-wrap">
            <div class="ppa-form-panel">
                <h1>PostPress AI</h1>
                <p class="ppa-subhead">Compose a preview and save to WordPress. The preview appears on the right.</p>

                <label for="ppa-subject">Subject</label>
                <input id="ppa-subject" name="subject" type="text" placeholder="e.g., Improve website speed" />

                <label for="ppa-genre">Genre</label>
                <select id="ppa-genre" name="genre">
                    <option value="How-to" selected>How-to</option>
                    <option value="Listicle">Listicle</option>
                    <option value="Guide">Guide</option>
                    <option value="News">News</option>
                    <option value="Announcement">Announcement</option>
                </select>

                <label for="ppa-tone">Tone</label>
                <select id="ppa-tone" name="tone">
                    <option value="Friendly" selected>Friendly</option>
                    <option value="Professional">Professional</option>
                    <option value="Conversational">Conversational</option>
                    <option value="Persuasive">Persuasive</option>
                    <option value="Informative">Informative</option>
                </select>

                <label for="ppa-prompt">Prompt</label>
                <textarea id="ppa-prompt" name="prompt" rows="8" placeholder="💡 Write clear, detailed instructions for the AI.
Example:
• Explain the goal (what the article or post should achieve).
• List key points or sections you want covered.
• Mention tone and audience if important.
• Add any keywords or special requirements.
The clearer you are, the better PostPress AI writes for you!"></textarea>

                <div class="ppa-buttons">
                    <button id="ppa-preview-btn" class="ppa-btn ppa-btn-primary" type="button">Preview</button>
                    <button id="ppa-save-draft-btn" class="ppa-btn ppa-btn-secondary" type="button">Save Draft</button>
                    <button id="ppa-publish-btn" class="ppa-btn ppa-btn-secondary" type="button" disabled>Publish</button>
                </div>
            </div>

            <div class="ppa-preview-panel">
                <h2 id="ppa-preview-title">Untitled</h2>
                <p id="ppa-preview-meta">
                    <span class="tone">Tone: Friendly</span> • <span class="genre">Genre How-to</span>
                </p>
                <div id="ppa-preview-body">
                    <p>This is a generated preview article about...ep semantic HTML headings and lists for readability and SEO.</p>
                    <h3>Key Points</h3>
                    <ul>
                        <li>Introduce the topic clearly</li>
                        <li>Explain benefits or insights</li>
                        <li>Provide actionable steps</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}
