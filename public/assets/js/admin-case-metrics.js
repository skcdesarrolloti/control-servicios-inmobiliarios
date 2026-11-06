(function () {
  "use strict";

  // Métricas › Casos: los casos llegan en una sola petición y los filtros (mes, rango de
  // fechas, tema, departamento, funcionario) y las tablas se calculan en el navegador.
  var CACHE_KEY = "scm-case-metrics-v2";
  var CACHE_TTL_MS = 5 * 60 * 1000;
  var MONTHS_LONG = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
  // Colores categóricos en orden fijo (paleta validada: CVD y visión normal pasan; el contraste
  // bajo de 2 tonos se compensa con leyenda, etiquetas, tooltips y la tabla por funcionario).
  var STATES = [
    { key: "Nuevo", label: "Nuevos", color: "#2a78d6" },
    { key: "En proceso", label: "En proceso", color: "#eb6834" },
    { key: "Postergado", label: "Postergados", color: "#1baf7a" },
    { key: "Cerrado", label: "Cerrados", color: "#eda100" },
  ];
  var SERIES_COLOR = "#2a78d6";
  var OLD_DAYS = 30;
  var AGE_BUCKETS = [
    { label: "Hasta 7 días", max: 7 },
    { label: "8 a 30 días", max: 30 },
    { label: "31 a 90 días", max: 90 },
    { label: "Más de 90 días", max: Infinity },
  ];
  var BRIEF_ICONS = { bad: "error", warn: "schedule", good: "check_circle" };
  var MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var numberFormat = new Intl.NumberFormat("es-CO");

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function count(value, one, many) { return num(value) + " " + (value === 1 ? one : many); }
  function pct(part, total) { return total ? Math.round((part / total) * 100) + "%" : "—"; }
  function ageDays(row) {
    return row.fecha ? Math.max(0, Math.floor((Date.now() - Date.parse(row.fecha + "T00:00:00")) / 86400000)) : null;
  }
  function formatDay(iso) {
    return Number(iso.slice(8, 10)) + " " + MONTHS_LONG[Number(iso.slice(5, 7)) - 1].slice(0, 3) + " " + iso.slice(0, 4);
  }
  function monthBounds(month) {
    var year = Number(month.slice(0, 4));
    var index = Number(month.slice(5, 7));
    var last = new Date(year, index, 0).getDate();
    return { from: month + "-01", to: month + "-" + String(last).padStart(2, "0") };
  }
  function monthLabel(month) {
    return MONTHS_LONG[Number(month.slice(5, 7)) - 1] + " " + month.slice(0, 4);
  }

  function readCache() {
    try {
      var cached = JSON.parse(window.sessionStorage.getItem(CACHE_KEY) || "null");
      return cached && Date.now() - cached.savedAt < CACHE_TTL_MS ? cached.snapshot : null;
    } catch (error) {
      return null;
    }
  }
  function writeCache(snapshot) {
    try { window.sessionStorage.setItem(CACHE_KEY, JSON.stringify({ savedAt: Date.now(), snapshot: snapshot })); } catch (error) { /* sin caché */ }
  }
  function clearCache() {
    try { window.sessionStorage.removeItem(CACHE_KEY); } catch (error) { /* sin caché */ }
  }

  function init(root) {
    var module = root.querySelector("[data-scm-case-metrics]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.cmInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.cmInit = "1";

    var panel = module.closest(".scm-tab-panel");
    var action = (runtime.actions || {}).case_metrics || "";
    var content = module.querySelector("[data-scm-case-content]");
    var status = module.querySelector("[data-scm-case-status]");
    var updatedEl = module.querySelector("[data-scm-case-updated]");
    var exportBtn = module.querySelector("[data-scm-case-export]");
    var compliance = module.querySelector("[data-scm-case-compliance]");
    var inputs = {};
    module.querySelectorAll("[data-scm-case-filter]").forEach(function (input) {
      inputs[input.getAttribute("data-scm-case-filter")] = input;
    });
    var currentMonth = module.getAttribute("data-current-month");
    var state = { data: null, pending: null, byEmployee: [], mode: "actual" };
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
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudieron cargar los casos.");
          return json.data || {};
        });
    }

    function load(force) {
      if (state.pending) return state.pending;
      if (state.data && !force) return Promise.resolve();
      var cached = force ? null : readCache();
      if (cached) {
        apply(cached);
        return Promise.resolve();
      }
      if (force) clearCache();
      setStatus(state.data ? "Actualizando casos…" : "Cargando casos…", "loading");
      module.classList.add("is-busy");
      state.pending = request()
        .then(function (snapshot) {
          writeCache(snapshot);
          apply(snapshot);
        })
        .catch(function (error) { setStatus(error.message, "error"); })
        .then(function () {
          state.pending = null;
          module.classList.remove("is-busy");
        });
      return state.pending;
    }

    function apply(snapshot) {
      var dict = snapshot.dictionaries || {};
      compliance.innerHTML = snapshot.compliance_html ? "<p>Foto de Reportes · no cambia con los filtros</p>" + snapshot.compliance_html : "";
      compliance.hidden = !snapshot.compliance_html;
      var names = snapshot.funcionarios || {};
      state.data = {
        generatedAt: snapshot.generated_at || 0,
        excluded: snapshot.excluidos || 0,
        names: names,
        rows: (snapshot.rows || []).map(function (values) {
          return {
            estado: dict.estado[values[0]] || "",
            adm: dict.estado_administrativo[values[1]] || "",
            tema: dict.tema[values[2]] || "",
            departamento: dict.departamento[values[3]] || "",
            funcionario: values[4] || "",
            fecha: values[5] || "",
            cerrado: values[6] || "",
          };
        }),
      };
      var years = {};
      years[currentMonth.slice(0, 4)] = true;
      state.data.rows.forEach(function (row) { if (row.fecha) years[row.fecha.slice(0, 4)] = true; });
      var currentYear = inputs.year.value;
      inputs.year.innerHTML = Object.keys(years).sort().reverse().map(function (year) {
        return '<option value="' + esc(year) + '">' + esc(year) + "</option>";
      }).join("");
      inputs.year.value = currentYear || currentMonth.slice(0, 4);
      fillSelect(inputs.tema, dict.tema, "Todos");
      fillSelect(inputs.departamento, dict.departamento, "Todos");
      var employees = {};
      state.data.rows.forEach(function (row) { if (row.funcionario) employees[row.funcionario] = true; });
      var current = inputs.funcionario.value;
      inputs.funcionario.innerHTML = '<option value="">Todos</option>' + Object.keys(employees)
        .map(function (id) { return { id: id, label: employeeLabel(id) }; })
        .sort(function (a, b) { return a.label.localeCompare(b.label, "es"); })
        .map(function (o) { return '<option value="' + esc(o.id) + '">' + esc(o.label) + "</option>"; }).join("");
      inputs.funcionario.value = current;
      exportBtn.disabled = false;
      setStatus("");
      if (panel) panel.setAttribute("data-scm-loaded", "1");
      render();
    }

    function fillSelect(select, values, allLabel) {
      var current = select.value;
      select.innerHTML = '<option value="">' + allLabel + "</option>" + (values || []).filter(Boolean).slice()
        .sort(function (a, b) { return a.localeCompare(b, "es"); })
        .map(function (value) { return '<option value="' + esc(value) + '">' + esc(value) + "</option>"; }).join("");
      select.value = current;
    }

    function employeeLabel(id) {
      if (!id) return "Sin funcionario";
      var employee = state.data.names[id];
      if (!employee) return "Funcionario " + id;
      return employee.name + (employee.active ? "" : " (retirado)");
    }

    // Periodo: mes actual, un mes, un año (del año en curso hasta el mes actual) o acumulado de un mes a otro.
    function period() {
      var valid = /^\d{4}-\d{2}$/;
      if (state.mode === "anual") {
        var year = inputs.year.value || currentMonth.slice(0, 4);
        var last = year === currentMonth.slice(0, 4) ? currentMonth : year + "-12";
        return { from: year + "-01-01", to: monthBounds(last).to, label: "todo " + year };
      }
      if (state.mode === "acumulado") {
        var from = valid.test(inputs.from.value) ? inputs.from.value : currentMonth.slice(0, 4) + "-01";
        var to = valid.test(inputs.to.value) ? inputs.to.value : currentMonth;
        if (from > to) { var swap = from; from = to; to = swap; }
        return { from: from + "-01", to: monthBounds(to).to, label: from === to ? monthLabel(from) : monthLabel(from) + " a " + monthLabel(to) };
      }
      var month = state.mode === "mes" && valid.test(inputs.month.value) ? inputs.month.value : currentMonth;
      var bounds = monthBounds(month);
      return { from: bounds.from, to: bounds.to, label: monthLabel(month) + (month === currentMonth ? " (mes actual)" : "") };
    }

    function setMode(mode) {
      state.mode = mode;
      module.querySelectorAll("[data-scm-case-mode]").forEach(function (button) {
        var active = button.getAttribute("data-scm-case-mode") === mode;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-checked", active ? "true" : "false");
      });
      module.querySelectorAll("[data-scm-case-mode-field]").forEach(function (field) {
        field.hidden = field.getAttribute("data-scm-case-mode-field") !== mode;
      });
    }

    // Tema, departamento y funcionario: el periodo no aplica a lo que se mide "hoy".
    function scopedRows() {
      var tema = inputs.tema.value;
      var departamento = inputs.departamento.value;
      var funcionario = inputs.funcionario.value;
      return state.data.rows.filter(function (row) {
        return (!tema || row.tema === tema) &&
          (!departamento || row.departamento === departamento) &&
          (!funcionario || row.funcionario === funcionario);
      });
    }

    function tally(rows) {
      var counts = { total: rows.length, Nuevo: 0, "En proceso": 0, Postergado: 0, Cerrado: 0, otros: 0 };
      rows.forEach(function (row) {
        if (counts[row.estado] !== undefined) counts[row.estado] += 1;
        else counts.otros += 1;
      });
      counts.abiertos = counts.Nuevo + counts["En proceso"];
      return counts;
    }

    function kpi(label, value, note, tone) {
      return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" + esc(value) +
        "</strong>" + (note ? "<small>" + esc(note) + "</small>" : "") + "</article>";
    }
    function brief(lines) {
      return '<section class="scm-cm-brief" aria-label="Lectura rápida"><h2>Lectura rápida</h2><ul>' + lines.map(function (line) {
        return '<li class="is-' + line.tone + '"><span class="material-symbols-outlined" aria-hidden="true">' + BRIEF_ICONS[line.tone] + "</span><span>" + esc(line.text) + "</span></li>";
      }).join("") + "</ul></section>";
    }
    function card(title, body, aside) {
      return '<section class="scm-cm-card is-wide"><header><h2>' + esc(title) + "</h2>" + (aside || "") + "</header>" + body + "</section>";
    }

    // Casos creados por día (periodos de hasta ~2 meses) o por mes.
    function timelineChart(rows, range) {
      var dates = rows.map(function (row) { return row.fecha; }).sort();
      if (!dates.length) return '<div class="scm-cm-empty">No hay casos en el periodo.</div>';
      var from = range.from;
      var to = range.to;
      var days = Math.round((Date.parse(to) - Date.parse(from)) / 86400000) + 1;
      var daily = days <= 62;
      var buckets = [];
      var index = {};
      if (daily) {
        for (var d = new Date(from + "T00:00:00"); d <= new Date(to + "T00:00:00"); d.setDate(d.getDate() + 1)) {
          var key = d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
          index[key] = buckets.length;
          buckets.push({ key: key, label: String(d.getDate()), tip: formatDay(key), count: 0 });
        }
      } else {
        var cursor = new Date(Number(from.slice(0, 4)), Number(from.slice(5, 7)) - 1, 1);
        var end = new Date(Number(to.slice(0, 4)), Number(to.slice(5, 7)) - 1, 1);
        for (; cursor <= end; cursor.setMonth(cursor.getMonth() + 1)) {
          var monthKey = cursor.getFullYear() + "-" + String(cursor.getMonth() + 1).padStart(2, "0");
          index[monthKey] = buckets.length;
          buckets.push({ key: monthKey, label: MONTHS_SHORT[cursor.getMonth()] + (cursor.getMonth() === 0 || buckets.length === 0 ? " " + String(cursor.getFullYear()).slice(2) : ""), tip: MONTHS_LONG[cursor.getMonth()] + " " + cursor.getFullYear(), count: 0 });
        }
      }
      rows.forEach(function (row) {
        var position = index[daily ? row.fecha : row.fecha.slice(0, 7)];
        if (position !== undefined) buckets[position].count += 1;
      });
      var max = Math.max.apply(null, buckets.map(function (b) { return b.count; })) || 1;
      var peak = buckets.reduce(function (best, b) { return b.count > best.count ? b : best; }, buckets[0]);
      var labelEvery = Math.ceil(buckets.length / 16);
      var html = '<div class="scm-cc-columns" style="--cols:' + buckets.length + '"><div class="scm-cc-plot">';
      buckets.forEach(function (bucket) {
        var height = bucket.count ? Math.max(2, (bucket.count / max) * 100) : 0;
        html += '<div class="scm-cc-col" data-tip="' + esc(bucket.tip + ": " + num(bucket.count) + (bucket.count === 1 ? " caso" : " casos")) + '">' +
          '<span class="scm-cc-bar" style="height:' + height.toFixed(2) + "%;background:" + SERIES_COLOR + '">' +
          (bucket === peak && bucket.count ? "<i>" + num(bucket.count) + "</i>" : "") + "</span></div>";
      });
      html += '</div><div class="scm-cc-axis">' + buckets.map(function (bucket, i) {
        return "<span>" + (i % labelEvery === 0 ? esc(bucket.label) : "") + "</span>";
      }).join("") + "</div></div>";
      return html;
    }

    // Estado de los casos por funcionario: barras apiladas con 2px de separación entre segmentos.
    function employeeChart(items) {
      if (!items.length) return '<div class="scm-cm-empty">No hay casos con estos filtros.</div>';
      var shown = items.slice(0, 12);
      var max = Math.max.apply(null, shown.map(function (item) { return item.total; })) || 1;
      var html = '<div class="scm-cc-legend">' + STATES.map(function (s) {
        return '<span><i style="background:' + s.color + '"></i>' + esc(s.label) + "</span>";
      }).join("") + '</div><ul class="scm-cc-hbars">';
      shown.forEach(function (item) {
        html += '<li><span class="scm-cc-hlabel" title="' + esc(item.label) + '">' + esc(item.label) + '</span><span class="scm-cc-htrack"><span class="scm-cc-stack" style="width:' +
          ((item.total / max) * 100).toFixed(2) + '%">' + STATES.map(function (s) {
            var value = item[s.key];
            if (!value) return "";
            return '<span class="scm-cc-seg" style="flex:' + value + ";background:" + s.color + '" data-tip="' +
              esc(item.label + " · " + s.label + ": " + num(value) + " (" + pct(value, item.total) + ")") + '"></span>';
          }).join("") + '</span><b>' + num(item.total) + "</b></span></li>";
      });
      html += "</ul>";
      if (items.length > shown.length) {
        html += '<p class="scm-cm-hint">Se muestran los 12 funcionarios con más casos abiertos; la tabla tiene todos.</p>';
      }
      return html;
    }

    // Casos abiertos por antigüedad: barras horizontales de una sola serie.
    function agingChart(openRows) {
      if (!openRows.length) return '<div class="scm-cm-empty">No hay casos abiertos.</div>';
      var buckets = AGE_BUCKETS.map(function (bucket) { return { label: bucket.label, max: bucket.max, total: 0 }; });
      openRows.forEach(function (row) {
        var age = ageDays(row);
        if (age === null) return;
        buckets.filter(function (bucket) { return age <= bucket.max; })[0].total += 1;
      });
      var max = Math.max.apply(null, buckets.map(function (bucket) { return bucket.total; })) || 1;
      return '<ul class="scm-cc-hbars">' + buckets.map(function (bucket) {
        return '<li><span class="scm-cc-hlabel">' + esc(bucket.label) + '</span><span class="scm-cc-htrack"><span class="scm-cc-stack" style="width:' +
          ((bucket.total / max) * 100).toFixed(2) + '%"><span class="scm-cc-seg" style="flex:1;background:' + SERIES_COLOR + '" data-tip="' +
          esc(bucket.label + ": " + count(bucket.total, "caso", "casos")) + '"></span></span><b>' + num(bucket.total) + "</b></span></li>";
      }).join("") + "</ul>";
    }

    function groupBy(rows, key) {
      var groups = {};
      rows.forEach(function (row) {
        var value = row[key] || "";
        (groups[value] = groups[value] || []).push(row);
      });
      return groups;
    }

    function render() {
      if (!state.data) return;
      var range = period();
      updatedEl.textContent = state.data.generatedAt
        ? "Datos de las " + new Date(state.data.generatedAt * 1000).toLocaleTimeString("es-CO", { hour: "2-digit", minute: "2-digit" })
        : "";
      var scoped = scopedRows();
      var rows = scoped.filter(function (row) { return row.fecha && row.fecha >= range.from && row.fecha <= range.to; });
      var totals = tally(rows);
      var closed = scoped.filter(function (row) { return row.cerrado && row.cerrado >= range.from && row.cerrado <= range.to; }).length;
      var balance = totals.total - closed;
      var openRows = scoped.filter(function (row) { return row.estado === "Nuevo" || row.estado === "En proceso"; });
      var oldOpen = openRows.filter(function (row) { return ageDays(row) > OLD_DAYS; });
      var postponed = scoped.filter(function (row) { return row.estado === "Postergado"; }).length;

      var html = "";
      var byEmployee = groupBy(rows, "funcionario");
      var openByEmployee = groupBy(openRows, "funcionario");
      var ids = {};
      Object.keys(byEmployee).concat(Object.keys(openByEmployee)).forEach(function (id) { ids[id] = true; });
      state.byEmployee = Object.keys(ids).map(function (id) {
        var counts = tally(byEmployee[id] || []);
        var open = openByEmployee[id] || [];
        counts.id = id;
        counts.label = employeeLabel(id);
        counts.hoy = open.length;
        counts.viejos = open.filter(function (row) { return ageDays(row) > OLD_DAYS; }).length;
        return counts;
      }).sort(function (a, b) { return b.hoy - a.hoy || b.total - a.total || a.label.localeCompare(b.label, "es"); });
      var byTopic = groupBy(rows, "tema");
      var topicRows = Object.keys(byTopic).map(function (key) {
        var counts = tally(byTopic[key]);
        counts.label = key || "Sin tema";
        return counts;
      }).sort(function (a, b) { return b.total - a.total; });
      var openByTopic = groupBy(openRows, "tema");
      var topTopic = Object.keys(openByTopic).filter(Boolean).sort(function (a, b) { return openByTopic[b].length - openByTopic[a].length; })[0];

      var lines = [];
      lines.push({ tone: oldOpen.length ? "bad" : "good", text: count(openRows.length, "caso abierto", "casos abiertos") + " hoy" +
        (oldOpen.length ? "; " + num(oldOpen.length) + " (" + pct(oldOpen.length, openRows.length) + ") llevan más de " + OLD_DAYS + " días." : ".") });
      lines.push({ tone: balance > 0 ? "warn" : "good", text: "En " + range.label + " se crearon " + num(totals.total) + " y se cerraron " + num(closed) +
        (balance === 0 ? ": la carga se mantiene." : ": la carga " + (balance > 0 ? "subió" : "bajó") + " en " + num(Math.abs(balance)) + ".") });
      var topEmployee = state.byEmployee[0];
      if (topEmployee && topEmployee.hoy) {
        lines.push({ tone: "warn", text: "Más carga: " + topEmployee.label + " con " + count(topEmployee.hoy, "abierto", "abiertos") +
          (topTopic ? " · tema con más abiertos: " + topTopic + " (" + num(openByTopic[topTopic].length) + ")." : ".") });
      }
      if (postponed) lines.push({ tone: "warn", text: count(postponed, "caso postergado", "casos postergados") + " que no cuentan como abiertos." });
      html += brief(lines);

      html += '<div class="scm-cm-kpis scm-cm-kpis-6">';
      html += kpi("Casos creados", num(totals.total), "Creados en " + range.label, "stock");
      html += kpi("Cerrados", num(closed), "En " + range.label + ", sin importar cuándo se crearon", "good");
      html += kpi("Balance", (balance > 0 ? "+" : "") + num(balance), balance > 0 ? "Entran más de los que salen" : "Salen tantos o más de los que entran", balance > 0 ? "bad" : "good");
      html += kpi("Abiertos hoy", num(openRows.length), "Nuevos y en proceso, de cualquier fecha", "ocup");
      html += kpi("Más de " + OLD_DAYS + " días", num(oldOpen.length), pct(oldOpen.length, openRows.length) + " de los abiertos hoy", oldOpen.length ? "bad" : "good");
      html += kpi("Postergados hoy", num(postponed), "No cuentan como abiertos");
      html += "</div>";

      html += '<div class="scm-cm-grid">';
      html += '<section class="scm-cm-card"><header><h2>Estado de los creados en el periodo</h2></header>' +
        employeeChart([Object.assign({ label: "Creados" }, totals)]) + "</section>";
      html += '<section class="scm-cm-card"><header><h2>Antigüedad de los abiertos hoy</h2></header>' + agingChart(openRows) + "</section>";
      html += "</div>";

      html += '<section class="scm-cm-card is-wide"><header><h2>Casos creados · ' + esc(range.label) + "</h2></header>" + timelineChart(rows, range) + "</section>";

      var stateHeads = STATES.map(function (s) { return '<th class="is-num">' + s.label + "</th>"; }).join("");
      var employeeTable = state.byEmployee.length
        ? '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Funcionario</th><th class="is-num">Abiertos hoy</th><th class="is-num">Más de ' + OLD_DAYS + ' días</th><th class="is-num">Creados</th>' + stateHeads +
          '<th class="is-num">% cerrados</th></tr></thead><tbody>' +
          state.byEmployee.map(function (item) {
            return "<tr><td>" + (item.id
              ? '<button type="button" class="scm-cm-link" data-scm-case-employee="' + esc(item.id) + '" title="Filtrar por este funcionario">' + esc(item.label) + "</button>"
              : '<span class="scm-cm-muted">Sin funcionario</span>') +
              '</td><td class="is-num is-total">' + num(item.hoy) + '</td><td class="is-num">' + num(item.viejos) + '</td><td class="is-num">' + num(item.total) + "</td>" +
              STATES.map(function (s) { return '<td class="is-num">' + num(item[s.key]) + "</td>"; }).join("") +
              '<td class="is-num">' + pct(item.Cerrado, item.total) + "</td></tr>";
          }).join("") +
          '</tbody><tfoot><tr><td>Total</td><td class="is-num is-total">' + num(openRows.length) + '</td><td class="is-num">' + num(oldOpen.length) + '</td><td class="is-num">' + num(totals.total) + "</td>" +
          STATES.map(function (s) { return '<td class="is-num">' + num(totals[s.key]) + "</td>"; }).join("") +
          '<td class="is-num">' + pct(totals.Cerrado, totals.total) + "</td></tr></tfoot></table></div>"
        : '<div class="scm-cm-empty">No hay casos con estos filtros.</div>';

      // Por funcionario y por estado administrativo.
      html += '<div class="scm-cm-grid">';
      html += '<section class="scm-cm-card"><header><h2>Estado por funcionario</h2></header>' + employeeChart(state.byEmployee.filter(function (item) { return item.total; })) + "</section>";
      var byAdm = groupBy(rows, "adm");
      var admRows = Object.keys(byAdm).map(function (key) { return { label: key || "Sin estado administrativo", count: byAdm[key].length, empty: !key }; })
        .sort(function (a, b) { return a.empty - b.empty || b.count - a.count; });
      html += '<section class="scm-cm-card"><header><h2>Por estado administrativo</h2></header>' + (admRows.length
        ? '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Estado</th><th class="is-num">Casos</th><th class="is-num">%</th></tr></thead><tbody>' +
          admRows.map(function (item) {
            return "<tr><td>" + (item.empty ? '<span class="scm-cm-muted">' + esc(item.label) + "</span>" : esc(item.label)) + '</td><td class="is-num">' + num(item.count) +
              '</td><td class="is-num">' + pct(item.count, totals.total) + "</td></tr>";
          }).join("") + "</tbody></table></div>"
        : '<div class="scm-cm-empty">Sin casos.</div>') + "</section>";
      html += "</div>";

      html += card("Casos por funcionario", employeeTable, '<p class="scm-cm-hint">Las dos primeras columnas son de hoy; las demás, de los creados en el periodo. Toca un funcionario para filtrar.</p>');

      html += card("Casos por tema", topicRows.length
        ? '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Tema</th><th class="is-num">Creados</th><th class="is-num">Abiertos</th><th class="is-num">Postergados</th><th class="is-num">Cerrados</th></tr></thead><tbody>' +
          topicRows.map(function (item) {
            return "<tr><td>" + esc(item.label) + '</td><td class="is-num is-total">' + num(item.total) + '</td><td class="is-num">' + num(item.abiertos) +
              '</td><td class="is-num">' + num(item.Postergado) + '</td><td class="is-num">' + num(item.Cerrado) + "</td></tr>";
          }).join("") + "</tbody></table></div>"
        : '<div class="scm-cm-empty">Sin casos.</div>');

      html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>Los casos no guardan fecha de cierre: «Cerrados» usa la última actualización del caso cerrado, y la antigüedad se mide desde su creación.</p>';
      if (state.data.excluded) {
        html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>' +
          esc("Solo se cuentan los funcionarios configurados en los filtros del panel; " + num(state.data.excluded) + " casos de otros funcionarios no se incluyen.") + "</p>";
      }
      var noDate = state.data.rows.filter(function (row) { return !row.fecha; }).length;
      if (noDate) {
        html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>' +
          esc(num(noDate) + (noDate === 1 ? " caso no tiene fecha de creación y no se cuenta." : " casos no tienen fecha de creación y no se cuentan.")) + "</p>";
      }
      content.innerHTML = html;
    }

    function exportCsv() {
      if (!state.data) return;
      var lines = [["Funcionario", "Abiertos hoy", "Más de " + OLD_DAYS + " días", "Creados", "Nuevos", "En proceso", "Postergados", "Cerrados"]];
      state.byEmployee.forEach(function (item) {
        lines.push([item.label, item.hoy, item.viejos, item.total, item.Nuevo, item["En proceso"], item.Postergado, item.Cerrado]);
      });
      var csv = "﻿" + lines.map(function (line) {
        return line.map(function (cell) {
          var text = String(cell == null ? "" : cell);
          return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }).join(";");
      }).join("\r\n");
      var range = period();
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = "casos-por-funcionario-" + range.label.replace(/\s+/g, "-") + ".csv";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    }

    module.addEventListener("change", function (event) {
      var key = event.target.getAttribute && event.target.getAttribute("data-scm-case-filter");
      if (!key || !state.data) return;
      render();
    });

    module.addEventListener("click", function (event) {
      if (event.target.closest("[data-scm-case-refresh]")) { load(true); return; }
      var mode = event.target.closest("[data-scm-case-mode]");
      if (mode) {
        setMode(mode.getAttribute("data-scm-case-mode"));
        if (state.data) render();
        return;
      }
      if (!state.data) return;
      if (event.target.closest("[data-scm-case-export]")) { exportCsv(); return; }
      if (event.target.closest("[data-scm-case-clear]")) {
        setMode("actual");
        inputs.month.value = currentMonth;
        inputs.year.value = currentMonth.slice(0, 4);
        inputs.from.value = currentMonth.slice(0, 4) + "-01";
        inputs.to.value = currentMonth;
        inputs.tema.value = "";
        inputs.departamento.value = "";
        inputs.funcionario.value = "";
        render();
        return;
      }
      var employee = event.target.closest("[data-scm-case-employee]");
      if (employee) {
        var id = employee.getAttribute("data-scm-case-employee");
        inputs.funcionario.value = inputs.funcionario.value === id ? "" : id;
        render();
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
      var x = event.clientX - bounds.left + 14;
      var y = event.clientY - bounds.top + 14;
      tooltip.style.left = Math.min(x, bounds.width - tooltip.offsetWidth - 8) + "px";
      tooltip.style.top = y + "px";
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
