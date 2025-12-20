/* global window, document */
/**
 * PostPress AI — Composer Store Module (ES5-safe)
 *
 * Purpose:
 * - Provide a reusable "store/save" action wrapper around:
 *   - window.PPAAdminModules.api.apiPost('ppa_store', ...)
 *   - optional payload building via window.PPAAdminModules.payloads
 *
 * IMPORTANT:
 * - NO side effects on load.
 * - Not wired into admin.js yet (one-file rule).
 *
 * WP side:
 * - Uses admin-ajax action `ppa_store` (WP proxy/controller already defines it).
 *
 * Output:
 * - Normalizes the transport + WP envelope (when present) into a stable object.
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.2: Merge export (no early return); preserve unknown keys in fallback payload build; strip common *El helper keys from outgoing payload to avoid leaking DOM refs/selectors. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-store.v2025-12-20.2"; // CHANGED:
  var composerStore = window.PPAAdminModules.composerStore || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
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

  // ---- Payload building ------------------------------------------------------
  function buildStorePayload(input) {
    // If payloads module exists, use it; otherwise pass through as plain object.
    var payloads = window.PPAAdminModules.payloads;

    if (payloads && typeof payloads.buildStorePayload === "function") {
      var built = payloads.buildStorePayload(input); // CHANGED:

      // CHANGED: Never leak convenience DOM keys into outgoing payload.
      // Payload modules preserve unknown keys during cutover; we must strip DOM refs/selectors.
      if (built && typeof built === "object") { // CHANGED:
        // Common patterns we may introduce later (safe to delete if absent).
        try { delete built.subjectEl; } catch (e1) {}  // CHANGED:
        try { delete built.briefEl; } catch (e2) {}    // CHANGED:
        try { delete built.contentEl; } catch (e3) {}  // CHANGED:

        try { delete built.postIdEl; } catch (e4) {}   // CHANGED:
        try { delete built.statusEl; } catch (e5) {}   // CHANGED:
        try { delete built.titleEl; } catch (e6) {}    // CHANGED:
        try { delete built.excerptEl; } catch (e7) {}  // CHANGED:
        try { delete built.slugEl; } catch (e8) {}     // CHANGED:
        try { delete built.metaEl; } catch (e9) {}     // CHANGED:
      } // CHANGED:

      return built; // CHANGED:
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};

    // CHANGED: Preserve unknown keys in fallback mode too (no stripping).
    var payload = shallowClone(input); // CHANGED:

    // CHANGED: Strip DOM helper keys if present.
    try { delete payload.subjectEl; } catch (e10) {} // CHANGED:
    try { delete payload.briefEl; } catch (e11) {}   // CHANGED:
    try { delete payload.contentEl; } catch (e12) {} // CHANGED:

    try { delete payload.postIdEl; } catch (e13) {}  // CHANGED:
    try { delete payload.statusEl; } catch (e14) {}  // CHANGED:
    try { delete payload.titleEl; } catch (e15) {}   // CHANGED:
    try { delete payload.excerptEl; } catch (e16) {} // CHANGED:
    try { delete payload.slugEl; } catch (e17) {}    // CHANGED:
    try { delete payload.metaEl; } catch (e18) {}    // CHANGED:

    // Common keys that might be used by the WP controller:
    // post_id, status, title, content, excerpt, slug, meta
    return payload; // CHANGED:
  }

  // ---- Public API ------------------------------------------------------------
  /**
   * store(input[, options])
   *
   * input:
   * - Prepared payload object OR an object compatible with buildStorePayload.
   *
   * options:
   * - storeDebug: boolean (default true) — stores window.PPA_LAST_STORE when complete
   * - apiOptions: passed through to apiPost (headers/timeout/ajaxUrl/nonce etc.)
   *
   * Returns:
   * - Promise/Deferred (same as api.apiPost) resolving to:
   *   {
   *     ok: boolean,              // accounts for HTTP + WP envelope success where present
   *     status: number,
   *     transport: { ...apiPostNormalized },   // raw transport object from apiPost
   *     wp: { hasEnvelope, success, data },    // parsed WP envelope if present
   *     data: any                 // wp.data or body
   *   }
   */
  function store(input, options) {
    options = options || {};

    var api = window.PPAAdminModules.api;
    if (!api || typeof api.apiPost !== "function") {
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

    var payload = buildStorePayload(input);

    // NOTE: If apiPost only accepts (action, data), the 3rd arg is safely ignored in JS.
    var p = api.apiPost("ppa_store", payload, options.apiOptions || {});

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

        var out = {
          ok: overallOk,
          status: transport.status,
          transport: transport,
          wp: wp,
          data: data
        };

        var storeDebug = (options.storeDebug !== undefined) ? !!options.storeDebug : true;
        if (storeDebug) {
          try {
            window.PPA_LAST_STORE = out;
          } catch (e) {
            // ignore
          }
        }

        return out;
      }, function (transportErr) {
        var wpErr = unwrapWpAjax(transportErr && transportErr.body);
        var dataErr = wpErr.hasEnvelope ? wpErr.data : (transportErr ? transportErr.body : null);

        var outErr = {
          ok: false,
          status: transportErr && transportErr.status ? transportErr.status : 0,
          transport: transportErr,
          wp: wpErr,
          data: dataErr
        };

        var storeDebug2 = (options.storeDebug !== undefined) ? !!options.storeDebug : true;
        if (storeDebug2) {
          try {
            window.PPA_LAST_STORE = outErr;
          } catch (e2) {
            // ignore
          }
        }

        // Preserve caller error flow
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
  composerStore.ver = MOD_VER; // CHANGED:
  composerStore.store = store;
  composerStore._unwrapWpAjax = unwrapWpAjax;
  composerStore._buildStorePayload = buildStorePayload;

  window.PPAAdminModules.composerStore = composerStore; // CHANGED:

})(window, document);
