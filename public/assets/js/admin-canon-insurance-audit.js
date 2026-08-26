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
    var mandatesAction = actions.canon_insurance_audit_mandates || "";
    var linkMandateAction = actions.canon_insurance_audit_link_mandate || "";
    var requestsAction = actions.canon_insurance_audit_requests || "";
    var updateRequestAction = actions.canon_insurance_audit_update_request || "";
    var content = module.querySelector("[data-cia-content]");
    var status = module.querySelector("[data-cia-status]");
    var mandateModal = module.querySelector("[data-cia-mandate-modal]");
    var mandateContent = module.querySelector("[data-cia-mandate-content]");
    var mandateStatus = module.querySelector("[data-cia-mandate-status]");
    var requestModal = module.querySelector("[data-cia-request-modal]");
    var requestContent = module.querySelector("[data-cia-request-content]");
    var requestStatus = module.querySelector("[data-cia-request-status]");
    var currentMandateContractId = "";
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

    function insurerGroupKey(group, index) {
      var title = group ? group.querySelector(".scm-cia-insurer-title b") : null;
      var text = title ? title.textContent.trim() : "";
      return text || "group-" + index;
    }

    function captureContentContext() {
      var context = {
        windowY: window.pageYOffset || document.documentElement.scrollTop || 0,
        openGroups: [],
        tableScroll: {},
      };
      if (!content) return context;
      content.querySelectorAll(".scm-cia-insurer-group").forEach(function (group, index) {
        var key = insurerGroupKey(group, index);
        if (group.open) context.openGroups.push(key);
        var tableWrap = group.querySelector(".scm-cia-table-wrap");
        if (tableWrap) {
          context.tableScroll[key] = {
            left: tableWrap.scrollLeft || 0,
            top: tableWrap.scrollTop || 0,
          };
        }
      });
      return context;
    }

    function restoreContentContext(context, focusContractId) {
      if (!context || !content) return;
      window.requestAnimationFrame(function () {
        var openGroups = context.openGroups || [];
        var tableScroll = context.tableScroll || {};
        content.querySelectorAll(".scm-cia-insurer-group").forEach(function (group, index) {
          var key = insurerGroupKey(group, index);
          if (openGroups.indexOf(key) !== -1) group.open = true;
          var tableWrap = group.querySelector(".scm-cia-table-wrap");
          if (tableWrap && tableScroll[key]) {
            tableWrap.scrollLeft = tableScroll[key].left || 0;
            tableWrap.scrollTop = tableScroll[key].top || 0;
          }
        });
        if (typeof context.windowY === "number") {
          window.scrollTo({ top: context.windowY, behavior: "auto" });
        }
        if (focusContractId) {
          var safeContractId = String(focusContractId).replace(/"/g, "");
          var row = content.querySelector('[data-cia-contract-id="' + safeContractId + '"]');
          if (row) {
            row.classList.add("scm-cia-row-flash");
            window.setTimeout(function () {
              row.classList.remove("scm-cia-row-flash");
            }, 2200);
          }
        }
      });
    }

    function replaceContentKeepingPlace(data, focusContractId) {
      var context = captureContentContext();
      replaceContent(data);
      restoreContentContext(context, focusContractId || "");
    }

    function setMandateStatus(message, tone) {
      if (!mandateStatus) return;
      mandateStatus.textContent = message || "";
      mandateStatus.classList.toggle("is-error", tone === "error");
      mandateStatus.classList.toggle("is-success", tone === "success");
      mandateStatus.classList.toggle("is-loading", tone === "loading");
    }

    function openMandateModal(searchValue, contractId) {
      if (!mandateModal) return;
      currentMandateContractId = contractId ? String(contractId) : "";
      mandateModal.hidden = false;
      document.documentElement.classList.add("scm-cia-modal-open");
      var searchInput = mandateModal.querySelector('[name="search"]');
      if (searchInput && typeof searchValue === "string") searchInput.value = searchValue;
      if (searchInput) window.setTimeout(function () { searchInput.focus(); }, 50);
      loadMandates();
    }

    function closeMandateModal() {
      if (!mandateModal) return;
      mandateModal.hidden = true;
      currentMandateContractId = "";
      document.documentElement.classList.remove("scm-cia-modal-open");
    }

    function currentMandateSearch() {
      var searchInput = mandateModal ? mandateModal.querySelector('[name="search"]') : null;
      return searchInput ? searchInput.value || "" : "";
    }

    function loadMandates(search) {
      if (!mandatesAction) {
        setMandateStatus("La accion de mandatos no esta configurada.", "error");
        return Promise.resolve();
      }
      var fd = new FormData();
      fd.append("action", mandatesAction);
      fd.append("search", typeof search === "string" ? search : currentMandateSearch());
      if (currentMandateContractId) fd.append("contract_id", currentMandateContractId);
      setMandateStatus("Consultando contratos sin mandato...", "loading");
      return request(fd)
        .then(function (data) {
          if (mandateContent && typeof data.html === "string") {
            mandateContent.innerHTML = data.html;
          }
          setMandateStatus("Contratos pendientes actualizados.", "success");
        })
        .catch(function (error) {
          setMandateStatus(error.message || "No se pudieron consultar los mandatos.", "error");
      });
    }

    function setRequestStatus(message, tone) {
      if (!requestStatus) return;
      requestStatus.textContent = message || "";
      requestStatus.classList.toggle("is-error", tone === "error");
      requestStatus.classList.toggle("is-success", tone === "success");
      requestStatus.classList.toggle("is-loading", tone === "loading");
    }

    function openRequestModal(searchValue) {
      if (!requestModal) return;
      requestModal.hidden = false;
      document.documentElement.classList.add("scm-cia-modal-open");
      var searchInput = requestModal.querySelector('[name="search"]');
      if (searchInput && typeof searchValue === "string") searchInput.value = searchValue;
      if (searchInput) window.setTimeout(function () { searchInput.focus(); }, 50);
      loadRequests();
    }

    function closeRequestModal() {
      if (!requestModal) return;
      requestModal.hidden = true;
      document.documentElement.classList.remove("scm-cia-modal-open");
    }

    function currentRequestSearch() {
      var searchInput = requestModal ? requestModal.querySelector('[name="search"]') : null;
      return searchInput ? searchInput.value || "" : "";
    }

    function loadRequests(search) {
      if (!requestsAction) {
        setRequestStatus("La accion de solicitudes no esta configurada.", "error");
        return Promise.resolve();
      }
      var fd = new FormData();
      fd.append("action", requestsAction);
      fd.append("search", typeof search === "string" ? search : currentRequestSearch());
      setRequestStatus("Consultando contratos sin solicitud...", "loading");
      return request(fd)
        .then(function (data) {
          if (requestContent && typeof data.html === "string") {
            requestContent.innerHTML = data.html;
          }
          setRequestStatus("Contratos sin solicitud actualizados.", "success");
        })
        .catch(function (error) {
          setRequestStatus(error.message || "No se pudieron consultar las solicitudes.", "error");
        });
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
      var requestSearchForm = event.target.closest("[data-cia-request-search]");
      if (requestSearchForm) {
        event.preventDefault();
        loadRequests(new FormData(requestSearchForm).get("search") || "");
        return;
      }

      var requestUpdateForm = event.target.closest("[data-cia-update-request-form]");
      if (requestUpdateForm) {
        event.preventDefault();
        if (!updateRequestAction) {
          setRequestStatus("La accion para guardar solicitud no esta configurada.", "error");
          return;
        }
        var requestData = new FormData(requestUpdateForm);
        requestData.append("action", updateRequestAction);
        requestData.append("search", currentRequestSearch());
        var requestButton = requestUpdateForm.querySelector('button[type="submit"]');
        if (requestButton) requestButton.disabled = true;
        setRequestStatus("Guardando numero de solicitud...", "loading");
        request(requestData)
          .then(function (data) {
            if (requestContent && typeof data.html === "string") {
              requestContent.innerHTML = data.html;
            }
            setRequestStatus(data.message || "Solicitud guardada.", "success");
            var filterForm = module.querySelector("[data-cia-filter-form]");
            if (filterForm) load(new FormData(filterForm));
          })
          .catch(function (error) {
            setRequestStatus(error.message || "No se pudo guardar la solicitud.", "error");
          })
          .finally(function () {
            if (requestButton) requestButton.disabled = false;
          });
        return;
      }

      var mandateSearchForm = event.target.closest("[data-cia-mandate-search]");
      if (mandateSearchForm) {
        event.preventDefault();
        currentMandateContractId = "";
        loadMandates(new FormData(mandateSearchForm).get("search") || "");
        return;
      }

      var mandateLinkForm = event.target.closest("[data-cia-link-mandate-form]");
      if (mandateLinkForm) {
        event.preventDefault();
        if (!linkMandateAction) {
          setMandateStatus("La accion para vincular mandato no esta configurada.", "error");
          return;
        }
        if (!window.confirm("¿Vincular este mandato al contrato de arrendamiento?")) return;
        var mandateData = new FormData(mandateLinkForm);
        mandateData.append("action", linkMandateAction);
        mandateData.append("search", currentMandateSearch());
        var activeFilterForm = module.querySelector("[data-cia-filter-form]");
        if (activeFilterForm) {
          var activeFilters = new FormData(activeFilterForm);
          mandateData.append("active_period", activeFilters.get("period") || "");
          mandateData.append("active_status", activeFilters.get("status") || "");
          mandateData.append("active_search", activeFilters.get("search") || "");
        }
        if (currentMandateContractId) mandateData.append("context_contract_id", currentMandateContractId);
        var mandateButton = mandateLinkForm.querySelector('button[type="submit"]');
        if (mandateButton) mandateButton.disabled = true;
        setMandateStatus("Vinculando mandato...", "loading");
        request(mandateData)
          .then(function (data) {
            if (mandateContent && typeof data.html === "string") {
              mandateContent.innerHTML = data.html;
            }
            setMandateStatus(data.message || "Mandato vinculado.", "success");
            if (typeof data.dashboard_html === "string") {
              replaceContentKeepingPlace(
                { html: data.dashboard_html },
                mandateData.get("contract_id") || currentMandateContractId || ""
              );
              setStatus("Auditorias actualizadas.", "success");
            } else if (activeFilterForm) {
              load(new FormData(activeFilterForm));
            }
          })
          .catch(function (error) {
            setMandateStatus(error.message || "No se pudo vincular el mandato.", "error");
          })
          .finally(function () {
            if (mandateButton) mandateButton.disabled = false;
          });
        return;
      }

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
      if (
        event.target &&
        event.target.name === "period" &&
        event.target.closest("[data-cia-upload-form]")
      ) {
        updateFilenameGuide(event.target.value);
      }
      if (
        event.target &&
        (event.target.name === "audit_id" || event.target.name === "period") &&
        event.target.closest("[data-cia-filter-form]")
      ) {
        var form = event.target.closest("[data-cia-filter-form]");
        if (form) load(new FormData(form));
      }
    });

    module.addEventListener("click", function (event) {
      if (event.target.closest("[data-cia-open-mandates]")) {
        event.preventDefault();
        var mandateTrigger = event.target.closest("[data-cia-open-mandates]");
        openMandateModal(
          mandateTrigger ? mandateTrigger.dataset.ciaMandateSearchValue || "" : "",
          mandateTrigger ? mandateTrigger.dataset.ciaMandateContractId || "" : ""
        );
        return;
      }
      if (event.target.closest("[data-cia-close-mandates]")) {
        event.preventDefault();
        closeMandateModal();
        return;
      }
      if (event.target.closest("[data-cia-open-requests]")) {
        event.preventDefault();
        var requestTrigger = event.target.closest("[data-cia-open-requests]");
        openRequestModal(requestTrigger ? requestTrigger.dataset.ciaRequestSearchValue || "" : "");
        return;
      }
      if (event.target.closest("[data-cia-close-requests]")) {
        event.preventDefault();
        closeRequestModal();
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && mandateModal && !mandateModal.hidden) {
        closeMandateModal();
      }
      if (event.key === "Escape" && requestModal && !requestModal.hidden) {
        closeRequestModal();
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
