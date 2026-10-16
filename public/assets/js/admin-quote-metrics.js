(function () {
  "use strict";

  // Métricas › Mantenimiento › Cotizaciones: todas las cotizaciones llegan en una sola petición
  // y el mes a mes, los indicadores, el detalle y el CSV se calculan en el navegador.
  // - Creadas: por fecha de la cotización.  - Sin enviar: creadas sin se_envio = Si.
  // - Aprobadas / administración ganada: estado Aprobada con fecha de respuesta en el periodo.
  // "Ver caso" reutiliza el popup del panel ([data-scm-maintenance-quote-open-case]).
  var MONTHS_LONG = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
  var MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var SERIES_COLOR = "#2a78d6";
  var PAGE_SIZE = 20;
  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var decimalFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 });

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function money(value) { return moneyFormat.format(value || 0); }
  function compactMoney(value) {
    var abs = Math.abs(value || 0);
    if (abs >= 1e6) return "$" + decimalFormat.format(abs / 1e6) + " M";
    if (abs >= 1e3) return "$" + decimalFormat.format(abs / 1e3) + " mil";
    return "$" + num(abs);
  }
  function formatDay(iso) {
    return iso ? Number(iso.slice(8, 10)) + " " + MONTHS_SHORT[Number(iso.slice(5, 7)) - 1] + " " + iso.slice(0, 4) : "—";
  }
  function normalize(text) {
    return String(text || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }
  function summarize(rows) {
    var out = { count: rows.length, total: 0, admin: 0 };
    rows.forEach(function (row) {
      out.total += row.total || 0;
      out.admin += row.admin || 0;
    });
    return out;
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
    var updatedEl = module.querySelector("[data-scm-quote-updated]");
    var exportBtn = module.querySelector("[data-scm-quote-export]");
    var yearSelect = module.querySelector('[data-scm-quote-filter="year"]');
    var monthSelect = module.querySelector('[data-scm-quote-filter="month"]');
    var categorySelect = module.querySelector('[data-scm-quote-filter="categoria"]');
    var state = { data: null, pending: null, view: "creadas", search: "", page: 1, monthly: [], detail: [] };
    var tooltip = document.createElement("div");
    tooltip.className = "scm-cc-tooltip";
    tooltip.setAttribute("role", "tooltip");
    tooltip.hidden = true;
    module.appendChild(tooltip);

    function setStatus(message, tone) {
      status.textContent = message || "";
      status.hidden = !message;
      status.className = "scm-cm-status" + (tone ? " is-" + tone : "");
    }

    function request() {
      var body = new FormData();
      body.append("action", action);
      body.append("nonce", runtime.nonce || "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudieron cargar las cotizaciones.");
          return json.data || {};
        });
    }

    function load(force) {
      if (state.pending) return state.pending;
      if (state.data && !force) return Promise.resolve();
      setStatus(state.data ? "Actualizando cotizaciones…" : "Cargando cotizaciones…", "loading");
      module.classList.add("is-busy");
      state.pending = request()
        .then(apply)
        .catch(function (error) { setStatus(error.message, "error"); })
        .then(function () {
          state.pending = null;
          module.classList.remove("is-busy");
        });
      return state.pending;
    }

    function apply(snapshot) {
      var columns = snapshot.columns || [];
      var years = {};
      var categories = {};
      var rows = (snapshot.rows || []).map(function (values) {
        var row = {};
        columns.forEach(function (column, index) { row[column] = values[index]; });
        row.approved = row.estado === "Aprobada";
        if (row.fecha) years[row.fecha.slice(0, 4)] = true;
        if (row.approved && row.respuesta) years[row.respuesta.slice(0, 4)] = true;
        categories[row.categoria] = true;
        row.blob = normalize([row.id, row.ticket, row.contrato, row.inmueble, row.direccion, row.destinatario].join(" "));
        return row;
      });
      years[module.getAttribute("data-default-year")] = true;
      state.data = { rows: rows, generatedAt: snapshot.generated_at || 0 };
      var currentYear = yearSelect.value;
      yearSelect.innerHTML = Object.keys(years).sort().reverse().map(function (year) {
        return '<option value="' + esc(year) + '">' + esc(year) + "</option>";
      }).join("");
      yearSelect.value = currentYear;
      var currentCategory = categorySelect.value;
      categorySelect.innerHTML = '<option value="">Todas</option>' + Object.keys(categories).sort().map(function (category) {
        return '<option value="' + esc(category) + '">' + esc(category) + "</option>";
      }).join("");
      categorySelect.value = currentCategory;
      exportBtn.disabled = false;
      setStatus("");
      if (panel) panel.setAttribute("data-scm-loaded", "1");
      render();
    }

    function baseRows() {
      var category = categorySelect.value;
      return state.data.rows.filter(function (row) { return !category || row.categoria === category; });
    }

    function inPeriod(date, year, month) {
      if (!date || date.slice(0, 4) !== String(year)) return false;
      return !month || Number(date.slice(5, 7)) === month;
    }

    function kpi(label, value, note, tone) {
      return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" + esc(value) +
        "</strong>" + (note ? "<small>" + esc(note) + "</small>" : "") + "</article>";
    }

    function render() {
      if (!state.data) return;
      var year = Number(yearSelect.value);
      var month = Number(monthSelect.value);
      var periodLabel = month ? MONTHS_LONG[month - 1].toLowerCase() + " " + year : "todo " + year;
      updatedEl.textContent = state.data.generatedAt
        ? "Datos de las " + new Date(state.data.generatedAt * 1000).toLocaleTimeString("es-CO", { hour: "2-digit", minute: "2-digit" })
        : "";
      var rows = baseRows();
      var created = rows.filter(function (row) { return inPeriod(row.fecha, year, month); });
      var unsent = created.filter(function (row) { return !row.enviada; });
      var approved = rows.filter(function (row) { return row.approved && inPeriod(row.respuesta, year, month); });
      var sc = summarize(created);
      var sa = summarize(approved);

      var html = '<h3 class="scm-qm-section">Creadas en ' + esc(periodLabel) + "</h3>";
      html += '<div class="scm-cm-kpis">';
      html += kpi("Cotizaciones creadas", num(sc.count), "Por fecha de la cotización", "stock");
      html += kpi("Valor total", money(sc.total), "Suma del total de las cotizaciones");
      html += kpi("Administración", money(sc.admin), "Administración cotizada (sin IVA)");
      html += kpi("Sin enviar", num(unsent.length), unsent.length ? money(summarize(unsent).total) + " sin enviar" : "Todas enviadas", unsent.length ? "bad" : "good");
      html += "</div>";
      html += '<h3 class="scm-qm-section">Aprobadas en ' + esc(periodLabel) + " · por fecha de respuesta</h3>";
      html += '<div class="scm-cm-kpis">';
      html += kpi("Aprobadas", num(sa.count), "Estado Aprobada con respuesta en el periodo", "ocup");
      html += kpi("Valor total aprobado", money(sa.total), "Suma del total de las aprobadas");
      html += kpi("Administración ganada", money(sa.admin), "Administración de las aprobadas (sin IVA)", "good");
      html += "</div>";

      // Mes a mes del año.
      state.monthly = MONTHS_LONG.map(function (name, index) {
        var m = index + 1;
        var c = rows.filter(function (row) { return inPeriod(row.fecha, year, m); });
        var a = rows.filter(function (row) { return row.approved && inPeriod(row.respuesta, year, m); });
        var created = summarize(c);
        var won = summarize(a);
        return { month: m, name: name, created: created, unsent: c.filter(function (row) { return !row.enviada; }).length, approved: won };
      });
      html += '<section class="scm-cm-card is-wide"><header><h2>Administración ganada mes a mes · ' + year + '</h2><p class="scm-cm-hint">Toca un mes para ver su detalle</p></header>' + monthlyChart(month) + "</section>";
      html += '<section class="scm-cm-card is-wide"><header><h2>Mes a mes · ' + year + "</h2></header>" + monthlyTable(month) + "</section>";

      // Detalle del periodo.
      state.detail = detailRows({ creadas: created, aprobadas: approved, sin_enviar: unsent });
      var views = [["creadas", "Creadas", created.length], ["aprobadas", "Aprobadas", approved.length], ["sin_enviar", "Sin enviar", unsent.length]];
      html += '<section class="scm-cm-card is-wide"><header><div class="scm-qm-views" role="tablist">' + views.map(function (view) {
        return '<button type="button" role="tab" class="scm-qm-view' + (state.view === view[0] ? " is-active" : "") + '" aria-selected="' + (state.view === view[0]) +
          '" data-scm-quote-view="' + view[0] + '">' + esc(view[1]) + " <b>" + num(view[2]) + "</b></button>";
      }).join("") + '</div><label class="scm-cm-search"><span class="material-symbols-outlined" aria-hidden="true">search</span>' +
        '<input type="search" data-scm-quote-search placeholder="Cotización, caso, contrato, inmueble o dirección" value="' + esc(state.search) + '"></label></header>' +
        '<div data-scm-quote-detail></div></section>';

      var missing = rows.filter(function (row) { return row.approved && !row.respuesta; });
      var fromCase = rows.filter(function (row) { return row.approved && row.respuesta_fuente === "caso"; }).length;
      if (missing.length || fromCase) {
        html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>' + esc(
          (missing.length ? num(missing.length) + " cotizaciones aprobadas no tienen fecha de respuesta y no se cuentan en ningún mes (" + money(summarize(missing).admin) + " de administración). " : "") +
          (fromCase ? num(fromCase) + " aprobadas toman la fecha de respuesta registrada en su caso." : "")) + "</p>";
      }
      content.innerHTML = html;
      renderDetail();
    }

    function periodLists() {
      var year = Number(yearSelect.value);
      var month = Number(monthSelect.value);
      var rows = baseRows();
      var created = rows.filter(function (row) { return inPeriod(row.fecha, year, month); });
      return {
        creadas: created,
        aprobadas: rows.filter(function (row) { return row.approved && inPeriod(row.respuesta, year, month); }),
        sin_enviar: created.filter(function (row) { return !row.enviada; }),
      };
    }

    function detailRows(lists) {
      var query = normalize(state.search.trim());
      var key = state.view === "aprobadas" ? "respuesta" : "fecha";
      return lists[state.view].filter(function (row) { return !query || row.blob.indexOf(query) !== -1; })
        .sort(function (a, b) { return a[key] < b[key] ? 1 : a[key] > b[key] ? -1 : b.id - a.id; });
    }

    function monthlyChart(activeMonth) {
      var max = Math.max.apply(null, state.monthly.map(function (m) { return m.approved.admin; })) || 1;
      var html = '<div class="scm-cc-columns" style="--cols:12"><div class="scm-cc-plot">';
      state.monthly.forEach(function (m) {
        var height = m.approved.admin ? Math.max(2, (m.approved.admin / max) * 100) : 0;
        html += '<button type="button" class="scm-cc-col' + (activeMonth === m.month ? " is-active" : "") + '" data-scm-quote-month="' + m.month + '" data-tip="' +
          esc(m.name + ": " + money(m.approved.admin) + " de administración · " + num(m.approved.count) + " aprobadas") + '">' +
          '<span class="scm-cc-bar" style="height:' + height.toFixed(2) + "%;background:" + SERIES_COLOR + '">' +
          (m.approved.admin ? "<i>" + esc(compactMoney(m.approved.admin)) + "</i>" : "") + "</span></button>";
      });
      return html + '</div><div class="scm-cc-axis">' + state.monthly.map(function (m) {
        return '<span class="' + (activeMonth === m.month ? "is-active" : "") + '">' + MONTHS_SHORT[m.month - 1] + "</span>";
      }).join("") + "</div></div>";
    }

    function monthlyTable(activeMonth) {
      var totals = { created: 0, total: 0, admin: 0, unsent: 0, approved: 0, approvedTotal: 0, won: 0 };
      var body = state.monthly.map(function (m) {
        totals.created += m.created.count; totals.total += m.created.total; totals.admin += m.created.admin; totals.unsent += m.unsent;
        totals.approved += m.approved.count; totals.approvedTotal += m.approved.total; totals.won += m.approved.admin;
        return '<tr class="' + (activeMonth === m.month ? "is-active" : "") + '"><td><button type="button" class="scm-cm-link" data-scm-quote-month="' + m.month + '">' + esc(m.name) +
          '</button></td><td class="is-num">' + num(m.created.count) + '</td><td class="is-num">' + money(m.created.total) + '</td><td class="is-num">' + money(m.created.admin) +
          '</td><td class="is-num' + (m.unsent ? " is-bad" : "") + '">' + num(m.unsent) + '</td><td class="is-num">' + num(m.approved.count) + '</td><td class="is-num">' + money(m.approved.total) +
          '</td><td class="is-num is-total">' + money(m.approved.admin) + "</td></tr>";
      }).join("");
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Mes</th><th class="is-num">Creadas</th><th class="is-num">Valor creadas</th>' +
        '<th class="is-num">Administración creadas</th><th class="is-num">Sin enviar</th><th class="is-num">Aprobadas</th><th class="is-num">Valor aprobado</th>' +
        '<th class="is-num">Administración ganada</th></tr></thead><tbody>' + body + '</tbody><tfoot><tr><td>Total</td><td class="is-num">' + num(totals.created) +
        '</td><td class="is-num">' + money(totals.total) + '</td><td class="is-num">' + money(totals.admin) + '</td><td class="is-num">' + num(totals.unsent) +
        '</td><td class="is-num">' + num(totals.approved) + '</td><td class="is-num">' + money(totals.approvedTotal) + '</td><td class="is-num is-total">' + money(totals.won) +
        "</td></tr></tfoot></table></div>";
    }

    function renderDetail() {
      var holder = content.querySelector("[data-scm-quote-detail]");
      if (!holder) return;
      var rows = state.detail;
      if (!rows.length) {
        holder.innerHTML = '<div class="scm-cm-empty">No hay cotizaciones en este periodo.</div>';
        return;
      }
      var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      state.page = Math.min(Math.max(1, state.page), pages);
      var slice = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);
      var approvedView = state.view === "aprobadas";
      var sum = summarize(rows);
      var html = '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Cotización</th><th>' + (approvedView ? "Respuesta" : "Creada") +
        "</th><th>Caso</th><th>Contrato</th><th>Inmueble</th><th>Dirección</th><th>Destinatario</th><th>Categoría</th><th>Estado</th>" +
        '<th class="is-num">Valor total</th><th class="is-num">Administración</th><th></th></tr></thead><tbody>';
      html += slice.map(function (row) {
        var date = approvedView ? row.respuesta : row.fecha;
        return '<tr class="scm-maintenance-quote-row" data-cotizacion-id="' + esc(row.id) + '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">' +
          "<td><strong>#" + esc(row.id) + "</strong></td><td>" + esc(formatDay(date)) +
          (approvedView && row.respuesta_fuente === "caso" ? '<small class="scm-cm-source">fecha del caso</small>' : "") + "</td>" +
          "<td>" + (row.ticket ? "#" + esc(row.ticket) : '<span class="scm-cm-muted">—</span>') + "</td><td>" + esc(row.contrato || "—") + "</td><td>" + esc(row.inmueble || "—") +
          '</td><td class="is-address">' + esc(row.direccion || "—") + '</td><td class="is-name">' + esc(row.destinatario || "—") + "</td><td>" + esc(row.categoria) + "</td>" +
          "<td>" + statusBadge(row) + (row.enviada ? "" : '<small class="scm-cm-source">Sin enviar</small>') + "</td>" +
          '<td class="is-num">' + esc(money(row.total)) + '</td><td class="is-num is-total">' + esc(money(row.admin)) + "</td>" +
          '<td class="is-actions"><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-maintenance-quote-open-case data-cotizacion-id="' + esc(row.id) +
          '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">Ver caso</button></td></tr>';
      }).join("");
      html += '</tbody><tfoot><tr><td colspan="9">Total · ' + esc(num(rows.length) + (rows.length === 1 ? " cotización" : " cotizaciones")) + '</td><td class="is-num">' +
        esc(money(sum.total)) + '</td><td class="is-num is-total">' + esc(money(sum.admin)) + "</td><td></td></tr></tfoot></table></div>";
      html += '<div class="scm-cm-pager"><span>' + esc(num(rows.length) + (rows.length === 1 ? " cotización" : " cotizaciones")) + "</span>";
      if (pages > 1) {
        html += '<div><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-quote-page="' + (state.page - 1) + '"' + (state.page === 1 ? " disabled" : "") +
          ">Anterior</button><span>Página " + state.page + " de " + pages + '</span><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-quote-page="' +
          (state.page + 1) + '"' + (state.page === pages ? " disabled" : "") + ">Siguiente</button></div>";
      }
      holder.innerHTML = html + "</div>";
    }

    function statusBadge(row) {
      var tone = row.estado === "Aprobada" ? "is-entregado" : row.estado === "Desaprobada" ? "is-recibido" : "is-por_recibir";
      return '<span class="scm-cm-badge ' + tone + '">' + esc(row.estado) + "</span>";
    }

    function exportCsv() {
      if (!state.data) return;
      var lines = [["Mes", "Creadas", "Valor creadas", "Administracion creadas", "Sin enviar", "Aprobadas", "Valor aprobado", "Administracion ganada"]];
      state.monthly.forEach(function (m) {
        lines.push([m.name + " " + yearSelect.value, m.created.count, m.created.total, m.created.admin, m.unsent, m.approved.count, m.approved.total, m.approved.admin]);
      });
      var csv = "﻿" + lines.map(function (line) {
        return line.map(function (cell) {
          var text = String(cell == null ? "" : cell);
          return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }).join(";");
      }).join("\r\n");
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = "cotizaciones-mes-a-mes-" + yearSelect.value + ".csv";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    }

    module.addEventListener("change", function (event) {
      if (!state.data || !event.target.matches("[data-scm-quote-filter]")) return;
      state.page = 1;
      render();
    });
    var searchTimer = 0;
    module.addEventListener("input", function (event) {
      if (!event.target.matches("[data-scm-quote-search]")) return;
      var value = event.target.value;
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.search = value;
        state.page = 1;
        // Solo se repinta el detalle para no perder el foco del buscador.
        state.detail = detailRows(periodLists());
        renderDetail();
      }, 150);
    });
    module.addEventListener("click", function (event) {
      if (event.target.closest("[data-scm-quote-refresh]")) { load(true); return; }
      if (!state.data) return;
      if (event.target.closest("[data-scm-quote-export]")) { exportCsv(); return; }
      var monthButton = event.target.closest("[data-scm-quote-month]");
      if (monthButton) {
        var value = monthButton.getAttribute("data-scm-quote-month");
        monthSelect.value = monthSelect.value === value ? "0" : value;
        state.page = 1;
        render();
        return;
      }
      var view = event.target.closest("[data-scm-quote-view]");
      if (view) {
        state.view = view.getAttribute("data-scm-quote-view");
        state.page = 1;
        render();
        return;
      }
      var page = event.target.closest("[data-scm-quote-page]");
      if (page && !page.disabled) {
        state.page = Number(page.getAttribute("data-scm-quote-page")) || 1;
        renderDetail();
      }
    });
    module.addEventListener("mouseover", function (event) {
      var target = event.target.closest("[data-tip]");
      if (!target) { tooltip.hidden = true; return; }
      tooltip.textContent = target.getAttribute("data-tip");
      tooltip.hidden = false;
    });
    module.addEventListener("mousemove", function (event) {
      if (tooltip.hidden) return;
      var bounds = module.getBoundingClientRect();
      tooltip.style.left = Math.min(event.clientX - bounds.left + 14, bounds.width - tooltip.offsetWidth - 8) + "px";
      tooltip.style.top = event.clientY - bounds.top + 14 + "px";
    });
    module.addEventListener("mouseleave", function () { tooltip.hidden = true; });

    module.addEventListener("scm:load-metrics", function () { load(false); });
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
