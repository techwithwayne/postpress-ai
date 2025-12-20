/* global window, document */
/**
 * PostPress AI — Composer Preview Module (ES5-safe)
 *
 * Purpose:
 * - Provide a thin helper around generateView to render results into a Composer preview pane.
 * - NO side effects on load.
 * - Not wired into admin.js yet (one-file rule).
 *
 * Contracts:
 * - Exposes: window.PPAAdminModules.composerPreview
 * - Does NOT assume specific DOM IDs. Caller can pass a container selector/element.
 * - If no container is found, it fails safely (returns { ok:false }).
 *
 * ========= CHANGE LOG =========
 * 2025-12-20.2: Merge export (no early return) to avoid late-load clobber during modular cutover; no behavior change. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-preview.v2025-12-20.2"; // CHANGED:
  var composerPreview = window.PPAAdminModules.composerPreview || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
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

  // Conservative fallback selectors (non-breaking because this module is non-wired).
  // Caller should pass the real container used by admin.js once we wire it.
  function findPreviewContainerFallback() {
    var selectors = [
      "#ppa-preview",
      "#ppa_preview",
      "#ppa-preview-pane",
      ".ppa-preview-pane",
      ".ppa-preview"
    ];

    for (var i = 0; i < selectors.length; i++) {
      var el = getEl(selectors[i]);
      if (el) return el;
    }

    return null;
  }

  function resolvePreviewContainer(options) {
    options = options || {};

    // Preferred explicit inputs
    var el =
      getEl(options.container) ||
      getEl(options.containerEl) ||
      getEl(options.containerSelector);

    if (el) return el;

    // Optional fallback scan (safe; returns null if not found)
    if (options.allowFallbackScan) {
      return findPreviewContainerFallback();
    }

    return null;
  }

  /**
   * render(containerOrSelector, result, options)
   *
   * - containerOrSelector: DOM element OR selector string. If omitted, requires options.allowFallbackScan=true.
   * - result: any supported result shape (generateView will normalize)
   * - options:
   *   - allowMarked: bool (passed through to generateView)
   *   - mode: "replace" (default) or "append"
   *   - allowFallbackScan: bool (default false) — tries common selectors
   *
   * Returns:
   * - { ok:true, rendered:{...} } or { ok:false, error:"..." }
   */
  function render(containerOrSelector, result, options) {
    options = options || {};

    var container = resolvePreviewContainer({
      container: containerOrSelector,
      allowFallbackScan: !!options.allowFallbackScan
    });

    if (!container) {
      return { ok: false, error: "preview_container_not_found" };
    }

    if (!window.PPAAdminModules.generateView || typeof window.PPAAdminModules.generateView.renderPreview !== "function") {
      return { ok: false, error: "generate_view_module_missing" };
    }

    var rendered = window.PPAAdminModules.generateView.renderPreview(container, result, {
      allowMarked: !!options.allowMarked,
      mode: options.mode || "replace"
    });

    return { ok: true, rendered: rendered };
  }

  /**
   * clear(containerOrSelector, options)
   * Clears preview container content safely.
   */
  function clear(containerOrSelector, options) {
    options = options || {};

    var container = resolvePreviewContainer({
      container: containerOrSelector,
      allowFallbackScan: !!options.allowFallbackScan
    });

    if (!container) {
      return { ok: false, error: "preview_container_not_found" };
    }

    // Clear safely
    while (container.firstChild) {
      container.removeChild(container.firstChild);
    }

    return { ok: true };
  }

  // ---- Public export (merge) -------------------------------------------------
  composerPreview.ver = MOD_VER; // CHANGED:
  composerPreview.resolvePreviewContainer = resolvePreviewContainer; // CHANGED:
  composerPreview.render = render; // CHANGED:
  composerPreview.clear = clear; // CHANGED:

  // low-level helper (kept for future wiring)
  composerPreview._findPreviewContainerFallback = findPreviewContainerFallback; // CHANGED:

  window.PPAAdminModules.composerPreview = composerPreview; // CHANGED:

})(window, document);
