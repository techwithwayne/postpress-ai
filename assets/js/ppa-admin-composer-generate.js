/* global window, document */
/**
 * PostPress AI — Composer Generate Module (ES5-safe)
 *
 * Purpose:
 * - Provide a reusable "generate preview" action wrapper around:
 *   - window.PPAAdminModules.api.apiPost('ppa_generate', ...)
 *   - optional payload building via window.PPAAdminModules.payloads
 *   - optional result normalization via window.PPAAdminModules.generateView
 *
 * IMPORTANT:
 * - NO side effects on load.
 * - Not wired into admin.js yet (one-file rule).
 * - When invoked later, it can optionally store window.PPA_LAST_GENERATE for debugging,
 *   matching the existing Composer behavior expectation.
 *
 * ========= CHANGE LOG =========
 * 2025-12-21.1: Add Generate click handler + pick/render/apply helpers (parity with stable admin.js behavior) for future cutover; no auto-wiring. // CHANGED:
 * 2025-12-20.3: Strip ANY *El helper keys from outgoing payload (generic) to prevent leaking DOM refs/selectors while payload builders preserve unknown keys. // CHANGED:
 * 2025-12-20.2: Merge export (no early return); preserve unknown keys in fallback payload build; strip *El helper keys from outgoing payload to avoid leaking DOM refs/selectors. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-generate.v2025-12-21.1"; // CHANGED:
  var composerGenerate = window.PPAAdminModules.composerGenerate || {};

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function toStr(val) {
    return (val === undefined || val === null) ? "" : String(val);
  }

  function isObject(val) {
    return !!val && (typeof val === "object");
  }

  // shallow clone helper to preserve unknown keys (no stripping).
  function shallowClone(obj) {
    var out = {};
    if (!obj || typeof obj !== "object") return out;
    for (var k in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, k)) {
        out[k] = obj[k];
      }
    }
    return out;
  }

  // CHANGED: Strip any helper keys that end in "El" (subjectEl, briefEl, contentEl, etc.)
  // This prevents DOM nodes/selectors from being sent to WP/Django while we preserve unknown keys.
  function stripElKeys(obj) { // CHANGED:
    if (!obj || typeof obj !== "object") return obj; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        if (k && k.length >= 2 && k.slice(-2) === "El") { // CHANGED:
          try { delete obj[k]; } catch (e) {} // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return obj; // CHANGED:
  } // CHANGED:

  // ---- WP ajax envelope helpers ---------------------------------------------
  function unwrapWpAjax(body) {
    // WP admin-ajax typically returns:
    // { success: true, data: {...} }  OR  { success:false, data:{...} }
    if (!isObject(body)) {
      return { hasEnvelope: false, success: null, data: body };
    }

    if (hasOwn(body, "success") && hasOwn(body, "data")) {
      return {
        hasEnvelope: true,
        success: body.success === true,
        data: body.data
      };
    }

    return { hasEnvelope: false, success: null, data: body };
  }

  function pickDjangoResultShape(unwrappedData) {
    // Our WP proxy typically wraps Django like:
    // success: true, data: { provider, result:{...}, ver, ... }
    // Defensive extraction:
    if (!isObject(unwrappedData)) return unwrappedData;

    if (unwrappedData.result && isObject(unwrappedData.result)) {
      return unwrappedData.result;
    }

    // Sometimes callers might pass Django direct shape already.
    if (hasOwn(unwrappedData, "title") || hasOwn(unwrappedData, "outline") || hasOwn(unwrappedData, "body_markdown")) {
      return unwrappedData;
    }

    return unwrappedData;
  }

  // ---- Payload building ------------------------------------------------------
  function buildGeneratePayload(input) {
    // If payloads module exists, use it; otherwise pass through plain object.
    var payloads = window.PPAAdminModules.payloads;

    if (payloads && typeof payloads.buildGeneratePayload === "function") {
      var built = payloads.buildGeneratePayload(input);

      // Never leak convenience DOM keys into outgoing payload (generic strip).
      stripElKeys(built); // CHANGED:

      return built;
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};

    // Preserve unknown keys in fallback mode too (no stripping).
    var payload = shallowClone(input);

    payload.subject = toStr(input.subject || "");
    payload.brief   = toStr(input.brief || "");
    payload.content = toStr(input.content || "");

    // Strip DOM helper keys if present (generic strip).
    stripElKeys(payload); // CHANGED:

    return payload;
  }

  // ---- Result normalization --------------------------------------------------
  function normalizeResultModel(anyShape) {
    var gv = window.PPAAdminModules.generateView;

    if (gv && typeof gv.normalizeGenerateResult === "function") {
      return gv.normalizeGenerateResult(anyShape);
    }

    // Minimal fallback shape (no assumptions)
    return anyShape;
  }

  // ---- Generate UI helpers (parity with stable admin.js; NO auto-wiring) ---- // CHANGED:
  // These helpers are intentionally local to this module so we can later delete
  // the legacy copies from assets/js/admin.js without behavior regressions.     // CHANGED:

  function $(sel, ctx) { return (ctx || document).querySelector(sel); } // CHANGED:
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel) || []); } // CHANGED:

  function escHtml(s){ // CHANGED:
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  } // CHANGED:

  function escAttr(s){ // CHANGED:
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  } // CHANGED:

  // Composer root resolver (mirrors admin.js behavior; never moves DOM nodes). // CHANGED:
  function getComposerRoot(){ // CHANGED:
    return document.getElementById('ppa-composer'); // CHANGED:
  } // CHANGED:

  // Preview pane resolver (defensive; mirrors admin.js).                        // CHANGED:
  var __ppaPreviewPane = null; // CHANGED:
  function getPreviewPane(){ // CHANGED:
    if (__ppaPreviewPane && __ppaPreviewPane.nodeType === 1) return __ppaPreviewPane; // CHANGED:
    var root = getComposerRoot(); // CHANGED:
    if (!root) return null; // CHANGED:
    var pane = null; // CHANGED:
    try { pane = root.querySelector('#ppa-preview-pane'); } catch (e) { pane = null; } // CHANGED:
    if (!pane) pane = document.getElementById('ppa-preview-pane'); // CHANGED:
    if (!pane) { // CHANGED:
      try { pane = root.querySelector('[data-ppa-preview-pane]') || root.querySelector('.ppa-preview-pane'); } // CHANGED:
      catch (e2) { pane = null; } // CHANGED:
    } // CHANGED:
    __ppaPreviewPane = pane || null; // CHANGED:
    return __ppaPreviewPane; // CHANGED:
  } // CHANGED:

  function ensurePreviewPaneVisible(pane) { // CHANGED:
    if (!pane) return; // CHANGED:
    // Only undo inline "hidden" state; do not add styles or move DOM nodes.    // CHANGED:
    try { if (pane.hasAttribute('hidden')) pane.removeAttribute('hidden'); } catch (e) {} // CHANGED:
    try { if (pane.getAttribute('aria-hidden') === 'true') pane.removeAttribute('aria-hidden'); } catch (e2) {} // CHANGED:
    try { if (pane.style && pane.style.display === 'none') pane.style.display = ''; } catch (e3) {} // CHANGED:
  } // CHANGED:

  function setPreview(html) { // CHANGED:
    // Prefer composerPreview module if present; otherwise use the stable DOM target. // CHANGED:
    try { // CHANGED:
      var cp = window.PPAAdminModules.composerPreview; // CHANGED:
      if (cp && typeof cp.setPreview === 'function') { // CHANGED:
        cp.setPreview(html); // CHANGED:
        return; // CHANGED:
      } // CHANGED:
    } catch (e0) {} // CHANGED:

    var pane = getPreviewPane(); // CHANGED:
    if (!pane) { // CHANGED:
      console.info('PPA: preview pane not found; cannot render preview'); // CHANGED:
      return; // CHANGED:
    } // CHANGED:
    ensurePreviewPaneVisible(pane); // CHANGED:
    pane.innerHTML = String(html || ''); // CHANGED:
    try { pane.focus(); } catch (e) {} // CHANGED:
  } // CHANGED:

  // Clean AI-generated titles so we never leave trailing ellipsis/punctuation. // CHANGED:
  function ppaCleanTitle(raw) { // CHANGED:
    if (!raw) return ''; // CHANGED:
    var t = String(raw); // CHANGED:
    t = t.replace(/\s*\.\.\.\s*$/, ''); // CHANGED:
    t = t.replace(/\s*[-:–—·|]+\s*$/, ''); // CHANGED:
    return t.replace(/^\s+|\s+$/g, ''); // CHANGED:
  } // CHANGED:

  // Convert plain text to minimal HTML paragraphs (mirrors admin.js).          // CHANGED:
  function toHtmlFromText(text){ // CHANGED:
    var t = String(text || '').trim(); // CHANGED:
    if (!t) return ''; // CHANGED:
    var parts = t.split(/\n{2,}/); // CHANGED:
    for (var i = 0; i < parts.length; i++) { // CHANGED:
      var safe = escHtml(parts[i]).replace(/\n/g,'<br>'); // CHANGED:
      parts[i] = '<p>' + safe + '</p>'; // CHANGED:
    } // CHANGED:
    return parts.join(''); // CHANGED:
  } // CHANGED:

  // Minimal Markdown-ish to HTML helper (mirrors admin.js).                    // CHANGED:
  function markdownToHtml(m) { // CHANGED:
    var txt = String(m || ''); // CHANGED:
    if (!txt) return ''; // CHANGED:
    if (/<[a-z][\s\S]*>/i.test(txt)) { return txt; } // CHANGED:

    function applyInline(mdText) { // CHANGED:
      var s = escHtml(mdText); // CHANGED:
      s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>'); // CHANGED:
      s = s.replace(/__(.+?)__/g, '<strong>$1</strong>'); // CHANGED:
      s = s.replace(/\*(.+?)\*/g, '<em>$1</em>'); // CHANGED:
      s = s.replace(/_(.+?)_/g, '<em>$1</em>'); // CHANGED:
      return s; // CHANGED:
    } // CHANGED:

    var lines = txt.split(/\r?\n/); // CHANGED:
    var htmlParts = []; // CHANGED:
    var inList = false; // CHANGED:
    var paraBuf = []; // CHANGED:

    function flushParagraph() { // CHANGED:
      if (!paraBuf.length) return; // CHANGED:
      var text = paraBuf.join(' ').replace(/\s+/g, ' ').trim(); // CHANGED:
      if (!text) { paraBuf = []; return; } // CHANGED:
      htmlParts.push('<p>' + applyInline(text) + '</p>'); // CHANGED:
      paraBuf = []; // CHANGED:
    } // CHANGED:

    function flushList() { // CHANGED:
      if (!inList) return; // CHANGED:
      htmlParts.push('</ul>'); // CHANGED:
      inList = false; // CHANGED:
    } // CHANGED:

    for (var i = 0; i < lines.length; i++) { // CHANGED:
      var line = lines[i]; // CHANGED:
      var trimmed = String(line || '').trim(); // CHANGED:
      if (!trimmed) { flushParagraph(); flushList(); continue; } // CHANGED:

      var mHeading = trimmed.match(/^(#{1,6})\s+(.*)$/); // CHANGED:
      if (mHeading) { // CHANGED:
        flushParagraph(); flushList(); // CHANGED:
        var level = mHeading[1].length; // CHANGED:
        if (level < 1) level = 1; if (level > 6) level = 6; // CHANGED:
        var hText = applyInline(mHeading[2] || ''); // CHANGED:
        htmlParts.push('<h' + level + '>' + hText + '</h' + level + '>'); // CHANGED:
        continue; // CHANGED:
      } // CHANGED:

      var mList = trimmed.match(/^[-*+]\s+(.*)$/); // CHANGED:
      if (mList) { // CHANGED:
        flushParagraph(); // CHANGED:
        if (!inList) { htmlParts.push('<ul>'); inList = true; } // CHANGED:
        htmlParts.push('<li>' + applyInline(mList[1] || '') + '</li>'); // CHANGED:
        continue; // CHANGED:
      } // CHANGED:

      paraBuf.push(trimmed); // CHANGED:
    } // CHANGED:

    flushParagraph(); // CHANGED:
    flushList(); // CHANGED:

    if (!htmlParts.length) { return toHtmlFromText(txt); } // CHANGED:
    return htmlParts.join(''); // CHANGED:
  } // CHANGED:

  // --- Rich text helpers (Classic/TinyMCE/Gutenberg-compatible) ------------- // CHANGED:
  function getTinyMCEContentById(id) { // CHANGED:
    try { // CHANGED:
      if (!window.tinyMCE || !tinyMCE.get) return ''; // CHANGED:
      var ed = tinyMCE.get(id); // CHANGED:
      return ed && !ed.isHidden() ? String(ed.getContent() || '') : ''; // CHANGED:
    } catch (e) { return ''; } // CHANGED:
  } // CHANGED:

  function getEditorContent() { // CHANGED:
    var txt = $('#ppa-content'); // CHANGED:
    if (txt && String(txt.value || '').trim()) return String(txt.value || '').trim(); // CHANGED:
    var mce = getTinyMCEContentById('content'); // CHANGED:
    if (mce) return mce; // CHANGED:
    var raw = $('#content'); // CHANGED:
    if (raw && String(raw.value || '').trim()) return String(raw.value || '').trim(); // CHANGED:
    return ''; // CHANGED:
  } // CHANGED:

  function setEditorContent(html) { // CHANGED:
    // Prefer editor module if present; otherwise mirror admin.js fallback logic. // CHANGED:
    try { // CHANGED:
      var edMod = window.PPAAdminModules.editor; // CHANGED:
      if (edMod && typeof edMod.setEditorContent === 'function') { // CHANGED:
        edMod.setEditorContent(html); // CHANGED:
        return; // CHANGED:
      } // CHANGED:
    } catch (e0) {} // CHANGED:

    var value = String(html || ''); // CHANGED:
    var txt = $('#ppa-content'); // CHANGED:
    try { // CHANGED:
      if (window.tinyMCE && tinyMCE.get) { // CHANGED:
        var ed = tinyMCE.get('content'); // CHANGED:
        if (ed && !ed.isHidden()) { // CHANGED:
          ed.setContent(value); // CHANGED:
          return; // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } catch (e) {} // CHANGED:
    var raw = $('#content'); // CHANGED:
    if (raw) { raw.value = value; return; } // CHANGED:
    if (txt) { txt.value = value; } // CHANGED:
  } // CHANGED:

  // Extractors used by applyGenerateResult (mirrors admin.js).                  // CHANGED:
  function textFromFirstMatch(html, selector) { // CHANGED:
    try { // CHANGED:
      var tmp = document.createElement('div'); // CHANGED:
      tmp.innerHTML = html || ''; // CHANGED:
      var el = tmp.querySelector(selector); // CHANGED:
      if (!el) return ''; // CHANGED:
      return (el.textContent || '').trim(); // CHANGED:
    } catch (e) { return ''; } // CHANGED:
  } // CHANGED:

  function extractExcerptFromHtml(html) { // CHANGED:
    var p = textFromFirstMatch(html, 'p'); // CHANGED:
    if (!p) return ''; // CHANGED:
    return p.replace(/\s+/g, ' ').trim().slice(0, 300); // CHANGED:
  } // CHANGED:

  function sanitizeSlug(s) { // CHANGED:
    if (!s) return ''; // CHANGED:
    var t = String(s).toLowerCase(); // CHANGED:
    t = t.replace(/<[^>]*>/g, ''); // CHANGED:
    t = t.normalize ? t.normalize('NFKD') : t; // CHANGED:
    t = t.replace(/[^\w\s-]+/g, ''); // CHANGED:
    t = t.replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, ''); // CHANGED:
    return t; // CHANGED:
  } // CHANGED:

  // ---- Generate result helpers (pick/render/apply) -------------------------- // CHANGED:
  function pickGenerateResult(body) { // CHANGED:
    if (!body || typeof body !== 'object') return null; // CHANGED:
    var src = body; // CHANGED:
    if (src.data && typeof src.data === 'object') src = src.data; // CHANGED:
    if (src.result && typeof src.result === 'object') src = src.result; // CHANGED:
    var title = src && typeof src.title === 'string' ? src.title : ''; // CHANGED:
    var outline = src && Object.prototype.toString.call(src.outline) === '[object Array]'
      ? src.outline.slice()
      : []; // CHANGED:
    var bodyMd = src && typeof src.body_markdown === 'string'
      ? src.body_markdown
      : (typeof src.body === 'string' ? src.body : ''); // CHANGED:
    var meta = src && src.meta && typeof src.meta === 'object' ? src.meta : {}; // CHANGED:
    if (!title && !bodyMd && outline.length === 0 && !meta) return null; // CHANGED:
    return { title: title, outline: outline, body_markdown: bodyMd, meta: meta }; // CHANGED:
  } // CHANGED:

  function renderGeneratePreview(gen) { // CHANGED:
    if (!gen) { setPreview('<p>No generate result available.</p>'); return; } // CHANGED:
    var parts = []; // CHANGED:
    if (gen.title) { parts.push('<h2>' + escHtml(gen.title) + '</h2>'); } // CHANGED:
    if (gen.outline && gen.outline.length) { // CHANGED:
      parts.push('<h3>Outline</h3><ol>'); // CHANGED:
      for (var i = 0; i < gen.outline.length; i++) { // CHANGED:
        parts.push('<li>' + escHtml(String(gen.outline[i] || '')) + '</li>'); // CHANGED:
      } // CHANGED:
      parts.push('</ol>'); // CHANGED:
    } // CHANGED:
    var bodyHtml = markdownToHtml(gen.body_markdown); // CHANGED:
    if (bodyHtml) { parts.push('<h3>Draft</h3>'); parts.push(bodyHtml); } // CHANGED:
    var meta = gen.meta || {}; // CHANGED:
    var metaItems = []; // CHANGED:
    if (meta.focus_keyphrase) metaItems.push('<li><strong>Focus keyphrase:</strong> ' + escHtml(meta.focus_keyphrase) + '</li>'); // CHANGED:
    if (meta.meta_description) metaItems.push('<li><strong>Meta description:</strong> ' + escHtml(meta.meta_description) + '</li>'); // CHANGED:
    if (meta.slug) metaItems.push('<li><strong>Slug:</strong> ' + escHtml(meta.slug) + '</li>'); // CHANGED:
    if (metaItems.length) { parts.push('<h3>SEO</h3><ul>' + metaItems.join('') + '</ul>'); } // CHANGED:
    if (!parts.length) { parts.push('<p>Generate completed, but no structured result fields were found.</p>'); } // CHANGED:
    setPreview(parts.join('')); // CHANGED:
  } // CHANGED:

  // ---- Auto-fill helpers (Title/Excerpt/Slug) ------------------------------- // CHANGED:
  function getElTitle() { return $('#ppa-title') || $('#title'); } // CHANGED:
  function getElExcerpt() { return $('#ppa-excerpt') || $('#excerpt'); } // CHANGED:
  function getElSlug() { return $('#ppa-slug') || $('#post_name'); } // CHANGED:

  function setIfEmpty(el, val) { // CHANGED:
    if (!el) return; // CHANGED:
    var cur = String(el.value || '').trim(); // CHANGED:
    if (!cur && val) el.value = String(val); // CHANGED:
  } // CHANGED:

  function applyGenerateResult(gen) { // CHANGED:
    if (!gen) return; // CHANGED:
    // Render into preview pane first. // CHANGED:
    renderGeneratePreview(gen); // CHANGED:

    // Auto-fill core post fields where still empty. // CHANGED:
    var meta = gen.meta || {}; // CHANGED:
    var rawTitle = gen.title || meta.title || ''; // CHANGED:
    var title = ppaCleanTitle(rawTitle); // CHANGED:
    var slug = meta.slug || ''; // CHANGED:
    var excerpt = meta.excerpt || meta.meta_description || ''; // CHANGED:

    var bodyHtml = markdownToHtml(gen.body_markdown); // CHANGED:
    if (bodyHtml) { // CHANGED:
      setEditorContent(bodyHtml); // CHANGED:
      if (!excerpt) { excerpt = extractExcerptFromHtml(bodyHtml); } // CHANGED:
      if (!slug && title) { slug = sanitizeSlug(title); } // CHANGED:
    } // CHANGED:

    setIfEmpty(getElTitle(), title); // CHANGED:
    setIfEmpty(getElExcerpt(), excerpt); // CHANGED:
    setIfEmpty(getElSlug(), slug); // CHANGED:

    console.info('PPA: applyGenerateResult →', { // CHANGED:
      titleFilled: !!title, // CHANGED:
      excerptFilled: !!excerpt, // CHANGED:
      slugFilled: !!slug // CHANGED:
    }); // CHANGED:
  } // CHANGED:

  // ---- Optional composer payload builder (mirrors admin.js buildPreviewPayload) // CHANGED:
  function readCsvValues(el) { // CHANGED:
    if (!el) return []; // CHANGED:
    var raw = String(el.value || '').trim(); // CHANGED:
    if (!raw) return []; // CHANGED:
    return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean); // CHANGED:
  } // CHANGED:

  function buildComposerGeneratePayload(opts) { // CHANGED:
    opts = opts || {}; // CHANGED:

    var subject = $('#ppa-subject'); // CHANGED:
    var brief   = $('#ppa-brief'); // CHANGED:
    var genre   = $('#ppa-genre'); // CHANGED:
    var tone    = $('#ppa-tone'); // CHANGED:
    var wc      = $('#ppa-word-count'); // CHANGED:
    var audience = $('#ppa-audience'); // CHANGED:

    var titleEl = $('#ppa-title') || $('#title'); // CHANGED:
    var contentFromEditor = getEditorContent(); // CHANGED:
    var subjVal = subject ? subject.value : ''; // CHANGED:
    var briefVal = brief ? brief.value : ''; // CHANGED:

    // Compose HTML content for generate endpoint when editor is empty. // CHANGED:
    var composedHtml = contentFromEditor; // CHANGED:
    if (!composedHtml && briefVal) { composedHtml = toHtmlFromText(briefVal); } // CHANGED:

    var jsVer = (opts.jsVer !== undefined)
      ? String(opts.jsVer || '')
      : (window.PPA && window.PPA.jsVer ? String(window.PPA.jsVer || '') : ''); // CHANGED:

    var payload = { // CHANGED:
      // canonical inputs for Django normalize/generate // CHANGED:
      title: (titleEl && titleEl.value) ? String(titleEl.value) : String(subjVal || ''), // CHANGED:
      content: String(composedHtml || ''), // CHANGED:
      // synonyms (help backends that look for these keys) // CHANGED:
      html: String(composedHtml || ''), // CHANGED:
      text: String(briefVal || ''), // CHANGED:
      // UI/meta // CHANGED:
      subject: subjVal, // CHANGED:
      brief: briefVal, // CHANGED:
      genre: genre ? genre.value : '', // CHANGED:
      tone:  tone  ? tone.value  : '', // CHANGED:
      word_count: wc ? Number(wc.value || 0) : 0, // CHANGED:
      audience: audience ? String(audience.value || '') : '', // CHANGED:
      _js_ver: jsVer // CHANGED:
    }; // CHANGED:

    // Optional: pass tags/categories if present on the page. // CHANGED:
    var tagsEl = $('#ppa-tags') || $('#new-tag-post_tag') || $('#tax-input-post_tag'); // CHANGED:
    var catsEl = $('#ppa-categories') || $('#post_category'); // CHANGED:
    if (tagsEl) { // CHANGED:
      payload.tags = (function(){ // CHANGED:
        if (tagsEl.tagName === 'SELECT') { // CHANGED:
          return $all('option:checked', tagsEl).map(function (o) { return o.value; }); // CHANGED:
        } return readCsvValues(tagsEl); // CHANGED:
      })(); // CHANGED:
    } // CHANGED:
    if (catsEl) { // CHANGED:
      payload.categories = (function(){ // CHANGED:
        if (catsEl.tagName === 'SELECT') { // CHANGED:
          return $all('option:checked', catsEl).map(function (o) { return o.value; }); // CHANGED:
        } return readCsvValues(catsEl); // CHANGED:
      })(); // CHANGED:
    } // CHANGED:

    return payload; // CHANGED:
  } // CHANGED:

  // ---- Optional click handler (mirrors admin.js Generate handler; NOT bound) -- // CHANGED:
  function clickGuard(btn) { // CHANGED:
    if (!btn) return false; // CHANGED:
    var ts = Number(btn.getAttribute('data-ppa-ts') || 0); // CHANGED:
    var now = Date.now(); // CHANGED:
    if (now - ts < 350) return true; // CHANGED:
    btn.setAttribute('data-ppa-ts', String(now)); // CHANGED:
    return false; // CHANGED:
  } // CHANGED:

  function renderNotice(type, message) { // CHANGED:
    try { // CHANGED:
      var n = window.PPAAdminModules.notices; // CHANGED:
      if (n && typeof n.renderNotice === 'function') { n.renderNotice(type, message); return; } // CHANGED:
    } catch (e) {} // CHANGED:
    console.info('PPA: notice', { type: type, message: String(message || '') }); // CHANGED:
  } // CHANGED:

  function withBusy(promiseFactory, label) { // CHANGED:
    try { // CHANGED:
      var n = window.PPAAdminModules.notices; // CHANGED:
      if (n && typeof n.withBusy === 'function') return n.withBusy(promiseFactory, label); // CHANGED:
    } catch (e) {} // CHANGED:

    // Lightweight fallback. // CHANGED:
    try { // CHANGED:
      var p = promiseFactory(); // CHANGED:
      if (window.Promise) return window.Promise.resolve(p); // CHANGED:
      return p; // CHANGED:
    } catch (e2) { // CHANGED:
      renderNotice('error', 'There was an error while preparing your request.'); // CHANGED:
      throw e2; // CHANGED:
    } // CHANGED:
  } // CHANGED:

  function handleGenerateClick(ev, btnEl, opts) { // CHANGED:
    opts = opts || {}; // CHANGED:
    if (ev && typeof ev.preventDefault === 'function') ev.preventDefault(); // CHANGED:
    if (ev && typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation(); // CHANGED:
    if (ev && typeof ev.stopPropagation === 'function') ev.stopPropagation(); // CHANGED:
    if (btnEl && clickGuard(btnEl)) return; // CHANGED:

    console.info('PPA: Generate clicked'); // CHANGED:

    var probe = (opts.payload && typeof opts.payload === 'object')
      ? opts.payload
      : buildComposerGeneratePayload({ jsVer: opts.jsVer }); // CHANGED:

    if (!String(probe.title || '').trim() &&
        !String(probe.text || '').trim() &&
        !String(probe.content || '').trim()) { // CHANGED:
      renderNotice('warn', 'Add a subject or a brief before generating.'); // CHANGED:
      return; // CHANGED:
    } // CHANGED:

    // Use the existing stable transport surface if available; otherwise fall back to apiPost. // CHANGED:
    function doPost(payload) { // CHANGED:
      try { // CHANGED:
        if (window.PPAAdmin && typeof window.PPAAdmin.postGenerate === 'function') { // CHANGED:
          return window.PPAAdmin.postGenerate(payload); // CHANGED:
        } // CHANGED:
      } catch (e0) {} // CHANGED:

      var api = window.PPAAdminModules.api; // CHANGED:
      if (api && typeof api.apiPost === 'function') return api.apiPost('ppa_generate', payload); // CHANGED:
      return null; // CHANGED:
    } // CHANGED:

    return withBusy(function () { // CHANGED:
      var resP = doPost(probe); // CHANGED:
      if (!resP || typeof resP.then !== 'function') { // CHANGED:
        renderNotice('error', 'Generate failed: transport unavailable.'); // CHANGED:
        return null; // CHANGED:
      } // CHANGED:

      return resP.then(function (res) { // CHANGED:
        if (!res || !res.ok) { // CHANGED:
          renderNotice('error', 'Generate failed (' + (res ? res.status : 0) + ').'); // CHANGED:
          console.info('PPA: generate failed', res); // CHANGED:
          return; // CHANGED:
        } // CHANGED:

        var gen = pickGenerateResult(res.body); // CHANGED:
        try { window.PPA_LAST_GENERATE = res; } catch (e1) {} // CHANGED:

        if (!gen) { // CHANGED:
          var pretty = escHtml(JSON.stringify(res.body, null, 2)); // CHANGED:
          setPreview('<pre class="ppa-json">' + pretty + '</pre>'); // CHANGED:
          renderNotice('warn', 'Generate completed, but result shape was unexpected; showing JSON.'); // CHANGED:
          console.info('PPA: generate unexpected result shape', res); // CHANGED:
          return; // CHANGED:
        } // CHANGED:

        applyGenerateResult(gen); // CHANGED:
        renderNotice('success', 'AI draft generated. Review, tweak, then Save Draft or Publish.'); // CHANGED:
      }); // CHANGED:
    }, 'generate'); // CHANGED:
  } // CHANGED:

  // ---- Public API ------------------------------------------------------------
  /**
   * generate(input[, options])
   *
   * input:
   * - Either a prepared payload: {subject, brief, content, ...}
   * - Or an object compatible with payload builder: { subjectEl, briefEl, contentEl, ... }
   *
   * options:
   * - storeDebug: boolean (default true) — stores window.PPA_LAST_GENERATE when complete
   * - apiOptions: passed through to apiPost (headers/timeout/ajaxUrl/nonce etc.)
   *
   * Returns:
   * - Promise/Deferred (same as api.apiPost) resolving to:
   *   {
   *     ok: boolean,              // accounts for HTTP + WP envelope success where present
   *     status: number,
   *     transport: { ...apiPostNormalized },   // raw transport object from apiPost
   *     wp: { hasEnvelope, success, data },    // parsed WP envelope if present
   *     data: any,                // wp.data or body
   *     djangoResult: any,         // extracted result-ish object
   *     model: any                 // normalized model if generateView exists
   *   }
   */
  function generate(input, options) {
    options = options || {};

    var api = window.PPAAdminModules.api;
    if (!api || typeof api.apiPost !== "function") {
      // Return a Promise-like if possible; else return a plain object.
      if (window.Promise) {
        return window.Promise.resolve({
          ok: false,
          status: 0,
          error: "api_module_missing"
        });
      }
      return {
        ok: false,
        status: 0,
        error: "api_module_missing"
      };
    }

    var payload = buildGeneratePayload(input);

    // Call WP ajax action: ppa_generate (WP proxy -> Django /generate/)
    // If apiPost only accepts (action, data), the 3rd arg is safely ignored in JS.
    var p = api.apiPost("ppa_generate", payload, options.apiOptions || {});

    // p may be Promise or jQuery Deferred promise; normalize via then() where possible.
    if (p && typeof p.then === "function") {
      return p.then(function (transport) {
        var wp = unwrapWpAjax(transport.body);

        // Determine overall ok:
        // - transport.ok implies HTTP 2xx
        // - if WP envelope exists, require wp.success===true
        var overallOk = transport.ok;
        if (wp.hasEnvelope) {
          overallOk = overallOk && (wp.success === true);
        }

        var data = wp.hasEnvelope ? wp.data : transport.body;
        var djangoResult = pickDjangoResultShape(data);
        var model = normalizeResultModel(djangoResult);

        var out = {
          ok: overallOk,
          status: transport.status,
          transport: transport,
          wp: wp,
          data: data,
          djangoResult: djangoResult,
          model: model
        };

        // Optional debug store — matches existing Composer debug hook expectation.
        var storeDebug = (options.storeDebug !== undefined) ? !!options.storeDebug : true;
        if (storeDebug) {
          try {
            window.PPA_LAST_GENERATE = out;
          } catch (e) {
            // ignore
          }
        }

        return out;
      }, function (transportErr) {
        // Rejection path: still normalize shape for callers
        var wpErr = unwrapWpAjax(transportErr && transportErr.body);

        var dataErr = wpErr.hasEnvelope ? wpErr.data : (transportErr ? transportErr.body : null);
        var djangoResultErr = pickDjangoResultShape(dataErr);
        var modelErr = normalizeResultModel(djangoResultErr);

        var outErr = {
          ok: false,
          status: transportErr && transportErr.status ? transportErr.status : 0,
          transport: transportErr,
          wp: wpErr,
          data: dataErr,
          djangoResult: djangoResultErr,
          model: modelErr
        };

        var storeDebug2 = (options.storeDebug !== undefined) ? !!options.storeDebug : true;
        if (storeDebug2) {
          try {
            window.PPA_LAST_GENERATE = outErr;
          } catch (e2) {
            // ignore
          }
        }

        // Re-throw to preserve caller's error flow
        throw outErr;
      });
    }

    // If apiPost returned null (no Promise + no jQuery), fail safely.
    if (window.Promise) {
      return window.Promise.resolve({
        ok: false,
        status: 0,
        error: "no_promise_support"
      });
    }

    return {
      ok: false,
      status: 0,
      error: "no_promise_support"
    };
  }

  // Export (merge)
  composerGenerate.ver = MOD_VER;
  composerGenerate.generate = generate;
  composerGenerate._unwrapWpAjax = unwrapWpAjax;
  composerGenerate._pickDjangoResultShape = pickDjangoResultShape;
  composerGenerate._buildGeneratePayload = buildGeneratePayload;
  composerGenerate._normalizeResultModel = normalizeResultModel;

  // Parity helpers for future cutover from assets/js/admin.js (NOT auto-wired). // CHANGED:
  composerGenerate.pickGenerateResult = pickGenerateResult; // CHANGED:
  composerGenerate.renderGeneratePreview = renderGeneratePreview; // CHANGED:
  composerGenerate.applyGenerateResult = applyGenerateResult; // CHANGED:
  composerGenerate.buildComposerGeneratePayload = buildComposerGeneratePayload; // CHANGED:
  composerGenerate.handleGenerateClick = handleGenerateClick; // CHANGED:

  window.PPAAdminModules.composerGenerate = composerGenerate;

})(window, document);
