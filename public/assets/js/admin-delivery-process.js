// Actividades administrativas › Proceso de entrega: listados de revisiones antes de la ocupación e inventarios.
(function () {
  "use strict";

  function init(root, module) {
    var core = window.SCMAdminCore;
    if (!core || module.dataset.dpInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.dpInit = "1";
    var content = module.querySelector("[data-delivery-process-content]");
    var search = module.querySelector("[data-delivery-process-search]");
    var page = 1;
    var loaded = false;

    function load() {
      var body = new FormData(search);
      body.append("action", module.dataset.action || "");
      body.append("nonce", runtime.nonce || "");
      body.append("kind", module.getAttribute("data-delivery-process"));
      body.append("page", String(page));
      content.setAttribute("aria-busy", "true");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo cargar el listado.");
          content.innerHTML = (json.data && json.data.html) || "";
          loaded = true;
        })
        .catch(function (error) {
          var p = document.createElement("p");
          p.className = "!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-red-700";
          p.textContent = error.message;
          content.replaceChildren(p);
        })
        .finally(function () { content.removeAttribute("aria-busy"); });
    }

    search.addEventListener("submit", function (event) {
      event.preventDefault();
      page = 1;
      load();
    });
    content.addEventListener("click", function (event) {
      var target = event.target.closest("button");
      if (!target) return;
      if (target.dataset.deliveryProcessPage) {
        page = Number(target.dataset.deliveryProcessPage) || 1;
        load();
      } else if (target.dataset.deliveryProcessEdit && typeof window.scmOpenInventoryEditor === "function") {
        window.scmOpenInventoryEditor(root, target.dataset.ticketPk, target.dataset.deliveryProcessEdit);
      }
    });
    root.addEventListener("scm:case-action-saved", function () {
      if (loaded) load();
    });
    module.addEventListener("scm:load-delivery-process", function () {
      if (!loaded) load();
    });
    var panel = module.closest(".scm-admin-activity-panel");
    if (panel && panel.classList.contains("active")) load();
  }

  function boot() {
    document.querySelectorAll("#scm-app").forEach(function (root) {
      root.querySelectorAll("[data-delivery-process]").forEach(function (module) { init(root, module); });
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot, { once: true });
  else boot();
})();
