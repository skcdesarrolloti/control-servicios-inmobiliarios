(function () {
  "use strict";
  // Herramientas › Simulador de ingreso neto del propietario con su crédito preaprobado (mensaje y PDF para el
  // propietario). Igual a commercial-tools.js del panel comercial; los eventos se delegan en el documento.
  var app = document.getElementById("scm-app");
  if (!app) return;
  var runtime = {};
  try { runtime = JSON.parse(app.getAttribute("data-scm-runtime") || "{}"); } catch (_error) {}
  // El panel renueva el nonce en su runtime: se lee al enviar.
  function nonce() {
    var core = window.SCMAdminCore;
    var current = core && core.parseRuntime ? core.parseRuntime(app) : null;
    return (current && current.nonce) || runtime.nonce || "";
  }
  var moneyFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });

  function money(value) { return "$" + moneyFormat.format(Math.round(Number(value) || 0)); }
  function digits(value) { return Number(String(value || "").replace(/[^\d]/g, "")) || 0; }
  function escapeHtml(value) { return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]; }); }

  async function request(action, data) {
    data.set("action", action);
    data.set("nonce", nonce());
    var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
    var payload;
    try { payload = await response.json(); } catch (_error) { throw new Error("El servidor devolvió una respuesta no válida."); }
    if (!response.ok || !payload.success) throw new Error((payload.data && payload.data.message) || "No se pudo completar la operación.");
    return payload.data || {};
  }

  // Pesos colombianos: $1.250.000 (sin decimales). Al escribir se conserva el cursor contando dígitos.
  function formatMoney(input, keepCaret) {
    var raw = String(input.value || "");
    var caret = keepCaret && typeof input.selectionStart === "number" ? input.selectionStart : raw.length;
    var digitsBefore = raw.slice(0, caret).replace(/[^\d]/g, "").length;
    var clean = raw.replace(/[^\d]/g, "").replace(/^0+(?=\d)/, "").slice(0, 13);
    var formatted = clean === "" ? "" : "$" + moneyFormat.format(Number(clean));
    if (formatted === raw) return;
    input.value = formatted;
    if (!keepCaret) return;
    var position = 0;
    for (var seen = 0; position < formatted.length && seen < digitsBefore; position++) {
      if (/\d/.test(formatted.charAt(position))) seen++;
    }
    if (digitsBefore === 0) position = formatted.charAt(0) === "$" ? 1 : 0;
    input.setSelectionRange(position, position);
  }

  /* ------------------------------------------------------------ Simulador de ingreso neto del propietario */

  var MODALITIES = { defecto: "Aseguradora", berkley: "Berkley", mundial: "Seguros Mundial" };
  var NO_SERVICES_POLICY = "Sin póliza de servicios públicos: este contrato no cumple las condiciones para la aprobación del crédito.";
  var USE_LABELS = { servicios: "servicios públicos", administracion: "cuotas de administración", reparaciones: "reparaciones y mantenimiento", otros: "libre inversión" };

  function round2(value) { return Math.round((Number(value) || 0) * 100) / 100; }
  function percent(value) { return String(Math.round((Number(value) || 0) * 10000) / 10000).replace(".", ",") + " %"; }
  // Porcentaje escrito como 10, "10 %", "2,5" o "0.8" (OwnerNetIncomeSimulator::rate).
  function parseRate(value) {
    var text = String(value || "").replace(/[%\s ]/g, "");
    text = text.indexOf(",") >= 0 && text.indexOf(".") >= 0 ? text.replace(/\./g, "").replace(",", ".") : text.replace(",", ".");
    var number = Number(text);
    return text !== "" && isFinite(number) ? Math.max(0, number) : 0;
  }
  function rates(root) {
    if (!root._rates) { try { root._rates = JSON.parse(root.dataset.rates || "{}"); } catch (_error) { root._rates = {}; } }
    return root._rates;
  }

  // Cuota fija (sistema francés) y sus intereses; sin tasa, la prima se divide en partes iguales.
  function loan(amount, installments, monthlyRate) {
    installments = Math.max(1, installments);
    var rate = monthlyRate / 100;
    var factor = rate > 0 ? rate * Math.pow(1 + rate, installments) / (Math.pow(1 + rate, installments) - 1) : 1 / installments;
    var installment = round2(amount * factor);
    return { cuota: installment, intereses: round2(installment * installments - amount) };
  }

  function simField(card, name) { return card.querySelector('[data-pre-sim="' + name + '"]'); }

  // Mismo cálculo que OwnerNetIncomeSimulator::simulate() (plugin de la calculadora de comisión).
  function simulate(root, card, canon) {
    var r = rates(root);
    var iva = Number(r.iva) || 0;
    var modality = MODALITIES[simField(card, "modalidad").value] ? simField(card, "modalidad").value : "defecto";
    var admin = digits(simField(card, "administracion").value);
    var rateInput = simField(card, "tasa").value.trim();
    var commissionRate = rateInput === "" ? Number(r.comision) || 0 : Math.min(100, parseRate(rateInput));
    var ivaCanon = simField(card, "iva").checked ? round2(canon * iva / 100) : 0;
    var canonTotal = round2(canon + admin);
    // La comisión y la garantía se cobran sobre el canon total (canon + administración).
    var commission = round2(canonTotal * commissionRate / 100);
    commission = round2(commission + round2(commission * iva / 100));
    var installments = Math.max(1, Math.min(modality === "berkley" ? 4 : 12, Number(simField(card, "cuotas").value) || 1));
    var insured = digits(simField(card, "valor").value);
    var services = simField(card, "servicios").checked;
    var out = { comision: commission, garantia: 0, seguro: 0, intereses: 0, cuota: 0, cuotas: 0, modalidad: MODALITIES[modality], aseguradora: MODALITIES[modality], tasa: commissionRate, warnings: [] };
    if (modality === "defecto") {
      var insurers = r.aseguradoras || {};
      var name = insurers[simField(card, "aseguradora").value] ? simField(card, "aseguradora").value : Object.keys(insurers)[0] || "";
      var insurer = insurers[name] || { seguro: 0, servicios: 0 };
      out.aseguradora = name;
      if (simField(card, "garantia").checked) out.garantia = round2((canonTotal + ivaCanon) * insurer.seguro / 100);
      if (services) {
        if (insured <= 0) out.warnings.push("Indica el valor asegurado de servicios públicos.");
        else if (!(insurer.servicios > 0)) out.warnings.push(name + " no tiene tasa de servicios públicos configurada.");
        else {
          var premium = round2(insured * insurer.servicios / 100);
          // Hasta 4 cuotas se dividen sin intereses; desde 5 se financian con la tasa de préstamo de garantías.
          var financed = installments <= 4 ? { cuota: round2(premium / installments), intereses: 0 } : loan(premium, installments, Number(r.prestamo) || 0);
          out.seguro = premium; out.intereses = financed.intereses; out.cuota = financed.cuota; out.cuotas = installments;
        }
      }
    } else if (modality === "berkley") {
      var berkley = r.berkley || { tasa: 0, expedicion: 0 };
      var premiumCanon = round2(round2(canonTotal * 12) * berkley.tasa / 100);
      var premiumServices = 0;
      if (services) {
        if (insured <= 0) out.warnings.push("Indica el valor asegurado de servicios para Berkley.");
        else premiumServices = round2(insured * berkley.tasa / 100);
      }
      var policy = round2(premiumCanon + premiumServices + (Number(berkley.expedicion) || 0));
      out.garantia = round2(premiumCanon / 12); out.seguro = policy; out.cuota = round2(policy / installments); out.cuotas = installments;
    } else {
      var mundial = r.mundial || { tasa: 0, expedicion: 0 };
      var canonPremium = round2(round2((canonTotal + ivaCanon) * 12) * mundial.tasa / 100);
      var withoutIva = round2(canonPremium + (Number(mundial.expedicion) || 0));
      var total = round2(withoutIva + round2(withoutIva * iva / 100));
      out.garantia = round2(canonPremium / 12);
      if (services) {
        var credit = loan(total, installments, Number(r.prestamo) || 0);
        out.seguro = total; out.intereses = credit.intereses; out.cuota = credit.cuota; out.cuotas = installments;
      }
    }
    return out;
  }

  // Muestra solo los campos de la modalidad elegida y deja las cuotas que permite (Berkley hasta 4).
  function syncSimFields(root, card) {
    var modality = simField(card, "modalidad").value;
    var services = simField(card, "servicios").checked;
    card.querySelectorAll('[data-pre-sim-row="defecto"]').forEach(function (node) { node.hidden = modality !== "defecto"; });
    card.querySelector('[data-pre-sim-row="valor"]').hidden = !services || modality === "mundial";
    card.querySelector('[data-pre-sim-row="cuotas"]').hidden = !(services || modality === "berkley");
    var select = simField(card, "cuotas");
    var max = modality === "berkley" ? 4 : 12;
    Array.from(select.options).forEach(function (option) { option.hidden = option.disabled = Number(option.value) > max; });
    if (Number(select.value) > max) select.value = String(max);
    var r = rates(root);
    var text = "";
    if (modality === "defecto") {
      var insurer = (r.aseguradoras || {})[simField(card, "aseguradora").value] || { seguro: 0, servicios: 0 };
      text = "Tarifas de " + simField(card, "aseguradora").value + ": garantía " + percent(insurer.seguro) + " · servicios públicos " + (insurer.servicios > 0 ? percent(insurer.servicios) : "sin tasa") + ". Desde 5 cuotas el seguro se financia al " + percent(r.prestamo) + " mensual.";
    } else if (modality === "berkley") {
      text = "Berkley: " + percent((r.berkley || {}).tasa) + " sobre el canon anual (y los servicios asegurados) + expedición " + money((r.berkley || {}).expedicion) + ". La póliza se paga en 1 a 4 cuotas.";
    } else {
      text = "Seguros Mundial: " + percent((r.mundial || {}).tasa) + " sobre el canon anual + expedición " + money((r.mundial || {}).expedicion) + " + IVA. Con servicios, la póliza se financia al " + percent(r.prestamo) + " mensual.";
    }
    card.querySelector("[data-pre-sim-rates]").textContent = text;
  }

  // Ingreso neto mensual de un inmueble: canon − comisión − garantía − (seguro + intereses) / 12 − otros.
  function sourceValues(root, card) {
    var value = function (name) { var input = card.querySelector('[data-pre-field="' + name + '"]'); return input ? digits(input.value) : 0; };
    var item = {
      canon: value("canon"), otros: value("otros"),
      duracion: Number(card.querySelector('[data-pre-field="duracion"]').value) || 0,
      meta: card.querySelector("[data-pre-source-meta]").textContent, fromContract: card.dataset.contract === "1",
    };
    // Póliza de servicios públicos: se descuenta, ya se pagó (la del mandato) o el inmueble no la tiene.
    item.poliza = simField(card, "servicios").checked ? "si" : card.dataset.policy === "pagada" ? "pagada" : "no";
    var sim = simulate(root, card, item.canon);
    item.sim = sim;
    ["comision", "garantia", "seguro", "intereses", "cuota", "cuotas", "warnings"].forEach(function (key) { item[key] = sim[key]; });
    item.seguroMes = (item.seguro + item.intereses) / 12;
    // Los contratos traídos del propietario registrado descuentan como mínimo el 15 % del canon (no_mandate_deduction):
    // sin mandato no hay con qué calcular sus descuentos reales, así que la diferencia va como ajuste.
    var deductions = item.comision + item.garantia + item.seguroMes + item.otros;
    var minimum = item.fromContract ? round2(item.canon * (Number(root.dataset.minDeduction) || 0)) : 0;
    item.ajuste = Math.max(0, round2(minimum - deductions));
    item.net = Math.max(0, item.canon - deductions - item.ajuste);
    return item;
  }

  function breakdownRow(label, value, note) {
    return "<div><dt>" + escapeHtml(label) + (note ? "<small>" + escapeHtml(note) + "</small>" : "") + "</dt><dd>" + escapeHtml(value) + "</dd></div>";
  }

  function renderSource(root, card) {
    syncSimFields(root, card);
    var item = sourceValues(root, card);
    var rows = breakdownRow("Canon mensual", money(item.canon));
    if (item.comision > 0) rows += breakdownRow("(−) Comisión de administración", money(item.comision), percent(item.sim.tasa) + " + IVA sobre " + (digits(simField(card, "administracion").value) > 0 ? "canon + administración" : "el canon"));
    if (item.garantia > 0) rows += breakdownRow("(−) Garantía inmobiliaria", money(item.garantia), item.sim.aseguradora);
    if (item.seguro <= 0 || item.poliza !== "si") rows += breakdownRow("(−) Póliza de servicios públicos", money(0), item.poliza === "pagada" ? "Ya se pagó: no se descuenta" : item.poliza === "no" ? "No la tiene" : "");
    if (item.seguro > 0) rows += breakdownRow((item.poliza === "si" && simField(card, "modalidad").value === "defecto" ? "(−) Póliza de servicios públicos" : "(−) Seguro") + (item.intereses > 0 ? " e intereses" : ""), money(item.seguroMes), item.cuotas > 0 ? money(item.seguro + item.intereses) + " en " + (item.cuotas === 1 ? "1 pago" : item.cuotas + " cuotas de " + money(item.cuota)) + ", repartido en 12 meses" : money(item.seguro + item.intereses) + " en 12 meses");
    if (item.otros > 0) rows += breakdownRow("(−) Otros descuentos", money(item.otros));
    if (item.ajuste > 0) rows += breakdownRow("(−) Ajuste al descuento mínimo", money(item.ajuste), "Los contratos descuentan como mínimo el " + percent((Number(root.dataset.minDeduction) || 0) * 100) + " del canon");
    card.querySelector("[data-pre-breakdown]").innerHTML = item.canon > 0 ? rows : "";
    var warning = card.querySelector("[data-pre-source-warning]");
    var minMonths = Number(root.dataset.minMonths) || 12;
    var warnings = item.warnings.concat(item.canon > 0 && item.poliza === "no" ? [NO_SERVICES_POLICY] : [], item.duracion > 0 && item.duracion < minMonths ? ["El crédito exige un contrato de mínimo " + minMonths + " meses."] : []);
    warning.textContent = warnings.join(" ");
    warning.hidden = !warnings.length;
    card.querySelector("[data-pre-source-net]").textContent = money(item.net);
    // Con la cuota del seguro en menos de 12 meses, el neto cambia entre los meses con y sin cuota.
    var base = Math.max(0, item.canon - item.comision - item.garantia - item.otros - item.ajuste);
    card.querySelector("[data-pre-source-detail]").textContent = item.cuota > 0 && item.cuotas < 12
      ? "Promedio de 12 meses: " + money(Math.max(0, base - item.cuota)) + " en " + (item.cuotas === 1 ? "el mes" : "los " + item.cuotas + " meses") + " con cuota del seguro y " + money(base) + " en los demás"
      : item.seguroMes > 0 ? "Seguro" + (item.intereses > 0 ? " e intereses" : "") + ": " + money(item.seguroMes) + " / mes" : "";
    return item;
  }

  function useValues(root) {
    var uses = {};
    if (root.dataset.needs !== "si") return uses;
    root.querySelectorAll("[data-pre-use]").forEach(function (input) { var value = digits(input.value); if (value > 0) uses[input.dataset.preUse] = value; });
    return uses;
  }

  function preState(root) {
    var months = Number(root.dataset.incomeMonths) || 3;
    var margin = Number(root.dataset.termMargin) || 0;
    var sources = Array.from(root.querySelectorAll("[data-pre-sources] [data-pre-source]")).map(function (card) { return renderSource(root, card); });
    var net = sources.reduce(function (sum, item) { return sum + item.net; }, 0);
    var gross = sources.reduce(function (sum, item) { return sum + item.canon; }, 0);
    // Plazo: duración en meses del contrato más largo, menos el margen del liquidador.
    var longest = sources.reduce(function (max, item) { return Math.max(max, item.duracion); }, 0);
    var term = longest > 0 ? Math.max(0, longest - margin) : null;
    var owner = {};
    root.querySelectorAll("[data-pre-owner]").forEach(function (input) { owner[input.dataset.preOwner] = input.value.trim(); });
    return { months: months, margin: margin, sources: sources, gross: gross, net: net, amount: net * months, longest: longest, term: term, owner: owner, registered: root.dataset.mode !== "manual", needs: root.dataset.needs === "si", uses: useValues(root), notes: root.querySelector("[data-pre-notes]").value.trim() };
  }

  function recalc(root) {
    var state = preState(root);
    root.querySelector("[data-pre-total]").textContent = money(state.amount);
    root.querySelector("[data-pre-net]").textContent = money(state.net);
    root.querySelector("[data-pre-formula]").textContent = "Canon " + money(state.gross) + " − descuentos " + money(state.gross - state.net) + " / mes";
    root.querySelector("[data-pre-gross]").textContent = money(state.gross);
    root.querySelector("[data-pre-deductions]").textContent = money(state.gross - state.net);
    root.querySelector("[data-pre-count]").textContent = String(state.sources.length);
    root.querySelector("[data-pre-term]").textContent = state.term === null ? "Según vigencia" : state.term > 0 ? "Hasta " + state.term + " cuotas" : "Sin plazo disponible";
    root.querySelector("[data-pre-empty]").hidden = state.sources.length > 0;
    var need = Object.keys(state.uses).reduce(function (sum, key) { return sum + state.uses[key]; }, 0);
    var summary = root.querySelector("[data-pre-uses-summary]");
    if (!need) {
      summary.dataset.state = "empty";
      summary.textContent = "Escribe cuánto necesita para cada cosa y muéstrale que su crédito preaprobado lo cubre.";
    } else if (!(state.amount > 0)) {
      summary.dataset.state = "empty";
      summary.textContent = "Necesita " + money(need) + ". Completa el canon de sus inmuebles para ver cuánto le cubre el crédito.";
    } else if (need <= state.amount) {
      summary.dataset.state = "ok";
      summary.textContent = "Su crédito preaprobado cubre los " + money(need) + " que necesita y le quedan " + money(state.amount - need) + " disponibles.";
    } else {
      summary.dataset.state = "partial";
      summary.textContent = "Necesita " + money(need) + ": su crédito preaprobado cubre " + money(state.amount) + " (" + Math.floor(state.amount / need * 100) + " %).";
    }
    return state;
  }

  function addSource(root, data) {
    var template = root.querySelector("[data-pre-source-template]");
    var card = template.content.firstElementChild.cloneNode(true);
    var list = root.querySelector("[data-pre-sources]");
    data = data || {};
    var canon = Number(data.canon) || 0;
    card.querySelector('[data-pre-field="canon"]').value = canon > 0 ? money(canon) : "";
    // Contratos vigentes: los meses que le quedan, en la opción más cercana de la lista.
    if (Number(data.max_months) > 0) {
      var duration = card.querySelector('[data-pre-field="duracion"]');
      var options = Array.from(duration.options).map(function (option) { return Number(option.value); });
      var months = Number(data.max_months);
      if (options.indexOf(months) < 0) { duration.append(new Option(months + (months === 1 ? " mes" : " meses") + " (restantes)", String(months))); }
      duration.value = String(months);
    }
    // Los contratos Por entregar se simulan como un inmueble nuevo: el consultor escribe canon y administración.
    var pending = !!data.por_entregar;
    card.dataset.contract = data.contrato && !pending ? "1" : "";
    // Un contrato con mandato llena el simulador con su administración, su comisión (tasa sobre canon + administración,
    // sin IVA) y sus coberturas; sin mandato se simula con la tasa y las coberturas por defecto.
    var commission = Number(data.comision) || 0;
    var admin = Number(data.administracion) || 0;
    if (admin > 0) simField(card, "administracion").value = money(admin);
    if (data.contrato && commission > 0 && canon > 0) {
      var rate = commission / ((canon + admin) * (1 + (Number(rates(root).iva) || 0) / 100)) * 100;
      simField(card, "tasa").value = String(Math.round(rate * 10000) / 10000).replace(".", ",");
      simField(card, "garantia").checked = Number(data.garantia) > 0;
    }
    // Póliza de servicios del mandato: se paga una sola vez en N cuotas desde el inicio del contrato. Si ya pasaron
    // esos meses está pagada y no se descuenta; si no, se simula con su valor asegurado y sus cuotas.
    var policy = data.contrato && !pending ? data.servicios : null;
    simField(card, "servicios").checked = !!policy && policy.estado !== "pagada";
    card.dataset.policy = policy ? policy.estado : "";
    if (policy) {
      if (Number(policy.valor) > 0) simField(card, "valor").value = money(policy.valor);
      simField(card, "cuotas").value = String(policy.cuotas);
      var start = policy.inicio ? policy.inicio.split("-").reverse().join("/") : "";
      var count = policy.cuotas === 1 ? "1 pago" : policy.cuotas + " cuotas";
      var info = card.querySelector("[data-pre-source-policy]");
      info.textContent = policy.estado === "pagada" ? "Póliza de servicios públicos ya pagada: " + count + " desde el inicio del contrato (" + start + "). No se descuenta."
        : policy.estado === "pendiente" ? "Póliza de servicios públicos en " + count + " desde el inicio del contrato (" + start + "): lleva " + policy.pagadas + " y le faltan " + (policy.cuotas - policy.pagadas) + "."
        : "El contrato no tiene fecha de inicio: no se puede saber si ya pagó la póliza de servicios públicos (" + count + "). Si ya la pagó, desactívala.";
      info.hidden = false;
    }
    if (pending) {
      var note = card.querySelector("[data-pre-source-policy]");
      note.textContent = "Contrato por entregar: escribe el canon y la administración para simularlo como un inmueble nuevo, con las tarifas de SuCasa.";
      note.hidden = false;
    }
    card.querySelector("[data-pre-source-meta]").textContent = data.contrato ? ["Contrato " + data.contrato, data.inmueble ? "SIMI " + data.inmueble : "", data.direccion, data.estado].filter(Boolean).join(" · ") : "Simulación con las tarifas de SuCasa";
    list.append(card);
    renumber(root);
    return card;
  }

  function renumber(root) {
    root.querySelectorAll("[data-pre-sources] [data-pre-source]").forEach(function (card, index) {
      card.querySelector("[data-pre-source-title]").textContent = "Inmueble " + (index + 1);
    });
  }

  function clearSources(root) {
    root.querySelectorAll("[data-pre-sources] [data-pre-source]").forEach(function (card) { card.remove(); });
  }

  function setStatus(root, text, error) {
    var node = root.querySelector("[data-pre-status]");
    node.textContent = text || "";
    node.dataset.error = error ? "true" : "false";
  }

  function showError(root, text) {
    var node = root.querySelector("[data-pre-error]");
    node.textContent = text || "";
    node.hidden = !text;
  }

  function setNeeds(root, needs) {
    root.dataset.needs = needs;
    root.querySelectorAll("[data-pre-needs]").forEach(function (button) { button.setAttribute("aria-pressed", button.dataset.preNeeds === needs ? "true" : "false"); });
    root.querySelector("[data-pre-needs-panel]").hidden = needs !== "si";
    recalc(root);
    if (needs === "si") root.querySelector("[data-pre-use]").focus();
  }

  function setMode(root, mode) {
    root.dataset.mode = mode;
    root.querySelectorAll("[data-pre-mode]").forEach(function (button) { button.setAttribute("aria-pressed", button.dataset.preMode === mode ? "true" : "false"); });
    root.querySelector("[data-pre-search]").hidden = mode === "manual";
    root.querySelector("[data-pre-credits]").hidden = true;
    root.querySelectorAll("[data-pre-owner]").forEach(function (input) { input.value = ""; });
    setStatus(root, "");
    showError(root, "");
    clearSources(root);
    if (mode === "manual") {
      addSource(root);
      root.querySelector('[data-pre-owner="name"]').focus();
    } else {
      root.querySelector("[data-pre-document]").focus();
    }
    recalc(root);
  }

  async function search(root) {
    var input = root.querySelector("[data-pre-document]");
    var button = root.querySelector("[data-pre-search-button]");
    var query = input.value.trim();
    showError(root, "");
    if (query.replace(/[\s.]/g, "").length < 4) { setStatus(root, "Escribe la cédula o el NIT (mínimo 4 caracteres).", true); input.focus(); return; }
    if (button.disabled) return;
    button.disabled = true;
    setStatus(root, "Buscando contratos…");
    try {
      var data = new FormData();
      data.set("document", query);
      var result = await request("scm_tools_preapproval_lookup", data);
      clearSources(root);
      var credits = root.querySelector("[data-pre-credits]");
      credits.hidden = !(result.active_credits > 0);
      credits.textContent = result.active_credits > 0 ? "Tiene " + result.active_credits + (result.active_credits === 1 ? " crédito aprobado vigente" : " créditos aprobados vigentes") + " en el liquidador: el crédito disponible será menor, porque el analista descuenta su saldo." : "";
      var contracts = result.contracts || [];
      if (!contracts.length || !result.owner) {
        root.querySelector('[data-pre-owner="document"]').value = query;
        setStatus(root, "No encontramos contratos Entregado o Por entregar de este documento. Si aún no está registrado, usa \"Aún no registrado\" o agrega un inmueble para simularlo.", true);
        recalc(root);
        return;
      }
      ["name", "document", "phone", "email"].forEach(function (key) { root.querySelector('[data-pre-owner="' + key + '"]').value = result.owner[key] || ""; });
      contracts.forEach(function (contract) { addSource(root, contract); });
      var pendingCount = contracts.filter(function (contract) { return contract.por_entregar; }).length;
      var active = contracts.length - pendingCount;
      var parts = [];
      if (active) parts.push(active === 1 ? "1 contrato entregado" : active + " contratos entregados");
      if (pendingCount) parts.push(pendingCount === 1 ? "1 por entregar" : pendingCount + " por entregar");
      setStatus(root, result.owner.name + " es propietario en " + parts.join(" y ") + (pendingCount ? ". Escribe el canon y la administración de los contratos por entregar." : "."));
      var firstPending = root.querySelector('[data-pre-source]:not([data-contract="1"]) [data-pre-field="canon"]');
      if (pendingCount && firstPending && !active) firstPending.focus();
      recalc(root);
    } catch (error) {
      setStatus(root, error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  /* ---------------------------------------------------------- Mensaje y PDF para el propietario */

  function shareMessage(state) {
    var lines = [state.owner.name ? "Hola " + state.owner.name + "," : "Hola,", "", "Te comparto la simulación de tu ingreso neto con SKC SuCasa Inmobiliaria:"];
    state.sources.forEach(function (item, index) {
      lines.push("- Inmueble " + (index + 1) + (item.fromContract && item.meta ? " (" + item.meta + ")" : "") + ": canon " + money(item.canon) + " → neto " + money(item.net) + " / mes (" + (item.sim.modalidad === MODALITIES.defecto ? item.sim.aseguradora : "póliza " + item.sim.modalidad) + ")");
    });
    lines.push("", "Ingreso neto mensual: " + money(state.net));
    lines.push("Con este ingreso cuentas con un crédito preaprobado de hasta " + money(state.amount) + " para ponerte al día con servicios públicos, cuotas de administración, reparaciones o libre inversión" + (state.term > 0 ? ", hasta en " + state.term + " cuotas." : "."));
    var uses = Object.keys(state.uses);
    if (uses.length) {
      var need = uses.reduce(function (sum, key) { return sum + state.uses[key]; }, 0);
      lines.push("Lo que necesitas cubrir (" + uses.map(function (key) { return USE_LABELS[key] + " " + money(state.uses[key]); }).join(", ") + ") suma " + money(need) + (need <= state.amount ? ": tu crédito lo cubre todo." : ": tu crédito cubre " + money(state.amount) + "."));
    }
    if (state.notes) lines.push("", state.notes);
    lines.push("", "Valores de referencia sujetos a estudio y aprobación del crédito.");
    return lines.join("\n");
  }

  function validState(root, state) {
    if (!state.sources.length) { showError(root, "Agrega al menos un inmueble con su canon."); return false; }
    var empty = state.sources.findIndex(function (item) { return !(item.canon > 0); });
    if (empty >= 0) { showError(root, "Escribe el canon del inmueble " + (empty + 1) + " o quítalo."); return false; }
    if (!(state.net > 0)) { showError(root, "El ingreso neto es $0: revisa el canon y los descuentos."); return false; }
    return true;
  }

  async function copyMessage(root, button) {
    var state = recalc(root);
    showError(root, "");
    if (!validState(root, state)) return;
    var text = shareMessage(state);
    var label = button.querySelector("[data-pre-copy-label]");
    try {
      await navigator.clipboard.writeText(text);
    } catch (_error) {
      var area = document.createElement("textarea");
      area.value = text;
      area.setAttribute("readonly", "");
      area.style.position = "fixed";
      area.style.opacity = "0";
      document.body.append(area);
      area.select();
      document.execCommand("copy");
      area.remove();
    }
    label.textContent = "Mensaje copiado";
    setTimeout(function () { label.textContent = "Copiar mensaje para WhatsApp"; }, 1800);
  }

  // Documento, celular y correo del propietario son obligatorios: aparecen en el PDF y sirven para contactarlo.
  function validOwner(root, state) {
    var labels = { name: "el nombre", document: "la cédula o NIT", phone: "el celular", email: "el correo" };
    var missing = Object.keys(labels).filter(function (key) { return !state.owner[key]; })[0];
    var input = missing ? root.querySelector('[data-pre-owner="' + missing + '"]') : root.querySelector('[data-pre-owner="email"]');
    if (missing) { showError(root, "Escribe " + labels[missing] + " del propietario: es obligatorio y aparece en el PDF."); input.focus(); return false; }
    if (!input.checkValidity()) { showError(root, "El correo del propietario no es válido."); input.focus(); return false; }
    return true;
  }

  // El servidor recalcula la simulación y genera el PDF con el diseño de las actas (Chromium); primero se muestra en
  // la vista previa y desde ahí se descarga.
  async function previewPdf(root, button) {
    var state = recalc(root);
    showError(root, "");
    if (!validState(root, state) || !validOwner(root, state)) return;
    if (button.disabled) return;
    var label = button.innerHTML;
    button.disabled = true;
    button.textContent = "Preparando vista previa…";
    try {
      var cards = Array.from(root.querySelectorAll("[data-pre-sources] [data-pre-source]"));
      var data = new FormData();
      data.set("action", "scm_tools_preapproval_pdf");
      data.set("nonce", nonce());
      data.set("payload", JSON.stringify({
        owner: state.owner, registered: state.registered, needs: state.needs, uses: state.uses, notes: state.notes,
        sources: state.sources.map(function (item, index) {
          var card = cards[index];
          return {
            calc: "sim", contrato: item.fromContract, canon: item.canon, otros: item.otros, duracion: item.duracion, meta: item.fromContract ? item.meta : "",
            administracion: digits(simField(card, "administracion").value), tasa_comision: simField(card, "tasa").value, modalidad: simField(card, "modalidad").value, aseguradora: simField(card, "aseguradora").value,
            iva_canon: simField(card, "iva").checked, garantia: simField(card, "garantia").checked, servicios: simField(card, "servicios").checked, poliza_pagada: item.poliza === "pagada", valor_asegurado: digits(simField(card, "valor").value), cuotas: Number(simField(card, "cuotas").value) || 1,
          };
        }),
      }));
      var response = await fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin" });
      if (!response.ok || (response.headers.get("Content-Type") || "").indexOf("application/pdf") !== 0) {
        var payload = null;
        try { payload = await response.json(); } catch (_error) {}
        throw new Error((payload && payload.data && payload.data.message) || "No se pudo generar el PDF.");
      }
      var match = /filename="([^"]+)"/.exec(response.headers.get("Content-Disposition") || "");
      if (root._pdf) URL.revokeObjectURL(root._pdf.url);
      root._pdf = { url: URL.createObjectURL(await response.blob()), name: match ? match[1] : "simulacion-ingreso-neto.pdf" };
      root.querySelector("[data-pre-viewer-frame]").src = root._pdf.url;
      root.querySelector("[data-pre-viewer]").showModal();
    } catch (error) {
      showError(root, error.message || "No se pudo generar el PDF.");
    } finally {
      button.disabled = false;
      button.innerHTML = label;
    }
  }

  function downloadPdf(root) {
    if (!root._pdf) return;
    var link = document.createElement("a");
    link.href = root._pdf.url;
    link.download = root._pdf.name;
    document.body.append(link);
    link.click();
    link.remove();
  }

  /* ------------------------------------------------------------------------------------------ Eventos */

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    var pre = target.closest('[data-net-income-tool]');
    if (pre) {
      var mode = target.closest("[data-pre-mode]");
      if (mode) { if ((pre.dataset.mode || "registrado") !== mode.dataset.preMode) setMode(pre, mode.dataset.preMode); return; }
      if (target.closest("[data-pre-search-button]")) { search(pre); return; }
      if (target.closest("[data-pre-add]")) { addSource(pre).querySelector('[data-pre-field="canon"]').focus(); recalc(pre); return; }
      var copy = target.closest("[data-pre-copy]");
      if (copy) { copyMessage(pre, copy); return; }
      var remove = target.closest("[data-pre-remove]");
      if (remove) { remove.closest("[data-pre-source]").remove(); renumber(pre); recalc(pre); return; }
      var needs = target.closest("[data-pre-needs]");
      if (needs) { setNeeds(pre, needs.dataset.preNeeds); return; }
      var viewer = pre.querySelector("[data-pre-viewer]");
      if (target.closest("[data-pre-viewer-close]") || target === viewer) { viewer.close(); return; }
      if (target.closest("[data-pre-download]")) { downloadPdf(pre); return; }
      var pdf = target.closest("[data-pre-pdf]");
      if (pdf) previewPdf(pre, pdf);
      return;
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter" || !(event.target instanceof Element) || !event.target.matches("[data-pre-document]")) return;
    event.preventDefault();
    search(event.target.closest('[data-net-income-tool]'));
  });

  document.addEventListener("input", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var root = target && target.closest("[data-net-income-tool]");
    if (!root) return;
    if (target.matches("[data-tool-money]")) formatMoney(target, true);
    showError(root, "");
    recalc(root);
  });

  document.addEventListener("change", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var pre = target && target.closest('[data-net-income-tool]');
    if (pre) recalc(pre);
  });
})();
