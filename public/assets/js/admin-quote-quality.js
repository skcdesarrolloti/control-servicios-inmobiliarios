(function () {
  "use strict";

  // Métricas › Cotizaciones › pestaña "Conciliar con el auxiliar contable".
  // Al subir el auxiliar (AUI) se agrupan sus movimientos por número de inmueble y, por cada inmueble,
  // se listan TODAS las cotizaciones del sistema con ese inmueble (en la cotización o en su caso),
  // con fecha de respuesta, valor total y administración editables.
  // Segunda vista, "Todas las cotizaciones": todas las cotizaciones del sistema por mes (creación o
  // respuesta), filtrables por contrato e inmueble, con las mismas correcciones; no necesita el auxiliar.
  // Usa las filas que ya cargó admin-quote-metrics.js (evento scm:quote-data); al guardar actualiza esas
  // mismas filas y avisa con scm:quote-rows-changed para que el informe se recalcule sin recargar.
  var MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var PAGE_SIZE = 40;
  var numberFormat = new Intl.NumberFormat("es-CO");
  var moneyFormat = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
  var STATES = ["Esperando respuesta", "Aprobada", "Desaprobada"];
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
    var currentMonth = module.getAttribute("data-current-month") || todayIso().slice(0, 7);
    var state = {
      rows: [], canEditResponse: false, canEditQuote: false, ledger: null, filter: "revisar", search: "", limit: PAGE_SIZE, saving: false, sub: "auxiliar",
      all: { month: currentMonth, by: "fecha", contrato: "", inmueble: "", limit: PAGE_SIZE },
    };

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

    // ---- Datos: agrupación y emparejamiento por inmueble ----------------------------------------

    // Periodo del auxiliar en meses y ventana de cotizaciones a comparar: desde enero del año
    // anterior al inicio del auxiliar (auxiliar de 2026 → cotizaciones de 2025 y 2026).
    function ledgerRange() {
      var summary = (state.ledger && state.ledger.resumen) || {};
      var from = String(summary.desde || "").slice(0, 7);
      return { from: from, to: String(summary.hasta || "").slice(0, 7), sinceYear: from ? Number(from.slice(0, 4)) - 1 : 0 };
    }
    function inRange(row, range) {
      if (!row.approved || !row.respuesta) return false;
      var ym = row.respuesta.slice(0, 7);
      return ym >= range.from && ym <= range.to;
    }
    function inWindow(row, range) {
      var date = row.fecha || row.respuesta || "";
      return !!date && Number(date.slice(0, 4)) >= range.sinceYear;
    }
    function quotesOf(property, range) {
      return state.rows.filter(function (row) {
        return (String(row.inmueble) === property || String(row.inmueble_caso || "") === property) && inWindow(row, range);
      }).sort(function (a, b) { return (b.fecha || "") < (a.fecha || "") ? -1 : (b.fecha || "") > (a.fecha || "") ? 1 : b.id - a.id; });
    }
    function distance(row, move) {
      return Math.abs(Date.parse(row.respuesta || row.fecha || move.fecha) - Date.parse(move.fecha));
    }

    // Empareja cada movimiento del auxiliar con una cotización aprobada del inmueble:
    // 1. mismo valor de administración (la de fecha más cercana);  2. varios movimientos que suman
    // la administración de una cotización (cuotas);  3. mismo valor que una cotización ya emparejada
    // (posible cobro repetido);  4. la aprobada libre de fecha más cercana (diferencia de valor);
    // 5. sin cotización.
    function pairGroup(moves, quotes) {
      var candidates = quotes.filter(function (row) { return row.approved; });
      var used = {};
      var link = moves.map(function () { return null; });
      function free(fn) { return candidates.filter(function (row) { return !used[row.id] && fn(row); }); }
      function nearest(list, move) { return list.sort(function (a, b) { return distance(a, move) - distance(b, move); })[0] || null; }
      moves.forEach(function (move, index) {
        var quote = nearest(free(function (row) { return (row.admin || 0) === move.valor; }), move);
        if (quote) { used[quote.id] = true; link[index] = { kind: "exacto", quote: quote }; }
      });
      var left = function () { return moves.map(function (move, index) { return index; }).filter(function (index) { return !link[index]; }); };
      var pending = left();
      var sets = pending.length > 1 ? [pending] : [];
      for (var i = 0; i < pending.length; i++) for (var j = i + 1; j < pending.length; j++) if (pending.length > 2) sets.push([pending[i], pending[j]]);
      sets.some(function (set) {
        var sum = set.reduce(function (total, index) { return total + moves[index].valor; }, 0);
        var quote = nearest(free(function (row) { return (row.admin || 0) === sum; }), moves[set[0]]);
        if (!quote) return false;
        used[quote.id] = true;
        set.forEach(function (index) { link[index] = { kind: "cuotas", quote: quote }; });
        return true;
      });
      left().forEach(function (index) {
        var twin = candidates.filter(function (row) { return used[row.id] && (row.admin || 0) === moves[index].valor; })[0];
        if (twin) link[index] = { kind: "repetido", quote: twin };
      });
      left().forEach(function (index) {
        var quote = nearest(free(function () { return true; }), moves[index]);
        if (quote) { used[quote.id] = true; link[index] = { kind: "diferencia", quote: quote }; }
      });
      left().forEach(function (index) { link[index] = { kind: "sin_cotizacion", quote: null }; });
      return link;
    }

    function buildGroup(property, moves) {
      var range = ledgerRange();
      var quotes = quotesOf(property, range);
      var link = pairGroup(moves, quotes);
      var byQuote = {};
      moves.forEach(function (move, index) {
        var pair = link[index];
        if (pair.quote && pair.kind !== "repetido") (byQuote[pair.quote.id] = byQuote[pair.quote.id] || []).push(move);
      });
      var ledgerSum = moves.reduce(function (sum, move) { return sum + move.valor; }, 0);
      // Sistema: administración de las cotizaciones emparejadas y de las aprobadas del periodo que no están en el auxiliar.
      var systemSum = quotes.filter(function (row) { return byQuote[row.id] || inRange(row, range); }).reduce(function (sum, row) { return sum + (row.admin || 0); }, 0);
      var clean = link.every(function (pair) { return pair.kind === "exacto" || pair.kind === "cuotas"; }) &&
        quotes.every(function (row) { return byQuote[row.id] || !inRange(row, range); });
      var address = "";
      quotes.some(function (row) { address = row.direccion || ""; return !!address; });
      return {
        key: property, moves: moves, link: link, byQuote: byQuote, quotes: quotes, ledgerSum: ledgerSum, systemSum: systemSum,
        status: !quotes.length ? "none" : clean ? "ok" : "diff", address: address,
      };
    }

    function groups() {
      var byProperty = {};
      var order = [];
      ((state.ledger && state.ledger.filas) || []).forEach(function (move) {
        var key = String(move.inmueble);
        if (!byProperty[key]) { byProperty[key] = []; order.push(key); }
        byProperty[key].push(move);
      });
      return order.map(function (key) {
        return buildGroup(key, byProperty[key].slice().sort(function (a, b) { return a.fecha < b.fecha ? -1 : a.fecha > b.fecha ? 1 : 0; }));
      }).sort(function (a, b) { return Math.abs(b.ledgerSum - b.systemSum) - Math.abs(a.ledgerSum - a.systemSum) || a.key.localeCompare(b.key); });
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

    // Resultado de una cotización dentro de su inmueble.
    function quoteResult(row, group, range) {
      var moves = (group && group.byQuote[row.id]) || [];
      if (moves.length) {
        var sum = moves.reduce(function (total, move) { return total + move.valor; }, 0);
        var diff = sum - (row.admin || 0);
        if (!diff) return { text: moves.length > 1 ? "Cuadra en " + moves.length + " cuotas" : "Cuadra", tone: "is-entregado" };
        return { text: "Diferencia " + (diff > 0 ? "+" : "−") + money(Math.abs(diff)), tone: "is-recibido", hint: "El auxiliar registró " + money(sum) };
      }
      if (inRange(row, range)) return { text: "No está en el auxiliar", tone: "is-recibido", hint: "Aprobada en el periodo sin movimiento contable" };
      if (!row.approved) return { text: "No cuenta", tone: "is-muted", hint: row.estado };
      if (!row.respuesta) return { text: "Sin fecha de respuesta", tone: "is-por_recibir" };
      return { text: "No cuenta", tone: "is-muted", hint: "Respuesta fuera del periodo del auxiliar" };
    }

    // ---- Vista ---------------------------------------------------------------------------------

    function renderLedgerShell() {
      ledgerEl.innerHTML = '<section class="scm-cm-card is-wide"><header><h2>Conciliar con el auxiliar contable</h2>' +
        '<div class="scm-qm-views" role="tablist" aria-label="Vista de conciliación">' +
        [["auxiliar", "Por auxiliar contable"], ["todas", "Todas las cotizaciones"]].map(function (sub) {
          return '<button type="button" role="tab" class="scm-qm-view' + (state.sub === sub[0] ? " is-active" : "") + '" aria-selected="' + (state.sub === sub[0]) + '" data-scm-qq-sub-target="' + sub[0] + '">' + esc(sub[1]) + "</button>";
        }).join("") + "</div></header>" +
        '<div data-scm-qq-sub="auxiliar"' + (state.sub === "auxiliar" ? "" : " hidden") + '>' +
        '<p class="scm-cm-hint">Sube el auxiliar de administración (AUI) en .xls o .xlsx: por cada número de inmueble verás qué movimiento corresponde a cada cotización y podrás corregir fecha, total y administración.</p>' +
        '<form class="scm-qq-upload" data-scm-qq-ledger-form><label class="scm-qq-file"><input type="file" accept=".xls,.xlsx" data-scm-qq-ledger-file aria-label="Auxiliar contable">' +
        '<span class="material-symbols-outlined" aria-hidden="true">upload_file</span><span class="scm-qq-file-text"><strong data-scm-qq-ledger-name>Elegir auxiliar contable</strong>' +
        '<small>Archivo .xls o .xlsx exportado de contabilidad</small></span><span class="scm-qq-file-cta">Buscar archivo</span></label>' +
        '<button type="submit" class="scm-cm-btn scm-qq-primary">Conciliar</button><span class="scm-cm-hint" data-scm-qq-ledger-status aria-live="polite"></span></form>' +
        '<div data-scm-qq-ledger-result></div></div>' +
        '<div data-scm-qq-sub="todas"' + (state.sub === "todas" ? "" : " hidden") + '>' +
        '<form class="scm-cm-filters scm-ql-all-filters" onsubmit="return false;">' +
        '<label class="scm-cm-field"><span>Mes</span><input type="month" data-scm-qq-all-filter="month" value="' + esc(state.all.month) + '" max="' + esc(currentMonth) + '"></label>' +
        '<label class="scm-cm-field"><span>Por fecha de</span><select data-scm-qq-all-filter="by"><option value="fecha">Creación</option><option value="respuesta">Respuesta (aprobadas)</option></select></label>' +
        '<label class="scm-cm-field"><span>Contrato</span><input type="search" data-scm-qq-all-filter="contrato" placeholder="Número de contrato"></label>' +
        '<label class="scm-cm-field"><span>Inmueble</span><input type="search" inputmode="numeric" data-scm-qq-all-filter="inmueble" placeholder="Número de inmueble"></label>' +
        '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-all-clear>Todos los meses</button></form>' +
        '<p class="scm-cm-hint scm-ql-help">Sin mes se listan todas las cotizaciones del filtro. Al cambiar la administración se recalcula su IVA y, si no cambias el total, el total se ajusta solo.</p>' +
        '<div data-scm-qq-all></div></div></section>';
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
        kpi("Administración en el sistema", money(systemTotal), "Cotizaciones emparejadas y aprobadas del periodo", ledgerTotal === systemTotal ? "good" : "") +
        kpi("Cuadran", num(count.ok), "Cada movimiento tiene su cotización", "good") +
        kpi("Por revisar", num(count.diff + count.none), count.none ? num(count.none) + " sin cotizaciones en el sistema" : "Diferencias, repetidos o faltantes", count.diff || count.none ? "bad" : "") +
        kpi("Aprobadas fuera del auxiliar", num(orphans.length), orphans.length ? money(orphanAdmin) + " de inmuebles que no están en el auxiliar" : "Todas están en el auxiliar", orphans.length ? "bad" : "");
    }

    function renderLedger() {
      var holder = ledgerEl.querySelector("[data-scm-qq-ledger-result]");
      if (!holder) return;
      if (!state.ledger) { holder.innerHTML = ""; return; }
      var range = ledgerRange();
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
      html += '<p class="scm-cm-hint scm-ql-help">Se comparan las cotizaciones de ' + esc(range.sinceYear) + " en adelante. Cada fila muestra el movimiento del auxiliar que le corresponde " +
        "(mismo valor de administración, o cuotas que lo suman). Cuenta en el periodo la administración sin IVA de las <strong>aprobadas</strong> con respuesta entre " +
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
          quotesTable(null, orphans, true) + "</section>";
      }
      holder.innerHTML = html;
    }

    function groupHtml(group) {
      var status = STATUS[group.status];
      var diff = group.ledgerSum - group.systemSum;
      return '<article class="scm-ql-prop is-' + group.status + '" data-scm-qq-prop="' + esc(group.key) + '"><header><div><strong>Inmueble ' + esc(group.key) + "</strong>" +
        (group.address ? "<small>" + esc(group.address) + "</small>" : "") + '</div><dl class="scm-ql-sums"><div><dt>Auxiliar</dt><dd>' + esc(money(group.ledgerSum)) + "</dd></div>" +
        "<div><dt>Sistema</dt><dd>" + esc(money(group.systemSum)) + "</dd></div>" +
        '<div><dt>Diferencia</dt><dd class="' + (diff ? "is-bad" : "is-good") + '">' + (diff ? (diff > 0 ? "+" : "−") + esc(money(Math.abs(diff))) : "—") + "</dd></div></dl>" +
        '<span class="scm-cm-badge ' + status[1] + '">' + esc(status[0]) + "</span></header>" +
        quotesTable(group, group.quotes, false) + "</article>";
    }

    function moveChip(move, extra) {
      return '<span class="scm-ql-move"><b>' + esc(money(move.valor)) + "</b> " + esc(day(move.fecha)) + (move.transaccion ? " <small>Tx " + esc(move.transaccion) + "</small>" : "") + (extra || "") + "</span>";
    }

    // Una sola tabla por inmueble: cada cotización con su(s) movimiento(s) del auxiliar al lado, y al final
    // los movimientos que no se pudieron emparejar con ninguna cotización.
    function quotesTable(group, rows, showProperty) {
      var range = ledgerRange();
      var unpaired = group ? group.moves.map(function (move, index) { return { move: move, pair: group.link[index] }; })
        .filter(function (item) { return item.pair.kind === "repetido" || item.pair.kind === "sin_cotizacion"; }) : [];
      var columns = showProperty ? 10 : 9;
      var body = rows.map(function (row) { return quoteRow(row, group, range, showProperty); }).join("");
      body += unpaired.map(function (item) {
        var repeated = item.pair.kind === "repetido";
        return '<tr class="scm-ql-orphan-move"><td colspan="' + (columns - 3) + '"><span class="scm-cm-muted">Movimiento del auxiliar sin cotización</span></td><td>' + moveChip(item.move) + "</td>" +
          '<td><span class="scm-cm-badge ' + (repeated ? "is-por_recibir" : "is-recibido") + '">' + (repeated ? "Repite el valor de #" + esc(item.pair.quote.id) : "Sin cotización") + "</span>" +
          '<small class="scm-cm-source">' + (repeated ? "¿Cobro repetido o cuota? Confírmalo con contabilidad" : "No hay cotización con ese valor; búscala en el caso") + "</small></td><td></td></tr>";
      }).join("");
      if (!rows.length && !unpaired.length) body = '<tr><td colspan="' + columns + '" class="scm-cm-muted">Sin cotizaciones.</td></tr>';
      if (!rows.length && group) body = '<tr><td colspan="' + columns + '" class="scm-cm-muted">No hay cotizaciones de ' + esc(range.sinceYear) + " en adelante con este inmueble (ni en la cotización ni en su caso).</td></tr>" + body;
      return '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-qq-table scm-ql-quotes"><thead><tr><th>Cotización</th>' + (showProperty ? "<th>Inmueble</th>" : "") +
        '<th>Estado</th><th>Creada</th><th>Fecha de respuesta</th><th class="is-num">Valor total</th><th class="is-num">Administración</th><th>Auxiliar contable</th><th>Resultado</th><th></th></tr></thead><tbody>' +
        body + "</tbody></table></div>";
    }

    function quoteRow(row, group, range, showProperty) {
      var result = quoteResult(row, group, range);
      var moves = (group && group.byQuote[row.id]) || [];
      return '<tr class="scm-maintenance-quote-row' + (result.tone === "is-muted" ? " is-muted" : "") + '" data-ql-quote="' + esc(row.id) + '" data-cotizacion-id="' + esc(row.id) + '" data-ticket="' + esc(row.ticket) + '">' +
        "<td><strong>#" + esc(row.id) + '</strong><small class="scm-cm-source">' + (row.ticket ? "Caso #" + esc(row.ticket) : "Sin caso") + "</small></td>" +
        (showProperty ? "<td>" + esc(row.inmueble || "—") + "</td>" : "") +
        "<td>" + stateSelect(row) + "</td><td>" + createdInput(row) + "</td>" +
        "<td>" + dateInput(row) + "</td>" +
        '<td class="is-num">' + moneyInput("total", row.total, "Valor total de la cotización " + row.id) + "</td>" +
        '<td class="is-num">' + moneyInput("administracion", row.admin, "Administración de la cotización " + row.id) + "</td>" +
        "<td>" + (moves.length ? moves.map(function (move) { return moveChip(move); }).join("") : '<span class="scm-cm-muted">—</span>') + "</td>" +
        '<td><span class="scm-cm-badge ' + result.tone + '">' + esc(result.text) + "</span>" + (result.hint ? '<small class="scm-cm-source">' + esc(result.hint) + "</small>" : "") +
        '<small class="scm-qm-row-msg" aria-live="polite"></small></td>' +
        '<td class="is-actions">' + actionButtons(row) + "</td></tr>";
    }

    // ---- Vista "Todas las cotizaciones" ---------------------------------------------------------

    function allRows() {
      var f = state.all;
      var contrato = normalize(f.contrato.trim());
      var inmueble = f.inmueble.trim();
      return state.rows.filter(function (row) {
        var date = f.by === "respuesta" ? (row.approved ? row.respuesta : "") : row.fecha;
        if (f.month && (!date || date.slice(0, 7) !== f.month)) return false;
        if (contrato && normalize(row.contrato).indexOf(contrato) === -1) return false;
        if (inmueble && String(row.inmueble).indexOf(inmueble) === -1 && String(row.inmueble_caso || "").indexOf(inmueble) === -1) return false;
        return true;
      }).sort(function (a, b) {
        var key = f.by === "respuesta" ? "respuesta" : "fecha";
        return (b[key] || "") < (a[key] || "") ? -1 : (b[key] || "") > (a[key] || "") ? 1 : b.id - a.id;
      });
    }

    function renderAll() {
      var holder = ledgerEl.querySelector("[data-scm-qq-all]");
      if (!holder) return;
      var rows = allRows();
      var sum = rows.reduce(function (acc, row) { acc.total += row.total || 0; acc.admin += row.admin || 0; return acc; }, { total: 0, admin: 0 });
      var shown = rows.slice(0, state.all.limit);
      var label = state.all.month ? (state.all.by === "respuesta" ? "aprobadas en " : "creadas en ") + day(state.all.month + "-01").replace(/^1 /, "") : "todas las fechas";
      holder.innerHTML = '<div class="scm-cm-table-wrap"><table class="scm-cm-table scm-qq-table scm-ql-quotes"><thead><tr><th>Cotización</th><th>Contrato</th><th>Inmueble</th>' +
        '<th>Estado</th><th>Creada</th><th>Fecha de respuesta</th><th class="is-num">Valor total</th><th class="is-num">Administración</th><th></th></tr></thead><tbody>' +
        (shown.length ? shown.map(allRow).join("") : '<tr><td colspan="9" class="scm-cm-muted">No hay cotizaciones con estos filtros.</td></tr>') +
        '</tbody><tfoot><tr><td colspan="6">' + esc(num(rows.length) + (rows.length === 1 ? " cotización · " : " cotizaciones · ") + label) +
        '</td><td class="is-num">' + esc(money(sum.total)) + '</td><td class="is-num is-total">' + esc(money(sum.admin)) + "</td><td></td></tr></tfoot></table></div>" +
        (rows.length > shown.length ? '<div class="scm-cm-pager"><span>' + num(shown.length) + " de " + num(rows.length) + '</span><button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-qq-all-more>Ver más</button></div>' : "");
    }

    function allRow(row) {
      var property = row.inmueble && !/^no asignado$/i.test(row.inmueble) ? row.inmueble : row.inmueble_caso ? row.inmueble_caso + " (del caso)" : "—";
      return '<tr class="scm-maintenance-quote-row" data-ql-quote="' + esc(row.id) + '" data-cotizacion-id="' + esc(row.id) + '" data-ticket="' + esc(row.ticket) + '">' +
        "<td><strong>#" + esc(row.id) + '</strong><small class="scm-cm-source">' + (row.ticket ? "Caso #" + esc(row.ticket) : "Sin caso") + "</small></td>" +
        "<td>" + esc(row.contrato || "—") + "</td><td>" + esc(property) + '<small class="scm-cm-source">' + esc(row.direccion || "") + "</small></td>" +
        "<td>" + stateSelect(row) + "</td><td>" + createdInput(row) + "</td><td>" + dateInput(row) + "</td>" +
        '<td class="is-num">' + moneyInput("total", row.total, "Valor total de la cotización " + row.id) + "</td>" +
        '<td class="is-num">' + moneyInput("administracion", row.admin, "Administración de la cotización " + row.id) + '<small class="scm-qm-row-msg" aria-live="polite"></small></td>' +
        '<td class="is-actions">' + actionButtons(row) + "</td></tr>";
    }

    // Estado (respuesta) de la cotización. Cambiarlo es una corrección de datos: no avisa al cliente.
    function stateSelect(row) {
      var tone = row.estado === "Aprobada" ? "is-entregado" : row.estado === "Desaprobada" ? "is-recibido" : "is-por_recibir";
      if (!state.canEditResponse) return '<span class="scm-cm-badge ' + tone + '">' + esc(row.estado) + "</span>";
      var options = STATES.indexOf(row.estado) === -1 ? [row.estado].concat(STATES) : STATES;
      return '<select class="scm-qm-date scm-ql-state ' + tone + '" data-ql-field="estado" data-original="' + esc(row.estado) + '" aria-label="Estado de la cotización ' + esc(row.id) + '">' +
        options.map(function (value) {
          return '<option value="' + esc(value) + '"' + (value === row.estado ? " selected" : "") + (STATES.indexOf(value) === -1 ? " disabled" : "") + ">" + esc(value) + "</option>";
        }).join("") + "</select>";
    }
    function createdInput(row) {
      return state.canEditQuote
        ? '<input type="date" class="scm-qm-date" data-ql-field="creada" value="' + esc(row.fecha || "") + '" data-original="' + esc(row.fecha || "") + '" max="' + todayIso() + '" aria-label="Fecha de creación de la cotización ' + esc(row.id) + '">'
        : esc(day(row.fecha));
    }
    // La fecha de respuesta aplica a aprobadas y desaprobadas; en "Esperando respuesta" queda vacía.
    function dateInput(row) {
      var waiting = row.estado === "Esperando respuesta" || row.estado === "Sin estado";
      return state.canEditResponse
        ? '<input type="date" class="scm-qm-date" data-ql-field="fecha" value="' + esc(row.respuesta || "") + '" data-original="' + esc(row.respuesta || "") + '" max="' + todayIso() + '"' +
          (waiting ? " disabled" : "") + ' aria-label="Fecha de respuesta de la cotización ' + esc(row.id) + '">' +
          (row.respuesta_fuente === "caso" ? '<small class="scm-cm-source">Fecha tomada del caso</small>' : "")
        : esc(day(row.respuesta));
    }
    function moneyInput(field, value, label) {
      return state.canEditQuote
        ? '<input type="text" inputmode="numeric" class="scm-qm-date scm-ql-money" data-ql-field="' + field + '" value="' + esc(num(value)) + '" data-original="' + esc(value || 0) + '" aria-label="' + esc(label) + '">'
        : esc(money(value));
    }
    function actionButtons(row) {
      return (state.canEditQuote || state.canEditResponse ? '<button type="button" class="scm-cm-btn scm-qq-primary" data-ql-save disabled>Guardar</button> ' : "") +
        '<button type="button" class="scm-cm-btn scm-cm-btn-ghost" data-scm-maintenance-quote-open-case data-cotizacion-id="' + esc(row.id) +
        '" data-ticket="' + esc(row.ticket) + '" data-contract="' + esc(row.contrato) + '">Ver caso</button>';
    }

    function setSub(sub) {
      state.sub = sub;
      ledgerEl.querySelectorAll("[data-scm-qq-sub-target]").forEach(function (button) {
        var active = button.getAttribute("data-scm-qq-sub-target") === sub;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
      });
      ledgerEl.querySelectorAll("[data-scm-qq-sub]").forEach(function (panel) { panel.hidden = panel.getAttribute("data-scm-qq-sub") !== sub; });
      if (sub === "todas") renderAll();
    }

    // Cambios pendientes de una fila: solo los campos que difieren del valor guardado.
    function rowChanges(tr) {
      var changes = {};
      tr.querySelectorAll("[data-ql-field]").forEach(function (input) {
        var field = input.getAttribute("data-ql-field");
        var original = input.getAttribute("data-original");
        if (field === "estado") {
          if (input.value !== original) changes.estado = input.value;
        } else if (field === "creada") {
          if (input.value && input.value !== original) changes.fecha_creacion = input.value;
        } else if (field === "fecha") {
          if (!input.disabled && input.value && input.value !== original) changes.fecha_respuesta = input.value;
        } else {
          var value = digits(input.value);
          if (value !== null && value !== Number(original)) changes[field] = value;
        }
      });
      // Al responder (aprobada/desaprobada) se envía la fecha visible aunque venga del caso.
      var responseInput = tr.querySelector('[data-ql-field="fecha"]');
      if (changes.estado && changes.estado !== "Esperando respuesta" && responseInput && responseInput.value) changes.fecha_respuesta = responseInput.value;
      return changes;
    }

    function changeKey(field) {
      return { fecha: "fecha_respuesta", creada: "fecha_creacion" }[field] || field;
    }

    function refreshGroup(property) {
      var card = ledgerEl.querySelector('[data-scm-qq-prop="' + (window.CSS && CSS.escape ? CSS.escape(property) : property) + '"]');
      if (card) {
        var moves = ((state.ledger && state.ledger.filas) || []).filter(function (move) { return String(move.inmueble) === property; })
          .sort(function (a, b) { return a.fecha < b.fecha ? -1 : a.fecha > b.fecha ? 1 : 0; });
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
      if (changes.estado && tr.dataset.qlConfirm !== "1") {
        // Confirmación en la misma fila: el cambio de estado mueve la administración entre meses.
        var before = tr.querySelector('[data-ql-field="estado"]').getAttribute("data-original");
        tr.dataset.qlConfirm = "1";
        button.textContent = "Confirmar";
        message.textContent = "Cambia el estado de " + before + " a " + changes.estado + ". No se avisa al cliente.";
        message.className = "scm-qm-row-msg is-warn";
        return;
      }
      delete tr.dataset.qlConfirm;
      state.saving = true;
      button.disabled = true;
      button.textContent = "Guardando…";
      message.textContent = "";
      message.className = "scm-qm-row-msg";
      post(actions.quote_ledger_update, Object.assign({ cotizacion_id: id }, changes))
        .then(function (result) {
          var row = state.rows.filter(function (item) { return item.id === id; })[0];
          if (row) {
            if (result.estado) { row.estado = result.estado; row.approved = result.estado === "Aprobada"; }
            if (Object.prototype.hasOwnProperty.call(result, "fecha") && result.fecha) row.fecha = result.fecha;
            if (Object.prototype.hasOwnProperty.call(result, "respuesta")) {
              row.respuesta = result.respuesta;
              row.respuesta_fuente = result.respuesta ? "cotizacion" : "";
              row.revisada = result.respuesta ? 1 : 0;
            }
            if (typeof result.total === "number") row.total = result.total;
            if (typeof result.admin === "number") row.admin = result.admin;
          }
          // El informe de Cotizaciones usa estas mismas filas: se recalcula sin volver a consultar.
          module.dispatchEvent(new CustomEvent("scm:quote-rows-changed"));
          var card = tr.closest("[data-scm-qq-prop]");
          if (tr.closest("[data-scm-qq-all]")) {
            // Vista "Todas": se repinta conservando los filtros; la conciliación se recalcula al volver a ella.
            renderAll();
            if (state.ledger) renderLedger();
            var saved = ledgerEl.querySelector('[data-scm-qq-all] [data-ql-quote="' + id + '"] .scm-qm-row-msg');
            if (saved) { saved.textContent = "Guardado"; saved.className = "scm-qm-row-msg is-ok"; }
          } else if (card) {
            refreshGroup(card.getAttribute("data-scm-qq-prop"));
            renderAll();
          } else {
            renderLedger();
            renderAll();
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

    // Una línea por cotización con su(s) movimiento(s) emparejado(s) y una por cada movimiento sin cotización.
    function exportLedger() {
      var range = ledgerRange();
      var lines = [["Inmueble", "Total auxiliar inmueble", "Administracion sistema inmueble", "Cotizacion", "Caso", "Estado cotizacion", "Creada", "Fecha respuesta",
        "Valor total", "Administracion", "Movimientos auxiliar", "Valor auxiliar emparejado", "Resultado"]];
      var list = groups();
      list.forEach(function (group) {
        var base = [group.key, group.ledgerSum, group.systemSum];
        group.quotes.forEach(function (row) {
          var moves = group.byQuote[row.id] || [];
          lines.push(base.concat([row.id, row.ticket, row.estado, row.fecha || "", row.respuesta || "", row.total, row.admin,
            moves.map(function (move) { return move.fecha + " " + move.valor + (move.transaccion ? " Tx " + move.transaccion : ""); }).join(" | "),
            moves.length ? moves.reduce(function (sum, move) { return sum + move.valor; }, 0) : "", quoteResult(row, group, range).text]));
        });
        group.moves.forEach(function (move, index) {
          var pair = group.link[index];
          if (pair.kind !== "repetido" && pair.kind !== "sin_cotizacion") return;
          lines.push(base.concat(["", "", "", "", "", "", "", move.fecha + " " + move.valor + (move.transaccion ? " Tx " + move.transaccion : ""), move.valor,
            pair.kind === "repetido" ? "Repite el valor de #" + pair.quote.id : "Sin cotizacion"]));
        });
      });
      orphanQuotes(list).forEach(function (row) {
        lines.push([row.inmueble, "", "", row.id, row.ticket, row.estado, row.fecha || "", row.respuesta || "", row.total, row.admin, "", "", "Aprobada sin inmueble en el auxiliar"]);
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
      renderAll();
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
    var allTimer = 0;
    module.addEventListener("input", function (event) {
      var allFilter = event.target.closest("[data-scm-qq-all-filter]");
      if (allFilter) {
        var key = allFilter.getAttribute("data-scm-qq-all-filter");
        var filterValue = allFilter.value;
        window.clearTimeout(allTimer);
        allTimer = window.setTimeout(function () {
          state.all[key] = filterValue;
          state.all.limit = PAGE_SIZE;
          renderAll();
        }, key === "contrato" || key === "inmueble" ? 200 : 0);
        return;
      }
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
      if (field.getAttribute("data-ql-field") === "estado") {
        // "Esperando respuesta" no lleva fecha de respuesta.
        var response = tr.querySelector('[data-ql-field="fecha"]');
        if (response) {
          response.disabled = field.value === "Esperando respuesta";
          if (response.disabled) response.value = "";
          else if (!response.value) response.value = response.getAttribute("data-original") || "";
        }
        if (save) save.disabled = state.saving || !Object.keys(rowChanges(tr)).length;
        delete tr.dataset.qlConfirm;
        if (save) save.textContent = "Guardar";
      }
      field.classList.toggle("is-changed", Object.prototype.hasOwnProperty.call(rowChanges(tr), changeKey(field.getAttribute("data-ql-field"))));
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
      var sub = target.closest("[data-scm-qq-sub-target]");
      if (sub) { setSub(sub.getAttribute("data-scm-qq-sub-target")); return; }
      if (target.closest("[data-scm-qq-all-more]")) { state.all.limit += PAGE_SIZE; renderAll(); return; }
      if (target.closest("[data-scm-qq-all-clear]")) {
        state.all.month = "";
        state.all.limit = PAGE_SIZE;
        var monthField = ledgerEl.querySelector('[data-scm-qq-all-filter="month"]');
        if (monthField) monthField.value = "";
        renderAll();
        return;
      }
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
