(function () {
  "use strict";
  document.querySelectorAll("[data-acta-print]").forEach(function (button) {
    button.addEventListener("click", function () { window.print(); });
  });
  var form = document.querySelector("[data-acta-sign-form]");
  if (!form) return;
  form.addEventListener("submit", function () {
    var button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = "Registrando firma…";
    form.querySelector("[data-acta-sign-status]").textContent = "Espera mientras se guarda la firma y se cierra el ticket.";
  });
  window.addEventListener("pageshow", function () {
    var button = form.querySelector('button[type="submit"]');
    button.disabled = false;
    button.textContent = "Firmar acta y cerrar ticket";
    form.querySelector("[data-acta-sign-status]").textContent = "";
  });
})();
