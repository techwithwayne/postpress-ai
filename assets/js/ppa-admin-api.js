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
 * 2025-12-22.2: Defensive hardening only — guard Headers access, ensure string coercion safety,
 *               and bump module version. NO contract or wiring changes. // CHANGED:
 * 2025-12-22.1: Harden fallback transport to post URL-encoded `payload=` so PHP receives $_POST['payload'].
 * 2025-12-20.3: Prefer PPAAdmin.core helpers; keep linked apiPost getter for strict equality.
 * 2025-12-20.2: Do not overwrite PPAAdminModules.api; expose apiPost as linked getter.
 * 2025-12-20.1: Initial Admin API module.
 */

(function (win, doc) {
  'use strict';

  if (!win.PPAAdminModules) { win.PPAAdminModules = {}; }

  var MOD_VER = 'ppa-admin-api.v2025-12-22.2'; // CHANGED:

  // Merge-safe module object
  var api = win.PPAAdminModules.api || {};

  // --- Internal helpers ------------------------------------------------------

  function getCore() {
    try {
      if (win.PPAAdmin && win.PPAAdmin.core && typeof win.PPAAdmin.core === 'object') {
        return win.PPAAdmin.core;
      }
    } catch (e) {}
    return null;
  }

  function getAjaxUrl() {
    try {
      var core = getCore();
      if (core && typeof core.getAjaxUrl === 'function') {
        var u = core.getAjaxUrl();
        if (u) return u;
      }
    } catch (e0) {}

    if (win.PPA && win.PPA.ajaxUrl) return win.PPA.ajaxUrl;
    if (win.PPA && win.PPA.ajax) return win.PPA.ajax;
    if (win.ppaAdmin && win.ppaAdmin.ajaxurl) return win.ppaAdmin.ajaxurl;
    if (win.ajaxurl) return win.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  }

  function getNonce() {
    try {
      var core = getCore();
      if (core && typeof core.getNonce === 'function') {
        var n0 = core.getNonce();
        if (n0) return String(n0).trim();
      }
    } catch (e00) {}

    if (win.ppaAdmin && win.ppaAdmin.nonce) return String(win.ppaAdmin.nonce).trim();

    try {
      var root = doc.getElementById('ppa-composer');
      if (root) {
        var dn = root.getAttribute('data-ppa-nonce');
        if (dn) return String(dn).trim();
      }
    } catch (e1) {}

    if (win.PPA && win.PPA.nonce) return String(win.PPA.nonce).trim();
    var el = doc.getElementById('ppa-nonce');
    if (el) return String(el.value || '').trim();
    return '';
  }

  function jsonTryParse(text) {
    try { return JSON.parse(text); }
    catch (e) { return { raw: String(text || '') }; }
  }

  function getViewTag() {
    var view = 'composer';
    try {
      var root2 = doc.getElementById('ppa-composer');
      if (root2 && root2.getAttribute('data-ppa-view')) {
        view = String(root2.getAttribute('data-ppa-view') || 'composer');
      }
    } catch (e) {}
    return view;
  }

  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function normalizePayloadValue(data) {
    if (!data || typeof data !== 'object') return data;
    if (!hasOwn(data, 'payload')) return data;
    var onlyPayload = true;
    for (var k in data) {
      if (hasOwn(data, k) && k !== 'payload') { onlyPayload = false; break; }
    }
    return onlyPayload ? data.payload : data;
  }

  function buildUrlEncodedBody(payloadStr) {
    try {
      if (typeof win.URLSearchParams !== 'undefined') {
        var usp = new win.URLSearchParams();
        usp.set('payload', payloadStr);
        return usp.toString();
      }
    } catch (e0) {}
    return 'payload=' + encodeURIComponent(payloadStr);
  }

  // Internal implementation (used when window.PPAAdmin.apiPost is not present yet).
  var apiPostImpl = function apiPostImpl(action, data, options) {
    options = options || {};

    var url = options.ajaxUrl || getAjaxUrl();
    var nonce = (typeof options.nonce === 'string' && options.nonce.trim()) ? options.nonce.trim() : getNonce();
    var viewTag = (typeof options.view === 'string' && options.view.trim()) ? options.view.trim() : getViewTag();

    var qs = url.indexOf('?') === -1 ? '?' : '&';
    var endpoint = url + qs + 'action=' + encodeURIComponent(action);

    var payloadVal = normalizePayloadValue(data || {});
    var payloadStr = (typeof payloadVal === 'string') ? payloadVal : JSON.stringify(payloadVal || {});
    var bodyStr = buildUrlEncodedBody(payloadStr);

    var headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
    if (nonce) {
      headers['X-PPA-Nonce'] = nonce;
      headers['X-WP-Nonce'] = nonce;
    }
    headers['X-Requested-With'] = 'XMLHttpRequest';
    headers['X-PPA-View'] = viewTag;

    if (options.headers && typeof options.headers === 'object') {
      for (var k2 in options.headers) {
        if (Object.prototype.hasOwnProperty.call(options.headers, k2)) {
          headers[k2] = options.headers[k2];
        }
      }
    }

    return win.fetch(endpoint, {
      method: 'POST',
      headers: headers,
      body: bodyStr,
      credentials: 'same-origin'
    })
    .then(function (res) {
      var ct = '';
      try { ct = (res.headers.get('content-type') || '').toLowerCase(); } catch (eCt) {} // CHANGED:
      return res.text().then(function (text) {
        var body = jsonTryParse(text);
        return {
          ok: res.ok,
          status: res.status,
          body: body,
          raw: text,
          contentType: ct,
          headers: res.headers
        };
      });
    })
    .catch(function (err) {
      return {
        ok: false,
        status: 0,
        body: { error: String(err) },
        raw: '',
        contentType: '',
        headers: (win.Headers ? new win.Headers() : {})
      };
    });
  };

  function resolveApiPostRef() {
    try {
      if (win.PPAAdmin && typeof win.PPAAdmin.apiPost === 'function') return win.PPAAdmin.apiPost;
    } catch (e) {}
    return apiPostImpl;
  }

  // ---- Export (merge-safe) ---------------------------------------------------
  api.ver = MOD_VER;
  api.getAjaxUrl = getAjaxUrl;
  api.getNonce = getNonce;
  api.jsonTryParse = jsonTryParse;

  try {
    Object.defineProperty(api, 'apiPost', {
      configurable: true,
      enumerable: true,
      get: function () {
        return resolveApiPostRef();
      },
      set: function (fn) {
        if (typeof fn === 'function') {
          apiPostImpl = fn;
          try {
            if (win.PPAAdmin && typeof win.PPAAdmin === 'object') { win.PPAAdmin.apiPost = fn; }
          } catch (e2) {}
        }
      }
    });
  } catch (e3) {
    api.apiPost = resolveApiPostRef();
  }

  win.PPAAdminModules.api = api;

  console.info('PPA: ppa-admin-api.js loaded →', MOD_VER);
})(window, document);
