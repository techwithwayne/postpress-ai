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
    var h = Math.min(Number(preferredH) || 680, Math.max(480, availH - 180));

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

  
  function payloadLooksLikeFullAccountSnapshot(p) {
    // We only use the Support account_status endpoint if it includes the same rich license snapshot
    // as the legacy account_status bridge. Otherwise, we fall back to the stable legacy payload.
    if (!p || typeof p !== 'object') return false;

    var core = (p.data && typeof p.data === 'object') ? p.data :
               (p.result && typeof p.result === 'object') ? p.result :
               p;

    var license = (core.license && typeof core.license === 'object') ? core.license :
                  (core.license_snapshot && typeof core.license_snapshot === 'object') ? core.license_snapshot :
                  (core.lic && typeof core.lic === 'object') ? core.lic :
                  null;

    if (!license || typeof license !== 'object') return false;

    // Require at least tokens + sites to exist somewhere; otherwise it's not the full Account snapshot.
    var hasTokens = !!((license.tokens && typeof license.tokens === 'object') || (core.tokens && typeof core.tokens === 'object'));
    var hasSites = !!(license.sites && typeof license.sites === 'object');

    // Require a string-ish plan identifier (label/name/slug) to avoid "[object Object]".
    var planOk = false;
    if (license.plan && typeof license.plan === 'object') {
      planOk = (typeof license.plan.label === 'string' && license.plan.label.trim()) ||
               (typeof license.plan.name === 'string' && license.plan.name.trim()) ||
               (typeof license.plan.slug === 'string' && license.plan.slug.trim()) ||
               (typeof license.plan.plan_slug === 'string' && license.plan.plan_slug.trim());
    } else {
      planOk = (typeof license.plan_slug === 'string' && license.plan_slug.trim()) ||
               (typeof license.plan === 'string' && license.plan.trim());
    }

    return !!(hasTokens && hasSites && planOk);
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
      if (supportPayload && typeof supportPayload === 'object' && supportPayload.ok === true && payloadLooksLikeFullAccountSnapshot(supportPayload)) {
        payload = supportPayload;
      }
    }

    if (!payload) {
      payload = await postAjax(null, true);
    }

    // If both failed but Support gave us an *error* envelope, prefer showing that rather than nothing.
    // Important: never let an incomplete *ok:true* Support payload override the stable legacy snapshot.
    if (!payload && supportPayload && typeof supportPayload === 'object' && supportPayload.ok === false) {
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
      var _lbl = license.plan.label;
      var _nm = license.plan.name;

      if (typeof _lbl === 'string' && _lbl.trim()) planName = _lbl.trim();
      else if (typeof _nm === 'string' && _nm.trim()) planName = _nm.trim();
      else {
        // Some envelopes nest slug/id inside license.plan; prefer a string fallback rather than "[object Object]".
        var _slug2 = license.plan.plan_slug || license.plan.slug || license.plan.id || '';
        if (typeof _slug2 === 'string' && _slug2.trim()) planName = _slug2.trim();
        else if (typeof license.plan_slug === 'string' && license.plan_slug.trim()) planName = license.plan_slug.trim();
      }
    } else {
      var _slug = license.plan_slug || license.plan || '—';
      planName = (typeof _slug === 'string' && _slug.trim()) ? _slug.trim() : '—';
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

        // HARDEN: Some backends signal "unlimited" by returning max=0. If sites are in use, treat 0 as unlimited.
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

        if (!sitesUnlimited && sitesMax === 0 && sitesUsed !== null && sitesUsed > 0) sitesUnlimited = true;

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
    var popup = openSizedPopup('ppa_' + intent, 840, 680);
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
  var chatState = {
    open: false,
    busy: false,
    threadId: null,
    hasGreeted: false,
    typingEl: null,
    lastTypingStartedAt: 0
  };

  var YUKIA_GREETING = "Hi, I’m Yukia with the PostPress AI support team. How can I help you today?";
  var YUKIA_NET_FAIL = "I couldn’t reach support right now.";
  var YUKIA_FAIL_Q = "What did you click, and what did you expect?";
  var YUKIA_DELAY_MS = 5000;

  function ensureSupportChatUI() {
    // Create a Support Chat button next to the Refresh button (Account page header).
    var refreshBtn = $('ppa-account-refresh');
    var existing = $('ppa-support-chat-open');

    if (!existing) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'ppa-support-chat-open';
      btn.className = 'button';
      btn.textContent = 'Support';

      if (refreshBtn && refreshBtn.parentNode) {
        if (refreshBtn.nextSibling) refreshBtn.parentNode.insertBefore(btn, refreshBtn.nextSibling);
        else refreshBtn.parentNode.appendChild(btn);
      } else {
        document.body.appendChild(btn);
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
            '<div class="ppa-support-chat__title">Support</div>' +
            '<button type="button" class="button" id="ppa-support-chat-close" data-ppa-chat-close="1">Close</button>' +
          '</div>' +
          '<div class="ppa-support-chat__log" id="ppa-support-chat-log" aria-live="polite"></div>' +
          '<div class="ppa-support-chat__composer">' +
            '<textarea id="ppa-support-chat-input" rows="2" placeholder="Type your message…"></textarea>' +
            '<button type="button" class="button button-primary" id="ppa-support-chat-send">Send</button>' +
          '</div>' +
        '</div>';

      document.body.appendChild(modal);
      ensureSupportChatStyles();
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
      '#ppa-support-chat-modal .ppa-chatline{display:flex;}' +
      '#ppa-support-chat-modal .ppa-chatline--user{justify-content:flex-end;}' +
      '#ppa-support-chat-modal .ppa-chatline__bubble{max-width:88%;padding:10px 12px;border-radius:12px;font-size:13px;line-height:1.35;white-space:pre-wrap;word-break:break-word;}' +
      '#ppa-support-chat-modal .ppa-chatline--user .ppa-chatline__bubble{background:#2b2b30;color:#fff;border:1px solid rgba(255,255,255,.10);}' +
      '#ppa-support-chat-modal .ppa-chatline--agent .ppa-chatline__bubble{background:#121214;color:#f2f2f2;border:1px solid rgba(255,255,255,.08);}' +
      '#ppa-support-chat-modal .ppa-chatline__wrap{display:flex;flex-direction:column;align-items:flex-start;gap:8px;}' +
      '#ppa-support-chat-modal .ppa-support-chat__actions{display:flex;gap:8px;flex-wrap:wrap;}' +
      '#ppa-support-chat-modal .ppa-support-chat__actions .button{height:auto;line-height:1.1;padding:8px 10px;}' +
      '#ppa-support-chat-modal .ppa-typing{display:inline-flex;gap:6px;align-items:center;}' +
      '#ppa-support-chat-modal .ppa-typing span{display:inline-block;width:6px;height:6px;border-radius:6px;background:rgba(255,255,255,.65);animation:ppaDot 1.15s infinite ease-in-out;}' +
      '#ppa-support-chat-modal .ppa-typing span:nth-child(2){animation-delay:.15s;}' +
      '#ppa-support-chat-modal .ppa-typing span:nth-child(3){animation-delay:.30s;}' +
      '@keyframes ppaDot{0%,80%,100%{transform:translateY(0);opacity:.55;}40%{transform:translateY(-3px);opacity:1;}}' +
      '@media (max-width:640px){#ppa-support-chat-modal .ppa-support-chat__panel{right:10px;left:10px;bottom:10px;width:auto;}}';

    var style = document.createElement('style');
    style.id = 'ppa-support-chat-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function showSupportChat(open) {
    var modal = $('ppa-support-chat-modal');
    if (!modal) return;

    chatState.open = !!open;
    modal.style.display = chatState.open ? 'block' : 'none';
    modal.setAttribute('aria-hidden', chatState.open ? 'false' : 'true');

    if (chatState.open) {
      scrollChatToBottom();
      // Typing starts when the user initiates chat (opening the panel).
      if (!chatState.hasGreeted) {
        yukiaRespond(YUKIA_GREETING, null, true);
        chatState.hasGreeted = true;
      }
      var input = $('ppa-support-chat-input');
      if (input) {
        setTimeout(function () { try { input.focus(); } catch (e) {} }, 40);
      }
    }
  }

  function scrollChatToBottom() {
    var log = $('ppa-support-chat-log');
    if (!log) return;
    try { log.scrollTop = log.scrollHeight + 9999; } catch (e) {}
  }

  function setChatBusy(isBusy) {
    chatState.busy = !!isBusy;
    var send = $('ppa-support-chat-send');
    var input = $('ppa-support-chat-input');
    if (send) send.disabled = chatState.busy;
    if (input) input.disabled = chatState.busy;
  }

  function stripMarkdownLite(s) {
    var t = toSafeStr(s);
    if (!t) return '';
    t = t.replace(/\*\*/g, '').replace(/__/g, '').replace(/`/g, '');
    t = t.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1 ($2)');
    return t;
  }

  function appendChatLine(kind, text, suggestedActions) {
    var log = $('ppa-support-chat-log');
    if (!log) return;

    var k = (kind === 'user' || kind === 'agent') ? kind : 'agent';

    var line = document.createElement('div');
    line.className = 'ppa-chatline ppa-chatline--' + k;

    var wrap = document.createElement('div');
    wrap.className = 'ppa-chatline__wrap';

    var bubble = document.createElement('div');
    bubble.className = 'ppa-chatline__bubble';

    var out = toSafeStr(text) || '—';
    out = stripMarkdownLite(out);
    bubble.textContent = out;

    wrap.appendChild(bubble);

    // Buttons render OUTSIDE bubble, directly under it.
    if (k === 'agent' && Array.isArray(suggestedActions) && suggestedActions.length) {
      var row = document.createElement('div');
      row.className = 'ppa-support-chat__actions';

      for (var i = 0; i < suggestedActions.length; i++) {
        var a = suggestedActions[i] || {};
        var id = toSafeStr(a.id).trim();
        var label = toSafeStr(a.label).trim();
        if (!id || !label) continue;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button ppa-support-chat__action';
        btn.setAttribute('data-ppa-action-id', id);
        btn.textContent = label;
        row.appendChild(btn);
      }

      if (row.childNodes.length) wrap.appendChild(row);
    }

    line.appendChild(wrap);
    log.appendChild(line);
    scrollChatToBottom();
  }

  function showTyping() {
    if (chatState.typingEl) return;
    var log = $('ppa-support-chat-log');
    if (!log) return;

    var line = document.createElement('div');
    line.className = 'ppa-chatline ppa-chatline--agent';
    line.setAttribute('data-ppa-typing', '1');

    var wrap = document.createElement('div');
    wrap.className = 'ppa-chatline__wrap';

    var bubble = document.createElement('div');
    bubble.className = 'ppa-chatline__bubble';

    var dots = document.createElement('div');
    dots.className = 'ppa-typing';
    dots.innerHTML = '<span></span><span></span><span></span>';

    bubble.appendChild(dots);
    wrap.appendChild(bubble);
    line.appendChild(wrap);

    chatState.typingEl = line;
    chatState.lastTypingStartedAt = Date.now();

    log.appendChild(line);
    scrollChatToBottom();
  }

  function hideTyping() {
    if (!chatState.typingEl) return;
    try { chatState.typingEl.remove(); } catch (e) {}
    chatState.typingEl = null;
  }

  function afterMinDelay(startAt, fn) {
    var elapsed = Date.now() - startAt;
    var wait = Math.max(0, YUKIA_DELAY_MS - elapsed);
    setTimeout(fn, wait);
  }

  function yukiaRespond(text, suggestedActions, isGreeting) {
    // Always show typing immediately, then respond after a minimum delay.
    var startAt = Date.now();
    showTyping();

    afterMinDelay(startAt, function () {
      hideTyping();
      appendChatLine('agent', text, suggestedActions || null);
      if (isGreeting) chatState.hasGreeted = true;
    });
  }

  function looksLikeTokensIntent(msg) {
    var m = toSafeStr(msg).trim().toLowerCase();
    if (!m) return false;
    return /(^|\b)(credit|credits|token|tokens|balance|buy tokens)(\b|$)/i.test(m);
  }

  function tokensFlowMessage() {
    return "Okay, I see you’re asking about credits (tokens).\nPick one below:";
  }

  function tokensFlowActions() {
    return [
      { id: 'buy_tokens', label: 'Buy Tokens' },
      { id: 'check_token_balance', label: 'Check My Token Balance' },
      { id: 'something_looks_wrong', label: 'Something Looks Wrong' }
    ];
  }

  function focusTokenUsage() {
    // Try a few known anchors; scroll the closest card into view.
    var anchor = $('ppa-tokens-used') || $('ppa-tokens-bar') || $('ppa-tokens-period') || $('ppa-tokens-remaining') || null;
    if (!anchor) return;
    var card = anchor.closest('.ppa-card') || anchor.closest('.ppa-card__body') || anchor;
    try { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
  }

  function parseChatReplyPayload(payload) {
    // Expected envelope from WP proxy: { ok:true, data:{ reply:"...", thread_id:"...", suggested_actions:[...] } }
    if (!payload || typeof payload !== 'object') return null;

    var core = (payload.data && typeof payload.data === 'object') ? payload.data :
               (payload.result && typeof payload.result === 'object') ? payload.result :
               payload;

    var reply = core.reply || core.response || core.message || core.text || null;
    reply = toSafeStr(reply).trim();

    var tid = core.thread_id || core.threadId || core.thread || null;
    tid = toSafeStr(tid).trim();

    var acts = Array.isArray(core.suggested_actions) ? core.suggested_actions : [];
    return { reply: reply || '', threadId: tid || '', actions: acts };
  }

  async function sendSupportChatMessage(message) {
    var msg = toSafeStr(message).trim();
    if (!msg) return;

    // Local routing first (tokens).
    if (looksLikeTokensIntent(msg)) {
      yukiaRespond(tokensFlowMessage(), tokensFlowActions(), false);
      return;
    }

    setChatBusy(true);
    var startAt = Date.now();
    showTyping();

    var params = { message: msg };
    if (chatState.threadId) params.thread_id = chatState.threadId;

    var payload = await postAjax(params, false, 'ppa_support_chat');

    afterMinDelay(startAt, function () {
      hideTyping();

      if (!payload || (payload.ok === false)) {
        appendChatLine('agent', YUKIA_NET_FAIL, null);
        setChatBusy(false);
        return;
      }

      var parsed = parseChatReplyPayload(payload) || null;
      if (!parsed) {
        appendChatLine('agent', YUKIA_NET_FAIL, null);
        setChatBusy(false);
        return;
      }

      if (parsed.threadId) chatState.threadId = parsed.threadId;

      if (parsed.reply) appendChatLine('agent', parsed.reply, parsed.actions || null);
      else appendChatLine('agent', YUKIA_NET_FAIL, null);

      setChatBusy(false);
    });
  }

  function handleSuggestedAction(actionId) {
    var id = toSafeStr(actionId).trim();

    if (id === 'buy_tokens') {
      // Open the same popup as the Account "Buy Tokens" button.
      var popup = openSizedPopup('ppa_buy_tokens', 980, 780);
      if (!popup) {
        yukiaRespond("Popup blocked.\n" + YUKIA_FAIL_Q, null, false);
        return;
      }
      writePopupLoading(popup, 'Opening token purchase…');
      fetchIntent('buy_tokens').then(function (payload) {
        var url = extractLinkFromPayload(payload || lastPayload, 'buy_tokens', hrefForChatIntent('buy_tokens'));
        if (!url) {
          try { popup.close(); } catch (e) {}
          yukiaRespond("Link not available yet.\n" + YUKIA_FAIL_Q, null, false);
          return;
        }
        try { popup.location.href = url; popup.focus(); } catch (e2) {
          yukiaRespond("Could not open popup.\n" + YUKIA_FAIL_Q, null, false);
        }
      });
      return;
    }

    if (id === 'check_token_balance') {
      // Refresh Account data + focus token usage section (no extra Yukia chatter needed).
      fetchAccount(true).then(function () { focusTokenUsage(); });
      return;
    }

    if (id === 'something_looks_wrong') {
      yukiaRespond(YUKIA_FAIL_Q, null, false);
      return;
    }
  }

  function bindSupportChatEventsOnce() {
    if (document.__ppaSupportChatBound) return;
    document.__ppaSupportChatBound = true;

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t) return;

      // Open
      if (t.closest && t.closest('#ppa-support-chat-open')) {
        try { e.preventDefault(); } catch (err) {}
        ensureSupportChatUI();
        showSupportChat(true);
        return;
      }

      // Close/backdrop
      var closeEl = (t.closest && t.closest('[data-ppa-chat-close="1"]')) ? t.closest('[data-ppa-chat-close="1"]') : null;
      if (closeEl) {
        try { e.preventDefault(); } catch (err2) {}
        showSupportChat(false);
        return;
      }

      // Suggested action buttons
      var actBtn = (t.closest && t.closest('.ppa-support-chat__action')) ? t.closest('.ppa-support-chat__action') : null;
      if (actBtn) {
        try { e.preventDefault(); } catch (errA) {}
        handleSuggestedAction(actBtn.getAttribute('data-ppa-action-id'));
        return;
      }

      // Send
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

      if (e.key === 'Escape') {
        showSupportChat(false);
        return;
      }

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