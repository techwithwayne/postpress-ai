/* global window, document */
/**
 * PostPress AI — Generate View Module (ES5-safe)
 *
 * Purpose:
 * - Provide pure view helpers for rendering a Generate Preview result into a preview pane.
 * - NO side effects on load.
 * - Not wired into admin.js yet (one-file rule).
 *
 * Inputs supported (defensive):
 * - Normalized Django/WP proxy shapes:
 *   - { title, outline:[], body_markdown, meta:{...} }
 *   - { result:{...} }
 *   - { data:{ result:{...} } } (common WP ajax wrapper)
 *
 * ========= CHANGE LOG =========
 * 2025-12-22.1: Safety hardening: only accept ELEMENT containers (never the Document); guard DOM mutations with try/catch so bad callers can’t hard-crash the Composer screen. Output HTML unchanged. // CHANGED:
 * 2025-12-21.1: Render Generate preview like stable admin.js (Title as h2; Outline/Draft/SEO sections); // CHANGED:
 *               replace raw <pre> dump + "Title/Outline/Body/Meta" blocks with safe Markdown-ish HTML. // CHANGED:
 * 2025-12-20.2: Merge export (no early return) to avoid late-load clobber during modular cutover; no behavior change. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-generate-view.v2025-12-22.1"; // CHANGED:
  var generateView = window.PPAAdminModules.generateView || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function toStr(val) {
    return (val === undefined || val === null) ? "" : String(val);
  }

  function trim(val) {
    return toStr(val).replace(/^\s+|\s+$/g, "");
  }

  function isArray(val) {
    return Object.prototype.toString.call(val) === "[object Array]";
  }

  function escapeHtml(str) {
    // Minimal HTML escaping for safe rendering in admin
    return toStr(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function isEl(node) {
    // Safety: ONLY accept ELEMENT nodes (never Document). // CHANGED:
    return !!(node && node.nodeType === 1); // CHANGED:
  }

  function getEl(selectorOrEl) {
    if (!selectorOrEl) return null;

    if (isEl(selectorOrEl)) return selectorOrEl;

    if (typeof selectorOrEl === "string") {
      try {
        return document.querySelector(selectorOrEl);
      } catch (e) {
        return null;
      }
    }

    return null;
  }

  function removeAllChildren(el) {
    if (!el || el.nodeType !== 1) return; // CHANGED:
    while (el.firstChild) {
      el.removeChild(el.firstChild);
    }
  }

  // ---- Result normalization --------------------------------------------------
  function unwrapResultShape(input) {
    // Accept multiple wrapper shapes and return the inner "result-ish" object.
    if (!input || typeof input !== "object") return {};

    // Direct normalized
    if (hasOwn(input, "title") || hasOwn(input, "outline") || hasOwn(input, "body_markdown")) {
      return input;
    }

    // { result: {...} }
    if (input.result && typeof input.result === "object") {
      return input.result;
    }

    // { data: { result: {...} } }
    if (input.data && typeof input.data === "object") {
      if (input.data.result && typeof input.data.result === "object") {
        return input.data.result;
      }
      // Sometimes WP returns { success:true, data:{...normalized...} }
      if (hasOwn(input.data, "title") || hasOwn(input.data, "outline") || hasOwn(input.data, "body_markdown")) {
        return input.data;
      }
    }

    // Fallback: return as-is
    return input;
  }

  function normalizeGenerateResult(input) {
    var r = unwrapResultShape(input);

    var meta = (r && r.meta && typeof r.meta === "object") ? r.meta : {};

    var outline = [];
    if (r && isArray(r.outline)) {
      outline = r.outline;
    } else if (r && r.outline) {
      // Coerce single outline value into array
      outline = [r.outline];
    }

    // Ensure outline is array of strings (trimmed)
    var cleanOutline = [];
    for (var i = 0; i < outline.length; i++) {
      var item = trim(outline[i]);
      if (item) cleanOutline.push(item);
    }

    return {
      title: trim(r && r.title),
      outline: cleanOutline,
      body_markdown: toStr(r && r.body_markdown),
      meta: {
        focus_keyphrase: trim(meta.focus_keyphrase),
        meta_description: trim(meta.meta_description),
        slug: trim(meta.slug)
      }
    };
  }

  // ---- Markdown-ish renderer (safe, ES5) ------------------------------------ // CHANGED:
  // Goal: match stable admin.js preview behavior without requiring marked.js.   // CHANGED:
  function toHtmlFromText(text) { // CHANGED:
    var t = trim(text); // CHANGED:
    if (!t) return ""; // CHANGED:
    var parts = t.split(/\n{2,}/); // CHANGED:
    for (var i = 0; i < parts.length; i++) { // CHANGED:
      var safe = escapeHtml(parts[i]).replace(/\n/g, "<br>"); // CHANGED:
      parts[i] = "<p>" + safe + "</p>"; // CHANGED:
    } // CHANGED:
    return parts.join(""); // CHANGED:
  } // CHANGED:

  function markdownToHtml(m) { // CHANGED:
    var txt = toStr(m); // CHANGED:
    if (!txt) return ""; // CHANGED:

    // If it already looks like HTML, return as-is (parity with stable admin.js). // CHANGED:
    if (/<[a-z][\s\S]*>/i.test(txt)) { // CHANGED:
      return txt; // CHANGED:
    } // CHANGED:

    function applyInline(mdText) { // CHANGED:
      var s = escapeHtml(mdText); // CHANGED:
      // Bold: **text** or __text__ // CHANGED:
      s = s.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>"); // CHANGED:
      s = s.replace(/__(.+?)__/g, "<strong>$1</strong>"); // CHANGED:
      // Emphasis: *text* or _text_ // CHANGED:
      s = s.replace(/\*(.+?)\*/g, "<em>$1</em>"); // CHANGED:
      s = s.replace(/_(.+?)_/g, "<em>$1</em>"); // CHANGED:
      return s; // CHANGED:
    } // CHANGED:

    var lines = txt.split(/\r?\n/); // CHANGED:
    var htmlParts = []; // CHANGED:
    var inList = false; // CHANGED:
    var paraBuf = []; // CHANGED:

    function flushParagraph() { // CHANGED:
      if (!paraBuf.length) return; // CHANGED:
      var text = paraBuf.join(" ").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, ""); // CHANGED:
      if (!text) { paraBuf = []; return; } // CHANGED:
      htmlParts.push("<p>" + applyInline(text) + "</p>"); // CHANGED:
      paraBuf = []; // CHANGED:
    } // CHANGED:

    function flushList() { // CHANGED:
      if (!inList) return; // CHANGED:
      htmlParts.push("</ul>"); // CHANGED:
      inList = false; // CHANGED:
    } // CHANGED:

    for (var i = 0; i < lines.length; i++) { // CHANGED:
      var line = lines[i]; // CHANGED:
      var trimmedLine = trim(line); // CHANGED:

      if (!trimmedLine) { // CHANGED:
        flushParagraph(); // CHANGED:
        flushList(); // CHANGED:
        continue; // CHANGED:
      } // CHANGED:

      // Headings: #, ##, ###, ####, #####, ###### // CHANGED:
      var mHeading = trimmedLine.match(/^(#{1,6})\s+(.*)$/); // CHANGED:
      if (mHeading) { // CHANGED:
        flushParagraph(); // CHANGED:
        flushList(); // CHANGED:
        var level = mHeading[1].length; // CHANGED:
        if (level < 1) level = 1; // CHANGED:
        if (level > 6) level = 6; // CHANGED:
        var hText = applyInline(mHeading[2] || ""); // CHANGED:
        htmlParts.push("<h" + level + ">" + hText + "</h" + level + ">"); // CHANGED:
        continue; // CHANGED:
      } // CHANGED:

      // Unordered list items: -, *, + at start // CHANGED:
      var mList = trimmedLine.match(/^[-*+]\s+(.*)$/); // CHANGED:
      if (mList) { // CHANGED:
        flushParagraph(); // CHANGED:
        if (!inList) { // CHANGED:
          htmlParts.push("<ul>"); // CHANGED:
          inList = true; // CHANGED:
        } // CHANGED:
        htmlParts.push("<li>" + applyInline(mList[1] || "") + "</li>"); // CHANGED:
        continue; // CHANGED:
      } // CHANGED:

      // Otherwise: paragraph text buffer // CHANGED:
      paraBuf.push(trimmedLine); // CHANGED:
    } // CHANGED:

    flushParagraph(); // CHANGED:
    flushList(); // CHANGED:

    if (!htmlParts.length) { // CHANGED:
      return toHtmlFromText(txt); // CHANGED:
    } // CHANGED:
    return htmlParts.join(""); // CHANGED:
  } // CHANGED:

  // ---- HTML builders (no side effects) -------------------------------------- // CHANGED:
  function buildOutlineHtml(outlineArr) { // CHANGED:
    if (!outlineArr || !outlineArr.length) { // CHANGED:
      return ""; // CHANGED:
    } // CHANGED:

    var html = "<ol>"; // CHANGED:
    for (var i = 0; i < outlineArr.length; i++) { // CHANGED:
      html += "<li>" + escapeHtml(outlineArr[i]) + "</li>"; // CHANGED:
    } // CHANGED:
    html += "</ol>"; // CHANGED:
    return html; // CHANGED:
  } // CHANGED:

  function buildMetaListHtml(meta) { // CHANGED:
    meta = meta || {}; // CHANGED:

    var items = []; // CHANGED:
    if (meta.focus_keyphrase) { // CHANGED:
      items.push("<li><strong>Focus keyphrase:</strong> " + escapeHtml(meta.focus_keyphrase) + "</li>"); // CHANGED:
    } // CHANGED:
    if (meta.meta_description) { // CHANGED:
      items.push("<li><strong>Meta description:</strong> " + escapeHtml(meta.meta_description) + "</li>"); // CHANGED:
    } // CHANGED:
    if (meta.slug) { // CHANGED:
      items.push("<li><strong>Slug:</strong> " + escapeHtml(meta.slug) + "</li>"); // CHANGED:
    } // CHANGED:

    if (!items.length) return ""; // CHANGED:
    return "<ul>" + items.join("") + "</ul>"; // CHANGED:
  } // CHANGED:

  function buildPreviewHtml(model, options) { // CHANGED:
    options = options || {}; // CHANGED:

    // Stable preview structure (parity with admin.js renderGeneratePreview). // CHANGED:
    var parts = []; // CHANGED:

    if (model.title) { // CHANGED:
      parts.push("<h2>" + escapeHtml(model.title) + "</h2>"); // CHANGED:
    } // CHANGED:

    if (model.outline && model.outline.length) { // CHANGED:
      parts.push("<h3>Outline</h3>"); // CHANGED:
      parts.push(buildOutlineHtml(model.outline)); // CHANGED:
    } // CHANGED:

    var bodyHtml = markdownToHtml(model.body_markdown); // CHANGED:
    if (bodyHtml) { // CHANGED:
      parts.push("<h3>Draft</h3>"); // CHANGED:
      parts.push(bodyHtml); // CHANGED:
    } // CHANGED:

    var metaHtml = buildMetaListHtml(model.meta); // CHANGED:
    if (metaHtml) { // CHANGED:
      parts.push("<h3>SEO</h3>"); // CHANGED:
      parts.push(metaHtml); // CHANGED:
    } // CHANGED:

    if (!parts.length) { // CHANGED:
      parts.push("<p>No preview content available.</p>"); // CHANGED:
    } // CHANGED:

    // Keep wrapper class for backward compatibility with any existing admin CSS. // CHANGED:
    return "<div class=\"ppa-preview\">" + parts.join("") + "</div>"; // CHANGED:
  } // CHANGED:

  // ---- Render helper (acts only when called) --------------------------------
  /**
   * renderPreview(containerOrSelector, input, options)
   *
   * - containerOrSelector: DOM element or selector string
   * - input: any supported result shape (see header)
   * - options:
   *   - allowMarked: boolean (kept for compatibility; no longer required for readable output) // CHANGED:
   *   - mode: "replace" (default) or "append"
   *
   * Returns:
   * - { model, html, container } for caller debugging
   */
  function renderPreview(containerOrSelector, input, options) {
    options = options || {};
    var container = getEl(containerOrSelector);

    if (!container || container.nodeType !== 1) { // CHANGED:
      return {
        model: normalizeGenerateResult(input),
        html: "",
        container: null
      };
    }

    var model = normalizeGenerateResult(input);
    var html = buildPreviewHtml(model, options);

    var mode = options.mode || "replace";

    try { // CHANGED:
      if (mode === "append") {
        // Append as a wrapper node
        var wrapper = document.createElement("div");
        wrapper.innerHTML = html;
        container.appendChild(wrapper);
      } else {
        // Replace contents
        removeAllChildren(container);
        container.innerHTML = html;
      }
    } catch (e) { // CHANGED:
      // Fail safe: do not crash the page if caller passes a bad container or DOM is locked. // CHANGED:
      return { model: model, html: "", container: null }; // CHANGED:
    } // CHANGED:

    return {
      model: model,
      html: html,
      container: container
    };
  }

  // ---- Public export (merge) -------------------------------------------------
  generateView.ver = MOD_VER; // CHANGED:
  generateView.normalizeGenerateResult = normalizeGenerateResult; // CHANGED:
  generateView.buildPreviewHtml = buildPreviewHtml; // CHANGED:
  generateView.renderPreview = renderPreview; // CHANGED:

  // low-level helpers (kept for future wiring)
  generateView._escapeHtml = escapeHtml; // CHANGED:
  generateView._unwrapResultShape = unwrapResultShape; // CHANGED:
  generateView._markdownToHtml = markdownToHtml; // CHANGED:

  window.PPAAdminModules.generateView = generateView; // CHANGED:

})(window, document);
