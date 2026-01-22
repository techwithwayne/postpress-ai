/* global window, document, jQuery */
/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2026-01-21.2: FIX: Wire "Show Outline" checkbox (#ppa-show-outline) so it hides/shows the Outline
 *              section in the Preview pane, and persists preference via localStorage. // CHANGED:
 *
 * 2026-01-21: FIX: Add missing ensureAutoHelperNotes() and wire it so the "Auto sends 'auto'..." helper text
 *            becomes ONE single note spanning Genre + Tone + Word Count. Hide duplicates safely. // CHANGED:
 *            FIX: Composer root detection is now robust (#ppa-composer OR [data-ppa-composer] OR .ppa-composer)
 *            so admin.js doesn't idle when the ID differs. // CHANGED:
 *
 * 2025-12-25.2: FIX ppa_store 400: send JSON-body transport for ppa_store (same as ppa_generate) so PHP proxy reads valid JSON from php://input and forwards object-root to Django. This resolves Django “Root must be an object” and unblocks Save Draft redirects. // CHANGED:
 * 2025-12-25.1: FIX Save Draft (Store): ensure local WP draft is created by sending status + target_sites in store payload; redirect to edit_link (or fallback edit URL) after successful store. Also fix status dropdown incorrectly overwriting payload.mode; improve edit_link/id extraction from nested response shapes. // CHANGED:
 * 2025-12-22.1: Preview outline cleanup… // CHANGED:
 * 2025-12-27.1: FIX nonce lookup order: prefer window.PPA.nonce first for admin AJAX (ppa_usage_snapshot, etc.). No other behavior changes. // CHANGED:
 */

(function () {
  'use strict';

  var PPA_JS_VER = 'admin.v2026-01-21.2'; // CHANGED:

  // Abort if composer root is missing (defensive)
  // CHANGED: Make root lookup more robust so admin.js doesn't go idle if the ID differs.
  var root =
    document.getElementById('ppa-composer') || // CHANGED:
    document.querySelector('[data-ppa-composer]') || // CHANGED:
    document.querySelector('.ppa-composer'); // CHANGED:

  if (!root) {
    console.info('PPA: composer root not found, admin.js is idle');
    return;
  }

  // Ensure toolbar message acts as a live region (A11y)
  (function ensureLiveRegion(){
    var msg = document.getElementById('ppa-toolbar-msg');
    if (!msg) return;
    try {
      msg.setAttribute('role', 'status');
      msg.setAttribute('aria-live', 'polite');
      msg.setAttribute('aria-atomic', 'true');
    } catch (e) {}
  })();

  // ---- Preview Pane Resolver (Composer) ------------------------------------
  var __ppaPreviewPane = null;

  function getPreviewPane() {
    if (__ppaPreviewPane && __ppaPreviewPane.nodeType === 1) return __ppaPreviewPane;
    var pane = null;
    try { pane = root.querySelector('#ppa-preview-pane'); } catch (e) { pane = null; }
    if (!pane) pane = document.getElementById('ppa-preview-pane');
    if (!pane) {
      try { pane = root.querySelector('[data-ppa-preview-pane]') || root.querySelector('.ppa-preview-pane'); }
      catch (e2) { pane = null; }
    }
    __ppaPreviewPane = pane || null;
    return __ppaPreviewPane;
  }

  (function ensurePreviewPaneFocusable(){
    var pane = getPreviewPane();
    if (pane && !pane.hasAttribute('tabindex')) pane.setAttribute('tabindex', '-1');
  })();

  // ---- Helpers -------------------------------------------------------------

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel) || []); }

  function getAjaxUrl() {
    if (window.PPA && window.PPA.ajaxUrl) return window.PPA.ajaxUrl;
    if (window.PPA && window.PPA.ajax) return window.PPA.ajax; // legacy
    if (window.ppaAdmin && window.ppaAdmin.ajaxurl) return window.ppaAdmin.ajaxurl;
    if (window.ajaxurl) return window.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  }

  function getNonce() {
    // NEW ORDER (LOCKED): window.PPA.nonce -> window.ppaAdmin.nonce -> #ppa-nonce -> [data-ppa-nonce] -> '' // CHANGED:
    if (window.PPA && window.PPA.nonce) return String(window.PPA.nonce); // CHANGED:
    if (window.ppaAdmin && window.ppaAdmin.nonce) return String(window.ppaAdmin.nonce); // CHANGED:
    var el = $('#ppa-nonce');
    if (el && el.value) return String(el.value);
    var data = $('[data-ppa-nonce]');
    if (data) return String(data.getAttribute('data-ppa-nonce') || '');
    return '';
  }

  function toFormBody(obj) {
    var parts = [];
    Object.keys(obj || {}).forEach(function (k) {
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(obj[k] == null ? '' : obj[k])));
    });
    return parts.join('&');
  }

  function normalizePayloadObject(payload) {
    if (payload === undefined || payload === null) return {};
    if (typeof payload === 'string') {
      var s = String(payload || '').trim();
      if (!s) return {};
      try {
        var parsed = JSON.parse(s);
        if (typeof parsed === 'string') {
          try { parsed = JSON.parse(parsed); } catch (e2) {}
        }
        if (parsed && typeof parsed === 'object') return parsed;
        return { value: parsed };
      } catch (e1) {
        return { raw: s };
      }
    }
    if (typeof payload !== 'object') return { value: payload };
    return payload;
  }

  function apiPost(action, payload) {
    var ajaxUrl = getAjaxUrl();
    var payloadObj = normalizePayloadObject(payload);

    // Transport rules (critical):
    // - ppa_generate MUST be JSON body (proven fix).
    // - ppa_store MUST ALSO be JSON body because PHP proxy reads php://input expecting JSON,
    //   and forwards that raw string to Django. Form-encoded breaks Django object-root.  // CHANGED:
    var act = String(action || '');
    var useJsonBody = (act === 'ppa_generate' || act === 'ppa_store'); // CHANGED:

    // Build endpoint robustly (supports ajaxUrl already containing a '?').
    var endpoint = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(act);

    var headers = {
      'X-Requested-With': 'XMLHttpRequest',
      'X-PPA-View': (window.ppaAdmin && window.ppaAdmin.view) ? String(window.ppaAdmin.view) : 'composer'
    };

    var nonce = getNonce();
    if (nonce) headers['X-WP-Nonce'] = nonce;

    var body;
    if (useJsonBody) { // CHANGED:
      headers['Content-Type'] = 'application/json; charset=UTF-8'; // CHANGED:
      body = JSON.stringify(payloadObj || {}); // CHANGED:
    } else {
      headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      body = toFormBody({ action: act, payload: JSON.stringify(payloadObj || {}) });
    }

    try {
      window.PPA_LAST_REQUEST = {
        action: act,
        transport: (useJsonBody ? 'json-body' : 'form-payload-json'), // CHANGED:
        js_ver: PPA_JS_VER
      };
    } catch (e0) {}

    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: body
    }).then(function (resp) {
      return resp.text().then(function (txt) {
        var parsed = null;
        try { parsed = JSON.parse(txt); } catch (e) {}
        return {
          ok: resp.ok,
          status: resp.status,
          bodyText: txt,
          body: (parsed != null ? parsed : txt)
        };
      });
    }).catch(function (err) {
      return { ok: false, status: 0, error: err };
    });
  }

  function escHtml(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function escAttr(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function ppaCleanTitle(raw) {
    if (!raw) return '';
    var t = String(raw).trim();
    t = t.replace(/[.?!…]+$/g, '').trim();
    return t;
  }

  function sanitizeSlug(s) {
    var v = String(s || '').trim().toLowerCase();
    v = v.replace(/\s+/g,'-').replace(/[^a-z0-9\-]/g,'').replace(/\-+/g,'-').replace(/^\-+|\-+$/g,'');
    return v;
  }

  function readCsvValues(el) {
    if (!el) return [];
    var v = '';
    if (typeof el.value === 'string') v = el.value;
    else v = String(el.textContent || '');
    v = v.trim();
    if (!v) return [];
    v = v.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    var parts = v.split(/[\n,]/);
    return parts.map(function(p){ return String(p || '').trim(); }).filter(Boolean);
  }

  function toHtmlFromText(txt) {
    var s = String(txt || '').trim();
    if (!s) return '';
    s = escHtml(s);
    var parts = s.split(/\n\s*\n/).map(function (p) {
      p = String(p || '').trim();
      if (!p) return '';
      return '<p>' + p.replace(/\n/g,'<br>') + '</p>';
    }).filter(Boolean);
    return parts.join('');
  }

  function markdownToHtml(md) {
    var txt = String(md || '').trim();
    if (!txt) return '';
    txt = escHtml(txt).replace(/\r\n/g,'\n').replace(/\r/g,'\n');

    txt = txt.replace(/^######\s+(.*)$/gm, '<h6>$1</h6>');
    txt = txt.replace(/^#####\s+(.*)$/gm, '<h5>$1</h5>');
    txt = txt.replace(/^####\s+(.*)$/gm, '<h4>$1</h4>');
    txt = txt.replace(/^###\s+(.*)$/gm, '<h3>$1</h3>');
    txt = txt.replace(/^##\s+(.*)$/gm, '<h2>$1</h2>');
    txt = txt.replace(/^#\s+(.*)$/gm, '<h1>$1</h1>');

    txt = txt.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    txt = txt.replace(/\*(.+?)\*/g, '<em>$1</em>');
    txt = txt.replace(/`([^`]+)`/g, '<code>$1</code>');

    txt = txt.replace(/^\-\s+(.*)$/gm, '<li>$1</li>');
    txt = txt.replace(/(<li>.*<\/li>\n?)+/g, function (block) {
      return '<ul>' + block.replace(/\n/g, '') + '</ul>';
    });

    var htmlParts = [];
    var blocks = txt.split(/\n\s*\n/);
    blocks.forEach(function (b) {
      var p = String(b || '').trim();
      if (!p) return;
      if (p.indexOf('<h') === 0 || p.indexOf('<ul>') === 0) {
        htmlParts.push(p);
      } else {
        htmlParts.push('<p>' + p.replace(/\n/g,'<br>') + '</p>');
      }
    });

    if (!htmlParts.length) {
      return toHtmlFromText(txt);
    }
    return htmlParts.join('');
  }

  // -------------------------------------------------------------------------
  // CHANGED: Show Outline toggle wiring
  // - Checkbox: #ppa-show-outline (in composer.php)
  // - Behavior: hide/show the "Outline" section inside #ppa-preview-pane
  // - Persistence: localStorage key "ppa_show_outline"
  // -------------------------------------------------------------------------
  var OUTLINE_PREF_KEY = 'ppa_show_outline'; // CHANGED:
  var outlineToggleEl = null; // CHANGED:

  function getOutlineToggleEl() { // CHANGED:
    if (outlineToggleEl && outlineToggleEl.nodeType === 1) return outlineToggleEl; // CHANGED:
    var el = null; // CHANGED:
    try { el = root.querySelector('#ppa-show-outline'); } catch (e0) { el = null; } // CHANGED:
    if (!el) el = document.getElementById('ppa-show-outline'); // CHANGED:
    outlineToggleEl = el || null; // CHANGED:
    return outlineToggleEl; // CHANGED:
  } // CHANGED:

  function readOutlinePref() { // CHANGED:
    try {
      var v = window.localStorage ? window.localStorage.getItem(OUTLINE_PREF_KEY) : null; // CHANGED:
      return (String(v || '') === '1'); // CHANGED:
    } catch (e) {
      return false; // CHANGED:
    }
  } // CHANGED:

  function writeOutlinePref(isOn) { // CHANGED:
    try {
      if (!window.localStorage) return; // CHANGED:
      window.localStorage.setItem(OUTLINE_PREF_KEY, isOn ? '1' : '0'); // CHANGED:
    } catch (e) {}
  } // CHANGED:

  function setNodeVisible(node, visible) { // CHANGED:
    if (!node || node.nodeType !== 1) return; // CHANGED:
    try { node.style.display = visible ? '' : 'none'; } catch (e1) {} // CHANGED:
    try { node.setAttribute('aria-hidden', visible ? 'false' : 'true'); } catch (e2) {} // CHANGED:
  } // CHANGED:

  function applyOutlineVisibility() { // CHANGED:
    var pane = getPreviewPane(); // CHANGED:
    if (!pane || pane.nodeType !== 1) return; // CHANGED:

    var toggle = getOutlineToggleEl(); // CHANGED:
    var show = toggle ? !!toggle.checked : false; // CHANGED:

    // 1) Our legacy admin.js renderer: <div class="ppa-outline">...</div>
    var blocks = []; // CHANGED:
    try { blocks = Array.prototype.slice.call(pane.querySelectorAll('.ppa-outline') || []); } catch (e0) { blocks = []; } // CHANGED:
    for (var i = 0; i < blocks.length; i++) { // CHANGED:
      var b = blocks[i]; // CHANGED:
      setNodeVisible(b, show); // CHANGED:
      // Hide/show the header immediately preceding the outline block (if it is "Outline")
      try { // CHANGED:
        var prev = b.previousElementSibling; // CHANGED:
        if (prev && prev.nodeType === 1 && String(prev.tagName || '').toUpperCase() === 'H3') { // CHANGED:
          var t = String(prev.textContent || '').trim().toLowerCase(); // CHANGED:
          if (t === 'outline') setNodeVisible(prev, show); // CHANGED:
        } // CHANGED:
      } catch (e1) {} // CHANGED:
    } // CHANGED:

    // 2) Module/other renderer: <h3>Outline</h3><ol>...</ol> (or <ul>/<div>)
    var h3s = []; // CHANGED:
    try { h3s = Array.prototype.slice.call(pane.querySelectorAll('h3') || []); } catch (e2) { h3s = []; } // CHANGED:
    for (var j = 0; j < h3s.length; j++) { // CHANGED:
      var h = h3s[j]; // CHANGED:
      if (!h || h.nodeType !== 1) continue; // CHANGED:
      var ht = String(h.textContent || '').trim().toLowerCase(); // CHANGED:
      if (ht !== 'outline') continue; // CHANGED:

      setNodeVisible(h, show); // CHANGED:

      // Hide/show the immediate next block if it looks like the outline content
      try { // CHANGED:
        var nxt = h.nextElementSibling; // CHANGED:
        if (nxt && nxt.nodeType === 1) { // CHANGED:
          var tag = String(nxt.tagName || '').toUpperCase(); // CHANGED:
          if (tag === 'OL' || tag === 'UL' || tag === 'DIV') { // CHANGED:
            setNodeVisible(nxt, show); // CHANGED:
          } // CHANGED:
        } // CHANGED:
      } catch (e3) {} // CHANGED:
    } // CHANGED:
  } // CHANGED:

  (function bootOutlineToggle(){ // CHANGED:
    var toggle = getOutlineToggleEl(); // CHANGED:
    if (!toggle) return; // CHANGED:

    // Set initial state from localStorage (default OFF)
    try { toggle.checked = readOutlinePref(); } catch (e0) {} // CHANGED:

    // Apply once on boot (in case preview is already rendered)
    try { applyOutlineVisibility(); } catch (e1) {} // CHANGED:

    // Wire change handler
    toggle.addEventListener('change', function () { // CHANGED:
      try { writeOutlinePref(!!toggle.checked); } catch (e2) {} // CHANGED:
      try { applyOutlineVisibility(); } catch (e3) {} // CHANGED:
    }, true); // CHANGED:
  })(); // CHANGED:

  // -------------------------------------------------------------------------
  // CHANGED: ensureAutoHelperNotes()
  // Goal: If the UI renders duplicate helper text under Genre and Tone like:
  //   Auto sends "auto" so PostPress AI chooses a best-fit genre...
  // We want ONE helper block spanning under Genre + Tone + Word Count.
  // This is defensive: it tries multiple selector patterns and only acts
  // when the three selects exist.
  // -------------------------------------------------------------------------
  function ensureAutoHelperNotes() { // CHANGED:
    try {
      var genre = $('#ppa-genre', root) || $('#ppa-genre'); // CHANGED:
      var tone  = $('#ppa-tone', root) || $('#ppa-tone'); // CHANGED:
      var wc    = $('#ppa-word-count', root) || $('#ppa-word-count'); // CHANGED:
      if (!genre || !tone || !wc) return; // CHANGED:

      // Find helper elements near a given control that match the "Auto sends" note.
      function findAutoHelperNear(el) { // CHANGED:
        if (!el) return null; // CHANGED:

        var container = null; // CHANGED:
        try {
          // Prefer a local field wrapper if your markup has one.
          container = (el.closest && el.closest('.ppa-field, .ppa-control, .ppa-form-row, .ppa-row, .form-field')) || el.parentNode; // CHANGED:
        } catch (e0) { container = el.parentNode; } // CHANGED:

        var candidates = []; // CHANGED:
        if (container && container.querySelectorAll) { // CHANGED:
          candidates = candidates.concat($all('.description, .ppa-help, .ppa-inline-help, .help, small, p, div', container)); // CHANGED:
        } // CHANGED:

        // Also consider immediate sibling blocks (common WP pattern).
        try {
          if (el.parentNode && el.parentNode.querySelectorAll) {
            candidates = candidates.concat($all('.description, .ppa-help, .ppa-inline-help, small, p, div', el.parentNode)); // CHANGED:
          }
        } catch (e1) {}

        // Filter to "Auto sends" notes (case-insensitive, tolerant).
        var match = null; // CHANGED:
        for (var i = 0; i < candidates.length; i++) { // CHANGED:
          var node = candidates[i]; // CHANGED:
          if (!node || node.nodeType !== 1) continue; // CHANGED:
          var txt = String(node.textContent || '').trim(); // CHANGED:
          if (!txt) continue; // CHANGED:
          var t = txt.toLowerCase(); // CHANGED:
          if (t.indexOf('auto sends') !== -1 && t.indexOf('"auto"') !== -1) { // CHANGED:
            match = node; // CHANGED:
            break; // CHANGED:
          }
          // Slightly more permissive fallback.
          if (t.indexOf('auto sends') !== -1 && t.indexOf('auto') !== -1 && t.indexOf('postpress ai') !== -1) { // CHANGED:
            match = node; // CHANGED:
            break; // CHANGED:
          }
        } // CHANGED:

        return match; // CHANGED:
      } // CHANGED:

      var h1 = findAutoHelperNear(genre); // CHANGED:
      var h2 = findAutoHelperNear(tone); // CHANGED:
      var h3 = findAutoHelperNear(wc); // CHANGED:

      // Choose the first found helper as the "source text".
      var source = h1 || h2 || h3; // CHANGED:
      if (!source) return; // CHANGED:

      var noteText = String(source.textContent || '').trim(); // CHANGED:
      if (!noteText) return; // CHANGED:

      // Find a wrapper that contains all three controls, so we can place a single note beneath them.
      function findCommonWrapper(a, b, c) { // CHANGED:
        var node = a; // CHANGED:
        while (node && node !== root) { // CHANGED:
          try {
            if (node.contains && node.contains(b) && node.contains(c)) return node; // CHANGED:
          } catch (e2) {}
          node = node.parentNode; // CHANGED:
        }
        return root; // CHANGED:
      } // CHANGED:

      var wrap = findCommonWrapper(genre, tone, wc); // CHANGED:
      if (!wrap) wrap = root; // CHANGED:

      // If we already created the single note, just keep it updated.
      var existing = null; // CHANGED:
      try { existing = wrap.querySelector('.ppa-auto-helper-note'); } catch (e3) { existing = null; } // CHANGED:

      if (!existing) { // CHANGED:
        var p = document.createElement('p'); // CHANGED:
        p.className = 'description ppa-auto-helper-note'; // CHANGED:
        p.textContent = noteText; // CHANGED:

        // Prefer to append after the three-field row if we can detect it.
        // Otherwise, append to the common wrapper.
        wrap.appendChild(p); // CHANGED:
        existing = p; // CHANGED:
      } else { // CHANGED:
        existing.textContent = noteText; // CHANGED:
      } // CHANGED:

      // Hide duplicates near each field (but do not remove from DOM).
      [h1, h2, h3].forEach(function (h) { // CHANGED:
        if (!h || h === existing) return; // CHANGED:
        try {
          h.classList.add('ppa-hidden-auto-helper'); // CHANGED:
          h.style.display = 'none'; // CHANGED:
        } catch (e4) {}
      }); // CHANGED:
    } catch (e5) {
      // Silent on purpose — this is UI sugar and must never break Composer.
    }
  }

  // CHANGED: Run once on init (after a tiny delay in case the Composer markup is injected).
  (function bootAutoHelperNotes(){ // CHANGED:
    try { ensureAutoHelperNotes(); } catch (e0) {} // CHANGED:
    window.setTimeout(function(){ // CHANGED:
      try { ensureAutoHelperNotes(); } catch (e1) {} // CHANGED:
    }, 50); // CHANGED:
  })(); // CHANGED:

  // CHANGED: If Composer UI re-renders pieces dynamically, re-apply (light, safe observer).
  (function observeAutoHelperNotes(){ // CHANGED:
    try {
      if (!window.MutationObserver) return; // CHANGED:
      var obs = new MutationObserver(function () { // CHANGED:
        try { ensureAutoHelperNotes(); } catch (e2) {} // CHANGED:
      }); // CHANGED:
      obs.observe(root, { childList: true, subtree: true }); // CHANGED:
    } catch (e3) {}
  })(); // CHANGED:

  function buildPreviewPayload() {
    var subject = $('#ppa-subject');
    var brief   = $('#ppa-brief');
    var genre   = $('#ppa-genre');
    var tone    = $('#ppa-tone');
    var wc      = $('#ppa-word-count');
    var audience = $('#ppa-audience');

    var payload = {
      subject: subject ? String(subject.value || '').trim() : '',
      title:   subject ? String(subject.value || '').trim() : '',
      brief:   brief   ? String(brief.value   || '').trim() : '',
      content: brief   ? String(brief.value   || '').trim() : '',
      text:    brief   ? String(brief.value   || '').trim() : '',
      html:    '',
      genre:   genre ? String(genre.value || '').trim() : '',
      tone:    tone  ? String(tone.value  || '').trim() : '',
      word_count: wc ? String(wc.value || '').trim() : '',
      audience: audience ? String(audience.value || '').trim() : '',
      keywords: readCsvValues($('#ppa-keywords')),
      _js_ver: PPA_JS_VER
    };

    var tagsEl = $('#ppa-tags') || $('#new-tag-post_tag') || $('#tax-input-post_tag');
    var catsEl = $('#ppa-categories') || $('#post_category');
    if (tagsEl) {
      payload.tags = (function(){
        if (tagsEl.tagName === 'SELECT') {
          return $all('option:checked', tagsEl).map(function (o) { return o.value; });
        } return readCsvValues(tagsEl);
      })();
    }
    if (catsEl) {
      payload.categories = (function(){
        if (catsEl.tagName === 'SELECT') {
          return $all('option:checked', catsEl).map(function (o) { return o.value; });
        } return readCsvValues(catsEl);
      })();
    }

    var focusEl = $('#yoast_wpseo_focuskw_text_input');
    var metaEl  = $('#yoast_wpseo_metadesc');
    payload.meta = {
      focus_keyphrase: focusEl ? String(focusEl.value || '').trim() : '',
      meta_description: metaEl ? String(metaEl.value  || '').trim() : ''
    };

    return payload;
  }

  function extractTitleFromHtml(html) {
    var h = String(html || '');
    var m = h.match(/<h[12][^>]*>(.*?)<\/h[12]>/i);
    if (!m) return '';
    return String(m[1] || '').replace(/<[^>]+>/g,'').trim();
  }

  function extractExcerptFromHtml(html) {
    var h = String(html || '');
    var m = h.match(/<p[^>]*>(.*?)<\/p>/i);
    if (!m) return '';
    return String(m[1] || '').replace(/<[^>]+>/g,'').trim();
  }

  function buildStorePayload(mode) {
    var title   = $('#ppa-title')   || $('#title');
    var excerpt = $('#ppa-excerpt') || $('#excerpt');
    var slug    = $('#ppa-slug')    || $('#post_name');
    var content = $('#ppa-content') || $('#content');

    var safeMode = String(mode || 'draft').trim().toLowerCase();
    if (!safeMode) safeMode = 'draft';

    var payload = {
      mode: safeMode,
      status: safeMode,
      target_sites: [ safeMode ],
      title:   title ? String(title.value || '').trim() : '',
      excerpt: excerpt ? String(excerpt.value || '').trim() : '',
      slug:    slug ? String(slug.value || '').trim() : '',
      content: content ? String(content.value || '').trim() : '',
      _js_ver: PPA_JS_VER
    };

    var postId = $('#post_ID');
    if (postId && postId.value) payload.post_id = String(postId.value);

    var statusEl = $('#ppa-status');
    var statusVal = statusEl ? String(statusEl.value || '').trim().toLowerCase() : '';
    if (statusVal) {
      payload.status = statusVal;
      payload.target_sites = [ statusVal ];
    }

    if (!payload.content || !String(payload.content).trim()) {
      var pane = getPreviewPane();
      var html = pane ? String(pane.innerHTML || '').trim() : '';
      if (html) {
        payload.content = html;
        if (!payload.title)   payload.title   = extractTitleFromHtml(html);
        if (!payload.excerpt) payload.excerpt = extractExcerptFromHtml(html);
        if (!payload.slug && payload.title) payload.slug = sanitizeSlug(payload.title);
      }
    }

    var focusEl2 = $('#yoast_wpseo_focuskw_text_input');
    var metaEl2  = $('#yoast_wpseo_metadesc');
    payload.meta = {
      focus_keyphrase: focusEl2 ? String(focusEl2.value || '').trim() : '',
      meta_description: metaEl2 ? String(metaEl2.value  || '').trim() : ''
    };

    return payload;
  }

  function hasTitleOrSubject() {
    var subjectEl = $('#ppa-subject');
    var titleEl   = $('#ppa-title') || $('#title');

    var subject = subjectEl ? String(subjectEl.value || '').trim() : '';
    var title   = titleEl ? String(titleEl.value || '').trim() : '';

    return !!(subject || title);
  }

  var btnPreview  = document.getElementById('ppa-preview');
  var btnDraft    = document.getElementById('ppa-draft');
  var btnPublish  = document.getElementById('ppa-publish');
  var btnGenerate = document.getElementById('ppa-generate');

  (function adaptGenerateAsPreview(){
    if (btnPreview) {
      try { btnPreview.style.display = 'none'; } catch (e) {}
    }
    if (btnGenerate) {
      try { btnGenerate.textContent = 'Generate Preview'; } catch (e) {}
    }
  })();

  function noticeContainer() {
    var el = document.getElementById('ppa-toolbar-msg');
    if (el) return el;

    var host = null;
    if (btnGenerate && btnGenerate.parentNode) host = btnGenerate.parentNode;
    else if (btnPreview && btnPreview.parentNode) host = btnPreview.parentNode;
    else if (btnDraft && btnDraft.parentNode) host = btnDraft.parentNode;
    else if (btnPublish && btnPublish.parentNode) host = btnPublish.parentNode;
    if (!host) return null;

    el = document.createElement('div');
    el.id = 'ppa-toolbar-msg';
    el.className = 'ppa-notice';
    try {
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'true');
    } catch (e2) {}
    host.insertBefore(el, host.firstChild);
    return el;
  }

  function renderNotice(type, message) {
    var el = noticeContainer();
    if (!el) {
      if (type === 'error' || type === 'warn') { try { window.alert(String(message || '')); } catch (e) {} }
      return;
    }
    el.className = 'ppa-notice ppa-notice-' + String(type || 'info');
    el.textContent = String(message == null ? '' : message);
  }

  function renderNoticeTimed(type, message, ms) {
    renderNotice(type, message);
    var dur = parseInt(ms, 10);
    if (!dur || dur < 250) dur = 2500;
    window.setTimeout(function () { clearNotice(); }, dur);
  }

  function renderNoticeHtml(type, html) {
    var el = noticeContainer();
    if (!el) return;
    el.className = 'ppa-notice ppa-notice-' + String(type || 'info');
    el.innerHTML = String(html == null ? '' : html);
  }

  function renderNoticeTimedHtml(type, html, ms) {
    renderNoticeHtml(type, html);
    var dur = parseInt(ms, 10);
    if (!dur || dur < 250) dur = 2500;
    window.setTimeout(function () { clearNotice(); }, dur);
  }

  function clearNotice() {
    var el = noticeContainer();
    if (!el) return;
    el.className = 'ppa-notice';
    el.textContent = '';
  }

  function setButtonsDisabled(disabled) {
    var dis = !!disabled;
    if (btnPreview)  btnPreview.disabled = dis;
    if (btnDraft)    btnDraft.disabled = dis;
    if (btnPublish)  btnPublish.disabled = dis;
    if (btnGenerate) btnGenerate.disabled = dis;
  }

  function clickGuard(btn) {
    if (!btn) return false;
    var ts = Number(btn.getAttribute('data-ppa-ts') || 0);
    var now = (Date.now ? Date.now() : (new Date()).getTime());
    if (now - ts < 350) return true;
    btn.setAttribute('data-ppa-ts', String(now));
    return false;
  }

  function withBusy(promiseFactory, label) {
    setButtonsDisabled(true);
    clearNotice();
    var tag = label || 'request';
    try { console.info('PPA: busy start →', tag); } catch (e0) {}

    try {
      var p = promiseFactory();
      return Promise.resolve(p)
        .catch(function (err) {
          try { console.info('PPA: busy error on', tag, err); } catch (e1) {}
          renderNotice('error', 'There was an error while processing your request.');
          throw err;
        })
        .finally(function () {
          setButtonsDisabled(false);
          try { console.info('PPA: busy end ←', tag); } catch (e2) {}
        });
    } catch (e3) {
      setButtonsDisabled(false);
      try { console.info('PPA: busy sync error on', tag, e3); } catch (e4) {}
      renderNotice('error', 'There was an error while preparing your request.');
      throw e3;
    }
  }

  function ensurePreviewPaneVisible() {
    var pane = getPreviewPane();
    if (!pane) return;
    try {
      if (pane.style && pane.style.display === 'none') pane.style.display = '';
      pane.removeAttribute('hidden');
      pane.classList.remove('ppa-hidden');
    } catch (e) {}
  }

  function hardenPreviewLists(pane) {
    if (!pane || pane.nodeType !== 1) return;
    var scope = pane;

    var lists = [];
    try { lists = Array.prototype.slice.call(scope.querySelectorAll('ul, ol') || []); } catch (e1) { lists = []; }
    for (var i = 0; i < lists.length; i++) {
      var list = lists[i];
      if (!list || list.nodeType !== 1) continue;
      try {
        list.style.listStylePosition = 'outside';
        list.style.paddingLeft = '1.25em';
        list.style.marginLeft = '0.75em';
        list.style.maxWidth = '100%';
        list.style.boxSizing = 'border-box';
        if (String(list.tagName || '').toUpperCase() === 'UL') list.style.listStyleType = 'disc';
        if (String(list.tagName || '').toUpperCase() === 'OL') list.style.listStyleType = 'decimal';
      } catch (e2) {}
    }

    var items = [];
    try { items = Array.prototype.slice.call(scope.querySelectorAll('li') || []); } catch (e3) { items = []; }
    for (var j = 0; j < items.length; j++) {
      var li = items[j];
      if (!li || li.nodeType !== 1) continue;
      try {
        li.style.display = 'list-item';
        li.style.overflowWrap = 'anywhere';
        li.style.wordBreak = 'break-word';
        li.style.maxWidth = '100%';
        li.style.boxSizing = 'border-box';
      } catch (e4) {}
    }
  }

  function buildPreviewHtml(result) {
    var title = '';
    var outline = '';
    var bodyMd = '';
    var meta = null;

    if (result && typeof result === 'object') {
      title = String(result.title || '').trim();
      outline = String(result.outline || '');
      bodyMd = String(result.body_markdown || result.body || '');
      meta = result.meta || result.seo || null;
    }

    var html = '';
    html += '<div class="ppa-preview">';
    if (title) html += '<h2>' + escHtml(title) + '</h2>';
    if (outline) {
      html += '<h3>Outline</h3>';
      html += '<div class="ppa-outline">' + markdownToHtml(outline) + '</div>';
    }
    if (bodyMd) {
      html += '<h3>Body</h3>';
      html += '<div class="ppa-body">' + markdownToHtml(bodyMd) + '</div>';
    }
    if (meta && typeof meta === 'object') {
      html += '<h3>Meta</h3>';
      html += '<ul class="ppa-meta">';
      if (meta.focus_keyphrase) html += '<li><strong>Focus keyphrase:</strong> ' + escHtml(meta.focus_keyphrase) + '</li>';
      if (meta.meta_description) html += '<li><strong>Meta description:</strong> ' + escHtml(meta.meta_description) + '</li>';
      if (meta.slug) html += '<li><strong>Slug:</strong> ' + escHtml(meta.slug) + '</li>';
      html += '</ul>';
    }
    html += '</div>';
    return html;
  }

  function renderPreview(result, provider) {
    ensurePreviewPaneVisible();
    var pane = getPreviewPane();
    if (!pane) {
      renderNoticeTimed('error', 'Preview pane not found on this screen.', 3500);
      return;
    }
    if (provider) {
      try { pane.setAttribute('data-ppa-provider', String(provider)); } catch (e) {}
    }

    var html = '';
    if (result && typeof result === 'object' && result.html) {
      html = String(result.html);
    } else {
      html = buildPreviewHtml(result);
    }
    pane.innerHTML = html;

    hardenPreviewLists(pane);

    // CHANGED: After any preview render, re-apply Outline visibility preference.
    try { applyOutlineVisibility(); } catch (e3) {} // CHANGED:

    try { pane.focus(); } catch (e2) {}
  }

  function unwrapWpAjax(body) {
    if (!body || typeof body !== 'object') return { hasEnvelope: false, success: null, data: body };
    if (Object.prototype.hasOwnProperty.call(body, 'success') && Object.prototype.hasOwnProperty.call(body, 'data')) {
      return { hasEnvelope: true, success: body.success === true, data: body.data };
    }
    return { hasEnvelope: false, success: null, data: body };
  }

  function pickDjangoResultShape(unwrappedData) {
    if (!unwrappedData || typeof unwrappedData !== 'object') return unwrappedData;
    if (unwrappedData.result && typeof unwrappedData.result === 'object') return unwrappedData.result;
    if (Object.prototype.hasOwnProperty.call(unwrappedData, 'title') ||
        Object.prototype.hasOwnProperty.call(unwrappedData, 'outline') ||
        Object.prototype.hasOwnProperty.call(unwrappedData, 'body_markdown')) return unwrappedData;
    return unwrappedData;
  }

  function applyGenerateResult(result) {
    if (!result || typeof result !== 'object') return { titleFilled: false, excerptFilled: false, slugFilled: false };

    var title = ppaCleanTitle(result.title || '');
    var bodyMd = String(result.body_markdown || result.body || '');
    var meta = result.meta || result.seo || {};

    var filled = { titleFilled: false, excerptFilled: false, slugFilled: false };

    var titleEl = $('#ppa-title') || $('#title');
    if (titleEl && title && !String(titleEl.value || '').trim()) {
      titleEl.value = title;
      filled.titleFilled = true;
    }

    var contentEl = $('#ppa-content') || $('#content');
    if (contentEl && (!String(contentEl.value || '').trim()) && bodyMd) {
      contentEl.value = markdownToHtml(bodyMd);
    }

    var excerptEl = $('#ppa-excerpt') || $('#excerpt');
    if (excerptEl && meta && meta.meta_description && !String(excerptEl.value || '').trim()) {
      excerptEl.value = String(meta.meta_description);
      filled.excerptFilled = true;
    }

    var slugEl = $('#ppa-slug') || $('#post_name');
    if (slugEl && (!String(slugEl.value || '').trim())) {
      var s = '';
      if (meta && meta.slug) s = String(meta.slug);
      if (!s && title) s = sanitizeSlug(title);
      if (s) {
        slugEl.value = s;
        filled.slugFilled = true;
      }
    }

    var focusEl3 = $('#yoast_wpseo_focuskw_text_input');
    var metaEl3  = $('#yoast_wpseo_metadesc');
    if (focusEl3 && meta && meta.focus_keyphrase && !String(focusEl3.value || '').trim()) {
      focusEl3.value = String(meta.focus_keyphrase);
    }
    if (metaEl3 && meta && meta.meta_description && !String(metaEl3.value || '').trim()) {
      metaEl3.value = String(meta.meta_description);
    }

    return filled;
  }

  function pickMessage(body) {
    if (!body) return '';
    if (typeof body === 'string') return body;
    if (typeof body === 'object') {
      if (body.message) return String(body.message);
      if (body.error) return String(body.error);
      if (body.data && body.data.message) return String(body.data.message);
    }
    return '';
  }

  function pickId(body) {
    try {
      if (body && typeof body === 'object') {
        if (body.id) return body.id;
        if (body.post_id) return body.post_id;

        if (body.data && body.data.id) return body.data.id;
        if (body.data && body.data.post_id) return body.data.post_id;

        if (body.data && body.data.result && body.data.result.id) return body.data.result.id;
        if (body.result && body.result.id) return body.result.id;
      }
    } catch (e) {}
    return '';
  }

  function pickEditLink(body) {
    try {
      if (body && typeof body === 'object') {
        if (body.edit_link) return body.edit_link;
        if (body.data && body.data.edit_link) return body.data.edit_link;

        if (body.data && body.data.result && body.data.result.edit_link) return body.data.result.edit_link;
        if (body.result && body.result.edit_link) return body.result.edit_link;
      }
    } catch (e) {}
    return '';
  }

  function pickViewLink(body) {
    try {
      if (body && typeof body === 'object') {
        if (body.view_link) return body.view_link;
        if (body.permalink) return body.permalink;

        if (body.data && body.data.view_link) return body.data.view_link;
        if (body.data && body.data.permalink) return body.data.permalink;

        if (body.data && body.data.result && body.data.result.permalink) return body.data.result.permalink;
        if (body.result && body.result.permalink) return body.result.permalink;
      }
    } catch (e) {}
    return '';
  }

  function buildWpEditUrlFromId(id) {
    var pid = parseInt(String(id || ''), 10);
    if (!pid || pid < 1) return '';
    return '/wp-admin/post.php?post=' + String(pid) + '&action=edit';
  }

  function stopEvent(ev) {
    if (!ev) return;
    try { if (typeof ev.preventDefault === 'function') ev.preventDefault(); } catch (e1) {}
    try { if (typeof ev.stopPropagation === 'function') ev.stopPropagation(); } catch (e2) {}
    try { if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation(); } catch (e3) {}
  }

  // Wire buttons
  if (btnDraft) {
    btnDraft.addEventListener('click', function (ev) {
      stopEvent(ev);
      if (clickGuard(btnDraft)) return;
      console.info('PPA: Draft clicked');

      if (!hasTitleOrSubject()) {
        renderNotice('warn', 'Add a subject or title before saving.');
        return;
      }

      withBusy(function () {
        var payload = buildStorePayload('draft');
        return apiPost('ppa_store', payload).then(function (res) {
          var wp = unwrapWpAjax(res.body);
          var data = wp.hasEnvelope ? wp.data : res.body;
          var msg = pickMessage(res.body) || 'Draft request sent.';

          if (!res.ok || (wp.hasEnvelope && !wp.success)) {
            renderNotice('error', 'Save draft failed (' + res.status + '): ' + msg);
            console.info('PPA: draft failed', res);
            return;
          }

          var edit = pickEditLink(res.body) || (data && data.edit_link ? data.edit_link : '');
          var pid  = pickId(res.body) || (data && (data.id || data.post_id) ? (data.id || data.post_id) : '');
          var fallbackEdit = (!edit && pid) ? buildWpEditUrlFromId(pid) : '';

          renderNoticeTimed('success', 'Draft saved. Opening it now…', 2500);
          console.info('PPA: draft ok', data);

          window.setTimeout(function () {
            var url = edit || fallbackEdit;
            if (url) {
              try { window.location.href = String(url); } catch (e1) {}
            } else {
              renderNoticeTimed('success', 'Draft saved, but no edit link was returned.', 5000);
              console.info('PPA: draft ok but missing edit link/id', res);
            }
          }, 450);
        });
      }, 'store');
    }, true);
  }

  if (btnPublish) {
    btnPublish.addEventListener('click', function (ev) {
      stopEvent(ev);
      if (clickGuard(btnPublish)) return;
      console.info('PPA: Publish clicked');

      if (!hasTitleOrSubject()) {
        renderNotice('warn', 'Add a subject or title before publishing.');
        return;
      }

      withBusy(function () {
        var payload = buildStorePayload('publish');
        return apiPost('ppa_store', payload).then(function (res) {
          var wp = unwrapWpAjax(res.body);
          var data = wp.hasEnvelope ? wp.data : res.body;
          var serr = (data && data.error) ? data.error : null;
          var msg = (serr && serr.message) || pickMessage(res.body) || 'Publish request sent.';
          var pid = pickId(res.body);
          var edit = pickEditLink(res.body);
          var view = pickViewLink(res.body);

          if (!res.ok || (wp.hasEnvelope && !wp.success)) {
            renderNotice('error', 'Publish failed (' + res.status + '): ' + msg);
            console.info('PPA: publish failed', res);
            return;
          }
          if (view || edit) {
            var parts = [];
            if (view) parts.push('<a href="' + escAttr(view) + '" target="_blank" rel="noopener">View</a>');
            if (edit) parts.push('<a href="' + escAttr(edit) + '" target="_blank" rel="noopener">Edit</a>');
            var html = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + ' — ' + parts.join(' &middot; ');
            renderNoticeTimedHtml('success', html, 8000);
          } else {
            renderNoticeTimed('success', 'Published successfully.', 5000);
          }
          console.info('PPA: publish ok', data);
        });
      }, 'store');
    }, true);
  }

  if (btnGenerate) {
    btnGenerate.addEventListener('click', function (ev) {
      stopEvent(ev);
      if (clickGuard(btnGenerate)) return;
      console.info('PPA: Generate clicked');

      var probe = buildPreviewPayload();
      if (!String(probe.title || '').trim() &&
          !String(probe.text || '').trim() &&
          !String(probe.content || '').trim()) {
        renderNotice('warn', 'Add a subject or a brief before generating.');
        return;
      }

      withBusy(function () {
        var payload = probe;

        return apiPost('ppa_generate', payload).then(function (res) {
          var wp = unwrapWpAjax(res.body);
          var overallOk = res.ok;
          if (wp.hasEnvelope) overallOk = overallOk && (wp.success === true);

          var data = wp.hasEnvelope ? wp.data : res.body;
          var django = pickDjangoResultShape(data);

          try { window.PPA_LAST_GENERATE = { ok: overallOk, status: res.status, body: res.body, data: data, djangoResult: django }; } catch (e) {}

          if (!overallOk) {
            renderNotice('error', 'Generate failed (' + res.status + '): ' + (pickMessage(res.body) || 'Unknown error'));
            console.info('PPA: generate failed', res);
            return;
          }

          var provider = (data && data.provider) ? data.provider : (django && django.provider ? django.provider : '');
          renderPreview(django, provider);

          var filled = applyGenerateResult(django);
          try { console.info('PPA: applyGenerateResult →', filled); } catch (e2) {}

          renderNotice('success', 'AI draft generated. Review, tweak, then Save Draft or Publish.');
        });
      }, 'generate');
    }, true);
  }

  window.PPAAdmin = window.PPAAdmin || {};
  window.PPAAdmin.apiPost = apiPost;
  window.PPAAdmin.postGenerate = function () { return apiPost('ppa_generate', buildPreviewPayload()); };
  window.PPAAdmin.postStore = function (mode) { return apiPost('ppa_store', buildStorePayload(mode || 'draft')); };
  window.PPAAdmin.markdownToHtml = markdownToHtml;
  window.PPAAdmin.renderPreview = renderPreview;
  window.PPAAdmin._js_ver = PPA_JS_VER;

  (function patchModuleBridge(){
    try {
      window.PPAAdminModules = window.PPAAdminModules || {};
      window.PPAAdminModules.api = window.PPAAdminModules.api || {};
      if (window.PPAAdminModules.api.apiPost !== apiPost) {
        window.PPAAdminModules.api.apiPost = apiPost;
      }
    } catch (e) {}
  })();

  console.info('PPA: admin.js initialized →', PPA_JS_VER);

})();
