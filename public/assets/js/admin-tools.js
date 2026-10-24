(function () {
  "use strict";

  // Herramientas del encabezado (src/Modules/Tools/ToolsView.php):
  // - Generar PSE: sube el Excel de facturación al servidor, que arma los dos .txt y los guarda en el archivador.
  // - Calculadora de mora: copia de herramientas/cal-mora/assets/js/app.js; el cálculo y la tasa SFC se piden
  //   al servidor y el Excel y los PDF se resuelven en el navegador (SheetJS y jsPDF, mismas versiones).
  var LIBS = {
    xlsx: "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js",
    jspdf: "https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js",
    autotable: "https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js",
  };
  var scriptLoads = {};

  function loadScript(url) {
    if (!scriptLoads[url]) {
      scriptLoads[url] = new Promise(function (resolve, reject) {
        var script = document.createElement("script");
        script.src = url;
        script.async = true;
        script.onload = resolve;
        script.onerror = function () {
          delete scriptLoads[url];
          reject(new Error("No se pudo cargar una librería necesaria. Revisa la conexión e intenta de nuevo."));
        };
        document.head.appendChild(script);
      });
    }
    return scriptLoads[url];
  }

  function init(root) {
    var core = window.SCMAdminCore;
    if (!core || root.dataset.toolsInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    root.dataset.toolsInit = "1";

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

    var pse = root.querySelector('[data-scm-tool="pse"]');
    if (pse) initPse(pse, core, request, post);
    var mora = root.querySelector('[data-scm-tool="mora"]');
    if (mora) initMora(mora, post);
    // Si la página abrió directo en una herramienta, el runtime pudo pedir la carga antes de este script.
    [pse, mora].forEach(function (module) {
      var panel = module && module.closest(".scm-tab-panel");
      if (panel && panel.classList.contains("active")) module.dispatchEvent(new CustomEvent("scm:load-tool"));
    });
  }

  // ── Generar PSE ─────────────────────────────────────────────────────────
  function initPse(module, core, request, post) {
    var form = module.querySelector("[data-pse-form]");
    var archive = module.querySelector("[data-pse-archive]");
    var result = module.querySelector("[data-pse-result]");
    var status = module.querySelector("[data-pse-status]");
    var submit = module.querySelector("[data-pse-submit]");
    var notify = typeof core.scmNotify === "function" ? core.scmNotify : function () {};
    var filter = module.querySelector("[data-pse-archive-filter]");

    function applyFilter() {
      var term = String((filter && filter.value) || "").trim().toLowerCase();
      archive.querySelectorAll("[data-pse-archive-item]").forEach(function (item) {
        var hide = term !== "" && String(item.getAttribute("data-pse-archive-item") || "").toLowerCase().indexOf(term) === -1;
        // Las filas usan !sp-flex (display con !important): ocultarlas también con prioridad.
        if (hide) item.style.setProperty("display", "none", "important");
        else item.style.removeProperty("display");
      });
    }

    function loadArchive() {
      return post("scm_tools_pse_archive", {})
        .then(function (data) {
          archive.innerHTML = data.html || "";
          applyFilter();
        })
        .catch(function (error) {
          archive.innerHTML = '<p class="!sp-m-0 !sp-p-6 !sp-text-center !sp-text-xs !sp-text-red-700">' + escapeHtml(error.message) + "</p>";
        });
    }

    function download(name, button) {
      if (button) button.disabled = true;
      request("scm_tools_pse_download", { name: name })
        .then(function (response) {
          var type = response.headers.get("Content-Type") || "";
          if (type.indexOf("application/json") !== -1) {
            return response.json().then(function (json) {
              throw new Error((json && json.data && json.data.message) || "No se pudo descargar el archivo.");
            });
          }
          if (!response.ok) throw new Error("No se pudo descargar el archivo.");
          return response.blob();
        })
        .then(function (blob) {
          var link = document.createElement("a");
          link.href = URL.createObjectURL(blob);
          link.download = name;
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        })
        .catch(function (error) { notify("error", error.message, "Generar PSE"); })
        .finally(function () { if (button) button.disabled = false; });
    }

    module.addEventListener("scm:load-tool", function () {
      if (module.dataset.scmLoaded === "1") return;
      module.dataset.scmLoaded = "1";
      loadArchive();
    });
    if (filter) filter.addEventListener("input", applyFilter);

    module.addEventListener("click", function (event) {
      var refresh = event.target.closest("[data-pse-archive-refresh]");
      if (refresh) {
        loadArchive();
        return;
      }
      var file = event.target.closest("[data-pse-download]");
      if (file) download(file.getAttribute("data-pse-download") || "", file);
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var missing = Array.prototype.filter.call(form.querySelectorAll("[required]"), function (input) {
        return input.type === "file" ? !input.files || !input.files.length : String(input.value || "").trim() === "";
      });
      if (missing.length) {
        missing[0].focus();
        status.textContent = "Completa las fechas, los porcentajes y el Excel antes de generar.";
        return;
      }
      submit.disabled = true;
      status.textContent = "Leyendo el Excel y generando los archivos…";
      result.innerHTML = "";
      post("scm_tools_pse_generate", new FormData(form))
        .then(function (data) {
          result.innerHTML = data.result_html || "";
          archive.innerHTML = data.archive_html || archive.innerHTML;
          applyFilter();
          status.textContent = "";
          notify("success", data.message || "Archivos PSE generados.", "Generar PSE");
        })
        .catch(function (error) {
          status.textContent = error.message;
          notify("error", error.message, "Generar PSE");
        })
        .finally(function () { submit.disabled = false; });
    });
  }

  // ── Calculadora de mora (port de cal-mora/assets/js/app.js) ────────────────
  var STATUS_TONES = {
    info: { box: "!sp-bg-slate-50 !sp-border-slate-200 !sp-text-service-navy", icon: "info" },
    success: { box: "!sp-bg-emerald-50 !sp-border-emerald-200 !sp-text-emerald-800", icon: "check_circle" },
    warn: { box: "!sp-bg-amber-50 !sp-border-amber-200 !sp-text-amber-900", icon: "warning" },
    error: { box: "!sp-bg-red-50 !sp-border-red-200 !sp-text-red-700", icon: "error" },
  };
  var CELL_INPUT = "!sp-block !sp-box-border !sp-w-full !sp-h-8 !sp-px-2 !sp-border !sp-border-solid !sp-border-slate-300 !sp-rounded-md !sp-bg-white !sp-text-service-navy !sp-text-[11px] !sp-font-sans focus:!sp-outline-none focus:!sp-ring-2 focus:!sp-ring-service-yellow";
  var CELL = "!sp-px-2 !sp-py-2 !sp-align-middle !sp-border-0 !sp-border-t !sp-border-solid !sp-border-slate-100";
  var METHOD_BADGES = {
    interes_mora: "!sp-bg-blue-50 !sp-text-blue-800 !sp-border-blue-200",
    clausula_penal: "!sp-bg-amber-50 !sp-text-amber-900 !sp-border-amber-200",
    none: "!sp-bg-slate-100 !sp-text-service-muted !sp-border-slate-200",
  };

  function initMora(module, post) {
    function el(name) {
      return module.querySelector('[data-mora="' + name + '"]');
    }

    var annualRateInput = el("annualRate");
    var globalPenaltyInput = el("globalPenaltyPct");
    var globalPaymentInput = el("globalPaymentDate");
    var clientNameInput = el("clientName");
    var clientDocumentInput = el("clientDocument");
    var clientDescriptionInput = el("clientDescription");
    var moraAnnualInput = el("moraAnnualRate");
    var moraMonthlyInput = el("moraMonthlyRate");

    var resolutionLabel = el("resolutionLabel");
    var vigenciaText = el("vigenciaText");
    var updatedAtText = el("updatedAtText");
    var resolutionLink = el("resolutionLink");
    var bulletinLink = el("bulletinLink");
    var statusBox = el("statusBox");
    var statusIcon = el("statusIcon");
    var statusText = el("statusText");

    var rowsBody = el("installmentsBody");
    var excelInput = el("excelInput");

    var kpiCapital = el("kpiCapital");
    var kpiInterest = el("kpiInterest");
    var kpiPenalty = el("kpiPenalty");
    var kpiRecommended = el("kpiRecommended");
    var chargeDescriptionText = el("chargeDescriptionText");
    var accountingEntryText = el("accountingEntryText");
    var paymentDetailText = el("paymentDetailText");
    var payBreakdownCanon = el("payBreakdownCanon");
    var payBreakdownServices = el("payBreakdownServices");
    var payBreakdownInterest = el("payBreakdownInterest");
    var payBreakdownRecargo = el("payBreakdownRecargo");
    var payBreakdownTotal = el("payBreakdownTotal");
    var payBreakdownPaid = el("payBreakdownPaid");
    var payBreakdownSaldo = el("payBreakdownSaldo");
    var applyPayTotal = el("applyPayTotal");
    var applyPayServicesTotal = el("applyPayServicesTotal");
    var applyPayInterestTotal = el("applyPayInterestTotal");
    var applyPayCanonTotal = el("applyPayCanonTotal");
    var applyPayPending = el("applyPayPending");

    var rowCounter = 0;
    var latestCalculationData = null;
    var latestSuperfinData = null;
    var pdfLogo = null;

    var skcLogoUrl = "https://sucasainmobiliaria.com.co/wp-content/uploads/2022/06/logo-skc-registrado-2048x790.png";

    var currency = new Intl.NumberFormat("es-CO", {
      style: "currency",
      currency: "COP",
      minimumFractionDigits: 2,
    });

    var dateFormat = new Intl.DateTimeFormat("es-CO", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });

    function setStatus(message, kind) {
      var tone = STATUS_TONES[kind] || STATUS_TONES.info;
      statusText.textContent = message;
      statusIcon.textContent = tone.icon;
      Object.keys(STATUS_TONES).forEach(function (key) {
        STATUS_TONES[key].box.split(" ").forEach(function (cls) { statusBox.classList.remove(cls); });
      });
      tone.box.split(" ").forEach(function (cls) { statusBox.classList.add(cls); });
    }

    function setReadonlyRateFields() {
      var annualRate = asNumber(annualRateInput.value);
      var moraAnnual = annualRate * 1.5;
      var moraMonthly = moraAnnual / 12;
      moraAnnualInput.value = formatPercent(moraAnnual, 4);
      moraMonthlyInput.value = formatPercent(moraMonthly, 6);
    }

    function methodBadge(method) {
      var key = method === "interes_mora" || method === "clausula_penal" ? method : "none";
      var label = key === "none" ? "-" : methodLabel(method);
      return '<span class="!sp-inline-flex !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[10px] !sp-font-semibold !sp-whitespace-nowrap ' + METHOD_BADGES[key] + '">' + label + "</span>";
    }

    function addRow(data) {
      data = data || {};
      rowCounter += 1;
      var tr = document.createElement("tr");
      tr.dataset.rowId = String(rowCounter);
      var installmentType = normalizeInstallmentType(data.installment_type, data.concept);
      tr.innerHTML =
        '<td class="' + CELL + ' row-index !sp-font-semibold"></td>' +
        '<td class="' + CELL + ' !sp-min-w-[140px]"><input type="text" class="' + CELL_INPUT + ' concept" value="' + escapeHtml(data.concept == null ? "" : data.concept) + '" placeholder="Canon"></td>' +
        '<td class="' + CELL + ' !sp-min-w-[130px]"><select class="' + CELL_INPUT + ' installment-type">' +
        '<option value="canon"' + (installmentType === "canon" ? " selected" : "") + ">Canon</option>" +
        '<option value="servicios_publicos"' + (installmentType === "servicios_publicos" ? " selected" : "") + ">Servicios públicos</option>" +
        "</select></td>" +
        '<td class="' + CELL + ' !sp-min-w-[110px]"><input type="text" inputmode="decimal" autocomplete="off" class="' + CELL_INPUT + ' amount !sp-text-right" value="' + safeNum(data.amount) + '"></td>' +
        '<td class="' + CELL + ' !sp-min-w-[110px]"><input type="text" inputmode="decimal" autocomplete="off" class="' + CELL_INPUT + ' amount-paid !sp-text-right" value="' + safeNum(data.paid_amount) + '"></td>' +
        '<td class="' + CELL + ' !sp-min-w-[130px]"><input type="date" class="' + CELL_INPUT + ' due-date" value="' + safeDate(data.due_date) + '"></td>' +
        '<td class="' + CELL + ' !sp-min-w-[130px]"><input type="date" class="' + CELL_INPUT + ' pay-date" value="' + safeDate(data.payment_date) + '"></td>' +
        '<td class="' + CELL + ' !sp-min-w-[70px]"><input type="text" inputmode="decimal" autocomplete="off" class="' + CELL_INPUT + ' penalty-pct !sp-text-right" value="' + safeNum(data.penalty_percent) + '" placeholder="' + safeNum(globalPenaltyInput.value) + '"></td>' +
        '<td class="' + CELL + ' !sp-text-right out-days">0</td>' +
        '<td class="' + CELL + ' !sp-text-right out-months">0.00</td>' +
        '<td class="' + CELL + ' !sp-text-right !sp-whitespace-nowrap out-interest">' + currency.format(0) + "</td>" +
        '<td class="' + CELL + ' !sp-text-right !sp-whitespace-nowrap out-penalty">' + currency.format(0) + "</td>" +
        '<td class="' + CELL + ' !sp-text-right !sp-whitespace-nowrap !sp-font-bold out-best">' + currency.format(0) + "</td>" +
        '<td class="' + CELL + ' out-method">' + methodBadge("") + "</td>" +
        '<td class="' + CELL + ' !sp-text-center"><button type="button" class="!sp-inline-flex !sp-items-center !sp-justify-center !sp-w-8 !sp-h-8 !sp-rounded-md !sp-bg-white !sp-border !sp-border-solid !sp-border-red-200 !sp-text-red-700 !sp-cursor-pointer hover:!sp-bg-red-50 remove-row" title="Eliminar" aria-label="Eliminar fila"><span class="material-symbols-outlined !sp-text-[16px]" aria-hidden="true">delete</span></button></td>';

      tr.querySelector(".remove-row").addEventListener("click", function () {
        tr.remove();
        updateRowIndexes();
        if (rowsBody.children.length === 0) {
          addRow();
        }
      });

      rowsBody.appendChild(tr);
      updateRowIndexes();
    }

    function updateRowIndexes() {
      Array.prototype.slice.call(rowsBody.querySelectorAll("tr")).forEach(function (row, index) {
        var idxCell = row.querySelector(".row-index");
        if (idxCell) {
          idxCell.textContent = String(index + 1);
        }
      });
    }

    function rowValue(row, selector) {
      var input = row.querySelector(selector);
      return input ? input.value : undefined;
    }

    function collectRows() {
      return Array.prototype.slice.call(rowsBody.querySelectorAll("tr")).map(function (row) {
        var concept = String(rowValue(row, ".concept") || "").trim();
        var amount = asNumber(rowValue(row, ".amount"));
        var dueDate = rowValue(row, ".due-date") || "";
        var payDate = rowValue(row, ".pay-date") || "";
        var installmentType = normalizeInstallmentType(rowValue(row, ".installment-type"), concept);
        var paidAmount = asNumber(rowValue(row, ".amount-paid"));
        var penaltyPctRaw = rowValue(row, ".penalty-pct") || "";
        var penaltyPct = penaltyPctRaw === "" ? null : asNumber(penaltyPctRaw);
        return {
          concept: concept,
          installment_type: installmentType,
          amount: amount,
          paid_amount: paidAmount,
          due_date: dueDate,
          payment_date: payDate,
          penalty_percent: penaltyPct,
        };
      });
    }

    function calculate() {
      setStatus("Calculando...", "info");
      var payload = {
        annual_effective_rate_percent: asNumber(annualRateInput.value),
        global_penalty_percent: asNumber(globalPenaltyInput.value),
        global_payment_date: globalPaymentInput.value || null,
        installments: collectRows(),
      };
      return post("scm_tools_mora_calculate", { payload: JSON.stringify(payload) })
        .then(function (data) {
          renderCalculation(data.calculation);
          setStatus("Cálculo completado.", "success");
        })
        .catch(function (error) {
          setStatus(error.message || "Error al calcular.", "error");
        });
    }

    function renderCalculation(data) {
      if (!data || !Array.isArray(data.items)) {
        return;
      }

      latestCalculationData = data;
      setReadonlyRateFields();

      var rows = Array.prototype.slice.call(rowsBody.querySelectorAll("tr"));
      data.items.forEach(function (item, index) {
        var row = rows[index];
        if (!row) {
          return;
        }
        var typeSelect = row.querySelector(".installment-type");
        if (typeSelect) {
          typeSelect.value = normalizeInstallmentType(item.installment_type, item.concept);
        }
        var paidInput = row.querySelector(".amount-paid");
        if (paidInput) {
          paidInput.value = safeNum(item.paid_amount);
        }
        setText(row, ".out-days", String(item.days_late == null ? 0 : item.days_late));
        setText(row, ".out-months", formatNumber(item.months_late == null ? 0 : item.months_late, 2));
        setText(row, ".out-interest", currency.format(item.interest_mora || 0));
        setText(row, ".out-penalty", currency.format(item.clausula_penal || 0));
        setText(row, ".out-best", currency.format(item.recommended_charge || 0));

        var methodCell = row.querySelector(".out-method");
        if (methodCell) {
          methodCell.innerHTML = methodBadge(item.recommended_method === "interes_mora" ? "interes_mora" : "clausula_penal");
        }
      });

      var summary = data.summary || {};
      kpiCapital.textContent = currency.format(summary.total_capital || 0);
      kpiInterest.textContent = currency.format(summary.total_interest_mora || 0);
      kpiPenalty.textContent = currency.format(summary.total_clausula_penal || 0);
      kpiRecommended.textContent = currency.format(summary.total_recommended_charge || 0);
      refreshNarrativeBlocks(summary, data.items);
    }

    function ensurePdfLibraries() {
      return loadScript(LIBS.jspdf).then(function () { return loadScript(LIBS.autotable); });
    }

    function generatePdfReport(mode) {
      if (!latestCalculationData || !Array.isArray(latestCalculationData.items) || latestCalculationData.items.length === 0) {
        setStatus("Primero calcula el cobro para generar el PDF.", "warn");
        return Promise.resolve();
      }
      setStatus("Preparando el PDF...", "info");
      return ensurePdfLibraries()
        .then(function () {
          if (!window.jspdf || !window.jspdf.jsPDF) {
            throw new Error("No se pudo cargar la librería de PDF.");
          }
          return buildPdf(mode);
        })
        .catch(function (error) {
          setStatus(error.message || "No se pudo generar el PDF.", "error");
        });
    }

    function buildPdf(mode) {
      var isAdmin = mode === "admin";
      var jsPDF = window.jspdf.jsPDF;
      var doc = new jsPDF({
        orientation: "landscape",
        unit: "pt",
        format: "a4",
      });

      var pageWidth = doc.internal.pageSize.getWidth();
      var pageHeight = doc.internal.pageSize.getHeight();
      var generatedAt = new Date();
      var clientName = (clientNameInput.value || "").trim();
      var clientDocument = (clientDocumentInput.value || "").trim();
      var clientDescription = (clientDescriptionInput.value || "").trim();
      var titleName = clientName || "Cliente";

      var colorDark = [62, 65, 69];
      var colorYellow = [252, 208, 76];
      var colorOrange = [245, 145, 37];
      var colorLight = [249, 249, 249];

      doc.setFillColor(colorDark[0], colorDark[1], colorDark[2]);
      doc.rect(0, 0, pageWidth, 86, "F");
      doc.setFillColor(colorYellow[0], colorYellow[1], colorYellow[2]);
      doc.rect(0, 82, pageWidth, 2, "F");
      doc.setFillColor(colorOrange[0], colorOrange[1], colorOrange[2]);
      doc.rect(0, 84, pageWidth, 2, "F");

      return getPdfLogo().then(function (logo) {
        if (logo) {
          if (logo.wide) {
            doc.addImage(logo.data, "PNG", pageWidth - 218, 14, 182, 54);
          } else {
            doc.addImage(logo.data, "PNG", pageWidth - 90, 14, 54, 54);
          }
        } else {
          setStatus("No se pudo cargar el logo para el PDF. Se generó sin logo.", "warn");
        }

        doc.setTextColor(255, 255, 255);
        doc.setFont("helvetica", "bold");
        doc.setFontSize(18);
        doc.text(isAdmin ? "Soporte de Cobro - Administrador" : "Soporte de Cobro - " + titleName, 36, 34);

        doc.setFont("helvetica", "normal");
        doc.setFontSize(10);
        doc.text("Fecha de emision: " + dateFormat.format(generatedAt), 36, 56);
        doc.text(isAdmin ? "Documento interno con detalle de opciones" : "Documento para presentacion al cliente", 36, 72);

        var leftX = 36;
        var gapX = 16;
        var boxW = (pageWidth - leftX * 2 - gapX) / 2;
        var boxY = 100;
        var boxH = isAdmin ? 104 : 132;

        doc.setDrawColor(209, 222, 244);
        doc.setFillColor(colorLight[0], colorLight[1], colorLight[2]);
        doc.roundedRect(leftX, boxY, boxW, boxH, 8, 8, "FD");
        doc.roundedRect(leftX + boxW + gapX, boxY, boxW, boxH, 8, 8, "FD");

        doc.setTextColor(23, 37, 63);
        doc.setFont("helvetica", "bold");
        doc.setFontSize(11);
        doc.text("Soporte normativo (SFC)", leftX + 12, boxY + 18);
        doc.text(isAdmin ? "Parametros de calculo" : "Datos del cliente y calculo", leftX + boxW + gapX + 12, boxY + 18);

        var resolution = (latestSuperfinData && latestSuperfinData.resolution_label) || "No disponible";
        var vigencia = (latestSuperfinData && latestSuperfinData.vigencia_text) || "No disponible";
        var tasaEA = formatPercent(asNumber(annualRateInput.value), 4);
        var moraAnual = moraAnnualInput.value || "-";
        var moraMensual = moraMonthlyInput.value || "-";
        var penalPct = formatNumber(asNumber(globalPenaltyInput.value), 2) + "%";
        var fechaGlobal = globalPaymentInput.value ? formatDateForPdf(globalPaymentInput.value) : "No definida";

        doc.setFont("helvetica", "normal");
        doc.setFontSize(9.5);
        doc.text("Resolucion: " + resolution, leftX + 12, boxY + 38);
        doc.text("Vigencia: " + vigencia, leftX + 12, boxY + 54);
        doc.text("Fuente: " + ((latestSuperfinData && latestSuperfinData.source) || "Superintendencia Financiera de Colombia"), leftX + 12, boxY + 70);

        var rightBase = leftX + boxW + gapX + 12;
        if (isAdmin) {
          doc.text("Tasa efectiva anual: " + tasaEA, rightBase, boxY + 38);
          doc.text("Mora anual (x1.5): " + moraAnual, rightBase, boxY + 54);
          doc.text("Mora mensual: " + moraMensual, rightBase, boxY + 70);
          doc.text("% clausula penal: " + penalPct + " | Fecha global pago: " + fechaGlobal, rightBase, boxY + 86);
        } else {
          doc.text("Nombre: " + (clientName || "No definido"), rightBase, boxY + 38);
          doc.text("Cedula: " + (clientDocument || "No definida"), rightBase, boxY + 54);
          doc.text("Tasa efectiva anual: " + tasaEA, rightBase, boxY + 70);
          doc.text("Mora mensual: " + moraMensual + " | Clausula penal: " + penalPct, rightBase, boxY + 86);
          var descriptionText = clientDescription || "Sin descripcion";
          var wrappedDescription = doc.splitTextToSize("Descripcion: " + descriptionText, boxW - 24);
          doc.text(wrappedDescription.slice(0, 2), rightBase, boxY + 102);
        }

        var tableRows = latestCalculationData.items.map(function (item, index) {
          if (isAdmin) {
            return [
              index + 1,
              item.concept || "Canon " + (index + 1),
              installmentTypeLabel(item.installment_type, item.concept),
              currency.format(item.amount || 0),
              currency.format(item.paid_amount || 0),
              formatDateForPdf(item.due_date),
              formatDateForPdf(item.payment_date),
              String(item.days_late == null ? 0 : item.days_late),
              formatNumber(item.penalty_percent == null ? 0 : item.penalty_percent, 2) + "%",
              currency.format(item.interest_mora || 0),
              currency.format(item.clausula_penal || 0),
              currency.format(item.recommended_charge || 0),
              methodLabel(item.recommended_method),
            ];
          }
          return [
            index + 1,
            item.concept || "Canon " + (index + 1),
            installmentTypeLabel(item.installment_type, item.concept),
            currency.format(item.amount || 0),
            currency.format(item.paid_amount || 0),
            formatDateForPdf(item.due_date),
            formatDateForPdf(item.payment_date),
            String(item.days_late == null ? 0 : item.days_late),
            currency.format(item.recommended_charge || 0),
            methodLabel(item.recommended_method),
          ];
        });

        var tableHead = isAdmin
          ? [["#", "Concepto", "Tipo", "Valor canon", "Valor pagado", "Vencimiento", "Pago", "Dias", "% Penal", "Interes mora", "Clausula penal", "Mayor", "Metodo"]]
          : [["#", "Concepto", "Tipo", "Valor canon", "Valor pagado", "Vencimiento", "Pago", "Dias", "Mayor", "Metodo"]];

        var columnStyles = isAdmin
          ? {
            0: { halign: "center", cellWidth: 24 },
            1: { cellWidth: 86 },
            2: { halign: "center", cellWidth: 56 },
            3: { halign: "right", cellWidth: 66 },
            4: { halign: "right", cellWidth: 66 },
            5: { halign: "center", cellWidth: 56 },
            6: { halign: "center", cellWidth: 56 },
            7: { halign: "right", cellWidth: 34 },
            8: { halign: "right", cellWidth: 42 },
            9: { halign: "right", cellWidth: 68 },
            10: { halign: "right", cellWidth: 68 },
            11: { halign: "right", cellWidth: 68 },
            12: { halign: "center", cellWidth: 54 },
          }
          : {
            0: { halign: "center", cellWidth: 24 },
            1: { cellWidth: 112 },
            2: { halign: "center", cellWidth: 58 },
            3: { halign: "right", cellWidth: 80 },
            4: { halign: "right", cellWidth: 80 },
            5: { halign: "center", cellWidth: 62 },
            6: { halign: "center", cellWidth: 62 },
            7: { halign: "right", cellWidth: 34 },
            8: { halign: "right", cellWidth: 86 },
            9: { halign: "center", cellWidth: 74 },
          };

        var methodColumnIndex = isAdmin ? 12 : 9;

        doc.autoTable({
          startY: boxY + boxH + 14,
          margin: { left: 36, right: 36 },
          head: tableHead,
          body: tableRows,
          theme: "grid",
          styles: {
            fontSize: 8.4,
            cellPadding: 4,
            valign: "middle",
            textColor: [25, 35, 52],
            lineColor: [225, 233, 247],
            lineWidth: 0.6,
          },
          headStyles: {
            fillColor: [245, 145, 37],
            textColor: [255, 255, 255],
            fontStyle: "bold",
          },
          columnStyles: columnStyles,
          didParseCell: function (hook) {
            if (hook.section !== "body" || hook.column.index !== methodColumnIndex) {
              return;
            }
            var raw = String(hook.cell.raw || "");
            if (raw === "Interes mora") {
              hook.cell.styles.fillColor = [255, 243, 219];
              hook.cell.styles.textColor = [138, 82, 10];
              hook.cell.styles.fontStyle = "bold";
            } else {
              hook.cell.styles.fillColor = [241, 241, 241];
              hook.cell.styles.textColor = [62, 65, 69];
              hook.cell.styles.fontStyle = "bold";
            }
          },
        });

        var y = ((doc.lastAutoTable && doc.lastAutoTable.finalY) || 250) + 16;
        if (y > pageHeight - 145) {
          doc.addPage();
          y = 40;
        }

        var summary = latestCalculationData.summary || {};
        var byType = summary.by_type || {};
        var canonSummary = byType.canon || {};
        var servicesSummary = byType.servicios_publicos || {};
        var totalCapital = Number(summary.total_capital || 0);
        var totalInterest = Number(summary.total_interest_mora || 0);
        var totalPenalty = Number(summary.total_clausula_penal || 0);
        var totalRecommended = Number(summary.total_recommended_charge || 0);
        var canonRecommended = Number(canonSummary.total_recommended_charge || 0);
        var servicesRecommended = Number(servicesSummary.total_recommended_charge || 0);
        var totalCobro = totalCapital + totalRecommended;

        var cards = isAdmin
          ? [
            { label: "Capital", value: currency.format(totalCapital), color: [244, 244, 244] },
            { label: "Interes mora", value: currency.format(totalInterest), color: [255, 243, 219] },
            { label: "Clausula penal", value: currency.format(totalPenalty), color: [255, 235, 209] },
            { label: "Total recomendado", value: currency.format(totalRecommended), color: [245, 145, 37], invert: true },
            { label: "Capital + recargo", value: currency.format(totalCobro), color: [62, 65, 69], invert: true },
          ]
          : [
            { label: "Capital", value: currency.format(totalCapital), color: [244, 244, 244] },
            { label: "Mayor canon", value: currency.format(canonRecommended), color: [255, 243, 219] },
            { label: "Mayor serv. pub.", value: currency.format(servicesRecommended), color: [255, 235, 209] },
            { label: "Total recomendado", value: currency.format(totalRecommended), color: [245, 145, 37], invert: true },
            { label: "Capital + recargo", value: currency.format(totalCobro), color: [62, 65, 69], invert: true },
          ];

        var cardGap = 8;
        var cardW = (pageWidth - 72 - cardGap * (cards.length - 1)) / cards.length;
        var cardH = 56;
        cards.forEach(function (card, i) {
          var x = 36 + i * (cardW + cardGap);
          var color = card.color || [245, 249, 255];
          doc.setFillColor(color[0], color[1], color[2]);
          doc.setDrawColor(225, 225, 225);
          doc.roundedRect(x, y, cardW, cardH, 8, 8, "FD");
          if (card.invert) {
            doc.setTextColor(255, 255, 255);
          } else {
            doc.setTextColor(42, 56, 80);
          }
          doc.setFont("helvetica", "bold");
          doc.setFontSize(9);
          doc.text(card.label, x + 10, y + 19);
          doc.setFontSize(11);
          doc.text(card.value, x + 10, y + 38);
        });

        y += cardH + 20;
        if (y > pageHeight - 160) {
          doc.addPage();
          y = 40;
        }

        var fileDate = generatedAt.toISOString().slice(0, 10);
        if (!isAdmin) {
          var breakdown = computePaymentBreakdown(summary, latestCalculationData.items);
          var paymentApp = computePaymentApplication(summary, latestCalculationData.items);
          var tableGap = 14;
          var tableW = (pageWidth - 72 - tableGap) / 2;
          var rightX = 36 + tableW + tableGap;
          var leftRows = [
            ["Valor canon", currency.format(breakdown.canon)],
            ["Servicios publicos", currency.format(breakdown.services)],
            ["Intereses de mora", currency.format(breakdown.interest)],
            ["Recargo aplicado (informativo)", currency.format(breakdown.recargo)],
            ["Total discriminado (canon + servicios + intereses)", currency.format(breakdown.total)],
            ["Total pagado", currency.format(breakdown.paid)],
            ["Saldo pendiente", currency.format(breakdown.saldo)],
          ];
          var rightRows = [
            ["Pago total", currency.format(paymentApp.paid)],
            ["Pago total de servicio", currency.format(paymentApp.afterServices)],
            ["Pago total de intereses", currency.format(paymentApp.afterInterest)],
            ["Pago total del canon", currency.format(paymentApp.canonPending)],
            ["Saldo pendiente", currency.format(paymentApp.pendingTotal)],
          ];

          doc.setTextColor(23, 37, 63);
          doc.setFont("helvetica", "bold");
          doc.setFontSize(10.5);
          doc.text("RESUMEN VISUAL VALOR A PAGAR", 36, y);
          doc.text("DISCRIMINACION DE PAGO", rightX, y);

          var tableStartY = y + 8;
          doc.autoTable({
            startY: tableStartY,
            margin: { left: 36, right: pageWidth - 36 - tableW },
            tableWidth: tableW,
            body: leftRows,
            theme: "grid",
            styles: {
              fontSize: 8.8,
              cellPadding: 4.2,
              textColor: [35, 53, 89],
              lineColor: [224, 235, 252],
              lineWidth: 0.55,
            },
            columnStyles: {
              0: { cellWidth: tableW * 0.65, fontStyle: "bold" },
              1: { cellWidth: tableW * 0.35, halign: "right", fontStyle: "bold" },
            },
            didParseCell: function (hook) {
              if (hook.section !== "body") return;
              hook.cell.styles.fillColor = [255, 255, 255];
              var isHighlight = hook.row.index === 4 || hook.row.index === leftRows.length - 1;
              if (isHighlight) {
                hook.cell.styles.fontStyle = "bold";
                hook.cell.styles.textColor = [17, 44, 96];
              }
            },
          });

          doc.autoTable({
            startY: tableStartY,
            margin: { left: rightX, right: 36 },
            tableWidth: tableW,
            body: rightRows,
            theme: "grid",
            styles: {
              fontSize: 8.8,
              cellPadding: 5,
              textColor: [35, 53, 89],
              lineColor: [224, 235, 252],
              lineWidth: 0.55,
            },
            columnStyles: {
              0: { cellWidth: tableW * 0.67, fontStyle: "bold" },
              1: { cellWidth: tableW * 0.33, halign: "right", fontStyle: "bold" },
            },
            didParseCell: function (hook) {
              if (hook.section !== "body") return;
              hook.cell.styles.fillColor = [255, 255, 255];
              if (hook.row.index === rightRows.length - 1) {
                hook.cell.styles.fontStyle = "bold";
                hook.cell.styles.textColor = [17, 44, 96];
              }
            },
          });
          doc.save("Soporte_Cobro_Cliente_" + fileDate + ".pdf");
          setStatus("PDF cliente generado y descargado correctamente.", "success");
          return;
        }

        var descriptionLines = doc.splitTextToSize(buildChargeDescription(summary, latestCalculationData.items), pageWidth - 72);
        var entryLines = doc.splitTextToSize(buildAccountingEntry(summary, latestCalculationData.items), pageWidth - 72);
        var detailLines = doc.splitTextToSize(buildPaymentDetailBreakdown(latestCalculationData.items), pageWidth - 72);

        doc.setTextColor(23, 37, 63);
        doc.setFont("helvetica", "bold");
        doc.setFontSize(9.4);
        doc.text("Descripcion cuenta de cobro", 36, y);
        doc.setFont("helvetica", "normal");
        doc.setFontSize(8.7);
        doc.text(descriptionLines, 36, y + 13);
        y += 13 + descriptionLines.length * 11 + 6;

        if (y > pageHeight - 95) {
          doc.addPage();
          y = 40;
        }

        doc.setTextColor(23, 37, 63);
        doc.setFont("helvetica", "bold");
        doc.setFontSize(9.4);
        doc.text("Registro contable sugerido", 36, y);
        doc.setFont("helvetica", "normal");
        doc.setFontSize(8.7);
        doc.text(entryLines, 36, y + 13);
        y += 13 + entryLines.length * 11 + 6;

        if (y > pageHeight - 95) {
          doc.addPage();
          y = 40;
        }

        doc.setTextColor(23, 37, 63);
        doc.setFont("helvetica", "bold");
        doc.setFontSize(9.4);
        doc.text("Discriminacion detallada por canon", 36, y);
        doc.setFont("helvetica", "normal");
        doc.setFontSize(8.7);
        doc.text(detailLines, 36, y + 13);
        y += 13 + detailLines.length * 11 + 4;

        if (y > pageHeight - 60) {
          doc.addPage();
          y = 40;
        }

        doc.setTextColor(95, 111, 137);
        doc.setFont("helvetica", "normal");
        doc.setFontSize(8.6);
        doc.text("Regla aplicada por cuota: cobrar el mayor valor entre Interes de mora y Clausula penal. Formula mora: ((capital * tasa_mora_mensual) / 30) * dias.", 36, y);
        doc.text("Este documento se genera automaticamente para fines de evidencia de calculo.", 36, y + 13);

        doc.save("Soporte_Cobro_Admin_" + fileDate + ".pdf");
        setStatus("PDF admin generado y descargado correctamente.", "success");
      });
    }

    function syncSuperfin(force) {
      setStatus("Consultando Superfinanciera...", "info");
      return post("scm_tools_mora_rate", { refresh: force ? "1" : "" })
        .then(function (result) {
          var data = result.rate || {};
          latestSuperfinData = data;
          annualRateInput.value = safeNum(data.annual_effective_rate_percent);
          setReadonlyRateFields();

          resolutionLabel.textContent = data.resolution_label || "No disponible";
          vigenciaText.textContent = data.vigencia_text || "No disponible";
          updatedAtText.textContent = data.fetched_at ? formatDateTime(data.fetched_at) : "No disponible";

          applyLink(resolutionLink, data.resolution_url);
          applyLink(bulletinLink, data.bulletin_url);

          if (Array.isArray(data.warnings) && data.warnings.length > 0) {
            setStatus("Actualizado con observaciones: " + data.warnings[0], "warn");
          } else {
            setStatus("Resolución y tasa actualizadas correctamente.", "success");
          }
        })
        .catch(function (error) {
          setStatus(error.message || "Error consultando la SFC.", "error");
        });
    }

    function applyLink(anchor, href) {
      if (!anchor) return;
      if (typeof href === "string" && href.trim() !== "") {
        anchor.href = href;
        anchor.setAttribute("aria-disabled", "false");
        anchor.removeAttribute("tabindex");
      } else {
        anchor.href = "#";
        anchor.setAttribute("aria-disabled", "true");
        anchor.setAttribute("tabindex", "-1");
      }
    }

    function importExcelFile(file) {
      if (!file) return;
      setStatus("Leyendo archivo Excel...", "info");
      loadScript(LIBS.xlsx)
        .then(function () {
          return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function (event) { resolve(event.target && event.target.result); };
            reader.onerror = function () { reject(new Error("No se pudo leer el archivo.")); };
            reader.readAsArrayBuffer(file);
          });
        })
        .then(function (data) {
          var wb = XLSX.read(data, { type: "array", cellDates: true });
          var specialized = mapRowsFromSuperintendenciaTemplate(wb);
          var parsed = specialized.rows;
          var source = specialized.source;

          if (parsed.length === 0) {
            var sheetName = wb.SheetNames[0];
            var ws = wb.Sheets[sheetName];
            var rows = XLSX.utils.sheet_to_json(ws, { defval: "", raw: true });
            parsed = mapRowsFromExcel(rows);
            source = "hoja tabular";
          }

          if (parsed.length === 0) {
            throw new Error("No se encontraron filas válidas para cargar.");
          }
          rowsBody.innerHTML = "";
          parsed.forEach(function (row) { addRow(row); });
          setStatus("Se importaron " + parsed.length + " cánones desde Excel (" + source + ").", "success");
        })
        .catch(function (error) {
          setStatus(error.message || "No se pudo importar el Excel.", "error");
        })
        .finally(function () {
          excelInput.value = "";
        });
    }

    function mapRowsFromSuperintendenciaTemplate(wb) {
      if (!wb || !Array.isArray(wb.SheetNames)) {
        return { rows: [], source: "" };
      }

      var names = wb.SheetNames;
      var hasTemplate = names.indexOf("Calculos Interes") !== -1 && names.indexOf("Claus. Penal Arriendos") !== -1;

      if (!hasTemplate) {
        return { rows: [], source: "" };
      }

      var clauseSheet = wb.Sheets["Claus. Penal Arriendos"];
      var globalPenalty = normalizePenaltyPercent(parseExcelNumber(readCellValue(clauseSheet, "F4")));

      var candidateSheets = ["Calculos Interes (2)", "Calculos Interes", "Claus. Penal Arriendos"];

      var parsedRows = [];
      candidateSheets.forEach(function (sheetName) {
        var ws = wb.Sheets[sheetName];
        if (!ws) return;

        var amount = parseExcelNumber(readCellValue(ws, "J6"));
        var dueDate = parseExcelDate(readCellValue(ws, "D6"));
        var paymentDate = parseExcelDate(readCellValue(ws, "D7"));

        var penaltyRaw = null;
        if (sheetName === "Claus. Penal Arriendos") {
          penaltyRaw = parseExcelNumber(readCellValue(ws, "F4"));
        } else {
          penaltyRaw = parseExcelNumber(readCellValue(ws, "K13"));
        }
        var penaltySource = penaltyRaw != null ? penaltyRaw : globalPenalty != null ? globalPenalty : parseExcelNumber(globalPenaltyInput.value);
        var penalty = normalizePenaltyPercent(penaltySource);

        if (!amount || !dueDate) {
          return;
        }

        parsedRows.push({
          concept: "Canon (" + sheetName + ")",
          installment_type: "canon",
          amount: amount,
          paid_amount: 0,
          due_date: dueDate,
          payment_date: paymentDate,
          penalty_percent: penalty,
        });
      });

      var rows = dedupeRows(parsedRows);
      if (rows.length === 0) {
        return { rows: [], source: "" };
      }

      return {
        rows: rows,
        source: "plantilla SFC",
      };
    }

    function readCellValue(ws, address) {
      if (!ws || !address) return null;
      var cell = ws[address];
      if (!cell) return null;
      if (cell.v !== undefined && cell.v !== null) return cell.v;
      if (cell.w !== undefined && cell.w !== null) return cell.w;
      return null;
    }

    function normalizePenaltyPercent(value) {
      if (value === null || value === undefined || value === "") return null;
      var num = Number(value);
      if (!Number.isFinite(num)) return null;
      return num <= 1 ? num * 100 : num;
    }

    function dedupeRows(rows) {
      var seen = new Set();
      return rows.filter(function (row) {
        var key = [
          Number(row.amount || 0).toFixed(2),
          Number(row.paid_amount || 0).toFixed(2),
          row.due_date || "",
          row.payment_date || "",
          row.penalty_percent == null ? "" : row.penalty_percent,
          row.installment_type || "",
        ].join("|");
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
    }

    function mapRowsFromExcel(rows) {
      if (!Array.isArray(rows) || rows.length === 0) {
        return [];
      }

      var keys = Object.keys(rows[0] || {});
      var fieldMap = {
        amount: findKey(keys, ["canon", "valor", "capital", "monto", "cuota"]),
        due: findKey(keys, ["fecha de vencimiento", "fecha vencimiento", "vencimiento", "fecha_vencimiento"]),
        payment: findKey(keys, ["fecha de pago", "fecha pago", "pago", "fecha liquidacion", "liquidacion"]),
        concept: findKey(keys, ["concepto", "detalle", "mes", "canon"]),
        penalty: findKey(keys, ["clausula", "penal", "porcentaje", "%"]),
        type: findKey(keys, ["tipo", "categoria", "clase", "servicio"]),
        paid: findKey(keys, ["pagado", "valor pagado", "abono", "pago parcial"]),
      };

      if (!fieldMap.amount || !fieldMap.due) {
        throw new Error("El Excel debe tener columnas de valor canon y fecha de vencimiento.");
      }

      var parsed = [];
      rows.forEach(function (row, index) {
        var amount = parseExcelNumber(row[fieldMap.amount]);
        var dueDate = parseExcelDate(row[fieldMap.due]);
        if (!amount || !dueDate) {
          return;
        }

        parsed.push({
          concept: normalizeCell(row[fieldMap.concept]) || "Canon " + (index + 1),
          installment_type: normalizeInstallmentType(normalizeCell(row[fieldMap.type]), normalizeCell(row[fieldMap.concept])),
          amount: amount,
          paid_amount: parseExcelNumber(row[fieldMap.paid]) || 0,
          due_date: dueDate,
          payment_date: parseExcelDate(row[fieldMap.payment]),
          penalty_percent: parseExcelNumber(row[fieldMap.penalty]),
        });
      });

      return parsed;
    }

    function findKey(keys, aliases) {
      var normalizedAliases = aliases.map(normalizeKey);
      for (var i = 0; i < keys.length; i += 1) {
        var normalized = normalizeKey(keys[i]);
        if (normalizedAliases.some(function (a) { return normalized.indexOf(a) !== -1; })) {
          return keys[i];
        }
      }
      return null;
    }

    function normalizeKey(value) {
      return String(value || "")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .replace(/[^a-z0-9]+/g, " ")
        .trim();
    }

    function normalizeCell(value) {
      if (value === null || value === undefined) return "";
      return String(value).trim();
    }

    function parseExcelNumber(value) {
      if (value === null || value === undefined || value === "") return null;
      if (typeof value === "number") return Number.isFinite(value) ? value : null;
      return parseLocaleNumber(String(value));
    }

    // Formato colombiano: punto de miles y coma decimal ("1.500.000", "1.500.000,50", "19,06", "$ 80.000").
    // Misma regla que LateFeeCalculator::num en el servidor.
    function parseLocaleNumber(text) {
      var clean = String(text).replace(/[\s$% ]/g, "");
      if (clean === "") return null;
      var lastComma = clean.lastIndexOf(",");
      var lastDot = clean.lastIndexOf(".");
      if (lastComma !== -1 && lastDot !== -1) {
        clean = lastComma > lastDot ? clean.replace(/\./g, "").replace(",", ".") : clean.replace(/,/g, "");
      } else if (lastComma !== -1) {
        clean = (clean.match(/,/g) || []).length > 1 ? clean.replace(/,/g, "") : clean.replace(",", ".");
      } else if (lastDot !== -1 && ((clean.match(/\./g) || []).length > 1 || /^-?\d{1,3}\.\d{3}$/.test(clean))) {
        clean = clean.replace(/\./g, "");
      }
      var num = Number(clean);
      return Number.isFinite(num) ? num : null;
    }

    function parseExcelDate(value) {
      if (value === null || value === undefined || value === "") return "";
      if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return toIsoDate(value.getFullYear(), value.getMonth() + 1, value.getDate());
      }

      if (typeof value === "number") {
        var parsed = XLSX.SSF.parse_date_code(value);
        if (parsed && parsed.y) {
          return toIsoDate(parsed.y, parsed.m, parsed.d);
        }
      }

      var str = String(value).trim();
      var ymd = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(str);
      if (ymd) {
        return toIsoDate(Number(ymd[1]), Number(ymd[2]), Number(ymd[3]));
      }
      var dmy = /^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/.exec(str);
      if (dmy) {
        return toIsoDate(Number(dmy[3]), Number(dmy[2]), Number(dmy[1]));
      }
      return "";
    }

    function toIsoDate(y, m, d) {
      var dt = new Date(Date.UTC(y, m - 1, d));
      if (Number.isNaN(dt.getTime())) return "";
      return dt.toISOString().slice(0, 10);
    }

    function setText(scope, selector, value) {
      var node = scope.querySelector(selector);
      if (node) node.textContent = value;
    }

    function asNumber(value) {
      if (typeof value === "number") return Number.isFinite(value) ? value : 0;
      if (typeof value !== "string") return 0;
      var parsed = parseLocaleNumber(value);
      return parsed === null ? 0 : parsed;
    }

    function formatPercent(value, decimals) {
      return formatNumber(value, decimals == null ? 2 : decimals) + "%";
    }

    function formatNumber(value, decimals) {
      decimals = decimals == null ? 2 : decimals;
      return Number(value || 0).toLocaleString("es-CO", {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
    }

    function formatDateTime(isoText) {
      var date = new Date(isoText);
      return Number.isNaN(date.getTime()) ? isoText : dateFormat.format(date);
    }

    function formatDateForPdf(value) {
      if (!value) return "-";
      if (typeof value !== "string") return String(value);
      var ymd = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
      if (!ymd) return value;
      return ymd[3] + "/" + ymd[2] + "/" + ymd[1];
    }

    function methodLabel(method) {
      return method === "interes_mora" ? "Interes mora" : "Clausula penal";
    }

    function buildChargeDescription(summary, items) {
      items = items || [];
      var byType = (summary && summary.by_type) || {};
      var canonCapital = Number((byType.canon && byType.canon.total_capital) || 0);
      var servicesCapital = Number((byType.servicios_publicos && byType.servicios_publicos.total_capital) || 0);
      var totalInteres = Number((summary && summary.total_interest_mora) || 0);
      var canonRecargo = Number((byType.canon && byType.canon.total_recommended_charge) || 0);
      var servicesRecargo = Number((byType.servicios_publicos && byType.servicios_publicos.total_recommended_charge) || 0);
      var totalCapital = Number((summary && summary.total_capital) || 0);
      var totalRecargo = Number((summary && summary.total_recommended_charge) || 0);
      var totalCobro = totalCapital + totalRecargo;
      var totalPagado = Array.isArray(items)
        ? items.reduce(function (acc, item) { return acc + Number((item && item.paid_amount) || 0); }, 0)
        : Number((summary && summary.total_paid_amount) || 0);
      var saldoPendiente = Math.max(0, totalCobro - totalPagado);
      var saldoFavor = Math.max(0, totalPagado - totalCobro);
      return [
        "Discriminacion del valor a pagar:",
        "Capital canon: " + currency.format(canonCapital) + ".",
        "Capital servicios publicos: " + currency.format(servicesCapital) + ".",
        "Intereses de mora calculados (informativo): " + currency.format(totalInteres) + ".",
        "Recargo recomendado canon: " + currency.format(canonRecargo) + ".",
        "Recargo recomendado servicios publicos: " + currency.format(servicesRecargo) + ".",
        "Total recargo aplicado (mayor por item): " + currency.format(totalRecargo) + ".",
        "Total cuenta de cobro (capital + recargo): " + currency.format(totalCobro) + ".",
        "Total pagado registrado: " + currency.format(totalPagado) + ".",
        "Saldo pendiente total: " + currency.format(saldoPendiente) + ".",
        "Saldo a favor total: " + currency.format(saldoFavor) + ".",
      ].join("\n");
    }

    function refreshNarrativeBlocks(summary, items) {
      refreshPaymentBreakdownTable(summary, items);
      refreshPaymentApplicationTable(summary, items);
      chargeDescriptionText.textContent = buildChargeDescription(summary, items);
      accountingEntryText.textContent = buildAccountingEntry(summary, items);
      paymentDetailText.textContent = buildPaymentDetailBreakdown(items);
    }

    function computePaymentBreakdown(summary, items) {
      var rows = Array.isArray(items) ? items : [];
      var canon = rows.reduce(function (acc, item) {
        var type = normalizeInstallmentType(item && item.installment_type, (item && item.concept) || "");
        if (type !== "canon") {
          return acc;
        }
        return acc + Number((item && item.amount) || 0);
      }, 0);
      var services = rows.reduce(function (acc, item) {
        var type = normalizeInstallmentType(item && item.installment_type, (item && item.concept) || "");
        if (type !== "servicios_publicos") {
          return acc;
        }
        return acc + Number((item && item.amount) || 0);
      }, 0);
      var interest = Number((summary && summary.total_interest_mora) || 0);
      var recargo = Number((summary && summary.total_recommended_charge) || 0);
      var total = canon + services + interest;
      var paid = rows.reduce(function (acc, item) { return acc + Number((item && item.paid_amount) || 0); }, 0);
      var saldo = Math.max(0, total - paid);
      return { canon: canon, services: services, interest: interest, recargo: recargo, total: total, paid: paid, saldo: saldo };
    }

    function refreshPaymentBreakdownTable(summary, items) {
      var breakdown = computePaymentBreakdown(summary, items);
      payBreakdownCanon.textContent = currency.format(breakdown.canon);
      payBreakdownServices.textContent = currency.format(breakdown.services);
      payBreakdownInterest.textContent = currency.format(breakdown.interest);
      payBreakdownRecargo.textContent = currency.format(breakdown.recargo);
      payBreakdownTotal.textContent = currency.format(breakdown.total);
      payBreakdownPaid.textContent = currency.format(breakdown.paid);
      payBreakdownSaldo.textContent = currency.format(breakdown.saldo);
      refreshPaymentApplicationTable(summary, items, breakdown);
    }

    function computePaymentApplication(summary, items) {
      var breakdown = computePaymentBreakdown(summary, items);
      var canon = breakdown.canon;
      var services = breakdown.services;
      var interest = breakdown.interest;
      var paid = breakdown.paid;
      var afterServices = Math.max(0, paid - services);
      var unpaidServices = Math.max(0, services - paid);
      var afterInterest = Math.max(0, afterServices - interest);
      var unpaidInterest = Math.max(0, interest - afterServices);
      var canonApplied = Math.min(canon, afterInterest);
      var canonPending = Math.max(0, canon - canonApplied);
      var pendingTotal = unpaidServices + unpaidInterest + canonPending;
      return {
        canon: canon,
        services: services,
        interest: interest,
        paid: paid,
        afterServices: afterServices,
        afterInterest: afterInterest,
        canonApplied: canonApplied,
        canonPending: canonPending,
        pendingTotal: pendingTotal,
      };
    }

    function refreshPaymentApplicationTable(summary, items, precomputedBreakdown) {
      var baseBreakdown = precomputedBreakdown || computePaymentBreakdown(summary, items);
      var data = computePaymentApplication(summary, items);
      var paid = Number(baseBreakdown.paid || 0);
      var services = Number(baseBreakdown.services || 0);
      var interest = Number(baseBreakdown.interest || 0);
      var canon = Number(baseBreakdown.canon || 0);
      var afterServices = Math.max(0, paid - services);
      var afterInterest = Math.max(0, afterServices - interest);
      var canonPending = Math.max(0, canon - Math.min(canon, afterInterest));
      var pendingTotal = Math.max(0, Math.max(0, services - paid) + Math.max(0, interest - afterServices) + canonPending);
      applyPayTotal.textContent = currency.format(paid || data.paid);
      applyPayServicesTotal.textContent = currency.format(afterServices || data.afterServices);
      applyPayInterestTotal.textContent = currency.format(afterInterest || data.afterInterest);
      applyPayCanonTotal.textContent = currency.format(canonPending || data.canonPending);
      applyPayPending.textContent = currency.format(pendingTotal || data.pendingTotal);
    }

    function syncPaidAmountsFromTable() {
      if (!latestCalculationData || !Array.isArray(latestCalculationData.items)) {
        return;
      }
      var rows = Array.prototype.slice.call(rowsBody.querySelectorAll("tr"));
      latestCalculationData.items = latestCalculationData.items.map(function (item, index) {
        var row = rows[index];
        var paidValue = row ? rowValue(row, ".amount-paid") : undefined;
        return Object.assign({}, item, { paid_amount: asNumber(paidValue) });
      });
      refreshNarrativeBlocks(latestCalculationData.summary || {}, latestCalculationData.items);
    }

    function buildAccountingEntry(summary, items) {
      items = items || [];
      var byType = (summary && summary.by_type) || {};
      var canonCapital = Number((byType.canon && byType.canon.total_capital) || 0);
      var servicesCapital = Number((byType.servicios_publicos && byType.servicios_publicos.total_capital) || 0);
      var totalRecargo = Number((summary && summary.total_recommended_charge) || 0);
      var totalCapital = Number((summary && summary.total_capital) || 0);
      var totalCuentaCobro = totalCapital + totalRecargo;
      var totalPagado = Array.isArray(items)
        ? items.reduce(function (acc, item) { return acc + Number((item && item.paid_amount) || 0); }, 0)
        : 0;
      var aplicado = Math.min(totalPagado, totalCuentaCobro);
      var saldoPendiente = Math.max(0, totalCuentaCobro - aplicado);
      var saldoFavor = Math.max(0, totalPagado - totalCuentaCobro);
      var lines = [];
      lines.push("Causacion:");
      lines.push("Debe  | Cuentas por cobrar - arrendatario | " + currency.format(totalCuentaCobro));
      if (canonCapital > 0) {
        lines.push("Haber | Ingreso por canon de arrendamiento | " + currency.format(canonCapital));
      }
      if (servicesCapital > 0) {
        lines.push("Haber | Reintegro servicios publicos | " + currency.format(servicesCapital));
      }
      if (totalRecargo > 0) {
        lines.push("Haber | Ingreso por recargo mora/clausula | " + currency.format(totalRecargo));
      }
      lines.push("Total Debe: " + currency.format(totalCuentaCobro) + " | Total Haber: " + currency.format(totalCuentaCobro));
      if (totalPagado > 0) {
        lines.push("");
        lines.push("Recaudo:");
        lines.push("Debe  | Caja/Bancos | " + currency.format(totalPagado));
        lines.push("Haber | Cuentas por cobrar - arrendatario | " + currency.format(aplicado));
        if (saldoFavor > 0) {
          lines.push("Haber | Anticipos de arrendatarios | " + currency.format(saldoFavor));
        }
        lines.push("Saldo pendiente por cobrar: " + currency.format(saldoPendiente));
      }
      return lines.join("\n");
    }

    function buildPaymentDetailBreakdown(items) {
      if (!Array.isArray(items) || items.length === 0) {
        return "Sin detalles para discriminar.";
      }
      var totals = items.reduce(function (acc, item) {
        var capital = Number((item && item.amount) || 0);
        var interest = Number((item && item.interest_mora) || 0);
        var recargo = Number((item && item.recommended_charge) || 0);
        var pagado = Number((item && item.paid_amount) || 0);
        var type = normalizeInstallmentType(item && item.installment_type, (item && item.concept) || "");
        if (type === "servicios_publicos") {
          acc.services += capital;
        } else {
          acc.canon += capital;
        }
        acc.interest += interest;
        acc.recargo += recargo;
        acc.total += capital + recargo;
        acc.paid += pagado;
        return acc;
      }, { canon: 0, services: 0, interest: 0, recargo: 0, total: 0, paid: 0 });
      var saldo = Math.max(0, totals.total - totals.paid);
      var header = [
        "Resumen discriminado valor a pagar:",
        "  Valor canon: " + currency.format(totals.canon),
        "  Servicios publicos: " + currency.format(totals.services),
        "  Intereses de mora calculados: " + currency.format(totals.interest),
        "  Recargo aplicado (mayor por item): " + currency.format(totals.recargo),
        "  Total discriminado (canon + servicios + intereses): " + currency.format(totals.canon + totals.services + totals.interest),
        "  Total cuenta de cobro (capital + recargo): " + currency.format(totals.total),
        "  Total pagado: " + currency.format(totals.paid),
        "  Saldo pendiente total: " + currency.format(saldo),
      ].join("\n");
      var detail = items.map(function (item, index) {
        var capital = Number((item && item.amount) || 0);
        var interest = Number((item && item.interest_mora) || 0);
        var penalty = Number((item && item.clausula_penal) || 0);
        var recargo = Number((item && item.recommended_charge) || 0);
        var total = capital + recargo;
        var pagado = Number((item && item.paid_amount) || 0);
        var itemSaldo = Math.max(0, total - pagado);
        var metodo = methodLabel(item && item.recommended_method);
        var concepto = (item && item.concept) || "Canon " + (index + 1);
        var tipo = installmentTypeLabel(item && item.installment_type, item && item.concept);
        return [
          index + 1 + ". " + concepto + " (" + tipo + ")",
          "  Capital: " + currency.format(capital),
          "  Interes mora: " + currency.format(interest),
          "  Clausula penal: " + currency.format(penalty),
          "  Recargo aplicado (" + metodo + "): " + currency.format(recargo),
          "  Total a pagar este canon: " + currency.format(total),
          "  Valor pagado: " + currency.format(pagado),
          "  Saldo pendiente canon: " + currency.format(itemSaldo),
        ].join("\n");
      }).join("\n\n");
      return header + "\n\n" + detail;
    }

    function normalizeInstallmentType(value, concept) {
      var raw = normalizeKey(value);
      if (raw.indexOf("servicio") !== -1 || raw.indexOf("publico") !== -1 || raw.indexOf("acueducto") !== -1 || raw.indexOf("energia") !== -1 || raw.indexOf("gas") !== -1 || /\bluz\b/.test(raw)) {
        return "servicios_publicos";
      }
      if (raw.indexOf("canon") !== -1 || raw.indexOf("arriendo") !== -1) {
        return "canon";
      }
      var conceptRaw = normalizeKey(concept || "");
      if (conceptRaw.indexOf("servicio") !== -1 || conceptRaw.indexOf("publico") !== -1 || conceptRaw.indexOf("acueducto") !== -1 || conceptRaw.indexOf("energia") !== -1 || conceptRaw.indexOf("gas") !== -1 || /\bluz\b/.test(conceptRaw)) {
        return "servicios_publicos";
      }
      return "canon";
    }

    function installmentTypeLabel(type, concept) {
      return normalizeInstallmentType(type, concept || "") === "servicios_publicos" ? "Servicios publicos" : "Canon";
    }

    // Logo horizontal de la web; si no se puede descargar (CORS o red) se usa el isologo del panel.
    function getPdfLogo() {
      if (pdfLogo) return Promise.resolve(pdfLogo);
      var localLogo = (document.querySelector('script[src*="admin-tools.js"]') || {}).src || "";
      localLogo = localLogo ? localLogo.replace(/js\/admin-tools\.js.*$/, "img/cropped-ISOLOGO-WEB.png") : "";
      return fetchDataUrl(skcLogoUrl)
        .then(function (data) { return { data: data, wide: true }; })
        .catch(function () {
          if (!localLogo) return null;
          return fetchDataUrl(localLogo).then(function (data) { return { data: data, wide: false }; }).catch(function () { return null; });
        })
        .then(function (logo) {
          pdfLogo = logo;
          return logo;
        });
    }

    function fetchDataUrl(url) {
      return fetch(url, { cache: "force-cache" })
        .then(function (res) {
          if (!res.ok) throw new Error("No se pudo descargar el logo (" + res.status + ").");
          return res.blob();
        })
        .then(function (blob) {
          return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(String(reader.result || "")); };
            reader.onerror = function () { reject(new Error("No se pudo leer el logo.")); };
            reader.readAsDataURL(blob);
          });
        });
    }

    function safeNum(value) {
      if (value === null || value === undefined || value === "") return "";
      return String(value);
    }

    function safeDate(value) {
      return typeof value === "string" ? value : "";
    }

    function bindEvents() {
      module.addEventListener("click", function (event) {
        var button = event.target.closest("[data-mora-action]");
        if (!button || !module.contains(button)) return;
        var action = button.getAttribute("data-mora-action");
        if (action === "addRow") addRow();
        if (action === "calculate") calculate();
        if (action === "pdfClient") generatePdfReport("client");
        if (action === "pdfAdmin") generatePdfReport("admin");
        if (action === "syncSuperfin") syncSuperfin(true);
        if (action === "importExcel") excelInput.click();
        if (action === "applyGlobalDate") {
          var dateValue = globalPaymentInput.value;
          if (!dateValue) {
            setStatus("Selecciona una fecha global para aplicar.", "warn");
            return;
          }
          rowsBody.querySelectorAll(".pay-date").forEach(function (input) {
            input.value = dateValue;
          });
          setStatus("Fecha global aplicada a todos los cánones.", "success");
        }
      });
      excelInput.addEventListener("change", function (event) {
        var file = event.target && event.target.files && event.target.files[0];
        importExcelFile(file);
      });
      annualRateInput.addEventListener("input", setReadonlyRateFields);
      rowsBody.addEventListener("input", function (event) {
        var target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        // El tipo sigue al concepto ("Servicio de energía" → Servicios públicos) hasta que se elija a mano.
        if (target.classList.contains("concept")) {
          var typeSelect = target.closest("tr") && target.closest("tr").querySelector(".installment-type");
          if (typeSelect && typeSelect.dataset.manual !== "1") {
            typeSelect.value = normalizeInstallmentType("", target.value);
          }
          return;
        }
        if (target.classList.contains("amount-paid")) {
          syncPaidAmountsFromTable();
        }
      });
      rowsBody.addEventListener("change", function (event) {
        var target = event.target;
        if (target instanceof HTMLElement && target.classList.contains("installment-type")) {
          target.dataset.manual = "1";
        }
      });
    }

    function bootstrap() {
      globalPaymentInput.value = new Date().toISOString().slice(0, 10);
      addRow();
      bindEvents();
      setReadonlyRateFields();
    }

    bootstrap();
    module.addEventListener("scm:load-tool", function () {
      if (module.dataset.scmLoaded === "1") return;
      module.dataset.scmLoaded = "1";
      syncSuperfin(false);
    });
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function boot() {
    document.querySelectorAll("#scm-app.scm-wrap[data-scm-runtime]").forEach(init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
