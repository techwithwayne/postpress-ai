/* global window, document */
/**
 * PostPress AI — Admin Payloads Module (ES5-safe)
 *
 * Purpose:
 * - Provide reusable payload builders + text normalization helpers.
 * - NO side effects. NO DOM assumptions required.
 * - Not wired into admin.js yet (one-file rule). Safe to deploy as-is.
 *
 * Notes:
 * - We keep these builders flexible, because wiring must mirror the exact admin.js field mapping later.
 * - This module only helps standardize + sanitize values (trim, normalize newlines, etc.).
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  if (window.PPAAdminModules.payloads) {
    return;
  }

  // ---- Small utils (ES5) -----------------------------------------------------
  function toStr(val) {
    return (val === undefined || val === null) ? "" : String(val);
  }

  function trim(val) {
    // ES5-safe trim
    return toStr(val).replace(/^\s+|\s+$/g, "");
  }

  function normalizeNewlines(val) {
    // Convert CRLF/CR to LF
    return toStr(val).replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  }

  function collapseSpaces(val) {
    // Collapse repeated spaces/tabs, keep newlines intact
    return toStr(val).replace(/[ \t]+/g, " ");
  }

  function safeText(val) {
    // Standard normalization used across payload fields
    // (trim + normalize newlines + collapse spaces)
    var s = normalizeNewlines(val);
    s = collapseSpaces(s);
    s = trim(s);
    return s;
  }

  function safeMultiline(val) {
    // Similar to safeText, but preserves multiple spaces within lines less aggressively.
    // Still normalizes CRLF/CR -> LF and trims edges.
    var s = normalizeNewlines(val);
    s = trim(s);
    return s;
  }

  function isArray(val) {
    return Object.prototype.toString.call(val) === "[object Array]";
  }

  function toArray(val) {
    if (val === undefined || val === null) return [];
    if (isArray(val)) return val;
    return [val];
  }

  function filterNonEmpty(arr) {
    var out = [];
    for (var i = 0; i < arr.length; i++) {
      var v = safeText(arr[i]);
      if (v) out.push(v);
    }
    return out;
  }

  // ---- Optional DOM helper (safe; no assumptions) ---------------------------
  function getEl(selectorOrEl) {
    if (!selectorOrEl) return null;

    // If a DOM node was passed in
    if (selectorOrEl.nodeType === 1 || selectorOrEl.nodeType === 9) {
      return selectorOrEl;
    }

    // If a selector string was passed in
    if (typeof selectorOrEl === "string") {
      try {
        return document.querySelector(selectorOrEl);
      } catch (e) {
        return null;
      }
    }

    return null;
  }

  function readValue(selectorOrEl) {
    var el = getEl(selectorOrEl);
    if (!el) return "";

    // Support inputs/textareas/selects; fallback to textContent
    if (typeof el.value === "string") return el.value;
    return toStr(el.textContent || "");
  }

  // ---- Payload builders ------------------------------------------------------
  /**
   * buildGeneratePayload(input)
   *
   * Minimal canonical fields (per Composer behavior):
   * - subject, brief, content
   *
   * Additional fields may be included by caller (tone, audience, etc.).
   * This module does NOT enforce required-ness; guards remain in notices/wiring.
   */
  function buildGeneratePayload(input) {
    input = input || {};

    // Accept either:
    // - { subject, brief, content }
    // - or { subjectEl, briefEl, contentEl } (DOM selectors or nodes) for convenience
    var subject = (input.subject !== undefined) ? input.subject : readValue(input.subjectEl);
    var brief   = (input.brief !== undefined) ? input.brief : readValue(input.briefEl);
    var content = (input.content !== undefined) ? input.content : readValue(input.contentEl);

    var payload = {
      subject: safeText(subject),
      brief: safeMultiline(brief),
      content: safeMultiline(content)
    };

    // Optional passthrough fields (only if provided)
    // We keep these generic and non-opinionated.
    if (input.tone !== undefined) payload.tone = safeText(input.tone);
    if (input.audience !== undefined) payload.audience = safeText(input.audience);
    if (input.length !== undefined) payload.length = safeText(input.length);
    if (input.category !== undefined) payload.category = safeText(input.category);

    // Optional tags/keywords as array (kept as plain strings)
    if (input.keywords !== undefined) {
      payload.keywords = filterNonEmpty(toArray(input.keywords));
    }
    if (input.tags !== undefined) {
      payload.tags = filterNonEmpty(toArray(input.tags));
    }

    return payload;
  }

  /**
   * buildStorePayload(input)
   *
   * Intended for the future "Save to Draft" / store action.
   * Keeps keys generic so wiring can match the current WP controller expectations later.
   */
  function buildStorePayload(input) {
    input = input || {};

    var payload = {};

    if (input.post_id !== undefined) payload.post_id = parseInt(input.post_id, 10) || 0;
    if (input.status !== undefined) payload.status = safeText(input.status);

    if (input.title !== undefined) payload.title = safeText(input.title);
    if (input.content !== undefined) payload.content = safeMultiline(input.content);
    if (input.excerpt !== undefined) payload.excerpt = safeMultiline(input.excerpt);
    if (input.slug !== undefined) payload.slug = safeText(input.slug);

    // Optional Yoast-ish meta (kept nested)
    if (input.meta && typeof input.meta === "object") {
      payload.meta = {
        focus_keyphrase: safeText(input.meta.focus_keyphrase),
        meta_description: safeText(input.meta.meta_description),
        slug: safeText(input.meta.slug)
      };
    }

    return payload;
  }

  // ---- Public export ---------------------------------------------------------
  window.PPAAdminModules.payloads = {
    // text helpers
    safeText: safeText,
    safeMultiline: safeMultiline,
    normalizeNewlines: normalizeNewlines,

    // DOM helper
    readValue: readValue,

    // payload builders
    buildGeneratePayload: buildGeneratePayload,
    buildStorePayload: buildStorePayload
  };

})(window, document);
