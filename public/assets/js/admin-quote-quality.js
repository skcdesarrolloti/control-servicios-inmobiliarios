(function () {
  "use strict";

  // Métricas › Cotizaciones › pestaña "Conciliar con el auxiliar contable".
  // Al subir el auxiliar (AUI) se agrupan sus movimientos por número de inmueble y, por cada inmueble,
  // se listan TODAS las cotizaciones del sistema con ese inmueble (en la cotización o en su caso),
  // con fecha de respuesta, valor total y administración editables.
  // Usa las filas que ya cargó admin-quote-metrics.js (evento scm:quote-data); al guardar actualiza esas
  // mismas filas y avisa con scm:quote-rows-changed para que el informe se recalcule sin recargar.
  var MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var PAGE_SIZE = 40;
  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var STATUS = {
    ok: ["Cuadra", "is-entregado"],
    diff: ["Diferencia", "is-recibido"],
    none: ["Sin cotizaciones", "is-por_recibir"],
  };

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function num(value) { return numberFormat.format(value || 0); }
  function money(value) { return moneyFormat.format(value || 0); }
  function digits(value) { var raw = String(value == null ? "" : value).replace(/\D+/g, ""); return raw === "" ? null : Number(raw); }
  function day(iso) {
    return iso ? Number(iso.slice(8, 10)) + " " + MONTHS_SHORT[Number(iso.slice(5, 7)) - 1] + " " + iso.slice(0, 4) : "—";
  }
  function todayIso() {
    var d = new Date();
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }
  function normalize(text) {
    return String(text || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
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
    var state = { rows: [], canEditResponse: false, canEditQuote: false, ledger: null, filter: "revisar", search: "", limit: PAGE_SIZE, saving: false };

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

    // ---- Datos: agrupación por inmueble -------------------------------------------------------

    // Periodo del auxiliar en meses: la administración del sistema se compara con las aprobadas
    // cuya fecha de respuesta cae en esos meses.
    function ledgerRange() {
      var summary = (state.ledger && state.ledger.resumen) || {};
      return { from: String(summary.desde || "").slice(0, 7), to: String(summary.hasta || "").slice(0, 7) };
    }
    function inRange(row, range) {
      if (!row.approved || !row.respuesta) return false;
      var ym = row.respuesta.slice(0, 7);
      return ym >= range.from && ym <= range.to;
    }
    function quotesOf(property) {
      return state.rows.filter(function (row) { return String(row.inmueble) === property || String(row.inmueble_caso || "") === property; })
        .sort(function (a, b) { return (b.fecha || "") < (a.fecha || "") ? -1 : (b.fecha || "") > (a.fecha || "") ? 1 : b.id - a.id; });
    }

    function buildGroup(property, moves) {
      var range = ledgerRange();
      var quotes = quotesOf(property);
      var ledgerSum = moves.reduce(function (sum, move) { return sum + move.valor; }, 0);
      var systemSum = quotes.filter(function (row) { return inRange(row, range); }).reduce(function (sum, row) { return sum + (row.admin || 0); }, 0);
      var status = !quotes.length ? "none" : ledgerSum === systemSum ? "ok" : "diff";
      var address = "";
      quotes.some(function (row) { address = row.direccion || ""; return !!address; });
      return { key: property, moves: moves, quotes: quotes, ledgerSum: ledgerSum, systemSum: systemSum, status: status, address: address };
    }

    function groups() {
      var byProperty = {};
      var order = [];
      ((state.ledger && state.ledger.filas) || []).forEach(function (move) {
        var key = String(move.inmueble);
        if (!byProperty[key]) { byProperty[key] = []; order.push(key); }
        byProperty[key].push(move);
      });
      return order.map(function (key) { return buildGroup(key, byProperty[key]); })
        .sort(function (a, b) { return Math.abs(b.ledgerSum - b.systemSum) - Math.abs(a.ledgerSum - a.systemSum) || a.key.localeCompare(b.key); });
    }

    // Aprobadas en el periodo del auxiliar cuyo inmueble no aparece en el auxiliar.
    function orphanQuotes(list) {
      var range = ledgerRange();
      var inLedger = {};
      list.forEach(function (group) { inLedger[group.key] = true; });
      return state.rows.filter(function (row) {
        return inRange(row, range) && !inLedger[String(row.inmueble)] && !inLedger[String(row.inmueble_caso || "")];
      }).sort(function (a, b) { return (b.respuesta || "") < (a.respuesta || "") ? -1 : 1; });
    }

    function matchedIds() {
      var ids = {};
      ((state.ledger && state.ledger.filas) || []).forEach(function (move) {
        if (move.cotizacion && String(move.estado || "").indexOf("coincide") === 0) ids[move.cotizacion] = true;
      });
      return ids;
    }

    // ---- Vista ---------------------------------------------------------------------------------

    function renderLedgerShell() {
      ledgerEl.innerHTML = '<section class="scm-cm-card is-wide"><header><h2>Conciliar con el auxiliar contable</h2>' +
        '<p class="scm-cm-hint">Sube el auxiliar de administración (AUI) en .xls o .xlsx: por cada número de inmueble verás todas sus cotizaciones para corregir fecha, total y administración.</p></header>' +
        '<form class="scm-qq-upload" data-scm-qq-ledger-form><label class="scm-qq-file"><input type="file" accept=".xls,.xlsx" data-scm-qq-ledger-file aria-label="Auxiliar contable">' +
        '<span class="material-symbols-outlined" aria-hidden="true">upload_file</span><span class="scm-qq-file-text"><strong data-scm-qq-ledger-name>Elegir auxiliar contable</strong>' +
        '<small>Archivo .xls o .xlsx exportado de contabilidad</small></span><span class="scm-qq-file-cta">Buscar archivo</span></label>' +
        '<button type="submit" class="scm-cm-btn scm-qq-primary">Conciliar</button><span class="scm-cm-hint" data-scm-qq-ledger-status aria-live="polite"></span></form>' +
        '<div data-scm-qq-ledger-result></div></section>';
    }

    function kpi(label, value, note, tone) {
      return '<article class="scm-cm-kpi' + (tone ? " is-" + tone : "") + '"><span>' + esc(label) + "</span><strong>" + esc(value) + "</strong>" + (note ? "<small>" + esc(note) + "</small>" : "") + "</article>";
    }

    function kpisHtml(list) {
      var summary = state.ledger.resumen || {};
      var count = { ok: 0, diff: 0, none: 0 };
      var ledgerTotal = 0;
      var systemTotal = 0;
      list.forEach(function (group) { count[group.status] += 1; ledgerTotal += group.ledgerSum; systemTotal += group.systemSum; });
      var orphans = orphanQuotes(list);
      var orphanAdmin = orphans.reduce(function (sum, row) { return sum + (row.admin || 0); }, 0);
      return kpi("Inmuebles en el auxiliar", num(list.length), num(summary.movimientos) + " movimientos · " + day(summary.desde) + " a " + day(summary.hasta), "stock") +
        kpi("Total auxiliar", money(ledgerTotal), "Administración registrada en contabilidad") +
        kpi("Administración en el sistema", money(systemTotal), "Aprobadas de esos inmuebles con respuesta en el periodo", ledgerTotal === systemTotal ? "good" : "") +
        kpi("Cuadran", num(count.ok), "Auxiliar igual al sistema", "good") +
        kpi("Con diferencia", num(count.diff), count.none ? num(count.none) + " sin cotizaciones en el sistema" : "Revisa fecha, total o administración", count.diff || count.none ? "bad" : "") +
        kpi("Aprobadas fuera del auxiliar", num(orphans.length), orphans.length ? money(orphanAdmin) + " de inmuebles que no están en el auxiliar" : "Todas están en el auxiliar", orphans.length ? "bad" : "");
    }

    function renderLedger() {
      var holder = ledgerEl.querySelector("[data-scm-qq-ledger-result]");
      if (!holder) return;
      if (!state.ledger) { holder.innerHTML = ""; return; }
      var list = groups();
      var pending = list.filter(function (group) { return group.status !== "ok"; });
      var query = normalize(state.search.trim());
      var shown = (state.filter === "revisar" ? pending : list).filter(function (group) {
        if (!query) return true;
        return normalize([group.key, group.address].concat(group.quotes.map(function (row) { return "#" + row.id + " " + row.ticket + " " + row.destinatario; })).join(" ")).indexOf(query) !== -1;
      });
      var html = '<div class="scm-cm-kpis scm-cm-kpis-6" data-scm-qq-kpis>' + kpisHtml(list) + "</div>";
      html += '<div class="scm-qm-views scm-qq-filters">' +
        [["revisar", "Por revisar", pending.length], ["todos", "Todos", list.length]].map(function (f) {
          return '<button type="button" class="scm-qm-view' + (state.filter === f[0] ? " is-active" : "") + '" data-scm-qq-ledger-filter="' + f[0] + '">' + esc(f[1]) + " <b>" + num(f[2]) + "</b></button>";
        }).join("") +
        '<label class="scm-cm-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input type="search" data-scm-qq-ledger-search placeholder="Inmueble, cotización, caso o dirección" value="' + esc(state.search) + '"></label>' +
        '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-ledger-export>Exportar conciliación</button></div>';
      html += '<p class="scm-cm-hint scm-ql-help">Se comparan los movimientos del auxiliar con la administración (sin IVA) de las cotizaciones <strong>aprobadas</strong> con respuesta entre ' +
        esc(day((state.ledger.resumen || {}).desde)) + " y " + esc(day((state.ledger.resumen || {}).hasta)) + ". Al cambiar la administración se recalcula su IVA y, si no cambias el total, el total se ajusta solo.</p>";
      html += '<div class="scm-ql-list" data-scm-qq-ledger-list>' +
        (shown.length ? shown.slice(0, state.limit).map(groupHtml).join("") : '<div class="scm-cm-empty">' + (state.filter === "revisar" && !query ? "Todos los inmuebles cuadran." : "No hay inmuebles con ese filtro.") + "</div>") +
        "</div>";
      if (shown.length > state.limit) {
        html += '<div class="scm-cm-pager"><span>' + num(Math.min(state.limit, shown.length)) + " de " + num(shown.length) + ' inmuebles</span><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-ledger-more>Ver más</button></div>';
      }
      var orphans = orphanQuotes(list);
      if (orphans.length) {
        html += '<section class="scm-ql-prop is-diff" data-scm-qq-orphans><header><div><strong>Aprobadas en el periodo cuyo inmueble no está en el auxiliar</strong>' +
          "<small>Puede que contabilidad no las haya causado, que tengan otro inmueble o que su fecha de respuesta esté fuera del periodo.</small></div></header>" +
          quotesTable(orphans, true) + "</section>";
      }
      holder.innerHTML = html;
    }

    function groupHtml(group) {
      var status = STATUS[group.status];
      var diff = group.ledgerSum - group.systemSum;
      var movesHtml = group.moves.map(function (move) {
        return '<span class="scm-ql-move"><b>' + esc(money(move.valor)) + "</b> " + esc(day(move.fecha)) + (move.transaccion ? ' <small>Tx ' + esc(move.transaccion) + "</small>" : "") + "</span>";
      }).join("");
      return '<article class="scm-ql-prop is-' + group.status + '" data-scm-qq-prop="' + esc(group.key) + '"><header><div><strong>Inmueble ' + esc(group.key) + "</strong>" +
        (group.address ? "<small>" + esc(group.address) + "</small>" : "") + '</div><dl class="scm-ql-sums"><div><dt>Auxiliar</dt><dd>' + esc(money(group.ledgerSum)) + "</dd></div>" +
        "<div><dt>Sistema</dt><dd>" + esc(money(group.systemSum)) + "</dd></div>" +
        '<div><dt>Diferencia</dt><dd class="' + (diff ? "is-bad" : "is-good") + '">' + (diff ? (diff > 0 ? "+" : "−") + esc(money(Math.abs(diff))) : "—") + "</dd></div></dl>" +
        '<span class="scm-cm-badge ' + status[1] + '">' + esc(status[0]) + "</span></header>" +
        '<div class="scm-ql-moves"><span class="scm-ql-label">Movimientos del auxiliar</span>' + movesHtml + "</div>" +
        (group.quotes.length ? quotesTable(group.quotes, false)
          : '<p class="scm-cm-muted scm-ql-empty">No hay cotizaciones con este inmueble en el sistema (ni en la cotización ni en su caso). Búscala en el caso o confírmalo con contabilidad.</p>') +
        "</article>";
    }

    function quotesTable(rows, showProperty) {
      var range = ledgerRange();
      var matched = matchedIds();
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-qq-table scm-ql-quotes"><thead><tr><th>Cotización</th>' + (showProperty ? "<th>Inmueble</th>" : "") +
        '<th>Estado</th><th>Creada</th><th>Fecha de respuesta</th><th class="is-num">Valor total</th><th class="is-num">Administración</th><th></th></tr></thead><tbody>' +
        rows.map(function (row) { return quoteRow(row, range, matched, showProperty); }).join("") + "</tbody></table></div>";
    }

    function quoteRow(row, range, matched, showProperty) {
      var counts = inRange(row, range);
      var tone = row.estado === "Aprobada" ? "is-entregado" : row.estado === "Desaprobada" ? "is-recibido" : "is-por_recibir";
      var dateCell = row.approved && state.canEditResponse
        ? '<input type="date" class="scm-qm-date" data-ql-field="fecha" value="' + esc(row.respuesta || "") + '" data-original="' + esc(row.respuesta || "") + '" max="' + todayIso() + '"' +
          (row.fecha ? ' min="' + esc(row.fecha) + '"' : "") + ' aria-label="Fecha de respuesta de la cotización ' + esc(row.id) + '">'
        : esc(day(row.respuesta));
      var moneyInput = function (field, value, label) {
        return state.canEditQuote
          ? '<input type="text" inputmode="numeric" class="scm-qm-date scm-ql-money" data-ql-field="' + field + '" value="' + esc(num(value)) + '" data-original="' + esc(value || 0) + '" aria-label="' + esc(label) + '">'
          : esc(money(value));
      };
      var notes = [];
      if (row.approved && !row.respuesta) notes.push('<small class="scm-cm-source is-bad">Sin fecha de respuesta</small>');
      else if (row.approved && !counts) notes.push('<small class="scm-cm-source">Fuera del periodo del auxiliar</small>');
      if (matched[row.id]) notes.push('<small class="scm-cm-source is-good">En el auxiliar</small>');
      if (row.respuesta_fuente === "caso") notes.push('<small class="scm-cm-source">Fecha tomada del caso</small>');
      return '<tr class="scm-maintenance-quote-row' + (counts ? "" : " is-muted") + '" data-ql-quote="' + esc(row.id) + '" data-cotizacion-id="' + esc(row.id) + '" data-ticket="' + esc(row.ticket) + '">' +
        "<td><strong>#" + esc(row.id) + "</strong><small class=\"scm-cm-source\">" + (row.ticket ? "Caso #" + esc(row.ticket) : "Sin caso") + "</small></td>" +
        (showProperty ? "<td>" + esc(row.inmueble || "—") + "</td>" : "") +
        '<td><span class="scm-cm-badge ' + tone + '">' + esc(row.estado) + "</span></td><td>" + esc(day(row.fecha)) + "</td>" +
        "<td>" + dateCell + notes.join("") + "</td>" +
        '<td class="is-num">' + moneyInput("total", row.total, "Valor total de la cotización " + row.id) + "</td>" +
        '<td class="is-num">' + moneyInput("administracion", row.admin, "Administración de la cotización " + row.id) + '<small class="scm-qm-row-msg" aria-live="polite"></small></td>' +
        '<td class="is-actions">' + (state.canEditQuote || (row.approved && state.canEditResponse)
          ? '<button type="button" class="scm-cm-btn scm-qq-primary" data-ql-save disabled>Guardar</button> ' : "") +
        '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-maintenance-quote-open-case data-cotizacion-id="' + esc(row.id) +
        '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">Ver caso</button></td></tr>';
    }

    // Cambios pendientes de una fila: solo los campos que difieren del valor guardado.
    function rowChanges(tr) {
      var changes = {};
      tr.querySelectorAll("[data-ql-field]").forEach(function (input) {
        var field = input.getAttribute("data-ql-field");
        var original = input.getAttribute("data-original");
        if (field === "fecha") {
          if (input.value && input.value !== original) changes.fecha_respuesta = input.value;
        } else {
          var value = digits(input.value);
          if (value !== null && value !== Number(original)) changes[field] = value;
        }
      });
      return changes;
    }

    function refreshGroup(property) {
      var card = ledgerEl.querySelector('[data-scm-qq-prop="' + (window.CSS && CSS.escape ? CSS.escape(property) : property) + '"]');
      if (card) {
        var moves = ((state.ledger && state.ledger.filas) || []).filter(function (move) { return String(move.inmueble) === property; });
        card.outerHTML = groupHtml(buildGroup(property, moves));
      }
      var kpis = ledgerEl.querySelector("[data-scm-qq-kpis]");
      if (kpis) kpis.innerHTML = kpisHtml(groups());
    }

    function saveRow(button) {
      var tr = button.closest("[data-ql-quote]");
      var id = Number(tr.getAttribute("data-ql-quote"));
      var changes = rowChanges(tr);
      var message = tr.querySelector(".scm-qm-row-msg");
      var dateInput = tr.querySelector('[data-ql-field="fecha"]');
      if (dateInput && changes.fecha_respuesta && !dateInput.checkValidity()) { dateInput.reportValidity(); return; }
      if (!Object.keys(changes).length) return;
      state.saving = true;
      button.disabled = true;
      button.textContent = "Guardando…";
      message.textContent = "";
      message.className = "scm-qm-row-msg";
      post(actions.quote_ledger_update, Object.assign({ cotizacion_id: id }, changes))
        .then(function (result) {
          var row = state.rows.filter(function (item) { return item.id === id; })[0];
          if (row) {
            if (result.respuesta) { row.respuesta = result.respuesta; row.respuesta_fuente = "cotizacion"; row.revisada = 1; }
            if (typeof result.total === "number") row.total = result.total;
            if (typeof result.admin === "number") row.admin = result.admin;
          }
          // El informe de Cotizaciones usa estas mismas filas: se recalcula sin volver a consultar.
          module.dispatchEvent(new CustomEvent("scm:quote-rows-changed"));
          var card = tr.closest("[data-scm-qq-prop]");
          if (card) {
            refreshGroup(card.getAttribute("data-scm-qq-prop"));
          } else {
            renderLedger();
          }
        })
        .catch(function (error) {
          button.disabled = false;
          button.textContent = "Guardar";
          message.textContent = error.message;
          message.className = "scm-qm-row-msg is-error";
        })
        .then(function () { state.saving = false; });
    }

    function exportLedger() {
      var range = ledgerRange();
      var lines = [["Inmueble", "Total auxiliar", "Administracion sistema (periodo)", "Diferencia", "Resultado", "Cotizacion", "Caso", "Estado cotizacion", "Creada", "Fecha respuesta", "En el periodo", "Valor total", "Administracion"]];
      var list = groups();
      list.forEach(function (group) {
        var base = [group.key, group.ledgerSum, group.systemSum, group.ledgerSum - group.systemSum, STATUS[group.status][0]];
        if (!group.quotes.length) { lines.push(base); return; }
        group.quotes.forEach(function (row) {
          lines.push(base.concat([row.id, row.ticket, row.estado, row.fecha || "", row.respuesta || "", inRange(row, range) ? "Si" : "No", row.total, row.admin]));
        });
      });
      orphanQuotes(list).forEach(function (row) {
        lines.push([row.inmueble, "", "", "", "Aprobada sin inmueble en el auxiliar", row.id, row.ticket, row.estado, row.fecha || "", row.respuesta || "", "Si", row.total, row.admin]);
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

    // ---- Eventos -------------------------------------------------------------------------------

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

    var searchTimer = 0;
    module.addEventListener("input", function (event) {
      var search = event.target.closest("[data-scm-qq-ledger-search]");
      if (search) {
        var value = search.value;
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
          state.search = value;
          state.limit = PAGE_SIZE;
          renderLedger();
          var again = ledgerEl.querySelector("[data-scm-qq-ledger-search]");
          if (again) { again.focus(); again.setSelectionRange(again.value.length, again.value.length); }
        }, 200);
        return;
      }
      var field = event.target.closest("[data-ql-field]");
      if (!field) return;
      var tr = field.closest("[data-ql-quote]");
      var save = tr && tr.querySelector("[data-ql-save]");
      if (save) save.disabled = state.saving || !Object.keys(rowChanges(tr)).length;
      field.classList.toggle("is-changed", Object.prototype.hasOwnProperty.call(rowChanges(tr), field.getAttribute("data-ql-field") === "fecha" ? "fecha_respuesta" : field.getAttribute("data-ql-field")));
    });

    // Los valores se muestran con separador de miles al salir del campo.
    module.addEventListener("focusout", function (event) {
      var input = event.target.closest(".scm-ql-money");
      if (!input) return;
      var value = digits(input.value);
      if (value !== null) input.value = num(value);
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
          state.filter = "revisar";
          state.search = "";
          state.limit = PAGE_SIZE;
          status.textContent = file.name;
          renderLedger();
        })
        .catch(function (error) { status.textContent = error.message; });
    });

    module.addEventListener("click", function (event) {
      var target = event.target;
      var filter = target.closest("[data-scm-qq-ledger-filter]");
      if (filter) { state.filter = filter.getAttribute("data-scm-qq-ledger-filter"); state.limit = PAGE_SIZE; renderLedger(); return; }
      if (target.closest("[data-scm-qq-ledger-more]")) { state.limit += PAGE_SIZE; renderLedger(); return; }
      if (target.closest("[data-scm-qq-ledger-export]")) { exportLedger(); return; }
      var save = target.closest("[data-ql-save]");
      if (save && !save.disabled && !state.saving) saveRow(save);
    });

    module.addEventListener("keydown", function (event) {
      if (event.key !== "Enter" || !event.target.closest("[data-ql-field]")) return;
      event.preventDefault();
      var save = event.target.closest("[data-ql-quote]").querySelector("[data-ql-save]");
      if (save && !save.disabled && !state.saving) saveRow(save);
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
