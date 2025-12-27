/**
 * PostPress AI — Admin Core Helpers
 * Path: assets/js/ppa-admin-core.js
 *
 * ========= CHANGE LOG =========
 * 2025-12-22.1: Harden DOM helpers with try/catch guards, normalize string coercion,
 *               and bump module version. NO behavior or contract changes. // CHANGED:
 * 2025-12-21.1: Add non-breaking top-level helper aliases + safeJsonParse.
 * 2025-12-20.3: Harden core export for modular cutover; merge-only behavior.
 * 2025-12-20.2: Nonce priority fix — prefer window.ppaAdmin.nonce.
 * 2025-12-09.1: Initial core module.
 */

(function () {
  'use strict';

  var global = window;
  var documentRef = global.document; // CHANGED:
  var PPAAdmin = global.PPAAdmin = global.PPAAdmin || {};

  var MOD_VER = 'ppa-admin-core.v2025-12-22.1'; // CHANGED:

  /**
   * Core DOM helpers
   */

  function $(sel, ctx) {
    try { // CHANGED:
      return (ctx || documentRef).querySelector(sel);
    } catch (e) {
      return null; // CHANGED:
    }
  }

  function $all(sel, ctx) {
    try { // CHANGED:
      var nodeList = (ctx || documentRef).querySelectorAll(sel) || [];
      return Array.prototype.slice.call(nodeList);
    } catch (e) {
      return []; // CHANGED:
    }
  }

  function escHtml(s) {
    return String(s == null ? '' : s) // CHANGED:
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escAttr(s) {
    return String(s == null ? '' : s) // CHANGED:
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getAjaxUrl() {
    try {
      if (global.PPA && global.PPA.ajaxUrl) return global.PPA.ajaxUrl;
      if (global.PPA && global.PPA.ajax) return global.PPA.ajax;
      if (global.ppaAdmin && global.ppaAdmin.ajaxurl) return global.ppaAdmin.ajaxurl;
      if (global.ajaxurl) return global.ajaxurl;
    } catch (e) {}
    return '/wp-admin/admin-ajax.php';
  }

  function getNonce() {
    try {
      if (global.ppaAdmin && global.ppaAdmin.nonce) {
        return String(global.ppaAdmin.nonce).trim();
      }
    } catch (e0) {}

    try {
      var composer = documentRef.getElementById('ppa-composer');
      if (composer) {
        var dn = composer.getAttribute('data-ppa-nonce');
        if (dn) return String(dn).trim();
      }
    } catch (e1) {}

    try {
      if (global.PPA && global.PPA.nonce) {
        return String(global.PPA.nonce).trim();
      }
    } catch (e2) {}

    try {
      var el = $('#ppa-nonce');
      if (el) return String(el.value || '').trim();
    } catch (e3) {}

    return '';
  }

  function safeJsonParse(raw, fallback) {
    try {
      return JSON.parse(String(raw || ''));
    } catch (e) {
      return (typeof fallback !== 'undefined') ? fallback : null;
    }
  }

  if (!PPAAdmin.core || typeof PPAAdmin.core !== 'object') {
    PPAAdmin.core = {};
  }

  PPAAdmin.core.ver = MOD_VER; // CHANGED:

  if (typeof PPAAdmin.core.$ !== 'function') PPAAdmin.core.$ = $;
  if (typeof PPAAdmin.core.$all !== 'function') PPAAdmin.core.$all = $all;
  if (typeof PPAAdmin.core.escHtml !== 'function') PPAAdmin.core.escHtml = escHtml;
  if (typeof PPAAdmin.core.escAttr !== 'function') PPAAdmin.core.escAttr = escAttr;
  if (typeof PPAAdmin.core.getAjaxUrl !== 'function') PPAAdmin.core.getAjaxUrl = getAjaxUrl;
  if (typeof PPAAdmin.core.getNonce !== 'function') PPAAdmin.core.getNonce = getNonce;
  if (typeof PPAAdmin.core.safeJsonParse !== 'function') PPAAdmin.core.safeJsonParse = safeJsonParse;

  if (typeof PPAAdmin.$ !== 'function') PPAAdmin.$ = $;
  if (typeof PPAAdmin.$all !== 'function') PPAAdmin.$all = $all;
  if (typeof PPAAdmin.escHtml !== 'function') PPAAdmin.escHtml = escHtml;
  if (typeof PPAAdmin.escAttr !== 'function') PPAAdmin.escAttr = escAttr;
  if (typeof PPAAdmin.getAjaxUrl !== 'function') PPAAdmin.getAjaxUrl = getAjaxUrl;
  if (typeof PPAAdmin.getNonce !== 'function') PPAAdmin.getNonce = getNonce;
  if (typeof PPAAdmin.safeJsonParse !== 'function') PPAAdmin.safeJsonParse = safeJsonParse;

  console.info('PPA: ppa-admin-core.js initialized (core helpers ready)', { ver: MOD_VER });
})();
