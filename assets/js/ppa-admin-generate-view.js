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
 * 2026-01-21.1: FIX: Outline now renders as true individual list items (splits multi-line outline strings,
 *               strips bullets/numbering), and links each item to its matching heading in the Draft by
 *               injecting stable heading IDs into the rendered preview HTML.                              // CHANGED:
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

  // CHANGED: Normalize text for matching (outline item -> heading).
  function normText(s) {
    return toStr(s)
      .toLowerCase()
      .replace(/<[^>]*>/g, '')
      .replace(/&[^;]+;/g, ' ')
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  // CHANGED: Strip common outline prefixes (bullets/numbering).
  function stripOutlinePrefix(line) {
    var s = trim(line);
    // Examples: "- Item", "* Item", "1. Item", "1) Item", "I. Item"
    s = s.replace(/^\s*(?:[-*+•]|\(?\d+\)?[\.)]|[ivxlcdm]+\.)\s+/i, '');
    return trim(s);
  }

  // CHANGED: Turn outline into clean array of items (supports array, string, multi-line strings).
  function parseOutlineItems(outline) {
    var out = [];
    var pushLine = function (ln) {
      var cleaned = stripOutlinePrefix(ln);
      if (cleaned) out.push(cleaned);
    };

    if (!outline) return out;

    if (typeof outline === 'string') {
      var lines = outline.split(/\r?\n/);
      for (var i = 0; i < lines.length; i++) {
        pushLine(lines[i]);
      }
      return out;
    }

    if (isArray(outline)) {
      for (var j = 0; j < outline.length; j++) {
        var item = outline[j];
        if (typeof item === 'string') {
          // Some backends send a single string containing a markdown list.
          if (item.indexOf('\n') !== -1) {
            var sub = item.split(/\r?\n/);
            for (var k = 0; k < sub.length; k++) pushLine(sub[k]);
          } else {
            pushLine(item);
          }
        }
      }
    }

    return out;
  }

  // CHANGED: Slugify for heading ids (stable + URL-safe).
  function slugifyId(text) {
    var s = normText(text);
    if (!s) return 'section';
    return s.replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
  }

  // CHANGED: Ensure IDs are unique within one rendered preview.
  function uniqueId(base, used) {
    var b = base || 'section';
    if (!used[b]) { used[b] = 1; return b; }
    used[b] += 1;
    return b + '-' + used[b];
  }

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
    // CHANGED: Robust outline parsing.
    // - Accept array or string.
    // - Split multi-line strings into individual items.
    // - Strip bullets/numbering ("- ", "1.", "1)" etc.).
    var cleanOutline = parseOutlineItems(r && r.outline);

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

  function markdownToHtml(m, ctx) { // CHANGED: ctx collects headings + id map for outline linking
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
        var rawHeadingText = trim(mHeading[2] || ""); // CHANGED

        // CHANGED: Inject stable IDs into headings so the Outline can link/scroll.
        // ctx is created per-render (see buildPreviewHtml) so ids are stable within the current preview.
        var idAttr = "";
        if (ctx && typeof ctx === 'object') {
          if (!ctx._usedIds) ctx._usedIds = {};
          if (!ctx._headingMap) ctx._headingMap = {};
          if (!ctx._headings) ctx._headings = [];

          var baseId = slugifyId(rawHeadingText);
          var hid = uniqueId(baseId, ctx._usedIds);
          var key = normText(rawHeadingText);
          if (key && !ctx._headingMap[key]) {
            ctx._headingMap[key] = hid;
          }
          ctx._headings.push({ text: rawHeadingText, id: hid, level: level });
          idAttr = ' id="' + escapeHtml(hid) + '"';
        }

        htmlParts.push("<h" + level + idAttr + ">" + applyInline(rawHeadingText) + "</h" + level + ">");
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

  function buildOutlineHtml(outlineArr, ctx) { // CHANGED: link outline items to headings when possible
    if (!outlineArr || !outlineArr.length) return "";

    var html = "<ol>";
    for (var i = 0; i < outlineArr.length; i++) {
      var itemText = trim(outlineArr[i]);
      if (!itemText) continue;

      var id = "";
      if (ctx && ctx._headingMap) {
        var key = normText(itemText);
        id = ctx._headingMap[key] || "";

        // Loose matching: sometimes outline text is slightly different than heading text.
        if (!id && ctx._headings && ctx._headings.length) {
          for (var h = 0; h < ctx._headings.length; h++) {
            var ht = normText(ctx._headings[h].text);
            if (ht && (ht === key || ht.indexOf(key) !== -1 || key.indexOf(ht) !== -1)) {
              id = ctx._headings[h].id;
              break;
            }
          }
        }
      }

      if (id) {
        html += "<li><a href=\"#" + escapeHtml(id) + "\" data-ppa-outline-link=\"1\">" + escapeHtml(itemText) + "</a></li>";
      } else {
        html += "<li>" + escapeHtml(itemText) + "</li>";
      }
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

    // CHANGED: Build the draft first so we can inject stable heading IDs and then link outline items to those headings.
    var ctx = { _usedIds: {}, _headingMap: {}, _headings: [] };
    var bodyHtml = markdownToHtml(model.body_markdown, ctx);

    // CHANGED: If outline is empty/missing, derive a simple outline from headings (skip H1).
    var outlineItems = (model.outline && model.outline.length) ? model.outline : [];
    if ((!outlineItems || !outlineItems.length) && ctx._headings && ctx._headings.length) {
      var derived = [];
      for (var d = 0; d < ctx._headings.length; d++) {
        if (ctx._headings[d].level && ctx._headings[d].level <= 1) continue;
        derived.push(ctx._headings[d].text);
      }
      outlineItems = derived;
    }

    if (outlineItems && outlineItems.length) {
      parts.push("<h3>Outline</h3>");
      parts.push(buildOutlineHtml(outlineItems, ctx));
    }

    if (bodyHtml) { parts.push("<h3>Draft</h3>"); parts.push(bodyHtml); }
    var metaHtml = buildMetaListHtml(model.meta);
    if (metaHtml) { parts.push("<h3>SEO</h3>"); parts.push(metaHtml); }
    if (!parts.length) parts.push("<p>No preview content available.</p>");
    return "<div class=\"ppa-preview\">" + parts.join("") + "</div>";
  }

  // CHANGED: Make outline links scroll within the preview pane (not the whole admin page).
  function bindOutlineScroll(container) {
    if (!container || container.nodeType !== 1) return;
    if (container.dataset && container.dataset.ppaOutlineBound === "1") return;
    if (container.dataset) container.dataset.ppaOutlineBound = "1";

    container.addEventListener("click", function (ev) {
      var t = ev.target;
      if (!t) return;
      // Support clicks on nested spans inside the link.
      var link = (t.closest) ? t.closest('a[data-ppa-outline-link="1"]') : null;
      if (!link) return;

      var href = link.getAttribute("href") || "";
      if (href.charAt(0) !== "#") return;
      var id = href.slice(1);
      if (!id) return;

      var escId = (window.CSS && typeof CSS.escape === 'function')
        ? CSS.escape(id)
        : id.replace(/[^a-zA-Z0-9_\-]/g, "\\$"); // fallback
      var target = container.querySelector("#" + escId);
      if (!target) return;

      ev.preventDefault();

      try {
        target.scrollIntoView({ behavior: "smooth", block: "start" });
      } catch (e) {
        target.scrollIntoView();
      }

      // Keep the hash updated (without jumping the entire page).
      try {
        if (history && history.replaceState) {
          history.replaceState(null, document.title, "#" + id);
        }
      } catch (e2) {}
    }, { passive: false });
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
      bindOutlineScroll(container); // CHANGED
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
