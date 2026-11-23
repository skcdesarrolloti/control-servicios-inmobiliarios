(function () {
  "use strict";

  // Simulación de la financiación de una cotización: cuota de cada plazo posible y plan de pagos de los meses elegidos.
  // La usan la respuesta pública (cotizacion-mantenimiento.php) y los formularios del panel que preguntan "¿A cuántos
  // meses?". Los planes los calcula el servidor (OwnerCreditSimulator::browserPreview); aquí solo se pintan.
  var STYLE_ID = "scm-qfp-style";
  var CSS =
    ".scm-qfp{grid-column:1/-1;min-width:0;display:flex;flex-direction:column;gap:12px;margin:4px 0 0;padding:14px;border:1px solid #bfdbfe;border-radius:14px;background:#f8fbff;font-family:Poppins,Arial,sans-serif;text-align:left;box-sizing:border-box}" +
    ".scm-qfp[hidden]{display:none!important}" +
    ".scm-qfp-head span{display:block;color:#ff8a00;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}" +
    ".scm-qfp-head strong{display:block;margin-top:2px;color:#0b254f;font-size:17px;font-weight:800}" +
    ".scm-qfp-head p{margin:4px 0 0;color:#475569;font-size:12.5px;font-weight:500;line-height:1.5}" +
    ".scm-qfp-options{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:8px}" +
    ".scm-qfp .scm-qfp-option{grid-column:auto;justify-self:stretch;display:flex;flex-direction:column;align-items:flex-start;gap:2px;width:100%;min-height:0;margin:0;padding:9px 11px;border:1px solid #cbd5e1;border-radius:11px;background:#fff;color:#0b254f;font:600 12px Poppins,Arial,sans-serif;text-align:left;text-transform:none;letter-spacing:0;box-shadow:none;cursor:pointer}" +
    ".scm-qfp .scm-qfp-option strong{font-size:13.5px;font-weight:800;color:inherit}" +
    ".scm-qfp .scm-qfp-option span{color:#475569;font-weight:600}" +
    ".scm-qfp .scm-qfp-option[aria-pressed=\"true\"]{border-color:#0b254f;background:#0b254f;color:#fff}" +
    ".scm-qfp .scm-qfp-option[aria-pressed=\"true\"] span{color:#fde68a}" +
    ".scm-qfp .scm-qfp-option:focus-visible{outline:3px solid #ff8a00;outline-offset:2px}" +
    ".scm-qfp-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}" +
    ".scm-qfp-summary div{padding:9px 11px;border-radius:11px;background:#fff;border:1px solid #e2e8f0}" +
    ".scm-qfp-summary div.is-main{background:#0b254f;border-color:#0b254f}" +
    ".scm-qfp-summary span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}" +
    ".scm-qfp-summary strong{display:block;margin-top:2px;color:#0b254f;font-size:16px;font-weight:800}" +
    ".scm-qfp-summary .is-main span{color:#fde68a}" +
    ".scm-qfp-summary .is-main strong{color:#fff}" +
    ".scm-qfp-table{overflow-x:auto;max-height:320px;overflow-y:auto;margin-top:10px;border:1px solid #e2e8f0;border-radius:11px;background:#fff}" +
    ".scm-qfp-table table{width:100%;margin:0;border-collapse:collapse;font-size:12px;color:#0f172a}" +
    ".scm-qfp-table th,.scm-qfp-table td{padding:6px 10px;border:0;border-bottom:1px solid #eef2f7;text-align:right;white-space:nowrap;background:transparent}" +
    ".scm-qfp-table th:first-child,.scm-qfp-table td:first-child{text-align:left}" +
    ".scm-qfp-table thead th{position:sticky;top:0;background:#f1f5f9;color:#475569;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}" +
    ".scm-qfp-table tfoot td{font-weight:800;background:#f8fafc}" +
    ".scm-qfp-hint{margin:0;color:#475569;font-size:12.5px;font-weight:600}" +
    ".scm-qfp-note{margin:0;color:#64748b;font-size:11px;font-weight:500;line-height:1.5}" +
    ".scm-qfp-eval{padding:11px 12px;border:1px solid #c7d2fe;border-radius:11px;background:#fff}" +
    ".scm-qfp-eval h4{margin:0 0 8px;color:#3730a3;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}" +
    ".scm-qfp-eval dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 14px;margin:0}" +
    ".scm-qfp-eval dt{color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}" +
    ".scm-qfp-eval dd{margin:1px 0 0;color:#0b254f;font-size:12.5px;font-weight:700}" +
    ".scm-qfp-eval dd small{display:block;color:#64748b;font-size:11px;font-weight:500}" +
    ".scm-qfp-eval .scm-qfp-eval-credit{margin:8px 0 0;color:#92400e;font-size:12px;font-weight:700}" +
    ".scm-qfp .scm-qfp-option em{display:block;margin-top:3px;padding:1px 6px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:10px;font-style:normal;font-weight:800}" +
    ".scm-qfp .scm-qfp-option[aria-pressed=\"true\"] em{background:#fde68a;color:#78350f}" +
    ".scm-qfp-checks{display:grid;gap:4px;margin:10px 0 0;padding:0;list-style:none}" +
    ".scm-qfp-checks li{display:flex;gap:6px;align-items:flex-start;color:#0f172a;font-size:12px;font-weight:600}" +
    ".scm-qfp-checks li b{flex:0 0 auto;font-size:12px}" +
    ".scm-qfp-checks li.is-ok b{color:#047857}" +
    ".scm-qfp-checks li.is-bad b{color:#b91c1c}" +
    "@media (max-width:720px){.scm-qfp-summary,.scm-qfp-eval dl{grid-template-columns:1fr}}";

  var money = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = CSS;
    document.head.appendChild(style);
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function percent(value) {
    return (Math.round(Number(value) * 10000) / 100).toLocaleString("es-CO", { maximumFractionDigits: 2 }) + " %";
  }

  function months(term) {
    return term + (Number(term) === 1 ? " mes" : " meses");
  }

  // Evaluación del funcionario (solo en el panel): si el plazo, la cuota y el valor caben en lo del propietario.
  function checks(data, term) {
    var ev = data.evaluation;
    var plan = data.plans[term];
    if (!ev || !plan) return [];
    var list = [];
    if (ev.max_term > 0) {
      list.push(Number(term) <= ev.max_term
        ? { ok: true, text: months(term) + " está dentro del plazo máximo según el contrato (" + months(ev.max_term) + ")." }
        : { ok: false, text: months(term) + " supera el plazo máximo según el contrato (" + months(ev.max_term) + ")." });
    }
    list.push(plan.payment <= ev.available
      ? { ok: true, text: "La cuota de " + money.format(plan.payment) + " cabe en lo que puede ir a la cuota (" + money.format(ev.available) + " al mes)." }
      : { ok: false, text: "La cuota de " + money.format(plan.payment) + " supera lo que puede ir a la cuota (" + money.format(ev.available) + " al mes)." });
    list.push(data.amount <= ev.cupo
      ? { ok: true, text: "El valor cabe en el cupo preaprobado (" + money.format(ev.cupo) + ")." }
      : { ok: false, text: "El valor supera el cupo preaprobado (" + money.format(ev.cupo) + ") en " + money.format(data.amount - ev.cupo) + "." });
    return list;
  }

  function evaluationHtml(ev) {
    if (!ev) return "";
    var item = function (label, value, note) {
      return "<div><dt>" + label + "</dt><dd>" + value + (note ? "<small>" + escapeHtml(note) + "</small>" : "") + "</dd></div>";
    };
    return '<div class="scm-qfp-eval" data-scm-qfp-eval><h4>Evaluación para el funcionario</h4><dl>' +
      item("Propietario", escapeHtml(ev.owner || "Sin dato"), ev.document ? "Documento " + ev.document : "") +
      item("Contrato", escapeHtml(ev.contract || "Sin dato"), ev.property ? "Inmueble " + ev.property : "") +
      item("Plazo máximo según contrato", ev.max_term > 0 ? months(ev.max_term) : "Sin plazo por vigencia", ev.term_basis || "") +
      item("Ingreso neto mensual", money.format(ev.net_income), ev.contracts > 1 ? ev.contracts + " contratos del propietario" : "Contrato del inmueble") +
      item("Cupo preaprobado", money.format(ev.cupo), "Ingreso neto × " + ev.income_months + " meses") +
      item("Puede ir a la cuota", money.format(ev.available) + " al mes", "Según el % utilizable y la cobertura mínima") +
      "</dl>" +
      (ev.credit ? '<p class="scm-qfp-eval-credit">Esta cotización ya tiene la solicitud de crédito #' + ev.credit.id + " (" + escapeHtml(ev.credit.status) + ").</p>" : "") +
      "</div>";
  }

  function usable(data) {
    return !!(data && data.plans && Object.keys(data.plans).length);
  }

  /**
   * Pinta la simulación dentro de host. onPick(term) se llama al elegir un plazo de la lista.
   * @return {{update: function(string|number): void}}
   */
  function mount(host, data, onPick) {
    ensureStyle();
    host.classList.add("scm-qfp");
    var terms = Object.keys(data.plans).map(Number).sort(function (a, b) { return a - b; });
    host.innerHTML =
      '<div class="scm-qfp-head"><span>Simulación de la financiación</span><strong>Valor a financiar: ' + money.format(data.amount) + "</strong>" +
      "<p>Cuota mensual estimada según el plazo, con la tasa de referencia de " + percent(data.rate) + " mensual (" + percent(data.effective_annual) + " efectivo anual). Elige los meses para ver el plan de pagos.</p></div>" +
      '<div class="scm-qfp-options" role="group" aria-label="Cuota según el plazo">' +
      terms.map(function (term) {
        var ev = data.evaluation;
        var flags = [];
        if (ev && ev.max_term > 0 && term > ev.max_term) flags.push("Supera plazo");
        if (ev && data.plans[term].payment > ev.available) flags.push("No alcanza");
        return '<button type="button" class="scm-qfp-option" data-scm-qfp-term="' + term + '" aria-pressed="false"><strong>' + months(term) + "</strong><span>" + money.format(data.plans[term].payment) + " al mes</span>" + (flags.length ? "<em>" + flags.join(" · ") + "</em>" : "") + "</button>";
      }).join("") +
      "</div>" +
      '<div class="scm-qfp-plan" data-scm-qfp-plan aria-live="polite"></div>' +
      (data.note ? '<p class="scm-qfp-note">' + escapeHtml(data.note) + "</p>" : "");
    if (data.evaluation) host.querySelector(".scm-qfp-head").insertAdjacentHTML("afterend", evaluationHtml(data.evaluation));
    var planBox = host.querySelector("[data-scm-qfp-plan]");

    function update(value) {
      var term = String(Number(value) || "");
      var plan = data.plans[term];
      host.querySelectorAll("[data-scm-qfp-term]").forEach(function (button) {
        button.setAttribute("aria-pressed", button.getAttribute("data-scm-qfp-term") === term ? "true" : "false");
      });
      if (!plan) {
        planBox.innerHTML = '<p class="scm-qfp-hint">' + (term !== "" ? "No hay simulación para " + months(term) + ": elige uno de los plazos de arriba." : "Elige un plazo o escribe los meses para ver el plan de pagos.") + "</p>";
        return;
      }
      var rows = plan.rows.map(function (row, index) {
        return "<tr><td>" + (index + 1) + "</td><td>" + money.format(row[0]) + "</td><td>" + money.format(row[1]) + "</td><td>" + money.format(row[2]) + "</td><td>" + money.format(row[3]) + "</td></tr>";
      }).join("");
      var checkList = data.evaluation ? '<ul class="scm-qfp-checks" data-scm-qfp-checks>' + checks(data, term).map(function (check) {
        return '<li class="' + (check.ok ? "is-ok" : "is-bad") + '"><b aria-hidden="true">' + (check.ok ? "✓" : "✗") + "</b><span>" + escapeHtml(check.text) + "</span></li>";
      }).join("") + "</ul>" : "";
      planBox.innerHTML =
        '<div class="scm-qfp-summary">' +
        '<div class="is-main"><span>Cuota mensual estimada</span><strong>' + money.format(plan.payment) + "</strong></div>" +
        "<div><span>Total intereses</span><strong>" + money.format(plan.interest) + "</strong></div>" +
        "<div><span>Total a pagar en " + months(term) + "</span><strong>" + money.format(plan.total) + "</strong></div></div>" +
        checkList +
        '<div class="scm-qfp-table"><table><thead><tr><th>Cuota</th><th>Valor cuota</th><th>Intereses</th><th>Abono a capital</th><th>Saldo</th></tr></thead><tbody>' + rows + "</tbody>" +
        "<tfoot><tr><td>Total</td><td>" + money.format(plan.total) + "</td><td>" + money.format(plan.interest) + "</td><td>" + money.format(data.amount) + "</td><td></td></tr></tfoot></table></div>";
    }

    host.addEventListener("click", function (event) {
      var button = event.target.closest("[data-scm-qfp-term]");
      if (!button) return;
      event.preventDefault();
      var term = button.getAttribute("data-scm-qfp-term");
      if (typeof onPick === "function") onPick(term);
      update(term);
    });
    update("");
    return { update: update };
  }

  /**
   * Simulación debajo del campo de meses de un formulario del panel: se crea después de anchor, sigue lo que se escribe
   * en input y, al elegir un plazo, lo escribe en input (dispara "input" para las validaciones del formulario).
   * @return {{setVisible: function(boolean): void}|null}
   */
  function attach(anchor, input, data) {
    if (!anchor || !input || !usable(data)) return null;
    var host = anchor.nextElementSibling && anchor.nextElementSibling.hasAttribute("data-scm-qfp") ? anchor.nextElementSibling : null;
    if (!host) {
      host = document.createElement("div");
      host.setAttribute("data-scm-qfp", "");
      anchor.insertAdjacentElement("afterend", host);
    }
    var view = mount(host, data, function (term) {
      input.value = term;
      input.dispatchEvent(new Event("input", { bubbles: true }));
    });
    input.addEventListener("input", function () { view.update(input.value); });
    view.update(input.value);
    return {
      setVisible: function (visible) {
        host.hidden = !visible;
        if (visible) view.update(input.value);
      },
    };
  }

  window.SCMQuoteFinancingPreview = { mount: mount, attach: attach, usable: usable };
})();
