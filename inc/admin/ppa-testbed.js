/**
 * PostPress AI — Testbed Admin Helpers
 * Path: inc/admin/ppa-testbed.js
 *
 * ========= CHANGE LOG =========
 * 2025-11-11: Normalize fallback input → {title, content|text, provider:"testbed"}; add X-Requested-With;  // CHANGED:
 *             optional #ppa-testbed-title support; safer JSON wrapper surfacing.                           // CHANGED:
 * 2025-11-10: Add X-WP-Nonce header from ppaAdmin.wpNonce; unify json fetch helper;                        // (prev)
 *             robust DOM guards; status renderer; 10s timeout via AbortController.                         // (prev)
 * 2025-11-09: Initial cache-busted enqueue via filemtime (handled in PHP enqueue).                         // (prev)
 */

(() => {
  'use strict';

  // --- Guards & config ------------------------------------------------------------------------
  const cfg = (typeof window !== 'undefined' && window.PPA) ? window.PPA : {};
  const admin = (typeof window !== 'undefined' && window.ppaAdmin) ? window.ppaAdmin : {};
  const DEBUG = new URLSearchParams(location.search).has('ppa_debug');

  if (DEBUG) {
    console.info('PPA Testbed boot', { cfg, admin });
  }

  // Hard guard: must have ajaxUrl and nonces available
  if (!cfg.ajaxUrl || !admin?.nonce || !admin?.wpNonce) {
    console.warn('PPA Testbed: missing ajaxUrl or nonces; aborting init.', { ajaxUrl: cfg.ajaxUrl, nonce: admin?.nonce, wpNonce: admin?.wpNonce });
    return;
  }

  // --- DOM hookup (best-effort; only attaches if elements exist) ------------------------------
  const $ = (sel) => document.querySelector(sel);
  const inputEl   = $('#ppa-testbed-input');     // <textarea> or <input> with JSON or brief text
  const titleEl   = $('#ppa-testbed-title');     // optional <input> title                                  // CHANGED:
  const previewEl = $('#ppa-testbed-preview');   // <button> Preview
  const storeEl   = $('#ppa-testbed-store');     // <button> Store
  const outEl     = $('#ppa-testbed-output');    // <pre> or <div> render response
  const statusEl  = $('#ppa-testbed-status');    // <div> live status/notice

  // Allow running even if not all elements are present (e.g., markup variations)
  function setStatus(msg, type = 'info') {
    if (!statusEl) {
      if (DEBUG) console.log(`[status:${type}]`, msg);
      return;
    }
    statusEl.textContent = '';
    statusEl.className = `ppa-notice ppa-${type}`;
    statusEl.setAttribute('role', 'status');
    statusEl.setAttribute('aria-live', 'polite');
    statusEl.append(document.createTextNode(String(msg)));
  }

  function setOutput(objOrText) {
    if (!outEl) return;
    try {
      outEl.textContent = (typeof objOrText === 'string')
        ? objOrText
        : JSON.stringify(objOrText, null, 2);
    } catch {
      outEl.textContent = String(objOrText);
    }
  }

  // --- Networking helpers --------------------------------------------------------------------
  const HEADERS = {
    'Content-Type': 'application/json',
    'X-PPA-Nonce': admin.nonce,          // CSRF/intent nonce validated server-side (admin-post/ajax)
    'X-WP-Nonce': admin.wpNonce,         // REST nonce for wp-json routes (exposed via enqueue.php)
    'X-Requested-With': 'XMLHttpRequest' // helps some security layers detect AJAX                     // CHANGED:
  };

  function withTimeout(ms = 10000) {
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(new Error('timeout')), ms);
    return { signal: controller.signal, cancel: () => clearTimeout(t) };
  }

  async function jsonFetch(url, body, opts = {}) {
    const { signal, cancel } = withTimeout(opts.timeout || 10000);
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: HEADERS,
        body: JSON.stringify(body || {}),
        credentials: 'same-origin',
        signal
      });

      const ct = res.headers.get('content-type') || '';
      const isJson = ct.includes('application/json');

      let parsed = isJson ? await res.json() : await res.text();
      if (!res.ok) {
        const msg = isJson
          ? (parsed?.message || parsed?.error || JSON.stringify(parsed))
          : String(parsed);
        throw new Error(`HTTP ${res.status} — ${msg || 'Request failed'}`);
      }
      return parsed;
    } finally {
      cancel();
    }
  }

  function api(action, payload) {
    // AJAX actions handled by WP (server app proxies to Django)
    const url = `${cfg.ajaxUrl}?action=${encodeURIComponent(action)}`;
    return jsonFetch(url, payload);
  }

  // --- Payload helper ------------------------------------------------------------------------
  function parseInput() {                                                                                   // CHANGED:
    if (!inputEl) return {};
    const raw = String(inputEl.value || '');
    // Try JSON first
    try {
      const obj = JSON.parse(raw);
      return (obj && typeof obj === 'object') ? obj : {};
    } catch {
      // Fallback mapping aligned with Composer: prefer content (HTML), else text (plain)
      const title = (titleEl && titleEl.value) ? String(titleEl.value) : 'Testbed';
      const looksHtml = /<[^>]+>/.test(raw);
      const payload = { title, provider: 'testbed' };
      if (looksHtml) {
        payload.content = raw;
      } else {
        payload.text = raw;
      }
      return payload;
    }
  }

  // --- Actions -------------------------------------------------------------------------------
  async function doPreview() {
    try {
      setStatus('Generating preview…', 'info');
      const payload = parseInput();
      if (DEBUG) console.info('Preview payload:', payload);
      const data = await api('ppa_preview', payload);
      setOutput(data);
      setStatus('Preview complete.', 'success');
    } catch (err) {
      console.error('Preview failed:', err);
      setStatus(`Preview failed: ${err?.message || err}`, 'error');
    }
  }

  async function doStore() {
    try {
      setStatus('Storing to Draft…', 'info');
      const payload = parseInput();
      if (DEBUG) console.info('Store payload:', payload);
      const data = await api('ppa_store', payload);
      setOutput(data);
      setStatus('Draft created. Check the success notice for links in the Composer UI.', 'success');
    } catch (err) {
      console.error('Store failed:', err);
      setStatus(`Store failed: ${err?.message || err}`, 'error');
    }
  }

  // --- Wire events if buttons exist ----------------------------------------------------------
  if (previewEl) previewEl.addEventListener('click', doPreview, { passive: true });
  if (storeEl)   storeEl.addEventListener('click', doStore,   { passive: true });

  // Expose minimal API for manual testing from Console
  window.PPATestbed = {
    doPreview, doStore, parseInput, jsonFetch, api,
    headers: () => ({ ...HEADERS })
  };

  if (DEBUG) console.info('PPA Testbed ready.');
})();
