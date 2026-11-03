(function () {
  "use strict";
  // Revisión antes de la ocupación: el registro fotográfico (carpeta de Drive) se abre dentro de la misma página.
  document.addEventListener("click", function (event) {
    var button = event.target.closest("[data-delivery-photos]");
    if (!button) return;
    var box = button.parentElement.querySelector("[data-delivery-photos-frame]");
    if (!box) return;
    if (!box.hidden) {
      box.hidden = true;
      button.textContent = "Ver registro fotográfico";
      return;
    }
    if (!box.firstElementChild) {
      var frame = document.createElement("iframe");
      frame.src = button.getAttribute("data-delivery-photos") || "";
      frame.title = "Registro fotográfico del inmueble";
      frame.loading = "lazy";
      frame.referrerPolicy = "no-referrer";
      box.appendChild(frame);
    }
    box.hidden = false;
    button.textContent = "Ocultar registro fotográfico";
  });
})();
