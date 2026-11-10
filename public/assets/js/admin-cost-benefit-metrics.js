(function () {
  "use strict";

  // Métricas › Contratos: Costo entrega y recibo. Métricas › Mantenimiento: Costo-beneficio mantenimiento.
  // Cada vista (data-scope) trae todas sus filas en una sola petición y los periodos, indicadores,
  // detalle y CSV se calculan en el navegador.
  // - Ingreso: administración de las cotizaciones aprobadas por fecha de respuesta o, en entrega y recibo,
  //   un mes de comisión y los derechos de cada contrato, en el mes de su fecha de entrega.
  // - Gasto: servicio y transporte de los reportes administrativos de la vista, por fecha del reporte.
  // Periodo: mes actual, un mes, un año o acumulado de un mes a otro; siempre es un rango de meses (YYYY-MM).
  var MONTHS_LONG = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
  var MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var INCOME_COLOR = "#2a78d6";
  var EXPENSE_COLOR = "#e0741f";
  var PAGE_SIZE = 20;
  var STATES = {
    pagado: ["Pagado", "is-entregado"],
    en_cobro: ["En cuenta de cobro", "is-por_recibir"],
    sin_cobrar: ["Sin cobrar", "is-recibido"],
  };
  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var decimalFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 });
  var ratioFormat = new Intl.NumberFormat("es-CO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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
  function monthLabel(ym) {
    return MONTHS_LONG[Number(ym.slice(5, 7)) - 1].toLowerCase() + " " + ym.slice(0, 4);
  }
  function addMonths(ym, delta) {
    var index = Number(ym.slice(0, 4)) * 12 + Number(ym.slice(5, 7)) - 1 + delta;
    return Math.floor(index / 12) + "-" + String((index % 12) + 1).padStart(2, "0");
  }
  function monthRange(from, to) {
    var out = [];
    for (var ym = from; ym <= to && out.length < 120; ym = addMonths(ym, 1)) out.push(ym);
    return out;
  }
  function inPeriod(date, p) {
    var ym = date.slice(0, 7);
    return ym >= p.from && ym <= p.to;
  }
  // Ingreso dividido entre gasto: sin gasto no hay relación que calcular.
  function ratio(income, expense) { return expense > 0 ? income / expense : null; }
  function ratioText(value) { return value === null ? "—" : ratioFormat.format(value); }
  function summarize(income, expense) {
    var out = { income: 0, commission: 0, rights: 0, quotes: income.length, expense: 0, reports: expense.length, service: 0, transport: 0, pagado: 0, en_cobro: 0, sin_cobrar: 0 };
    income.forEach(function (row) {
      out.income += row.admin || 0;
      out.commission += row.comision || 0;
      out.rights += row.derechos || 0;
    });
    expense.forEach(function (row) {
      out.expense += row.total || 0;
      out.service += row.servicio || 0;
      out.transport += row.transporte || 0;
      out[row.estado] += row.total || 0;
    });
    out.net = out.income - out.expense;
    out.ratio = ratio(out.income, out.expense);
    return out;
  }
  function rowsToObjects(columns, rows, blobKeys) {
    return (rows || []).map(function (values) {
      var row = {};
      columns.forEach(function (column, index) { row[column] = values[index]; });
      row.blob = normalize(blobKeys.map(function (key) { return row[key]; }).join(" "));
      return row;
    });
  }
  function groupExpense(rows, key) {
    var groups = {};
    rows.forEach(function (row) {
      var group = groups[row[key]] || (groups[row[key]] = { name: row[key], count: 0, service: 0, transport: 0, total: 0 });
      group.count++;
      group.service += row.servicio || 0;
      group.transport += row.transporte || 0;
      group.total += row.total || 0;
    });
    return Object.keys(groups).map(function (name) { return groups[name]; }).sort(function (a, b) { return b.total - a.total; });
  }

  function init(root) {
    var core = window.SCMAdminCore;
    if (!core) return;
    root.querySelectorAll("[data-scm-cost-benefit]").forEach(function (module) { initModule(root, module, core); });
  }

  function initModule(root, module, core) {
    if (module.dataset.cbInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.cbInit = "1";

    var panel = module.closest(".scm-tab-panel");
    var action = (runtime.actions || {}).maintenance_cost_benefit || "";
    var content = module.querySelector("[data-scm-cb-content]");
    var status = module.querySelector("[data-scm-cb-status]");
    var updatedEl = module.querySelector("[data-scm-cb-updated]");
    var exportBtn = module.querySelector("[data-scm-cb-export]");
    var yearSelect = module.querySelector('[data-scm-cb-filter="year"]');
    var monthInput = module.querySelector('[data-scm-cb-filter="month"]');
    var fromInput = module.querySelector('[data-scm-cb-filter="from"]');
    var toInput = module.querySelector('[data-scm-cb-filter="to"]');
    var typeSelect = module.querySelector('[data-scm-cb-filter="tipo"]');
    var currentMonth = module.getAttribute("data-current-month");
    var state = { data: null, pending: null, mode: "actual", kind: "general", view: "ingresos", search: "", page: 1, monthly: [], detail: [], lists: null };
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
      body.append("scope", module.getAttribute("data-scope") || "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo cargar el costo-beneficio.");
          return json.data || {};
        });
    }

    function load(force) {
      if (state.pending) return state.pending;
      if (state.data && !force) return Promise.resolve();
      setStatus(state.data ? "Actualizando costo-beneficio…" : "Cargando costo-beneficio…", "loading");
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
      var income = rowsToObjects(snapshot.income_columns || [], snapshot.income, ["id", "ticket", "contrato", "inmueble"]);
      var contracts = snapshot.income_source === "contratos" ? rowsToObjects(snapshot.contract_columns || [], snapshot.contracts, ["id", "contrato", "inmueble"]) : null;
      var expense = rowsToObjects(snapshot.expense_columns || [], snapshot.expense, ["id", "ticket", "inmueble", "funcionario", "categoria"]);
      var years = {};
      var types = {};
      years[currentMonth.slice(0, 4)] = true;
      income.forEach(function (row) {
        row.kind = row.tipo.toLowerCase();
        years[row.fecha.slice(0, 4)] = true;
      });
      (contracts || []).forEach(function (row) { years[row.entrega.slice(0, 4)] = true; });
      expense.forEach(function (row) {
        years[row.fecha.slice(0, 4)] = true;
        types[row.categoria] = true;
        row.kind = normalize(row.categoria).indexOf("prevent") !== -1 ? "preventiva" : "correctiva";
      });
      state.data = {
        income: income, contracts: contracts, contractsWithoutRate: snapshot.contracts_without_rate || 0, expense: expense, undated: snapshot.undated || {},
        expenseNote: snapshot.expense_note || "", excluded: snapshot.excluded || {}, employeesNotFound: snapshot.employees_not_found || [], generatedAt: snapshot.generated_at || 0,
      };
      var currentYear = yearSelect.value;
      yearSelect.innerHTML = Object.keys(years).sort().reverse().map(function (year) {
        return '<option value="' + esc(year) + '">' + esc(year) + "</option>";
      }).join("");
      yearSelect.value = currentYear;
      var currentType = typeSelect.value;
      typeSelect.innerHTML = '<option value="">Todos</option>' + Object.keys(types).sort().map(function (type) {
        return '<option value="' + esc(type) + '">' + esc(type) + "</option>";
      }).join("");
      typeSelect.value = currentType;
      exportBtn.disabled = false;
      setStatus("");
      if (panel) panel.setAttribute("data-scm-loaded", "1");
      render();
    }

    // Solo los contratos con fecha de entrega en el periodo: cada uno suma un mes de comisión y sus derechos.
    function contractIncome(p) {
      return state.data.contracts.filter(function (contract) {
        return inPeriod(contract.entrega, p) && contract.entrega.slice(0, 7) <= currentMonth;
      }).map(function (contract) {
        var commission = Math.round(contract.canon * contract.tasa / 100);
        var rights = Math.round(contract.canon * contract.derechos / 100);
        return { id: contract.id, contrato: contract.contrato, inmueble: contract.inmueble, fecha: contract.entrega, canon: contract.canon, comision: commission, derechos: rights, admin: commission + rights, blob: contract.blob };
      });
    }

    // Pestañas de mantenimiento: General suma todo; Correctiva y Preventiva separan cotizaciones y reportes.
    function matchesKind(row) { return state.kind === "general" || row.kind === state.kind; }
    function kindSuffix() { return state.kind === "general" ? "" : " · " + state.kind + "s"; }
    function setKind(kind) {
      state.kind = kind;
      module.querySelectorAll("[data-scm-cb-kind]").forEach(function (button) {
        var active = button.getAttribute("data-scm-cb-kind") === kind;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
      });
    }

    function incomeFor(p) {
      return state.data.contracts ? contractIncome(p) : state.data.income.filter(function (row) { return matchesKind(row) && inPeriod(row.fecha, p); });
    }

    function expenseRows() {
      var type = typeSelect.value;
      return state.data.expense.filter(function (row) { return matchesKind(row) && (!type || row.categoria === type); });
    }

    // Periodo elegido como rango de meses; `months` son las columnas del mes a mes, que solo aplica
    // al año y al acumulado (un mes suelto no tiene mes a mes).
    function period() {
      var valid = /^\d{4}-\d{2}$/;
      if (state.mode === "anual") {
        var year = yearSelect.value;
        // Del año en curso solo se muestran los meses ya transcurridos.
        return { from: year + "-01", to: year + "-12", label: "todo " + year, title: year, months: monthRange(year + "-01", year + "-12").filter(function (ym) { return ym <= currentMonth; }) };
      }
      if (state.mode === "acumulado") {
        var from = valid.test(fromInput.value) ? fromInput.value : currentMonth.slice(0, 4) + "-01";
        var to = valid.test(toInput.value) ? toInput.value : currentMonth;
        if (from > to) { var swap = from; from = to; to = swap; }
        var label = from === to ? monthLabel(from) : monthLabel(from) + " a " + monthLabel(to);
        return { from: from, to: to, label: label, title: label, months: monthRange(from, to) };
      }
      var month = state.mode === "mes" && valid.test(monthInput.value) ? monthInput.value : currentMonth;
      return { from: month, to: month, label: monthLabel(month) + (month === currentMonth ? " (mes actual)" : ""), title: monthLabel(month), months: [] };
    }

    function setMode(mode) {
      state.mode = mode;
      module.querySelectorAll("[data-scm-cb-mode]").forEach(function (button) {
        var active = button.getAttribute("data-scm-cb-mode") === mode;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-checked", active ? "true" : "false");
      });
      module.querySelectorAll("[data-scm-cb-mode-field]").forEach(function (field) {
        field.hidden = field.getAttribute("data-scm-cb-mode-field") !== mode;
      });
    }

    function kpi(label, value, note, tone) {
      return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" + esc(value) +
        "</strong>" + (note ? "<small>" + esc(note) + "</small>" : "") + "</article>";
    }

    function render() {
      if (!state.data) return;
      var p = period();
      var type = typeSelect.value;
      updatedEl.textContent = state.data.generatedAt
        ? "Datos de las " + new Date(state.data.generatedAt * 1000).toLocaleTimeString("es-CO", { hour: "2-digit", minute: "2-digit" })
        : "";
      var expenses = expenseRows();
      var income = incomeFor(p);
      var expense = expenses.filter(function (row) { return inPeriod(row.fecha, p); });
      var s = summarize(income, expense);
      state.lists = { ingresos: income, gastos: expense };

      var html = '<h3 class="scm-qm-section">Resultado de ' + esc(p.label + kindSuffix()) + "</h3>";
      html += '<div class="scm-cm-kpis">';
      html += kpi("Ingresos", money(s.income), state.data.contracts
        ? "Comisión " + money(s.commission) + " + derechos " + money(s.rights) + " · " + num(s.quotes) + (s.quotes === 1 ? " contrato" : " contratos")
        : "Administración ganada · " + num(s.quotes) + (s.quotes === 1 ? " cotización aprobada" : " cotizaciones aprobadas"), "ocup");
      html += kpi("Gastos", money(s.expense), (type ? "Solo " + type + " · " : "Pagos a funcionarios · ") + num(s.reports) + (s.reports === 1 ? " reporte" : " reportes"), "desoc");
      html += kpi("Beneficio neto", money(s.net), "Ingresos menos gastos", s.net < 0 ? "bad" : "good");
      html += kpi("Relación beneficio/costo", ratioText(s.ratio), s.ratio === null ? "No hay gastos en el periodo" : "Por cada $1 de gasto ingresan $" + ratioText(s.ratio), s.ratio === null ? "" : s.ratio < 1 ? "bad" : "good");
      html += "</div>";
      html += '<h3 class="scm-qm-section">Composición del gasto</h3>';
      html += '<div class="scm-cm-kpis">';
      html += kpi("Servicios", money(s.service), "Revisiones y mantenimientos");
      html += kpi("Transporte", money(s.transport), "Transporte de los reportes");
      html += kpi("Pagado", money(s.pagado), "Reportes ya pagados", "good");
      html += kpi("En cuenta de cobro", money(s.en_cobro), "Cobrados, pendientes de pago", "stock");
      html += kpi("Sin cobrar", money(s.sin_cobrar), "Aún no están en una cuenta de cobro");
      html += "</div>";
      html += employeesNote() + undatedNote();

      // Mes a mes: los meses del año o del acumulado; no aplica a un mes suelto.
      state.monthly = p.months.map(function (ym) {
        var range = { from: ym, to: ym };
        var monthly = summarize(
          incomeFor(range),
          expenses.filter(function (row) { return inPeriod(row.fecha, range); })
        );
        monthly.month = ym;
        monthly.name = monthLabel(ym).replace(/^./, function (ch) { return ch.toUpperCase(); });
        return monthly;
      });
      state.periodTitle = p.title;
      exportBtn.hidden = !state.monthly.length;
      if (state.monthly.length) {
        html += '<section class="scm-cm-card is-wide"><header><h2>Ingresos y gastos mes a mes · ' + esc(p.title) + '</h2><p class="scm-cm-hint">Toca un mes para ver su detalle</p></header>' + monthlyChart() + "</section>";
        html += '<section class="scm-cm-card is-wide"><header><h2>Mes a mes · ' + esc(p.title) + "</h2></header>" + monthlyTable(s) + "</section>";
      }

      html += '<div class="scm-cm-grid">';
      html += '<section class="scm-cm-card"><header><h2>Gasto por funcionario</h2></header>' + employeeTable(groupExpense(expense, "funcionario")) + "</section>";
      html += '<section class="scm-cm-card"><header><h2>Gasto por tipo</h2></header>' + typeTable(groupExpense(expense, "categoria"), s.expense) + "</section>";
      html += "</div>";

      html += '<h3 class="scm-qm-section">Detalle de ' + esc(p.label + kindSuffix()) + "</h3>";
      var views = [["ingresos", "Ingresos", income.length], ["gastos", "Gastos", expense.length]];
      html += '<section class="scm-cm-card is-wide"><header><div class="scm-qm-views" role="tablist">' + views.map(function (view) {
        return '<button type="button" role="tab" class="scm-qm-view' + (state.view === view[0] ? " is-active" : "") + '" aria-selected="' + (state.view === view[0]) +
          '" data-scm-cb-view="' + view[0] + '">' + esc(view[1]) + " <b>" + num(view[2]) + "</b></button>";
      }).join("") + '</div><label class="scm-cm-search"><span class="material-symbols-outlined" aria-hidden="true">search</span>' +
        '<input type="search" data-scm-cb-search placeholder="Número, caso, inmueble o funcionario" value="' + esc(state.search) + '"></label></header>' +
        '<div data-scm-cb-detail></div></section>';

      content.innerHTML = html;
      updateDetail();
    }

    function employeesNote() {
      var excluded = state.data.excluded;
      var note = state.data.expenseNote;
      if (excluded.count) note += " En todo el historial no se cuentan " + num(excluded.count) + (excluded.count === 1 ? " reporte" : " reportes") + " que no corresponden a esta vista (" + money(excluded.value) + ").";
      if (state.data.employeesNotFound.length) note += " No se encontraron reportes de " + state.data.employeesNotFound.join(" ni de ") + ".";
      if (state.data.contractsWithoutRate) note += " " + num(state.data.contractsWithoutRate) + (state.data.contractsWithoutRate === 1 ? " contrato no tiene" : " contratos no tienen") + " % de administración ni de derechos y no suman ingreso.";
      return '<p class="scm-cb-note">' + esc(note) + "</p>";
    }

    function undatedNote() {
      var undated = state.data.undated;
      var parts = [];
      if (undated.income_count && state.data.contracts) parts.push(num(undated.income_count) + (undated.income_count === 1 ? " contrato" : " contratos") + " sin fecha de entrega");
      if (undated.income_count && !state.data.contracts) parts.push(num(undated.income_count) + (undated.income_count === 1 ? " cotización aprobada" : " cotizaciones aprobadas") + " sin fecha de respuesta (" + money(undated.income_value) + ")");
      if (undated.expense_count) parts.push(num(undated.expense_count) + (undated.expense_count === 1 ? " reporte" : " reportes") + " sin fecha (" + money(undated.expense_value) + ")");
      return parts.length ? '<p class="scm-cb-note">No entran en ningún periodo: ' + esc(parts.join(" y ")) + ".</p>" : "";
    }

    // Barras agrupadas sobre una misma escala; solo la barra más alta lleva valor, el resto va en el tooltip y en la tabla.
    function monthlyChart() {
      var max = Math.max.apply(null, state.monthly.map(function (m) { return Math.max(m.income, m.expense); })) || 1;
      var labeled = false;
      function bar(value, color) {
        var height = value ? Math.max(2, (value / max) * 100) : 0;
        var label = value === max && !labeled ? "<i>" + esc(compactMoney(value)) + "</i>" : "";
        if (label) labeled = true;
        return '<span class="scm-cc-bar" style="height:' + height.toFixed(2) + "%;background:" + color + '">' + label + "</span>";
      }
      var multiYear = state.monthly[0].month.slice(0, 4) !== state.monthly[state.monthly.length - 1].month.slice(0, 4);
      var html = '<div class="scm-cc-legend"><span><i style="background:' + INCOME_COLOR + '"></i>Ingresos</span><span><i style="background:' + EXPENSE_COLOR + '"></i>Gastos</span></div>';
      html += '<div class="scm-cc-columns" style="--cols:' + state.monthly.length + '"><div class="scm-cc-plot">';
      state.monthly.forEach(function (m) {
        html += '<button type="button" class="scm-cc-col scm-cb-col" data-scm-cb-month="' + m.month + '" data-tip="' +
          esc(m.name + ": ingresos " + money(m.income) + " · gastos " + money(m.expense) + " · beneficio neto " + money(m.net)) + '">' +
          bar(m.income, INCOME_COLOR) + bar(m.expense, EXPENSE_COLOR) + "</button>";
      });
      return html + '</div><div class="scm-cc-axis">' + state.monthly.map(function (m) {
        return "<span>" + MONTHS_SHORT[Number(m.month.slice(5, 7)) - 1] + (multiYear ? " " + m.month.slice(2, 4) : "") + "</span>";
      }).join("") + "</div></div>";
    }

    function incomeUnit() { return state.data.contracts ? "Contratos" : "Cotizaciones"; }

    function cash(value, cls) {
      return '<td class="is-num' + (cls || "") + '">' + (value ? money(value) : '<span class="scm-cm-muted">—</span>') + "</td>";
    }
    function count(value) {
      return '<td class="is-num">' + (value ? num(value) : '<span class="scm-cm-muted">—</span>') + "</td>";
    }

    // El total usa los mismos gastos e ingresos del periodo que los indicadores.
    function monthlyTable(total) {
      var multiYear = state.monthly[0].month.slice(0, 4) !== state.monthly[state.monthly.length - 1].month.slice(0, 4);
      var body = state.monthly.map(function (m) {
        var name = multiYear ? m.name : MONTHS_LONG[Number(m.month.slice(5, 7)) - 1];
        return '<tr><td><button type="button" class="scm-cm-link" data-scm-cb-month="' + m.month + '">' + esc(name) + "</button></td>" +
          count(m.quotes) + cash(m.income) + count(m.reports) + cash(m.expense) + cash(m.net, m.net < 0 ? " is-bad" : " is-total") +
          '<td class="is-num">' + ratioText(m.ratio) + "</td></tr>";
      }).join("");
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-qm-monthly"><thead><tr><th>Mes</th><th class="is-num">' + incomeUnit() + '</th><th class="is-num">Ingresos</th>' +
        '<th class="is-num">Reportes</th><th class="is-num">Gastos</th><th class="is-num">Beneficio neto</th><th class="is-num">Relación B/C</th></tr></thead><tbody>' + body +
        '</tbody><tfoot><tr><td>Total</td><td class="is-num">' + num(total.quotes) + '</td><td class="is-num">' + money(total.income) + '</td><td class="is-num">' + num(total.reports) +
        '</td><td class="is-num">' + money(total.expense) + '</td><td class="is-num' + (total.net < 0 ? " is-bad" : " is-total") + '">' + money(total.net) +
        '</td><td class="is-num">' + ratioText(total.ratio) + "</td></tr></tfoot></table></div>";
    }

    function employeeTable(groups) {
      if (!groups.length) return '<div class="scm-cm-empty">No hay gastos en este periodo.</div>';
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Funcionario</th><th class="is-num">Reportes</th><th class="is-num">Servicios</th><th class="is-num">Transporte</th><th class="is-num">Total</th></tr></thead><tbody>' +
        groups.map(function (g) {
          return '<tr><td class="is-name">' + esc(g.name) + "</td>" + count(g.count) + cash(g.service) + cash(g.transport) + cash(g.total, " is-total") + "</tr>";
        }).join("") + "</tbody></table></div>";
    }

    function typeTable(groups, total) {
      if (!groups.length) return '<div class="scm-cm-empty">No hay gastos en este periodo.</div>';
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Tipo</th><th class="is-num">Reportes</th><th class="is-num">Total</th><th class="is-num">% del gasto</th></tr></thead><tbody>' +
        groups.map(function (g) {
          return "<tr><td>" + esc(g.name) + "</td>" + count(g.count) + cash(g.total, " is-total") + '<td class="is-num">' + (total ? decimalFormat.format((g.total / total) * 100) + "%" : "—") + "</td></tr>";
        }).join("") + "</tbody></table></div>";
    }

    function updateDetail() {
      var query = normalize(state.search.trim());
      state.detail = state.lists[state.view].filter(function (row) { return !query || row.blob.indexOf(query) !== -1; })
        .sort(function (a, b) { return a.fecha < b.fecha ? 1 : a.fecha > b.fecha ? -1 : b.id - a.id; });
      renderDetail();
    }

    function renderDetail() {
      var holder = content.querySelector("[data-scm-cb-detail]");
      if (!holder) return;
      var rows = state.detail;
      if (!rows.length) {
        holder.innerHTML = '<div class="scm-cm-empty">No hay registros en este periodo.</div>';
        return;
      }
      var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      state.page = Math.min(Math.max(1, state.page), pages);
      var slice = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);
      var html = state.view === "gastos" ? expenseDetail(slice, rows) : state.data.contracts ? contractDetail(slice, rows) : incomeDetail(slice, rows);
      html += '<div class="scm-cm-pager"><span>' + esc(num(rows.length) + (rows.length === 1 ? " registro" : " registros")) + "</span>";
      if (pages > 1) {
        html += '<div><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cb-page="' + (state.page - 1) + '"' + (state.page === 1 ? " disabled" : "") +
          ">Anterior</button><span>Página " + state.page + " de " + pages + '</span><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cb-page="' +
          (state.page + 1) + '"' + (state.page === pages ? " disabled" : "") + ">Siguiente</button></div>";
      }
      holder.innerHTML = html + "</div>";
    }

    function caseCell(ticket) {
      return "<td>" + (ticket ? "#" + esc(ticket) : '<span class="scm-cm-muted">—</span>') + "</td>";
    }

    function contractDetail(slice, rows) {
      var sum = rows.reduce(function (out, row) { out.commission += row.comision; out.rights += row.derechos; out.total += row.admin; return out; }, { commission: 0, rights: 0, total: 0 });
      return '<p class="scm-cb-note is-table">Lista los contratos con fecha de entrega en el periodo: cada uno suma un mes de comisión y sus derechos.</p>' +
        '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Contrato</th><th>Entrega</th><th>Inmueble</th>' +
        '<th class="is-num">Canon</th><th class="is-num">Comisión</th><th class="is-num">Derechos</th><th class="is-num">Total</th></tr></thead><tbody>' + slice.map(function (row) {
          return "<tr><td><strong>" + esc(row.contrato || "#" + row.id) + "</strong></td><td>" + esc(formatDay(row.fecha)) + "</td><td>" + esc(row.inmueble || "—") + "</td>" +
            cash(row.canon) + cash(row.comision) + cash(row.derechos) + cash(row.admin, " is-total") + "</tr>";
        }).join("") + '</tbody><tfoot><tr><td colspan="4">Total</td><td class="is-num">' + esc(money(sum.commission)) + '</td><td class="is-num">' + esc(money(sum.rights)) + '</td><td class="is-num is-total">' + esc(money(sum.total)) + "</td></tr></tfoot></table></div>";
    }

    function incomeDetail(slice, rows) {
      var sum = rows.reduce(function (out, row) { out.total += row.total || 0; out.admin += row.admin || 0; return out; }, { total: 0, admin: 0 });
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Cotización</th><th>Aprobada</th><th>Caso</th><th>Contrato</th><th>Inmueble</th><th>Tipo</th>' +
        '<th class="is-num">Valor total</th><th class="is-num">Administración</th><th></th></tr></thead><tbody>' + slice.map(function (row) {
          return '<tr class="scm-maintenance-quote-row" data-cotizacion-id="' + esc(row.id) + '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">' +
            "<td><strong>#" + esc(row.id) + "</strong></td><td>" + esc(formatDay(row.fecha)) + "</td>" + caseCell(row.ticket) + "<td>" + esc(row.contrato || "—") + "</td><td>" + esc(row.inmueble || "—") + "</td><td>" + esc(row.tipo) + "</td>" +
            '<td class="is-num">' + esc(money(row.total)) + '</td><td class="is-num is-total">' + esc(money(row.admin)) + "</td>" +
            '<td class="is-actions"><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-maintenance-quote-open-case data-cotizacion-id="' + esc(row.id) + '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">Ver caso</button></td></tr>';
        }).join("") + '</tbody><tfoot><tr><td colspan="6">Total</td><td class="is-num">' + esc(money(sum.total)) + '</td><td class="is-num is-total">' + esc(money(sum.admin)) + "</td><td></td></tr></tfoot></table></div>";
    }

    function expenseDetail(slice, rows) {
      var sum = rows.reduce(function (out, row) { out.service += row.servicio || 0; out.transport += row.transporte || 0; out.total += row.total || 0; return out; }, { service: 0, transport: 0, total: 0 });
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Reporte</th><th>Fecha</th><th>Caso</th><th>Inmueble</th><th>Funcionario</th><th>Tipo</th><th>Estado</th>' +
        '<th class="is-num">Servicio</th><th class="is-num">Transporte</th><th class="is-num">Total</th></tr></thead><tbody>' + slice.map(function (row) {
          var paymentState = STATES[row.estado] || STATES.sin_cobrar;
          return "<tr><td><strong>#" + esc(row.id) + "</strong></td><td>" + esc(formatDay(row.fecha)) + "</td>" + caseCell(row.ticket) + "<td>" + esc(row.inmueble || "—") + '</td><td class="is-name">' + esc(row.funcionario) + "</td><td>" + esc(row.categoria) + "</td>" +
            '<td><span class="scm-cm-badge ' + paymentState[1] + '">' + esc(paymentState[0]) + "</span></td>" + cash(row.servicio) + cash(row.transporte) + cash(row.total, " is-total") + "</tr>";
        }).join("") + '</tbody><tfoot><tr><td colspan="7">Total</td><td class="is-num">' + esc(money(sum.service)) + '</td><td class="is-num">' + esc(money(sum.transport)) + '</td><td class="is-num is-total">' + esc(money(sum.total)) + "</td></tr></tfoot></table></div>";
    }

    function exportCsv() {
      var lines = [["Mes", state.data.contracts ? "Contratos" : "Cotizaciones aprobadas", "Ingresos", "Reportes", "Gastos", "Beneficio neto", "Relacion beneficio/costo"]];
      state.monthly.forEach(function (m) {
        lines.push([m.name, m.quotes, m.income, m.reports, m.expense, m.net, m.ratio === null ? "" : m.ratio.toFixed(2).replace(".", ",")]);
      });
      var csv = "﻿" + lines.map(function (line) {
        return line.map(function (cell) {
          var text = String(cell == null ? "" : cell);
          return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }).join(";");
      }).join("\r\n");
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = "costo-" + module.getAttribute("data-scope") + "-" + (state.kind === "general" ? "" : state.kind + "-") + String(state.periodTitle || "").replace(/[^0-9a-z]+/gi, "-").toLowerCase() + ".csv";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    }

    module.addEventListener("change", function (event) {
      if (!state.data || !event.target.matches("[data-scm-cb-filter]")) return;
      state.page = 1;
      render();
    });
    var searchTimer = 0;
    module.addEventListener("input", function (event) {
      if (!event.target.matches("[data-scm-cb-search]")) return;
      var value = event.target.value;
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.search = value;
        state.page = 1;
        // Solo se repinta el detalle para no perder el foco del buscador.
        updateDetail();
      }, 150);
    });
    module.addEventListener("click", function (event) {
      if (event.target.closest("[data-scm-cb-refresh]")) { load(true); return; }
      var kind = event.target.closest("[data-scm-cb-kind]");
      if (kind) {
        setKind(kind.getAttribute("data-scm-cb-kind"));
        state.page = 1;
        render();
        return;
      }
      var mode = event.target.closest("[data-scm-cb-mode]");
      if (mode) {
        setMode(mode.getAttribute("data-scm-cb-mode"));
        state.page = 1;
        render();
        return;
      }
      if (!state.data) return;
      if (event.target.closest("[data-scm-cb-export]")) { exportCsv(); return; }
      var monthButton = event.target.closest("[data-scm-cb-month]");
      if (monthButton) {
        // Tocar un mes del año o del acumulado abre ese mes.
        var value = monthButton.getAttribute("data-scm-cb-month");
        monthInput.value = value;
        setMode(value === currentMonth ? "actual" : "mes");
        state.page = 1;
        render();
        return;
      }
      var view = event.target.closest("[data-scm-cb-view]");
      if (view) {
        state.view = view.getAttribute("data-scm-cb-view");
        state.page = 1;
        render();
        return;
      }
      var page = event.target.closest("[data-scm-cb-page]");
      if (page && !page.disabled) {
        state.page = Number(page.getAttribute("data-scm-cb-page")) || 1;
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
