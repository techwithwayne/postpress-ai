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
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  if (window.PPAAdminModules.generateView) {
    return;
  }

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
    return !!(node && (node.nodeType === 1 || node.nodeType === 9));
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
    if (!el) return;
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

  // ---- HTML builders (no side effects) --------------------------------------
  function buildOutlineHtml(outlineArr) {
    if (!outlineArr || !outlineArr.length) {
      return '<div class="ppa-preview-empty">No outline returned.</div>';
    }

    var html = "<ol class=\"ppa-preview-outline\">";
    for (var i = 0; i < outlineArr.length; i++) {
      html += "<li>" + escapeHtml(outlineArr[i]) + "</li>";
    }
    html += "</ol>";
    return html;
  }

  function buildBodyHtml(bodyMarkdown, options) {
    options = options || {};

    // Default: render as escaped <pre> to avoid any unsafe HTML assumptions.
    // If the caller explicitly opts in AND marked is present, we can render markdown.
    var allowMarked = !!options.allowMarked;

    if (allowMarked && window.marked && typeof window.marked.parse === "function") {
      // NOTE: marked is not sanitized by default. Caller must decide if this is acceptable.
      return "<div class=\"ppa-preview-body ppa-preview-body-marked\">" + window.marked.parse(toStr(bodyMarkdown)) + "</div>";
    }

    return "<pre class=\"ppa-preview-body ppa-preview-body-pre\">" + escapeHtml(toStr(bodyMarkdown)) + "</pre>";
  }

  function buildMetaTableHtml(meta) {
    meta = meta || {};

    var fk = meta.focus_keyphrase ? escapeHtml(meta.focus_keyphrase) : "";
    var md = meta.meta_description ? escapeHtml(meta.meta_description) : "";
    var sl = meta.slug ? escapeHtml(meta.slug) : "";

    return (
      "<table class=\"ppa-preview-meta\" role=\"presentation\">" +
        "<tbody>" +
          "<tr><th>Focus keyphrase</th><td>" + (fk || "<em>—</em>") + "</td></tr>" +
          "<tr><th>Meta description</th><td>" + (md || "<em>—</em>") + "</td></tr>" +
          "<tr><th>Slug</th><td>" + (sl || "<em>—</em>") + "</td></tr>" +
        "</tbody>" +
      "</table>"
    );
  }

  function buildPreviewHtml(model, options) {
    options = options || {};

    var titleHtml = model.title ? escapeHtml(model.title) : "<em>No title returned.</em>";

    var html = "";
    html += "<div class=\"ppa-preview\">";

    html += "<div class=\"ppa-preview-section ppa-preview-title\">";
    html += "<h3 class=\"ppa-preview-h\">Title</h3>";
    html += "<div class=\"ppa-preview-title-text\">" + titleHtml + "</div>";
    html += "</div>";

    html += "<div class=\"ppa-preview-section ppa-preview-outline-wrap\">";
    html += "<h3 class=\"ppa-preview-h\">Outline</h3>";
    html += buildOutlineHtml(model.outline);
    html += "</div>";

    html += "<div class=\"ppa-preview-section ppa-preview-body-wrap\">";
    html += "<h3 class=\"ppa-preview-h\">Body</h3>";
    html += buildBodyHtml(model.body_markdown, options);
    html += "</div>";

    html += "<div class=\"ppa-preview-section ppa-preview-meta-wrap\">";
    html += "<h3 class=\"ppa-preview-h\">Meta</h3>";
    html += buildMetaTableHtml(model.meta);
    html += "</div>";

    html += "</div>";
    return html;
  }

  // ---- Render helper (acts only when called) --------------------------------
  /**
   * renderPreview(containerOrSelector, input, options)
   *
   * - containerOrSelector: DOM element or selector string
   * - input: any supported result shape (see header)
   * - options:
   *   - allowMarked: boolean (default false) — only used if window.marked exists
   *   - mode: "replace" (default) or "append"
   *
   * Returns:
   * - { model, html, container } for caller debugging
   */
  function renderPreview(containerOrSelector, input, options) {
    options = options || {};
    var container = getEl(containerOrSelector);

    if (!container) {
      return {
        model: normalizeGenerateResult(input),
        html: "",
        container: null
      };
    }

    var model = normalizeGenerateResult(input);
    var html = buildPreviewHtml(model, options);

    var mode = options.mode || "replace";
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

    return {
      model: model,
      html: html,
      container: container
    };
  }

  // ---- Public export ---------------------------------------------------------
  window.PPAAdminModules.generateView = {
    normalizeGenerateResult: normalizeGenerateResult,
    buildPreviewHtml: buildPreviewHtml,
    renderPreview: renderPreview,

    // low-level helpers (kept for future wiring)
    _escapeHtml: escapeHtml,
    _unwrapResultShape: unwrapResultShape
  };

})(window, document);
