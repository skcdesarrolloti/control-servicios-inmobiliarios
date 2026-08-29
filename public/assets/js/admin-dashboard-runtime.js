(function () {
  "use strict";
  var core = window.SCMAdminCore;
  if (!core) {
    console.error("SCMAdminCore no esta disponible.");
    return;
  }
  var parseRuntime = core.parseRuntime;
  var persistRuntime = core.persistRuntime || function () {};
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
  function notifyCalendarAppointment(root, appointments) {
    var helper = window.SCMAdminCore && window.SCMAdminCore.notifyCalendarAppointment;
    if (typeof helper === "function") {
      return helper(root, appointments);
    }
    console.warn("SCM calendar cita notify: helper no disponible.");
    return Promise.resolve({ queued: 0, skipped: Array.isArray(appointments) ? appointments.length : 0, error: "Helper no disponible" });
  }

  function showCalendarNotificationResult(result) {
    result = result || {};
    var queued = Number(result.queued || 0);
    var skipped = Number(result.skipped || 0);
    var error = String(result.error || "").trim();
    var errors = Array.isArray(result.errors) ? result.errors.filter(Boolean) : [];
    if (queued > 0) {
      showToast("success", queued + " notificación" + (queued === 1 ? "" : "es") + " WhatsApp encolada" + (queued === 1 ? "" : "s") + ".");
      return;
    }
    if (error || errors.length) {
      showToast("error", error || errors[0] || "No se pudieron encolar las notificaciones WhatsApp.");
      return;
    }
    if (skipped > 0) {
      showToast("warning", "No se encoló WhatsApp: revisa si el ticket/funcionario tiene celular.");
    }
  }
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
          e.preventDefault();
        }
      });
    }

    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") {
        return;
      }
      var openModal = root.querySelector("#scm-case-modal.open");
      var openAdminTicketDialog = root.querySelector("#scm-admin-ticket-modal.open");
      if (openAdminTicketDialog) {
        e.preventDefault();
        closeAdminTicketModal();
        return;
      }
      if (openModal) {
        e.preventDefault();
      }
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
    var actionCotizacionPdf = actions.cotizacion_pdf || "";
    var actionActivateTicket = actions.activate_ticket || "";
    var actionCloseTicket = actions.close_ticket || "";
    var actionContactsUpdate = actions.contacts_update || "";
    var actionSavePropertyLocation = actions.save_property_location || "";
    var actionTrasladarCaso = actions.trasladar_caso || "";
    var actionContratosArrendamiento = actions.contratos_arrendamiento || "";
    var actionContratoRecibido = actions.contrato_recibido || "";
    var actionContratoUltimaPreventiva =
      actions.contrato_ultima_preventiva || "";
    var actionPreventivasPendientes = actions.preventivas_pendientes || "";
    var actionServiciosPublicosPendientes =
      actions.servicios_publicos_pendientes || "";
    var actionRevisionServiciosPublicos =
      actions.revision_servicios_publicos || "";
    var actionContratosArrendamientoFallback =
      actions.preventivas_pendientes || "";
    var actionCrearTicketAdministrativo =
      actions.crear_ticket_administrativo || "";
    var actionDashboardPermissionsRead =
      actions.dashboard_permissions_read || "";
    var actionDashboardPermissionsSave =
      actions.dashboard_permissions_save || "";
    var actionAdminNotificationsRecipients =
      actions.admin_notifications_recipients || "";
    var actionAdminNotificationsPanel =
      actions.admin_notifications_panel || "";
    var actionAdminNotificationsSend = actions.admin_notifications_send || "";
    var actionAdminNotificationsImport =
      actions.admin_notifications_import || "";
    var actionAdminNotificationsCollection =
      actions.admin_notifications_collection || "";
    var actionAdminNotificationsCollectionOptions =
      actions.admin_notifications_collection_options || "";
    var actionAdminNotificationsCollectionQueue =
      actions.admin_notifications_collection_queue || "";
    var actionAdminNotificationsCollectionLog =
      actions.admin_notifications_collection_log || "";
    var actionInternalNotificationsSave =
      actions.internal_notifications_save || "";
    var actionPublicPqrSettingsRead = actions.public_pqr_settings_read || "";
    var actionInternalNotificationsRead =
      actions.internal_notifications_read || "";
    var actionMetricsExecution = actions.metrics_execution || "";
    var actionDashboardHome = actions.dashboard_home || "";
    var actionDashboardMetrics = actions.dashboard_metrics || "";
    var actionDashboardFilterOptions = actions.dashboard_filter_options || "";
    var panelLoader = root.querySelector("[data-scm-panel-loader]");
    var panelLoaderTitle = panelLoader
      ? panelLoader.querySelector("[data-scm-panel-loader-title]")
      : null;
    var panelLoaderDetail = panelLoader
      ? panelLoader.querySelector("[data-scm-panel-loader-detail]")
      : null;
    var panelLoaderRequestId = 0;

    function showPanelLoader(title, detail) {
      panelLoaderRequestId += 1;
      var requestId = panelLoaderRequestId;
      if (!panelLoader) {
        return requestId;
      }
      if (panelLoaderTitle) {
        panelLoaderTitle.textContent = title || "Cargando información";
      }
      if (panelLoaderDetail) {
        panelLoaderDetail.textContent =
          detail || "Espera un momento mientras consultamos los datos.";
      }
      panelLoader.hidden = false;
      panelLoader.setAttribute("aria-hidden", "false");
      document.body.classList.add("scm-panel-loading");
      window.requestAnimationFrame(function () {
        if (!panelLoader.hidden) panelLoader.classList.add("is-visible");
      });
      return requestId;
    }

    function hidePanelLoader(requestId) {
      if (!panelLoader || requestId !== panelLoaderRequestId) {
        return;
      }
      panelLoader.classList.remove("is-visible");
      window.setTimeout(function () {
        if (requestId !== panelLoaderRequestId) return;
        panelLoader.hidden = true;
        panelLoader.setAttribute("aria-hidden", "true");
        document.body.classList.remove("scm-panel-loading");
      }, 180);
    }

    function withPanelLoader(work, title, detail) {
      var requestId = showPanelLoader(title, detail);
      var promise;
      try {
        promise = typeof work === "function" ? work() : work;
      } catch (error) {
        hidePanelLoader(requestId);
        return Promise.reject(error);
      }
      return Promise.resolve(promise).finally(function () {
        hidePanelLoader(requestId);
      });
    }

    function fetchWithTimeout(input, init, timeoutMs) {
      if (typeof window.AbortController !== "function") {
        return fetch(input, init);
      }
      var controller = new AbortController();
      var options = Object.assign({}, init || {}, { signal: controller.signal });
      var timeout = window.setTimeout(function () {
        controller.abort();
      }, Math.max(5000, Number(timeoutMs || 30000)));
      return fetch(input, options)
        .catch(function (error) {
          if (error && error.name === "AbortError") {
            throw new Error("La consulta tardó demasiado. Intenta nuevamente.");
          }
          throw error;
        })
        .finally(function () {
          window.clearTimeout(timeout);
        });
    }
    var calendarAppUrl = String(
      (config && config.calendar_app_url) || "https://calendar-skc.netlify.app",
    ).replace(/\/+$/, "");
    var calendarApiUrl = String(
      (config && config.calendar_api_url) ||
        "https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=",
    );

    function buildCalendarUrl(path) {
      var cleanPath = String(path || "").trim();
      if (!cleanPath) {
        cleanPath = "/";
      }
      if (/^https?:\/\//i.test(cleanPath)) {
        return cleanPath;
      }
      if (cleanPath.charAt(0) !== "/") {
        cleanPath = "/" + cleanPath;
      }
      return calendarAppUrl + cleanPath;
    }

    function openCalendarPath(path, title) {
      openIframeModal(buildCalendarUrl(path), title || "Calendario", false);
    }

    function calendarApiRequest(action, payload, method) {
      var options = {
        method: method || (payload ? "POST" : "GET"),
        credentials: "same-origin",
      };
      if (payload) {
        options.headers = { "Content-Type": "application/json" };
        options.body = JSON.stringify(payload);
      }
      return fetch(calendarApiUrl + encodeURIComponent(action), options).then(function (r) {
        return r.json();
      });
    }

    function openCollectionQueueFromLog(managementId) {
      managementId = String(managementId || "").replace(/\D+/g, "");
      if (!managementId) {
        showToast("error", "Gestión de cobro inválida.");
        return;
      }
      if (!actionAdminNotificationsCollectionQueue) {
        showToast("error", "La consulta de notificaciones no está disponible.");
        return;
      }
      var fd = new FormData();
      fd.set("action", actionAdminNotificationsCollectionQueue);
      fd.set("nonce", nonce);
      fd.set("management_id", managementId);
      showToast("info", "Consultando notificaciones de la gestión...");
      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) {
          return response.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudieron consultar las notificaciones.",
            );
          }
          var data = json.data || {};
          var stats = data.stats || {};
          var total = Number(stats.total || 0);
          var html =
            '<div class="scm-collection-queue-modal">' +
            '<div class="scm-collection-queue-summary" aria-label="Resumen de notificaciones">' +
            '<article><span>Total</span><strong>' +
            escHtml(String(total)) +
            '</strong></article><article><span>Pendientes</span><strong>' +
            escHtml(String(Number(stats.pending || 0))) +
            '</strong></article><article><span>Enviadas</span><strong>' +
            escHtml(String(Number(stats.sent || 0))) +
            '</strong></article><article><span>Fallidas</span><strong>' +
            escHtml(String(Number(stats.failed || 0))) +
            "</strong></article></div>" +
            (data.html || "") +
            "</div>";
          if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
              title: "Notificaciones de la gestión #" + managementId,
              html: html,
              width: 980,
              confirmButtonText: "Cerrar",
              customClass: { confirmButton: "scm-btn-primary" },
            });
            return;
          }
          showToast(total > 0 ? "success" : "warning", total + " notificaciones relacionadas.");
        })
        .catch(function (err) {
          showToast("error", err.message || "No se pudieron consultar las notificaciones.");
        });
    }

    function collectionLogParamsFromForm(form) {
      var fd = collectionLogCurrentParams(form.closest("[data-scm-collection-log]"));
      new FormData(form).forEach(function (value, key) {
        fd.set(key, value);
      });
      fd.set("action", actionAdminNotificationsCollectionLog);
      fd.set("nonce", nonce);
      return fd;
    }

    function collectionLogCurrentParams(container) {
      var fd = new FormData();
      var scope = container || collectionLogContainerFromPanel(null);
      [
        "scmgc_fecha_desde",
        "scmgc_fecha_hasta",
        "scmgc_tipo",
        "scmgc_page",
        "scmgc_buscar",
        "scmgc_estado",
        "scmgc_etapa",
        "scmgc_movimiento",
        "scmgc_cartera_page",
      ].forEach(function (key) {
        var field = scope ? scope.querySelector("[name='" + key + "']") : null;
        fd.set(key, field ? String(field.value || "") : "");
      });
      return fd;
    }

    function collectionLogParamsFromUrl(url) {
      var parsed = new URL(url, window.location.href);
      var fd = collectionLogCurrentParams(null);
      [
        "scmgc_fecha_desde",
        "scmgc_fecha_hasta",
        "scmgc_tipo",
        "scmgc_page",
        "scmgc_buscar",
        "scmgc_estado",
        "scmgc_etapa",
        "scmgc_movimiento",
        "scmgc_cartera_page",
      ].forEach(function (key) {
        if (parsed.searchParams.has(key)) {
          fd.set(key, parsed.searchParams.get(key) || "");
        }
      });
      fd.set("action", actionAdminNotificationsCollectionLog);
      fd.set("nonce", nonce);
      return fd;
    }

    function replaceCollectionLog(container, html) {
      var tmp = document.createElement("div");
      tmp.innerHTML = String(html || "");
      var next = tmp.querySelector("[data-scm-collection-log]");
      if (next && container && container.parentNode) {
        container.parentNode.replaceChild(next, container);
        return next;
      }
      return container;
    }

    function collectionLogParamsFromContainer(container) {
      if (container) {
        var form = container.querySelector("[data-scm-collection-log-form]");
        if (form) {
          return collectionLogParamsFromForm(form);
        }
      }
      return collectionLogParamsFromUrl("?scm_tab=gestiones_cobro");
    }

    function collectionLogContainerFromPanel(panel) {
      if (panel) {
        var local = panel.querySelector("[data-scm-collection-log]");
        if (local) {
          return local;
        }
      }
      return root.querySelector("#scm-panel-gestiones-cobro [data-scm-collection-log]");
    }

    function markCollectionLogStale() {
      var container = collectionLogContainerFromPanel(null);
      if (container) {
        container.setAttribute("data-scm-collection-log-stale", "1");
      }
    }

    function refreshCollectionLogPanel(panel, force) {
      var container = collectionLogContainerFromPanel(panel);
      if (!container) {
        return Promise.resolve();
      }
      var stale = container.getAttribute("data-scm-collection-log-stale") === "1";
      var loaded = container.getAttribute("data-scm-collection-log-loaded") === "1";
      if (!force && loaded && !stale) {
        return Promise.resolve();
      }
      return loadCollectionLog(container, collectionLogParamsFromContainer(container));
    }

    function loadCollectionLog(container, fd) {
      if (!actionAdminNotificationsCollectionLog || !container) {
        return Promise.resolve();
      }
      container.classList.add("is-loading");
      container.setAttribute("aria-busy", "true");
      return fetchWithTimeout(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) {
          return response.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudieron cargar las gestiones de cobro.",
            );
          }
          var nextContainer = replaceCollectionLog(container, (json.data || {}).html || "");
          if (nextContainer) {
            nextContainer.setAttribute("data-scm-collection-log-loaded", "1");
            nextContainer.setAttribute("data-scm-collection-log-stale", "0");
          }
        })
        .catch(function (err) {
          container.innerHTML =
            '<div class="scm-admin-notif-empty is-error" role="alert">' +
            "<strong>No pudimos cargar las gestiones de cobro.</strong>" +
            "<span>" + escHtml(err.message || "Error desconocido") + "</span>" +
            '<button type="button" class="scm-btn-primary btn btn-primary" data-scm-collection-log-retry>Reintentar</button>' +
            "</div>";
          container.setAttribute("data-scm-collection-log-loaded", "0");
          showToast("error", err.message || "No se pudieron cargar las gestiones de cobro.");
        })
        .finally(function () {
          container.classList.remove("is-loading");
          container.removeAttribute("aria-busy");
        });
    }

    root.addEventListener("submit", function (event) {
      var form = event.target && event.target.closest
        ? event.target.closest("[data-scm-collection-log-form]")
        : null;
      if (!form || !root.contains(form)) {
        return;
      }
      event.preventDefault();
      var container = form.closest("[data-scm-collection-log]");
      loadCollectionLog(container, collectionLogParamsFromForm(form));
    });

    root.addEventListener("click", function (event) {
      var notificationsRetry = event.target && event.target.closest
        ? event.target.closest("[data-scm-admin-notifications-retry]")
        : null;
      if (notificationsRetry && root.contains(notificationsRetry)) {
        event.preventDefault();
        adminNotificationsPanelPromise = null;
        withPanelLoader(
          loadAdminNotificationsPanel,
          "Cargando Notificaciones",
          "Estamos preparando destinatarios y plantillas.",
        );
        return;
      }

      var collectionRetry = event.target && event.target.closest
        ? event.target.closest("[data-scm-collection-log-retry]")
        : null;
      if (collectionRetry && root.contains(collectionRetry)) {
        event.preventDefault();
        var retryContainer = collectionRetry.closest("[data-scm-collection-log]");
        withPanelLoader(
          function () {
            return loadCollectionLog(
              retryContainer,
              collectionLogParamsFromContainer(retryContainer),
            );
          },
          "Cargando Gestiones de cobro",
          "Estamos consultando el historial de cobranza.",
        );
        return;
      }

      var queueBtn = event.target && event.target.closest
        ? event.target.closest("[data-scm-collection-queue]")
        : null;
      if (queueBtn && root.contains(queueBtn)) {
        event.preventDefault();
        openCollectionQueueFromLog(queueBtn.getAttribute("data-scm-collection-queue") || "");
        return;
      }

      var clearBtn = event.target && event.target.closest
        ? event.target.closest("[data-scm-collection-log-clear]")
        : null;
      if (clearBtn && root.contains(clearBtn)) {
        event.preventDefault();
        var clearContainer = clearBtn.closest("[data-scm-collection-log]");
        loadCollectionLog(clearContainer, collectionLogParamsFromUrl(clearBtn.getAttribute("href") || ""));
        return;
      }

      var pageLink = event.target && event.target.closest
        ? event.target.closest(".scm-collection-log-pagination a")
        : null;
      if (!pageLink || !root.contains(pageLink) || pageLink.classList.contains("disabled")) {
        return;
      }
      event.preventDefault();
      var container = pageLink.closest("[data-scm-collection-log]");
      loadCollectionLog(container, collectionLogParamsFromUrl(pageLink.getAttribute("href") || ""));
    });

    function initCalendarPanel() {
      var panel = root.querySelector("[data-scm-calendar-panel]");
      if (!panel || panel.getAttribute("data-scm-calendar-init") === "1") {
        return;
      }
      panel.setAttribute("data-scm-calendar-init", "1");
      var panelAppUrl = String(panel.getAttribute("data-calendar-app-url") || "").replace(/\/+$/, "");
      var panelApiUrl = String(panel.getAttribute("data-calendar-api-url") || "");
      if (panelAppUrl) calendarAppUrl = panelAppUrl;
      if (panelApiUrl) calendarApiUrl = panelApiUrl;

      var filterForm = panel.querySelector("[data-scm-calendar-filters]");
      var eventsWrap = panel.querySelector("[data-scm-calendar-events]");
      var monthGrid = panel.querySelector("[data-scm-calendar-grid]");
      var titleEl = panel.querySelector("[data-scm-calendar-title]");
      var dayTitleEl = panel.querySelector("[data-scm-calendar-day-title]");
      var daySubtitleEl = panel.querySelector("[data-scm-calendar-day-subtitle]");
      var spinner = panel.querySelector("[data-scm-calendar-spinner]");
      var totalEl = panel.querySelector("[data-scm-calendar-total]");
      var pendingEl = panel.querySelector("[data-scm-calendar-pending]");
      var doneEl = panel.querySelector("[data-scm-calendar-done]");
      var todayEl = panel.querySelector("[data-scm-calendar-today]");
      var rangeEl = panel.querySelector("[data-scm-calendar-range]");
      var allowedCargos = String(panel.getAttribute("data-calendar-allowed-cargos") || "").split(",").map(function (v) { return v.trim(); }).filter(Boolean);
      var allowedEmployees = parseCalendarEmployees(panel.getAttribute("data-calendar-employees-json") || "[]");
      var currentCalendarEmployeeId = String(panel.getAttribute("data-calendar-current-employee-id") || "").trim();
      var allowedEmployeeIds = {};
      var categories = [];
      var categoriesById = {};
      var currentMonth = startOfMonth(new Date());
      var selectedDay = toDateKey(new Date());
      var calendarEvents = [];
      var popupAgendaRequestId = 0;
      var ticketCacheByEmployee = {};
      var holidayCache = {};
      var calendarBootstrapPromise = null;

      panel.querySelectorAll("[data-scm-calendar-open-path]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          openCalendarPath(btn.getAttribute("data-scm-calendar-open-path") || "/", btn.getAttribute("data-iframe-title") || "Calendario");
        });
      });

      rebuildAllowedEmployeeMap();

      function calendarApi(action, payload, method) {
        var options = {
          method: method || (payload ? "POST" : "GET"),
          credentials: "same-origin",
        };
        if (payload) {
          options.headers = { "Content-Type": "application/json" };
          options.body = JSON.stringify(payload);
        }
        return fetch(calendarApiUrl + encodeURIComponent(action), options).then(function (r) {
          return r.json();
        });
      }

      function parseCalendarEmployees(raw) {
        try {
          var parsed = JSON.parse(raw || "[]");
          return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
          return [];
        }
      }

      function rebuildAllowedEmployeeMap() {
        allowedEmployeeIds = {};
        allowedEmployees.forEach(function (employee) {
          var id = getEmployeeId(employee);
          if (id) allowedEmployeeIds[id] = true;
        });
      }

      function getEmployeeId(row) {
        return String((row && (row.id_empleado || row.id || row._ID || row.funcionario_id)) || "").trim();
      }

      function getEventEmployeeId(row) {
        return String((row && (row.id_empleado || row.funcionario_id || row.empleado_id)) || "").trim();
      }

      function getCategoryId(row) {
        return String((row && (row.id_categoria || row.categoria_id)) || "").trim();
      }

      function fillEmployeeOptions(selects, rows, firstLabel) {
        selects.forEach(function (select) {
          var current = select.value || currentCalendarEmployeeId || "";
          if (current && !rows.some(function (row) { return getEmployeeId(row) === current; })) {
            current = rows.length ? getEmployeeId(rows[0]) : "";
          }
          select.innerHTML = '<option value="">' + firstLabel + "</option>";
          rows.forEach(function (row) {
            var id = getEmployeeId(row);
            var name = String(row.nombre || row.empleado || row.funcionario || id).trim();
            if (!id) return;
            var option = document.createElement("option");
            option.value = id;
            option.textContent = name ? name + " (" + id + ")" : id;
            select.appendChild(option);
          });
          if (current) select.value = current;
        });
      }

      function fillCategoryOptions(select, rows, firstLabel) {
        if (!select) return;
        var current = select.value || "";
        select.innerHTML = '<option value="">' + firstLabel + "</option>";
        rows.forEach(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          var name = String(row.nombre || row.categoria || id).trim();
          if (!id) return;
          var option = document.createElement("option");
          option.value = id;
          option.textContent = name;
          select.appendChild(option);
        });
        if (current) select.value = current;
      }

      function calendarCategoryLabel(row) {
        return String((row && (row.nombre || row.categoria || row.name || row.id || row._ID || row.id_categoria)) || "").trim();
      }

      function isAdministrativeCalendarCategory(row) {
        var name = normalizeText(calendarCategoryLabel(row));
        if (!name) return false;
        var commercialTerms = [
          "comercial",
          "captacion",
          "captado",
          "recaptado",
          "prospect",
          "mostrando",
          "contactado",
          "entregado",
          "por publicar",
          "publicar",
          "en cierre",
          "en busqueda",
          "venta",
          "banco",
          "aseguradora",
        ];
        if (commercialTerms.some(function (term) { return name.indexOf(term) !== -1; })) return false;
        var adminTerms = [
          "administr",
          "preventiva",
          "correctiva",
          "revision",
          "mantenimiento",
          "servicio",
          "cita",
          "inspeccion",
          "contrato",
          "inmueble",
          "pendiente",
          "visita",
          "recibo",
        ];
        return adminTerms.some(function (term) { return name.indexOf(term) !== -1; });
      }

      function calendarAdminCategories() {
        var rows = categories.filter(isAdministrativeCalendarCategory);
        return rows.length ? rows : categories;
      }

      function formatDateTime(value) {
        var raw = String(value || "").replace("T", " ");
        if (!raw) return "-";
        return raw.replace(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2}).*$/, "$3/$2/$1 $4");
      }

      function datePartFromDateTime(value) {
        return String(value || "").replace("T", " ").slice(0, 10);
      }

      function timePartFromDateTime(value) {
        var match = String(value || "").replace("T", " ").match(/\s(\d{2}:\d{2})/);
        return match ? match[1] : "";
      }

      function toDateKey(date) {
        return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
      }

      function eventDateKey(row) {
        return String(row && row.fecha_inicio ? row.fecha_inicio : "").slice(0, 10);
      }

      function startOfMonth(date) {
        return new Date(date.getFullYear(), date.getMonth(), 1);
      }

      function endOfMonth(date) {
        return new Date(date.getFullYear(), date.getMonth() + 1, 0);
      }

      function monthLabel(date) {
        return date.toLocaleDateString("es-CO", { month: "long", year: "numeric" });
      }

      function monthRange(date) {
        return { from: toDateKey(startOfMonth(date)), to: toDateKey(endOfMonth(date)) };
      }

      function capitalizeFirst(value) {
        value = String(value || "");
        return value ? value.charAt(0).toUpperCase() + value.slice(1) : "";
      }

      function addDays(date, days) {
        var d = new Date(date);
        d.setDate(d.getDate() + days);
        return d;
      }

      function nextMonday(date) {
        var d = new Date(date);
        var diff = (8 - d.getDay()) % 7;
        d.setDate(d.getDate() + diff);
        return d;
      }

      function easterSunday(year) {
        var a = year % 19;
        var b = Math.floor(year / 100);
        var c = year % 100;
        var d = Math.floor(b / 4);
        var e = b % 4;
        var f = Math.floor((b + 8) / 25);
        var g = Math.floor((b - f + 1) / 3);
        var h = (19 * a + b - d - g + 15) % 30;
        var i = Math.floor(c / 4);
        var k = c % 4;
        var l = (32 + 2 * e + 2 * i - h - k) % 7;
        var m = Math.floor((a + 11 * h + 22 * l) / 451);
        var month = Math.floor((h + l - 7 * m + 114) / 31);
        var day = ((h + l - 7 * m + 114) % 31) + 1;
        return new Date(year, month - 1, day);
      }

      function addHoliday(map, date, name) {
        map[toDateKey(date)] = name;
      }

      function colombiaHolidays(year) {
        if (holidayCache[year]) return holidayCache[year];
        var map = {};
        addHoliday(map, new Date(year, 0, 1), "Año Nuevo");
        addHoliday(map, nextMonday(new Date(year, 0, 6)), "Reyes Magos");
        addHoliday(map, nextMonday(new Date(year, 2, 19)), "San José");
        addHoliday(map, new Date(year, 4, 1), "Día del Trabajo");
        addHoliday(map, nextMonday(new Date(year, 5, 29)), "San Pedro y San Pablo");
        addHoliday(map, new Date(year, 6, 20), "Independencia de Colombia");
        addHoliday(map, new Date(year, 7, 7), "Batalla de Boyacá");
        addHoliday(map, nextMonday(new Date(year, 7, 15)), "Asunción de la Virgen");
        addHoliday(map, nextMonday(new Date(year, 9, 12)), "Día de la Raza");
        addHoliday(map, nextMonday(new Date(year, 10, 1)), "Todos los Santos");
        addHoliday(map, nextMonday(new Date(year, 10, 11)), "Independencia de Cartagena");
        addHoliday(map, new Date(year, 11, 8), "Inmaculada Concepción");
        addHoliday(map, new Date(year, 11, 25), "Navidad");
        var easter = easterSunday(year);
        addHoliday(map, addDays(easter, -3), "Jueves Santo");
        addHoliday(map, addDays(easter, -2), "Viernes Santo");
        addHoliday(map, nextMonday(addDays(easter, 39)), "Ascensión del Señor");
        addHoliday(map, nextMonday(addDays(easter, 60)), "Corpus Christi");
        addHoliday(map, nextMonday(addDays(easter, 68)), "Sagrado Corazón");
        holidayCache[year] = map;
        return map;
      }

      function holidayForDateKey(key) {
        var year = parseInt(String(key || "").slice(0, 4), 10);
        if (!year) return "";
        return colombiaHolidays(year)[key] || "";
      }

      function calendarRichTextHtml(value) {
        var html = escHtml(value || "Sin descripcion");
        html = html
          .replace(/\r?\n/g, "<br>")
          .replace(/&lt;\/?br\s*\/?&gt;/gi, "<br>")
          .replace(/&lt;b&gt;/gi, "<strong>")
          .replace(/&lt;\/b&gt;/gi, "</strong>")
          .replace(/&lt;strong&gt;/gi, "<strong>")
          .replace(/&lt;\/strong&gt;/gi, "</strong>");
        return html;
      }

      function normalizeText(value) {
        return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
      }

      function formatDateForMessage(value) {
        var parts = String(value || "").split("-");
        if (parts.length !== 3) return value || "";
        return parts[2] + "/" + parts[1] + "/" + parts[0];
      }

      function formatTimeForMessage(value) {
        var pieces = String(value || "").split(":");
        var hour = parseInt(pieces[0] || "0", 10);
        var minute = pieces[1] || "00";
        if (Number.isNaN(hour)) return value || "";
        var suffix = hour >= 12 ? "p. m." : "a. m.";
        var displayHour = hour % 12 || 12;
        return String(displayHour).padStart(2, "0") + ":" + minute + " " + suffix;
      }

      function buildPreventiveDescription(categoryName, dateValue, startValue, endValue) {
        if (!categoryName || !dateValue || !startValue || !endValue) return "";
        return "Por medio de la presente, le confirmo que he dispuesto de un espacio con el propósito de reunirnos, ya sea de forma presencial o por medios virtuales, a fin de atender cualquier inquietud o asunto pendiente.\n\nEn cumplimiento de " +
          categoryName +
          ", se ha programado una visita y/o reunión, la cual ha quedado agendada para el día " +
          formatDateForMessage(dateValue) +
          ", de " +
          formatTimeForMessage(startValue) +
          " a " +
          formatTimeForMessage(endValue) +
          ". En caso de no ser posible contar con su atención en la fecha indicada, le agradecemos nos lo comunique por este mismo medio con al menos 3 horas de antelación.";
      }

      function buildRescheduleDescription(title, dateValue, startValue, endValue, observation) {
        if (!title || !dateValue || !startValue || !endValue) return "";
        return "Por medio de la presente, se informa que la cita " +
          title +
          " fue reprogramada para el día " +
          formatDateForMessage(dateValue) +
          ", de " +
          formatTimeForMessage(startValue) +
          " a " +
          formatTimeForMessage(endValue) +
          "." +
          (observation ? "\n\nMotivo: " + observation : "");
      }

      function isCalendarAppointmentCategoryName(categoryName) {
        var name = normalizeText(categoryName);
        return name.indexOf("preventiva") !== -1 || name.indexOf("correctiva") !== -1;
      }

      function extractRows(payload) {
        if (Array.isArray(payload)) return payload;
        if (!payload) return [];
        if (Array.isArray(payload.data)) return payload.data;
        if (payload.data && Array.isArray(payload.data.data)) return payload.data.data;
        if (payload.data && Array.isArray(payload.data.eventos)) return payload.data.eventos;
        if (Array.isArray(payload.eventos)) return payload.eventos;
        if (Array.isArray(payload.rows)) return payload.rows;
        return [];
      }

      function filterRowsByAllowedEmployees(rows) {
        if (!Object.keys(allowedEmployeeIds).length) return rows;
        return rows.filter(function (row) {
          return !!allowedEmployeeIds[getEventEmployeeId(row)];
        });
      }

      function updateFilterCategories(rows) {
        var select = filterForm ? filterForm.querySelector("[data-scm-calendar-filter-categories]") : null;
        if (!select) return;
        var current = select.value || "";
        var used = {};
        rows.forEach(function (row) {
          var id = getCategoryId(row);
          if (id) used[id] = true;
        });
        var hasUsed = Object.keys(used).length > 0;
        var available = calendarAdminCategories().filter(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          return !hasUsed || !!used[id] || id === current;
        });
        fillCategoryOptions(select, available, "Todas");
        if (current) select.value = current;
      }

      function renderKpis(rows) {
        var todayKey = toDateKey(new Date());
        var pending = rows.filter(function (row) { return String(row.estado || "").toLowerCase() !== "si"; }).length;
        var done = rows.filter(function (row) { return String(row.estado || "").toLowerCase() === "si"; }).length;
        var todayCount = rows.filter(function (row) { return eventDateKey(row) === todayKey; }).length;
        if (totalEl) totalEl.textContent = String(rows.length || 0);
        if (pendingEl) pendingEl.textContent = String(pending || 0);
        if (doneEl) doneEl.textContent = String(done || 0);
        if (todayEl) todayEl.textContent = String(todayCount || 0);
        if (rangeEl) {
          var range = monthRange(currentMonth);
          rangeEl.textContent = range.from + " / " + range.to;
        }
      }

      function eventCardHtml(row) {
        var id = String(row.id || "").trim();
        var ticket = String(row.id_ticket || "").trim();
        var isDone = String(row.estado || "").toLowerCase() === "si";
        var estado = isDone ? "Realizado" : "Pendiente";
        var color = String(row.color || "#f59e0b").trim() || "#f59e0b";
        var eventoUrl = id ? buildCalendarUrl("/evento/" + encodeURIComponent(id)) : "";
        var ticketUrl = ticket ? "https://sucasainmobiliaria.com.co/ticket/?id_ticket=" + encodeURIComponent(ticket) : "";
        return '<article class="scm-calendar-event-card">' +
          '<div class="scm-calendar-event-color" style="background:' + escHtml(color) + '"></div>' +
          '<div class="scm-calendar-event-main">' +
          '<div class="scm-calendar-event-title-row"><h5>' + escHtml(row.titulo || "Evento") + '</h5><span class="scm-calendar-event-state">' + escHtml(estado) + "</span></div>" +
          '<div class="scm-calendar-event-description">' + calendarRichTextHtml(row.descripcion || "Sin descripcion") + "</div>" +
          '<div class="scm-calendar-event-meta">' +
          '<span>' + escHtml(formatDateTime(row.fecha_inicio)) + " - " + escHtml(formatDateTime(row.fecha_fin)) + "</span>" +
          '<span>' + escHtml(row.funcionario || row.nombre || "Funcionario") + "</span>" +
          '<span>' + escHtml(row.categoria || (categoriesById[getCategoryId(row)] && categoriesById[getCategoryId(row)].nombre) || "Sin categoria") + "</span>" +
          (ticket ? '<span>Ticket #' + escHtml(ticket) + "</span>" : "") +
          "</div>" +
          '<div class="scm-calendar-event-actions">' +
          (eventoUrl ? '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' + escHtml(eventoUrl) + '" data-iframe-title="Evento #' + escHtml(id) + '">Ver evento</button>' : "") +
          (ticketUrl ? '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' + escHtml(ticketUrl) + '" data-iframe-title="Ticket #' + escHtml(ticket) + '">Ver ticket</button>' : "") +
          (id && !isDone ? '<button type="button" class="scm-case-work-btn" data-scm-calendar-complete-event data-event-id="' + escHtml(id) + '">Marcar realizado</button>' : "") +
          (id ? '<button type="button" class="scm-case-work-btn" data-scm-calendar-reschedule-event data-event-id="' + escHtml(id) + '">Trasladar evento</button>' : "") +
          "</div></div></article>";
      }

      function renderSelectedDay() {
        var dayRows = calendarEvents.filter(function (row) { return eventDateKey(row) === selectedDay; });
        var holiday = holidayForDateKey(selectedDay);
        if (dayTitleEl) dayTitleEl.textContent = selectedDay || "Selecciona un dia";
        if (daySubtitleEl) daySubtitleEl.textContent = (holiday ? "Festivo Colombia: " + holiday + ". " : "") + (dayRows.length ? dayRows.length + " evento(s) para este dia." : "Sin eventos para este dia.");
        if (!eventsWrap) return;
        eventsWrap.innerHTML = dayRows.length ? dayRows.map(eventCardHtml).join("") : '<div class="scm-empty scm-empty-cards">No hay eventos para este dia.</div>';
      }

      function renderCalendarGrid() {
        if (!monthGrid) return;
        if (titleEl) titleEl.textContent = monthLabel(currentMonth);
        var prevMonthBtn = panel.querySelector("[data-scm-calendar-prev]");
        var nextMonthBtn = panel.querySelector("[data-scm-calendar-next]");
        if (prevMonthBtn) prevMonthBtn.textContent = "‹ " + capitalizeFirst(monthLabel(new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1)).replace(/\s+\d{4}$/, ""));
        if (nextMonthBtn) nextMonthBtn.textContent = capitalizeFirst(monthLabel(new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1)).replace(/\s+\d{4}$/, "")) + " ›";
        var first = startOfMonth(currentMonth);
        var start = new Date(first);
        var weekday = first.getDay();
        start.setDate(first.getDate() + (weekday === 0 ? -6 : 1 - weekday));
        var todayKey = toDateKey(new Date());
        var html = "";
        for (var i = 0; i < 42; i += 1) {
          var cellDate = new Date(start);
          cellDate.setDate(start.getDate() + i);
          var key = toDateKey(cellDate);
          var holiday = holidayForDateKey(key);
          var dayEvents = calendarEvents.filter(function (row) { return eventDateKey(row) === key; });
          var classes = "scm-calendar-day";
          if (cellDate.getMonth() !== currentMonth.getMonth()) classes += " is-muted";
          if (key === todayKey) classes += " is-today";
          if (key === selectedDay) classes += " is-selected";
          if (holiday) classes += " is-holiday";
          html += '<button type="button" class="' + classes + '" data-scm-calendar-day="' + escHtml(key) + '">';
          html += '<span class="scm-calendar-day-number">' + String(cellDate.getDate()) + "</span>";
          if (holiday) html += '<span class="scm-calendar-day-holiday">Festivo · ' + escHtml(holiday) + "</span>";
          html += '<span class="scm-calendar-day-events-count">' + (dayEvents.length ? dayEvents.length + " evento(s)" : "") + "</span>";
          dayEvents.slice(0, 3).forEach(function (row) {
            html += '<span class="scm-calendar-day-pill" style="border-color:' + escHtml(row.color || "#f59e0b") + '">' + escHtml(row.titulo || "Evento") + "</span>";
          });
          if (dayEvents.length > 3) html += '<span class="scm-calendar-day-more">+' + (dayEvents.length - 3) + " mas</span>";
          html += "</button>";
        }
        monthGrid.innerHTML = html;
        monthGrid.querySelectorAll("[data-scm-calendar-day]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            selectedDay = btn.getAttribute("data-scm-calendar-day") || selectedDay;
            renderCalendarGrid();
            renderSelectedDay();
          });
        });
      }

      function renderEvents(payload) {
        calendarEvents = filterRowsByAllowedEmployees(extractRows(payload));
        renderKpis(calendarEvents);
        updateFilterCategories(calendarEvents);
        renderCalendarGrid();
        renderSelectedDay();
      }

      function loadEvents() {
        if (spinner) spinner.classList.add("active");
        var range = monthRange(currentMonth);
        var filters = { pagina: 1, limite: 500, fecha_inicio: range.from, fecha_fin: range.to };
        var selectedEmployeeId = "";
        if (filterForm) {
          var employeeField = filterForm.querySelector('[name="id_empleado"]');
          var categoryField = filterForm.querySelector('[name="id_categoria"]');
          var estadoField = filterForm.querySelector('[name="estado"]');
          selectedEmployeeId = employeeField ? String(employeeField.value || "").trim() : "";
          if (selectedEmployeeId) filters.id_empleado = selectedEmployeeId;
          if (categoryField && categoryField.value) filters.id_categoria = categoryField.value;
          if (estadoField && estadoField.value) filters.estado = estadoField.value;
        }
        if (!selectedEmployeeId) {
          calendarEvents = [];
          renderKpis(calendarEvents);
          if (monthGrid) monthGrid.innerHTML = '<div class="scm-calendar-loading">Selecciona un funcionario para ver su calendario.</div>';
          if (eventsWrap) eventsWrap.innerHTML = '<div class="scm-empty scm-empty-cards">Este apartado funciona por calendario de funcionario, no como calendario general.</div>';
          if (spinner) spinner.classList.remove("active");
          return Promise.resolve();
        }
        return calendarApi("filtrar_eventos_admin", filters)
          .then(function (json) {
            if (!json || !json.success) throw new Error((json && json.message) || "No se pudieron cargar eventos.");
            renderEvents(json.data || []);
          })
          .catch(function (err) {
            if (monthGrid) monthGrid.innerHTML = '<div class="scm-calendar-loading">No se pudo cargar el calendario.</div>';
            if (eventsWrap) eventsWrap.innerHTML = '<div class="scm-empty scm-empty-cards">No se pudieron cargar eventos.</div>';
            showToast("error", err.message || "No se pudieron cargar eventos.");
          })
          .finally(function () {
            if (spinner) spinner.classList.remove("active");
          });
      }

      function selectedEmployeeFromFilter() {
        var field = filterForm ? filterForm.querySelector('[name="id_empleado"]') : null;
        return field ? String(field.value || "").trim() : "";
      }

      function employeeDisplayName(employeeId) {
        employeeId = String(employeeId || "").trim();
        var employee = allowedEmployees.find(function (row) { return getEmployeeId(row) === employeeId; });
        if (!employee) return employeeId || "Funcionario";
        return String(employee.nombre || employee.empleado || employee.funcionario || employeeId).trim();
      }

      function employeeOptionHtml(row, selected) {
        var id = getEmployeeId(row);
        if (!id) return "";
        var name = String(row.nombre || row.empleado || row.funcionario || id).trim();
        var label = name ? name + " (" + id + ")" : id;
        return '<option value="' + escHtml(id) + '"' + (selected && id === selected ? " selected" : "") + '>' + escHtml(label) + "</option>";
      }

      function employeeMultiPickerHtml(preselectedEmployee) {
        var rows = allowedEmployees.map(function (row) {
          var id = getEmployeeId(row);
          if (!id) return "";
          var name = String(row.nombre || row.empleado || row.funcionario || id).trim();
          var checked = preselectedEmployee && id === preselectedEmployee ? " checked" : "";
          return '<label class="scm-calendar-employee-option" data-employee-option data-search-text="' + escHtml((name + " " + id).toLowerCase()) + '">' +
            '<input type="checkbox" name="empleados_multi" value="' + escHtml(id) + '"' + checked + ' data-calendar-employee-check>' +
            '<span><strong>' + escHtml(name || id) + '</strong><small>ID ' + escHtml(id) + '</small></span>' +
            '</label>';
        }).join("");
        return '<div class="scm-calendar-employee-picker" data-calendar-employee-picker>' +
          '<input class="input input-bordered input-sm scm-input scm-calendar-employee-search" type="search" placeholder="Buscar funcionario..." data-calendar-employee-search>' +
          '<div class="scm-calendar-employee-options">' + rows + '</div>' +
          '<small>Marca uno o varios funcionarios. La agenda se agrupa abajo por cada seleccionado.</small>' +
          '</div>';
      }

      function selectedEmployeesFromPopup(form) {
        if (!form) return [];
        var multi = form.querySelector("[data-calendar-employee-picker]");
        if (multi) {
          return Array.prototype.slice.call(form.querySelectorAll("[data-calendar-employee-check]:checked")).map(function (input) {
            return input.value;
          }).filter(Boolean);
        }
        var select = form.querySelector('[name="empleados"]');
        return Array.prototype.slice.call(select ? select.selectedOptions : []).map(function (opt) { return opt.value; }).filter(Boolean);
      }

      function currentMonthEventsForEmployee(employeeId) {
        return calendarEvents.filter(function (row) { return getEventEmployeeId(row) === String(employeeId); }).sort(function (a, b) {
          return String(a.fecha_inicio || "").localeCompare(String(b.fecha_inicio || ""));
        });
      }

      function fetchEmployeeMonthEvents(employeeId) {
        employeeId = String(employeeId || "").trim();
        if (!employeeId) return Promise.resolve([]);
        var rows = currentMonthEventsForEmployee(employeeId);
        if (rows.length) return Promise.resolve(rows);
        var range = monthRange(currentMonth);
        return calendarApi("filtrar_eventos_admin", {
          pagina: 1,
          limite: 120,
          fecha_inicio: range.from,
          fecha_fin: range.to,
          id_empleado: employeeId,
        }).then(function (json) {
          if (!json || !json.success) return [];
          return filterRowsByAllowedEmployees(extractRows(json.data || [])).filter(function (row) {
            return getEventEmployeeId(row) === employeeId;
          }).sort(function (a, b) {
            return String(a.fecha_inicio || "").localeCompare(String(b.fecha_inicio || ""));
          });
        }).catch(function () {
          return [];
        });
      }

      function agendaRowsHtml(rows) {
        if (!rows.length) return '<div class="scm-calendar-popup-agenda-empty">Sin eventos visibles este mes.</div>';
        return rows.slice(0, 12).map(function (row) {
          return '<div class="scm-calendar-popup-agenda-item"><strong>' + escHtml(formatDateTime(row.fecha_inicio)) + '</strong><span>' + escHtml(row.titulo || "Evento") + '</span></div>';
        }).join("");
      }

      function popupEmployeeAgendaHtml(employeeId) {
        if (!employeeId) return '<div class="scm-calendar-popup-agenda-empty">Selecciona un funcionario para ver su agenda del mes.</div>';
        return agendaRowsHtml(currentMonthEventsForEmployee(employeeId));
      }

      function updatePopupAgenda(agenda, employeeIds, isMultiple) {
        if (!agenda) return;
        employeeIds = (employeeIds || []).filter(Boolean);
        if (!employeeIds.length) {
          agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">Selecciona funcionario para ver su agenda del mes.</div>';
          return;
        }
        var requestId = ++popupAgendaRequestId;
        agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">Cargando agenda...</div>';
        Promise.all(employeeIds.map(fetchEmployeeMonthEvents)).then(function (allRows) {
          if (requestId !== popupAgendaRequestId) return;
          if (isMultiple) {
            agenda.innerHTML = employeeIds.map(function (employeeId, index) {
              var openAttr = index === 0 ? " open" : "";
              return '<details class="scm-calendar-agenda-accordion"' + openAttr + '><summary>' + escHtml(employeeDisplayName(employeeId)) + '<span>' + (allRows[index] || []).length + ' evento(s)</span></summary><div>' + agendaRowsHtml(allRows[index] || []) + '</div></details>';
            }).join("");
          } else {
            agenda.innerHTML = agendaRowsHtml(allRows[0] || []);
          }
        });
      }

      function ticketLabel(ticket) {
        if (!ticket) return "";
        var id = String(ticket._ID || ticket.id_ticket || ticket.id || "").trim();
        if (String(ticket.departamento || "").trim() === "Servicio al cliente") {
          return "Ticket #" + id + " - Solicitante: " + String(ticket.solicitante || "").trim();
        }
        return "Ticket #" + id + " - Contrato #" + String(ticket.contrato || "-").trim() + " - Inmueble #" + String(ticket.inmueble || "-").trim();
      }

      function loadTicketsForEmployee(employeeId) {
        employeeId = String(employeeId || "").trim();
        if (!employeeId) return Promise.resolve([]);
        if (ticketCacheByEmployee[employeeId]) return Promise.resolve(ticketCacheByEmployee[employeeId]);
        return calendarApi("obtener_tickets_funcionario", { id_empleado: employeeId }).then(function (json) {
          var rows = json && json.success ? extractRows(json.data || []) : [];
          ticketCacheByEmployee[employeeId] = rows;
          return rows;
        }).catch(function () {
          ticketCacheByEmployee[employeeId] = [];
          return [];
        });
      }

      function loadTicketsForEmployees(employeeIds) {
        employeeIds = (employeeIds || []).filter(Boolean);
        if (!employeeIds.length) return Promise.resolve([]);
        return Promise.all(employeeIds.map(loadTicketsForEmployee)).then(function (groups) {
          var seen = {};
          var rows = [];
          groups.forEach(function (group) {
            (group || []).forEach(function (ticket) {
              var id = String(ticket._ID || ticket.id_ticket || ticket.id || "").trim();
              if (!id || seen[id]) return;
              seen[id] = true;
              rows.push(ticket);
            });
          });
          return rows;
        });
      }

      function renderTicketSelector(select, rows, query) {
        if (!select) return;
        query = normalizeText(query || "");
        var current = select.value || "";
        var filtered = (rows || []).filter(function (ticket) {
          if (!query) return true;
          return normalizeText(ticketLabel(ticket) + " " + (ticket.direccion || "") + " " + (ticket.solicitante || "")).indexOf(query) !== -1;
        }).slice(0, 500);
        select.innerHTML = '<option value="">Sin ticket relacionado</option>' + filtered.map(function (ticket) {
          var id = String(ticket._ID || ticket.id_ticket || ticket.id || "").trim();
          return id ? '<option value="' + escHtml(id) + '">' + escHtml(ticketLabel(ticket)) + "</option>" : "";
        }).join("");
        if (current) select.value = current;
      }

      function categoryNameFromSelect(select) {
        if (!select || select.selectedIndex < 0) return "";
        var option = select.options[select.selectedIndex];
        return option ? String(option.textContent || "").trim() : "";
      }

      function buildCalendarTitle(categoryName, ticket) {
        categoryName = String(categoryName || "").trim();
        if (ticket) {
          var contrato = String(ticket.contrato || "").trim();
          if (contrato) return "Contrato #" + contrato + " - " + (categoryName || "Actividad");
          var ticketId = String(ticket._ID || ticket.id_ticket || ticket.id || "").trim();
          return "Ticket #" + ticketId + " - " + (categoryName || String(ticket.solicitante || "Actividad").trim());
        }
        return "";
      }

      function validateCalendarEventTimes(dateValue, startValue, endValue) {
        if (!dateValue || !startValue || !endValue) return "Debes ingresar fecha, hora de inicio y hora de fin.";
        var start = new Date(dateValue + "T" + startValue);
        var end = new Date(dateValue + "T" + endValue);
        var now = new Date();
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return "Debes ingresar fechas y horas validas.";
        if (end < start) return "La hora de finalizacion no puede ser menor que la hora de inicio.";
        var startDay = new Date(start.getFullYear(), start.getMonth(), start.getDate());
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        if (startDay < today) return "No puedes seleccionar una fecha pasada.";
        if (end < now) return "No puedes seleccionar una hora de finalizacion pasada.";
        var startHour = start.getHours() + start.getMinutes() / 60;
        var endHour = end.getHours() + end.getMinutes() / 60;
        if (startHour < 8 || startHour > 21) return "La hora de inicio debe estar entre las 8:00 a. m. y las 9:00 p. m.";
        if (endHour < 8 || endHour > 21) return "La hora de finalizacion debe estar entre las 8:00 a. m. y las 9:00 p. m.";
        return "";
      }

      function estadoAdministrativoOptionsHtml() {
        return [
          "En espera de respuesta",
          "Por inspeccionar",
          "Inspeccionado",
          "Cotizado",
          "En ejecucion por inmobiliaria",
          "En ejecucion por propietario",
          "En ejecucion por arrendatario",
          "En ejecucion por copropiedad",
        ].map(function (value) {
          return '<option value="' + escHtml(value) + '">' + escHtml(value) + "</option>";
        }).join("");
      }

      function categoryNameForRow(row) {
        var id = getCategoryId(row);
        return String((row && row.categoria) || (id && categoriesById[id] && (categoriesById[id].nombre || categoriesById[id].categoria)) || "Sin categoria").trim();
      }

      function employeeNameForRow(row) {
        return String((row && (row.funcionario || row.nombre_empleado || row.nombre)) || employeeDisplayName(getEventEmployeeId(row)) || "Funcionario").trim();
      }

      function uniqueReportRows(rows) {
        var seen = {};
        return (rows || []).filter(function (row) {
          var key = String(row.id || row._ID || "").trim();
          if (!key) {
            key = [
              getEventEmployeeId(row),
              eventDateKey(row),
              row.fecha_inicio || "",
              row.titulo || "",
              row.id_ticket || "",
            ].join("|");
          }
          if (seen[key]) return false;
          seen[key] = true;
          return true;
        });
      }

      function countReportGroups(rows, labelFn) {
        var map = {};
        (rows || []).forEach(function (row) {
          var label = String(labelFn(row) || "Sin dato").trim() || "Sin dato";
          if (!map[label]) map[label] = { label: label, count: 0, rows: [] };
          map[label].count += 1;
          map[label].rows.push(row);
        });
        return Object.keys(map).map(function (key) { return map[key]; }).sort(function (a, b) {
          return b.count - a.count || a.label.localeCompare(b.label);
        });
      }

      function reportGroupListHtml(title, groups, emptyText) {
        if (!groups.length) {
          return '<section class="scm-calendar-report-section"><h4>' + escHtml(title) + '</h4><div class="scm-calendar-report-empty">' + escHtml(emptyText) + '</div></section>';
        }
        var max = groups.reduce(function (acc, item) { return Math.max(acc, item.count); }, 1);
        return '<section class="scm-calendar-report-section"><h4>' + escHtml(title) + '</h4>' + groups.map(function (item) {
          var width = Math.max(8, Math.round((item.count / max) * 100));
          return '<div class="scm-calendar-report-bar"><div><span>' + escHtml(item.label) + '</span><strong>' + item.count + '</strong></div><i style="width:' + width + '%"></i></div>';
        }).join("") + '</section>';
      }

      function reportEventsListHtml(rows) {
        if (!rows.length) {
          return '<section class="scm-calendar-report-section scm-calendar-report-events-section"><h4>Eventos creados</h4><div class="scm-calendar-report-empty">No hay eventos creados con esos filtros.</div></section>';
        }
        return '<section class="scm-calendar-report-section scm-calendar-report-events-section"><h4>Cu&aacute;les eventos se crearon</h4><div class="scm-calendar-report-events">' + rows.map(function (row) {
          var ticket = String(row.id_ticket || "").trim();
          var created = eventCreatedValue(row);
          return '<article class="scm-calendar-report-event">' +
            '<div><strong>' + escHtml(row.titulo || "Evento") + '</strong><span>Creado: ' + escHtml(created ? formatDateTime(created) : "Sin fecha") + '</span></div>' +
            '<p><b>Programado:</b> ' + escHtml(formatDateTime(row.fecha_inicio)) + (row.fecha_fin ? " - " + escHtml(formatDateTime(row.fecha_fin)) : "") + ' <b>Categor&iacute;a:</b> ' + escHtml(categoryNameForRow(row)) + ' <b>Funcionario:</b> ' + escHtml(employeeNameForRow(row)) + (ticket ? ' <b>Ticket:</b> #' + escHtml(ticket) : "") + '</p>' +
            '</article>';
        }).join("") + '</div></section>';
      }

      function eventCreatedValue(row) {
        return String(row && (row.creado_en || row.created_at || row.cct_created || row.fecha_creacion || row.fecha_registro || row.created || "")).trim();
      }

      function eventCreatedKey(row) {
        return eventCreatedValue(row).slice(0, 10);
      }

      function reportScopeEmployeeIds(filters) {
        var selectedEmployee = String((filters && filters.id_empleado) || "").trim();
        if (selectedEmployee) return [selectedEmployee];
        return allowedEmployees.map(getEmployeeId).filter(Boolean);
      }

      function reportCategoryOptionsHtml(current) {
        current = String(current || "");
        return '<option value="">Todas las categor&iacute;as</option>' + calendarAdminCategories().map(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          var name = calendarCategoryLabel(row) || id;
          return id ? '<option value="' + escHtml(id) + '"' + (id === current ? " selected" : "") + '>' + escHtml(name) + "</option>" : "";
        }).join("");
      }

      function reportEmployeeOptionsHtml(current) {
        current = String(current || "");
        return '<option value="">Todos los funcionarios visibles</option>' + allowedEmployees.map(function (row) {
          return employeeOptionHtml(row, current);
        }).join("");
      }

      function calendarReportShellHtml(defaultDate, defaultEmployee, defaultCategory) {
        return '<div class="scm-calendar-report-shell">' +
          '<form class="scm-calendar-report-filters" data-calendar-report-filters autocomplete="off">' +
          '<label><span>Creado el d&iacute;a</span><input class="input input-bordered input-sm scm-input" type="date" name="creado_en" value="' + escHtml(defaultDate) + '"></label>' +
          '<label><span>Funcionario</span><select class="select select-bordered select-sm scm-select" name="id_empleado">' + reportEmployeeOptionsHtml(defaultEmployee) + '</select></label>' +
          '<label><span>Categor&iacute;a</span><select class="select select-bordered select-sm scm-select" name="id_categoria">' + reportCategoryOptionsHtml(defaultCategory) + '</select></label>' +
          '<button type="submit" class="scm-btn-primary btn btn-primary">Aplicar</button>' +
          '</form>' +
          '<div data-calendar-report-content><div class="scm-calendar-report-loading">Cargando informe...</div></div>' +
          '</div>';
      }

      function fetchCalendarReportPage(employeeId, page, limit) {
        return calendarApi("filtrar_eventos_admin", {
          pagina: page,
          limite: limit,
          id_empleado: employeeId,
        }).then(function (json) {
          var rows = json && json.success ? extractRows(json.data || []) : [];
          var total = json && json.data && typeof json.data.total !== "undefined" ? parseInt(json.data.total, 10) : rows.length;
          return { rows: rows, total: Number.isNaN(total) ? rows.length : total };
        }).catch(function () {
          return { rows: [], total: 0 };
        });
      }

      function loadCalendarReportRows(filters) {
        filters = filters || {};
        var createdKey = String(filters.creado_en || toDateKey(new Date())).slice(0, 10);
        var categoryId = String(filters.id_categoria || "").trim();
        var ids = reportScopeEmployeeIds(filters);
        if (!ids.length) return Promise.resolve([]);
        var limit = 500;
        return Promise.all(ids.map(function (employeeId) {
          return fetchCalendarReportPage(employeeId, 1, limit).then(function (first) {
            var pages = Math.min(20, Math.max(1, Math.ceil((first.total || first.rows.length) / limit)));
            if (pages <= 1) return first.rows;
            var requests = [];
            for (var page = 2; page <= pages; page += 1) {
              requests.push(fetchCalendarReportPage(employeeId, page, limit));
            }
            return Promise.all(requests).then(function (rest) {
              var rows = first.rows.slice();
              rest.forEach(function (item) { rows = rows.concat(item.rows || []); });
              return rows;
            });
          });
        })).then(function (groups) {
          var rows = [];
          groups.forEach(function (group) {
            rows = rows.concat(group || []);
          });
          return uniqueReportRows(filterRowsByAllowedEmployees(rows)).filter(function (row) {
            if (eventCreatedKey(row) !== createdKey) return false;
            if (categoryId && getCategoryId(row) !== categoryId) return false;
            return true;
          }).sort(function (a, b) {
            return String(eventCreatedValue(b) || "").localeCompare(String(eventCreatedValue(a) || ""));
          });
        });
      }

      function calendarReportHtml(rowsToday, filters) {
        rowsToday = rowsToday || [];
        filters = filters || {};
        var selectedEmployee = String(filters.id_empleado || "").trim();
        var employee = allowedEmployees.find(function (row) { return getEmployeeId(row) === selectedEmployee; });
        var categoryId = String(filters.id_categoria || "").trim();
        var categoryLabel = categoryId && categoriesById[categoryId] ? String(categoriesById[categoryId].nombre || categoriesById[categoryId].categoria || categoryId) : "Todas las categorias";
        var pendingRows = rowsToday.filter(function (row) { return String(row.estado || "").toLowerCase() !== "si"; });
        var doneRows = rowsToday.filter(function (row) { return String(row.estado || "").toLowerCase() === "si"; });
        var scheduledTodayRows = rowsToday.filter(function (row) { return eventDateKey(row) === String(filters.creado_en || "").slice(0, 10); });
        var categoryGroups = countReportGroups(rowsToday, categoryNameForRow);
        var employeeGroups = countReportGroups(rowsToday, employeeNameForRow);
        var scopeLabel = employee ? (employee.nombre || employee.funcionario || selectedEmployee) : "Todos los funcionarios visibles";
        return '<div class="scm-calendar-report-modal">' +
          '<p class="scm-calendar-report-employee"><span>Eventos creados el ' + escHtml(filters.creado_en || toDateKey(new Date())) + '</span><strong>' + escHtml(scopeLabel) + '</strong><em>' + escHtml(categoryLabel) + '</em></p>' +
          '<div class="scm-calendar-report-grid">' +
          '<div><span>Creados</span><strong>' + rowsToday.length + '</strong></div>' +
          '<div><span>Categor&iacute;as</span><strong>' + categoryGroups.length + '</strong></div>' +
          '<div><span>Funcionarios</span><strong>' + employeeGroups.length + '</strong></div>' +
          '<div><span>Para ese mismo d&iacute;a</span><strong>' + scheduledTodayRows.length + '</strong></div>' +
          '<div><span>Pendientes</span><strong>' + pendingRows.length + '</strong></div>' +
          '<div><span>Realizadas</span><strong>' + doneRows.length + '</strong></div>' +
          '</div>' +
          '<div class="scm-calendar-report-columns">' +
          reportGroupListHtml("Eventos por categoría", categoryGroups, "Sin categorías para hoy.") +
          reportGroupListHtml("Por funcionario", employeeGroups, "Sin funcionarios para hoy.") +
          '</div>' +
          reportEventsListHtml(rowsToday) +
          '</div>';
      }

      function openCalendarReport() {
        if (!window.Swal || typeof window.Swal.fire !== "function") {
          showToast("error", "No esta disponible el popup de informe.");
          return;
        }
        var defaultDate = toDateKey(new Date());
        var defaultEmployee = selectedEmployeeFromFilter();
        var defaultCategory = filterForm && filterForm.querySelector('[name="id_categoria"]') ? filterForm.querySelector('[name="id_categoria"]').value : "";
        window.Swal.fire({
          title: "Informe del día",
          html: calendarReportShellHtml(defaultDate, defaultEmployee, defaultCategory),
          width: 980,
          customClass: { popup: "scm-calendar-swal-popup scm-calendar-report-swal" },
          confirmButtonText: "Cerrar",
          showCancelButton: false,
          didOpen: function () {
            var popup = window.Swal.getPopup();
            var form = popup ? popup.querySelector("[data-calendar-report-filters]") : null;
            var content = popup ? popup.querySelector("[data-calendar-report-content]") : null;
            function currentReportFilters() {
              if (!form) return { creado_en: defaultDate, id_empleado: defaultEmployee, id_categoria: defaultCategory };
              var fd = new FormData(form);
              return {
                creado_en: fd.get("creado_en") || defaultDate,
                id_empleado: fd.get("id_empleado") || "",
                id_categoria: fd.get("id_categoria") || "",
              };
            }
            function renderReport() {
              var filters = currentReportFilters();
              if (content) content.innerHTML = '<div class="scm-calendar-report-loading">Cargando informe por fecha de creaci&oacute;n...</div>';
              loadCalendarReportRows(filters).then(function (rows) {
                if (content) content.innerHTML = calendarReportHtml(rows, filters);
              }).catch(function () {
                if (content) content.innerHTML = '<div class="scm-calendar-report-empty">No se pudo cargar el informe del d&iacute;a.</div>';
              });
            }
            if (form) {
              form.addEventListener("submit", function (e) {
                e.preventDefault();
                renderReport();
              });
              form.querySelectorAll("input, select").forEach(function (field) {
                field.addEventListener("change", renderReport);
              });
            }
            renderReport();
          },
        });
      }

      function calendarEventById(id) {
        id = String(id || "").trim();
        if (!id) return null;
        return calendarEvents.find(function (row) {
          return String(row.id || row._ID || row.event_id || "").trim() === id;
        }) || null;
      }

      function openRescheduleEventPopup(eventId) {
        if (!window.Swal || typeof window.Swal.fire !== "function") {
          showToast("error", "No esta disponible el popup para trasladar eventos.");
          return;
        }
        var row = calendarEventById(eventId);
        if (!row) {
          showToast("error", "No encontre el evento seleccionado.");
          return;
        }
        var title = String(row.titulo || row.title || "Evento #" + eventId).trim();
        var categoryName = categoryNameForRow(row);
        var dateValue = datePartFromDateTime(row.fecha_inicio) || selectedDay || toDateKey(new Date());
        var startValue = timePartFromDateTime(row.fecha_inicio);
        var endValue = timePartFromDateTime(row.fecha_fin);
        var ticket = String(row.id_ticket || "").trim();
        var html = '<form class="scm-calendar-popup-form scm-calendar-reschedule-form" autocomplete="off">' +
          '<div class="scm-calendar-reschedule-summary">' +
          '<strong>' + escHtml(title) + '</strong>' +
          '<span>' + escHtml(categoryName) + (ticket ? " · Ticket #" + escHtml(ticket) : "") + '</span>' +
          '</div>' +
          '<div class="scm-calendar-popup-grid">' +
          '<label class="scm-seg-field"><span>Nueva fecha</span><input class="input input-bordered input-sm scm-input" type="date" name="fecha" required value="' + escHtml(dateValue) + '"></label>' +
          '<label class="scm-seg-field"><span>Hora inicio</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_inicio" required value="' + escHtml(startValue) + '"></label>' +
          '<label class="scm-seg-field"><span>Hora fin</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_fin" required value="' + escHtml(endValue) + '"></label>' +
          '<label class="scm-seg-field"><span>Es cita</span><select class="select select-bordered select-sm scm-select" name="es_cita"><option value="si"' + (ticket ? " selected" : "") + '>Si</option><option value="no"' + (!ticket ? " selected" : "") + '>No</option></select></label>' +
          '<label class="scm-seg-field scm-calendar-field-full"><span>Motivo del traslado</span><textarea class="textarea textarea-bordered scm-input" name="observacion" rows="3" required placeholder="Explica por qu&eacute; se traslada este evento..."></textarea></label>' +
          '<label class="scm-seg-field scm-calendar-field-full"><span>Mensaje para el ticket</span><textarea class="textarea textarea-bordered scm-input" name="descripcion" rows="5" placeholder="Este texto se enviar&aacute; al proceso del ticket si el evento est&aacute; relacionado."></textarea><small>Si es una cita preventiva o correctiva, el texto se genera autom&aacute;ticamente y puedes editarlo.</small></label>' +
          '</div></form>';
        window.Swal.fire({
          title: "Trasladar evento",
          html: html,
          width: 760,
          customClass: { popup: "scm-calendar-swal-popup scm-calendar-reschedule-swal" },
          showCancelButton: true,
          confirmButtonText: "Guardar traslado",
          cancelButtonText: "Cerrar",
          focusConfirm: false,
          didOpen: function () {
            var popup = window.Swal.getPopup();
            var form = popup ? popup.querySelector(".scm-calendar-reschedule-form") : null;
            if (!form) return;
            var dateInput = form.querySelector('[name="fecha"]');
            var startInput = form.querySelector('[name="hora_inicio"]');
            var endInput = form.querySelector('[name="hora_fin"]');
            var citaSelect = form.querySelector('[name="es_cita"]');
            var observationInput = form.querySelector('[name="observacion"]');
            var descriptionInput = form.querySelector('[name="descripcion"]');
            function maybeAutofillRescheduleDescription() {
              if (!descriptionInput || !dateInput || !startInput || !endInput) return;
              if (!ticket || !isCalendarAppointmentCategoryName(categoryName) || (citaSelect && citaSelect.value !== "si")) return;
              if (descriptionInput.value && descriptionInput.getAttribute("data-auto-calendar-text") !== "1") return;
              descriptionInput.value = buildRescheduleDescription(title, dateInput.value, startInput.value, endInput.value, observationInput ? observationInput.value.trim() : "");
              descriptionInput.setAttribute("data-auto-calendar-text", "1");
            }
            [dateInput, startInput, endInput, citaSelect, observationInput].forEach(function (field) {
              if (field) field.addEventListener("input", maybeAutofillRescheduleDescription);
              if (field) field.addEventListener("change", maybeAutofillRescheduleDescription);
            });
            if (descriptionInput) {
              descriptionInput.addEventListener("input", function () {
                descriptionInput.setAttribute("data-auto-calendar-text", "0");
              });
            }
            maybeAutofillRescheduleDescription();
          },
          preConfirm: function () {
            var popup = window.Swal.getPopup();
            var form = popup ? popup.querySelector(".scm-calendar-reschedule-form") : null;
            if (!form) {
              window.Swal.showValidationMessage("No se encontro el formulario.");
              return false;
            }
            var fd = new FormData(form);
            var date = String(fd.get("fecha") || "");
            var start = String(fd.get("hora_inicio") || "");
            var end = String(fd.get("hora_fin") || "");
            var err = validateCalendarEventTimes(date, start, end);
            if (err) {
              window.Swal.showValidationMessage(err);
              return false;
            }
            var observation = String(fd.get("observacion") || "").trim();
            if (!observation) {
              window.Swal.showValidationMessage("Escribe el motivo del traslado.");
              return false;
            }
            var payload = {
              id_evento: eventId,
              fecha_inicio: date + " " + start + ":00",
              fecha_fin: date + " " + end + ":00",
              observacion: observation,
              es_cita: String(fd.get("es_cita") || "no"),
              descripcion: String(fd.get("descripcion") || "").trim(),
            };
            if (ticket && payload.es_cita === "si" && !payload.descripcion) {
              payload.descripcion = buildRescheduleDescription(title, date, start, end, observation);
            }
            window.Swal.showLoading();
            return calendarApi("trasladar_evento", payload).then(function (json) {
              if (!json || !json.success) throw new Error((json && json.message) || "No se pudo trasladar el evento.");
              if (ticket && payload.es_cita === "si") {
                json._scmCitaNotificationAppointments = [{
                  id_ticket: ticket,
                  id_empleado: getEventEmployeeId(row) || selectedEmployeeFromFilter(),
                  categoria: categoryName,
                  titulo: title,
                  fecha_inicio: payload.fecha_inicio,
                  fecha_fin: payload.fecha_fin,
                  ubicacion: String(row.ubicacion || row.lugar || row.direccion || "").trim(),
                  es_cita: "si",
                }];
              }
              return json;
            }).catch(function (err2) {
              window.Swal.showValidationMessage(err2.message || "No se pudo trasladar el evento.");
              return false;
            });
          },
        }).then(function (result) {
          if (!result.isConfirmed || !result.value) return;
          showToast("success", result.value.message || "Evento trasladado.");
          if (Array.isArray(result.value._scmCitaNotificationAppointments) && result.value._scmCitaNotificationAppointments.length) {
            notifyCalendarAppointment(root, result.value._scmCitaNotificationAppointments)
              .then(showCalendarNotificationResult);
          }
          loadEvents();
        });
      }

      function openCompleteEventPopup(eventId) {
        if (!window.Swal || typeof window.Swal.fire !== "function") {
          showToast("error", "No esta disponible el popup para marcar eventos.");
          return;
        }
        var row = calendarEventById(eventId) || {};
        var title = String(row.titulo || row.title || "Evento #" + eventId).trim();
        var ticket = String(row.id_ticket || "").trim();
        var html = '<form class="scm-calendar-popup-form scm-calendar-complete-form" autocomplete="off">' +
          '<div class="scm-calendar-reschedule-summary">' +
          '<strong>' + escHtml(title) + '</strong>' +
          '<span>' + escHtml(formatDateTime(row.fecha_inicio || "")) + (ticket ? " · Ticket #" + escHtml(ticket) : "") + '</span>' +
          '</div>' +
          '<label class="scm-seg-field scm-calendar-field-full"><span>Observaci&oacute;n de cierre</span><textarea class="textarea textarea-bordered scm-input" name="observacion" rows="4" required>Realizado</textarea><small>Este texto se guardar&aacute; por defecto. Si tienes informaci&oacute;n adicional, puedes ampliarlo antes de guardar.</small></label>' +
          '</form>';
        window.Swal.fire({
          title: "Marcar evento realizado",
          html: html,
          width: 680,
          customClass: { popup: "scm-calendar-swal-popup scm-calendar-complete-swal" },
          showCancelButton: true,
          confirmButtonText: "Marcar realizado",
          cancelButtonText: "Cerrar",
          focusConfirm: false,
          preConfirm: function () {
            var popup = window.Swal.getPopup();
            var form = popup ? popup.querySelector(".scm-calendar-complete-form") : null;
            var observation = form ? String(new FormData(form).get("observacion") || "").trim() : "";
            if (!observation) {
              window.Swal.showValidationMessage("La observación es obligatoria.");
              return false;
            }
            window.Swal.showLoading();
            return calendarApi("cambiar_estado", {
              id_evento: eventId,
              observacion: observation,
            }).then(function (json) {
              if (!json || !json.success) throw new Error((json && json.message) || "No se pudo marcar el evento como realizado.");
              return json;
            }).catch(function (err) {
              window.Swal.showValidationMessage(err.message || "No se pudo marcar el evento como realizado.");
              return false;
            });
          },
        }).then(function (result) {
          if (!result.isConfirmed || !result.value) return;
          showToast("success", result.value.message || "Evento marcado como realizado.");
          loadEvents();
        });
      }

      function pendingEventRowsHtml(rows) {
        if (!rows.length) {
          return '<div class="scm-calendar-report-empty">Este funcionario no tiene eventos pendientes vencidos.</div>';
        }
        return '<div class="scm-calendar-report-events scm-calendar-pending-events">' + rows.map(function (row) {
          var id = String(row.id || row._ID || row.event_id || "").trim();
          var ticket = String(row.id_ticket || "").trim();
          var eventoUrl = id ? buildCalendarUrl("/evento/" + encodeURIComponent(id)) : "";
          var ticketUrl = ticket ? "https://sucasainmobiliaria.com.co/ticket/?id_ticket=" + encodeURIComponent(ticket) : "";
          return '<article class="scm-calendar-report-event scm-calendar-pending-event-card">' +
            '<div class="scm-calendar-pending-event-head"><strong>' + escHtml(row.titulo || "Evento") + '</strong><span>' + escHtml(formatDateTime(row.fecha_inicio)) + (row.fecha_fin ? " - " + escHtml(formatDateTime(row.fecha_fin)) : "") + '</span></div>' +
            '<p class="scm-calendar-pending-event-meta"><b>Categor&iacute;a:</b> ' + escHtml(categoryNameForRow(row)) + ' <b>Funcionario:</b> ' + escHtml(employeeNameForRow(row)) + (ticket ? ' <b>Ticket:</b> #' + escHtml(ticket) : "") + '</p>' +
            '<div class="scm-calendar-event-actions scm-calendar-pending-event-actions">' +
            (eventoUrl ? '<button type="button" class="scm-calendar-action-btn scm-calendar-action-btn--ghost" data-scm-open-iframe data-iframe-url="' + escHtml(eventoUrl) + '" data-iframe-title="Evento #' + escHtml(id) + '">Ver evento</button>' : "") +
            (ticketUrl ? '<button type="button" class="scm-calendar-action-btn scm-calendar-action-btn--ghost" data-scm-open-iframe data-iframe-url="' + escHtml(ticketUrl) + '" data-iframe-title="Ticket #' + escHtml(ticket) + '">Ver ticket</button>' : "") +
            (id ? '<button type="button" class="scm-calendar-action-btn scm-calendar-action-btn--primary" data-scm-calendar-complete-event data-event-id="' + escHtml(id) + '">Marcar realizado</button>' : "") +
            '</div>' +
            '</article>';
        }).join("") + '</div>';
      }

      function openPendingEventsPopup() {
        if (!window.Swal || typeof window.Swal.fire !== "function") {
          showToast("error", "No esta disponible el popup de pendientes.");
          return;
        }
        var employeeId = selectedEmployeeFromFilter();
        if (!employeeId) {
          showToast("warning", "Selecciona un funcionario para ver sus eventos pendientes.");
          return;
        }
        var employeeName = employeeDisplayName(employeeId);
        window.Swal.fire({
          title: "Eventos pendientes",
          html: '<div class="scm-calendar-report-shell"><p class="scm-calendar-report-employee"><span>Funcionario</span><strong>' + escHtml(employeeName) + '</strong><em>Eventos vencidos sin realizar</em></p><div data-calendar-pending-content><div class="scm-calendar-report-loading">Cargando pendientes...</div></div></div>',
          width: 920,
          customClass: { popup: "scm-calendar-swal-popup scm-calendar-pending-swal" },
          confirmButtonText: "Cerrar",
          showCancelButton: false,
          didOpen: function () {
            var popup = window.Swal.getPopup();
            var content = popup ? popup.querySelector("[data-calendar-pending-content]") : null;
            calendarApi("listar_pendientes_vencidos", { id_empleado: employeeId })
              .then(function (json) {
                if (!json || !json.success) throw new Error((json && json.message) || "No se pudieron cargar pendientes.");
                var rows = filterRowsByAllowedEmployees(extractRows(json.data || []));
                if (content) content.innerHTML = pendingEventRowsHtml(rows);
              })
              .catch(function (err) {
                if (content) content.innerHTML = '<div class="scm-calendar-report-empty">No se pudieron cargar los pendientes: ' + escHtml(err.message || "Error") + '</div>';
              });
            if (popup) {
              popup.addEventListener("click", function (event) {
                var iframeBtn = event.target && event.target.closest ? event.target.closest("[data-scm-open-iframe]") : null;
                if (iframeBtn) {
                  event.preventDefault();
                  openIframeModal(
                    iframeBtn.dataset.iframeUrl || "",
                    iframeBtn.dataset.iframeTitle || "",
                    iframeBtn.hasAttribute("data-scm-compact-iframe"),
                  );
                  return;
                }
                var completeBtn = event.target && event.target.closest ? event.target.closest("[data-scm-calendar-complete-event]") : null;
                if (completeBtn) {
                  event.preventDefault();
                  var id = completeBtn.getAttribute("data-event-id") || "";
                  window.Swal.close();
                  openCompleteEventPopup(id);
                }
              });
            }
          },
        });
      }

      function openCreateEventPopup(mode) {
        mode = mode === "multiple" ? "multiple" : "single";
        var preselectedEmployee = selectedEmployeeFromFilter();
        var employeeOptions = allowedEmployees.map(function (row) { return employeeOptionHtml(row, preselectedEmployee); }).join("");
        var categoryOptions = calendarAdminCategories().map(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          var name = calendarCategoryLabel(row) || id;
          return id ? '<option value="' + escHtml(id) + '">' + escHtml(name) + "</option>" : "";
        }).join("");
        var employeesControl = mode === "multiple"
          ? employeeMultiPickerHtml(preselectedEmployee)
          : '<select class="select select-bordered select-sm scm-select" name="empleados" required><option value="">Selecciona funcionario</option>' + employeeOptions + "</select>";
        var ticketFieldsHtml = mode === "single"
          ? '<div class="scm-calendar-ticket-inline scm-calendar-field-full">' +
            '<label class="scm-seg-field"><span>Relacionado con ticket</span><select class="select select-bordered select-sm scm-select" name="relacionado_ticket" data-calendar-related-ticket><option value="">Selecciona una opci&oacute;n</option><option value="si">S&iacute;, est&aacute; relacionado</option><option value="no">No, evento libre</option></select></label>' +
            '<label class="scm-seg-field" data-calendar-ticket-field hidden><span>Ticket relacionado</span><select class="select select-bordered select-sm scm-select scm-calendar-ticket-select" name="id_ticket" data-calendar-ticket-select><option value="">Selecciona funcionario para cargar tickets</option></select><small>Busca y escoge el ticket; se autocompleta t&iacute;tulo y direcci&oacute;n.</small></label>' +
            "</div>" +
            '<label class="scm-seg-field" data-calendar-cita-field hidden><span>Es cita</span><select class="select select-bordered select-sm scm-select" name="es_cita"><option value="">Selecciona si es cita</option><option value="si">Si</option><option value="no">No</option></select></label>' +
            '<label class="scm-seg-field scm-calendar-ticket-state" data-calendar-admin-state hidden><span>Estado administrativo</span><select class="select select-bordered select-sm scm-select" name="estado_administrativo"><option value="">Selecciona estado administrativo</option>' + estadoAdministrativoOptionsHtml() + '</select></label>'
          : "";
        var html = '<form class="scm-calendar-popup-form" autocomplete="off">' +
          '<div class="scm-calendar-popup-grid">' +
          '<label class="scm-seg-field"><span>T&iacute;tulo</span><input class="input input-bordered input-sm scm-input" name="titulo" required placeholder="Ej: Cita revisi&oacute;n preventiva"></label>' +
          '<label class="scm-seg-field"><span>Categor&iacute;a</span><select class="select select-bordered select-sm scm-select" name="id_categoria" required><option value="">Selecciona categor&iacute;a</option>' + categoryOptions + '</select></label>' +
          '<label class="scm-seg-field scm-calendar-field-full"><span>Funcionario(s)</span>' + employeesControl + '</label>' +
          '<label class="scm-seg-field"><span>Fecha</span><input class="input input-bordered input-sm scm-input" type="date" name="fecha" required value="' + escHtml(selectedDay || toDateKey(new Date())) + '"></label>' +
          '<label class="scm-seg-field"><span>Hora inicio</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_inicio" required></label>' +
          '<label class="scm-seg-field"><span>Hora fin</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_fin" required></label>' +
          '<div class="scm-calendar-recurrence scm-calendar-field-full" data-calendar-recurrence>' +
          '<label class="scm-calendar-recurrence-toggle"><input type="checkbox" name="es_recurrente" value="1" data-calendar-recurrence-toggle><span>Evento recurrente / m&uacute;ltiples fechas</span></label>' +
          '<div class="scm-calendar-recurrence-body" data-calendar-recurrence-body hidden>' +
          '<label class="scm-seg-field"><span>Tipo recurrencia</span><select class="select select-bordered select-sm scm-select" name="tipo_recurrencia" data-calendar-recurrence-type><option value="diario">Diario</option><option value="semanal">Semanal</option><option value="personalizado">Personalizado</option></select></label>' +
          '<label class="scm-seg-field" data-calendar-recurrence-end><span>Fecha fin</span><input class="input input-bordered input-sm scm-input" type="date" name="fecha_fin_recurrencia"></label>' +
          '<div class="scm-calendar-week-picker" data-calendar-week-picker hidden><span>D&iacute;as de la semana</span><label><input type="checkbox" value="1" name="dias_semana"> Lun</label><label><input type="checkbox" value="2" name="dias_semana"> Mar</label><label><input type="checkbox" value="3" name="dias_semana"> Mi&eacute;</label><label><input type="checkbox" value="4" name="dias_semana"> Jue</label><label><input type="checkbox" value="5" name="dias_semana"> Vie</label><label><input type="checkbox" value="6" name="dias_semana"> S&aacute;b</label><label><input type="checkbox" value="0" name="dias_semana"> Dom</label></div>' +
          '<div class="scm-calendar-custom-dates" data-calendar-custom-dates hidden><div data-calendar-custom-rows></div><button type="button" class="scm-case-work-btn" data-calendar-add-custom-date>Agregar fecha personalizada</button></div>' +
          '</div></div>' +
          ticketFieldsHtml +
          '<label class="scm-seg-field scm-calendar-field-full"><span>Ubicaci&oacute;n</span><input class="input input-bordered input-sm scm-input" name="ubicacion" placeholder="Direcci&oacute;n o lugar"></label>' +
          '<label class="scm-seg-field scm-calendar-field-full"><span>Descripci&oacute;n</span><textarea class="textarea textarea-bordered scm-input" name="descripcion" rows="4" required></textarea></label>' +
          '</div><div class="scm-calendar-popup-agenda"><h4>' + (mode === "multiple" ? "Agenda por funcionario" : "Agenda del funcionario") + '</h4><div data-scm-calendar-popup-agenda>' + popupEmployeeAgendaHtml(preselectedEmployee) + '</div></div></form>';
        if (!window.Swal || typeof window.Swal.fire !== "function") {
          showToast("error", "No esta disponible el popup para crear eventos.");
          return;
        }
        window.Swal.fire({
          title: mode === "multiple" ? "Crear evento múltiple" : "Crear evento",
          html: html,
          width: 1060,
          customClass: {
            popup: "scm-calendar-swal-popup",
          },
          showCancelButton: true,
          confirmButtonText: "Crear evento",
          cancelButtonText: "Cerrar",
          focusConfirm: false,
          didOpen: function () {
            var popup = window.Swal.getPopup();
            if (!popup) return;
            var employeesSelect = popup.querySelector('[name="empleados"]');
            var employeePicker = popup.querySelector("[data-calendar-employee-picker]");
            var employeeSearch = popup.querySelector("[data-calendar-employee-search]");
            var agenda = popup.querySelector("[data-scm-calendar-popup-agenda]");
            var categorySelect = popup.querySelector('[name="id_categoria"]');
            var dateInput = popup.querySelector('[name="fecha"]');
            var startInput = popup.querySelector('[name="hora_inicio"]');
            var endInput = popup.querySelector('[name="hora_fin"]');
            var recurrenceToggle = popup.querySelector("[data-calendar-recurrence-toggle]");
            var recurrenceBody = popup.querySelector("[data-calendar-recurrence-body]");
            var recurrenceType = popup.querySelector("[data-calendar-recurrence-type]");
            var recurrenceEndWrap = popup.querySelector("[data-calendar-recurrence-end]");
            var weekPicker = popup.querySelector("[data-calendar-week-picker]");
            var customDatesWrap = popup.querySelector("[data-calendar-custom-dates]");
            var customRows = popup.querySelector("[data-calendar-custom-rows]");
            var addCustomDateBtn = popup.querySelector("[data-calendar-add-custom-date]");
            var titleInput = popup.querySelector('[name="titulo"]');
            var locationInput = popup.querySelector('[name="ubicacion"]');
            var descriptionInput = popup.querySelector('[name="descripcion"]');
            var relatedTicketSelect = popup.querySelector("[data-calendar-related-ticket]");
            var ticketFieldWrap = popup.querySelector("[data-calendar-ticket-field]");
            var citaFieldWrap = popup.querySelector("[data-calendar-cita-field]");
            var citaSelect = popup.querySelector('[name="es_cita"]');
            var ticketSelect = popup.querySelector("[data-calendar-ticket-select]");
            var adminStateWrap = popup.querySelector("[data-calendar-admin-state]");
            var adminStateSelect = popup.querySelector('[name="estado_administrativo"]');
            var currentTicketRows = [];
            function canUseSelect2() {
              return !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2);
            }
            function destroyTicketSelect2() {
              if (!ticketSelect || !canUseSelect2()) return;
              var $ticket = window.jQuery(ticketSelect);
              if ($ticket.data("select2")) {
                $ticket.select2("destroy");
              }
            }
            function initTicketSelect2() {
              if (!ticketSelect || !canUseSelect2() || !isTicketRelated()) return;
              var $ticket = window.jQuery(ticketSelect);
              if ($ticket.data("select2")) return;
              $ticket.select2({
                width: "100%",
                dropdownParent: window.jQuery(popup),
                placeholder: "Buscar ticket por número, contrato, inmueble o solicitante",
                allowClear: true,
              });
            }
            function selectedEmployees() {
              return selectedEmployeesFromPopup(popup.querySelector(".scm-calendar-popup-form"));
            }
            function selectedTicket() {
              var value = ticketSelect ? String(ticketSelect.value || "").trim() : "";
              if (!value) return null;
              return currentTicketRows.find(function (ticket) {
                return String(ticket._ID || ticket.id_ticket || ticket.id || "").trim() === value;
              }) || null;
            }
            function isTicketRelated() {
              return relatedTicketSelect && relatedTicketSelect.value === "si";
            }
            function maybeAutofillTitleAndLocation(force) {
              var categoryName = categoryNameFromSelect(categorySelect);
              var ticket = isTicketRelated() ? selectedTicket() : null;
              if (titleInput) {
                var title = buildCalendarTitle(categoryName, ticket);
                if (title && (force || !titleInput.value || titleInput.getAttribute("data-auto-calendar-title") === "1")) {
                  titleInput.value = title;
                  titleInput.setAttribute("data-auto-calendar-title", "1");
                }
              }
              if (locationInput && ticket && ticket.direccion && (force || !locationInput.value || locationInput.getAttribute("data-auto-calendar-location") === "1")) {
                locationInput.value = ticket.direccion;
                locationInput.setAttribute("data-auto-calendar-location", "1");
              }
            }
            function applyRelatedTicketVisibility() {
              var related = isTicketRelated();
              if (ticketFieldWrap) ticketFieldWrap.hidden = !related;
              if (citaFieldWrap) citaFieldWrap.hidden = !related;
              if (!related) {
                if (ticketSelect) ticketSelect.value = "";
                destroyTicketSelect2();
                if (citaSelect) citaSelect.value = "";
              } else if (citaSelect && !citaSelect.value) {
                citaSelect.value = "si";
              }
              if (related) initTicketSelect2();
              applyTicketStateVisibility();
            }
            function applyTicketStateVisibility() {
              var ticket = isTicketRelated() ? selectedTicket() : null;
              var showAdmin = !!ticket;
              if (adminStateWrap) adminStateWrap.hidden = !showAdmin;
              if (!showAdmin && adminStateSelect) adminStateSelect.value = "";
              applyCitaAdminState();
            }
            function isAutoCalendarDescription() {
              if (!descriptionInput) return false;
              var value = String(descriptionInput.value || "").trim();
              return descriptionInput.getAttribute("data-auto-calendar-text") === "1" ||
                value.indexOf("Por medio de la presente") === 0 ||
                value.indexOf("En cumplimiento de") !== -1;
            }
            function clearAutoCalendarDescription() {
              if (!descriptionInput) return;
              if (!descriptionInput.value || isAutoCalendarDescription()) {
                descriptionInput.value = "";
              }
              descriptionInput.setAttribute("data-auto-calendar-text", "0");
            }
            function applyCitaAdminState() {
              if (!adminStateSelect) return;
              if (isTicketRelated() && citaSelect && citaSelect.value === "si") {
                adminStateSelect.value = "Por inspeccionar";
                adminStateSelect.setAttribute("data-forced-cita", "1");
                adminStateSelect.classList.add("is-forced-cita");
                adminStateSelect.title = "Cuando es cita, el estado administrativo queda Por inspeccionar.";
                return;
              }
              adminStateSelect.removeAttribute("data-forced-cita");
              adminStateSelect.classList.remove("is-forced-cita");
              adminStateSelect.removeAttribute("title");
            }
            function refreshTickets() {
              if (mode !== "single") return;
              var selected = selectedEmployees();
              destroyTicketSelect2();
              if (ticketSelect) ticketSelect.innerHTML = '<option value="">Cargando tickets...</option>';
              loadTicketsForEmployees(selected).then(function (rows) {
                currentTicketRows = rows || [];
                renderTicketSelector(ticketSelect, currentTicketRows, "");
                initTicketSelect2();
                maybeAutofillTitleAndLocation(false);
              });
            }
            function refreshAgenda() {
              updatePopupAgenda(agenda, selectedEmployees(), mode === "multiple");
            }
            function maybeAutofillPreventiveDescription() {
              if (!categorySelect || !dateInput || !startInput || !endInput || !descriptionInput) return;
              var selectedOption = categorySelect.options[categorySelect.selectedIndex];
              var categoryName = selectedOption ? selectedOption.textContent : "";
              if (!isCalendarAppointmentCategoryName(categoryName)) return;
              if (relatedTicketSelect && relatedTicketSelect.value !== "si") {
                relatedTicketSelect.value = "si";
                applyRelatedTicketVisibility();
              }
              if (citaSelect && !citaSelect.value) {
                citaSelect.value = "si";
              }
              applyCitaAdminState();
              if (isTicketRelated() && citaSelect && citaSelect.value === "no") {
                clearAutoCalendarDescription();
                return;
              }
              maybeAutofillTitleAndLocation(false);
              if (!dateInput.value || !startInput.value || !endInput.value) return;
              if (descriptionInput.value && descriptionInput.getAttribute("data-auto-calendar-text") !== "1") return;
              descriptionInput.value = buildPreventiveDescription(categoryName, dateInput.value, startInput.value, endInput.value);
              descriptionInput.setAttribute("data-auto-calendar-text", "1");
            }
            function customDateRowHtml() {
              var defaultDate = dateInput ? dateInput.value : "";
              var defaultStart = startInput ? startInput.value : "";
              var defaultEnd = endInput ? endInput.value : "";
              return '<div class="scm-calendar-custom-date-row">' +
                '<input class="input input-bordered input-sm scm-input" type="date" name="custom_fecha" value="' + escHtml(defaultDate) + '">' +
                '<input class="input input-bordered input-sm scm-input" type="time" name="custom_hora_inicio" value="' + escHtml(defaultStart) + '">' +
                '<input class="input input-bordered input-sm scm-input" type="time" name="custom_hora_fin" value="' + escHtml(defaultEnd) + '">' +
                '<button type="button" class="scm-case-work-btn" data-calendar-remove-custom-date>Quitar</button>' +
                '</div>';
            }
            function refreshRecurrenceUi() {
              var active = !!(recurrenceToggle && recurrenceToggle.checked);
              if (recurrenceBody) recurrenceBody.hidden = !active;
              var type = recurrenceType ? recurrenceType.value : "diario";
              if (recurrenceEndWrap) recurrenceEndWrap.hidden = !active || type === "personalizado";
              if (weekPicker) weekPicker.hidden = !active || type !== "semanal";
              if (customDatesWrap) customDatesWrap.hidden = !active || type !== "personalizado";
              if (active && type === "personalizado" && customRows && !customRows.children.length) {
                customRows.insertAdjacentHTML("beforeend", customDateRowHtml());
              }
            }
            if (employeesSelect) {
              employeesSelect.addEventListener("change", function () {
                refreshAgenda();
                refreshTickets();
              });
            }
            if (employeePicker) {
              employeePicker.querySelectorAll("[data-calendar-employee-check]").forEach(function (input) {
                input.addEventListener("change", function () {
                  refreshAgenda();
                  refreshTickets();
                });
              });
            }
            if (employeeSearch) {
              employeeSearch.addEventListener("input", function () {
                var term = normalizeText(employeeSearch.value);
                employeePicker.querySelectorAll("[data-employee-option]").forEach(function (option) {
                  option.style.display = !term || normalizeText(option.getAttribute("data-search-text") || "").indexOf(term) !== -1 ? "" : "none";
                });
              });
            }
            if (ticketSelect) {
              ticketSelect.addEventListener("change", function () {
                maybeAutofillTitleAndLocation(true);
                applyTicketStateVisibility();
                maybeAutofillPreventiveDescription();
              });
            }
            if (relatedTicketSelect) {
              relatedTicketSelect.addEventListener("change", function () {
                applyRelatedTicketVisibility();
                maybeAutofillTitleAndLocation(true);
                maybeAutofillPreventiveDescription();
              });
            }
            if (citaSelect) {
              citaSelect.addEventListener("change", function () {
                if (citaSelect.value === "no") {
                  clearAutoCalendarDescription();
                }
                applyCitaAdminState();
                maybeAutofillPreventiveDescription();
              });
            }
            if (adminStateSelect) {
              adminStateSelect.addEventListener("change", applyCitaAdminState);
            }
            if (titleInput) {
              titleInput.addEventListener("input", function () {
                titleInput.setAttribute("data-auto-calendar-title", "0");
              });
            }
            if (locationInput) {
              locationInput.addEventListener("input", function () {
                locationInput.setAttribute("data-auto-calendar-location", "0");
              });
            }
            [categorySelect, dateInput, startInput, endInput].forEach(function (field) {
              if (field) field.addEventListener("change", function () {
                maybeAutofillTitleAndLocation(false);
                maybeAutofillPreventiveDescription();
              });
            });
            if (recurrenceToggle) recurrenceToggle.addEventListener("change", refreshRecurrenceUi);
            if (recurrenceType) recurrenceType.addEventListener("change", refreshRecurrenceUi);
            if (addCustomDateBtn && customRows) {
              addCustomDateBtn.addEventListener("click", function () {
                customRows.insertAdjacentHTML("beforeend", customDateRowHtml());
              });
              customRows.addEventListener("click", function (e) {
                var removeBtn = e.target && e.target.closest ? e.target.closest("[data-calendar-remove-custom-date]") : null;
                if (removeBtn) {
                  var row = removeBtn.closest(".scm-calendar-custom-date-row");
                  if (row) row.remove();
                }
              });
            }
            if (descriptionInput) {
              descriptionInput.addEventListener("input", function () {
                descriptionInput.setAttribute("data-auto-calendar-text", "0");
              });
            }
            refreshAgenda();
            refreshTickets();
            applyRelatedTicketVisibility();
            refreshRecurrenceUi();
            maybeAutofillTitleAndLocation(false);
            maybeAutofillPreventiveDescription();
          },
          willClose: function () {
            if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)) return;
            var popup = window.Swal.getPopup();
            var select = popup ? popup.querySelector("[data-calendar-ticket-select]") : null;
            if (!select) return;
            var $select = window.jQuery(select);
            if ($select.data("select2")) {
              $select.select2("destroy");
            }
          },
          preConfirm: function () {
            var popup = window.Swal.getPopup();
            var form = popup ? popup.querySelector(".scm-calendar-popup-form") : null;
            if (!form) {
              window.Swal.showValidationMessage("No se encontro el formulario.");
              return false;
            }
            var selected = selectedEmployeesFromPopup(form);
            if (!selected.length) {
              window.Swal.showValidationMessage("Selecciona al menos un funcionario.");
              return false;
            }
            var fd = new FormData(form);
            var relatedTicket = mode === "single" && fd.get("relacionado_ticket") === "si";
            var isCita = relatedTicket ? String(fd.get("es_cita") || "") : "";
            if (!String(fd.get("titulo") || "").trim() || !String(fd.get("ubicacion") || "").trim() || !String(fd.get("id_categoria") || "").trim()) {
              window.Swal.showValidationMessage("Titulo, ubicacion y categoria son obligatorios.");
              return false;
            }
            if (relatedTicket && isCita === "no" && !String(fd.get("descripcion") || "").trim()) {
              window.Swal.showValidationMessage("La descripcion es obligatoria si el evento no es una cita.");
              return false;
            }
            var basePayload = {
              titulo: fd.get("titulo") || "",
              descripcion: fd.get("descripcion") || "",
              ubicacion: fd.get("ubicacion") || "",
              id_categoria: fd.get("id_categoria") || "",
            };
            if (mode === "single") {
              basePayload.id_ticket = relatedTicket ? fd.get("id_ticket") || "" : "";
              basePayload.es_cita = isCita;
              basePayload.estado_administrativo = relatedTicket ? (isCita === "si" ? "Por inspeccionar" : fd.get("estado_administrativo") || "") : "";
            }
            var recurrent = fd.get("es_recurrente") === "1";
            var recurrenceTypeValue = String(fd.get("tipo_recurrencia") || "diario");
            var eventsToCreate = [];
            function makePayload(dateValue, startValue, endValue) {
              return Object.assign({}, basePayload, {
                fecha_inicio: dateValue + " " + startValue + ":00",
                fecha_fin: dateValue + " " + endValue + ":00",
              });
            }
            function addValidatedPayload(dateValue, startValue, endValue) {
              var err = validateCalendarEventTimes(dateValue, startValue, endValue);
              if (err) return err;
              eventsToCreate.push(makePayload(dateValue, startValue, endValue));
              return "";
            }
            if (recurrent && recurrenceTypeValue === "personalizado") {
              var customRowsList = Array.prototype.slice.call(form.querySelectorAll(".scm-calendar-custom-date-row"));
              if (!customRowsList.length) {
                window.Swal.showValidationMessage("Agrega al menos una fecha personalizada.");
                return false;
              }
              for (var customIndex = 0; customIndex < customRowsList.length; customIndex += 1) {
                var row = customRowsList[customIndex];
                var customDate = row.querySelector('[name="custom_fecha"]');
                var customStart = row.querySelector('[name="custom_hora_inicio"]');
                var customEnd = row.querySelector('[name="custom_hora_fin"]');
                var customError = addValidatedPayload(customDate ? customDate.value : "", customStart ? customStart.value : "", customEnd ? customEnd.value : "");
                if (customError) {
                  window.Swal.showValidationMessage(customError);
                  return false;
                }
              }
            } else {
              var baseDate = String(fd.get("fecha") || "");
              var baseStart = String(fd.get("hora_inicio") || "");
              var baseEnd = String(fd.get("hora_fin") || "");
              var baseError = validateCalendarEventTimes(baseDate, baseStart, baseEnd);
              if (baseError) {
                window.Swal.showValidationMessage(baseError);
                return false;
              }
              if (!recurrent) {
                eventsToCreate.push(makePayload(baseDate, baseStart, baseEnd));
              } else {
                var endRecurrence = String(fd.get("fecha_fin_recurrencia") || "");
                if (!endRecurrence) {
                  window.Swal.showValidationMessage("Debes seleccionar una fecha fin para la recurrencia.");
                  return false;
                }
                var current = new Date(baseDate + "T00:00:00");
                var endDate = new Date(endRecurrence + "T00:00:00");
                if (Number.isNaN(endDate.getTime()) || endDate < current) {
                  window.Swal.showValidationMessage("La fecha fin de recurrencia no puede ser menor a la fecha de inicio.");
                  return false;
                }
                var selectedDays = fd.getAll("dias_semana").map(function (value) { return parseInt(value, 10); });
                if (recurrenceTypeValue === "semanal" && !selectedDays.length) {
                  window.Swal.showValidationMessage("Selecciona al menos un dia de la semana.");
                  return false;
                }
                while (current <= endDate) {
                  var shouldAdd = recurrenceTypeValue === "diario" || selectedDays.indexOf(current.getDay()) !== -1;
                  if (shouldAdd) {
                    eventsToCreate.push(makePayload(toDateKey(current), baseStart, baseEnd));
                  }
                  current = addDays(current, 1);
                }
                if (!eventsToCreate.length) {
                  window.Swal.showValidationMessage("No se generaron eventos con la configuracion seleccionada.");
                  return false;
                }
              }
            }
            window.Swal.showLoading();
            var citaNotificationAppointments = [];
            if (mode === "single" && relatedTicket && isCita === "si") {
              var notificationCategoryName = categoryNameFromSelect(categorySelect) || fd.get("id_categoria") || "cita";
              eventsToCreate.forEach(function (eventPayload) {
                selected.forEach(function (employeeId) {
                  citaNotificationAppointments.push(Object.assign({}, eventPayload, {
                    id_ticket: basePayload.id_ticket,
                    id_empleado: employeeId,
                    categoria: notificationCategoryName,
                    titulo: basePayload.titulo,
                    ubicacion: basePayload.ubicacion,
                    es_cita: "si",
                  }));
                });
              });
            }
            var request = selected.length > 1 || eventsToCreate.length > 1
              ? calendarApi("crear_eventos", { eventos: eventsToCreate, empleados: selected })
              : calendarApi("crear_evento", Object.assign({}, eventsToCreate[0], { id_empleado: selected[0] }));
            return request.then(function (json) {
              if (!json || !json.success) throw new Error((json && json.message) || "No se pudo crear el evento.");
              json._scmCitaNotificationAppointments = citaNotificationAppointments;
              return json;
            }).catch(function (err) {
              window.Swal.showValidationMessage(err.message || "No se pudo crear el evento.");
              return false;
            });
          },
        }).then(function (result) {
          if (!result.isConfirmed || !result.value) return;
          showToast("success", result.value.message || "Evento creado.");
          if (Array.isArray(result.value._scmCitaNotificationAppointments) && result.value._scmCitaNotificationAppointments.length) {
            notifyCalendarAppointment(root, result.value._scmCitaNotificationAppointments)
              .then(showCalendarNotificationResult);
          }
          loadEvents();
        });
      }

      function loadFuncionariosFallback() {
        if (allowedEmployees.length) return Promise.resolve(allowedEmployees);
        return calendarApi("listar_funcionarios").then(function (json) {
          var rows = json && json.success && Array.isArray(json.data) ? json.data : [];
          if (allowedCargos.length) {
            rows = rows.filter(function (row) { return allowedCargos.indexOf(String(row.id_cargo || "").trim()) !== -1; });
          }
          allowedEmployees = rows;
          rebuildAllowedEmployeeMap();
          return allowedEmployees;
        });
      }

      calendarBootstrapPromise = Promise.all([loadFuncionariosFallback(), calendarApi("listar_categorias")]).then(function (results) {
        var funcionarios = Array.isArray(results[0]) ? results[0] : [];
        categories = results[1] && results[1].success && Array.isArray(results[1].data) ? results[1].data : [];
        categoriesById = {};
        categories.forEach(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          if (id) categoriesById[id] = row;
        });
        fillEmployeeOptions(Array.prototype.slice.call(panel.querySelectorAll("[data-scm-calendar-filter-employees]")), funcionarios, "Selecciona funcionario");
        fillCategoryOptions(panel.querySelector("[data-scm-calendar-filter-categories]"), calendarAdminCategories(), "Todas");
      }).catch(function (err) {
        showToast("error", (err && err.message) || "No se pudieron cargar los funcionarios del calendario.");
      }).finally(loadEvents);

      if (filterForm) {
        filterForm.addEventListener("submit", function (e) {
          e.preventDefault();
          loadEvents();
        });
        filterForm.querySelectorAll("select, input").forEach(function (field) {
          field.addEventListener("change", loadEvents);
        });
      }

      var clearBtn = panel.querySelector("[data-scm-calendar-clear]");
      if (clearBtn && filterForm) {
        clearBtn.addEventListener("click", function () {
          filterForm.reset();
          loadEvents();
        });
      }
      var refreshBtn = panel.querySelector("[data-scm-calendar-refresh]");
      if (refreshBtn) refreshBtn.addEventListener("click", loadEvents);

      var reportBtn = panel.querySelector("[data-scm-calendar-open-report]");
      if (reportBtn) reportBtn.addEventListener("click", openCalendarReport);
      var pendingEventsBtn = panel.querySelector("[data-scm-calendar-open-pending]");
      if (pendingEventsBtn) pendingEventsBtn.addEventListener("click", openPendingEventsPopup);

      panel.addEventListener("click", function (e) {
        var completeBtn = e.target && e.target.closest ? e.target.closest("[data-scm-calendar-complete-event]") : null;
        if (completeBtn && panel.contains(completeBtn)) {
          e.preventDefault();
          openCompleteEventPopup(completeBtn.getAttribute("data-event-id") || "");
          return;
        }
        var rescheduleBtn = e.target && e.target.closest ? e.target.closest("[data-scm-calendar-reschedule-event]") : null;
        if (!rescheduleBtn || !panel.contains(rescheduleBtn)) return;
        e.preventDefault();
        openRescheduleEventPopup(rescheduleBtn.getAttribute("data-event-id") || "");
      });

      var prevBtn = panel.querySelector("[data-scm-calendar-prev]");
      var nextBtn = panel.querySelector("[data-scm-calendar-next]");
      var todayBtn = panel.querySelector("[data-scm-calendar-today-btn]");
      if (prevBtn) {
        prevBtn.addEventListener("click", function () {
          currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
          selectedDay = toDateKey(currentMonth);
          loadEvents();
        });
      }
      if (nextBtn) {
        nextBtn.addEventListener("click", function () {
          currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
          selectedDay = toDateKey(currentMonth);
          loadEvents();
        });
      }
      if (todayBtn) {
        todayBtn.addEventListener("click", function () {
          currentMonth = startOfMonth(new Date());
          selectedDay = toDateKey(new Date());
          loadEvents();
        });
      }
      panel.querySelectorAll("[data-scm-calendar-open-create]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var mode = btn.getAttribute("data-calendar-mode") || "single";
          if (allowedEmployees.length) {
            openCreateEventPopup(mode);
            return;
          }
          withPanelLoader(
            function () {
              return calendarBootstrapPromise || loadFuncionariosFallback();
            },
            "Cargando funcionarios",
            "Estamos consultando los funcionarios disponibles.",
          ).then(function () {
            if (!allowedEmployees.length) {
              showToast("error", "No fue posible cargar funcionarios para crear el evento.");
              return;
            }
            openCreateEventPopup(mode);
          });
        });
      });
    }

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

    var adminNotificationsPanelPromise = null;

    function loadAdminNotificationsPanel() {
      var host = root.querySelector("#scm-panel-admin-notificaciones");
      if (!host || !actionAdminNotificationsPanel || !ajaxUrl) {
        return Promise.resolve();
      }
      if (host.getAttribute("data-scm-loaded") === "1") {
        return initAdminNotificationsPanel(false);
      }
      if (adminNotificationsPanelPromise) {
        return adminNotificationsPanelPromise;
      }
      host.classList.add("is-loading");
      host.setAttribute("aria-busy", "true");
      var fd = new FormData();
      fd.append("action", actionAdminNotificationsPanel);
      fd.append("nonce", nonce);
      adminNotificationsPanelPromise = fetchWithTimeout(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success || !json.data) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo cargar el módulo de notificaciones.",
            );
          }
          host.innerHTML = json.data.html || "";
          host.setAttribute("data-scm-loaded", "1");
          return initAdminNotificationsPanel(true);
        })
        .catch(function (error) {
          adminNotificationsPanelPromise = null;
          host.setAttribute("data-scm-loaded", "0");
          host.innerHTML =
            '<div class="scm-admin-notif-empty is-error" role="alert">' +
            "<strong>No pudimos cargar Notificaciones.</strong>" +
            "<span>" + escHtml(error.message || "Error desconocido") + "</span>" +
            '<button type="button" class="scm-btn-primary btn btn-primary" data-scm-admin-notifications-retry>Reintentar</button>' +
            "</div>";
          showToast("error", error.message || "No se pudo cargar Notificaciones.");
        })
        .finally(function () {
          host.classList.remove("is-loading");
          host.removeAttribute("aria-busy");
        });
      return adminNotificationsPanelPromise;
    }

    function initAdminNotificationsPanel(forceReload) {
      var panel = root.querySelector("[data-scm-admin-notifications]");
      if (!panel || !actionAdminNotificationsRecipients || !actionAdminNotificationsSend) {
        return Promise.resolve();
      }
      var searchForm = panel.querySelector("[data-admin-notif-search]");
      var importForm = panel.querySelector("[data-admin-notif-import]");
      var importWrap = panel.querySelector("[data-admin-notif-import-wrap]");
      var importFileInput = panel.querySelector("[data-admin-notif-import-file]");
      var importClearBtn = panel.querySelector("[data-admin-notif-import-clear]");
      var importResultEl = panel.querySelector("[data-admin-notif-import-result]");
      var sendForm = panel.querySelector("[data-admin-notif-send]");
      var typeSelect = panel.querySelector("[data-admin-notif-type]");
      var queryInput = panel.querySelector("[data-admin-notif-query]");
      var nameInput = panel.querySelector("[data-admin-notif-name]");
      var emailInput = panel.querySelector("[data-admin-notif-email]");
      var phoneInput = panel.querySelector("[data-admin-notif-phone]");
      var documentInput = panel.querySelector("[data-admin-notif-document]");
      var contractStatusWrap = panel.querySelector("[data-admin-notif-contract-status-wrap]");
      var contractExtraWraps = panel.querySelectorAll("[data-admin-notif-contract-extra-wrap]");
      var contractStatusSelect = panel.querySelector("[data-admin-notif-contract-status]");
      var inmuebleSimiInput = panel.querySelector("[data-admin-notif-inmueble-simi]");
      var contractNumberInput = panel.querySelector("[data-admin-notif-contract-number]");
      var sendType = panel.querySelector("[data-admin-notif-send-type]");
      var sendQuery = panel.querySelector("[data-admin-notif-send-query]");
      var sendName = panel.querySelector("[data-admin-notif-send-name]");
      var sendEmail = panel.querySelector("[data-admin-notif-send-email]");
      var sendPhone = panel.querySelector("[data-admin-notif-send-phone]");
      var sendDocument = panel.querySelector("[data-admin-notif-send-document]");
      var sendContractStatus = panel.querySelector("[data-admin-notif-send-contract-status]");
      var sendInmuebleSimi = panel.querySelector("[data-admin-notif-send-inmueble-simi]");
      var sendContractNumber = panel.querySelector("[data-admin-notif-send-contract-number]");
      var sendImportPayload = panel.querySelector("[data-admin-notif-import-payload]");
      var recipientsEl = panel.querySelector("[data-admin-notif-recipients]");
      var paginationEl = panel.querySelector("[data-admin-notif-pagination]");
      var totalEl = panel.querySelector("[data-admin-notif-total]");
      var listTitle = panel.querySelector("[data-admin-notif-list-title]");
      var selectedCountEl = panel.querySelector("[data-admin-notif-selected-count]");
      var selectVisibleBtn = panel.querySelector("[data-admin-notif-select-visible]");
      var allFiltered = panel.querySelector("[data-admin-notif-all-filtered]");
      var allFilteredWrap = allFiltered ? allFiltered.closest(".scm-admin-notif-all") : null;
      var subjectWrap = panel.querySelector("[data-admin-notif-subject-wrap]");
      var subjectInput = panel.querySelector("[data-admin-notif-subject]");
      var messageFieldWrap = panel.querySelector(".scm-admin-notif-message-field");
      var noMessageNote = panel.querySelector("[data-admin-notif-no-message-note]");
      var messageInput = panel.querySelector("[data-admin-notif-message]");
      var emailTemplateSelect = panel.querySelector("[data-admin-notif-email-template]");
      var whatsappTemplateWrap = panel.querySelector("[data-admin-notif-whatsapp-template-wrap]");
      var whatsappTemplateSelect = panel.querySelector("[data-admin-notif-whatsapp-template]");
      var previewEl = panel.querySelector("[data-admin-notif-preview]");
      var smsCounter = panel.querySelector("[data-admin-notif-sms-counter]");
      var submitBtn = panel.querySelector("[data-admin-notif-submit]");
      var spinner = panel.querySelector("[data-admin-notif-spinner]");
      var resultEl = panel.querySelector("[data-admin-notif-result]");
      var composerModal = panel.querySelector("[data-admin-notif-modal]");
      var composerTitle = panel.querySelector("#scm-admin-notif-modal-title");
      var composerDescription = panel.querySelector("[data-admin-notif-modal-description]");
      var channelGroup = panel.querySelector(".scm-admin-notif-channel-group");
      var closeComposerBtn = panel.querySelector("[data-admin-notif-close-composer]");
      var closeConfirmModal = panel.querySelector("[data-admin-notif-confirm]");
      var closeConfirmAcceptBtn = panel.querySelector("[data-admin-notif-confirm-accept]");
      var closeConfirmCancelBtn = panel.querySelector("[data-admin-notif-confirm-cancel]");
      var openCollectionBtn = panel.querySelector("[data-admin-notif-open-collection]");
      var collectionModal = panel.querySelector("[data-admin-notif-collection-modal]");
      var closeCollectionBtn = panel.querySelector("[data-admin-notif-close-collection]");
      var collectionForm = panel.querySelector("[data-admin-notif-collection]");
      var collectionSelectedCountEl = panel.querySelector("[data-admin-notif-collection-selected-count]");
      var collectionContractWrap = panel.querySelector("[data-admin-notif-collection-contract-wrap]");
      var collectionContractSelect = panel.querySelector("[data-admin-notif-collection-contract]");
      var collectionTypeSelect = panel.querySelector("#scm-admin-notif-collection-type");
      var collectionObservationInput = panel.querySelector("#scm-admin-notif-collection-observation");
      var collectionCodeudoresWrap = panel.querySelector("[data-admin-notif-collection-codeudores-wrap]");
      var collectionCodeudoresList = panel.querySelector("[data-admin-notif-collection-codeudores-list]");
      var collectionCodeudorCountEl = panel.querySelector("[data-admin-notif-collection-codeudor-count]");
      var collectionCodeudoresSelectAllBtn = panel.querySelector("[data-admin-notif-codeudores-select-all]");
      var collectionCodeudoresClearBtn = panel.querySelector("[data-admin-notif-codeudores-clear]");
      var collectionPreviewToggle = panel.querySelector("[data-admin-notif-collection-preview-toggle]");
      var collectionPreviewEl = panel.querySelector("[data-admin-notif-collection-preview]");
      var collectionSpinner = panel.querySelector("[data-admin-notif-collection-spinner]");
      var collectionSubmitBtn = panel.querySelector("[data-admin-notif-collection-submit]");
      var collectionResultEl = panel.querySelector("[data-admin-notif-collection-result]");
      if (!searchForm || !sendForm || !typeSelect || !recipientsEl) {
        return Promise.resolve();
      }

      if (panel.dataset.scmAdminNotificationsInit === "1" && !forceReload) {
        return Promise.resolve();
      }
      panel.dataset.scmAdminNotificationsInit = "1";

      var selected = new Set();
      var currentPage = 1;
      var recipientsRequestId = 0;
      var composerDirty = false;
      var subjectDirty = false;
      var composerChannelMode = "";
      var composerSingleRecipient = false;
      var importedPayload = {};
      var selectedCollectionContracts = [];

      var channelNames = {
        email: "Email",
        sms: "SMS",
        whatsapp: "WhatsApp",
      };

      function currentType() {
        return typeSelect ? String(typeSelect.value || "propietarios") : "propietarios";
      }

      function currentQuery() {
        return queryInput ? String(queryInput.value || "").trim() : "";
      }

      function currentNameFilter() {
        return nameInput ? String(nameInput.value || "").trim() : "";
      }

      function currentEmailFilter() {
        return emailInput ? String(emailInput.value || "").trim() : "";
      }

      function currentPhoneFilter() {
        return phoneInput ? String(phoneInput.value || "").trim() : "";
      }

      function currentDocumentFilter() {
        return documentInput ? String(documentInput.value || "").trim() : "";
      }

      function currentTypeLabel() {
        var active = panel.querySelector("[data-admin-notif-type-shortcut].active");
        var label = active ? active.querySelector("span") : null;
        if (label) {
          return String(label.textContent || "").trim();
        }
        if (active) {
          return String(active.textContent || "").trim();
        }
        if (typeSelect && typeSelect.options && typeSelect.selectedIndex >= 0) {
          return String(typeSelect.options[typeSelect.selectedIndex].text || "").trim();
        }
        return "";
      }

      function supportsContractStatus(type) {
        return /^(propietarios|arrendatarios|copropiedades)/.test(String(type || ""));
      }

      function resetImportState(clearFile) {
        importedPayload = {};
        if (sendImportPayload) {
          sendImportPayload.value = "";
        }
        if (importResultEl) {
          importResultEl.textContent = "";
          importResultEl.classList.remove("is-error", "is-success");
        }
        if (clearFile && importFileInput) {
          importFileInput.value = "";
        }
      }

      function currentContractStatus() {
        if (!contractStatusSelect || !supportsContractStatus(currentType())) {
          return "";
        }
        return String(contractStatusSelect.value || "").trim();
      }

      function currentInmuebleSimi() {
        if (!inmuebleSimiInput || !supportsContractStatus(currentType())) {
          return "";
        }
        return String(inmuebleSimiInput.value || "").trim();
      }

      function currentContractNumber() {
        if (!contractNumberInput || !supportsContractStatus(currentType())) {
          return "";
        }
        return String(contractNumberInput.value || "").trim();
      }

      function selectedMessageTemplateOption() {
        return whatsappTemplateSelect && whatsappTemplateSelect.options
          ? whatsappTemplateSelect.options[whatsappTemplateSelect.selectedIndex]
          : null;
      }

      function messageTemplateAllowedForType(option, type) {
        if (!option) {
          return false;
        }
        var actors = String(option.getAttribute("data-actors") || "")
          .split(",")
          .map(function (value) {
            return value.trim();
          })
          .filter(Boolean);
        return actors.length === 0 || actors.indexOf(String(type || currentType())) !== -1;
      }

      function filterMessageTemplateOptions() {
        if (!whatsappTemplateSelect || !whatsappTemplateSelect.options) {
          return;
        }
        var type = currentType();
        var firstAllowed = null;
        Array.prototype.slice.call(whatsappTemplateSelect.options).forEach(function (option) {
          var allowed = messageTemplateAllowedForType(option, type);
          option.disabled = !allowed;
          option.hidden = !allowed;
          if (allowed && !firstAllowed) {
            firstAllowed = option;
          }
        });
        var current = selectedMessageTemplateOption();
        if (current && current.disabled && firstAllowed) {
          whatsappTemplateSelect.value = firstAllowed.value;
          subjectDirty = false;
        }
      }

      function syncEmailTemplateFromMessageTemplate() {
        if (!emailTemplateSelect) {
          return;
        }
        var option = selectedMessageTemplateOption();
        if (!option) {
          return;
        }
        var emailTemplate = option.getAttribute("data-email-template") || emailTemplateSelect.value || "";
        emailTemplateSelect.value = emailTemplate;
        emailTemplateSelect.setAttribute("data-subject", option.getAttribute("data-email-subject") || "");
        emailTemplateSelect.setAttribute("data-message", option.getAttribute("data-email-message") || "");
        emailTemplateSelect.setAttribute("data-preview-template", option.getAttribute("data-email-preview-template") || "{{mensaje}}");
        emailTemplateSelect.setAttribute("data-requires-message", option.getAttribute("data-email-requires-message") || "0");
        emailTemplateSelect.setAttribute("data-message-only", option.getAttribute("data-email-message-only") || "0");
        emailTemplateSelect.setAttribute("data-template-html", option.getAttribute("data-email-template-html") || "1");
        emailTemplateSelect.setAttribute("data-template-fixed", option.getAttribute("data-email-template-fixed") || "0");
        emailTemplateSelect.setAttribute("data-template-full", option.getAttribute("data-email-template-full") || "0");
        if (subjectInput && !subjectDirty) {
          subjectInput.value = option.getAttribute("data-email-subject") || subjectInput.value || "";
        }
      }

      function senderProfile() {
        var signature = panel.getAttribute("data-admin-notif-sender-signature") || "";
        var name = panel.getAttribute("data-admin-notif-sender-name") || "Funcionario";
        var cargo = panel.getAttribute("data-admin-notif-sender-cargo") || "Control Servicios Inmobiliarios";
        var phone = panel.getAttribute("data-admin-notif-sender-phone") || "";
        var signatureLine = [name, cargo, phone ? "Cel. " + phone : ""].filter(Boolean).join(" - ");
        return {
          name: name,
          cargo: cargo,
          phone: phone,
          signature: signature || "Atentamente,\n" + signatureLine,
          signatureLine: signatureLine,
        };
      }

      function smsPrefix() {
        return panel.getAttribute("data-admin-notif-sms-prefix") || "SKC SuCasa Inmobiliaria ";
      }

      function setLoading(isLoading) {
        panel.classList.toggle("is-loading", !!isLoading);
        if (spinner) {
          spinner.classList.toggle("active", !!isLoading);
        }
        if (submitBtn) {
          submitBtn.disabled = !!isLoading;
        }
      }

      function setCollectionLoading(isLoading) {
        if (collectionSpinner) {
          collectionSpinner.classList.toggle("active", !!isLoading);
        }
        if (collectionSubmitBtn) {
          collectionSubmitBtn.disabled = !!isLoading;
        }
      }

      function markComposerDirty() {
        composerDirty = true;
      }

      function updateComposerMode() {
        var mode = String(composerChannelMode || "").trim().toLowerCase();
        var isChannelMode = !!mode;
        var label = channelNames[mode] || "notificación";
        if (composerModal) {
          composerModal.classList.toggle("is-channel-specific", isChannelMode);
          composerModal.classList.toggle("is-single-recipient", composerSingleRecipient);
          composerModal.setAttribute("data-admin-notif-channel-mode", mode);
        }
        if (channelGroup) {
          channelGroup.hidden = isChannelMode;
        }
        if (allFilteredWrap) {
          allFilteredWrap.hidden = composerSingleRecipient;
          allFilteredWrap.classList.toggle("is-hidden", composerSingleRecipient);
        }
        if (composerTitle) {
          composerTitle.textContent = isChannelMode
            ? "Enviar notificación por " + label
            : composerSingleRecipient
            ? "Enviar notificación individual"
            : "Enviar notificación";
        }
        if (composerDescription) {
          composerDescription.textContent = isChannelMode
            ? "Vista dedicada para " + label + ". Puedes cambiar a todos los canales antes de encolar."
            : composerSingleRecipient
            ? "Notificación para un solo destinatario. Escoge plantilla, canales y revisa la vista previa."
            : "Prepara el mensaje, elige canales y revisa la vista previa antes de encolar. Si hay texto escrito, el cierre pide confirmación.";
        }
      }

      function openComposer() {
        if (!composerModal) {
          return;
        }
        updateComposerMode();
        composerModal.hidden = false;
        composerModal.classList.add("is-open");
        document.body.classList.add("scm-admin-notif-modal-open");
        syncContext();
        setTimeout(function () {
          if (messageInput && !(messageFieldWrap && messageFieldWrap.hidden)) {
            messageInput.focus();
          } else if (whatsappTemplateSelect && !(whatsappTemplateWrap && whatsappTemplateWrap.hidden)) {
            whatsappTemplateSelect.focus();
          } else if (subjectInput && subjectWrap && subjectWrap.style.display !== "none") {
            subjectInput.focus();
          }
        }, 30);
      }

      function hideCloseConfirm() {
        if (!closeConfirmModal) {
          return;
        }
        closeConfirmModal.hidden = true;
        closeConfirmModal.classList.remove("is-open");
      }

      function showCloseConfirm() {
        if (!closeConfirmModal) {
          return false;
        }
        closeConfirmModal.hidden = false;
        closeConfirmModal.classList.add("is-open");
        setTimeout(function () {
          if (closeConfirmCancelBtn) {
            closeConfirmCancelBtn.focus();
          }
        }, 20);
        return true;
      }

      function performCloseComposer() {
        if (!composerModal) {
          return;
        }
        hideCloseConfirm();
        composerModal.hidden = true;
        composerModal.classList.remove("is-open");
        composerChannelMode = "";
        composerSingleRecipient = false;
        updateComposerMode();
        document.body.classList.remove("scm-admin-notif-modal-open");
      }

      function closeComposer(force) {
        if (!composerModal) {
          return;
        }
        if (!force && composerDirty && showCloseConfirm()) {
          return;
        }
        performCloseComposer();
      }

      function openComposerForChannel(channel, options) {
        var wanted = String(channel || "").trim().toLowerCase();
        if (["email", "sms", "whatsapp"].indexOf(wanted) === -1) {
          return;
        }
        var opts = options || {};
        composerSingleRecipient = !!opts.singleRecipient;
        composerChannelMode = wanted;
        var changed = false;
        panel.querySelectorAll("[data-admin-notif-channel]").forEach(function (input) {
          var shouldCheck = String(input.value || "").toLowerCase() === wanted;
          if (input.checked !== shouldCheck) {
            changed = true;
          }
          input.checked = shouldCheck;
        });
        if (allFiltered) {
          allFiltered.checked = false;
        }
        if (changed) {
          markComposerDirty();
        }
        syncContext();
        openComposer();
      }

      function openComposerForAllChannels(options) {
        var opts = options || {};
        composerSingleRecipient = !!opts.singleRecipient;
        composerChannelMode = "";
        var changed = false;
        panel.querySelectorAll("[data-admin-notif-channel]").forEach(function (input) {
          if (!input.checked) {
            changed = true;
          }
          input.checked = true;
        });
        if (allFiltered) {
          allFiltered.checked = false;
        }
        if (changed) {
          markComposerDirty();
        }
        syncContext();
        openComposer();
      }

      function loadCollectionOptions() {
        if (!actionAdminNotificationsCollectionOptions) {
          return Promise.reject(new Error("La consulta de contratos y codeudores no está disponible."));
        }
        if (selected.size === 0) {
          return Promise.reject(new Error("Selecciona al menos un arrendatario activo."));
        }
        var fd = new FormData();
        fd.set("action", actionAdminNotificationsCollectionOptions);
        fd.set("nonce", nonce);
        selected.forEach(function (id) {
          fd.append("ids[]", id);
        });
        return fetchWithTimeout(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (response) {
            return response.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudieron cargar los contratos y codeudores.",
              );
            }
            return Array.isArray(json.data && json.data.contracts)
              ? json.data.contracts
              : [];
          });
      }

      function activeCollectionContract() {
        if (!Array.isArray(selectedCollectionContracts) || selectedCollectionContracts.length === 0) {
          return null;
        }
        var selectedContractId = collectionContractSelect
          ? String(collectionContractSelect.value || "").trim()
          : "";
        if (!selectedContractId && selectedCollectionContracts.length === 1) {
          selectedContractId = String(selectedCollectionContracts[0].id || "").trim();
        }
        if (!selectedContractId) {
          return null;
        }
        return selectedCollectionContracts.find(function (contract) {
          return String((contract && contract.id) || "").trim() === selectedContractId;
        }) || null;
      }

      function selectedCollectionCodeudorInputs() {
        if (!collectionCodeudoresList) {
          return [];
        }
        return Array.prototype.slice.call(
          collectionCodeudoresList.querySelectorAll("[data-admin-notif-codeudor-key]:checked"),
        );
      }

      function updateCollectionCodeudorCounter() {
        var total = collectionCodeudoresList
          ? collectionCodeudoresList.querySelectorAll("[data-admin-notif-codeudor-key]").length
          : 0;
        var selectedCount = selectedCollectionCodeudorInputs().length;
        if (collectionCodeudorCountEl) {
          collectionCodeudorCountEl.textContent = "Codeudores: " + selectedCount + " de " + total + " seleccionados";
        }
      }

      function renderCollectionCodeudores() {
        if (!collectionCodeudoresWrap || !collectionCodeudoresList) {
          updateCollectionCodeudorCounter();
          return;
        }
        var activeContract = activeCollectionContract();
        var contracts = selected.size > 1
          ? selectedCollectionContracts
          : activeContract
          ? [activeContract]
          : [];
        var seenCodeudores = {};
        var codeudores = [];
        contracts.forEach(function (contract) {
          (contract && Array.isArray(contract.codeudores) ? contract.codeudores : []).forEach(function (codeudor) {
            var key = String((codeudor && codeudor.key) || "").trim();
            if (!key || seenCodeudores[key]) return;
            seenCodeudores[key] = true;
            codeudores.push({
              data: codeudor,
              contractLabel: String((contract && contract.label) || "Contrato").trim(),
            });
          });
        });
        collectionCodeudoresList.innerHTML = "";
        if (contracts.length === 0) {
          collectionCodeudoresWrap.hidden = true;
          collectionCodeudoresWrap.classList.add("is-hidden");
          updateCollectionCodeudorCounter();
          return;
        }
        collectionCodeudoresWrap.hidden = false;
        collectionCodeudoresWrap.classList.remove("is-hidden");
        if (codeudores.length === 0) {
          collectionCodeudoresList.innerHTML =
            '<div class="scm-admin-notif-codeudores-empty">Sin codeudores registrados para los contratos seleccionados.</div>';
          updateCollectionCodeudorCounter();
          return;
        }
        collectionCodeudoresList.innerHTML = codeudores.map(function (item, index) {
          var codeudor = item.data || {};
          var key = String((codeudor && codeudor.key) || "").trim();
          var name = String((codeudor && codeudor.nombre) || ("Codeudor " + (index + 1))).trim();
          var email = String((codeudor && codeudor.correo) || "").trim();
          var phone = String((codeudor && codeudor.celular) || "").trim();
          var documentValue = String((codeudor && codeudor.documento) || "").trim();
          var address = String((codeudor && codeudor.direccion) || "").trim();
          var source = String((codeudor && codeudor.fuente) || "Contrato").trim();
          var contactBits = [];
          contactBits.push(email ? "Email: " + email : "Sin correo");
          contactBits.push(phone ? "Celular: " + phone : "Sin celular");
          if (documentValue) contactBits.push("Documento: " + documentValue);
          if (address) contactBits.push("Dirección: " + address);
          return (
            '<label class="scm-admin-notif-codeudor-card">' +
            '<input type="checkbox" name="notify_codeudor_keys[]" value="' + escAttr(key) + '" data-admin-notif-codeudor-key checked>' +
            '<span class="scm-admin-notif-codeudor-info">' +
            '<strong>' + escHtml(name) + '</strong>' +
            '<small>' + escHtml(contactBits.join(" · ")) + '</small>' +
            '<em>' + escHtml(item.contractLabel + " · " + source) + '</em>' +
            '</span>' +
            '</label>'
          );
        }).join("");
        updateCollectionCodeudorCounter();
      }

      function syncCollectionContracts(contracts) {
        selectedCollectionContracts = Array.isArray(contracts) ? contracts : [];
        if (!collectionContractWrap || !collectionContractSelect) {
          renderCollectionCodeudores();
          return;
        }
        collectionContractSelect.innerHTML = "";
        if (selected.size > 1) {
          collectionContractWrap.hidden = true;
          collectionContractWrap.classList.add("is-hidden");
          renderCollectionCodeudores();
          updateCollectionPreview();
          return;
        }
        if (selectedCollectionContracts.length <= 1) {
          collectionContractWrap.hidden = true;
          collectionContractWrap.classList.add("is-hidden");
          if (selectedCollectionContracts.length === 1 && selectedCollectionContracts[0].id) {
            var option = document.createElement("option");
            option.value = String(selectedCollectionContracts[0].id || "");
            option.textContent = selectedCollectionContracts[0].label || ("Contrato #" + option.value);
            option.selected = true;
            collectionContractSelect.appendChild(option);
          }
          renderCollectionCodeudores();
          updateCollectionPreview();
          return;
        }
        selectedCollectionContracts.forEach(function (contract) {
          var option = document.createElement("option");
          option.value = String(contract.id || "");
          option.textContent = contract.label || ("Contrato #" + option.value);
          collectionContractSelect.appendChild(option);
        });
        collectionContractWrap.hidden = false;
        collectionContractWrap.classList.remove("is-hidden");
        renderCollectionCodeudores();
        updateCollectionPreview();
      }

      function collectionSelectedChannels() {
        return Array.prototype.slice.call(
          panel.querySelectorAll("[data-admin-notif-collection-channel]:checked"),
        ).map(function (input) {
          return String(input.value || "").trim().toLowerCase();
        }).filter(Boolean);
      }

      function collectionDetailText() {
        var type = collectionTypeSelect ? String(collectionTypeSelect.value || "Canon").trim() : "Canon";
        var observation = collectionObservationInput ? String(collectionObservationInput.value || "").trim() : "";
        var typeLabel = /servicio/i.test(type)
          ? "Servicios públicos"
          : /admin/i.test(type)
          ? "Administración"
          : type || "Canon";
        var concept = typeLabel === "Canon"
          ? "canon"
          : typeLabel === "Administración"
          ? "administración"
          : typeLabel === "Servicios públicos"
          ? "servicios públicos"
          : String(typeLabel || "").toLowerCase();
        var lines = ["Cobro por deuda de " + concept + "."];
        lines.push("Observación: " + (observation || "Detalle de la gestión escrito por el funcionario.") + ".");
        return lines.join(" ");
      }

      function collectionSmsText(detail) {
        return smsPrefix() + "Buen día, Nombre del destinatario. " + previewMessage(detail || collectionDetailText());
      }

      function renderCollectionWhatsappPreviewText(detailOverride) {
        var sender = senderProfile();
        var signature = sender.signatureLine || "Funcionario - Control Servicios Inmobiliarios";
        var detail = detailOverride || collectionDetailText();
        return (
          "Buen dia, Nombre del destinatario.\n\n" +
          "Le informamos que se registro una gestion de cobro relacionada con su contrato de arrendamiento.\n\n" +
          "Detalle de la gestion:\n\n" +
          previewMessage(detail) +
          "\n\nSi ya realizo el pago o tiene alguna novedad, por favor comuniquese con nosotros.\n\n" +
          "Atentamente,\n" +
          signature +
          "\n\nGracias por su atencion."
        );
      }

      function renderCollectionEmailPreviewHtml(detailOverride) {
        var detail = detailOverride || collectionDetailText();
        var content = (
          "<p><strong>Buen d&iacute;a, Nombre del destinatario</strong>.</p>" +
          "<p>Le informamos que se registr&oacute; una gesti&oacute;n de cobro relacionada con su contrato de arrendamiento.</p>" +
          "<div>" + escHtml(previewMessage(detail)).replace(/\n/g, "<br>") + "</div>" +
          "<p>Si ya realiz&oacute; el pago o tiene alguna novedad, por favor comun&iacute;quese con nosotros.</p>" +
          '<p style="margin-top:24px;">Atentamente,<br><strong>' +
          escHtml(senderProfile().signatureLine || "Funcionario - Control Servicios Inmobiliarios") +
          "</strong></p>"
        );
        return wrapEmailPreviewHtml("Gestion de cobro de contrato de arrendamiento", content);
      }

      function renderCollectionPreviewCards(channels, detail) {
        var normalizedChannels = (Array.isArray(channels) ? channels : []).map(function (channel) {
          return String(channel || "").trim().toLowerCase();
        }).filter(Boolean);
        var cleanDetail = detail || collectionDetailText();
        var previewDetail = previewMessage(cleanDetail);
        var smsText = collectionSmsText(previewDetail);
        var smsLimit = 480;
        return normalizedChannels.map(function (channel) {
          if (channel === "email") {
            return (
              '<article class="scm-admin-notif-preview-card is-email">' +
              "<h5>Email / HTML</h5>" +
              '<iframe class="scm-admin-notif-email-frame" sandbox srcdoc="' +
              escAttr(renderCollectionEmailPreviewHtml(cleanDetail)) +
              '"></iframe>' +
              "</article>"
            );
          }
          if (channel === "sms") {
            return (
              '<article class="scm-admin-notif-preview-card is-sms' +
              (smsText.length > smsLimit ? " is-over" : "") +
              '">' +
              "<h5>SMS</h5>" +
              '<p class="scm-admin-notif-preview-text">' +
              escHtml(smsText) +
              "</p><small>" +
              smsText.length +
              "/" + smsLimit + " caracteres incluyendo la marca.</small></article>"
            );
          }
          return (
            '<article class="scm-admin-notif-preview-card is-whatsapp">' +
            "<h5>WhatsApp oficial</h5>" +
            '<p class="scm-admin-notif-preview-text">' +
            escHtml(renderCollectionWhatsappPreviewText(cleanDetail)).replace(/\n/g, "<br>") +
            "</p></article>"
          );
        }).join("");
      }

      function openCollectionQueuedPreview(channels, detail, stats) {
        var cards = renderCollectionPreviewCards(channels, detail);
        var info = stats || {};
        var queued = Number(info.queued || 0);
        var failed = Number(info.failed || 0);
        var invalid = Number(info.invalid || 0);
        var statusTitle = queued > 0 ? "Mensaje encolado" : "Notificación no encolada";
        var statusCopy = queued > 0
          ? queued + " notificación(es) quedaron en cola."
          : "No se creó ninguna notificación en la cola. Revisa canales, correo/celular o plantilla.";
        if (failed > 0 || invalid > 0) {
          statusCopy += " Fallidas: " + failed + ". Inválidas: " + invalid + ".";
        }
        if (!cards) {
          showToast("warning", "No se marco ningun canal para notificar.");
          return;
        }
        if (window.Swal && typeof window.Swal.fire === "function") {
          window.Swal.fire({
            title: statusTitle,
            html:
              '<div class="scm-admin-notif-preview scm-admin-notif-queued-preview"><strong>' +
              escHtml(statusCopy) +
              '</strong><div>' +
              cards +
              "</div></div>",
            width: 900,
            confirmButtonText: "Cerrar",
            customClass: { confirmButton: "scm-btn-primary" },
          });
          return;
        }
        showToast("info", "Mensaje preparado: " + detail);
      }

      function openCollectionQueueFromLog(managementId) {
        managementId = String(managementId || "").replace(/\D+/g, "");
        if (!managementId) {
          showToast("error", "Gestion de cobro invalida.");
          return;
        }
        if (!actionAdminNotificationsCollectionQueue) {
          showToast("error", "La consulta de notificaciones no esta disponible.");
          return;
        }
        var fd = new FormData();
        fd.set("action", actionAdminNotificationsCollectionQueue);
        fd.set("nonce", nonce);
        fd.set("management_id", managementId);
        showToast("info", "Consultando notificaciones de la gestion...");
        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (response) {
            return response.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudieron consultar las notificaciones.",
              );
            }
            var data = json.data || {};
            var stats = data.stats || {};
            var total = Number(stats.total || 0);
            var html =
              '<div class="scm-collection-queue-modal">' +
              '<div class="scm-collection-queue-summary" aria-label="Resumen de notificaciones">' +
              '<article><span>Total</span><strong>' +
              escHtml(String(total)) +
              '</strong></article><article><span>Pendientes</span><strong>' +
              escHtml(String(Number(stats.pending || 0))) +
              '</strong></article><article><span>Enviadas</span><strong>' +
              escHtml(String(Number(stats.sent || 0))) +
              '</strong></article><article><span>Fallidas</span><strong>' +
              escHtml(String(Number(stats.failed || 0))) +
              "</strong></article></div>" +
              (data.html || "") +
              "</div>";
            if (window.Swal && typeof window.Swal.fire === "function") {
              window.Swal.fire({
                title: "Notificaciones de la gestión #" + managementId,
                html: html,
                width: 980,
                confirmButtonText: "Cerrar",
                customClass: { confirmButton: "scm-btn-primary" },
              });
              return;
            }
            showToast(total > 0 ? "success" : "warning", total + " notificaciones relacionadas.");
          })
          .catch(function (err) {
            showToast("error", err.message || "No se pudieron consultar las notificaciones.");
          });
      }

      function updateCollectionPreview() {
        if (!collectionPreviewEl || collectionPreviewEl.hidden) {
          return;
        }
        var channels = collectionSelectedChannels();
        if (channels.length === 0) {
          collectionPreviewEl.innerHTML =
            "<strong>Vista previa</strong><p>No se enviara notificacion. Solo se guardara la gestion de cobro y el historial.</p>";
          return;
        }
        var cards = renderCollectionPreviewCards(channels, collectionDetailText());
        collectionPreviewEl.innerHTML = '<strong>Vista previa de gestion de cobro</strong><div>' + cards + "</div>";
      }

      function openCollectionModal() {
        if (!collectionModal) {
          return;
        }
        if (currentType() !== "arrendatarios_activos") {
          showToast("warning", "La gestion de cobro solo aplica para Arrendatarios activos.");
          return;
        }
        if (!actionAdminNotificationsCollection) {
          showToast("error", "La accion de gestion de cobro no esta disponible.");
          return;
        }
        if (collectionResultEl) {
          collectionResultEl.textContent = "";
          collectionResultEl.classList.remove("is-error");
        }
        updateCollectionPreview();
        syncContext();
        collectionModal.hidden = false;
        collectionModal.classList.add("is-open");
        document.body.classList.add("scm-admin-notif-modal-open");
        setTimeout(function () {
          var field = collectionForm ? collectionForm.querySelector("textarea, select, input") : null;
          if (field) {
            field.focus();
          }
        }, 30);
      }

      function prepareCollectionModal() {
        return withPanelLoader(
          function () {
            return loadCollectionOptions().then(function (contracts) {
              if (contracts.length === 0) {
                throw new Error(
                  "Los arrendatarios seleccionados no tienen contratos activos para gestionar.",
                );
              }
              syncCollectionContracts(contracts);
              openCollectionModal();
            });
          },
          "Cargando contratos y codeudores",
          "Estamos verificando los contratos activos y sus datos de contacto.",
        ).catch(function (error) {
          showToast(
            "error",
            error.message || "No se pudieron cargar los contratos y codeudores.",
          );
        });
      }

      function selectOnlyRecipient(id) {
        var value = String(id || "").trim();
        if (!value) {
          return false;
        }
        selected.clear();
        selected.add(value);
        if (allFiltered) {
          allFiltered.checked = false;
        }
        syncCollectionContracts([]);
        updateVisibleChecks();
        syncContext();
        return true;
      }

      function closeCollectionModal() {
        if (!collectionModal) {
          return;
        }
        collectionModal.hidden = true;
        collectionModal.classList.remove("is-open");
        selectedCollectionContracts = [];
        if (collectionContractWrap) {
          collectionContractWrap.hidden = true;
          collectionContractWrap.classList.add("is-hidden");
        }
        if (collectionContractSelect) {
          collectionContractSelect.innerHTML = "";
        }
        if (collectionCodeudoresList) {
          collectionCodeudoresList.innerHTML = "";
        }
        if (collectionCodeudoresWrap) {
          collectionCodeudoresWrap.hidden = true;
          collectionCodeudoresWrap.classList.add("is-hidden");
        }
        updateCollectionCodeudorCounter();
        document.body.classList.remove("scm-admin-notif-modal-open");
      }

      function syncContext() {
        if (sendType) {
          sendType.value = currentType();
        }
        if (sendQuery) {
          sendQuery.value = currentQuery();
        }
        if (sendName) {
          sendName.value = currentNameFilter();
        }
        if (sendEmail) {
          sendEmail.value = currentEmailFilter();
        }
        if (sendPhone) {
          sendPhone.value = currentPhoneFilter();
        }
        if (sendDocument) {
          sendDocument.value = currentDocumentFilter();
        }
        if (sendContractStatus) {
          sendContractStatus.value = currentContractStatus();
        }
        if (sendInmuebleSimi) {
          sendInmuebleSimi.value = currentInmuebleSimi();
        }
        if (sendContractNumber) {
          sendContractNumber.value = currentContractNumber();
        }
        updateComposerMode();
        if (contractStatusWrap) {
          var canFilterContracts = supportsContractStatus(currentType());
          contractStatusWrap.hidden = !canFilterContracts;
          contractStatusWrap.classList.toggle("is-hidden", !canFilterContracts);
          if (!canFilterContracts && contractStatusSelect) {
            contractStatusSelect.value = "";
          }
        }
        contractExtraWraps.forEach(function (wrap) {
          var canFilterContracts = supportsContractStatus(currentType());
          wrap.hidden = !canFilterContracts;
          wrap.classList.toggle("is-hidden", !canFilterContracts);
        });
        if (importWrap) {
          var canImport = supportsContractStatus(currentType());
          importWrap.hidden = !canImport;
          importWrap.classList.toggle("is-hidden", !canImport);
        }
        if (sendImportPayload) {
          sendImportPayload.value = JSON.stringify(importedPayload || {});
        }
        if (selectedCountEl) {
          selectedCountEl.textContent =
            allFiltered && allFiltered.checked
              ? String(totalEl ? totalEl.textContent || "todos" : "todos")
              : String(selected.size);
        }
        if (collectionSelectedCountEl) {
          collectionSelectedCountEl.textContent = String(selected.size);
        }
        if (openCollectionBtn) {
          var canCollection = currentType() === "arrendatarios_activos";
          openCollectionBtn.hidden = !canCollection;
          openCollectionBtn.classList.toggle("is-hidden", !canCollection);
        }
        panel.querySelectorAll("[data-admin-notif-type-shortcut]").forEach(function (btn) {
          btn.classList.toggle(
            "active",
            btn.getAttribute("data-admin-notif-type-shortcut") === currentType(),
          );
        });
        var emailChecked = !!panel.querySelector(
          '[data-admin-notif-channel][value="email"]:checked',
        );
        var whatsappChecked = !!panel.querySelector(
          '[data-admin-notif-channel][value="whatsapp"]:checked',
        );
        var templateVisible = emailChecked || whatsappChecked;
        filterMessageTemplateOptions();
        syncEmailTemplateFromMessageTemplate();
        if (subjectWrap) {
          subjectWrap.style.display = emailChecked ? "" : "none";
        }
        if (whatsappTemplateWrap) {
          whatsappTemplateWrap.hidden = !templateVisible;
          whatsappTemplateWrap.classList.toggle("is-hidden", !templateVisible);
        }
        updateMessageVisibility();
        updateSmsCounter();
        updatePreview();
        updateCollectionPreview();
      }

      function previewMessage(value) {
        var sender = senderProfile();
        return String(value || "")
          .replace(/\{\{nombre\}\}/g, "Nombre del destinatario")
          .replace(/\{\{correo\}\}/g, "correo@ejemplo.com")
          .replace(/\{\{celular\}\}/g, "+573001112233")
          .replace(/\{\{tipo_actor\}\}/g, currentType())
          .replace(/\{\{rol_persona\}\}/g, "Rol")
          .replace(/\{\{funcionario\}\}/g, sender.name)
          .replace(/\{\{cargo_funcionario\}\}/g, sender.cargo)
          .replace(/\{\{celular_funcionario\}\}/g, sender.phone || "Sin celular registrado")
          .replace(/\{\{firma_funcionario\}\}/g, sender.signature)
          .replace(/\{\{firma_funcionario_linea\}\}/g, sender.signatureLine)
          .replace(/\{\{canon_excel\}\}/g, "$1.850.000")
          .replace(/\{\{valor_canon\}\}/g, "$1.850.000")
          .replace(/\{\{canon\}\}/g, "$1.850.000")
          .replace(/\{\{contrato_excel\}\}/g, "700")
          .replace(/\{\{inmueble_simi_excel\}\}/g, "10578")
          .replace(/\{\{mes_excel\}\}/g, "Agosto 2026")
          .replace(/\{\{direccion_excel\}\}/g, "DG 49 #51-66 Apto 1")
          .replace(/\{\{detalle_excel\}\}/g, "Canon: $1.850.000\nContrato: #700\nInmueble SIMI: 10578\nPeriodo: Agosto 2026")
          .replace(/\{\{mes_excel_linea\}\}/g, "<br>Periodo: <strong>Agosto 2026</strong>")
          .replace(/\{\{direccion_excel_linea\}\}/g, "<br>Dirección: <strong>DG 49 #51-66 Apto 1</strong>");
      }

      function stripHtml(value) {
        var div = document.createElement("div");
        div.innerHTML = String(value || "").replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, "");
        return (div.textContent || div.innerText || "").trim();
      }

      function safePreviewHtml(value) {
        return String(value || "")
          .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, "")
          .replace(/<style[\s\S]*?>[\s\S]*?<\/style>/gi, "")
          .replace(/\son[a-z]+\s*=\s*(['"]).*?\1/gi, "")
          .replace(/\s(href|src)\s*=\s*(['"])\s*javascript:[\s\S]*?\2/gi, "");
      }

      function escAttr(value) {
        return escHtml(value).replace(/"/g, "&quot;");
      }

      function isFullEmailHtml(value) {
        return /<!doctype\s+html|<html[\s>]|<body[\s>]/i.test(String(value || ""));
      }

      function selectedTemplateBody() {
        if (!emailTemplateSelect) {
          return "{{mensaje}}";
        }
        var option = emailTemplateSelect.options
          ? emailTemplateSelect.options[emailTemplateSelect.selectedIndex]
          : emailTemplateSelect;
        return option ? option.getAttribute("data-preview-template") || "{{mensaje}}" : "{{mensaje}}";
      }

      function selectedEmailOption() {
        if (!emailTemplateSelect) {
          return null;
        }
        return emailTemplateSelect.options
          ? emailTemplateSelect.options[emailTemplateSelect.selectedIndex]
          : emailTemplateSelect;
      }

      function emailBannerUrl() {
        return panel.getAttribute("data-admin-notif-email-banner") || "https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/banner-sitio-web.png";
      }

      function wrapEmailPreviewHtml(title, contentHtml) {
        return (
          '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' +
          escHtml(title || "Notificacion") +
          '</title></head><body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;">' +
          '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;"><tr><td align="center" style="padding:24px 10px;">' +
          '<table role="presentation" width="700" cellpadding="0" cellspacing="0" border="0" style="max-width:700px;width:100%;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;">' +
          '<tr><td style="padding:0;"><img src="' +
          escAttr(emailBannerUrl()) +
          '" alt="Su Casa Inmobiliaria" style="display:block;width:100%;height:auto;border:0;"></td></tr>' +
          '<tr><td style="background:#f59120;height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>' +
          '<tr><td style="padding:34px 30px;text-align:left;color:#334155;">' +
          '<h3 style="color:#061d49;font-size:22px;margin:0 0 22px;text-align:center;">' +
          escHtml(title || "Notificacion") +
          "</h3>" +
          contentHtml +
          '</td></tr><tr><td style="background:#0f172a;text-align:center;font-size:14px;padding:22px 20px;color:#cbd5e1;">' +
          '<p style="margin:0 0 6px;color:#fff;font-weight:700;">Una empresa para lograr sus sue&ntilde;os.</p>' +
          '<p style="margin:0;color:#94a3b8;">&copy; Su Casa Inmobiliaria</p>' +
          "</td></tr></table></td></tr></table></body></html>"
        );
      }

      function renderEmailPreviewHtml() {
        var rawMessage = messageInput ? String(messageInput.value || "") : "";
        var slotMessage = selectedTemplateUsesImportedDetail()
          ? importedDetailPreview()
          : rawMessage;
        var template = selectedTemplateBody();
        var hasSlot = template.indexOf("{{mensaje}}") !== -1 || template.indexOf("{{custom_message}}") !== -1;
        var option = selectedEmailOption();
        var isFullTemplate = !!(option && option.getAttribute("data-template-full") === "1");
        var isFixedTemplate = !!(option && option.getAttribute("data-template-fixed") === "1");
        var messageForEmail = template !== stripHtml(template)
          ? safePreviewHtml(slotMessage !== stripHtml(slotMessage) ? slotMessage : escHtml(slotMessage).replace(/\n/g, "<br>"))
          : slotMessage;
        var body = hasSlot
          ? template
              .replace(/\{\{mensaje\}\}/g, messageForEmail || "Mensaje escrito por el funcionario.")
              .replace(/\{\{custom_message\}\}/g, messageForEmail || "Mensaje escrito por el funcionario.")
          : (isFixedTemplate || isFullTemplate || isFullEmailHtml(template) ? template : slotMessage || template || "Mensaje escrito por el funcionario.");
        body = previewMessage(body);
        if (body === stripHtml(body)) {
          body = escHtml(body).replace(/\n/g, "<br>");
        }
        body = safePreviewHtml(body);
        return isFullEmailHtml(body)
          ? body
          : wrapEmailPreviewHtml(subjectInput ? subjectInput.value : "Notificacion", body);
      }

      function selectedEmailNeedsMessage() {
        var option = selectedEmailOption();
        if (!option) {
          return true;
        }
        var explicit = option.getAttribute("data-requires-message");
        if (explicit === "0" || explicit === "1") {
          return explicit === "1";
        }
        var template = option.getAttribute("data-preview-template") || "";
        return template.indexOf("{{mensaje}}") !== -1 || template.indexOf("{{custom_message}}") !== -1;
      }

      function selectedWhatsappNeedsMessage() {
        var option = selectedMessageTemplateOption();
        var mode = option ? option.getAttribute("data-template-mode") || "name_message_signature" : "name_message_signature";
        return (mode === "name_message_signature" || mode === "name_message") && !selectedTemplateUsesImportedDetail();
      }

      function hasImportedRecipients() {
        return importedPayload && Object.keys(importedPayload).length > 0;
      }

      function selectedTemplateUsesImportedDetail() {
        var option = selectedMessageTemplateOption();
        if (!option || !hasImportedRecipients()) {
          return false;
        }
        return String(option.value || "") === "scm_propietario_arriendo_consignado_v1";
      }

      function importedDetailPreview() {
        return "Canon: $1.850.000\nContrato: #700\nInmueble SIMI: 10578\nPeriodo: Agosto 2026";
      }

      function messageRequiredForCurrentSelection() {
        var emailChecked = !!panel.querySelector(
          '[data-admin-notif-channel][value="email"]:checked',
        );
        var smsChecked = !!panel.querySelector(
          '[data-admin-notif-channel][value="sms"]:checked',
        );
        var whatsappChecked = !!panel.querySelector(
          '[data-admin-notif-channel][value="whatsapp"]:checked',
        );
        return smsChecked || (emailChecked && selectedEmailNeedsMessage() && !selectedTemplateUsesImportedDetail()) || (whatsappChecked && selectedWhatsappNeedsMessage());
      }

      function updateMessageVisibility() {
        if (!messageFieldWrap) {
          return;
        }
        var shouldShow = messageRequiredForCurrentSelection();
        messageFieldWrap.hidden = !shouldShow;
        messageFieldWrap.classList.toggle("is-hidden", !shouldShow);
        if (noMessageNote) {
          noMessageNote.hidden = shouldShow;
          noMessageNote.classList.toggle("is-hidden", shouldShow);
        }
      }

      function renderWhatsappPreviewText() {
        var rawMessage = messageInput ? String(messageInput.value || "") : "";
        var name = "Nombre del destinatario";
        var template = "Hola {{1}}.\n\n{{2}}";
        var option = selectedMessageTemplateOption();
        var mode = option ? option.getAttribute("data-template-mode") || "name_message_signature" : "name_message_signature";
        if (option) {
          template = option.getAttribute("data-template-body") || template;
        }
        var sender = senderProfile();
        var signature = sender.signatureLine || "Funcionario - Control Servicios Inmobiliarios";
        if (mode === "name_signature") {
          return template
            .replace(/\{\{1\}\}/g, name)
            .replace(/\{\{2\}\}/g, signature)
            .replace(/\{\{3\}\}/g, previewMessage(rawMessage || "Mensaje escrito por el funcionario."));
        }
        var messagePreview = selectedTemplateUsesImportedDetail()
          ? importedDetailPreview()
          : previewMessage(rawMessage || "Mensaje escrito por el funcionario.");
        return template
          .replace(/\{\{1\}\}/g, name)
          .replace(/\{\{2\}\}/g, messagePreview)
          .replace(/\{\{3\}\}/g, signature);
      }

      function updatePreview() {
        if (!previewEl || !messageInput || previewEl.hidden) {
          return;
        }
        var channels = Array.prototype.slice.call(
          panel.querySelectorAll("[data-admin-notif-channel]:checked"),
        ).map(function (input) {
          return String(input.value || "");
        });
        if (channels.length === 0) {
          previewEl.innerHTML =
            '<strong>Vista previa</strong><p>Selecciona al menos un canal para ver el mensaje.</p>';
          return;
        }
        var rawMessage = messageInput ? String(messageInput.value || "") : "";
        var smsText = smsPrefix() + previewMessage(stripHtml(rawMessage));
        var smsClass = smsText.length > 160 ? " is-over" : "";
        var cards = channels.map(function (channel) {
          if (channel === "email") {
            var emailHtml = renderEmailPreviewHtml();
            return (
              '<article class="scm-admin-notif-preview-card is-email">' +
              "<h5>Email / HTML</h5>" +
              '<iframe class="scm-admin-notif-email-frame" sandbox srcdoc="' +
              escAttr(emailHtml) +
              '"></iframe>' +
              "</article>"
            );
          }
          if (channel === "sms") {
            return (
              '<article class="scm-admin-notif-preview-card is-sms' +
              smsClass +
              '">' +
              "<h5>SMS</h5>" +
              '<p class="scm-admin-notif-preview-text">' +
              escHtml(smsText || smsPrefix() + "Mensaje escrito por el funcionario.") +
              "</p>" +
              "<small>" +
              smsText.length +
              "/160 caracteres incluyendo la marca.</small>" +
              "</article>"
            );
          }
          return (
            '<article class="scm-admin-notif-preview-card is-whatsapp">' +
            "<h5>WhatsApp oficial</h5>" +
            '<p class="scm-admin-notif-preview-text">' +
            escHtml(renderWhatsappPreviewText()).replace(/\n/g, "<br>") +
            "</p>" +
            "</article>"
          );
        }).join("");
        previewEl.innerHTML = '<strong>Vista previa por canal</strong><div>' + cards + "</div>";
      }

      function updateVisibleChecks() {
        recipientsEl.querySelectorAll("[data-admin-notif-recipient]").forEach(function (input) {
          input.checked = selected.has(String(input.value || ""));
        });
      }

      function updateSmsCounter() {
        if (!smsCounter || !messageInput) {
          return;
        }
        var value = String(messageInput.value || "");
        var smsText = smsPrefix() + previewMessage(stripHtml(value));
        var smsChecked = !!panel.querySelector(
          '[data-admin-notif-channel][value="sms"]:checked',
        );
        smsCounter.hidden = !smsChecked;
        smsCounter.textContent = smsText.length + "/160 SMS con marca incluida";
        smsCounter.classList.toggle("is-over", smsChecked && smsText.length > 160);
      }

      function loadRecipients(page) {
        currentPage = page || 1;
        recipientsRequestId += 1;
        var requestId = recipientsRequestId;
        var fd = new FormData();
        fd.append("action", actionAdminNotificationsRecipients);
        fd.append("nonce", nonce);
        fd.append("type", currentType());
        fd.append("q", currentQuery());
        fd.append("nombre", currentNameFilter());
        fd.append("correo", currentEmailFilter());
        fd.append("celular", currentPhoneFilter());
        fd.append("documento", currentDocumentFilter());
        fd.append("contract_status", currentContractStatus());
        fd.append("inmueble_simi", currentInmuebleSimi());
        fd.append("contract_number", currentContractNumber());
        fd.append("page", String(currentPage));
        recipientsEl.innerHTML =
          '<div class="scm-admin-notif-empty"><strong>Cargando destinatarios...</strong><span>Un momento mientras actualizamos la lista.</span></div>';
        setLoading(true);
        return fetchWithTimeout(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (response) {
            return response.json();
          })
          .then(function (json) {
            if (requestId !== recipientsRequestId) {
              return;
            }
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudieron cargar los destinatarios.",
              );
            }
            var data = json.data || {};
            recipientsEl.innerHTML = data.html || "";
            if (paginationEl) {
              paginationEl.innerHTML = data.pagination || "";
            }
            if (totalEl) {
              totalEl.textContent = String(data.total || 0);
            }
            if (listTitle) {
              listTitle.textContent = data.type_label || currentTypeLabel();
            }
            updateVisibleChecks();
            syncContext();
          })
          .catch(function (err) {
            if (requestId !== recipientsRequestId) {
              return;
            }
            recipientsEl.innerHTML =
              '<div class="scm-admin-notif-empty is-error"><strong>No se pudo cargar.</strong><span>' +
              escHtml(err.message || "Error desconocido") +
              '</span><button type="button" class="scm-btn-primary btn btn-primary" data-admin-notif-recipients-retry>Reintentar</button></div>';
            showToast("error", err.message || "No se pudieron cargar los destinatarios.");
          })
          .finally(function () {
            if (requestId === recipientsRequestId) {
              setLoading(false);
            }
          });
      }

      function importRecipientsFromFile() {
        if (!importForm || !importFileInput || !actionAdminNotificationsImport) {
          showToast("error", "La importacion no esta disponible.");
          return;
        }
        if (!supportsContractStatus(currentType())) {
          showToast("warning", "Este destinatario no se cruza por contrato o inmueble.");
          return;
        }
        if (!importFileInput.files || importFileInput.files.length === 0) {
          showToast("warning", "Selecciona un archivo Excel o CSV.");
          return;
        }
        var fd = new FormData(importForm);
        fd.set("action", actionAdminNotificationsImport);
        fd.set("nonce", nonce);
        fd.set("type", currentType());
        recipientsEl.innerHTML =
          '<div class="scm-admin-notif-empty"><strong>Importando archivo...</strong><span>Estamos cruzando contrato e inmueble SIMI.</span></div>';
        if (paginationEl) {
          paginationEl.innerHTML = "";
        }
        if (importResultEl) {
          importResultEl.textContent = "Importando y cruzando datos...";
          importResultEl.classList.remove("is-error", "is-success");
        }
        setLoading(true);
        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (response) {
            return response.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudo importar el archivo.",
              );
            }
            var data = json.data || {};
            importedPayload = data.payload || {};
            selected.clear();
            Object.keys(importedPayload).forEach(function (id) {
              selected.add(String(id));
            });
            if (allFiltered) {
              allFiltered.checked = false;
            }
            recipientsEl.innerHTML = data.html || "";
            if (paginationEl) {
              paginationEl.innerHTML =
                '<span class="scm-admin-notif-page-info">Importados seleccionados: ' +
                String(data.matched || 0) +
                "</span>";
            }
            if (totalEl) {
              totalEl.textContent = String(data.matched || 0);
            }
            if (listTitle && data.type_label) {
              listTitle.textContent = data.type_label + " importados";
            }
            if (importResultEl) {
              importResultEl.textContent = data.message || "Archivo importado.";
              importResultEl.classList.add((data.matched || 0) > 0 ? "is-success" : "is-error");
            }
            updateVisibleChecks();
            syncContext();
            showToast((data.matched || 0) > 0 ? "success" : "warning", data.message || "Archivo importado.");
          })
          .catch(function (err) {
            importedPayload = {};
            recipientsEl.innerHTML =
              '<div class="scm-admin-notif-empty is-error"><strong>No se pudo importar.</strong><span>' +
              escHtml(err.message || "Error desconocido") +
              "</span></div>";
            if (importResultEl) {
              importResultEl.textContent = err.message || "Error desconocido.";
              importResultEl.classList.add("is-error");
            }
            syncContext();
            showToast("error", err.message || "No se pudo importar el archivo.");
          })
          .finally(function () {
            setLoading(false);
          });
      }

      if (!panel.dataset.scmAdminNotificationsEvents) {
        panel.dataset.scmAdminNotificationsEvents = "1";

        panel.querySelectorAll("[data-admin-notif-open-channel]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            openComposerForChannel(btn.getAttribute("data-admin-notif-open-channel") || "", {
              singleRecipient: false,
            });
          });
        });
        panel.querySelector("[data-admin-notif-open-all-channels]")?.addEventListener("click", function () {
          openComposerForAllChannels({
            singleRecipient: false,
          });
        });
        if (closeComposerBtn) {
          closeComposerBtn.addEventListener("click", function () {
            closeComposer(false);
          });
        }
        if (closeConfirmAcceptBtn) {
          closeConfirmAcceptBtn.addEventListener("click", function () {
            performCloseComposer();
          });
        }
        if (closeConfirmCancelBtn) {
          closeConfirmCancelBtn.addEventListener("click", hideCloseConfirm);
        }
        if (closeConfirmModal) {
          closeConfirmModal.addEventListener("click", function (event) {
            var shouldCancel = event.target && event.target.closest
              ? !!event.target.closest("[data-admin-notif-confirm-cancel], .scm-admin-notif-confirm-backdrop")
              : false;
            if (shouldCancel) {
              hideCloseConfirm();
            }
          });
        }
        if (openCollectionBtn) {
          openCollectionBtn.addEventListener("click", function () {
            prepareCollectionModal();
          });
        }
        if (closeCollectionBtn) {
          closeCollectionBtn.addEventListener("click", closeCollectionModal);
        }
        if (collectionModal) {
          collectionModal.addEventListener("click", function (event) {
            if (event.target && event.target.classList && event.target.classList.contains("scm-admin-notif-modal-backdrop")) {
              event.preventDefault();
            }
          });
        }

        searchForm.addEventListener("submit", function (event) {
          event.preventDefault();
          resetImportState(false);
          selected.clear();
          if (allFiltered) {
            allFiltered.checked = false;
          }
          syncContext();
          loadRecipients(1);
        });

        panel.querySelector("[data-admin-notif-clear]")?.addEventListener("click", function () {
          resetImportState(true);
          if (queryInput) {
            queryInput.value = "";
          }
          [nameInput, emailInput, phoneInput, documentInput].forEach(function (input) {
            if (input) {
              input.value = "";
            }
          });
          if (contractStatusSelect) {
            contractStatusSelect.value = "";
          }
          if (inmuebleSimiInput) {
            inmuebleSimiInput.value = "";
          }
          if (contractNumberInput) {
            contractNumberInput.value = "";
          }
          selected.clear();
          if (allFiltered) {
            allFiltered.checked = false;
          }
          syncContext();
          loadRecipients(1);
        });

        typeSelect.addEventListener("change", function () {
          resetImportState(true);
          selected.clear();
          if (allFiltered) {
            allFiltered.checked = false;
          }
          syncContext();
          loadRecipients(1);
        });

        if (contractStatusSelect) {
          contractStatusSelect.addEventListener("change", function () {
            resetImportState(false);
            selected.clear();
            if (allFiltered) {
              allFiltered.checked = false;
            }
            syncContext();
            loadRecipients(1);
          });
        }
        [nameInput, emailInput, phoneInput, documentInput, inmuebleSimiInput, contractNumberInput].forEach(function (input) {
          if (!input) {
            return;
          }
          input.addEventListener("input", function () {
            syncContext();
          });
        });

        panel.querySelectorAll("[data-admin-notif-type-shortcut]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var nextType = btn.getAttribute("data-admin-notif-type-shortcut") || "";
            if (!nextType || !typeSelect) {
              return;
            }
            typeSelect.value = nextType;
            selected.clear();
            if (allFiltered) {
              allFiltered.checked = false;
            }
            syncContext();
            loadRecipients(1);
          });
        });

        recipientsEl.addEventListener("click", function (event) {
          var retryBtn = event.target && event.target.closest
            ? event.target.closest("[data-admin-notif-recipients-retry]")
            : null;
          if (retryBtn) {
            event.preventDefault();
            loadRecipients(currentPage);
            return;
          }

          var channelBtn = event.target && event.target.closest
            ? event.target.closest("[data-admin-notif-single-channel]")
            : null;
          if (channelBtn) {
            event.preventDefault();
            event.stopPropagation();
            if (!selectOnlyRecipient(channelBtn.getAttribute("data-admin-notif-single-id") || "")) {
              return;
            }
            openComposerForChannel(channelBtn.getAttribute("data-admin-notif-single-channel") || "", {
              singleRecipient: true,
            });
            return;
          }

          var allChannelsRowBtn = event.target && event.target.closest
            ? event.target.closest("[data-admin-notif-single-all-channels]")
            : null;
          if (allChannelsRowBtn) {
            event.preventDefault();
            event.stopPropagation();
            if (!selectOnlyRecipient(allChannelsRowBtn.getAttribute("data-admin-notif-single-id") || "")) {
              return;
            }
            openComposerForAllChannels({
              singleRecipient: true,
            });
            return;
          }

          var collectionBtn = event.target && event.target.closest
            ? event.target.closest("[data-admin-notif-single-collection]")
            : null;
          if (collectionBtn) {
            event.preventDefault();
            event.stopPropagation();
            if (!selectOnlyRecipient(collectionBtn.getAttribute("data-admin-notif-single-id") || "")) {
              return;
            }
            prepareCollectionModal();
            return;
          }

          var row = event.target && event.target.closest
            ? event.target.closest("[data-admin-notif-recipient-row]")
            : null;
          var interactive = event.target && event.target.closest
            ? event.target.closest("button, a, input, select, textarea")
            : null;
          if (row && !interactive) {
            var checkbox = row.querySelector("[data-admin-notif-recipient]");
            if (checkbox) {
              event.preventDefault();
              checkbox.checked = !checkbox.checked;
              checkbox.dispatchEvent(new Event("change", { bubbles: true }));
            }
          }
        });

        recipientsEl.addEventListener("change", function (event) {
          var input = event.target && event.target.closest
            ? event.target.closest("[data-admin-notif-recipient]")
            : null;
          if (!input) {
            return;
          }
          var value = String(input.value || "");
          if (input.checked) {
            selected.add(value);
          } else {
            selected.delete(value);
          }
          if (allFiltered && selected.size > 0) {
            allFiltered.checked = false;
          }
          syncContext();
        });

        if (selectVisibleBtn) {
          selectVisibleBtn.addEventListener("click", function () {
            var visible = Array.prototype.slice.call(
              recipientsEl.querySelectorAll("[data-admin-notif-recipient]"),
            );
            var shouldSelect = visible.some(function (input) {
              return !input.checked;
            });
            visible.forEach(function (input) {
              input.checked = shouldSelect;
              var value = String(input.value || "");
              if (shouldSelect) {
                selected.add(value);
              } else {
                selected.delete(value);
              }
            });
            if (allFiltered && selected.size > 0) {
              allFiltered.checked = false;
            }
            syncContext();
          });
        }

        if (importForm) {
          importForm.addEventListener("submit", function (event) {
            event.preventDefault();
            importRecipientsFromFile();
          });
        }
        if (importClearBtn) {
          importClearBtn.addEventListener("click", function () {
            resetImportState(true);
            selected.clear();
            if (allFiltered) {
              allFiltered.checked = false;
            }
            syncContext();
            loadRecipients(1);
          });
        }

        if (paginationEl) {
          paginationEl.addEventListener("click", function (event) {
            var btn = event.target && event.target.closest
              ? event.target.closest("[data-admin-notif-page]")
              : null;
            if (!btn || btn.disabled) {
              return;
            }
            event.preventDefault();
            loadRecipients(parseInt(btn.getAttribute("data-admin-notif-page") || "1", 10));
          });
        }

        panel.querySelectorAll("[data-admin-notif-channel]").forEach(function (input) {
          input.addEventListener("change", function () {
            markComposerDirty();
            syncContext();
          });
        });
        if (emailTemplateSelect) {
          emailTemplateSelect.addEventListener("change", function () {
            var option = selectedEmailOption();
            if (messageInput && option) {
              messageInput.placeholder = option.getAttribute("data-template-full") === "1"
                ? "Esta plantilla ya trae un diseno HTML completo. Escribe solo si tambien enviaras SMS o WhatsApp."
                : option.getAttribute("data-message-only") === "1"
                ? "Escribe solo el mensaje que va dentro de la plantilla..."
                : "Edita el contenido de la plantilla...";
            }
            markComposerDirty();
            syncContext();
          });
        }
        panel.querySelector("[data-admin-notif-clear-message]")?.addEventListener("click", function () {
          if (messageInput) {
            messageInput.value = "";
            messageInput.focus();
          }
          markComposerDirty();
          updateSmsCounter();
          updatePreview();
        });
        panel.querySelector("[data-admin-notif-preview-toggle]")?.addEventListener("click", function (event) {
          if (!previewEl) {
            return;
          }
          previewEl.hidden = !previewEl.hidden;
          event.target.textContent = previewEl.hidden ? "Ver vista previa" : "Ocultar vista previa";
          updatePreview();
        });
        if (collectionPreviewToggle) {
          collectionPreviewToggle.addEventListener("click", function (event) {
            if (!collectionPreviewEl) {
              return;
            }
            collectionPreviewEl.hidden = !collectionPreviewEl.hidden;
            event.target.textContent = collectionPreviewEl.hidden ? "Ver vista previa" : "Ocultar vista previa";
            updateCollectionPreview();
          });
        }
        panel.querySelector("[data-admin-notif-copy-message]")?.addEventListener("click", function () {
          var value = messageInput ? String(messageInput.value || "") : "";
          if (value === "") {
            showToast("warning", "No hay mensaje para copiar.");
            return;
          }
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
              showToast("success", "Mensaje copiado.");
            }).catch(function () {
              showToast("warning", "No fue posible copiar automaticamente.");
            });
          } else {
            showToast("warning", "Tu navegador no permite copiar automaticamente.");
          }
        });
        if (whatsappTemplateSelect) {
          whatsappTemplateSelect.addEventListener("change", function () {
            subjectDirty = false;
            markComposerDirty();
            syncContext();
          });
        }
        if (messageInput) {
          messageInput.addEventListener("input", function () {
            markComposerDirty();
            updateSmsCounter();
            updatePreview();
          });
        }
        if (subjectInput) {
          subjectInput.addEventListener("input", function () {
            subjectDirty = true;
            markComposerDirty();
            updatePreview();
          });
        }
        if (allFiltered) {
          allFiltered.addEventListener("change", function () {
            if (allFiltered.checked) {
              selected.clear();
              updateVisibleChecks();
            }
            markComposerDirty();
            syncContext();
          });
        }
        [
          collectionTypeSelect,
          collectionObservationInput,
          collectionContractSelect,
        ].forEach(function (input) {
          if (!input) {
            return;
          }
          input.addEventListener("input", function () {
            if (input === collectionContractSelect) {
              renderCollectionCodeudores();
            }
            updateCollectionPreview();
          });
          input.addEventListener("change", function () {
            if (input === collectionContractSelect) {
              renderCollectionCodeudores();
            }
            updateCollectionPreview();
          });
        });
        if (collectionCodeudoresList) {
          collectionCodeudoresList.addEventListener("change", function (event) {
            if (event.target && event.target.matches && event.target.matches("[data-admin-notif-codeudor-key]")) {
              updateCollectionCodeudorCounter();
            }
          });
        }
        if (collectionCodeudoresSelectAllBtn && collectionCodeudoresList) {
          collectionCodeudoresSelectAllBtn.addEventListener("click", function () {
            collectionCodeudoresList.querySelectorAll("[data-admin-notif-codeudor-key]").forEach(function (input) {
              input.checked = true;
            });
            updateCollectionCodeudorCounter();
          });
        }
        if (collectionCodeudoresClearBtn && collectionCodeudoresList) {
          collectionCodeudoresClearBtn.addEventListener("click", function () {
            collectionCodeudoresList.querySelectorAll("[data-admin-notif-codeudor-key]").forEach(function (input) {
              input.checked = false;
            });
            updateCollectionCodeudorCounter();
          });
        }
        panel.querySelectorAll("[data-admin-notif-collection-channel]").forEach(function (input) {
          input.addEventListener("change", updateCollectionPreview);
        });

        if (collectionForm) {
          collectionForm.addEventListener("submit", function (event) {
            event.preventDefault();
            syncContext();
            if (currentType() !== "arrendatarios_activos") {
              showToast("warning", "La gestion de cobro solo aplica para Arrendatarios activos.");
              return;
            }
            var useAll = false;
            if (selected.size === 0) {
              showToast("error", "Selecciona un arrendatario activo.");
              return;
            }
            if (collectionContractWrap && !collectionContractWrap.hidden && collectionContractSelect && !collectionContractSelect.value) {
              showToast("error", "Selecciona el contrato de la gestion de cobro.");
              collectionContractSelect.focus();
              return;
            }
            var fd = new FormData(collectionForm);
            fd.set("action", actionAdminNotificationsCollection);
            fd.set("nonce", nonce);
            fd.set("type", currentType());
            fd.set("q", currentQuery());
            fd.set("nombre", currentNameFilter());
            fd.set("correo", currentEmailFilter());
            fd.set("celular", currentPhoneFilter());
            fd.set("documento", currentDocumentFilter());
            fd.set("contract_status", currentContractStatus());
            fd.set("inmueble_simi", currentInmuebleSimi());
            fd.set("contract_number", currentContractNumber());
            fd.set("all_filtered", "0");
            selected.forEach(function (id) {
              fd.append("ids[]", id);
            });
            setCollectionLoading(true);
            if (collectionResultEl) {
              collectionResultEl.textContent = "Guardando gestiones de cobro...";
              collectionResultEl.classList.remove("is-error");
            }
            fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
              .then(function (response) {
                return response.json();
              })
              .then(function (json) {
                if (!json || !json.success) {
                  throw new Error(
                    (json && json.data && json.data.message) ||
                      "No se pudo registrar la gestion de cobro.",
                  );
                }
                var data = json.data || {};
                var msg = data.message || "Gestion de cobro registrada.";
                if (collectionResultEl) {
                  collectionResultEl.textContent = msg;
                  collectionResultEl.classList.remove("is-error");
                }
                showToast((data.created || 0) > 0 ? "success" : "warning", msg);
                if ((data.created || 0) > 0) {
                  markCollectionLogStale();
                  var activeCollectionPanel = root.querySelector(
                    "#scm-panel-actividades-administrativas.active #scm-panel-gestiones-cobro.active",
                  );
                  if (activeCollectionPanel) {
                    refreshCollectionLogPanel(activeCollectionPanel, true);
                  }
                  closeCollectionModal();
                }
                loadRecipients(currentPage);
              })
              .catch(function (err) {
                if (collectionResultEl) {
                  collectionResultEl.textContent = err.message || "Error desconocido.";
                  collectionResultEl.classList.add("is-error");
                }
                showToast("error", err.message || "No se pudo registrar la gestion de cobro.");
              })
              .finally(function () {
                setCollectionLoading(false);
              });
          });
        }

        panel.addEventListener("click", function (event) {
          var queueBtn = event.target && event.target.closest
            ? event.target.closest("[data-scm-collection-queue]")
            : null;
          if (!queueBtn || !panel.contains(queueBtn)) {
            return;
          }
          event.preventDefault();
          openCollectionQueueFromLog(
            queueBtn.getAttribute("data-scm-collection-queue") || "",
          );
        });

        sendForm.addEventListener("submit", function (event) {
          event.preventDefault();
          syncContext();
          var useAll = !!(allFiltered && allFiltered.checked);
          if (!useAll && selected.size === 0) {
            showToast("error", "Selecciona destinatarios o activa todos los filtrados.");
            return;
          }
          var fd = new FormData(sendForm);
          fd.set("action", actionAdminNotificationsSend);
          fd.set("nonce", nonce);
          fd.set("type", currentType());
          fd.set("q", currentQuery());
          fd.set("nombre", currentNameFilter());
          fd.set("correo", currentEmailFilter());
          fd.set("celular", currentPhoneFilter());
          fd.set("documento", currentDocumentFilter());
          fd.set("contract_status", currentContractStatus());
          fd.set("inmueble_simi", currentInmuebleSimi());
          fd.set("contract_number", currentContractNumber());
          fd.set("import_payload", JSON.stringify(importedPayload || {}));
          fd.set("all_filtered", useAll ? "1" : "0");
          if (!useAll) {
            selected.forEach(function (id) {
              fd.append("ids[]", id);
            });
          }
          setLoading(true);
          if (resultEl) {
            resultEl.textContent = "Encolando notificaciones...";
            resultEl.classList.remove("is-error");
          }
          fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (response) {
              return response.json();
            })
            .then(function (json) {
              if (!json || !json.success) {
                throw new Error(
                  (json && json.data && json.data.message) ||
                    "No se pudo encolar la notificacion.",
                );
              }
              var data = json.data || {};
              var msg = data.message || "Notificacion encolada.";
              if (resultEl) {
                resultEl.textContent = msg;
                resultEl.classList.remove("is-error");
              }
              composerDirty = false;
              showToast((data.queued || 0) > 0 ? "success" : "warning", msg);
            })
            .catch(function (err) {
              if (resultEl) {
                resultEl.textContent = err.message || "Error desconocido.";
                resultEl.classList.add("is-error");
              }
              showToast("error", err.message || "No se pudo encolar la notificacion.");
            })
            .finally(function () {
              setLoading(false);
            });
        });
      }

      syncContext();
      if (!panel.dataset.scmAdminNotificationsLoaded || forceReload) {
        panel.dataset.scmAdminNotificationsLoaded = "1";
        return loadRecipients(currentPage);
      }
      return Promise.resolve();
    }

    function bindDashboardPermissions() {
      var permConfig = runtime.dashboardPermissions || {};
      if (!permConfig.canManage) {
        return;
      }
      var openBtn = root.querySelector("#scm-open-permissions");
      var modal = root.querySelector("#scm-permissions-modal");
      var closeBtn = root.querySelector("#scm-close-permissions");
      var form = root.querySelector("#scm-permissions-form");
      var msg = root.querySelector("#scm-permissions-msg");
      if (!openBtn || !modal || !form) {
        return;
      }

      function openModal() {
        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        refreshCardState(form);
      }
      function closeModal() {
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
      }
      function collectPermissions() {
        var permissions = {};
        form
          .querySelectorAll('input[type="checkbox"][name^="permissions["]')
          .forEach(function (input) {
            var match = input.name.match(/^permissions\[([^\]]+)\]/);
            var cargo = match ? match[1] : "";
            if (!cargo) return;
            if (!permissions[cargo]) permissions[cargo] = [];
            if (input.checked) permissions[cargo].push(input.value);
          });
        return permissions;
      }
      function collectEmployeeCargoIds() {
        return Array.prototype.slice
          .call(form.querySelectorAll('input[type="checkbox"][name="employee_cargo_ids[]"]:checked'))
          .map(function (input) {
            return String(input.value || "").trim();
          })
          .filter(Boolean);
      }
      function setMessage(text, isError) {
        if (!msg) return;
        msg.textContent = text || "";
        msg.classList.toggle("error", !!isError);
      }
      function refreshCardState(scope) {
        var target = scope || form;
        target.querySelectorAll(".scm-permissions-check").forEach(function (label) {
          var input = label.querySelector('input[type="checkbox"]');
          label.classList.toggle("is-checked", !!(input && input.checked));
        });
        var cards = [];
        if (target.classList && target.classList.contains("scm-permission-card")) {
          cards.push(target);
        }
        target.querySelectorAll(".scm-permission-card").forEach(function (card) {
          cards.push(card);
        });
        cards.forEach(function (card) {
          var checks = Array.prototype.slice.call(
            card.querySelectorAll('.scm-permissions-check input[type="checkbox"]')
          );
          var allChecked = checks.length > 0 && checks.every(function (input) {
            return input.checked;
          });
          var btn = card.querySelector("[data-scm-perm-all]");
          if (btn) {
            btn.textContent = allChecked ? "Quitar todo" : "Todo";
          }
        });
      }

      openBtn.addEventListener("click", openModal);
      if (closeBtn) closeBtn.addEventListener("click", closeModal);
      modal.addEventListener("click", function (event) {
        if (event.target === modal) closeModal();
      });
      form.addEventListener("change", function (event) {
        var input = event.target;
        if (input && input.matches && input.matches('.scm-permissions-check input[type="checkbox"]')) {
          refreshCardState(input.closest(".scm-permission-card") || form);
        }
      });
      form.addEventListener("click", function (event) {
        var btn = event.target && event.target.closest
          ? event.target.closest("[data-scm-perm-all]")
          : null;
        if (!btn) {
          return;
        }
        var card = btn.closest(".scm-permission-card");
        if (!card) {
          return;
        }
        var checks = Array.prototype.slice.call(
          card.querySelectorAll('.scm-permissions-check input[type="checkbox"]')
        );
        var shouldCheck = checks.some(function (input) {
          return !input.checked;
        });
        checks.forEach(function (input) {
          input.checked = shouldCheck;
        });
        btn.textContent = shouldCheck ? "Quitar todo" : "Todo";
        refreshCardState(card);
      });
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        if (!ajaxUrl || !actionDashboardPermissionsSave) {
          setMessage("No esta disponible la accion de guardado.", true);
          return;
        }
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        setMessage("Guardando permisos...", false);
        var fd = new FormData();
        fd.append("action", actionDashboardPermissionsSave);
        fd.append("nonce", nonce);
        fd.append("permissions", JSON.stringify(collectPermissions()));
        fd.append("employee_cargo_ids", JSON.stringify(collectEmployeeCargoIds()));
        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                "No se pudieron guardar los permisos.",
              );
            }
            if (json.data) {
              if (Array.isArray(json.data.employee_cargo_ids)) {
                permConfig.employeeCargoIds = json.data.employee_cargo_ids;
                runtime.dashboardPermissions = permConfig;
                config.calendar_allowed_cargos = json.data.employee_cargo_ids;
              }
              if (Array.isArray(json.data.calendar_allowed_employee_ids)) {
                config.calendar_allowed_employee_ids = json.data.calendar_allowed_employee_ids;
              }
            }
            setMessage(
              (json.data && json.data.message) || "Permisos guardados.",
              false,
            );
            showToast("success", "Permisos y funcionarios visibles guardados.");
          })
          .catch(function (err) {
            setMessage(err && err.message ? err.message : "Error al guardar.", true);
            showToast("error", err && err.message ? err.message : "Error al guardar.");
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
        });
      });
    }

    bindDashboardPermissions();

    function bindPublicPqrSettingsShortcut() {
      var openBtn = root.querySelector("#scm-open-pqr-settings");
      var modal = root.querySelector("#scm-pqr-settings-modal");
      if (
        !openBtn ||
        !modal ||
        modal.hasAttribute("data-scm-lazy-settings") ||
        modal.dataset.scmSettingsBound === "1"
      ) {
        return;
      }
      modal.dataset.scmSettingsBound = "1";
      var closeBtn = modal.querySelector("#scm-close-pqr-settings");

      function initSelects() {
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)) {
          return;
        }
        var $ = window.jQuery;
        modal.querySelectorAll("select.scm-select").forEach(function (selectEl) {
          var $select = $(selectEl);
          if ($select.data("select2")) {
            return;
          }
          $select.select2({
            width: "100%",
            closeOnSelect: !selectEl.multiple,
            dropdownParent: $(modal),
            placeholder: selectEl.multiple ? "Buscar y seleccionar..." : "Seleccionar...",
            allowClear: !selectEl.multiple,
          });
        });
      }

      function openModal() {
        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        initSelects();
      }

      function closeModal() {
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
      }

      function setMessage(form, text, isError) {
        var msg = form.querySelector(
          ".scm-public-pqr-corresponsable-msg, .scm-notif-responsable-msg"
        );
        if (!msg) {
          return;
        }
        msg.textContent = text || "";
        msg.classList.toggle("error", !!isError);
      }

      openBtn.addEventListener("click", openModal);
      if (closeBtn) {
        closeBtn.addEventListener("click", closeModal);
      }
      modal.addEventListener("click", function (event) {
        if (event.target === modal) {
          closeModal();
        }
      });

      modal.addEventListener("submit", function (event) {
        var form = event.target;
        if (!form || !form.classList || !form.classList.contains("scm-dashboard-pqr-config-form")) {
          return;
        }
        event.preventDefault();
        var isCorresponsable = form.classList.contains("scm-public-pqr-corresponsable-form");
        var action = isCorresponsable
          ? actions.guardar_corresponsable_pqr_publico || "scm_guardar_corresponsable_pqr_publico"
          : actions.notif_responsable_pqr || "scm_guardar_notif_responsable_pqr";
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        setMessage(form, "Guardando...", false);

        var fd = new FormData(form);
        fd.append("action", action);
        fd.append("nonce", nonce);

        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  (isCorresponsable
                    ? "No se pudo guardar el corresponsable."
                    : "No se pudo guardar la notificacion.")
              );
            }
            setMessage(form, (json.data && json.data.message) || "Configuracion guardada.", false);
            showToast("success", "Configuracion guardada.");
          })
          .catch(function (err) {
            setMessage(form, err && err.message ? err.message : "No se pudo guardar.", true);
            showToast("error", err && err.message ? err.message : "No se pudo guardar.");
          })
          .finally(function () {
            if (btn) btn.disabled = false;
          });
      });
    }

    bindPublicPqrSettingsShortcut();

    function bindInternalNotificationsSettings() {
      var openBtn = root.querySelector("#scm-open-internal-notifications");
      var modal = root.querySelector("#scm-internal-notifications-modal");
      var form = root.querySelector("#scm-internal-notifications-form");
      if (
        !openBtn ||
        !modal ||
        !form ||
        modal.dataset.scmSettingsBound === "1"
      ) {
        return;
      }
      modal.dataset.scmSettingsBound = "1";
      var closeBtn = modal.querySelector("#scm-close-internal-notifications");
      var msg = modal.querySelector("#scm-internal-notifications-msg");

      function initSelects() {
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)) {
          return;
        }
        var $ = window.jQuery;
        modal.querySelectorAll("select.scm-select").forEach(function (selectEl) {
          var $select = $(selectEl);
          if ($select.data("select2")) {
            return;
          }
          $select.select2({
            width: "100%",
            closeOnSelect: false,
            dropdownParent: $(modal),
            placeholder: "Buscar funcionarios...",
          });
        });
      }

      function openModal() {
        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        initSelects();
      }

      function closeModal() {
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
      }

      function setMessage(text, isError) {
        if (!msg) return;
        msg.textContent = text || "";
        msg.classList.toggle("error", !!isError);
      }

      function collectSettings() {
        var settings = {};
        form.querySelectorAll('select[name^="settings["]').forEach(function (select) {
          var match = select.name.match(/^settings\[([^\]]+)\]/);
          var action = match ? match[1] : "";
          if (!action) return;
          settings[action] = Array.prototype.slice.call(select.selectedOptions || [])
            .map(function (option) {
              return option.value;
            })
            .filter(Boolean);
        });
        return settings;
      }

      openBtn.addEventListener("click", openModal);
      if (closeBtn) {
        closeBtn.addEventListener("click", closeModal);
      }
      modal.addEventListener("click", function (event) {
        if (event.target === modal) {
          event.preventDefault();
        }
      });
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        if (!ajaxUrl || !actionInternalNotificationsSave) {
          setMessage("No esta disponible la accion de guardado.", true);
          return;
        }
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        setMessage("Guardando configuracion...", false);
        var fd = new FormData();
        fd.append("action", actionInternalNotificationsSave);
        fd.append("nonce", nonce);
        fd.append("settings", JSON.stringify(collectSettings()));
        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudieron guardar las notificaciones internas.",
              );
            }
            setMessage(
              (json.data && json.data.message) || "Notificaciones internas guardadas.",
              false,
            );
            showToast("success", "Notificaciones internas guardadas.");
          })
          .catch(function (err) {
            setMessage(err && err.message ? err.message : "No se pudo guardar.", true);
            showToast("error", err && err.message ? err.message : "No se pudo guardar.");
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    }

    bindInternalNotificationsSettings();

    var settingsModalPromises = {};
    root.addEventListener("click", function (event) {
      var button = event.target.closest(
        "#scm-open-pqr-settings, #scm-open-internal-notifications",
      );
      if (!button) return;
      var isPublicPqr = button.id === "scm-open-pqr-settings";
      var modalId = isPublicPqr
        ? "scm-pqr-settings-modal"
        : "scm-internal-notifications-modal";
      var action = isPublicPqr
        ? actionPublicPqrSettingsRead
        : actionInternalNotificationsRead;
      var shell = root.querySelector("#" + modalId + "[data-scm-lazy-settings]");
      if (!shell) return;
      event.preventDefault();
      if (!action || !ajaxUrl) {
        showToast("error", "No está disponible la configuración solicitada.");
        return;
      }
      if (!settingsModalPromises[modalId]) {
        button.disabled = true;
        var fd = new FormData();
        fd.append("action", action);
        fd.append("nonce", nonce);
        settingsModalPromises[modalId] = fetch(ajaxUrl, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (response) { return response.json(); })
          .then(function (json) {
            if (!json || !json.success || !json.data || !json.data.html) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudo cargar la configuración.",
              );
            }
            var template = document.createElement("template");
            template.innerHTML = String(json.data.html).trim();
            var modal = template.content.firstElementChild;
            if (!modal) throw new Error("La configuración llegó vacía.");
            shell.replaceWith(modal);
            if (isPublicPqr) {
              bindPublicPqrSettingsShortcut();
            } else {
              bindInternalNotificationsSettings();
            }
            button.disabled = false;
            button.click();
          })
          .catch(function (error) {
            settingsModalPromises[modalId] = null;
            showToast("error", error.message || "No se pudo cargar la configuración.");
          })
          .finally(function () {
            button.disabled = false;
          });
      }
    });

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
        '<header class="scm-case-submodal-head">' +
        '<div><h4 class="scm-case-submodal-title" id="scm-admin-ticket-title">Crear ticket</h4><p class="scm-case-submodal-meta">Ticket administrativo desde contrato</p></div>' +
        '<button type="button" class="scm-case-submodal-close" data-admin-ticket-close aria-label="Cerrar formulario"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>' +
        "</header>" +
        '<div class="scm-admin-ticket-body"></div>' +
        "</div>";
      root.appendChild(modal);
      modal.addEventListener("click", function (e) {
        if (e.target.closest("[data-admin-ticket-close]")) {
          closeAdminTicketModal();
        } else if (e.target === modal) {
          e.preventDefault();
        }
      });
      if (!root.dataset.preventivaTicketsEscapeBound) {
        root.dataset.preventivaTicketsEscapeBound = "1";
        document.addEventListener("keydown", function (event) {
          if (event.key === "Escape") {
            closePreventivaTicketsModal();
          }
        });
      }
      return modal;
    }

    function closeAdminTicketModal() {
      var modal = root.querySelector("#scm-admin-ticket-modal");
      if (!modal) return;
      modal.classList.remove("open");
      modal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("scm-modal-open");
      var trigger = modal._scmTrigger;
      modal._scmTrigger = null;
      if (trigger && trigger.focus) {
        trigger.focus();
      }
    }

    function ensurePublicServicesReviewModal() {
      var modal = root.querySelector("#scm-public-services-review-modal");
      if (modal) return modal;
      modal = document.createElement("div");
      modal.id = "scm-public-services-review-modal";
      modal.className = "scm-public-services-review-modal";
      modal.setAttribute("aria-hidden", "true");
      modal.innerHTML =
        '<div class="scm-public-services-review-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-public-services-review-title">' +
        '<header class="scm-public-services-review-head"><div><span>Servicios públicos</span><h4 id="scm-public-services-review-title">Agregar revisión</h4><p data-public-services-review-meta>Cargando información del contrato...</p></div>' +
        '<button type="button" class="scm-public-services-review-close" data-public-services-review-close aria-label="Cerrar">&times;</button></header>' +
        '<div class="scm-public-services-review-body" data-public-services-review-body><div class="scm-public-services-review-loading" role="status"><i aria-hidden="true"></i><strong>Cargando formulario...</strong></div></div>' +
        "</div>";
      root.appendChild(modal);
      modal.addEventListener("click", function (event) {
        var closeButton = event.target && event.target.closest
          ? event.target.closest("[data-public-services-review-close]")
          : null;
        if (closeButton) {
          event.preventDefault();
          closePublicServicesReviewModal(false);
        }
      });
      modal.addEventListener("change", function (event) {
        var form = modal.querySelector("[data-public-services-review-form]");
        if (form) form.dataset.dirty = "1";
        var serviceToggle = event.target && event.target.matches
          ? event.target.matches('input[name="servicios[]"]')
            ? event.target
            : null
          : null;
        if (serviceToggle) {
          syncPublicServiceCard(serviceToggle.closest("[data-public-service-card]"));
          return;
        }
        if (event.target && event.target.matches && event.target.matches("[data-public-service-status]")) {
          syncPublicServiceAmount(event.target);
        }
      });
      modal.addEventListener("input", function () {
        var form = modal.querySelector("[data-public-services-review-form]");
        if (form) form.dataset.dirty = "1";
      });
      document.addEventListener("keydown", function (event) {
        if (!modal.classList.contains("open")) return;
        if (event.key === "Escape") {
          event.preventDefault();
          closePublicServicesReviewModal(false);
          return;
        }
        if (event.key !== "Tab") return;
        var focusable = Array.prototype.slice.call(
          modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), [href]'),
        ).filter(function (element) {
          return element.offsetParent !== null;
        });
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });
      return modal;
    }

    function closePublicServicesReviewModal(force) {
      var modal = root.querySelector("#scm-public-services-review-modal");
      if (!modal || !modal.classList.contains("open")) return;
      var form = modal.querySelector("[data-public-services-review-form]");
      if (!force && form && form.dataset.dirty === "1") {
        if (!window.confirm("Hay datos sin guardar. ¿Deseas cerrar el formulario?")) {
          return;
        }
      }
      modal.classList.remove("open");
      modal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("scm-modal-open");
      var trigger = modal._scmTrigger;
      modal._scmTrigger = null;
      if (trigger && trigger.focus) trigger.focus();
    }

    function syncPublicServiceCard(card) {
      if (!card) return;
      var toggle = card.querySelector('input[name="servicios[]"]');
      var enabled = !!(toggle && toggle.checked);
      card.classList.toggle("is-selected", enabled);
      if (toggle) toggle.setAttribute("aria-expanded", enabled ? "true" : "false");
      card.querySelectorAll(".scm-public-service-fields input, .scm-public-service-fields select").forEach(function (field) {
        field.disabled = !enabled;
      });
    }

    function syncPublicServiceAmount(statusField) {
      var card = statusField && statusField.closest
        ? statusField.closest("[data-public-service-card]")
        : null;
      var amount = card ? card.querySelector("[data-public-service-amount]") : null;
      if (!amount) return;
      if (statusField.value === "Al dia") {
        amount.value = "0";
        amount.readOnly = true;
      } else {
        amount.readOnly = false;
        if (amount.value === "0") amount.value = "";
      }
    }

    function openPublicServicesReviewModal(button) {
      if (!ajaxUrl || !actionRevisionServiciosPublicos) {
        showToast("error", "La acción de revisión de servicios no está configurada.");
        return;
      }
      var contractId = button.getAttribute("data-contract-id") || "";
      if (!contractId) {
        showToast("error", "No se encontro el contrato.");
        return;
      }
      var modal = ensurePublicServicesReviewModal();
      var body = modal.querySelector("[data-public-services-review-body]");
      var meta = modal.querySelector("[data-public-services-review-meta]");
      modal._scmTrigger = button;
      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      document.body.classList.add("scm-modal-open");
      if (meta) {
        meta.textContent = "Contrato " + (button.getAttribute("data-contract-code") || contractId);
      }
      if (body) {
        body.innerHTML = '<div class="scm-public-services-review-loading" role="status"><i aria-hidden="true"></i><strong>Cargando formulario...</strong></div>';
      }
      var originalText = button.textContent;
      button.disabled = true;
      button.textContent = "Cargando...";
      var fd = new FormData();
      fd.append("action", actionRevisionServiciosPublicos);
      fd.append("nonce", nonce);
      fd.append("operation", "load");
      fd.append("contract_id", contractId);
      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || "No se pudo cargar el formulario.");
          }
          if (body) {
            body.innerHTML = (json.data && json.data.form_html) || "";
            body.querySelectorAll("[data-public-service-card]").forEach(syncPublicServiceCard);
            var firstField = body.querySelector('input[name="servicios[]"]');
            if (firstField && firstField.focus) firstField.focus();
          }
        })
        .catch(function (error) {
          if (body) {
            body.innerHTML = '<div class="scm-public-services-review-load-error" role="alert"><strong>No fue posible cargar el formulario.</strong><span>' + escHtml(error && error.message ? error.message : "Error desconocido") + "</span></div>";
          }
          showToast("error", error && error.message ? error.message : "No se pudo cargar el formulario.");
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = originalText;
        });
    }

    function publicServicesReviewDocumentsHtml(documents) {
      if (!Array.isArray(documents) || !documents.length) return "";
      return '<div class="scm-public-services-review-links">' + documents.map(function (document) {
        return '<a href="' + escHtml(document.url || "#") + '" target="_blank" rel="noopener noreferrer">' + escHtml(document.title || "Ver acta") + "</a>";
      }).join("") + "</div>";
    }

    function submitPublicServicesReviewForm(form) {
      var errorBox = form.querySelector(".scm-public-services-review-error");
      var selected = form.querySelectorAll('input[name="servicios[]"]:checked');
      if (!selected.length) {
        if (errorBox) {
          errorBox.hidden = false;
          errorBox.textContent = "Selecciona por lo menos un servicio.";
        }
        return;
      }
      if (!form.checkValidity()) {
        if (errorBox) {
          errorBox.hidden = false;
          errorBox.textContent = "Completa los campos obligatorios de los servicios seleccionados.";
        }
        form.reportValidity();
        return;
      }
      if (errorBox) {
        errorBox.hidden = true;
        errorBox.textContent = "";
      }
      var submitButton = form.querySelector("[data-public-services-review-submit]");
      var originalText = submitButton ? submitButton.textContent : "";
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Guardando y generando actas...";
      }
      form.setAttribute("aria-busy", "true");
      var fd = new FormData(form);
      fd.append("action", actionRevisionServiciosPublicos);
      fd.append("nonce", nonce);
      fd.append("operation", "submit");
      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || "No se pudo registrar la revisión.");
          }
          var data = json.data || {};
          form.dataset.dirty = "0";
          closePublicServicesReviewModal(true);
          showToast("success", data.message || "Revisión agregada con éxito.");
          if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
              icon: "success",
              title: "Revisión registrada",
              html: '<p class="scm-public-services-review-success-copy">' + escHtml(data.message || "La revisión fue guardada.") + "</p>" + publicServicesReviewDocumentsHtml(data.documents || []),
              confirmButtonText: "Cerrar",
              confirmButtonColor: "#1b447d",
            });
          }
          var panel = root.querySelector("#scm-panel-servicios-publicos-pendientes");
          return reloadPendingPanel(panel, "rsp_", actionServiciosPublicosPendientes, "rsp_table", "rsp_kpis");
        })
        .catch(function (error) {
          var message = error && error.message ? error.message : "No se pudo registrar la revisión.";
          if (errorBox) {
            errorBox.hidden = false;
            errorBox.textContent = message;
            errorBox.focus && errorBox.focus();
          }
          showToast("error", message);
        })
        .finally(function () {
          form.setAttribute("aria-busy", "false");
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalText;
          }
        });
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
      modal._scmTrigger = btn;
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
        ? "Se crea este ticket para coordinar y documentar la revision preventiva anual del inmueble conforme a la fecha de inicio del contrato de arrendamiento."
        : "";
      var evidenceFields = isPreventiva
        ? ""
        : '<label class="scm-seg-field"><span>Imagenes / evidencias</span><input type="file" name="imagen[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
          renderPasteEvidenceBox("imagen[]") +
          renderTicketDocumentFields();

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

      function adminTicketHiddenField(name, value) {
        return (
          '<input type="hidden" name="' +
          escHtml(name) +
          '" value="' +
          escHtml(value) +
          '">'
        );
      }

      function adminTicketHiddenFields(fields) {
        return fields
          .map(function (field) {
            return adminTicketHiddenField(field[0], field[1]);
          })
          .join("");
      }

      function adminTicketPreviewItem(label, value) {
        return (
          '<div class="scm-admin-ticket-preview-item"><span>' +
          escHtml(label) +
          "</span><strong>" +
          escHtml(value || "-") +
          "</strong></div>"
        );
      }

      var commonTicketHiddenFields = adminTicketHiddenFields([
        ["ticket_mode", mode],
        ["contract_pk", contractDataset(btn, "contractPk")],
        ["id_contrato", contractDataset(btn, "contractPk")],
        ["id_inmueble", contractDataset(btn, "idInmueble")],
        ["id_arrendatario", contractDataset(btn, "idArrendatario")],
        ["id_propietario", contractDataset(btn, "idPropietario")],
        ["id_sucursal", contractDataset(btn, "idSucursal")],
        ["id_inventario", contractDataset(btn, "idInventario")],
        ["fecha_final_contrato", contractDataset(btn, "fechaFinalContrato")],
      ]);

      if (isPreventiva) {
        body.innerHTML =
          '<form class="scm-admin-ticket-form scm-admin-ticket-form--preview" method="post" enctype="multipart/form-data" autocomplete="off">' +
          commonTicketHiddenFields +
          adminTicketHiddenFields([
            ["solicitante_tipo", "arrendatario"],
            ["prioridad", defaultPrioridad],
            ["departamento", defaultDepartamento],
            ["tema_ayuda", defaultTema],
            ["contrato", contractCode],
            ["inmueble", inmueble],
            ["direccion", direccion],
            ["barrio", contractDataset(btn, "barrio")],
            ["registro_fotografico", contractDataset(btn, "registroFotografico")],
            ["propietario", contractDataset(btn, "propietario")],
            ["correo_propietario", contractDataset(btn, "correoPropietario")],
            ["celular_propietario", contractDataset(btn, "celularPropietario")],
            ["arrendatario", contractDataset(btn, "arrendatario")],
            ["correo_arrendatario", contractDataset(btn, "correoArrendatario")],
            ["celular_arrendatario", contractDataset(btn, "celularArrendatario")],
            ["asunto", defaultAsunto],
            ["descripcion", defaultDescripcion],
          ]) +
          '<div class="scm-admin-ticket-summary scm-admin-ticket-summary--locked">' +
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
          '<section class="scm-admin-ticket-section scm-admin-ticket-section--locked">' +
          '<h5 class="scm-admin-ticket-section-title">Datos fijos de la preventiva</h5>' +
          '<p class="scm-admin-ticket-preview-note">Estos datos se generan automaticamente para revision preventiva y no se pueden modificar desde este apartado.</p>' +
          '<div class="scm-admin-ticket-preview-grid">' +
          adminTicketPreviewItem("Prioridad", defaultPrioridad) +
          adminTicketPreviewItem("Departamento", defaultDepartamento) +
          adminTicketPreviewItem("Tema de ayuda", defaultTema) +
          adminTicketPreviewItem("Solicitante", "Arrendatario") +
          adminTicketPreviewItem("Contrato", contractCode) +
          adminTicketPreviewItem("Inmueble", inmueble) +
          adminTicketPreviewItem("Direccion", direccion) +
          adminTicketPreviewItem("Barrio", contractDataset(btn, "barrio")) +
          adminTicketPreviewItem("Propietario", contractDataset(btn, "propietario")) +
          adminTicketPreviewItem("Arrendatario", contractDataset(btn, "arrendatario")) +
          adminTicketPreviewItem("Correo propietario", contractDataset(btn, "correoPropietario")) +
          adminTicketPreviewItem("Correo arrendatario", contractDataset(btn, "correoArrendatario")) +
          "</div>" +
          "</section>" +
          '<section class="scm-admin-ticket-section">' +
          '<h5 class="scm-admin-ticket-section-title">Responsable</h5>' +
          '<div class="scm-admin-ticket-responsible-row">' +
          '<label class="scm-seg-field"><span>Funcionario responsable</span><select name="id_empleado" required>' +
          adminTicketEmployeeOptions(defaultEmpleado) +
          "</select></label>" +
          "</div>" +
          "</section>" +
          renderNotifyTargets(["solicitante"], ["arrendatario", "empleado"]) +
          '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Crear ticket</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
          "</form>";
      } else {
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
        evidenceFields +
        renderNotifyTargets(
          isPreventiva ? ["solicitante"] : [],
          isPreventiva ? ["arrendatario", "empleado"] : null
        ) +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Crear ticket</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      }

      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      document.body.classList.add("scm-modal-open");
      var closeButton = modal.querySelector("[data-admin-ticket-close]");
      if (closeButton && closeButton.focus) {
        closeButton.focus();
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
        return false;
      }
      var modal = root.querySelector("#scm-case-modal.open");
      if (!modal) {
        return false;
      }
      var btn = root.querySelector(
        '.scm-btn-case[data-ticket-pk="' + cssAttrValue(ticketPk) + '"]',
      );
      if (btn) {
        window.scmOpenCase(btn);
        return true;
      }
      closeCaseModal(modal);
      showToast(
        "success",
        "Cambios guardados. El caso ya no aparece en el filtro actual.",
      );
      return false;
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
      }).catch(function () {
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
          allowOutsideClick: false,
          allowEscapeKey: false,
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
      } else if (activeKey === "preventivas-pendientes") {
        activeKey = "preventiva";
      } else if (activeKey === "servicios-publicos-pendientes") {
        activeKey = "servicios_publicos_pendientes";
      } else if (activeKey === "actividades-administrativas" && activePanel) {
        var activeAdministrativePanel = activePanel.querySelector(
          ".scm-admin-activity-panel.active",
        );
        activeKey = administrativeActivityKeyFromPanelId(
          activeAdministrativePanel ? activeAdministrativePanel.id : "",
        );
      }
      if (activeKey === "mant" && form) {
        return doFetch(new FormData(form));
      } else if (activeKey === "calendario_actividades") {
        initCalendarPanel();
        var calendarRefresh = root.querySelector(
          "#scm-panel-calendario-actividades [data-scm-calendar-refresh]",
        );
        if (calendarRefresh) {
          calendarRefresh.click();
        }
        return Promise.resolve();
      } else if (activeKey === "preventivas_pendientes") {
        return reloadPendingPanel(
          activeAdministrativePanel ||
            root.querySelector("#scm-panel-preventivas-pendientes"),
          "spp_",
          actionPreventivasPendientes,
          "spp_table",
          "spp_kpis",
        );
      } else if (activeKey === "servicios_publicos_pendientes") {
        return reloadPendingPanel(
          activeAdministrativePanel ||
            root.querySelector("#scm-panel-servicios-publicos-pendientes"),
          "rsp_",
          actionServiciosPublicosPendientes,
          "rsp_table",
          "rsp_kpis",
        );
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

    root.addEventListener("scm:case-action-saved", function (event) {
      var detail = event && event.detail ? event.detail : {};
      refreshCaseAfterSave(detail.ticketPk || "", detail.fromNode || root);
    });

    function administrativeActivityKeyFromPanelId(panelId) {
      if (panelId === "scm-panel-cotizaciones-mantenimiento") {
        return "cotizaciones_mantenimiento";
      }
      if (panelId === "scm-panel-calendario-actividades") {
        return "calendario_actividades";
      }
      if (panelId === "scm-panel-admin-notificaciones") {
        return "notificaciones";
      }
      if (panelId === "scm-panel-gestiones-cobro") {
        return "gestiones_cobro";
      }
      if (panelId === "scm-panel-preventivas-pendientes") {
        return "preventivas_pendientes";
      }
      if (panelId === "scm-panel-servicios-publicos-pendientes") {
        return "servicios_publicos_pendientes";
      }
      if (panelId === "scm-panel-reportes-administrativos-pendientes") {
        return "reportes_administrativos_pendientes";
      }
      if (panelId === "scm-panel-auditoria-canon-aseguradoras") {
        return "auditoria_canon_aseguradoras";
      }
      return "";
    }

    function bogotaTodayDate() {
      try {
        var formatter = new Intl.DateTimeFormat("en-CA", {
          timeZone: "America/Bogota",
          year: "numeric",
          month: "2-digit",
          day: "2-digit",
        });
        var parts = {};
        formatter.formatToParts(new Date()).forEach(function (part) {
          if (part.type !== "literal") {
            parts[part.type] = part.value;
          }
        });
        if (parts.year && parts.month && parts.day) {
          return parts.year + "-" + parts.month + "-" + parts.day;
        }
      } catch (err) {}
      return new Date().toISOString().slice(0, 10);
    }

    function reloadPendingPanel(panel, prefix, action, tableId, kpisId) {
      if (!panel || !action) {
        return Promise.resolve(false);
      }
      var form = panel.querySelector("#" + prefix + "form");
      if (!form) {
        return Promise.resolve(false);
      }
      var fd = new FormData(form);
      fd.append("action", action);
      fd.append("nonce", nonce);
      var spinner = panel.querySelector("#" + prefix + "spinner");
      if (spinner) {
        spinner.classList.add("active");
      }
      panel.setAttribute("data-scm-loading", "1");
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
                "No se pudo recargar el listado.",
            );
          }
          var data = json.data || {};
          var table = panel.querySelector("#" + tableId);
          var kpis = panel.querySelector("#" + kpisId);
          if (table && typeof data.table_html === "string") {
            table.innerHTML = data.table_html;
          }
          if (kpis && typeof data.kpis_html === "string") {
            kpis.innerHTML = data.kpis_html;
          }
          var headerCountId =
            prefix === "spp_"
              ? "spp-kpi-count"
              : prefix === "rsp_"
                ? "rsp-kpi-count"
                : "";
          var headerCount = headerCountId
            ? panel.querySelector("#" + headerCountId)
            : null;
          if (headerCount && typeof data.count === "string") {
            headerCount.textContent = data.count;
          }
          panel.setAttribute("data-scm-loaded", "1");
          return true;
        })
        .catch(function (err) {
          showToast(
            "error",
            err && err.message ? err.message : "No se pudo recargar el listado.",
          );
          return false;
        })
        .finally(function () {
          if (spinner) {
            spinner.classList.remove("active");
          }
          panel.setAttribute("data-scm-loading", "0");
        });
    }

    function refreshAfterContractReceived(button) {
      var contractPanel = button.closest
        ? button.closest("[data-scm-contracts]")
        : null;
      if (contractPanel) {
        root.dispatchEvent(new CustomEvent("scm:contracts-refresh"));
        return Promise.resolve();
      }
      var preventivePanel = button.closest
        ? button.closest("#scm-panel-preventivas-pendientes")
        : null;
      if (preventivePanel) {
        return reloadPendingPanel(
          preventivePanel,
          "spp_",
          actionPreventivasPendientes,
          "spp_table",
          "spp_kpis",
        );
      }
      var utilitiesPanel = button.closest
        ? button.closest("#scm-panel-servicios-publicos-pendientes")
        : null;
      if (utilitiesPanel) {
        return reloadPendingPanel(
          utilitiesPanel,
          "rsp_",
          actionServiciosPublicosPendientes,
          "rsp_table",
          "rsp_kpis",
        );
      }
      return refreshActiveTab();
    }

    function submitContractReceived(button, fechaRecibo) {
      if (!ajaxUrl || !actionContratoRecibido) {
        showToast("error", "Accion no disponible.");
        return Promise.resolve();
      }
      var contractId = button.getAttribute("data-contract-id") || "";
      if (!contractId) {
        showToast("error", "No se encontro el contrato.");
        return Promise.resolve();
      }
      var originalText = button.textContent;
      button.disabled = true;
      button.textContent = "Guardando...";
      var fd = new FormData();
      fd.append("action", actionContratoRecibido);
      fd.append("nonce", nonce);
      fd.append("contract_id", contractId);
      fd.append("fecha_recibo", fechaRecibo);
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
                "No se pudo marcar el contrato como recibido.",
            );
          }
          showToast(
            "success",
            (json.data && json.data.message) ||
              "Contrato marcado como recibido.",
          );
          return refreshAfterContractReceived(button);
        })
        .catch(function (err) {
          showToast(
            "error",
            err && err.message
              ? err.message
              : "No se pudo marcar el contrato como recibido.",
          );
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = originalText;
        });
    }

    function openContractReceivedPrompt(button) {
      var code = button.getAttribute("data-contract-code") || "";
      if (window.Swal && typeof window.Swal.fire === "function") {
        window.Swal.fire({
          title: "Contrato recibido",
          text: code ? "Contrato " + code : "Selecciona la fecha de recibo.",
          input: "date",
          inputLabel: "Fecha de recibo",
          inputValue: bogotaTodayDate(),
          showCancelButton: true,
          allowOutsideClick: false,
          allowEscapeKey: false,
          confirmButtonText: "Marcar recibido",
          cancelButtonText: "Cancelar",
          confirmButtonColor: "#10b981",
          inputValidator: function (value) {
            return String(value || "").trim()
              ? undefined
              : "La fecha de recibo es obligatoria.";
          },
        }).then(function (result) {
          if (!result || !result.isConfirmed) {
            return;
          }
          submitContractReceived(button, String(result.value || "").trim());
        });
        return;
      }
      var fecha = window.prompt("Fecha de recibo (AAAA-MM-DD):", bogotaTodayDate());
      if (fecha === null) {
        return;
      }
      fecha = String(fecha || "").trim();
      if (!fecha) {
        showToast("error", "La fecha de recibo es obligatoria.");
        return;
      }
      submitContractReceived(button, fecha);
    }

    function refreshAfterPreventivaPostponed(button) {
      var preventivePanel = button.closest
        ? button.closest("#scm-panel-preventivas-pendientes")
        : null;
      if (preventivePanel) {
        return reloadPendingPanel(
          preventivePanel,
          "spp_",
          actionPreventivasPendientes,
          "spp_table",
          "spp_kpis",
        );
      }
      return refreshActiveTab();
    }

    function submitPreventivaPostpone(button, fechaUltima) {
      if (!ajaxUrl || !actionContratoUltimaPreventiva) {
        showToast("error", "Accion no disponible.");
        return Promise.resolve();
      }
      var contractId = button.getAttribute("data-contract-id") || "";
      if (!contractId) {
        showToast("error", "No se encontro el contrato.");
        return Promise.resolve();
      }
      var originalText = button.textContent;
      button.disabled = true;
      button.textContent = "Guardando...";
      var fd = new FormData();
      fd.append("action", actionContratoUltimaPreventiva);
      fd.append("nonce", nonce);
      fd.append("contract_id", contractId);
      fd.append("ultima_revision_preventiva", fechaUltima);
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
                "No se pudo actualizar la última preventiva.",
            );
          }
          showToast(
            "success",
            (json.data && json.data.message) ||
              "Última preventiva actualizada.",
          );
          return refreshAfterPreventivaPostponed(button);
        })
        .catch(function (err) {
          showToast(
            "error",
            err && err.message
              ? err.message
              : "No se pudo actualizar la última preventiva.",
          );
        })
        .finally(function () {
          button.disabled = false;
          button.textContent = originalText;
        });
    }

    function openPreventivaPostponePrompt(button) {
      var code = button.getAttribute("data-contract-code") || "";
      if (window.Swal && typeof window.Swal.fire === "function") {
        window.Swal.fire({
          title: "Pasar preventiva a próximo año",
          text: code
            ? "Contrato " + code
            : "Selecciona la fecha base de última preventiva.",
          input: "date",
          inputLabel: "Fecha para última preventiva",
          inputValue: bogotaTodayDate(),
          showCancelButton: true,
          allowOutsideClick: false,
          allowEscapeKey: false,
          confirmButtonText: "Guardar fecha",
          cancelButtonText: "Cancelar",
          confirmButtonColor: "#1f4f99",
          inputValidator: function (value) {
            return String(value || "").trim()
              ? undefined
              : "La fecha de última preventiva es obligatoria.";
          },
        }).then(function (result) {
          if (!result || !result.isConfirmed) {
            return;
          }
          submitPreventivaPostpone(button, String(result.value || "").trim());
        });
        return;
      }
      var fecha = window.prompt(
        "Fecha para última preventiva (AAAA-MM-DD):",
        bogotaTodayDate(),
      );
      if (fecha === null) {
        return;
      }
      fecha = String(fecha || "").trim();
      if (!fecha) {
        showToast("error", "La fecha de última preventiva es obligatoria.");
        return;
      }
      submitPreventivaPostpone(button, fecha);
    }

    function closePreventivaTicketsModal(modal) {
      modal =
        modal ||
        root.querySelector("#scm-preventiva-tickets-modal.open");
      if (!modal) {
        return;
      }
      modal.classList.remove("open");
      modal.setAttribute("aria-hidden", "true");
      var body = modal.querySelector("[data-preventiva-tickets-body]");
      if (body) {
        body.innerHTML = "";
      }
    }

    function ensurePreventivaTicketsModal() {
      var modal = root.querySelector("#scm-preventiva-tickets-modal");
      if (modal) {
        return modal;
      }
      modal = document.createElement("div");
      modal.id = "scm-preventiva-tickets-modal";
      modal.className =
        "scm-standalone-detail-modal scm-preventiva-tickets-modal";
      modal.setAttribute("aria-hidden", "true");
      modal.innerHTML =
        '<div class="scm-standalone-detail-dialog scm-preventiva-tickets-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-preventiva-tickets-title">' +
        '<button type="button" class="scm-standalone-detail-close" data-close-preventiva-tickets aria-label="Cerrar">&times;</button>' +
        '<div class="scm-standalone-detail-head"><h4 class="scm-standalone-detail-title" id="scm-preventiva-tickets-title">Tickets preventivos</h4></div>' +
        '<div class="scm-standalone-detail-body" data-preventiva-tickets-body></div>' +
        "</div>";
      root.appendChild(modal);
      modal.addEventListener("click", function (event) {
        if (event.target === modal) {
          closePreventivaTicketsModal(modal);
          return;
        }
        var closeBtn =
          event.target && event.target.closest
            ? event.target.closest("[data-close-preventiva-tickets]")
            : null;
        if (closeBtn) {
          event.preventDefault();
          closePreventivaTicketsModal(modal);
        }
      });
      return modal;
    }

    function openPreventivaTicketsPopup(button) {
      var targetId = button.getAttribute("data-target") || "";
      var sourceRow = targetId
        ? root.querySelector("#" + cssAttrValue(targetId))
        : null;
      var sourceHtml = "";
      if (sourceRow) {
        if (
          sourceRow.tagName &&
          sourceRow.tagName.toLowerCase() === "template"
        ) {
          sourceHtml = sourceRow.innerHTML || "";
        } else {
          var sourceCell = sourceRow.querySelector("td");
          sourceHtml = sourceCell ? sourceCell.innerHTML || "" : "";
        }
      }
      if (!sourceHtml) {
        showToast("error", "No se encontraron tickets preventivos.");
        return;
      }
      var modal = ensurePreventivaTicketsModal();
      var title = modal.querySelector("#scm-preventiva-tickets-title");
      var body = modal.querySelector("[data-preventiva-tickets-body]");
      var contractCode = String(button.getAttribute("data-contract-code") || "").trim();
      if (title) {
        title.textContent = contractCode
          ? "Tickets preventivos del contrato " + contractCode
          : "Tickets preventivos del contrato";
      }
      if (body) {
        body.innerHTML = sourceHtml;
      }
      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      var firstCaseBtn = body ? body.querySelector(".scm-btn-case") : null;
      if (firstCaseBtn && firstCaseBtn.focus) {
        window.setTimeout(function () {
          firstCaseBtn.focus();
        }, 30);
      }
    }

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
            });
          }
        });
    }

    function updateInlineCotizacionResponseFields(scope) {
      if (!scope) {
        return;
      }
      var select = scope.querySelector("select[name='estado_cotizacion']");
      if (!select) {
        return;
      }
      var value = String(select.value || "").trim().toLowerCase();
      var motivoWrap = scope.querySelector(".scm-cotizacion-motivo");
      var financiacionWrap = scope.querySelector(".scm-cotizacion-financiacion");
      if (motivoWrap) {
        motivoWrap.style.display = value === "desaprobada" ? "" : "none";
      }
      if (financiacionWrap) {
        financiacionWrap.style.display = value === "aprobada" ? "" : "none";
      }
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

    function parseExecutionDate(value) {
      var raw = String(value || "").trim();
      if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        return "";
      }
      return raw;
    }

    function executionNumber(value) {
      var n = Number(value || 0);
      return Number.isFinite(n) ? n : 0;
    }

    function unwrapCalendarRows(json) {
      var data = json && json.data ? json.data : json;
      if (Array.isArray(data)) return data;
      if (data && Array.isArray(data.data)) return data.data;
      if (data && Array.isArray(data.eventos)) return data.eventos;
      if (data && data.data && Array.isArray(data.data.data)) return data.data.data;
      return [];
    }

    function isCalendarDone(row) {
      var value = String(
        row && (row.estado || row.realizado || row.fue_realizado || ""),
      ).trim().toLowerCase();
      return ["si", "sí", "realizado", "realizada", "1", "true"].indexOf(value) !== -1;
    }

    function isCalendarAppointment(row) {
      var esCita = String(row && (row.es_cita || row.cita || "")).trim().toLowerCase();
      if (["si", "sí", "1", "true"].indexOf(esCita) !== -1) {
        return true;
      }
      var text = [
        row && row.categoria,
        row && row.nombre_categoria,
        row && row.titulo,
        row && row.title,
      ].join(" ").toLowerCase();
      return /cita|preventiva|correctiva|revisi[oó]n/.test(text);
    }

    function formatExecutionDate(value) {
      var raw = String(value || "").trim();
      if (!raw) return "-";
      var normalized = raw.replace(" ", "T");
      var date = new Date(normalized);
      if (Number.isNaN(date.getTime())) {
        return raw;
      }
      var pad = function (n) { return String(n).padStart(2, "0"); };
      return (
        pad(date.getDate()) +
        "/" +
        pad(date.getMonth() + 1) +
        "/" +
        date.getFullYear() +
        " " +
        pad(date.getHours()) +
        ":" +
        pad(date.getMinutes())
      );
    }

    function addExecutionSummary(summary, item) {
      var key = item.funcionario_key || ("name:" + String(item.funcionario || "Sin funcionario").toLowerCase());
      if (!summary[key]) {
        summary[key] = {
          funcionario_key: key,
          funcionario_id: item.funcionario_id || "",
          funcionario: item.funcionario || "Sin funcionario",
          funcionario_label: item.funcionario_label || item.funcionario || "Sin funcionario",
          respuestas: 0,
          actualizaciones: 0,
          seguimientos: 0,
          citas_realizadas: 0,
          total_acciones: 0,
          _casos: {},
        };
      }
      if (item.type === "respuesta") summary[key].respuestas += 1;
      if (item.type === "actualizacion") summary[key].actualizaciones += 1;
      if (item.type === "seguimiento") summary[key].seguimientos += 1;
      if (item.type === "cita_realizada") summary[key].citas_realizadas += 1;
      summary[key].total_acciones += 1;
      if (item.ticket && item.ticket !== "-") {
        summary[key]._casos[String(item.ticket)] = true;
      }
    }

    function mergeExecutionCalendar(baseData, calendarRows) {
      var data = Object.assign({}, baseData || {});
      var details = Array.isArray(data.details) ? data.details.slice() : [];
      var summaryMap = {};
      (Array.isArray(data.summary) ? data.summary : []).forEach(function (row) {
        var key = row.funcionario_key || ("name:" + String(row.funcionario || "Sin funcionario").toLowerCase());
        summaryMap[key] = Object.assign({ _casos: {} }, row);
        if (Array.isArray(row.case_keys)) {
          row.case_keys.forEach(function (caseKey) {
            if (caseKey) summaryMap[key]._casos[String(caseKey)] = true;
          });
        } else if (row.casos && !summaryMap[key]._casos.__base_count) {
          summaryMap[key]._casos.__base_count = Number(row.casos || 0);
        }
      });

      calendarRows.forEach(function (row) {
        if (!isCalendarDone(row) || !isCalendarAppointment(row)) {
          return;
        }
        var employeeId = String(row.id_empleado || row.funcionario_id || row.id_funcionario || "").trim();
        var employeeName = String(row.funcionario || row.nombre_funcionario || row.empleado || "").trim() || "Sin funcionario";
        var key = employeeId ? "id:" + employeeId : "name:" + employeeName.toLowerCase();
        var ticket = String(row.id_ticket || row.ticket || "").trim();
        var item = {
          id: String(row.id || row._ID || ""),
          type: "cita_realizada",
          label: "Cita realizada",
          fecha_ts: Math.floor(new Date(String(row.fecha_inicio || row.inicio || "").replace(" ", "T")).getTime() / 1000) || 0,
          fecha: formatExecutionDate(row.fecha_inicio || row.inicio || ""),
          funcionario_id: employeeId,
          funcionario: employeeName,
          funcionario_label: employeeId ? employeeName + " (" + employeeId + ")" : employeeName,
          funcionario_key: key,
          ticket_pk: ticket,
          ticket: ticket || "-",
          asunto: String(row.titulo || row.title || row.categoria || "Cita realizada"),
          contrato: "",
          inmueble: "",
          estado: "Realizado",
          estado_admin: "",
          detalle: String(row.observacion || row.descripcion || row.titulo || "Cita marcada como realizada."),
        };
        details.push(item);
        addExecutionSummary(summaryMap, item);
      });

      details.sort(function (a, b) {
        return executionNumber(b.fecha_ts) - executionNumber(a.fecha_ts);
      });
      data.details = details.slice(0, 1000);
      data.summary = Object.keys(summaryMap).map(function (key) {
        var row = summaryMap[key];
        var caseKeys = Object.keys(row._casos || {}).filter(function (key) {
          return key !== "__base_count";
        });
        var baseCount = row._casos && row._casos.__base_count ? Number(row._casos.__base_count) : 0;
        var caseCount = Math.max(baseCount, caseKeys.length);
        delete row._casos;
        delete row.case_keys;
        row.casos = caseCount;
        return row;
      }).sort(function (a, b) {
        return executionNumber(b.total_acciones) - executionNumber(a.total_acciones);
      });
      var totals = {
        funcionarios: data.summary.length,
        respuestas: 0,
        actualizaciones: 0,
        seguimientos: 0,
        citas_realizadas: 0,
        casos: 0,
        total_acciones: 0,
      };
      var caseSet = {};
      data.summary.forEach(function (row) {
        totals.respuestas += executionNumber(row.respuestas);
        totals.actualizaciones += executionNumber(row.actualizaciones);
        totals.seguimientos += executionNumber(row.seguimientos);
        totals.citas_realizadas += executionNumber(row.citas_realizadas);
        totals.total_acciones += executionNumber(row.total_acciones);
      });
      data.details.forEach(function (item) {
        if (item.ticket && item.ticket !== "-") caseSet[String(item.ticket)] = true;
      });
      totals.casos = Object.keys(caseSet).length;
      data.totals = totals;
      return data;
    }

    function renderExecutionMetrics(data, warning) {
      var panel = root.querySelector("[data-scm-execution-panel]");
      if (!panel) return;
      var kpis = panel.querySelector("[data-scm-execution-kpis]");
      var summary = panel.querySelector("[data-scm-execution-summary]");
      var details = panel.querySelector("[data-scm-execution-details]");
      var status = panel.querySelector("[data-scm-execution-status]");
      var totals = (data && data.totals) || {};
      if (status) {
        status.textContent = warning || ("Resumen del " + (data.from || "-") + " al " + (data.to || "-") + ".");
        status.classList.toggle("is-warning", Boolean(warning));
      }
      if (kpis) {
        var kpiRows = [
          ["Funcionarios", totals.funcionarios || 0],
          ["Respuestas", totals.respuestas || 0],
          ["Actualizaciones", totals.actualizaciones || 0],
          ["Seguimientos", totals.seguimientos || 0],
          ["Citas realizadas", totals.citas_realizadas || 0],
          ["Casos trabajados", totals.casos || 0],
          ["Acciones totales", totals.total_acciones || 0],
        ];
        kpis.innerHTML = kpiRows.map(function (row) {
          return '<article class="scm-execution-kpi"><span>' + escHtml(row[0]) + '</span><strong>' + escHtml(String(row[1])) + "</strong></article>";
        }).join("");
      }
      if (summary) {
        var summaryRows = Array.isArray(data.summary) ? data.summary : [];
        summary.innerHTML = summaryRows.length
          ? '<div class="scm-execution-card-grid">' + summaryRows.map(function (row) {
              return (
                '<article class="scm-execution-employee-card">' +
                '<h4>' + escHtml(row.funcionario_label || row.funcionario || "Sin funcionario") + '</h4>' +
                '<div class="scm-execution-card-stats">' +
                '<span><strong>' + escHtml(String(row.respuestas || 0)) + '</strong> Respuestas</span>' +
                '<span><strong>' + escHtml(String(row.actualizaciones || 0)) + '</strong> Actualizaciones</span>' +
                '<span><strong>' + escHtml(String(row.seguimientos || 0)) + '</strong> Seguimientos</span>' +
                '<span><strong>' + escHtml(String(row.citas_realizadas || 0)) + '</strong> Citas realizadas</span>' +
                '<span><strong>' + escHtml(String(row.casos || 0)) + '</strong> Casos</span>' +
                '</div>' +
                '</article>'
              );
            }).join("") + '</div>'
          : '<div class="scm-empty">No hay movimientos para el rango seleccionado.</div>';
      }
      if (details) {
        var detailRows = Array.isArray(data.details) ? data.details : [];
        details.innerHTML = detailRows.length
          ? '<div class="scm-execution-table-wrap"><table class="scm-execution-table"><thead><tr><th>Fecha</th><th>Funcionario</th><th>Caso</th><th>Acci&oacute;n</th><th>Detalle</th></tr></thead><tbody>' +
            detailRows.map(function (item) {
              var meta = [];
              if (item.asunto) meta.push(item.asunto);
              if (item.contrato) meta.push("Contrato #" + item.contrato);
              if (item.inmueble) meta.push("Inmueble " + item.inmueble);
              var typeClass = "is-" + String(item.type || "accion").replace(/_/g, "-");
              return (
                '<tr>' +
                '<td><strong>' + escHtml(item.fecha || "-") + '</strong></td>' +
                '<td>' + escHtml(item.funcionario_label || item.funcionario || "-") + '</td>' +
                '<td><span class="scm-execution-case">#' + escHtml(item.ticket || "-") + '</span>' + (meta.length ? '<small>' + escHtml(meta.join(" · ")) + '</small>' : '') + '</td>' +
                '<td><span class="scm-execution-badge ' + escHtml(typeClass) + '">' + escHtml(item.label || "Acci\u00f3n") + '</span></td>' +
                '<td>' + escHtml(item.detalle || "-") + '</td>' +
                '</tr>'
              );
            }).join("") +
            '</tbody></table></div>'
          : '<div class="scm-empty">No hay detalle caso por caso para mostrar.</div>';
      }
    }

    function loadExecutionCalendarRows(filters) {
      return calendarApiRequest("filtrar_eventos_admin", {
        id_empleado: filters.funcionario || "",
        fecha_inicio: filters.fecha_desde,
        fecha_fin: filters.fecha_hasta,
        estado: "Si",
        pagina: 1,
        limite: 1000,
      }).then(function (json) {
        if (!json || json.success === false) {
          throw new Error((json && json.message) || "No se pudieron cargar las citas.");
        }
        return unwrapCalendarRows(json);
      });
    }

    function loadMetricsExecution(force) {
      var panel = root.querySelector("[data-scm-execution-panel]");
      if (!panel || !ajaxUrl || !actionMetricsExecution) {
        return Promise.resolve();
      }
      if (!force && panel.getAttribute("data-scm-execution-loaded") === "1") {
        return Promise.resolve();
      }
      var form = panel.querySelector("[data-scm-execution-form]");
      var status = panel.querySelector("[data-scm-execution-status]");
      var fd = form ? new FormData(form) : new FormData();
      var filters = {
        fecha_desde: parseExecutionDate(fd.get("fecha_desde")) || new Date().toISOString().slice(0, 10),
        fecha_hasta: parseExecutionDate(fd.get("fecha_hasta")) || new Date().toISOString().slice(0, 10),
        funcionario: String(fd.get("funcionario") || "").trim(),
      };
      if (status) {
        status.textContent = "Cargando resumen de ejecuci\u00f3n...";
        status.classList.remove("is-warning");
      }
      fd.append("action", actionMetricsExecution);
      fd.append("nonce", nonce);
      panel.classList.add("is-loading");
      return fetch(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || "No se pudo cargar el resumen.");
          }
          var baseData = json.data || {};
          return loadExecutionCalendarRows(filters)
            .then(function (calendarRows) {
              return { data: mergeExecutionCalendar(baseData, calendarRows), warning: "" };
            })
            .catch(function () {
              return { data: baseData, warning: "Resumen cargado, pero no fue posible traer citas realizadas del calendario." };
            });
        })
        .then(function (result) {
          panel.setAttribute("data-scm-execution-loaded", "1");
          renderExecutionMetrics(result.data, result.warning);
        })
        .catch(function (err) {
          if (status) {
            status.textContent = err && err.message ? err.message : "No se pudo cargar el resumen.";
            status.classList.add("is-warning");
          }
        })
        .finally(function () {
          panel.classList.remove("is-loading");
        });
    }

    var executionForm = root.querySelector("[data-scm-execution-form]");
    if (executionForm) {
      executionForm.addEventListener("submit", function (e) {
        e.preventDefault();
        loadMetricsExecution(true);
      });
    }

    var initialMetrics = readInitialMetrics();
    var dashboardFilterOptionsLoaded = false;
    var dashboardFilterOptionsPromise = null;

    function replaceSelectOptions(select, rows, valueKey, labelKey) {
      if (!select) return;
      var current = String(
        ((runtime.initialFilters || {})[select.name] || select.value || ""),
      );
      while (select.options.length > 1) {
        select.remove(1);
      }
      (Array.isArray(rows) ? rows : []).forEach(function (row) {
        var value = typeof row === "object" && row !== null
          ? String(row[valueKey] || "")
          : String(row || "");
        if (!value) return;
        var label = typeof row === "object" && row !== null
          ? String(row[labelKey] || value)
          : value;
        var option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        option.selected = current === value;
        select.appendChild(option);
      });
      refreshSelectWidget(select);
    }

    function refreshSelectWidget(select) {
      if (
        !select ||
        !(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)
      ) {
        return;
      }
      var $select = window.jQuery(select);
      if (
        select.classList &&
        select.classList.contains("scm-select2") &&
        !$select.data("select2")
      ) {
        $select.select2({
          width: "100%",
          placeholder:
            select.getAttribute("data-placeholder") || "Buscar y seleccionar...",
          allowClear: true,
          language: {
            noResults: function () {
              return "Sin resultados";
            },
            searching: function () {
              return "Buscando...";
            },
          },
        });
      }
      if ($select.data("select2")) {
        $select.trigger("change.select2");
      }
    }

    function populateDashboardFilterOptions(data) {
      var options = data && data.filter_options ? data.filter_options : {};
      var cotizacionOptions = data.cotizacion_options || {};
      var funcionarioOptions = Array.isArray(options.funcionarios)
        && options.funcionarios.length
        ? options.funcionarios
        : (Array.isArray(cotizacionOptions.funcionarios)
          ? cotizacionOptions.funcionarios
          : []);
      var allowedEmployeeIds = (data.calendar_allowed_employee_ids || config.calendar_allowed_employee_ids || [])
        .map(function (id) { return String(id || "").trim(); })
        .filter(Boolean);
      if (allowedEmployeeIds.length) {
        var allowedEmployeeMap = {};
        allowedEmployeeIds.forEach(function (id) {
          allowedEmployeeMap[id] = true;
        });
        funcionarioOptions = funcionarioOptions.filter(function (row) {
          var id = String((row && (row.id || row.id_empleado || row.employee_id)) || "").trim();
          return !!allowedEmployeeMap[id];
        });
        if (Array.isArray(cotizacionOptions.funcionarios)) {
          cotizacionOptions.funcionarios = cotizacionOptions.funcionarios.filter(function (row) {
            var id = String((row && (row.id || row.id_empleado || row.employee_id)) || "").trim();
            return !!allowedEmployeeMap[id];
          });
        }
      }
      runtime.funcionarios = funcionarioOptions;
      var mappings = [
        ["select[name$='id_empleado'], select[name$='_empleado'], select[name='empleado'], [data-scm-execution-form] select[name='funcionario']", funcionarioOptions, "id", "label"],
        ["select[name$='barrio']", options.barrios || [], "value", "label"],
        ["select[name$='estado_admin']", options.estado_admin || [], "value", "label"],
        ["select[name$='prioridad']", options.prioridad || [], "value", "label"],
        ["select[name$='cotizacion_estado']", options.cotizacion_estado || [], "value", "label"],
        ["select[name$='revision_estado']", options.revision_estado || [], "value", "label"],
        ["#scm_tema", options.tema || [], "value", "label"],
      ];
      mappings.forEach(function (mapping) {
        root.querySelectorAll(mapping[0]).forEach(function (select) {
          replaceSelectOptions(select, mapping[1], mapping[2], mapping[3]);
        });
      });
      replaceSelectOptions(
        root.querySelector("#scmqt_funcionario"),
        Array.isArray(cotizacionOptions.funcionarios) && cotizacionOptions.funcionarios.length
          ? cotizacionOptions.funcionarios
          : funcionarioOptions,
        "id",
        "label",
      );
      replaceSelectOptions(
        root.querySelector("#scmqt_tipo_mantenimiento"),
        cotizacionOptions.tipos_mantenimiento || [],
        "value",
        "label",
      );
      replaceSelectOptions(
        root.querySelector("#scmqt_categoria"),
        cotizacionOptions.categorias || [],
        "value",
        "label",
      );
      config.calendar_allowed_funcionarios = data.calendar_allowed_funcionarios || [];
      config.calendar_allowed_employee_ids = data.calendar_allowed_employee_ids || [];
      config.calendar_current_employee_id = data.calendar_current_employee_id || "";
      persistRuntime(root, runtime);
      try {
        root.dispatchEvent(
          new CustomEvent("scm:dashboard-filter-options-loaded", {
            detail: data,
          }),
        );
      } catch (err) {}
    }

    function loadDashboardFilterOptions() {
      if (dashboardFilterOptionsLoaded || !ajaxUrl || !actionDashboardFilterOptions) {
        return Promise.resolve();
      }
      if (dashboardFilterOptionsPromise) return dashboardFilterOptionsPromise;
      var fd = new FormData();
      fd.append("action", actionDashboardFilterOptions);
      fd.append("nonce", nonce);
      dashboardFilterOptionsPromise = fetchWithTimeout(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success || !json.data) {
            throw new Error("No se pudieron cargar las opciones de filtros.");
          }
          populateDashboardFilterOptions(json.data);
          dashboardFilterOptionsLoaded = true;
        })
        .catch(function (error) {
          dashboardFilterOptionsPromise = null;
          dashboardFilterOptionsLoaded = false;
          showToast("error", error.message || "No se pudieron cargar los filtros.");
        });
      return dashboardFilterOptionsPromise;
    }

    var dashboardHomePromise = null;
    var dashboardMetricsPromise = null;

    function formatDashboardCount(value) {
      var numericValue = Number(value);
      if (!Number.isFinite(numericValue)) return "0";
      return Math.max(0, Math.round(numericValue)).toLocaleString("es-CO");
    }

    function renderDashboardHome(summary, generatedAt) {
      var panel = root.querySelector("#scm-panel-inicio");
      if (!panel) return;

      panel.querySelectorAll("[data-scm-home-metric]").forEach(function (node) {
        var key = node.getAttribute("data-scm-home-metric") || "";
        node.textContent = formatDashboardCount(summary && summary[key]);
      });
      var categories =
        summary && summary.por_categoria && typeof summary.por_categoria === "object"
          ? summary.por_categoria
          : {};
      panel.querySelectorAll("[data-scm-home-category]").forEach(function (node) {
        var category = node.getAttribute("data-scm-home-category") || "";
        node.textContent = formatDashboardCount(categories[category]);
      });

      var status = panel.querySelector("[data-scm-home-status]");
      if (status) status.hidden = true;
      var updated = panel.querySelector("[data-scm-home-updated]");
      if (updated && generatedAt) {
        var timestamp = new Date(generatedAt);
        if (!Number.isNaN(timestamp.getTime())) {
          updated.textContent =
            "Actualizado a las " +
            timestamp.toLocaleTimeString("es-CO", {
              hour: "2-digit",
              minute: "2-digit",
            });
        }
      }
      panel.setAttribute("data-scm-loaded", "1");
    }

    function showDashboardHomeMessage(message, isError) {
      var panel = root.querySelector("#scm-panel-inicio");
      if (!panel) return;
      var status = panel.querySelector("[data-scm-home-status]");
      var textNode = panel.querySelector("[data-scm-home-status-text]");
      var retryButton = panel.querySelector("[data-scm-home-retry]");
      if (status) {
        status.hidden = false;
        status.classList.toggle("is-error", Boolean(isError));
      }
      if (textNode) textNode.textContent = message;
      if (retryButton) retryButton.hidden = !isError;
    }

    function loadDashboardHome() {
      var panel = root.querySelector("#scm-panel-inicio");
      if (!panel || !ajaxUrl || !actionDashboardHome) {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loaded") === "1") {
        return Promise.resolve();
      }
      if (dashboardHomePromise) return dashboardHomePromise;

      panel.setAttribute("aria-busy", "true");
      showDashboardHomeMessage("Cargando el resumen…", false);
      var fd = new FormData();
      fd.append("action", actionDashboardHome);
      fd.append("nonce", nonce);
      dashboardHomePromise = fetchWithTimeout(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success || !json.data) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo cargar el resumen.",
            );
          }
          if (!json.data.summary) {
            panel.setAttribute("data-scm-loaded", "1");
            showDashboardHomeMessage(
              json.data.message || "No hay indicadores disponibles para tu perfil.",
              false,
            );
            return;
          }
          renderDashboardHome(json.data.summary, json.data.generated_at || "");
        })
        .catch(function (error) {
          dashboardHomePromise = null;
          showDashboardHomeMessage(
            error && error.message
              ? error.message
              : "No fue posible cargar el resumen. Puedes reintentarlo.",
            true,
          );
        })
        .finally(function () {
          panel.removeAttribute("aria-busy");
        });
      return dashboardHomePromise;
    }

    function loadDashboardMetrics() {
      var panel = root.querySelector("#scm-panel-metricas");
      if (!panel || !ajaxUrl || !actionDashboardMetrics) {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loaded") === "1") {
        return Promise.resolve();
      }
      if (dashboardMetricsPromise) {
        return dashboardMetricsPromise;
      }

      panel.classList.add("is-loading");
      panel.setAttribute("aria-busy", "true");
      var fd = new FormData();
      fd.append("action", actionDashboardMetrics);
      fd.append("nonce", nonce);
      dashboardMetricsPromise = fetchWithTimeout(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success || !json.data || !json.data.metrics) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudieron cargar las métricas.",
            );
          }
          initialMetrics = json.data.metrics;
          panel.setAttribute("data-scm-metrics", JSON.stringify(initialMetrics));
          panel.setAttribute("data-scm-loaded", "1");
          var loadingState = panel.querySelector("[data-scm-metrics-loading]");
          if (loadingState) {
            loadingState.hidden = true;
          }
          var activeCategoryMetrics = getCategoryMetricSet(
            initialMetrics,
            activeMetricCategory,
          );
          renderMetricsCharts(activeCategoryMetrics, activeMetricCategory);
          renderGuardianMetrics(initialMetrics.web || {});
          applyRevisionKpiVisibility(
            "scm-",
            activeCategoryMetrics.con_revision,
            activeCategoryMetrics.sin_revision,
          );
        })
        .catch(function (error) {
          dashboardMetricsPromise = null;
          var loadingState = panel.querySelector("[data-scm-metrics-loading]");
          if (loadingState) {
            loadingState.hidden = false;
            loadingState.textContent = "No fue posible cargar los indicadores. Intenta recargar la página.";
            loadingState.classList.add("is-error");
          }
          showToast(
            "error",
            error && error.message
              ? error.message
              : "No se pudieron cargar las métricas.",
          );
        })
        .finally(function () {
          panel.classList.remove("is-loading");
          panel.removeAttribute("aria-busy");
        });

      return dashboardMetricsPromise;
    }

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
          name = name === "guardian" || name === "ejecucion" ? name : "operativas";
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
              var paneName = btn.getAttribute("data-scm-metric-panel") || "guardian";
              showMetricsPane(paneName);
              if (paneName === "ejecucion") {
                loadDashboardFilterOptions().then(function () {
                  loadMetricsExecution(false);
                });
              } else {
                renderGuardianMetrics(initialMetrics.web || {});
              }
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
      var mantPanel = root.querySelector("#scm-panel-mant");
      if (mantPanel && mantPanel.getAttribute("data-scm-loading") === "1") {
        return Promise.resolve();
      }

      fd.append("action", actionMant);
      fd.append("nonce", nonce);
      fd.append("config", JSON.stringify(config));

      if (mantPanel) {
        mantPanel.setAttribute("data-scm-loading", "1");
      }
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
          if (mantPanel) {
            mantPanel.setAttribute("data-scm-loaded", "1");
          }
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
          if (mantPanel) {
            mantPanel.setAttribute("data-scm-loading", "0");
          }
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
      form.querySelectorAll("select.scm-select").forEach(function (select) {
        if (
          select === cotizacionSelect ||
          select === perPageSelect ||
          (select.closest && select.closest(".scm-cotizacion-dependent"))
        ) {
          return;
        }
        select.addEventListener("change", function () {
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
        if (
          runtime &&
          Array.isArray(runtime.funcionarios) &&
          runtime.funcionarios.length > 0
        ) {
          openAdminTicketModal(adminTicketBtn);
          return;
        }
        withPanelLoader(
          loadDashboardFilterOptions,
          "Cargando responsables",
          "Estamos consultando los funcionarios disponibles.",
        ).then(function () {
          if (
            !runtime ||
            !Array.isArray(runtime.funcionarios) ||
            runtime.funcionarios.length === 0
          ) {
            showToast(
              "error",
              "No fue posible cargar los funcionarios responsables. Intenta nuevamente.",
            );
            return;
          }
          openAdminTicketModal(adminTicketBtn);
        });
        return;
      }

      var publicServicesReviewBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-public-services-review]")
          : null;
      if (publicServicesReviewBtn) {
        e.preventDefault();
        openPublicServicesReviewModal(publicServicesReviewBtn);
        return;
      }

      var contractReceivedBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-mark-contract-received]")
          : null;
      if (contractReceivedBtn) {
        e.preventDefault();
        openContractReceivedPrompt(contractReceivedBtn);
        return;
      }

      var postponePreventivaBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-postpone-preventiva]")
          : null;
      if (postponePreventivaBtn) {
        e.preventDefault();
        openPreventivaPostponePrompt(postponePreventivaBtn);
        return;
      }

      var togglePreventivaTicketsBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-toggle-preventiva-tickets]")
          : null;
      if (togglePreventivaTicketsBtn) {
        e.preventDefault();
        openPreventivaTicketsPopup(togglePreventivaTicketsBtn);
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

    function updateCotizacionStateTabs(form) {
      if (!form) {
        return;
      }
      var stateInput = form.querySelector("input[name='scmqt_estado']");
      var sentSelect = form.querySelector("[name='scmqt_enviada']");
      var current = String(stateInput ? stateInput.value || "" : "").trim();
      var currentSent = String(sentSelect ? sentSelect.value || "" : "").trim();
      root
        .querySelectorAll(".scm-cotizacion-state-tab")
        .forEach(function (btn) {
          var value = String(btn.getAttribute("data-cotizacion-state") || "").trim();
          var sent = String(btn.getAttribute("data-cotizacion-sent") || "").trim();
          var active = false;
          if (sent) {
            active = sent === currentSent && !current;
          } else if (!value) {
            active = !current && !currentSent;
          } else {
            active = value === current && !currentSent;
          }
          btn.classList.toggle("active", active);
          btn.setAttribute("aria-selected", active ? "true" : "false");
        });
    }

    function updateCotizacionKpiVisibility(form) {
      if (!form) {
        return;
      }
      var stateInput = form.querySelector("input[name='scmqt_estado']");
      var sentSelect = form.querySelector("[name='scmqt_enviada']");
      var current = String(stateInput ? stateInput.value || "" : "").trim().toLowerCase();
      var currentSent = String(sentSelect ? sentSelect.value || "" : "").trim().toLowerCase();
      var visible = {
        total: true,
        ordenes: true,
        "valor-total": true,
      };

      if (!current && !currentSent) {
        ["enviadas", "no-enviadas", "aprobadas", "desaprobadas", "esperando-respuesta", "finalizadas"].forEach(function (key) {
          visible[key] = true;
        });
      } else if (currentSent === "no" && !current) {
        visible["no-enviadas"] = true;
      } else if (currentSent === "si" && !current) {
        ["enviadas", "aprobadas", "desaprobadas", "esperando-respuesta", "finalizadas"].forEach(function (key) {
          visible[key] = true;
        });
      } else if (current) {
        var stateMap = {
          aprobada: "aprobadas",
          desaprobada: "desaprobadas",
          "esperando respuesta": "esperando-respuesta",
          finalizado: "finalizadas",
          finalizada: "finalizadas",
        };
        if (stateMap[current]) {
          visible[stateMap[current]] = true;
        }
      }

      root.querySelectorAll("[data-scm-cotizacion-kpi]").forEach(function (card) {
        var key = card.getAttribute("data-scm-cotizacion-kpi") || "";
        var show = !!visible[key];
        card.classList.toggle("scm-kpi-context-hidden", !show);
        card.setAttribute("aria-hidden", show ? "false" : "true");
      });
    }

    function updateCotizacionStateTabCounts(data) {
      if (!data) {
        return;
      }
      var map = {
        "total": data.kpi_tab_total || data.kpi_total || "0",
        "no-enviadas": data.kpi_tab_no_enviadas || data.kpi_no_enviadas || "0",
        "enviadas": data.kpi_tab_enviadas || data.kpi_enviadas || "0",
        "aprobadas": data.kpi_tab_aprobadas || data.kpi_aprobadas || "0",
        "desaprobadas": data.kpi_tab_desaprobadas || data.kpi_desaprobadas || "0",
        "esperando-respuesta":
          data.kpi_tab_esperando_respuesta ||
          data.kpi_esperando_respuesta ||
          "0",
        "finalizadas": data.kpi_tab_finalizadas || data.kpi_finalizadas || "0",
      };
      Object.keys(map).forEach(function (key) {
        var el = root.querySelector("#scm-cotizaciones_mantenimiento-tab-" + key);
        if (el) {
          el.textContent = map[key];
        }
      });
    }

    function enhanceCotizacionSelects(form) {
      if (
        !form ||
        !(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)
      ) {
        return;
      }
      var $ = window.jQuery;
      form.querySelectorAll("select.scm-select2").forEach(function (selectEl) {
        var $select = $(selectEl);
        if ($select.data("select2")) {
          return;
        }
        $select.select2({
          width: "100%",
          placeholder:
            selectEl.getAttribute("data-placeholder") || "Buscar y seleccionar...",
          allowClear: true,
          language: {
            noResults: function () {
              return "Sin resultados";
            },
            searching: function () {
              return "Buscando...";
            },
          },
        });
      });
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
          showToast("error", "No se pudo clasificar la magnitud: " + (err.message || "error"));
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
          tabCards.closest(".scm-admin-activity-panel") ||
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
              updateGenericKPI(tabKey, "total-card", d.kpi_total || "0");
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
              updateGenericKPI(
                tabKey,
                "esperando-respuesta",
                d.kpi_esperando_respuesta || "0",
              );
              updateGenericKPI(
                tabKey,
                "finalizadas",
                d.kpi_finalizadas || "0",
              );
              updateGenericKPI(
                tabKey,
                "ordenes",
                d.kpi_ordenes_total || "0",
              );
              updateGenericKPI(
                tabKey,
                "valor-total",
                d.kpi_valor_total || "$0",
              );
              updateCotizacionStateTabs(tabForm);
              updateCotizacionStateTabCounts(d);
              updateCotizacionKpiVisibility(tabForm);
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

      if (tabKey === "cotizaciones_mantenimiento") {
        enhanceCotizacionSelects(tabForm);
        root
          .querySelectorAll(".scm-cotizacion-state-tab")
          .forEach(function (stateBtn) {
            stateBtn.addEventListener("click", function () {
              var stateInput = tabForm.querySelector("input[name='scmqt_estado']");
              var sentSelect = tabForm.querySelector("[name='scmqt_enviada']");
              if (stateInput) {
                stateInput.value =
                  stateBtn.getAttribute("data-cotizacion-state") || "";
              }
              if (sentSelect) {
                sentSelect.value =
                  stateBtn.getAttribute("data-cotizacion-sent") || "";
              }
              if (pageInput) {
                pageInput.value = "1";
              }
              updateCotizacionStateTabs(tabForm);
              updateCotizacionKpiVisibility(tabForm);
              fetchTab(new FormData(tabForm));
            });
          });
      }

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
      tabForm.querySelectorAll("select.scm-select").forEach(function (select) {
        if (
          select === tabCotizacionSelect ||
          (select.closest && select.closest(".scm-cotizacion-dependent"))
        ) {
          return;
        }
        select.addEventListener("change", function () {
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
            if (window.jQuery && window.jQuery.fn && window.jQuery(s).data("select2")) {
              window.jQuery(s).trigger("change.select2");
            }
          });
          tabForm
            .querySelectorAll("input[type='text'], input[type='date']")
            .forEach(function (i) {
              i.value = "";
            });
          if (tabKey === "cotizaciones_mantenimiento") {
            var stateInput = tabForm.querySelector("input[name='scmqt_estado']");
            if (stateInput) {
              stateInput.value = "";
            }
          }
          if (pageInput) {
            pageInput.value = "1";
          }
          if (tabKey === "cotizaciones_mantenimiento") {
            updateCotizacionStateTabs(tabForm);
            updateCotizacionKpiVisibility(tabForm);
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
      if (tabKey === "cotizaciones_mantenimiento") {
        updateCotizacionKpiVisibility(tabForm);
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
            if (typeof d.form === "string" && d.form) {
              var formWrap = document.createElement("div");
              formWrap.innerHTML = d.form;
              var nextForm = formWrap.querySelector("form");
              var currentFilterCard = tabForm.closest(".scm-filter-card");
              var nextFilterCard = nextForm ? nextForm.closest(".scm-filter-card") : null;
              if (currentFilterCard && nextFilterCard) {
                currentFilterCard.replaceWith(nextFilterCard);
                tabFetchers[statusKey] = makeStatusFetcher(statusPanel);
              }
            }
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
      tabForm.querySelectorAll("select.scm-select").forEach(function (select) {
        if (
          select === tabCotizacionSelect ||
          (select.closest && select.closest(".scm-cotizacion-dependent"))
        ) {
          return;
        }
        select.addEventListener("change", function () {
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

    function buildCotizacionPrintDocument(title, contentHtml, autoPrint) {
      var safeTitle = escHtml(title || "Cotización de mantenimiento");
      return (
        '<!doctype html><html lang="es"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        "<title>" + safeTitle + "</title>" +
        '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">' +
        "<style>" +
        "@page{size:A4;margin:10mm}*{box-sizing:border-box}body{margin:0;background:#fff;color:#0b1f3a;font-family:Poppins,Arial,sans-serif;font-size:11px;line-height:1.45}.scm-cotizacion-native-doc{max-width:980px;margin:0 auto;background:#fff}.scm-cotizacion-native-audience{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:11px;background:#eef5ff}.scm-cotizacion-native-audience span{color:#1f4f99;font-size:8.5px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.scm-cotizacion-native-audience strong{color:#10233f;font-size:10px}.is-audience-destinatario .scm-cotizacion-native-audience{border-color:#fed7aa;background:#fff7ed}.is-audience-destinatario .scm-cotizacion-native-audience span{color:#9a4f00}.scm-cotizacion-native-hero{border-radius:18px;padding:22px 24px;margin-bottom:16px;color:#fff;background:linear-gradient(135deg,#0b1f3a 0%,#1f4f99 68%,#f28c00 170%)}.scm-cotizacion-native-brand{display:flex;align-items:center;justify-content:space-between;gap:18px}.scm-cotizacion-native-brand img{max-width:190px;max-height:58px;object-fit:contain;background:#fff;border-radius:14px;padding:8px}.scm-cotizacion-native-brand span,.scm-cotizacion-native-state span{display:block;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:800;opacity:.82}.scm-cotizacion-native-brand strong{display:block;font-size:28px;line-height:1.05}.scm-cotizacion-native-state{text-align:right}.scm-cotizacion-native-state strong{display:inline-block;margin-top:7px;padding:7px 11px;border-radius:999px;background:#fff;color:#0b1f3a}.scm-cotizacion-native-state .is-pending{color:#fed7aa}.scm-cotizacion-native-state .is-sent{color:#bfdbfe}.scm-cotizacion-native-hero h2{margin:20px 0 0;font-size:21px;line-height:1.2}.scm-cotizacion-native-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.scm-cotizacion-native-grid>div,.scm-cotizacion-native-section,.scm-cotizacion-damage-card{border:1px solid #cbd5e1;border-radius:14px;background:#f8fafc;padding:12px}.scm-cotizacion-native-grid span,.scm-cotizacion-damage-head span{display:block;text-transform:uppercase;letter-spacing:.07em;color:#475569;font-size:8.5px;font-weight:800}.scm-cotizacion-native-grid strong,.scm-cotizacion-damage-head strong{display:block;margin-top:3px;color:#0b1f3a;font-weight:800}.scm-cotizacion-native-section{margin-top:12px;background:#fff}.scm-cotizacion-native-section h3{margin:0 0 10px;color:#0b1f3a;font-size:14px}.scm-cotizacion-native-section h4{margin:8px 0 6px;color:#173f7a;font-size:11px}.scm-cotizacion-native-section-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}.scm-cotizacion-native-section-title>div>span{display:block;color:#475569;font-size:8px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.scm-cotizacion-native-section-title h3{margin-bottom:0}.scm-cotizacion-native-section-title>strong{padding:5px 8px;border-radius:999px;background:#e8f1ff;color:#173f7a;font-size:8.5px}.scm-cotizacion-native-two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}.scm-cotizacion-media-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.scm-cotizacion-media-item{display:block;border:1px solid #d9e4f2;border-radius:12px;padding:8px;text-decoration:none;color:#0b1f3a;background:#f8fafc}.scm-cotizacion-media-item img{width:100%;height:115px;object-fit:cover;border-radius:9px;border:1px solid #e2e8f0}.scm-cotizacion-media-item strong{display:block;margin-top:6px;font-size:9px}.scm-cotizacion-damage-card{overflow:hidden;padding:0}.scm-cotizacion-damage-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 12px;background:#0a285a;color:#fff}.scm-cotizacion-damage-title>div{display:flex;align-items:baseline;gap:5px}.scm-cotizacion-damage-title span{color:#ffbd6b;font-size:8px;font-weight:900;text-transform:uppercase}.scm-cotizacion-damage-title strong,.scm-cotizacion-damage-title p{color:#fff}.scm-cotizacion-damage-title p{margin:0;font-weight:700}.scm-cotizacion-damage-head{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:11px 12px 0}.scm-cotizacion-damage-card>.scm-cotizacion-native-tags,.scm-cotizacion-damage-card>.scm-cotizacion-native-rich,.scm-cotizacion-damage-card>.scm-cotizacion-native-evidence{margin-right:12px;margin-left:12px}.scm-cotizacion-native-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;margin-bottom:8px}.scm-cotizacion-native-tags span{border:1px solid #fed7aa;border-radius:999px;background:#fff7ed;color:#9a3412;padding:4px 8px;font-size:9px;font-weight:800}.scm-cotizacion-native-rich p{margin:0 0 8px;color:#1e293b}.scm-cotizacion-budget-block{overflow:hidden;margin-top:10px;border:1px solid #cbd5e1;border-radius:12px}.scm-cotizacion-budget-block h4{margin:0;padding:8px 10px;background:#0b1f3a;color:#fff!important}.scm-cotizacion-budget-block>.scm-cotizacion-native-empty{padding:10px}.scm-cotizacion-budget-table{width:100%;border-collapse:collapse}.scm-cotizacion-budget-table th,.scm-cotizacion-budget-table td{padding:8px;border-bottom:1px solid #e2e8f0;text-align:left}.scm-cotizacion-budget-table th{background:#0b1f3a;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:.06em}.scm-cotizacion-budget-table td:last-child{text-align:right;font-weight:800}.scm-cotizacion-budget-subtotal,.scm-cotizacion-native-total-box>div{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 10px;border-bottom:1px solid #e2e8f0}.scm-cotizacion-native-total-box{margin-top:12px;border:1px solid #fed7aa;border-radius:14px;overflow:hidden;background:#fff7ed}.scm-cotizacion-native-total-box .is-grand-total{background:#0b1f3a;color:#fff}.scm-cotizacion-native-total-box .is-grand-total strong{font-size:16px;color:#fff}.scm-cotizacion-table-wrap{overflow:hidden;border:1px solid #cbd5e1;border-radius:12px}.scm-cotizacion-balance-table{width:100%;border-collapse:collapse}.scm-cotizacion-balance-table th,.scm-cotizacion-balance-table td{padding:8px 10px;border-bottom:1px solid #dbe7f4;text-align:left}.scm-cotizacion-balance-table th{background:#0b1f3a;color:#fff;font-size:8.5px;text-transform:uppercase}.scm-cotizacion-balance-table th:not(:first-child),.scm-cotizacion-balance-table td:not(:first-child){text-align:right}.scm-cotizacion-balance-table tbody tr:nth-child(even) td{background:#f8fbff}.scm-cotizacion-balance-table tfoot td{background:#fff7ed;font-weight:900}.scm-cotizacion-native-note{border-left:4px solid #f28c00;padding:10px 12px;background:#fff7ed;border-radius:10px}.scm-cotizacion-native-criteria{margin:8px 0 0;padding-left:20px}.scm-cotizacion-native-empty{margin:0;color:#475569;font-weight:600}.scm-cotizacion-native-legal{background:#fff7ed;border-color:#fed7aa}.scm-cotizacion-native-footer{display:flex;justify-content:space-between;gap:18px;margin-top:14px;padding:16px;border-radius:16px;background:#0b1f3a;color:#fff}.scm-cotizacion-native-footer span{display:block;color:#fed7aa;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:800}.scm-cotizacion-native-footer p{margin:4px 0 0;color:#dbeafe}.scm-cotizacion-native-logo{display:grid;grid-template-columns:auto 1fr;align-items:center;column-gap:12px;min-width:330px;padding:10px 14px;border-radius:15px;background:#fff;color:#061d49}.scm-cotizacion-native-logo img{grid-row:span 2;width:72px!important;max-width:72px!important;max-height:50px!important;padding:0!important;border:0!important;background:transparent!important;box-shadow:none!important}.scm-cotizacion-native-logo strong{color:#061d49;font-size:18px;font-weight:900}.scm-cotizacion-native-logo span{color:#64748b}.scm-cotizacion-native-number{text-align:right}.scm-cotizacion-native-number strong{color:#fff;font-size:36px;font-weight:900}.scm-cotizacion-native-hero-bottom{display:flex;align-items:end;justify-content:space-between;gap:16px}.scm-cotizacion-native-eyebrow{margin:0 0 6px;color:rgba(255,255,255,.78);font-size:9px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.scm-cotizacion-native-state em{display:block;margin-top:8px;color:#fff;font-style:normal;font-weight:900}.scm-cotizacion-native-summary{grid-template-columns:repeat(4,minmax(0,1fr))}.scm-cotizacion-native-footer{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.scm-cotizacion-native-footer>div{padding:10px;border:1px solid rgba(255,255,255,.14);border-radius:12px;background:rgba(255,255,255,.07)}@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.scm-cotizacion-native-section,.scm-cotizacion-damage-card,.scm-cotizacion-budget-block,.scm-cotizacion-table-wrap{break-inside:avoid}.scm-cotizacion-media-item img{height:95px}}@media(max-width:760px){.scm-cotizacion-native-grid,.scm-cotizacion-native-two-col,.scm-cotizacion-damage-head,.scm-cotizacion-native-summary,.scm-cotizacion-native-footer{grid-template-columns:1fr}.scm-cotizacion-native-brand,.scm-cotizacion-native-hero-bottom{display:grid}.scm-cotizacion-native-state,.scm-cotizacion-native-number{text-align:left}}" +
        "</style></head><body>" +
        contentHtml +
        (autoPrint ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.focus();window.print();},450);});<\/script>' : "") +
        "</body></html>"
      );
    }

    function openCotizacionPrintWindow(title, contentHtml, autoPrint) {
      var printWin = window.open("", "_blank");
      if (!printWin) {
        showToast("error", "El navegador bloqueo la ventana. Permite ventanas emergentes para exportar.");
        return;
      }
      printWin.document.open();
      printWin.document.write(buildCotizacionPrintDocument(title, contentHtml, autoPrint));
      printWin.document.close();
    }

    function downloadCotizacionPdf(cotizacionId, audience, button) {
      if (!ajaxUrl || !actionCotizacionPdf || !cotizacionId) {
        showToast("error", "No se pudo generar el PDF de la cotización.");
        return;
      }
      audience = audience === "destinatario" ? "destinatario" : "funcionario";
      var originalText = button ? button.textContent : "";
      if (button) {
        button.disabled = true;
        button.textContent = "Generando...";
      }
      var formData = new FormData();
      formData.append("action", actionCotizacionPdf);
      formData.append("nonce", nonce);
      formData.append("id_cotizacion", cotizacionId);
      formData.append("audience", audience);
      fetch(ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
        .then(function (response) {
          var contentType = response.headers.get("content-type") || "";
          if (!response.ok || contentType.indexOf("application/pdf") === -1) {
            return response.text().then(function (text) {
              var message = "No se pudo generar el PDF.";
              try {
                var json = JSON.parse(text);
                message =
                  (json && json.data && json.data.message) ||
                  json.message ||
                  message;
              } catch (ignore) {
                if (text) {
                  message = text.replace(/<[^>]+>/g, " ").trim() || message;
                }
              }
              throw new Error(message);
            });
          }
          return response.blob();
        })
        .then(function (blob) {
          var url = URL.createObjectURL(blob);
          var link = document.createElement("a");
          link.href = url;
          link.download =
            "cotizacion-mantenimiento-" + cotizacionId + "-" + audience + ".pdf";
          document.body.appendChild(link);
          link.click();
          link.remove();
          setTimeout(function () {
            URL.revokeObjectURL(url);
          }, 1500);
          showToast(
            "success",
            "PDF para " + (audience === "destinatario" ? "destinatario" : "funcionario") + " generado.",
          );
        })
        .catch(function (err) {
          showToast("error", err.message || "No se pudo generar el PDF.");
        })
        .finally(function () {
          if (button) {
            button.disabled = false;
            button.textContent = originalText || "Generar PDF";
          }
        });
    }

    function openCotizacionNativeModal(button) {
      var card = button.closest(".scm-cotizacion-card");
      var cotizacionId = button.getAttribute("data-cotizacion-id") || "";
      var sourceFuncionario = card
        ? card.querySelector('[data-scm-cotizacion-native-audience="funcionario"]')
        : null;
      var sourceDestinatario = card
        ? card.querySelector('[data-scm-cotizacion-native-audience="destinatario"]')
        : null;
      var content = sourceFuncionario ? sourceFuncionario.innerHTML : "";
      var title = "Cotización #" + (cotizacionId || "");
      if (!content) {
        showToast("error", "No se encontró la información de la cotización.");
        return;
      }
      if (!window.Swal) {
        openCotizacionPrintWindow(title, content, false);
        return;
      }
      window.Swal.fire({
        title: "",
        html:
          '<div class="scm-cotizacion-native-modal">' +
          '<div class="scm-cotizacion-native-toolbar">' +
          '<div class="scm-cotizacion-native-toolbar-heading"><span>Cotización de mantenimiento</span><strong>#' + escHtml(cotizacionId || "-") + "</strong></div>" +
          '<div class="scm-cotizacion-native-audience-switch" role="group" aria-label="Seleccionar vista de la cotización">' +
          '<button type="button" class="active" data-scm-cotizacion-audience="funcionario" aria-pressed="true">Vista funcionario</button>' +
          '<button type="button" data-scm-cotizacion-audience="destinatario" aria-pressed="false">Vista destinatario</button>' +
          "</div>" +
          '<div class="scm-cotizacion-native-toolbar-actions">' +
          '<button type="button" class="scm-case-work-btn" data-scm-cotizacion-open-large>Ver en grande</button>' +
          '<button type="button" class="scm-case-work-btn" data-scm-cotizacion-print="destinatario">PDF destinatario</button>' +
          '<button type="button" class="scm-case-work-btn scm-primary-action" data-scm-cotizacion-print="funcionario">PDF funcionario</button>' +
          "</div></div>" +
          '<div class="scm-cotizacion-native-scroll"><div class="scm-cotizacion-native-print-root">' +
          content +
          "</div></div></div>",
        width: "min(1180px, 96vw)",
        padding: 0,
        showConfirmButton: false,
        showCloseButton: true,
        customClass: {
          popup: "scm-cotizacion-native-swal",
          closeButton: "scm-swal-close-round",
        },
        didOpen: function () {
          var popup = window.Swal.getPopup();
          if (!popup) return;
          var printRoot = popup.querySelector(".scm-cotizacion-native-print-root");
          var openLarge = popup.querySelector("[data-scm-cotizacion-open-large]");
          var audienceButtons = popup.querySelectorAll("[data-scm-cotizacion-audience]");
          var printButtons = popup.querySelectorAll("[data-scm-cotizacion-print]");
          var currentAudience = "funcionario";
          var contentByAudience = {
            funcionario: sourceFuncionario ? sourceFuncionario.innerHTML : content,
            destinatario: sourceDestinatario ? sourceDestinatario.innerHTML : content,
          };
          var setAudience = function (audience) {
            currentAudience = audience === "destinatario" ? "destinatario" : "funcionario";
            if (printRoot) {
              printRoot.innerHTML = contentByAudience[currentAudience] || content;
            }
            audienceButtons.forEach(function (audienceButton) {
              var active =
                audienceButton.getAttribute("data-scm-cotizacion-audience") === currentAudience;
              audienceButton.classList.toggle("active", active);
              audienceButton.setAttribute("aria-pressed", active ? "true" : "false");
            });
          };
          audienceButtons.forEach(function (audienceButton) {
            audienceButton.addEventListener("click", function () {
              setAudience(
                audienceButton.getAttribute("data-scm-cotizacion-audience") || "funcionario",
              );
            });
          });
          if (openLarge) {
            openLarge.addEventListener("click", function () {
              var printable = printRoot ? printRoot.innerHTML : contentByAudience[currentAudience];
              openCotizacionPrintWindow(
                title +
                  (currentAudience === "destinatario"
                    ? " - Destinatario"
                    : " - Funcionario"),
                printable,
                false,
              );
            });
          }
          printButtons.forEach(function (printButton) {
            printButton.addEventListener("click", function () {
              downloadCotizacionPdf(
                cotizacionId,
                printButton.getAttribute("data-scm-cotizacion-print") || "funcionario",
                printButton,
              );
            });
          });
          setAudience("funcionario");
        },
      });
    }

    function findCotizacionOrderDetailSource(card, orderKey) {
      if (!card || !orderKey) return null;
      var sources = card.querySelectorAll("[data-scm-cotizacion-order-detail]");
      for (var i = 0; i < sources.length; i += 1) {
        if (sources[i].getAttribute("data-scm-cotizacion-order-detail") === orderKey) {
          return sources[i];
        }
      }
      return null;
    }

    function openCotizacionOrderDetailModal(card, orderKey) {
      var source = findCotizacionOrderDetailSource(card, orderKey);
      if (!source) {
        showToast("error", "No se encontró el detalle de la orden.");
        return;
      }
      var orderNumber = source.getAttribute("data-order-number") || orderKey;
      window.Swal.fire({
        title: "Detalle de la orden #" + orderNumber,
        html: source.innerHTML,
        width: "min(920px, 94vw)",
        showCloseButton: true,
        showCancelButton: true,
        confirmButtonText: "Cerrar",
        cancelButtonText: "Volver a órdenes",
        buttonsStyling: false,
        focusConfirm: false,
        returnFocus: true,
        customClass: {
          popup: "scm-cotizacion-dialog scm-cotizacion-order-detail-swal",
          title: "scm-cotizacion-dialog-title",
          htmlContainer: "scm-cotizacion-dialog-body",
          actions: "scm-cotizacion-dialog-actions",
          confirmButton: "scm-cotizacion-dialog-confirm",
          cancelButton: "scm-cotizacion-dialog-cancel",
          closeButton: "scm-swal-close-round scm-cotizacion-dialog-close",
        },
      }).then(function (result) {
        if (result.dismiss === window.Swal.DismissReason.cancel) {
          openCotizacionOrdersModal(card);
        }
      });
    }

    function openCotizacionOrdersModal(card) {
      var source = card ? card.querySelector(".scm-cotizacion-orders-source") : null;
      var cotizacionId = card ? card.getAttribute("data-cotizacion-id") || "" : "";
      if (!window.Swal) {
        showToast("info", source ? source.textContent : "Sin órdenes registradas.");
        return;
      }
      window.Swal.fire({
        title: "Órdenes de la cotización" + (cotizacionId ? " #" + cotizacionId : ""),
        html: source ? source.innerHTML : "Sin órdenes registradas.",
        width: "min(920px, 94vw)",
        showCloseButton: true,
        confirmButtonText: "Cerrar",
        buttonsStyling: false,
        focusConfirm: false,
        returnFocus: true,
        customClass: {
          popup: "scm-cotizacion-dialog scm-cotizacion-orders-swal",
          title: "scm-cotizacion-dialog-title",
          htmlContainer: "scm-cotizacion-dialog-body",
          actions: "scm-cotizacion-dialog-actions",
          confirmButton: "scm-cotizacion-dialog-confirm",
          closeButton: "scm-swal-close-round scm-cotizacion-dialog-close",
        },
        didOpen: function () {
          var popup = window.Swal.getPopup();
          if (!popup) return;
          popup.addEventListener("click", function (event) {
            var orderButton =
              event.target && event.target.closest
                ? event.target.closest("[data-scm-view-cotizacion-order]")
                : null;
            if (!orderButton) return;
            event.preventDefault();
            openCotizacionOrderDetailModal(
              card,
              orderButton.getAttribute("data-scm-view-cotizacion-order") || "",
            );
          });
        },
      });
    }

    root.addEventListener("click", function (e) {
      var linkedTicketCaseBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-linked-ticket-case]")
          : null;
      if (linkedTicketCaseBtn) {
        e.preventDefault();
        var cotizacionCard = linkedTicketCaseBtn.closest(".scm-cotizacion-card");
        var sourceTemplate = cotizacionCard
          ? cotizacionCard.querySelector(".scm-cotizacion-linked-ticket-source")
          : null;
        if (!cotizacionCard || !sourceTemplate) {
          showToast("error", "No se encontro el ticket completo de la cotizacion.");
          return;
        }
        var holder = cotizacionCard.querySelector(".scm-cotizacion-linked-ticket-dom");
        if (!holder) {
          holder = document.createElement("div");
          holder.className = "scm-cotizacion-linked-ticket-dom";
          holder.setAttribute("aria-hidden", "true");
          holder.style.display = "none";
          holder.innerHTML = sourceTemplate.innerHTML || "";
          cotizacionCard.appendChild(holder);
        }
        var caseButton = holder.querySelector(".scm-btn-case");
        if (!caseButton || typeof window.scmOpenCase !== "function") {
          showToast("error", "No se pudo abrir el popup completo del ticket.");
          return;
        }
        window.scmOpenCase(caseButton);
        return;
      }

      var nativeCotizacionBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-view-cotizacion-native]")
          : null;
      if (nativeCotizacionBtn) {
        e.preventDefault();
        openCotizacionNativeModal(nativeCotizacionBtn);
        return;
      }

      var financeBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-cotizacion-toggle-panel]")
          : null;
      if (financeBtn) {
        e.preventDefault();
        var panelKey = financeBtn.getAttribute("data-scm-cotizacion-toggle-panel") || "";
        var financeCard = financeBtn.closest(".scm-cotizacion-card");
        var targetPanel = financeCard
          ? financeCard.querySelector(
              '[data-scm-cotizacion-finance-panel="' + panelKey + '"]',
            )
          : null;
        if (!targetPanel) {
          return;
        }
        var willOpen = targetPanel.hasAttribute("hidden");
        if (willOpen) {
          targetPanel.removeAttribute("hidden");
        } else {
          targetPanel.setAttribute("hidden", "hidden");
        }
        financeBtn.classList.toggle("active", willOpen);
        financeBtn.setAttribute("aria-expanded", willOpen ? "true" : "false");
        if (panelKey === "saldos") {
          financeBtn.textContent = willOpen ? "Ocultar saldos" : "Ver saldos";
        } else if (panelKey === "totales") {
          financeBtn.textContent = willOpen ? "Ocultar totales" : "Ver totales";
        }
        return;
      }

      var ordersBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-view-cotizacion-orders]")
          : null;
      if (ordersBtn) {
        e.preventDefault();
        var card = ordersBtn.closest(".scm-cotizacion-card");
        openCotizacionOrdersModal(card);
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
          title: "Responder cotización",
          html:
            '<div class="scm-cotizacion-response-form"><p class="scm-cotizacion-dialog-intro">Registra la decisión recibida y la información necesaria para continuar el proceso.</p><div class="scm-cotizacion-response-grid">' +
            '<label class="scm-cotizacion-dialog-field"><span>Respuesta <em>*</em></span><select id="swal-cot-estado"><option value="">Selecciona una respuesta</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>' +
            '<label class="scm-cotizacion-dialog-field" id="swal-cot-motivo-wrap" hidden><span>Motivo <em>*</em></span><select id="swal-cot-motivo"><option value="">Selecciona un motivo</option><option value="Por costo">Por costo</option><option value="Ejecución por cuenta propia">Ejecución por cuenta propia</option></select></label>' +
            '<label class="scm-cotizacion-dialog-field" id="swal-cot-fin-wrap" hidden><span>Financiación</span><select id="swal-cot-fin"><option value="">No aplica / sin respuesta</option><option value="Si">Sí</option><option value="No">No</option></select></label>' +
            '<label class="scm-cotizacion-dialog-field is-wide"><span>Observaciones</span><textarea id="swal-cot-observacion" rows="5" placeholder="Agrega contexto para el equipo">Ninguna</textarea><small>Este comentario quedará asociado a la respuesta de la cotización.</small></label>' +
            "</div></div>",
          width: "min(700px, 94vw)",
          showCloseButton: true,
          showCancelButton: true,
          allowOutsideClick: false,
          allowEscapeKey: true,
          confirmButtonText: "Guardar y enviar",
          cancelButtonText: "Cancelar",
          buttonsStyling: false,
          focusConfirm: false,
          returnFocus: true,
          customClass: {
            popup: "scm-cotizacion-dialog scm-cotizacion-response-swal",
            title: "scm-cotizacion-dialog-title",
            htmlContainer: "scm-cotizacion-dialog-body",
            actions: "scm-cotizacion-dialog-actions",
            confirmButton: "scm-cotizacion-dialog-confirm",
            cancelButton: "scm-cotizacion-dialog-cancel",
            closeButton: "scm-swal-close-round scm-cotizacion-dialog-close",
          },
          didOpen: function () {
            var estado = document.getElementById("swal-cot-estado");
            var motivoWrap = document.getElementById("swal-cot-motivo-wrap");
            var finWrap = document.getElementById("swal-cot-fin-wrap");
            if (estado) {
              estado.focus();
              estado.addEventListener("change", function () {
                var isNo = estado.value === "Desaprobada";
                var isYes = estado.value === "Aprobada";
                if (motivoWrap) motivoWrap.hidden = !isNo;
                if (finWrap) finWrap.hidden = !isYes;
              });
            }
          },
          preConfirm: function () {
            var estado = document.getElementById("swal-cot-estado");
            var motivo = document.getElementById("swal-cot-motivo");
            var financiacion = document.getElementById("swal-cot-fin");
            var observacion = document.getElementById("swal-cot-observacion");
            if (!estado || !estado.value) {
              window.Swal.showValidationMessage("Elige una respuesta.");
              return false;
            }
            if (estado.value === "Desaprobada" && (!motivo || !motivo.value)) {
              window.Swal.showValidationMessage("Elige el motivo.");
              return false;
            }
            return {
              estado: estado.value,
              motivo: motivo ? motivo.value : "",
              financiacion: financiacion ? financiacion.value : "",
              observacion: observacion ? observacion.value : "Ninguna",
            };
          },
        }).then(function (res) {
          if (!res.isConfirmed) return;
          var responseData = res.value || {};
          var fd = new FormData();
          fd.append("ticket_pk", ticketPk);
          fd.append("estado", responseData.estado || "");
          fd.append("motivo", responseData.motivo || "");
          fd.append("financiacion", responseData.financiacion || "");
          fd.append("observacion", responseData.observacion || "Ninguna");
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
          allowOutsideClick: false,
          allowEscapeKey: false,
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
      if (!key) {
        return Promise.resolve();
      }
      if (key === "mant") {
        if (panel.getAttribute("data-scm-loaded") === "1") {
          return Promise.resolve();
        }
        if (form) {
          return doFetch(new FormData(form));
        }
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

    function loadPendingFormOnce(panel, formSelector) {
      if (!panel || !formSelector) {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loaded") === "1") {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loading") === "1") {
        return Promise.resolve();
      }
      var form = panel.querySelector(formSelector);
      if (!form) {
        return Promise.resolve();
      }
      form.dispatchEvent(
        new Event("submit", { bubbles: true, cancelable: true }),
      );
      return Promise.resolve();
    }

    function loadActiveLazyPanel() {
      var activePanel = root.querySelector(".scm-tab-panel.active");
      if (!activePanel) {
        return Promise.resolve();
      }
      if (activePanel.id === "scm-panel-inicio") {
        return loadDashboardHome();
      }
      if (
        activePanel.id !== "scm-panel-metricas" &&
        !dashboardFilterOptionsLoaded
      ) {
        return loadDashboardFilterOptions().then(loadActiveLazyPanel);
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
      if (activePanel.id === "scm-panel-metricas") {
        return Promise.all([
          loadDashboardMetrics(),
          loadDashboardFilterOptions(),
        ]).then(function () {});
      }
      if (activePanel.id === "scm-panel-actividades-administrativas") {
        var activeAdministrativePanel = activePanel.querySelector(
          ".scm-admin-activity-panel.active",
        );
        var administrativeKey = administrativeActivityKeyFromPanelId(
          activeAdministrativePanel ? activeAdministrativePanel.id : "",
        );
        if (
          activeAdministrativePanel &&
          administrativeKey === "cotizaciones_mantenimiento"
        ) {
          return loadPanelOnce(activeAdministrativePanel, administrativeKey);
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "preventivas_pendientes"
        ) {
          return loadPendingFormOnce(activeAdministrativePanel, "#spp_form");
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "servicios_publicos_pendientes"
        ) {
          return loadPendingFormOnce(activeAdministrativePanel, "#rsp_form");
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "reportes_administrativos_pendientes"
        ) {
          return loadPendingFormOnce(activeAdministrativePanel, "#sra_form");
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "auditoria_canon_aseguradoras"
        ) {
          var auditModule = activeAdministrativePanel.querySelector(
            "[data-canon-insurance-audit]",
          );
          if (auditModule) {
            auditModule.dispatchEvent(new CustomEvent("scm:load-canon-audit"));
          }
          return Promise.resolve();
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "calendario_actividades"
        ) {
          initCalendarPanel();
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "notificaciones"
        ) {
          return loadAdminNotificationsPanel();
        }
        if (
          activeAdministrativePanel &&
          administrativeKey === "gestiones_cobro"
        ) {
          return refreshCollectionLogPanel(activeAdministrativePanel, false);
        }
      }
      return Promise.resolve();
    }

    function activeLazyPanelLabel() {
      var activeMain = root.querySelector(".scm-main-tabs .scm-tab.active[data-tab]");
      var activeAdministrative = root.querySelector(
        "#scm-panel-actividades-administrativas.active .scm-admin-activity-tab.active",
      );
      var label = activeAdministrative || activeMain;
      return label ? String(label.textContent || "").trim() : "la sección";
    }

    function loadActiveLazyPanelWithFeedback() {
      var label = activeLazyPanelLabel();
      return withPanelLoader(
        loadActiveLazyPanel,
        "Cargando " + label,
        "Estamos consultando la información más reciente.",
      );
    }

    root.querySelectorAll(".scm-admin-activity-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        var target = tab.getAttribute("data-admin-activity-target") || "";
        var parentPanel = tab.closest("#scm-panel-actividades-administrativas");
        if (!target || !parentPanel) {
          return;
        }
        parentPanel
          .querySelectorAll(".scm-admin-activity-tab")
          .forEach(function (item) {
            item.classList.toggle("active", item === tab);
          });
        parentPanel
          .querySelectorAll(".scm-admin-activity-panel")
          .forEach(function (panel) {
            panel.classList.toggle("active", panel.id === target);
          });
        window.setTimeout(loadActiveLazyPanelWithFeedback, 0);
      });
    });

    root.querySelectorAll(".scm-open-topic-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        window.setTimeout(loadActiveLazyPanelWithFeedback, 0);
      });
    });

    root.querySelectorAll(".scm-tab[data-tab]").forEach(function (tab) {
      tab.addEventListener("click", function () {
        window.setTimeout(loadActiveLazyPanelWithFeedback, 0);
      });
    });

    root.addEventListener("click", function (event) {
      var retryButton = event.target.closest("[data-scm-home-retry]");
      if (retryButton) {
        dashboardHomePromise = null;
        var homePanel = root.querySelector("#scm-panel-inicio");
        if (homePanel) homePanel.setAttribute("data-scm-loaded", "0");
        loadDashboardHome();
        return;
      }

      var shortcut = event.target.closest("[data-scm-home-target]");
      if (!shortcut) return;
      var targetPanel = shortcut.getAttribute("data-scm-home-target") || "";
      var targetTab = Array.prototype.find.call(
        root.querySelectorAll(".scm-main-tabs .scm-tab[data-tab]"),
        function (tab) {
          return tab.getAttribute("data-tab") === targetPanel;
        },
      );
      if (targetTab) {
        targetTab.click();
        targetTab.focus({ preventScroll: true });
      }
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
      var estadoCotizacion = segForm.querySelector("select[name='estado_cotizacion']");
      var motivoCotizacion = segForm.querySelector("select[name='motivo_cotizacion']");
      if (
        estadoCotizacion &&
        estadoCotizacion.value === "Desaprobada" &&
        motivoCotizacion &&
        !motivoCotizacion.value
      ) {
        if (msg) {
          msg.textContent = "Elige el motivo de la desaprobacion.";
          msg.classList.add("error");
        }
        showToast("error", "Elige el motivo de la desaprobacion.");
        motivoCotizacion.focus();
        return;
      }
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
          updateInlineCotizacionResponseFields(segForm);
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

    root.addEventListener("change", function (e) {
      var target = e.target;
      if (
        target &&
        target.matches &&
        target.matches("select[name='estado_cotizacion']")
      ) {
        updateInlineCotizacionResponseFields(
          target.closest("[data-scm-cotizacion-response-fields]") ||
            target.closest(".scm-seg-form"),
        );
      }
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
      var reviewForm = e.target;
      if (
        reviewForm &&
        reviewForm.matches &&
        reviewForm.matches("[data-public-services-review-form]")
      ) {
        e.preventDefault();
        submitPublicServicesReviewForm(reviewForm);
        return;
      }
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
          var activeAdministrativePanel = active
            ? active.querySelector(".scm-admin-activity-panel.active")
            : null;
          var activePanelId =
            (activeAdministrativePanel && activeAdministrativePanel.id) ||
            (active && active.id) ||
            "";
          if (activePanelId === "scm-panel-preventivas-pendientes") {
            var sppForm = root.querySelector("#spp_form");
            if (sppForm) {
              sppForm.dispatchEvent(
                new Event("submit", { bubbles: true, cancelable: true }),
              );
            }
          }
          if (activePanelId === "scm-panel-contratos-arrendamiento") {
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
