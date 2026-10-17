(function () {
  "use strict";
  // revision.php: abre el editor de revisión del caso con el mismo código que el popup del panel.
  function start() {
    var core = window.SCMAdminCore;
    var caseBtn = document.querySelector("[data-scm-review-case]");
    var modal = document.getElementById("scm-case-modal");
    var status = document.querySelector("[data-scm-review-status]");
    if (!caseBtn || !modal || !status) return;
    var kind = caseBtn.getAttribute("data-review-kind");
    if (!core || typeof core.openDamageReviewEditor !== "function") {
      status.textContent = "No se pudo cargar el editor. Recarga la página.";
      status.classList.add("is-error");
      return;
    }
    var open = function () {
      status.hidden = true;
      core.openDamageReviewEditor(modal, caseBtn, kind);
    };
    open();
    // En la página no hay popup que desplazar: al cambiar de paso se sube al inicio del formulario.
    modal.addEventListener("click", function (event) {
      if (!event.target.closest("[data-prev-next], [data-prev-back], [data-prev-goto]")) return;
      window.setTimeout(function () {
        var card = modal.closest(".rp-card") || modal;
        var top = card.getBoundingClientRect().top + window.scrollY - 80;
        if (window.scrollY > top) window.scrollTo({ top: top, behavior: "smooth" });
      }, 50);
    });
    // Si el editor se cierra (p. ej. tras eliminar), se ofrece volver a abrirlo.
    var sub = modal.querySelector(".scm-case-submodal");
    if (sub && window.MutationObserver) {
      new MutationObserver(function () {
        if (sub.classList.contains("open")) return;
        status.hidden = false;
        status.classList.remove("is-error");
        status.innerHTML = 'El editor se cerró.<button type="button" data-scm-review-reopen>Abrir de nuevo</button><a href="index.php">Ir al panel</a>';
        status.querySelector("[data-scm-review-reopen]").onclick = open;
      }).observe(sub, { attributes: true, attributeFilter: ["class"] });
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
