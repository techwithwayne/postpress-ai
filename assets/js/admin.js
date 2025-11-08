// /home/u3007-tenkoaygp3je/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/assets/js/admin.js
/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2025-11-08.5: Fix ajax URL key to match enqueue (window.PPA.ajaxUrl); keep legacy fallback.   // CHANGED
 * 2025-11-08.4: Align with window.PPA config + publish link parity.                              // history
 *   - Read ajax/nonce from window.PPA (fallbacks kept).
 *   - Send both X-PPA-Nonce and X-WP-Nonce if nonce present.
 *   - Publish success mirrors Draft: render View/Edit links when available.
 *   - pickViewLink() also considers data.link and data.result.link.
 * 2025-11-08.3: Robust save path:
 *   - Nonce fallback: data-ppa-nonce → ppaAdmin.nonce → #ppa-nonce.
 *   - Empty-content fix: use Preview pane HTML when editor is blank; auto-fill.
 *   - Early guard on empty submit (no title, no content, no preview HTML).
 *   - Wrap-aware fields: pickId()/pickEditLink() read data.* and data.result.*.
 * 2025-11-08: Add "View Draft" link (prefer view/permalink; fallback to edit; both when present).
 * 2025-11-06: Add "Edit Draft" link to success notice.
 * 2025-11-05: UX polish + resilience
 * 2025-11-03: Auto-fill (Title/Excerpt/Slug) after Preview.
 * 2025-10-30: “Save to Draft” wiring.
 * 2025-10-19: Provider tracing; async guards & notices.
 */

(function () {
  'use strict';

  var PPA_JS_VER = 'admin.v2025-11-08.5'; // CHANGED

  // Abort if composer root is missing (defensive)
  var root = document.getElementById('ppa-composer');
  if (!root) {
    console.info('PPA: composer root not found, admin.js is idle');
    return;
  }

  // Ensure toolbar message acts as a live region (A11y)
  (function ensureLiveRegion(){
    var msg = document.getElementById('ppa-toolbar-msg');
    if (msg) {
      if (!msg.getAttribute('role')) msg.setAttribute('role', 'status');
      if (!msg.getAttribute('aria-live')) msg.setAttribute('aria-live', 'polite');
    }
  })();

  // Make preview pane focusable for screen readers
  (function ensurePreviewPaneFocusable(){
    var pane = document.getElementById('ppa-preview-pane');
    if (pane && !pane.hasAttribute('tabindex')) pane.setAttribute('tabindex', '-1');
  })();

  // ---- Helpers -------------------------------------------------------------

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel) || []); }

  function getAjaxUrl() {                                                     // CHANGED
    if (window.PPA && window.PPA.ajaxUrl) return window.PPA.ajaxUrl;          // CHANGED: primary (matches enqueue)
    if (window.PPA && window.PPA.ajax) return window.PPA.ajax;                // legacy support
    if (window.ppaAdmin && window.ppaAdmin.ajaxurl) return window.ppaAdmin.ajaxurl;
    if (window.ajaxurl) return window.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  }

  // Expanded nonce fallback: data-attr → PPA.nonce → ppaAdmin.nonce → #ppa-nonce
  function getNonce() {
    var r = document.getElementById('ppa-composer');
    if (r) {
      var dn = r.getAttribute('data-ppa-nonce');
      if (dn) return String(dn).trim();
    }
    if (window.PPA && window.PPA.nonce) return String(window.PPA.nonce).trim();
    if (window.ppaAdmin && window.ppaAdmin.nonce) return String(window.ppaAdmin.nonce).trim();
    var el = $('#ppa-nonce');
    if (el) return String(el.value || '').trim();
    return '';
  }

  function jsonTryParse(text) {
    try { return JSON.parse(text); } catch (e) {
      console.info('PPA: JSON parse failed, returning raw text');
      return { raw: String(text || '') };
    }
  }

  function setPreview(html) {
    var pane = $('#ppa-preview-pane');
    if (!pane) return;
    pane.innerHTML = html;
    try { pane.focus(); } catch (e) {}
  }

  // Extract `provider` from an HTML comment like: <!-- provider: local-fallback -->
  function extractProviderFromHtml(html) {
    if (typeof html !== 'string') return '';
    var m = html.match(/<!--\s*provider:\s*([a-z0-9._-]+)\s*-->/i);
    return m ? m[1] : '';
  }

  // --- Rich text helpers (Classic/TinyMCE/Gutenberg-compatible) -------------

  function getTinyMCEContentById(id) {
    try {
      if (!window.tinyMCE || !tinyMCE.get) return '';
      var ed = tinyMCE.get(id);
      return ed && !ed.isHidden() ? String(ed.getContent() || '') : '';
    } catch (e) { return ''; }
  }

  function getEditorContent() {
    var txt = $('#ppa-content');
    if (txt && String(txt.value || '').trim()) return String(txt.value || '').trim();

    var mce = getTinyMCEContentById('content');
    if (mce) return mce;

    var raw = $('#content');
    if (raw && String(raw.value || '').trim()) return String(raw.value || '').trim();

    return '';
  }

  // ---- Payload builders ----------------------------------------------------

  function buildPreviewPayload() {
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
      word_count: wc ? Number(wc.value || 0) : 0,
      _js_ver: PPA_JS_VER
    };
  }

  function readCsvValues(el) {
    if (!el) return [];
    var raw = String(el.value || '').trim();
    if (!raw) return [];
    return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }

  function textFromFirstMatch(html, selector) {
    try {
      var tmp = document.createElement('div');
      tmp.innerHTML = html || '';
      var el = tmp.querySelector(selector);
      if (!el) return '';
      return (el.textContent || '').trim();
    } catch (e) { return ''; }
  }
  function extractTitleFromHtml(html) {
    return textFromFirstMatch(html, 'h1') || textFromFirstMatch(html, 'h2') || textFromFirstMatch(html, 'h3') || '';
  }
  function extractExcerptFromHtml(html) {
    var p = textFromFirstMatch(html, 'p');
    if (!p) return '';
    return p.replace(/\s+/g, ' ').trim().slice(0, 300);
  }
  function sanitizeSlug(s) {
    if (!s) return '';
    var t = String(s).toLowerCase();
    t = t.replace(/<[^>]*>/g, '');
    t = t.normalize ? t.normalize('NFKD') : t;
    t = t.replace(/[^\w\s-]+/g, '');
    t = t.replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
    return t;
  }

  function buildStorePayload(target) {
    var title   = $('#ppa-title')   || $('#title');
    var excerpt = $('#ppa-excerpt') || $('#excerpt');
    var slug    = $('#ppa-slug')    || $('#post_name');

    var tagsEl   = $('#ppa-tags') || $('#new-tag-post_tag') || $('#tax-input-post_tag');
    var catsEl   = $('#ppa-categories') || $('#post_category');
    var statusEl = $('#ppa-status');

    var payload = {
      title:   title   ? String(title.value || '')   : '',
      content: getEditorContent(),
      excerpt: excerpt ? String(excerpt.value || '') : '',
      slug:    slug    ? String(slug.value || '')    : '',
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
        return readCsvValues(catsEl);
      })(),
      status: statusEl ? String(statusEl.value || '') : '',
      target_sites: [String(target || 'draft')],
      source: 'admin',
      ver: '1',
      _js_ver: PPA_JS_VER
    };

    // If editor is empty, use Preview HTML and auto-fill
    if (!payload.content || !String(payload.content).trim()) {
      var pane = document.getElementById('ppa-preview-pane');
      var html = pane ? String(pane.innerHTML || '').trim() : '';
      if (html) {
        payload.content = html;
        if (!payload.title)   payload.title   = extractTitleFromHtml(html);
        if (!payload.excerpt) payload.excerpt = extractExcerptFromHtml(html);
        if (!payload.slug && payload.title) payload.slug = sanitizeSlug(payload.title);
      }
    }

    // Merge preview meta for traceability
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
  var btnDraft   = $('#ppa-draft');
  var btnPublish = $('#ppa-publish');

  function noticeContainer() { return $('#ppa-toolbar-msg') || null; }

  function renderNotice(type, message) {
    var el = noticeContainer();
    var text = String(message == null ? '' : message);
    if (!el) {
      if (type === 'error' || type === 'warn') { alert(text); } // eslint-disable-line no-alert
      else { console.info('PPA:', type + ':', text); }
      return;
    }
    var clsBase = 'ppa-notice', clsType = 'ppa-notice-' + type;
    el.className = clsBase + ' ' + clsType;
    el.textContent = text;
  }

  function renderNoticeTimed(type, message, ms) { renderNotice(type, message); if (ms && ms > 0) setTimeout(clearNotice, ms); }

  function renderNoticeHtml(type, html) {
    var el = noticeContainer();
    if (!el) { console.info('PPA:', type + ':', html); return; }
    var clsBase = 'ppa-notice', clsType = 'ppa-notice-' + type;
    el.className = clsBase + ' ' + clsType;
    el.innerHTML = String(html || '');
  }

  function renderNoticeTimedHtml(type, html, ms) { renderNoticeHtml(type, html); if (ms && ms > 0) setTimeout(clearNotice, ms); }

  function clearNotice() {
    var el = noticeContainer();
    if (el) { el.className = 'ppa-notice'; el.textContent = ''; }
  }

  function setButtonsDisabled(disabled) {
    [btnPreview, btnDraft, btnPublish].forEach(function (b) {
      if (!b) return;
      b.disabled = !!disabled;
      if (disabled) b.setAttribute('aria-busy', 'true'); else b.removeAttribute('aria-busy');
    });
  }

  // Lightweight extra debounce to avoid double-trigger before withBusy runs
  function clickGuard(btn) {
    if (!btn) return false;
    var ts = Number(btn.getAttribute('data-ppa-ts') || 0);
    var now = Date.now();
    if (now - ts < 350) return true;
    btn.setAttribute('data-ppa-ts', String(now));
    return false;
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
    if (nonce) {
      headers['X-PPA-Nonce'] = nonce;
      headers['X-WP-Nonce']  = nonce;
    }

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
          var body = ct.indexOf('application/json') !== -1 ? jsonTryParse(text) : jsonTryParse(text);
          return { ok: res.ok, status: res.status, body: body, raw: text, contentType: ct, headers: res.headers };
        });
      })
      .catch(function (err) {
        console.info('PPA: fetch error', err);
        return { ok: false, status: 0, body: { error: String(err) }, raw: '', contentType: '', headers: new Headers() };
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
    if (typeof body.raw === 'string') return body.raw;
    return '';
  }

  function pickMessage(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.message === 'string') return body.message;
    if (body.result && typeof body.result.message === 'string') return body.result.message;
    if (body.data && typeof body.data.message === 'string') return body.data.message; // wrap-aware
    if (body.data && body.data.result && typeof body.data.result.message === 'string') return body.data.result.message; // wrap-aware
    return '';
  }

  function pickId(body) {
    if (!body || typeof body !== 'object') return '';
    if (typeof body.id === 'string' || typeof body.id === 'number') return String(body.id);
    if (body.result && (typeof body.result.id === 'string' || typeof body.result.id === 'number')) return String(body.result.id);
    if (body.data && (typeof body.data.id === 'string' || typeof body.data.id === 'number')) return String(body.data.id);                    // wrap-aware
    if (body.data && body.data.result && (typeof body.data.result.id === 'string' || typeof body.data.result.id === 'number'))               // wrap-aware
      return String(body.data.result.id);                                                                                                     // wrap-aware
    return '';
  }

  function pickEditLink(body) {
    if (!body || typeof body !== 'object') return '';
    var v = '';
    if (typeof body.edit_link === 'string') v = body.edit_link;
    else if (body.result && typeof body.result.edit_link === 'string') v = body.result.edit_link;
    else if (body.data && typeof body.data.edit_link === 'string') v = body.data.edit_link;                     // wrap-aware
    else if (body.data && body.data.result && typeof body.data.result.edit_link === 'string')                    // wrap-aware
      v = body.data.result.edit_link;                                                                            // wrap-aware
    return String(v || '');
  }

  function pickViewLink(body) {                                              // CHANGED
    if (!body || typeof body !== 'object') return '';
    var cand = '';
    if (typeof body.view_link === 'string') cand = body.view_link;
    else if (typeof body.permalink === 'string') cand = body.permalink;
    else if (typeof body.link === 'string') cand = body.link;
    else if (body.result && typeof body.result.view_link === 'string') cand = body.result.view_link;
    else if (body.result && typeof body.result.permalink === 'string') cand = body.result.permalink;
    else if (body.result && typeof body.result.link === 'string') cand = body.result.link;
    else if (body.data && typeof body.data.permalink === 'string') cand = body.data.permalink;                   // wrap-aware
    else if (body.data && typeof body.data.link === 'string') cand = body.data.link;                             // wrap-aware
    else if (body.data && body.data.result && typeof body.data.result.permalink === 'string') cand = body.data.result.permalink; // wrap-aware
    else if (body.data && body.data.result && typeof body.data.result.link === 'string') cand = body.data.result.link;           // wrap-aware
    return String(cand || '');
  }

  function pickStructuredError(body) {
    if (!body || typeof body !== 'object') return null;
    if (body.error && typeof body.error === 'object') return body.error;
    if (body.data && body.data.error && typeof body.data.error === 'object') return body.data.error;
    return null;
  }

  // ---- Auto-fill helpers (Title/Excerpt/Slug) ------------------------------

  function getElTitle() { return $('#ppa-title') || $('#title'); }
  function getElExcerpt() { return $('#ppa-excerpt') || $('#excerpt'); }
  function getElSlug() { return $('#ppa-slug') || $('#post_name'); }

  function setIfEmpty(el, val) {
    if (!el) return;
    var cur = String(el.value || '').trim();
    if (!cur && val) el.value = String(val);
  }

  function autoFillAfterPreview(body, html) {
    function pickField(src, key) {
      if (!src || typeof src !== 'object') return '';
      if (typeof src[key] === 'string') return src[key];
      if (src.result && typeof src.result[key] === 'string') return src.result[key];
      if (src.data && typeof src.data[key] === 'string') return src.data[key];                    // wrap-aware
      if (src.data && src.data.result && typeof src.data.result[key] === 'string') return src.data.result[key]; // wrap-aware
      return '';
    }

    var title = pickField(body, 'title');
    var excerpt = pickField(body, 'excerpt');
    var slug = pickField(body, 'slug');

    if (!title) title = extractTitleFromHtml(html);
    if (!excerpt) excerpt = extractExcerptFromHtml(html);
    if (!slug && title) slug = sanitizeSlug(title);

    setIfEmpty(getElTitle(), title);
    setIfEmpty(getElExcerpt(), excerpt);
    setIfEmpty(getElSlug(), slug);

    console.info('PPA: autofill candidates →', { title: !!title, excerpt: !!excerpt, slug: !!slug });
  }

  // ---- Simple escaper for attribute values (URL) ---------------------------
  function escAttr(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // ---- Events --------------------------------------------------------------

  function handleRateLimit(res, which) {
    if (!res || res.status !== 429) return false;
    var retry = 0;
    var err = pickStructuredError(res.body);
    if (err && err.details && typeof err.details.retry_after === 'number') {
      retry = Math.max(0, Math.ceil(err.details.retry_after));
    }
    var btn = which === 'preview' ? btnPreview : which === 'draft' ? btnDraft : btnPublish;
    if (btn) btn.disabled = true;
    var sec = retry || 10;
    var t = setInterval(function(){
      renderNotice('warn', 'Rate-limited. Try again in ' + sec + 's.');
      if (--sec <= 0) {
        clearInterval(t);
        if (btn) btn.disabled = false;
        clearNotice();
      }
    }, 1000);
    return true;
  }

  if (btnPreview) {
    btnPreview.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (clickGuard(btnPreview)) return;
      console.info('PPA: Preview clicked');

      withBusy(function () {
        var payload = buildPreviewPayload();
        return apiPost('ppa_preview', payload).then(function (res) {
          if (handleRateLimit(res, 'preview')) return;

          var html = pickHtmlFromResponseBody(res.body);
          var serr = pickStructuredError(res.body);

          var provider = extractProviderFromHtml(html);
          console.info('PPA: provider=' + (provider || '(unknown)'));

          if (serr && !res.ok) {
            var msg = serr.message || 'Request failed.';
            renderNotice('error', '[' + (serr.type || 'error') + '] ' + msg);
            return;
          }

          if (html) {
            setPreview(html);
            clearNotice();
            autoFillAfterPreview(res.body, html);
            return;
          }

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
      if (clickGuard(btnDraft)) return;
      console.info('PPA: Save Draft clicked');

      // Early guard to prevent empty submissions with no preview
      var probe = buildStorePayload('draft');
      var hasPreviewHtml = !!document.getElementById('ppa-preview-pane')?.innerHTML.trim();
      if (!probe.title && !probe.content && !hasPreviewHtml) {
        renderNotice('warn', 'Add a title or run Preview before saving a draft.');
        return;
      }

      withBusy(function () {
        var payload = probe; // use built probe (already has preview fallback)
        return apiPost('ppa_store', payload).then(function (res) {
          if (handleRateLimit(res, 'draft')) return;
          var serr = pickStructuredError(res.body);
          var msg = (serr && serr.message) || pickMessage(res.body) || 'Draft request sent.';
          var pid = pickId(res.body);
          var edit = pickEditLink(res.body);  // wrap-aware
          var view = pickViewLink(res.body);  // wrap-aware
          if (!res.ok) {
            renderNotice('error', 'Draft failed (' + res.status + '): ' + msg);
            console.info('PPA: draft failed', res);
            return;
          }

          if (view || edit) {
            var pieces = [];
            if (view) pieces.push('<a href="' + escAttr(view) + '" target="_blank" rel="noopener">View Draft</a>');
            if (edit) pieces.push('<a href="' + escAttr(edit) + '" target="_blank" rel="noopener">Edit Draft</a>');
            var linkHtml = pieces.join(' &middot; ');
            var okHtml = 'Draft saved.' + (pid ? ' ID: ' + pid : '') + ' — ' + linkHtml;
            renderNoticeTimedHtml('success', okHtml, 8000);
          } else {
            var okMsg = 'Draft saved.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : '');
            renderNoticeTimed('success', okMsg, 4000);
          }
          console.info('PPA: draft success', res);
        });
      }, 'draft');
    });
  }

  if (btnPublish) {
    btnPublish.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (clickGuard(btnPublish)) return;
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
          if (handleRateLimit(res, 'publish')) return;
          var serr = pickStructuredError(res.body);
          var msg = (serr && serr.message) || pickMessage(res.body) || 'Publish request sent.';
          var pid = pickId(res.body);
          var edit = pickEditLink(res.body);
          var view = pickViewLink(res.body);
          if (!res.ok) {
            renderNotice('error', 'Publish failed (' + res.status + '): ' + msg);
            console.info('PPA: publish failed', res);
            return;
          }
          if (view || edit) {
            var parts = [];
            if (view) parts.push('<a href="' + escAttr(view) + '" target="_blank" rel="noopener">View</a>');
            if (edit) parts.push('<a href="' + escAttr(edit) + '" target="_blank" rel="noopener">Edit</a>');
            var html = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + ' — ' + parts.join(' &middot; ');
            renderNoticeTimedHtml('success', html, 8000);
          } else {
            var okMsg = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + (msg ? ' — ' + msg : '');
            renderNoticeTimed('success', okMsg, 4000);
          }
          console.info('PPA: publish success', res);
        });
      }, 'publish');
    });
  }

  console.info('PPA: admin.js initialized →', PPA_JS_VER);
})();
