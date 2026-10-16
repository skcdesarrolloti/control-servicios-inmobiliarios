(function () {
  "use strict";

  // Los contratos llegan en una sola petición y todo lo demás (vistas, filtros,
  // drill-down por mes, tablas y CSV) se calcula aquí, sin volver al servidor.
  var CACHE_KEY = "scm-contract-movements-v1";
  var CACHE_TTL_MS = 10 * 60 * 1000;
  var PAGE_SIZE = 15;
  var MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
  var MONTHS_LONG = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
  var STATE_LABELS = { entregado: "Entregado", por_recibir: "Por recibir", recibido: "Recibido" };
  var FILTER_LABELS = { funcionario: "Funcionario", tipo: "Tipo", destinacion: "Destinación" };
  var VIEWS = {
    ocupaciones: {
      title: "Ocupaciones",
      tab: "ocupaciones",
      subtitle: "Contratos entregados al arrendatario según su fecha de entrega (o el inicio del contrato si no la tiene).",
    },
    desocupaciones: {
      title: "Desocupaciones",
      tab: "desocupaciones",
      subtitle: "Contratos recibidos según su fecha de recibo (o el fin del contrato si no la tiene).",
    },
    comparativo: {
      title: "Ocupaciones vs Desocupaciones",
      tab: "ocupaciones_vs_desocupaciones",
      subtitle: "Balance entre inmuebles que se arriendan y los que se desocupan, con el inventario arrendado al cierre de cada periodo.",
    },
  };

  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var decimalFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 1 });

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function money(value) { return moneyFormat.format(value || 0); }
  function compactMoney(value) {
    var abs = Math.abs(value || 0);
    var sign = value < 0 ? "-" : "";
    if (abs >= 1e9) return sign + "$" + decimalFormat.format(abs / 1e9) + " mil M";
    if (abs >= 1e6) return sign + "$" + decimalFormat.format(abs / 1e6) + " M";
    if (abs >= 1e3) return sign + "$" + decimalFormat.format(abs / 1e3) + " mil";
    return sign + "$" + num(abs);
  }
  function signed(value, formatter) {
    return (value > 0 ? "+" : "") + (formatter || num)(value);
  }
  function formatDate(iso) {
    if (!iso) return "Sin fecha";
    return iso.slice(8, 10) + " " + MONTHS[Number(iso.slice(5, 7)) - 1] + " " + iso.slice(0, 4);
  }
  function todayIso() {
    var now = new Date();
    return now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") + "-" + String(now.getDate()).padStart(2, "0");
  }
  function lastDayIso(year, month) {
    var day = new Date(year, month, 0).getDate();
    return year + "-" + String(month).padStart(2, "0") + "-" + String(day).padStart(2, "0");
  }

  function readCache() {
    try {
      var raw = window.sessionStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      var cached = JSON.parse(raw);
      if (!cached || !cached.savedAt || Date.now() - cached.savedAt > CACHE_TTL_MS) return null;
      return cached.snapshot || null;
    } catch (error) {
      return null;
    }
  }
  function writeCache(snapshot) {
    try {
      window.sessionStorage.setItem(CACHE_KEY, JSON.stringify({ savedAt: Date.now(), snapshot: snapshot }));
    } catch (error) {
      // Sin almacenamiento disponible solo se pierde la caché entre recargas.
    }
  }
  function clearCache() {
    try { window.sessionStorage.removeItem(CACHE_KEY); } catch (error) { /* sin caché */ }
  }

  function parseSnapshot(snapshot) {
    var columns = snapshot.columns || [];
    var names = snapshot.funcionarios || {};
    var years = {};
    var options = { funcionario: {}, tipo: {}, destinacion: {} };
    var rows = (snapshot.rows || []).map(function (values) {
      var row = {};
      columns.forEach(function (column, index) { row[column] = values[index]; });
      row.funcionario = row.funcionario ? String(row.funcionario) : "";
      row.funcionarioNombre = row.funcionario ? names[row.funcionario] || "Funcionario " + row.funcionario : "";
      row.ingY = row.ingreso ? Number(row.ingreso.slice(0, 4)) : 0;
      row.ingM = row.ingreso ? Number(row.ingreso.slice(5, 7)) : 0;
      row.egY = row.egreso ? Number(row.egreso.slice(0, 4)) : 0;
      row.egM = row.egreso ? Number(row.egreso.slice(5, 7)) : 0;
      row.permanencia = row.ingreso && row.egreso
        ? Math.max(0, Math.round((Date.parse(row.egreso) - Date.parse(row.ingreso)) / (30.4375 * 86400000)))
        : null;
      if (row.ingY) years[row.ingY] = true;
      if (row.egY && row.estado === "recibido") years[row.egY] = true;
      if (row.funcionario) options.funcionario[row.funcionario] = row.funcionarioNombre;
      ["tipo", "destinacion"].forEach(function (key) {
        if (row[key]) options[key][row[key]] = row[key];
      });
      return row;
    });
    var sortedOptions = {};
    Object.keys(options).forEach(function (key) {
      sortedOptions[key] = Object.keys(options[key])
        .map(function (value) { return { value: value, label: options[key][value] }; })
        .sort(function (a, b) { return a.label.localeCompare(b.label, "es"); });
    });
    return {
      rows: rows,
      generatedAt: Number(snapshot.generated_at || 0),
      years: Object.keys(years).map(Number).sort(function (a, b) { return b - a; }),
      options: sortedOptions,
    };
  }

  function eventsOf(rows, kind) {
    var events = [];
    rows.forEach(function (row) {
      if (kind === "ocup" && row.ingreso) {
        events.push({ row: row, date: row.ingreso, y: row.ingY, m: row.ingM });
      } else if (kind === "desoc" && row.estado === "recibido" && row.egreso) {
        events.push({ row: row, date: row.egreso, y: row.egY, m: row.egM });
      }
    });
    return events;
  }
  function summarize(events) {
    var canon = 0;
    events.forEach(function (event) { canon += event.row.canon || 0; });
    return { count: events.length, canon: canon, avg: events.length ? canon / events.length : 0 };
  }
  // Contratos arrendados en una fecha: ya entregados y aún no recibidos.
  function stockAt(rows, iso) {
    var total = 0;
    rows.forEach(function (row) {
      if (!row.ingreso || row.ingreso > iso) return;
      if (row.estado === "recibido" && (!row.egreso || row.egreso <= iso)) return;
      total += 1;
    });
    return total;
  }
  function kpi(label, value, note, tone) {
    return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" +
      esc(value) + "</strong>" + (note ? "<small>" + note + "</small>" : "") + "</article>";
  }
  function card(title, body, extraClass, aside) {
    return '<section class="scm-cm-card' + (extraClass ? " " + extraClass : "") + '"><header><h2>' + esc(title) + "</h2>" +
      (aside || "") + "</header>" + body + "</section>";
  }

  function init(root) {
    var module = root.querySelector("[data-scm-contract-movements]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.cmInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.cmInit = "1";

    var action = (runtime.actions || {}).contract_movements || "";
    var panel = module.closest(".scm-tab-panel");
    var content = module.querySelector("[data-scm-cm-content]");
    var status = module.querySelector("[data-scm-cm-status]");
    var titleEl = module.querySelector("[data-scm-cm-title]");
    var subtitleEl = module.querySelector("[data-scm-cm-subtitle]");
    var updatedEl = module.querySelector("[data-scm-cm-updated]");
    var exportBtn = module.querySelector("[data-scm-cm-export]");
    var clearBtn = module.querySelector("[data-scm-cm-clear]");
    var selects = {};
    module.querySelectorAll("[data-scm-cm-filter]").forEach(function (select) {
      selects[select.getAttribute("data-scm-cm-filter")] = select;
    });

    var state = {
      view: VIEWS[module.getAttribute("data-initial-view")] ? module.getAttribute("data-initial-view") : "ocupaciones",
      filters: { year: String(new Date().getFullYear()), funcionario: "", tipo: "", destinacion: "" },
      month: 0,
      search: "",
      page: 1,
      data: null,
      pending: null,
      detailEvents: [],
    };

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
          return response.json().catch(function () {
            throw new Error("El servidor devolvió una respuesta no válida.");
          });
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || "No se pudieron cargar los contratos.");
          }
          return json.data || {};
        });
    }

    function load(force) {
      if (state.pending) return state.pending;
      if (state.data && !force) {
        render();
        return Promise.resolve();
      }
      var cached = force ? null : readCache();
      if (cached) {
        applySnapshot(cached);
        return Promise.resolve();
      }
      if (force) clearCache();
      setStatus(state.data ? "Actualizando contratos…" : "Cargando contratos…", "loading");
      module.classList.add("is-busy");
      state.pending = request()
        .then(function (snapshot) {
          writeCache(snapshot);
          applySnapshot(snapshot);
          if (panel) panel.setAttribute("data-scm-loaded", "1");
        })
        .catch(function (error) {
          setStatus(error.message || "No se pudieron cargar los contratos.", "error");
        })
        .then(function () {
          state.pending = null;
          module.classList.remove("is-busy");
        });
      return state.pending;
    }

    function applySnapshot(snapshot) {
      state.data = parseSnapshot(snapshot);
      if (state.filters.year && state.data.years.indexOf(Number(state.filters.year)) === -1 && state.data.years.length) {
        state.filters.year = String(state.data.years[0]);
      }
      fillSelects();
      setStatus("");
      exportBtn.disabled = false;
      render();
    }

    function fillSelects() {
      var data = state.data;
      selects.year.innerHTML = '<option value="">Todos los años</option>' + data.years.map(function (year) {
        return '<option value="' + year + '">' + year + "</option>";
      }).join("");
      ["funcionario", "tipo", "destinacion"].forEach(function (key) {
        var first = selects[key].options[0] ? selects[key].options[0].outerHTML : '<option value="">Todos</option>';
        selects[key].innerHTML = first + data.options[key].map(function (option) {
          return '<option value="' + esc(option.value) + '">' + esc(option.label) + "</option>";
        }).join("");
      });
      syncSelects();
    }

    function syncSelects() {
      Object.keys(selects).forEach(function (key) {
        if (key !== "month") selects[key].value = state.filters[key] || "";
      });
      // El mes solo tiene sentido dentro de un año.
      if (!selectedYear()) state.month = 0;
      selects.month.value = state.month ? String(state.month) : "";
      selects.month.disabled = !selectedYear();
      var dirty = !!(state.filters.funcionario || state.filters.tipo || state.filters.destinacion || state.month ||
        state.filters.year !== String(new Date().getFullYear()));
      clearBtn.disabled = !dirty;
    }

    function baseRows() {
      var f = state.filters;
      return state.data.rows.filter(function (row) {
        return (!f.funcionario || row.funcionario === f.funcionario) &&
          (!f.tipo || row.tipo === f.tipo) &&
          (!f.destinacion || row.destinacion === f.destinacion);
      });
    }

    function selectedYear() {
      return Number(state.filters.year) || 0;
    }


    function render() {
      if (!state.data) return;
      var view = VIEWS[state.view];
      titleEl.textContent = view.title;
      subtitleEl.textContent = view.subtitle;
      module.querySelectorAll("[data-scm-subview-target]").forEach(function (button) {
        var active = button.getAttribute("data-scm-subview-target") === state.view;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
      });
      updatedEl.textContent = state.data.generatedAt
        ? "Datos de las " + new Date(state.data.generatedAt * 1000).toLocaleTimeString("es-CO", { hour: "2-digit", minute: "2-digit" })
        : "";
      syncSelects();
      var html = renderChips();
      html += state.view === "comparativo" ? renderComparison() : renderMovements(state.view === "ocupaciones" ? "ocup" : "desoc");
      content.innerHTML = html;
      renderDetail();
    }

    function renderChips() {
      var chips = [];
      var year = selectedYear();
      if (year && state.month) {
        chips.push({ key: "month", label: "Mes: " + MONTHS_LONG[state.month - 1] + " " + year });
      }
      Object.keys(FILTER_LABELS).forEach(function (key) {
        var value = state.filters[key];
        if (!value) return;
        var label = key === "funcionario"
          ? (state.data.options.funcionario.find(function (o) { return o.value === value; }) || { label: value }).label
          : value;
        chips.push({ key: key, label: FILTER_LABELS[key] + ": " + label });
      });
      if (!chips.length) return "";
      return '<div class="scm-cm-chips" aria-label="Filtros aplicados">' + chips.map(function (chip) {
        return '<button type="button" class="scm-cm-chip" data-scm-cm-unset="' + chip.key + '" aria-label="Quitar ' + esc(chip.label) + '">' +
          esc(chip.label) + '<span class="material-symbols-outlined" aria-hidden="true">close</span></button>';
      }).join("") + "</div>";
    }

    function renderMovements(kind) {
      var isOcup = kind === "ocup";
      var noun = isOcup ? "ocupaciones" : "desocupaciones";
      var rows = baseRows();
      var all = eventsOf(rows, kind);
      var year = selectedYear();
      var period = year ? all.filter(function (event) { return event.y === year; }) : all;
      var shown = year && state.month ? period.filter(function (event) { return event.m === state.month; }) : period;
      var periodLabel = year ? (state.month ? MONTHS_LONG[state.month - 1] + " " + year : String(year)) : "todos los años";
      var current = summarize(shown);
      var html = '<div class="scm-cm-kpis scm-cm-kpis-6">';
      html += kpi(isOcup ? "Total ocupaciones" : "Total desocupaciones", num(current.count),
        esc(year ? periodLabel : "Histórico completo"), isOcup ? "ocup" : "desoc");
      html += kpi("Canon promedio", money(current.avg), esc(current.count ? "Todos los inmuebles del periodo" : "Sin movimientos"));
      var byDestination = function (name) {
        return summarize(shown.filter(function (event) { return event.row.destinacion === name; }));
      };
      var housing = byDestination("Vivienda");
      var commerce = byDestination("Comercio");
      var share = function (part) { return current.count ? Math.round((part / current.count) * 100) + "% del total" : "—"; };
      html += kpi("Vivienda", num(housing.count), esc(share(housing.count)));
      html += kpi("Canon promedio vivienda", housing.count ? money(housing.avg) : "—", esc(housing.count ? num(housing.count) + " inmuebles" : "Sin vivienda"));
      html += kpi("Comercio", num(commerce.count), esc(share(commerce.count)));
      html += kpi("Canon promedio comercio", commerce.count ? money(commerce.avg) : "—", esc(commerce.count ? num(commerce.count) + " inmuebles" : "Sin comercio"));
      html += "</div>";

      if (!isOcup) html += renderUpcoming(rows);

      state.detailEvents = shown.slice().sort(function (a, b) { return a.date < b.date ? 1 : a.date > b.date ? -1 : 0; });
      state.detailKind = kind;
      html += card("Detalle de " + noun + " · " + periodLabel, '<div data-scm-cm-detail></div>', "is-wide",
        '<label class="scm-cm-search"><span class="material-symbols-outlined" aria-hidden="true">search</span>' +
        '<input type="search" data-scm-cm-search placeholder="Buscar contrato, inmueble, dirección o arrendatario" value="' + esc(state.search) + '"></label>');

      var missing = isOcup
        ? rows.filter(function (row) { return !row.ingreso; })
        : rows.filter(function (row) { return row.estado === "recibido" && !row.egreso; });
      if (missing.length) {
        html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>' +
          esc(num(missing.length) + (missing.length === 1 ? " contrato no se cuenta" : " contratos no se cuentan") +
          " porque no tiene" + (missing.length === 1 ? "" : "n") + " fecha de " + (isOcup ? "entrega ni inicio" : "recibo ni fin") +
          ": " + missing.slice(0, 8).map(function (row) { return row.contrato || "#" + row.id; }).join(", ") + (missing.length > 8 ? "…" : "")) + "</p>";
      }
      return html;
    }

    function yearsAxis(events) {
      var set = {};
      events.forEach(function (event) { set[event.y] = true; });
      var years = Object.keys(set).map(Number).sort(function (a, b) { return a - b; });
      if (!years.length) return [];
      var out = [];
      for (var y = Math.max(years[0], years[years.length - 1] - 14); y <= years[years.length - 1]; y += 1) out.push(y);
      return out;
    }

    function renderUpcoming(rows) {
      var upcoming = rows.filter(function (row) { return row.estado === "por_recibir"; })
        .sort(function (a, b) { return (a.fin || "9999") < (b.fin || "9999") ? -1 : 1; });
      if (!upcoming.length) return "";
      var today = todayIso();
      var body = '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Fin previsto</th><th>Contrato</th><th>Inmueble</th><th>Dirección</th><th>Funcionario</th><th class="is-num">Canon</th></tr></thead><tbody>' +
        upcoming.map(function (row) {
          var late = row.fin && row.fin < today;
          return "<tr><td>" + esc(formatDate(row.fin)) + (late ? ' <span class="scm-cm-badge is-bad">Vencido</span>' : "") + "</td><td>" + esc(row.contrato) +
            "</td><td>" + esc(row.inmueble) + "</td><td>" + esc(row.direccion) + "</td><td>" + esc(row.funcionarioNombre || "—") +
            '</td><td class="is-num">' + esc(money(row.canon)) + "</td></tr>";
        }).join("") + "</tbody></table></div>";
      return card("Por desocupar · " + num(upcoming.length) + " en estado Por recibir", body, "is-wide");
    }

    function renderDetail() {
      var holder = content.querySelector("[data-scm-cm-detail]");
      if (!holder) return;
      var query = state.search.trim().toLowerCase();
      var events = query
        ? state.detailEvents.filter(function (event) {
          var row = event.row;
          return [row.contrato, row.inmueble, row.direccion, row.arrendatario, row.funcionarioNombre, row.tipo]
            .join(" ").toLowerCase().indexOf(query) !== -1;
        })
        : state.detailEvents;
      var pages = Math.max(1, Math.ceil(events.length / PAGE_SIZE));
      state.page = Math.min(Math.max(1, state.page), pages);
      var slice = events.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);
      if (!slice.length) {
        holder.innerHTML = '<div class="scm-cm-empty">' + (query ? "Ningún contrato coincide con la búsqueda." : "No hay movimientos en este periodo.") + "</div>";
        return;
      }
      var isDesoc = state.detailKind === "desoc";
      var html = '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr>' +
        (isDesoc ? "<th>Fecha recibo</th><th>Fin contrato</th>" : "<th>Fecha entrega</th>") +
        '<th>Contrato</th><th title="Código del inmueble">Cód. inmueble</th><th>Dirección</th><th>Arrendatario</th><th>Tipo</th><th>Funcionario</th>' +
        '<th class="is-num">Duración</th>' + (isDesoc ? '<th class="is-num">Permanencia</th>' : '<th class="is-num">Días de gracia</th>') +
        '<th class="is-num">Canon</th><th class="is-num">Administración</th><th class="is-num">Total</th>' + (isDesoc ? "" : "<th>Estado actual</th>") + "</tr></thead><tbody>";
      html += slice.map(function (event) {
        var row = event.row;
        // Si falta la fecha propia del evento, se contó con la fecha de respaldo (inicio o fin del contrato).
        var first = isDesoc ? row.recibo : row.entrega;
        var firstCell = first ? esc(formatDate(first))
          : esc(formatDate(event.date)) + '<small class="scm-cm-source">' + (isDesoc ? "fin del contrato" : "inicio del contrato") + "</small>";
        var dates = "<td>" + firstCell + "</td>" + (isDesoc ? "<td>" + (row.fin ? esc(formatDate(row.fin)) : '<span class="scm-cm-muted">—</span>') + "</td>" : "");
        return "<tr>" + dates + "<td>" + esc(row.contrato) +
          "</td><td>" + esc(row.inmueble) + '</td><td class="is-address">' + esc(row.direccion) + '</td><td class="is-name">' + esc(row.arrendatario) + "</td><td>" +
          esc(row.tipo || "—") + "</td><td>" + esc(row.funcionarioNombre || "—") + '</td><td class="is-num">' + (row.meses ? esc(row.meses + " meses") : "—") + "</td>" +
          (isDesoc
            ? '<td class="is-num">' + (row.permanencia == null ? "—" : esc(num(row.permanencia) + " meses")) + "</td>"
            : '<td class="is-num">' + graceCell(row) + "</td>") +
          '<td class="is-num">' + esc(money(row.canon)) + '</td><td class="is-num">' + (row.admin ? esc(money(row.admin)) : '<span class="scm-cm-muted">No aplica</span>') + "</td>" +
          '<td class="is-num is-total">' + esc(money((row.canon || 0) + (row.admin || 0))) + "</td>" +
          (isDesoc ? "" : '<td><span class="scm-cm-badge is-' + row.estado + '">' + esc(STATE_LABELS[row.estado] || row.estado) + "</span></td>") + "</tr>";
      }).join("");
      // La fila de totales suma todos los contratos filtrados, no solo los de la página.
      var sumCanon = 0;
      var sumAdmin = 0;
      events.forEach(function (event) {
        sumCanon += event.row.canon || 0;
        sumAdmin += event.row.admin || 0;
      });
      html += '</tbody><tfoot><tr><td colspan="' + (isDesoc ? 10 : 9) + '">Total · ' + esc(num(events.length) + (events.length === 1 ? " contrato" : " contratos")) +
        '</td><td class="is-num">' + esc(money(sumCanon)) + '</td><td class="is-num">' + esc(money(sumAdmin)) + '</td><td class="is-num is-total">' + esc(money(sumCanon + sumAdmin)) +
        "</td>" + (isDesoc ? "" : "<td></td>") + "</tr></tfoot></table></div>";
      html += '<div class="scm-cm-pager"><span>' + esc(num(events.length) + (events.length === 1 ? " contrato" : " contratos")) + "</span>";
      if (pages > 1) {
        html += '<div><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cm-page="' + (state.page - 1) + '"' + (state.page === 1 ? " disabled" : "") +
          '>Anterior</button><span>Página ' + state.page + " de " + pages + '</span><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cm-page="' +
          (state.page + 1) + '"' + (state.page === pages ? " disabled" : "") + ">Siguiente</button></div>";
      }
      holder.innerHTML = html + "</div>";
    }

    function graceCell(row) {
      if (row.dias_gracia == null) return '<span class="scm-cm-muted">Sin dato</span>';
      var source = row.dias_gracia_fuente && row.dias_gracia_fuente !== "contrato"
        ? '<small class="scm-cm-source">' + (row.dias_gracia_fuente === "cierre" ? "hoja de cierre" : "mandato") + "</small>"
        : "";
      return esc(row.dias_gracia === 1 ? "1 día" : num(row.dias_gracia) + " días") + source;
    }

    function comparisonRows(rows, oc, de, buckets) {
      return buckets.map(function (bucket) {
        var o = oc.filter(bucket.match);
        var d = de.filter(bucket.match);
        var so = summarize(o);
        var sd = summarize(d);
        return {
          label: bucket.label,
          value: bucket.value,
          oc: so.count,
          de: sd.count,
          net: so.count - sd.count,
          canonOc: so.canon,
          canonDe: sd.canon,
          canonNet: so.canon - sd.canon,
          ratio: sd.count ? (so.count / sd.count) * 100 : null,
          stock: bucket.closing ? stockAt(rows, bucket.closing) : null,
        };
      });
    }

    function renderComparison() {
      var rows = baseRows();
      var oc = eventsOf(rows, "ocup");
      var de = eventsOf(rows, "desoc");
      var year = selectedYear();
      var today = todayIso();
      var currentYear = new Date().getFullYear();
      var month = year ? state.month : 0;
      var inPeriod = function (event) { return !year || event.y === year && (!month || event.m === month); };
      var so = summarize(oc.filter(inPeriod));
      var sd = summarize(de.filter(inPeriod));
      var closing = !year || year >= currentYear && !month ? today : month ? lastDayIso(year, month) : year + "-12-31";
      if (closing > today) closing = today;
      var stockEnd = stockAt(rows, closing);
      var net = so.count - sd.count;

      var html = '<div class="scm-cm-kpis scm-cm-kpis-6">';
      html += kpi("Ocupaciones", num(so.count), esc(compactMoney(so.canon) + " de canon"), "ocup");
      html += kpi("Desocupaciones", num(sd.count), esc(compactMoney(sd.canon) + " de canon"), "desoc");
      html += kpi("Balance neto", signed(net), net >= 0 ? "Crece la cartera arrendada" : "Se reduce la cartera arrendada", net > 0 ? "good" : net < 0 ? "bad" : "");
      html += kpi("Índice de reposición", sd.count ? Math.round((so.count / sd.count) * 100) + "%" : "—",
        esc(sd.count ? "Ocupaciones por cada 100 desocupaciones" : "Sin desocupaciones en el periodo"));
      html += kpi("Canon neto", compactMoney(so.canon - sd.canon), esc(money(so.canon - sd.canon) + " mensual"), so.canon - sd.canon >= 0 ? "good" : "bad");
      html += kpi("Inventario arrendado", num(stockEnd), esc(closing === today ? "Contratos arrendados hoy" : "Al cierre de " + formatDate(closing)), "stock");
      html += "</div>";

      var periodRows;
      var exportRows;
      if (year) {
        var lastMonth = year === currentYear ? new Date().getMonth() + 1 : 12;
        periodRows = comparisonRows(rows, oc, de, MONTHS.map(function (label, index) {
          var month = index + 1;
          return {
            label: label,
            value: month,
            match: function (event) { return event.y === year && event.m === month; },
            closing: year > currentYear || month > lastMonth ? null : month === lastMonth && year === currentYear ? today : lastDayIso(year, month),
          };
        }));
        exportRows = periodRows.map(function (row) { return Object.assign({ periodo: MONTHS_LONG[row.value - 1] + " " + year }, row); });
      }
      var years = yearsAxis(oc.concat(de));
      var annualRows = comparisonRows(rows, oc, de, years.map(function (y) {
        return {
          label: String(y),
          value: y,
          match: function (event) { return event.y === y; },
          closing: y >= currentYear ? today : y + "-12-31",
        };
      }));
      if (!year) {
        exportRows = annualRows.map(function (row) { return Object.assign({ periodo: row.label }, row); });
      }

      html += card("Resumen anual", comparisonTable(annualRows.slice().reverse(), "Año", year), "is-wide",
        '<p class="scm-cm-hint">Toca un año para filtrar</p>');
      if (year) html += card("Resumen mensual " + year, comparisonTable(periodRows.filter(function (row) { return row.stock != null || row.oc || row.de; }), "Mes"), "is-wide");
      state.exportRows = exportRows;
      return html;
    }

    function comparisonTable(periodRows, firstColumn, activeYear) {
      if (!periodRows.length) return '<div class="scm-cm-empty">No hay movimientos con estos filtros.</div>';
      var clickable = firstColumn === "Año";
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>' + firstColumn +
        '</th><th class="is-num">Ocupaciones</th><th class="is-num">Desocupaciones</th><th class="is-num">Neto</th><th class="is-num">Reposición</th>' +
        '<th class="is-num">Canon ingresado</th><th class="is-num">Canon retirado</th><th class="is-num">Canon neto</th><th class="is-num">Inventario al cierre</th></tr></thead><tbody>' +
        periodRows.map(function (row) {
          var label = clickable
            ? '<button type="button" class="scm-cm-link" data-scm-cm-pick="year" data-value="' + row.value + '">' + esc(row.label) + "</button>"
            : esc(MONTHS_LONG[row.value - 1]);
          return '<tr class="' + (activeYear && row.value === activeYear ? "is-active" : "") + '"><td>' + label + '</td><td class="is-num">' + num(row.oc) +
            '</td><td class="is-num">' + num(row.de) + '</td><td class="is-num ' + (row.net > 0 ? "is-good" : row.net < 0 ? "is-bad" : "") + '">' + signed(row.net) +
            '</td><td class="is-num">' + (row.ratio == null ? "—" : Math.round(row.ratio) + "%") + '</td><td class="is-num">' + money(row.canonOc) +
            '</td><td class="is-num">' + money(row.canonDe) + '</td><td class="is-num ' + (row.canonNet >= 0 ? "is-good" : "is-bad") + '">' + money(row.canonNet) +
            '</td><td class="is-num">' + (row.stock == null ? "—" : num(row.stock)) + "</td></tr>";
        }).join("") + "</tbody></table></div>";
    }

    function exportCsv() {
      if (!state.data) return;
      var lines;
      var name;
      var year = selectedYear() || "historico";
      if (state.view === "comparativo") {
        lines = [["Periodo", "Ocupaciones", "Desocupaciones", "Neto", "Reposicion %", "Canon ingresado", "Canon retirado", "Canon neto", "Inventario al cierre"]];
        (state.exportRows || []).forEach(function (row) {
          lines.push([row.periodo, row.oc, row.de, row.net, row.ratio == null ? "" : Math.round(row.ratio), row.canonOc, row.canonDe, row.canonNet, row.stock == null ? "" : row.stock]);
        });
        name = "ocupaciones-vs-desocupaciones-" + year;
      } else {
        var isDesoc = state.view === "desocupaciones";
        lines = [["Fecha del movimiento", "Fecha entrega", "Fecha recibo", "Fin contrato", "Contrato", "Codigo inmueble", "Direccion", "Arrendatario",
          "Tipo inmueble", "Destinacion", "Funcionario", "Estado", "Duracion meses", "Dias de gracia", "Fuente dias de gracia", "Canon", "Administracion", "Total", "Permanencia meses"]];
        state.detailEvents.forEach(function (event) {
          var row = event.row;
          lines.push([event.date, row.entrega || "", row.recibo || "", row.fin || "", row.contrato, row.inmueble, row.direccion, row.arrendatario,
            row.tipo, row.destinacion, row.funcionarioNombre, STATE_LABELS[row.estado] || row.estado, row.meses || "",
            row.dias_gracia == null ? "" : row.dias_gracia, row.dias_gracia == null ? "" : row.dias_gracia_fuente, row.canon, row.admin || 0,
            (row.canon || 0) + (row.admin || 0), row.permanencia == null ? "" : row.permanencia]);
        });
        name = state.view + "-" + year + (state.month ? "-" + String(state.month).padStart(2, "0") : "");
      }
      var csv = "﻿" + lines.map(function (line) {
        return line.map(function (cell) {
          var text = String(cell == null ? "" : cell);
          return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }).join(";");
      }).join("\r\n");
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = name + ".csv";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () {
        URL.revokeObjectURL(link.href);
        link.remove();
      }, 0);
    }

    // Mantiene la URL y el menú superior alineados cuando se cambia de vista desde el panel.
    function syncLocation() {
      var tab = VIEWS[state.view].tab;
      try {
        var url = new URL(window.location.href);
        if (url.searchParams.get("tab") !== tab) {
          url.searchParams.set("tab", tab);
          window.history.replaceState({}, "", url.toString());
        }
      } catch (error) { /* URL no disponible */ }
      var item = document.querySelector('.nav-tab-dropdown-item[data-tab-key="' + tab + '"]');
      var menu = item ? item.closest("[data-scm-dropdown-menu]") : null;
      if (!menu) return;
      menu.querySelectorAll(".nav-tab-dropdown-item").forEach(function (pill) {
        var active = pill === item;
        pill.classList.toggle("bg-[#0f1e36]", active);
        pill.classList.toggle("text-white", active);
        pill.classList.toggle("font-semibold", active);
        pill.classList.toggle("text-slate-700", !active);
        pill.classList.toggle("font-medium", !active);
        if (active) pill.setAttribute("aria-current", "page");
        else pill.removeAttribute("aria-current");
        var icon = pill.querySelector(".material-symbols-outlined");
        if (icon) {
          icon.classList.toggle("text-white", active);
          icon.classList.toggle("text-slate-500", !active);
        }
      });
    }

    function resetDrill() {
      state.page = 1;
      state.search = "";
    }

    module.addEventListener("click", function (event) {
      var viewButton = event.target.closest("[data-scm-subview-target]");
      if (viewButton) {
        var view = viewButton.getAttribute("data-scm-subview-target");
        if (VIEWS[view] && view !== state.view) {
          state.view = view;
          resetDrill();
          syncLocation();
          if (state.data) render();
          else load(false);
        }
        return;
      }
      if (!state.data) {
        if (event.target.closest("[data-scm-cm-refresh]")) load(true);
        return;
      }
      if (event.target.closest("[data-scm-cm-refresh]")) {
        load(true);
        return;
      }
      if (event.target.closest("[data-scm-cm-export]")) {
        exportCsv();
        return;
      }
      if (event.target.closest("[data-scm-cm-clear]")) {
        state.filters = { year: String(new Date().getFullYear()), funcionario: "", tipo: "", destinacion: "" };
        if (state.data.years.indexOf(Number(state.filters.year)) === -1) state.filters.year = String(state.data.years[0] || "");
        state.month = 0;
        resetDrill();
        render();
        return;
      }
      var pick = event.target.closest("[data-scm-cm-pick]");
      if (pick) {
        var value = Number(pick.getAttribute("data-value"));
        if (pick.getAttribute("data-scm-cm-pick") === "month") {
          state.month = state.month === value ? 0 : value;
        } else {
          state.filters.year = String(value);
          state.month = 0;
        }
        resetDrill();
        render();
        if (state.month) {
          var detail = content.querySelector("[data-scm-cm-detail]");
          if (detail) detail.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
        return;
      }
      var unset = event.target.closest("[data-scm-cm-unset]");
      if (unset) {
        var key = unset.getAttribute("data-scm-cm-unset");
        if (key === "month") state.month = 0;
        else state.filters[key] = "";
        resetDrill();
        render();
        return;
      }
      var page = event.target.closest("[data-scm-cm-page]");
      if (page && !page.disabled) {
        state.page = Number(page.getAttribute("data-scm-cm-page")) || 1;
        renderDetail();
      }
    });

    module.addEventListener("change", function (event) {
      var select = event.target.closest("[data-scm-cm-filter]");
      if (!select || !state.data) return;
      var key = select.getAttribute("data-scm-cm-filter");
      if (key === "month") {
        state.month = Number(select.value) || 0;
        resetDrill();
        render();
        return;
      }
      state.filters[key] = select.value;
      if (key === "year") state.month = 0;
      resetDrill();
      render();
    });

    var searchTimer = 0;
    module.addEventListener("input", function (event) {
      if (!event.target.matches("[data-scm-cm-search]")) return;
      var value = event.target.value;
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.search = value;
        state.page = 1;
        renderDetail();
      }, 150);
    });

    module.addEventListener("scm:load-contract-movements", function () {
      load(false);
    });
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
