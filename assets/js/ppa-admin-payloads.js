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
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.2: Preserve unknown keys in buildGeneratePayload/buildStorePayload (no stripping); merge exports (no early-return) to avoid clobber issues during modular cutover. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if the namespace already exists.
  // During modular cutover, late scripts can pre-create objects; we merge instead of bailing.
  var MOD_VER = "ppa-admin-payloads.v2025-12-20.2"; // CHANGED:
  var payloads = window.PPAAdminModules.payloads || {}; // CHANGED:

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

  // CHANGED: shallow clone helper to preserve all keys (prevents stripping during cutover).
  function shallowClone(obj) { // CHANGED:
    var out = {}; // CHANGED:
    if (!obj || typeof obj !== "object") return out; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        out[k] = obj[k]; // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return out; // CHANGED:
  } // CHANGED:

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
   *
   * CHANGED:
   * - Preserve ALL keys from input (no stripping) to match admin.js cutover needs.
   * - Normalize canonical fields (subject/brief/content) + known optional scalars/arrays.
   */
  function buildGeneratePayload(input) { // CHANGED:
    input = input || {};

    // CHANGED: Start from a shallow clone to preserve unknown keys.
    var payload = shallowClone(input); // CHANGED:

    // Accept either:
    // - { subject, brief, content }
    // - or { subjectEl, briefEl, contentEl } (DOM selectors or nodes) for convenience
    var subject = (input.subject !== undefined) ? input.subject : readValue(input.subjectEl);
    var brief   = (input.brief !== undefined) ? input.brief : readValue(input.briefEl);
    var content = (input.content !== undefined) ? input.content : readValue(input.contentEl);

    // Canonical fields (always present)
    payload.subject = safeText(subject);
    payload.brief   = safeMultiline(brief);
    payload.content = safeMultiline(content);

    // Optional scalars (normalize if present)
    if (payload.tone !== undefined) payload.tone = safeText(payload.tone);           // CHANGED:
    if (payload.audience !== undefined) payload.audience = safeText(payload.audience); // CHANGED:
    if (payload.length !== undefined) payload.length = safeText(payload.length);     // CHANGED:
    if (payload.category !== undefined) payload.category = safeText(payload.category); // CHANGED:

    // Optional tags/keywords as array (kept as plain strings)
    if (payload.keywords !== undefined) payload.keywords = filterNonEmpty(toArray(payload.keywords)); // CHANGED:
    if (payload.tags !== undefined) payload.tags = filterNonEmpty(toArray(payload.tags));             // CHANGED:

    return payload; // CHANGED:
  }

  /**
   * buildStorePayload(input)
   *
   * Intended for the future "Save to Draft" / store action.
   * Keeps keys generic so wiring can match the current WP controller expectations later.
   *
   * CHANGED:
   * - Preserve ALL keys from input (no stripping) and normalize known fields.
   */
  function buildStorePayload(input) { // CHANGED:
    input = input || {};

    // CHANGED: Preserve unknown keys by starting with a clone.
    var payload = shallowClone(input); // CHANGED:

    if (payload.post_id !== undefined) payload.post_id = parseInt(payload.post_id, 10) || 0; // CHANGED:
    if (payload.status !== undefined) payload.status = safeText(payload.status);             // CHANGED:

    if (payload.title !== undefined) payload.title = safeText(payload.title);                // CHANGED:
    if (payload.content !== undefined) payload.content = safeMultiline(payload.content);     // CHANGED:
    if (payload.excerpt !== undefined) payload.excerpt = safeMultiline(payload.excerpt);     // CHANGED:
    if (payload.slug !== undefined) payload.slug = safeText(payload.slug);                   // CHANGED:

    // Optional Yoast-ish meta (kept nested)
    if (payload.meta && typeof payload.meta === "object") {                                  // CHANGED:
      payload.meta = {                                                                       // CHANGED:
        focus_keyphrase: safeText(payload.meta.focus_keyphrase),
        meta_description: safeText(payload.meta.meta_description),
        slug: safeText(payload.meta.slug)
      };
    }

    return payload; // CHANGED:
  }

  // ---- Public export (merge) -------------------------------------------------
  payloads.ver = MOD_VER; // CHANGED:

  // text helpers
  payloads.safeText = safeText;
  payloads.safeMultiline = safeMultiline;
  payloads.normalizeNewlines = normalizeNewlines;

  // DOM helper
  payloads.readValue = readValue;

  // payload builders
  payloads.buildGeneratePayload = buildGeneratePayload; // CHANGED:
  payloads.buildStorePayload = buildStorePayload;       // CHANGED:

  // Re-attach merged module
  window.PPAAdminModules.payloads = payloads; // CHANGED:

})(window, document);
