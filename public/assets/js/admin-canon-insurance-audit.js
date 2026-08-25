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
    var observationAction = actions.canon_insurance_audit_observation || "";
    var reportAction = actions.canon_insurance_audit_report || "";
    var content = module.querySelector("[data-cia-content]");
    var status = module.querySelector("[data-cia-status]");
    module.dataset.ciaInit = "1";

    function updateFilenameGuide(period) {
      if (!/^\d{4}-\d{2}$/.test(period || "")) return;
      var suffix = "_" + period.slice(5, 7) + "_" + period.slice(0, 4);
      module.querySelectorAll("[data-cia-filename]").forEach(function (code) {
        code.textContent =
          code.dataset.ciaFilename + suffix + "." + code.dataset.extension;
      });
    }

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
        var expectedSuffix = period
          ? "_" + period.slice(5, 7) + "_" + period.slice(0, 4)
          : "";
        var invalidNames = files.filter(function (file) {
          return !new RegExp(
            "^(simi|libertador|fianza_bogota|unifianza)" +
              expectedSuffix +
              "\\.(xls|xlsx)$",
            "i",
          ).test(file.name);
        });
        if (invalidNames.length) {
          setStatus(
            "Nombre no valido: " +
              invalidNames.map(function (file) { return file.name; }).join(", ") +
              ". Revisa la guia y el periodo seleccionado.",
            "error",
          );
          return;
        }
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

      var observationForm = event.target.closest("[data-cia-observation-form]");
      if (observationForm) {
        event.preventDefault();
        if (!observationAction) {
          setStatus("La accion de observaciones no esta configurada.", "error");
          return;
        }
        var observationData = new FormData(observationForm);
        observationData.append("action", observationAction);
        var observationButton = observationForm.querySelector('button[type="submit"]');
        if (observationButton) observationButton.disabled = true;
        setStatus("Guardando observacion...", "loading");
        request(observationData)
          .then(function (data) {
            replaceContent(data);
            setStatus(data.message || "Observacion guardada.", "success");
          })
          .catch(function (error) {
            setStatus(error.message || "No se pudo guardar la observacion.", "error");
          })
          .finally(function () {
            if (observationButton) observationButton.disabled = false;
          });
        return;
      }

      var reportForm = event.target.closest("[data-cia-report-form]");
      if (reportForm) {
        event.preventDefault();
        if (!reportAction) {
          setStatus("La accion del informe no esta configurada.", "error");
          return;
        }
        if (!window.confirm("¿Enviar las diferencias y anomalias al Coordinador Contractual?")) return;
        var reportData = new FormData(reportForm);
        reportData.append("action", reportAction);
        var reportButton = reportForm.querySelector('button[type="submit"]');
        if (reportButton) reportButton.disabled = true;
        setStatus("Preparando y encolando el informe...", "loading");
        request(reportData)
          .then(function (data) {
            replaceContent(data);
            setStatus(data.message || "Informe encolado.", "success");
          })
          .catch(function (error) {
            setStatus(error.message || "No se pudo enviar el informe.", "error");
          })
          .finally(function () {
            if (reportButton) reportButton.disabled = false;
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
      if (event.target && event.target.name === "period") {
        updateFilenameGuide(event.target.value);
      }
      if (event.target && event.target.name === "audit_id") {
        var form = event.target.closest("[data-cia-filter-form]");
        if (form) load(new FormData(form));
      }
    });

    var initialPeriod = module.querySelector('[name="period"]');
    if (initialPeriod) updateFilenameGuide(initialPeriod.value);
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
