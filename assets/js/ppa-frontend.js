/*
 * PostPress AI — Admin Testbed JS
 *
 * CHANGE LOG
 * 2025-11-07 • Bind #ppaTbPreview/#ppaTbDraft; handle 429 rate-limit w/ countdown;                 // CHANGED:
 *              surface structured errors; render preview HTML + JSON trace in #ppaTbLog;           // CHANGED:
 *              safe key header (if localized), harden busy state + logs.                           // CHANGED:
 * 2025-10-29 • Initial extract from inline: robust logging, JSON tolerant parsing,                  // CHANGED:
 *              button disable states, and small UX niceties.                                       // CHANGED:
 *
 * Expected DOM on Testbed screen:
 * - #ppaTbTitle   (input[type=text])
 * - #ppaTbContent (textarea)
 * - #ppaTbPreview (button)
 * - #ppaTbDraft   (button)
 * - #ppaTbLog     (div)
 */

(function () {
  "use strict";

  const $ = (sel, ctx) => (ctx || document).querySelector(sel);

  // Guard: only run on the Testbed screen DOM
  const titleEl   = $("#ppaTbTitle");
  const contentEl = $("#ppaTbContent");
  const btnPrev   = $("#ppaTbPreview");
  const btnDraft  = $("#ppaTbDraft");
  const logBox    = $("#ppaTbLog");

  if (!titleEl || !contentEl || !btnPrev || !btnDraft || !logBox) {
    // Not on the testbed screen; bail
    return;
  }

  // ajaxUrl from localized script or fallback to WP admin-ajax
  const ajaxUrl =
    (window.PPA && window.PPA.ajaxUrl) ||
    (typeof window.ajaxurl === "string" && window.ajaxurl) ||
    (document.body.dataset && document.body.dataset.ajaxurl) ||
    "/wp-admin/admin-ajax.php";

  // Optional shared key if server localized it (never log it)
  const sharedKey = (window.PPA && window.PPA.key) || "";

  // ---------- Utilities ------------------------------------------------------

  function setBusy(busy) {
    btnPrev.disabled  = !!busy;
    btnDraft.disabled = !!busy;
    if (busy) {
      writeLog("Working…");
    }
  }

  function writeLog(msg) {
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

  function writeLogHtml(html, jsonObj) {                                           // CHANGED:
    if (!logBox) return;                                                           // CHANGED:
    const safeJson = (() => {                                                      // CHANGED:
      try { return JSON.stringify(jsonObj || {}, null, 2); } catch(_) { return ""; } // CHANGED:
    })();                                                                          // CHANGED:
    logBox.innerHTML =                                                             // CHANGED:
      String(html || "<p><em>(no html)</em></p>") +                                // CHANGED:
      '<hr><pre style="white-space:pre-wrap;word-break:break-word;margin:0;">' +   // CHANGED:
      safeJson.replace(/[&<>]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s])) +  // CHANGED:
      '</pre>';                                                                    // CHANGED:
  }                                                                                // CHANGED:

  function jsonTry(text) {
    try { return JSON.parse(text); } catch { return { ok: false, raw: String(text || "") }; }
  }

  async function postJSON(action, payload) {
    const headers = { "Content-Type": "application/json" };
    if (sharedKey) headers["X-PPA-Key"] = sharedKey; // optional                     // CHANGED:

    const res  = await fetch(`${ajaxUrl}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      headers,
      body: JSON.stringify(payload || {}),
      credentials: "same-origin",
    });

    const text = await res.text();
    const data = jsonTry(text);
    // Always expose HTTP status so the caller can branch (e.g., 429)               // CHANGED:
    if (data && typeof data === "object") data.http_status = res.status;           // CHANGED:
    return data;
  }

  // 429 rate-limit handling with countdown                                         // CHANGED:
  function handleRateLimit(data) {                                                 // CHANGED:
    const is429 = Number(data && data.http_status) === 429;                        // CHANGED:
    if (!is429) return false;                                                      // CHANGED:
    const retry = Math.max(0, Math.ceil(                                           // CHANGED:
      Number(data?.error?.details?.retry_after || 10)                              // CHANGED:
    ));                                                                            // CHANGED:
    btnPrev.disabled = true;                                                       // CHANGED:
    btnDraft.disabled = true;                                                      // CHANGED:
    let sec = retry;                                                               // CHANGED:
    writeLog(`Rate-limited. Try again in ${sec}s.`);                               // CHANGED:
    const t = setInterval(() => {                                                  // CHANGED:
      sec -= 1;                                                                    // CHANGED:
      if (sec <= 0) {                                                              // CHANGED:
        clearInterval(t);                                                          // CHANGED:
        btnPrev.disabled = false;                                                  // CHANGED:
        btnDraft.disabled = false;                                                 // CHANGED:
        writeLog("Ready.");                                                        // CHANGED:
      } else {                                                                     // CHANGED:
        writeLog(`Rate-limited. Try again in ${sec}s.`);                           // CHANGED:
      }                                                                            // CHANGED:
    }, 1000);                                                                      // CHANGED:
    return true;                                                                   // CHANGED:
  }                                                                                // CHANGED:

  function surfaceStructuredError(data) {                                          // CHANGED:
    const err = data && data.error;                                                // CHANGED:
    if (!err || typeof err !== "object") return false;                             // CHANGED:
    const type = String(err.type || "error");                                      // CHANGED:
    const msg  = String(err.message || "Request failed.");                         // CHANGED:
    writeLog(`[${type}] ${msg}`);                                                  // CHANGED:
    return true;                                                                    // CHANGED:
  }                                                                                // CHANGED:

  // ---------- Actions --------------------------------------------------------

  async function doPreview() {
    setBusy(true);
    try {
      const payload = {
        title:   titleEl.value,
        content: contentEl.value,
        status:  "draft",
      };
      const data = await postJSON("ppa_preview", payload);

      // 429 countdown?
      if (handleRateLimit(data)) return;                                           // CHANGED:
      // Structured error?
      if (!data?.ok && surfaceStructuredError(data)) return;                        // CHANGED:

      // Prefer top-level html; fall back to nested/result/html or content/raw      // CHANGED:
      const html =
        (typeof data?.html === "string" && data.html) ||
        (typeof data?.result?.html === "string" && data.result.html) ||
        (typeof data?.content === "string" && data.content) ||
        (typeof data?.preview === "string" && data.preview) ||
        (typeof data?.raw === "string" && data.raw) ||
        "";

      if (html) {
        writeLogHtml(html, data);                                                  // CHANGED:
      } else {
        writeLog(data);
      }
    } catch (e) {
      writeLog({ ok: false, error: String(e) });
    } finally {
      setBusy(false);
    }
  }

  async function doDraft() {
    setBusy(true);
    try {
      const payload = {
        title:   titleEl.value,
        content: contentEl.value,
        status:  "draft",
        mode:    "draft",
      };
      const data = await postJSON("ppa_store", payload);

      if (handleRateLimit(data)) return;                                           // CHANGED:
      if (!data?.ok && surfaceStructuredError(data)) return;                        // CHANGED:

      // Prefer id from top-level; fallback to result.id                            // CHANGED:
      const id = (data && (data.id ?? data?.result?.id)) ?? null;                  // CHANGED:
      if (id != null) {
        writeLog({ ok: true, message: "Draft saved", id: Number(id), ver: data.ver || "1" }); // CHANGED:
      } else {
        writeLog(data);
      }
    } catch (e) {
      writeLog({ ok: false, error: String(e) });
    } finally {
      setBusy(false);
    }
  }

  btnPrev .addEventListener("click", doPreview);
  btnDraft.addEventListener("click", doDraft);

  console.info("PPA: testbed.js initialized → testbed.v2025-11-07");              // CHANGED:
})();
