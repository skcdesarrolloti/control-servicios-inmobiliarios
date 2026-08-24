(function () {
  "use strict";

  function init(root) {
    var module = root.querySelector("[data-canon-insurance-audit]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.ciaInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;

    var actions = runtime.actions || {};
    var listAction = actions.canon_insurance_audit_list || "";
    var importAction = actions.canon_insurance_audit_import || "";
    var content = module.querySelector("[data-cia-content]");
    var status = module.querySelector("[data-cia-status]");
    module.dataset.ciaInit = "1";

    function setStatus(message, tone) {
      if (!status) return;
      status.textContent = message || "";
      status.classList.toggle("is-error", tone === "error");
      status.classList.toggle("is-success", tone === "success");
      status.classList.toggle("is-loading", tone === "loading");
    }

    function request(formData) {
      formData.append("nonce", runtime.nonce || "");
      return fetch(runtime.ajaxUrl || "", {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
        .then(function (response) {
          return response.json().catch(function () {
            throw new Error("La pagina devolvio una respuesta no valida.");
          });
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo completar la auditoria.",
            );
          }
          return json.data || {};
        });
    }

    function replaceContent(data) {
      if (content && typeof data.html === "string") {
        content.innerHTML = data.html;
      }
    }

    function load(filters) {
      if (!listAction) {
        setStatus("La accion de consulta no esta configurada.", "error");
        return Promise.resolve();
      }
      var fd = filters instanceof FormData ? filters : new FormData();
      fd.append("action", listAction);
      setStatus("Consultando auditorias registradas...", "loading");
      return request(fd)
        .then(function (data) {
          replaceContent(data);
          setStatus("Auditorias actualizadas.", "success");
        })
        .catch(function (error) {
          setStatus(error.message || "No se pudo cargar la auditoria.", "error");
        });
    }

    module.addEventListener("submit", function (event) {
      var uploadForm = event.target.closest("[data-cia-upload-form]");
      if (uploadForm) {
        event.preventDefault();
        if (!importAction) {
          setStatus("La accion de importacion no esta configurada.", "error");
          return;
        }
        var fileInput = uploadForm.querySelector('input[type="file"]');
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
          setStatus("Selecciona un archivo XLS o XLSX.", "error");
          if (fileInput) fileInput.focus();
          return;
        }
        var button = uploadForm.querySelector("[data-cia-upload-button]");
        if (button) {
          button.disabled = true;
          button.setAttribute("aria-busy", "true");
          button.textContent = "Auditando...";
        }
        var uploadData = new FormData(uploadForm);
        uploadData.append("action", importAction);
        setStatus(
          "Leyendo el extracto y comparando contratos y mandatos...",
          "loading",
        );
        request(uploadData)
          .then(function (data) {
            replaceContent(data);
            setStatus(data.message || "Auditoria registrada.", "success");
            uploadForm.reset();
          })
          .catch(function (error) {
            setStatus(error.message || "No se pudo auditar el archivo.", "error");
            if (core.scmNotify) {
              core.scmNotify("error", error.message, "No se pudo auditar");
            }
          })
          .finally(function () {
            if (button) {
              button.disabled = false;
              button.removeAttribute("aria-busy");
              button.textContent = "Auditar archivo";
            }
          });
        return;
      }

      var filterForm = event.target.closest("[data-cia-filter-form]");
      if (filterForm) {
        event.preventDefault();
        load(new FormData(filterForm));
      }
    });

    module.addEventListener("change", function (event) {
      if (event.target && event.target.name === "audit_id") {
        var form = event.target.closest("[data-cia-filter-form]");
        if (form) load(new FormData(form));
      }
    });

    load();
  }

  function boot() {
    document.querySelectorAll("#scm-app").forEach(init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
