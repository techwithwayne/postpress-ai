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

  window.PPAAdminModules = window.PPAAdminModules || {};

  var MOD_VER = "ppa-admin-generate-view.v2025-12-22.1"; // CHANGED:
  var generateView = window.PPAAdminModules.generateView || {}; // CHANGED:

  function hasOwn(obj, key) { return Object.prototype.hasOwnProperty.call(obj, key); }
  function toStr(val) { return (val === undefined || val === null) ? "" : String(val); }
  function trim(val) { return toStr(val).replace(/^\s+|\s+$/g, ""); }
  function isArray(val) { return Object.prototype.toString.call(val) === "[object Array]"; }

  function escapeHtml(str) {
    return toStr(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function isEl(node) { return !!(node && node.nodeType === 1); } // CHANGED:

  function getEl(selectorOrEl) {
    if (!selectorOrEl) return null;
    if (isEl(selectorOrEl)) return selectorOrEl;
    if (typeof selectorOrEl === "string") {
      try { return document.querySelector(selectorOrEl); } catch (e) { return null; }
    }
    return null;
  }

  function removeAllChildren(el) {
    if (!el || el.nodeType !== 1) return; // CHANGED:
    while (el.firstChild) el.removeChild(el.firstChild);
  }

  function unwrapResultShape(input) {
    if (!input || typeof input !== "object") return {};
    if (hasOwn(input, "title") || hasOwn(input, "outline") || hasOwn(input, "body_markdown")) return input;
    if (input.result && typeof input.result === "object") return input.result;
    if (input.data && typeof input.data === "object") {
      if (input.data.result && typeof input.data.result === "object") return input.data.result;
      if (hasOwn(input.data, "title") || hasOwn(input.data, "outline") || hasOwn(input.data, "body_markdown")) return input.data;
    }
    return input;
  }

  function normalizeGenerateResult(input) {
    var r = unwrapResultShape(input);
    var meta = (r && r.meta && typeof r.meta === "object") ? r.meta : {};
    var outline = [];
    if (r && isArray(r.outline)) outline = r.outline;
    else if (r && r.outline) outline = [r.outline];

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

  function toHtmlFromText(text) {
    var t = trim(text);
    if (!t) return "";
    var parts = t.split(/\n{2,}/);
    for (var i = 0; i < parts.length; i++) {
      var safe = escapeHtml(parts[i]).replace(/\n/g, "<br>");
      parts[i] = "<p>" + safe + "</p>";
    }
    return parts.join("");
  }

  function markdownToHtml(m) {
    var txt = toStr(m);
    if (!txt) return "";
    if (/<[a-z][\s\S]*>/i.test(txt)) return txt;

    function applyInline(mdText) {
      var s = escapeHtml(mdText);
      s = s.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
      s = s.replace(/__(.+?)__/g, "<strong>$1</strong>");
      s = s.replace(/\*(.+?)\*/g, "<em>$1</em>");
      s = s.replace(/_(.+?)_/g, "<em>$1</em>");
      return s;
    }

    var lines = txt.split(/\r?\n/);
    var htmlParts = [];
    var inList = false;
    var paraBuf = [];

    function flushParagraph() {
      if (!paraBuf.length) return;
      var text = paraBuf.join(" ").replace(/\s+/g, " ").replace(/^\s+|\s+$/g, "");
      if (!text) { paraBuf = []; return; }
      htmlParts.push("<p>" + applyInline(text) + "</p>");
      paraBuf = [];
    }

    function flushList() {
      if (!inList) return;
      htmlParts.push("</ul>");
      inList = false;
    }

    for (var i = 0; i < lines.length; i++) {
      var trimmedLine = trim(lines[i]);
      if (!trimmedLine) { flushParagraph(); flushList(); continue; }

      var mHeading = trimmedLine.match(/^(#{1,6})\s+(.*)$/);
      if (mHeading) {
        flushParagraph(); flushList();
        var level = Math.min(Math.max(mHeading[1].length, 1), 6);
        htmlParts.push("<h" + level + ">" + applyInline(mHeading[2] || "") + "</h" + level + ">");
        continue;
      }

      var mList = trimmedLine.match(/^[-*+]\s+(.*)$/);
      if (mList) {
        flushParagraph();
        if (!inList) { htmlParts.push("<ul>"); inList = true; }
        htmlParts.push("<li>" + applyInline(mList[1] || "") + "</li>");
        continue;
      }

      paraBuf.push(trimmedLine);
    }

    flushParagraph(); flushList();
    return htmlParts.length ? htmlParts.join("") : toHtmlFromText(txt);
  }

  function buildOutlineHtml(outlineArr) {
    if (!outlineArr || !outlineArr.length) return "";
    var html = "<ol>";
    for (var i = 0; i < outlineArr.length; i++) {
      html += "<li>" + escapeHtml(outlineArr[i]) + "</li>";
    }
    html += "</ol>";
    return html;
  }

  function buildMetaListHtml(meta) {
    meta = meta || {};
    var items = [];
    if (meta.focus_keyphrase) items.push("<li><strong>Focus keyphrase:</strong> " + escapeHtml(meta.focus_keyphrase) + "</li>");
    if (meta.meta_description) items.push("<li><strong>Meta description:</strong> " + escapeHtml(meta.meta_description) + "</li>");
    if (meta.slug) items.push("<li><strong>Slug:</strong> " + escapeHtml(meta.slug) + "</li>");
    return items.length ? "<ul>" + items.join("") + "</ul>" : "";
  }

  function buildPreviewHtml(model) {
    var parts = [];
    if (model.title) parts.push("<h2>" + escapeHtml(model.title) + "</h2>");
    if (model.outline && model.outline.length) { parts.push("<h3>Outline</h3>"); parts.push(buildOutlineHtml(model.outline)); }
    var bodyHtml = markdownToHtml(model.body_markdown);
    if (bodyHtml) { parts.push("<h3>Draft</h3>"); parts.push(bodyHtml); }
    var metaHtml = buildMetaListHtml(model.meta);
    if (metaHtml) { parts.push("<h3>SEO</h3>"); parts.push(metaHtml); }
    if (!parts.length) parts.push("<p>No preview content available.</p>");
    return "<div class=\"ppa-preview\">" + parts.join("") + "</div>";
  }

  function renderPreview(containerOrSelector, input, options) {
    options = options || {};
    var container = getEl(containerOrSelector);
    if (!container || container.nodeType !== 1) {
      return { model: normalizeGenerateResult(input), html: "", container: null };
    }

    var model = normalizeGenerateResult(input);
    var html = buildPreviewHtml(model);
    var mode = options.mode || "replace";

    try {
      if (mode === "append") {
        var wrapper = document.createElement("div");
        wrapper.innerHTML = html;
        container.appendChild(wrapper);
      } else {
        removeAllChildren(container);
        container.innerHTML = html;
      }
    } catch (e) {
      return { model: model, html: "", container: null };
    }

    return { model: model, html: html, container: container };
  }

  generateView.ver = MOD_VER;
  generateView.normalizeGenerateResult = normalizeGenerateResult;
  generateView.buildPreviewHtml = buildPreviewHtml;
  generateView.renderPreview = renderPreview;

  generateView._escapeHtml = escapeHtml;
  generateView._unwrapResultShape = unwrapResultShape;
  generateView._markdownToHtml = markdownToHtml;

  window.PPAAdminModules.generateView = generateView;
})(window, document);
