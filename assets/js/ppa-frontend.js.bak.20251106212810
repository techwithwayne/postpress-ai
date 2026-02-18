// /home/customer/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/assets/js/ppa-frontend.js
/**
 * PostPress AI — Frontend JS for [postpress_ai_preview]
 *
 * ========= CHANGE LOG =========
 * 2025-11-03: New file. Progressive enhancement for shortcode container.                // CHANGED:
 *             - Finds .ppa-frontend wrappers and enhances form submit.                 // CHANGED:
 *             - Posts to admin-ajax.php?action=ppa_preview (JSON tolerant).            // CHANGED:
 *             - Renders returned HTML into .ppa-frontend__preview.                     // CHANGED:
 *             - Inline notices via .ppa-frontend__msg, no alerts, no inline CSS.       // CHANGED:
 *             - Zero exposure of PPA_SHARED_KEY (server-to-server only).               // CHANGED:
 * =====================================================================================
 */

(function () {
  'use strict';

  // ---- Utilities ----------------------------------------------------------- // CHANGED:
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }               // CHANGED:
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel) || []); } // CHANGED:

  function jsonTryParse(text) {                                                       // CHANGED:
    try { return JSON.parse(text); } catch (e) { return { raw: String(text || '') }; }
  }

  function pickHtml(body) {                                                           // CHANGED:
    if (!body || typeof body !== 'object') return '';
    if (typeof body.html === 'string') return body.html;
    if (body.result && typeof body.result.html === 'string') return body.result.html;
    if (body.data && typeof body.data.html === 'string') return body.data.html;
    if (typeof body.content === 'string') return body.content;
    if (typeof body.preview === 'string') return body.preview;
    if (typeof body.raw === 'string') return body.raw;
    return '';
  }

  function pickMessage(body) {                                                        // CHANGED:
    if (!body || typeof body !== 'object') return '';
    if (typeof body.message === 'string') return body.message;
    if (body.result && typeof body.result.message === 'string') return body.result.message;
    return '';
  }

  function renderNotice(wrapper, type, text) {                                        // CHANGED:
    var msg = $('.ppa-frontend__msg', wrapper);
    if (!msg) return;
    msg.setAttribute('data-type', String(type || 'info'));
    msg.textContent = String(text == null ? '' : text);
  }

  function setBusy(wrapper, busy) {                                                   // CHANGED:
    var btn = $('.ppa-frontend__btn', wrapper);
    if (btn) {
      btn.disabled = !!busy;
      if (busy) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy');
    }
  }

  function toNumber(v) {                                                              // CHANGED:
    var n = Number(v);
    return Number.isFinite(n) ? n : 0;
  }

  // ---- Core enhance -------------------------------------------------------- // CHANGED:
  function enhance(wrapper) {                                                         // CHANGED:
    if (!wrapper || wrapper.__ppaEnhanced) return;
    wrapper.__ppaEnhanced = true;

    var previewPane = $('.ppa-frontend__preview', wrapper);
    var form = $('.ppa-frontend__form-inner', wrapper);

    // Read defaults from data- attributes (author may prefill via shortcode attrs).   // CHANGED:
    var cfg = {
      ajaxurl: (wrapper.getAttribute('data-ppa-ajaxurl') || '').trim(),
      action: (wrapper.getAttribute('data-ppa-action') || 'ppa_preview').trim(),
      subject: (wrapper.getAttribute('data-ppa-subject') || '').trim(),
      brief: (wrapper.getAttribute('data-ppa-brief') || '').trim(),
      genre: (wrapper.getAttribute('data-ppa-genre') || '').trim(),
      tone: (wrapper.getAttribute('data-ppa-tone') || '').trim(),
      word_count: toNumber(wrapper.getAttribute('data-ppa-wordcount') || '0')
    };

    // Seed the form fields with defaults if present.                                   // CHANGED:
    if (form) {
      var fx = {
        subject: form.querySelector('[name="subject"]'),
        brief: form.querySelector('[name="brief"]'),
        genre: form.querySelector('[name="genre"]'),
        tone: form.querySelector('[name="tone"]'),
        word_count: form.querySelector('[name="word_count"]')
      };
      if (fx.subject && !fx.subject.value && cfg.subject) fx.subject.value = cfg.subject;
      if (fx.brief && !fx.brief.value && cfg.brief) fx.brief.value = cfg.brief;
      if (fx.genre && !fx.genre.value && cfg.genre) fx.genre.value = cfg.genre;
      if (fx.tone && !fx.tone.value && cfg.tone) fx.tone.value = cfg.tone;
      if (fx.word_count && !fx.word_count.value && cfg.word_count) fx.word_count.value = String(cfg.word_count);

      // Submit handler (AJAX)                                                            // CHANGED:
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        renderNotice(wrapper, 'info', '');
        setBusy(wrapper, true);

        var payload = {
          subject: fx.subject ? fx.subject.value : '',
          brief: fx.brief ? fx.brief.value : '',
          genre: fx.genre ? fx.genre.value : '',
          tone: fx.tone ? fx.tone.value : '',
          word_count: fx.word_count ? toNumber(fx.word_count.value || '0') : 0
        };

        var url = cfg.ajaxurl || (window.ajaxurl || '/wp-admin/admin-ajax.php');
        var qs = url.indexOf('?') === -1 ? '?' : '&';
        var endpoint = url + qs + 'action=' + encodeURIComponent(cfg.action || 'ppa_preview');

        fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(payload)
        })
          .then(function (res) {
            var ct = (res.headers.get('content-type') || '').toLowerCase();
            return res.text().then(function (text) {
              var body = ct.indexOf('application/json') !== -1 ? jsonTryParse(text) : jsonTryParse(text);
              return { ok: res.ok, status: res.status, body: body, raw: text, contentType: ct };
            });
          })
          .then(function (resp) {
            var html = pickHtml(resp.body);
            if (html && previewPane) {
              previewPane.innerHTML = html;
              renderNotice(wrapper, 'success', '');
            } else {
              renderNotice(wrapper, 'warn', 'Preview completed, but no HTML was returned.');
              if (previewPane) previewPane.innerHTML = '<p><em>No preview HTML returned.</em></p>';
            }
          })
          .catch(function (err) {
            console.info('PPA(frontend): fetch error', err);
            renderNotice(wrapper, 'error', 'There was an error while generating the preview.');
          })
          .finally(function () { setBusy(wrapper, false); });
      });
    }
  }

  // Enhance all existing shortcode wrappers on DOM ready.                               // CHANGED:
  function ready(fn) {                                                                  // CHANGED:
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  ready(function () {
    $all('.ppa-frontend').forEach(enhance);
    // In case of dynamically inserted content later, projects may re-run enhance(wrapper).
  });

  console.info('PPA(frontend): initialized');
})();
