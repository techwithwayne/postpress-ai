// /var/www/html/wp-content/plugins/postpress-ai/assets/js/admin.js
/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2025-11-03: Add auto-fill (Title/Excerpt/Slug) after successful Preview response.     // CHANGED:
 *             - Tolerant JSON extraction (body.*, body.result.*, body.data.*).         // CHANGED:
 *             - HTML fallback: <h1> for title, first <p> text for excerpt.             // CHANGED:
 *             - Safe slug sanitizer; only fills empty fields (never overwrites).       // CHANGED:
 * 2025-10-30: Add full “Save to Draft” wiring:
 *             - Robust store payload (title, content, excerpt, status, slug, tags, categories).
 *             - TinyMCE/Classic editor content detection with graceful fallback.          // CHANGED:
 *             - Tolerant field selectors for various admin screens.                       // CHANGED:
 *             - Unified notices, double-submit guard kept.                                // CHANGED:
 * 2025-10-30: Keep Preview flow intact; provider sniff retained.                          // CHANGED:
 *
 * 2025-10-19: Added provider tracing for preview via <!-- provider: X --> sniff.
 * 2025-10-19: Added double-submit guard: disables buttons during async ops and re-enables on settle.
 * 2025-10-19: Expanded tolerant parsing: also reads id/message at body.level and body.result.level.
 * 2025-10-19: Replaced most alert() errors with inline toolbar notices (fallback to alert if no container).
 * =================================
 *
 * Notes:
 * - Logs are namespaced with "PPA:".
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
    // Primary from localized script, fallback to window.ajaxurl, then default path.
    if (window.ppaAdmin && window.ppaAdmin.ajaxurl) return window.ppaAdmin.ajaxurl;
    if (window.ajaxurl) return window.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  }

  function getNonce() {
    var el = $('#ppa-nonce');
    return el ? String(el.value || '').trim() : '';
  }

  function jsonTryParse(text) {
    try {
      return JSON.parse(text);
    } catch (e) {
      console.info('PPA: JSON parse failed, returning raw text');
      return { raw: String(text || '') };
    }
  }

  function setPreview(html) {
    var pane = $('#ppa-preview-pane');
    if (!pane) return;
    pane.innerHTML = html;
  }

  // Extract `provider` from an HTML comment like: <!-- provider: local-fallback -->
  function extractProviderFromHtml(html) {
    if (typeof html !== 'string') return '';
    var m = html.match(/<!--\s*provider:\s*([a-z0-9._-]+)\s*-->/i);
    return m ? m[1] : '';
  }

  // --- Rich text helpers (Classic/TinyMCE/Gutenberg-compatible) ------------- // CHANGED:

  function getTinyMCEContentById(id) { // CHANGED:
    try {
      if (!window.tinyMCE || !tinyMCE.get) return '';
      var ed = tinyMCE.get(id);
      return ed && !ed.isHidden() ? String(ed.getContent() || '') : '';
    } catch (e) {
      return '';
    }
  }

  function getEditorContent() { // CHANGED:
    // Try common WordPress editor fields, in order.
    // 1) Custom composer textarea
    var txt = $('#ppa-content');
    if (txt && String(txt.value || '').trim()) return String(txt.value || '').trim();

    // 2) TinyMCE (classic editor)
    var mce = getTinyMCEContentById('content');
    if (mce) return mce;

    // 3) Fallback to the raw textarea #content (classic editor fallback)
    var raw = $('#content');
    if (raw && String(raw.value || '').trim()) return String(raw.value || '').trim();

    // 4) Gutenberg note: we don't programmatically read blocks here; server will normalize if needed.
    return '';
  }

  // ---- Payload builders ----------------------------------------------------

  function buildPreviewPayload() {
    // Lightweight preview fields (prompting inputs)
    var subject = $('#ppa-subject');
    var brief = $('#ppa-brief');
    var genre = $('#ppa-genre');
    var tone = $('#ppa-tone');
    var wc = $('#ppa-word-count');

    return {
      subject: subject ? subject.value : '',
      brief: brief ? brief.value : '',
      genre: genre ? genre.value : '',
      tone: tone ? tone.value : '',
      word_count: wc ? Number(wc.value || 0) : 0
    };
  }

  function readCsvValues(el) { // CHANGED:
    if (!el) return [];
    var raw = String(el.value || '').trim();
    if (!raw) return [];
    return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }

  function buildStorePayload(target) { // CHANGED:
    // Build a tolerant payload for /ppa_store: include core post fields when present.
    // Field ids are tolerant and optional; server normalizes.
    var title = $('#ppa-title') || $('#title'); // custom or core
    var excerpt = $('#ppa-excerpt') || $('#excerpt');
    var slug = $('#ppa-slug') || $('#post_name');

    // Tags/Cats (comma-separated text inputs or multi-selects)
    var tagsEl = $('#ppa-tags') || $('#new-tag-post_tag') || $('#tax-input-post_tag');
    var catsEl = $('#ppa-categories') || $('#post_category');

    // Status (explicit if provided; otherwise server may decide based on target)
    var statusEl = $('#ppa-status');

    var payload = {
      // Core post fields
      title: title ? String(title.value || '') : '',
      content: getEditorContent(),
      excerpt: excerpt ? String(excerpt.value || '') : '',
      slug: slug ? String(slug.value || '') : '',

      // Taxonomy (tolerant)
      tags: (function () {
        if (tagsEl && tagsEl.tagName === 'SELECT') {
          return $all('option:checked', tagsEl).map(function (o) { return o.value; });
        }
        return readCsvValues(tagsEl);
      })(),
      categories: (function () {
        if (catsEl && catsEl.tagName === 'SELECT') {
          return $all('option:checked', catsEl).map(function (o) { return o.value; });
        }
        // Comma-separated fallback if using text
        return readCsvValues(catsEl);
      })(),

      // Status/Target
      status: statusEl ? String(statusEl.value || '') : '',

      // Metadata
      target_sites: [String(target || 'draft')], // 'draft' | 'publish'
      source: 'admin',
      ver: '1'
    };

    // Also include preview prompt fields if present (server may use them for context)
    var prev = buildPreviewPayload();
    for (var k in prev) {
      if (Object.prototype.hasOwnProperty.call(prev, k) && !Object.prototype.hasOwnProperty.call(payload, k)) {
        payload[k] = prev[k];
      }
    }
    return payload;
  }

  // ---- Toolbar Notices & Busy State ---------------------------------------

  var btnPreview = $('#ppa-preview');
  var btnDraft = $('#ppa-draft');
  var btnPublish = $('#ppa-publish');

  function noticeContainer() {
    // Preferred container for inline notices; optional in DOM.
    return $('#ppa-toolbar-msg') || null;
  }

  function renderNotice(type, message) {
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

  function clearNotice() {
    var el = noticeContainer();
    if (el) {
      el.className = 'ppa-notice';
      el.textContent = '';
    }
  }

  function setButtonsDisabled(disabled) {
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

  function withBusy(promiseFactory, label) {
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

  function pickMessage(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.message === 'string') return body.message;
    if (body.result && typeof body.result.message === 'string') return body.result.message;
    return '';
  }

  function pickId(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.id === 'string' || typeof body.id === 'number') return String(body.id);
    if (body.result && (typeof body.result.id === 'string' || typeof body.result.id === 'number')) return String(body.result.id);
    return '';
  }

  // ---- Auto-fill helpers (Title/Excerpt/Slug) ------------------------------ // CHANGED:

  function getElTitle() { return $('#ppa-title') || $('#title'); }          // CHANGED:
  function getElExcerpt() { return $('#ppa-excerpt') || $('#excerpt'); }    // CHANGED:
  function getElSlug() { return $('#ppa-slug') || $('#post_name'); }        // CHANGED:

  function setIfEmpty(el, val) {                                            // CHANGED:
    if (!el) return;
    var cur = String(el.value || '').trim();
    if (!cur && val) el.value = String(val);
  }

  function sanitizeSlug(s) {                                                // CHANGED:
    if (!s) return '';
    // Lowercase, strip HTML, remove non-url chars, collapse dashes.
    var t = String(s).toLowerCase();
    t = t.replace(/<[^>]*>/g, '');                 // strip any tags if present
    t = t.normalize ? t.normalize('NFKD') : t;     // unicode normalize when available
    t = t.replace(/[^\w\s-]+/g, '');               // remove punctuation except dash/underscore
    t = t.replace(/\s+/g, '-');                    // spaces → dashes
    t = t.replace(/-+/g, '-');                     // collapse multiples
    t = t.replace(/^-+|-+$/g, '');                 // trim leading/trailing dashes
    return t;
  }

  function textFromFirstMatch(html, selector) {                              // CHANGED:
    try {
      var tmp = document.createElement('div');
      tmp.innerHTML = html || '';
      var el = tmp.querySelector(selector);
      if (!el) return '';
      var text = (el.textContent || '').trim();
      return text;
    } catch (e) {
      return '';
    }
  }

  function extractTitleFromHtml(html) {                                     // CHANGED:
    // Priority: <h1>, fallback first heading in order h1..h3.
    var t = textFromFirstMatch(html, 'h1');
    if (t) return t;
    t = textFromFirstMatch(html, 'h2');
    if (t) return t;
    t = textFromFirstMatch(html, 'h3');
    return t || '';
  }

  function extractExcerptFromHtml(html) {                                   // CHANGED:
    // First non-empty <p> text; trimmed, single line.
    var p = textFromFirstMatch(html, 'p');
    if (!p) return '';
    // Make it short-ish; WordPress excerpt is typically brief.
    return p.replace(/\s+/g, ' ').trim().slice(0, 300);
  }

  function pickField(body, key) {                                           // CHANGED:
    if (!body || typeof body !== 'object') return '';
    if (typeof body[key] === 'string') return body[key];
    if (body.result && typeof body.result[key] === 'string') return body.result[key];
    if (body.data && typeof body.data[key] === 'string') return body.data[key];
    return '';
  }

  function autoFillAfterPreview(body, html) {                               // CHANGED:
    // 1) Attempt JSON-sourced values.
    var title = pickField(body, 'title');
    var excerpt = pickField(body, 'excerpt');
    var slug = pickField(body, 'slug');

    // 2) Fallback from HTML if still missing.
    if (!title) title = extractTitleFromHtml(html);
    if (!excerpt) excerpt = extractExcerptFromHtml(html);
    if (!slug && title) slug = sanitizeSlug(title);

    // 3) Set fields only if currently empty (do not overwrite user input).
    setIfEmpty(getElTitle(), title);
    setIfEmpty(getElExcerpt(), excerpt);
    setIfEmpty(getElSlug(), slug);

    // 4) Console trace for observability.
    console.info('PPA: autofill candidates →', { title: !!title, excerpt: !!excerpt, slug: !!slug });
  }

  // ---- Events --------------------------------------------------------------

  if (btnPreview) {
    btnPreview.addEventListener('click', function (ev) {
      ev.preventDefault();
      console.info('PPA: Preview clicked');

      withBusy(function () {
        var payload = buildPreviewPayload();
        return apiPost('ppa_preview', payload).then(function (res) {
          var html = pickHtmlFromResponseBody(res.body);

          // Provider tracing — sniff provider marker and log it.
          var provider = extractProviderFromHtml(html);
          if (provider) {
            console.info('PPA: provider=' + provider);
          } else {
            console.info('PPA: provider=(unknown)');
          }

          if (html) {
            setPreview(html);
            clearNotice();
            autoFillAfterPreview(res.body, html); // CHANGED: perform tolerant auto-fill after preview
            return;
          }

          // If no HTML, surface a friendly message in the pane
          setPreview('<p><em>Preview did not return HTML content. Check logs.</em></p>');
          renderNotice('warn', 'Preview completed, but no HTML was returned.');
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
        var payload = buildStorePayload('draft');
        return apiPost('ppa_store', payload).then(function (res) {
          var msg = pickMessage(res.body) || 'Draft request sent.';
          var pid = pickId(res.body);
          if (!res.ok) {
            renderNotice('error', 'Draft failed (' + res.status + '): ' + msg);
            console.info('PPA: draft failed', res);
            return;
          }
          var okMsg = 'Draft saved.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : '');
          renderNotice('success', okMsg);
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
        var payload = buildStorePayload('publish');
        return apiPost('ppa_store', payload).then(function (res) {
          var msg = pickMessage(res.body) || 'Publish request sent.';
          var pid = pickId(res.body);
        if (!res.ok) {
            renderNotice('error', 'Publish failed (' + res.status + '): ' + msg);
            console.info('PPA: publish failed', res);
            return;
          }
          var okMsg = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : '');
          renderNotice('success', okMsg);
          console.info('PPA: publish success', res);
        });
      }, 'publish');
    });
  }

  console.info('PPA: admin.js initialized');
})();
