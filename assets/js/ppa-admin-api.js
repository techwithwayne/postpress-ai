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
 * 2025-12-20.1: Create Admin API module with apiPost(), nonce resolution, and JSON parsing helpers. // CHANGED:
 */

(function (win, doc) {
  'use strict';

  // Namespace for modular admin JS (avoid touching window.PPAAdmin until admin.js is refactored) // CHANGED:
  if (!win.PPAAdminModules) { win.PPAAdminModules = {}; }                                        // CHANGED:

  var MOD_VER = 'ppa-admin-api.v2025-12-20.1'; // CHANGED:

  // --- Internal helpers ------------------------------------------------------

  function getAjaxUrl() { // CHANGED:
    if (win.PPA && win.PPA.ajaxUrl) return win.PPA.ajaxUrl;                                       // CHANGED:
    if (win.PPA && win.PPA.ajax) return win.PPA.ajax;                                             // CHANGED:
    if (win.ppaAdmin && win.ppaAdmin.ajaxurl) return win.ppaAdmin.ajaxurl;                         // CHANGED:
    if (win.ajaxurl) return win.ajaxurl;                                                           // CHANGED:
    return '/wp-admin/admin-ajax.php';                                                             // CHANGED:
  } // CHANGED:

  // Prefer the admin-ajax nonce localized via wp_localize_script (ppaAdmin.nonce)                // CHANGED:
  function getNonce() { // CHANGED:
    if (win.ppaAdmin && win.ppaAdmin.nonce) return String(win.ppaAdmin.nonce).trim();              // CHANGED:

    // Fallback: allow template-provided nonce via data attr (ONLY as fallback)                   // CHANGED:
    var root = doc.getElementById('ppa-composer');                                                 // CHANGED:
    if (root) {                                                                                    // CHANGED:
      var dn = root.getAttribute('data-ppa-nonce');                                                 // CHANGED:
      if (dn) return String(dn).trim();                                                             // CHANGED:
    }                                                                                               // CHANGED:

    // Legacy fallbacks (rare)                                                                     // CHANGED:
    if (win.PPA && win.PPA.nonce) return String(win.PPA.nonce).trim();                              // CHANGED:
    var el = doc.getElementById('ppa-nonce');                                                       // CHANGED:
    if (el) return String(el.value || '').trim();                                                   // CHANGED:
    return '';                                                                                      // CHANGED:
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
      var root = doc.getElementById('ppa-composer');                                                // CHANGED:
      if (root && root.getAttribute('data-ppa-view')) {                                             // CHANGED:
        view = String(root.getAttribute('data-ppa-view') || 'composer');                            // CHANGED:
      }                                                                                             // CHANGED:
    } catch (e) {}                                                                                  // CHANGED:
    return view;                                                                                    // CHANGED:
  } // CHANGED:

  /**
   * apiPost(action, data)
   * - Sends JSON payload to admin-ajax endpoint: ?action=<action>
   * - Mirrors admin.js headers (X-PPA-Nonce, X-WP-Nonce, X-Requested-With, X-PPA-View)
   * - Returns: Promise<{ ok, status, body, raw, contentType, headers }>
   */ // CHANGED:
  function apiPost(action, data) { // CHANGED:
    var url = getAjaxUrl();                                                                         // CHANGED:
    var nonce = getNonce();                                                                         // CHANGED:
    var qs = url.indexOf('?') === -1 ? '?' : '&';                                                    // CHANGED:
    var endpoint = url + qs + 'action=' + encodeURIComponent(action);                                // CHANGED:

    var headers = { 'Content-Type': 'application/json' };                                            // CHANGED:
    if (nonce) {                                                                                    // CHANGED:
      headers['X-PPA-Nonce'] = nonce;                                                                // CHANGED:
      headers['X-WP-Nonce'] = nonce;                                                                 // CHANGED:
    }                                                                                               // CHANGED:
    headers['X-Requested-With'] = 'XMLHttpRequest';                                                  // CHANGED:
    headers['X-PPA-View'] = getViewTag();                                                            // CHANGED:

    // NOTE: Keep the logging light; admin.js remains canonical right now.                          // CHANGED:
    // console.info('PPA: apiPost', action, '→', endpoint);                                          // CHANGED:

    return win.fetch(endpoint, {                                                                     // CHANGED:
      method: 'POST',                                                                               // CHANGED:
      headers: headers,                                                                             // CHANGED:
      body: JSON.stringify(data || {}),                                                              // CHANGED:
      credentials: 'same-origin'                                                                     // CHANGED:
    })                                                                                              // CHANGED:
    .then(function (res) {                                                                          // CHANGED:
      var ct = (res.headers.get('content-type') || '').toLowerCase();                                // CHANGED:
      return res.text().then(function (text) {                                                       // CHANGED:
        var body = jsonTryParse(text);                                                               // CHANGED:
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
        headers: new win.Headers()                                                                  // CHANGED:
      };                                                                                            // CHANGED:
    });                                                                                             // CHANGED:
  } // CHANGED:

  // Export module surface (no behavior change yet; admin.js still owns runtime wiring)             // CHANGED:
  win.PPAAdminModules.api = {                                                                       // CHANGED:
    ver: MOD_VER,                                                                                   // CHANGED:
    getAjaxUrl: getAjaxUrl,                                                                         // CHANGED:
    getNonce: getNonce,                                                                             // CHANGED:
    apiPost: apiPost,                                                                               // CHANGED:
    jsonTryParse: jsonTryParse                                                                       // CHANGED:
  };                                                                                                // CHANGED:

  console.info('PPA: ppa-admin-api.js loaded →', MOD_VER); // CHANGED:
})(window, document);
