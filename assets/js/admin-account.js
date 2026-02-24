/*
PostPress AI — Admin Account Screen (Isolated)

========= CHANGE LOG =========
2026-01-15: ADD: Account screen JS that fetches account/token/site details via WP AJAX (PPA_Controller proxy).

2026-01-25: FIX: Call the correct WP AJAX action (ppa_account_status) that bridges to Django /license/verify/.  // CHANGED:
           HARDEN: Parse license.v1 envelopes ({ok,data,error}) and render Plan/Sites/Tokens deterministically. // CHANGED:
           HARDEN: Always send nonce via header (X-PPA-Nonce) and add cache-busting _ts.                       // CHANGED:
           HARDEN: Discover nonce + ajaxurl from common plugin globals (ppaAdmin/PPA/PPAAccount/ajaxurl).       // CHANGED:
           FIX: Status text now updates even if the page markup has no .ppa-status__text span.                 // CHANGED:

2026-01-25: FIX: Prevent WP check_ajax_referer() 403 by sending nonce in ALL common POST fields               // CHANGED:
           (nonce, _ajax_nonce, _wpnonce, security, ppa_nonce) and include site fields in POST body.          // CHANGED:
           HARDEN: Detect 403 + "-1" responses and show clear "nonce failed" guidance.                        // CHANGED:
           HARDEN: In-flight guard prevents double requests (auto-refresh + click).                           // CHANGED:

2026-01-25: ADD: Enable/disable Account page action links from Django license.links.*                         // CHANGED:
           - upgrade, buy_tokens, billing_portal                                                              // CHANGED:
           - never hardcode URLs in WP; null/invalid keeps buttons disabled                                    // CHANGED:
           - prevent disabled link clicks                                                                     // CHANGED:

2026-01-26: FIX: Robust token parsing for license.v1 nested shapes + legacy flat keys (always updates UI).    // CHANGED:
           HARDEN: Force fresh fetch (cache bust in URL + body, and fetch cache: "no-store").                 // CHANGED:
           HARDEN: Throttled refresh on tab focus/visibility to reflect recent Generate Preview usage.        // CHANGED:

2026-01-26: FIX: Update missing UI IDs (ppa-tokens-remaining-total, ppa-sites-remaining).                     // CHANGED:
           FIX: Treat WP "-1" nonce failure even when HTTP 200 (common admin-ajax behavior).                  // CHANGED:
           HARDEN: When disabling links, also remove target/rel (prevents stale enabled behavior).            // CHANGED:

2026-02-22: FIX: POPUPS: Don’t rely on exact button IDs anymore. Use delegated click handling to always catch
                     Upgrade/Buy/Billing clicks and open the popup immediately (popup-safe).                  // CHANGED:
           FIX: POPUPS: Billing Portal always requests a fresh one-time URL via intent=billing_portal, even if
                     the button is currently disabled (server decides).                                       // CHANGED:

2026-02-22: FIX: SITES: Render Active/Site Limit/Remaining from license.sites.* (∞ when unlimited).          // CHANGED:
2026-02-23: ADD: Support Chat modal on Account page + WP AJAX action ppa_support_chat (server-side proxy to Django /support/chat/). // CHANGED:
*/

(function () {
  'use strict';

  var inflight = false;
  var lastFetchAt = 0;
  var lastPayload = null;

  function $(id) { return document.getElementById(id); }

  function num(v) {
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function boolish(v) {
    if (v === true) return true;
    if (v === false) return false;
    if (typeof v === 'number') return v === 1;
    if (typeof v === 'string') {
      var s = v.trim().toLowerCase();
      if (s === 'true' || s === '1' || s === 'yes' || s === 'y') return true;
      if (s === 'false' || s === '0' || s === 'no' || s === 'n') return false;
    }
    return null;
  }

  function fmtInt(v) {
    var n = num(v);
    if (n === null) return '—';
    return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function clamp01(v) {
    if (v < 0) return 0;
    if (v > 1) return 1;
    return v;
  }

  function toSafeStr(v) {
    if (v === null || v === undefined) return '';
    try { return String(v); } catch (e) { return ''; }
  }

  function firstDefined(list) {
    if (!Array.isArray(list)) return null;
    for (var i = 0; i < list.length; i++) {
      var v = list[i];
      if (v === null || v === undefined) continue;
      if (typeof v === 'string' && v.trim() === '') continue;
      return v;
    }
    return null;
  }

  function setText(id, value) {
    var el = $(id);
    if (!el) return;
    el.textContent = (value === null || value === undefined || value === '') ? '—' : String(value);
  }

  function setTextAny(ids, value) {
    if (!Array.isArray(ids)) return;
    for (var i = 0; i < ids.length; i++) {
      setText(ids[i], value);
    }
  }

  function setStatus(type, msg) {
    var el = $('ppa-account-status');
    if (!el) return;

    el.classList.remove('is-good', 'is-bad');
    if (type === 'good') el.classList.add('is-good');
    if (type === 'bad') el.classList.add('is-bad');

    var text = msg || '—';
    var t = el.querySelector('.ppa-status__text');
    if (t) t.textContent = text;
    else el.textContent = text;
  }

  function parseMaybeDate(v) {
    if (!v) return null;
    if (typeof v === 'string') {
      var s = v.trim();
      if (!s) return null;
      var d = new Date(s);
      return isNaN(d.getTime()) ? null : d;
    }
    if (typeof v === 'number') {
      var d2 = new Date(v);
      return isNaN(d2.getTime()) ? null : d2;
    }
    return null;
  }

  function fmtDateShort(d) {
    if (!d || isNaN(d.getTime())) return '';
    try {
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (e) {
      return d.toDateString();
    }
  }

  function formatPeriodLabel(periodObj) {
    if (!periodObj || typeof periodObj !== 'object') return '—';
    var start = parseMaybeDate(periodObj.start);
    var end = parseMaybeDate(periodObj.end);
    if (!start || !end) return '—';
    var a = fmtDateShort(start);
    var b = fmtDateShort(end);
    if (!a || !b) return '—';
    return a + ' – ' + b;
  }

  function dig(obj, path) {
    if (!obj || typeof obj !== 'object') return null;
    if (!path) return null;
    var cur = obj;
    var segs = String(path).split('.');
    for (var i = 0; i < segs.length; i++) {
      var k = segs[i];
      if (!cur || typeof cur !== 'object' || !(k in cur)) return null;
      cur = cur[k];
    }
    return cur;
  }

  function withTs(url) {
    var base = toSafeStr(url);
    if (!base) return base;
    var ts = String(Date.now());
    try {
      var u = new URL(base, window.location.href);
      u.searchParams.set('_ts', ts);
      return u.toString();
    } catch (e) {
      return base + (base.indexOf('?') === -1 ? '?' : '&') + '_ts=' + encodeURIComponent(ts);
    }
  }

  // ----------------------------
  // Sites list
  // ----------------------------
  function renderSites(list) {
    var wrap = $('ppa-sites-list');
    if (!wrap) return;

    var arr = Array.isArray(list) ? list : [];
    wrap.innerHTML = '';

    if (!arr.length) {
      var empty = document.createElement('div');
      empty.className = 'ppa-list__empty';
      empty.textContent = 'No activated sites returned.';
      wrap.appendChild(empty);
      return;
    }

    arr.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'ppa-list__row';

      var left = document.createElement('div');
      left.className = 'ppa-list__left';
      left.textContent = (item && (item.url || item.domain || item.site_url)) ? String(item.url || item.domain || item.site_url) : '—';

      var right = document.createElement('div');
      right.className = 'ppa-list__right';
      right.textContent = (item && item.status) ? String(item.status) : '';

      row.appendChild(left);
      row.appendChild(right);
      wrap.appendChild(row);
    });
  }

  // ----------------------------
  // Link enabling (Django authoritative)
  // ----------------------------
  function isHttpUrl(v) {
    var s = toSafeStr(v).trim();
    return !!s && /^https?:\/\//i.test(s);
  }

  function setLinkEnabled(el, href) {
    if (!el) return;
    var url = toSafeStr(href).trim();
    if (isHttpUrl(url)) {
      el.setAttribute('href', url);
      el.setAttribute('target', '_blank');
      el.setAttribute('rel', 'noopener');
      el.classList.remove('is-disabled');
      el.removeAttribute('aria-disabled');
      el.removeAttribute('tabindex');
      return;
    }
    el.setAttribute('href', '#');
    el.classList.add('is-disabled');
    el.setAttribute('aria-disabled', 'true');
    el.setAttribute('tabindex', '-1');
    el.removeAttribute('target');
    el.removeAttribute('rel');
  }

  function applyLinksFromLicense(licenseObj) {
    if (!licenseObj || typeof licenseObj !== 'object') return;

    var links = (licenseObj.links && typeof licenseObj.links === 'object') ? licenseObj.links : {};
    var upgrade = links.upgrade || links.upgrade_url || null;
    var buyTokens = links.buy_tokens || links.buyTokens || links.purchase || null;
    var billingPortal = links.billing_portal || links.billingPortal || links.portal || null;

    setLinkEnabled($('ppa-account-upgrade'), upgrade);
    setLinkEnabled($('ppa-account-buy-tokens'), buyTokens);
    setLinkEnabled($('ppa-account-billing-portal'), billingPortal);
  }

  // ----------------------------
  // Popup helpers
  // ----------------------------
  function openSizedPopup(name, preferredW, preferredH) {
    var availW = (window.screen && window.screen.availWidth) ? window.screen.availWidth : 1200;
    var availH = (window.screen && window.screen.availHeight) ? window.screen.availHeight : 900;

    var w = Math.min(Number(preferredW) || 980, Math.max(520, availW - 60));
    var h = Math.min(Number(preferredH) || 780, Math.max(520, availH - 120));

    var left = Math.max(0, Math.round((availW - w) / 2));
    var top = Math.max(0, Math.round((availH - h) / 2));

    var features =
      'popup=yes' +
      ',width=' + w +
      ',height=' + h +
      ',left=' + left +
      ',top=' + top +
      ',resizable=yes,scrollbars=yes';

    var win = window.open('about:blank', name, features);
    return win || null;
  }

  function writePopupLoading(win, label) {
    if (!win) return;
    try {
      win.document.title = 'PostPress AI';
      win.document.body.innerHTML =
        '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;padding:18px;">' +
        '<div style="font-size:14px;opacity:.85;">PostPress AI</div>' +
        '<div style="margin-top:10px;font-size:18px;font-weight:600;">Loading…</div>' +
        '<div style="margin-top:8px;font-size:13px;opacity:.8;">' + (label || '') + '</div>' +
        '</div>';
    } catch (e) {}
  }

  function extractLinkFromPayload(payload, intent, fallbackHref) {
    var p = payload && typeof payload === 'object' ? payload : null;
    if (!p) return isHttpUrl(fallbackHref) ? fallbackHref : null;

    var core = (p.data && typeof p.data === 'object') ? p.data :
               (p.result && typeof p.result === 'object') ? p.result :
               p;

    var license = (core.license && typeof core.license === 'object') ? core.license :
                  (core.license_snapshot && typeof core.license_snapshot === 'object') ? core.license_snapshot :
                  (core.lic && typeof core.lic === 'object') ? core.lic :
                  core;

    var links = (license.links && typeof license.links === 'object') ? license.links : {};

    var url = null;
    if (intent === 'billing_portal') url = links.billing_portal || links.billingPortal || links.portal || null;
    if (intent === 'upgrade') url = links.upgrade || links.upgrade_url || null;
    if (intent === 'buy_tokens') url = links.buy_tokens || links.buyTokens || links.purchase || null;

    if (!url) url = core.url || core.session_url || core.portal_url || null;
    if (!url && isHttpUrl(fallbackHref)) url = fallbackHref;

    return isHttpUrl(url) ? String(url) : null;
  }

  // ----------------------------
  // Config + AJAX
  // ----------------------------
  function bestConfig() {
    var cfg = window.PPAAccount || {};

    var ajaxUrl =
      cfg.ajaxUrl ||
      cfg.ajaxurl ||
      (typeof ajaxurl !== 'undefined' ? ajaxurl : '') ||
      window.ajaxurl ||
      '';

    var nonce =
      cfg.nonce ||
      cfg.wpNonce ||
      cfg._wpnonce ||
      (window.ppaAdmin && (window.ppaAdmin.nonce || window.ppaAdmin.wpNonce || window.ppaAdmin._wpnonce)) ||
      (window.PPA && (window.PPA.nonce || window.PPA.wpNonce || window.PPA._wpnonce)) ||
      '';

    if (!nonce) {
      var nEl = $('ppa-account-nonce');
      if (nEl && nEl.value) nonce = String(nEl.value);
    }

    var site = cfg.site || '';
    var sEl = $('ppa-account-site');
    if (!site && sEl && sEl.value) site = String(sEl.value);

    var action = cfg.action || 'ppa_account_status';
    return { ajaxUrl: ajaxUrl, nonce: nonce, site: site, action: action };
  }

  function addNonceFields(form, nonce) {
    var n = toSafeStr(nonce);
    if (!n) return;
    form.set('nonce', n);
    form.set('_ajax_nonce', n);
    form.set('_wpnonce', n);
    form.set('security', n);
    form.set('ppa_nonce', n);
  }

  function addSiteFields(form, site) {
    var s = toSafeStr(site);
    if (!s) return;
    form.set('site', s);
    form.set('site_url', s);
    form.set('domain', s);
  }

  function shouldAutoRefresh() {
    return (Date.now() - lastFetchAt) > 15000;
  }

  async function postAjax(extraParams, affectUi, actionOverride) {
    var cfg = bestConfig();

    if (!cfg.ajaxUrl || !cfg.nonce) {
      if (affectUi) setStatus('bad', 'Account config missing.');
      return null;
    }

    var form = new URLSearchParams();
    var action = actionOverride || cfg.action;
    form.set('action', action);
    form.set('_ts', String(Date.now()));
    addNonceFields(form, cfg.nonce);
    addSiteFields(form, cfg.site);

    if (extraParams && typeof extraParams === 'object') {
      for (var k in extraParams) {
        if (!Object.prototype.hasOwnProperty.call(extraParams, k)) continue;
        form.set(k, String(extraParams[k]));
      }
    }

    var ajaxUrl = withTs(cfg.ajaxUrl);

    try {
      var resp = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json, text/plain, */*',
          'Cache-Control': 'no-cache, no-store, max-age=0',
          'Pragma': 'no-cache',
          'Expires': '0',
          'X-PPA-Nonce': cfg.nonce,
          'X-WP-Nonce': cfg.nonce,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: form.toString()
      });

      var text = await resp.text();
      var trimmed = (text || '').trim();

      if (trimmed === '-1') {
        if (affectUi) setStatus('bad', 'Nonce failed. Reload this page, then try again.');
        return null;
      }

      if (trimmed === '0') {
        try {
          window.__ppaMissingActions = window.__ppaMissingActions || {};
          window.__ppaMissingActions[action] = true;
        } catch (e0) {}

        if (affectUi) setStatus('bad', 'Handler missing. Refresh the page, then try again.');
        return null;
      }

      var json = null;
      try { json = JSON.parse(text); } catch (e) { json = null; }

      if (!json) {
        if (affectUi) setStatus('bad', 'Could not refresh (non-JSON response).');
        return null;
      }

      if (json && typeof json === 'object' && 'success' in json) {
        if (json.success && json.data) return json.data;
        if (affectUi) {
          var errMsg = (json.data && (json.data.message || json.data.error)) ? (json.data.message || json.data.error) : '';
          setStatus('bad', errMsg || 'Account request failed.');
        }
        return null;
      }

      return json;
    } catch (e2) {
      if (affectUi) setStatus('bad', 'Could not refresh (network / JSON error).');
      return null;
    }
  }

  async function fetchAccount(force) {
    if (inflight) return null;
    if (!force && !shouldAutoRefresh() && lastFetchAt > 0) return null;

    inflight = true;
    lastFetchAt = Date.now();
    setStatus('', 'Refreshing…');

    var payload = null;

    // CHANGED: Prefer Support account_status if available (server-side shared secret), but fall back
    // to the legacy account_status bridge (license/verify) to keep the Account page stable.
    var supportAction = 'ppa_support_account_status';
    var missingSupport = false;
    try {
      missingSupport = !!(window.__ppaMissingActions && window.__ppaMissingActions[supportAction]);
    } catch (e0) { missingSupport = false; }

    var supportPayload = null;
    if (!missingSupport) {
      supportPayload = await postAjax(null, false, supportAction);
      if (supportPayload && typeof supportPayload === 'object' && supportPayload.ok === true) {
        payload = supportPayload;
      }
    }

    if (!payload) {
      payload = await postAjax(null, true);
    }

    // If both failed but Support gave us an error envelope, prefer showing that rather than nothing.
    if (!payload && supportPayload && typeof supportPayload === 'object') {
      payload = supportPayload;
    }

    if (payload) {
      lastPayload = payload;
      renderFromData(payload);
    }

    inflight = false;
    return payload;
  }

  async function fetchIntent(intent) {
    // Intent fetch does NOT depend on inflight; popups must work even during auto-refresh.
    return await postAjax({ intent: intent }, false);
  }

  // ----------------------------
  // Render (same behavior as before)
  // ----------------------------
  function renderFromData(data) {
    if (!data || typeof data !== 'object') {
      setStatus('bad', 'Account data missing.');
      return;
    }

    var envelopeOk = (typeof data.ok === 'boolean') ? data.ok : null;
    var envelopeErr = (data.error && typeof data.error === 'object') ? data.error : null;

    var core = (data.data && typeof data.data === 'object') ? data.data :
               (data.result && typeof data.result === 'object') ? data.result :
               data;

    var license = (core.license && typeof core.license === 'object') ? core.license :
                  (core.license_snapshot && typeof core.license_snapshot === 'object') ? core.license_snapshot :
                  (core.lic && typeof core.lic === 'object') ? core.lic :
                  core;

    applyLinksFromLicense(license);

    var activation = (core.activation && typeof core.activation === 'object') ? core.activation : {};

    var licStatus = String(license.status || '').toLowerCase();
    var activated = (activation.activated === true);

    if (licStatus === 'active' && activated && envelopeOk === true) {
      setStatus('good', 'Account synced.');
    } else if (envelopeOk === false) {
      var msg = '';
      if (envelopeErr) msg = envelopeErr.message || envelopeErr.code || '';
      msg = msg || data.message || data.error || 'Account check failed.';
      setStatus('bad', String(msg));
    } else {
      setStatus('', 'Account loaded.');
    }

    // Plan (avoid [object Object])
    var planName = '—';
    if (license.plan && typeof license.plan === 'object') {
      planName = license.plan.label || license.plan.name || '—';
    } else {
      planName = license.plan_slug || license.plan || '—';
    }
    setText('ppa-plan-name', planName);

    var billingEmail = dig(core, 'account.email') || dig(core, 'account.billing_email') || license.email || license.billing_email || '';
    setText('ppa-billing-email', billingEmail || '—');

    // Tokens
    var tokens = (license.tokens && typeof license.tokens === 'object') ? license.tokens :
                 (core.tokens && typeof core.tokens === 'object') ? core.tokens :
                 {};

    var periodObj = null;
    if (tokens.period && typeof tokens.period === 'object') {
      periodObj = tokens.period;
    } else {
      var ps = firstDefined([tokens.period_start, tokens.start]);
      var pe = firstDefined([tokens.period_end, tokens.end]);
      if (ps || pe) periodObj = { start: ps || null, end: pe || null };
    }

    var periodLabel = firstDefined([tokens.period_label, tokens.period]);
    if (periodObj) periodLabel = formatPeriodLabel(periodObj);
    setText('ppa-tokens-period', periodLabel || '—');

    var used = num(firstDefined([tokens.monthly_used, tokens.used, tokens.usage]));
    var limit = num(firstDefined([tokens.monthly_limit, tokens.limit, tokens.cap]));

    var remainingMonthly = num(firstDefined([tokens.monthly_remaining, tokens.remaining_monthly]));
    if (remainingMonthly === null && used !== null && limit !== null && limit > 0) remainingMonthly = Math.max(0, limit - used);

    var remainingTotal = num(firstDefined([tokens.remaining_total, tokens.remainingTotal]));

    setText('ppa-tokens-used', (used !== null) ? (fmtInt(used) + ' used') : '—');
    setText('ppa-tokens-limit', (limit !== null) ? (fmtInt(limit) + ' / month') : '—');
    setText('ppa-tokens-remaining', (remainingMonthly !== null) ? (fmtInt(remainingMonthly) + ' remaining') : '—');
    setText('ppa-tokens-remaining-total', (remainingTotal !== null) ? (fmtInt(remainingTotal) + ' total') : '—');

    var bar = $('ppa-tokens-bar');
    if (bar) {
      if (used !== null && limit !== null && limit > 0) {
        var pct = clamp01(used / limit);
        bar.style.width = String(Math.round(pct * 100)) + '%';
      } else {
        bar.style.width = '0%';
      }
    }

    // Sites (counts + list)
    var sites = (license.sites && typeof license.sites === 'object') ? license.sites : {};

    var sitesUnlimited = boolish(firstDefined([
      sites.unlimited,
      license.unlimited_sites,
      license.unlimitedSites,
      license.unlimited,
      license.unlimited_site,
      license.site_unlimited,
      license.sites_unlimited,
      license.sitesUnlimited
    ]));
    if (sitesUnlimited === null) sitesUnlimited = false;

    var sitesUsed = num(firstDefined([
      sites.used,
      license.sites_used,
      license.sitesUsed,
      license.sites_used_count,
      license.sitesUsedCount,
      license.sites_used_total,
      license.sitesUsedTotal
    ]));
    if (sitesUsed === null && Array.isArray(sites.list)) sitesUsed = sites.list.length;

    var sitesMax = num(firstDefined([
      sites.max,
      license.max_sites,
      license.maxSites,
      license.site_limit,
      license.siteLimit,
      license.sites_max,
      license.sitesMax
    ]));

    var sitesRemaining = num(firstDefined([
      sites.remaining,
      license.sites_remaining,
      license.sitesRemaining,
      license.remaining_sites,
      license.remainingSites
    ]));

    if (!sitesUnlimited) {
      if (sitesRemaining === null && sitesUsed !== null && sitesMax !== null) {
        sitesRemaining = Math.max(0, sitesMax - sitesUsed);
      }
    }

    var sitesUsedDisplay = (sitesUsed !== null) ? fmtInt(sitesUsed) : '—';
    var sitesMaxDisplay = sitesUnlimited ? '∞' : ((sitesMax !== null) ? fmtInt(sitesMax) : '—');
    var sitesRemainingDisplay = sitesUnlimited ? '∞' : ((sitesRemaining !== null) ? fmtInt(sitesRemaining) : '—');

    // These IDs are expected on the Account screen. We set multiple variants for back-compat.
    setTextAny(['ppa-sites-used', 'ppa-active-sites', 'ppa-sites-active'], sitesUsedDisplay);
    setTextAny(['ppa-sites-limit', 'ppa-site-limit', 'ppa-sites-max'], sitesMaxDisplay);
    setTextAny(['ppa-sites-remaining', 'ppa-remaining-sites', 'ppa-sites-left'], sitesRemainingDisplay);

    var list = Array.isArray(sites.list) ? sites.list : (Array.isArray(core.sites_list) ? core.sites_list : []);
    if (!list.length && activation && activation.site_url) {
      list = [{ url: activation.site_url, status: activation.activated ? 'activated' : 'not activated' }];
    }
    renderSites(list);
  }

  // ----------------------------
  // POPUPS: delegated click binding (ID mismatch proof)
  // ----------------------------
  function normalizeText(s) {
    return toSafeStr(s).replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function detectIntent(el) {
    if (!el) return null;

    var id = normalizeText(el.id || '');
    var dt =
      normalizeText(el.getAttribute('data-ppa-intent') || '') ||
      normalizeText(el.getAttribute('data-intent') || '') ||
      normalizeText(el.getAttribute('data-action') || '');

    if (dt) {
      if (dt.indexOf('billing') >= 0 || dt.indexOf('portal') >= 0) return 'billing_portal';
      if (dt.indexOf('upgrade') >= 0) return 'upgrade';
      if (dt.indexOf('buy') >= 0 || dt.indexOf('token') >= 0) return 'buy_tokens';
    }

    if (id.indexOf('billing') >= 0 || id.indexOf('portal') >= 0) return 'billing_portal';
    if (id.indexOf('upgrade') >= 0) return 'upgrade';
    if (id.indexOf('buy') >= 0 || id.indexOf('token') >= 0) return 'buy_tokens';

    var txt = normalizeText(el.textContent || '');
    if (txt.indexOf('billing portal') >= 0) return 'billing_portal';
    if (txt.indexOf('upgrade plan') >= 0 || txt === 'upgrade') return 'upgrade';
    if (txt.indexOf('buy tokens') >= 0 || (txt.indexOf('buy') >= 0 && txt.indexOf('token') >= 0)) return 'buy_tokens';

    return null;
  }

  function getHref(el) {
    if (!el) return '';
    var href = '';
    if (el.tagName === 'A') href = el.getAttribute('href') || '';
    if (!href) href = el.getAttribute('data-href') || el.getAttribute('data-url') || '';
    return toSafeStr(href).trim();
  }

  function inActionsArea(el) {
    if (!el) return false;
    // best-effort scoping so we don’t catch unrelated admin clicks
    return !!el.closest('#ppa-account-actions, .ppa-actions, .ppa-account-actions, .ppa-card--actions, .ppa-actions-card, #ppa-actions');
  }

  function intentLabel(intent) {
    if (intent === 'billing_portal') return 'Opening billing portal…';
    if (intent === 'upgrade') return 'Opening upgrade…';
    return 'Opening token purchase…';
  }

  function openIntentPopup(intent, fallbackHref) {
    var popup = openSizedPopup('ppa_' + intent, 980, 780);
    if (!popup) {
      setStatus('bad', 'Popup blocked. Please allow popups for this site, then try again.');
      return;
    }

    writePopupLoading(popup, intentLabel(intent));

    // Always try an intent fetch first (server decides what’s allowed).
    fetchIntent(intent).then(function (payload) {
      var url = extractLinkFromPayload(payload || lastPayload, intent, fallbackHref);
      if (!url) {
        try { popup.close(); } catch (e) {}
        setStatus('bad', 'Link not available yet. Hit Refresh, then try again.');
        return;
      }
      try {
        popup.location.href = url;
        popup.focus();
      } catch (e2) {
        setStatus('bad', 'Could not open popup. Please allow popups, then try again.');
      }
    });
  }

  function bindDelegatedPopupsOnce() {
    if (document.__ppaAccountDelegatedBound) return;
    document.__ppaAccountDelegatedBound = true;

    document.addEventListener('click', function (e) {
      var target = e.target;
      if (!target) return;

      var clickable = target.closest('a,button');
      if (!clickable) return;

      var intent = detectIntent(clickable);
      if (!intent) return;

      // Scope: prefer ACTIONS area; but allow exact known IDs if present
      var id = normalizeText(clickable.id || '');
      var isKnownId = (id === 'ppa-account-upgrade' || id === 'ppa-account-buy-tokens' || id === 'ppa-account-billing-portal');
      if (!isKnownId && !inActionsArea(clickable)) return;

      // Prevent default navigation and stop bubbling
      try { e.preventDefault(); e.stopPropagation(); } catch (err) {}

      // If it’s visually disabled, we STILL attempt intent fetch (server will deny if not allowed).
      var href = getHref(clickable);

      openIntentPopup(intent, href);
    }, true);
  }


  // ----------------------------
  // Support Chat (WP AJAX -> PHP proxy -> Django)
  // ----------------------------
  function ensureSupportChatUI() {
    // Create a Support Chat button next to the Refresh button (Account page header).
    var refreshBtn = $('ppa-account-refresh');
    var existing = $('ppa-support-chat-open');

    if (!existing) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'ppa-support-chat-open';
      btn.className = 'button';
      btn.textContent = 'Support Chat';

      if (refreshBtn && refreshBtn.parentNode) {
        // Insert right after Refresh
        if (refreshBtn.nextSibling) refreshBtn.parentNode.insertBefore(btn, refreshBtn.nextSibling);
        else refreshBtn.parentNode.appendChild(btn);
      } else {
        // Fallback: try actions area, else append to body (rare edge cases)
        var actions = document.querySelector('#ppa-account-actions, .ppa-actions, .ppa-account-actions, .ppa-card--actions, .ppa-actions-card, #ppa-actions');
        if (actions) actions.appendChild(btn);
        else document.body.appendChild(btn);
      }
    }

    // Modal container (once)
    if (!$('ppa-support-chat-modal')) {
      var modal = document.createElement('div');
      modal.id = 'ppa-support-chat-modal';
      modal.setAttribute('aria-hidden', 'true');
      modal.style.display = 'none';

      modal.innerHTML =
        '<div class="ppa-support-chat__backdrop" data-ppa-chat-close="1"></div>' +
        '<div class="ppa-support-chat__panel" role="dialog" aria-modal="true" aria-label="PostPress AI Support Chat">' +
          '<div class="ppa-support-chat__head">' +
            '<div class="ppa-support-chat__title">Support Chat</div>' +
            '<button type="button" class="button" id="ppa-support-chat-close" data-ppa-chat-close="1">Close</button>' +
          '</div>' +
          '<div class="ppa-support-chat__log" id="ppa-support-chat-log" aria-live="polite"></div>' +
          '<div class="ppa-support-chat__composer">' +
            '<textarea id="ppa-support-chat-input" rows="2" placeholder="Type your message…"></textarea>' +
            '<button type="button" class="button button-primary" id="ppa-support-chat-send">Send</button>' +
          '</div>' +
          '<div class="ppa-support-chat__hint">Note: no secrets are sent from your browser. This goes WP → server → Django.</div>' +
        '</div>';

      document.body.appendChild(modal);
      ensureSupportChatStyles();
    }

    // Seed a hello once (only if empty)
    var log = $('ppa-support-chat-log');
    if (log && !log.__ppaSeeded) {
      log.__ppaSeeded = true;
      appendChatLine('agent', 'Hi, Yukia here. What’s going on?');
      chatState.hasGreeting = true;
    }
  }

  function ensureSupportChatStyles() {
    if (document.getElementById('ppa-support-chat-style')) return;

    var css =
      '#ppa-support-chat-modal{position:fixed;inset:0;z-index:100000;}' +
      '#ppa-support-chat-modal .ppa-support-chat__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55);}' +
      '#ppa-support-chat-modal .ppa-support-chat__panel{position:absolute;right:18px;bottom:18px;width:min(520px, calc(100vw - 36px));max-height:min(720px, calc(100vh - 36px));background:#0f0f10;border:1px solid rgba(255,255,255,.08);border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.55);display:flex;flex-direction:column;overflow:hidden;}' +
      '#ppa-support-chat-modal .ppa-support-chat__head{display:flex;align-items:center;justify-content:space-between;padding:12px 12px;border-bottom:1px solid rgba(255,255,255,.08);}' +
      '#ppa-support-chat-modal .ppa-support-chat__title{font-size:14px;font-weight:700;color:#f2f2f2;}' +
      '#ppa-support-chat-modal .ppa-support-chat__log{padding:12px;display:flex;flex-direction:column;gap:10px;overflow:auto;flex:1;}' +
      '#ppa-support-chat-modal .ppa-support-chat__composer{display:flex;gap:10px;padding:12px;border-top:1px solid rgba(255,255,255,.08);}' +
      '#ppa-support-chat-modal textarea{flex:1;resize:none;min-height:42px;max-height:140px;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151518;color:#f2f2f2;}' +
      '#ppa-support-chat-modal .ppa-support-chat__hint{padding:10px 12px;font-size:12px;opacity:.8;color:#e9e9e9;border-top:1px solid rgba(255,255,255,.06);}' +
      '#ppa-support-chat-modal .ppa-chatline{display:flex;}' +
      '#ppa-support-chat-modal .ppa-chatline--user{justify-content:flex-end;}' +
      '#ppa-support-chat-modal .ppa-chatline__bubble{max-width:88%;padding:10px 12px;border-radius:12px;font-size:13px;line-height:1.35;white-space:pre-wrap;word-break:break-word;}' +
      '#ppa-support-chat-modal .ppa-chatline--user .ppa-chatline__bubble{background:#2b2b30;color:#fff;border:1px solid rgba(255,255,255,.10);}' +
      '#ppa-support-chat-modal .ppa-chatline--agent .ppa-chatline__bubble{background:#121214;color:#f2f2f2;border:1px solid rgba(255,255,255,.08);}' +
      '#ppa-support-chat-modal .ppa-chatline--system .ppa-chatline__bubble{background:transparent;color:#d9d9d9;border:1px dashed rgba(255,255,255,.20);opacity:.95;}' +
      '#ppa-support-chat-modal .ppa-chatline__meta{font-size:11px;opacity:.7;margin-top:4px;}' +
      '#ppa-support-chat-modal .ppa-chatline__wrap{display:flex;flex-direction:column;}' +
      '#ppa-support-chat-modal .ppa-chatbusy{opacity:.6;pointer-events:none;}' +
      '@media (max-width:640px){#ppa-support-chat-modal .ppa-support-chat__panel{right:10px;left:10px;bottom:10px;width:auto;}}';

    var style = document.createElement('style');
    style.id = 'ppa-support-chat-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  var chatState = {
    open: false,
    busy: false,
    threadId: null,
    hasGreeting: false
  };

  function showSupportChat(open) {
    var modal = $('ppa-support-chat-modal');
    if (!modal) return;

    chatState.open = !!open;
    modal.style.display = chatState.open ? 'block' : 'none';
    modal.setAttribute('aria-hidden', chatState.open ? 'false' : 'true');

    if (chatState.open) {
      var input = $('ppa-support-chat-input');
      if (input) {
        setTimeout(function () { try { input.focus(); } catch (e) {} }, 40);
      }
      scrollChatToBottom();
    }
  }

  function scrollChatToBottom() {
    var log = $('ppa-support-chat-log');
    if (!log) return;
    try { log.scrollTop = log.scrollHeight + 9999; } catch (e) {}
  }

  function setChatBusy(isBusy) {
    chatState.busy = !!isBusy;
    var modal = $('ppa-support-chat-modal');
    if (!modal) return;
    if (chatState.busy) modal.classList.add('ppa-chatbusy');
    else modal.classList.remove('ppa-chatbusy');

    var send = $('ppa-support-chat-send');
    var input = $('ppa-support-chat-input');
    if (send) send.disabled = chatState.busy;
    if (input) input.disabled = chatState.busy;
  }

  // CHANGED: Agent output sanitizer (no Markdown tokens; avoid repeated "Yukia here" intro)
  function stripMarkdownLite(s) {
    var t = toSafeStr(s);
    if (!t) return '';
    // Remove common Markdown formatting markers but keep the words.
    t = t.replace(/\*\*/g, '').replace(/__/g, '').replace(/`/g, '');
    // Convert markdown links: [text](url) -> text (url)
    t = t.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1 ($2)');
    return t;
  }

  function stripRedundantAgentIntro(s) {
    var t = toSafeStr(s).trim();
    if (!t) return '';
    if (chatState && chatState.hasGreeting) {
      // If we've already greeted, don’t re-introduce on every reply.
      t = t.replace(/^yukia\s+here\s*(?:—|-|:|,)\s*/i, '');
      t = t.replace(/^yukia\s+here\.\s*/i, '');
    }
    return t;
  }

  function sanitizeAgentText(s) {
    var t = stripMarkdownLite(s);
    t = stripRedundantAgentIntro(t);
    return t;
  }

  function appendChatLine(kind, text) {
    var log = $('ppa-support-chat-log');
    if (!log) return;

    var k = (kind === 'user' || kind === 'agent' || kind === 'system') ? kind : 'system';
    var line = document.createElement('div');
    line.className = 'ppa-chatline ppa-chatline--' + k;

    var wrap = document.createElement('div');
    wrap.className = 'ppa-chatline__wrap';

    var bubble = document.createElement('div');
    bubble.className = 'ppa-chatline__bubble';
    var out = toSafeStr(text) || '—';

    if (k === 'agent') out = sanitizeAgentText(out);

    bubble.textContent = out || '—';

    wrap.appendChild(bubble);
    line.appendChild(wrap);
    log.appendChild(line);

    scrollChatToBottom();
  }

  function extractChatCore(payload) {
    var p = payload && typeof payload === 'object' ? payload : null;
    if (!p) return null;
    if (p.data && typeof p.data === 'object') return p.data;
    if (p.result && typeof p.result === 'object') return p.result;
    return p;
  }

  function extractChatThreadId(core) {
    if (!core || typeof core !== 'object') return null;
    var v = core.thread_id || core.threadId || core.thread || core.session_id || core.sessionId || null;
    v = toSafeStr(v).trim();
    return v ? v : null;
  }

  function extractChatReply(core) {
    if (!core) return null;
    if (typeof core === 'string') return core;
    if (typeof core !== 'object') return null;

    var v =
      core.reply ||
      core.response ||
      core.message ||
      (core.data && (core.data.reply || core.data.response || core.data.message)) ||
      (core.output && (core.output.reply || core.output.text)) ||
      core.text ||
      null;

    v = toSafeStr(v).trim();
    return v ? v : null;
  }

  function extractChatError(payload) {
    var p = payload && typeof payload === 'object' ? payload : null;
    if (!p) return 'Support chat failed.';
    if (p.error) {
      if (typeof p.error === 'string') return p.error;
      if (p.error && typeof p.error === 'object') return p.error.message || p.error.code || 'Support chat failed.';
    }
    if (p.message) return toSafeStr(p.message);
    if (p.data && p.data.error) return toSafeStr(p.data.error);
    return 'Support chat failed.';
  }

  async function sendSupportChatMessage(message) {
    var msg = toSafeStr(message).trim();
    if (!msg) return;

    // Browser sends ONLY: message + thread_id (optional). No shared secret ever leaves the server.
    var params = { message: msg };
    if (chatState.threadId) params.thread_id = chatState.threadId;

    setChatBusy(true);

    // IMPORTANT: This must hit WP admin-ajax.php with action=ppa_support_chat
    var payload = await postAjax(params, false, 'ppa_support_chat');

    if (!payload) {
      // If the WP AJAX handler is not installed yet, admin-ajax.php returns "0".
      var missing = false;
      try { missing = !!(window.__ppaMissingActions && window.__ppaMissingActions['ppa_support_chat']); } catch (e0) { missing = false; }
      if (missing) {
        appendChatLine('system', 'Support chat handler missing on this install. Next step: add wp_ajax_ppa_support_chat (PHP proxy).');
      } else {
        appendChatLine('system', 'Could not send. Reload this page, then try again.');
      }
      setChatBusy(false);
      return;
    }

    var core = extractChatCore(payload);

    // If it returns an ok/envelope shape, respect it.
    var ok = (typeof payload.ok === 'boolean') ? payload.ok : ((typeof core.ok === 'boolean') ? core.ok : null);
    if (ok === false) {
      appendChatLine('system', extractChatError(payload) || 'Support chat failed.');
      setChatBusy(false);
      return;
    }

    // Thread tracking
    var tid = extractChatThreadId(core);
    if (tid) chatState.threadId = tid;

    var reply = extractChatReply(core);
    if (!reply) {
      // fallback: some handlers may return {ok:true,data:{reply:""}} or raw envelope
      var core2 = extractChatCore(core);
      reply = extractChatReply(core2);
    }

    if (reply) appendChatLine('agent', reply);
    else appendChatLine('system', 'Sent. (No reply payload returned.)');

    setChatBusy(false);
  }

  function bindSupportChatEventsOnce() {
    if (document.__ppaSupportChatBound) return;
    document.__ppaSupportChatBound = true;

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t) return;

      // Open button
      if (t.closest && t.closest('#ppa-support-chat-open')) {
        try { e.preventDefault(); } catch (err) {}
        ensureSupportChatUI();
        showSupportChat(true);
        return;
      }

      // Close / backdrop click
      var closeEl = (t.closest && t.closest('[data-ppa-chat-close="1"]')) ? t.closest('[data-ppa-chat-close="1"]') : null;
      if (closeEl) {
        try { e.preventDefault(); } catch (err2) {}
        showSupportChat(false);
        return;
      }

      // Send button
      if (t.closest && t.closest('#ppa-support-chat-send')) {
        try { e.preventDefault(); } catch (err3) {}
        var input = $('ppa-support-chat-input');
        var val = input ? input.value : '';
        if (input) input.value = '';
        appendChatLine('user', val);
        sendSupportChatMessage(val);
        return;
      }
    }, true);

    document.addEventListener('keydown', function (e) {
      if (!chatState.open) return;

      // ESC closes
      if (e.key === 'Escape') {
        showSupportChat(false);
        return;
      }

      // Enter to send (Shift+Enter = newline)
      if (e.key === 'Enter' && !e.shiftKey) {
        var input = e.target && e.target.id === 'ppa-support-chat-input' ? e.target : null;
        if (!input) return;
        try { e.preventDefault(); } catch (err) {}

        var val = toSafeStr(input.value);
        input.value = '';
        appendChatLine('user', val);
        sendSupportChatMessage(val);
      }
    }, true);
  }

  // ----------------------------
  // Bind
  // ----------------------------
  function bind() {
    bindDelegatedPopupsOnce();
    bindSupportChatEventsOnce();
    ensureSupportChatUI();

    var btn = $('ppa-account-refresh');
    if (btn) {
      btn.addEventListener('click', function (e) {
        try { e.preventDefault(); } catch (err) {}
        fetchAccount(true);
      });
    }

    window.addEventListener('focus', function () { fetchAccount(false); });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) fetchAccount(false);
    });

    fetchAccount(true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();