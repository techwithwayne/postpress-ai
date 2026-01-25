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
           - upgrade, buy_tokens, billing_portal                                                          // CHANGED:
           - never hardcode URLs in WP; null/invalid keeps buttons disabled                                // CHANGED:
           - prevent disabled link clicks                                                                 // CHANGED:

Notes:
- This file is ONLY enqueued on the Account screen.
- No dependencies on other PPA scripts.
*/

(function () {
  'use strict';

  var inflight = false; // CHANGED:

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

    // Disable safely
    el.setAttribute('href', '#');
    el.classList.add('is-disabled');
    el.setAttribute('aria-disabled', 'true');
    el.setAttribute('tabindex', '-1');
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

    var tokens = (license.tokens && typeof license.tokens === 'object') ? license.tokens :
                 (core.tokens && typeof core.tokens === 'object') ? core.tokens :
                 {};

    var periodLabel = tokens.period_label || tokens.period || '';
    if (tokens.period && typeof tokens.period === 'object') {
      periodLabel = formatPeriodLabel(tokens.period);
    }
    if (periodLabel) setText('ppa-tokens-period', periodLabel);

    var used = num((tokens.monthly_used !== undefined ? tokens.monthly_used : (tokens.used !== undefined ? tokens.used : (tokens.usage !== undefined ? tokens.usage : core.tokens_used))));
    var limit = num((tokens.monthly_limit !== undefined ? tokens.monthly_limit : (tokens.limit !== undefined ? tokens.limit : (tokens.cap !== undefined ? tokens.cap : core.tokens_limit))));
    var remainingMonthly = num(tokens.monthly_remaining);
    var remainingTotal = num((tokens.remaining_total !== undefined ? tokens.remaining_total : license.tokens_remaining_total));

    if (used !== null) setText('ppa-tokens-used', fmtInt(used) + ' used');
    if (limit !== null) setText('ppa-tokens-limit', fmtInt(limit) + ' / month');

    var remaining = (remainingTotal !== null) ? remainingTotal : remainingMonthly;
    if (remaining !== null) setText('ppa-tokens-remaining', fmtInt(remaining) + ' remaining');

    if (used !== null && limit !== null) {
      var pct = clamp01(limit ? used / limit : 0);
      var bar = $('ppa-tokens-bar');
      if (bar) bar.style.width = String(Math.round(pct * 100)) + '%';
    }

    var sites = (license.sites && typeof license.sites === 'object') ? license.sites : {};
    var sitesUsed = (sites.used !== undefined) ? sites.used : (license.sites_used !== undefined ? license.sites_used : null);
    var sitesMax = (sites.max !== undefined) ? sites.max : (license.max_sites !== undefined ? license.max_sites : null);
    var unlimited = (sites.unlimited === true) || (license.unlimited_sites === true);

    if (sitesUsed !== null) setText('ppa-sites-active', String(sitesUsed));
    if (unlimited) {
      setText('ppa-sites-limit', 'Unlimited');
      if (sitesUsed !== null) setText('ppa-sites-label', String(sitesUsed) + ' / ∞');
    } else {
      if (sitesMax !== null) setText('ppa-sites-limit', String(sitesMax));
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

  async function fetchAccount() {
    if (inflight) return; // CHANGED:
    inflight = true;      // CHANGED:

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

    try {
      var resp = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          // Headers are not used by check_ajax_referer(), but are safe diagnostics / future-proof. // CHANGED:
          'X-PPA-Nonce': cfg.nonce,
          'X-WP-Nonce': cfg.nonce,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: form.toString()
      });

      var text = await resp.text();
      var trimmed = (text || '').trim(); // CHANGED:

      // If the controller uses check_ajax_referer() and nonce is missing/invalid, WP responds 403 + "-1".
      if (resp.status === 403 && (trimmed === '-1' || trimmed === '0' || trimmed === '')) { // CHANGED:
        setStatus('bad', 'Forbidden (nonce failed). Reload this page, then try again. If it persists, log out/in.'); // CHANGED:
        inflight = false; // CHANGED:
        return;
      }

      // Try JSON parse (WP should return JSON on success/error; but some failures can be HTML or "-1").
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
        fetchAccount();
      });
    }
    fetchAccount();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
