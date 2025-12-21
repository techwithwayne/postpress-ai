/* global window, document */
/**
 * PostPress AI — Composer Store Module (ES5-safe)
 *
 * Purpose:
 * - Provide a reusable "store/save" action wrapper around:
 *   - window.PPAAdminModules.api.apiPost('ppa_store', ...)
 *   - optional payload building via window.PPAAdminModules.payloads
 *
 * IMPORTANT:
 * - NO side effects on load.
 * - Not wired into admin.js yet (one-file rule).
 *
 * WP side:
 * - Uses admin-ajax action `ppa_store` (WP proxy/controller already defines it).
 *
 * Output:
 * - Normalizes the transport + WP envelope (when present) into a stable object.
 *
 * ========= CHANGE LOG =========
 * 2025-12-21.1: Add Composer Draft/Publish helper functions + payload builder (parity targets for admin.js cutover); NO auto-wiring; store() contract unchanged. // CHANGED:
 * 2025-12-20.3: Merge export (no early return); strip ANY *El helper keys from outgoing payload to prevent leaking DOM refs/selectors while payload builders preserve unknown keys. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-store.v2025-12-21.1"; // CHANGED:
  var composerStore = window.PPAAdminModules.composerStore || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function isObject(val) {
    return !!val && (typeof val === "object");
  }

  function toStr(val) { // CHANGED:
    return (val === undefined || val === null) ? "" : String(val); // CHANGED:
  } // CHANGED:

  // CHANGED: shallow clone helper (avoid mutating caller input).
  function shallowClone(obj) { // CHANGED:
    var out = {}; // CHANGED:
    if (!obj || typeof obj !== "object") return out; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        out[k] = obj[k]; // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return out; // CHANGED:
  } // CHANGED:

  // CHANGED: Strip any helper keys that end in "El" (postEl, titleEl, contentEl, etc.)
  // Prevent DOM nodes/selectors from being sent to WP/Django while we preserve unknown keys.
  function stripElKeys(obj) { // CHANGED:
    if (!obj || typeof obj !== "object") return obj; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        if (k && k.length >= 2 && k.slice(-2) === "El") { // CHANGED:
          try { delete obj[k]; } catch (e) {} // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return obj; // CHANGED:
  } // CHANGED:

  // ---- WP ajax envelope helpers ---------------------------------------------
  function unwrapWpAjax(body) {
    // WP admin-ajax typically returns:
    // { success: true, data: {...} }  OR  { success:false, data:{...} }
    if (!isObject(body)) {
      return { hasEnvelope: false, success: null, data: body };
    }

    if (hasOwn(body, "success") && hasOwn(body, "data")) {
      return {
        hasEnvelope: true,
        success: body.success === true,
        data: body.data
      };
    }

    return { hasEnvelope: false, success: null, data: body };
  }

  // ---- Payload building ------------------------------------------------------
  function buildStorePayload(input) {
    // If payloads module exists, use it; otherwise pass through as plain object.
    var payloads = window.PPAAdminModules.payloads;

    if (payloads && typeof payloads.buildStorePayload === "function") {
      var built = payloads.buildStorePayload(input); // CHANGED:
      // CHANGED: Never leak DOM helper keys into outgoing payload.
      stripElKeys(built); // CHANGED:
      return built; // CHANGED:
    }

    // Minimal fallback (do not enforce required-ness here)
    input = input || {};

    // CHANGED: Clone to avoid mutating caller, and strip any *El helper keys.
    var payload = shallowClone(input); // CHANGED:
    stripElKeys(payload); // CHANGED:
    return payload; // CHANGED:
  }

  // ---- Public API ------------------------------------------------------------
  /**
   * store(input[, options])
   *
   * input:
   * - Prepared payload object OR an object compatible with buildStorePayload.
   *
   * options:
   * - storeDebug: boolean (default true) — stores window.PPA_LAST_STORE when complete
   * - apiOptions: passed through to apiPost (headers/timeout/ajaxUrl/nonce etc.)
   *
   * Returns:
   * - Promise/Deferred (same as api.apiPost) resolving to:
   *   {
   *     ok: boolean,              // accounts for HTTP + WP envelope success where present
   *     status: number,
   *     transport: { ...apiPostNormalized },   // raw transport object from apiPost
   *     wp: { hasEnvelope, success, data },    // parsed WP envelope if present
   *     data: any                 // wp.data or body
   *   }
   */
  function store(input, options) {
    options = options || {};

    var api = window.PPAAdminModules.api;
    if (!api || typeof api.apiPost !== "function") {
      if (window.Promise) {
        return window.Promise.resolve({
          ok: false,
          status: 0,
          error: "api_module_missing"
        });
      }
      return {
        ok: false,
        status: 0,
        error: "api_module_missing"
      };
    }

    var payload = buildStorePayload(input);

    var p = api.apiPost("ppa_store", payload, options.apiOptions || {});

    if (p && typeof p.then === "function") {
      return p.then(function (transport) {
        var wp = unwrapWpAjax(transport.body);

        // Determine overall ok:
        // - transport.ok implies HTTP 2xx
        // - if WP envelope exists, require wp.success===true
        var overallOk = transport.ok;
        if (wp.hasEnvelope) {
          overallOk = overallOk && (wp.success === true);
        }

        var data = wp.hasEnvelope ? wp.data : transport.body;

        var out = {
          ok: overallOk,
          status: transport.status,
          transport: transport,
          wp: wp,
          data: data
        };

        var storeDebug = (options.storeDebug !== undefined) ? !!options.storeDebug : true;
        if (storeDebug) {
          try {
            window.PPA_LAST_STORE = out;
          } catch (e) {
            // ignore
          }
        }

        return out;
      }, function (transportErr) {
        var wpErr = unwrapWpAjax(transportErr && transportErr.body);
        var dataErr = wpErr.hasEnvelope ? wpErr.data : (transportErr ? transportErr.body : null);

        var outErr = {
          ok: false,
          status: transportErr && transportErr.status ? transportErr.status : 0,
          transport: transportErr,
          wp: wpErr,
          data: dataErr
        };

        var storeDebug2 = (options.storeDebug !== undefined) ? !!options.storeDebug : true;
        if (storeDebug2) {
          try {
            window.PPA_LAST_STORE = outErr;
          } catch (e2) {
            // ignore
          }
        }

        // Preserve caller error flow
        throw outErr;
      });
    }

    // If apiPost returned null (no Promise + no jQuery), fail safely.
    if (window.Promise) {
      return window.Promise.resolve({
        ok: false,
        status: 0,
        error: "no_promise_support"
      });
    }

    return {
      ok: false,
      status: 0,
      error: "no_promise_support"
    };
  }

  // ---------------------------------------------------------------------------
  // Composer parity helpers (future admin.js cutover) — NO auto-wiring.         // CHANGED:
  // These functions are exported so admin.js can call them later, then we can
  // safely delete duplicated chunks from admin.js without regressions.          // CHANGED:
  // ---------------------------------------------------------------------------

  function $(sel, ctx) { return (ctx || document).querySelector(sel); } // CHANGED:

  function getEditorContent() { // CHANGED:
    // Prefer editor module if present.
    try { // CHANGED:
      var ed = window.PPAAdminModules.editor; // CHANGED:
      if (ed && typeof ed.getEditorContent === "function") return String(ed.getEditorContent() || ""); // CHANGED:
    } catch (e0) {} // CHANGED:

    // Fallbacks: Composer textarea -> TinyMCE -> WP #content textarea.
    var txt = $("#ppa-content"); // CHANGED:
    if (txt && String(txt.value || "").trim()) return String(txt.value || ""); // CHANGED:

    try { // CHANGED:
      if (window.tinyMCE && tinyMCE.get) { // CHANGED:
        var mce = tinyMCE.get("content"); // CHANGED:
        if (mce && !mce.isHidden()) return String(mce.getContent() || ""); // CHANGED:
      } // CHANGED:
    } catch (e1) {} // CHANGED:

    var raw = $("#content"); // CHANGED:
    if (raw && String(raw.value || "").trim()) return String(raw.value || ""); // CHANGED:

    return ""; // CHANGED:
  } // CHANGED:

  function safeInt(n, fallback) { // CHANGED:
    var x = parseInt(n, 10); // CHANGED:
    return isNaN(x) ? (fallback || 0) : x; // CHANGED:
  } // CHANGED:

  function buildComposerStorePayload(mode, opts) { // CHANGED:
    // mode: "draft" | "publish" | "update" (hint only; wiring decides)          // CHANGED:
    opts = opts || {}; // CHANGED:

    var titleEl = $("#ppa-title") || $("#title"); // CHANGED:
    var excerptEl = $("#ppa-excerpt") || $("#excerpt"); // CHANGED:
    var slugEl = $("#ppa-slug") || $("#post_name"); // CHANGED:

    var payload = { // CHANGED:
      mode: String(mode || ""), // CHANGED:
      post_id: safeInt(opts.post_id || opts.postId || 0, 0), // CHANGED:
      title: titleEl ? String(titleEl.value || "") : "", // CHANGED:
      excerpt: excerptEl ? String(excerptEl.value || "") : "", // CHANGED:
      slug: slugEl ? String(slugEl.value || "") : "", // CHANGED:
      content: String(getEditorContent() || ""), // CHANGED:
      meta: shallowClone(opts.meta || {}) // CHANGED:
    }; // CHANGED:

    // Normalize + preserve meta keys using payloads module (if available).      // CHANGED:
    return buildStorePayload(payload); // CHANGED:
  } // CHANGED:

  function clickGuard(btn) { // CHANGED:
    if (!btn) return false; // CHANGED:
    var ts = safeInt(btn.getAttribute("data-ppa-ts") || 0, 0); // CHANGED:
    var now = Date.now(); // CHANGED:
    if (now - ts < 350) return true; // CHANGED:
    btn.setAttribute("data-ppa-ts", String(now)); // CHANGED:
    return false; // CHANGED:
  } // CHANGED:

  function renderNotice(type, message) { // CHANGED:
    try { // CHANGED:
      var n = window.PPAAdminModules.notices; // CHANGED:
      if (n && typeof n.renderNotice === "function") { n.renderNotice(type, message); return; } // CHANGED:
    } catch (e0) {} // CHANGED:
    console.info("PPA: notice", { type: type, message: String(message || "") }); // CHANGED:
  } // CHANGED:

  function withBusy(promiseFactory, label) { // CHANGED:
    try { // CHANGED:
      var n = window.PPAAdminModules.notices; // CHANGED:
      if (n && typeof n.withBusy === "function") return n.withBusy(promiseFactory, label); // CHANGED:
    } catch (e0) {} // CHANGED:

    // Lightweight fallback (no disable buttons here; notices module owns that).
    try { // CHANGED:
      var p = promiseFactory(); // CHANGED:
      if (window.Promise) return window.Promise.resolve(p); // CHANGED:
      return p; // CHANGED:
    } catch (e1) { // CHANGED:
      renderNotice("error", "There was an error while preparing your request."); // CHANGED:
      throw e1; // CHANGED:
    } // CHANGED:
  } // CHANGED:

  function doStoreViaStableSurface(payload) { // CHANGED:
    // Prefer the known-good surface from admin.js if present (keeps behavior stable).
    try { // CHANGED:
      if (window.PPAAdmin && typeof window.PPAAdmin.postStore === "function") { // CHANGED:
        return window.PPAAdmin.postStore(payload); // CHANGED:
      } // CHANGED:
    } catch (e0) {} // CHANGED:

    // Otherwise, use this module's store() wrapper.
    return store(payload); // CHANGED:
  } // CHANGED:

  function handleStoreClick(ev, btnEl, mode, opts) { // CHANGED:
    opts = opts || {}; // CHANGED:
    if (ev && typeof ev.preventDefault === "function") ev.preventDefault(); // CHANGED:
    if (ev && typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation(); // CHANGED:
    if (ev && typeof ev.stopPropagation === "function") ev.stopPropagation(); // CHANGED:
    if (btnEl && clickGuard(btnEl)) return; // CHANGED:

    console.info("PPA: Store clicked →", mode); // CHANGED:

    var payload = (opts.payload && typeof opts.payload === "object")
      ? buildStorePayload(opts.payload) // CHANGED:
      : buildComposerStorePayload(mode, opts); // CHANGED:

    if (!toStr(payload.title).trim() && !toStr(payload.content).trim()) { // CHANGED:
      renderNotice("warn", "Add a title or some content before saving."); // CHANGED:
      return; // CHANGED:
    } // CHANGED:

    return withBusy(function () { // CHANGED:
      var p = doStoreViaStableSurface(payload); // CHANGED:
      if (!p || typeof p.then !== "function") { // CHANGED:
        renderNotice("error", "Save failed: transport unavailable."); // CHANGED:
        return null; // CHANGED:
      } // CHANGED:

      return p.then(function (res) { // CHANGED:
        if (!res || !res.ok) { // CHANGED:
          renderNotice("error", "Save failed (" + (res ? res.status : 0) + ")."); // CHANGED:
          console.info("PPA: store failed", res); // CHANGED:
          return; // CHANGED:
        } // CHANGED:

        // Success notice only; UI actions remain owned by admin.js until wired.
        renderNotice("success", (mode === "publish") ? "Post published." : "Draft saved."); // CHANGED:
      }); // CHANGED:
    }, (mode === "publish") ? "publish" : "draft"); // CHANGED:
  } // CHANGED:

  function handleDraftClick(ev, btnEl, opts) { // CHANGED:
    return handleStoreClick(ev, btnEl, "draft", opts); // CHANGED:
  } // CHANGED:

  function handlePublishClick(ev, btnEl, opts) { // CHANGED:
    return handleStoreClick(ev, btnEl, "publish", opts); // CHANGED:
  } // CHANGED:

  // Export (merge)
  composerStore.ver = MOD_VER; // CHANGED:
  composerStore.store = store; // CHANGED:

  // exposed internals for debugging/testing later
  composerStore._unwrapWpAjax = unwrapWpAjax; // CHANGED:
  composerStore._buildStorePayload = buildStorePayload; // CHANGED:

  // Parity helpers (NOT auto-wired).                                            // CHANGED:
  composerStore.buildComposerStorePayload = buildComposerStorePayload; // CHANGED:
  composerStore.handleDraftClick = handleDraftClick; // CHANGED:
  composerStore.handlePublishClick = handlePublishClick; // CHANGED:
  composerStore._handleStoreClick = handleStoreClick; // CHANGED:
  composerStore._doStoreViaStableSurface = doStoreViaStableSurface; // CHANGED:

  window.PPAAdminModules.composerStore = composerStore; // CHANGED:

})(window, document);
