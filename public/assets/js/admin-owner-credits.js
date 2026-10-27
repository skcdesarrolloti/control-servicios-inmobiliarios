(function () {
  "use strict";

  // Créditos a propietarios: liquidador (popup sobre la cotización o desde Métricas › Créditos),
  // listado con totales, nuevo crédito manual y configuración. Todos los cálculos se hacen en el
  // servidor (scm_owner_credit_simulate); aquí solo se envía el formulario y se pinta el HTML.
  var moneyFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });
  // Mismas primitivas Tailwind del panel (PublicServicesUi); este archivo está en tailwind.services.config.js.
  var UI = {
    primary: "!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-4 !sp-py-2.5 !sp-rounded-lg !sp-bg-service-yellow !sp-text-service-navy !sp-border !sp-border-solid !sp-border-service-yellow !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-amber-300",
    secondary: "!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-text-service-navy !sp-border !sp-border-solid !sp-border-slate-300 !sp-text-xs !sp-font-medium !sp-font-sans !sp-cursor-pointer hover:!sp-bg-slate-50",
    note: "!sp-m-0 !sp-text-[11px] !sp-text-service-muted",
    tones: { error: " !sp-text-red-700 !sp-font-semibold", success: " !sp-text-emerald-700 !sp-font-semibold", "": "" },
  };

  function init(root) {
    var core = window.SCMAdminCore;
    if (!core || root.dataset.ownerCreditsInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    root.dataset.ownerCreditsInit = "1";

    var modal = null;
    var simulateTimer = 0;
    var simulateSeq = 0;
    var reportModule = root.querySelector("[data-scm-owner-credits]");

    function post(action, data) {
      var body = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) {
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
      }
      body.set("action", action);
      body.set("nonce", runtime.nonce || "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
          return json.data || {};
        });
    }

    function ensureModal() {
      if (modal) return modal;
      modal = document.createElement("div");
      modal.className = "scm-cia-modal";
      modal.hidden = true;
      modal.setAttribute("data-owner-credit-modal", "");
      modal.innerHTML =
        '<div class="scm-cia-modal-backdrop" data-owner-credit-dismiss></div>' +
        '<section tabindex="-1" class="!sp-relative !sp-outline-none !sp-flex !sp-flex-col !sp-w-[min(1240px,calc(100vw-24px))] !sp-max-h-[94vh] !sp-overflow-hidden !sp-rounded-2xl !sp-bg-service-background !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-2xl !sp-font-sans" role="dialog" aria-modal="true" aria-labelledby="scm-owner-credit-title">' +
        '<header class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-4 !sp-px-4 sm:!sp-px-6 !sp-py-4 !sp-bg-white !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">' +
        '<div class="!sp-flex !sp-items-center !sp-gap-3 !sp-min-w-0"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-service-navy">' +
        '<svg class="!sp-w-5 !sp-h-5" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18v10H3V7zm9 2.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 1 0 0-5zM6 10v4m12-4v4"></path></svg></span>' +
        '<div class="!sp-min-w-0"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Liquidador de créditos</p>' +
        '<h3 id="scm-owner-credit-title" class="!sp-m-0 !sp-text-lg !sp-font-bold !sp-text-service-navy" data-owner-credit-title>Crédito</h3>' +
        '<p class="!sp-m-0 !sp-text-[11px] !sp-text-service-muted" data-owner-credit-subtitle>Simulador de créditos a propietarios.</p></div></div>' +
        '<button type="button" class="!sp-flex !sp-items-center !sp-justify-center !sp-w-9 !sp-h-9 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-service-navy !sp-text-lg !sp-cursor-pointer hover:!sp-bg-slate-50" data-owner-credit-dismiss aria-label="Cerrar">&times;</button></header>' +
        '<div class="!sp-flex !sp-flex-col !sp-flex-1 !sp-min-h-0" data-owner-credit-body></div></section>';
      root.appendChild(modal);
      // Si el popup de cotizaciones del caso (SweetAlert) sigue abierto detrás, sus atajos de teclado
      // (Tab, Escape) no deben recibir lo que se escribe aquí: Escape cierra solo el liquidador.
      modal.addEventListener("keydown", function (event) {
        event.stopPropagation();
        if (event.key === "Escape") closeModal();
        if (event.key === "Enter" && event.target.matches && event.target.matches("[data-owner-credit-search-query]")) {
          event.preventDefault();
          searchContracts(event.target.closest("[data-owner-credit-new-form]"));
        }
      });
      return modal;
    }

    function showModal(title, subtitle, html) {
      var box = ensureModal();
      box.querySelector("[data-owner-credit-title]").textContent = title;
      box.querySelector("[data-owner-credit-subtitle]").textContent = subtitle;
      box.querySelector("[data-owner-credit-body]").innerHTML = html;
      box.hidden = false;
      document.body.classList.add("scm-cia-modal-open");
      // El foco va al diálogo (no al botón cerrar): Tab recorre el formulario desde el inicio.
      box.querySelector("[role=dialog]").focus();
    }

    function closeModal() {
      if (!modal) return;
      modal.hidden = true;
      document.body.classList.remove("scm-cia-modal-open");
    }

    function setStatus(form, message, tone) {
      var status = form && form.querySelector("[data-owner-credit-status]");
      if (!status) return;
      if (!status.hasAttribute("data-base-class")) status.setAttribute("data-base-class", status.className);
      status.textContent = message || "";
      status.className = status.getAttribute("data-base-class") + (UI.tones[tone || ""] || "");
    }

    function message(text, tone) {
      return '<p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs ' + (tone === "error" ? "!sp-text-red-700 !sp-font-semibold" : "!sp-text-service-muted") + '">' + escapeHtml(text) + "</p>";
    }

    function setLoading(element, loading) {
      if (element) element.style.opacity = loading ? "0.55" : "";
    }

    function openCredit(params) {
      showModal("Cargando…", "Preparando el liquidador.", message("Cargando crédito…"));
      return post("scm_owner_credit_open", params)
        .then(function (data) {
          showModal(data.title || "Crédito", "Datos del contrato, políticas, condiciones y tabla de amortización.", data.html || "");
        })
        .catch(function (error) {
          showModal("No se pudo abrir", "", message(error.message, "error"));
        });
    }

    // Quien registra ve la confirmación; la revisión y la decisión se hacen después en el liquidador.
    function showRegistered(data) {
      var id = String(data.credit_id || "");
      showModal("Solicitud registrada", "La solicitud quedó pendiente de revisión.",
        '<div class="!sp-p-6 !sp-text-center" data-owner-credit-registered>' +
          '<p class="!sp-m-0 !sp-text-base !sp-font-bold !sp-text-emerald-700">' + escapeHtml(data.message || "Solicitud registrada.") + "</p>" +
          '<p class="!sp-mt-2 !sp-mb-0 !sp-text-xs !sp-text-service-muted">Crédito #' + escapeHtml(id) + ": queda en estado pendiente hasta que el analista la revise y decida en el liquidador.</p>" +
          '<div class="!sp-flex !sp-flex-wrap !sp-justify-center !sp-gap-2 !sp-mt-5">' +
            '<button type="button" class="!sp-px-4 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-xs !sp-font-semibold !sp-text-service-navy !sp-cursor-pointer hover:!sp-bg-slate-50" data-owner-credit-open data-credit-id="' + escapeHtml(id) + '">Ver solicitud</button>' +
            '<button type="button" class="!sp-px-4 !sp-py-2 !sp-rounded-lg !sp-bg-service-navy !sp-border-0 !sp-text-xs !sp-font-semibold !sp-text-white !sp-cursor-pointer" data-owner-credit-dismiss>Cerrar</button>' +
          "</div></div>");
    }

    function escapeHtml(value) {
      return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
      });
    }

    function formData(form) {
      var data = new FormData(form);
      data.set("credit_id", form.getAttribute("data-credit-id") || "");
      return data;
    }

    // La observación se ve cuando se pulsó una acción que la pide (o ya tiene texto).
    function syncNote(form) {
      var box = form.querySelector("[data-owner-credit-note-box]");
      if (!box) return;
      var show = !!form._noteRequested || box.querySelector("textarea").value.trim() !== "";
      if (show) box.style.removeProperty("display"); else box.style.setProperty("display", "none", "important");
    }

    // Rechazar, pagado, anular y aprobar fuera de política piden la observación: el primer clic la abre y el siguiente confirma.
    function requireNote(form, label) {
      var note = form.elements.note;
      if (!note || note.value.trim().length >= 10) return true;
      form._noteRequested = true;
      syncNote(form);
      note.focus();
      setStatus(form, "Escribe la observación (mínimo 10 caracteres) y vuelve a pulsar " + label + ".", "error");
      return false;
    }

    function simulate(form) {
      var seq = ++simulateSeq;
      var results = form.querySelector("[data-owner-credit-results]");
      var schedule = form.querySelector("[data-owner-credit-schedule]");
      setLoading(results, true);
      setLoading(schedule, true);
      post("scm_owner_credit_simulate", formData(form))
        .then(function (data) {
          if (seq !== simulateSeq) return;
          // Las guías abiertas siguen abiertas tras recalcular.
          var open = Array.prototype.map.call(form.querySelectorAll("[data-owner-credit-guide][open]"), function (el) { return el.getAttribute("data-owner-credit-guide"); });
          if (results) results.innerHTML = data.results_html || "";
          if (schedule) schedule.innerHTML = data.schedule_html || "";
          open.forEach(function (key) { var guide = form.querySelector('[data-owner-credit-guide="' + key + '"]'); if (guide) guide.open = true; });
          syncNote(form);
          setStatus(form, "");
        })
        .catch(function (error) { if (seq === simulateSeq) setStatus(form, error.message, "error"); })
        .then(function () {
          if (seq !== simulateSeq) return;
          setLoading(results, false);
          setLoading(schedule, false);
        });
    }

    // Pesos colombianos: $1.250.000 (sin decimales). Al escribir se conserva el cursor contando dígitos.
    function formatMoneyInput(input, keepCaret) {
      var raw = String(input.value || "");
      var caret = keepCaret && typeof input.selectionStart === "number" ? input.selectionStart : raw.length;
      var digitsBefore = raw.slice(0, caret).replace(/[^\d]/g, "").length;
      var digits = raw.replace(/[^\d]/g, "").replace(/^0+(?=\d)/, "");
      var formatted = digits === "" ? "" : "$" + moneyFormat.format(Number(digits));
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

    function loadReport(force) {
      if (!reportModule) return;
      if (reportModule.getAttribute("data-scm-loaded") === "1" && !force) return;
      var target = reportModule.querySelector("[data-owner-credit-report]");
      var filters = reportModule.querySelector("[data-owner-credit-filters]");
      reportModule.setAttribute("data-scm-loaded", "1");
      setLoading(target, true);
      post("scm_owner_credit_report", filters ? new FormData(filters) : {})
        .then(function (data) {
          if (!target) return;
          target.innerHTML = data.html || "";
          var total = target.querySelector("[data-owner-credit-total]");
          var count = reportModule.querySelector("[data-owner-credit-count]");
          if (count && total) count.textContent = total.getAttribute("data-owner-credit-total");
        })
        .catch(function (error) {
          reportModule.setAttribute("data-scm-loaded", "0");
          if (target) target.innerHTML = message(error.message, "error");
        })
        .then(function () { setLoading(target, false); });
    }

    // Nuevo crédito sin cotización: se busca al propietario por documento. Sus contratos son informativos: el de
    // vigencia más larga queda como principal (el analista lo puede cambiar en el liquidador).
    function searchContracts(form) {
      var query = form.querySelector("[data-owner-credit-search-query]").value.trim();
      var status = form.querySelector("[data-owner-credit-search-status]");
      var results = form.querySelector("[data-owner-credit-search-results]");
      var person = form.querySelector("[data-owner-credit-person]");
      var preapproval = form.querySelector("[data-owner-credit-preapproval]");
      var checks = form.querySelector("[data-owner-credit-checks]");
      pickContract(form, null);
      results.innerHTML = "";
      person.hidden = true;
      preapproval.hidden = true;
      form._preapproval = null;
      checks.hidden = true;
      if (query.replace(/[\s.]/g, "").length < 4) { status.textContent = "Escribe la cédula o el NIT (mínimo 4 caracteres)."; return; }
      status.textContent = "Buscando…";
      post("scm_owner_credit_contracts", { document: query })
        .then(function (data) {
          var contracts = data.contracts || [];
          if (!contracts.length) {
            status.textContent = "No encontramos contratos Entregado o Por entregar donde este documento sea de un propietario. Revisa el número.";
            return;
          }
          var owner = contracts[0].owner || {};
          var detail = [owner.document ? "Documento " + owner.document : "", owner.phone ? "Cel. " + owner.phone : "", owner.email || ""].filter(Boolean).join(" · ");
          person.innerHTML = '<strong class="!sp-block !sp-text-xs !sp-text-service-navy">' + escapeHtml(owner.name || "Propietario") + "</strong>" +
            '<span class="!sp-block !sp-text-[11px] !sp-text-service-muted">' + escapeHtml(detail || "Sin datos de contacto") + "</span>";
          person.hidden = false;
          form._preapproval = data.preapproval || null;
          renderPreapproval(preapproval, data.preapproval, data.disclaimer);
          renderChecks(checks, data.checks);
          status.textContent = contracts.length === 1 ? "Es propietario en 1 contrato." : "Es propietario en " + contracts.length + " contratos. El de vigencia más larga queda como contrato principal; el analista puede cambiarlo en el liquidador.";
          results.innerHTML = contracts.map(function (c, index) {
            return '<div data-owner-credit-contract-card class="!sp-p-3 !sp-rounded-lg !sp-border !sp-border-solid ' + (index === 0 ? "!sp-border-service-yellow !sp-bg-amber-50/40" : "!sp-border-slate-200 !sp-bg-white") + ' !sp-text-[11px] !sp-text-service-muted">' +
              '<span class="!sp-block !sp-min-w-0"><strong class="!sp-block !sp-text-xs !sp-text-service-navy">Contrato ' + escapeHtml(c.contrato) + " · SIMI " + escapeHtml(c.inmueble) + (index === 0 ? ' <span class="!sp-ml-1 !sp-px-1.5 !sp-py-0.5 !sp-rounded !sp-bg-service-navy !sp-text-white !sp-text-[9px]" data-owner-credit-main>Principal</span>' : "") + (c.siniestrado ? ' <span class="!sp-ml-1 !sp-text-red-700">· Siniestrado</span>' : "") + "</strong>" +
              '<span class="!sp-block">' + escapeHtml(c.direccion) + " · " + escapeHtml(c.estado) + (c.fin ? " · Vence " + escapeHtml(c.fin) : "") + "</span>" +
              (c.arrendatario ? '<span class="!sp-block">Arrendatario: ' + escapeHtml(c.arrendatario) + "</span>" : "") +
              '<span class="!sp-block">Canon ' + money(c.canon) + " − descuentos " + money(c.deductions) + " = ingreso neto <strong class=\"!sp-text-service-navy\">" + money(c.net_income) + "</strong>" + (c.fin ? " · " + escapeHtml(c.max_months) + " meses de vigencia" : "") + "</span></span></div>";
          }).join("");
          // El servidor los ordena con el de vigencia más larga primero: ese es el principal.
          pickContract(form, contracts[0]);
        })
        .catch(function (error) { status.textContent = error.message; });
    }

    function money(value) {
      return "$" + moneyFormat.format(Math.round(Number(value) || 0));
    }

    // Cupo con todos los contratos de la persona: Σ ingreso neto × meses de política; cuotas = las que vencen antes
    // del fin del contrato más largo, sin pasar del tope. Con el valor, la simulación revisa la cobertura de cada cuota.
    function renderPreapproval(box, p, disclaimer) {
      if (!box || !p || !p.contracts) return;
      var term = p.max_term > 0
        ? "Hasta " + p.max_term + " cuotas (" + (p.term_basis || "") + "); la simulación revisa la cobertura de cada cuota."
        : "Sin cuotas disponibles: ningún contrato tiene vigencia suficiente (" + (p.term_basis || "") + ").";
      box.innerHTML = '<span class="!sp-block !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Cupo preaprobado máximo</span>' +
        '<strong class="!sp-block !sp-text-base !sp-text-service-navy">' + money(p.outstanding > 0 ? p.available : p.amount) + "</strong>" +
        '<span class="!sp-block !sp-text-[11px] !sp-text-service-muted">Ingreso neto de ' + (p.contracts === 1 ? "1 contrato" : p.contracts + " contratos") + " " + money(p.net_income) + " × " + p.income_months + " meses = " + money(p.amount) + ". " + escapeHtml(term) + "</span>" +
        (p.outstanding > 0 ? '<span class="!sp-block !sp-text-[11px] !sp-font-semibold !sp-text-amber-800">Debe ' + money(p.outstanding) + " de créditos vigentes: queda disponible " + money(p.available) + ".</span>" : "") +
        '<span class="!sp-block !sp-mt-1 !sp-text-[11px] !sp-font-semibold !sp-text-service-navy" data-owner-credit-disclaimer>' + escapeHtml(disclaimer || "Valores de referencia sujetos a las políticas de crédito y a los términos y condiciones de la empresa: no constituyen una aprobación.") + "</span>";
      box.className = box.className.replace(/!sp-(bg|border)-(amber|red)-(50|200)/g, "") + (p.max_term > 0 ? " !sp-bg-amber-50 !sp-border-amber-200" : " !sp-bg-red-50 !sp-border-red-200");
      box.hidden = false;
    }

    // Revisión previa sobre todos los contratos: informa, no impide registrar.
    function renderChecks(box, checks) {
      if (!box || !checks || !checks.length) return;
      box.innerHTML = '<span class="!sp-block !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted !sp-mb-1">Revisión previa de políticas</span>' +
        '<ul class="!sp-m-0 !sp-p-0 !sp-list-none">' + checks.map(function (c) {
          return '<li class="!sp-flex !sp-gap-2 !sp-py-1 !sp-text-[11px]" data-owner-credit-check="' + (c.ok ? "ok" : "bad") + '"><b class="' + (c.ok ? "!sp-text-emerald-700" : "!sp-text-red-700") + '">' + (c.ok ? "✓" : "✗") + "</b>" +
            '<span><b class="!sp-text-service-navy">' + escapeHtml(c.label) + ":</b> " + escapeHtml(c.detail) + "</span></li>";
        }).join("") + '</ul><span class="!sp-block !sp-mt-1 !sp-text-[11px] !sp-text-service-muted">Es informativa: puedes registrar la solicitud y el analista decide en el liquidador.</span>';
      box.hidden = false;
    }

    // "Registrar solicitud" solo aparece después de ver la simulación con los datos actuales (valor, plazo,
    // destinación y contrato); si cambian, hay que volver a simular.
    function requestKey(form) {
      return ["contract_id", "owner_key", "amount", "term", "purpose"].map(function (name) { return form.elements[name] ? form.elements[name].value : ""; }).join("|");
    }

    function setRegistrable(form, simulated) {
      form._simulatedKey = simulated ? requestKey(form) : "";
      var button = form.querySelector("[data-owner-credit-register]");
      var hint = form.querySelector("[data-owner-credit-register-hint]");
      // El botón usa !sp-inline-flex (!important): ocultarlo también necesita !important.
      if (button) { if (simulated) button.style.removeProperty("display"); else button.style.setProperty("display", "none", "important"); }
      if (hint) hint.textContent = simulated ? "Simulación revisada: si el propietario está de acuerdo, registra la solicitud." : "Primero simula y revisa el PDF con el propietario; si está de acuerdo, registra la solicitud.";
    }

    function pickContract(form, contract) {
      var request = form.querySelector("[data-owner-credit-request]");
      setRegistrable(form, false);
      form.elements.contract_id.value = contract ? String(contract.id) : "";
      form.elements.owner_key.value = contract && contract.owner ? contract.owner.key : "";
      request.hidden = !contract;
      form._contract = contract;
      if (!contract) return;
      updateTermHint(form);
      form.elements.amount.focus();
    }

    // Solo informa: el consultor no conoce la fecha fin; si el plazo la supera, el analista la revisa en el liquidador.
    // Plazo máximo de la solicitud: el del preaprobado (cuotas antes del fin del contrato más largo, sin pasar del tope). Si sus
    // contratos no dan cuotas se permite hasta el tope, para que el analista revise la fecha fin en el liquidador.
    function termLimit(form) {
      var p = form._preapproval || {};
      return Number(p.max_term) > 0 ? Number(p.max_term) : (Number(p.max_term_cap) || 12);
    }

    function termFits(form) {
      return (Number(form.elements.term.value) || 0) <= termLimit(form);
    }

    function updateTermHint(form) {
      var hint = form.querySelector("[data-owner-credit-term-hint]");
      var p = form._preapproval;
      if (!hint || !form._contract || !p) return;
      var limit = termLimit(form);
      var term = Number(form.elements.term.value) || 0;
      var basis = p.term_basis || "";
      form.elements.term.max = String(limit);
      if (!(Number(p.max_term) > 0)) {
        hint.textContent = "Sus contratos no dan cuotas (" + basis + "). Puedes registrar hasta " + limit + " meses: el analista revisará la fecha fin en el liquidador.";
      } else if (term > limit) {
        hint.textContent = "El plazo máximo es de " + limit + " cuotas (" + basis + "). Corrige el plazo.";
      } else {
        hint.textContent = "Máximo " + limit + " cuotas (" + basis + ").";
      }
      hint.className = "!sp-m-0 !sp-mt-2 !sp-text-[11px] " + (term > limit ? "!sp-font-semibold !sp-text-red-700" : (Number(p.max_term) > 0 ? "!sp-text-service-muted" : "!sp-font-semibold !sp-text-amber-800"));
      // Mientras se escribe: campo en rojo y sin simular hasta corregir (el borde del input usa !important).
      var over = term > limit;
      form.elements.term.setAttribute("aria-invalid", over ? "true" : "false");
      if (over) form.elements.term.style.setProperty("border-color", "#dc2626", "important");
      else form.elements.term.style.removeProperty("border-color");
      var simulateButton = form.querySelector("[data-owner-credit-simulate-pdf]");
      if (simulateButton) {
        simulateButton.disabled = over;
        simulateButton.title = over ? "Corrige el plazo: máximo " + limit + " cuotas." : "";
      }
    }

    // Confirmación dentro del mismo popup (los diálogos externos quedarían detrás del modal).
    function askConfirm(button, text, onConfirm) {
      var actions = button.closest("[data-owner-credit-actions]") || button.closest("section") || button.parentNode;
      if (!actions) return;
      var old = actions.parentNode.querySelector("[data-owner-credit-confirm]");
      if (old) old.remove();
      var bar = document.createElement("div");
      bar.className = "!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-end !sp-gap-3 !sp-mt-3 !sp-p-3 !sp-rounded-lg !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-[11px] !sp-font-semibold !sp-text-service-navy";
      bar.setAttribute("data-owner-credit-confirm", "");
      bar.setAttribute("role", "alertdialog");
      bar.innerHTML = '<span class="!sp-flex-1 !sp-min-w-[200px]">' + escapeHtml(text) + '</span><button type="button" class="' + UI.secondary + '" data-owner-credit-confirm-no>Cancelar</button><button type="button" class="' + UI.primary + '" data-owner-credit-confirm-yes>Sí, continuar</button>';
      actions.parentNode.insertBefore(bar, actions.nextSibling);
      bar.querySelector("[data-owner-credit-confirm-no]").addEventListener("click", function () { bar.remove(); });
      bar.querySelector("[data-owner-credit-confirm-yes]").addEventListener("click", function () { bar.remove(); onConfirm(); });
      bar.querySelector("[data-owner-credit-confirm-yes]").focus();
    }

    function runAction(form, action, extra, successReload) {
      var data = formData(form);
      Object.keys(extra).forEach(function (key) { data.set(key, extra[key]); });
      var buttons = form.querySelectorAll("button");
      buttons.forEach(function (button) { button.disabled = true; });
      setStatus(form, "Guardando…", "");
      return post(action, data)
        .then(function (result) {
          loadReport(true);
          refreshQuoteButtons();
          return openCredit({ credit_id: form.getAttribute("data-credit-id") }).then(function () {
            var next = modal.querySelector("[data-owner-credit-form]");
            setStatus(next, result.message || "Guardado.", "success");
          });
        })
        .catch(function (error) {
          buttons.forEach(function (button) { button.disabled = false; });
          setStatus(form, error.message, "error");
        });
    }

    // PDF interno del crédito o simulación de la solicitud: se pide por POST (CSRF) y se muestra en un visor dentro
    // del mismo popup. El visor se abre de inmediato con "Generando PDF…" para que se note que está trabajando.
    function showPdf(button, options) {
      var box = ensureModal();
      var label = button ? button.innerHTML : "";
      if (button) {
        if (button.disabled) return;
        button.disabled = true;
        button.innerHTML = spinner() + "Generando PDF…";
      }
      var viewer = document.createElement("div");
      viewer.className = "!sp-absolute !sp-inset-0 !sp-z-20 !sp-flex !sp-flex-col !sp-bg-white !sp-rounded-2xl !sp-overflow-hidden";
      viewer.setAttribute("data-owner-credit-pdf-viewer", "");
      viewer.innerHTML = '<div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-3 !sp-px-4 !sp-py-3 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200 !sp-font-sans">' +
        '<strong class="!sp-text-sm !sp-text-service-navy">' + escapeHtml(options.title) + "</strong>" +
        '<div class="!sp-flex !sp-gap-2" data-owner-credit-pdf-actions><button type="button" class="' + UI.secondary + '" data-owner-credit-pdf-close>' + escapeHtml(options.back) + "</button></div></div>" +
        '<div class="!sp-flex-1 !sp-flex !sp-flex-col !sp-items-center !sp-justify-center !sp-gap-3 !sp-font-sans !sp-text-service-navy" data-owner-credit-pdf-body role="status" aria-live="polite">' +
        spinner("!sp-w-8 !sp-h-8") + '<strong class="!sp-text-sm">Generando PDF…</strong><span class="' + UI.note + '">Suele tardar unos segundos.</span></div>';
      box.querySelector("[role=dialog]").appendChild(viewer);
      var url = "";
      var closed = false;
      var restore = function () {
        if (!button) return;
        button.disabled = false;
        button.innerHTML = label;
      };
      viewer.querySelector("[data-owner-credit-pdf-close]").addEventListener("click", function () {
        closed = true;
        viewer.remove();
        if (url) URL.revokeObjectURL(url);
        restore();
      });
      var body = options.data;
      body.set("action", options.action);
      body.set("nonce", runtime.nonce || "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          if ((response.headers.get("Content-Type") || "").indexOf("application/pdf") === -1) {
            return response.json().catch(function () { return null; }).then(function (json) { throw new Error((json && json.data && json.data.message) || "No se pudo generar el PDF."); });
          }
          return response.blob();
        })
        .then(function (blob) {
          restore();
          if (closed) return;
          url = URL.createObjectURL(blob);
          viewer.querySelector("[data-owner-credit-pdf-actions]").insertAdjacentHTML("afterbegin", '<a class="' + UI.secondary + '" href="' + url + '" download="' + escapeHtml(options.filename) + '">Descargar</a>');
          var area = viewer.querySelector("[data-owner-credit-pdf-body]");
          area.className = "!sp-flex-1 !sp-flex";
          area.removeAttribute("role");
          area.innerHTML = '<iframe class="!sp-flex-1 !sp-w-full !sp-border-0" title="PDF del crédito" src="' + url + '"></iframe>';
          if (options.onReady) options.onReady(viewer);
        })
        .catch(function (error) {
          restore();
          if (closed) return;
          var area = viewer.querySelector("[data-owner-credit-pdf-body]");
          area.innerHTML = '<strong class="!sp-text-sm !sp-text-red-700">No se pudo generar el PDF</strong><span class="' + UI.note + '">' + escapeHtml(error.message) + "</span>";
        });
    }

    function spinner(size) {
      return '<svg class="' + (size || "!sp-w-3.5 !sp-h-3.5") + ' !sp-animate-spin" aria-hidden="true" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"></circle><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path></svg>';
    }

    // Las tarjetas muestran el estado del crédito en el botón; tras decidir se recargan los listados.
    function refreshQuoteButtons() {
      var panel = root.querySelector("#scm-panel-cotizaciones-mantenimiento");
      if (panel) panel.setAttribute("data-scm-loaded", "0");
    }

    // En el documento: las tarjetas de cotización también se muestran dentro del popup del caso, fuera de #scm-app.
    document.addEventListener("click", function (event) {
      var target = event.target;
      if (!(target instanceof Element)) return;

      var open = target.closest("[data-owner-credit-open]");
      if (open) {
        event.preventDefault();
        event.stopPropagation();
        var creditId = open.getAttribute("data-credit-id");
        openCredit(creditId ? { credit_id: creditId } : { quote_id: open.getAttribute("data-quote-id") || "" });
        return;
      }
      if (target.closest("[data-owner-credit-dismiss]")) { closeModal(); return; }

      var voidButton = target.closest("[data-owner-credit-void]");
      if (voidButton) {
        event.preventDefault();
        askConfirm(voidButton, "¿Anular la solicitud #" + voidButton.getAttribute("data-credit-id") + "? Deja de aparecer en el listado y en los totales.", function () {
          voidButton.disabled = true;
          post("scm_owner_credit_void", { credit_id: voidButton.getAttribute("data-credit-id") })
            .then(function () { loadReport(true); })
            .catch(function (error) { voidButton.disabled = false; showModal("No se pudo anular", "", message(error.message, "error")); });
        });
        return;
      }

      var form = target.closest("[data-owner-credit-form]");
      if (form && target.closest("[data-owner-credit-use-suggested]")) {
        var suggested = form.querySelector("[data-owner-credit-suggested]");
        if (!suggested) return;
        form.elements.amount.value = suggested.getAttribute("data-amount") || "";
        formatMoneyInput(form.elements.amount);
        form.elements.term.value = suggested.getAttribute("data-term") || "";
        form.elements.rate.value = suggested.getAttribute("data-rate") || "";
        simulate(form);
        return;
      }
      if (form && target.closest("[data-owner-credit-pdf]")) {
        var creditId = form.getAttribute("data-credit-id") || "";
        // El documento sale con lo que está en pantalla (canon, fecha fin, condiciones…), igual que el recálculo.
        showPdf(target.closest("[data-owner-credit-pdf]"), { title: "Documento del crédito #" + creditId, back: "Volver al liquidador", action: "scm_owner_credit_pdf", data: formData(form), filename: "credito-propietario-" + creditId + ".pdf" });
        return;
      }
      var decide = form && target.closest("[data-owner-credit-decide]");
      if (decide) {
        var decision = decide.getAttribute("data-owner-credit-decide");
        if (decision === "rechazado" && !requireNote(form, "Rechazar")) return;
        if (decision === "aprobado" && form.querySelector("[data-owner-credit-needs-note]") && !requireNote(form, "Aprobar crédito")) return;
        askConfirm(decide, decision === "aprobado"
          ? "Se aprobará el crédito con las condiciones del analista y se avisará al propietario."
          : "Se rechazará la solicitud y se avisará al propietario.", function () {
          runAction(form, "scm_owner_credit_decide", { decision: decision });
        });
        return;
      }
      var close = form && target.closest("[data-owner-credit-close]");
      if (close) {
        var status = close.getAttribute("data-owner-credit-close");
        if (!requireNote(form, status === "pagado" ? "Marcar como pagado" : "Anular")) return;
        askConfirm(close, status === "pagado" ? "El crédito quedará como pagado y dejará de contar como vigente." : "El crédito quedará anulado. Esta acción queda en el historial.", function () {
          runAction(form, "scm_owner_credit_close", { status: status });
        });
        return;
      }
      var newForm = target.closest("[data-owner-credit-new-form]");
      if (newForm && target.closest("[data-owner-credit-search]")) { searchContracts(newForm); return; }
      if (newForm && target.closest("[data-owner-credit-simulate-pdf]")) {
        if (!newForm.elements.contract_id.value) { setStatus(newForm, "Busca al propietario por documento.", "error"); return; }
        if (!termFits(newForm)) { setStatus(newForm, "El plazo supera el máximo de " + termLimit(newForm) + " cuotas.", "error"); return; }
        setStatus(newForm, "");
        var key = requestKey(newForm);
        showPdf(target.closest("[data-owner-credit-simulate-pdf]"), {
          title: "Simulación de crédito · Contrato " + (newForm._contract ? newForm._contract.contrato : ""), back: "Volver a la solicitud", action: "scm_owner_credit_simulation_pdf",
          data: new FormData(newForm), filename: "simulacion-credito-" + (newForm._contract ? newForm._contract.contrato : "") + ".pdf",
          onReady: function (viewer) {
            if (requestKey(newForm) !== key) return;
            setRegistrable(newForm, true);
            // Con valor y plazo escritos se puede registrar desde el mismo visor.
            if (!newForm.elements.amount.value || !newForm.elements.term.value) return;
            viewer.querySelector("[data-owner-credit-pdf-actions]").insertAdjacentHTML("beforeend", '<button type="button" class="' + UI.primary + '" data-owner-credit-pdf-register>Registrar solicitud</button>');
            viewer.querySelector("[data-owner-credit-pdf-register]").addEventListener("click", function () {
              viewer.querySelector("[data-owner-credit-pdf-close]").click();
              newForm.requestSubmit();
            });
          },
        });
        return;
      }
      if (target.closest("[data-owner-credit-new]")) {
        post("scm_owner_credit_new_form", {})
          .then(function (data) { showModal("Nueva solicitud de crédito", "Registra lo que pide el propietario; luego se evalúa y se aprueba o rechaza en el liquidador.", data.html || ""); })
          .catch(function (error) { window.alert(error.message); });
        return;
      }
      var refresh = target.closest("[data-owner-credit-usury-refresh]");
      if (refresh) {
        var settingsForm = refresh.closest("[data-owner-credit-settings-form]");
        refresh.disabled = true;
        setStatus(settingsForm, "Consultando datos.gov.co…", "");
        post("scm_owner_credit_settings", { mode: "refresh" })
          .then(function (data) {
            showModal("Configuración de créditos", "Variables de entrada del simulador y avisos.", data.html || "");
            setStatus(modal.querySelector("[data-owner-credit-settings-form]"), data.message || "", "success");
          })
          .catch(function (error) { refresh.disabled = false; setStatus(settingsForm, error.message, "error"); });
        return;
      }
      if (target.closest("[data-owner-credit-settings]")) {
        post("scm_owner_credit_settings", {})
          .then(function (data) { showModal("Configuración de créditos", "Variables de entrada del simulador y avisos.", data.html || ""); })
          .catch(function (error) { window.alert(error.message); });
      }
    });

    root.addEventListener("input", function (event) {
      if (event.target.matches && event.target.matches("[data-owner-credit-money]:not([readonly])")) formatMoneyInput(event.target, true);
      var newCredit = event.target.closest && event.target.closest("[data-owner-credit-new-form]");
      if (newCredit && event.target.name === "term") updateTermHint(newCredit);
      if (newCredit && newCredit._simulatedKey && newCredit._simulatedKey !== requestKey(newCredit)) setRegistrable(newCredit, false);
      var form = event.target.closest && event.target.closest("[data-owner-credit-form][data-credit-id]");
      if (!form || ["note", "owner_key", "main_contract_id"].indexOf(event.target.name) >= 0) return;
      window.clearTimeout(simulateTimer);
      simulateTimer = window.setTimeout(function () { simulate(form); }, 350);
    });
    root.addEventListener("change", function (event) {
      var newRequest = event.target.closest && event.target.closest("[data-owner-credit-new-form]");
      if (newRequest && newRequest._simulatedKey && newRequest._simulatedKey !== requestKey(newRequest)) setRegistrable(newRequest, false);
      var form = event.target.closest && event.target.closest("[data-owner-credit-form][data-credit-id]");
      // Cambiar a quién es el crédito o su contrato principal se guarda y recarga el liquidador.
      var owner = form && event.target.matches("[data-owner-credit-owner]");
      if (owner || (form && event.target.matches("[data-owner-credit-main-contract]"))) {
        var creditId = form.getAttribute("data-credit-id");
        var change = owner ? { action: "scm_owner_credit_owner", key: "owner_key" } : { action: "scm_owner_credit_contract", key: "contract_id" };
        var payload = { credit_id: creditId };
        payload[change.key] = event.target.value;
        event.target.disabled = true;
        post(change.action, payload)
          .then(function () { loadReport(true); return openCredit({ credit_id: creditId }); })
          .catch(function (error) { event.target.disabled = false; window.alert(error.message); });
        return;
      }
      // Selects y fecha fin recalculan al cambiar (algunos navegadores no emiten "input" en fechas).
      if (form && (event.target.tagName === "SELECT" || event.target.type === "date")) simulate(form);
    });
    root.addEventListener("focusout", function (event) {
      if (event.target.matches && event.target.matches("[data-owner-credit-money]:not([readonly])")) formatMoneyInput(event.target);
    });

    root.addEventListener("reset", function (event) {
      if (event.target.matches && event.target.matches("[data-owner-credit-filters]")) window.setTimeout(function () { loadReport(true); }, 0);
    });

    root.addEventListener("submit", function (event) {
      var form = event.target;
      if (form.matches("[data-owner-credit-filters]")) {
        event.preventDefault();
        loadReport(true);
        return;
      }
      if (form.matches("[data-owner-credit-settings-form]")) {
        event.preventDefault();
        var settings = new FormData(form);
        settings.set("mode", "save");
        setStatus(form, "Guardando…", "");
        post("scm_owner_credit_settings", settings)
          .then(function (data) {
            if (data.html) showModal("Configuración de créditos", "Variables de entrada del simulador y avisos.", data.html);
            setStatus(modal.querySelector("[data-owner-credit-settings-form]"), data.message || "Guardado.", "success");
          })
          .catch(function (error) { setStatus(form, error.message, "error"); });
        return;
      }
      // Abono extraordinario: solo simula, en el mismo popup, sin tocar el liquidador.
      if (form.matches("[data-owner-credit-prepay-form]")) {
        event.preventDefault();
        var prepay = new FormData(form);
        prepay.set("credit_id", form.getAttribute("data-credit-id") || "");
        var prepayResults = form.querySelector("[data-owner-credit-prepay-results]");
        setStatus(form, "Simulando…", "");
        setLoading(prepayResults, true);
        post("scm_owner_credit_prepayment", prepay)
          .then(function (data) { if (prepayResults) prepayResults.innerHTML = data.html || ""; setStatus(form, ""); })
          .catch(function (error) { setStatus(form, error.message, "error"); })
          .then(function () { setLoading(prepayResults, false); });
        return;
      }
      if (form.matches("[data-owner-credit-new-form]")) {
        event.preventDefault();
        if (!form.elements.contract_id.value) { setStatus(form, "Busca al propietario por documento.", "error"); return; }
        if (!termFits(form)) { setStatus(form, "El plazo supera el máximo de " + termLimit(form) + " cuotas.", "error"); return; }
        if (!form._simulatedKey || form._simulatedKey !== requestKey(form)) { setRegistrable(form, false); setStatus(form, "Primero simula y revisa el PDF con los datos actuales.", "error"); return; }
        setStatus(form, "Registrando…", "");
        post("scm_owner_credit_create", new FormData(form))
          .then(function (data) { loadReport(true); showRegistered(data); })
          .catch(function (error) { setStatus(form, error.message, "error"); });
        return;
      }
      if (form.matches("[data-owner-credit-form]")) event.preventDefault();
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal && !modal.hidden) closeModal();
    });

    if (reportModule) {
      reportModule.addEventListener("scm:load-owner-credits", function () { loadReport(false); });
      var panel = reportModule.closest(".scm-tab-panel");
      if (panel && panel.classList.contains("active")) loadReport(false);
    }
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
