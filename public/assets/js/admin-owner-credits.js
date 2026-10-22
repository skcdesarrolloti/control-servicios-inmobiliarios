(function () {
  "use strict";

  // Créditos a propietarios: liquidador (popup sobre la cotización o desde Métricas › Créditos),
  // listado con totales, nuevo crédito manual y configuración. Todos los cálculos se hacen en el
  // servidor (scm_owner_credit_simulate); aquí solo se envía el formulario y se pinta el HTML.
  var moneyFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });

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
        '<section class="scm-cia-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scm-owner-credit-title">' +
        '<header><div><span class="scm-eyebrow">Liquidador de créditos</span><h3 id="scm-owner-credit-title" data-owner-credit-title>Crédito</h3>' +
        '<p data-owner-credit-subtitle>Simulador de créditos a propietarios.</p></div>' +
        '<button type="button" class="scm-cia-modal-close" data-owner-credit-dismiss aria-label="Cerrar">&times;</button></header>' +
        '<div data-owner-credit-body style="display:contents"></div></section>';
      root.appendChild(modal);
      return modal;
    }

    function showModal(title, subtitle, html) {
      var box = ensureModal();
      box.querySelector("[data-owner-credit-title]").textContent = title;
      box.querySelector("[data-owner-credit-subtitle]").textContent = subtitle;
      box.querySelector("[data-owner-credit-body]").innerHTML = html;
      box.hidden = false;
      document.body.classList.add("scm-cia-modal-open");
      var first = box.querySelector("input:not([readonly]), select:not([disabled]), textarea, button");
      if (first) first.focus();
    }

    function closeModal() {
      if (!modal) return;
      modal.hidden = true;
      document.body.classList.remove("scm-cia-modal-open");
    }

    function setStatus(form, message, tone) {
      var status = form && form.querySelector("[data-owner-credit-status]");
      if (!status) return;
      status.textContent = message || "";
      status.className = "scm-cia-modal-status" + (tone ? " is-" + tone : "");
    }

    function openCredit(params) {
      showModal("Cargando…", "Preparando el liquidador.", '<p class="scm-owner-credit-note" style="padding:24px">Cargando crédito…</p>');
      return post("scm_owner_credit_open", params)
        .then(function (data) {
          showModal(data.title || "Crédito", "Datos del contrato, políticas, condiciones y tabla de amortización.", data.html || "");
        })
        .catch(function (error) {
          showModal("No se pudo abrir", "", '<p class="scm-owner-credit-note is-bad" style="padding:24px">' + escapeHtml(error.message) + "</p>");
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
      if (results) results.classList.add("is-loading");
      if (schedule) schedule.classList.add("is-loading");
      post("scm_owner_credit_simulate", formData(form))
        .then(function (data) {
          if (seq !== simulateSeq) return;
          if (results) results.innerHTML = data.results_html || "";
          if (schedule) schedule.innerHTML = data.schedule_html || "";
          setStatus(form, "");
        })
        .catch(function (error) { if (seq === simulateSeq) setStatus(form, error.message, "error"); })
        .then(function () {
          if (seq !== simulateSeq) return;
          if (results) results.classList.remove("is-loading");
          if (schedule) schedule.classList.remove("is-loading");
        });
    }

    function formatMoneyInput(input) {
      var digits = String(input.value || "").replace(/[^\d]/g, "");
      input.value = digits === "" ? "" : "$" + moneyFormat.format(Number(digits));
    }

    function loadReport(force) {
      if (!reportModule) return;
      if (reportModule.getAttribute("data-scm-loaded") === "1" && !force) return;
      var target = reportModule.querySelector("[data-owner-credit-report]");
      var filters = reportModule.querySelector("[data-owner-credit-filters]");
      reportModule.setAttribute("data-scm-loaded", "1");
      if (target) target.classList.add("is-loading");
      post("scm_owner_credit_report", filters ? new FormData(filters) : {})
        .then(function (data) { if (target) target.innerHTML = data.html || ""; })
        .catch(function (error) {
          reportModule.setAttribute("data-scm-loaded", "0");
          if (target) target.innerHTML = '<p class="scm-owner-credits-empty">' + escapeHtml(error.message) + "</p>";
        })
        .then(function () { if (target) target.classList.remove("is-loading"); });
    }

    // Confirmación dentro del mismo popup (los diálogos externos quedarían detrás del modal).
    function askConfirm(button, text, onConfirm) {
      var actions = button.closest(".scm-public-services-review-actions");
      if (!actions) return;
      var old = actions.parentNode.querySelector("[data-owner-credit-confirm]");
      if (old) old.remove();
      var bar = document.createElement("div");
      bar.className = "scm-owner-credit-note is-bad";
      bar.setAttribute("data-owner-credit-confirm", "");
      bar.setAttribute("role", "alertdialog");
      bar.innerHTML = '<span>' + escapeHtml(text) + '</span> <button type="button" class="scm-btn-secondary" data-owner-credit-confirm-no>Cancelar</button> <button type="button" class="scm-btn-primary" data-owner-credit-confirm-yes>Sí, continuar</button>';
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
      if (target.closest("[data-owner-credit-new]")) {
        post("scm_owner_credit_new_form", {})
          .then(function (data) { showModal("Nuevo crédito", "Crédito sin cotización, a partir del contrato.", data.html || ""); })
          .catch(function (error) { window.alert(error.message); });
        return;
      }
      if (target.closest("[data-owner-credit-settings]")) {
        post("scm_owner_credit_settings", {})
          .then(function (data) { showModal("Configuración de créditos", "Variables de entrada del simulador y avisos.", data.html || ""); })
          .catch(function (error) { window.alert(error.message); });
      }
    });

    root.addEventListener("input", function (event) {
      var form = event.target.closest && event.target.closest("[data-owner-credit-form][data-credit-id]");
      if (!form || event.target.name === "note") return;
      window.clearTimeout(simulateTimer);
      simulateTimer = window.setTimeout(function () { simulate(form); }, 350);
    });
    root.addEventListener("change", function (event) {
      var form = event.target.closest && event.target.closest("[data-owner-credit-form][data-credit-id]");
      if (form && event.target.tagName === "SELECT") simulate(form);
    });
    root.addEventListener("focusout", function (event) {
      if (event.target.matches && event.target.matches("[data-owner-credit-money]:not([readonly])")) formatMoneyInput(event.target);
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
          .then(function (data) { setStatus(form, data.message || "Guardado.", "success"); })
          .catch(function (error) { setStatus(form, error.message, "error"); });
        return;
      }
      if (form.matches("[data-owner-credit-new-form]")) {
        event.preventDefault();
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
