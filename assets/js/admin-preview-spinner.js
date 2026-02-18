/**
 * PostPress AI — Composer Preview Spinner
 * Path: assets/js/admin-preview-spinner.js
 *
 * ========= CHANGE LOG =========
 * 2026-02-18 — UI: Loader-circle-9 updated to PostPress AI brand color #ff6c00.      // CHANGED:
 *            — TODO: Display clear notification when Target Audience is empty.       // CHANGED:
 *            — Keep stable fetch wrapper + preview overlay sizing sync intact.
 *
 * 2025-12-22.1: Detect preview/generate actions when `action` is sent in POST body (FormData/URLSearchParams/x-www-form-urlencoded),
 *               not only URL query; de-dupe injected <style> tag; add a version tag for grep/logs.
 *
 * Purpose:
 * - Add a loading overlay on the preview column whenever PostPress AI runs Preview or Generate requests.
 *
 * TODO (UI validation):
 * - Display a clear notification indicating that the Target Audience field must be filled in.
 */

(function () {
  'use strict';

  var PPA_SPINNER_VER = 'preview-spinner.v2026-02-18.3'; // CHANGED:
  window.PPA_PREVIEW_SPINNER_VER = PPA_SPINNER_VER; // CHANGED:

  if (window.__PPA_PREVIEW_SPINNER_INIT__) return;
  window.__PPA_PREVIEW_SPINNER_INIT__ = true;

  var root = document.getElementById('ppa-composer');
  if (!root || typeof window.fetch !== 'function') return;

  var previewPane = document.getElementById('ppa-preview-pane');
  if (!previewPane) return;

  // previewPane.parentElement is typically the scroll container for the preview column.
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

  // loader-circle-9 markup
  var loader = document.createElement('div');
  loader.className = 'loader-circle-9';
  loader.setAttribute('role', 'status');
  loader.setAttribute('aria-label', 'Loading');
  loader.appendChild(document.createTextNode('Loading'));

  var loaderSpan = document.createElement('span');
  loader.appendChild(loaderSpan);

  spinnerWrap.appendChild(loader);
  overlay.appendChild(spinnerWrap);
  overlayContainer.appendChild(overlay);

  overlay.style.display = 'none';
  overlay.style.opacity = '0';

  var activeCount = 0;
  var fadeTimeout = null;

  function safeNum(n) { return (typeof n === 'number' && isFinite(n)) ? n : 0; }

  // Keep overlay covering the visible portion of the scroll container while busy.
  function syncOverlayViewport() {
    try {
      var h = Math.max(safeNum(overlayContainer.clientHeight), 0);
      var t = safeNum(overlayContainer.scrollTop);

      overlay.style.top = t + 'px';
      overlay.style.height = h + 'px';
    } catch (e) {}
  }

  function showSpinner() {
    if (fadeTimeout) { clearTimeout(fadeTimeout); fadeTimeout = null; }
    syncOverlayViewport();
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

  function onMaybeUpdate() { if (activeCount > 0) syncOverlayViewport(); }

  try { overlayContainer.addEventListener('scroll', onMaybeUpdate, { passive: true }); } catch (e1) {}
  try { window.addEventListener('resize', onMaybeUpdate); } catch (e2) {}

  try {
    if (typeof ResizeObserver !== 'undefined') {
      var ro = new ResizeObserver(onMaybeUpdate);
      ro.observe(overlayContainer);
    }
  } catch (e3) {}

  try {
    if (typeof MutationObserver !== 'undefined') {
      var mo = new MutationObserver(onMaybeUpdate);
      mo.observe(previewPane, { childList: true, subtree: true, characterData: true });
    }
  } catch (e4) {}

  // Styles (single injected tag, overwritten to ensure correct loader CSS)
  var css =
    '.ppa-preview-spinner-overlay{' +
      'position:absolute;left:0;right:0;top:0;height:100%;' +
      'display:none;align-items:center;justify-content:center;' +
      'background:radial-gradient(circle at center,rgba(0,0,0,0.12) 0,rgba(0,0,0,0.28) 45%,rgba(0,0,0,0.4) 100%);' +
      'backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);' +
      'z-index:10;transition:opacity 0.24s ease-out;pointer-events:none;' +
    '}' +
    '.ppa-preview-spinner{display:flex;align-items:center;justify-content:center;}' +

    /* Loader-circle-9 (adapted to work inside our overlay; no body styles) */
    ':root{--ppa-brand:#ff6c00;}' + // CHANGED:
    '.loader-circle-9{' +
      'position:relative;' +
      'width:70px;height:70px;' +
      'background:transparent;' +
      'border:3px solid rgba(255,255,255,.18);' +
      'border-radius:50%;' +
      'text-align:center;' +
      'line-height:70px;' +
      'font-family:sans-serif;' +
      'font-size:12px;' +
      'color:var(--ppa-brand);' + // CHANGED:
      'text-transform:uppercase;' +
      'box-shadow:0 0 20px rgba(0,0,0,.5);' +
      'user-select:none;' +
    '}' +
    '.loader-circle-9:before{' +
      "content:'';" +
      'position:absolute;top:-3px;left:-3px;' +
      'width:100%;height:100%;' +
      'border:3px solid transparent;' +
      'border-top:3px solid var(--ppa-brand);' + // CHANGED:
      'border-right:3px solid var(--ppa-brand);' + // CHANGED:
      'border-radius:50%;' +
      'animation:ppa_loader_animateC 2s linear infinite;' +
    '}' +
    '.loader-circle-9 span{' +
      'display:block;' +
      'position:absolute;' +
      'top:calc(50% - 2px);' +
      'left:50%;' +
      'width:50%;' +
      'height:4px;' +
      'background:transparent;' +
      'transform-origin:left;' +
      'animation:ppa_loader_animate 2s linear infinite;' +
    '}' +
    '.loader-circle-9 span:before{' +
      "content:'';" +
      'position:absolute;' +
      'width:16px;height:16px;' +
      'border-radius:50%;' +
      'background:var(--ppa-brand);' + // CHANGED:
      'top:-6px;' +
      'right:-8px;' +
      'box-shadow:0 0 18px rgba(255,108,0,.95);' + // CHANGED:
    '}' +
    '@keyframes ppa_loader_animateC{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}' +
    '@keyframes ppa_loader_animate{0%{transform:rotate(45deg)}100%{transform:rotate(405deg)}}';

  var styleTagId = 'ppa-preview-spinner-styles';
  var styleTag = document.getElementById(styleTagId);
  if (!styleTag) {
    styleTag = document.createElement('style');
    styleTag.id = styleTagId;
    document.head.appendChild(styleTag);
  }
  styleTag.textContent = css;

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

    return originalFetch.call(window, input, init)
      .then(function (r) {
        if (watch) endSpinner();
        return r;
      })
      .catch(function (e) {
        if (watch) endSpinner();
        throw e;
      });
  };

  console.info('PPA: preview spinner initialized → ' + PPA_SPINNER_VER);
})();
