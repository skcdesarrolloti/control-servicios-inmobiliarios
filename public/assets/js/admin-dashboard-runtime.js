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
      if (openModal || root.querySelector("#scm-admin-ticket-modal.open")) {
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
    var actionActivateTicket = actions.activate_ticket || "";
    var actionCloseTicket = actions.close_ticket || "";
    var actionContactsUpdate = actions.contacts_update || "";
    var actionSavePropertyLocation = actions.save_property_location || "";
    var actionTrasladarCaso = actions.trasladar_caso || "";
    var actionContratosArrendamiento = actions.contratos_arrendamiento || "";
    var actionContratoRecibido = actions.contrato_recibido || "";
    var actionContratosArrendamientoFallback =
      actions.preventivas_pendientes || "";
    var actionCrearTicketAdministrativo =
      actions.crear_ticket_administrativo || "";
    var actionDashboardPermissionsRead =
      actions.dashboard_permissions_read || "";
    var actionDashboardPermissionsSave =
      actions.dashboard_permissions_save || "";
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
              return json;
            }).catch(function (err2) {
              window.Swal.showValidationMessage(err2.message || "No se pudo trasladar el evento.");
              return false;
            });
          },
        }).then(function (result) {
          if (!result.isConfirmed || !result.value) return;
          showToast("success", result.value.message || "Evento trasladado.");
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

      Promise.all([loadFuncionariosFallback(), calendarApi("listar_categorias")]).then(function (results) {
        var funcionarios = Array.isArray(results[0]) ? results[0] : [];
        categories = results[1] && results[1].success && Array.isArray(results[1].data) ? results[1].data : [];
        categoriesById = {};
        categories.forEach(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          if (id) categoriesById[id] = row;
        });
        fillEmployeeOptions(Array.prototype.slice.call(panel.querySelectorAll("[data-scm-calendar-filter-employees]")), funcionarios, "Selecciona funcionario");
        fillCategoryOptions(panel.querySelector("[data-scm-calendar-filter-categories]"), calendarAdminCategories(), "Todas");
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
          openCreateEventPopup(btn.getAttribute("data-calendar-mode") || "single");
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
            setMessage(
              (json.data && json.data.message) || "Permisos guardados.",
              false,
            );
            showToast("success", "Permisos de pestañas guardados.");
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
      if (!openBtn || !modal) {
        return;
      }
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
        if (e.target.closest("[data-admin-ticket-close]")) {
          closeAdminTicketModal();
        } else if (e.target === modal) {
          e.preventDefault();
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
      if (panelId === "scm-panel-preventivas-pendientes") {
        return "preventivas_pendientes";
      }
      if (panelId === "scm-panel-servicios-publicos-pendientes") {
        return "servicios_publicos_pendientes";
      }
      if (panelId === "scm-panel-reportes-administrativos-pendientes") {
        return "reportes_administrativos_pendientes";
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
        var sppForm = root.querySelector("#scm-form-preventiva") || root.querySelector("#spp_form");
        if (sppForm) {
          sppForm.dispatchEvent(
            new Event("submit", { bubbles: true, cancelable: true }),
          );
          return Promise.resolve();
        }
      }
      var utilitiesPanel = button.closest
        ? button.closest("#scm-panel-servicios-publicos-pendientes")
        : null;
      if (utilitiesPanel) {
        var rspForm = root.querySelector("#rsp_form");
        if (rspForm) {
          rspForm.dispatchEvent(
            new Event("submit", { bubbles: true, cancelable: true }),
          );
          return Promise.resolve();
        }
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
        openAdminTicketModal(adminTicketBtn);
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
          allowOutsideClick: false,
          allowEscapeKey: false,
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
          administrativeKey === "calendario_actividades"
        ) {
          initCalendarPanel();
        }
      }
      return Promise.resolve();
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
        window.setTimeout(loadActiveLazyPanel, 0);
      });
    });

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
