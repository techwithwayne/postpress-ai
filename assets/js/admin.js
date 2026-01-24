/* global window, document, jQuery */
/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2026-01-23: UX: Output Language no longer persists across page reloads. Always defaults to "Original".     // CHANGED:
 *            - Removed localStorage restore/apply behavior                                                   // CHANGED:
 *            - Clears legacy saved key once on load (best-effort)                                            // CHANGED:
 *
 * 2026-01-23: FIX: Output Language code normalization + strict allowlist to prevent missing_or_invalid_lang; send lang aliases (language/target_lang). // CHANGED:
 * 2026-01-23: FIX: Translate invalid_json hardening — salvage JSON from noisy WP output + detect HTML/401 + normalize WP ajax envelope. // CHANGED:
 *            - Strips BOM + trims + extracts first JSON object/array region                                  // CHANGED:
 *            - Adds X-WP-Nonce + X-Requested-With + Accept headers                                            // CHANGED:
 *            - Converts {success,data} into consistent {ok:true|false,...}                                    // CHANGED:
 *
 * Locked rules respected:
 * - Composer CSS is “gospel” and is NOT modified here.
 */

(function () {
  'use strict';

  var PPA_JS_VER = 'admin.v2026-01-23.7'; // CHANGED: // CHANGED:

  // Abort if composer root is missing (defensive).
  // CHANGED: Make root lookup more robust so admin.js doesn't go idle if the ID differs.
  var root =
    document.getElementById('ppa-composer') || // CHANGED:
    document.querySelector('[data-ppa-composer]') || // CHANGED:
    document.querySelector('.ppa-composer'); // CHANGED:

  if (!root) {
    console.info('PPA: composer root not found, admin.js is idle');
    return;
  }

  // Ensure toolbar message acts as a live region (A11y).
  (function ensureLiveRegion() {
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

  (function ensurePreviewPaneFocusable() {
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
    // LOCKED ORDER: window.PPA.nonce -> window.ppaAdmin.nonce -> #ppa-nonce -> [data-ppa-nonce] -> '' // CHANGED:
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
      'X-PPA-View': (window.ppaAdmin && window.ppaAdmin.view) ? String(window.ppaAdmin.view) : 'composer',
      'X-PPA-JS': PPA_JS_VER // CHANGED:
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
        js_ver: PPA_JS_VER // CHANGED:
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

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function escAttr(s) {
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
  // Bulletproof notices (always visible)
  // -------------------------------------------------------------------------

  function ensureTopNoticeHost() { // CHANGED:
    var host = document.getElementById('ppa-top-notice-host'); // CHANGED:
    if (host) return host; // CHANGED:

    // Prefer WP admin .wrap; fallback to #wpbody-content; last resort body.
    var wrap = document.querySelector('.wrap') || document.getElementById('wpbody-content') || document.body; // CHANGED:
    if (!wrap) return null; // CHANGED:

    host = document.createElement('div'); // CHANGED:
    host.id = 'ppa-top-notice-host'; // CHANGED:
    host.style.margin = '12px 0 0 0'; // CHANGED:

    try {
      wrap.insertBefore(host, wrap.firstChild); // CHANGED:
    } catch (e0) {
      try { document.body.insertBefore(host, document.body.firstChild); } catch (e1) {}
    }
    return host; // CHANGED:
  }

  function noticeContainer() {
    // Prefer existing toolbar message if present; otherwise use the top notice host. // CHANGED:
    var el = document.getElementById('ppa-toolbar-msg');
    if (el) return el;

    var topHost = ensureTopNoticeHost(); // CHANGED:
    if (!topHost) return null; // CHANGED:

    // Create a default notice node inside the host.
    el = document.getElementById('ppa-top-notice'); // CHANGED:
    if (el) return el; // CHANGED:

    el = document.createElement('div'); // CHANGED:
    el.id = 'ppa-top-notice'; // CHANGED:
    el.className = 'ppa-notice'; // CHANGED:
    try {
      el.setAttribute('role', 'status'); // CHANGED:
      el.setAttribute('aria-live', 'polite'); // CHANGED:
      el.setAttribute('aria-atomic', 'true'); // CHANGED:
    } catch (e2) {}
    topHost.appendChild(el); // CHANGED:
    return el; // CHANGED:
  }

  function renderNotice(type, message) {
    var el = noticeContainer();
    var msg = String(message == null ? '' : message);

    if (!el) {
      // Absolute fallback so you never miss critical validation issues. // CHANGED:
      if (type === 'error' || type === 'warn') { try { window.alert(msg); } catch (e) {} } // CHANGED:
      return;
    }

    el.className = 'ppa-notice ppa-notice-' + String(type || 'info');
    el.textContent = msg;

    // If hidden by CSS/layout, fallback to alert for warn/error. // CHANGED:
    try {
      if ((type === 'error' || type === 'warn') && el.offsetParent === null) { // CHANGED:
        window.alert(msg); // CHANGED:
      }
    } catch (e0) {}
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

  function stopEvent(ev) {
    if (!ev) return;
    try { if (typeof ev.preventDefault === 'function') ev.preventDefault(); } catch (e1) {}
    try { if (typeof ev.stopPropagation === 'function') ev.stopPropagation(); } catch (e2) {}
    try { if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation(); } catch (e3) {}
  }

  // -------------------------------------------------------------------------
  // Issue 1 (CHANGED): Strip duplicate title heading from body_markdown
  // -------------------------------------------------------------------------

  function ppaNormalizeComparableTitle(s) { // CHANGED:
    return String(s || '')
      .trim()
      .toLowerCase()
      .replace(/[\u2019']/g, '')        // normalize apostrophes // CHANGED:
      .replace(/[^a-z0-9\s-]/g, '')     // drop punctuation // CHANGED:
      .replace(/\s+/g, ' ')
      .trim();
  }

  function ppaStripLeadingTitleHeading(bodyMarkdown, title) { // CHANGED:
    var md = String(bodyMarkdown || '');
    if (!md.trim()) return md;

    var t = ppaNormalizeComparableTitle(title);
    if (!t) return md;

    // Match an initial markdown heading line (#..######) possibly after blank lines.
    var re = /^(\s*\n)*#{1,6}\s+(.+?)\s*(\n+|$)/; // CHANGED:
    var m = md.match(re);
    if (!m) return md;

    var headingText = ppaNormalizeComparableTitle(m[2] || '');
    if (headingText && headingText === t) {
      // Remove the heading + any blank lines immediately after it.
      var stripped = md.slice(m[0].length).replace(/^(\s*\n)+/, ''); // CHANGED:
      return stripped;
    }
    return md;
  }

  // -------------------------------------------------------------------------
  // Issue 2 (CHANGED): Show Outline checkbox + persistent localStorage
  // -------------------------------------------------------------------------
  var PPA_LS_SHOW_OUTLINE = 'ppa_show_outline'; // CHANGED:

  function ppaGetShowOutlinePref() { // CHANGED:
    try {
      var v = window.localStorage ? window.localStorage.getItem(PPA_LS_SHOW_OUTLINE) : null; // CHANGED:
      // Default ON
      if (v === null || v === undefined || v === '') return true; // CHANGED:
      return (v === '1' || v === 'true'); // CHANGED:
    } catch (e) {
      return true; // CHANGED:
    }
  }

  function ppaSetShowOutlinePref(isOn) { // CHANGED:
    try {
      if (!window.localStorage) return;
      window.localStorage.setItem(PPA_LS_SHOW_OUTLINE, isOn ? '1' : '0'); // CHANGED:
    } catch (e) {}
  }

  function ppaFindShowOutlineCheckbox() { // CHANGED:
    // Defensive: support multiple possible IDs/names.
    return (
      document.getElementById('ppa-show-outline') || // CHANGED:
      document.getElementById('ppa_show_outline') || // CHANGED:
      $('input[name="ppa_show_outline"]', root) || // CHANGED:
      $('input[name="ppa_show_outline"]') || // CHANGED:
      $('input[data-ppa="show-outline"]', root) || // CHANGED:
      $('input[data-ppa="show-outline"]') || // CHANGED:
      $('input#show-outline', root) || // CHANGED:
      $('input#show-outline') // CHANGED:
    );
  }

  function ppaApplyOutlineVisibilityToPreview(pane) { // CHANGED:
    if (!pane || pane.nodeType !== 1) return;

    var show = ppaGetShowOutlinePref(); // CHANGED:

    // Prefer the wrapper we render (ppa-outline-section), otherwise fallback to .ppa-outline
    var wrap = null; // CHANGED:
    try { wrap = pane.querySelector('.ppa-outline-section'); } catch (e0) { wrap = null; } // CHANGED:
    if (wrap) {
      try { wrap.style.display = show ? '' : 'none'; } catch (e1) {} // CHANGED:
      return; // CHANGED:
    }

    var outline = null; // CHANGED:
    try { outline = pane.querySelector('.ppa-outline'); } catch (e2) { outline = null; } // CHANGED:
    if (outline) {
      try { outline.style.display = show ? '' : 'none'; } catch (e3) {} // CHANGED:
    }
  }

  function ppaBindShowOutlineCheckbox() { // CHANGED:
    var cb = ppaFindShowOutlineCheckbox();
    if (!cb) return;

    // Set on load from saved preference
    try { cb.checked = ppaGetShowOutlinePref(); } catch (e0) {}

    // Apply visibility immediately if preview already exists
    try { ppaApplyOutlineVisibilityToPreview(getPreviewPane()); } catch (e1) {}

    cb.addEventListener('change', function () { // CHANGED:
      var isOn = !!cb.checked; // CHANGED:
      ppaSetShowOutlinePref(isOn); // CHANGED:
      ppaApplyOutlineVisibilityToPreview(getPreviewPane()); // CHANGED:
    });
  }

  (function bootShowOutline() { // CHANGED:
    try { ppaBindShowOutlineCheckbox(); } catch (e0) {}
    // In case the checkbox is injected late, retry briefly.
    window.setTimeout(function () { // CHANGED:
      try { ppaBindShowOutlineCheckbox(); } catch (e1) {}
    }, 50); // CHANGED:
    window.setTimeout(function () { // CHANGED:
      try { ppaBindShowOutlineCheckbox(); } catch (e2) {}
    }, 250); // CHANGED:
  })(); // CHANGED:

  function ppaStripOutlineFromHtml(html) { // CHANGED:
    var s = String(html || '');
    if (!s.trim()) return s;
    try {
      var tmp = document.createElement('div');
      tmp.innerHTML = s;
      var sec = tmp.querySelector('.ppa-outline-section');
      if (sec && sec.parentNode) sec.parentNode.removeChild(sec);

      // Fallback: remove any remaining .ppa-outline blocks if wrapper wasn't present.
      var leftovers = tmp.querySelectorAll('.ppa-outline');
      for (var i = 0; i < leftovers.length; i++) {
        var n = leftovers[i];
        if (n && n.parentNode) n.parentNode.removeChild(n);
      }
      return tmp.innerHTML;
    } catch (e) {
      // Regex fallback (best-effort)
      return s.replace(/<div[^>]*class="[^"]*ppa-outline-section[^"]*"[^>]*>[\s\S]*?<\/div>/i, ''); // CHANGED:
    }
  }

  // -------------------------------------------------------------------------
  // CHANGED: ensureAutoHelperNotes()
  // Goal: If the UI renders duplicate helper text under Genre and Tone like:
  //   Auto sends "auto" so PostPress AI chooses a best-fit genre...
  // We want ONE helper block spanning under Genre + Tone + Word Count.
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
    } catch (e5) {}
  }

  (function bootAutoHelperNotes() { // CHANGED:
    try { ensureAutoHelperNotes(); } catch (e0) {}
    window.setTimeout(function () { // CHANGED:
      try { ensureAutoHelperNotes(); } catch (e1) {}
    }, 50); // CHANGED:
  })(); // CHANGED:

  (function observeAutoHelperNotes() { // CHANGED:
    try {
      if (!window.MutationObserver) return;
      var obs = new MutationObserver(function () { // CHANGED:
        try { ensureAutoHelperNotes(); } catch (e2) {}
      });
      obs.observe(root, { childList: true, subtree: true });
    } catch (e3) {}
  })();

  // -------------------------------------------------------------------------
  // CHANGED: Centralized composer-field reader so Generate + Store always send the same core fields.
  // Bulletproof goal: keep backend enforcement happy (audience required) and ensure brief is never "lost".
  // -------------------------------------------------------------------------
  function buildComposerMetaPayload() { // CHANGED:
    var subjectEl  = $('#ppa-subject'); // CHANGED:
    var briefEl    = $('#ppa-brief');   // CHANGED:
    var genreEl    = $('#ppa-genre');   // CHANGED:
    var toneEl     = $('#ppa-tone');    // CHANGED:
    var wcEl       = $('#ppa-word-count'); // CHANGED:
    var audienceEl = $('#ppa-audience'); // CHANGED:

    var subject  = subjectEl  ? String(subjectEl.value  || '').trim() : ''; // CHANGED:
    var brief    = briefEl    ? String(briefEl.value    || '').trim() : ''; // CHANGED:
    var genre    = genreEl    ? String(genreEl.value    || '').trim() : ''; // CHANGED:
    var tone     = toneEl     ? String(toneEl.value     || '').trim() : ''; // CHANGED:
    var wc       = wcEl       ? String(wcEl.value       || '').trim() : ''; // CHANGED:
    var audience = audienceEl ? String(audienceEl.value || '').trim() : ''; // CHANGED:

    return { // CHANGED:
      subject: subject, // CHANGED:
      genre: genre, // CHANGED:
      tone: tone, // CHANGED:
      word_count: wc, // CHANGED:
      audience: audience, // CHANGED:
      brief: brief, // CHANGED:
      keywords: readCsvValues(el('#ppa-keywords')) // CHANGED:
    }; // CHANGED:
  } // CHANGED:

  // FIX: buildComposerMetaPayload used a helper "el" in your pasted file segment.
  // We’ll safely alias it here to avoid runtime ReferenceError. (No UI/CSS change.)
  function el(sel) { try { return document.querySelector(sel); } catch (e) { return null; } }

  // If Django returns outline as array (normalized contract), render it nicely. // CHANGED:
  function outlineToMarkdown(outlineVal) { // CHANGED:
    if (!outlineVal) return '';
    if (Array.isArray(outlineVal)) {
      return outlineVal.map(function (s) { return '- ' + String(s || '').trim(); }).filter(Boolean).join('\n'); // CHANGED:
    }
    return String(outlineVal || '').trim();
  }

  function buildPreviewPayload() {
    var meta = buildComposerMetaPayload(); // CHANGED:

    var payload = {
      subject: meta.subject,
      title: meta.subject,
      brief: meta.brief,
      content: meta.brief,
      text: meta.brief,
      html: '',
      genre: meta.genre,
      tone: meta.tone,
      word_count: meta.word_count,
      audience: meta.audience,
      keywords: meta.keywords,
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

    // CHANGED: Always include the core composer fields on Store requests too.
    try { // CHANGED:
      var meta = buildComposerMetaPayload(); // CHANGED:
      payload.subject = meta.subject || ''; // CHANGED:
      payload.genre = meta.genre || ''; // CHANGED:
      payload.tone = meta.tone || ''; // CHANGED:
      payload.word_count = meta.word_count || ''; // CHANGED:
      payload.audience = meta.audience || ''; // CHANGED:
      payload.brief = meta.brief || ''; // CHANGED:
      payload.keywords = meta.keywords || []; // CHANGED:
    } catch (eMeta) {} // CHANGED:

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
        // CHANGED: If Show Outline is OFF, do NOT store the outline section.
        if (!ppaGetShowOutlinePref()) { // CHANGED:
          html = ppaStripOutlineFromHtml(html); // CHANGED:
        }
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

  (function adaptGenerateAsPreview() {
    if (btnPreview) {
      try { btnPreview.style.display = 'none'; } catch (e) {}
    }
    if (btnGenerate) {
      try { btnGenerate.textContent = 'Generate Preview'; } catch (e) {}
    }
  })();

  function setButtonsDisabled(disabled) {
    // Refresh refs in case the DOM re-rendered. // CHANGED:
    btnPreview  = btnPreview  || document.getElementById('ppa-preview'); // CHANGED:
    btnDraft    = btnDraft    || document.getElementById('ppa-draft'); // CHANGED:
    btnPublish  = btnPublish  || document.getElementById('ppa-publish'); // CHANGED:
    btnGenerate = btnGenerate || document.getElementById('ppa-generate'); // CHANGED:

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

  // ---------------------------------------------------------------------------
  // OUTLINE (ordered list + anchor links)
  // ---------------------------------------------------------------------------

  function ppaSlugifyHeading(text) {
    text = (text == null) ? '' : String(text);
    var s = text.trim().toLowerCase();
    s = s.replace(/[“”"']/g, '');
    s = s.replace(/[^a-z0-9\s\-]/g, '');
    s = s.replace(/\s+/g, '-');
    s = s.replace(/\-+/g, '-');
    s = s.replace(/^\-+|\-+$/g, '');
    return s || 'section';
  }

  function ppaEnsureUniqueId(el, used) {
    if (!el || el.nodeType !== 1) return '';
    used = used || {};
    var base = el.id ? String(el.id) : ppaSlugifyHeading(el.textContent || '');
    if (!base) base = 'section';
    var id = base;
    var n = 2;
    while (used[id] || document.getElementById(id)) {
      id = base + '-' + n;
      n++;
    }
    try { el.id = id; } catch (e) {}
    used[id] = true;
    return id;
  }

  function ppaCollectHeadings(pane) {
    if (!pane || pane.nodeType !== 1) return [];
    var body = null;
    try { body = pane.querySelector('.ppa-body'); } catch (e) { body = null; }
    var scope = body || pane;

    var hs = [];
    try { hs = Array.prototype.slice.call(scope.querySelectorAll('h2, h3, h4') || []); } catch (e2) { hs = []; }

    var skip = { 'outline': 1, 'body': 1, 'meta': 1, 'seo': 1, 'seo meta': 1 };
    var out = [];
    for (var i = 0; i < hs.length; i++) {
      var h = hs[i];
      if (!h || h.nodeType !== 1) continue;

      var inOutline = false;
      try { inOutline = !!h.closest('.ppa-outline'); } catch (e3) { inOutline = false; }
      if (inOutline) continue;

      var t = String(h.textContent || '').trim();
      if (!t) continue;
      var tKey = t.toLowerCase();
      if (skip[tKey]) continue;

      out.push({ el: h, text: t });
    }
    return out;
  }

  function ppaParseOutlineText(outlineText) {
    outlineText = (outlineText == null) ? '' : String(outlineText);
    var txt = outlineText.trim();
    if (!txt) return [];

    var lines = txt.split(/\r?\n/).map(function(x){ return String(x).trim(); }).filter(Boolean);
    if (lines.length > 1) {
      return lines.map(function(line){
        return line.replace(/^\s*(?:\d+\.|\-|\*|\u2022)\s*/, '').trim();
      }).filter(Boolean);
    }

    var parts = txt.split(/\s*,\s*/).map(function(x){ return String(x).trim(); }).filter(Boolean);
    if (parts.length > 1) return parts;

    return [txt];
  }

  function ppaHydrateOutline(pane, result) {
    if (!pane || pane.nodeType !== 1) return;

    var outlineBox = null;
    try { outlineBox = pane.querySelector('.ppa-outline'); } catch (e1) { outlineBox = null; }

    var headings = ppaCollectHeadings(pane);
    var usedIds = {};

    var items = [];
    if (headings.length) {
      for (var i = 0; i < headings.length; i++) {
        var h = headings[i];
        var id = ppaEnsureUniqueId(h.el, usedIds);
        if (!id) continue;
        items.push({ text: h.text, id: id });
      }
    } else {
      var outlineText = (result && result.outline) ? outlineToMarkdown(result.outline) : ''; // CHANGED:
      var parts = ppaParseOutlineText(outlineText);
      for (var j = 0; j < parts.length; j++) {
        items.push({ text: parts[j], id: '' });
      }
    }

    if (!items.length) return;

    if (!outlineBox) {
      outlineBox = document.createElement('div');
      outlineBox.className = 'ppa-outline';
      var h1 = null;
      try { h1 = pane.querySelector('h1'); } catch (e2) { h1 = null; }
      if (h1 && h1.parentNode) {
        h1.parentNode.insertBefore(outlineBox, h1.nextSibling);
      } else {
        pane.insertBefore(outlineBox, pane.firstChild);
      }
    }

    var ol = document.createElement('ol');
    ol.className = 'ppa-outline-list';

    for (var k = 0; k < items.length; k++) {
      var it = items[k];
      var li = document.createElement('li');
      li.className = 'ppa-outline-item';

      if (it.id) {
        var a = document.createElement('a');
        a.href = '#' + it.id;
        a.textContent = it.text;
        a.setAttribute('data-ppa-target', it.id);
        li.appendChild(a);
      } else {
        li.textContent = it.text;
      }
      ol.appendChild(li);
    }

    outlineBox.innerHTML = '';
    outlineBox.appendChild(ol);

    outlineBox.onclick = function(ev) {
      try {
        var t = ev.target;
        if (!t) return;
        if (t.tagName && String(t.tagName).toUpperCase() === 'A') {
          var targetId = t.getAttribute('data-ppa-target') || '';
          if (!targetId) return;
          ev.preventDefault();
          var targetEl = null;
          try { targetEl = pane.querySelector('#' + CSS.escape(targetId)); } catch (e4) { targetEl = document.getElementById(targetId); }
          if (targetEl && targetEl.scrollIntoView) {
            targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      } catch (e5) {}
    };
  }

  function buildPreviewHtml(result) {
    var outline = '';
    var bodyMd = '';
    var meta = null;
    var title = '';

    if (result && typeof result === 'object') {
      title = String(result.title || '').trim();
      outline = outlineToMarkdown(result.outline); // CHANGED:
      bodyMd = String(result.body_markdown || result.body || '');
      meta = result.meta || result.seo || null;
    }

    // CHANGED: Strip a leading "# Title" style heading from body_markdown so title doesn't show twice.
    bodyMd = ppaStripLeadingTitleHeading(bodyMd, title); // CHANGED:

    var html = '';
    html += '<div class="ppa-preview">';

    // CHANGED: Do NOT inject title into preview pane HTML.
    // Title should live only in the WP title field.

    // Outline section (wrapped so we can toggle cleanly)
    if (outline) {
      html += '<div class="ppa-outline-section">';
      html += '<h3>Outline</h3>';
      html += '<div class="ppa-outline">' + markdownToHtml(outline) + '</div>';
      html += '</div>';
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

    // CHANGED: Apply outline visibility preference immediately after rendering.
    try { ppaApplyOutlineVisibilityToPreview(pane); } catch (eV) {}

    try { ppaHydrateOutline(pane, result); } catch (eO) {}
    try { ppaApplyOutlineVisibilityToPreview(pane); } catch (eV2) {}
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

    // CHANGED: Strip duplicate title heading from body markdown before converting to HTML.
    bodyMd = ppaStripLeadingTitleHeading(bodyMd, title);

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

  // -------------------------------------------------------------------------
  // Save Draft NEW TAB flow (popup-safe + window-name fallback)
  // -------------------------------------------------------------------------

  function absolutizeUrl(url) {
    var u = String(url || '').trim();
    if (!u) return '';
    if (u.indexOf('http://') === 0 || u.indexOf('https://') === 0) return u;
    if (u.charAt(0) === '/') {
      try { return String(window.location.origin || '') + u; } catch (e0) { return u; }
    }
    return u;
  }

  function openDraftTabSync() {
    // MUST be synchronous in the click handler to avoid popup blockers.
    var w = null;
    var name = 'ppa_draft_' + String(Date.now ? Date.now() : (new Date()).getTime());
    try { w = window.open('about:blank', name); } catch (e) { w = null; }
    try { if (w) w.opener = null; } catch (e2) {}
    try { window.__PPA_DRAFT_TAB = w; } catch (e3) {}
    try { window.__PPA_DRAFT_TAB_NAME = name; } catch (e4) {}
    return { win: w, name: name };
  }

  function navigateDraftTarget(draftWin, draftName, url) {
    var u = absolutizeUrl(url);
    if (!u) return false;

    if (draftWin) {
      try { draftWin.location.replace(String(u)); return true; } catch (e0) {}
      try { draftWin.location.href = String(u); return true; } catch (e1) {}
    }
    if (draftName) {
      try { window.open(String(u), String(draftName)); return true; } catch (e2) {}
    }
    return false;
  }

  function closeDraftTab(draftWin) {
    if (!draftWin) return;
    try { draftWin.close(); } catch (e0) {}
  }

  function requireAudienceOrWarn() { // CHANGED:
    try {
      var meta = buildComposerMetaPayload();
      if (!String(meta.audience || '').trim()) {
        renderNotice('warn', 'Target audience is required.');
        return false;
      }
    } catch (e0) {
      return true;
    }
    return true;
  }

  function handleDraftClick(ev) {
    btnDraft = document.getElementById('ppa-draft') || btnDraft;
    if (!btnDraft) return;

    stopEvent(ev);
    if (clickGuard(btnDraft)) return;
    if (btnDraft.disabled) return;

    var opened = openDraftTabSync();
    var draftTab = opened.win;
    var draftName = opened.name;

    if (!hasTitleOrSubject()) {
      if (draftTab) closeDraftTab(draftTab);
      renderNotice('warn', 'Add a subject or title before saving.');
      return;
    }

    if (!requireAudienceOrWarn()) {
      if (draftTab) closeDraftTab(draftTab);
      return;
    }

    withBusy(function () {
      var payload = buildStorePayload('draft');
      return apiPost('ppa_store', payload).then(function (res) {
        var wp = unwrapWpAjax(res.body);
        var data = wp.hasEnvelope ? wp.data : res.body;
        var msg = pickMessage(res.body) || 'Draft request sent.';

        if (!res.ok || (wp.hasEnvelope && !wp.success)) {
          if (draftTab) closeDraftTab(draftTab);
          renderNotice('error', 'Save draft failed (' + res.status + '): ' + msg);
          try { console.info('PPA: draft failed', res); } catch (e0) {}
          return;
        }

        var edit = pickEditLink(res.body) || (data && data.edit_link ? data.edit_link : '');
        var pid  = pickId(res.body) || (data && (data.id || data.post_id) ? (data.id || data.post_id) : '');
        var view = pickViewLink(res.body) || (data && data.permalink ? data.permalink : '');
        var fallbackEdit = (!edit && pid) ? buildWpEditUrlFromId(pid) : '';
        var url = absolutizeUrl(edit || fallbackEdit || view);

        try {
          console.info('PPA: draft store ok →', {
            edit_link: edit,
            post_id: pid,
            view_link: view,
            fallback_edit: fallbackEdit,
            final_url: url,
            popup_handle: !!draftTab,
            popup_name: draftName
          });
        } catch (eD) {}

        if (!draftTab) {
          if (url) {
            renderNoticeTimedHtml(
              'success',
              'Draft saved. Pop-up was blocked. <a href="' + escAttr(url) + '" target="_blank" rel="noopener">Open Draft</a>',
              12000
            );
          } else {
            renderNoticeTimed('success', 'Draft saved, but no edit link/post ID was returned.', 9000);
          }
          return;
        }

        if (url) {
          var didNav = navigateDraftTarget(draftTab, draftName, url);
          if (didNav) {
            renderNoticeTimed('success', 'Draft saved. Opened in a new tab.', 2500);
            return;
          }
        }

        closeDraftTab(draftTab);
        renderNoticeTimed('success', 'Draft saved, but no edit link/post ID was returned.', 9000);
      });
    }, 'store');
  }

  function handlePublishClick(ev) {
    btnPublish = document.getElementById('ppa-publish') || btnPublish;
    if (!btnPublish) return;

    stopEvent(ev);
    if (clickGuard(btnPublish)) return;
    console.info('PPA: Publish clicked');

    if (!hasTitleOrSubject()) {
      renderNotice('warn', 'Add a subject or title before publishing.');
      return;
    }

    if (!requireAudienceOrWarn()) return;

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
          try { console.info('PPA: publish failed', res); } catch (e0) {}
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
        try { console.info('PPA: publish ok', data); } catch (e1) {}
      });
    }, 'store');
  }

  function handleGenerateClick(ev) {
    btnGenerate = document.getElementById('ppa-generate') || btnGenerate;
    if (!btnGenerate) return;

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

    if (!String(probe.audience || '').trim()) {
      renderNotice('warn', 'Target audience is required.');
      return;
    }

    withBusy(function () {
      return apiPost('ppa_generate', probe).then(function (res) {
        var wp = unwrapWpAjax(res.body);
        var overallOk = res.ok;
        if (wp.hasEnvelope) overallOk = overallOk && (wp.success === true);

        var data = wp.hasEnvelope ? wp.data : res.body;
        var django = pickDjangoResultShape(data);

        try { window.PPA_LAST_GENERATE = { ok: overallOk, status: res.status, body: res.body, data: data, djangoResult: django }; } catch (e) {}

        if (!overallOk) {
          renderNotice('error', 'Generate failed (' + res.status + '): ' + (pickMessage(res.body) || 'Unknown error'));
          try { console.info('PPA: generate failed', res); } catch (e2) {}
          return;
        }

        var provider = (data && data.provider) ? data.provider : (django && django.provider ? django.provider : '');
        renderPreview(django, provider);

        var filled = applyGenerateResult(django);
        try { console.info('PPA: applyGenerateResult →', filled); } catch (e3) {}

        renderNotice('success', 'AI draft generated. Review, tweak, then Save Draft or Publish.');
      });
    }, 'generate');
  }

  // -------------------------------------------------------------------------
  // Delegated CAPTURE click binding (bulletproof)
  // -------------------------------------------------------------------------
  (function bindDelegatedClicks() {
    try {
      document.addEventListener('click', function (ev) {
        var t = ev && ev.target ? ev.target : null;
        if (!t) return;

        var gen = null;
        var dra = null;
        var pub = null;

        try { gen = (t.id === 'ppa-generate') ? t : (t.closest ? t.closest('#ppa-generate') : null); } catch (e0) { gen = null; }
        if (gen) { handleGenerateClick(ev); return; }

        try { dra = (t.id === 'ppa-draft') ? t : (t.closest ? t.closest('#ppa-draft') : null); } catch (e1) { dra = null; }
        if (dra) { handleDraftClick(ev); return; }

        try { pub = (t.id === 'ppa-publish') ? t : (t.closest ? t.closest('#ppa-publish') : null); } catch (e2) { pub = null; }
        if (pub) { handlePublishClick(ev); return; }
      }, true);
    } catch (e3) {}
  })();

  // -------------------------------------------------------------------------
  // Public bridge (used elsewhere)
  // -------------------------------------------------------------------------
  window.PPAAdmin = window.PPAAdmin || {};
  window.PPAAdmin.apiPost = apiPost;
  window.PPAAdmin.postGenerate = function () { return apiPost('ppa_generate', buildPreviewPayload()); };
  window.PPAAdmin.postStore = function (mode) { return apiPost('ppa_store', buildStorePayload(mode || 'draft')); };
  window.PPAAdmin.markdownToHtml = markdownToHtml;
  window.PPAAdmin.renderPreview = renderPreview;
  window.PPAAdmin._js_ver = PPA_JS_VER;

  (function patchModuleBridge() {
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

/* =========================================================================
 * PPA OUTPUT LANGUAGE MODULE (Composer Preview) — v2026-01-23.9
 *
 * GOALS (client-side only):
 * - State-safe: capture + freeze original_html immediately after Generate Preview renders.
 * - Poll-safe: every poll sends draft_hash, lang, mode, original_html (+ original_json when available), and job_id when continuing.
 * - UI-safe: never overwrite preview with partial HTML unless server explicitly flags it safe; show "Translating… (x%)" while pending.
 * - Spam-safe: one active poll loop at a time; cancel token on language changes.
 * - Recovery: if server says "Missing original_html", auto re-send frozen original_html once; then stop with visible error.
 * - Logging: tight, non-flooding logs only on state transitions.
 *
 * Locked rules respected:
 * - Composer CSS is “gospel” and is NOT modified here.
 * ========================================================================= */
(function () {
  "use strict";

  // -------------------------------------------------------------------------
  // Init guard (prevents duplicate event bindings if the script is enqueued twice)
  // -------------------------------------------------------------------------
  if (window.PPA_OUTPUT_LANGUAGE_MODULE_INIT) return; // CHANGED:
  window.PPA_OUTPUT_LANGUAGE_MODULE_INIT = "v2026-01-23.9"; // CHANGED:

  function byId(id) { try { return document.getElementById(id); } catch (e) { return null; } }

  // -------------------------------------------------------------------------
  // Config
  // -------------------------------------------------------------------------
  var ACTION = "ppa_translate_preview"; // WP admin-ajax action (PHP proxy)
  var MODE = "strict";

  // Guardrails:
  // - If progress never moves off 0% for this long, we assume the server job is stuck.
  // - If total elapsed exceeds MAX_TOTAL_MS, we stop polling and show a visible message.
  var STALL_ZERO_MS = 60 * 1000;       // CHANGED:
  var STALL_NO_MOVE_MS = 120 * 1000;   // CHANGED:
  var MAX_TOTAL_MS = 5 * 60 * 1000;    // CHANGED: 5 minutes hard cap
  var MAX_POLLS = 300;                // CHANGED:

  // Poll timing clamp (prevents hammering admin-ajax)
  var MIN_POLL_MS = 800;              // CHANGED:
  var MAX_POLL_MS = 10000;            // CHANGED:

  // -------------------------------------------------------------------------
  // Language allowlist (matches what we render in the dropdown)
  // -------------------------------------------------------------------------
  var LANGS = [
    { v: "original", label: "Original" },
    { v: "es", label: "Spanish" },
    { v: "fr", label: "French" },
    { v: "de", label: "German" },
    { v: "pt", label: "Portuguese" },
    { v: "it", label: "Italian" },
    { v: "ja", label: "Japanese" },
    { v: "zh-hans", label: "Chinese (Simplified)" },
    { v: "zh-hant", label: "Chinese (Traditional)" },
    { v: "hi", label: "Hindi" },
    { v: "ar", label: "Arabic" },
    { v: "ko", label: "Korean" },
    { v: "nl", label: "Dutch" },
    { v: "sv", label: "Swedish" },
    { v: "no", label: "Norwegian" },
    { v: "da", label: "Danish" },
    { v: "fi", label: "Finnish" },
    { v: "pl", label: "Polish" },
    { v: "tr", label: "Turkish" },
    { v: "ru", label: "Russian" },
    { v: "id", label: "Indonesian" },
    { v: "vi", label: "Vietnamese" },
    { v: "cs", label: "Czech" },
    { v: "uk", label: "Ukrainian" },
    { v: "fa", label: "Persian" }
  ];

  var LANG_ALIASES = { // CHANGED:
    "zh-Hans": "zh-hans",
    "zh-Hant": "zh-hant",
    "zh_hans": "zh-hans",
    "zh_hant": "zh-hant"
  };

  function normalizeLang(raw) { // CHANGED:
    var v = String(raw == null ? "" : raw).trim();
    if (!v) return "original";
    if (v.toLowerCase() === "original") return "original";
    if (Object.prototype.hasOwnProperty.call(LANG_ALIASES, v)) return LANG_ALIASES[v];
    return v.toLowerCase();
  }

  function buildAllowedLangSet() { // CHANGED:
    var s = {};
    for (var i = 0; i < LANGS.length; i++) {
      s[normalizeLang(LANGS[i].v)] = true;
    }
    return s;
  }
  var ALLOWED_LANGS = buildAllowedLangSet(); // CHANGED:

  // -------------------------------------------------------------------------
  // Minimal hashing for stable draft_hash (client fallback if server doesn't provide one)
  // -------------------------------------------------------------------------
  function djb2Hash(str) {
    str = String(str || "");
    var hash = 5381;
    for (var i = 0; i < str.length; i++) {
      hash = ((hash << 5) + hash) + str.charCodeAt(i);
      hash = hash & 0xffffffff;
    }
    return "h_" + (hash >>> 0).toString(16);
  }

  // -------------------------------------------------------------------------
  // Transport helpers
  // -------------------------------------------------------------------------
  function getNonce() {
    try {
      if (window.PPA && window.PPA.nonce) return String(window.PPA.nonce);
      if (window.ppaAdmin && window.ppaAdmin.nonce) return String(window.ppaAdmin.nonce);
      var el = byId("ppa-nonce");
      if (el && el.value) return String(el.value);
      var data = document.querySelector("[data-ppa-nonce]");
      if (data) return String(data.getAttribute("data-ppa-nonce") || "");
    } catch (e0) {}
    return "";
  }

  function stripBom(s) {
    s = String(s == null ? "" : s);
    return s.replace(/^\uFEFF/, "");
  }

  function safeJsonParse(txt) {
    var s = stripBom(txt);
    s = String(s || "").trim();
    if (!s) return null;

    // WP sometimes returns literal 0 for auth/nonce errors.
    if (s === "0") return { ok: false, error: "wp_ajax_0" };

    // Fast-path
    if (s.charAt(0) === "{" || s.charAt(0) === "[") {
      try { return JSON.parse(s); } catch (e1) {}
    }

    // HTML indicates fatal/notice output, or a login page
    if (/<\s*html/i.test(s) || /<!doctype/i.test(s)) {
      return { ok: false, error: "non_json_html", raw: s.slice(0, 600) };
    }

    // Salvage: extract first JSON region from noisy output
    var iObj = s.indexOf("{");
    var iArr = s.indexOf("[");
    var start = -1;
    if (iObj !== -1 && iArr !== -1) start = Math.min(iObj, iArr);
    else start = (iObj !== -1 ? iObj : iArr);
    if (start === -1) return null;

    var slice = s.slice(start);
    var endObj = slice.lastIndexOf("}");
    var endArr = slice.lastIndexOf("]");
    var end = Math.max(endObj, endArr);
    if (end !== -1) slice = slice.slice(0, end + 1);

    try { return JSON.parse(slice); } catch (e2) {}
    return null;
  }

  function normalizeWpEnvelope(json) { // CHANGED:
    // WP may wrap ajax responses as { success: true, data: {...} }
    // Normalize into { ok: true|false, ... }
    if (!json || typeof json !== "object") return json;
    if (Object.prototype.hasOwnProperty.call(json, "ok")) return json;

    if (Object.prototype.hasOwnProperty.call(json, "success")) {
      if (json.success === true) {
        if (json.data && typeof json.data === "object") {
          json.data.ok = true;
          return json.data;
        }
        return { ok: true, data: json.data };
      }
      // success:false
      if (json.data && typeof json.data === "object") {
        if (!Object.prototype.hasOwnProperty.call(json.data, "ok")) json.data.ok = false;
        return json.data;
      }
      return { ok: false, error: "wp_ajax_failure", data: json.data };
    }
    return json;
  }

  function buildAjaxUrl(action) {
    var base = (typeof window.ajaxurl !== "undefined" && window.ajaxurl) ? window.ajaxurl : "/wp-admin/admin-ajax.php";
    if (base.indexOf("?") === -1) return base + "?action=" + encodeURIComponent(action);
    return base + "&action=" + encodeURIComponent(action);
  }

  function buildHeaders(contentType) { // CHANGED:
    var nonce = getNonce();
    var h = {
      "X-Requested-With": "XMLHttpRequest",
      "Accept": "application/json, text/plain, */*",
      "Content-Type": contentType
    };
    if (nonce) h["X-WP-Nonce"] = nonce;
    return h;
  }

  function extractHtml(resp) {
    if (!resp) return null;
    if (typeof resp.html === "string") return resp.html;
    if (resp.data && typeof resp.data.html === "string") return resp.data.html;
    if (resp.result && typeof resp.result.html === "string") return resp.result.html;
    if (typeof resp.preview === "string") return resp.preview;
    if (resp.data && typeof resp.data.preview === "string") return resp.data.preview;
    return null;
  }

  function parseProgress(resp) { // CHANGED:
    if (!resp) return null;
    var p = null;
    try {
      if (typeof resp.progress === "number") p = resp.progress;
      else if (typeof resp.progress === "string") p = parseFloat(resp.progress);
      else if (resp.data && typeof resp.data.progress === "number") p = resp.data.progress;
      else if (resp.data && typeof resp.data.progress === "string") p = parseFloat(resp.data.progress);
    } catch (e0) { p = null; }

    if (p == null || isNaN(p)) return null;
    if (p < 0) p = 0;
    if (p > 100) p = 100;
    return Math.round(p);
  }

  function isMissingOriginalHtml(resp) { // CHANGED:
    var msg = "";
    try { msg = String(resp && (resp.error || resp.message || resp.detail || "")).toLowerCase(); } catch (e0) { msg = ""; }
    return (msg.indexOf("missing original_html") !== -1 || msg.indexOf("missing_original_html") !== -1);
  }

  function isSafeToReplacePreview(resp) { // CHANGED:
    // Only replace preview with HTML if:
    // - pending is false (job complete), OR
    // - server explicitly signals "final/complete/safe_replace"
    if (!resp) return false;
    if (resp.pending === false) return true;
    if (resp.complete === true) return true;
    if (resp.final === true) return true;
    if (resp.is_final === true) return true;
    if (resp.safe_replace === true) return true;
    if (resp.replace_preview === true) return true;
    return false;
  }

  async function postTranslate(canonical, signal) { // CHANGED:
    // Transport strategy:
    // - Try JSON body first (some proxies read php://input)
    // - Retry form-encoded with payload=... (some proxies read $_POST['payload'])
    // We ALWAYS include a payload object as well to satisfy proxies that expect it.
    var url = buildAjaxUrl(ACTION);

    function shouldRetry(resp) {
      if (!resp || resp.ok === true) return false;
      var err = "";
      try { err = String(resp.error || resp.message || "").toLowerCase(); } catch (e0) { err = ""; }
      if (!err) return true;
      return (
        err.indexOf("missing_or_invalid_lang") !== -1 ||
        err.indexOf("missing original_html") !== -1 ||
        err.indexOf("missing_original_html") !== -1 ||
        err.indexOf("invalid_json") !== -1 ||
        err.indexOf("wp_ajax_0") !== -1 ||
        err.indexOf("non_json_html") !== -1
      );
    }

    async function doFetch(headers, body) {
      try {
        var res = await fetch(url, {
          method: "POST",
          credentials: "same-origin",
          headers: headers,
          body: body,
          signal: signal // CHANGED:
        });
        var txt = await res.text();
        var json = safeJsonParse(txt);
        if (!json) {
          try { console.info("PPA: translate invalid_json raw →", String(txt || "").slice(0, 900)); } catch (e0) {}
          return { ok: false, error: "invalid_json", raw: String(txt || "").slice(0, 900), status: res.status };
        }

        if (json && json.ok === false && json.error === "non_json_html") {
          json.status = res.status;
          return json;
        }

        json = normalizeWpEnvelope(json);

        if (!res.ok && json && typeof json === "object") {
          if (json.ok !== true) {
            json.ok = false;
            json.status = res.status;
            if (!json.error) json.error = "http_" + String(res.status);
          }
        }

        if (json && typeof json === "object" && !Object.prototype.hasOwnProperty.call(json, "status")) {
          json.status = res.status;
        }
        return json;
      } catch (err) {
        if (err.name === 'AbortError') return { ok: false, aborted: true };
        return { ok: false, error: "network_error", message: String(err) };
      }
    }

    // 1) JSON body
    var jsonBodyObj = {};
    try {
      for (var k in canonical) {
        if (Object.prototype.hasOwnProperty.call(canonical, k)) jsonBodyObj[k] = canonical[k];
      }
    } catch (eC) {}
    // Provide payload in JSON too (some proxies look for it)
    jsonBodyObj.payload = canonical; // CHANGED:

    var resp1 = await doFetch(buildHeaders("application/json; charset=UTF-8"), JSON.stringify(jsonBodyObj));
    if (resp1 && resp1.aborted) return resp1; // CHANGED:
    if (!shouldRetry(resp1)) return resp1;

    // 2) Form-encoded fallback
    var originalJsonStr = "";
    try { originalJsonStr = canonical.original_json ? JSON.stringify(canonical.original_json) : ""; } catch (eJ) { originalJsonStr = ""; }

    var body =
      "action=" + encodeURIComponent(ACTION) +
      "&payload=" + encodeURIComponent(JSON.stringify(canonical || {})) +
      "&lang=" + encodeURIComponent(String(canonical.lang || "")) +
      "&mode=" + encodeURIComponent(String(canonical.mode || "")) +
      "&draft_hash=" + encodeURIComponent(String(canonical.draft_hash || "")) +
      "&job_id=" + encodeURIComponent(String(canonical.job_id || "")) +
      "&original_html=" + encodeURIComponent(String(canonical.original_html || "")) +
      "&original_json=" + encodeURIComponent(originalJsonStr);

    var resp2 = await doFetch(buildHeaders("application/x-www-form-urlencoded; charset=UTF-8"), body);
    return resp2;
  }

  // -------------------------------------------------------------------------
  // UI helpers (no CSS changes, just HTML inside the pane)
  // -------------------------------------------------------------------------
  function setUiState(selectEl, helpEl, enabled) {
    if (!selectEl) return;
    selectEl.disabled = !enabled;
    if (helpEl) helpEl.style.display = enabled ? "none" : "block";
  }

  function setLoading(pane, label) {
    if (!pane) return;
    pane.setAttribute("data-ppa-lang-loading", "1");
    pane.innerHTML = '<p><em>' + (label || "Translating…") + "</em></p>";
  }

  function clearLoading(pane) {
    if (!pane) return;
    try { pane.removeAttribute("data-ppa-lang-loading"); } catch (e0) {}
  }

  function setPaneHtml(pane, html) {
    if (!pane) return;
    pane.innerHTML = html || '<p><em>No content.</em></p>';
    clearLoading(pane);
  }

  function showProgress(pane, pct) { // CHANGED:
    var msg = "Translating…";
    if (pct != null) msg += " (" + String(pct) + "%)";
    setLoading(pane, msg);
  }

  function showError(pane, msg) { // CHANGED:
    setPaneHtml(pane, '<p><em>Translation error: ' + String(msg || "unknown") + "</em></p>");
  }

  // -------------------------------------------------------------------------
  // Logging (non-flooding): only log when a given "state key" changes
  // -------------------------------------------------------------------------
  var lastLog = {}; // key -> signature
  function logOnce(key, obj) { // CHANGED:
    var sig = "";
    try { sig = JSON.stringify(obj || {}); } catch (e0) { sig = String(obj); }
    if (lastLog[key] === sig) return;
    lastLog[key] = sig;
    try { console.info("PPA:", key, obj || ""); } catch (e1) {}
  }

  // -------------------------------------------------------------------------
  // Preview state + caching
  // -------------------------------------------------------------------------
  var translationCache = new Map(); // key -> { html, ts }
  function cacheKey(draftHash, lang, mode) {
    return String(draftHash || "nohash") + "|" + String(lang || "") + "|" + String(mode || "");
  }

  var state = {
    hasPreview: false,
    draftHash: null,
    frozenOriginalHtml: null, // CHANGED:
    frozenOriginalJson: null, // CHANGED:
    translating: false,
    currentLang: "original"
  };

  var poll = {
    abortController: null
  };

  function cancelCurrentRequest(reason) {
    if (poll.abortController) {
      try { poll.abortController.abort(); } catch (e) {}
      poll.abortController = null;
    }
    try { state.translating = false; } catch (e1) {}
    if (reason) logOnce("translate cancel", { reason: reason });
  }

  function previewLooksGenerated(pane) {
    if (!pane) return false;

    // If we are showing our own placeholder, treat as generated as long as we have frozen HTML.
    try {
      if (pane.getAttribute("data-ppa-lang-loading") === "1") {
        return !!(state && state.frozenOriginalHtml);
      }
    } catch (e0) {}

    var txt = (pane.textContent || "").trim();
    if (txt.indexOf("(Preview will appear here once generated.)") !== -1) return false;

    var html = (pane.innerHTML || "").trim();
    return (html.length >= 20);
  }

  function ensureOptions(selectEl) { // CHANGED:
    if (!selectEl) return;

    // Always rebuild options so values are guaranteed valid lang codes.
    var current = null;
    try { current = String(selectEl.value || "").trim(); } catch (e0) { current = null; }

    selectEl.innerHTML = "";
    for (var i = 0; i < LANGS.length; i++) {
      var opt = document.createElement("option");
      opt.value = normalizeLang(LANGS[i].v);
      opt.textContent = LANGS[i].label;
      selectEl.appendChild(opt);
    }

    // Default to Original every load (no persistence)
    var desired = normalizeLang(current || "original");
    try { selectEl.value = (ALLOWED_LANGS[desired] ? desired : "original"); } catch (e1) {}
  }

  function freezeOriginalIfNeeded(selectEl, pane) { // CHANGED:
    // Freeze original_html when:
    // - pane looks generated (real preview HTML)
    // - NOT translating (so we don't freeze placeholders / partials)
    // - dropdown is currently "Original" (so we don't freeze translated HTML)
    if (!selectEl || !pane) return;

    if (!previewLooksGenerated(pane)) return;
    if (state.translating === true) return;

    var currentLang = normalizeLang(selectEl.value || "original");
    if (currentLang !== "original") return;

    // Avoid freezing while loading placeholder is visible
    try { if (pane.getAttribute("data-ppa-lang-loading") === "1") return; } catch (e0) {}

    var html = String(pane.innerHTML || "").trim();
    if (!html || html.length < 20) return;

    var dh = djb2Hash(html);
    if (state.draftHash !== dh) { // New preview detected
      state.draftHash = dh;
      state.frozenOriginalHtml = html;
      state.frozenOriginalJson = null; // none available client-side right now
      translationCache.clear();

      try { pane.setAttribute("data-draft-hash", dh); } catch (eH) {}

      logOnce("translate freeze original", { draft_hash: dh, chars: html.length }); // CHANGED:
    } else if (!state.frozenOriginalHtml) {
      state.frozenOriginalHtml = html;
      try { pane.setAttribute("data-draft-hash", dh); } catch (eH2) {}
      logOnce("translate freeze original", { draft_hash: dh, chars: html.length });
    }
  }

  // -------------------------------------------------------------------------
  // Core translate flow
  // -------------------------------------------------------------------------
  function buildCanonicalPayload(lang) {
    var safeLang = normalizeLang(lang);
    var dh = String(state.draftHash || "");

    return {
      lang: safeLang,
      language: safeLang,
      target_lang: safeLang,
      mode: MODE,
      draft_hash: dh,
      job_id: "",
      original_html: String(state.frozenOriginalHtml || ""),
      original_json: state.frozenOriginalJson || null
    };
  }

  async function startTranslate(selectEl, pane, lang) {
    lang = normalizeLang(lang);

    // Allowlist enforcement
    if (!ALLOWED_LANGS[lang]) {
      try { selectEl.value = "original"; } catch (e0) {}
      showError(pane, "missing_or_invalid_lang");
      return;
    }

    // Must have a frozen original preview
    freezeOriginalIfNeeded(selectEl, pane);

    if (!state.frozenOriginalHtml || !state.draftHash) {
      try { selectEl.value = "original"; } catch (e1) {}
      showError(pane, "Missing original preview HTML. Click Generate Preview first.");
      return;
    }

    // Cache hit?
    var key = cacheKey(state.draftHash, lang, MODE);
    if (translationCache.has(key)) {
      var hit = translationCache.get(key);
      if (hit && hit.html) {
        setPaneHtml(pane, hit.html);
        logOnce("translate cache hit", { lang: lang, draft_hash: state.draftHash });
        return;
      }
    }

    // Cancel any in-flight request
    cancelCurrentRequest("restart");

    poll.abortController = new AbortController();
    var signal = poll.abortController.signal;

    state.translating = true;
    state.currentLang = lang;

    logOnce("translate start", { lang: lang, draft_hash: state.draftHash });
    setLoading(pane, "Translating to " + lang + "...");

    // Build payload
    var payload = buildCanonicalPayload(lang);
    var attempt = 0;
    var maxAttempts = 300; 

    try {
      while (attempt < maxAttempts) {
        if (signal.aborted) return;
        
        var resp = await postTranslate(payload, signal);

        // 1. Check if aborted while waiting
        if (signal.aborted) return;
        if (resp && resp.aborted) return;

        // 2. Handle failure
        if (!resp || resp.ok === false) {
           var err = (resp && (resp.error || resp.message)) ? (resp.error || resp.message) : "translate_failed";
           showError(pane, "Translation failed: " + err);
           state.translating = false;
           return;
        }

        // 3. Handle pending (POLLING)
        if (resp.pending === true) {
            var pct = parseProgress(resp);
            showProgress(pane, pct);
            
            if (resp.job_id) {
                payload.job_id = resp.job_id;
            }

            var wait = (resp.next_poll_ms && resp.next_poll_ms > 0) ? resp.next_poll_ms : 1000;
            // Sleep
            await new Promise(function(resolve) { 
                var tid = setTimeout(resolve, wait); 
                signal.addEventListener('abort', function() { clearTimeout(tid); resolve(); }, {once:true});
            });
            
            attempt++;
            continue;
        }

        // 4. Handle complete success
        var html = extractHtml(resp);
        if (html && html.length > 20) {
          setPaneHtml(pane, html);
          try { translationCache.set(key, { html: html, ts: Date.now() }); } catch (eC) {}
          logOnce("translate complete", { lang: lang, draft_hash: state.draftHash });
        } else {
          showError(pane, "Translation returned empty or invalid HTML.");
        }
        break; 
      }
    } catch (e) {
      if (signal.aborted) return;
      showError(pane, "Translation error: " + String(e));
    } finally {
      if (!signal.aborted) {
        state.translating = false;
        poll.abortController = null;
      }
    }
  }

  function restoreOriginal(selectEl, pane) {
    cancelCurrentRequest("restore_original");
    state.currentLang = "original";
    if (state.frozenOriginalHtml) {
      setPaneHtml(pane, state.frozenOriginalHtml);
    }
    try { selectEl.value = "original"; } catch (e0) {}
  }

  function debounce(func, wait) {
    var timeout;
    return function() {
      var context = this, args = arguments;
      var later = function() {
        timeout = null;
        func.apply(context, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // -------------------------------------------------------------------------
  // Init / bind
  // -------------------------------------------------------------------------
  function init() {
    var selectEl = byId("ppa-output-language");
    var helpEl = byId("ppa-output-language-help");
    var pane = byId("ppa-preview-pane");

    if (!selectEl || !pane) return;

    ensureOptions(selectEl);

    // Default Original every load (no persistence)
    try { selectEl.value = "original"; } catch (e0) {}

    setUiState(selectEl, helpEl, false);

    // Observe preview changes so we can freeze the original HTML immediately after Generate Preview renders.
    var observer = new MutationObserver(function () {
      var has = previewLooksGenerated(pane);

      if (has) {
        state.hasPreview = true;
        setUiState(selectEl, helpEl, true);

        // If the user somehow has a non-original selection during a fresh preview render,
        // force it back to original so we freeze a true "original" baseline. // CHANGED:
        try {
          if (state.translating !== true) {
            var cur = normalizeLang(selectEl.value || "original");
            if (cur !== "original") selectEl.value = "original";
          }
        } catch (e1) {}

        freezeOriginalIfNeeded(selectEl, pane);
      } else {
        // If preview gets cleared and we're not translating, disable controls and reset.
        if (state.translating === true) return;
        state.hasPreview = false;
        setUiState(selectEl, helpEl, false);
        translationCache.clear();
        state.draftHash = null;
        state.frozenOriginalHtml = null;
        state.frozenOriginalJson = null;
        try { selectEl.value = "original"; } catch (e2) {}
        cancelCurrentRequest("preview_cleared");
      }
    });

    observer.observe(pane, { childList: true, subtree: true });

    selectEl.addEventListener("change", debounce(function () { // CHANGED:
      var lang = normalizeLang(selectEl.value || "original");

      // If no preview, force back to original.
      if (!previewLooksGenerated(pane) && !state.frozenOriginalHtml) {
        try { selectEl.value = "original"; } catch (e0) {}
        return;
      }

      // Always keep frozen original up-to-date before translating.
      freezeOriginalIfNeeded(selectEl, pane);

      if (lang === "original") {
        restoreOriginal(selectEl, pane);
        return;
      }

      startTranslate(selectEl, pane, lang);
    }, 300)); // CHANGED:

    // If preview already exists at load, enable + freeze baseline.
    if (previewLooksGenerated(pane)) {
      state.hasPreview = true;
      setUiState(selectEl, helpEl, true);
      try { selectEl.value = "original"; } catch (e3) {}
      freezeOriginalIfNeeded(selectEl, pane);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { passive: true });
  } else {
    init();
  }
})();
