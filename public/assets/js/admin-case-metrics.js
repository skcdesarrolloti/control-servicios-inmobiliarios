(function () {
  "use strict";

  // Métricas › Casos: los casos llegan en una sola petición y los filtros (mes, rango de
  // fechas, tema, departamento, funcionario) y las tablas se calculan en el navegador.
  var CACHE_KEY = "scm-case-metrics-v1";
  var CACHE_TTL_MS = 5 * 60 * 1000;
  var MONTHS_LONG = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
  var STATES = [
    { key: "Nuevo", label: "Nuevos" },
    { key: "En proceso", label: "En proceso" },
    { key: "Postergado", label: "Postergados" },
    { key: "Cerrado", label: "Cerrados" },
  ];
  var numberFormat = new Intl.NumberFormat("es-CO");

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function pct(part, total) { return total ? Math.round((part / total) * 100) + "%" : "—"; }
  function formatDay(iso) {
    return Number(iso.slice(8, 10)) + " " + MONTHS_LONG[Number(iso.slice(5, 7)) - 1].slice(0, 3) + " " + iso.slice(0, 4);
  }
  function monthBounds(month) {
    var year = Number(month.slice(0, 4));
    var index = Number(month.slice(5, 7));
    var last = new Date(year, index, 0).getDate();
    return { from: month + "-01", to: month + "-" + String(last).padStart(2, "0") };
  }
  function currentMonth() {
    var now = new Date();
    return now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0");
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
    var inputs = {};
    module.querySelectorAll("[data-scm-case-filter]").forEach(function (input) {
      inputs[input.getAttribute("data-scm-case-filter")] = input;
    });
    var state = { data: null, pending: null, byEmployee: [] };

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
      var names = snapshot.funcionarios || {};
      state.data = {
        generatedAt: snapshot.generated_at || 0,
        names: names,
        rows: (snapshot.rows || []).map(function (values) {
          return {
            estado: dict.estado[values[0]] || "",
            adm: dict.estado_administrativo[values[1]] || "",
            tema: dict.tema[values[2]] || "",
            departamento: dict.departamento[values[3]] || "",
            funcionario: values[4] || "",
            fecha: values[5] || "",
          };
        }),
      };
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

    // El rango de fechas manda sobre el mes; sin ninguno se muestra todo el histórico.
    function period() {
      var from = inputs.from.value;
      var to = inputs.to.value;
      if (from || to) {
        return { from: from || "0000-01-01", to: to || "9999-12-31", label: (from ? formatDay(from) : "el inicio") + " a " + (to ? formatDay(to) : "hoy") };
      }
      if (inputs.month.value) {
        var bounds = monthBounds(inputs.month.value);
        return { from: bounds.from, to: bounds.to, label: MONTHS_LONG[Number(inputs.month.value.slice(5, 7)) - 1] + " " + inputs.month.value.slice(0, 4) };
      }
      return { from: "0000-01-01", to: "9999-12-31", label: "todo el histórico" };
    }

    function filteredRows() {
      var range = period();
      var tema = inputs.tema.value;
      var departamento = inputs.departamento.value;
      var funcionario = inputs.funcionario.value;
      return state.data.rows.filter(function (row) {
        return row.fecha && row.fecha >= range.from && row.fecha <= range.to &&
          (!tema || row.tema === tema) &&
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
    function card(title, body, aside) {
      return '<section class="scm-cm-card is-wide"><header><h2>' + esc(title) + "</h2>" + (aside || "") + "</header>" + body + "</section>";
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
      var rows = filteredRows();
      var totals = tally(rows);

      var html = '<div class="scm-cm-kpis scm-cm-kpis-6">';
      html += kpi("Casos creados", num(totals.total), "Creados en " + range.label, "stock");
      html += kpi("Abiertos", num(totals.abiertos), pct(totals.abiertos, totals.total) + " · nuevos y en proceso", "ocup");
      html += kpi("Nuevos", num(totals.Nuevo), pct(totals.Nuevo, totals.total) + " del total");
      html += kpi("En proceso", num(totals["En proceso"]), pct(totals["En proceso"], totals.total) + " del total");
      html += kpi("Postergados", num(totals.Postergado), pct(totals.Postergado, totals.total) + " del total");
      html += kpi("Cerrados", num(totals.Cerrado), pct(totals.Cerrado, totals.total) + " del total", "good");
      html += "</div>";

      // Por funcionario.
      var byEmployee = groupBy(rows, "funcionario");
      state.byEmployee = Object.keys(byEmployee).map(function (id) {
        var counts = tally(byEmployee[id]);
        counts.id = id;
        counts.label = employeeLabel(id);
        return counts;
      }).sort(function (a, b) { return b.abiertos - a.abiertos || b.total - a.total || a.label.localeCompare(b.label, "es"); });
      var stateHeads = STATES.map(function (s) { return '<th class="is-num">' + s.label + "</th>"; }).join("");
      var employeeTable = state.byEmployee.length
        ? '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Funcionario</th><th class="is-num">Total</th><th class="is-num">Abiertos</th>' + stateHeads +
          '<th class="is-num">% cerrados</th></tr></thead><tbody>' +
          state.byEmployee.map(function (item) {
            return "<tr><td>" + (item.id
              ? '<button type="button" class="scm-cm-link" data-scm-case-employee="' + esc(item.id) + '" title="Filtrar por este funcionario">' + esc(item.label) + "</button>"
              : '<span class="scm-cm-muted">Sin funcionario</span>') +
              '</td><td class="is-num is-total">' + num(item.total) + '</td><td class="is-num">' + num(item.abiertos) + "</td>" +
              STATES.map(function (s) { return '<td class="is-num">' + num(item[s.key]) + "</td>"; }).join("") +
              '<td class="is-num">' + pct(item.Cerrado, item.total) + "</td></tr>";
          }).join("") +
          '</tbody><tfoot><tr><td>Total</td><td class="is-num is-total">' + num(totals.total) + '</td><td class="is-num">' + num(totals.abiertos) + "</td>" +
          STATES.map(function (s) { return '<td class="is-num">' + num(totals[s.key]) + "</td>"; }).join("") +
          '<td class="is-num">' + pct(totals.Cerrado, totals.total) + "</td></tr></tfoot></table></div>"
        : '<div class="scm-cm-empty">No hay casos con estos filtros.</div>';
      html += card("Casos por funcionario", employeeTable, '<p class="scm-cm-hint">Toca un funcionario para filtrar</p>');

      // Por estado administrativo y por tema.
      html += '<div class="scm-cm-grid">';
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

      var byTopic = groupBy(rows, "tema");
      var topicRows = Object.keys(byTopic).map(function (key) {
        var counts = tally(byTopic[key]);
        counts.label = key || "Sin tema";
        return counts;
      }).sort(function (a, b) { return b.total - a.total; });
      html += '<section class="scm-cm-card"><header><h2>Por tema</h2></header>' + (topicRows.length
        ? '<div class="scm-cm-table-wrap"><table class="scm-cm-table"><thead><tr><th>Tema</th><th class="is-num">Total</th><th class="is-num">Abiertos</th><th class="is-num">Postergados</th><th class="is-num">Cerrados</th></tr></thead><tbody>' +
          topicRows.map(function (item) {
            return "<tr><td>" + esc(item.label) + '</td><td class="is-num is-total">' + num(item.total) + '</td><td class="is-num">' + num(item.abiertos) +
              '</td><td class="is-num">' + num(item.Postergado) + '</td><td class="is-num">' + num(item.Cerrado) + "</td></tr>";
          }).join("") + "</tbody></table></div>"
        : '<div class="scm-cm-empty">Sin casos.</div>') + "</section>";
      html += "</div>";

      var noDate = state.data.rows.filter(function (row) { return !row.fecha; }).length;
      if (noDate) {
        html += '<p class="scm-cm-note"><span class="material-symbols-outlined" aria-hidden="true">info</span>' +
          esc(num(noDate) + (noDate === 1 ? " caso no tiene fecha de creación y no se cuenta." : " casos no tienen fecha de creación y no se cuentan.")) + "</p>";
      }
      content.innerHTML = html;
    }

    function exportCsv() {
      if (!state.data) return;
      var lines = [["Funcionario", "Total", "Abiertos", "Nuevos", "En proceso", "Postergados", "Cerrados"]];
      state.byEmployee.forEach(function (item) {
        lines.push([item.label, item.total, item.abiertos, item.Nuevo, item["En proceso"], item.Postergado, item.Cerrado]);
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
      if (key === "month" && inputs.month.value) {
        inputs.from.value = "";
        inputs.to.value = "";
      }
      if ((key === "from" || key === "to") && (inputs.from.value || inputs.to.value)) {
        inputs.month.value = "";
      }
      render();
    });

    module.addEventListener("click", function (event) {
      if (event.target.closest("[data-scm-case-refresh]")) { load(true); return; }
      if (!state.data) return;
      if (event.target.closest("[data-scm-case-export]")) { exportCsv(); return; }
      if (event.target.closest("[data-scm-case-clear]")) {
        inputs.month.value = currentMonth();
        inputs.from.value = "";
        inputs.to.value = "";
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
