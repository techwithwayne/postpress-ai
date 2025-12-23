/* global window, document */
/**
 * PostPress AI — Composer Preview Module (ES5-safe)
 *
 * Purpose:
 * - Provide a thin helper around generateView to render results into a Composer preview pane.
 * - NO side effects on load.
 *
 * IMPORTANT (Composer stability):
 * - Preview MUST render ONLY into the right-side pane.
 * - Canonical pane ALWAYS wins if present.
 *
 * ========= CHANGE LOG =========
 * 2025-12-22.2: Version bump only. No behavioral changes. File confirmed stable for release. // CHANGED:
 * 2025-12-22.1: Safety hardening: canonical pane enforcement, reject bad targets, try/catch render guards.
 */

(function (window, document) {
  "use strict";

  window.PPAAdminModules = window.PPAAdminModules || {};

  var MOD_VER = "ppa-admin-composer-preview.v2025-12-22.2"; // CHANGED:
  var composerPreview = window.PPAAdminModules.composerPreview || {};

  function isEl(node) {
    return !!(node && (node.nodeType === 1 || node.nodeType === 9));
  }

  function getEl(selectorOrEl) {
    if (!selectorOrEl) return null;
    if (isEl(selectorOrEl)) return selectorOrEl;
    if (typeof selectorOrEl === "string") {
      try { return document.querySelector(selectorOrEl); }
      catch (e) { return null; }
    }
    return null;
  }

  function isPreviewPaneCandidate(el) {
    if (!el || el.nodeType !== 1) return false;
    var tag = (el.tagName || "").toUpperCase();
    if (tag === "INPUT" || tag === "TEXTAREA" || tag === "FORM" || tag === "BUTTON" || tag === "SELECT") {
      return false;
    }
    return true;
  }

  function getComposerRoot() {
    try {
      return (
        document.getElementById("ppa-composer") ||
        document.getElementById("ppa-composer-app") ||
        document.querySelector("[data-ppa-composer]") ||
        document.querySelector(".ppa-composer") ||
        document.querySelector(".ppa-composer-layout") ||
        null
      );
    } catch (e) {
      return null;
    }
  }

  function isLikelyPreviewPane(el) {
    if (!isPreviewPaneCandidate(el)) return false;

    var id = (el.id || "");
    if (id === "ppa-preview-pane" || id === "ppa-composer-preview-pane" || id === "ppa-composer-preview") {
      return true;
    }

    try {
      if (el.getAttribute && el.getAttribute("data-ppa-preview-pane") !== null) {
        return true;
      }
    } catch (e) {}

    var cls = (el.className || "");
    if (typeof cls === "string") {
      if (cls.indexOf("ppa-preview-pane") !== -1 || cls.indexOf("ppa-composer-preview-pane") !== -1) {
        return true;
      }
    }
    return false;
  }

  function getCanonicalPreviewPane() {
    try {
      var el = document.getElementById("ppa-preview-pane");
      if (isPreviewPaneCandidate(el)) return el;

      el = document.getElementById("ppa-composer-preview-pane");
      if (isPreviewPaneCandidate(el)) return el;

      el = document.getElementById("ppa-composer-preview");
      if (isPreviewPaneCandidate(el)) return el;

      var root = getComposerRoot() || document;
      var selectors = [
        "[data-ppa-preview-pane='1']",
        "[data-ppa-preview-pane='true']",
        "[data-ppa-preview-pane]",
        ".ppa-preview-pane",
        ".ppa-composer-preview-pane"
      ];

      for (var i = 0; i < selectors.length; i++) {
        try {
          var found = root.querySelector(selectors[i]);
          if (isPreviewPaneCandidate(found)) return found;
        } catch (e1) {}
      }
      return null;
    } catch (e2) {
      return null;
    }
  }

  function findPreviewContainerFallback() {
    return getCanonicalPreviewPane();
  }

  function resolvePreviewContainer(options) {
    options = options || {};

    var canonical = getCanonicalPreviewPane();
    if (canonical) return canonical;

    var el =
      getEl(options.container) ||
      getEl(options.containerEl) ||
      getEl(options.containerSelector);

    if (options.allowNonCanonical && el && isPreviewPaneCandidate(el)) {
      return el;
    }

    if (!options.allowNonCanonical && el && isLikelyPreviewPane(el)) {
      return el;
    }

    if (options.allowFallbackScan) {
      return findPreviewContainerFallback();
    }

    return null;
  }

  function render(containerOrSelector, result, options) {
    options = options || {};

    var container = resolvePreviewContainer({
      container: containerOrSelector,
      allowFallbackScan: !!options.allowFallbackScan,
      allowNonCanonical: !!options.allowNonCanonical
    });

    if (!container) return { ok: false, error: "preview_container_not_found" };
    if (!window.PPAAdminModules.generateView ||
        typeof window.PPAAdminModules.generateView.renderPreview !== "function") {
      return { ok: false, error: "generate_view_module_missing" };
    }

    try {
      var rendered = window.PPAAdminModules.generateView.renderPreview(container, result, {
        allowMarked: !!options.allowMarked,
        mode: options.mode || "replace"
      });
      return { ok: true, rendered: rendered };
    } catch (e) {
      return { ok: false, error: "render_failed" };
    }
  }

  function clear(containerOrSelector, options) {
    options = options || {};

    var container = resolvePreviewContainer({
      container: containerOrSelector,
      allowFallbackScan: !!options.allowFallbackScan,
      allowNonCanonical: !!options.allowNonCanonical
    });

    if (!container) return { ok: false, error: "preview_container_not_found" };

    while (container.firstChild) {
      container.removeChild(container.firstChild);
    }
    return { ok: true };
  }

  composerPreview.ver = MOD_VER;
  composerPreview.resolvePreviewContainer = resolvePreviewContainer;
  composerPreview.render = render;
  composerPreview.clear = clear;

  composerPreview._findPreviewContainerFallback = findPreviewContainerFallback;
  composerPreview._getCanonicalPreviewPane = getCanonicalPreviewPane;
  composerPreview._getComposerRoot = getComposerRoot;
  composerPreview._isLikelyPreviewPane = isLikelyPreviewPane;

  window.PPAAdminModules.composerPreview = composerPreview;

})(window, document);
