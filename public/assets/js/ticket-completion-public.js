(function () {
  "use strict";

  document.querySelectorAll("[data-acta-print]").forEach(function (button) {
    button.addEventListener("click", function () {
      window.print();
    });
  });

  var form = document.querySelector("[data-acta-sign-form]");
  if (!form) return;

  var busy = false;
  var codeButton = document.querySelector("[data-acta-request-code]");
  var codeStatus = document.querySelector("[data-acta-otp-status]");
  var channelSelect = document.querySelector("[data-acta-otp-channel]");
  var signStatus = form.querySelector("[data-acta-sign-status]");

  async function request(data) {
    var controller = new AbortController();
    var timeout = window.setTimeout(function () {
      controller.abort();
    }, 30000);
    try {
      var response = await fetch(window.location.href, {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        signal: controller.signal,
      });
      var result = await response.json();
      if (!result || !result.ok) {
        throw new Error(
          (result && result.message) || "No se pudo completar la operación.",
        );
      }
      return result;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  if (codeButton) {
    codeButton.addEventListener("click", async function () {
      if (busy) return;
      codeButton.disabled = true;
      if (codeStatus) codeStatus.textContent = "Solicitando código…";
      var data = new FormData();
      data.set("operation", "request_code");
      data.set("channel", channelSelect ? channelSelect.value : "email");
      data.set("_csrf_token", form.elements._csrf_token.value);
      try {
        var result = await request(data);
        if (codeStatus) codeStatus.textContent = result.message;
        if (form.elements.otp_code) form.elements.otp_code.focus();
      } catch (error) {
        if (codeStatus) {
          codeStatus.textContent =
            error.name === "AbortError"
              ? "No se confirmó el envío. Revisa tu correo o WhatsApp antes de solicitar otro código."
              : error.message;
        }
      } finally {
        codeButton.disabled = false;
      }
    });
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    if (busy) return;
    if (typeof form.reportValidity === "function" && !form.reportValidity()) {
      return;
    }
    var data = new FormData(form);
    var button = form.querySelector('button[type="submit"]');
    busy = true;
    if (button) {
      button.disabled = true;
      button.textContent = "Registrando firma…";
    }
    if (codeButton) codeButton.disabled = true;
    var overlay = document.createElement("div");
    overlay.className = "scm-acta-busy";
    overlay.setAttribute("role", "status");
    overlay.setAttribute("aria-live", "polite");
    overlay.innerHTML =
      '<div><span class="scm-acta-spinner" aria-hidden="true"></span><strong>Guardando tu firma</strong><p>Estamos generando el PDF y registrando el cierre. No cierres esta página.</p></div>';
    document.body.appendChild(overlay);
    form.setAttribute("aria-busy", "true");
    try {
      await request(data);
      window.location.reload();
    } catch (error) {
      if (signStatus) {
        signStatus.textContent =
          error.name === "AbortError"
            ? "No se confirmó la respuesta. Recarga para consultar si la firma quedó registrada antes de reintentar. No se duplicará el cobro."
            : error.message;
      }
    } finally {
      overlay.remove();
      busy = false;
      if (button) {
        button.disabled = false;
        button.textContent = "Firmar acta y cerrar ticket";
      }
      if (codeButton) codeButton.disabled = false;
      form.removeAttribute("aria-busy");
    }
  });
})();
