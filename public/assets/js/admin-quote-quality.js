(function () {
  "use strict";

  // Métricas › Cotizaciones › pestaña "Conciliar con el auxiliar contable".
  // Usa las filas que ya cargó admin-quote-metrics.js (evento scm:quote-data) y, tras corregir,
  // le pide recargar (scm:quote-reload). Solo va al servidor para guardar o para conciliar un archivo.
  var MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var SOURCE_LABELS = { orden: "primera orden de trabajo", acta: "acta de satisfacción", auxiliar: "fecha contable del auxiliar" };
  var LEDGER_STATES = {
    coincide: ["Coincide", "is-entregado"],
    coincide_inmueble_caso: ["Coincide · inmueble del caso", "is-entregado"],
    coincide_sin_inmueble: ["Coincide · cotización sin inmueble", "is-por_recibir"],
    coincide_otro_inmueble: ["Mismo valor · otro inmueble", "is-por_recibir"],
    coincide_suma: ["Coincide por suma de cuotas", "is-entregado"],
    diferencia: ["Valor distinto", "is-recibido"],
    sin_cotizacion: ["Sin cotización", "is-recibido"],
  };

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function money(value) { return moneyFormat.format(value || 0); }
  function day(iso) {
    return iso ? Number(iso.slice(8, 10)) + " " + MONTHS_SHORT[Number(iso.slice(5, 7)) - 1] + " " + iso.slice(0, 4) : "—";
  }
  function daysBetween(a, b) { return (Date.parse(a) - Date.parse(b)) / 86400000; }
  function todayIso() {
    var d = new Date();
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }
  function missingProperty(value) { return !/^\d+$/.test(String(value || "").trim()); }

  // Fecha de respuesta dudosa: muy posterior a la creación (aprobaciones marcadas tarde, en lote)
  // o muy posterior a la primera orden de trabajo, que solo existe si ya estaba aprobada.
  // Las que un funcionario ya corrigió o confirmó (revisada) no se vuelven a marcar.
  function isDubious(row) {
    if (!row.approved || !row.respuesta || row.respuesta_fuente !== "cotizacion" || !row.fecha || row.revisada) return false;
    return daysBetween(row.respuesta, row.fecha) > 180 || (row.sugerida && daysBetween(row.respuesta, row.sugerida) > 45);
  }

  function init(root) {
    var module = root.querySelector("[data-scm-quote-metrics]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.qqInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.qqInit = "1";
    var actions = runtime.actions || {};
    var ledgerEl = module.querySelector("[data-scm-quote-ledger]");
    var state = { rows: [], canEditResponse: false, canEditQuote: false, ledger: null, ledgerFilter: "", saving: false };

    function post(action, fields, file) {
      var body = new FormData();
      body.append("action", action || "");
      body.append("nonce", runtime.nonce || "");
      Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
      if (file) body.append("auxiliar", file);
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
          return json.data || {};
        });
    }

    function caseButton(row) {
      return '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-case data-scm-maintenance-quote-open-case data-cotizacion-id="' + esc(row.id) +
        '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">Ver caso</button>';
    }

    function assignProperty(ids, button) {
      state.saving = true;
      if (button) { button.disabled = true; button.textContent = "Asignando…"; }
      return post(actions.quote_assign_property, { ids: JSON.stringify(ids) })
        .then(function (result) {
          if (!(result.updated || []).length) throw new Error("No se encontró un inmueble para asignar.");
          return result;
        })
        .catch(function (error) {
          if (button) {
            button.disabled = false;
            button.textContent = "Reintentar";
            button.title = error.message;
          }
          throw error;
        })
        .then(function (result) { state.saving = false; return result; }, function () { state.saving = false; });
    }

    // Conciliación con el auxiliar contable.
    function renderLedgerShell() {
      ledgerEl.innerHTML = '<section class="scm-cm-card is-wide"><header><h2>Conciliar con el auxiliar contable</h2><p class="scm-cm-hint">Sube el auxiliar de administración (AUI) en .xls o .xlsx</p></header>' +
        '<form class="scm-qq-upload" data-scm-qq-ledger-form><label class="scm-qq-file"><input type="file" accept=".xls,.xlsx" data-scm-qq-ledger-file aria-label="Auxiliar contable">' +
        '<span class="material-symbols-outlined" aria-hidden="true">upload_file</span><span class="scm-qq-file-text"><strong data-scm-qq-ledger-name>Elegir auxiliar contable</strong>' +
        '<small>Archivo .xls o .xlsx exportado de contabilidad</small></span><span class="scm-qq-file-cta">Buscar archivo</span></label>' +
        '<button type="submit" class="scm-cm-btn scm-qq-primary">Conciliar</button><span class="scm-cm-hint" data-scm-qq-ledger-status aria-live="polite"></span></form>' +
        '<div data-scm-qq-ledger-result></div></section>';
    }

    function renderLedger() {
      var holder = ledgerEl.querySelector("[data-scm-qq-ledger-result]");
      var data = state.ledger;
      if (!data) { holder.innerHTML = ""; return; }
      var summary = data.resumen || {};
      var counts = summary.por_estado || {};
      var rowsById = {};
      state.rows.forEach(function (row) { rowsById[row.id] = row; });
      var filter = state.ledgerFilter;
      var rows = (data.filas || []).filter(function (row) { return !filter || (filter === "pendientes" ? row.estado !== "coincide" : row.estado === filter); });
      var differences = (counts.diferencia || 0) + (counts.sin_cotizacion || 0);
      var html = '<div class="scm-cm-kpis scm-cm-kpis-6">' +
        kpi("Movimientos", num(summary.movimientos), day(summary.desde) + " a " + day(summary.hasta), "stock") +
        kpi("Total auxiliar", money(summary.total_auxiliar), "Administración registrada en contabilidad") +
        kpi("Conciliados", num(summary.conciliados), Math.round(((summary.conciliados || 0) / (summary.movimientos || 1)) * 100) + "% de los movimientos", "good") +
        kpi("Valor distinto", num(counts.diferencia || 0), "Cuotas, abonos o administración diferente", (counts.diferencia || 0) ? "bad" : "") +
        kpi("Sin cotización", num(counts.sin_cotizacion || 0), "En contabilidad pero no en el sistema", (counts.sin_cotizacion || 0) ? "bad" : "") +
        kpi("Aprobadas sin movimiento", num((data.aprobadas_sin_movimiento || []).length), "En el sistema pero no en contabilidad", (data.aprobadas_sin_movimiento || []).length ? "bad" : "") +
        "</div>";
      var filters = [["pendientes", "Por revisar", (summary.movimientos || 0) - (counts.coincide || 0)], ["", "Todos", summary.movimientos]];
      html += '<div class="scm-qm-views scm-qq-filters">' + filters.map(function (f) {
        return '<button type="button" class="scm-qm-view' + (filter === f[0] ? " is-active" : "") + '" data-scm-qq-ledger-filter="' + f[0] + '">' + esc(f[1]) + " <b>" + num(f[2]) + "</b></button>";
      }).join("") + '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-ledger-export>Exportar conciliación</button></div>';
      html += '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-qq-table"><thead><tr><th>Fecha contable</th><th>Transacción</th><th>Inmueble</th><th class="is-num">Valor auxiliar</th>' +
        '<th>Resultado</th><th>Cotización</th><th class="is-num">Administración sistema</th><th>Qué hacer</th></tr></thead><tbody>' +
        (rows.length ? rows.map(function (row) {
          var label = LEDGER_STATES[row.estado] || [row.estado, ""];
          var quote = row.cotizacion ? rowsById[row.cotizacion] : null;
          return '<tr class="scm-maintenance-quote-row" data-cotizacion-id="' + esc(row.cotizacion || "") + '" data-ticket="' + esc(row.ticket || "") + '"><td>' + esc(day(row.fecha)) + "</td><td>" + esc(row.transaccion) +
            "</td><td>" + esc(row.inmueble) + '</td><td class="is-num">' + esc(money(row.valor)) + '</td><td><span class="scm-cm-badge ' + label[1] + '">' + esc(label[0]) + "</span></td><td>" +
            (row.cotizacion ? "<strong>#" + esc(row.cotizacion) + "</strong>" + (row.estado_cotizacion ? '<small class="scm-cm-source">' + esc(row.estado_cotizacion) + "</small>" : "") : "—") +
            '</td><td class="is-num">' + (row.admin_sistema === null || row.admin_sistema === undefined ? "—" : esc(money(row.admin_sistema))) + "</td><td>" + ledgerAction(row, quote) + "</td></tr>";
        }).join("") : '<tr><td colspan="8" class="scm-cm-muted">Todo cuadra en este filtro.</td></tr>') + "</tbody></table></div>";
      var missing = data.aprobadas_sin_movimiento || [];
      if (missing.length) {
        html += '<h3 class="scm-qm-section">Aprobadas en el periodo sin movimiento en el auxiliar</h3>' +
          '<p class="scm-cm-hint">Confírmalas con contabilidad: pueden estar sin causar, o su fecha de respuesta puede estar mal (revísala con “Ver caso”).</p>' +
          '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-qq-table"><thead><tr><th>Cotización</th><th>Respuesta</th><th>Inmueble</th><th>Destinatario</th><th>Categoría</th><th class="is-num">Administración</th><th></th></tr></thead><tbody>' +
          missing.map(function (item) {
            return '<tr class="scm-maintenance-quote-row" data-cotizacion-id="' + esc(item.cotizacion) + '" data-ticket="' + esc(item.ticket) + '"><td><strong>#' + esc(item.cotizacion) + "</strong></td><td>" +
              esc(day(item.respuesta)) + (rowsById[item.cotizacion] && isDubious(rowsById[item.cotizacion]) ? '<small class="scm-cm-source is-bad">fecha dudosa</small>' : "") + "</td><td>" +
              esc(item.inmueble || "—") + '</td><td class="is-name">' + esc(item.destinatario || "—") + "</td><td>" + esc(item.categoria) + '</td><td class="is-num">' + esc(money(item.admin)) + '</td><td class="is-actions">' +
              caseButton({ id: item.cotizacion, ticket: item.ticket, contrato: "" }) + "</td></tr>";
          }).join("") + "</tbody></table></div>";
      }
      holder.innerHTML = html;
    }

    function ledgerAction(row, quote) {
      var actionsHtml = [];
      if ((row.estado === "coincide_inmueble_caso" || row.estado === "coincide_sin_inmueble") && quote && missingProperty(quote.inmueble) && quote.inmueble_caso === row.inmueble) {
        actionsHtml.push(state.canEditQuote
          ? '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-ledger-property="' + quote.id + '">Asignar inmueble ' + esc(quote.inmueble_caso) + "</button>"
          : "Asignar inmueble " + esc(quote.inmueble_caso) + " a la cotización");
      }
      if (quote && quote.approved && !quote.respuesta) {
        var suggested = quote.sugerida || row.fecha;
        actionsHtml.push(state.canEditResponse
          ? '<span class="scm-qq-inline"><input type="date" class="scm-qm-date" data-scm-qq-ledger-date value="' + esc(suggested) + '" max="' + todayIso() + '"' + (quote.fecha ? ' min="' + esc(quote.fecha) + '"' : "") +
            ' aria-label="Fecha de respuesta"><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-ledger-save-date="' + quote.id + '">Guardar fecha</button></span>' +
            '<small class="scm-cm-source">Sin fecha de respuesta · sugerida: ' + esc(quote.sugerida ? SOURCE_LABELS[quote.sugerida_fuente] : SOURCE_LABELS.auxiliar) + "</small>"
          : "Completar fecha de respuesta");
      }
      if (row.estado === "coincide_otro_inmueble") actionsHtml.push("Verificar inmueble: sistema " + esc(row.inmueble_sistema) + ", contabilidad " + esc(row.inmueble));
      if (row.estado === "coincide_suma") actionsHtml.push("Cuotas que suman " + esc(money(row.suma_inmueble)));
      if (row.estado === "diferencia") {
        actionsHtml.push((row.suma_inmueble !== row.valor ? "Movimientos del inmueble suman " + esc(money(row.suma_inmueble)) + "; " : "") +
          "diferencia de " + esc(money(Math.abs((row.suma_inmueble || row.valor) - (row.admin_sistema || 0)))) + ". Revisa la administración o las cuotas con contabilidad.");
      }
      if (row.estado === "sin_cotizacion") actionsHtml.push("No hay cotización de este inmueble con ese valor; búscala en el caso o confírmalo con contabilidad.");
      if (row.cotizacion) actionsHtml.push(caseButton({ id: row.cotizacion, ticket: row.ticket, contrato: "" }));
      return actionsHtml.length ? actionsHtml.join(" ") : '<span class="scm-cm-muted">Nada</span>';
    }

    function kpi(label, value, note, tone) {
      return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" + esc(value) + "</strong>" + (note ? "<small>" + esc(note) + "</small>" : "") + "</article>";
    }

    function exportLedger() {
      var lines = [["Fecha contable", "Transaccion", "Inmueble", "Valor auxiliar", "Resultado", "Cotizacion", "Estado cotizacion", "Inmueble sistema", "Administracion sistema", "Suma movimientos inmueble", "Fecha respuesta"]];
      (state.ledger.filas || []).forEach(function (row) {
        lines.push([row.fecha, row.transaccion, row.inmueble, row.valor, (LEDGER_STATES[row.estado] || [row.estado])[0], row.cotizacion || "", row.estado_cotizacion, row.inmueble_sistema,
          row.admin_sistema === null ? "" : row.admin_sistema, row.suma_inmueble === null ? "" : row.suma_inmueble, row.respuesta || ""]);
      });
      (state.ledger.aprobadas_sin_movimiento || []).forEach(function (item) {
        lines.push(["", "", item.inmueble, "", "Aprobada sin movimiento en el auxiliar", item.cotizacion, "Aprobada", item.inmueble, item.admin, "", item.respuesta]);
      });
      var csv = "﻿" + lines.map(function (line) {
        return line.map(function (cell) {
          var text = String(cell == null ? "" : cell);
          return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }).join(";");
      }).join("\r\n");
      var link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = "conciliacion-auxiliar-cotizaciones.csv";
      document.body.appendChild(link);
      link.click();
      setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    }

    module.addEventListener("scm:quote-data", function (event) {
      state.rows = event.detail.rows || [];
      state.canEditResponse = !!event.detail.canEditResponse;
      state.canEditQuote = !!event.detail.canEditQuote;
      if (!ledgerEl.innerHTML) renderLedgerShell();
      renderLedger();
    });

    module.addEventListener("change", function (event) {
      var input = event.target.closest("[data-scm-qq-ledger-file]");
      if (!input) return;
      var file = input.files && input.files[0];
      var label = input.closest(".scm-qq-file");
      label.classList.toggle("has-file", !!file);
      label.querySelector("[data-scm-qq-ledger-name]").textContent = file ? file.name : "Elegir auxiliar contable";
    });

    module.addEventListener("submit", function (event) {
      var form = event.target.closest("[data-scm-qq-ledger-form]");
      if (!form) return;
      event.preventDefault();
      var input = form.querySelector("[data-scm-qq-ledger-file]");
      var status = form.querySelector("[data-scm-qq-ledger-status]");
      var file = input.files && input.files[0];
      if (!file) { status.textContent = "Elige el archivo del auxiliar."; return; }
      if (!/\.xlsx?$/i.test(file.name)) { status.textContent = "El auxiliar debe ser .xls o .xlsx."; return; }
      status.textContent = "Conciliando…";
      post(actions.quote_ledger, {}, file)
        .then(function (data) {
          state.ledger = data;
          state.ledgerFilter = "pendientes";
          status.textContent = file.name;
          renderLedger();
        })
        .catch(function (error) { status.textContent = error.message; });
    });

    module.addEventListener("click", function (event) {
      var target = event.target;
      var ledgerFilter = target.closest("[data-scm-qq-ledger-filter]");
      if (ledgerFilter) { state.ledgerFilter = ledgerFilter.getAttribute("data-scm-qq-ledger-filter"); renderLedger(); return; }
      if (target.closest("[data-scm-qq-ledger-export]")) { exportLedger(); return; }
      var ledgerProperty = target.closest("[data-scm-qq-ledger-property]");
      if (ledgerProperty && !state.saving) {
        if (!window.confirm("¿Asignar a la cotización #" + ledgerProperty.getAttribute("data-scm-qq-ledger-property") + " el inmueble de su caso?")) return;
        assignProperty([Number(ledgerProperty.getAttribute("data-scm-qq-ledger-property"))], ledgerProperty).then(function () {
          module.dispatchEvent(new CustomEvent("scm:quote-reload"));
        });
        return;
      }
      var ledgerDate = target.closest("[data-scm-qq-ledger-save-date]");
      if (ledgerDate && !state.saving) {
        var input = ledgerDate.parentElement.querySelector("[data-scm-qq-ledger-date]");
        if (!input.value || !input.checkValidity()) { input.reportValidity(); return; }
        state.saving = true;
        ledgerDate.disabled = true;
        post(actions.quote_response_date, { changes: JSON.stringify([{ id: Number(ledgerDate.getAttribute("data-scm-qq-ledger-save-date")), date: input.value }]), overwrite: "0" })
          .then(function (result) {
            if ((result.errors || []).length) throw new Error(result.errors[0].message);
            ledgerDate.textContent = "Guardada";
            module.dispatchEvent(new CustomEvent("scm:quote-reload"));
          })
          .catch(function (error) {
            ledgerDate.disabled = false;
            var holder = ledgerDate.closest("td");
            var message = holder.querySelector(".scm-qm-row-msg") || holder.appendChild(document.createElement("small"));
            message.className = "scm-qm-row-msg is-error";
            message.textContent = error.message;
          })
          .then(function () { state.saving = false; });
      }
    });
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
