/*
PostPress AI — Admin Account Screen (Isolated)

========= CHANGE LOG =========
2026-01-15: ADD: Account screen JS that fetches account/token/site details via WP AJAX (PPA_Controller proxy).

Notes:
- This file is ONLY enqueued on the Account screen.
- No dependencies on other PPA scripts.
*/

(function () {
  'use strict';

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
    var t = el.querySelector('.ppa-status__text');
    if (t) t.textContent = msg || '—';
  }

  function setText(id, value) {
    var el = $(id);
    if (!el) return;
    el.textContent = (value === null || value === undefined || value === '') ? '—' : String(value);
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

  function updateLicensePill(state) {
    var pill = $('ppa-license-pill');
    if (!pill) return;
    var s = String(state || '').toLowerCase();
    pill.setAttribute('data-state', s || 'unknown');
    if (s === 'active') pill.textContent = 'License Active';
    else if (s === 'inactive') pill.textContent = 'License Inactive';
    else pill.textContent = 'License Unknown';
  }

  function renderFromData(data) {
    if (!data || typeof data !== 'object') {
      setStatus('bad', 'Account data missing.');
      return;
    }

    // Accept either {ok:true, ...} or {result:{...}} shapes.
    var root = data.result && typeof data.result === 'object' ? data.result : data;

    // License
    if (root.license_state) {
      updateLicensePill(root.license_state);
    }

    // Account overview
    if (root.account && typeof root.account === 'object') {
      setText('ppa-plan-name', root.account.plan || root.account.plan_name || root.account.tier || '—');
      setText('ppa-billing-email', root.account.email || root.account.billing_email || '—');
    }

    // Tokens
    var tokens = (root.tokens && typeof root.tokens === 'object') ? root.tokens : {};
    var used = num(tokens.used ?? tokens.usage ?? root.tokens_used);
    var limit = num(tokens.limit ?? tokens.cap ?? root.tokens_limit);

    if (tokens.period || tokens.period_label) {
      setText('ppa-tokens-period', tokens.period_label || tokens.period);
    }

    if (used !== null) setText('ppa-tokens-used', fmtInt(used) + ' used');
    if (limit !== null) setText('ppa-tokens-limit', fmtInt(limit) + ' total');

    if (used !== null && limit !== null) {
      var remaining = Math.max(0, limit - used);
      setText('ppa-tokens-remaining', fmtInt(remaining) + ' remaining');
      var pct = clamp01(limit ? used / limit : 0);
      var bar = $('ppa-tokens-bar');
      if (bar) bar.style.width = String(Math.round(pct * 100)) + '%';
    }

    // Sites
    var sites = (root.sites && typeof root.sites === 'object') ? root.sites : {};
    var activeSites = sites.active ?? sites.count ?? root.sites_active;
    var siteLimit = sites.limit ?? root.sites_limit;
    setText('ppa-sites-active', (activeSites === undefined || activeSites === null) ? '—' : String(activeSites));
    setText('ppa-sites-limit', (siteLimit === undefined || siteLimit === null) ? '—' : String(siteLimit));
    setText('ppa-sites-label', (activeSites !== undefined && siteLimit !== undefined) ? (String(activeSites) + ' / ' + String(siteLimit)) : '—');
    renderSites(sites.list || sites.sites || root.sites_list);

    // Status
    if (root.ok === true || data.ok === true) {
      setStatus('good', 'Account synced.');
    } else if (root.ok === false || data.ok === false) {
      setStatus('bad', root.message || root.error || 'Account check failed.');
    } else {
      setStatus('', 'Account loaded.');
    }
  }

  async function fetchAccount() {
    var cfg = window.PPAAccount || {};
    if (!cfg.ajaxUrl || !cfg.nonce) {
      setStatus('bad', 'Account config missing.');
      return;
    }

    setStatus('', 'Refreshing…');

    var form = new URLSearchParams();
    form.set('action', 'ppa_account');
    form.set('nonce', cfg.nonce);

    try {
      var resp = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-PPA-Nonce': cfg.nonce
        },
        body: form.toString()
      });

      var json = await resp.json();
      // WP AJAX wrapper: { success: true, data: {...} }
      if (json && typeof json === 'object' && 'success' in json) {
        if (json.success && json.data) {
          renderFromData(json.data);
          return;
        }
        setStatus('bad', (json.data && (json.data.message || json.data.error)) ? (json.data.message || json.data.error) : 'Account request failed.');
        return;
      }

      renderFromData(json);
    } catch (e) {
      setStatus('bad', 'Could not refresh (network / JSON error).');
    }
  }

  function bind() {
    var btn = $('ppa-account-refresh');
    if (btn) {
      btn.addEventListener('click', function () {
        fetchAccount();
      });
    }

    // Auto refresh on load.
    fetchAccount();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
