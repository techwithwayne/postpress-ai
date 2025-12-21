/* global window, document */
/**
 * PostPress AI — Admin Notices Module (ES5-safe)
 *
 * Purpose:
 * - Provide reusable notice helpers for WP Admin screens.
 *   1) WP-native notices inserted into a container (.wrap, #ppa-notice-area, etc.)
 *   2) Composer toolbar message area (#ppa-toolbar-msg) used by admin.js (ppa-notice). // CHANGED:
 * - NO side effects on load. Only acts when its exported methods are called.
 * - Not wired into admin.js yet (one-file rule).
 *
 * Design goals:
 * - Safe by default (text nodes, not HTML)
 * - Flexible containers (caller can pass selector/element)
 * - WP-native notice classes: notice, notice-error, notice-warning, notice-success, notice-info
 *
 * ========= CHANGE LOG =========
 * 2025-12-21.2: Add clickGuard helper (parity target for admin.js cutover) + export alias; NO wiring changes. // CHANGED:
 * 2025-12-21.1: Add Composer toolbar notice + busy helpers (mirror admin.js API) without changing existing WP notice helpers. // CHANGED:
 * 2025-12-20.2: Merge export (no early return) to avoid clobber issues during modular cutover. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-notices.v2025-12-21.2"; // CHANGED:
  var notices = window.PPAAdminModules.notices || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function toStr(val) {
    return (val === undefined || val === null) ? "" : String(val);
  }

  function trim(val) {
    return toStr(val).replace(/^\s+|\s+$/g, "");
  }

  function isEl(node) {
    return !!(node && (node.nodeType === 1 || node.nodeType === 9));
  }

  function getEl(selectorOrEl) {
    if (!selectorOrEl) return null;

    if (isEl(selectorOrEl)) return selectorOrEl;

    if (typeof selectorOrEl === "string") {
      try {
        return document.querySelector(selectorOrEl);
      } catch (e) {
        return null;
      }
    }

    return null;
  }

  function removeAllChildren(el) {
    if (!el) return;
    while (el.firstChild) {
      el.removeChild(el.firstChild);
    }
  }

  // ---- Container resolution (WP notices) ------------------------------------
  function getDefaultContainer() {
    // Conservative default: if a dedicated container exists, use it.
    // We avoid guessing too hard; wiring can pass an explicit container later.
    // Common admin wrapper: .wrap
    try {
      var explicit = document.querySelector("#ppa-notice-area");
      if (explicit) return explicit;

      // Try within the current admin page header area
      var wrap = document.querySelector(".wrap");
      if (wrap) return wrap;

      return document.body;
    } catch (e) {
      return document.body;
    }
  }

  function resolveContainer(options) {
    options = options || {};
    var c = options.container || options.containerEl || options.containerSelector;
    var el = getEl(c);
    return el || getDefaultContainer();
  }

  // ---- Notice building (WP notices) -----------------------------------------
  function typeToClass(type) {
    // WP notice types: error, warning, success, info
    // Normalize aliases safely.
    var t = trim(type).toLowerCase();

    if (t === "err") t = "error";
    if (t === "warn") t = "warning";
    if (t === "ok") t = "success";

    if (t !== "error" && t !== "warning" && t !== "success" && t !== "info") {
      t = "info";
    }

    return "notice-" + t;
  }

  function buildNoticeEl(type, message, options) {
    options = options || {};

    var notice = document.createElement("div");
    notice.className = "notice " + typeToClass(type);

    if (options.dismissible) {
      notice.className += " is-dismissible";
    }

    // Optional stable hook for styling/targeted clearing
    notice.setAttribute("data-ppa-notice", options.name ? toStr(options.name) : "1");

    var p = document.createElement("p");

    // Safe by default: treat message as text.
    if (options.allowHtml) {
      // Only allow HTML when the caller explicitly opts in.
      p.innerHTML = toStr(message);
    } else {
      p.appendChild(document.createTextNode(toStr(message)));
    }

    notice.appendChild(p);

    // If dismissible, add a button similar to WP core (optional)
    // We keep it simple. WP core also wires close behavior via JS; we provide a local handler.
    if (options.dismissible) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "notice-dismiss";
      btn.setAttribute("aria-label", "Dismiss this notice");

      btn.addEventListener("click", function () {
        if (notice && notice.parentNode) {
          notice.parentNode.removeChild(notice);
        }
      });

      notice.appendChild(btn);
    }

    return notice;
  }

  // ---- Public API (WP notices) ----------------------------------------------
  /**
   * clear(options)
   *
   * Clears notices previously inserted by this module when:
   * - options.selector is provided (clears those)
   * - OR options.name is provided (clears [data-ppa-notice="<name>"])
   * - OR clears ALL [data-ppa-notice] within container
   */
  function clear(options) {
    options = options || {};
    var container = resolveContainer(options);

    if (!container) return;

    var selector = options.selector;
    var name = options.name;

    try {
      var nodes;
      if (selector) {
        nodes = container.querySelectorAll(selector);
      } else if (name) {
        nodes = container.querySelectorAll('[data-ppa-notice="' + toStr(name) + '"]');
      } else {
        nodes = container.querySelectorAll("[data-ppa-notice]");
      }

      for (var i = 0; i < nodes.length; i++) {
        if (nodes[i] && nodes[i].parentNode) {
          nodes[i].parentNode.removeChild(nodes[i]);
        }
      }
    } catch (e) {
      // If querySelectorAll fails for any reason, do nothing.
    }
  }

  /**
   * show(type, message, options)
   *
   * options:
   * - container / containerEl / containerSelector
   * - dismissible: true/false
   * - allowHtml: true/false (default false)
   * - prepend: true/false (default true)
   * - name: stable identifier (for clearing)
   * - clearBefore: true/false (default false)
   */
  function show(type, message, options) {
    options = options || {};
    var container = resolveContainer(options);

    if (!container) return null;

    if (options.clearBefore) {
      clear({ containerEl: container, name: options.name });
    }

    var noticeEl = buildNoticeEl(type, message, options);

    // Insert at top by default to mimic WP admin notice positioning
    var prepend = (options.prepend !== undefined) ? !!options.prepend : true;

    if (prepend && container.firstChild) {
      container.insertBefore(noticeEl, container.firstChild);
    } else {
      container.appendChild(noticeEl);
    }

    return noticeEl;
  }

  /**
   * replace(type, message, options)
   * Convenience: clear + show with the same name (or all).
   */
  function replace(type, message, options) {
    options = options || {};
    options.clearBefore = true;
    return show(type, message, options);
  }

  // ---- Composer toolbar notice helpers (admin.js parity) -------------------- // CHANGED:
  // These helpers are intentionally conservative and mirror the stable behavior // CHANGED:
  // in assets/js/admin.js (no contract/UI changes).                             // CHANGED:

  function getToolbarButtons() {                                                // CHANGED:
    // Mirrors admin.js IDs: #ppa-preview, #ppa-draft, #ppa-publish, #ppa-generate // CHANGED:
    return [                                                                    // CHANGED:
      getEl("#ppa-preview"),                                                    // CHANGED:
      getEl("#ppa-draft"),                                                      // CHANGED:
      getEl("#ppa-publish"),                                                    // CHANGED:
      getEl("#ppa-generate")                                                    // CHANGED:
    ];                                                                          // CHANGED:
  }                                                                             // CHANGED:

  function noticeContainer() {                                                  // CHANGED:
    // Toolbar message area used by the Composer screen.                         // CHANGED:
    var el = getEl("#ppa-toolbar-msg");                                          // CHANGED:
    if (el) return el;                                                          // CHANGED:

    // Try to anchor it in the same row as the primary buttons (admin.js parity). // CHANGED:
    var host = null;                                                            // CHANGED:
    var buttons = getToolbarButtons();                                          // CHANGED:
    for (var i = 0; i < buttons.length; i++) {                                  // CHANGED:
      if (buttons[i] && buttons[i].parentNode) {                                // CHANGED:
        host = buttons[i].parentNode;                                           // CHANGED:
        break;                                                                  // CHANGED:
      }                                                                         // CHANGED:
    }                                                                           // CHANGED:

    if (!host) {                                                                // CHANGED:
      try { console.info("PPA: noticeContainer could not find a host for toolbar msg"); } catch (e) {} // CHANGED:
      return null;                                                              // CHANGED:
    }                                                                           // CHANGED:

    el = document.createElement("div");                                         // CHANGED:
    el.id = "ppa-toolbar-msg";                                                  // CHANGED:
    el.className = "ppa-notice";                                                // CHANGED:
    try {                                                                       // CHANGED:
      el.setAttribute("role", "status");                                        // CHANGED:
      el.setAttribute("aria-live", "polite");                                   // CHANGED:
    } catch (e2) {}                                                             // CHANGED:

    // Insert *above* the buttons (admin.js parity).                             // CHANGED:
    host.insertBefore(el, host.firstChild);                                     // CHANGED:
    try { console.info("PPA: created #ppa-toolbar-msg above buttons"); } catch (e3) {} // CHANGED:
    return el;                                                                  // CHANGED:
  }                                                                             // CHANGED:

  function renderNotice(type, message) {                                        // CHANGED:
    var el = noticeContainer();                                                 // CHANGED:
    var text = String(message == null ? "" : message);                          // CHANGED:
    try { console.info("PPA: renderNotice", { type: type, message: text, hasEl: !!el }); } catch (e0) {} // CHANGED:
    if (!el) {                                                                  // CHANGED:
      // Hard fallback so you ALWAYS see something during debugging (admin.js parity). // CHANGED:
      if (type === "error" || type === "warn") {                                // CHANGED:
        try { window.alert(text); } catch (e1) {}                               // CHANGED:
      } else {                                                                  // CHANGED:
        try { console.info("PPA:", type + ":", text); } catch (e2) {}           // CHANGED:
      }                                                                         // CHANGED:
      return;                                                                   // CHANGED:
    }                                                                           // CHANGED:
    var clsBase = "ppa-notice", clsType = "ppa-notice-" + type;                 // CHANGED:
    el.className = clsBase + " " + clsType;                                     // CHANGED:
    el.textContent = text;                                                      // CHANGED:

    // If for some reason it's still visually hidden, alert as a backup (admin.js parity). // CHANGED:
    try {                                                                       // CHANGED:
      var visible = el.offsetWidth > 0 && el.offsetHeight > 0;                  // CHANGED:
      if (!visible && (type === "error" || type === "warn")) {                  // CHANGED:
        window.alert(text);                                                     // CHANGED:
      }                                                                         // CHANGED:
    } catch (e3) {}                                                             // CHANGED:
  }                                                                             // CHANGED:

  function renderNoticeTimed(type, message, ms) {                               // CHANGED:
    renderNotice(type, message);                                                // CHANGED:
    if (ms && ms > 0) setTimeout(clearNotice, ms);                              // CHANGED:
  }                                                                             // CHANGED:

  function renderNoticeHtml(type, html) {                                       // CHANGED:
    var el = noticeContainer();                                                 // CHANGED:
    if (!el) {                                                                  // CHANGED:
      try { console.info("PPA:", type + ":", html); } catch (e0) {}             // CHANGED:
      return;                                                                   // CHANGED:
    }                                                                           // CHANGED:
    var clsBase = "ppa-notice", clsType = "ppa-notice-" + type;                 // CHANGED:
    el.className = clsBase + " " + clsType;                                     // CHANGED:
    el.innerHTML = String(html || "");                                          // CHANGED:
  }                                                                             // CHANGED:

  function renderNoticeTimedHtml(type, html, ms) {                              // CHANGED:
    renderNoticeHtml(type, html);                                               // CHANGED:
    if (ms && ms > 0) setTimeout(clearNotice, ms);                              // CHANGED:
  }                                                                             // CHANGED:

  function clearNotice() {                                                     // CHANGED:
    var el = noticeContainer();                                                 // CHANGED:
    if (el) { el.className = "ppa-notice"; el.textContent = ""; }               // CHANGED:
  }                                                                             // CHANGED:

  function setButtonsDisabled(disabled) {                                       // CHANGED:
    var arr = getToolbarButtons();                                              // CHANGED:
    for (var i = 0; i < arr.length; i++) {                                      // CHANGED:
      var b = arr[i];                                                          // CHANGED:
      if (!b) continue;                                                        // CHANGED:
      b.disabled = !!disabled;                                                  // CHANGED:
      if (disabled) { b.setAttribute("aria-busy", "true"); }                    // CHANGED:
      else { b.removeAttribute("aria-busy"); }                                  // CHANGED:
    }                                                                           // CHANGED:
  }                                                                             // CHANGED:

  function withBusy(promiseFactory, label) {                                    // CHANGED:
    setButtonsDisabled(true);                                                   // CHANGED:
    clearNotice();                                                              // CHANGED:
    var tag = label || "request";                                               // CHANGED:
    try { console.info("PPA: busy start →", tag); } catch (e0) {}               // CHANGED:
    try {                                                                       // CHANGED:
      // Match admin.js behavior: wrap promiseFactory(), show generic error notices, always re-enable buttons. // CHANGED:
      var p = promiseFactory();                                                 // CHANGED:
      var chain = (window.Promise ? window.Promise.resolve(p) : p);             // CHANGED:

      // If promiseFactory doesn't return a thenable, normalize to a resolved promise (best-effort). // CHANGED:
      if (chain && typeof chain.then === "function") {                          // CHANGED:
        // ok                                                                   // CHANGED:
      } else if (window.Promise) {                                              // CHANGED:
        chain = window.Promise.resolve(chain);                                  // CHANGED:
      }                                                                         // CHANGED:

      // Catch + finally with backward-safe fallback.                           // CHANGED:
      var caught = chain.then(function (v) { return v; }).catch(function (err) { // CHANGED:
        try { console.info("PPA: busy error on", tag, err); } catch (e1) {}     // CHANGED:
        renderNotice("error", "There was an error while processing your request."); // CHANGED:
        throw err;                                                             // CHANGED:
      });                                                                      // CHANGED:

      if (caught && typeof caught.finally === "function") {                    // CHANGED:
        return caught.finally(function () {                                    // CHANGED:
          setButtonsDisabled(false);                                            // CHANGED:
          try { console.info("PPA: busy end ←", tag); } catch (e2) {}           // CHANGED:
        });                                                                    // CHANGED:
      }                                                                        // CHANGED:

      // Fallback if .finally is unavailable.                                   // CHANGED:
      return caught.then(function (v2) {                                        // CHANGED:
        setButtonsDisabled(false);                                              // CHANGED:
        try { console.info("PPA: busy end ←", tag); } catch (e3) {}             // CHANGED:
        return v2;                                                             // CHANGED:
      }, function (e4) {                                                       // CHANGED:
        setButtonsDisabled(false);                                              // CHANGED:
        try { console.info("PPA: busy end ←", tag); } catch (e5) {}             // CHANGED:
        throw e4;                                                              // CHANGED:
      });                                                                      // CHANGED:
    } catch (e6) {                                                             // CHANGED:
      setButtonsDisabled(false);                                               // CHANGED:
      try { console.info("PPA: busy sync error on", tag, e6); } catch (e7) {}  // CHANGED:
      renderNotice("error", "There was an error while preparing your request."); // CHANGED:
      throw e6;                                                                // CHANGED:
    }                                                                          // CHANGED:
  }                                                                             // CHANGED:

  function clickGuard(btn, ms) {                                                // CHANGED:
    // Parity target for admin.js: prevent rapid double-clicks.                  // CHANGED:
    if (!btn) return false;                                                     // CHANGED:
    var windowMs = (ms && ms > 0) ? ms : 350;                                   // CHANGED:
    var ts = Number(btn.getAttribute("data-ppa-ts") || 0);                      // CHANGED:
    var now = (Date.now ? Date.now() : (new Date()).getTime());                 // CHANGED:
    if (now - ts < windowMs) return true;                                       // CHANGED:
    btn.setAttribute("data-ppa-ts", String(now));                               // CHANGED:
    return false;                                                               // CHANGED:
  }                                                                             // CHANGED:

  // ---- Export (merge) -------------------------------------------------------
  // Existing WP notice API (do not break other modules)
  notices.ver = MOD_VER; // CHANGED:
  notices.show = show; // CHANGED:
  notices.clear = clear; // CHANGED:
  notices.replace = replace; // CHANGED:
  // low-level helpers exposed for advanced use later
  notices._buildNoticeEl = buildNoticeEl; // CHANGED:
  notices._resolveContainer = resolveContainer; // CHANGED:
  notices._typeToClass = typeToClass; // CHANGED:

  // Composer toolbar notice API (admin.js parity)
  notices.noticeContainer = noticeContainer; // CHANGED:
  notices.renderNotice = renderNotice; // CHANGED:
  notices.renderNoticeTimed = renderNoticeTimed; // CHANGED:
  notices.renderNoticeHtml = renderNoticeHtml; // CHANGED:
  notices.renderNoticeTimedHtml = renderNoticeTimedHtml; // CHANGED:
  notices.clearNotice = clearNotice; // CHANGED:
  notices.setButtonsDisabled = setButtonsDisabled; // CHANGED:
  notices.withBusy = withBusy; // CHANGED:
  notices.clickGuard = clickGuard; // CHANGED:

  // Friendly aliases for future module callers (non-breaking)
  if (!hasOwn(notices, "render")) notices.render = renderNotice; // CHANGED:
  if (!hasOwn(notices, "renderTimed")) notices.renderTimed = renderNoticeTimed; // CHANGED:
  if (!hasOwn(notices, "renderHtml")) notices.renderHtml = renderNoticeHtml; // CHANGED:
  if (!hasOwn(notices, "renderTimedHtml")) notices.renderTimedHtml = renderNoticeTimedHtml; // CHANGED:

  window.PPAAdminModules.notices = notices; // CHANGED:

})(window, document);
