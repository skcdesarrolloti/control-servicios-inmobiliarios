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

    // Nuevo crédito sin cotización: se busca el contrato (como en Crear caso) y luego se piden los datos.
    function searchContracts(form) {
      var query = form.querySelector("[data-owner-credit-search-query]").value.trim();
      var status = form.querySelector("[data-owner-credit-search-status]");
      var results = form.querySelector("[data-owner-credit-search-results]");
      if (query.length < 2) { status.textContent = "Escribe al menos 2 caracteres."; return; }
      status.textContent = "Buscando contratos…";
      results.innerHTML = "";
      post("scm_owner_credit_contracts", { query: query, by: form.querySelector("[data-owner-credit-search-by]").value })
        .then(function (data) {
          var contracts = data.contracts || [];
          status.textContent = contracts.length ? contracts.length + " resultado(s). Selecciona el contrato." : "No se encontraron contratos. Revisa el dato o cambia el tipo de búsqueda.";
          results.innerHTML = contracts.map(function (c) {
            return '<button type="button" class="!sp-grid !sp-gap-0.5 !sp-w-full !sp-p-3 !sp-rounded-lg !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-left !sp-font-sans !sp-text-[11px] !sp-text-service-muted !sp-cursor-pointer hover:!sp-border-service-yellow hover:!sp-bg-amber-50/40 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow" data-owner-credit-pick data-contract="' + escapeHtml(JSON.stringify(c)) + '">' +
              '<strong class="!sp-text-xs !sp-text-service-navy">Contrato ' + escapeHtml(c.contrato) + " · SIMI " + escapeHtml(c.inmueble) + "</strong>" +
              "<span>" + escapeHtml(c.direccion) + " · " + escapeHtml(c.estado) + (c.fin ? " · Fin " + escapeHtml(c.fin) : "") + "</span>" +
              "<span>Propietario: " + escapeHtml(c.propietario) + " · Arrendatario: " + escapeHtml(c.arrendatario) + "</span></button>";
          }).join("");
        })
        .catch(function (error) { status.textContent = error.message; });
    }

    function pickContract(form, contract) {
      var picked = form.querySelector("[data-owner-credit-picked]");
      var request = form.querySelector("[data-owner-credit-request]");
      form.elements.contract_id.value = contract ? String(contract.id) : "";
      form.querySelector("[data-owner-credit-search-results]").hidden = !!contract;
      picked.hidden = !contract;
      request.hidden = !contract;
      if (!contract) return;
      picked.innerHTML = "<span><b>Contrato " + escapeHtml(contract.contrato) + "</b> · " + escapeHtml(contract.propietario) + " · " + escapeHtml(contract.direccion) + "</span>" +
        '<button type="button" class="!sp-p-0 !sp-bg-transparent !sp-border-0 !sp-text-[11px] !sp-font-semibold !sp-text-service-blue !sp-underline !sp-cursor-pointer" data-owner-credit-unpick>Cambiar contrato</button>';
      form.elements.contract_end.value = contract.fin_iso || "";
      updateTermHint(form);
      form.elements.amount.focus();
    }

    // Mismo cálculo del servidor: meses completos de 30 días entre hoy y la fecha fin.
    function updateTermHint(form) {
      var hint = form.querySelector("[data-owner-credit-term-hint]");
      var end = form.elements.contract_end.value;
      if (!hint) return;
      if (!end) { hint.textContent = "El contrato no tiene fecha fin: indícala para calcular el plazo máximo."; return; }
      var today = new Date(); today.setHours(0, 0, 0, 0);
      var max = Math.floor(Math.floor((new Date(end + "T00:00:00") - today) / 86400000) / 30);
      form.elements.term.max = String(Math.max(1, max));
      hint.textContent = max < 1 ? "El contrato ya terminó o termina este mes: corrige la fecha fin si fue renovado." : "Plazo máximo: " + max + " meses.";
      hint.className = "!sp-m-0 !sp-mt-1 !sp-text-[11px] !sp-font-semibold " + (max < 1 || Number(form.elements.term.value) > max ? "!sp-text-red-700" : "!sp-text-emerald-700");
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

    // PDF interno: se pide por POST (CSRF) y se muestra en un visor dentro del mismo popup.
    function showPdf(form) {
      var box = ensureModal();
      var body = new FormData();
      body.set("action", "scm_owner_credit_pdf");
      body.set("nonce", runtime.nonce || "");
      body.set("credit_id", form.getAttribute("data-credit-id") || "");
      setStatus(form, "Generando PDF…", "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          if ((response.headers.get("Content-Type") || "").indexOf("application/pdf") === -1) {
            return response.json().then(function (json) { throw new Error((json && json.data && json.data.message) || "No se pudo generar el PDF."); });
          }
          return response.blob();
        })
        .then(function (blob) {
          setStatus(form, "");
          var url = URL.createObjectURL(blob);
          var viewer = document.createElement("div");
          viewer.className = "!sp-absolute !sp-inset-0 !sp-z-20 !sp-flex !sp-flex-col !sp-bg-white !sp-rounded-2xl !sp-overflow-hidden";
          viewer.setAttribute("data-owner-credit-pdf-viewer", "");
          viewer.innerHTML = '<div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-3 !sp-px-4 !sp-py-3 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200 !sp-font-sans">' +
            '<strong class="!sp-text-sm !sp-text-service-navy">PDF interno del crédito #' + escapeHtml(form.getAttribute("data-credit-id")) + "</strong>" +
            '<div class="!sp-flex !sp-gap-2"><a class="' + UI.secondary + '" href="' + url + '" download="credito-propietario-' + escapeHtml(form.getAttribute("data-credit-id")) + '.pdf">Descargar</a>' +
            '<button type="button" class="' + UI.primary + '" data-owner-credit-pdf-close>Volver al liquidador</button></div></div>' +
            '<iframe class="!sp-flex-1 !sp-w-full !sp-border-0" title="PDF del crédito" src="' + url + '"></iframe>';
          box.querySelector("[role=dialog]").appendChild(viewer);
          viewer.querySelector("[data-owner-credit-pdf-close]").addEventListener("click", function () { viewer.remove(); URL.revokeObjectURL(url); });
        })
        .catch(function (error) { setStatus(form, error.message, "error"); window.alert(error.message); });
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
      if (form && target.closest("[data-owner-credit-pdf]")) { showPdf(form); return; }
      var decide = form && target.closest("[data-owner-credit-decide]");
      if (decide) {
        var decision = decide.getAttribute("data-owner-credit-decide");
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
        askConfirm(close, status === "pagado" ? "El crédito quedará como pagado y dejará de contar como vigente." : "El crédito quedará anulado. Esta acción queda en el historial.", function () {
          runAction(form, "scm_owner_credit_close", { status: status });
        });
        return;
      }
      var newForm = target.closest("[data-owner-credit-new-form]");
      if (newForm && target.closest("[data-owner-credit-search]")) { searchContracts(newForm); return; }
      var pick = newForm && target.closest("[data-owner-credit-pick]");
      if (pick) { pickContract(newForm, JSON.parse(pick.getAttribute("data-contract") || "{}")); return; }
      if (newForm && target.closest("[data-owner-credit-unpick]")) { pickContract(newForm, null); return; }
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
      if (newCredit && (event.target.name === "contract_end" || event.target.name === "term")) updateTermHint(newCredit);
      var form = event.target.closest && event.target.closest("[data-owner-credit-form][data-credit-id]");
      if (!form || event.target.name === "note") return;
      window.clearTimeout(simulateTimer);
      simulateTimer = window.setTimeout(function () { simulate(form); }, 350);
    });
    root.addEventListener("change", function (event) {
      var form = event.target.closest && event.target.closest("[data-owner-credit-form][data-credit-id]");
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
      if (form.matches("[data-owner-credit-new-form]")) {
        event.preventDefault();
        if (!form.elements.contract_id.value) { setStatus(form, "Busca y selecciona el contrato.", "error"); return; }
        setStatus(form, "Creando…", "");
        post("scm_owner_credit_create", new FormData(form))
          .then(function (data) { loadReport(true); return openCredit({ credit_id: data.credit_id }); })
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
