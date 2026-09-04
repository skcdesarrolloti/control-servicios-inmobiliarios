(function () {
  "use strict";

  document.querySelectorAll("[data-acta-print]").forEach(function (button) {
    button.addEventListener("click", function () {
      window.print();
    });
  });

  var galleryItems = Array.prototype.slice.call(
    document.querySelectorAll("[data-acta-gallery-item]")
  );
  var gallery = null;
  var galleryImage = null;
  var galleryCaption = null;
  var galleryCount = null;
  var galleryPrev = null;
  var galleryNext = null;
  var galleryClose = null;
  var currentGalleryIndex = 0;
  var lastGalleryTrigger = null;

  function ensureGallery() {
    if (gallery) return;
    gallery = document.createElement("div");
    gallery.className = "scm-acta-gallery";
    gallery.setAttribute("role", "dialog");
    gallery.setAttribute("aria-modal", "true");
    gallery.setAttribute("aria-labelledby", "scm-acta-gallery-title");
    gallery.innerHTML =
      '<div class="scm-acta-gallery-dialog">' +
      '<div class="scm-acta-gallery-head">' +
      '<p class="scm-acta-gallery-title" id="scm-acta-gallery-title">Evidencia fotográfica <span class="scm-acta-gallery-count"></span></p>' +
      '<button type="button" class="scm-acta-gallery-close" aria-label="Cerrar galería">×</button>' +
      "</div>" +
      '<div class="scm-acta-gallery-stage">' +
      '<button type="button" class="scm-acta-gallery-nav scm-acta-gallery-prev" aria-label="Foto anterior">‹</button>' +
      '<div class="scm-acta-gallery-image-wrap"><img class="scm-acta-gallery-image" alt=""></div>' +
      '<button type="button" class="scm-acta-gallery-nav scm-acta-gallery-next" aria-label="Foto siguiente">›</button>' +
      "</div>" +
      '<p class="scm-acta-gallery-caption"></p>' +
      "</div>";
    document.body.appendChild(gallery);
    galleryImage = gallery.querySelector(".scm-acta-gallery-image");
    galleryCaption = gallery.querySelector(".scm-acta-gallery-caption");
    galleryCount = gallery.querySelector(".scm-acta-gallery-count");
    galleryPrev = gallery.querySelector(".scm-acta-gallery-prev");
    galleryNext = gallery.querySelector(".scm-acta-gallery-next");
    galleryClose = gallery.querySelector(".scm-acta-gallery-close");

    gallery.addEventListener("click", function (event) {
      if (event.target === gallery) closeGallery();
    });
    galleryClose.addEventListener("click", closeGallery);
    galleryPrev.addEventListener("click", function () {
      showGallery(currentGalleryIndex - 1);
    });
    galleryNext.addEventListener("click", function () {
      showGallery(currentGalleryIndex + 1);
    });
  }

  function showGallery(index) {
    if (!galleryItems.length) return;
    ensureGallery();
    currentGalleryIndex =
      (index + galleryItems.length) % galleryItems.length;
    var item = galleryItems[currentGalleryIndex];
    var src = item.getAttribute("data-full-src") || "";
    var caption = item.getAttribute("data-caption") || "Evidencia fotográfica";
    galleryImage.src = src;
    galleryImage.alt = caption;
    galleryCaption.textContent = caption;
    galleryCount.textContent =
      String(currentGalleryIndex + 1) + " de " + String(galleryItems.length);
    galleryPrev.hidden = galleryItems.length < 2;
    galleryNext.hidden = galleryItems.length < 2;
    gallery.classList.add("is-open");
    document.body.style.overflow = "hidden";
    galleryClose.focus();
  }

  function closeGallery() {
    if (!gallery) return;
    gallery.classList.remove("is-open");
    galleryImage.removeAttribute("src");
    document.body.style.overflow = "";
    if (lastGalleryTrigger && typeof lastGalleryTrigger.focus === "function") {
      lastGalleryTrigger.focus();
    }
  }

  galleryItems.forEach(function (item, index) {
    item.addEventListener("click", function () {
      lastGalleryTrigger = item;
      showGallery(index);
    });
  });

  document.addEventListener("keydown", function (event) {
    if (!gallery || !gallery.classList.contains("is-open")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeGallery();
    } else if (event.key === "ArrowLeft") {
      event.preventDefault();
      showGallery(currentGalleryIndex - 1);
    } else if (event.key === "ArrowRight") {
      event.preventDefault();
      showGallery(currentGalleryIndex + 1);
    }
  });

  var form = document.querySelector("[data-acta-sign-form]");
  if (!form) return;

  var busy = false;
  var codeButton = document.querySelector("[data-acta-request-code]");
  var codeStatus = document.querySelector("[data-acta-otp-status]");
  var channelSelect = document.querySelector("[data-acta-otp-channel]");
  var signStatus = form.querySelector("[data-acta-sign-status]");
  var feedbackDialog = null;
  var feedbackTitle = null;
  var feedbackMessage = null;
  var feedbackPrimary = null;
  var feedbackClose = null;
  var lastFeedbackFocus = null;

  function ensureFeedbackDialog() {
    if (feedbackDialog) return;
    feedbackDialog = document.createElement("div");
    feedbackDialog.className = "scm-acta-feedback";
    feedbackDialog.setAttribute("role", "dialog");
    feedbackDialog.setAttribute("aria-modal", "true");
    feedbackDialog.setAttribute("aria-labelledby", "scm-acta-feedback-title");
    feedbackDialog.innerHTML =
      '<div class="scm-acta-feedback-card">' +
      '<span class="scm-acta-feedback-icon" aria-hidden="true">!</span>' +
      '<h2 id="scm-acta-feedback-title">No se pudo firmar</h2>' +
      '<p data-acta-feedback-message></p>' +
      '<div class="scm-acta-feedback-actions">' +
      '<button type="button" class="scm-acta-button" data-acta-feedback-primary>Solicitar nuevo código</button>' +
      '<button type="button" class="scm-acta-button scm-acta-secondary" data-acta-feedback-close>Cerrar</button>' +
      '</div>' +
      '</div>';
    document.body.appendChild(feedbackDialog);
    feedbackTitle = feedbackDialog.querySelector("#scm-acta-feedback-title");
    feedbackMessage = feedbackDialog.querySelector("[data-acta-feedback-message]");
    feedbackPrimary = feedbackDialog.querySelector("[data-acta-feedback-primary]");
    feedbackClose = feedbackDialog.querySelector("[data-acta-feedback-close]");
    feedbackClose.addEventListener("click", closeFeedback);
    feedbackDialog.addEventListener("click", function (event) {
      if (event.target === feedbackDialog) closeFeedback();
    });
  }

  function closeFeedback() {
    if (!feedbackDialog) return;
    feedbackDialog.classList.remove("is-open");
    document.body.style.overflow = gallery && gallery.classList.contains("is-open") ? "hidden" : "";
    if (lastFeedbackFocus && typeof lastFeedbackFocus.focus === "function") {
      lastFeedbackFocus.focus();
    }
  }

  function showFeedback(options) {
    ensureFeedbackDialog();
    lastFeedbackFocus = document.activeElement;
    feedbackTitle.textContent = options.title || "No se pudo firmar";
    feedbackMessage.textContent = options.message || "Revisa los datos e inténtalo nuevamente.";
    feedbackPrimary.textContent = options.primaryLabel || "Entendido";
    feedbackPrimary.onclick = function () {
      closeFeedback();
      if (typeof options.onPrimary === "function") options.onPrimary();
    };
    feedbackPrimary.hidden = options.primaryLabel === "";
    feedbackDialog.classList.add("is-open");
    document.body.style.overflow = "hidden";
    feedbackPrimary.hidden ? feedbackClose.focus() : feedbackPrimary.focus();
  }

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
        signal: controller.signal
      });
      var result = await response.json();
      if (!result || !result.ok) {
        var error = new Error((result && result.message) || "No se pudo completar la operación.");
        error.code = (result && result.code) || "";
        throw error;
      }
      return result;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  async function requestVerificationCode() {
    if (!codeButton || busy) return;
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
        var text = error.name === "AbortError"
          ? "No se confirmó el envío. Revisa tu correo o WhatsApp antes de solicitar otro código."
          : error.message;
        if (codeStatus) {
          codeStatus.textContent = text;
        }
        showFeedback({
          title: "No se pudo enviar el código",
          message: text,
          primaryLabel: "Cerrar",
          onPrimary: function () { if (codeButton) codeButton.focus(); },
        });
      } finally {
        codeButton.disabled = false;
      }
  }

  if (codeButton) {
    codeButton.addEventListener("click", function () {
      requestVerificationCode();
    });
  }
  if (form.elements.otp_code) {
    form.elements.otp_code.addEventListener("input", function () {
      form.elements.otp_code.removeAttribute("aria-invalid");
      if (signStatus) signStatus.textContent = "";
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
      var result = await request(data);
      if (result.redirect_url) {
        window.location.assign(result.redirect_url);
      } else {
        window.location.reload();
      }
    } catch (error) {
      var text =
        error.name === "AbortError"
          ? "No se confirmó la respuesta. Recarga para consultar si la firma quedó registrada antes de reintentar. No se duplicará el cobro."
          : error.message;
      if (signStatus) {
        signStatus.textContent = text;
      }
      if (form.elements.otp_code) {
        form.elements.otp_code.setAttribute("aria-invalid", "true");
      }
      var code = error.code || "";
      if (code === "OTP_REQUIRED") {
        showFeedback({
          title: "El código venció o no está vigente",
          message: "Solicita un nuevo código de verificación y usa el último recibido antes de firmar.",
          primaryLabel: "Solicitar nuevo código",
          onPrimary: requestVerificationCode,
        });
      } else if (code === "OTP_INVALID") {
        showFeedback({
          title: "Código incorrecto",
          message: "Revisa el último código recibido. Después de 5 intentos se bloquea la verificación durante una hora.",
          primaryLabel: "Corregir código",
          onPrimary: function () {
            if (form.elements.otp_code) form.elements.otp_code.focus();
          },
        });
      } else if (code === "OTP_LIMIT") {
        showFeedback({
          title: "Límite de verificación alcanzado",
          message: "Por seguridad, espera una hora antes de solicitar o intentar otro código.",
          primaryLabel: "Cerrar",
          onPrimary: function () {
            if (form.elements.otp_code) form.elements.otp_code.focus();
          },
        });
      } else {
        showFeedback({
          title: "No se pudo firmar",
          message: text,
          primaryLabel: "Revisar datos",
          onPrimary: function () {
            if (form.elements.otp_code) form.elements.otp_code.focus();
          },
        });
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

  document.addEventListener("keydown", function (event) {
    if (!feedbackDialog || !feedbackDialog.classList.contains("is-open")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeFeedback();
    }
  });
})();
