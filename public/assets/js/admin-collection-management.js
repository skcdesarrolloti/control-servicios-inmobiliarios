(function () {
  "use strict";

  function init() {
    var root = document.getElementById("scm-app");
    if (!root || root.dataset.scmCollectionInit === "1") return;

    var core = window.SCMAdminCore || {};
    var runtime = typeof core.parseRuntime === "function"
      ? core.parseRuntime(root)
      : JSON.parse(root.getAttribute("data-scm-runtime") || "{}");
    if (!runtime) return;

    var ajaxUrl = runtime.ajaxUrl || "";
    var nonce = runtime.nonce || "";
    var actions = runtime.actions || {};
    var notify = typeof core.scmNotify === "function"
      ? core.scmNotify
      : function (type, message) { window.alert(message); };
    var actionImport = actions.collection_portfolio_import || "";
    var actionPortfolio = actions.collection_portfolio_action || "";
    var actionPdf = actions.collection_portfolio_pdf || "";
    var actionPanel = actions.admin_notifications_collection_log || "";
    var actionManagement = actions.admin_notifications_collection || "";
    var actionOptions = actions.admin_notifications_collection_options || "";
    var activePreviewUrl = "";

    root.dataset.scmCollectionInit = "1";

    function errorMessage(json, fallback) {
      return String(
        (json && json.data && json.data.message) ||
        (json && json.message) ||
        fallback ||
        "No se pudo completar la operación."
      );
    }

    function postJson(action, formData) {
      if (!ajaxUrl || !action) {
        return Promise.reject(new Error("La acción solicitada no está disponible."));
      }
      var fd = formData || new FormData();
      fd.set("action", action);
      fd.set("nonce", nonce);
      return fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () {
            throw new Error("El servidor devolvió una respuesta no válida.");
          });
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(errorMessage(json));
          }
          return json.data || {};
        });
    }

    function setBusy(form, busy) {
      if (!form) return;
      form.classList.toggle("is-busy", !!busy);
      form.setAttribute("aria-busy", busy ? "true" : "false");
      form.querySelectorAll("button, input[type='file']").forEach(function (control) {
        control.disabled = !!busy;
      });
    }

    function panelParams() {
      var fd = new FormData();
      var panel = root.querySelector("#scm-panel-gestiones-cobro [data-scm-collection-log]");
      [
        "scmgc_buscar", "scmgc_estado", "scmgc_etapa", "scmgc_movimiento", "scmgc_cartera_page",
        "scmgc_fecha_desde", "scmgc_fecha_hasta", "scmgc_tipo", "scmgc_page"
      ].forEach(function (name) {
        var field = panel ? panel.querySelector("[name='" + name + "']") : null;
        fd.set(name, field ? String(field.value || "") : "");
      });
      var selectedView = panel ? panel.querySelector("[data-scm-portfolio-tab][aria-selected='true']") : null;
      fd.set("scmgc_view", selectedView ? String(selectedView.getAttribute("data-scm-portfolio-tab") || "principal") : "principal");
      fd.set("action", actionPanel);
      fd.set("nonce", nonce);
      return fd;
    }

    function refreshPanel() {
      var current = root.querySelector("#scm-panel-gestiones-cobro [data-scm-collection-log]");
      if (!current || !actionPanel) return Promise.resolve();
      var activeTab = current.querySelector("[data-scm-portfolio-tab][aria-selected='true']");
      var activeView = activeTab ? String(activeTab.getAttribute("data-scm-portfolio-tab") || "principal") : "principal";
      current.classList.add("is-loading");
      current.setAttribute("aria-busy", "true");
      return postJson(actionPanel, panelParams()).then(function (data) {
        var holder = document.createElement("div");
        holder.innerHTML = String(data.html || "");
        var next = holder.querySelector("[data-scm-collection-log]");
        if (!next) throw new Error("No se pudo actualizar la vista de cartera.");
        activatePortfolioTab(next, activeView, false);
        current.parentNode.replaceChild(next, current);
      }).catch(function (error) {
        current.classList.remove("is-loading");
        current.removeAttribute("aria-busy");
        throw error;
      });
    }

    function confirmStage(button) {
      var stage = String(button.getAttribute("data-scm-portfolio-stage") || "");
      var portfolioId = String(button.getAttribute("data-portfolio-id") || "");
      var label = stage === "siniestro" ? "marcar como siniestro" : "volver a cobro normal";
      var run = function (note) {
        var fd = new FormData();
        fd.set("portfolio_id", portfolioId);
        fd.set("operation", "mark_" + stage);
        fd.set("note", note || "");
        button.disabled = true;
        return postJson(actionPortfolio, fd).then(function (data) {
          notify("success", data.message || "Estado de cobranza actualizado.", "Cartera");
          return refreshPanel();
        }).catch(function (error) {
          notify("error", error.message, "Cartera");
        }).finally(function () {
          button.disabled = false;
        });
      };

      if (window.Swal && typeof window.Swal.fire === "function") {
        window.Swal.fire({
          title: stage === "siniestro" ? "Marcar contrato como siniestro" : "Normalizar etapa de cobro",
          text: stage === "siniestro"
            ? "Esta acción cambia la etapa y deja trazabilidad, pero no genera ni envía la carta de siniestro. Para la carta usa Preparar siniestro."
            : "El contrato volverá a cobro normal y el cambio quedará registrado en el historial.",
          input: "textarea",
          inputLabel: "Motivo u observación (opcional)",
          inputPlaceholder: "Indica por qué cambia la etapa...",
          showCancelButton: true,
          confirmButtonText: "Sí, " + label,
          cancelButtonText: "Cancelar",
          confirmButtonColor: "#1e3a5f"
        }).then(function (result) {
          if (result.isConfirmed) run(String(result.value || ""));
        });
        return;
      }
      if (window.confirm("¿Deseas " + label + "?")) run("");
    }

    function downloadLetter(portfolioId, type, button) {
      var fd = new FormData();
      fd.set("action", actionPdf);
      fd.set("nonce", nonce);
      fd.set("portfolio_id", portfolioId);
      fd.set("letter_type", type);
      button.disabled = true;
      notify("info", "Preparando la carta en PDF...", "Cartera");
      return fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) {
          var contentType = String(response.headers.get("content-type") || "");
          if (contentType.indexOf("application/json") !== -1) {
            return response.json().then(function (json) {
              throw new Error(errorMessage(json, "No se pudo generar la carta."));
            });
          }
          if (!response.ok) throw new Error("No se pudo generar la carta.");
          var disposition = String(response.headers.get("content-disposition") || "");
          var match = disposition.match(/filename="?([^";]+)"?/i);
          var filename = match ? match[1] : (type === "siniestro" ? "carta-siniestro.pdf" : "carta-prejuridica.pdf");
          return response.blob().then(function (blob) {
            var url = URL.createObjectURL(blob);
            var link = document.createElement("a");
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
            notify("success", "Carta generada y descargada.", "Cartera");
            return refreshPanel();
          });
        })
        .catch(function (error) {
          notify("error", error.message, "Cartera");
        })
        .finally(function () { button.disabled = false; });
    }

    function sendLetter(portfolioId, type, button) {
      var fd = new FormData();
      fd.set("portfolio_id", portfolioId);
      fd.set("operation", "send_" + type);
      button.disabled = true;
      notify("info", "Generando y encolando la carta...", "Cartera");
      return postJson(actionPortfolio, fd).then(function (data) {
        notify(Number(data.queued || 0) > 0 ? "success" : "warning", data.message || "Carta procesada.", "Cartera");
        return refreshPanel();
      }).catch(function (error) {
        notify("error", error.message, "Cartera");
      }).finally(function () { button.disabled = false; });
    }

    function syncModalBodyState() {
      var openModal = root.querySelector("[data-scm-portfolio-management-modal]:not([hidden]), [data-scm-portfolio-letter-preview-modal]:not([hidden])");
      document.body.classList.toggle("scm-modal-open", !!openModal);
    }

    function closeLetterPreview() {
      var modal = root.querySelector("[data-scm-portfolio-letter-preview-modal]");
      if (!modal) return;
      var frame = modal.querySelector("[data-scm-portfolio-letter-preview-frame]");
      if (frame) frame.src = "about:blank";
      if (activePreviewUrl) {
        URL.revokeObjectURL(activePreviewUrl);
        activePreviewUrl = "";
      }
      modal.hidden = true;
      modal.removeAttribute("data-portfolio-id");
      modal.removeAttribute("data-letter-type");
      syncModalBodyState();
    }

    function openLetterPreview(portfolioId, type, button) {
      var modal = root.querySelector("[data-scm-portfolio-letter-preview-modal]");
      if (!modal) {
        downloadLetter(portfolioId, type, button);
        return;
      }
      var fd = new FormData();
      fd.set("action", actionPdf);
      fd.set("nonce", nonce);
      fd.set("portfolio_id", portfolioId);
      fd.set("letter_type", type);
      fd.set("mode", "preview");
      button.disabled = true;
      notify("info", "Preparando vista previa del PDF...", "Cartera");
      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) {
          var contentType = String(response.headers.get("content-type") || "");
          if (contentType.indexOf("application/json") !== -1) {
            return response.json().then(function (json) {
              throw new Error(errorMessage(json, "No se pudo preparar la vista previa."));
            });
          }
          if (!response.ok) throw new Error("No se pudo preparar la vista previa.");
          return response.blob();
        })
        .then(function (blob) {
          closeLetterPreview();
          activePreviewUrl = URL.createObjectURL(blob);
          var frame = modal.querySelector("[data-scm-portfolio-letter-preview-frame]");
          var title = modal.querySelector("[data-scm-portfolio-letter-preview-title]");
          var context = modal.querySelector("[data-scm-portfolio-letter-preview-context]");
          modal.setAttribute("data-portfolio-id", portfolioId);
          modal.setAttribute("data-letter-type", type);
          if (frame) frame.src = activePreviewUrl;
          if (title) title.textContent = type === "siniestro" ? "Vista previa de carta de siniestro" : "Vista previa de carta prejurídica";
          if (context) context.textContent = "Revisa nombres, contrato, inmueble, saldo y destinatarios antes de continuar.";
          modal.hidden = false;
          syncModalBodyState();
          var close = modal.querySelector("[data-scm-portfolio-letter-preview-close]");
          if (close) close.focus();
        })
        .catch(function (error) {
          notify("error", error.message, "Cartera");
        })
        .finally(function () { button.disabled = false; });
    }

    function chooseLetterAction(button) {
      var type = String(button.getAttribute("data-scm-portfolio-letter") || "");
      var portfolioId = String(button.getAttribute("data-portfolio-id") || "");
      openLetterPreview(portfolioId, type, button);
    }

    function closeManagementModal() {
      var modal = root.querySelector("[data-scm-portfolio-management-modal]");
      if (!modal) return;
      modal.hidden = true;
      syncModalBodyState();
    }

    function renderCodeudores(modal, contract) {
      var wrap = modal.querySelector("[data-scm-portfolio-codeudores]");
      var list = modal.querySelector("[data-scm-portfolio-codeudores-list]");
      if (!wrap || !list) return;
      var codeudores = contract && Array.isArray(contract.codeudores) ? contract.codeudores : [];
      list.innerHTML = "";
      wrap.hidden = codeudores.length === 0;
      codeudores.forEach(function (codeudor, index) {
        var label = document.createElement("label");
        label.className = "scm-admin-notif-codeudor-card";
        var input = document.createElement("input");
        input.type = "checkbox";
        input.name = "notify_codeudor_keys[]";
        input.value = String(codeudor.key || "");
        input.checked = true;
        var info = document.createElement("span");
        info.className = "scm-admin-notif-codeudor-info";
        var name = document.createElement("strong");
        name.textContent = String(codeudor.nombre || ("Codeudor " + (index + 1)));
        var contact = document.createElement("small");
        contact.textContent = [codeudor.correo || "Sin correo", codeudor.celular || "Sin celular"].join(" · ");
        info.appendChild(name);
        info.appendChild(contact);
        label.appendChild(input);
        label.appendChild(info);
        list.appendChild(label);
      });
    }

    function openManagementModal(button) {
      var modal = root.querySelector("[data-scm-portfolio-management-modal]");
      if (!modal) return;
      var form = modal.querySelector("[data-scm-portfolio-management-form]");
      var tenantId = String(button.getAttribute("data-tenant-id") || "");
      var contractId = String(button.getAttribute("data-contract-id") || "");
      var portfolioId = String(button.getAttribute("data-portfolio-id") || "");
      var tenantName = String(button.getAttribute("data-tenant-name") || "Arrendatario");
      var contractNumber = String(button.getAttribute("data-contract-number") || "");
      var fd = new FormData();
      fd.append("ids[]", tenantId);
      button.disabled = true;
      notify("info", "Consultando contrato y codeudores...", "Cartera");
      postJson(actionOptions, fd).then(function (data) {
        var contracts = Array.isArray(data.contracts) ? data.contracts : [];
        var contract = contracts.find(function (item) {
          return String(item.id || "") === contractId;
        });
        if (!contract) throw new Error("No se encontró el contrato activo para esta gestión.");
        form.reset();
        form.querySelector("[name='portfolio_id']").value = portfolioId;
        form.querySelector("[name='ids[]']").value = tenantId;
        form.querySelector("[name='contract_ids[]']").value = contractId;
        modal.querySelector("[data-scm-portfolio-management-context]").textContent = tenantName + " · Contrato " + (contractNumber || contract.contrato || contractId);
        renderCodeudores(modal, contract);
        modal.querySelectorAll("[data-scm-portfolio-followup-field]").forEach(function (field) { field.hidden = true; });
        var result = modal.querySelector("[data-scm-portfolio-management-result]");
        if (result) result.textContent = "";
        modal.hidden = false;
        syncModalBodyState();
        window.setTimeout(function () {
          var observation = form.querySelector("[name='observacion']");
          if (observation) observation.focus();
        }, 50);
      }).catch(function (error) {
        notify("error", error.message, "Cartera");
      }).finally(function () { button.disabled = false; });
    }

    function activatePortfolioTab(container, view, focusTab) {
      if (!container) return;
      var allowed = ["principal", "informe", "historial"];
      if (allowed.indexOf(view) === -1) view = "principal";
      container.querySelectorAll("[data-scm-portfolio-tab]").forEach(function (tab) {
        var selected = String(tab.getAttribute("data-scm-portfolio-tab") || "") === view;
        tab.classList.toggle("is-active", selected);
        tab.setAttribute("aria-selected", selected ? "true" : "false");
        tab.setAttribute("tabindex", selected ? "0" : "-1");
        if (selected && focusTab) tab.focus();
      });
      container.querySelectorAll("[data-scm-portfolio-panel]").forEach(function (panel) {
        panel.hidden = String(panel.getAttribute("data-scm-portfolio-panel") || "") !== view;
      });
    }

    root.addEventListener("submit", function (event) {
      var importForm = event.target.closest && event.target.closest("[data-scm-portfolio-import]");
      if (importForm && root.contains(importForm)) {
        event.preventDefault();
        var result = importForm.querySelector("[data-scm-portfolio-import-result]");
        var fileInput = importForm.querySelector("input[type='file']");
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
          if (result) result.textContent = "Selecciona el auxiliar 1380.";
          return;
        }
        // Capture the file before disabling the input. Disabled controls are
        // omitted from FormData, so creating it afterwards sent no file.
        var uploadData = new FormData(importForm);
        setBusy(importForm, true);
        if (result) result.textContent = "Procesando saldos y cruzando contratos...";
        postJson(actionImport, uploadData).then(function (data) {
          notify(data.duplicate ? "warning" : "success", data.message || "Cartera actualizada.", "Cartera");
          return refreshPanel();
        }).catch(function (error) {
          if (result) result.textContent = error.message;
          notify("error", error.message, "Cartera");
        }).finally(function () { setBusy(importForm, false); });
        return;
      }

      var managementForm = event.target.closest && event.target.closest("[data-scm-portfolio-management-form]");
      if (managementForm && root.contains(managementForm)) {
        event.preventDefault();
        var resultEl = managementForm.querySelector("[data-scm-portfolio-management-result]");
        setBusy(managementForm, true);
        if (resultEl) resultEl.textContent = "Guardando gestión...";
        postJson(actionManagement, new FormData(managementForm)).then(function (data) {
          notify("success", data.message || "Gestión registrada.", "Cartera");
          closeManagementModal();
          return refreshPanel();
        }).catch(function (error) {
          if (resultEl) resultEl.textContent = error.message;
          notify("error", error.message, "Cartera");
        }).finally(function () { setBusy(managementForm, false); });
      }
    });

    root.addEventListener("change", function (event) {
      if (!event.target.matches || !event.target.matches("[data-scm-portfolio-followup]")) return;
      var modal = event.target.closest("[data-scm-portfolio-management-modal]");
      if (!modal) return;
      var show = String(event.target.value || "") === "Si";
      modal.querySelectorAll("[data-scm-portfolio-followup-field]").forEach(function (field) {
        field.hidden = !show;
      });
    });

    root.addEventListener("click", function (event) {
      var portfolioTab = event.target.closest && event.target.closest("[data-scm-portfolio-tab]");
      if (portfolioTab && root.contains(portfolioTab)) {
        event.preventDefault();
        activatePortfolioTab(
          portfolioTab.closest("[data-scm-collection-log]"),
          String(portfolioTab.getAttribute("data-scm-portfolio-tab") || "principal"),
          true
        );
        return;
      }
      var reportPrint = event.target.closest && event.target.closest("[data-scm-portfolio-report-print]");
      if (reportPrint && root.contains(reportPrint)) {
        event.preventDefault();
        document.body.classList.add("scm-print-portfolio-report");
        window.print();
        window.setTimeout(function () { document.body.classList.remove("scm-print-portfolio-report"); }, 10000);
        return;
      }
      var stageButton = event.target.closest && event.target.closest("[data-scm-portfolio-stage]");
      if (stageButton && root.contains(stageButton)) {
        event.preventDefault();
        confirmStage(stageButton);
        return;
      }
      var letterButton = event.target.closest && event.target.closest("[data-scm-portfolio-letter]");
      if (letterButton && root.contains(letterButton)) {
        event.preventDefault();
        chooseLetterAction(letterButton);
        return;
      }
      var managementButton = event.target.closest && event.target.closest("[data-scm-portfolio-management]");
      if (managementButton && root.contains(managementButton)) {
        event.preventDefault();
        openManagementModal(managementButton);
        return;
      }
      var closeButton = event.target.closest && event.target.closest("[data-scm-portfolio-management-close]");
      if (closeButton && root.contains(closeButton)) {
        event.preventDefault();
        closeManagementModal();
        return;
      }
      var previewClose = event.target.closest && event.target.closest("[data-scm-portfolio-letter-preview-close]");
      if (previewClose && root.contains(previewClose)) {
        event.preventDefault();
        closeLetterPreview();
        return;
      }
      var previewDownload = event.target.closest && event.target.closest("[data-scm-portfolio-letter-preview-download]");
      if (previewDownload && root.contains(previewDownload)) {
        event.preventDefault();
        var downloadModal = previewDownload.closest("[data-scm-portfolio-letter-preview-modal]");
        var downloadPortfolioId = downloadModal ? String(downloadModal.getAttribute("data-portfolio-id") || "") : "";
        var downloadType = downloadModal ? String(downloadModal.getAttribute("data-letter-type") || "") : "";
        closeLetterPreview();
        downloadLetter(downloadPortfolioId, downloadType, previewDownload);
        return;
      }
      var previewSend = event.target.closest && event.target.closest("[data-scm-portfolio-letter-preview-send]");
      if (previewSend && root.contains(previewSend)) {
        event.preventDefault();
        var sendModal = previewSend.closest("[data-scm-portfolio-letter-preview-modal]");
        var sendPortfolioId = sendModal ? String(sendModal.getAttribute("data-portfolio-id") || "") : "";
        var sendType = sendModal ? String(sendModal.getAttribute("data-letter-type") || "") : "";
        closeLetterPreview();
        sendLetter(sendPortfolioId, sendType, previewSend);
      }
    });

    document.addEventListener("keydown", function (event) {
      var currentTab = event.target.closest && event.target.closest("[data-scm-portfolio-tab]");
      if (currentTab && ["ArrowLeft", "ArrowRight", "Home", "End"].indexOf(event.key) !== -1) {
        var tabs = Array.prototype.slice.call(currentTab.closest("[role='tablist']").querySelectorAll("[data-scm-portfolio-tab]"));
        var index = tabs.indexOf(currentTab);
        if (event.key === "Home") index = 0;
        else if (event.key === "End") index = tabs.length - 1;
        else index = (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
        event.preventDefault();
        tabs[index].click();
        return;
      }
      if (event.key === "Escape") {
        var preview = root.querySelector("[data-scm-portfolio-letter-preview-modal]:not([hidden])");
        if (preview) closeLetterPreview();
        else closeManagementModal();
      }
    });

    window.addEventListener("afterprint", function () {
      document.body.classList.remove("scm-print-portfolio-report");
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
