(function () {
  "use strict";

  // Los contratos llegan en una sola petición y todo lo demás (vistas, filtros,
  // drill-down por mes, tablas y CSV) se calcula aquí, sin volver al servidor.
  var CACHE_KEY = "scm-contract-movements-v6";
  var CACHE_TTL_MS = 10 * 60 * 1000;
  var PAGE_SIZE = 15;
  var MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
  var MONTHS_LONG = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
  var STATE_LABELS = { entregado: "Entregado", por_recibir: "Por recibir", recibido: "Recibido" };
  var SIMI_FILTERS = { "": "Auditoría SIMI: todas", Correcto: "Correcto", Incorrecto: "Incorrecto", pendiente: "Sin auditar" };
  var INCREASE_LABELS = { canon: "Canon", admin: "Administración" };
  var SUB_FILTERS = {
    aumentos: { "": "Tipo: todos", canon: "Canon", admin: "Administración" },
  };
  var FILTER_LABELS = { estado: "Estado actual", funcionario: "Funcionario", tipo: "Tipo", destinacion: "Destinación", arrendatario: "Arrendatario", inmueble: "Inmueble", contrato: "Contrato" };
  var TEXT_FILTERS = ["arrendatario", "inmueble", "contrato"];
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
    por_terminar: {
      title: "Contratos por terminar",
      tab: "metricas_por_terminar",
      subtitle: "Contratos por recibir con caso de recibo abierto, según la fecha de terminación que registró el área contractual.",
    },
    aumentos: {
      title: "Aumentos",
      tab: "metricas_aumentos",
      subtitle: "Aumentos de canon y administración según las cartas de aumento, con el valor anterior y el nuevo para facturar.",
    },
  };
  // Vistas que se consultan por mes: abren en el mes en curso.
  var MONTH_VIEWS = { por_terminar: true, aumentos: true };

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
  // Compara sin mayúsculas ni tildes para los filtros de texto libre.
  function fold(value) {
    return String(value == null ? "" : value).toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").trim();
  }
  function emptyFilters() {
    return { year: String(new Date().getFullYear()), estado: "", funcionario: "", tipo: "", destinacion: "", arrendatario: "", inmueble: "", contrato: "" };
  }
  function num(value) { return numberFormat.format(value || 0); }
  function money(value) { return moneyFormat.format(value || 0); }
  function percent(value) { return value == null ? "" : decimalFormat.format(value) + "%"; }
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
      row.estado_simi = row.estado_simi || "";
      row.obs_simi = row.obs_simi || "";
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
    // Casos de recibo y cartas de aumento toman del contrato lo que no guardan, para usar los mismos filtros.
    var byId = {};
    var byCode = {};
    rows.forEach(function (row) {
      byId[String(row.id)] = row;
      if (row.contrato) byCode[row.contrato] = row;
    });
    function contractOf(item) {
      return byId[item.id_contrato] || byCode[item.contrato] || {};
    }
    function linkToContract(items, dateKey) {
      var itemYears = {};
      var linked = items.map(function (item) {
        var contract = contractOf(item);
        var date = item[dateKey];
        var out = Object.assign({}, item, {
          funcionario: contract.funcionario || "",
          funcionarioNombre: contract.funcionarioNombre || "",
          tipo: contract.tipo || "",
          destinacion: contract.destinacion || "",
          canon: contract.canon || 0,
          admin: contract.admin || 0,
          inmueble: item.inmueble || contract.inmueble || "",
          direccion: item.direccion || contract.direccion || "",
          arrendatario: item.arrendatario || contract.arrendatario || "",
          periodY: Number(date.slice(0, 4)),
          periodM: Number(date.slice(5, 7)),
        });
        itemYears[out.periodY] = true;
        return out;
      });
      return { items: linked, years: Object.keys(itemYears).map(Number).sort(function (a, b) { return b - a; }) };
    }
    // Por terminar solo muestra lo que falta por recibir: caso abierto y contrato que no figure ya como recibido.
    var receipts = linkToContract((snapshot.receipts || []).filter(function (item) {
      return item.abierto && contractOf(item).estado !== "recibido";
    }), "fin");
    var increases = linkToContract(snapshot.increases || [], "fecha");
    var sortedOptions = {};
    Object.keys(options).forEach(function (key) {
      sortedOptions[key] = Object.keys(options[key])
        .map(function (value) { return { value: value, label: options[key][value] }; })
        .sort(function (a, b) { return a.label.localeCompare(b.label, "es"); });
    });
    return {
      rows: rows,
      generatedAt: Number(snapshot.generated_at || 0),
      simiEnabled: !!snapshot.simi_enabled,
      correctable: Array.isArray(snapshot.correctable) ? snapshot.correctable : [],
      years: Object.keys(years).map(Number).sort(function (a, b) { return b - a; }),
      receipts: receipts.items,
      receiptYears: receipts.years,
      increases: increases.items,
      increaseYears: increases.years,
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
    var kpiSlot = module.querySelector("[data-scm-cm-kpis]");
    var titleEl = module.querySelector("[data-scm-cm-title]");
    var subtitleEl = module.querySelector("[data-scm-cm-subtitle]");
    var updatedEl = module.querySelector("[data-scm-cm-updated]");
    var exportBtn = module.querySelector("[data-scm-cm-export]");
    var clearBtn = module.querySelector("[data-scm-cm-clear]");
    var selects = {};
    module.querySelectorAll("[data-scm-cm-filter]").forEach(function (select) {
      selects[select.getAttribute("data-scm-cm-filter")] = select;
    });

    var initialView = VIEWS[module.getAttribute("data-initial-view")] ? module.getAttribute("data-initial-view") : "ocupaciones";
    var state = {
      view: initialView,
      filters: emptyFilters(),
      month: MONTH_VIEWS[initialView] ? new Date().getMonth() + 1 : 0,
      simi: "",
      sub: "",
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

    // Único punto de consulta al servidor: sin argumentos carga los contratos; con fields guarda.
    function request(actionName, fields) {
      var body = new FormData();
      body.append("action", actionName || action);
      body.append("nonce", runtime.nonce || "");
      Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
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
      if (state.filters.year && viewYears().indexOf(Number(state.filters.year)) === -1 && viewYears().length) {
        state.filters.year = String(viewYears()[0]);
      }
      fillSelects();
      setStatus("");
      exportBtn.disabled = false;
      render();
    }

    // Cada vista ofrece los años que tienen datos suyos.
    function viewYears() {
      if (state.view === "por_terminar") return state.data.receiptYears;
      if (state.view === "aumentos") return state.data.increaseYears;
      return state.data.years;
    }

    function fillYears() {
      selects.year.innerHTML = '<option value="">Todos los años</option>' + viewYears().map(function (year) {
        return '<option value="' + year + '">' + year + "</option>";
      }).join("");
    }

    function fillSelects() {
      var data = state.data;
      fillYears();
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
        // No se reescribe un campo de texto igual para no mover el cursor mientras se escribe.
        if (key !== "month" && selects[key].value !== (state.filters[key] || "")) selects[key].value = state.filters[key] || "";
      });
      // El mes solo tiene sentido dentro de un año.
      if (!selectedYear()) state.month = 0;
      selects.month.value = state.month ? String(state.month) : "";
      selects.month.disabled = !selectedYear();
      var dirty = !!(state.filters.estado || state.filters.funcionario || state.filters.tipo || state.filters.destinacion || state.month ||
        TEXT_FILTERS.some(function (key) { return state.filters[key]; }) ||
        state.filters.year !== String(new Date().getFullYear()));
      clearBtn.disabled = !dirty;
    }

    function baseRows(list) {
      var f = state.filters;
      var tenant = fold(f.arrendatario);
      var property = fold(f.inmueble);
      var contract = fold(f.contrato);
      // El estado actual solo filtra Ocupaciones: separa las que siguen arrendadas de las que ya salieron.
      var estado = state.view === "ocupaciones" ? f.estado : "";
      // El filtro de auditoría SIMI vive en el detalle de ocupaciones/desocupaciones y también mueve los KPI.
      var simi = state.view === "comparativo" ? "" : state.simi;
      return (list || state.data.rows).filter(function (row) {
        return (!estado || row.estado === estado) &&
          (!simi || (simi === "pendiente" ? !row.estado_simi : row.estado_simi === simi)) &&
          (!f.funcionario || row.funcionario === f.funcionario) &&
          (!f.tipo || row.tipo === f.tipo) &&
          (!f.destinacion || row.destinacion === f.destinacion) &&
          (!tenant || fold(row.arrendatario).indexOf(tenant) !== -1) &&
          (!property || fold(row.inmueble).indexOf(property) !== -1 || fold(row.direccion).indexOf(property) !== -1) &&
          (!contract || fold(row.contrato).indexOf(contract) !== -1);
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
      module.querySelectorAll("[data-scm-cm-only]").forEach(function (field) {
        field.hidden = field.getAttribute("data-scm-cm-only") !== state.view;
      });
      syncSelects();
      var html = renderChips();
      html += state.view === "comparativo" ? renderComparison()
        : state.view === "por_terminar" ? renderEnding()
        : state.view === "aumentos" ? renderIncreases()
        : renderMovements(state.view === "ocupaciones" ? "ocup" : "desoc");
      content.innerHTML = html;
      kpiSlot.innerHTML = state.kpiHtml || "";
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
        if (!value || !String(value).trim()) return;
        if (key === "estado" && state.view !== "ocupaciones") return;
        var label = key === "funcionario"
          ? (state.data.options.funcionario.find(function (o) { return o.value === value; }) || { label: value }).label
          : key === "estado" ? STATE_LABELS[value] || value : String(value).trim();
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
      // Los KPI van encima de los filtros; el resto del contenido, debajo.
      state.kpiHtml = html;
      html = "";

      if (!isOcup) html += renderUpcoming(rows);

      state.detailEvents = shown.slice().sort(function (a, b) { return a.date < b.date ? 1 : a.date > b.date ? -1 : 0; });
      state.detailKind = kind;
      html += card("Detalle de " + noun + " · " + periodLabel, '<div data-scm-cm-detail></div>', "is-wide",
        '<div class="scm-cm-detail-tools">' +
        '<select class="scm-cm-simi-filter" data-scm-cm-simi-filter aria-label="Filtrar por auditoría SIMI">' + Object.keys(SIMI_FILTERS).map(function (key) {
          return '<option value="' + key + '"' + (state.simi === key ? " selected" : "") + ">" + esc(SIMI_FILTERS[key]) + "</option>";
        }).join("") + "</select>" +
        '<button type="button" class="scm-cm-btn" data-scm-cm-simi-report title="Excel con contrato, inmueble, estado SIMI y observaciones del listado">' +
        '<span class="material-symbols-outlined" aria-hidden="true">table_view</span><span>Informe SIMI</span></button></div>');
      if (!state.data.simiEnabled) {
        html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>' +
          "Para auditar contra SIMI crea en JetEngine los campos estado_simi (Correcto/Incorrecto) y observaciones_estado_simi del CCT Contratos de arrendamiento y pulsa Actualizar.</p>";
      }

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
      // Acordeón cerrado por defecto; recuerda si se abrió mientras se cambian filtros.
      return '<details class="scm-cm-card is-wide scm-cm-accordion" data-scm-cm-upcoming' + (state.upcomingOpen ? " open" : "") + ">" +
        '<summary><h2>Por desocupar · ' + esc(num(upcoming.length)) + " en estado Por recibir</h2>" +
        '<span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>' + body + "</details>";
    }

    function sumOf(list, key) {
      return list.reduce(function (sum, row) { return sum + (row[key] || 0); }, 0);
    }

    function periodOf(list) {
      var year = selectedYear();
      var period = year ? list.filter(function (row) { return row.periodY === year; }) : list;
      return {
        year: year,
        shown: year && state.month ? period.filter(function (row) { return row.periodM === state.month; }) : period,
        label: year ? (state.month ? MONTHS_LONG[state.month - 1] + " " + year : String(year)) : "todos los años",
      };
    }

    function subFilterHtml() {
      var options = SUB_FILTERS[state.view];
      return '<div class="scm-cm-detail-tools"><select class="scm-cm-simi-filter" data-scm-cm-sub-filter aria-label="Filtrar el detalle">' + Object.keys(options).map(function (key) {
        return '<option value="' + key + '"' + (state.sub === key ? " selected" : "") + ">" + esc(options[key]) + "</option>";
      }).join("") + "</select></div>";
    }

    function renderEnding() {
      var scope = periodOf(baseRows(state.data.receipts));
      var shown = scope.shown;
      var today = todayIso();
      var canon = sumOf(shown, "canon");
      var countOf = function (destination) {
        return shown.filter(function (row) { return row.destinacion === destination; }).length;
      };
      var kpis = '<div class="scm-cm-kpis scm-cm-kpis-6">';
      kpis += kpi("Contratos por terminar", num(shown.length), esc(scope.year ? scope.label : "Histórico completo"), "desoc");
      kpis += kpi("Fecha vencida sin recibir", num(shown.filter(function (row) { return row.fin < today; }).length), "La fecha ya pasó y sigue abierto", "stock");
      kpis += kpi("Canon que termina", compactMoney(canon), esc(money(canon) + " mensual"));
      kpis += kpi("Administración que termina", compactMoney(sumOf(shown, "admin")), esc(money(sumOf(shown, "admin")) + " mensual"));
      kpis += kpi("Vivienda", num(countOf("Vivienda")), "");
      kpis += kpi("Comercio", num(countOf("Comercio")), "");
      state.kpiHtml = kpis + "</div>";

      state.detailKind = "ending";
      state.detailEvents = shown.slice()
        .sort(function (a, b) { return a.fin < b.fin ? -1 : a.fin > b.fin ? 1 : 0; })
        .map(function (row) { return { row: row, date: row.fin }; });
      return card("Detalle · " + scope.label, "<div data-scm-cm-detail></div>", "is-wide");
    }

    function endingTableHtml(slice, events) {
      var rows = events.map(function (event) { return event.row; });
      var today = todayIso();
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Fecha terminación</th><th>Caso</th><th>Contrato</th><th>Inmueble</th><th>Dirección</th><th>Arrendatario</th><th>Tipo</th><th>Destinación</th><th>Funcionario</th>' +
        '<th class="is-num">Canon</th><th class="is-num">Administración</th><th class="is-num">Total</th></tr></thead><tbody>' +
        slice.map(function (event) {
          var row = event.row;
          return "<tr><td>" + esc(formatDate(row.fin)) + (row.fin < today ? '<small class="scm-cm-source is-bad">Fecha vencida</small>' : "") + "</td><td>" + esc(row.caso) + "</td><td>" + esc(row.contrato) + "</td><td>" + esc(row.inmueble) +
            '</td><td class="is-address">' + esc(row.direccion) + '</td><td class="is-name">' + esc(row.arrendatario) + "</td><td>" + esc(row.tipo || "—") + "</td><td>" + esc(row.destinacion || "—") + "</td><td>" + esc(row.funcionarioNombre || "—") +
            '</td><td class="is-num">' + esc(money(row.canon)) + '</td><td class="is-num">' + (row.admin ? esc(money(row.admin)) : '<span class="scm-cm-muted">No aplica</span>') + '</td><td class="is-num is-total">' + esc(money((row.canon || 0) + (row.admin || 0))) + "</td></tr>";
        }).join("") +
        '</tbody><tfoot><tr><td colspan="9">Total · ' + esc(num(rows.length) + (rows.length === 1 ? " contrato" : " contratos")) + '</td><td class="is-num">' + esc(money(sumOf(rows, "canon"))) + '</td><td class="is-num">' + esc(money(sumOf(rows, "admin"))) + '</td><td class="is-num is-total">' + esc(money(sumOf(rows, "canon") + sumOf(rows, "admin"))) + "</td></tr></tfoot></table></div>";
    }

    function renderIncreases() {
      var scope = periodOf(baseRows(state.data.increases));
      var shown = scope.shown;
      var ofType = function (list, type) { return list.filter(function (row) { return row.tipo_aumento === type; }); };
      var canon = ofType(shown, "canon");
      var admin = ofType(shown, "admin");
      var contracts = {};
      shown.forEach(function (row) { contracts[row.id_contrato || row.contrato] = true; });
      var withPercent = shown.filter(function (row) { return row.porcentaje; });
      var kpis = '<div class="scm-cm-kpis scm-cm-kpis-6">';
      kpis += kpi("Contratos con aumento", num(Object.keys(contracts).length), esc(scope.year ? scope.label : "Histórico completo"), "ocup");
      kpis += kpi("Aumentos de canon", num(canon.length), esc("Nuevo total " + compactMoney(sumOf(canon, "nuevo"))));
      kpis += kpi("Aumentos de administración", num(admin.length), esc("Nuevo total " + compactMoney(sumOf(admin, "nuevo"))));
      kpis += kpi("Canon adicional al mes", money(sumOf(canon, "diferencia")), "Diferencia frente al valor anterior", "good");
      kpis += kpi("Administración adicional al mes", money(sumOf(admin, "diferencia")), "Diferencia frente al valor anterior", "good");
      kpis += kpi("Incremento promedio", withPercent.length ? decimalFormat.format(sumOf(withPercent, "porcentaje") / withPercent.length) + "%" : "—", esc(num(withPercent.length) + " con porcentaje"), "stock");
      state.kpiHtml = kpis + "</div>";

      state.detailKind = "increase";
      state.detailEvents = shown.filter(function (row) { return !state.sub || row.tipo_aumento === state.sub; })
        .sort(function (a, b) { return a.fecha < b.fecha ? 1 : a.fecha > b.fecha ? -1 : 0; })
        .map(function (row) { return { row: row, date: row.fecha }; });
      return card("Detalle · " + scope.label, "<div data-scm-cm-detail></div>", "is-wide", subFilterHtml());
    }

    function increaseTableHtml(slice, events) {
      var rows = events.map(function (event) { return event.row; });
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr><th>Fecha carta</th><th>Rige desde</th><th>Tipo</th><th>Contrato</th><th>Inmueble</th><th>Dirección</th><th>Arrendatario</th><th>Destinación</th><th>Funcionario</th>' +
        '<th class="is-num">Valor anterior</th><th class="is-num">Valor nuevo</th><th class="is-num">Diferencia</th><th class="is-num">%</th><th>Retroactivo</th></tr></thead><tbody>' +
        slice.map(function (event) {
          var row = event.row;
          return "<tr><td>" + esc(formatDate(row.fecha)) + "</td><td>" + esc(formatDate(row.vigencia || row.fecha)) + '</td><td><span class="scm-cm-badge ' + (row.tipo_aumento === "canon" ? "is-entregado" : "is-por_recibir") + '">' + esc(INCREASE_LABELS[row.tipo_aumento]) + "</span></td><td>" + esc(row.contrato) + "</td><td>" + esc(row.inmueble) +
            '</td><td class="is-address">' + esc(row.direccion) + '</td><td class="is-name">' + esc(row.arrendatario) + "</td><td>" + esc(row.destinacion || "—") + "</td><td>" + esc(row.funcionarioNombre || "—") +
            '</td><td class="is-num">' + (row.anterior ? esc(money(row.anterior)) + (row.calculado ? '<small class="scm-cm-source">calculado</small>' : "") : '<span class="scm-cm-muted">Sin dato</span>') + '</td><td class="is-num is-total">' + esc(money(row.nuevo)) +
            '</td><td class="is-num">' + (row.diferencia == null ? '<span class="scm-cm-muted">—</span>' : esc(money(row.diferencia))) + '</td><td class="is-num">' + (row.porcentaje ? esc(decimalFormat.format(row.porcentaje)) + "%" : '<span class="scm-cm-muted">—</span>') +
            '</td><td class="is-simi-obs"' + (row.retroactivo ? ' title="' + esc(row.retroactivo) + '"' : "") + ">" + (row.retroactivo ? esc(row.retroactivo) : '<span class="scm-cm-muted">—</span>') + "</td></tr>";
        }).join("") +
        '</tbody><tfoot><tr><td colspan="9">Total · ' + esc(num(rows.length) + (rows.length === 1 ? " aumento" : " aumentos")) + '</td><td class="is-num">' + esc(money(sumOf(rows, "anterior"))) + '</td><td class="is-num is-total">' + esc(money(sumOf(rows, "nuevo"))) + '</td><td class="is-num">' + esc(money(sumOf(rows, "diferencia"))) + "</td><td></td><td></td></tr></tfoot></table></div>";
    }

    function pagerHtml(total, pages) {
      var html = '<div class="scm-cm-pager"><span>' + esc(num(total) + (total === 1 ? " contrato" : " contratos")) + "</span>";
      if (pages > 1) {
        html += '<div><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cm-page="' + (state.page - 1) + '"' + (state.page === 1 ? " disabled" : "") +
          '>Anterior</button><span>Página ' + state.page + " de " + pages + '</span><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-cm-page="' +
          (state.page + 1) + '"' + (state.page === pages ? " disabled" : "") + ">Siguiente</button></div>";
      }
      return html + "</div>";
    }

    // Lista del detalle (ya con todos los filtros, incluido SIMI); el informe usa la misma.
    function visibleEvents() {
      return state.detailEvents;
    }

    function simiCell(row) {
      var badge = row.estado_simi
        ? '<span class="scm-cm-badge is-simi-' + (row.estado_simi === "Correcto" ? "ok" : "bad") + '">' + esc(row.estado_simi) + "</span>"
        : '<span class="scm-cm-badge is-muted">Sin auditar</span>';
      if (!state.data.simiEnabled) return badge;
      return '<button type="button" class="scm-cm-simi-btn" data-scm-cm-simi="' + row.id + '" title="Auditar contrato ' + esc(row.contrato) + ' en SIMI">' + badge +
        '<span class="material-symbols-outlined" aria-hidden="true">edit</span></button>';
    }

    function renderDetail() {
      var holder = content.querySelector("[data-scm-cm-detail]");
      if (!holder) return;
      var events = visibleEvents();
      var pages = Math.max(1, Math.ceil(events.length / PAGE_SIZE));
      state.page = Math.min(Math.max(1, state.page), pages);
      var slice = events.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);
      if (!slice.length) {
        holder.innerHTML = '<div class="scm-cm-empty">' + (state.simi ? "Ningún contrato coincide con el filtro de auditoría SIMI." : state.detailKind === "ending" ? "Ningún contrato termina en este periodo con estos filtros." : state.detailKind === "increase" ? "No hay aumentos en este periodo con estos filtros." : "No hay movimientos en este periodo.") + "</div>";
        return;
      }
      if (state.detailKind === "ending" || state.detailKind === "increase") {
        holder.innerHTML = (state.detailKind === "ending" ? endingTableHtml : increaseTableHtml)(slice, events) + pagerHtml(events.length, pages);
        return;
      }
      var isDesoc = state.detailKind === "desoc";
      var html = '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-cm-detail"><thead><tr>' +
        (isDesoc ? "<th>Fecha recibo</th><th>Fin contrato</th>" : "<th>Fecha entrega</th>") +
        '<th>Contrato</th><th>Inmueble</th><th>Dirección</th><th>Arrendatario</th><th>Tipo</th><th>Destinación</th><th>Funcionario</th>' +
        '<th class="is-num">Duración</th>' + (isDesoc ? '<th class="is-num">Permanencia</th>' : '<th class="is-num">Días de gracia</th>') +
        '<th class="is-num" title="Derechos inmobiliarios">% Derechos</th><th class="is-num" title="Tasa de administración">% Admin.</th>' +
        '<th class="is-num">Canon</th><th class="is-num">Administración</th><th class="is-num">Total</th>' + (isDesoc ? "" : "<th>Estado actual</th>") + "<th>Estado SIMI</th><th>Observaciones SIMI</th></tr></thead><tbody>";
      html += slice.map(function (event) {
        var row = event.row;
        // Si falta la fecha propia del evento, se contó con la fecha de respaldo (inicio o fin del contrato).
        var first = isDesoc ? row.recibo : row.entrega;
        var firstCell = first ? esc(formatDate(first))
          : esc(formatDate(event.date)) + '<small class="scm-cm-source">' + (isDesoc ? "fin del contrato" : "inicio del contrato") + "</small>";
        var dates = "<td>" + firstCell + "</td>" + (isDesoc ? "<td>" + (row.fin ? esc(formatDate(row.fin)) : '<span class="scm-cm-muted">—</span>') + "</td>" : "");
        return "<tr>" + dates + "<td>" + esc(row.contrato) +
          "</td><td>" + esc(row.inmueble) + '</td><td class="is-address">' + esc(row.direccion) + '</td><td class="is-name">' + esc(row.arrendatario) + "</td><td>" +
          esc(row.tipo || "—") + "</td><td>" + esc(row.destinacion || "—") + "</td><td>" + esc(row.funcionarioNombre || "—") + '</td><td class="is-num">' + (row.meses ? esc(row.meses + " meses") : "—") + "</td>" +
          (isDesoc
            ? '<td class="is-num">' + (row.permanencia == null ? "—" : esc(num(row.permanencia) + " meses")) + "</td>"
            : '<td class="is-num">' + graceCell(row) + "</td>") +
          '<td class="is-num">' + (row.derechos == null ? '<span class="scm-cm-muted">—</span>' : esc(percent(row.derechos))) + "</td>" +
          '<td class="is-num">' + (row.tasa_admin == null ? '<span class="scm-cm-muted">—</span>' : esc(percent(row.tasa_admin))) + "</td>" +
          '<td class="is-num">' + esc(money(row.canon)) + '</td><td class="is-num">' + (row.admin ? esc(money(row.admin)) : '<span class="scm-cm-muted">No aplica</span>') + "</td>" +
          '<td class="is-num is-total">' + esc(money((row.canon || 0) + (row.admin || 0))) + "</td>" +
          (isDesoc ? "" : '<td><span class="scm-cm-badge is-' + row.estado + '">' + esc(STATE_LABELS[row.estado] || row.estado) + "</span></td>") +
          "<td>" + simiCell(row) + '</td><td class="is-simi-obs"' + (row.obs_simi ? ' title="' + esc(row.obs_simi) + '"' : "") + ">" +
          (row.obs_simi ? esc(row.obs_simi) : '<span class="scm-cm-muted">—</span>') + "</td></tr>";
      }).join("");
      // La fila de totales suma todos los contratos filtrados, no solo los de la página.
      var sumCanon = 0;
      var sumAdmin = 0;
      events.forEach(function (event) {
        sumCanon += event.row.canon || 0;
        sumAdmin += event.row.admin || 0;
      });
      html += '</tbody><tfoot><tr><td colspan="' + (isDesoc ? 13 : 12) + '">Total · ' + esc(num(events.length) + (events.length === 1 ? " contrato" : " contratos")) +
        '</td><td class="is-num">' + esc(money(sumCanon)) + '</td><td class="is-num">' + esc(money(sumAdmin)) + '</td><td class="is-num is-total">' + esc(money(sumCanon + sumAdmin)) +
        "</td>" + (isDesoc ? "" : "<td></td>") + "<td></td><td></td></tr></tfoot></table></div>";
      holder.innerHTML = html + pagerHtml(events.length, pages);
    }

    function graceCell(row) {
      if (row.dias_gracia == null) return '<span class="scm-cm-muted">Sin dato</span>';
      var source = row.dias_gracia_fuente && row.dias_gracia_fuente !== "contrato"
        ? '<small class="scm-cm-source">' + (row.dias_gracia_fuente === "cierre" ? "hoja de cierre" : "mandato") + "</small>"
        : "";
      return esc(row.dias_gracia === 1 ? "1 día" : num(row.dias_gracia) + " días") + source;
    }

    function openSimiAudit(rowId) {
      var row = state.data.rows.find(function (item) { return item.id === rowId; });
      var auditAction = (runtime.actions || {}).contract_simi_audit || "";
      if (!row || !window.Swal || !auditAction) return;
      var option = function (value, tone) {
        return '<label class="scm-cm-simi-option is-' + tone + '"><input type="radio" name="scm_cm_simi" value="' + value + '"' +
          (row.estado_simi === value ? " checked" : "") + "><span>" + value + "</span></label>";
      };
      // Condiciones que se pueden corregir en la misma auditoría; solo se envían las modificadas.
      var fields = [
        { key: "canon", label: "Canon", value: row.canon, prefix: "$" },
        { key: "admin", label: "Administración", value: row.admin, prefix: "$" },
        { key: "meses", label: "Duración (meses)", value: row.meses },
        { key: "derechos", label: "% derechos", value: row.derechos, suffix: "%" },
        { key: "tasa_admin", label: "% administración", value: row.tasa_admin, suffix: "%" },
        { key: "dias_gracia", label: "Días de gracia", value: row.dias_gracia_fuente === "contrato" ? row.dias_gracia : null,
          hint: row.dias_gracia != null && row.dias_gracia_fuente !== "contrato" ? "Sin dato en el contrato; " + (row.dias_gracia_fuente === "cierre" ? "hoja de cierre" : "mandato") + ": " + row.dias_gracia : "" },
      ].filter(function (field) { return state.data.correctable.indexOf(field.key) !== -1; });
      var initial = function (field) {
        if (field.value == null) return "";
        return field.prefix ? numberFormat.format(Math.round(field.value)) : String(field.value).replace(".", ",");
      };
      var fieldsHtml = fields.length
        ? '<fieldset class="scm-cm-simi-fields"><legend>Condiciones del contrato</legend><div>' + fields.map(function (field) {
          return '<label><span>' + esc(field.label) + "</span><span class=\"scm-cm-simi-input\">" + (field.prefix ? "<b>" + field.prefix + "</b>" : "") +
            '<input type="text" inputmode="' + (field.prefix ? "numeric" : "decimal") + '" data-scm-cm-fix="' + field.key + '"' + (field.prefix ? " data-scm-cm-money" : "") + ' data-initial="' + esc(initial(field)) + '" value="' + esc(initial(field)) + '">' +
            (field.suffix ? "<b>" + field.suffix + "</b>" : "") + "</span>" + (field.hint ? "<small>" + esc(field.hint) + "</small>" : "") + "</label>";
        }).join("") + "</div><small>Corrige solo lo que no coincide con SIMI o con el contrato firmado. El cambio queda en el historial con el valor anterior.</small></fieldset>"
        : "";
      window.Swal.fire({
        title: "Auditoría SIMI · contrato " + row.contrato,
        html: '<div class="scm-cm-simi-form"><p>Inmueble <strong>' + esc(row.inmueble || "—") + "</strong> · " + esc(row.direccion || "") + "<br>" + esc(row.arrendatario || "") + "</p>" +
          '<div class="scm-cm-simi-options" role="radiogroup" aria-label="Estado en SIMI">' + option("Correcto", "ok") + option("Incorrecto", "bad") + "</div>" +
          fieldsHtml +
          '<label class="scm-cm-simi-obs"><span>Observaciones</span><textarea rows="4" maxlength="2000" data-scm-cm-simi-obs placeholder="Qué se revisó o qué se debe corregir en SIMI">' + esc(row.obs_simi) + "</textarea></label>" +
          "<small>Al guardar queda registrado en el historial del inmueble y del contrato.</small></div>",
        width: "min(680px, 96vw)",
        showCloseButton: true,
        showCancelButton: true,
        confirmButtonText: "Guardar auditoría",
        cancelButtonText: "Cancelar",
        focusConfirm: false,
        customClass: { popup: "scm-cm-simi-swal" },
        didOpen: function (popup) {
          // Canon y administración se muestran con separador de miles mientras se escribe.
          popup.addEventListener("input", function (event) {
            var input = event.target;
            if (!input.matches || !input.matches("[data-scm-cm-money]")) return;
            var digitsBefore = input.value.slice(0, input.selectionStart || 0).replace(/\D/g, "").length;
            var digits = input.value.replace(/\D/g, "").replace(/^0+(?=\d)/, "");
            input.value = digits ? numberFormat.format(Number(digits)) : "";
            var caret = 0;
            for (var seen = 0; caret < input.value.length && seen < digitsBefore; caret++) {
              if (/\d/.test(input.value[caret])) seen++;
            }
            input.setSelectionRange(caret, caret);
          });
        },
        preConfirm: function () {
          var popup = window.Swal.getPopup();
          var checked = popup.querySelector('input[name="scm_cm_simi"]:checked');
          if (!checked) {
            window.Swal.showValidationMessage("Selecciona Correcto o Incorrecto.");
            return false;
          }
          var payload = {
            contract_id: String(row.id),
            estado_simi: checked.value,
            observaciones_estado_simi: popup.querySelector("[data-scm-cm-simi-obs]").value,
          };
          popup.querySelectorAll("[data-scm-cm-fix]").forEach(function (input) {
            if (input.value.trim() !== input.getAttribute("data-initial")) {
              payload["corrections[" + input.getAttribute("data-scm-cm-fix") + "]"] = input.value.trim();
            }
          });
          return request(auditAction, payload)
            .catch(function (error) {
              window.Swal.showValidationMessage(error.message || "No se pudo guardar la auditoría.");
              return false;
            });
        },
      }).then(function (result) {
        if (!result.isConfirmed || !result.value) return;
        row.estado_simi = result.value.estado_simi;
        row.obs_simi = result.value.observaciones_estado_simi;
        var values = result.value.values || {};
        Object.keys(values).forEach(function (key) {
          row[key] = values[key];
          if (key === "dias_gracia") row.dias_gracia_fuente = "contrato";
        });
        // La caché guardada ya no refleja el cambio; la próxima carga vuelve a consultar.
        clearCache();
        render();
        window.Swal.fire({ icon: "success", title: result.value.message, timer: 2200, showConfirmButton: false });
      });
    }

    // Excel (.xls en HTML, como el resto de exportaciones del panel) con filas verdes o rojas según SIMI.
    function exportSimiReport() {
      var events = visibleEvents();
      if (!events.length) return;
      var styles = { Correcto: "background:#C6EFCE;mso-pattern:auto none;color:#006100;", Incorrecto: "background:#FFC7CE;mso-pattern:auto none;color:#9C0006;" };
      var cell = function (value, style) {
        return '<td style="mso-number-format:\'\\@\';' + (style || "") + '">' + esc(value) + "</td>";
      };
      var rows = events.map(function (event) {
        var row = event.row;
        var style = styles[row.estado_simi] || "";
        return "<tr>" + cell(row.contrato, style) + cell(row.inmueble, style) +
          cell(row.estado_simi || "Sin auditar", style + (style ? "font-weight:bold;" : "")) + cell(row.obs_simi, style) + "</tr>";
      }).join("");
      var html = '<html><head><meta charset="UTF-8"><style>td,th{border:1px solid #b7b7b7;padding:6px;vertical-align:top}th{background:#0f1e36;color:#fff}</style></head><body>' +
        "<table><thead><tr><th>Contrato</th><th>Inmueble</th><th>Estado SIMI</th><th>Observaciones SIMI</th></tr></thead><tbody>" + rows + "</tbody></table></body></html>";
      var year = selectedYear() || "historico";
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob(["\ufeff" + html], { type: "application/vnd.ms-excel;charset=utf-8" }));
      link.download = "auditoria-simi-" + state.view + "-" + year + (state.month ? "-" + String(state.month).padStart(2, "0") : "") + ".xls";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () {
        URL.revokeObjectURL(link.href);
        link.remove();
      }, 0);
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
      state.kpiHtml = html;
      html = "";

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

    function exportExcel() {
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
      } else if (state.view === "por_terminar") {
        lines = [["Fecha terminacion", "Caso", "Contrato", "Inmueble", "Direccion", "Arrendatario", "Tipo inmueble", "Destinacion", "Funcionario", "Canon", "Administracion", "Total"]];
        state.detailEvents.forEach(function (event) {
          var row = event.row;
          lines.push([row.fin, row.caso, row.contrato, row.inmueble, row.direccion, row.arrendatario, row.tipo, row.destinacion, row.funcionarioNombre, row.canon, row.admin || 0, (row.canon || 0) + (row.admin || 0)]);
        });
        name = "contratos-por-terminar-" + year + (state.month ? "-" + String(state.month).padStart(2, "0") : "");
      } else if (state.view === "aumentos") {
        lines = [["Fecha carta", "Rige desde", "Tipo", "Contrato", "Inmueble", "Direccion", "Arrendatario", "Destinacion", "Funcionario", "Valor anterior", "Valor anterior calculado", "Valor nuevo", "Diferencia", "Porcentaje", "Retroactivo"]];
        state.detailEvents.forEach(function (event) {
          var row = event.row;
          lines.push([row.fecha, row.vigencia || row.fecha, INCREASE_LABELS[row.tipo_aumento], row.contrato, row.inmueble, row.direccion, row.arrendatario, row.destinacion, row.funcionarioNombre, row.anterior || "", row.calculado ? "Si" : "No", row.nuevo, row.diferencia == null ? "" : row.diferencia, row.porcentaje == null ? "" : row.porcentaje, row.retroactivo]);
        });
        name = "aumentos-" + year + (state.month ? "-" + String(state.month).padStart(2, "0") : "");
      } else {
        var isDesoc = state.view === "desocupaciones";
        lines = [["Fecha del movimiento", "Fecha entrega", "Fecha recibo", "Fin contrato", "Contrato", "Inmueble", "Direccion", "Arrendatario",
          "Tipo inmueble", "Destinacion", "Funcionario", "Estado", "Duracion meses", "Dias de gracia", "Fuente dias de gracia", "Canon", "Administracion", "Total", "Permanencia meses", "% Derechos", "% Administracion", "Estado SIMI", "Observaciones SIMI"]];
        state.detailEvents.forEach(function (event) {
          var row = event.row;
          lines.push([event.date, row.entrega || "", row.recibo || "", row.fin || "", row.contrato, row.inmueble, row.direccion, row.arrendatario,
            row.tipo, row.destinacion, row.funcionarioNombre, STATE_LABELS[row.estado] || row.estado, row.meses || "",
            row.dias_gracia == null ? "" : row.dias_gracia, row.dias_gracia == null ? "" : row.dias_gracia_fuente, row.canon, row.admin || 0,
            (row.canon || 0) + (row.admin || 0), row.permanencia == null ? "" : row.permanencia, row.derechos == null ? "" : row.derechos, row.tasa_admin == null ? "" : row.tasa_admin, row.estado_simi, row.obs_simi]);
        });
        name = state.view + "-" + year + (state.month ? "-" + String(state.month).padStart(2, "0") : "");
      }
      // Excel (.xls en HTML, como la auditoría SIMI): números como valores y el resto como texto para conservar ceros iniciales.
      var cell = function (value) {
        if (typeof value === "number" && isFinite(value)) return "<td>" + value + "</td>";
        return '<td style="mso-number-format:\'\\@\';">' + esc(value == null ? "" : value) + "</td>";
      };
      var html = '<html><head><meta charset="UTF-8"><style>td,th{border:1px solid #b7b7b7;padding:6px;vertical-align:top}th{background:#0f1e36;color:#fff}</style></head><body><table><thead><tr>' +
        lines[0].map(function (title) { return "<th>" + esc(title) + "</th>"; }).join("") + "</tr></thead><tbody>" +
        lines.slice(1).map(function (line) { return "<tr>" + line.map(cell).join("") + "</tr>"; }).join("") + "</tbody></table></body></html>";
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob(["\ufeff" + html], { type: "application/vnd.ms-excel;charset=utf-8" }));
      link.download = name + ".xls";
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
      state.simi = "";
      state.sub = "";
    }

    function enterView(view) {
      var leavingMonthView = MONTH_VIEWS[state.view];
      state.view = view;
      if (MONTH_VIEWS[view]) {
        state.filters.year = String(new Date().getFullYear());
        state.month = new Date().getMonth() + 1;
      } else if (leavingMonthView) {
        state.month = 0;
      }
      if (state.data) fillYears();
    }

    module.addEventListener("click", function (event) {
      var viewButton = event.target.closest("[data-scm-subview-target]");
      if (viewButton) {
        var view = viewButton.getAttribute("data-scm-subview-target");
        if (VIEWS[view] && view !== state.view) {
          enterView(view);
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
        exportExcel();
        return;
      }
      var simiButton = event.target.closest("[data-scm-cm-simi]");
      if (simiButton) {
        openSimiAudit(Number(simiButton.getAttribute("data-scm-cm-simi")));
        return;
      }
      if (event.target.closest("[data-scm-cm-simi-report]")) {
        exportSimiReport();
        return;
      }
      if (event.target.closest("[data-scm-cm-clear]")) {
        state.filters = emptyFilters();
        if (viewYears().indexOf(Number(state.filters.year)) === -1) state.filters.year = String(viewYears()[0] || "");
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
      if (event.target.matches("[data-scm-cm-simi-filter]")) {
        state.simi = event.target.value;
        state.page = 1;
        render();
        return;
      }
      if (event.target.matches("[data-scm-cm-sub-filter]")) {
        state.sub = event.target.value;
        state.page = 1;
        render();
        return;
      }
      var select = event.target.closest("[data-scm-cm-filter]");
      if (!select || !state.data || select.hasAttribute("data-scm-cm-text")) return;
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

    var textTimer = 0;
    module.addEventListener("input", function (event) {
      var textFilter = event.target.closest("[data-scm-cm-text]");
      if (textFilter) {
        var key = textFilter.getAttribute("data-scm-cm-filter");
        var text = textFilter.value;
        window.clearTimeout(textTimer);
        textTimer = window.setTimeout(function () {
          if (!state.data) return;
          state.filters[key] = text;
          state.page = 1;
          render();
        }, 250);
        return;
      }
    });

    module.addEventListener("toggle", function (event) {
      if (event.target.matches && event.target.matches("[data-scm-cm-upcoming]")) state.upcomingOpen = event.target.open;
    }, true);

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
