/**
 * PostPress AI — Composer Preview Spinner
 * Path: assets/js/admin-preview-spinner.js
 *
 * Purpose:
 * - Add an elegant loading spinner overlay on the preview pane
 *   whenever PostPress AI runs Preview or Generate requests.
 *
 * Notes:
 * - This file is loaded IN ADDITION to assets/js/admin.js.
 * - It does NOT modify admin.js; instead it wraps window.fetch and
 *   watches for PostPress AI AJAX actions (ppa_preview / ppa_generate).
 * - Spinner is mounted inside #ppa-preview-pane only on the Composer page.
 */

(function () {
  'use strict';

  // Avoid double-init if something enqueues this twice.
  if (window.__PPA_PREVIEW_SPINNER_INIT__) return;
  window.__PPA_PREVIEW_SPINNER_INIT__ = true;

  // Only run on the PostPress AI Composer screen.
  var root = document.getElementById('ppa-composer');
  if (!root) return;

  if (typeof window.fetch !== 'function') {
    console.info('PPA: preview spinner skipped (fetch not available).');
    return;
  }

  var previewPane = document.getElementById('ppa-preview-pane');
  if (!previewPane) {
    console.info('PPA: preview spinner skipped (no #ppa-preview-pane).');
    return;
  }

  // Ensure the preview pane can host an absolute overlay without layout jumps.
  try {
    var cs = window.getComputedStyle(previewPane);
    if (cs && cs.position === 'static') {
      previewPane.style.position = 'relative';
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

  var ring = document.createElement('div');
  ring.className = 'ppa-spinner-ring';

  spinnerWrap.appendChild(ring);
  overlay.appendChild(spinnerWrap);
  previewPane.appendChild(overlay);

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
    // Small async frame so transition can apply cleanly.
    requestAnimationFrame(function () {
      overlay.style.opacity = '1';
    });
  }

  function hideSpinner() {
    // Smooth fade-out; after transition, hide display.
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
    if (activeCount > 0) activeCount--;
    if (activeCount <= 0) {
      activeCount = 0;
      hideSpinner();
    }
  }

  // ---------------------------------------------------------------------------
  // Inject CSS via <style> (keeps templates clean, styling lives with JS)
  // ---------------------------------------------------------------------------

  var css = ''
    + '.ppa-preview-spinner-overlay{'
    + 'position:absolute;top:0;left:0;right:0;bottom:0;'
    + 'display:none;align-items:center;justify-content:center;'
    + 'background:linear-gradient(135deg,rgba(0,0,0,0.78),rgba(0,0,0,0.88));'
    + 'backdrop-filter:blur(3px);'
    + 'z-index:10;'
    + 'transition:opacity 0.24s ease-out;'
    + '}'
    + '.ppa-preview-spinner{'
    + 'width:60px;height:60px;border-radius:999px;'
    + 'display:flex;align-items:center;justify-content:center;'
    + '}'
    + '.ppa-spinner-ring{'
    + 'width:46px;height:46px;border-radius:999px;'
    + 'border-width:2px;border-style:solid;'
    + 'border-color:rgba(255,255,255,0.15);'
    + 'border-top-color:#ff6c00;'
    + 'box-shadow:0 0 0 0 rgba(255,108,0,0.32);'
    + 'animation:ppa-spin 0.9s linear infinite,'
    + '          ppa-pulse 1.6s ease-in-out infinite;'
    + '}'
    + '@keyframes ppa-spin{'
    + 'to{transform:rotate(360deg);}'
    + '}'
    + '@keyframes ppa-pulse{'
    + '0%,100%{box-shadow:0 0 0 0 rgba(255,108,0,0.32);}'
    + '50%{box-shadow:0 0 0 12px rgba(255,108,0,0);}'
    + '}';

  var styleTag = document.createElement('style');
  styleTag.type = 'text/css';
  styleTag.textContent = css;
  document.head.appendChild(styleTag);

  // ---------------------------------------------------------------------------
  // Fetch wrapper — watch for PostPress AI Preview / Generate requests only
  // ---------------------------------------------------------------------------

  var originalFetch = window.fetch;
  var ajaxUrl = (window.PPA && window.PPA.ajaxUrl) || window.ajaxurl || '';

  function isComposerAction(input, init) {
    if (!ajaxUrl) return false;

    var url = '';
    if (typeof input === 'string') {
      url = input;
    } else if (input && typeof input.url === 'string') {
      url = input.url;
    }

    if (!url || url.indexOf(ajaxUrl) === -1) return false;
    if (!init || typeof init.body !== 'string') return false;

    try {
      var body = JSON.parse(init.body);
      var action = body && body.action;
      if (action === 'ppa_preview' || action === 'ppa_generate') {
        return true;
      }
    } catch (e) {
      // If parse fails, silently ignore; spinner won't engage.
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
