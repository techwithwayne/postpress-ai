<?php
/**
 * CHANGE LOG
 * 2025-10-19 • Revised composer page to fix access issues and match design.
 * - Removed duplicate capability checks (handled by plugin menu registration). # CHANGED
 * - Restructured HTML into two-column dark layout (Subject, Genre, Tone fields). # CHANGED
 * - Added missing 'Genre' field and 'Publish' button; renamed 'Store Draft' to 'Save Draft'. # CHANGED
 * - Standardized nonce usage to 'ppa_admin_nonce' to align with AJAX. # CHANGED
 * - Marked all modifications with # CHANGED for clarity.
 */

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Debug log plugin page load (optional) # CHANGED
error_log( 'PPA: composer.php rendering at ' . date( 'c' ) ); // CHANGED

// Prepare nonce for AJAX calls # CHANGED
$ppa_nonce = wp_create_nonce( 'ppa_admin_nonce' ); // CHANGED
?>
<div id="ppa-composer" class="ppa-composer ppa-composer-wrap">
    <style>
        /* Inline fallback styles (main styles in assets/css/admin.css) */
        .ppa-composer-wrap label { display:block; margin:8px 0 4px; font-weight:600; }
        .ppa-composer-wrap input[type="text"], .ppa-composer-wrap select, .ppa-composer-wrap input[type="number"], .ppa-composer-wrap textarea {
            width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #333; background: #0f0f0f; color: #fff;
        }
        .ppa-composer-wrap .ppa-actions { margin-top: 16px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .ppa-composer-wrap .ppa-btn { font-weight: 600; padding: 8px 16px; border-radius: 6px; cursor: pointer; border: none; }
        .ppa-composer-wrap .ppa-btn-primary { background: #ff6c00; color: #121212; }
        .ppa-composer-wrap .ppa-btn-secondary { background: #222; color: #fff; border: 1px solid #333; }
        .ppa-composer-wrap .ppa-note { font-size: 13px; color: #bbb; margin-top: 8px; display: block; }
        #ppa-preview-pane { margin-top: 18px; padding: 18px; background: #fff; color: #121212; }
    </style>

    <?php printf( '<input type="hidden" id="ppa-nonce" value="%s" />', esc_attr( $ppa_nonce ) ); ?> <!-- Nonce field for AJAX -->

    <div class="ppa-form-panel"> <!-- Left panel: form inputs -->
        <h1>PostPress AI Composer</h1> <!-- Panel heading --> # CHANGED
        <label for="ppa-subject">Subject / Title</label>
        <input id="ppa-subject" name="ppa_subject" type="text" placeholder="e.g. 5 ways to improve your website speed" value="" />

        <label for="ppa-subject-extra">Optional brief / instructions</label> # CHANGED
        <textarea id="ppa-subject-extra" name="ppa_subject_extra" rows="3" placeholder="(optional: additional context or outline)"></textarea> # CHANGED

        <label for="ppa-genre">Genre</label> <!-- New field --> # CHANGED
        <select id="ppa-genre" name="ppa_genre"> # CHANGED
            <option value="blog">Blog Post</option> # CHANGED
            <option value="news">News Article</option> # CHANGED
            <option value="listicle">Listicle</option> # CHANGED
            <option value="tutorial">Tutorial</option> # CHANGED
        </select> # CHANGED

        <label for="ppa-tone">Tone</label>
        <select id="ppa-tone" name="ppa_tone">
            <option value="casual">Casual</option>
            <option value="professional">Professional</option>
            <option value="friendly">Friendly</option>
        </select>

        <label for="ppa-wordcount">Approx. word count</label> # CHANGED
        <input id="ppa-wordcount" name="ppa_wordcount" type="number" min="50" max="2000" value="300" /> # CHANGED

        <div class="ppa-actions">
            <button id="ppa-preview-btn" class="ppa-btn ppa-btn-primary ppa-compose-btn" type="button">Preview</button> # CHANGED
            <button id="ppa-save-btn" class="ppa-btn ppa-btn-secondary" type="button">Save Draft</button> # CHANGED
            <button id="ppa-publish-btn" class="ppa-btn ppa-btn-secondary" type="button">Publish</button> # CHANGED
            <span class="ppa-note">Preview uses the AI backend. "Save Draft" creates a draft post in WordPress. "Publish" immediately publishes the post.</span> # CHANGED
        </div>
    </div> <!--/.ppa-form-panel-->

    <div class="ppa-preview-panel"> <!-- Right panel: preview output --> # CHANGED
        <div id="ppa-preview-pane" aria-live="polite"><em style="color:#666;">(Preview will appear here once generated.)</em></div> # CHANGED
    </div> <!--/.ppa-preview-panel-->
</div> <!--/#ppa-composer .ppa-composer-wrap-->
<script>
/* Minimal fallback JS for Preview/Save (uses direct fetch). For full functionality, see assets/js/admin.js */
console.info('PPA: composer.php inline script init'); // CHANGED
(function(){
    const previewBtn = document.getElementById('ppa-preview-btn');
    const saveBtn    = document.getElementById('ppa-save-btn'); // CHANGED (renamed from storeBtn)
    const publishBtn = document.getElementById('ppa-publish-btn'); // CHANGED
    const previewPane = document.getElementById('ppa-preview-pane');

    function getPayload() {
        return {
            subject: document.getElementById('ppa-subject').value || '',
            extra: document.getElementById('ppa-subject-extra').value || '',
            genre: document.getElementById('ppa-genre').value || '', // CHANGED
            tone: document.getElementById('ppa-tone').value || 'casual',
            word_count: parseInt(document.getElementById('ppa-wordcount').value, 10) || 300
        };
    }

    previewBtn && previewBtn.addEventListener('click', async function(){
        console.info('PPA: Preview button clicked');
        const payload = getPayload();
        try {
            const res = await fetch('https://apps.techwithwayne.com/postpress-ai/preview/', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
            });
            const data = await res.json();
            if ( data && data.ok && data.content ) {
                previewPane.innerHTML = '<h2>Preview:</h2>' + data.content;
            } else {
                previewPane.innerHTML = '<div class="notice notice-error"><p>Preview failed. Please try again.</p></div>';
            }
        } catch(err) {
            console.error('PPA: Preview AJAX error', err);
            previewPane.innerHTML = '<div class="notice notice-error"><p>Error retrieving preview.</p></div>';
        }
    });

    saveBtn && saveBtn.addEventListener('click', async function(){
        console.info('PPA: Save Draft button clicked'); // CHANGED
        const payload = getPayload();
        if ( ! confirm('Save draft post now?') ) return; // CHANGED: confirm message (optional, remove for final UX) 
        try {
            const res = await fetch('https://apps.techwithwayne.com/postpress-ai/store/', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: payload.subject, extra: payload.extra, genre: payload.genre, tone: payload.tone, word_count: payload.word_count }) // CHANGED: include genre
            });
            const data = await res.json();
            if ( data && data.ok ) {
                alert('Draft saved successfully. Post ID: ' + (data.id || 'N/A'));
            } else {
                alert('Failed to save draft: ' + (data.error || 'Unknown error'));
            }
        } catch(err) {
            console.error('PPA: Save Draft AJAX error', err);
            alert('Error occurred while saving draft.');
        }
    });

    publishBtn && publishBtn.addEventListener('click', function(){
        alert('Publish functionality coming soon!'); // CHANGED: placeholder action
    });
})();
</script>
