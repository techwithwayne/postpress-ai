/**
 * PostPress AI — Admin Core Helpers
 * Path: assets/js/ppa-admin-core.js
 *
 * ========= CHANGE LOG =========
 * 2025-12-09.1: Initial core module. Define window.PPAAdmin namespace and core helpers
 *               ($, $all, escHtml, escAttr, getAjaxUrl, getNonce) for reuse by other
 *               admin modules and future refactors. No behavior changes to Composer.
 */

(function () {
  'use strict';

  // Ensure global namespace exists
  var global = window;
  var PPAAdmin = global.PPAAdmin = global.PPAAdmin || {};

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
   * - #ppa-composer[data-ppa-nonce]
   * - window.PPA.nonce
   * - window.ppaAdmin.nonce
   * - #ppa-nonce input value
   *
   * Mirrors current admin.js behavior.
   *
   * @returns {string}
   */
  function getNonce() {
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
      if (global.ppaAdmin && global.ppaAdmin.nonce) {
        return String(global.ppaAdmin.nonce).trim();
      }
    } catch (e3) {
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
   * Attach helpers under PPAAdmin.core without clobbering any existing keys.
   * This allows future modules to reference a stable surface:
   *
   *   PPAAdmin.core.$
   *   PPAAdmin.core.escHtml
   *   PPAAdmin.core.getAjaxUrl()
   */
  if (!PPAAdmin.core) {
    PPAAdmin.core = {};
  }

  if (!PPAAdmin.core.$) {
    PPAAdmin.core.$ = $;
  }
  if (!PPAAdmin.core.$all) {
    PPAAdmin.core.$all = $all;
  }
  if (!PPAAdmin.core.escHtml) {
    PPAAdmin.core.escHtml = escHtml;
  }
  if (!PPAAdmin.core.escAttr) {
    PPAAdmin.core.escAttr = escAttr;
  }
  if (!PPAAdmin.core.getAjaxUrl) {
    PPAAdmin.core.getAjaxUrl = getAjaxUrl;
  }
  if (!PPAAdmin.core.getNonce) {
    PPAAdmin.core.getNonce = getNonce;
  }

  // Optional convenience aliases at top-level namespace (non-breaking, only if absent)
  if (!PPAAdmin.$) {
    PPAAdmin.$ = $;
  }
  if (!PPAAdmin.$all) {
    PPAAdmin.$all = $all;
  }

  console.info('PPA: ppa-admin-core.js initialized (core helpers ready)');
})();
