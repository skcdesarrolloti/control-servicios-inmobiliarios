(function () {
  "use strict";

  // Métricas › Mantenimiento › Cotizaciones: una petición por mes (se guarda en memoria);
  // el filtro de trabajo, la búsqueda, la tabla y el CSV se resuelven en el navegador.
  // "Ver caso" reutiliza el popup del panel ([data-scm-maintenance-quote-open-case]).
  var MONTHS_LONG = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function money(value) { return moneyFormat.format(value || 0); }
  function monthLabel(month) {
    return MONTHS_LONG[Number(month.slice(5, 7)) - 1] + " " + month.slice(0, 4);
  }
  function shiftMonth(month, delta) {
    var date = new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)) - 1 + delta, 1);
    return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0");
  }
  function normalize(text) {
    return String(text || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  function init(root) {
    var module = root.querySelector("[data-scm-quote-metrics]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.qmInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.qmInit = "1";

    var panel = module.closest(".scm-tab-panel");
    var action = (runtime.actions || {}).quote_metrics || "";
    var content = module.querySelector("[data-scm-quote-content]");
    var status = module.querySelector("[data-scm-quote-status]");
    var exportBtn = module.querySelector("[data-scm-quote-export]");
    var monthInput = module.querySelector('[data-scm-quote-filter="month"]');
    var stateSelect = module.querySelector('[data-scm-quote-filter="state"]');
    var searchInput = module.querySelector('[data-scm-quote-filter="search"]');
    var cache = {};
    var state = { month: monthInput.value, data: null, pending: null, visible: [] };

    function setStatus(message, tone) {
      status.textContent = message || "";
      status.hidden = !message;
      status.className = "scm-cm-status" + (tone ? " is-" + tone : "");
    }

    function request(month) {
      var body = new FormData();
      body.append("action", action);
      body.append("nonce", runtime.nonce || "");
      body.append("month", month);
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudieron cargar las cotizaciones.");
          return (json.data && json.data.metrics) || {};
        });
    }

    function load(force) {
      var month = monthInput.value;
      if (!/^\d{4}-\d{2}$/.test(month)) {
        setStatus("Selecciona un mes válido.", "error");
        return Promise.resolve();
      }
      state.month = month;
      if (!force && cache[month]) {
        state.data = cache[month];
        setStatus("");
        render();
        return Promise.resolve();
      }
      if (state.pending && state.pending.month === month) return state.pending.promise;
      setStatus("Cargando cotizaciones de " + monthLabel(month) + "…", "loading");
      module.classList.add("is-busy");
      var promise = request(month)
        .then(function (data) {
          cache[month] = data;
          if (state.month !== month) return;
          state.data = data;
          setStatus("");
          exportBtn.disabled = false;
          if (panel) panel.setAttribute("data-scm-loaded", "1");
          render();
        })
        .catch(function (error) {
          if (state.month === month) setStatus(error.message, "error");
        })
        .then(function () {
          if (state.pending && state.pending.month === month) state.pending = null;
          module.classList.remove("is-busy");
        });
      state.pending = { month: month, promise: promise };
      return promise;
    }

    function kpi(label, value, note, tone) {
      return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" + esc(value) +
        "</strong>" + (note ? "<small>" + esc(note) + "</small>" : "") + "</article>";
    }

    function visibleItems() {
      var filter = stateSelect.value;
      var query = normalize(searchInput.value.trim());
      return (state.data.items || []).filter(function (item) {
        if (filter === "terminada" && !item.trabajo_terminado) return false;
        if (filter === "pendiente" && item.trabajo_terminado) return false;
        if (!query) return true;
        return normalize([item.id, item.ticket, item.contrato, item.inmueble, item.direccion, item.destinatario].join(" ")).indexOf(query) !== -1;
      });
    }

    function rowHtml(item) {
      var source = String(item.case_source_html || "").trim();
      var canOpen = source || item.id || item.ticket;
      return '<tr class="scm-maintenance-quote-row" data-cotizacion-id="' + esc(item.id) + '" data-ticket="' + esc(item.ticket) + '" data-contract="' + esc(item.contrato) + '">' +
        "<td><strong>#" + esc(item.id || "—") + "</strong></td>" +
        "<td>" + esc(item.fecha_cotizacion || item.fecha_aprobacion || "—") + "</td>" +
        "<td>" + (item.ticket ? "#" + esc(item.ticket) : '<span class="scm-cm-muted">—</span>') + "</td>" +
        "<td>" + esc(item.contrato || "—") + "</td>" +
        "<td>" + esc(item.inmueble || "—") + "</td>" +
        '<td class="is-address">' + esc(item.direccion || "—") + "</td>" +
        '<td class="is-name">' + esc(item.destinatario || "—") + "</td>" +
        '<td class="is-num is-total">' + esc(money(item.administracion)) + "</td>" +
        "<td>" + (item.trabajo_terminado
          ? '<span class="scm-cm-badge is-entregado">Terminada</span>' + (item.acta_id ? '<small class="scm-cm-source">Acta #' + esc(item.acta_id) + "</small>" : "")
          : '<span class="scm-cm-badge is-por_recibir">Pendiente de acta</span>') + "</td>" +
        '<td class="is-actions">' + (canOpen
          ? '<button type="button" class="scm-cm-btn scm-cm-btn-ghost scm-quote-case-btn" data-scm-maintenance-quote-open-case data-cotizacion-id="' + esc(item.id) +
            '" data-ticket="' + esc(item.ticket) + '" data-contract="' + esc(item.contrato) + '">Ver caso</button>'
          : '<span class="scm-cm-muted">Sin referencia</span>') +
          (source ? '<template class="scm-maintenance-quote-case-source">' + source + "</template>" : "") + "</td></tr>";
    }

    function render() {
      if (!state.data) return;
      var data = state.data;
      var approved = data.aprobadas || 0;
      var finished = data.terminadas || 0;
      var pending = Math.max(0, approved - finished);
      var html = '<div class="scm-cm-kpis scm-cm-kpis-6">';
      html += kpi("Aprobadas", num(approved), "Cotizaciones de " + monthLabel(state.month), "stock");
      html += kpi("Administración aprobada", money(data.administracion_aprobada), "De todas las aprobadas", "ocup");
      html += kpi("Terminadas con acta", num(finished), approved ? Math.round((finished / approved) * 100) + "% de las aprobadas" : "—", "good");
      html += kpi("Administración terminada", money(data.administracion_terminada), "Trabajos con acta de satisfacción", "good");
      html += kpi("Pendientes de acta", num(pending), approved ? Math.round((pending / approved) * 100) + "% de las aprobadas" : "—");
      html += kpi("Administración pendiente", money(Math.max(0, (data.administracion_aprobada || 0) - (data.administracion_terminada || 0))), "Aún sin acta de satisfacción");
      html += "</div>";

      var items = visibleItems();
      state.visible = items;
      var all = data.items || [];
      var body;
      if (!all.length) {
        body = '<div class="scm-cm-empty">No hay cotizaciones aprobadas en ' + esc(monthLabel(state.month)) + ".</div>";
      } else if (!items.length) {
        body = '<div class="scm-cm-empty">Ninguna cotización coincide con los filtros.</div>';
      } else {
        var adminTotal = items.reduce(function (sum, item) { return sum + (item.administracion || 0); }, 0);
        body = '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Cotización</th><th>Fecha</th><th>Caso</th><th>Contrato</th>' +
          '<th>Inmueble</th><th>Dirección</th><th>Destinatario</th><th class="is-num">Administración</th><th>Trabajo</th><th></th></tr></thead><tbody>' +
          items.map(rowHtml).join("") +
          '</tbody><tfoot><tr><td colspan="7">Total · ' + esc(num(items.length) + (items.length === 1 ? " cotización" : " cotizaciones")) +
          '</td><td class="is-num is-total">' + esc(money(adminTotal)) + '</td><td colspan="2"></td></tr></tfoot></table></div>';
      }
      if (all.length && all.length < approved) {
        body += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>Se listan las ' + esc(num(all.length)) +
          " cotizaciones más recientes; los indicadores cuentan las " + esc(num(approved)) + " del mes.</p>";
      }
      html += '<section class="scm-cm-card is-wide"><header><h2>Cotizaciones aprobadas · ' + esc(monthLabel(state.month)) + "</h2></header>" + body + "</section>";
      content.innerHTML = html;
    }

    function exportCsv() {
      if (!state.data) return;
      var lines = [["Cotizacion", "Fecha", "Caso", "Contrato", "Inmueble", "Direccion", "Destinatario", "Administracion", "Trabajo", "Acta"]];
      state.visible.forEach(function (item) {
        lines.push([item.id, item.fecha_cotizacion || item.fecha_aprobacion || "", item.ticket, item.contrato, item.inmueble, item.direccion, item.destinatario,
          Math.round(item.administracion || 0), item.trabajo_terminado ? "Terminada" : "Pendiente de acta", item.acta_id || ""]);
      });
      var csv = "﻿" + lines.map(function (line) {
        return line.map(function (cell) {
          var text = String(cell == null ? "" : cell);
          return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }).join(";");
      }).join("\r\n");
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = "cotizaciones-" + state.month + ".csv";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    }

    module.addEventListener("change", function (event) {
      if (event.target === monthInput) { load(false); return; }
      if (event.target === stateSelect && state.data) render();
    });
    var searchTimer = 0;
    searchInput.addEventListener("input", function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () { if (state.data) render(); }, 150);
    });
    module.addEventListener("click", function (event) {
      var step = event.target.closest("[data-scm-quote-step]");
      if (step) {
        monthInput.value = shiftMonth(monthInput.value || state.month, Number(step.getAttribute("data-scm-quote-step")));
        load(false);
        return;
      }
      if (event.target.closest("[data-scm-quote-refresh]")) { load(true); return; }
      if (event.target.closest("[data-scm-quote-export]")) exportCsv();
    });

    module.addEventListener("scm:load-metrics", function () { if (!state.data) load(false); });
    if (panel && panel.classList.contains("active")) load(false);
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
