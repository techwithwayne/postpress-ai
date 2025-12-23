/**
 * PostPress AI — Composer Preview Spinner
 * Path: assets/js/admin-preview-spinner.js
 *
 * ========= CHANGE LOG =========
 * 2025-12-22.1: Detect preview/generate actions when `action` is sent in POST body (FormData/URLSearchParams/x-www-form-urlencoded),
 *               not only URL query; de-dupe injected <style> tag; add a version tag for grep/logs. // CHANGED:
 *               No endpoint/payload/auth changes. Spinner behavior unchanged except it now also triggers when action is in request body. // CHANGED:
 *
 * Purpose:
 * - Add an elegant loading spinner overlay on the preview column
 *   whenever PostPress AI runs Preview or Generate requests.
 */

(function () {
  'use strict';

  var PPA_SPINNER_VER = 'preview-spinner.v2025-12-22.1'; // CHANGED:
  window.PPA_PREVIEW_SPINNER_VER = PPA_SPINNER_VER; // CHANGED:

  if (window.__PPA_PREVIEW_SPINNER_INIT__) return;
  window.__PPA_PREVIEW_SPINNER_INIT__ = true;

  var root = document.getElementById('ppa-composer');
  if (!root || typeof window.fetch !== 'function') return;

  var previewPane = document.getElementById('ppa-preview-pane');
  if (!previewPane) return;

  var overlayContainer = previewPane.parentElement || previewPane;

  try {
    var cs = window.getComputedStyle(overlayContainer);
    if (cs && cs.position === 'static') overlayContainer.style.position = 'relative';
  } catch (e) {}

  var overlay = document.createElement('div');
  overlay.className = 'ppa-preview-spinner-overlay';
  overlay.setAttribute('aria-hidden', 'true');

  var spinnerWrap = document.createElement('div');
  spinnerWrap.className = 'ppa-preview-spinner';

  var loader = document.createElement('div');
  loader.className = 'ppa-lds-spinner';
  for (var i = 0; i < 12; i++) loader.appendChild(document.createElement('div'));

  spinnerWrap.appendChild(loader);
  overlay.appendChild(spinnerWrap);
  overlayContainer.appendChild(overlay);

  overlay.style.display = 'none';
  overlay.style.opacity = '0';

  var activeCount = 0;
  var fadeTimeout = null;

  function showSpinner() {
    if (fadeTimeout) { clearTimeout(fadeTimeout); fadeTimeout = null; }
    overlay.style.display = 'flex';
    overlay.style.opacity = '1';
  }

  function hideSpinner() {
    overlay.style.opacity = '0';
    fadeTimeout = setTimeout(function () {
      overlay.style.display = 'none';
    }, 260);
  }

  function beginSpinner() {
    activeCount++;
    if (activeCount === 1) showSpinner();
  }

  function endSpinner() {
    if (activeCount > 0) activeCount--;
    if (activeCount <= 0) { activeCount = 0; hideSpinner(); }
  }

  var css =
    '.ppa-preview-spinner-overlay{position:absolute;top:0;left:0;right:0;bottom:0;display:none;align-items:center;justify-content:center;' +
    'background:radial-gradient(circle at center,rgba(0,0,0,0.12) 0,rgba(0,0,0,0.28) 45%,rgba(0,0,0,0.4) 100%);' +
    'backdrop-filter:blur(3px);z-index:10;transition:opacity 0.24s ease-out;pointer-events:none;}' +
    '.ppa-preview-spinner{display:flex;align-items:center;justify-content:center;}' +
    '.ppa-lds-spinner{color:#ff6c00;display:inline-block;position:relative;width:80px;height:80px;}' +
    '.ppa-lds-spinner div{transform-origin:40px 40px;animation:ppa-lds-spinner 1.2s linear infinite;}' +
    '.ppa-lds-spinner div:after{content:" ";display:block;position:absolute;top:3.2px;left:36.8px;width:6.4px;height:17.6px;border-radius:20%;background:currentColor;}' +
    '@keyframes ppa-lds-spinner{0%{opacity:1;}100%{opacity:0;}}';

  var styleTagId = 'ppa-preview-spinner-styles';
  var styleTag = document.getElementById(styleTagId);
  if (!styleTag) {
    styleTag = document.createElement('style');
    styleTag.id = styleTagId;
    styleTag.textContent = css;
    document.head.appendChild(styleTag);
  }

  var originalFetch = window.fetch;

  function extractAction(init) {
    if (!init || !init.body) return '';
    try {
      if (typeof URLSearchParams !== 'undefined' && init.body instanceof URLSearchParams) {
        return String(init.body.get('action') || '').toLowerCase();
      }
      if (typeof FormData !== 'undefined' && init.body instanceof FormData) {
        return String(init.body.get('action') || '').toLowerCase();
      }
      if (typeof init.body === 'string') {
        var m = init.body.match(/(?:^|&)action=([^&]+)/i);
        if (m && m[1]) return decodeURIComponent(m[1]).toLowerCase();
      }
    } catch (e) {}
    return '';
  }

  function isWatched(input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!url || url.toLowerCase().indexOf('admin-ajax.php') === -1) return false;
    if (url.indexOf('action=ppa_preview') !== -1 || url.indexOf('action=ppa_generate') !== -1) return true;
    var a = extractAction(init);
    return a === 'ppa_preview' || a === 'ppa_generate';
  }

  window.fetch = function (input, init) {
    var watch = isWatched(input, init);
    if (watch) beginSpinner();
    return originalFetch(input, init).then(function (r) {
      if (watch) endSpinner();
      return r;
    }).catch(function (e) {
      if (watch) endSpinner();
      throw e;
    });
  };

  console.info('PPA: preview spinner initialized → ' + PPA_SPINNER_VER);
})();
