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
          setStatus("Selecciona uno o varios archivos XLS o XLSX.", "error");
          if (fileInput) fileInput.focus();
          return;
        }
        var files = Array.prototype.slice.call(fileInput.files);
        var periodInput = uploadForm.querySelector('[name="period"]');
        var period = periodInput ? periodInput.value : "";
        var button = uploadForm.querySelector("[data-cia-upload-button]");
        if (button) {
          button.disabled = true;
          button.setAttribute("aria-busy", "true");
          button.textContent = "Preparando " + files.length + " archivo(s)...";
        }
        var completed = 0;
        var duplicates = 0;
        var failures = [];
        var sequence = Promise.resolve();

        files.forEach(function (file, index) {
          sequence = sequence.then(function () {
            var position = index + 1;
            var uploadData = new FormData();
            uploadData.append("action", importAction);
            uploadData.append("period", period);
            uploadData.append("file", file, file.name);
            if (button) {
              button.textContent =
                "Auditando " + position + " de " + files.length + "...";
            }
            setStatus(
              "Procesando " + position + " de " + files.length + ": " + file.name,
              "loading",
            );
            return request(uploadData)
              .then(function (data) {
                completed += 1;
                if (data.duplicate) duplicates += 1;
                replaceContent(data);
              })
              .catch(function (error) {
                failures.push({
                  name: file.name,
                  message: error.message || "No se pudo procesar el archivo.",
                });
              });
          });
        });

        sequence
          .then(function () {
            uploadForm.reset();
            var summary =
              completed +
              " de " +
              files.length +
              " archivos procesados" +
              (duplicates ? " (" + duplicates + " ya estaban registrados)" : "") +
              ".";
            if (failures.length) {
              summary +=
                " No se procesaron: " +
                failures
                  .map(function (failure) {
                    return failure.name + " — " + failure.message;
                  })
                  .join("; ");
              setStatus(summary, "error");
              if (core.scmNotify) {
                core.scmNotify(
                  "error",
                  summary,
                  "Carga finalizada con novedades",
                );
              }
            } else {
              setStatus(summary, "success");
            }
          })
          .finally(function () {
            if (button) {
              button.disabled = false;
              button.removeAttribute("aria-busy");
              button.textContent = "Auditar archivos";
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
