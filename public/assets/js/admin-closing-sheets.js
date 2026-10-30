// Actividades administrativas › Hojas de cierre: listado por estado y búsqueda. Ver, revisar servicios y responder se
// abren en una ventana del panel (las páginas propias con embed=1); al guardar allí, el listado se recarga.
(function () {
  "use strict";

  function init(root) {
    var module = root.querySelector("[data-closing-sheets]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.csInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.csInit = "1";
    var content = module.querySelector("[data-closing-sheets-content]");
    var search = module.querySelector("[data-closing-sheets-search]");
    var state = "";
    var modal = null;
    var dirty = false;

    function load() {
      var body = new FormData();
      body.append("action", module.dataset.action || "");
      body.append("nonce", runtime.nonce || "");
      body.append("estado", state);
      body.append("q", search ? search.elements.q.value.trim() : "");
      content.setAttribute("aria-busy", "true");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudieron cargar las hojas de cierre.");
          content.innerHTML = (json.data && json.data.html) || "";
          var active = content.querySelector('[data-closing-sheets-state][aria-pressed="true"]');
          if (active) state = active.getAttribute("data-closing-sheets-state");
          var panel = module.closest(".scm-admin-activity-panel");
          if (panel) panel.setAttribute("data-scm-loaded", "1");
        })
        .catch(function (error) {
          content.innerHTML = "";
          var p = document.createElement("p");
          p.className = "!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-red-700";
          p.textContent = error.message;
          content.appendChild(p);
        })
        .finally(function () { content.removeAttribute("aria-busy"); });
    }

    function ensureModal() {
      if (modal) return modal;
      modal = document.createElement("div");
      modal.className = "scm-cia-modal";
      modal.hidden = true;
      modal.innerHTML =
        '<div class="scm-cia-modal-backdrop" data-closing-sheets-close></div>' +
        '<section tabindex="-1" class="!sp-relative !sp-outline-none !sp-flex !sp-flex-col !sp-w-[min(1100px,calc(100vw-24px))] !sp-h-[92vh] !sp-overflow-hidden !sp-rounded-2xl !sp-bg-service-background !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-2xl !sp-font-sans" role="dialog" aria-modal="true" aria-label="Hoja de cierre">' +
        '<header class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-4 !sp-px-4 !sp-py-3 !sp-bg-white !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">' +
        '<strong class="!sp-text-sm !sp-text-service-navy">Hoja de cierre</strong>' +
        '<button type="button" class="!sp-flex !sp-items-center !sp-justify-center !sp-w-9 !sp-h-9 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-service-navy !sp-text-lg !sp-cursor-pointer hover:!sp-bg-slate-50" data-closing-sheets-close aria-label="Cerrar">&times;</button></header>' +
        '<iframe title="Hoja de cierre" class="!sp-flex-1 !sp-w-full !sp-border-0 !sp-bg-white"></iframe></section>';
      root.appendChild(modal);
      modal.addEventListener("click", function (event) {
        if (event.target.closest("[data-closing-sheets-close]")) close();
      });
      modal.addEventListener("keydown", function (event) {
        if (event.key === "Escape") close();
      });
      return modal;
    }

    function open(url) {
      var box = ensureModal();
      var hash = url.indexOf("#") >= 0 ? url.slice(url.indexOf("#")) : "";
      var base = hash ? url.slice(0, url.indexOf("#")) : url;
      box.querySelector("iframe").src = base + (base.indexOf("?") >= 0 ? "&" : "?") + "embed=1" + hash;
      box.hidden = false;
      document.body.classList.add("scm-cia-modal-open");
      box.querySelector("section").focus();
    }

    function close() {
      if (!modal) return;
      modal.hidden = true;
      modal.querySelector("iframe").src = "about:blank";
      document.body.classList.remove("scm-cia-modal-open");
      if (dirty) {
        dirty = false;
        load();
      }
    }

    // Las páginas avisan al guardar (revisión, respuesta o edición): al cerrar se recarga el listado.
    window.addEventListener("message", function (event) {
      if (event.origin !== window.location.origin || !event.data || event.data.type !== "scm-closing-sheet-saved") return;
      dirty = true;
    });

    module.addEventListener("click", function (event) {
      var tab = event.target.closest("[data-closing-sheets-state]");
      if (tab) {
        state = tab.getAttribute("data-closing-sheets-state");
        load();
        return;
      }
      var action = event.target.closest("[data-closing-sheets-open]");
      if (action) open(action.getAttribute("data-closing-sheets-open"));
    });
    if (search) {
      search.addEventListener("submit", function (event) {
        event.preventDefault();
        load();
      });
    }
    module.addEventListener("scm:load-closing-sheets", function () {
      var panel = module.closest(".scm-admin-activity-panel");
      if (panel && panel.getAttribute("data-scm-loaded") === "1") return;
      load();
    });
    var initialPanel = module.closest(".scm-admin-activity-panel");
    if (initialPanel && initialPanel.classList.contains("active")) load();
  }

  function boot() {
    document.querySelectorAll("#scm-app.scm-wrap[data-scm-runtime]").forEach(init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
