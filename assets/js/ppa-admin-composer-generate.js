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
 * 2025-12-20.2: Merge export (no early return); preserve unknown keys in fallback payload build; strip *El helper keys from outgoing payload to avoid leaking DOM refs/selectors. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-generate.v2025-12-20.2"; // CHANGED:
  var composerGenerate = window.PPAAdminModules.composerGenerate || {}; // CHANGED:

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

  // CHANGED: shallow clone helper to preserve unknown keys (no stripping).
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
      var built = payloads.buildGeneratePayload(input); // CHANGED:

      // CHANGED: Never leak convenience DOM keys into outgoing payload.
      // Even if payloads preserves unknown keys, we must not send selector/node refs to WP/Django.
      if (built && typeof built === "object") { // CHANGED:
        try { delete built.subjectEl; } catch (e1) {} // CHANGED:
        try { delete built.briefEl; } catch (e2) {}   // CHANGED:
        try { delete built.contentEl; } catch (e3) {} // CHANGED:
      } // CHANGED:

      return built; // CHANGED:
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};

    // CHANGED: Preserve unknown keys in fallback mode too (no stripping).
    var payload = shallowClone(input); // CHANGED:

    payload.subject = toStr(input.subject || ""); // CHANGED:
    payload.brief   = toStr(input.brief || "");   // CHANGED:
    payload.content = toStr(input.content || ""); // CHANGED:

    // CHANGED: Strip DOM helper keys if present.
    try { delete payload.subjectEl; } catch (e4) {} // CHANGED:
    try { delete payload.briefEl; } catch (e5) {}   // CHANGED:
    try { delete payload.contentEl; } catch (e6) {} // CHANGED:

    return payload; // CHANGED:
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
    // NOTE: If apiPost only accepts (action, data), the 3rd arg is safely ignored in JS.
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
  composerGenerate.ver = MOD_VER; // CHANGED:
  composerGenerate.generate = generate;
  composerGenerate._unwrapWpAjax = unwrapWpAjax;
  composerGenerate._pickDjangoResultShape = pickDjangoResultShape;
  composerGenerate._buildGeneratePayload = buildGeneratePayload;
  composerGenerate._normalizeResultModel = normalizeResultModel;

  window.PPAAdminModules.composerGenerate = composerGenerate; // CHANGED:

})(window, document);
