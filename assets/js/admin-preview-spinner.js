/**
 * PostPress AI — Composer Preview Spinner
 * Path: assets/js/admin-preview-spinner.js
 *
 * Purpose:
 * - Add an elegant loading spinner overlay on the preview column
 *   whenever PostPress AI runs Preview or Generate requests.
 *
 * Notes:
 * - Loaded IN ADDITION to assets/js/admin.js.
 * - Wraps window.fetch and watches for admin-ajax.php calls with
 *   action=ppa_preview or action=ppa_generate in the URL.
 * - Spinner overlay covers the entire preview column area.
 */

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Preflight: only run once, only on Composer screen
  // ---------------------------------------------------------------------------

  if (window.__PPA_PREVIEW_SPINNER_INIT__) {
    return;
  }
  window.__PPA_PREVIEW_SPINNER_INIT__ = true;

  var root = document.getElementById('ppa-composer');
  if (!root) {
    return;
  }

  if (typeof window.fetch !== 'function') {
    console.info('PPA: preview spinner skipped (fetch not available).');
    return;
  }

  var previewPane = document.getElementById('ppa-preview-pane');
  if (!previewPane) {
    console.info('PPA: preview spinner skipped (no #ppa-preview-pane).');
    return;
  }

  // Use the preview pane's parent as the overlay container so we cover
  // the entire preview column, not just the inner content box.
  var overlayContainer = previewPane.parentElement || previewPane;

  try {
    var cs = window.getComputedStyle(overlayContainer);
    if (cs && cs.position === 'static') {
      overlayContainer.style.position = 'relative';
    }
  } catch (e) {}

  // ---------------------------------------------------------------------------
  // Spinner DOM
  // ---------------------------------------------------------------------------

  var overlay = document.createElement('div');
  overlay.className = 'ppa-preview-spinner-overlay';
  overlay.setAttribute('aria-hidden', 'true');

  var spinnerWrap = document.createElement('div');
  spinnerWrap.className = 'ppa-preview-spinner';

  // PPA-prefixed 12-bar spinner
  var loader = document.createElement('div');
  loader.className = 'ppa-lds-spinner';

  // Create 12 bars as children for the spinner
  for (var i = 0; i < 12; i++) {
    loader.appendChild(document.createElement('div'));
  }

  spinnerWrap.appendChild(loader);
  overlay.appendChild(spinnerWrap);
  overlayContainer.appendChild(overlay);

  overlay.style.display = 'none';
  overlay.style.opacity = '0';

  var activeCount = 0;
  var fadeTimeout = null;

  function showSpinner() {
    if (fadeTimeout) {
      window.clearTimeout(fadeTimeout);
      fadeTimeout = null;
    }
    overlay.style.display = 'flex';
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(function () {
        overlay.style.opacity = '1';
      });
    } else {
      overlay.style.opacity = '1';
    }
  }

  function hideSpinner() {
    overlay.style.opacity = '0';
    fadeTimeout = window.setTimeout(function () {
      overlay.style.display = 'none';
    }, 260);
  }

  function beginSpinner() {
    activeCount++;
    if (activeCount === 1) {
      showSpinner();
    }
  }

  function endSpinner() {
    if (activeCount > 0) {
      activeCount--;
    }
    if (activeCount <= 0) {
      activeCount = 0;
      hideSpinner();
    }
  }

  // ---------------------------------------------------------------------------
  // Inject CSS via <style> so we don't touch admin.css
  // ---------------------------------------------------------------------------

  var css = ''
    // Full-column overlay (lighter, with subtle blur)
    + '.ppa-preview-spinner-overlay{'
    + 'position:absolute;top:0;left:0;right:0;bottom:0;'
    + 'display:none;align-items:center;justify-content:center;'
    + 'background:radial-gradient(circle at center,'
    + 'rgba(0,0,0,0.12) 0,'
    + 'rgba(0,0,0,0.28) 45%,'
    + 'rgba(0,0,0,0.4) 100%);'
    + 'backdrop-filter:blur(3px);'
    + 'z-index:10;'
    + 'transition:opacity 0.24s ease-out;'
    + 'pointer-events:none;'
    + '}'
    // Center the loader
    + '.ppa-preview-spinner{'
    + 'display:flex;align-items:center;justify-content:center;'
    + '}'
    // Spinner core (PPA-prefixed adaptation of .lds-spinner)
    + '.ppa-lds-spinner,'
    + '.ppa-lds-spinner div,'
    + '.ppa-lds-spinner div:after{'
    + 'box-sizing:border-box;'
    + '}'
    + '.ppa-lds-spinner{'
    + 'color:#ff6c00;'
    + 'display:inline-block;'
    + 'position:relative;'
    + 'width:80px;'
    + 'height:80px;'
    + '}'
    + '.ppa-lds-spinner div{'
    + 'transform-origin:40px 40px;'
    + 'animation:ppa-lds-spinner 1.2s linear infinite;'
    + '}'
    + '.ppa-lds-spinner div:after{'
    + 'content:" ";'
    + 'display:block;'
    + 'position:absolute;'
    + 'top:3.2px;'
    + 'left:36.8px;'
    + 'width:6.4px;'
    + 'height:17.6px;'
    + 'border-radius:20%;'
    + 'background:currentColor;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(1){'
    + 'transform:rotate(0deg);'
    + 'animation-delay:-1.1s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(2){'
    + 'transform:rotate(30deg);'
    + 'animation-delay:-1s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(3){'
    + 'transform:rotate(60deg);'
    + 'animation-delay:-0.9s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(4){'
    + 'transform:rotate(90deg);'
    + 'animation-delay:-0.8s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(5){'
    + 'transform:rotate(120deg);'
    + 'animation-delay:-0.7s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(6){'
    + 'transform:rotate(150deg);'
    + 'animation-delay:-0.6s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(7){'
    + 'transform:rotate(180deg);'
    + 'animation-delay:-0.5s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(8){'
    + 'transform:rotate(210deg);'
    + 'animation-delay:-0.4s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(9){'
    + 'transform:rotate(240deg);'
    + 'animation-delay:-0.3s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(10){'
    + 'transform:rotate(270deg);'
    + 'animation-delay:-0.2s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(11){'
    + 'transform:rotate(300deg);'
    + 'animation-delay:-0.1s;'
    + '}'
    + '.ppa-lds-spinner div:nth-child(12){'
    + 'transform:rotate(330deg);'
    + 'animation-delay:0s;'
    + '}'
    + '@keyframes ppa-lds-spinner{'
    + '0%{opacity:1;}'
    + '100%{opacity:0;}'
    + '}';

  var styleTag = document.createElement('style');
  styleTag.type = 'text/css';
  styleTag.textContent = css;
  document.head.appendChild(styleTag);

  // ---------------------------------------------------------------------------
  // Fetch wrapper — watch for admin-ajax.php?action=ppa_preview|ppa_generate
  // ---------------------------------------------------------------------------

  var originalFetch = window.fetch;

  function isComposerAction(input, init) {
    var url = '';

    if (typeof input === 'string') {
      url = input;
    } else if (input && typeof input.url === 'string') {
      url = input.url;
    } else if (input && typeof input.href === 'string') {
      url = input.href;
    }

    if (!url) {
      return false;
    }

    var lower = String(url).toLowerCase();

    // Only care about WP admin-ajax calls
    if (lower.indexOf('admin-ajax.php') === -1) {
      return false;
    }

    // Composer Preview / Generate actions
    if (lower.indexOf('action=ppa_preview') !== -1) {
      return true;
    }
    if (lower.indexOf('action=ppa_generate') !== -1) {
      return true;
    }

    return false;
  }

  window.fetch = function patchedFetch(input, init) {
    var watch = false;
    try {
      watch = isComposerAction(input, init);
    } catch (e) {
      watch = false;
    }

    if (watch) {
      beginSpinner();
    }

    return originalFetch(input, init)
      .then(function (resp) {
        if (watch) {
          endSpinner();
        }
        return resp;
      })
      .catch(function (err) {
        if (watch) {
          endSpinner();
        }
        throw err;
      });
  };

  console.info('PPA: preview spinner initialized.');
})();
