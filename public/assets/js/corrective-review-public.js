(function () {
  "use strict";

  var modal = null;
  var lastFocus = null;

  function ensureModal() {
    if (modal) {
      return modal;
    }

    modal = document.createElement("div");
    modal.className = "scm-corrective-photo-lightbox";
    modal.setAttribute("hidden", "");
    modal.innerHTML =
      '<div class="scm-corrective-photo-lightbox__backdrop" data-corrective-photo-close></div>' +
      '<section class="scm-corrective-photo-lightbox__dialog" role="dialog" aria-modal="true" aria-label="Evidencia ampliada">' +
      '<button type="button" class="scm-corrective-photo-lightbox__close" data-corrective-photo-close aria-label="Cerrar evidencia">&times;</button>' +
      '<figure>' +
      '<img class="scm-corrective-photo-lightbox__image" src="" alt="">' +
      '<figcaption class="scm-corrective-photo-lightbox__caption"></figcaption>' +
      '</figure>' +
      '</section>';
    document.body.appendChild(modal);
    return modal;
  }

  function openPhoto(button) {
    var url = button.getAttribute("data-corrective-photo-url") || "";
    var alt = button.getAttribute("data-corrective-photo-alt") || "Evidencia";
    if (!url) {
      return;
    }

    var box = ensureModal();
    var image = box.querySelector(".scm-corrective-photo-lightbox__image");
    var caption = box.querySelector(".scm-corrective-photo-lightbox__caption");
    var close = box.querySelector(".scm-corrective-photo-lightbox__close");
    lastFocus = document.activeElement;
    if (image) {
      image.src = url;
      image.alt = alt;
    }
    if (caption) {
      caption.textContent = alt;
    }
    box.removeAttribute("hidden");
    document.body.classList.add("scm-corrective-photo-open");
    if (close) {
      close.focus();
    }
  }

  function closePhoto() {
    if (!modal || modal.hasAttribute("hidden")) {
      return;
    }
    var image = modal.querySelector(".scm-corrective-photo-lightbox__image");
    modal.setAttribute("hidden", "");
    document.body.classList.remove("scm-corrective-photo-open");
    if (image) {
      image.src = "";
      image.alt = "";
    }
    if (lastFocus && typeof lastFocus.focus === "function") {
      lastFocus.focus();
    }
  }

  document.addEventListener("click", function (event) {
    var target = event.target;
    var photoButton = target && target.closest ? target.closest("[data-corrective-photo-url]") : null;
    if (photoButton) {
      event.preventDefault();
      openPhoto(photoButton);
      return;
    }

    var closeButton = target && target.closest ? target.closest("[data-corrective-photo-close]") : null;
    if (closeButton) {
      event.preventDefault();
      closePhoto();
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closePhoto();
    }
  });
})();
