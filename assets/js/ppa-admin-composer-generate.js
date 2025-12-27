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
 * 2025-12-22.1: Fix pickGenerateResult empty-shape detection so unexpected result shapes correctly fall back to diagnostic JSON render (prevents false-positive 'success' render with empty model). No contract changes. // CHANGED:
 * 2025-12-21.1: Add Generate click handler + pick/render/apply helpers (parity with stable admin.js behavior) for future cutover; no auto-wiring. // CHANGED:
 * 2025-12-20.3: Strip ANY *El helper keys from outgoing payload (generic) to prevent leaking DOM refs/selectors while payload builders preserve unknown keys. // CHANGED:
 * 2025-12-20.2: Merge export (no early return); preserve unknown keys in fallback payload build; strip *El helper keys from outgoing payload to avoid leaking DOM refs/selectors. // CHANGED:
 */

(function (window, document) {
  "use strict";

  window.PPAAdminModules = window.PPAAdminModules || {};

  var MOD_VER = "ppa-admin-composer-generate.v2025-12-22.1"; // CHANGED:
  var composerGenerate = window.PPAAdminModules.composerGenerate || {};

  function hasOwn(obj, key) { return Object.prototype.hasOwnProperty.call(obj, key); }
  function toStr(val) { return (val === undefined || val === null) ? "" : String(val); }

  function isNonEmptyObject(obj) {
    if (!obj || typeof obj !== "object") return false;
    for (var k in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, k)) return true;
    }
    return false;
  }

  function shallowClone(obj) {
    var out = {};
    if (!obj || typeof obj !== "object") return out;
    for (var k in obj) if (Object.prototype.hasOwnProperty.call(obj, k)) out[k] = obj[k];
    return out;
  }

  function stripElKeys(obj) {
    if (!obj || typeof obj !== "object") return obj;
    for (var k in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, k) && k.slice(-2) === "El") {
        try { delete obj[k]; } catch (e) {}
      }
    }
    return obj;
  }

  function unwrapWpAjax(body) {
    if (!body || typeof body !== "object") return { hasEnvelope:false, success:null, data:body };
    if (hasOwn(body, "success") && hasOwn(body, "data")) {
      return { hasEnvelope:true, success:body.success===true, data:body.data };
    }
    return { hasEnvelope:false, success:null, data:body };
  }

  function pickDjangoResultShape(unwrapped) {
    if (!unwrapped || typeof unwrapped !== "object") return unwrapped;
    if (unwrapped.result && typeof unwrapped.result === "object") return unwrapped.result;
    return unwrapped;
  }

  function buildGeneratePayload(input) {
    var payloads = window.PPAAdminModules.payloads;
    if (payloads && typeof payloads.buildGeneratePayload === "function") {
      var built = payloads.buildGeneratePayload(input);
      return stripElKeys(built);
    }
    var payload = shallowClone(input || {});
    payload.subject = toStr(input.subject);
    payload.brief   = toStr(input.brief);
    payload.content = toStr(input.content);
    return stripElKeys(payload);
  }

  function normalizeResultModel(any) {
    var gv = window.PPAAdminModules.generateView;
    if (gv && typeof gv.normalizeGenerateResult === "function") {
      return gv.normalizeGenerateResult(any);
    }
    return any;
  }

  function pickGenerateResult(body) {
    if (!body || typeof body !== "object") return null;
    var src = body.data || body.result || body;
    var hasMeta = isNonEmptyObject(src.meta);
    if (!src.title && !src.body_markdown && (!src.outline || !src.outline.length) && !hasMeta) {
      return null;
    }
    return {
      title: src.title || "",
      outline: src.outline || [],
      body_markdown: src.body_markdown || "",
      meta: src.meta || {}
    };
  }

  function generate(input, options) {
    options = options || {};
    var api = window.PPAAdminModules.api;
    if (!api || typeof api.apiPost !== "function") {
      return window.Promise
        ? Promise.resolve({ ok:false, status:0, error:"api_module_missing" })
        : { ok:false, status:0, error:"api_module_missing" };
    }

    var payload = buildGeneratePayload(input);
    var p = api.apiPost("ppa_generate", payload, options.apiOptions || {});

    if (!p || typeof p.then !== "function") {
      return window.Promise
        ? Promise.resolve({ ok:false, status:0, error:"no_promise_support" })
        : { ok:false, status:0, error:"no_promise_support" };
    }

    return p.then(function (transport) {
      var wp = unwrapWpAjax(transport.body);
      var ok = transport.ok && (!wp.hasEnvelope || wp.success === true);
      var data = wp.hasEnvelope ? wp.data : transport.body;
      var djangoResult = pickDjangoResultShape(data);
      var model = normalizeResultModel(djangoResult);

      var out = {
        ok: ok,
        status: transport.status,
        transport: transport,
        wp: wp,
        data: data,
        djangoResult: djangoResult,
        model: model
      };

      if (options.storeDebug !== false) {
        try { window.PPA_LAST_GENERATE = out; } catch (e) {}
      }
      return out;
    });
  }

  composerGenerate.ver = MOD_VER;
  composerGenerate.generate = generate;
  composerGenerate.pickGenerateResult = pickGenerateResult;

  window.PPAAdminModules.composerGenerate = composerGenerate;

})(window, document);
