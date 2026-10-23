(function () {
  "use strict";

  // Actividades administrativas › Reportes administrativos: pestañas internas (Por aprobar, Mis reportes,
  // Consolidado de pagos, Cuentas de cobro). El servidor arma cada listado (scm_admin_reports_*) con los
  // totales calculados en la base de datos; aquí se filtra, se selecciona y se confirman las acciones.
  var moneyFormat = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });
  var UI = {
    secondary: "!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-text-service-navy !sp-border !sp-border-solid !sp-border-slate-300 !sp-text-xs !sp-font-medium !sp-font-sans !sp-cursor-pointer hover:!sp-bg-slate-50",
    note: "!sp-m-0 !sp-text-[11px] !sp-text-service-muted",
    tabActive: ["!sp-bg-service-navy", "!sp-text-white", "!sp-border-service-navy"],
    tabIdle: ["!sp-bg-white", "!sp-text-service-navy", "!sp-border-slate-200", "hover:!sp-bg-slate-50"],
  };
  var ENDPOINTS = { own: "scm_admin_reports_own", consolidated: "scm_admin_reports_consolidated", accounts: "scm_admin_reports_accounts" };
  var PANEL_FORM = { mis_reportes: "own", consolidado: "consolidated", cuentas: "accounts" };

  function money(value) {
    return "$" + moneyFormat.format(Number(value || 0));
  }

  function digits(value) {
    return Number(String(value || "").replace(/[^\d]/g, "")) || 0;
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }

  function spinner() {
    return '<span class="!sp-inline-block !sp-w-3.5 !sp-h-3.5 !sp-rounded-full !sp-border-2 !sp-border-solid !sp-border-current !sp-border-t-transparent !sp-animate-spin" aria-hidden="true"></span>';
  }

  function init(root) {
    var core = window.SCMAdminCore;
    var module = root.querySelector("[data-scm-admin-reports]");
    if (!core || !module || module.dataset.arInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.arInit = "1";

    var modal = null;
    var loaded = {};
    var requestSeq = {};
    var timers = {};

    function notify(type, text) {
      if (typeof core.scmNotify === "function") core.scmNotify(type, text);
      else if (type === "error") window.alert(text);
    }

    function confirmAction(title, text, confirmText) {
      if (window.Swal && typeof window.Swal.fire === "function") {
        return window.Swal.fire({
          title: title, text: text, icon: "question", showCancelButton: true, reverseButtons: true,
          confirmButtonText: confirmText || "Sí, continuar", cancelButtonText: "Cancelar",
          confirmButtonColor: "#061d49", cancelButtonColor: "#64748b",
        }).then(function (result) { return !!result.isConfirmed; });
      }
      return Promise.resolve(window.confirm(title + "\n\n" + text));
    }

    function request(action, data) {
      var body = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) {
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
      }
      body.set("action", action);
      body.set("nonce", runtime.nonce || "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" });
    }

    function post(action, data) {
      return request(action, data)
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
          return json.data || {};
        });
    }

    /** PDF/Excel: el servidor responde el archivo; si falla, responde JSON con el mensaje. */
    function fetchFile(action, data, type) {
      return request(action, data).then(function (response) {
        if ((response.headers.get("Content-Type") || "").indexOf(type) === -1) {
          return response.json().catch(function () { return null; }).then(function (json) {
            throw new Error((json && json.data && json.data.message) || "No se pudo generar el archivo.");
          });
        }
        return response.blob();
      });
    }

    function busy(button, label) {
      if (!button) return function () {};
      var original = button.innerHTML;
      button.disabled = true;
      button.innerHTML = spinner() + escapeHtml(label);
      return function () {
        button.disabled = false;
        button.innerHTML = original;
      };
    }

    // Popup -------------------------------------------------------------------

    function ensureModal() {
      if (modal) return modal;
      modal = document.createElement("div");
      modal.className = "scm-cia-modal";
      modal.hidden = true;
      modal.setAttribute("data-admin-reports-modal", "");
      modal.innerHTML =
        '<div class="scm-cia-modal-backdrop" data-ar-modal-close></div>' +
        '<section tabindex="-1" style="width:min(1180px,calc(100vw - 24px));max-height:94vh" class="!sp-relative !sp-outline-none !sp-flex !sp-flex-col !sp-overflow-hidden !sp-rounded-2xl !sp-bg-service-background !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-2xl !sp-font-sans !sp-text-service-navy" role="dialog" aria-modal="true" aria-labelledby="scm-ar-modal-title">' +
        '<header class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-4 !sp-px-4 sm:!sp-px-6 !sp-py-4 !sp-bg-white !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">' +
        '<div class="!sp-min-w-0"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Reportes administrativos</p>' +
        '<h3 id="scm-ar-modal-title" class="!sp-m-0 !sp-text-lg !sp-font-bold" data-ar-modal-title></h3></div>' +
        '<button type="button" class="!sp-flex !sp-items-center !sp-justify-center !sp-w-9 !sp-h-9 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-service-navy !sp-text-lg !sp-cursor-pointer hover:!sp-bg-slate-50" data-ar-modal-close aria-label="Cerrar">&times;</button></header>' +
        '<div class="!sp-relative !sp-flex !sp-flex-col !sp-flex-1 !sp-min-h-0" data-ar-modal-body></div></section>';
      root.appendChild(modal);
      modal.addEventListener("keydown", function (event) {
        event.stopPropagation();
        if (event.key === "Escape") closeModal();
      });
      modal.addEventListener("click", onClick);
      modal.addEventListener("submit", onSubmit);
      modal.addEventListener("input", onInput);
      modal.addEventListener("change", onChange);
      return modal;
    }

    function showModal(title, html) {
      var box = ensureModal();
      box.querySelector("[data-ar-modal-title]").textContent = title;
      box.querySelector("[data-ar-modal-body]").innerHTML = html;
      box.hidden = false;
      document.body.classList.add("scm-cia-modal-open");
      box.querySelector("[role=dialog]").focus();
      return box;
    }

    function closeModal() {
      if (!modal) return;
      var viewerUrl = modal.getAttribute("data-ar-blob");
      if (viewerUrl) URL.revokeObjectURL(viewerUrl);
      modal.removeAttribute("data-ar-blob");
      modal.hidden = true;
      document.body.classList.remove("scm-cia-modal-open");
    }

    function message(text, tone) {
      return '<p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs ' + (tone === "error" ? "!sp-text-red-700 !sp-font-semibold" : "!sp-text-service-muted") + '">' + escapeHtml(text) + "</p>";
    }

    // Visor de PDF dentro del popup ("Generando PDF…" mientras Chromium lo arma).
    function showPdf(id, number) {
      var box = showModal("Cuenta de cobro " + number,
        '<div class="!sp-flex !sp-flex-col !sp-items-center !sp-justify-center !sp-gap-3 !sp-p-10" role="status" aria-live="polite">' + spinner() +
        '<strong class="!sp-text-sm">Generando PDF…</strong><span class="' + UI.note + '">Suele tardar unos segundos.</span></div>');
      return fetchFile("scm_admin_reports_account_pdf", { id: id }, "application/pdf")
        .then(function (blob) {
          if (box.hidden) return;
          var url = URL.createObjectURL(blob);
          box.setAttribute("data-ar-blob", url);
          box.querySelector("[data-ar-modal-body]").innerHTML =
            '<div class="!sp-flex !sp-justify-end !sp-gap-2 !sp-px-4 !sp-py-3 !sp-bg-white !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">' +
            '<button type="button" class="' + UI.secondary + '" data-ar-account-excel="' + escapeHtml(id) + '" data-number="' + escapeHtml(number) + '">Descargar Excel</button>' +
            '<a class="' + UI.secondary + '" href="' + url + '" download="cuenta_cobro_' + escapeHtml(number) + '.pdf">Descargar PDF</a></div>' +
            '<iframe style="display:block;width:100%;height:78vh;border:0;background:#fff" title="PDF de la cuenta de cobro" src="' + url + '"></iframe>';
        })
        .catch(function (error) {
          if (!box.hidden) box.querySelector("[data-ar-modal-body]").innerHTML = message(error.message, "error");
        });
    }

    function downloadExcel(id, number, button) {
      var done = busy(button, "Generando…");
      return fetchFile("scm_admin_reports_account_excel", { id: id }, "application/vnd.ms-excel")
        .then(function (blob) {
          var url = URL.createObjectURL(blob);
          var link = document.createElement("a");
          link.href = url;
          link.download = "cuenta_cobro_" + number + ".xls";
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
        })
        .catch(function (error) { notify("error", error.message); })
        .then(done);
    }

    // Pestañas y listados ------------------------------------------------------

    function setTabState(button, active) {
      button.setAttribute("aria-selected", active ? "true" : "false");
      UI.tabActive.forEach(function (cls) { button.classList.toggle(cls, active); });
      UI.tabIdle.forEach(function (cls) { button.classList.toggle(cls, !active); });
    }

    function activeTab() {
      var button = module.querySelector('[data-ar-tab][aria-selected="true"]');
      return button ? button.getAttribute("data-ar-tab") : "";
    }

    function selectTab(tab) {
      module.querySelectorAll("[data-ar-tab]").forEach(function (button) {
        setTabState(button, button.getAttribute("data-ar-tab") === tab);
      });
      module.querySelectorAll("[data-ar-panel]").forEach(function (panel) {
        panel.hidden = panel.getAttribute("data-ar-panel") !== tab;
      });
      var key = PANEL_FORM[tab];
      if (key && !loaded[key]) load(key);
    }

    function form(key) {
      return module.querySelector('[data-ar-filters="' + key + '"]');
    }

    function results(key) {
      return module.querySelector('[data-ar-results="' + key + '"]');
    }

    function load(key) {
      var filters = form(key);
      var box = results(key);
      if (!filters || !box) return Promise.resolve();
      loaded[key] = true;
      var seq = (requestSeq[key] || 0) + 1;
      requestSeq[key] = seq;
      box.style.opacity = "0.55";
      return post(ENDPOINTS[key], new FormData(filters))
        .then(function (data) {
          if (seq !== requestSeq[key]) return;
          box.innerHTML = data.html || "";
          refreshSelection(box);
        })
        .catch(function (error) {
          if (seq === requestSeq[key]) box.innerHTML = '<div class="!sp-bg-white !sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200">' + message(error.message, "error") + "</div>";
        })
        .then(function () {
          if (seq === requestSeq[key]) box.style.opacity = "";
        });
    }

    function reloadSoon(key) {
      window.clearTimeout(timers[key]);
      timers[key] = window.setTimeout(function () { load(key); }, 350);
    }

    function setField(key, name, value) {
      var input = form(key) && form(key).elements[name];
      if (input) input.value = value;
    }

    // Selección y totales de lo seleccionado ------------------------------------

    function checkedRows(box) {
      return Array.prototype.filter.call(box.querySelectorAll("[data-ar-check]"), function (input) { return input.checked && !input.disabled; })
        .map(function (input) { return input.closest("[data-ar-row]"); });
    }

    function refreshSelection(box) {
      if (!box) return;
      var rows = checkedRows(box);
      var total = rows.reduce(function (sum, row) { return sum + Number(row.getAttribute("data-total") || 0); }, 0);
      var kpi = box.querySelector('[data-ar-kpi="selected"]');
      if (kpi) kpi.textContent = rows.length + " · " + money(total);
      var pending = rows.some(function (row) { return row.hasAttribute("data-ar-dirty"); });
      ["[data-ar-create-account]", "[data-ar-pay-reports]", "[data-ar-pay-accounts]"].forEach(function (selector) {
        var button = box.querySelector(selector);
        if (button) button.disabled = rows.length === 0 || pending;
      });
      var all = box.querySelector("[data-ar-check-all]");
      var enabled = Array.prototype.filter.call(box.querySelectorAll("[data-ar-check]"), function (input) { return !input.disabled; });
      if (all) all.checked = enabled.length > 0 && rows.length === enabled.length;
    }

    function selectedIds(box) {
      return checkedRows(box).map(function (row) { return row.getAttribute("data-ar-row"); });
    }

    // Acciones -----------------------------------------------------------------

    function createAccount(button) {
      var box = results("own");
      var ids = selectedIds(box);
      if (!ids.length) return;
      confirmAction("Crear cuenta de cobro", "Se registrará la cuenta con " + ids.length + " reporte" + (ids.length === 1 ? "" : "s") + ", se generará el PDF y se descargará el Excel. Los reportes pasarán a En cuenta de cobro.", "Crear cuenta")
        .then(function (ok) {
          if (!ok) return;
          var done = busy(button, "Creando…");
          var employee = form("own").elements.employee;
          return post("scm_admin_reports_account_create", { ids: JSON.stringify(ids), employee: employee ? employee.value : "" })
            .then(function (data) {
              notify("success", data.message || "Cuenta de cobro creada.");
              load("own");
              if (loaded.accounts) load("accounts");
              downloadExcel(data.id, data.numero, null);
              showPdf(data.id, data.numero);
            })
            .catch(function (error) { notify("error", error.message); })
            .then(done);
        });
    }

    function payReports(ids, button) {
      if (!ids.length) return;
      confirmAction(ids.length === 1 ? "Marcar reporte como pagado" : "Marcar " + ids.length + " reportes como pagados", "Pasarán a la pestaña Pagados del consolidado.", "Sí, marcar")
        .then(function (ok) {
          if (!ok) return;
          var done = busy(button, "Procesando…");
          return post("scm_admin_reports_pay_reports", { ids: JSON.stringify(ids) })
            .then(function (data) { notify("success", data.message); load("consolidated"); })
            .catch(function (error) { notify("error", error.message); })
            .then(done);
        });
    }

    function payAccounts(ids, button) {
      if (!ids.length) return;
      confirmAction(ids.length === 1 ? "Marcar cuenta de cobro como pagada" : "Marcar " + ids.length + " cuentas como pagadas", "También se marcarán como pagados todos los reportes incluidos en " + (ids.length === 1 ? "la cuenta." : "las cuentas."), "Sí, marcar")
        .then(function (ok) {
          if (!ok) return;
          var done = busy(button, "Procesando…");
          return post("scm_admin_reports_pay_accounts", { ids: JSON.stringify(ids) })
            .then(function (data) {
              notify("success", data.message);
              if (modal && !modal.hidden) closeModal();
              ["accounts", "consolidated", "own"].forEach(function (key) { if (loaded[key]) load(key); });
            })
            .catch(function (error) { notify("error", error.message); })
            .then(done);
        });
    }

    function backfill(button) {
      var pending = Number(button.getAttribute("data-pending") || 0);
      confirmAction("Completar datos faltantes", pending > 0
        ? "Se completarán contrato, solicitud y arrendatario de " + pending + " reportes usando su contrato de arrendamiento."
        : "No se detectaron reportes pendientes, pero se puede ejecutar la revisión.", "Ejecutar")
        .then(function (ok) {
          if (!ok) return;
          var done = busy(button, "Completando…");
          return post("scm_admin_reports_backfill", {})
            .then(function (data) { notify("success", data.message); load("consolidated"); })
            .catch(function (error) { notify("error", error.message); done(); });
        });
    }

    function saveValues(row, button) {
      var servicio = digits(row.querySelector('[data-ar-money="servicio"]').value);
      var transporte = digits(row.querySelector('[data-ar-money="transporte"]').value);
      var done = busy(button, "Guardando…");
      post("scm_admin_reports_values", { id: row.getAttribute("data-ar-row"), servicio: servicio, transporte: transporte })
        .then(function (data) {
          row.removeAttribute("data-ar-dirty");
          row.setAttribute("data-servicio", data.servicio);
          row.setAttribute("data-transporte", data.transporte);
          row.setAttribute("data-total", data.total);
          notify("success", data.message);
          done();
          button.disabled = true;
          refreshSelection(results("own"));
        })
        .catch(function (error) { notify("error", error.message); done(); });
    }

    var REPORT_TITLES = { proceso: "Crear reporte administrativo", transporte: "Añadir transporte por visita" };

    // Paso 1: el popup pide el caso (y el funcionario del reporte); paso 2: el formulario con los datos del caso.
    function openReportForm(mode) {
      var template = module.querySelector("[data-ar-ticket-step-template]");
      if (!template) return;
      var box = showModal(REPORT_TITLES[mode] || "Nuevo reporte", template.innerHTML);
      var step = box.querySelector("[data-ar-ticket-step]");
      step.setAttribute("data-mode", mode);
      var input = step.querySelector("[data-ar-ticket-input]");
      if (input) input.focus();
    }

    function submitTicketStep(step) {
      var mode = step.getAttribute("data-mode") || "proceso";
      var status = step.querySelector("[data-ar-form-status]");
      var ticket = String(step.elements.id_ticket.value || "").replace(/[^\d]/g, "");
      if (!ticket) {
        if (status) status.textContent = "Escribe el número del caso.";
        return;
      }
      var employee = step.elements.employee;
      var done = busy(step.querySelector('[type="submit"]'), "Buscando caso…");
      if (status) status.textContent = "";
      post("scm_admin_reports_report_form", { id_ticket: ticket, form: mode, employee: employee ? employee.value : "" })
        .then(function (data) {
          var box = showModal(data.title || REPORT_TITLES[mode], data.html || "");
          updateFormTotal(box.querySelector("[data-ar-report-form]"));
        })
        .catch(function (error) {
          if (status) status.textContent = error.message;
          done();
        });
    }

    function updateFormTotal(reportForm) {
      if (!reportForm) return;
      var total = 0;
      reportForm.querySelectorAll("[data-ar-form-money]").forEach(function (input) { total += digits(input.value); });
      var label = reportForm.querySelector("[data-ar-form-total]");
      if (label) label.textContent = money(total);
    }

    function submitReport(reportForm) {
      var status = reportForm.querySelector("[data-ar-form-status]");
      var button = reportForm.querySelector('[type="submit"]');
      var data = new FormData(reportForm);
      reportForm.querySelectorAll("[data-ar-form-money]").forEach(function (input) { data.set(input.name, String(digits(input.value))); });
      var done = busy(button, "Guardando…");
      if (status) status.textContent = "";
      post("scm_admin_reports_report_create", data)
        .then(function (result) {
          closeModal();
          notify("success", result.message || "Reporte creado.");
          if (form("own")) {
            setField("own", "state", "no_exportados");
            load("own");
          }
        })
        .catch(function (error) {
          if (status) {
            status.textContent = error.message;
            status.classList.add("!sp-text-red-700", "!sp-font-semibold");
          }
          done();
        });
    }

    function openTicket(ticket) {
      var url = "https://sucasainmobiliaria.com.co/ticket/?id_ticket=" + encodeURIComponent(ticket);
      if (typeof core.openIframeModal === "function") core.openIframeModal(url, "Caso #" + ticket);
    }

    // Eventos ------------------------------------------------------------------

    function onClick(event) {
      var target = event.target;
      var el;
      if ((el = target.closest("[data-ar-modal-close]"))) { closeModal(); return; }
      if ((el = target.closest("[data-ar-tab]"))) { selectTab(el.getAttribute("data-ar-tab")); return; }
      if ((el = target.closest("[data-ar-state]"))) {
        var stateKey = el.closest("[data-ar-results]").getAttribute("data-ar-results");
        setField(stateKey, "state", el.getAttribute("data-ar-state"));
        setField(stateKey, "page", "1");
        load(stateKey);
        return;
      }
      if ((el = target.closest("[data-ar-page]"))) { setField("consolidated", "page", el.getAttribute("data-ar-page")); load("consolidated"); return; }
      if ((el = target.closest("[data-ar-ticket]"))) { openTicket(el.getAttribute("data-ar-ticket")); return; }
      if ((el = target.closest("[data-ar-open-url]"))) {
        if (typeof core.openIframeModal === "function") core.openIframeModal(el.getAttribute("data-ar-open-url"), "Evidencia del reporte");
        return;
      }
      if ((el = target.closest("[data-ar-create-account]"))) { createAccount(el); return; }
      if ((el = target.closest("[data-ar-pay-reports]"))) { payReports(selectedIds(results("consolidated")), el); return; }
      if ((el = target.closest("[data-ar-pay-report]"))) { payReports([el.getAttribute("data-ar-pay-report")], el); return; }
      if ((el = target.closest("[data-ar-pay-accounts]"))) { payAccounts(selectedIds(results("accounts")), el); return; }
      if ((el = target.closest("[data-ar-pay-account]"))) { payAccounts([el.getAttribute("data-ar-pay-account")], el); return; }
      if ((el = target.closest("[data-ar-backfill]"))) { backfill(el); return; }
      if ((el = target.closest("[data-ar-save-values]"))) { saveValues(el.closest("[data-ar-row]"), el); return; }
      if ((el = target.closest("[data-ar-open-report]"))) { openReportForm(el.getAttribute("data-ar-open-report")); return; }
      if ((el = target.closest("[data-ar-account-pdf]"))) { showPdf(el.getAttribute("data-ar-account-pdf"), el.getAttribute("data-number") || ""); return; }
      if ((el = target.closest("[data-ar-account-excel]"))) { downloadExcel(el.getAttribute("data-ar-account-excel"), el.getAttribute("data-number") || "", el); return; }
      if ((el = target.closest("[data-ar-account]"))) {
        var id = el.getAttribute("data-ar-account");
        showModal("Cuenta de cobro", message("Cargando cuenta…"));
        post("scm_admin_reports_account", { id: id })
          .then(function (data) { showModal(data.title || "Cuenta de cobro", data.html || ""); })
          .catch(function (error) { showModal("Cuenta de cobro", message(error.message, "error")); });
      }
    }

    function onInput(event) {
      var target = event.target;
      var filters = target.closest("[data-ar-filters]");
      if (filters) {
        var key = filters.getAttribute("data-ar-filters");
        setField(key, "page", "1");
        reloadSoon(key);
        return;
      }
      if (target.matches("[data-ar-money]")) {
        var row = target.closest("[data-ar-row]");
        var servicio = digits(row.querySelector('[data-ar-money="servicio"]').value);
        var transporte = digits(row.querySelector('[data-ar-money="transporte"]').value);
        row.setAttribute("data-ar-dirty", "1");
        row.setAttribute("data-total", servicio + transporte);
        row.querySelector("[data-ar-total]").textContent = money(servicio + transporte);
        var save = row.querySelector("[data-ar-save-values]");
        if (save) save.disabled = false;
        refreshSelection(target.closest("[data-ar-results]"));
        return;
      }
      if (target.matches("[data-ar-form-money]")) updateFormTotal(target.closest("[data-ar-report-form]"));
    }

    function onChange(event) {
      var target = event.target;
      var box = target.closest("[data-ar-results]");
      if (target.matches("[data-ar-check-all]") && box) {
        box.querySelectorAll("[data-ar-check]").forEach(function (input) { if (!input.disabled) input.checked = target.checked; });
        refreshSelection(box);
        return;
      }
      if (target.matches("[data-ar-check]") && box) { refreshSelection(box); return; }
      if (target.matches("[data-ar-money], [data-ar-form-money]")) {
        var value = digits(target.value);
        target.value = moneyFormat.format(value);
        if (target.matches("[data-ar-form-money]")) updateFormTotal(target.closest("[data-ar-report-form]"));
      }
    }

    function onSubmit(event) {
      var target = event.target;
      if (target.matches("[data-ar-filters]")) {
        event.preventDefault();
        load(target.getAttribute("data-ar-filters"));
        return;
      }
      if (target.matches("[data-ar-ticket-step]")) {
        event.preventDefault();
        submitTicketStep(target);
        return;
      }
      if (target.matches("[data-ar-report-form]")) {
        event.preventDefault();
        submitReport(target);
      }
    }

    module.addEventListener("click", onClick);
    module.addEventListener("input", onInput);
    module.addEventListener("change", onChange);
    module.addEventListener("submit", onSubmit);
    module.addEventListener("reset", function (event) {
      var filters = event.target.closest("[data-ar-filters]");
      if (!filters) return;
      // "Limpiar" no debe borrar el estado ni el funcionario elegido; se restauran tras el reset nativo.
      var keep = {};
      ["state", "employee"].forEach(function (name) { if (filters.elements[name]) keep[name] = filters.elements[name].value; });
      window.setTimeout(function () {
        Object.keys(keep).forEach(function (name) { filters.elements[name].value = keep[name]; });
        setField(filters.getAttribute("data-ar-filters"), "page", "1");
        load(filters.getAttribute("data-ar-filters"));
      }, 0);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal && !modal.hidden) closeModal();
    });

    // Al abrir Reportes administrativos (menú Actividades administrativas) se carga la pestaña visible.
    module.addEventListener("scm:load-admin-reports", function () {
      var key = PANEL_FORM[activeTab()];
      if (key && !loaded[key]) load(key);
    });
    var panel = module.closest(".scm-admin-activity-panel");
    if (panel && panel.classList.contains("active")) module.dispatchEvent(new CustomEvent("scm:load-admin-reports"));
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
