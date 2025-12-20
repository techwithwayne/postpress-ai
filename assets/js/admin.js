/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.2: Fix PPAAdmin export merge so new helpers always exist (postGenerate no longer undefined); // CHANGED:
 *               add robust module bridge polling + on-demand patch so module apiPost equals legacy apiPost. // CHANGED:
 * 2025-12-20.1: Nonce priority fix — prefer ppaAdmin.nonce before data-ppa-nonce to prevent wp_rest nonce being sent to admin-ajax actions. // CHANGED:
 * 2025-12-09.1: Expose selected helpers on window.PPAAdmin for safe future modularization (no behavior change). // CHANGED:
 * 2025-11-18.7: Clean AI-generated titles to strip trailing ellipsis/punctuation before filling WP title. // CHANGED:
 * 2025-11-18.6: Hide Preview button in Composer and rename Generate Draft → Generate Preview.         // CHANGED:
 * 2025-11-18.5: Preview now builds HTML from AI title + body/markdown/text instead of only raw html; // CHANGED:
 *               JSON diagnostic fallback kept only as last resort.                                   // CHANGED:
 * 2025-11-17.2: Enhance Markdown → HTML rendering for generate preview (headings, lists, inline em/strong). // CHANGED:
 * 2025-11-17: Wire target audience field into preview payload.                                       // CHANGED:
 * 2025-11-16.3: Wire ppa_generate (Generate button) to AI /generate/ endpoint; render structured draft + SEO    // CHANGED:
 *               meta in preview and auto-fill core fields where empty.                                         // CHANGED:
 * 2025-11-16.2: Clarify draft success notice to mention WordPress draft; bump JS internal version.              // CHANGED:
 * 2025-11-16: Add mode hint to store payloads (draft/publish/update) for Django/WP store pipeline.               // CHANGED:
 * 2025-11-15: Add X-PPA-View ('composer') and X-Requested-With headers for Composer AJAX parity with Django logs;  // CHANGED:
 *             keeps existing payload/UX unchanged while improving diagnostics.                                      // CHANGED:
 * 2025-11-11.6: Always render preview from result.html (fallback to content); set data-ppa-provider on pane;    // CHANGED:
 *               de-duplicate readCsvValues; add window.PPA_LAST_PREVIEW debug hook; version bump.               // CHANGED:
 * 2025-11-11.5: Preview payload now maps UI fields for Django normalize endpoint:
 *               - subject → title, brief → content (+html/text synonyms).
 *               - If no HTML returned, show JSON diagnostic in preview pane.
 * 2025-11-11.4: Add early guard for Preview when both subject & brief are empty (UX); DevTools test hook.
 * 2025-11-11.3: Support nested preview shapes (data.result.content/html, result.content/html); provider pick.
 * 2025-11-11:   Version parity check; set window.PPA._tagJsVer; ES5-safe; data-attrs only if #ppa-composer.
 * 2025-10..2025-11: Prior fixes & UX polish (see earlier entries).
 */

(function () {
  'use strict';

  // ==== Version parity check (tag ?ver vs window.PPA.jsVer) ==============================
  (function versionParityCheck(){
    function parseVer(u){
      try {
        var url = new URL(u, window.location.origin);
        return String(url.searchParams.get('ver') || '');
      } catch (e) {
        var m = String(u||'').match(/[?&]ver=([^&#]+)/i);
        return m ? decodeURIComponent(m[1]) : '';
      }
    }
    function getOwnAdminScriptTag(){
      var current = document.currentScript;
      if (current && /postpress-ai\/assets\/js\/admin\.js/i.test(String(current.src||''))) return current;
      var nodes = document.getElementsByTagName('script');
      for (var i = nodes.length - 1; i >= 0; i--) {
        var s = nodes[i];
        var src = String(s && s.src || '');
        if (src.indexOf('postpress-ai/assets/js/admin.js') !== -1) return s;
      }
      return null;
    }
    var tag = getOwnAdminScriptTag();
    var tagVer = tag ? parseVer(tag.src) : '';
    var cfgVer = (window.PPA && String(window.PPA.jsVer || '')) || '';
    if (window.PPA) { window.PPA._tagJsVer = tagVer; }
    var r = document.getElementById('ppa-composer');
    if (r) {
      try { r.setAttribute('data-ppa-jsver', cfgVer); r.setAttribute('data-ppa-tag-jsver', tagVer); } catch (e) {}
    }
    if (cfgVer && tagVer && cfgVer !== tagVer) {
      console.warn('PPA: JS version mismatch — window.PPA.jsVer != tag ?ver', { cfgVer: cfgVer, tagVer: tagVer });
    } else {
      console.info('PPA: JS version parity OK', { jsVer: cfgVer || '(n/a)', tagVer: tagVer || '(n/a)' });
    }
  })();

  var PPA_JS_VER = 'admin.v2025-12-20.2'; // CHANGED:

  // Abort if composer root is missing (defensive)
  var root = document.getElementById('ppa-composer');
  if (!root) {
    console.info('PPA: composer root not found, admin.js is idle');
    return;
  }

  // Ensure toolbar message acts as a live region (A11y)
  (function ensureLiveRegion(){
    var msg = document.getElementById('ppa-toolbar-msg');
    if (msg) {
      if (!msg.getAttribute('role')) msg.setAttribute('role', 'status');
      if (!msg.getAttribute('aria-live')) msg.setAttribute('aria-live', 'polite');
    }
  })();

  // Make preview pane focusable for screen readers
  (function ensurePreviewPaneFocusable(){
    var pane = document.getElementById('ppa-preview-pane');
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

  // Simple escapers
  function escHtml(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function escAttr(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // Clean AI-generated titles so we never leave trailing ellipsis or dangling punctuation. // CHANGED:
  function ppaCleanTitle(raw) { // CHANGED:
    if (!raw) return ''; // CHANGED:
    var t = String(raw); // CHANGED:

    // Strip trailing "..." with optional surrounding whitespace.                  // CHANGED:
    t = t.replace(/\s*\.\.\.\s*$/, '');                                           // CHANGED:

    // Strip dangling punctuation or dashes at the very end (":", "-", "–", "—"). // CHANGED:
    t = t.replace(/\s*[-:–—·|]+\s*$/, '');                                        // CHANGED:

    return t.trim();                                                              // CHANGED:
  }                                                                               // CHANGED:

  // Expanded nonce fallback: ppaAdmin.nonce → data-attr → PPA.nonce → #ppa-nonce
  function getNonce() {                                                                 // CHANGED:
    // 1) Prefer the admin-ajax nonce localized via wp_localize_script (action: 'ppa-admin') // CHANGED:
    if (window.ppaAdmin && window.ppaAdmin.nonce) return String(window.ppaAdmin.nonce).trim(); // CHANGED:

    // 2) Allow template-provided nonce via data attr (but ONLY as fallback)      // CHANGED:
    var r = document.getElementById('ppa-composer');                                // CHANGED:
    if (r) {                                                                        // CHANGED:
      var dn = r.getAttribute('data-ppa-nonce');                                    // CHANGED:
      if (dn) return String(dn).trim();                                             // CHANGED:
    }                                                                               // CHANGED:

    // 3) Legacy fallbacks (rare)                                                  // CHANGED:
    if (window.PPA && window.PPA.nonce) return String(window.PPA.nonce).trim();
    var el = $('#ppa-nonce');
    if (el) return String(el.value || '').trim();
    return '';
  }

  function jsonTryParse(text) {
    try { return JSON.parse(text); }
    catch (e) {
      console.info('PPA: JSON parse failed, returning raw text');
      return { raw: String(text || '') };
    }
  }

  function setPreview(html) {
    var pane = $('#ppa-preview-pane');
    if (!pane) return;
    pane.innerHTML = html;
    try { pane.focus(); } catch (e) {}
  }

  // Extract `provider` from an HTML comment like: <!-- provider: local-fallback -->
  function extractProviderFromHtml(html) {
    if (typeof html !== 'string') return '';
    var m = html.match(/<!--\s*provider:\s*([a-z0-9._-]+)\s*-->/i);
    return m ? m[1] : '';
  }

  // --- Rich text helpers (Classic/TinyMCE/Gutenberg-compatible) -------------

  function getTinyMCEContentById(id) {
    try {
      if (!window.tinyMCE || !tinyMCE.get) return '';
      var ed = tinyMCE.get(id);
      return ed && !ed.isHidden() ? String(ed.getContent() || '') : '';
    } catch (e) { return ''; }
  }

  function getEditorContent() {
    var txt = $('#ppa-content');
    if (txt && String(txt.value || '').trim()) return String(txt.value || '').trim();

    var mce = getTinyMCEContentById('content');
    if (mce) return mce;

    var raw = $('#content');
    if (raw && String(raw.value || '').trim()) return String(raw.value || '').trim();

    return '';
  }

  // Set editor content in a Classic/Block/TinyMCE-safe way
  function setEditorContent(html) {
    var value = String(html || '');
    var txt = $('#ppa-content');
    try {
      if (window.tinyMCE && tinyMCE.get) {
        var ed = tinyMCE.get('content');
        if (ed && !ed.isHidden()) {
          ed.setContent(value);
          return;
        }
      }
    } catch (e) {}
    var raw = $('#content');
    if (raw) {
      raw.value = value;
      return;
    }
    if (txt) {
      txt.value = value;
    }
  }

  // Convert plain text to minimal HTML paragraphs
  function toHtmlFromText(text){
    var t = String(text || '').trim();
    if (!t) return '';
    // Split by blank lines into paragraphs; keep single newlines as <br>
    var parts = t.split(/\n{2,}/);
    for (var i = 0; i < parts.length; i++) {
      var safe = escHtml(parts[i]).replace(/\n/g,'<br>');
      parts[i] = '<p>' + safe + '</p>';
    }
    return parts.join('');
  }

  // Minimal Markdown-ish to HTML helper: headings, lists, and inline em/strong.
  function markdownToHtml(m) {
    var txt = String(m || '');
    if (!txt) return '';
    // If it already looks like HTML, just return it.
    if (/<[a-z][\s\S]*>/i.test(txt)) {
      return txt;
    }

    // Inline formatter: apply em/strong AFTER escaping HTML.
    function applyInline(mdText) {
      var s = escHtml(mdText);
      // Bold: **text** or __text__
      s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/__(.+?)__/g, '<strong>$1</strong>');
      // Emphasis: *text* or _text_
      s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
      s = s.replace(/_(.+?)_/g, '<em>$1</em>');
      return s;
    }

    var lines = txt.split(/\r?\n/);
    var htmlParts = [];
    var inList = false;
    var paraBuf = [];

    function flushParagraph() {
      if (!paraBuf.length) return;
      var text = paraBuf.join(' ').replace(/\s+/g, ' ').trim();
      if (!text) { paraBuf = []; return; }
      htmlParts.push('<p>' + applyInline(text) + '</p>');
      paraBuf = [];
    }

    function flushList() {
      if (!inList) return;
      htmlParts.push('</ul>');
      inList = false;
    }

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var trimmed = String(line || '').trim();

      // Blank line → paragraph / list break
      if (!trimmed) {
        flushParagraph();
        flushList();
        continue;
      }

      // Headings: #, ##, ###, ####, #####, ######
      var mHeading = trimmed.match(/^(#{1,6})\s+(.*)$/);
      if (mHeading) {
        flushParagraph();
        flushList();
        var level = mHeading[1].length;
        if (level < 1) level = 1;
        if (level > 6) level = 6;
        var hText = applyInline(mHeading[2] || '');
        htmlParts.push('<h' + level + '>' + hText + '</h' + level + '>');
        continue;
      }

      // Unordered list items: -, *, + at start
      var mList = trimmed.match(/^[-*+]\s+(.*)$/);
      if (mList) {
        flushParagraph();
        if (!inList) {
          htmlParts.push('<ul>');
          inList = true;
        }
        htmlParts.push('<li>' + applyInline(mList[1] || '') + '</li>');
        continue;
      }

      // Otherwise treat as paragraph text; accumulate.
      paraBuf.push(trimmed);
    }

    // Flush any remaining open blocks
    flushParagraph();
    flushList();

    if (!htmlParts.length) {
      // Fallback to the old behavior if nothing parsed
      return toHtmlFromText(txt);
    }
    return htmlParts.join('');
  }

  // ---- Payload builders ----------------------------------------------------

  function buildPreviewPayload() {
    var subject = $('#ppa-subject');
    var brief   = $('#ppa-brief');
    var genre   = $('#ppa-genre');
    var tone    = $('#ppa-tone');
    var wc      = $('#ppa-word-count');
    var audience = $('#ppa-audience');

    var titleEl = $('#ppa-title') || $('#title');
    var contentFromEditor = getEditorContent();
    var subjVal = subject ? subject.value : '';
    var briefVal = brief ? brief.value : '';

    // Compose HTML content for normalize endpoint when editor is empty
    var composedHtml = contentFromEditor;
    if (!composedHtml && briefVal) {
      composedHtml = toHtmlFromText(briefVal);
    }

    var payload = {
      // canonical inputs for Django normalize
      title: (titleEl && titleEl.value) ? String(titleEl.value) : String(subjVal || ''),
      content: String(composedHtml || ''),
      // synonyms (help backends that look for these keys)
      html: String(composedHtml || ''),
      text: String(briefVal || ''),
      // UI/meta
      subject: subjVal,
      brief: briefVal,
      genre: genre ? genre.value : '',
      tone:  tone  ? tone.value  : '',
      word_count: wc ? Number(wc.value || 0) : 0,
      audience: audience ? String(audience.value || '') : '',
      _js_ver: PPA_JS_VER
    };

    // Optional: pass tags/categories if present on the page
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

    return payload;
  }

  // (global) CSV reader used by preview/store payloads — de-duplicated
  function readCsvValues(el) {
    if (!el) return [];
    var raw = String(el.value || '').trim();
    if (!raw) return [];
    return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }

  function textFromFirstMatch(html, selector) {
    try {
      var tmp = document.createElement('div');
      tmp.innerHTML = html || '';
      var el = tmp.querySelector(selector);
      if (!el) return '';
      return (el.textContent || '').trim();
    } catch (e) { return ''; }
  }
  function extractTitleFromHtml(html) {
    return textFromFirstMatch(html, 'h1') || textFromFirstMatch(html, 'h2') || textFromFirstMatch(html, 'h3') || '';
  }
  function extractExcerptFromHtml(html) {
    var p = textFromFirstMatch(html, 'p');
    if (!p) return '';
    return p.replace(/\s+/g, ' ').trim().slice(0, 300);
  }
  function sanitizeSlug(s) {
    if (!s) return '';
    var t = String(s).toLowerCase();
    t = t.replace(/<[^>]*>/g, '');
    t = t.normalize ? t.normalize('NFKD') : t;
    t = t.replace(/[^\w\s-]+/g, '');
    t = t.replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
    return t;
  }

  function buildStorePayload(target) {
    var title   = $('#ppa-title')   || $('#title');
    var excerpt = $('#ppa-excerpt') || $('#excerpt');
    var slug    = $('#ppa-slug')    || $('#post_name');

    var tagsEl   = $('#ppa-tags') || $('#new-tag-post_tag') || $('#tax-input-post_tag');
    var catsEl   = $('#ppa-categories') || $('#post_category');
    var statusEl = $('#ppa-status');

    var payload = {
      title:   title   ? String(title.value || '')   : '',
      content: getEditorContent(),
      excerpt: excerpt ? String(excerpt.value || '') : '',
      slug:    slug    ? String(slug.value || '')    : '',
      tags: (function () {
        if (tagsEl && tagsEl.tagName === 'SELECT') {
          return $all('option:checked', tagsEl).map(function (o) { return o.value; });
        }
        return readCsvValues(tagsEl);
      })(),
      categories: (function () {
        if (catsEl && catsEl.tagName === 'SELECT') {
          return $all('option:checked', catsEl).map(function (o) { return o.value; });
        }
        return readCsvValues(catsEl);
      })(),
      status: statusEl ? String(statusEl.value || '') : '',
      target_sites: [String(target || 'draft')],
      source: 'admin',
      ver: '1',
      _js_ver: PPA_JS_VER
    };

    // Determine mode hint for backend (draft/publish/update).
    var modeVal = '';
    if (target === 'publish' || target === 'draft' || target === 'update') {
      modeVal = String(target);
    } else if (statusEl) {
      modeVal = String(statusEl.value || '');
    }
    if (modeVal) {
      payload.mode = modeVal;
    }

    // If editor is empty, use Preview HTML and auto-fill
    if (!payload.content || !String(payload.content).trim()) {
      var pane = document.getElementById('ppa-preview-pane');
      var html = pane ? String(pane.innerHTML || '').trim() : '';
      if (html) {
        payload.content = html;
        if (!payload.title)   payload.title   = extractTitleFromHtml(html);
        if (!payload.excerpt) payload.excerpt = extractExcerptFromHtml(html);
        if (!payload.slug && payload.title) payload.slug = sanitizeSlug(payload.title);
      }
    }

    // Merge preview meta for traceability
    var prev = buildPreviewPayload();
    for (var k in prev) {
      if (Object.prototype.hasOwnProperty.call(prev, k) && !Object.prototype.hasOwnProperty.call(payload, k)) {
        payload[k] = prev[k];
      }
    }
    return payload;
  }

  function hasTitleOrSubject() {
    var subjectEl = $('#ppa-subject');
    var titleEl   = $('#ppa-title') || $('#title');

    var subject = subjectEl ? String(subjectEl.value || '').trim() : '';
    var title   = titleEl ? String(titleEl.value || '').trim() : '';

    return !!(subject || title);
  }

  // ---- Toolbar Notices & Busy State ---------------------------------------

  var btnPreview  = $('#ppa-preview');
  var btnDraft    = $('#ppa-draft');
  var btnPublish  = $('#ppa-publish');
  var btnGenerate = $('#ppa-generate');

  // Repurpose Generate as the main preview button and hide the old Preview.          // CHANGED:
  (function adaptGenerateAsPreview(){                                                 // CHANGED:
    if (btnPreview) {                                                                 // CHANGED:
      try { btnPreview.style.display = 'none'; } catch (e) {}                         // CHANGED:
    }                                                                                 // CHANGED:
    if (btnGenerate) {                                                                // CHANGED:
      try { btnGenerate.textContent = 'Generate Preview'; } catch (e) {}              // CHANGED:
    }                                                                                 // CHANGED:
  })();                                                                               // CHANGED:

  // NEW: ensure toolbar notice exists and sits above the buttons             // CHANGED:
  var btnPreview2  = $('#ppa-preview');
  var btnDraft2    = $('#ppa-draft');
  var btnPublish2  = $('#ppa-publish');
  var btnGenerate2 = $('#ppa-generate'); // CHANGED:

  // Ensure we always have a notice container above the main buttons           // CHANGED:
  function noticeContainer() {                                                // CHANGED:
    var el = $('#ppa-toolbar-msg');                                           // CHANGED:
    if (el) return el;                                                        // CHANGED:

    // Try to anchor it in the same row as the primary buttons                // CHANGED:
    var host = null;                                                          // CHANGED:
    if (btnGenerate2 && btnGenerate2.parentNode) {                              // CHANGED:
      host = btnGenerate2.parentNode;                                          // CHANGED:
    } else if (btnPreview2 && btnPreview2.parentNode) {                         // CHANGED:
      host = btnPreview2.parentNode;                                           // CHANGED:
    } else if (btnDraft2 && btnDraft2.parentNode) {                             // CHANGED:
      host = btnDraft2.parentNode;                                             // CHANGED:
    } else if (btnPublish2 && btnPublish2.parentNode) {                         // CHANGED:
      host = btnPublish2.parentNode;                                           // CHANGED:
    }

    if (!host) {                                                              // CHANGED:
      console.info('PPA: noticeContainer could not find a host for toolbar msg'); // CHANGED:
      return null;                                                            // CHANGED:
    }

    el = document.createElement('div');                                       // CHANGED:
    el.id = 'ppa-toolbar-msg';                                                // CHANGED:
    el.className = 'ppa-notice';                                              // CHANGED:
    try {                                                                     // CHANGED:
      el.setAttribute('role', 'status');                                      // CHANGED:
      el.setAttribute('aria-live', 'polite');                                 // CHANGED:
    } catch (e) {}                                                            // CHANGED:

    // Insert *above* the buttons                                             // CHANGED:
    host.insertBefore(el, host.firstChild);                                   // CHANGED:
    console.info('PPA: created #ppa-toolbar-msg above buttons');              // CHANGED:
    return el;                                                                // CHANGED:
  }

  function renderNotice(type, message) {
    var el = noticeContainer();
    var text = String(message == null ? '' : message);
    console.info('PPA: renderNotice', { type: type, message: text, hasEl: !!el });
    if (!el) {
      // Hard fallback so you ALWAYS see something during debugging
      if (type === 'error' || type === 'warn') {
        alert(text);
      } else {
        console.info('PPA:', type + ':', text);
      }
      return;
    }
    var clsBase = 'ppa-notice', clsType = 'ppa-notice-' + type;
    el.className = clsBase + ' ' + clsType;
    el.textContent = text;

    // If for some reason it's still visually hidden, alert as a backup
    try {
      var visible = el.offsetWidth > 0 && el.offsetHeight > 0;
      if (!visible && (type === 'error' || type === 'warn')) {
        alert(text);
      }
    } catch (e) {}
  }

  function renderNoticeTimed(type, message, ms) {
    renderNotice(type, message);
    if (ms && ms > 0) setTimeout(clearNotice, ms);
  }

  function renderNoticeHtml(type, html) {
    var el = noticeContainer();
    if (!el) { console.info('PPA:', type + ':', html); return; }
    var clsBase = 'ppa-notice', clsType = 'ppa-notice-' + type;
    el.className = clsBase + ' ' + clsType;
    el.innerHTML = String(html || '');
  }

  function renderNoticeTimedHtml(type, html, ms) {
    renderNoticeHtml(type, html);
    if (ms && ms > 0) setTimeout(clearNotice, ms);
  }

  function clearNotice() {
    var el = noticeContainer();
    if (el) { el.className = 'ppa-notice'; el.textContent = ''; }
  }

  function setButtonsDisabled(disabled) {
    var arr = [btnPreview, btnDraft, btnPublish, btnGenerate];
    for (var i = 0; i < arr.length; i++) {
      var b = arr[i];
      if (!b) continue;
      b.disabled = !!disabled;
      if (disabled) { b.setAttribute('aria-busy', 'true'); }
      else { b.removeAttribute('aria-busy'); }
    }
  }

  // Lightweight extra debounce to avoid double-trigger before withBusy runs
  function clickGuard(btn) {
    if (!btn) return false;
    var ts = Number(btn.getAttribute('data-ppa-ts') || 0);
    var now = Date.now();
    if (now - ts < 350) return true;
    btn.setAttribute('data-ppa-ts', String(now));
    return false;
  }

  function withBusy(promiseFactory, label) {
    setButtonsDisabled(true);
    clearNotice();
    var tag = label || 'request';
    console.info('PPA: busy start →', tag);
    try {
      var p = promiseFactory();
      return Promise.resolve(p)
        .catch(function (err) {
          console.info('PPA: busy error on', tag, err);
          renderNotice('error', 'There was an error while processing your request.');
          throw err;
        })
        .finally(function () {
          setButtonsDisabled(false);
          console.info('PPA: busy end ←', tag);
        });
    } catch (e) {
      setButtonsDisabled(false);
      console.info('PPA: busy sync error on', tag, e);
      renderNotice('error', 'There was an error while preparing your request.');
      throw e;
    }
  }

  // ---- Network -------------------------------------------------------------

  function apiPost(action, data) {
    var url = getAjaxUrl();
    var nonce = getNonce();
    var qs = url.indexOf('?') === -1 ? '?' : '&';
    var endpoint = url + qs + 'action=' + encodeURIComponent(action);

    var headers = { 'Content-Type': 'application/json' };
    if (nonce) {
      headers['X-PPA-Nonce'] = nonce;
      headers['X-WP-Nonce']  = nonce;
    }
    headers['X-Requested-With'] = 'XMLHttpRequest';

    // Tag this as coming from the Composer view for Django-side diagnostics.
    var view = 'composer';
    try {
      if (root && root.getAttribute('data-ppa-view')) {
        view = String(root.getAttribute('data-ppa-view') || 'composer');
      }
    } catch (e) {}
    headers['X-PPA-View'] = view;

    console.info('PPA: POST', action, '→', endpoint);
    return fetch(endpoint, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(data || {}),
      credentials: 'same-origin'
    })
    .then(function (res) {
      var ct = (res.headers.get('content-type') || '').toLowerCase();
      return res.text().then(function (text) {
        var body = ct.indexOf('application/json') !== -1 ? jsonTryParse(text) : jsonTryParse(text);
        return { ok: res.ok, status: res.status, body: body, raw: text, contentType: ct, headers: res.headers };
      });
    })
    .catch(function (err) {
      console.info('PPA: fetch error', err);
      return { ok: false, status: 0, body: { error: String(err) }, raw: '', contentType: '', headers: new Headers() };
    });
  }

  // ---- Modularization Bridge (NO behavior change) -------------------------- // CHANGED:
  // Problem we are solving:
  // - Modules may load before/after admin.js.
  // - window.PPAAdmin may already exist (older export) and must be MERGED.
  // - Module apiPost must be forced to use this proven JSON+headers transport.
  var __ppaBridgeTimer = null; // CHANGED:

  function ensureModuleBridge() { // CHANGED:
    try { // CHANGED:
      var mods = window.PPAAdminModules; // CHANGED:
      if (!mods || typeof mods !== 'object') return false; // CHANGED:

      var apiPatched = false; // CHANGED:
      var payloadPatched = false; // CHANGED:

      if (mods.api && typeof mods.api === 'object') { // CHANGED:
        // Force apiPost transport to the legacy, working implementation. // CHANGED:
        if (mods.api.apiPost !== apiPost) { mods.api.apiPost = apiPost; } // CHANGED:
        // Keep nonce + ajax url parity (prevents wrong nonce selection). // CHANGED:
        if (mods.api.getNonce !== getNonce) { mods.api.getNonce = getNonce; } // CHANGED:
        if (mods.api.getAjaxUrl !== getAjaxUrl) { mods.api.getAjaxUrl = getAjaxUrl; } // CHANGED:
        apiPatched = (mods.api.apiPost === apiPost); // CHANGED:
      } // CHANGED:

      // IMPORTANT: composerGenerate must NOT strip keys during cutover.
      // If payloads module exists, force its buildGeneratePayload to pass-through for now.
      if (mods.payloads && typeof mods.payloads === 'object') { // CHANGED:
        if (!mods.payloads.__ppa_generate_passthru) { // CHANGED:
          mods.payloads.buildGeneratePayload = function (input) { return input || {}; }; // CHANGED:
          mods.payloads.__ppa_generate_passthru = true; // CHANGED:
        } // CHANGED:
        payloadPatched = (mods.payloads.__ppa_generate_passthru === true); // CHANGED:
      } // CHANGED:

      // Store a tiny bridge status for debugging. // CHANGED:
      mods.__ppa_bridge = mods.__ppa_bridge || {}; // CHANGED:
      mods.__ppa_bridge.apiPatched = apiPatched; // CHANGED:
      mods.__ppa_bridge.payloadPatched = payloadPatched; // CHANGED:
      mods.__ppa_bridge.at = Date.now(); // CHANGED:

      // Return true when the api side is patched (payload patch may not exist yet if payloads module isn't loaded). // CHANGED:
      return apiPatched; // CHANGED:
    } catch (e) { // CHANGED:
      console.info('PPA: ensureModuleBridge error', e); // CHANGED:
      return false; // CHANGED:
    } // CHANGED:
  } // CHANGED:

  (function startModuleBridgePolling(){ // CHANGED:
    // Poll longer than before so late-loading module scripts still get patched.
    var tries = 0; // CHANGED:
    var maxTries = 120; // CHANGED: 120 * 250ms = 30s
    var intervalMs = 250; // CHANGED:

    if (__ppaBridgeTimer) return; // CHANGED:

    __ppaBridgeTimer = setInterval(function () { // CHANGED:
      tries++; // CHANGED:
      var ok = ensureModuleBridge(); // CHANGED:
      // Stop early once api patch is confirmed. // CHANGED:
      if (ok || tries >= maxTries) { // CHANGED:
        clearInterval(__ppaBridgeTimer); // CHANGED:
        __ppaBridgeTimer = null; // CHANGED:
        if (ok) console.info('PPA: module bridge patched'); // CHANGED:
        else console.info('PPA: module bridge polling ended (modules may not be present)'); // CHANGED:
      } // CHANGED:
    }, intervalMs); // CHANGED:
  })(); // CHANGED:

  // Guarded module path for Generate (falls back to legacy apiPost; payload/UX unchanged). // CHANGED:
  function postGenerate(payload) { // CHANGED:
    // On-demand patch attempt (fixes "false" console check even if modules load late). // CHANGED:
    ensureModuleBridge(); // CHANGED:

    try { // CHANGED:
      var mods = window.PPAAdminModules; // CHANGED:
      if (!mods || typeof mods !== 'object') return apiPost('ppa_generate', payload); // CHANGED:

      // Only use module path if api patch is in effect AND composerGenerate exists.
      // NOTE: We are NOT changing payload shape here; payloads pass-through patch ensures no key stripping if payloads module exists.
      var hasApiPatch = !!(mods.api && mods.api.apiPost === apiPost); // CHANGED:
      var cg = mods.composerGenerate; // CHANGED:

      if (hasApiPatch && cg && typeof cg.generate === 'function') { // CHANGED:
        // composerGenerate returns an envelope; we convert back to the legacy transport object for unchanged downstream code.
        return cg.generate(payload, { storeDebug: false }).then(function (out) { // CHANGED:
          return (out && out.transport) ? out.transport : out; // CHANGED:
        }); // CHANGED:
      } // CHANGED:
    } catch (e) { // CHANGED:
      console.info('PPA: postGenerate module path failed, falling back', e); // CHANGED:
    } // CHANGED:

    return apiPost('ppa_generate', payload); // CHANGED:
  } // CHANGED:

  // ---- Response Shape Helpers ---------------------------------------------

  function pickHtmlFromResponseBody(body) {
    if (!body || typeof body !== 'object') return '';
    // prefer nested shapes (WP AJAX → Django proxy)
    if (body.data && body.data.result && typeof body.data.result.html === 'string') return body.data.result.html;
    if (body.data && body.data.result && typeof body.data.result.content === 'string') return body.data.result.content;
    if (body.result && typeof body.result.html === 'string') return body.result.html;
    if (body.result && typeof body.result.content === 'string') return body.result.content;
    // simpler shapes
    if (typeof body.data === 'object' && typeof body.data.html === 'string') return body.data.html;
    if (typeof body.html === 'string') return body.html;
    if (typeof body.content === 'string') return body.content;
    if (typeof body.preview === 'string') return body.preview;
    if (typeof body.raw === 'string') return body.raw;
    return '';
  }

  // Extract a reasonable text/body candidate for preview fallback
  function pickPreviewBodyText(body) {
    if (!body || typeof body !== 'object') return '';
    var src = body;
    if (src.data && typeof src.data === 'object') src = src.data;
    if (src.result && typeof src.result === 'object') src = src.result;

    // Prefer long-form fields first
    if (typeof src.body_markdown === 'string') return src.body_markdown;
    if (typeof src.body === 'string') return src.body;
    if (typeof src.content === 'string') return src.content;
    if (typeof src.html === 'string') return src.html;
    // Then fall back to simpler text shapes
    if (typeof src.text === 'string') return src.text;
    if (typeof src.brief === 'string') return src.brief;
    return '';
  }

  // Build final HTML for the Preview pane using title + body HTML/text
  function buildPreviewHtml(body, baseHtml) {
    var src = body && typeof body === 'object' ? body : {};
    if (src.data && typeof src.data === 'object') src = src.data;
    if (src.result && typeof src.result === 'object') src = src.result;

    var title = typeof src.title === 'string' ? src.title : '';
    var excerpt = typeof src.excerpt === 'string' ? src.excerpt : '';

    var html = baseHtml || '';
    if (!html && typeof src.html === 'string') html = src.html;
    if (!html && typeof src.content === 'string') html = src.content;

    // If we still don't have HTML, fall back to any body/text and render it
    if (!html) {
      var bodyText = pickPreviewBodyText(body);
      if (bodyText) {
        // If bodyText already looks like HTML, use it directly.
        if (/<[a-z][\s\S]*>/i.test(bodyText)) {
          html = bodyText;
        } else {
          // Prefer markdown-style rendering; falls back to paragraph shaping
          html = markdownToHtml(bodyText);
        }
      }
    }

    var parts = [];
    if (title) {
      parts.push('<h2>' + escHtml(title) + '</h2>');
    }
    if (html) {
      parts.push(html);
    } else if (excerpt) {
      parts.push('<p>' + escHtml(excerpt) + '</p>');
    }

    return parts.join('');
  }

  // DevTools hook
  if (!window.__PPA_TEST_PICK_HTML) { window.__PPA_TEST_PICK_HTML = function (b) { return pickHtmlFromResponseBody(b); }; }

  function pickMessage(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.message === 'string') return body.message;
    if (body.result && typeof body.result.message === 'string') return body.result.message;
    if (body.data && typeof body.data.message === 'string') return body.data.message;
    if (body.data && body.data.result && typeof body.data.result.message === 'string') return body.data.result.message;
    return '';
  }

  function pickId(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.id === 'string' || typeof body.id === 'number') return String(body.id);
    if (body.result && (typeof body.result.id === 'string' || typeof body.result.id === 'number')) return String(body.result.id);
    if (body.data && (typeof body.data.id === 'string' || typeof body.data.id === 'number')) return String(body.data.id);
    if (body.data && body.data.result && (typeof body.data.result.id === 'string' || typeof body.data.result.id === 'number'))
      return String(body.data.result.id);
    return '';
  }

  function pickEditLink(body) {
    if (!body || typeof body !== 'object') return '';
    var v = '';
    if (typeof body.edit_link === 'string') v = body.edit_link;
    else if (body.result && typeof body.result.edit_link === 'string') v = body.result.edit_link;
    else if (body.data && typeof body.data.edit_link === 'string') v = body.data.edit_link;
    else if (body.data && body.data.result && typeof body.data.result.edit_link === 'string') v = body.data.result.edit_link;
    return String(v || '');
  }

  function pickViewLink(body) {
    if (!body || typeof body !== 'object') return '';
    var cand = '';
    if (typeof body.view_link === 'string') cand = body.view_link;
    else if (typeof body.permalink === 'string') cand = body.permalink;
    else if (typeof body.link === 'string') cand = body.link;
    else if (body.result && typeof body.result.view_link === 'string') cand = body.result.view_link;
    else if (body.result && typeof body.result.permalink === 'string') cand = body.result.permalink;
    else if (body.result && typeof body.result.link === 'string') cand = body.result.link;
    else if (body.data && typeof body.data.permalink === 'string') cand = body.data.permalink;
    else if (body.data && typeof body.data.link === 'string') cand = body.data.link;
    else if (body.data && body.data.result && typeof body.data.result.permalink === 'string') cand = body.data.result.permalink;
    else if (body.data && body.data.result && typeof body.data.result.link === 'string') cand = body.data.result.link;
    return String(cand || '');
  }

  function pickStructuredError(body) {
    if (!body || typeof body !== 'object') return null;

    // Direct object error { type, message, code, meta, ... }
    if (body.error && typeof body.error === 'object') return body.error;
    if (body.data && body.data.error && typeof body.data.error === 'object')
      return body.data.error;

    // String error + optional meta/detail → normalize into object
    var errStr  = '';
    var meta    = null;
    var typeStr = '';

    if (typeof body.error === 'string') {
      errStr  = body.error;
      typeStr = body.error;
      if (body.meta && typeof body.meta === 'object') meta = body.meta;
    } else if (body.data && typeof body.data.error === 'string') {
      errStr  = body.data.error;
      typeStr = body.data.error;
      if (body.data.meta && typeof body.data.meta === 'object')
        meta = body.data.meta;
    }

    if (!errStr && !meta) return null;

    var msg = '';
    if (meta && typeof meta.detail === 'string') msg = meta.detail;
    if (!msg) msg = errStr;

    var code = 0;
    if (meta && typeof meta.code !== 'undefined') code = meta.code;
    else if (typeof body.code !== 'undefined') code = body.code;
    else if (body.data && typeof body.data.code !== 'undefined')
      code = body.data.code;

    return {
      type: typeStr || 'remote_error',
      message: msg,
      code: code,
      meta: meta || {}
    };
  }

  function pickProvider(body, html) {
    if (body && typeof body === 'object') {
      if (typeof body.provider === 'string') return body.provider;
      if (body.result && typeof body.result.provider === 'string') return body.result.provider;
      if (body.data && typeof body.data.provider === 'string') return body.data.provider;
      if (body.data && body.data.result && typeof body.data.result.provider === 'string') return body.data.result.provider;
    }
    return extractProviderFromHtml(html || '');
  }

  // ---- Auto-fill helpers (Title/Excerpt/Slug) --------------------------------

  function getElTitle() { return $('#ppa-title') || $('#title'); }
  function getElExcerpt() { return $('#ppa-excerpt') || $('#excerpt'); }
  function getElSlug() { return $('#ppa-slug') || $('#post_name'); }

  function setIfEmpty(el, val) {
    if (!el) return;
    var cur = String(el.value || '').trim();
    if (!cur && val) el.value = String(val);
  }

  function autoFillAfterPreview(body, html) {
    function pickField(src, key) {
      if (!src || typeof src !== 'object') return '';
      if (typeof src[key] === 'string') return src[key];
      if (src.result && typeof src.result[key] === 'string') return src.result[key];
      if (src.data && typeof src.data[key] === 'string') return src.data[key];
      if (src.data && src.data.result && typeof src.data.result[key] === 'string') return src.data.result[key];
      return '';
    }

    var rawTitle = pickField(body, 'title'); // CHANGED:
    var excerpt = pickField(body, 'excerpt');
    var slug = pickField(body, 'slug');

    var title = rawTitle; // CHANGED:

    if (!title) title = extractTitleFromHtml(html);
    // Clean trailing ellipsis/punctuation from the chosen title             // CHANGED:
    title = ppaCleanTitle(title);                                            // CHANGED:
    if (!excerpt) excerpt = extractExcerptFromHtml(html);
    if (!slug && title) slug = sanitizeSlug(title);

    setIfEmpty(getElTitle(), title);
    setIfEmpty(getElExcerpt(), excerpt);
    setIfEmpty(getElSlug(), slug);

    console.info('PPA: autofill candidates →', { title: !!title, excerpt: !!excerpt, slug: !!slug });
  }

  // ---- Generate helpers (normalize + render) ------------------------------------

  function pickGenerateResult(body) {
    if (!body || typeof body !== 'object') return null;
    var src = body;
    if (src.data && typeof src.data === 'object') src = src.data;
    if (src.result && typeof src.result === 'object') src = src.result;
    var title = src && typeof src.title === 'string' ? src.title : '';
    var outline = src && Object.prototype.toString.call(src.outline) === '[object Array]'
      ? src.outline.slice()
      : [];
    var bodyMd = src && typeof src.body_markdown === 'string'
      ? src.body_markdown
      : (typeof src.body === 'string' ? src.body : '');
    var meta = src && src.meta && typeof src.meta === 'object' ? src.meta : {};
    if (!title && !bodyMd && outline.length === 0 && !meta) return null;
    return {
      title: title,
      outline: outline,
      body_markdown: bodyMd,
      meta: meta
    };
  }

  function renderGeneratePreview(gen) {
    if (!gen) {
      setPreview('<p>No generate result available.</p>');
      return;
    }
    var parts = [];
    if (gen.title) {
      parts.push('<h2>' + escHtml(gen.title) + '</h2>');
    }
    if (gen.outline && gen.outline.length) {
      parts.push('<h3>Outline</h3><ol>');
      for (var i = 0; i < gen.outline.length; i++) {
        parts.push('<li>' + escHtml(String(gen.outline[i] || '')) + '</li>');
      }
      parts.push('</ol>');
    }
    var bodyHtml = markdownToHtml(gen.body_markdown);
    if (bodyHtml) {
      parts.push('<h3>Draft</h3>');
      parts.push(bodyHtml);
    }
    var meta = gen.meta || {};
    var metaItems = [];
    if (meta.focus_keyphrase) {
      metaItems.push('<li><strong>Focus keyphrase:</strong> ' + escHtml(meta.focus_keyphrase) + '</li>');
    }
    if (meta.meta_description) {
      metaItems.push('<li><strong>Meta description:</strong> ' + escHtml(meta.meta_description) + '</li>');
    }
    if (meta.slug) {
      metaItems.push('<li><strong>Slug:</strong> ' + escHtml(meta.slug) + '</li>');
    }
    if (metaItems.length) {
      parts.push('<h3>SEO</h3><ul>' + metaItems.join('') + '</ul>');
    }
    if (!parts.length) {
      parts.push('<p>Generate completed, but no structured result fields were found.</p>');
    }
    setPreview(parts.join(''));
  }

  function applyGenerateResult(gen) {
    if (!gen) return;
    // Render into preview pane first
    renderGeneratePreview(gen);

    // Auto-fill core post fields where still empty
    var meta = gen.meta || {};
    var rawTitle = gen.title || meta.title || '';          // CHANGED:
    var title = ppaCleanTitle(rawTitle);                   // CHANGED:
    var slug = meta.slug || '';
    var excerpt = meta.excerpt || meta.meta_description || '';

    var bodyHtml = markdownToHtml(gen.body_markdown);
    if (bodyHtml) {
      setEditorContent(bodyHtml);
      if (!excerpt) {
        excerpt = extractExcerptFromHtml(bodyHtml);
      }
      if (!slug && title) {
        slug = sanitizeSlug(title);
      }
    }

    setIfEmpty(getElTitle(), title);
    setIfEmpty(getElExcerpt(), excerpt);
    setIfEmpty(getElSlug(), slug);

    console.info('PPA: applyGenerateResult →', {
      titleFilled: !!title,
      excerptFilled: !!excerpt,
      slugFilled: !!slug
    });
  }

  // ---- Events --------------------------------------------------------------

  function handleRateLimit(res, which) {
    if (!res || res.status !== 429) return false;
    var retry = 0;
    var err = pickStructuredError(res.body);
    if (err && err.details && typeof err.details.retry_after === 'number') {
      retry = Math.max(0, Math.ceil(err.details.retry_after));
    }
    var btn = which === 'preview'
      ? btnPreview
      : which === 'draft'
        ? btnDraft
        : which === 'publish'
          ? btnPublish
          : btnGenerate;
    if (btn) btn.disabled = true;
    var sec = retry || 10;
    var t = setInterval(function(){
      renderNotice('warn', 'Rate-limited. Try again in ' + sec + 's.');
      if (--sec <= 0) {
        clearInterval(t);
        if (btn) btn.disabled = false;
        clearNotice();
      }
    }, 1000);
    return true;
  }

  if (btnPreview) {
    btnPreview.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (clickGuard(btnPreview)) return;

      // Early guard: avoid empty requests where backend returns ok:true but empty HTML
      var probe = buildPreviewPayload();
      if (!String(probe.title || '').trim() && !String(probe.text || '').trim() && !String(probe.content || '').trim()) {
        renderNotice('warn', 'Add a subject or a brief before preview.');
        return;
      }

      console.info('PPA: Preview clicked');

      withBusy(function () {
        var payload = probe; // validated payload
        return apiPost('ppa_preview', payload).then(function (res) {
          if (handleRateLimit(res, 'preview')) return;

          var html = pickHtmlFromResponseBody(res.body);
          var previewHtml = buildPreviewHtml(res.body, html);
          var serr = pickStructuredError(res.body);
          var provider = pickProvider(res.body, previewHtml || html);
          // Debug hook: expose last preview result for quick inspection
          try { window.PPA_LAST_PREVIEW = res; } catch (e) {}
          console.info('PPA: provider=' + (provider || '(unknown)'));

          if (serr && !res.ok) {
            var msg = serr.message || 'Request failed.';
            renderNotice('error', '[' + (serr.type || 'error') + '] ' + msg);
            return;
          }

          if (previewHtml && String(previewHtml).trim()) {
            setPreview(previewHtml);
            var pane = $('#ppa-preview-pane');
            if (pane) { try { pane.setAttribute('data-ppa-provider', String(provider || '')); } catch (e) {} }
            clearNotice();
            autoFillAfterPreview(res.body, previewHtml);
            return;
          }

          // Diagnostic fallback: show the JSON shape so we can see what came back
          var pretty = escHtml(JSON.stringify(res.body, null, 2));
          setPreview('<pre class="ppa-json">' + pretty + '</pre>');
          renderNotice('warn', 'Preview completed with no HTML; showing JSON response.');
          console.info('PPA: preview response lacked HTML', res);
        });
      }, 'preview');
    });
  }

  if (btnDraft) {
    btnDraft.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (clickGuard(btnDraft)) return;
      console.info('PPA: Save Draft clicked');

      // NEW: hard guard for missing title/subject
      if (!hasTitleOrSubject()) {
        renderNotice('warn', 'Add a title or subject before saving a draft.');
        return;
      }

      // Early guard to prevent empty submissions with no preview
      var probe = buildStorePayload('draft');
      var paneEl = document.getElementById('ppa-preview-pane');
      var hasPreviewHtml = !!(paneEl && String(paneEl.innerHTML || '').trim());
      if (!probe.title && !probe.content && !hasPreviewHtml) {
        renderNotice('warn', 'Add a title or run Preview before saving a draft.');
        return;
      }

      withBusy(function () {
        var payload = probe; // use built probe (already has preview fallback)
        return apiPost('ppa_store', payload).then(function (res) {
          if (handleRateLimit(res, 'draft')) return;
          var serr = pickStructuredError(res.body);
          var msg = (serr && serr.message) || pickMessage(res.body) || 'Draft request sent.';
          var pid = pickId(res.body);
          var edit = pickEditLink(res.body);
          var view = pickViewLink(res.body);
          if (!res.ok) {
            renderNotice('error', 'Draft failed (' + res.status + '): ' + msg);
            console.info('PPA: draft failed', res);
            return;
          }

          // Auto-redirect to the WordPress editor when we have an edit_link
          if (edit) {
            var redirectMsg = 'Draft saved in WordPress.' + (pid ? ' ID: ' + pid : '') + ' Redirecting you to the editor…';
            renderNoticeHtml('success', redirectMsg);
            console.info('PPA: draft success, redirecting to editor', { id: pid, edit: edit });
            setTimeout(function () {
              try {
                window.location.href = edit;
              } catch (e) {
                console.info('PPA: draft redirect failed; keeping Composer open', e);
              }
            }, 800);
            return;
          }

          if (view) {
            var pieces = [];
            pieces.push('<a href="' + escAttr(view) + '" target="_blank" rel="noopener">View Draft</a>');
            var okHtml = 'Draft saved in WordPress.' + (pid ? ' ID: ' + pid : '') + ' — ' + pieces.join(' &middot; ');
            renderNoticeTimedHtml('success', okHtml, 8000);
          } else {
            var okMsg = 'Draft saved in WordPress.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : '');
            renderNoticeTimed('success', okMsg, 4000);
          }
          console.info('PPA: draft success', res);
        });
      }, 'draft');
    });
  }

  if (btnPublish) {
    btnPublish.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (clickGuard(btnPublish)) return;
      console.info('PPA: Publish clicked');

      // NEW: hard guard for missing title/subject
      if (!hasTitleOrSubject()) {
        renderNotice('warn', 'Add a title or subject before publishing.');
        return;
      }

      /* eslint-disable no-alert */
      if (!window.confirm('Are you sure you want to publish this content now?')) {
        console.info('PPA: publish canceled by user');
        return;
      }
      /* eslint-enable no-alert */

      withBusy(function () {
        var payload = buildStorePayload('publish');
        return apiPost('ppa_store', payload).then(function (res) {
          if (handleRateLimit(res, 'publish')) return;
          var serr = pickStructuredError(res.body);
          var msg = (serr && serr.message) || pickMessage(res.body) || 'Publish request sent.';
          var pid = pickId(res.body);
          var edit = pickEditLink(res.body);
          var view = pickViewLink(res.body);
          if (!res.ok) {
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
            var okMsg = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : '');
            renderNoticeTimed('success', okMsg, 4000);
          }
          console.info('PPA: publish success', res);
        });
      }, 'publish');
    });
  }

  // Generate handler wired to ppa_generate → Django /generate/
  if (btnGenerate) {
    btnGenerate.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (clickGuard(btnGenerate)) return;
      console.info('PPA: Generate clicked');

      // Reuse preview payload so subject/brief/genre/tone/word_count all flow through.
      var probe = buildPreviewPayload();
      if (!String(probe.title || '').trim() &&
          !String(probe.text || '').trim() &&
          !String(probe.content || '').trim()) {
        renderNotice('warn', 'Add a subject or a brief before generating.');
        return;
      }

      withBusy(function () {
        var payload = probe;
        return postGenerate(payload).then(function (res) { // CHANGED:
          if (handleRateLimit(res, 'generate')) return;
          var serr = pickStructuredError(res.body);
          if (!res.ok) {
            var emsg  = (serr && serr.message) || pickMessage(res.body) || 'Generate request failed.';
            var etype = (serr && serr.type) || 'error';
            renderNotice(
              'error',
              'Generate failed (' + res.status + ') [' + etype + ']: ' + emsg
            );
            console.info('PPA: generate failed', { error: serr, response: res });
            return;
          }

          var gen = pickGenerateResult(res.body);
          try { window.PPA_LAST_GENERATE = res; } catch (e) {}

          if (!gen) {
            var pretty = escHtml(JSON.stringify(res.body, null, 2));
            setPreview('<pre class="ppa-json">' + pretty + '</pre>');
            renderNotice('warn', 'Generate completed, but result shape was unexpected; showing JSON.');
            console.info('PPA: generate unexpected result shape', res);
            return;
          }

          applyGenerateResult(gen);
          renderNoticeTimed('success', 'AI draft generated. Review, tweak, then Save Draft or Publish.', 8000);
        });
      }, 'generate');
    });
  }

  // Expose a small, read-only helper surface for future modularization (MERGE, do not only-set-if-missing). // CHANGED:
  window.PPAAdmin = window.PPAAdmin || {}; // CHANGED:
  window.PPAAdmin.markdownToHtml = markdownToHtml; // CHANGED:
  window.PPAAdmin.buildPreviewPayload = buildPreviewPayload; // CHANGED:
  window.PPAAdmin.buildStorePayload = buildStorePayload; // CHANGED:
  window.PPAAdmin.apiPost = apiPost; // CHANGED:
  window.PPAAdmin.pickGenerateResult = pickGenerateResult; // CHANGED:
  window.PPAAdmin.renderGeneratePreview = renderGeneratePreview; // CHANGED:
  window.PPAAdmin.applyGenerateResult = applyGenerateResult; // CHANGED:
  window.PPAAdmin.postGenerate = postGenerate; // CHANGED:
  window.PPAAdmin.ensureModuleBridge = ensureModuleBridge; // CHANGED:

  console.info('PPA: admin.js initialized →', PPA_JS_VER);
})();
