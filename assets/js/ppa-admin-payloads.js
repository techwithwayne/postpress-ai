/* global window, document */
/**
 * PostPress AI — Admin Payloads Module (ES5-safe)
 *
 * Purpose:
 * - Provide reusable payload builders + text normalization helpers.
 * - NO side effects. NO DOM assumptions required.
 * - Not wired into admin.js yet (one-file rule). Safe to deploy as-is.
 *
 * ========= CHANGE LOG =========
 * 2025-12-22.1: Harden normalization helpers (null/shape safety), ensure numeric coercion safety,
 *               and bump module version. NO contract or wiring changes. // CHANGED:
 * 2025-12-21.1: Add buildPreviewPayload + preserve nested meta keys while normalizing known meta fields.
 * 2025-12-20.2: Preserve unknown keys; merge exports (no early-return) during modular cutover.
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  var MOD_VER = "ppa-admin-payloads.v2025-12-22.1"; // CHANGED:
  var payloads = window.PPAAdminModules.payloads || {};

  // ---- Small utils (ES5) -----------------------------------------------------
  function toStr(val) {
    return (val === undefined || val === null) ? "" : String(val);
  }

  function trim(val) {
    return toStr(val).replace(/^\s+|\s+$/g, "");
  }

  function normalizeNewlines(val) {
    return toStr(val).replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  }

  function collapseSpaces(val) {
    return toStr(val).replace(/[ \t]+/g, " ");
  }

  function safeText(val) {
    var s = normalizeNewlines(val);
    s = collapseSpaces(s);
    return trim(s);
  }

  function safeMultiline(val) {
    var s = normalizeNewlines(val);
    return trim(s);
  }

  function isArray(val) {
    return Object.prototype.toString.call(val) === "[object Array]";
  }

  function toArray(val) {
    if (val === undefined || val === null) return [];
    return isArray(val) ? val : [val];
  }

  function filterNonEmpty(arr) {
    var out = [];
    for (var i = 0; i < arr.length; i++) {
      var v = safeText(arr[i]);
      if (v) out.push(v);
    }
    return out;
  }

  function shallowClone(obj) { // CHANGED:
    var out = {};
    if (!obj || typeof obj !== "object") return out;
    for (var k in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, k)) {
        out[k] = obj[k];
      }
    }
    return out;
  }

  function normalizeMetaPreserve(meta) { // CHANGED:
    if (!meta || typeof meta !== "object") return meta;
    var m = shallowClone(meta);
    if (m.focus_keyphrase !== undefined) m.focus_keyphrase = safeText(m.focus_keyphrase);
    if (m.meta_description !== undefined) m.meta_description = safeText(m.meta_description);
    if (m.slug !== undefined) m.slug = safeText(m.slug);
    return m;
  }

  // ---- Optional DOM helper ---------------------------------------------------
  function getEl(selectorOrEl) {
    if (!selectorOrEl) return null;
    if (selectorOrEl.nodeType === 1 || selectorOrEl.nodeType === 9) return selectorOrEl;
    if (typeof selectorOrEl === "string") {
      try { return document.querySelector(selectorOrEl); }
      catch (e) { return null; }
    }
    return null;
  }

  function readValue(selectorOrEl) {
    var el = getEl(selectorOrEl);
    if (!el) return "";
    if (typeof el.value === "string") return el.value;
    return toStr(el.textContent || "");
  }

  // ---- Payload builders ------------------------------------------------------
  function buildGeneratePayload(input) {
    input = input || {};
    var payload = shallowClone(input);

    var subject = (input.subject !== undefined) ? input.subject : readValue(input.subjectEl);
    var brief   = (input.brief !== undefined) ? input.brief : readValue(input.briefEl);
    var content = (input.content !== undefined) ? input.content : readValue(input.contentEl);

    payload.subject = safeText(subject);
    payload.brief   = safeMultiline(brief);
    payload.content = safeMultiline(content);

    if (payload.tone !== undefined) payload.tone = safeText(payload.tone);
    if (payload.audience !== undefined) payload.audience = safeText(payload.audience);
    if (payload.length !== undefined) payload.length = safeText(payload.length);
    if (payload.category !== undefined) payload.category = safeText(payload.category);

    if (payload.keywords !== undefined) payload.keywords = filterNonEmpty(toArray(payload.keywords));
    if (payload.tags !== undefined) payload.tags = filterNonEmpty(toArray(payload.tags));

    if (payload.meta && typeof payload.meta === "object") {
      payload.meta = normalizeMetaPreserve(payload.meta);
    }

    return payload;
  }

  function buildPreviewPayload(input) {
    input = input || {};
    var payload = shallowClone(input);

    var title   = (input.title !== undefined) ? input.title : readValue(input.titleEl);
    var outline = (input.outline !== undefined) ? input.outline : readValue(input.outlineEl);
    var body    = (input.body !== undefined) ? input.body : readValue(input.bodyEl);

    if (title !== undefined && title !== "") payload.title = safeText(title);
    if (outline !== undefined && outline !== "") payload.outline = safeMultiline(outline);
    if (body !== undefined && body !== "") payload.body = safeMultiline(body);

    if (payload.content !== undefined) payload.content = safeMultiline(payload.content);

    if (payload.meta && typeof payload.meta === "object") {
      payload.meta = normalizeMetaPreserve(payload.meta);
    }

    return payload;
  }

  function buildStorePayload(input) {
    input = input || {};
    var payload = shallowClone(input);

    if (payload.post_id !== undefined) payload.post_id = parseInt(payload.post_id, 10) || 0;
    if (payload.status !== undefined) payload.status = safeText(payload.status);

    if (payload.title !== undefined) payload.title = safeText(payload.title);
    if (payload.content !== undefined) payload.content = safeMultiline(payload.content);
    if (payload.excerpt !== undefined) payload.excerpt = safeMultiline(payload.excerpt);
    if (payload.slug !== undefined) payload.slug = safeText(payload.slug);

    if (payload.meta && typeof payload.meta === "object") {
      payload.meta = normalizeMetaPreserve(payload.meta);
    }

    return payload;
  }

  // ---- Public export (merge) -------------------------------------------------
  payloads.ver = MOD_VER;

  payloads.safeText = safeText;
  payloads.safeMultiline = safeMultiline;
  payloads.normalizeNewlines = normalizeNewlines;
  payloads.readValue = readValue;

  payloads.buildGeneratePayload = buildGeneratePayload;
  payloads.buildPreviewPayload = buildPreviewPayload;
  payloads.buildStorePayload = buildStorePayload;

  if (!payloads.buildPreview) payloads.buildPreview = buildPreviewPayload;
  if (!payloads.buildGenerate) payloads.buildGenerate = buildGeneratePayload;
  if (!payloads.buildStore) payloads.buildStore = buildStorePayload;

  window.PPAAdminModules.payloads = payloads;

})(window, document);
