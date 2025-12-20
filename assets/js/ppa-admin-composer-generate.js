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
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  if (window.PPAAdminModules.composerGenerate) {
    return;
  }

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
      return payloads.buildGeneratePayload(input);
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};
    return {
      subject: toStr(input.subject || ""),
      brief: toStr(input.brief || ""),
      content: toStr(input.content || "")
    };
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

  // Export
  window.PPAAdminModules.composerGenerate = {
    generate: generate,

    // exposed internals for debugging/testing later
    _unwrapWpAjax: unwrapWpAjax,
    _pickDjangoResultShape: pickDjangoResultShape,
    _buildGeneratePayload: buildGeneratePayload,
    _normalizeResultModel: normalizeResultModel
  };

})(window, document);
