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
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  if (window.PPAAdminModules.composerStore) {
    return;
  }

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
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

  // ---- Payload building ------------------------------------------------------
  function buildStorePayload(input) {
    // If payloads module exists, use it; otherwise pass through as plain object.
    var payloads = window.PPAAdminModules.payloads;

    if (payloads && typeof payloads.buildStorePayload === "function") {
      return payloads.buildStorePayload(input);
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};
    // Common keys that might be used by the WP controller:
    // post_id, status, title, content, excerpt, slug, meta
    return input;
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

  // Export
  window.PPAAdminModules.composerStore = {
    store: store,

    // exposed internals for debugging/testing later
    _unwrapWpAjax: unwrapWpAjax,
    _buildStorePayload: buildStorePayload
  };

})(window, document);
