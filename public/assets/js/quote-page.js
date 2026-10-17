(function () {
  "use strict";
  // cotizacion.php: abre el formulario de cotización u orden con el runtime del panel y muestra
  // una tarjeta clara cuando el formulario se cierra o no se puede abrir.
  var box = document.querySelector("[data-qp-status]");
  if (!box) return;
  var action = box.getAttribute("data-bridge-action");
  var param = box.getAttribute("data-record-param");
  var recordId = box.getAttribute("data-record-id");
  var ids = param === "ticket_pk" ? { ticketPk: recordId } : { quoteId: recordId };
  var sawDialog = false;
  var closeTimer = 0;
  var openTimer = 0;

  function show(state, message) {
    box.querySelectorAll("[data-qp-state]").forEach(function (node) {
      node.hidden = node.getAttribute("data-qp-state") !== state;
    });
    if (state === "error") box.querySelector("[data-qp-error]").textContent = message || "La acción no está disponible.";
  }

  function dialogOpen() {
    return !!document.querySelector(".swal2-container");
  }

  // Varios diálogos pueden encadenarse (confirmación, formulario, aviso): se espera a que no quede ninguno.
  new MutationObserver(function () {
    if (dialogOpen()) {
      sawDialog = true;
      window.clearTimeout(closeTimer);
      window.clearTimeout(openTimer);
      box.hidden = true;
      return;
    }
    if (!sawDialog) return;
    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(function () {
      if (dialogOpen()) return;
      box.hidden = false;
      if (box.querySelector('[data-qp-state="error"]').hidden) show("closed");
      box.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 700);
  }).observe(document.body, { childList: true });

  function run() {
    sawDialog = false;
    box.hidden = false;
    show("loading");
    var attempts = 0;
    (function waitForEngine() {
      var bridge = window.SCMFuncionarioBridge;
      if (!bridge || typeof bridge.run !== "function") {
        if (++attempts > 120) { show("error", "El sistema tardó demasiado en cargar. Recarga la página."); return; }
        window.setTimeout(waitForEngine, 250);
        return;
      }
      bridge.run(action, ids, function (message) {
        box.hidden = false;
        show("error", message);
      });
      openTimer = window.setTimeout(function () {
        if (!sawDialog && !box.querySelector('[data-qp-state="loading"]').hidden) {
          show("error", "El formulario no se abrió. Puede que esta cotización no permita esta acción en su estado actual.");
        }
      }, 20000);
    })();
  }

  box.addEventListener("click", function (event) {
    if (event.target.closest("[data-qp-reopen]")) run();
  });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", run, { once: true });
  else run();
})();
