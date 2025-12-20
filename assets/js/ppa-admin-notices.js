/* global window, document */
/**
 * PostPress AI — Admin Notices Module (ES5-safe)
 *
 * Purpose:
 * - Provide reusable notice helpers (render/clear) for WP Admin screens.
 * - NO side effects on load. Only acts when its exported methods are called.
 * - Not wired into admin.js yet (one-file rule).
 *
 * Design goals:
 * - Safe by default (text nodes, not HTML)
 * - Flexible containers (caller can pass selector/element)
 * - WP-native notice classes: notice, notice-error, notice-warning, notice-success, notice-info
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.2: Merge export (no early return) to avoid clobber issues during modular cutover. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-notices.v2025-12-20.2"; // CHANGED:
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

  // ---- Container resolution -------------------------------------------------
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

  // ---- Notice building -------------------------------------------------------
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

  // ---- Public API ------------------------------------------------------------
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

  // Export (merge) // CHANGED:
  notices.ver = MOD_VER; // CHANGED:
  notices.show = show; // CHANGED:
  notices.clear = clear; // CHANGED:
  notices.replace = replace; // CHANGED:
  // low-level helpers exposed for advanced use later
  notices._buildNoticeEl = buildNoticeEl; // CHANGED:
  notices._resolveContainer = resolveContainer; // CHANGED:
  notices._typeToClass = typeToClass; // CHANGED:

  window.PPAAdminModules.notices = notices; // CHANGED:

})(window, document);
