/**
 * PostPress AI — Admin Core Helpers
 * Path: assets/js/ppa-admin-core.js
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.3: Harden core export for modular cutover — merge into existing PPAAdmin.core and only overwrite when missing or non-function; always set core.ver. Keeps nonce priority fix intact. // CHANGED:
 * 2025-12-20.2: Nonce priority fix — prefer window.ppaAdmin.nonce before #ppa-composer[data-ppa-nonce] to avoid wp_rest nonce being used for admin-ajax actions. // CHANGED:
 * 2025-12-09.1: Initial core module. Define window.PPAAdmin namespace and core helpers
 *               ($, $all, escHtml, escAttr, getAjaxUrl, getNonce) for reuse by other
 *               admin modules and future refactors. No behavior changes to Composer.
 */

(function () {
  'use strict';

  // Ensure global namespace exists
  var global = window;
  var PPAAdmin = global.PPAAdmin = global.PPAAdmin || {};

  var MOD_VER = 'ppa-admin-core.v2025-12-20.3'; // CHANGED:

  /**
   * Core DOM helpers
   */

  /**
   * Shorthand querySelector
   * @param {string} sel
   * @param {Element|Document} [ctx]
   * @returns {Element|null}
   */
  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  /**
   * Shorthand querySelectorAll → Array
   * @param {string} sel
   * @param {Element|Document} [ctx]
   * @returns {Element[]}
   */
  function $all(sel, ctx) {
    var nodeList = (ctx || document).querySelectorAll(sel) || [];
    return Array.prototype.slice.call(nodeList);
  }

  /**
   * Simple HTML escaper for text nodes.
   * @param {string} s
   * @returns {string}
   */
  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  /**
   * Simple attribute escaper.
   * @param {string} s
   * @returns {string}
   */
  function escAttr(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * Resolve the AJAX URL from multiple possible globals.
   * Mirrors current admin.js behavior:
   * - window.PPA.ajaxUrl
   * - window.PPA.ajax (legacy)
   * - window.ppaAdmin.ajaxurl
   * - window.ajaxurl
   * - Fallback: /wp-admin/admin-ajax.php
   *
   * @returns {string}
   */
  function getAjaxUrl() {
    try {
      if (global.PPA && global.PPA.ajaxUrl) {
        return global.PPA.ajaxUrl;
      }
      if (global.PPA && global.PPA.ajax) {
        return global.PPA.ajax; // legacy
      }
      if (global.ppaAdmin && global.ppaAdmin.ajaxurl) {
        return global.ppaAdmin.ajaxurl;
      }
      if (global.ajaxurl) {
        return global.ajaxurl;
      }
    } catch (e) {
      // fall through to default
    }
    return '/wp-admin/admin-ajax.php';
  }

  /**
   * Resolve nonce from:
   * - window.ppaAdmin.nonce  (preferred admin-ajax nonce)                                            // CHANGED:
   * - #ppa-composer[data-ppa-nonce] (fallback only)                                                   // CHANGED:
   * - window.PPA.nonce
   * - #ppa-nonce input value
   *
   * Mirrors current admin.js behavior (nonce priority fix).
   *
   * @returns {string}
   */
  function getNonce() { // CHANGED:
    // CHANGED: Prefer wp_localize_script nonce for admin-ajax actions first.
    try { // CHANGED:
      if (global.ppaAdmin && global.ppaAdmin.nonce) { // CHANGED:
        return String(global.ppaAdmin.nonce).trim(); // CHANGED:
      } // CHANGED:
    } catch (e0) { // CHANGED:
      // ignore and continue fallback chain
    } // CHANGED:

    // Fallback: template-provided nonce on the Composer root (ONLY as fallback)
    try {
      var composer = document.getElementById('ppa-composer');
      if (composer) {
        var dn = composer.getAttribute('data-ppa-nonce');
        if (dn) {
          return String(dn).trim();
        }
      }
    } catch (e) {
      // ignore and continue fallback chain
    }

    try {
      if (global.PPA && global.PPA.nonce) {
        return String(global.PPA.nonce).trim();
      }
    } catch (e2) {
      // ignore
    }

    try {
      var el = $('#ppa-nonce');
      if (el) {
        return String(el.value || '').trim();
      }
    } catch (e4) {
      // ignore
    }

    return '';
  }

  /**
   * Attach helpers under PPAAdmin.core with cutover-safe merging.
   * During modular cutover, objects may be pre-created or partially overwritten.
   * We only overwrite when missing OR when the existing key is not a function. // CHANGED:
   */
  if (!PPAAdmin.core || typeof PPAAdmin.core !== 'object') {
    PPAAdmin.core = {}; // CHANGED:
  }

  // CHANGED: Always set core.ver to the current module version.
  PPAAdmin.core.ver = MOD_VER; // CHANGED:

  if (typeof PPAAdmin.core.$ !== 'function') { // CHANGED:
    PPAAdmin.core.$ = $; // CHANGED:
  }
  if (typeof PPAAdmin.core.$all !== 'function') { // CHANGED:
    PPAAdmin.core.$all = $all; // CHANGED:
  }
  if (typeof PPAAdmin.core.escHtml !== 'function') { // CHANGED:
    PPAAdmin.core.escHtml = escHtml; // CHANGED:
  }
  if (typeof PPAAdmin.core.escAttr !== 'function') { // CHANGED:
    PPAAdmin.core.escAttr = escAttr; // CHANGED:
  }
  if (typeof PPAAdmin.core.getAjaxUrl !== 'function') { // CHANGED:
    PPAAdmin.core.getAjaxUrl = getAjaxUrl; // CHANGED:
  }
  if (typeof PPAAdmin.core.getNonce !== 'function') { // CHANGED:
    PPAAdmin.core.getNonce = getNonce; // CHANGED:
  }

  // Optional convenience aliases at top-level namespace (non-breaking, only if absent/non-function)
  if (typeof PPAAdmin.$ !== 'function') { // CHANGED:
    PPAAdmin.$ = $; // CHANGED:
  }
  if (typeof PPAAdmin.$all !== 'function') { // CHANGED:
    PPAAdmin.$all = $all; // CHANGED:
  }

  console.info('PPA: ppa-admin-core.js initialized (core helpers ready)', { ver: MOD_VER }); // CHANGED:
})();
