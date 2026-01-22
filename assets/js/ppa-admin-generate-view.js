/*!
 * PostPress AI — Generate View Renderer
 * File: assets/js/ppa-admin-generate-view.js
 *
 * Renders the "Generate Preview" response into:
 * - Draft (HTML)
 * - SEO meta
 * - Optional Outline (toggle)
 *
 * ========= CHANGE LOG =========
 * 2026-01-21: FIX: Outline items normalize into individual lines and link to matching headings  // CHANGED:
 *            inside the Draft section (adds stable heading IDs during markdown render).        // CHANGED:
 * 2025-12-22.1: Safety hardening: only accept ELEMENT containers (never the Document); guard DOM mutations with try/catch so bad callers don't break editor; auto-hide outline container when empty.
 */

(function () {
  "use strict";

  var MOD_VER = "ppa-admin-generate-view.v2026-01-21.1"; // CHANGED:

  function isArray(v) { return Object.prototype.toString.call(v) === "[object Array]"; }
  function safeText(v) { return (v === null || v === undefined) ? "" : String(v); }
  function escapeHtml(s) {
    s = safeText(s);
    return s
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/\'/g, "&#39;");
  }

  /**
   * Normalize response shapes.
   * We accept:
   * - { ok: true, result: {...}, meta: {...} }
   * - { success: true, data: { ok:true, ... } } (WP ajax wrapper)
   * - { data: {...} } variants
   */
  function unwrapResultShape(input) {
    if (!input || typeof input !== "object") return {};
    // WP ajax wrapper: {success:true, data:{...}}
    if (input.data && typeof input.data === "object") return input.data;
    return input;
  }

  // Split a raw outline blob into individual lines.                                      // CHANGED:
  function splitOutlineLines(raw) {                                                       // CHANGED:
    var s = safeText(raw);                                                                // CHANGED:
    if (!s) return [];                                                                    // CHANGED:
    // Normalize newlines                                                                 // CHANGED:
    s = s.replace(/\r\n/g, "\n").replace(/\r/g, "\n");                                    // CHANGED:
    // If it's a markdown-ish bullet list, split by newline.                              // CHANGED:
    var lines = s.split("\n");                                                           // CHANGED:
    var out = [];                                                                         // CHANGED:
    for (var i = 0; i < lines.length; i++) {                                              // CHANGED:
      var line = safeText(lines[i]).trim();                                               // CHANGED:
      if (!line) continue;                                                                // CHANGED:
      // Strip common list prefixes: "-", "*", "+", "1.", "1)" etc                         // CHANGED:
      line = line.replace(/^(\*|\-|\+)\s+/, "");                                          // CHANGED:
      line = line.replace(/^\d+\s*[\.\)]\s+/, "");                                        // CHANGED:
      line = line.trim();                                                                 // CHANGED:
      if (line) out.push(line);                                                           // CHANGED:
    }                                                                                     // CHANGED:
    return out;                                                                           // CHANGED:
  }                                                                                       // CHANGED:

  function normalizeGenerateResult(input) {
    var r = unwrapResultShape(input);
    var meta = (r && r.meta && typeof r.meta === "object") ? r.meta : {};
    var outline = [];

    // Outline may arrive as array OR as a single string blob.                               // CHANGED:
    if (r && isArray(r.outline)) outline = r.outline;                                       // CHANGED:
    else if (r && r.outline) outline = [r.outline];                                         // CHANGED:

    // Expand any multi-line items into individual lines.                                    // CHANGED:
    var cleanOutline = [];                                                                  // CHANGED:
    for (var i = 0; i < outline.length; i++) {                                              // CHANGED:
      var it = outline[i];                                                                  // CHANGED:
      if (it === null || it === undefined) continue;                                        // CHANGED:
      if (typeof it === "string") {                                                         // CHANGED:
        var pieces = splitOutlineLines(it);                                                 // CHANGED:
        for (var p = 0; p < pieces.length; p++) cleanOutline.push(pieces[p]);               // CHANGED:
      } else {                                                                              // CHANGED:
        cleanOutline.push(safeText(it));                                                    // CHANGED:
      }                                                                                    // CHANGED:
    }                                                                                       // CHANGED:

    return {
      title: safeText(r.title || (r.result && r.result.title) || ""),
      excerpt: safeText(r.excerpt || (r.result && r.result.excerpt) || ""),
      slug: safeText(r.slug || (r.result && r.result.slug) || ""),
      seo: (r.seo && typeof r.seo === "object") ? r.seo : ((r.result && r.result.seo) ? r.result.seo : {}),
      outline: cleanOutline,                                                                 // CHANGED:
      html: safeText(r.html || (r.result && r.result.html) || ""),
      content: safeText(r.content || (r.result && r.result.content) || ""),
      meta: meta,
      raw: r
    };
  }

  function toHtmlFromText(txt) {
    txt = safeText(txt);
    if (!txt) return "";
    // very safe: split paragraphs
    var parts = txt.split(/\n{2,}/);
    var out = "";
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i].trim();
      if (!p) continue;
      out += "<p>" + escapeHtml(p).replace(/\n/g, "<br>") + "</p>";
    }
    return out;
  }

  /**
   * Minimal markdown-ish to HTML:
   * - Headings (#, ##, ###)
   * - Lists (-, *)
   * - Paragraphs
   */
  function markdownToHtml(m) {
    var txt = safeText(m);
    if (!txt) return "";

    var lines = txt.replace(/\r\n/g, "\n").replace(/\r/g, "\n").split("\n");
    var htmlParts = [];
    var inList = false;
    var para = [];

    function flushParagraph() {
      if (!para.length) return;
      htmlParts.push("<p>" + escapeHtml(para.join(" ")).replace(/\n/g, "<br>") + "</p>");
      para = [];
    }
    function flushList() {
      if (!inList) return;
      htmlParts.push("</ul>");
      inList = false;
    }

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var t = line.trim();

      // heading
      var m1 = t.match(/^(#{1,6})\s+(.*)$/);
      if (m1) {
        flushParagraph(); flushList();
        var level = m1[1].length;
        var text = m1[2].trim();
        htmlParts.push("<h" + level + ">" + escapeHtml(text) + "</h" + level + ">");
        continue;
      }

      // list item
      var m2 = t.match(/^(\-|\*)\s+(.*)$/);
      if (m2) {
        flushParagraph();
        if (!inList) { htmlParts.push("<ul>"); inList = true; }
        htmlParts.push("<li>" + escapeHtml(m2[2].trim()) + "</li>");
        continue;
      }

      // blank
      if (!t) {
        flushParagraph(); flushList();
        continue;
      }

      // paragraph
      flushList();
      para.push(t);
    }

    flushParagraph(); flushList();
    return htmlParts.length ? htmlParts.join("") : toHtmlFromText(txt);
  }

  // Create a stable anchor slug from a heading/outline line.                                     // CHANGED:
  function slugify(text) {                                                                        // CHANGED:
    var s = safeText(text).toLowerCase();                                                          // CHANGED:
    s = s.replace(/&/g, " and ");                                                                  // CHANGED:
    s = s.replace(/['"]/g, "");                                                                    // CHANGED:
    s = s.replace(/[^a-z0-9\\s\\-]/g, "");                                                         // CHANGED:
    s = s.replace(/\\s+/g, "-");                                                                   // CHANGED:
    s = s.replace(/\\-+/g, "-");                                                                   // CHANGED:
    s = s.replace(/^\\-+|\\-+$/g, "");                                                             // CHANGED:
    if (!s) s = "section";                                                                         // CHANGED:
    // Keep it reasonable (helps avoid insane ids)                                                  // CHANGED:
    if (s.length > 80) s = s.slice(0, 80).replace(/\\-+$/g, "");                                   // CHANGED:
    return s;                                                                                      // CHANGED:
  }                                                                                                // CHANGED:

  // Convert markdown to HTML BUT add id attributes for headings; also collect heading map.        // CHANGED:
  function markdownToHtmlAnchored(m) {                                                             // CHANGED:
    var txt = safeText(m);                                                                         // CHANGED:
    if (!txt) return { html: "", headings: [] };                                                    // CHANGED:

    var lines = txt.replace(/\\r\\n/g, "\\n").replace(/\\r/g, "\\n").split("\\n");                  // CHANGED:
    var htmlParts = [];                                                                            // CHANGED:
    var headings = [];                                                                             // CHANGED:
    var usedIds = {};                                                                              // CHANGED:
    var inList = false;                                                                            // CHANGED:
    var para = [];                                                                                 // CHANGED:

    function uniqueId(base) {                                                                      // CHANGED:
      var id = base;                                                                               // CHANGED:
      var n = 2;                                                                                   // CHANGED:
      while (usedIds[id]) {                                                                        // CHANGED:
        id = base + "-" + n;                                                                       // CHANGED:
        n++;                                                                                       // CHANGED:
      }                                                                                            // CHANGED:
      usedIds[id] = true;                                                                          // CHANGED:
      return id;                                                                                   // CHANGED:
    }                                                                                              // CHANGED:

    function flushParagraph() {                                                                    // CHANGED:
      if (!para.length) return;                                                                    // CHANGED:
      htmlParts.push("<p>" + escapeHtml(para.join(" ")).replace(/\\n/g, "<br>") + "</p>");          // CHANGED:
      para = [];                                                                                   // CHANGED:
    }                                                                                              // CHANGED:
    function flushList() {                                                                         // CHANGED:
      if (!inList) return;                                                                         // CHANGED:
      htmlParts.push("</ul>");                                                                     // CHANGED:
      inList = false;                                                                              // CHANGED:
    }                                                                                              // CHANGED:

    for (var i = 0; i < lines.length; i++) {                                                       // CHANGED:
      var line = lines[i];                                                                         // CHANGED:
      var t = safeText(line).trim();                                                               // CHANGED:

      // heading
      var m1 = t.match(/^(#{1,6})\\s+(.*)$/);                                                      // CHANGED:
      if (m1) {                                                                                    // CHANGED:
        flushParagraph(); flushList();                                                             // CHANGED:
        var level = m1[1].length;                                                                  // CHANGED:
        var text = m1[2].trim();                                                                   // CHANGED:
        var idBase = slugify(text);                                                                // CHANGED:
        var id = uniqueId(idBase);                                                                 // CHANGED:
        headings.push({ text: text, id: id, level: level });                                       // CHANGED:
        htmlParts.push("<h" + level + " id=\\"" + escapeHtml(id) + "\\">" + escapeHtml(text) + "</h" + level + ">"); // CHANGED:
        continue;                                                                                  // CHANGED:
      }                                                                                            // CHANGED:

      // list item
      var m2 = t.match(/^(\\-|\\*)\\s+(.*)$/);                                                     // CHANGED:
      if (m2) {                                                                                    // CHANGED:
        flushParagraph();                                                                          // CHANGED:
        if (!inList) { htmlParts.push("<ul>"); inList = true; }                                    // CHANGED:
        htmlParts.push("<li>" + escapeHtml(m2[2].trim()) + "</li>");                               // CHANGED:
        continue;                                                                                  // CHANGED:
      }                                                                                            // CHANGED:

      // blank
      if (!t) {                                                                                    // CHANGED:
        flushParagraph(); flushList();                                                             // CHANGED:
        continue;                                                                                  // CHANGED:
      }                                                                                            // CHANGED:

      // paragraph
      flushList();                                                                                 // CHANGED:
      para.push(t);                                                                                // CHANGED:
    }                                                                                              // CHANGED:

    flushParagraph(); flushList();                                                                 // CHANGED:
    return { html: htmlParts.length ? htmlParts.join("") : toHtmlFromText(txt), headings: headings }; // CHANGED:
  }                                                                                                // CHANGED:

  // Find the best heading id for an outline item.                                                  // CHANGED:
  function findHeadingIdForOutlineItem(itemText, headings) {                                        // CHANGED:
    var t = safeText(itemText).trim();                                                              // CHANGED:
    if (!t) return "";                                                                              // CHANGED:
    headings = headings || [];                                                                      // CHANGED:

    // 1) Exact text match (case-insensitive)                                                       // CHANGED:
    var low = t.toLowerCase();                                                                      // CHANGED:
    for (var i = 0; i < headings.length; i++) {                                                     // CHANGED:
      if (safeText(headings[i].text).trim().toLowerCase() === low) return headings[i].id;           // CHANGED:
    }                                                                                               // CHANGED:

    // 2) Slug match                                                                                // CHANGED:
    var s = slugify(t);                                                                             // CHANGED:
    for (var j = 0; j < headings.length; j++) {                                                     // CHANGED:
      if (slugify(headings[j].text) === s) return headings[j].id;                                   // CHANGED:
    }                                                                                               // CHANGED:

    // 3) Fallback: just use the outline slug (may not exist, but harmless)                         // CHANGED:
    return s;                                                                                       // CHANGED:
  }                                                                                                 // CHANGED:

  // Build outline list with links that jump to headings in the Draft section.                       // CHANGED:
  function buildOutlineHtml(outlineArr, headings) {                                                  // CHANGED:
    if (!outlineArr || !outlineArr.length) return "";                                                // CHANGED:
    var html = "<ol>";                                                                               // CHANGED:
    for (var i = 0; i < outlineArr.length; i++) {                                                     // CHANGED:
      var label = safeText(outlineArr[i]).trim();                                                     // CHANGED:
      if (!label) continue;                                                                          // CHANGED:
      var hid = findHeadingIdForOutlineItem(label, headings);                                         // CHANGED:
      html += "<li><a class=\\"ppa-outline-link\\" href=\\"#" + escapeHtml(hid) + "\\">" + escapeHtml(label) + "</a></li>"; // CHANGED:
    }                                                                                                 // CHANGED:
    html += "</ol>";                                                                                  // CHANGED:
    return html;                                                                                      // CHANGED:
  }                                                                                                   // CHANGED:

  function buildMetaHtml(seo) {
    if (!seo || typeof seo !== "object") return "";
    var keys = ["focus_keyphrase", "seo_title", "meta_description"];
    var labels = {
      focus_keyphrase: "Focus keyphrase",
      seo_title: "SEO title",
      meta_description: "Meta description"
    };
    var html = "<div class=\"ppa-seo-meta\"><h3>SEO</h3><dl>";
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      var v = seo[k];
      if (!v) continue;
      html += "<dt>" + escapeHtml(labels[k] || k) + "</dt><dd>" + escapeHtml(v) + "</dd>";
    }
    html += "</dl></div>";
    return html;
  }

  function buildPreviewHtml(result) {
    var r = normalizeGenerateResult(result);

    // Choose draft source:
    var draftSource = "";
    if (r.html) draftSource = r.html;
    else if (r.content) draftSource = r.content;
    else if (r.raw && r.raw.result && (r.raw.result.html || r.raw.result.content)) {
      draftSource = r.raw.result.html || r.raw.result.content;
    }

    // Build Draft HTML with stable heading IDs.                                                    // CHANGED:
    var anchored = { html: "", headings: [] };                                                      // CHANGED:
    var draftHtml = "";                                                                             // CHANGED:
    if (draftSource && /<\\w+[^>]*>/.test(draftSource)) {                                           // CHANGED:
      // Already HTML; don't rewrite.                                                                // CHANGED:
      draftHtml = draftSource;                                                                      // CHANGED:
    } else {                                                                                        // CHANGED:
      anchored = markdownToHtmlAnchored(draftSource);                                                // CHANGED:
      draftHtml = anchored.html;                                                                     // CHANGED:
    }                                                                                               // CHANGED:

    var outlineHtml = buildOutlineHtml(r.outline, anchored.headings);                                // CHANGED:
    var metaHtml = buildMetaHtml(r.seo);

    return {
      draftHtml: draftHtml || "<p><em>No content returned.</em></p>",
      outlineHtml: outlineHtml,
      metaHtml: metaHtml,
      title: r.title,
      excerpt: r.excerpt,
      slug: r.slug,
      outlineCount: r.outline ? r.outline.length : 0
    };
  }

  /**
   * Render into DOM
   * container: ELEMENT (not document)
   *
   * Expected structure in composer.php:
   * - #ppa-preview-pane
   *   - #ppa-outline (optional)
   *   - #ppa-draft
   *   - #ppa-seo
   * - #ppa-show-outline checkbox exists
   */
  function render(containerEl, apiResponse) {
    // Safety: only element nodes
    if (!containerEl || containerEl.nodeType !== 1) {
      console.warn("PPA: generate-view render aborted (invalid container)", containerEl);
      return;
    }

    var dom = {};
    try {
      dom.previewPane = containerEl.querySelector("#ppa-preview-pane");
      if (!dom.previewPane) return;

      dom.outlineWrap = dom.previewPane.querySelector("#ppa-outline");
      dom.draftEl = dom.previewPane.querySelector("#ppa-draft");
      dom.seoEl = dom.previewPane.querySelector("#ppa-seo");

      // If the template doesn't include these, create them.
      if (!dom.outlineWrap) {
        dom.outlineWrap = document.createElement("div");
        dom.outlineWrap.id = "ppa-outline";
        dom.outlineWrap.className = "ppa-outline";
        dom.previewPane.appendChild(dom.outlineWrap);
      }
      if (!dom.draftEl) {
        dom.draftEl = document.createElement("div");
        dom.draftEl.id = "ppa-draft";
        dom.draftEl.className = "ppa-draft";
        dom.previewPane.appendChild(dom.draftEl);
      }
      if (!dom.seoEl) {
        dom.seoEl = document.createElement("div");
        dom.seoEl.id = "ppa-seo";
        dom.seoEl.className = "ppa-seo";
        dom.previewPane.appendChild(dom.seoEl);
      }
    } catch (e) {
      console.warn("PPA: generate-view query failed", e);
      return;
    }

    var built = buildPreviewHtml(apiResponse);

    // Outline
    try {
      if (built.outlineHtml) {
        dom.outlineWrap.innerHTML = "<h3>Outline</h3>" + built.outlineHtml;
        dom.outlineWrap.style.display = "";
      } else {
        dom.outlineWrap.innerHTML = "";
        dom.outlineWrap.style.display = "none";
      }
    } catch (e1) {
      console.warn("PPA: outline render failed", e1);
    }

    // Draft + SEO
    try { dom.draftEl.innerHTML = built.draftHtml; } catch (e2) {}
    try { dom.seoEl.innerHTML = built.metaHtml; } catch (e3) {}

    // Autofill advanced fields if present
    try {
      var titleEl = containerEl.querySelector("#ppa-title");
      var excerptEl = containerEl.querySelector("#ppa-excerpt");
      var slugEl = containerEl.querySelector("#ppa-slug");

      if (titleEl && !titleEl.value && built.title) titleEl.value = built.title;
      if (excerptEl && !excerptEl.value && built.excerpt) excerptEl.value = built.excerpt;
      if (slugEl && !slugEl.value && built.slug) slugEl.value = built.slug;
    } catch (e4) {}

    // Outline toggle logic (checkbox)
    try {
      var chk = containerEl.querySelector("#ppa-show-outline");
      if (chk) {
        // default: show if outline exists
        if (built.outlineHtml) {
          chk.disabled = false;
          if (chk.checked === false) {
            // leave user choice as-is; if never interacted, keep default checked for visibility
            chk.checked = true;
          }
          dom.outlineWrap.style.display = chk.checked ? "" : "none";
        } else {
          chk.checked = false;
          chk.disabled = true;
          dom.outlineWrap.style.display = "none";
        }

        // ensure change handler only binds once
        if (!chk._ppaBound) {
          chk.addEventListener("change", function () {
            try {
              dom.outlineWrap.style.display = chk.checked ? "" : "none";
            } catch (e) {}
          });
          chk._ppaBound = true;
        }
      }
    } catch (e5) {}
  }

  // expose module
  window.PPAAdmin = window.PPAAdmin || {};
  window.PPAAdmin.generateView = {
    render: render,
    normalizeGenerateResult: normalizeGenerateResult,
    buildPreviewHtml: buildPreviewHtml,
    _markdownToHtml: markdownToHtml,
    ver: MOD_VER
  };

  try { console.log("PPA: ppa-admin-generate-view.js loaded →", MOD_VER); } catch (e) {}
})();
