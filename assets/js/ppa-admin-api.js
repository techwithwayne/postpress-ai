/**
 * PostPress AI — Admin API Module
 * Path: assets/js/ppa-admin-api.js
 *
 * Purpose:
 * - Provide a modular, reusable admin-ajax POST helper (fetch wrapper)
 * - Keep behavior aligned with the current admin.js implementation
 * - Avoid touching admin.js until we have stable modules in place
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.3: Prefer PPAAdmin.core helpers (getAjaxUrl/getNonce) when present for single-source behavior; keep linked apiPost getter for strict equality + safe cutover; remain merge-safe. // CHANGED:
 * 2025-12-20.2: Do not overwrite PPAAdminModules.api; expose apiPost as a linked getter to window.PPAAdmin.apiPost for strict equality + safe cutover. // CHANGED:
 * 2025-12-20.1: Create Admin API module with apiPost(), nonce resolution, and JSON parsing helpers. // CHANGED:
 */

(function (win, doc) {
  'use strict';

  // Namespace for modular admin JS (avoid touching window.PPAAdmin until admin.js is refactored) // CHANGED:
  if (!win.PPAAdminModules) { win.PPAAdminModules = {}; }                                        // CHANGED:

  var MOD_VER = 'ppa-admin-api.v2025-12-20.3'; // CHANGED:

  // IMPORTANT: Do NOT overwrite the module object. Merge into existing to avoid late-script clobbering. // CHANGED:
  var api = win.PPAAdminModules.api || {}; // CHANGED:

  // --- Internal helpers ------------------------------------------------------

  function getCore() { // CHANGED:
    try { // CHANGED:
      if (win.PPAAdmin && win.PPAAdmin.core && typeof win.PPAAdmin.core === 'object') return win.PPAAdmin.core; // CHANGED:
    } catch (e) {} // CHANGED:
    return null; // CHANGED:
  } // CHANGED:

  function getAjaxUrl() { // CHANGED:
    // CHANGED: Prefer PPAAdmin.core.getAjaxUrl() when present (single source of truth).
    try { // CHANGED:
      var core = getCore(); // CHANGED:
      if (core && typeof core.getAjaxUrl === 'function') { // CHANGED:
        var u = core.getAjaxUrl(); // CHANGED:
        if (u) return u; // CHANGED:
      } // CHANGED:
    } catch (e0) {} // CHANGED:

    if (win.PPA && win.PPA.ajaxUrl) return win.PPA.ajaxUrl;                                       // CHANGED:
    if (win.PPA && win.PPA.ajax) return win.PPA.ajax;                                             // CHANGED:
    if (win.ppaAdmin && win.ppaAdmin.ajaxurl) return win.ppaAdmin.ajaxurl;                         // CHANGED:
    if (win.ajaxurl) return win.ajaxurl;                                                           // CHANGED:
    return '/wp-admin/admin-ajax.php';                                                             // CHANGED:
  } // CHANGED:

  // Prefer the admin-ajax nonce localized via wp_localize_script (ppaAdmin.nonce)                // CHANGED:
  function getNonce() { // CHANGED:
    // CHANGED: Prefer PPAAdmin.core.getNonce() when present (single source of truth).
    try { // CHANGED:
      var core = getCore(); // CHANGED:
      if (core && typeof core.getNonce === 'function') { // CHANGED:
        var n0 = core.getNonce(); // CHANGED:
        if (n0) return String(n0).trim(); // CHANGED:
      } // CHANGED:
    } catch (e00) {} // CHANGED:

    if (win.ppaAdmin && win.ppaAdmin.nonce) return String(win.ppaAdmin.nonce).trim();              // CHANGED:

    // Fallback: allow template-provided nonce via data attr (ONLY as fallback)                   // CHANGED:
    var root = doc.getElementById('ppa-composer');                                                 // CHANGED:
    if (root) {                                                                                    // CHANGED:
      var dn = root.getAttribute('data-ppa-nonce');                                                // CHANGED:
      if (dn) return String(dn).trim();                                                            // CHANGED:
    }                                                                                              // CHANGED:

    // Legacy fallbacks (rare)                                                                     // CHANGED:
    if (win.PPA && win.PPA.nonce) return String(win.PPA.nonce).trim();                             // CHANGED:
    var el = doc.getElementById('ppa-nonce');                                                      // CHANGED:
    if (el) return String(el.value || '').trim();                                                  // CHANGED:
    return '';                                                                                     // CHANGED:
  } // CHANGED:

  function jsonTryParse(text) { // CHANGED:
    try { return JSON.parse(text); }                                                                // CHANGED:
    catch (e) {                                                                                     // CHANGED:
      // Keep parity with admin.js: return a predictable object                                     // CHANGED:
      return { raw: String(text || '') };                                                           // CHANGED:
    }                                                                                               // CHANGED:
  } // CHANGED:

  // Determine view tag for proxy/Django diagnostics                                                // CHANGED:
  function getViewTag() { // CHANGED:
    var view = 'composer';                                                                          // CHANGED:
    try {                                                                                           // CHANGED:
      var root2 = doc.getElementById('ppa-composer');                                               // CHANGED:
      if (root2 && root2.getAttribute('data-ppa-view')) {                                          // CHANGED:
        view = String(root2.getAttribute('data-ppa-view') || 'composer');                           // CHANGED:
      }                                                                                             // CHANGED:
    } catch (e) {}                                                                                  // CHANGED:
    return view;                                                                                    // CHANGED:
  } // CHANGED:

  // Internal implementation (used when window.PPAAdmin.apiPost is not present yet).                // CHANGED:
  var apiPostImpl = function apiPostImpl(action, data, options) { // CHANGED:
    options = options || {};                                                                        // CHANGED:

    var url = options.ajaxUrl || getAjaxUrl();                                                      // CHANGED:
    var nonce = (typeof options.nonce === 'string' && options.nonce.trim()) ? options.nonce.trim() : getNonce(); // CHANGED:
    var viewTag = (typeof options.view === 'string' && options.view.trim()) ? options.view.trim() : getViewTag(); // CHANGED:

    var qs = url.indexOf('?') === -1 ? '?' : '&';                                                   // CHANGED:
    var endpoint = url + qs + 'action=' + encodeURIComponent(action);                               // CHANGED:

    var headers = { 'Content-Type': 'application/json' };                                           // CHANGED:
    if (nonce) {                                                                                    // CHANGED:
      headers['X-PPA-Nonce'] = nonce;                                                               // CHANGED:
      headers['X-WP-Nonce'] = nonce;                                                                // CHANGED:
    }                                                                                               // CHANGED:
    headers['X-Requested-With'] = 'XMLHttpRequest';                                                 // CHANGED:
    headers['X-PPA-View'] = viewTag;                                                                // CHANGED:

    // Allow callers to add/override headers (kept minimal; admin.js still canonical).              // CHANGED:
    if (options.headers && typeof options.headers === 'object') {                                   // CHANGED:
      for (var k in options.headers) {                                                              // CHANGED:
        if (Object.prototype.hasOwnProperty.call(options.headers, k)) {                             // CHANGED:
          headers[k] = options.headers[k];                                                          // CHANGED:
        }                                                                                           // CHANGED:
      }                                                                                             // CHANGED:
    }                                                                                               // CHANGED:

    return win.fetch(endpoint, {                                                                    // CHANGED:
      method: 'POST',                                                                               // CHANGED:
      headers: headers,                                                                             // CHANGED:
      body: JSON.stringify(data || {}),                                                             // CHANGED:
      credentials: 'same-origin'                                                                    // CHANGED:
    })                                                                                              // CHANGED:
    .then(function (res) {                                                                          // CHANGED:
      var ct = (res.headers.get('content-type') || '').toLowerCase();                               // CHANGED:
      return res.text().then(function (text) {                                                      // CHANGED:
        var body = jsonTryParse(text);                                                              // CHANGED:
        return {                                                                                    // CHANGED:
          ok: res.ok,                                                                               // CHANGED:
          status: res.status,                                                                       // CHANGED:
          body: body,                                                                               // CHANGED:
          raw: text,                                                                                // CHANGED:
          contentType: ct,                                                                          // CHANGED:
          headers: res.headers                                                                       // CHANGED:
        };                                                                                          // CHANGED:
      });                                                                                           // CHANGED:
    })                                                                                              // CHANGED:
    .catch(function (err) {                                                                         // CHANGED:
      return {                                                                                      // CHANGED:
        ok: false,                                                                                  // CHANGED:
        status: 0,                                                                                  // CHANGED:
        body: { error: String(err) },                                                               // CHANGED:
        raw: '',                                                                                    // CHANGED:
        contentType: '',                                                                            // CHANGED:
        headers: (win.Headers ? new win.Headers() : {})                                             // CHANGED:
      };                                                                                            // CHANGED:
    });                                                                                             // CHANGED:
  }; // CHANGED:

  // Resolve the authoritative apiPost reference.
  // If admin.js has already defined window.PPAAdmin.apiPost, we *use that exact function* so strict equality passes. // CHANGED:
  function resolveApiPostRef() { // CHANGED:
    try {                                                                                            // CHANGED:
      if (win.PPAAdmin && typeof win.PPAAdmin.apiPost === 'function') return win.PPAAdmin.apiPost;  // CHANGED:
    } catch (e) {}                                                                                   // CHANGED:
    return apiPostImpl;                                                                             // CHANGED:
  } // CHANGED:

  // Export module surface (merged into existing object; no behavior change intended).              // CHANGED:
  api.ver = MOD_VER;                                                                                // CHANGED:
  api.getAjaxUrl = getAjaxUrl;                                                                      // CHANGED:
  api.getNonce = getNonce;                                                                          // CHANGED:
  api.jsonTryParse = jsonTryParse;                                                                  // CHANGED:

  // Define apiPost as a linked getter so:
  // window.PPAAdminModules.api.apiPost === window.PPAAdmin.apiPost  (when PPAAdmin.apiPost exists) // CHANGED:
  // This allows us to remove admin.js bridge hacks later, safely.                                   // CHANGED:
  try {                                                                                              // CHANGED:
    Object.defineProperty(api, 'apiPost', {                                                          // CHANGED:
      configurable: true,                                                                            // CHANGED:
      enumerable: true,                                                                              // CHANGED:
      get: function () {                                                                             // CHANGED:
        return resolveApiPostRef();                                                                  // CHANGED:
      },                                                                                             // CHANGED:
      set: function (fn) {                                                                           // CHANGED:
        if (typeof fn === 'function') {                                                              // CHANGED:
          apiPostImpl = fn;                                                                          // CHANGED:
          // Keep admin namespace aligned if present (helps during transitional wiring).             // CHANGED:
          try {                                                                                      // CHANGED:
            if (win.PPAAdmin && typeof win.PPAAdmin === 'object') { win.PPAAdmin.apiPost = fn; }     // CHANGED:
          } catch (e2) {}                                                                            // CHANGED:
        }                                                                                            // CHANGED:
      }                                                                                              // CHANGED:
    });                                                                                              // CHANGED:
  } catch (e3) {                                                                                     // CHANGED:
    // Fallback: direct assignment (older environments). Equality may depend on load order.          // CHANGED:
    api.apiPost = resolveApiPostRef();                                                               // CHANGED:
  }                                                                                                  // CHANGED:

  // Re-attach merged module object to namespace (no clobber).                                       // CHANGED:
  win.PPAAdminModules.api = api;                                                                     // CHANGED:

  console.info('PPA: ppa-admin-api.js loaded →', MOD_VER); // CHANGED:
})(window, document);
