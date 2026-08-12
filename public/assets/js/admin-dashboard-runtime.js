(function () {
  "use strict";
  var core = window.SCMAdminCore;
  if (!core) {
    console.error("SCMAdminCore no esta disponible.");
    return;
  }
  var parseRuntime = core.parseRuntime;
  var escHtml = core.escHtml;
  var scmNotify = core.scmNotify;
  var bindTabs = core.bindTabs;
  var findRootFromNode = core.findRootFromNode;
  var getCaseModal = core.getCaseModal;
  var openIframeModal = core.openIframeModal;
  var closeCaseModal = core.closeCaseModal;
  var openPropertyLocationEditor = core.openPropertyLocationEditor;
  var openPropertyLocationStandaloneEditor = core.openPropertyLocationStandaloneEditor;
  var renderTicketDocumentRow = core.renderTicketDocumentRow;
  var renderTicketDocumentFields = core.renderTicketDocumentFields;
  var renderPasteEvidenceBox = core.renderPasteEvidenceBox || function () { return ""; };
  var renderNotifyTargets = core.renderNotifyTargets;
  var getLlavesDetailPayload = core.getLlavesDetailPayload;
  var getConsultorEntregaDetailPayload = core.getConsultorEntregaDetailPayload;
  var openStandaloneDetail = core.openStandaloneDetail;
  function initRoot(root) {
    if (!root || root.dataset.scmInit === "1") {
      return;
    }

    var runtime = parseRuntime(root);
    if (!runtime) {
      return;
    }

    root.dataset.scmInit = "1";

    bindTabs(root, runtime);

    // ── Guide modal (new tabbed version) ────────────────────────────
    var guideBtn = root.querySelector("#scm-open-guide");
    var guideModal = document.getElementById("scm-guide-modal");
    var guideClose = document.getElementById("scm-close-guide");

    function openGuideModal() {
      if (!guideModal) return;
      guideModal.classList.add("open");
      guideModal.setAttribute("aria-hidden", "false");
    }
    function closeGuideModal() {
      if (!guideModal) return;
      guideModal.classList.remove("open");
      guideModal.setAttribute("aria-hidden", "true");
    }

    if (guideBtn) {
      guideBtn.addEventListener("click", function () {
        openGuideModal();
        // Load active CRUD pane on first open
        var activePane = guideModal.querySelector(".scm-go-pane.active");
        if (activePane) {
          var paneId = activePane.id;
          if (paneId === "scm-go-pane-correspondencias") scmGoLoad("gcd");
          else if (paneId === "scm-go-pane-respuestas") scmGoLoad("grt");
          else if (paneId === "scm-go-pane-articulos") scmGoLoad("gac");
        }
      });
    }
    if (guideClose) {
      guideClose.addEventListener("click", closeGuideModal);
    }
    if (guideModal) {
      guideModal.addEventListener("click", function (e) {
        if (e.target === guideModal) closeGuideModal();
      });
    }

    var caseModal = getCaseModal(root);
    if (caseModal) {
      caseModal.addEventListener("click", function (e) {
        if (e.target === caseModal) {
          closeCaseModal(caseModal);
        }
      });
    }

    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") {
        return;
      }
      var adminTicketModal = root.querySelector("#scm-admin-ticket-modal.open");
      if (adminTicketModal) {
        closeAdminTicketModal();
        return;
      }
      var openModal = root.querySelector("#scm-case-modal.open");
      if (!openModal) {
        return;
      }
      var sub = openModal.querySelector(".scm-case-submodal.open");
      if (sub) {
        sub.classList.remove("open");
        sub.setAttribute("aria-hidden", "true");
        return;
      }
      closeCaseModal(openModal);
    });

    var ajaxUrl = runtime.ajaxUrl || "";
    var nonce = runtime.nonce || "";
    var config = runtime.config || {};
    var actions = runtime.actions || {};

    var actionMant = actions.mant || "";
    var actionSeg = actions.seg || "";
    var actionNote = actions.nota || "";
    var actionTicketResponse = actions.ticket_response || "";
    var actionCotizacionResponse = actions.cotizacion_response || "";
    var actionPostponeTicket = actions.postpone_ticket || "";
    var actionStatusTickets = actions.status_tickets || "";
    var actionMyTickets = actions.my_tickets || "";
    var actionCotizacionesMantenimiento =
      actions.cotizaciones_mantenimiento || "";
    var actionDeleteCotizacion = actions.delete_cotizacion || "";
    var actionActivateTicket = actions.activate_ticket || "";
    var actionCloseTicket = actions.close_ticket || "";
    var actionContactsUpdate = actions.contacts_update || "";
    var actionSavePropertyLocation = actions.save_property_location || "";
    var actionTrasladarCaso = actions.trasladar_caso || "";
    var actionContratosArrendamiento = actions.contratos_arrendamiento || "";
    var actionContratosArrendamientoFallback =
      actions.preventivas_pendientes || "";
    var actionCrearTicketAdministrativo =
      actions.crear_ticket_administrativo || "";

    function setListLoading(cardsEl, isLoading, label) {
      if (!cardsEl) {
        return;
      }
      var wrap = cardsEl.closest ? cardsEl.closest(".scm-cards-wrap") : null;
      if (!wrap) {
        return;
      }
      var loader = wrap.querySelector(".scm-list-loader");
      if (!loader) {
        loader = document.createElement("div");
        loader.className = "scm-list-loader";
        loader.setAttribute("role", "status");
        loader.setAttribute("aria-live", "polite");
        loader.innerHTML =
          '<div class="scm-list-loader-card">' +
          '<span class="scm-list-loader-dots" aria-hidden="true"><i></i><i></i><i></i></span>' +
          '<strong></strong>' +
          "<small>Espera un momento mientras actualizamos la vista.</small>" +
          "</div>";
        wrap.appendChild(loader);
      }
      var title = loader.querySelector("strong");
      if (title) {
        title.textContent = label || "Cargando tickets...";
      }
      wrap.classList.toggle("scm-list-is-loading", !!isLoading);
      loader.classList.toggle("active", !!isLoading);
      cardsEl.setAttribute("aria-busy", isLoading ? "true" : "false");
    }

    function showToast(type, message) {
      scmNotify(type, message);
    }

    function ticketAdminDatalist(id, values) {
      return (
        '<datalist id="' +
        escHtml(id) +
        '">' +
        values
          .map(function (value) {
            return '<option value="' + escHtml(value) + '"></option>';
          })
          .join("") +
        "</datalist>"
      );
    }

    function ticketAdminSelectOptions(values, selected, placeholder) {
      selected = String(selected || "");
      var html = placeholder
        ? '<option value="">' + escHtml(placeholder) + "</option>"
        : "";
      values.forEach(function (value) {
        html +=
          '<option value="' +
          escHtml(value) +
          '"' +
          (value === selected ? " selected" : "") +
          ">" +
          escHtml(value) +
          "</option>";
      });
      return html;
    }

    function adminTicketEmployeeOptions(selectedId) {
      var funcionarios =
        runtime && Array.isArray(runtime.funcionarios)
          ? runtime.funcionarios
          : [];
      var html = '<option value="">Seleccionar responsable</option>';
      funcionarios.forEach(function (func) {
        var id = String((func && func.id) || "").trim();
        var label = String((func && func.label) || id).trim();
        if (!id) return;
        html +=
          '<option value="' +
          escHtml(id) +
          '"' +
          (id === selectedId ? " selected" : "") +
          ">" +
          escHtml(label) +
          "</option>";
      });
      return html;
    }

    function ensureAdminTicketModal() {
      var modal = root.querySelector("#scm-admin-ticket-modal");
      if (modal) {
        return modal;
      }
      modal = document.createElement("div");
      modal.id = "scm-admin-ticket-modal";
      modal.className = "scm-admin-ticket-modal";
      modal.setAttribute("aria-hidden", "true");
      modal.innerHTML =
        '<div class="scm-admin-ticket-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-admin-ticket-title">' +
        '<div class="scm-case-submodal-head">' +
        '<div><h4 class="scm-case-submodal-title" id="scm-admin-ticket-title">Crear ticket</h4><p class="scm-case-submodal-meta">Ticket administrativo desde contrato</p></div>' +
        '<button type="button" class="scm-case-submodal-close" data-admin-ticket-close aria-label="Cerrar">&times;</button>' +
        "</div>" +
        '<div class="scm-admin-ticket-body"></div>' +
        "</div>";
      root.appendChild(modal);
      modal.addEventListener("click", function (e) {
        if (e.target === modal || e.target.closest("[data-admin-ticket-close]")) {
          closeAdminTicketModal();
        }
      });
      return modal;
    }

    function closeAdminTicketModal() {
      var modal = root.querySelector("#scm-admin-ticket-modal");
      if (!modal) return;
      modal.classList.remove("open");
      modal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("scm-modal-open");
    }

    function contractDataset(btn, key) {
      return String((btn && btn.dataset && btn.dataset[key]) || "").trim();
    }

    function openAdminTicketModal(btn) {
      if (!btn) return;
      if (!actionCrearTicketAdministrativo) {
        showToast("error", "Accion de crear ticket no configurada.");
        return;
      }
      var mode = contractDataset(btn, "ticketMode") || "administrativo";
      var isPreventiva = mode === "preventiva";
      var modal = ensureAdminTicketModal();
      var title = modal.querySelector(".scm-case-submodal-title");
      var meta = modal.querySelector(".scm-case-submodal-meta");
      var body = modal.querySelector(".scm-admin-ticket-body");
      var contractCode = contractDataset(btn, "contractCode");
      var inmueble = contractDataset(btn, "inmueble");
      var direccion = contractDataset(btn, "direccion");
      var defaultEmpleado = contractDataset(btn, "defaultEmpleadoId");
      var defaultTema = isPreventiva ? "Revision preventiva" : "";
      var defaultDepartamento = isPreventiva ? "Servicio al arrendatario" : "";
      var defaultPrioridad = isPreventiva ? "Prioridad urgente" : "";
      var defaultAsunto = isPreventiva ? "REVISION PREVENTIVA" : "";
      var defaultDescripcion = isPreventiva
        ? "Espero que se encuentre bien. Se llevara acabo un revision preventiva programada segun la fecha de inicio del contrato de arrendamiento. En este ticket se documentara todo el proceso realizado."
        : "";

      if (title) {
        title.textContent = contractDataset(btn, "ticketTitle") || "Crear ticket";
      }
      if (meta) {
        meta.textContent =
          "Contrato " +
          (contractCode || contractDataset(btn, "contractPk") || "-") +
          (inmueble ? " | Inmueble " + inmueble : "");
      }
      if (!body) return;

      var deptOptions = [
        "Servicio al cliente",
        "Servicio al propietario",
        "Servicio al arrendatario",
        "Servicio a la copropiedad",
      ];
      var topicOptions = [
        "Mantenimiento",
        "Revision preventiva",
        "Entrega de inmuebles",
        "Recibo de inmuebles",
        "Procesos juridicos",
        "Solicitud contractual",
        "Solicitud de servicios publicos",
        "Retencion de contrato",
        "Otros servicios",
        "Contable y tributaria",
        "Certificaciones tributarias",
      ];
      var priorityOptions = [
        "Prioridad urgente",
        "Prioridad alta",
        "Prioridad media",
        "Prioridad baja",
      ];
      var solicitanteField = isPreventiva
        ? '<input type="hidden" name="solicitante_tipo" value="arrendatario">' +
          '<div class="scm-admin-ticket-fixed"><span>Solicitante</span><strong>Arrendatario</strong></div>'
        : '<label class="scm-seg-field"><span>Solicitante</span><select name="solicitante_tipo"><option value="arrendatario">Arrendatario</option><option value="propietario" selected>Propietario</option></select></label>';

      body.innerHTML =
        '<form class="scm-admin-ticket-form" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_mode" value="' +
        escHtml(mode) +
        '">' +
        '<input type="hidden" name="contract_pk" value="' +
        escHtml(contractDataset(btn, "contractPk")) +
        '">' +
        '<input type="hidden" name="id_contrato" value="' +
        escHtml(contractDataset(btn, "contractPk")) +
        '">' +
        '<input type="hidden" name="id_inmueble" value="' +
        escHtml(contractDataset(btn, "idInmueble")) +
        '">' +
        '<input type="hidden" name="id_arrendatario" value="' +
        escHtml(contractDataset(btn, "idArrendatario")) +
        '">' +
        '<input type="hidden" name="id_propietario" value="' +
        escHtml(contractDataset(btn, "idPropietario")) +
        '">' +
        '<input type="hidden" name="id_sucursal" value="' +
        escHtml(contractDataset(btn, "idSucursal")) +
        '">' +
        '<input type="hidden" name="id_inventario" value="' +
        escHtml(contractDataset(btn, "idInventario")) +
        '">' +
        '<input type="hidden" name="fecha_final_contrato" value="' +
        escHtml(contractDataset(btn, "fechaFinalContrato")) +
        '">' +
        '<div class="scm-admin-ticket-summary">' +
        '<span><b>Contrato:</b> ' +
        escHtml(contractCode || "-") +
        "</span>" +
        '<span><b>Inmueble:</b> ' +
        escHtml(inmueble || "-") +
        "</span>" +
        '<span><b>Direccion:</b> ' +
        escHtml(direccion || "-") +
        "</span>" +
        "</div>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Informacion del ticket</h5>' +
        '<div class="scm-admin-ticket-grid">' +
        '<label class="scm-seg-field"><span>Responsable</span><select name="id_empleado" required>' +
        adminTicketEmployeeOptions(defaultEmpleado) +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Prioridad</span><select name="prioridad" required>' +
        ticketAdminSelectOptions(priorityOptions, defaultPrioridad, "Selecciona prioridad") +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Departamento</span><select name="departamento" required>' +
        ticketAdminSelectOptions(deptOptions, defaultDepartamento, "Selecciona departamento") +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Tema de ayuda</span><select name="tema_ayuda" required>' +
        ticketAdminSelectOptions(topicOptions, defaultTema, "Selecciona tema") +
        "</select></label>" +
        solicitanteField +
        "</div>" +
        "</section>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Inmueble y contrato</h5>' +
        '<div class="scm-admin-ticket-grid">' +
        '<label class="scm-seg-field"><span>Contrato</span><input name="contrato" value="' +
        escHtml(contractCode) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Inmueble</span><input name="inmueble" value="' +
        escHtml(inmueble) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Direccion</span><input name="direccion" value="' +
        escHtml(direccion) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Barrio</span><input name="barrio" value="' +
        escHtml(contractDataset(btn, "barrio")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Registro fotografico</span><input name="registro_fotografico" value="' +
        escHtml(contractDataset(btn, "registroFotografico")) +
        '"></label>' +
        "</div>" +
        "</section>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Propietario</h5>' +
        '<div class="scm-admin-ticket-grid scm-admin-ticket-grid--contact">' +
        '<label class="scm-seg-field"><span>Propietario</span><input name="propietario" value="' +
        escHtml(contractDataset(btn, "propietario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Correo propietario</span><input name="correo_propietario" type="email" value="' +
        escHtml(contractDataset(btn, "correoPropietario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Celular propietario</span><input name="celular_propietario" value="' +
        escHtml(contractDataset(btn, "celularPropietario")) +
        '"></label>' +
        "</div>" +
        "</section>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Arrendatario</h5>' +
        '<div class="scm-admin-ticket-grid scm-admin-ticket-grid--contact">' +
        '<label class="scm-seg-field"><span>Arrendatario</span><input name="arrendatario" value="' +
        escHtml(contractDataset(btn, "arrendatario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Correo arrendatario</span><input name="correo_arrendatario" type="email" value="' +
        escHtml(contractDataset(btn, "correoArrendatario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Celular arrendatario</span><input name="celular_arrendatario" value="' +
        escHtml(contractDataset(btn, "celularArrendatario")) +
        '"></label>' +
        "</div>" +
        "</section>" +
        '<label class="scm-seg-field"><span>Asunto</span><input name="asunto" required value="' +
        escHtml(defaultAsunto) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Descripcion</span><textarea name="descripcion" rows="6" required>' +
        escHtml(defaultDescripcion) +
        "</textarea></label>" +
        '<label class="scm-seg-field"><span>Imagenes / evidencias</span><input type="file" name="imagen[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderPasteEvidenceBox("imagen[]") +
        renderTicketDocumentFields() +
        renderNotifyTargets() +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Crear ticket</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";

      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      document.body.classList.add("scm-modal-open");
      var firstInput = body.querySelector('select[name="id_empleado"]');
      if (firstInput && firstInput.focus) {
        firstInput.focus();
      }
    }

    function closeCaseSubmodalForNode(node) {
      var modal = node && node.closest ? node.closest(".scm-case-modal") : null;
      if (!modal) {
        modal = root.querySelector("#scm-case-modal.open");
      }
      if (modal) {
        var sub = modal.querySelector(".scm-case-submodal.open");
        if (sub) {
          sub.classList.remove("open");
          sub.setAttribute("aria-hidden", "true");
        }
      }
      var standalone =
        node && node.closest
          ? node.closest(".scm-standalone-detail-modal")
          : root.querySelector(".scm-standalone-detail-modal.open");
      if (standalone) {
        standalone.classList.remove("open");
        standalone.setAttribute("aria-hidden", "true");
        document.body.classList.remove("scm-modal-open");
      }
    }

    function cssAttrValue(value) {
      value = String(value || "");
      if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(value);
      }
      return value.replace(/\\/g, "\\\\").replace(/"/g, '\\"');
    }

    function reopenCaseFromUpdatedCard(ticketPk) {
      ticketPk = String(ticketPk || "").trim();
      if (!ticketPk) {
        return;
      }
      var modal = root.querySelector("#scm-case-modal.open");
      if (!modal) {
        return;
      }
      var btn = root.querySelector(
        '.scm-btn-case[data-ticket-pk="' + cssAttrValue(ticketPk) + '"]',
      );
      if (btn) {
        window.scmOpenCase(btn);
      }
    }

    function refreshCaseAfterSave(ticketPk, fromNode) {
      closeCaseSubmodalForNode(fromNode);
      var openModal = root.querySelector("#scm-case-modal.open");
      if (
        openModal &&
        openModal.dataset &&
        openModal.dataset.caseKind === "public-pqr"
      ) {
        closeCaseModal(openModal);
        refreshActiveTab();
        return;
      }
      var refreshed = refreshActiveTab();
      if (!refreshed || typeof refreshed.then !== "function") {
        reopenCaseFromUpdatedCard(ticketPk);
        return;
      }
      refreshed.then(function () {
        reopenCaseFromUpdatedCard(ticketPk);
      });
    }

    function finishActivateTicket(ticketPk, triggerNode, caseBtn) {
      var isPublicPqr = (caseBtn && caseBtn.dataset && caseBtn.dataset.caseKind === "public-pqr");
      var openModal = root.querySelector("#scm-case-modal.open");
      if (openModal) {
        closeCaseModal(openModal);
      }
      return refreshActiveTab().then(function () {
        showToast("success", isPublicPqr ? "Solicitud activada." : "Ticket activado.");
        if (triggerNode && triggerNode.focus) {
          triggerNode.focus();
        }
      });
    }

    function submitActivateTicket(caseBtn, motivo, triggerNode) {
      var ticketPk = String(caseBtn && caseBtn.dataset.ticketPk ? caseBtn.dataset.ticketPk : "").trim();
      if (!ticketPk) {
        showToast("error", "No se encontro el ticket.");
        return Promise.resolve();
      }
      if (!actionActivateTicket) {
        showToast("error", "Accion de activacion no configurada.");
        return Promise.resolve();
      }

      var fd = new FormData();
      fd.append("action", actionActivateTicket);
      fd.append("nonce", nonce);
      fd.append("ticket_pk", ticketPk);
      fd.append("motivo", motivo);

      if (triggerNode) {
        triggerNode.disabled = true;
      }
      return fetch(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo activar el ticket.",
            );
          }
          return finishActivateTicket(ticketPk, triggerNode, caseBtn);
        })
        .catch(function (err) {
          showToast("error", err.message || "No se pudo activar el ticket.");
        })
        .finally(function () {
          if (triggerNode) {
            triggerNode.disabled = false;
          }
        });
    }

    function openActivateTicketPrompt(caseBtn, triggerNode) {
      if (!caseBtn) {
        showToast("error", "No se encontro el ticket.");
        return;
      }
      var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";
      if (window.Swal && typeof window.Swal.fire === "function") {
        window.Swal.fire({
          title: isPublicPqr ? "Activar solicitud" : "Activar ticket",
          input: "textarea",
          inputLabel: "Mensaje de activacion",
          inputPlaceholder: isPublicPqr
            ? "Escribe el mensaje o motivo para activar la solicitud"
            : "Escribe el mensaje o motivo para activar el ticket",
          inputAttributes: { "aria-label": "Mensaje de activacion" },
          showCancelButton: true,
          confirmButtonText: "Activar",
          cancelButtonText: "Cancelar",
          confirmButtonColor: "#1f4f99",
          inputValidator: function (value) {
            return String(value || "").trim()
              ? undefined
              : "El mensaje es obligatorio.";
          },
        }).then(function (result) {
          if (!result || !result.isConfirmed) {
            return;
          }
          submitActivateTicket(
            caseBtn,
            String(result.value || "").trim(),
            triggerNode,
          );
        });
        return;
      }

      var motivo = window.prompt("Mensaje para activar el ticket:");
      if (motivo === null) {
        return;
      }
      motivo = String(motivo || "").trim();
      if (!motivo) {
        showToast("error", "El mensaje es obligatorio.");
        return;
      }
      submitActivateTicket(caseBtn, motivo, triggerNode);
    }

    function refreshActiveTab() {
      var activePanel = root.querySelector(".scm-tab-panel.active");
      var panelId = activePanel ? activePanel.id : "";
      var activeKey = panelId.replace("scm-panel-", "");
      if (activeKey === "mis-tickets") {
        activeKey = "mis_tickets";
      } else if (activeKey === "cotizaciones-mantenimiento") {
        activeKey = "cotizaciones_mantenimiento";
      }
      if (activeKey === "mant" && form) {
        return doFetch(new FormData(form));
      } else if (tabFetchers[activeKey]) {
        return tabFetchers[activeKey].fetchTab(
          new FormData(tabFetchers[activeKey].form),
        );
      } else if (activeKey === "abiertos" && activePanel) {
        var openPanel = activePanel.querySelector(
          ".scm-open-topic-panel.active",
        );
        var openKey = openPanel
          ? openPanel.getAttribute("data-open-topic") || ""
          : "";
        if (openKey === "mant" && form) {
          return doFetch(new FormData(form));
        }
        if (openKey && tabFetchers[openKey]) {
          return tabFetchers[openKey].fetchTab(
            new FormData(tabFetchers[openKey].form),
          );
        }
      } else if (
        (activeKey === "postergados" || activeKey === "cerrados") &&
        activePanel
      ) {
        var statusPanel = activePanel.querySelector(
          ".scm-status-topic-panel.active",
        );
        var statusKey = statusPanel
          ? statusPanel.getAttribute("data-status-key") || ""
          : "";
        if (statusKey && tabFetchers[statusKey]) {
          return tabFetchers[statusKey].fetchTab(
            new FormData(tabFetchers[statusKey].form),
          );
        }
      } else if (activeKey === "pqr-publico" && activePanel) {
        var publicPqrForm = activePanel.querySelector(
          "form.scm-public-pqr-filter-form",
        );
        if (publicPqrForm) {
          publicPqrForm.dispatchEvent(
            new Event("submit", { bubbles: true, cancelable: true }),
          );
        }
      }
      return Promise.resolve();
    }

    root.addEventListener("scm:refresh-active-tab", function () {
      refreshActiveTab();
    });

    function updateKPI(id, val) {
      var el = root.querySelector("#" + id);
      if (el) {
        el.textContent = val;
      }
    }

    function toNumber(value) {
      if (typeof value === "number" && isFinite(value)) {
        return value;
      }
      var parsed = parseFloat(String(value || "0").replace(/[^0-9.-]/g, ""));
      return isFinite(parsed) ? parsed : 0;
    }

    function normalizeMetrics(input) {
      var total = Math.max(0, Math.round(toNumber(input.total)));
      var abiertos = Math.max(0, Math.round(toNumber(input.abiertos)));
      var cerrados = Math.max(0, Math.round(toNumber(input.cerrados)));
      var slaVencido = Math.max(0, Math.round(toNumber(input.sla_vencido)));
      var slaRiesgo = Math.max(0, Math.round(toNumber(input.sla_riesgo)));
      var conCotizacion = Math.max(
        0,
        Math.round(toNumber(input.con_cotizacion)),
      );
      var sinCotizacion = Math.max(
        0,
        Math.round(toNumber(input.sin_cotizacion)),
      );
      var conRevision = Math.max(0, Math.round(toNumber(input.con_revision)));
      var sinRevision = Math.max(0, Math.round(toNumber(input.sin_revision)));

      if (total <= 0) {
        total = abiertos + cerrados;
      }
      if (total <= 0) {
        total = conCotizacion + sinCotizacion;
      }
      if (total <= 0) {
        total = conRevision + sinRevision;
      }

      var slaEnTiempo = Math.max(0, abiertos - slaRiesgo - slaVencido);

      return {
        total: total,
        abiertos: abiertos,
        cerrados: cerrados,
        sla_vencido: slaVencido,
        sla_riesgo: slaRiesgo,
        sla_en_tiempo: slaEnTiempo,
        con_cotizacion: conCotizacion,
        sin_cotizacion: sinCotizacion,
        con_revision: conRevision,
        sin_revision: sinRevision,
        avg_first_h: toNumber(input.avg_first_h),
        avg_close_h: toNumber(input.avg_close_h),
        avg_stale_h: toNumber(input.avg_stale_h),
        mes_actualizados: Math.max(
          0,
          Math.round(toNumber(input.mes_actualizados)),
        ),
        mes_cerrados: Math.max(0, Math.round(toNumber(input.mes_cerrados))),
        mes_seguimientos: Math.max(
          0,
          Math.round(toNumber(input.mes_seguimientos)),
        ),
        con_revision_entrega: Math.max(
          0,
          Math.round(toNumber(input.con_revision_entrega)),
        ),
        sin_revision_entrega: Math.max(
          0,
          Math.round(toNumber(input.sin_revision_entrega)),
        ),
        con_inventario: Math.max(0, Math.round(toNumber(input.con_inventario))),
        sin_inventario: Math.max(0, Math.round(toNumber(input.sin_inventario))),
        con_cita: Math.max(0, Math.round(toNumber(input.con_cita))),
        sin_cita: Math.max(0, Math.round(toNumber(input.sin_cita))),
        con_revision_recibo: Math.max(
          0,
          Math.round(toNumber(input.con_revision_recibo)),
        ),
        sin_revision_recibo: Math.max(
          0,
          Math.round(toNumber(input.sin_revision_recibo)),
        ),
        danos_si: Math.max(0, Math.round(toNumber(input.danos_si))),
        danos_no: Math.max(0, Math.round(toNumber(input.danos_no))),
        estado_nuevo: Math.max(0, Math.round(toNumber(input.estado_nuevo))),
        estado_en_proceso: Math.max(
          0,
          Math.round(toNumber(input.estado_en_proceso)),
        ),
        por_categoria:
          input &&
          typeof input.por_categoria === "object" &&
          input.por_categoria
            ? input.por_categoria
            : {},
        web:
          input && typeof input.web === "object" && input.web
            ? input.web
            : {
                total: 0,
                abiertos: 0,
                en_proceso: 0,
                cerrados: 0,
                por_estado_comercial: {},
                por_estado_administrativo: {},
              },
        seg_por_funcionario:
          input &&
          typeof input.seg_por_funcionario === "object" &&
          input.seg_por_funcionario
            ? input.seg_por_funcionario
            : {},
        abiertos_por_funcionario:
          input &&
          typeof input.abiertos_por_funcionario === "object" &&
          input.abiertos_por_funcionario
            ? input.abiertos_por_funcionario
            : {},
        actualizados_por_funcionario:
          input &&
          typeof input.actualizados_por_funcionario === "object" &&
          input.actualizados_por_funcionario
            ? input.actualizados_por_funcionario
            : {},
      };
    }

    function renderBars(container, rows, unitSuffix) {
      if (!container) {
        return;
      }

      var max = 0;
      rows.forEach(function (row) {
        if (row.value > max) {
          max = row.value;
        }
      });
      if (max <= 0) {
        max = 1;
      }

      container.innerHTML = "";
      rows.forEach(function (row) {
        var item = document.createElement("div");
        item.className = "scm-bar-item";

        var head = document.createElement("div");
        head.className = "scm-bar-head";

        var label = document.createElement("span");
        label.textContent = row.label;

        var value = document.createElement("strong");
        value.textContent =
          unitSuffix === "h"
            ? row.value.toFixed(1) + "h"
            : String(Math.round(row.value));

        head.appendChild(label);
        head.appendChild(value);

        var track = document.createElement("div");
        track.className = "scm-bar-track";

        var fill = document.createElement("div");
        fill.className = "scm-bar-fill " + (row.cls || "");
        fill.style.width = Math.max(5, (row.value / max) * 100) + "%";

        track.appendChild(fill);
        item.appendChild(head);
        item.appendChild(track);
        container.appendChild(item);
      });
    }

    function renderMetricsCharts(metrics, catKey) {
      var total = Math.max(1, metrics.total);

      var totalEl = root.querySelector("#scm-metrics-total");
      if (totalEl) {
        totalEl.textContent = String(metrics.total);
      }

      var abiertosEl = root.querySelector("#scm-metric-abiertos");
      if (abiertosEl) {
        abiertosEl.textContent = String(metrics.abiertos);
      }

      var cerradosEl = root.querySelector("#scm-metric-cerrados");
      if (cerradosEl) {
        cerradosEl.textContent = String(metrics.cerrados);
      }

      var donut = root.querySelector("#scm-chart-estado-ring");
      var donutCenter = root.querySelector("#scm-chart-estado-center");
      if (donut) {
        var openPct = Math.max(
          0,
          Math.min(100, (metrics.abiertos / total) * 100),
        );
        donut.style.background =
          "conic-gradient(var(--scm-metric-open) 0 " +
          openPct.toFixed(2) +
          "%, var(--scm-metric-closed) " +
          openPct.toFixed(2) +
          "% 100%)";
      }
      if (donutCenter) {
        donutCenter.textContent = String(metrics.total);
      }

      renderBars(
        root.querySelector("#scm-chart-sla"),
        [
          { label: "En tiempo", value: metrics.sla_en_tiempo, cls: "success" },
          { label: "En riesgo", value: metrics.sla_riesgo, cls: "warning" },
          { label: "Vencidos", value: metrics.sla_vencido, cls: "danger" },
        ],
        "",
      );

      var flujoRows;
      if (catKey === "entrega") {
        flujoRows = [
          {
            label: "Con revision de entrega",
            value: metrics.con_revision_entrega,
            cls: "success",
          },
          {
            label: "Sin revision de entrega",
            value: metrics.sin_revision_entrega,
            cls: "warning",
          },
          {
            label: "Con inventario",
            value: metrics.con_inventario,
            cls: "accent",
          },
          {
            label: "Sin inventario",
            value: metrics.sin_inventario,
            cls: "neutral",
          },
        ];
      } else if (catKey === "preventiva") {
        flujoRows = [
          {
            label: "Con cotizacion",
            value: metrics.con_cotizacion,
            cls: "accent",
          },
          {
            label: "Sin cotizacion",
            value: metrics.sin_cotizacion,
            cls: "neutral",
          },
          {
            label: "Con revision",
            value: metrics.con_revision,
            cls: "success",
          },
          {
            label: "Sin revision",
            value: metrics.sin_revision,
            cls: "warning",
          },
          { label: "Con danos", value: metrics.danos_si, cls: "danger" },
          { label: "Sin danos", value: metrics.danos_no, cls: "success" },
        ];
      } else if (catKey === "recibo") {
        flujoRows = [
          { label: "Con cita", value: metrics.con_cita, cls: "accent" },
          { label: "Sin cita", value: metrics.sin_cita, cls: "neutral" },
          {
            label: "Con revision de recibo",
            value: metrics.con_revision_recibo,
            cls: "success",
          },
          {
            label: "Sin revision de recibo",
            value: metrics.sin_revision_recibo,
            cls: "warning",
          },
        ];
      } else if (
        catKey === "contable" ||
        catKey === "certificaciones" ||
        catKey === "contractual"
      ) {
        flujoRows = [
          { label: "Nuevo", value: metrics.estado_nuevo, cls: "accent" },
          {
            label: "En proceso",
            value: metrics.estado_en_proceso,
            cls: "warning",
          },
        ];
      } else {
        flujoRows = [
          {
            label: "Con cotizacion",
            value: metrics.con_cotizacion,
            cls: "accent",
          },
          {
            label: "Sin cotizacion",
            value: metrics.sin_cotizacion,
            cls: "neutral",
          },
          {
            label: "Con revision",
            value: metrics.con_revision,
            cls: "success",
          },
          {
            label: "Sin revision",
            value: metrics.sin_revision,
            cls: "warning",
          },
        ];
      }
      renderBars(root.querySelector("#scm-chart-flujo"), flujoRows, "");

      renderBars(
        root.querySelector("#scm-chart-tiempos"),
        [
          {
            label: "Primera gestion",
            value: metrics.avg_first_h,
            cls: "accent",
          },
          { label: "Cierre", value: metrics.avg_close_h, cls: "success" },
          {
            label: "Desactualizacion",
            value: metrics.avg_stale_h,
            cls: "warning",
          },
        ],
        "h",
      );

      renderBars(
        root.querySelector("#scm-chart-produccion"),
        [
          {
            label: "Actualizados",
            value: metrics.mes_actualizados,
            cls: "accent",
          },
          { label: "Cerrados", value: metrics.mes_cerrados, cls: "success" },
          {
            label: "Seguimientos",
            value: metrics.mes_seguimientos,
            cls: "warning",
          },
        ],
        "",
      );

      var categorias = metrics.por_categoria || {};
      var categoriasRows = Object.keys(categorias).map(function (label) {
        return {
          label: label,
          value: Math.max(0, Math.round(toNumber(categorias[label]))),
          cls: "neutral",
        };
      });
      renderBars(
        root.querySelector("#scm-chart-categorias"),
        categoriasRows,
        "",
      );

      renderBars(
        root.querySelector("#scm-chart-seg-funcionario"),
        Object.keys(metrics.seg_por_funcionario || {}).map(function (name) {
          return {
            label: name,
            value: Math.max(
              0,
              Math.round(toNumber((metrics.seg_por_funcionario || {})[name])),
            ),
            cls: "accent",
          };
        }),
        "",
      );

      renderBars(
        root.querySelector("#scm-chart-actualizados-funcionario"),
        Object.keys(metrics.actualizados_por_funcionario || {}).map(
          function (name) {
            return {
              label: name,
              value: Math.max(
                0,
                Math.round(
                  toNumber((metrics.actualizados_por_funcionario || {})[name]),
                ),
              ),
              cls: "warning",
            };
          },
        ),
        "",
      );

      renderBars(
        root.querySelector("#scm-chart-abiertos-funcionario"),
        Object.keys(metrics.abiertos_por_funcionario || {}).map(
          function (name) {
            return {
              label: name,
              value: Math.max(
                0,
                Math.round(
                  toNumber((metrics.abiertos_por_funcionario || {})[name]),
                ),
              ),
              cls: "neutral",
            };
          },
        ),
        "",
      );
    }

    function renderGuardianMetrics(webMetrics) {
      webMetrics = webMetrics || {};
      renderBars(
        root.querySelector("#scm-chart-web"),
        [
          {
            label: "Total Guardian",
            value: Math.max(0, Math.round(toNumber(webMetrics.total))),
            cls: "accent",
          },
          {
            label: "Abiertos",
            value: Math.max(0, Math.round(toNumber(webMetrics.abiertos))),
            cls: "success",
          },
          {
            label: "En proceso",
            value: Math.max(0, Math.round(toNumber(webMetrics.en_proceso))),
            cls: "warning",
          },
          {
            label: "Cerrados",
            value: Math.max(0, Math.round(toNumber(webMetrics.cerrados))),
            cls: "danger",
          },
        ],
        "",
      );

      var webComercial = webMetrics.por_estado_comercial || {};
      renderBars(
        root.querySelector("#scm-chart-web-comercial"),
        Object.keys(webComercial).map(function (label) {
          return {
            label: label,
            value: Math.max(0, Math.round(toNumber(webComercial[label]))),
            cls: "accent",
          };
        }),
        "",
      );

      var webAdmin = webMetrics.por_estado_administrativo || {};
      renderBars(
        root.querySelector("#scm-chart-web-admin"),
        Object.keys(webAdmin).map(function (label) {
          return {
            label: label,
            value: Math.max(0, Math.round(toNumber(webAdmin[label]))),
            cls: "warning",
          };
        }),
        "",
      );
    }

    function toggleKpiVisibility(id, visible) {
      var el = root.querySelector("#" + id);
      if (!el || !el.parentElement) {
        return;
      }
      el.parentElement.style.display = visible ? "" : "none";
    }

    function applyRevisionKpiVisibility(
      prefix,
      conRevisionRaw,
      sinRevisionRaw,
    ) {
      var conRevision = Math.max(0, Math.round(toNumber(conRevisionRaw)));
      var sinRevision = Math.max(0, Math.round(toNumber(sinRevisionRaw)));
      var showOnlyConRevision = conRevision > 0 && sinRevision === 0;
      var showOnlySinRevision = sinRevision > 0 && conRevision === 0;
      var showMagnitude = !showOnlySinRevision;

      toggleKpiVisibility(prefix + "kpi-sin-prev", !showOnlyConRevision);
      toggleKpiVisibility(prefix + "kpi-magnitud-critico", showMagnitude);
      toggleKpiVisibility(prefix + "kpi-magnitud-alto", showMagnitude);
      toggleKpiVisibility(prefix + "kpi-magnitud-medio", showMagnitude);
      toggleKpiVisibility(prefix + "kpi-magnitud-bajo", showMagnitude);
    }

    function applyBinaryFilterKpiVisibility(prefix, filterValue, conSuffix, sinSuffix) {
      var normalized = String(filterValue || "").trim().toLowerCase();
      var showCon = true;
      var showSin = true;

      if (normalized === "has") {
        showSin = false;
      } else if (normalized === "none") {
        showCon = false;
      }

      toggleKpiVisibility(prefix + "kpi-" + conSuffix, showCon);
      toggleKpiVisibility(prefix + "kpi-" + sinSuffix, showSin);
    }

    function applyCotizacionDependentFilters(scope, cotizacionSelect) {
      if (!scope || !cotizacionSelect) {
        return;
      }
      var show = String(cotizacionSelect.value || "").trim().toLowerCase() === "has";
      var cotizacionId = cotizacionSelect.id || "";
      scope
        .querySelectorAll(".scm-cotizacion-dependent")
        .forEach(function (field) {
          var target = field.getAttribute("data-cotizacion-dependent-for") || "";
          if (target && cotizacionId && target !== cotizacionId) {
            return;
          }
          field.style.display = show ? "" : "none";
          if (!show) {
            field.querySelectorAll("select, input").forEach(function (input) {
              input.value = "";
              if (input.dispatchEvent) {
                input.dispatchEvent(new Event("change", { bubbles: true }));
              }
            });
          }
        });
    }

    function getCategoryMetricSet(baseMetrics, key) {
      var details =
        baseMetrics &&
        typeof baseMetrics.detalle_por_categoria === "object" &&
        baseMetrics.detalle_por_categoria
          ? baseMetrics.detalle_por_categoria
          : {};
      var row = details[key] || details.mantenimiento || {};
      return normalizeMetrics({
        total: row.total,
        abiertos: row.abiertos,
        cerrados: row.cerrados,
        sla_vencido: row.sla_vencido,
        sla_riesgo: row.sla_riesgo,
        con_cotizacion: row.con_cotizacion,
        sin_cotizacion: row.sin_cotizacion,
        con_revision: row.con_revision,
        sin_revision: row.sin_revision,
        avg_first_h: row.avg_first_h,
        avg_close_h: row.avg_close_h,
        avg_stale_h: row.avg_stale_h,
        mes_actualizados: row.mes_actualizados,
        mes_cerrados: row.mes_cerrados,
        mes_seguimientos: row.mes_seguimientos,
        con_revision_entrega: row.con_revision_entrega,
        sin_revision_entrega: row.sin_revision_entrega,
        con_inventario: row.con_inventario,
        sin_inventario: row.sin_inventario,
        con_cita: row.con_cita,
        sin_cita: row.sin_cita,
        con_revision_recibo: row.con_revision_recibo,
        sin_revision_recibo: row.sin_revision_recibo,
        danos_si: row.danos_si,
        danos_no: row.danos_no,
        estado_nuevo: row.estado_nuevo,
        estado_en_proceso: row.estado_en_proceso,
        seg_por_funcionario: row.seg_por_funcionario,
        abiertos_por_funcionario: row.abiertos_por_funcionario,
        actualizados_por_funcionario: row.actualizados_por_funcionario,
        por_categoria: baseMetrics.por_categoria || {},
      });
    }

    function readInitialMetrics() {
      var panel = root.querySelector("#scm-panel-metricas");
      if (!panel) {
        return null;
      }
      var raw = panel.getAttribute("data-scm-metrics") || "";
      if (!raw) {
        return null;
      }
      try {
        return JSON.parse(raw);
      } catch (err) {
        console.error("SCM metrics parse error:", err);
        return null;
      }
    }

    var activeMetricCategory = "mantenimiento";
    function updateMetricsFromAjax(data) {
      if (!initialMetrics) {
        return;
      }
      if (!initialMetrics.detalle_por_categoria) {
        initialMetrics.detalle_por_categoria = {};
      }
      initialMetrics.detalle_por_categoria.mantenimiento = {
        label: "Mantenimiento",
        total: data.kpi_total,
        abiertos: data.kpi_abiertos,
        cerrados: data.kpi_cerrados,
        sla_vencido: data.kpi_vencidos,
        sla_riesgo: data.kpi_en_riesgo,
        con_cotizacion: data.kpi_con_cotz,
        sin_cotizacion: data.kpi_sin_cotz,
        con_revision: data.kpi_con_prev,
        sin_revision: data.kpi_sin_prev,
        avg_first_h: data.kpi_avg_first_h,
        avg_close_h: data.kpi_avg_close_h,
        avg_stale_h: data.kpi_avg_stale_h,
        mes_actualizados: data.kpi_mes_actualizados,
        mes_cerrados: data.kpi_mes_cerrados,
        mes_seguimientos: data.kpi_mes_seguimientos,
        seg_por_funcionario: data.kpi_seg_por_funcionario || {},
        abiertos_por_funcionario: data.kpi_abiertos_por_funcionario || {},
        actualizados_por_funcionario:
          data.kpi_actualizados_por_funcionario || {},
      };
      initialMetrics.por_categoria =
        data.kpi_por_categoria || initialMetrics.por_categoria || {};
      renderMetricsCharts(
        getCategoryMetricSet(initialMetrics, activeMetricCategory),
        activeMetricCategory,
      );
    }

    var initialMetrics = readInitialMetrics();
    if (initialMetrics) {
      var initialCategoryMetrics = getCategoryMetricSet(
        initialMetrics,
        activeMetricCategory,
      );
      renderMetricsCharts(initialCategoryMetrics, activeMetricCategory);
      renderGuardianMetrics(initialMetrics.web || {});
      applyRevisionKpiVisibility(
        "scm-",
        initialCategoryMetrics.con_revision,
        initialCategoryMetrics.sin_revision,
      );
      var metricTabsWrap = root.querySelector("#scm-metric-tabs");
      if (metricTabsWrap) {
        function showMetricsPane(name) {
          name = name === "guardian" ? "guardian" : "operativas";
          root.querySelectorAll("[data-scm-metrics-pane]").forEach(function (pane) {
            pane.classList.toggle(
              "active",
              pane.getAttribute("data-scm-metrics-pane") === name,
            );
          });
        }
        metricTabsWrap
          .querySelectorAll("[data-scm-metric-cat]")
          .forEach(function (btn) {
            btn.addEventListener("click", function () {
              showMetricsPane("operativas");
              activeMetricCategory =
                btn.getAttribute("data-scm-metric-cat") || "mantenimiento";
              metricTabsWrap
                .querySelectorAll("[data-scm-metric-cat], [data-scm-metric-panel]")
                .forEach(function (b) {
                  b.classList.remove("active");
                });
              btn.classList.add("active");
              var activeCategoryMetrics = getCategoryMetricSet(
                initialMetrics,
                activeMetricCategory,
              );
              renderMetricsCharts(activeCategoryMetrics, activeMetricCategory);
              applyRevisionKpiVisibility(
                "scm-",
                activeCategoryMetrics.con_revision,
                activeCategoryMetrics.sin_revision,
              );
            });
          });
        metricTabsWrap
          .querySelectorAll("[data-scm-metric-panel]")
          .forEach(function (btn) {
            btn.addEventListener("click", function () {
              metricTabsWrap
                .querySelectorAll("[data-scm-metric-cat], [data-scm-metric-panel]")
                .forEach(function (b) {
                  b.classList.remove("active");
                });
              btn.classList.add("active");
              showMetricsPane(btn.getAttribute("data-scm-metric-panel") || "guardian");
              renderGuardianMetrics(initialMetrics.web || {});
            });
          });
      }
    }

    var revisionPrefixes = [
      "scm-",
      "scm-entrega-",
      "scm-preventiva-",
      "scm-recibo-",
      "scm-contable-",
      "scm-certificaciones-",
      "scm-contractual-",
    ];
    revisionPrefixes.forEach(function (prefix) {
      var conEl = root.querySelector("#" + prefix + "kpi-con-prev");
      var sinEl = root.querySelector("#" + prefix + "kpi-sin-prev");
      if (!conEl || !sinEl) {
        return;
      }
      applyRevisionKpiVisibility(prefix, conEl.textContent, sinEl.textContent);
    });

    applyBinaryFilterKpiVisibility(
      "scm-",
      root.querySelector("#scm_cotizacion")
        ? root.querySelector("#scm_cotizacion").value
        : "",
      "con-cotz",
      "sin-cotz",
    );

    var form = root.querySelector("#scm-form");
    var tbody = root.querySelector("#scm-tbody");
    var pagination = root.querySelector("#scm-pagination");
    var spinner = root.querySelector("#scm-spinner");
    var clearBtn = root.querySelector("#scm-clear");

    function setMantPage(page) {
      if (!form) {
        return;
      }
      var pageInput = form.querySelector("#scm_page");
      if (pageInput) {
        pageInput.value = String(page);
      }
    }

    function doFetch(fd) {
      if (!form || !tbody || !ajaxUrl || !actionMant) {
        return Promise.resolve();
      }

      fd.append("action", actionMant);
      fd.append("nonce", nonce);
      fd.append("config", JSON.stringify(config));

      if (spinner) {
        spinner.classList.add("active");
      }
      setListLoading(tbody, true, "Cargando tickets...");
      form.classList.add("scm-loading");

      return fetch(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            return;
          }
          var d = json.data || {};
          tbody.innerHTML = d.tbody || "";
          if (pagination) {
            pagination.innerHTML = d.pagination || "";
          }
          if (tbody) {
            tbody.scrollIntoView({ behavior: "smooth", block: "start" });
          }

          updateKPI("scm-kpi-total", d.kpi_total || "0");
          updateKPI("scm-kpi-sin-cotz", d.kpi_sin_cotz || "0");
          updateKPI("scm-kpi-con-cotz", d.kpi_con_cotz || "0");
          updateKPI("scm-kpi-sin-prev", d.kpi_sin_prev || "0");
          updateKPI("scm-kpi-con-prev", d.kpi_con_prev || "0");
          updateKPI("scm-kpi-abiertos", d.kpi_abiertos || "0");
          updateKPI("scm-kpi-cerrados", d.kpi_cerrados || "0");
          updateKPI("scm-kpi-vencidos", d.kpi_vencidos || "0");
          updateKPI("scm-kpi-riesgo", d.kpi_en_riesgo || "0");
          updateKPI("scm-kpi-avg-first", d.kpi_avg_first_h || "-");
          updateKPI("scm-kpi-avg-close", d.kpi_avg_close_h || "-");
          updateKPI("scm-kpi-avg-stale", d.kpi_avg_stale_h || "-");
          updateKPI("scm-kpi-magnitud-critico", d.kpi_magnitud_critico || "0");
          updateKPI("scm-kpi-magnitud-alto", d.kpi_magnitud_alto || "0");
          updateKPI("scm-kpi-magnitud-medio", d.kpi_magnitud_medio || "0");
          updateKPI("scm-kpi-magnitud-bajo", d.kpi_magnitud_bajo || "0");
          updateKPI("scm-kpi-header-count", d.kpi_total || "0");
          applyRevisionKpiVisibility("scm-", d.kpi_con_prev, d.kpi_sin_prev);
          applyBinaryFilterKpiVisibility(
            "scm-",
            form && form.querySelector("#scm_cotizacion")
              ? form.querySelector("#scm_cotizacion").value
              : "",
            "con-cotz",
            "sin-cotz",
          );
          updateMetricsFromAjax(d);
        })
        .catch(function (err) {
          console.error("SCM error:", err);
        })
        .finally(function () {
          if (spinner) {
            spinner.classList.remove("active");
          }
          setListLoading(tbody, false);
          form.classList.remove("scm-loading");
        });
    }

    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        setMantPage(1);
        doFetch(new FormData(form));
      });
      var perPageSelect = form.querySelector("#scm_per_page");
      var cotizacionSelect = form.querySelector("#scm_cotizacion");
      if (perPageSelect) {
        perPageSelect.addEventListener("change", function () {
          setMantPage(1);
          doFetch(new FormData(form));
        });
      }
      if (cotizacionSelect) {
        cotizacionSelect.addEventListener("change", function () {
          setMantPage(1);
          applyCotizacionDependentFilters(form, cotizacionSelect);
          applyBinaryFilterKpiVisibility(
            "scm-",
            cotizacionSelect.value,
            "con-cotz",
            "sin-cotz",
          );
          doFetch(new FormData(form));
        });
        applyCotizacionDependentFilters(form, cotizacionSelect);
      }
      form
        .querySelectorAll(
          ".scm-cotizacion-dependent select, .scm-cotizacion-dependent input",
        )
        .forEach(function (field) {
          field.addEventListener("change", function () {
            setMantPage(1);
            doFetch(new FormData(form));
          });
        });
    }

    if (clearBtn && form) {
        clearBtn.addEventListener("click", function () {
        form.querySelectorAll("select").forEach(function (s) {
          s.selectedIndex = 0;
        });
        form
          .querySelectorAll("input[type='text'], input[type='date']")
          .forEach(function (i) {
            i.value = "";
          });
          setMantPage(1);
          applyBinaryFilterKpiVisibility("scm-", "", "con-cotz", "sin-cotz");
          if (cotizacionSelect) {
            applyCotizacionDependentFilters(form, cotizacionSelect);
          }
          doFetch(new FormData(form));
        });
      }

    if (pagination && form) {
      pagination.addEventListener("click", function (e) {
        var pageBtn =
          e.target && e.target.closest
            ? e.target.closest(".scm-page-btn:not(.scm-page-btn-generic)")
            : null;
        if (
          !pageBtn ||
          pageBtn.disabled ||
          pageBtn.getAttribute("aria-disabled") === "true"
        ) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        setMantPage(pageBtn.getAttribute("data-page") || "1");
        doFetch(new FormData(form));
      });
    }

    root.querySelectorAll(".scm-classify-magnitude").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var type = btn.getAttribute("data-revision-type") || "correctiva";
        var oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Clasificando...";
        classifyMagnitude(
          type,
          function () {
            var tabKey = btn.getAttribute("data-tab-key") || "";
            if (tabKey && tabFetchers[tabKey]) {
              tabFetchers[tabKey].fetchTab(
                new FormData(tabFetchers[tabKey].form),
              );
            } else if (form) {
              setMantPage(1);
              doFetch(new FormData(form));
            }
          },
          function () {
            btn.textContent = oldText;
            btn.disabled = false;
          },
        );
      });
    });

    root.addEventListener("click", function (e) {
      var addTicketDocBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-add-ticket-document]")
          : null;
      if (addTicketDocBtn) {
        e.preventDefault();
        var form = addTicketDocBtn.closest("form");
        var docsWrap = form ? form.querySelector("[data-ticket-documents]") : null;
        var firstRow = docsWrap
          ? docsWrap.querySelector(".scm-ticket-document-row")
          : null;
        if (docsWrap && firstRow) {
          var clone = firstRow.cloneNode(true);
          clone.querySelectorAll("input").forEach(function (input) {
            input.value = "";
          });
          docsWrap.appendChild(clone);
        } else if (docsWrap) {
          docsWrap.insertAdjacentHTML("beforeend", renderTicketDocumentRow());
        }
        return;
      }

      var removeTicketDocBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-remove-ticket-document]")
          : null;
      if (removeTicketDocBtn) {
        e.preventDefault();
        var row = removeTicketDocBtn.closest(".scm-ticket-document-row");
        if (row) {
          row.remove();
        }
        return;
      }

      var extLink =
        e.target && e.target.closest ? e.target.closest("a[href]") : null;
      if (
        extLink &&
        extLink.closest(".scm-case-modal") &&
        extLink.getAttribute("target") !== "_blank" &&
        /^https?:\/\//i.test(extLink.getAttribute("href") || "")
      ) {
        e.preventDefault();
        openIframeModal(
          extLink.href,
          (extLink.textContent || "").trim() || extLink.getAttribute("href"),
        );
        return;
      }

      var adminTicketBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-admin-ticket]")
          : null;
      if (adminTicketBtn) {
        e.preventDefault();
        openAdminTicketModal(adminTicketBtn);
        return;
      }

      var iframeBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-iframe]")
          : null;
      if (iframeBtn) {
        e.preventDefault();
        openIframeModal(
          iframeBtn.dataset.iframeUrl || "",
          iframeBtn.dataset.iframeTitle || "",
          iframeBtn.hasAttribute("data-scm-compact-iframe"),
        );
        return;
      }

      var activateTicketBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-activate-ticket]")
          : null;
      if (activateTicketBtn) {
        e.preventDefault();
        var activateCard = activateTicketBtn.closest(".scm-ticket-card");
        var activateCaseBtn = activateCard
          ? activateCard.querySelector(".scm-btn-case")
          : null;
        if (!activateCaseBtn) {
          var activeModal = activateTicketBtn.closest(".scm-case-modal");
          var activeTicketPk = activeModal ? activeModal.dataset.ticketPk || "" : "";
          activateCaseBtn = activeTicketPk
            ? root.querySelector(
                '.scm-btn-case[data-ticket-pk="' +
                  cssAttrValue(activeTicketPk) +
                  '"]',
              )
            : null;
        }
        if (!activateCaseBtn) {
          showToast("error", "No se encontro el ticket.");
          return;
        }
        openActivateTicketPrompt(activateCaseBtn, activateTicketBtn);
        return;
      }

      var cardConsultorBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-card-consultor]")
          : null;
      var locationEditorBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-location-editor]")
          : null;
      if (locationEditorBtn) {
        e.preventDefault();
        var parentCaseModal = locationEditorBtn.closest(".scm-case-modal");
        if (parentCaseModal) {
          var activeTicketPk = parentCaseModal.dataset.ticketPk || "";
          var activeCaseBtn = activeTicketPk
            ? root.querySelector(
                '.scm-btn-case[data-ticket-pk="' +
                  cssAttrValue(activeTicketPk) +
                  '"]',
              )
            : null;
          if (activeCaseBtn) {
            openPropertyLocationEditor(parentCaseModal, activeCaseBtn);
          }
          return;
        }
        var standaloneModal = locationEditorBtn.closest(
          ".scm-standalone-detail-modal",
        );
        if (standaloneModal) {
          var standaloneTicketPk = standaloneModal.dataset.ticketPk || "";
          var standaloneCaseBtn = standaloneTicketPk
            ? root.querySelector(
                '.scm-btn-case[data-ticket-pk="' +
                  cssAttrValue(standaloneTicketPk) +
                  '"]',
              )
            : null;
          if (standaloneCaseBtn) {
            openPropertyLocationStandaloneEditor(root, standaloneCaseBtn);
          }
          return;
        }
      }
      if (cardConsultorBtn) {
        e.preventDefault();
        var consultorCard = cardConsultorBtn.closest(".scm-ticket-card");
        var consultorCaseBtn = consultorCard
          ? consultorCard.querySelector(".scm-btn-case")
          : null;
        if (!consultorCaseBtn) {
          return;
        }
        var consultorRoot = findRootFromNode(cardConsultorBtn) || root;
        openStandaloneDetail(
          consultorRoot,
          Object.assign(getConsultorEntregaDetailPayload(consultorCaseBtn), {
            caseBtn: consultorCaseBtn,
          }),
        );
        return;
      }

      var cardLlavesBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-card-llaves]")
          : null;
      if (cardLlavesBtn) {
        e.preventDefault();
        var llavesCard = cardLlavesBtn.closest(".scm-ticket-card");
        var llavesCaseBtn = llavesCard
          ? llavesCard.querySelector(".scm-btn-case")
          : null;
        if (!llavesCaseBtn) {
          return;
        }
        var llavesRoot = findRootFromNode(cardLlavesBtn) || root;
        openStandaloneDetail(
          llavesRoot,
          Object.assign(getLlavesDetailPayload(llavesCaseBtn), {
            caseBtn: llavesCaseBtn,
          }),
        );
        return;
      }

      var caseBtn =
        e.target && e.target.closest ? e.target.closest(".scm-btn-case") : null;
      if (caseBtn) {
        e.preventDefault();
        window.scmOpenCase(caseBtn);
        return;
      }

      var historyPager =
        e.target && e.target.closest
          ? e.target.closest(".scm-history-page-btn")
          : null;
      if (historyPager) {
        e.preventDefault();
        var targetId = historyPager.getAttribute("data-target") || "";
        var pagerScope =
          historyPager.closest(".scm-case-modal") ||
          historyPager.closest(".scm-case-main") ||
          root;
        var list = targetId ? pagerScope.querySelector("#" + targetId) : null;
        if (!list) {
          return;
        }

        var items = list.querySelectorAll(".scm-case-history-item[data-page]");
        if (!items.length) {
          return;
        }

        var totalPages = 1;
        items.forEach(function (it) {
          var p = parseInt(it.getAttribute("data-page") || "1", 10);
          if (p > totalPages) {
            totalPages = p;
          }
        });

        var current = parseInt(
          list.getAttribute("data-current-page") || "1",
          10,
        );
        var dir = historyPager.getAttribute("data-dir") || "next";
        var next = dir === "prev" ? current - 1 : current + 1;
        if (next < 1) {
          next = 1;
        }
        if (next > totalPages) {
          next = totalPages;
        }
        if (next === current) {
          return;
        }

        list.setAttribute("data-current-page", String(next));
        items.forEach(function (it) {
          var p = parseInt(it.getAttribute("data-page") || "1", 10);
          it.style.display = p === next ? "" : "none";
        });

        var status = pagerScope.querySelector(
          '.scm-history-page-status[data-target="' + targetId + '"]',
        );
        if (status) {
          var total = parseInt(status.getAttribute("data-total") || "0", 10);
          if (total <= 0) {
            total = items.length;
          }
          var perPage = parseInt(
            status.getAttribute("data-per-page") || "10",
            10,
          );
          if (perPage <= 0) {
            perPage = 10;
          }
          var start = (next - 1) * perPage + 1;
          var end = Math.min(total, next * perPage);
          status.textContent =
            "Mostrando " +
            String(start) +
            "-" +
            String(end) +
            " de " +
            String(total) +
            " | Pagina " +
            String(next) +
            " de " +
            String(totalPages);
        }

        var pagerBtns = pagerScope.querySelectorAll(
          '.scm-history-page-btn[data-target="' + targetId + '"]',
        );
        pagerBtns.forEach(function (b) {
          var bDir = b.getAttribute("data-dir") || "next";
          b.disabled =
            (bDir === "prev" && next <= 1) ||
            (bDir === "next" && next >= totalPages);
        });

        return;
      }

      var btn =
        e.target && e.target.closest ? e.target.closest(".scm-page-btn") : null;
      if (!btn || !form) {
        return;
      }
      if (btn.classList && btn.classList.contains("scm-page-btn-generic")) {
        return;
      }
      // Solo manejar botones de paginación del panel de Mantenimiento
      var mantPanel = root.querySelector("#scm-panel-mant");
      if (mantPanel && !mantPanel.contains(btn)) {
        return;
      }
      e.preventDefault();
      if (btn.disabled) {
        return;
      }
      setMantPage(btn.getAttribute("data-page") || "1");
      doFetch(new FormData(form));
    });

    // Generic tab fetchers
    var tabFetchers = {};
    function updateGenericKPI(tabKey, suffix, value) {
      var el = root.querySelector("#scm-" + tabKey + "-kpi-" + suffix);
      if (el) {
        el.textContent = value;
      }
    }

    function classifyMagnitude(revisionType, afterDone, afterFinally) {
      var fd = new FormData();
      fd.append(
        "action",
        (actions && actions.classify_magnitude) || "scm_clasificar_magnitud",
      );
      fd.append("nonce", nonce);
      fd.append("revision_type", revisionType || "correctiva");

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo clasificar.",
            );
          }
          if (typeof afterDone === "function") {
            afterDone(json.data || {});
          }
        })
        .catch(function (err) {
          alert(
            "No se pudo clasificar la magnitud: " + (err.message || "error"),
          );
        })
        .finally(function () {
          if (typeof afterFinally === "function") {
            afterFinally();
          }
        });
    }

    function makeTabFetcher(tabKey, action) {
      var tabForm = root.querySelector("#scm-form-" + tabKey);
      var tabCards = root.querySelector("#scm-cards-" + tabKey);
      var tabPagination = root.querySelector("#scm-pagination-" + tabKey);
      var tabCount = root.querySelector("#scm-" + tabKey + "-count");
      var tabSpinner = root.querySelector("#scm-spinner-" + tabKey);
      var tabClear = root.querySelector("#scm-clear-" + tabKey);
      var pageInput = tabForm
        ? tabForm.querySelector("input[name$='page']")
        : null;
      var tabCotizacionSelect = tabForm
        ? tabForm.querySelector("[name$='cotizacion']")
        : null;
      var tabPanel = tabCards
        ? tabCards.closest(".scm-open-topic-panel") ||
          tabCards.closest(".scm-tab-panel")
        : null;

      if (!tabForm || !tabCards || !ajaxUrl || !action) {
        return null;
      }

      function fetchTab(fd) {
        fd.append("action", action);
        fd.append("nonce", nonce);
        fd.append("config", JSON.stringify(config));

        if (tabSpinner) {
          tabSpinner.classList.add("active");
        }
        setListLoading(tabCards, true, "Cargando tickets...");
        tabForm.classList.add("scm-loading");

        return fetch(ajaxUrl, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              return;
            }
            var d = json.data || {};
            tabCards.innerHTML = d.cards || "";
            if (tabPagination) {
              tabPagination.innerHTML = d.pagination || "";
            }
            if (tabCount) {
              tabCount.textContent = d.count || "0";
            }
            if (tabPanel) {
              tabPanel.setAttribute("data-scm-loaded", "1");
            }
            updateGenericKPI(tabKey, "total", d.kpi_total || "0");
            if (tabKey === "cotizaciones_mantenimiento") {
              updateGenericKPI(tabKey, "enviadas", d.kpi_enviadas || "0");
              updateGenericKPI(
                tabKey,
                "no-enviadas",
                d.kpi_no_enviadas || "0",
              );
              updateGenericKPI(tabKey, "aprobadas", d.kpi_aprobadas || "0");
              updateGenericKPI(
                tabKey,
                "desaprobadas",
                d.kpi_desaprobadas || "0",
              );
            }
            updateGenericKPI(tabKey, "con-cotz", d.kpi_con_cotz || "0");
            updateGenericKPI(tabKey, "sin-cotz", d.kpi_sin_cotz || "0");
            updateGenericKPI(tabKey, "con-prev", d.kpi_con_prev || "0");
            updateGenericKPI(tabKey, "sin-prev", d.kpi_sin_prev || "0");
            if (tabKey === "entrega") {
              updateGenericKPI(
                tabKey,
                "con-rev-entrega",
                d.kpi_con_rev_entrega || "0",
              );
              updateGenericKPI(
                tabKey,
                "sin-rev-entrega",
                d.kpi_sin_rev_entrega || "0",
              );
              updateGenericKPI(
                tabKey,
                "con-inventario",
                d.kpi_con_inventario || "0",
              );
              updateGenericKPI(
                tabKey,
                "sin-inventario",
                d.kpi_sin_inventario || "0",
              );
            }
            if (tabKey === "recibo") {
              updateGenericKPI(tabKey, "con-cita", d.kpi_con_cita || "0");
              updateGenericKPI(tabKey, "sin-cita", d.kpi_sin_cita || "0");
              updateGenericKPI(
                tabKey,
                "con-rev-recibo",
                d.kpi_con_rev_recibo || "0",
              );
              updateGenericKPI(
                tabKey,
                "sin-rev-recibo",
                d.kpi_sin_rev_recibo || "0",
              );
            }
            if (
              ["contractual", "contable", "certificaciones"].includes(tabKey)
            ) {
              updateGenericKPI(tabKey, "nuevo", d.kpi_nuevo || "0");
              updateGenericKPI(tabKey, "en-proceso", d.kpi_en_proceso || "0");
            }
            updateGenericKPI(tabKey, "avg-first", d.kpi_avg_first_h || "-");
            updateGenericKPI(tabKey, "avg-stale", d.kpi_avg_stale_h || "-");
            updateGenericKPI(
              tabKey,
              "magnitud-critico",
              d.kpi_magnitud_critico || "0",
            );
            updateGenericKPI(
              tabKey,
              "magnitud-alto",
              d.kpi_magnitud_alto || "0",
            );
            updateGenericKPI(
              tabKey,
              "magnitud-medio",
              d.kpi_magnitud_medio || "0",
            );
            updateGenericKPI(
              tabKey,
              "magnitud-bajo",
              d.kpi_magnitud_bajo || "0",
            );
            updateGenericKPI(tabKey, "danos-si", d.kpi_danos_si || "0");
            updateGenericKPI(tabKey, "danos-no", d.kpi_danos_no || "0");
            applyRevisionKpiVisibility(
              "scm-" + tabKey + "-",
              d.kpi_con_prev,
              d.kpi_sin_prev,
            );
            applyBinaryFilterKpiVisibility(
              "scm-" + tabKey + "-",
              tabForm.querySelector("[name$='cotizacion']")
                ? tabForm.querySelector("[name$='cotizacion']").value
                : "",
              "con-cotz",
              "sin-cotz",
            );
          })
          .catch(function (err) {
            console.error("SCM " + tabKey + " error:", err);
          })
          .finally(function () {
            if (tabSpinner) {
              tabSpinner.classList.remove("active");
            }
            setListLoading(tabCards, false);
            tabForm.classList.remove("scm-loading");
          });
      }

      tabForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (pageInput) {
          pageInput.value = "1";
        }
        fetchTab(new FormData(tabForm));
      });

      var tabCotizacionSelect = tabForm.querySelector("[name$='cotizacion']");
      if (tabCotizacionSelect) {
        tabCotizacionSelect.addEventListener("change", function () {
          if (pageInput) {
            pageInput.value = "1";
          }
          applyCotizacionDependentFilters(tabForm, tabCotizacionSelect);
          applyBinaryFilterKpiVisibility(
            "scm-" + tabKey + "-",
            tabCotizacionSelect.value,
            "con-cotz",
            "sin-cotz",
          );
          fetchTab(new FormData(tabForm));
        });
      }
      tabForm
        .querySelectorAll(
          ".scm-cotizacion-dependent select, .scm-cotizacion-dependent input",
        )
        .forEach(function (field) {
          field.addEventListener("change", function () {
            if (pageInput) {
              pageInput.value = "1";
            }
            fetchTab(new FormData(tabForm));
          });
        });

      if (tabClear) {
        tabClear.addEventListener("click", function () {
          tabForm.querySelectorAll("select").forEach(function (s) {
            s.selectedIndex = 0;
          });
          tabForm
            .querySelectorAll("input[type='text'], input[type='date']")
            .forEach(function (i) {
              i.value = "";
            });
          if (pageInput) {
            pageInput.value = "1";
          }
          applyBinaryFilterKpiVisibility(
            "scm-" + tabKey + "-",
            "",
            "con-cotz",
            "sin-cotz",
          );
          if (tabCotizacionSelect) {
            applyCotizacionDependentFilters(tabForm, tabCotizacionSelect);
          }
          fetchTab(new FormData(tabForm));
        });
      }

      applyBinaryFilterKpiVisibility(
        "scm-" + tabKey + "-",
        tabCotizacionSelect ? tabCotizacionSelect.value : "",
        "con-cotz",
        "sin-cotz",
      );
      if (tabCotizacionSelect) {
        applyCotizacionDependentFilters(tabForm, tabCotizacionSelect);
      }

      if (tabPagination) {
        tabPagination.addEventListener("click", function (e) {
          var pageBtn =
            e.target && e.target.closest
              ? e.target.closest(".scm-page-btn-generic")
              : null;
          if (!pageBtn || pageBtn.disabled) {
            return;
          }
          e.preventDefault();
          e.stopPropagation();
          if (pageInput) {
            pageInput.value = String(pageBtn.getAttribute("data-page") || "1");
          }
          fetchTab(new FormData(tabForm));
        });
      }

      return { fetchTab: fetchTab, form: tabForm };
    }

    function makeStatusFetcher(statusPanel) {
      var statusKey = statusPanel
        ? statusPanel.getAttribute("data-status-key") || ""
        : "";
      var bucket = statusPanel
        ? statusPanel.getAttribute("data-status-bucket") || ""
        : "";
      var topic = statusPanel
        ? statusPanel.getAttribute("data-status-topic") || ""
        : "";
      var tabForm = statusKey
        ? root.querySelector("#scm-form-" + statusKey)
        : null;
      var tabCards = statusKey
        ? root.querySelector("#scm-status-cards-" + statusKey)
        : null;
      var tabPagination = statusKey
        ? root.querySelector("#scm-status-pagination-" + statusKey)
        : null;
      var tabCount = statusKey
        ? root.querySelector("#scm-" + statusKey + "-count")
        : null;
      var tabSpinner = statusKey
        ? root.querySelector("#scm-spinner-" + statusKey)
        : null;
      var tabClear = statusKey
        ? root.querySelector("#scm-clear-" + statusKey)
        : null;
      var pageInput = tabForm
        ? tabForm.querySelector("input[name$='page']")
        : null;
      var tabCotizacionSelect = tabForm
        ? tabForm.querySelector("[name$='cotizacion']")
        : null;

      if (
        !statusKey ||
        !tabForm ||
        !tabCards ||
        !ajaxUrl ||
        !actionStatusTickets
      ) {
        return null;
      }

      function fetchTab(fd) {
        fd.append("action", actionStatusTickets);
        fd.append("nonce", nonce);
        fd.append("config", JSON.stringify(config));
        fd.append("bucket", bucket);
        fd.append("topic", topic);

        if (tabSpinner) {
          tabSpinner.classList.add("active");
        }
        setListLoading(tabCards, true, "Cargando vista...");
        tabForm.classList.add("scm-loading");

        return fetch(ajaxUrl, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudo cargar la vista.",
              );
            }
            var d = json.data || {};
            tabCards.innerHTML = d.cards || "";
            if (tabPagination) {
              tabPagination.innerHTML = d.pagination || "";
            }
            if (tabCount) {
              tabCount.textContent = d.count || d.kpi_total || "0";
            }
            if (statusPanel) {
              statusPanel.setAttribute("data-scm-loaded", "1");
            }
          })
          .catch(function (err) {
            console.error("SCM status tickets error:", err);
            showToast("error", err.message || "No se pudo cargar la vista.");
          })
          .finally(function () {
            if (tabSpinner) {
              tabSpinner.classList.remove("active");
            }
            setListLoading(tabCards, false);
            tabForm.classList.remove("scm-loading");
          });
      }

      tabForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (pageInput) {
          pageInput.value = "1";
        }
        fetchTab(new FormData(tabForm));
      });

      if (tabClear) {
        tabClear.addEventListener("click", function () {
          tabForm.querySelectorAll("select").forEach(function (s) {
            s.selectedIndex = 0;
          });
          tabForm
            .querySelectorAll("input[type='text'], input[type='date']")
            .forEach(function (i) {
              if (!i.readOnly) {
                i.value = "";
              }
            });
          if (pageInput) {
            pageInput.value = "1";
          }
          if (tabCotizacionSelect) {
            applyCotizacionDependentFilters(tabForm, tabCotizacionSelect);
          }
          fetchTab(new FormData(tabForm));
        });
      }

      if (tabCotizacionSelect) {
        tabCotizacionSelect.addEventListener("change", function () {
          if (pageInput) {
            pageInput.value = "1";
          }
          applyCotizacionDependentFilters(tabForm, tabCotizacionSelect);
          fetchTab(new FormData(tabForm));
        });
        applyCotizacionDependentFilters(tabForm, tabCotizacionSelect);
      }
      tabForm
        .querySelectorAll(
          ".scm-cotizacion-dependent select, .scm-cotizacion-dependent input",
        )
        .forEach(function (field) {
          field.addEventListener("change", function () {
            if (pageInput) {
              pageInput.value = "1";
            }
            fetchTab(new FormData(tabForm));
          });
        });

      if (tabPagination) {
        tabPagination.addEventListener("click", function (e) {
          var pageBtn =
            e.target && e.target.closest
              ? e.target.closest(".scm-page-btn")
              : null;
          if (!pageBtn || pageBtn.disabled) {
            return;
          }
          e.preventDefault();
          e.stopPropagation();
          if (pageInput) {
            pageInput.value = String(pageBtn.getAttribute("data-page") || "1");
          }
          fetchTab(new FormData(tabForm));
        });
      }

      return { fetchTab: fetchTab, form: tabForm };
    }

    root.querySelectorAll(".scm-status-topic-panel").forEach(function (panel) {
      var statusFetcher = makeStatusFetcher(panel);
      if (statusFetcher) {
        tabFetchers[panel.getAttribute("data-status-key") || ""] =
          statusFetcher;
      }
    });

    root
      .querySelectorAll(".scm-status-bucket[data-status-bucket]")
      .forEach(function (bucketWrap) {
      var tabs = bucketWrap.querySelectorAll(".scm-status-topic-tab");
      var panels = bucketWrap.querySelectorAll(".scm-status-topic-panel");
      tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
          var target = tab.getAttribute("data-status-target") || "";
          tabs.forEach(function (item) {
            item.classList.toggle(
              "active",
              item.getAttribute("data-status-target") === target,
            );
          });
          panels.forEach(function (panel) {
            panel.classList.toggle(
              "active",
              panel.getAttribute("data-status-key") === target,
            );
          });
          loadStatusPanelIfNeeded(
            bucketWrap.querySelector(
              '.scm-status-topic-panel[data-status-key="' + target + '"]',
            ),
          );
        });
      });
    });

    function initContractsPanel() {
      var wrap = root.querySelector("[data-scm-contracts]");
      if (
        !wrap ||
        (!actionContratosArrendamiento && !actionContratosArrendamientoFallback)
      ) {
        return;
      }

      function activePanel() {
        return wrap.querySelector(".scm-contract-panel.active");
      }

      function fetchContracts(panel) {
        if (!panel) {
          return Promise.resolve();
        }
        var bucket = panel.getAttribute("data-contract-panel") || "";
        var form = panel.querySelector("form.sca_form");
        var table = panel.querySelector("[data-contract-table]");
        var pagination = panel.querySelector("[data-contract-pagination]");
        var countEl = panel.querySelector("[data-contract-count]");
        var spinner = panel.querySelector(".scm-spinner");
        if (!bucket || !form || !table) {
          return Promise.resolve();
        }
        function buildRequest(actionName, asFallback) {
          var fd = new FormData(form);
          fd.append("action", actionName);
          fd.append("nonce", nonce);
          fd.append("bucket", bucket);
          if (asFallback) {
            fd.append("pending_scope", "contratos_arrendamiento");
          }
          return fd;
        }

        function requestContracts(actionName, asFallback) {
          return fetch(ajaxUrl, {
            method: "POST",
            body: buildRequest(actionName, asFallback),
            credentials: "same-origin",
          }).then(function (r) {
            return r.json();
          });
        }

        if (spinner) spinner.classList.add("active");
        form.classList.add("scm-loading");
        var primaryAction =
          actionContratosArrendamiento || actionContratosArrendamientoFallback;
        var primaryIsFallback = !actionContratosArrendamiento;
        return requestContracts(primaryAction, primaryIsFallback)
          .then(function (json) {
            if (!json || !json.success) {
              var message =
                (json && json.data && json.data.message) ||
                "No se pudieron cargar los contratos.";
              if (
                actionContratosArrendamientoFallback &&
                actionContratosArrendamientoFallback !== primaryAction &&
                /desconocid/i.test(message)
              ) {
                return requestContracts(
                  actionContratosArrendamientoFallback,
                  true,
                );
              }
              throw new Error(
                message,
              );
            }
            return json;
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudieron cargar los contratos.",
              );
            }
            var data = json.data || {};
            table.innerHTML = data.table_html || "";
            if (pagination) {
              pagination.innerHTML = data.pagination_html || "";
            }
            if (countEl) {
              countEl.textContent = data.count || "0";
            }
            var headerCount = root.querySelector("#sca-kpi-count");
            if (headerCount) {
              headerCount.textContent = data.count || "0";
            }
            panel.setAttribute("data-scm-loaded", "1");
          })
          .catch(function (err) {
            showToast(
              "error",
              err && err.message
                ? err.message
                : "No se pudieron cargar los contratos.",
            );
          })
          .finally(function () {
            if (spinner) spinner.classList.remove("active");
            form.classList.remove("scm-loading");
          });
      }

      function loadActiveContracts(force) {
        var panel = activePanel();
        if (!panel) {
          return Promise.resolve();
        }
        if (!force && panel.getAttribute("data-scm-loaded") === "1") {
          return Promise.resolve();
        }
        return fetchContracts(panel);
      }

      wrap.querySelectorAll(".scm-contract-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
          var bucket = tab.getAttribute("data-contract-bucket") || "";
          wrap.querySelectorAll(".scm-contract-tab").forEach(function (item) {
            item.classList.toggle(
              "active",
              item.getAttribute("data-contract-bucket") === bucket,
            );
          });
          wrap.querySelectorAll(".scm-contract-panel").forEach(function (panel) {
            panel.classList.toggle(
              "active",
              panel.getAttribute("data-contract-panel") === bucket,
            );
          });
          loadActiveContracts(false);
        });
      });

      wrap.querySelectorAll("form.sca_form").forEach(function (form) {
        form.addEventListener("submit", function (e) {
          e.preventDefault();
          var page = form.querySelector('input[name="sca_page"]');
          if (page) page.value = "1";
          fetchContracts(form.closest(".scm-contract-panel"));
        });
      });

      wrap.addEventListener("click", function (e) {
        var clear = e.target.closest("[data-contract-clear]");
        if (clear) {
          e.preventDefault();
          var panel = clear.closest(".scm-contract-panel");
          var form = panel ? panel.querySelector("form.sca_form") : null;
          if (form) {
            form
              .querySelectorAll("input[type='text'], input[type='date']")
              .forEach(function (input) {
                input.value = "";
              });
            form.querySelectorAll("select").forEach(function (select) {
              select.selectedIndex = 0;
            });
            var page = form.querySelector('input[name="sca_page"]');
            if (page) page.value = "1";
          }
          fetchContracts(panel);
          return;
        }

        var pageBtn = e.target.closest(".scm-page-btn-contracts");
        if (pageBtn && !pageBtn.disabled) {
          e.preventDefault();
          var pagePanel = pageBtn.closest(".scm-contract-panel");
          var pageForm = pagePanel ? pagePanel.querySelector("form.sca_form") : null;
          var pageInput = pageForm
            ? pageForm.querySelector('input[name="sca_page"]')
            : null;
          if (pageInput) {
            pageInput.value = String(pageBtn.getAttribute("data-page") || "1");
          }
          fetchContracts(pagePanel);
        }
      });

      var contractsMainTab = root.querySelector(
        '.scm-tab[data-tab="scm-panel-contratos-arrendamiento"]',
      );
      if (contractsMainTab) {
        contractsMainTab.addEventListener("click", function () {
          window.setTimeout(function () {
            loadActiveContracts(false);
          }, 0);
        });
      }

      root.addEventListener("scm:contracts-refresh", function () {
        loadActiveContracts(true);
      });

      if (
        root.querySelector("#scm-panel-contratos-arrendamiento.active")
      ) {
        loadActiveContracts(false);
      }
    }

    initContractsPanel();

    function submitCotizacionAction(formData, action, errorMessage) {
      if (!ajaxUrl || !action) {
        showToast("error", "Accion no disponible.");
        return Promise.resolve();
      }
      formData.append("action", action);
      formData.append("nonce", nonce);
      return fetch(ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) || errorMessage,
            );
          }
          var message =
            (json.data && json.data.message) || "Accion realizada.";
          showToast("success", message);
          return refreshActiveTab();
        })
        .catch(function (err) {
          showToast("error", err.message || errorMessage);
        });
    }

    root.addEventListener("click", function (e) {
      var ordersBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-view-cotizacion-orders]")
          : null;
      if (ordersBtn) {
        e.preventDefault();
        var card = ordersBtn.closest(".scm-cotizacion-card");
        var source = card ? card.querySelector(".scm-cotizacion-orders-source") : null;
        if (window.Swal) {
          window.Swal.fire({
            title: "Ordenes de la cotizacion",
            html: source ? source.innerHTML : "Sin ordenes registradas.",
            width: 780,
            confirmButtonText: "Cerrar",
          });
        } else {
          showToast("info", source ? source.textContent : "Sin ordenes registradas.");
        }
        return;
      }

      var responseBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-cotizacion-response-standalone]")
          : null;
      if (responseBtn) {
        e.preventDefault();
        var ticketPk = responseBtn.getAttribute("data-ticket-pk") || "";
        if (!ticketPk) {
          showToast("error", "No se encontro el ticket de la cotizacion.");
          return;
        }
        if (!window.Swal) {
          showToast("error", "No se pudo abrir el popup de respuesta.");
          return;
        }
        window.Swal.fire({
          title: "Responder cotizacion",
          html:
            '<label class="scm-seg-field"><span>Respuesta</span><select id="swal-cot-estado" class="select select-bordered select-sm scm-select"><option value="">Elige una respuesta</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>' +
            '<label class="scm-seg-field" id="swal-cot-motivo-wrap" style="display:none;"><span>Motivo</span><select id="swal-cot-motivo" class="select select-bordered select-sm scm-select"><option value="">Elige un motivo</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option></select></label>' +
            '<label class="scm-seg-field" id="swal-cot-fin-wrap" style="display:none;"><span>Financiacion</span><select id="swal-cot-fin" class="select select-bordered select-sm scm-select"><option value="">No aplica / sin respuesta</option><option value="Si">Si</option><option value="No">No</option></select></label>' +
            '<label class="scm-seg-field"><span>Observaciones</span><textarea id="swal-cot-observacion" class="textarea textarea-bordered" rows="5">Ninguna</textarea></label>',
          width: 620,
          showCancelButton: true,
          confirmButtonText: "Guardar y enviar",
          cancelButtonText: "Cancelar",
          didOpen: function () {
            var estado = document.getElementById("swal-cot-estado");
            var motivoWrap = document.getElementById("swal-cot-motivo-wrap");
            var finWrap = document.getElementById("swal-cot-fin-wrap");
            if (estado) {
              estado.addEventListener("change", function () {
                var isNo = estado.value === "Desaprobada";
                var isYes = estado.value === "Aprobada";
                if (motivoWrap) motivoWrap.style.display = isNo ? "" : "none";
                if (finWrap) finWrap.style.display = isYes ? "" : "none";
              });
            }
          },
          preConfirm: function () {
            var estado = document.getElementById("swal-cot-estado");
            var motivo = document.getElementById("swal-cot-motivo");
            if (!estado || !estado.value) {
              window.Swal.showValidationMessage("Elige una respuesta.");
              return false;
            }
            if (estado.value === "Desaprobada" && (!motivo || !motivo.value)) {
              window.Swal.showValidationMessage("Elige el motivo.");
              return false;
            }
            return true;
          },
        }).then(function (res) {
          if (!res.isConfirmed) return;
          var fd = new FormData();
          fd.append("ticket_pk", ticketPk);
          fd.append("estado", document.getElementById("swal-cot-estado").value || "");
          fd.append("motivo", document.getElementById("swal-cot-motivo").value || "");
          fd.append("financiacion", document.getElementById("swal-cot-fin").value || "");
          fd.append(
            "observacion",
            document.getElementById("swal-cot-observacion").value || "Ninguna",
          );
          fd.append("notify_recipients_present", "1");
          fd.append("notify_recipients[]", "none");
          submitCotizacionAction(
            fd,
            actionCotizacionResponse,
            "Error enviando respuesta de cotizacion.",
          );
        });
        return;
      }

      var deleteBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-delete-cotizacion]")
          : null;
      if (deleteBtn) {
        e.preventDefault();
        var cotizacionId = deleteBtn.getAttribute("data-cotizacion-id") || "";
        if (!cotizacionId || !window.Swal) {
          showToast("error", "No se pudo abrir el formulario.");
          return;
        }
        window.Swal.fire({
          title: "Eliminar cotizacion",
          html:
            '<label class="scm-seg-field"><span>Motivo</span><select id="swal-del-motivo" class="select select-bordered select-sm scm-select"><option value="">Elige una opcion</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option><option value="Duplicada">Duplicada</option><option value="Error de registro">Error de registro</option></select></label>' +
            '<label class="scm-seg-field"><span>Observaciones a la cotizacion</span><textarea id="swal-del-observacion" class="textarea textarea-bordered" rows="5" placeholder="Por si tiene una observacion con respecto a la cotizacion presentada."></textarea></label>',
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "Eliminar cotizacion",
          cancelButtonText: "Cancelar",
          preConfirm: function () {
            var motivo = document.getElementById("swal-del-motivo");
            if (!motivo || !motivo.value) {
              window.Swal.showValidationMessage("Selecciona el motivo.");
              return false;
            }
            return true;
          },
        }).then(function (res) {
          if (!res.isConfirmed) return;
          var fd = new FormData();
          fd.append("id_cotizacion", cotizacionId);
          fd.append("motivo", document.getElementById("swal-del-motivo").value || "");
          fd.append(
            "observacion",
            document.getElementById("swal-del-observacion").value || "",
          );
          submitCotizacionAction(
            fd,
            actionDeleteCotizacion,
            "Error eliminando cotizacion.",
          );
        });
      }
    });

    var genericTabKeys = [
      { key: "entrega", action: actions.entrega || "" },
      { key: "preventiva", action: actions.preventiva || "" },
      { key: "recibo", action: actions.recibo || "" },
      { key: "contable", action: actions.contable || "" },
      { key: "certificaciones", action: actions.certificaciones || "" },
      { key: "contractual", action: actions.contractual || "" },
      { key: "mis_tickets", action: actionMyTickets || "" },
      {
        key: "cotizaciones_mantenimiento",
        action: actionCotizacionesMantenimiento || "",
      },
    ];

    genericTabKeys.forEach(function (t) {
      if (t.action) {
        var f = makeTabFetcher(t.key, t.action);
        if (f) {
          tabFetchers[t.key] = f;
        }
      }
    });

    // Seguimiento form
    function loadPanelOnce(panel, fetcherKey) {
      if (!panel || !fetcherKey || !tabFetchers[fetcherKey]) {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loaded") === "1") {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loading") === "1") {
        return Promise.resolve();
      }
      var fetcher = tabFetchers[fetcherKey];
      if (!fetcher || !fetcher.form) {
        return Promise.resolve();
      }
      panel.setAttribute("data-scm-loading", "1");
      return fetcher
        .fetchTab(new FormData(fetcher.form))
        .finally(function () {
          panel.setAttribute("data-scm-loading", "0");
        });
    }

    function loadOpenTopicPanelIfNeeded(panel) {
      if (!panel) {
        return Promise.resolve();
      }
      var key = panel.getAttribute("data-open-topic") || "";
      if (!key || key === "mant") {
        return Promise.resolve();
      }
      return loadPanelOnce(panel, key);
    }

    function loadStatusPanelIfNeeded(panel) {
      if (!panel) {
        return Promise.resolve();
      }
      return loadPanelOnce(panel, panel.getAttribute("data-status-key") || "");
    }

    function loadActiveLazyPanel() {
      var activePanel = root.querySelector(".scm-tab-panel.active");
      if (!activePanel) {
        return Promise.resolve();
      }
      if (activePanel.id === "scm-panel-abiertos") {
        return loadOpenTopicPanelIfNeeded(
          activePanel.querySelector(".scm-open-topic-panel.active"),
        );
      }
      if (
        activePanel.id === "scm-panel-postergados" ||
        activePanel.id === "scm-panel-cerrados"
      ) {
        return loadStatusPanelIfNeeded(
          activePanel.querySelector(".scm-status-topic-panel.active"),
        );
      }
      if (activePanel.id === "scm-panel-mis-tickets") {
        return loadPanelOnce(activePanel, "mis_tickets");
      }
      if (activePanel.id === "scm-panel-cotizaciones-mantenimiento") {
        return loadPanelOnce(activePanel, "cotizaciones_mantenimiento");
      }
      return Promise.resolve();
    }

    root.querySelectorAll(".scm-open-topic-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        window.setTimeout(loadActiveLazyPanel, 0);
      });
    });

    root.querySelectorAll(".scm-tab[data-tab]").forEach(function (tab) {
      tab.addEventListener("click", function () {
        window.setTimeout(loadActiveLazyPanel, 0);
      });
    });

    loadActiveLazyPanel();

    root.addEventListener("submit", function (e) {
      var segForm = e.target;
      if (
        !segForm ||
        !segForm.classList ||
        !segForm.classList.contains("scm-seg-form")
      ) {
        return;
      }
      e.preventDefault();

      var btn = segForm.querySelector("button[type='submit']");
      var msg = segForm.querySelector(".scm-seg-msg");
      if (btn) {
        btn.disabled = true;
      }
      if (msg) {
        msg.textContent = "Guardando...";
        msg.classList.remove("error");
      }

      var fd = new FormData(segForm);
      fd.append("action", actionSeg);
      fd.append("nonce", nonce);

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            var errText =
              json && json.data
                ? json.data.message || json.data
                : "No se pudo guardar.";
            throw new Error(errText);
          }
          if (msg) {
            msg.textContent =
              json.data && json.data.message
                ? json.data.message
                : "Seguimiento guardado.";
            msg.classList.remove("error");
          }
          segForm.reset();
          showToast(
            "success",
            json.data && json.data.message
              ? json.data.message
              : "Seguimiento guardado.",
          );
          refreshCaseAfterSave(fd.get("ticket_pk"), segForm);
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : "Error guardando seguimiento.";
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : "Error guardando seguimiento.",
          );
        })
        .finally(function () {
          if (btn) {
            btn.disabled = false;
          }
        });
    });

    root.addEventListener("submit", function (e) {
      var noteForm = e.target;
      if (
        !noteForm ||
        !noteForm.classList ||
        !noteForm.classList.contains("scm-note-form")
      ) {
        return;
      }
      e.preventDefault();

      var btn = noteForm.querySelector("button[type='submit']");
      var msg = noteForm.querySelector(".scm-seg-msg");
      if (btn) {
        btn.disabled = true;
      }
      if (msg) {
        msg.textContent = "Guardando...";
        msg.classList.remove("error");
      }

      var fd = new FormData(noteForm);
      fd.append("action", actionNote);
      fd.append("nonce", nonce);

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            var errText =
              json && json.data
                ? json.data.message || json.data
                : "No se pudo guardar.";
            throw new Error(errText);
          }
          if (msg) {
            msg.textContent =
              json.data && json.data.message
                ? json.data.message
                : "Nota guardada.";
            msg.classList.remove("error");
          }
          noteForm.reset();
          showToast(
            "success",
            json.data && json.data.message
              ? json.data.message
              : "Nota guardada.",
          );
          refreshCaseAfterSave(fd.get("ticket_pk"), noteForm);
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : "Error guardando nota.";
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : "Error guardando nota.",
          );
        })
        .finally(function () {
          if (btn) {
            btn.disabled = false;
          }
        });
    });

    function bindCasePostForm(e, formClass, actionName, fallbackMessage) {
      var postForm = e.target;
      if (
        !postForm ||
        !postForm.classList ||
        !postForm.classList.contains(formClass)
      ) {
        return false;
      }
      e.preventDefault();
      var btn = postForm.querySelector("button[type='submit']");
      var msg = postForm.querySelector(".scm-seg-msg");
      if (btn) btn.disabled = true;
      if (msg) {
        msg.textContent = "Enviando...";
        msg.classList.remove("error");
      }

      var fd = new FormData(postForm);
      fd.append("action", actionName);
      fd.append("nonce", nonce);
      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            var errText =
              json && json.data
                ? json.data.message || json.data
                : fallbackMessage;
            throw new Error(errText);
          }
          if (msg) {
            msg.textContent =
              json.data && json.data.message ? json.data.message : "Guardado.";
          }
          postForm.reset();
          showToast(
            "success",
            json.data && json.data.message ? json.data.message : "Guardado.",
          );
          refreshCaseAfterSave(fd.get("ticket_pk"), postForm);
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : fallbackMessage;
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : fallbackMessage,
          );
        })
        .finally(function () {
          if (btn) btn.disabled = false;
        });
      return true;
    }

    // Ninguno ↔ otros destinatarios: exclusión mutua en cualquier fieldset de notificaciones
    root.addEventListener("change", function (e) {
      var input = e.target;
      if (!input || input.name !== "notify_recipients[]") return;
      var fieldset = input.closest(".scm-notify-targets");
      if (!fieldset) return;
      if (input.value === "none" && input.checked) {
        fieldset
          .querySelectorAll('input[name="notify_recipients[]"]')
          .forEach(function (cb) {
            if (cb !== input) cb.checked = false;
          });
      } else if (input.value !== "none" && input.checked) {
        var noneInput = fieldset.querySelector(
          'input[name="notify_recipients[]"][value="none"]',
        );
        if (noneInput) noneInput.checked = false;
      }
    });

    root.addEventListener("submit", function (e) {
      var adminForm = e.target;
      if (
        !adminForm ||
        !adminForm.classList ||
        !adminForm.classList.contains("scm-admin-ticket-form")
      ) {
        return;
      }
      e.preventDefault();

      var btn = adminForm.querySelector("button[type='submit']");
      var msg = adminForm.querySelector(".scm-seg-msg");
      if (btn) btn.disabled = true;
      if (msg) {
        msg.textContent = "Creando ticket...";
        msg.classList.remove("error");
      }

      var fd = new FormData(adminForm);
      fd.append("action", actionCrearTicketAdministrativo);
      fd.append("nonce", nonce);

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo crear el ticket.",
            );
          }
          var data = json.data || {};
          var okMsg = data.message || "Ticket creado correctamente.";
          if (msg) {
            msg.textContent = okMsg;
            msg.classList.remove("error");
          }
          showToast("success", okMsg);
          closeAdminTicketModal();

          var active = root.querySelector(".scm-tab-panel.active");
          if (active && active.id === "scm-panel-preventivas-pendientes") {
            var sppForm = root.querySelector("#spp_form");
            if (sppForm) {
              sppForm.dispatchEvent(
                new Event("submit", { bubbles: true, cancelable: true }),
              );
            }
          }
          if (active && active.id === "scm-panel-contratos-arrendamiento") {
            root.dispatchEvent(new CustomEvent("scm:contracts-refresh"));
          }
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : "No se pudo crear el ticket.";
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : "No se pudo crear el ticket.",
          );
        })
        .finally(function () {
          if (btn) btn.disabled = false;
        });
    });

    root.addEventListener("submit", function (e) {
      if (
        bindCasePostForm(
          e,
          "scm-contact-update-form",
          actionContactsUpdate,
          "Error actualizando contactos.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-property-location-form",
          actionSavePropertyLocation,
          "Error guardando ubicacion del inmueble.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-close-ticket-form",
          actionCloseTicket,
          "Error cerrando ticket.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-postpone-ticket-form",
          actionPostponeTicket,
          "Error postergando ticket.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-ticket-response-form",
          actionTicketResponse,
          "Error enviando respuesta.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-cotizacion-response-form",
          actionCotizacionResponse,
          "Error enviando respuesta de cotizacion.",
        )
      ) {
        return;
      }
      bindCasePostForm(
        e,
        "scm-trasladar-form",
        actionTrasladarCaso,
        "Error trasladando caso.",
      );
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      document
        .querySelectorAll("#scm-app.scm-wrap[data-scm-runtime]")
        .forEach(initRoot);
    });
  } else {
    document
      .querySelectorAll("#scm-app.scm-wrap[data-scm-runtime]")
      .forEach(initRoot);
  }
})();
