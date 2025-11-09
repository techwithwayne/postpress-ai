/*
 * PostPress AI — Admin Testbed JS
 *
 * CHANGE LOG
 * 2025-10-29 • Initial extract from inline: adds robust logging, JSON tolerant parsing,
 *              button disable states, and small UX niceties.                             # CHANGED:
 *
 * Expected DOM from Testbed screen (already appended to postpress-ai.php):
 * - #ppaTbTitle (input[type=text])
 * - #ppaTbContent (textarea)
 * - #ppaTbPreview (button)
 * - #ppaTbDraft (button)
 * - #ppaTbLog (div)
 */

(function () {
  "use strict";

  const $ = (sel) => document.querySelector(sel);

  // Guard: only run on the Testbed screen DOM
  const titleEl = $("#ppaTbTitle");
  const contentEl = $("#ppaTbContent");
  const btnPreview = $("#ppaTbPreview");
  const btnDraft = $("#ppaTbDraft");
  const logBox = $("#ppaTbLog");

  if (!titleEl || !contentEl || !btnPreview || !btnDraft || !logBox) {
    // Not on the testbed screen; bail
    return;
  }

  // ajaxUrl from localized script or fallback to WP admin-ajax
  const ajaxUrl =
    (window.PPA && window.PPA.ajaxUrl) ||
    (window.ajaxurl ? window.ajaxurl : (window.PPA && window.PPA.adminAjax)) ||
    (document.body.dataset && document.body.dataset.ajaxurl) ||
    "/wp-admin/admin-ajax.php";

  // Optional shared key injection, if PPA.key is provided via server-side localization
  const sharedKey = (window.PPA && window.PPA.key) || "";

  function setBusy(busy) {
    btnPreview.disabled = !!busy;
    btnDraft.disabled = !!busy;
    if (busy) {
      log("Working...");
    }
  }

  function log(msg) {
    if (!logBox) return;
    if (typeof msg === "string") {
      logBox.textContent = msg;
    } else {
      try {
        logBox.textContent = JSON.stringify(msg, null, 2);
      } catch (e) {
        logBox.textContent = String(msg);
      }
    }
  }

  async function postJSON(action, payload, opts = {}) {
    const headers = { "Content-Type": "application/json" };
    // If a shared key is configured, send it for server-to-server auth path
    if (sharedKey) headers["X-PPA-Key"] = sharedKey;

    const res = await fetch(`${ajaxUrl}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      headers,
      body: JSON.stringify(payload || {}),
      credentials: "same-origin",
    });

    const text = await res.text();
    try {
      const data = JSON.parse(text);
      // Normalize typical error shapes
      if (!res.ok && data && typeof data === "object") {
        data.http_status = res.status;
      }
      return data;
    } catch (_) {
      return { ok: false, raw: text, http_status: res.status };
    }
  }

  async function doPreview() {
    setBusy(true);
    try {
      const data = await postJSON("ppa_preview", {
        title: titleEl.value,
        content: contentEl.value,
        status: "draft",
      });
      log(data);
    } catch (e) {
      log({ ok: false, error: String(e) });
    } finally {
      setBusy(false);
    }
  }

  async function doDraft() {
    setBusy(true);
    try {
      const data = await postJSON("ppa_store", {
        title: titleEl.value,
        content: contentEl.value,
        status: "draft",
        mode: "draft",
      });
      log(data);
    } catch (e) {
      log({ ok: false, error: String(e) });
    } finally {
      setBusy(false);
    }
  }

  btnPreview.addEventListener("click", doPreview);
  btnDraft.addEventListener("click", doDraft);
})();
