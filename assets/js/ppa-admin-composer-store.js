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
 * 2025-12-20.3: Merge export (no early return); strip ANY *El helper keys from outgoing payload to prevent leaking DOM refs/selectors while payload builders preserve unknown keys. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-store.v2025-12-20.3"; // CHANGED:
  var composerStore = window.PPAAdminModules.composerStore || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function isObject(val) {
    return !!val && (typeof val === "object");
  }

  // CHANGED: shallow clone helper (avoid mutating caller input).
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

  // CHANGED: Strip any helper keys that end in "El" (postEl, titleEl, contentEl, etc.)
  // Prevent DOM nodes/selectors from being sent to WP/Django while we preserve unknown keys.
  function stripElKeys(obj) { // CHANGED:
    if (!obj || typeof obj !== "object") return obj; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        if (k && k.length >= 2 && k.slice(-2) === "El") { // CHANGED:
          try { delete obj[k]; } catch (e) {} // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return obj; // CHANGED:
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
      // CHANGED: Never leak DOM helper keys into outgoing payload.
      stripElKeys(built); // CHANGED:
      return built; // CHANGED:
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};

    // CHANGED: Clone to avoid mutating caller, and strip any *El helper keys.
    var payload = shallowClone(input); // CHANGED:
    stripElKeys(payload); // CHANGED:
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
  composerStore.store = store; // CHANGED:

  // exposed internals for debugging/testing later
  composerStore._unwrapWpAjax = unwrapWpAjax; // CHANGED:
  composerStore._buildStorePayload = buildStorePayload; // CHANGED:

  window.PPAAdminModules.composerStore = composerStore; // CHANGED:

})(window, document);
