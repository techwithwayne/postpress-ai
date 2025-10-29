/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2025-10-19: Added provider tracing for preview via <!-- provider: X --> sniff. // CHANGED:
 * 2025-10-19: Added double-submit guard: disables buttons during async ops and re-enables on settle. // CHANGED:
 * 2025-10-19: Expanded tolerant parsing: also reads id/message at body.level and body.result.level. // CHANGED:
 * 2025-10-19: Replaced most alert() errors with inline toolbar notices (fallback to alert if no container). // CHANGED:
 * =================================
 *
 * Notes:
 * - Logs are namespaced with "PPA:" as per P1.
 * - Nonce header: X-PPA-Nonce when #ppa-nonce is present.
 * - JSON shape tolerance:
 *     html: body.html | body.result.html | body.data.html | body.content | body.preview | body.raw
 *     message: body.message | body.result.message
 *     id: body.id | body.result.id
 * - Inline notices render into #ppa-toolbar-msg when available; otherwise fall back to alert().
 */

(function () {
  'use strict';

  // Abort if composer root is missing (defensive)
  var root = document.getElementById('ppa-composer');
  if (!root) {
    console.info('PPA: composer root not found, admin.js is idle');
    return;
  }

  // ---- Helpers -------------------------------------------------------------

  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function $all(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel) || []);
  }

  function getAjaxUrl() {
    // Primary from localized script, fallback to window.ajaxurl, then default path. // CHANGED:
    if (window.ppaAdmin && window.ppaAdmin.ajaxurl) return window.ppaAdmin.ajaxurl; // CHANGED:
    if (window.ajaxurl) return window.ajaxurl; // CHANGED:
    return '/wp-admin/admin-ajax.php'; // CHANGED:
  }

  function getNonce() {
    var el = $('#ppa-nonce');
    return el ? String(el.value || '').trim() : '';
  }

  function jsonTryParse(text) {
    try {
      return JSON.parse(text);
    } catch (e) {
      console.info('PPA: JSON parse failed, returning raw text'); // CHANGED:
      return { raw: String(text || '') };
    }
  }

  function setPreview(html) {
    var pane = $('#ppa-preview-pane');
    if (!pane) return;
    pane.innerHTML = html;
  }

  // Extract `provider` from an HTML comment like: <!-- provider: local-fallback --> // CHANGED:
  function extractProviderFromHtml(html) { // CHANGED:
    if (typeof html !== 'string') return ''; // CHANGED:
    var m = html.match(/<!--\s*provider:\s*([a-z0-9._-]+)\s*-->/i); // CHANGED:
    return m ? m[1] : ''; // CHANGED:
  }

  function buildPayload() {
    var subject = $('#ppa-subject');
    var brief = $('#ppa-brief');
    var genre = $('#ppa-genre');
    var tone = $('#ppa-tone');
    var wc = $('#ppa-word-count');

    var payload = {
      subject: subject ? subject.value : '',
      brief: brief ? brief.value : '',
      genre: genre ? genre.value : '',
      tone: tone ? tone.value : '',
      word_count: wc ? Number(wc.value || 0) : 0
    };
    return payload;
  }

  // ---- Toolbar Notices & Busy State --------------------------------------- // CHANGED:

  var btnPreview = $('#ppa-preview'); // CHANGED:
  var btnDraft = $('#ppa-draft');     // CHANGED:
  var btnPublish = $('#ppa-publish'); // CHANGED:

  function noticeContainer() { // CHANGED:
    // Preferred container for inline notices; optional in DOM.
    return $('#ppa-toolbar-msg') || null;
  }

  function renderNotice(type, message) { // CHANGED:
    // type: 'info' | 'success' | 'error' | 'warn'
    var el = noticeContainer();
    var text = String(message == null ? '' : message);
    if (!el) {
      // Fallback to alert if we have nowhere to render.
      if (type === 'error' || type === 'warn') {
        // eslint-disable-next-line no-alert
        alert(text);
      } else {
        console.info('PPA:', type + ':', text);
      }
      return;
    }
    var clsBase = 'ppa-notice';
    var clsType = 'ppa-notice-' + type;
    el.className = clsBase + ' ' + clsType;
    el.textContent = text;
  }

  function clearNotice() { // CHANGED:
    var el = noticeContainer();
    if (el) {
      el.className = 'ppa-notice';
      el.textContent = '';
    }
  }

  function setButtonsDisabled(disabled) { // CHANGED:
    [btnPreview, btnDraft, btnPublish].forEach(function (b) {
      if (b) {
        b.disabled = !!disabled;
        if (disabled) {
          b.setAttribute('aria-busy', 'true');
        } else {
          b.removeAttribute('aria-busy');
        }
      }
    });
  }

  function withBusy(promiseFactory, label) { // CHANGED:
    setButtonsDisabled(true);
    clearNotice();
    var tag = label || 'request';
    console.info('PPA: busy start →', tag);
    try {
      var p = promiseFactory();
      return Promise.resolve(p)
        .catch(function (err) {
          console.info('PPA: busy error on', tag, err);
          renderNotice('error', 'There was an error while processing your request.');
          throw err;
        })
        .finally(function () {
          setButtonsDisabled(false);
          console.info('PPA: busy end ←', tag);
        });
    } catch (e) {
      setButtonsDisabled(false);
      console.info('PPA: busy sync error on', tag, e);
      renderNotice('error', 'There was an error while preparing your request.');
      throw e;
    }
  }

  // ---- Network -------------------------------------------------------------

  function apiPost(action, data) {
    var url = getAjaxUrl();
    var nonce = getNonce();

    var qs = url.indexOf('?') === -1 ? '?' : '&';
    var endpoint = url + qs + 'action=' + encodeURIComponent(action);

    var headers = { 'Content-Type': 'application/json' };
    if (nonce) headers['X-PPA-Nonce'] = nonce;

    console.info('PPA: POST', action, '→', endpoint);
    return fetch(endpoint, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(data || {}),
      credentials: 'same-origin'
    })
      .then(function (res) {
        var ct = (res.headers.get('content-type') || '').toLowerCase();
        return res.text().then(function (text) {
          // Try to parse JSON; if not JSON, wrap raw text.
          var body = ct.indexOf('application/json') !== -1 ? jsonTryParse(text) : jsonTryParse(text);
          return { ok: res.ok, status: res.status, body: body, raw: text, contentType: ct };
        });
      })
      .catch(function (err) {
        console.info('PPA: fetch error', err);
        return { ok: false, status: 0, body: { error: String(err) }, raw: '', contentType: '' };
      });
  }

  // ---- Response Shape Helpers ---------------------------------------------

  function pickHtmlFromResponseBody(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.html === 'string') return body.html;
    if (body.result && typeof body.result.html === 'string') return body.result.html;
    if (body.data && typeof body.data.html === 'string') return body.data.html;
    if (typeof body.content === 'string') return body.content;
    if (typeof body.preview === 'string') return body.preview;
    if (typeof body.raw === 'string') return body.raw; // last resort if server returned non-JSON
    return '';
  }

  function pickMessage(body) { // CHANGED:
    if (!body || typeof body !== 'object') return '';
    if (typeof body.message === 'string') return body.message;
    if (body.result && typeof body.result.message === 'string') return body.result.message;
    return '';
  }

  function pickId(body) { // CHANGED:
    if (!body || typeof body !== 'object') return '';
    if (typeof body.id === 'string' || typeof body.id === 'number') return String(body.id);
    if (body.result && (typeof body.result.id === 'string' || typeof body.result.id === 'number')) return String(body.result.id);
    return '';
  }

  // ---- Events --------------------------------------------------------------

  if (btnPreview) {
    btnPreview.addEventListener('click', function (ev) {
      ev.preventDefault();
      console.info('PPA: Preview clicked');

      withBusy(function () {
        var payload = buildPayload();
        return apiPost('ppa_preview', payload).then(function (res) {
          var html = pickHtmlFromResponseBody(res.body);

          // Provider tracing (Improvement #1) — sniff provider marker and log it. // CHANGED:
          var provider = extractProviderFromHtml(html); // CHANGED:
          if (provider) { // CHANGED:
            console.info('PPA: provider=' + provider); // CHANGED:
          } else { // CHANGED:
            console.info('PPA: provider=(unknown)'); // CHANGED:
          } // CHANGED:

          if (html) {
            setPreview(html);
            clearNotice(); // CHANGED:
            return;
          }

          // If no HTML, surface a friendly message in the pane
          setPreview('<p><em>Preview did not return HTML content. Check logs.</em></p>');
          renderNotice('warn', 'Preview completed, but no HTML was returned.'); // CHANGED:
          console.info('PPA: preview response lacked HTML', res);
        });
      }, 'preview');
    });
  }

  if (btnDraft) {
    btnDraft.addEventListener('click', function (ev) {
      ev.preventDefault();
      console.info('PPA: Save Draft clicked');

      withBusy(function () {
        var payload = buildPayload();
        payload.target_sites = ['draft'];

        return apiPost('ppa_store', payload).then(function (res) {
          var msg = pickMessage(res.body) || 'Draft request sent.'; // CHANGED:
          var pid = pickId(res.body); // CHANGED:
          if (!res.ok) {
            renderNotice('error', 'Draft failed (' + res.status + '): ' + msg); // CHANGED:
            console.info('PPA: draft failed', res);
            return;
          }
          var okMsg = 'Draft saved.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : ''); // CHANGED:
          renderNotice('success', okMsg); // CHANGED:
          console.info('PPA: draft success', res);
        });
      }, 'draft');
    });
  }

  if (btnPublish) {
    btnPublish.addEventListener('click', function (ev) {
      ev.preventDefault();
      console.info('PPA: Publish clicked');

      /* eslint-disable no-alert */
      if (!window.confirm('Are you sure you want to publish this content now?')) {
        console.info('PPA: publish canceled by user');
        return;
      }
      /* eslint-enable no-alert */

      withBusy(function () {
        var payload = buildPayload();
        payload.target_sites = ['publish'];

        return apiPost('ppa_store', payload).then(function (res) {
          var msg = pickMessage(res.body) || 'Publish request sent.'; // CHANGED:
          var pid = pickId(res.body); // CHANGED:
          if (!res.ok) {
            renderNotice('error', 'Publish failed (' + res.status + '): ' + msg); // CHANGED:
            console.info('PPA: publish failed', res);
            return;
          }
          var okMsg = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : ''); // CHANGED:
          renderNotice('success', okMsg); // CHANGED:
          console.info('PPA: publish success', res);
        });
      }, 'publish');
    });
  }

  console.info('PPA: admin.js initialized');
})();
