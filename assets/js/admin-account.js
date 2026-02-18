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

Notes:
- This file is ONLY enqueued on the Account screen.
- No dependencies on other PPA scripts.
*/

(function () {
  'use strict';

  var inflight = false; // CHANGED:
  var lastFetchAt = 0;  // CHANGED: throttle auto-refresh on focus/visibility

  function $(id) {
    return document.getElementById(id);
  }

  function num(v) {
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
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

  function setStatus(type, msg) {
    var el = $('ppa-account-status');
    if (!el) return;

    el.classList.remove('is-good', 'is-bad');
    if (type === 'good') el.classList.add('is-good');
    if (type === 'bad') el.classList.add('is-bad');

    var text = msg || '—';

    // Some templates do NOT include a child .ppa-status__text.
    // In that case, update the container directly so status is always visible.                         // CHANGED:
    var t = el.querySelector('.ppa-status__text');
    if (t) {
      t.textContent = text;
    } else {
      el.textContent = text;                                                                            // CHANGED:
    }
  }

  function setText(id, value) {
    var el = $(id);
    if (!el) return;
    el.textContent = (value === null || value === undefined || value === '') ? '—' : String(value);
  }

  function toSafeStr(v) {
    if (v === null || v === undefined) return '';
    try { return String(v); } catch (e) { return ''; }
  }

  function firstDefined(list) { // CHANGED:
    // Return the first value that is not null/undefined/empty-string.
    if (!Array.isArray(list)) return null;
    for (var i = 0; i < list.length; i++) {
      var v = list[i];
      if (v === null || v === undefined) continue;
      if (typeof v === 'string' && v.trim() === '') continue;
      return v;
    }
    return null;
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

  function updateLicensePill(state, text) {
    var pill = $('ppa-license-pill');
    if (!pill) return;
    var s = String(state || '').toLowerCase();
    pill.setAttribute('data-state', s || 'unknown');
    if (text) {
      pill.textContent = String(text);
      return;
    }
    if (s === 'active') pill.textContent = 'License Active';
    else if (s === 'inactive') pill.textContent = 'License Inactive';
    else pill.textContent = 'License Unknown';
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

  function withTs(url) { // CHANGED:
    // Cache-bust at the URL level too (some intermediaries ignore POST body).
    var base = toSafeStr(url);
    if (!base) return base;
    var ts = String(Date.now());
    try {
      var u = new URL(base, window.location.href);
      u.searchParams.set('_ts', ts);
      return u.toString();
    } catch (e) {
      // Fallback for older environments
      return base + (base.indexOf('?') === -1 ? '?' : '&') + '_ts=' + encodeURIComponent(ts);
    }
  }

  // ----------------------------
  // Link enabling (Django authoritative)
  // ----------------------------

  function isHttpUrl(v) { // CHANGED:
    var s = toSafeStr(v).trim();
    return !!s && /^https?:\/\//i.test(s); // CHANGED:
  }

  function setLinkEnabled(el, href) { // CHANGED:
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

    // Disable safely (and remove stale enabled attrs).                                                  // CHANGED:
    el.setAttribute('href', '#');
    el.classList.add('is-disabled');
    el.setAttribute('aria-disabled', 'true');
    el.setAttribute('tabindex', '-1');
    el.removeAttribute('target'); // CHANGED:
    el.removeAttribute('rel');    // CHANGED:
  }

  function preventDisabledClicksOnce() { // CHANGED:
    var ids = ['ppa-account-upgrade', 'ppa-account-buy-tokens', 'ppa-account-billing-portal']; // CHANGED:
    ids.forEach(function (id) {
      var el = $(id);
      if (!el) return;
      if (el.__ppaBound) return; // CHANGED:
      el.__ppaBound = true;      // CHANGED:
      el.addEventListener('click', function (e) {
        // If disabled, never navigate / jump to top.
        if (el.classList.contains('is-disabled') || el.getAttribute('aria-disabled') === 'true') {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    });
  }

  function applyLinksFromLicense(licenseObj) { // CHANGED:
    if (!licenseObj || typeof licenseObj !== 'object') return;

    var links = (licenseObj.links && typeof licenseObj.links === 'object') ? licenseObj.links : {};
    // Support a few reasonable aliases without changing contract.
    var upgrade = links.upgrade || links.upgrade_url || null;                 // CHANGED:
    var buyTokens = links.buy_tokens || links.buyTokens || links.purchase || null; // CHANGED:
    var billingPortal = links.billing_portal || links.billingPortal || links.portal || null; // CHANGED:

    setLinkEnabled($('ppa-account-upgrade'), upgrade);               // CHANGED:
    setLinkEnabled($('ppa-account-buy-tokens'), buyTokens);          // CHANGED:
    setLinkEnabled($('ppa-account-billing-portal'), billingPortal);  // CHANGED:
  }

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

    // Apply Django-provided links (upgrade/buy/billing) immediately after we have license.               // CHANGED:
    applyLinksFromLicense(license);                                                                        // CHANGED:

    var activation = (core.activation && typeof core.activation === 'object') ? core.activation : {};

    var licStatus = String(license.status || '').toLowerCase();
    var activated = (activation.activated === true);
    if (licStatus === 'active' && activated && envelopeOk === true) {
      updateLicensePill('active', 'License Active');
    } else if (licStatus && licStatus !== 'active') {
      updateLicensePill('inactive', 'License Inactive');
    } else if (activated === false && toSafeStr(activation.site_url)) {
      updateLicensePill('inactive', 'Site Not Activated');
    } else {
      updateLicensePill('unknown', 'License Unknown');
    }

    var plan = (license.plan && typeof license.plan === 'object') ? license.plan : {};
    var planName = plan.label || plan.name || license.plan_slug || license.plan || '—';
    setText('ppa-plan-name', planName);

    var billingEmail = dig(core, 'account.email') || dig(core, 'account.billing_email') || license.email || license.billing_email || '';
    setText('ppa-billing-email', billingEmail || '—');

    // ----------------------------
    // Tokens (license.v1 nested + legacy flat keys)
    // ----------------------------

    var tokens = (license.tokens && typeof license.tokens === 'object') ? license.tokens :
                 (core.tokens && typeof core.tokens === 'object') ? core.tokens :
                 {};

    // Period: prefer tokens.period object; otherwise accept period_start/period_end aliases.             // CHANGED:
    var periodObj = null;                                                                                   // CHANGED:
    if (tokens.period && typeof tokens.period === 'object') {                                                // CHANGED:
      periodObj = tokens.period;                                                                             // CHANGED:
    } else {                                                                                                 // CHANGED:
      var ps = firstDefined([                                                                                // CHANGED:
        tokens.period_start, tokens.start, tokens.periodStart,                                                // CHANGED:
        license.tokens_period_start, license.period_start,                                                    // CHANGED:
        core.tokens_period_start, dig(core, 'tokens.period.start'), dig(core, 'license.tokens.period.start') // CHANGED:
      ]);                                                                                                    // CHANGED:
      var pe = firstDefined([                                                                                // CHANGED:
        tokens.period_end, tokens.end, tokens.periodEnd,                                                      // CHANGED:
        license.tokens_period_end, license.period_end,                                                        // CHANGED:
        core.tokens_period_end, dig(core, 'tokens.period.end'), dig(core, 'license.tokens.period.end')       // CHANGED:
      ]);                                                                                                    // CHANGED:
      if (ps || pe) periodObj = { start: ps || null, end: pe || null };                                       // CHANGED:
    }                                                                                                        // CHANGED:

    var periodLabel = firstDefined([tokens.period_label, tokens.period, license.tokens_period_label, core.tokens_period_label]); // CHANGED:
    if (periodObj) periodLabel = formatPeriodLabel(periodObj);                                               // CHANGED:
    setText('ppa-tokens-period', periodLabel || '—');                                                        // CHANGED:

    // Used / Limit / Remaining (monthly + total)
    var used = num(firstDefined([
      tokens.monthly_used, tokens.used, tokens.usage, tokens.tokens_used, tokens.used_monthly,
      license.monthly_used, license.tokens_monthly_used, license.tokens_used, license.tokens_used_monthly,
      core.tokens_used, core.monthly_used, dig(core, 'tokens.monthly_used'), dig(core, 'license.tokens.monthly_used'),
      dig(core, 'tokens.used'), dig(core, 'license.tokens.used')
    ]));

    var limit = num(firstDefined([
      tokens.monthly_limit, tokens.limit, tokens.cap, tokens.tokens_limit, tokens.limit_monthly,
      license.monthly_limit, license.tokens_monthly_limit, license.tokens_limit, license.tokens_limit_monthly,
      core.tokens_limit, core.monthly_limit, dig(core, 'tokens.monthly_limit'), dig(core, 'license.tokens.monthly_limit'),
      dig(core, 'tokens.limit'), dig(core, 'license.tokens.limit')
    ]));

    // Purchased balance (used to compute remaining_total if Django didn't provide it).                    // CHANGED:
    var purchased = num(firstDefined([                                                                        // CHANGED:
      tokens.purchased_balance, tokens.purchased, tokens.addon_balance,                                       // CHANGED:
      license.purchased_balance, license.purchased_tokens_balance,                                            // CHANGED:
      core.purchased_balance, core.purchased_tokens_balance,                                                  // CHANGED:
      dig(core, 'tokens.purchased_balance'), dig(core, 'license.tokens.purchased_balance')                   // CHANGED:
    ]));                                                                                                      // CHANGED:
    if (purchased === null) purchased = 0;                                                                    // CHANGED:

    var remainingMonthly = num(firstDefined([
      tokens.monthly_remaining, tokens.remaining_monthly, tokens.monthly_left,
      license.tokens_monthly_remaining,
      core.tokens_monthly_remaining, dig(core, 'tokens.monthly_remaining'), dig(core, 'license.tokens.monthly_remaining')
    ]));

    // If monthly remaining not provided, compute it from used/limit (stable fallback).                     // CHANGED:
    if (remainingMonthly === null && used !== null && limit !== null && limit > 0) {                         // CHANGED:
      remainingMonthly = Math.max(0, limit - used);                                                          // CHANGED:
    }                                                                                                        // CHANGED:

    var remainingTotal = num(firstDefined([
      tokens.remaining_total, tokens.remainingTotal,
      license.tokens_remaining_total, license.remaining_total,
      core.tokens_remaining_total, dig(core, 'tokens.remaining_total'), dig(core, 'license.tokens.remaining_total')
    ]));

    // If total remaining not provided, compute it (monthly remaining + purchased).                         // CHANGED:
    if (remainingTotal === null && remainingMonthly !== null) {                                               // CHANGED:
      remainingTotal = Math.max(0, remainingMonthly) + Math.max(0, purchased || 0);                           // CHANGED:
    }                                                                                                        // CHANGED:

    // Always render deterministically (never leave stale text)
    setText('ppa-tokens-used', (used !== null) ? (fmtInt(used) + ' used') : '—');
    setText('ppa-tokens-limit', (limit !== null) ? (fmtInt(limit) + ' / month') : '—');

    // Monthly remaining shows in ppa-tokens-remaining.                                                      // CHANGED:
    setText('ppa-tokens-remaining', (remainingMonthly !== null) ? (fmtInt(remainingMonthly) + ' remaining') : '—'); // CHANGED:

    // Total remaining shows in ppa-tokens-remaining-total.                                                  // CHANGED:
    setText('ppa-tokens-remaining-total', (remainingTotal !== null) ? (fmtInt(remainingTotal) + ' total') : '—'); // CHANGED:

    // Progress bar: reset safely when unknown
    var bar = $('ppa-tokens-bar');
    if (bar) {
      if (used !== null && limit !== null && limit > 0) {
        var pct = clamp01(used / limit);
        bar.style.width = String(Math.round(pct * 100)) + '%';
      } else {
        bar.style.width = '0%';
      }
    }

    var sites = (license.sites && typeof license.sites === 'object') ? license.sites : {};
    var sitesUsed = (sites.used !== undefined) ? sites.used : (license.sites_used !== undefined ? license.sites_used : null);
    var sitesMax = (sites.max !== undefined) ? sites.max : (license.max_sites !== undefined ? license.max_sites : null);
    var unlimited = (sites.unlimited === true) || (license.unlimited_sites === true);

    // Remaining sites (new UI id)                                                                          // CHANGED:
    var sitesRemaining = (sites.remaining !== undefined && sites.remaining !== null) ? sites.remaining :     // CHANGED:
      (license.sites_remaining !== undefined ? license.sites_remaining : null);                               // CHANGED:
    if (!unlimited && sitesRemaining === null && sitesUsed !== null && sitesMax !== null) {                  // CHANGED:
      sitesRemaining = Math.max(0, Number(sitesMax) - Number(sitesUsed));                                     // CHANGED:
    }                                                                                                        // CHANGED:

    if (sitesUsed !== null) setText('ppa-sites-active', String(sitesUsed));
    if (unlimited) {
      setText('ppa-sites-limit', 'Unlimited');
      setText('ppa-sites-remaining', '∞'); // CHANGED:
      if (sitesUsed !== null) setText('ppa-sites-label', String(sitesUsed) + ' / ∞');
    } else {
      if (sitesMax !== null) setText('ppa-sites-limit', String(sitesMax));
      if (sitesRemaining !== null) setText('ppa-sites-remaining', String(sitesRemaining)); // CHANGED:
      if (sitesUsed !== null && sitesMax !== null) setText('ppa-sites-label', String(sitesUsed) + ' / ' + String(sitesMax));
    }

    var list = [];
    if (Array.isArray(sites.list)) list = sites.list;
    else if (Array.isArray(core.sites_list)) list = core.sites_list;
    else if (activation && activation.site_url) {
      list = [{
        url: activation.site_url,
        status: activation.activated ? 'activated' : 'not activated'
      }];
    }
    renderSites(list);

    if (envelopeOk === true) {
      setStatus('good', 'Account synced.');
    } else if (envelopeOk === false) {
      var msg = '';
      if (envelopeErr) msg = envelopeErr.message || envelopeErr.code || '';
      msg = msg || data.message || data.error || 'Account check failed.';
      setStatus('bad', String(msg));
    } else {
      setStatus('', 'Account loaded.');
    }
  }

  function bestConfig() {
    // Prefer localized Account config as the primary source.
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

  function addNonceFields(form, nonce) { // CHANGED:
    var n = toSafeStr(nonce);
    if (!n) return;

    // WP nonce validation varies by controller implementation:
    // - check_ajax_referer($action, 'nonce')
    // - check_ajax_referer($action, '_ajax_nonce')
    // - check_ajax_referer($action, 'security')
    // We send ALL common keys to be bulletproof across implementations. // CHANGED:
    form.set('nonce', n);         // CHANGED:
    form.set('_ajax_nonce', n);   // CHANGED:
    form.set('_wpnonce', n);      // CHANGED:
    form.set('security', n);      // CHANGED:
    form.set('ppa_nonce', n);     // CHANGED:
  }

  function addSiteFields(form, site) { // CHANGED:
    var s = toSafeStr(site);
    if (!s) return;
    // Some controllers expect 'site', others 'site_url' or 'domain'. Send broadly. // CHANGED:
    form.set('site', s);          // CHANGED:
    form.set('site_url', s);      // CHANGED:
    form.set('domain', s);        // CHANGED:
  }

  function shouldAutoRefresh() { // CHANGED:
    // Avoid spamming verify if the admin is clicking around quickly.
    // 15s is enough to catch "I just generated previews" behavior without noise.
    return (Date.now() - lastFetchAt) > 15000;
  }

  async function fetchAccount(force) { // CHANGED:
    if (inflight) return; // CHANGED:
    if (!force && !shouldAutoRefresh() && lastFetchAt > 0) return; // CHANGED:
    inflight = true;      // CHANGED:
    lastFetchAt = Date.now(); // CHANGED: record attempt time

    var cfg = bestConfig();

    if (!cfg.ajaxUrl || !cfg.nonce) {
      setStatus('bad', 'Account config missing.');
      inflight = false; // CHANGED:
      return;
    }

    setStatus('', 'Refreshing…');

    var form = new URLSearchParams();
    form.set('action', cfg.action);
    form.set('_ts', String(Date.now()));
    addNonceFields(form, cfg.nonce);   // CHANGED:
    addSiteFields(form, cfg.site);     // CHANGED:

    // Cache-bust at URL level too (belt + suspenders).                                                    // CHANGED:
    var ajaxUrl = withTs(cfg.ajaxUrl);                                                                       // CHANGED:

    try {
      var resp = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store', // CHANGED: force browser to bypass caches
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json, text/plain, */*', // CHANGED
          // Request cache hints (some proxies honor these)
          'Cache-Control': 'no-cache, no-store, max-age=0', // CHANGED
          'Pragma': 'no-cache', // CHANGED
          'Expires': '0', // CHANGED
          // Headers are not used by check_ajax_referer(), but are safe diagnostics / future-proof. // CHANGED:
          'X-PPA-Nonce': cfg.nonce,
          'X-WP-Nonce': cfg.nonce,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: form.toString()
      });

      var text = await resp.text();
      var trimmed = (text || '').trim(); // CHANGED:

      // WP nonce failures frequently return "-1" with HTTP 200 (not 403).                                  // CHANGED:
      if (trimmed === '-1' || trimmed === '0') {                                                             // CHANGED:
        setStatus('bad', 'Nonce failed. Reload this page, then try again. If it persists, log out/in.');     // CHANGED:
        inflight = false; // CHANGED:
        return;
      }                                                                                                      // CHANGED:

      // If the controller uses check_ajax_referer() and nonce is missing/invalid, WP can also respond 403.
      if (resp.status === 403 && trimmed === '') { // CHANGED:
        setStatus('bad', 'Forbidden (nonce failed). Reload this page, then try again. If it persists, log out/in.');
        inflight = false; // CHANGED:
        return;
      }

      // Try JSON parse (WP should return JSON on success/error; but some failures can be HTML).
      var json = null;
      try { json = JSON.parse(text); } catch (e) { json = null; }

      if (!json) {
        // If it's an HTML/nonce/WAF response, show short hint.
        if (resp.status === 403) { // CHANGED:
          setStatus('bad', 'Forbidden (403). Likely nonce/referer security. Reload page and retry.'); // CHANGED:
        } else {
          setStatus('bad', 'Could not refresh (non-JSON response).');
        }
        inflight = false; // CHANGED:
        return;
      }

      // WP AJAX standard: { success: true|false, data: ... }
      if (json && typeof json === 'object' && 'success' in json) {
        if (json.success && json.data) {
          renderFromData(json.data);
          inflight = false; // CHANGED:
          return;
        }
        var errMsg = (json.data && (json.data.message || json.data.error)) ? (json.data.message || json.data.error) : '';
        // If controller died with 403 but still returned JSON, surface it.
        if (resp.status === 403 && !errMsg) { // CHANGED:
          errMsg = 'Forbidden (nonce failed). Reload page and retry.'; // CHANGED:
        }
        setStatus('bad', errMsg || 'Account request failed.');
        inflight = false; // CHANGED:
        return;
      }

      // Non-standard JSON shape: render directly.
      renderFromData(json);
      inflight = false; // CHANGED:
    } catch (e2) {
      setStatus('bad', 'Could not refresh (network / JSON error).');
      inflight = false; // CHANGED:
    }
  }

  function bind() {
    preventDisabledClicksOnce(); // CHANGED:

    var btn = $('ppa-account-refresh');
    if (btn) {
      btn.addEventListener('click', function (e) { // CHANGED:
        try { e.preventDefault(); } catch (err) {}
        fetchAccount(true); // CHANGED: manual refresh should always force
      });
    }

    // Auto refresh when user returns to this tab/window (common after Generate Preview).                 // CHANGED:
    window.addEventListener('focus', function () { fetchAccount(false); });                                // CHANGED:
    document.addEventListener('visibilitychange', function () {                                            // CHANGED:
      if (!document.hidden) fetchAccount(false);                                                          // CHANGED:
    });                                                                                                   // CHANGED:

    fetchAccount(true); // CHANGED: initial load should always force
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
