/**
 * PostPress AI — Composer Preview Spinner
 * File: assets/js/admin-preview-spinner.js
 *
 * ========= CHANGE LOG =========
 * 2026-02-18 — FIX: Restore preview loading overlay (was not showing).                 // CHANGED:
 * 2026-02-18 — FIX: Show loader overlay when switching languages (XHR + fetch hook).   // CHANGED:
 * 2026-02-18 — UI: When translating, show target language name under the spinner.     // CHANGED:
 *
 * Notes:
 * - This script is intentionally defensive: it works whether requests use fetch() OR XMLHttpRequest().
 * - It ONLY watches WP admin-ajax actions for PostPress AI preview/generate/translate.
 */

(function () {
  'use strict';

  // Prevent double-init
  if (window.__PPA_PREVIEW_SPINNER_INIT__) return;
  window.__PPA_PREVIEW_SPINNER_INIT__ = true;

  var PPA_SPINNER_VER = 'admin-preview-spinner.v2026-02-18.4'; // CHANGED:
  window.PPA_PREVIEW_SPINNER_VER = PPA_SPINNER_VER; // CHANGED:

  // --- Locate preview pane (must exist for overlay to be meaningful) ---
  var previewPane =
    document.getElementById('ppa-preview-pane') ||
    document.querySelector('[data-ppa-preview-pane]') ||
    document.querySelector('.ppa-preview-pane');

  if (!previewPane) {
    // No preview pane found; nothing to do.
    return;
  }

  // --- Helpers ---
  function safeStr(v) {
    return (v == null) ? '' : String(v);
  }

  function isAdminAjaxUrl(url) {
    var u = safeStr(url).toLowerCase();
    return u.indexOf('admin-ajax.php') !== -1;
  }

  function extractActionFromUrl(url) {
    var u = safeStr(url).toLowerCase();
    // Query-string action (most common)
    if (u.indexOf('action=ppa_preview') !== -1) return 'ppa_preview';
    if (u.indexOf('action=ppa_generate') !== -1) return 'ppa_generate';
    if (u.indexOf('action=ppa_translate_preview') !== -1) return 'ppa_translate_preview';
    return '';
  }

  function extractActionFromBody(body) {
    try {
      // URLSearchParams
      if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
        return safeStr(body.get('action')).toLowerCase();
      }

      // FormData
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        return safeStr(body.get('action')).toLowerCase();
      }

      // String body (x-www-form-urlencoded)
      if (typeof body === 'string') {
        var m = body.match(/(?:^|&)action=([^&]+)/i);
        if (m && m[1]) return decodeURIComponent(m[1]).toLowerCase();
      }
    } catch (e) {}

    return '';
  }

  function getWatchedKind(url, body) {
    // Only watch WP admin-ajax for our 3 actions.
    if (!isAdminAjaxUrl(url)) return '';

    // Prefer URL param action.
    var aUrl = extractActionFromUrl(url);
    if (aUrl) return aUrl;

    // Fallback: POST body action.
    var aBody = extractActionFromBody(body);
    if (aBody === 'ppa_preview' || aBody === 'ppa_generate' || aBody === 'ppa_translate_preview') return aBody;

    return '';
  }

  function isScrollable(el) {
    if (!el) return false;
    try {
      var cs = window.getComputedStyle(el);
      var oy = cs ? cs.overflowY : '';
      var scrollLike = (oy === 'auto' || oy === 'scroll' || oy === 'overlay');
      if (!scrollLike) return false;
      return (el.scrollHeight > el.clientHeight + 2);
    } catch (e) {
      return false;
    }
  }

  function pickOverlayHost() {
    // Prefer a known wrapper if present, otherwise pick the best scrollable container near previewPane.
    var wrapper =
      (previewPane.closest && previewPane.closest('.ppa-preview, .ppa-preview-col, .ppa-preview-column, .ppa-preview-wrap, .ppa-preview-pane-wrap')) ||
      previewPane.parentElement ||
      previewPane;

    var candidates = [
      wrapper,
      previewPane.parentElement,
      previewPane,
      wrapper && wrapper.parentElement
    ];

    for (var i = 0; i < candidates.length; i++) {
      if (isScrollable(candidates[i])) return candidates[i];
    }

    // If nothing is clearly scrollable, use wrapper.
    return wrapper || previewPane;
  }

  function getTargetLanguageLabel() { // CHANGED:
    try {
      var sel =
        document.getElementById('ppa-output-language') ||
        document.querySelector('select[name="ppa_output_language"]') ||
        document.querySelector('select[data-ppa-output-language]');

      if (!sel || !sel.options || sel.selectedIndex == null) return '';
      var opt = sel.options[sel.selectedIndex];
      var label = opt && opt.textContent ? String(opt.textContent).trim() : '';
      if (!label) return '';
      if (label.toLowerCase() === 'original') return '';
      return label;
    } catch (e) {
      return '';
    }
  }

  // --- Build overlay UI ---
  var host = pickOverlayHost();

  // Ensure host is positioned for absolute overlay
  try {
    var hostCS = window.getComputedStyle(host);
    if (hostCS && hostCS.position === 'static') host.style.position = 'relative';
  } catch (e) {}

  var overlay = document.createElement('div');
  overlay.className = 'ppa-preview-spinner-overlay';
  overlay.setAttribute('aria-hidden', 'true');
  overlay.style.display = 'none';
  overlay.style.opacity = '0';

  var spinnerWrap = document.createElement('div');
  spinnerWrap.className = 'ppa-preview-spinner';

  // loader-circle-9 markup (kept minimal)
  var loader = document.createElement('div');
  loader.className = 'loader-circle-9';
  loader.setAttribute('role', 'status');
  loader.setAttribute('aria-label', 'Loading');
  loader.appendChild(document.createTextNode('Loading'));

  var loaderSpan = document.createElement('span');
  loader.appendChild(loaderSpan);

  spinnerWrap.appendChild(loader);

  // CHANGED: translate label under spinner
  var spinnerLabel = document.createElement('div');
  spinnerLabel.className = 'ppa-preview-spinner-label';
  spinnerLabel.setAttribute('aria-hidden', 'true');
  spinnerLabel.textContent = '';
  spinnerLabel.style.display = 'none';
  spinnerWrap.appendChild(spinnerLabel);

  overlay.appendChild(spinnerWrap);
  host.appendChild(overlay);

  // Keep overlay pinned to visible viewport inside scroll host.
  function syncOverlayViewport() {
    try {
      var h = Math.max(host.clientHeight || 0, 0);
      var t = host.scrollTop || 0;

      // When host scrolls, keep overlay at the visible top.
      overlay.style.top = t + 'px';
      overlay.style.height = h + 'px';
    } catch (e) {}
  }

  var activeCount = 0;
  var activeTranslateCount = 0; // CHANGED:
  var fadeTimeout = null;

  function showOverlay() {
    if (fadeTimeout) { clearTimeout(fadeTimeout); fadeTimeout = null; }
    syncOverlayViewport();
    overlay.style.display = 'flex';

    // Force reflow so opacity transition reliably kicks in.
    // eslint-disable-next-line no-unused-expressions
    overlay.offsetHeight;

    overlay.style.opacity = '1';
  }

  function hideOverlay() {
    overlay.style.opacity = '0';
    fadeTimeout = setTimeout(function () {
      overlay.style.display = 'none';
    }, 260);
  }

  function setTranslateLabelFromUi() { // CHANGED:
    var label = getTargetLanguageLabel();
    spinnerLabel.textContent = label || '';
    spinnerLabel.style.display = label ? 'block' : 'none';
  }

  function clearTranslateLabel() { // CHANGED:
    spinnerLabel.textContent = '';
    spinnerLabel.style.display = 'none';
  }

  function begin(kind) { // CHANGED:
    activeCount++;

    if (kind === 'ppa_translate_preview') {
      activeTranslateCount++;
      setTranslateLabelFromUi();
    }

    if (activeCount === 1) showOverlay();
    else syncOverlayViewport();
  }

  function end(kind) { // CHANGED:
    if (kind === 'ppa_translate_preview') {
      if (activeTranslateCount > 0) activeTranslateCount--;
      if (activeTranslateCount <= 0) { activeTranslateCount = 0; clearTranslateLabel(); }
    }

    if (activeCount > 0) activeCount--;
    if (activeCount <= 0) { activeCount = 0; hideOverlay(); }
  }

  function onMaybeUpdate() {
    if (activeCount > 0) syncOverlayViewport();
  }

  try { host.addEventListener('scroll', onMaybeUpdate, { passive: true }); } catch (e1) {}
  try { window.addEventListener('resize', onMaybeUpdate); } catch (e2) {}

  try {
    if (typeof ResizeObserver !== 'undefined') {
      var ro = new ResizeObserver(onMaybeUpdate);
      ro.observe(host);
    }
  } catch (e3) {}

  // --- Styles: injected once ---
  var css =
    '.ppa-preview-spinner-overlay{' +
      'position:absolute;left:0;right:0;top:0;height:100%;' +
      'display:none;align-items:center;justify-content:center;' +
      'z-index:9999;' + // CHANGED:
      'pointer-events:none;' +
      '--ppa-brand:#ff6c00;' + // CHANGED:
      'background:radial-gradient(circle at center,rgba(0,0,0,0.12) 0,rgba(0,0,0,0.28) 45%,rgba(0,0,0,0.4) 100%);' +
      'backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);' +
      'transition:opacity 0.24s ease-out;' +
    '}' +
    '.ppa-preview-spinner{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;}' +
    '.ppa-preview-spinner-label{' +
      'margin-top:2px;' +
      'font-family:inherit;' +
      'font-size:14px;' +
      'font-weight:700;' +
      'letter-spacing:0.02em;' +
      'color:var(--ppa-brand);' +
      'text-align:center;' +
      'opacity:0.95;' +
      'text-transform:none;' +
      'text-shadow:0 2px 12px rgba(0,0,0,.55);' +
    '}' +

    /* Loader-circle-9 (scoped) */
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
      'color:var(--ppa-brand);' +
      'text-transform:uppercase;' +
      'box-shadow:0 0 20px rgba(0,0,0,.5);' +
      'user-select:none;' +
    '}' +
    '.loader-circle-9:before{' +
      "content:'';" +
      'position:absolute;top:-3px;left:-3px;' +
      'width:100%;height:100%;' +
      'border:3px solid transparent;' +
      'border-top:3px solid var(--ppa-brand);' +
      'border-right:3px solid var(--ppa-brand);' +
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
      'background:var(--ppa-brand);' +
      'top:-6px;' +
      'right:-8px;' +
      'box-shadow:0 0 18px rgba(255,108,0,.95);' +
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

  // --- Hook: fetch() ---
  if (typeof window.fetch === 'function') {
    var originalFetch = window.fetch;
    window.fetch = function (input, init) { // CHANGED:
      var url = (typeof input === 'string') ? input : (input && input.url) || '';
      var body = init ? init.body : undefined;
      var kind = getWatchedKind(url, body);

      if (kind) begin(kind);

      return originalFetch.call(window, input, init)
        .then(function (resp) {
          if (kind) end(kind);
          return resp;
        })
        .catch(function (err) {
          if (kind) end(kind);
          throw err;
        });
    };
  }

  // --- Hook: XMLHttpRequest (covers jQuery $.ajax / $.post) ---
  if (typeof XMLHttpRequest !== 'undefined' && XMLHttpRequest.prototype) {
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) { // CHANGED:
      this.__ppa_url = url;
      return origOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) { // CHANGED:
      try {
        var url = this.__ppa_url || '';
        var kind = getWatchedKind(url, body);

        if (kind) {
          begin(kind);

          var xhr = this;
          var done = function () {
            try { xhr.removeEventListener('loadend', done); } catch (e1) {}
            end(kind);
          };

          // loadend fires for success/error/abort/timeout
          xhr.addEventListener('loadend', done);
        }
      } catch (e2) {}

      return origSend.apply(this, arguments);
    };
  }

  console.info('PPA: preview spinner ready → ' + PPA_SPINNER_VER);
})();
