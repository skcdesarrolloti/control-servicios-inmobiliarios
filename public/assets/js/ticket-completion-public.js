(function () {
  "use strict";
  document.querySelectorAll("[data-acta-print]").forEach(function (button) {
    button.addEventListener("click", function () { window.print(); });
  });
  var form = document.querySelector("[data-acta-sign-form]");
  if (!form) return;
  var canvas = form.querySelector("[data-acta-canvas]"), ctx = canvas.getContext("2d");
  var hidden = form.elements.signature_strokes, strokes = [], current = null, keyboardPoint = [150, 175], count = 0;
  var status = form.querySelector("[data-acta-draw-status]"), busy = false;
  try { strokes = JSON.parse(hidden.value || "[]"); } catch (_) { strokes = []; }
  count = strokes.reduce(function (n, s) { return n + s.length; }, 0);
  function render() {
    ctx.clearRect(0, 0, 1000, 350); ctx.strokeStyle = "#10264a"; ctx.lineWidth = 4; ctx.lineCap = "round"; ctx.lineJoin = "round";
    strokes.forEach(function (stroke) {
      ctx.beginPath(); stroke.forEach(function (p, i) { if (i === 0) ctx.moveTo(p[0], p[1]); else ctx.lineTo(p[0], p[1]); }); ctx.stroke();
    });
    hidden.value = JSON.stringify(strokes);
  }
  function point(event) {
    var rect = canvas.getBoundingClientRect();
    return [Math.round(Math.max(0, Math.min(1000, (event.clientX - rect.left) / rect.width * 1000)) * 10) / 10,
      Math.round(Math.max(0, Math.min(350, (event.clientY - rect.top) / rect.height * 350)) * 10) / 10];
  }
  function start(p) {
    if (busy || strokes.length >= 80 || count >= 1500) { status.textContent = "Alcanzaste el límite de trazos. Borra la firma para repetirla."; return; }
    current = [p]; strokes.push(current); count++; render();
  }
  function move(p) {
    if (!current || busy || count >= 1500) return;
    var last = current[current.length - 1];
    if (Math.hypot(p[0] - last[0], p[1] - last[1]) < 2) return;
    current.push(p); count++; render();
  }
  function end() {
    if (current && current.length < 2) { strokes.pop(); count--; }
    current = null; render(); status.textContent = strokes.length ? "Trazo guardado. Puedes agregar otro o borrar la firma." : "Dibuja tu firma completa.";
  }
  canvas.addEventListener("pointerdown", function (event) {
    if (!event.isPrimary || event.button !== 0 || current || busy) return;
    canvas.setPointerCapture(event.pointerId); start(point(event));
  });
  canvas.addEventListener("pointermove", function (event) { if (event.isPrimary) move(point(event)); });
  canvas.addEventListener("pointerup", end); canvas.addEventListener("pointercancel", end);
  canvas.addEventListener("keydown", function (event) {
    if (busy) return;
    if (event.code === "Space") { event.preventDefault(); if (current) end(); else { start(keyboardPoint.slice()); status.textContent = "Trazo iniciado. Usa las flechas y pulsa Espacio al terminar."; } return; }
    var delta = { ArrowLeft: [-10, 0], ArrowRight: [10, 0], ArrowUp: [0, -10], ArrowDown: [0, 10] }[event.key];
    if (!delta) return;
    event.preventDefault(); keyboardPoint = [Math.max(0, Math.min(1000, keyboardPoint[0] + delta[0])), Math.max(0, Math.min(350, keyboardPoint[1] + delta[1]))]; move(keyboardPoint.slice());
  });
  canvas.addEventListener("blur", function () { if (current) end(); });
  form.querySelector("[data-acta-clear]").addEventListener("click", function () {
    if (busy) return;
    strokes = []; current = null; count = 0; render(); status.textContent = "Firma borrada."; canvas.focus();
  });
  render();
  async function request(data) {
    var controller = new AbortController(), timeout = window.setTimeout(function () { controller.abort(); }, 30000);
    try {
      var response = await fetch(window.location.href, { method: "POST", body: data, credentials: "same-origin", headers: { Accept: "application/json" }, signal: controller.signal });
      var result = await response.json();
      if (!result || !result.ok) throw new Error(result && result.message || "No se pudo completar la operación.");
      return result;
    } finally { window.clearTimeout(timeout); }
  }
  var codeButton = document.querySelector("[data-acta-request-code]"), codeStatus = document.querySelector("[data-acta-otp-status]");
  codeButton.addEventListener("click", async function () {
    if (busy) return;
    codeButton.disabled = true; codeStatus.textContent = "Solicitando código…";
    var data = new FormData(); data.set("operation", "request_code"); data.set("channel", document.querySelector("[data-acta-otp-channel]").value); data.set("_csrf_token", form.elements._csrf_token.value);
    try { var result = await request(data); codeStatus.textContent = result.message; form.elements.otp_code.focus(); }
    catch (error) { codeStatus.textContent = error.name === "AbortError" ? "No se confirmó el envío. Revisa tu correo o WhatsApp antes de solicitar otro código." : error.message; }
    finally { codeButton.disabled = false; }
  });
  form.addEventListener("submit", async function (event) {
    event.preventDefault(); if (busy) return; end();
    if (count < 8) { status.textContent = "Dibuja tu firma completa antes de continuar."; canvas.focus(); return; }
    var data = new FormData(form), button = form.querySelector('button[type="submit"]');
    busy = true; button.disabled = true; codeButton.disabled = true; button.textContent = "Registrando firma…";
    var overlay = document.createElement("div"); overlay.className = "scm-acta-busy"; overlay.setAttribute("role", "status"); overlay.setAttribute("aria-live", "polite");
    overlay.innerHTML = '<div><span class="scm-acta-spinner" aria-hidden="true"></span><strong>Guardando tu firma</strong><p>Estamos generando el PDF y registrando el cierre. No cierres esta página.</p></div>';
    document.body.appendChild(overlay); form.setAttribute("aria-busy", "true");
    try { await request(data); window.location.reload(); }
    catch (error) { form.querySelector("[data-acta-sign-status]").textContent = error.name === "AbortError" ? "No se confirmó la respuesta. Recarga para consultar si la firma quedó registrada antes de reintentar. No se duplicará el cobro." : error.message; }
    finally { overlay.remove(); busy = false; button.disabled = false; codeButton.disabled = false; button.textContent = "Firmar acta y cerrar ticket"; form.removeAttribute("aria-busy"); }
  });
})();
