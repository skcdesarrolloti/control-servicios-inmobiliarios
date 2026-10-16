(function () {
  "use strict";

  // Funcionario responsable de contratos: una sola carga; filtros, ayuda y selección
  // se resuelven en el navegador y solo se vuelve al servidor para guardar cambios.
  var PAGE_SIZE = 25;
  var CONFIDENCE = {
    alta: { label: "Alta", tone: "good" },
    media: { label: "Media", tone: "warn" },
    baja: { label: "Baja", tone: "low" },
    ninguna: { label: "Sin sugerencia", tone: "none" },
  };
  var SOURCE_LABELS = { ambos: "Hoja de cierre y mandato", cierre: "Hoja de cierre", mandato: "Mandato" };
  var STATUS = {
    difiere: "Difiere de la sugerencia",
    sin_funcionario: "Sin funcionario",
    retirado: "Funcionario retirado",
    coincide: "Coincide con la sugerencia",
    sin_evidencia: "Sin hoja de cierre ni mandato",
  };

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function formatDate(iso) {
    if (!iso) return "Sin fecha";
    var months = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
    return Number(iso.slice(8, 10)) + " " + months[Number(iso.slice(5, 7)) - 1] + " " + iso.slice(0, 4);
  }
  function normalize(text) {
    return String(text || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  function init(root) {
    var module = root.querySelector("[data-scm-contract-owners]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.coInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.coInit = "1";

    var actions = runtime.actions || {};
    var panel = module.closest(".scm-tab-panel");
    var canEdit = module.getAttribute("data-can-edit") === "1";
    var statusEl = module.querySelector("[data-scm-co-status]");
    var tableEl = module.querySelector("[data-scm-co-table]");
    var kpisEl = module.querySelector("[data-scm-co-kpis]");
    var searchEl = module.querySelector("[data-scm-co-search]");
    var bulkEl = module.querySelector("[data-scm-co-bulk]");
    var modal = module.querySelector("[data-scm-co-modal]");
    var modalTitle = module.querySelector("[data-scm-co-modal-title]");
    var modalBody = module.querySelector("[data-scm-co-modal-body]");
    var filters = {};
    module.querySelectorAll("[data-scm-co-filter]").forEach(function (select) {
      filters[select.getAttribute("data-scm-co-filter")] = select;
    });

    var state = { data: null, employees: {}, pending: null, page: 1, selected: {}, search: "", saving: false, lastFocus: null };

    function setStatus(message, tone) {
      statusEl.textContent = message || "";
      statusEl.hidden = !message;
      statusEl.className = "scm-co-status" + (tone ? " is-" + tone : "");
    }

    function request(action, fields) {
      var body = new FormData();
      body.append("action", action);
      body.append("nonce", runtime.nonce || "");
      Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
          return json.data || {};
        });
    }

    function load(force) {
      if (state.pending) return state.pending;
      if (state.data && !force) return Promise.resolve();
      setStatus(state.data ? "Actualizando contratos…" : "Cargando contratos…", "loading");
      state.pending = request(actions.contract_owners || "", {})
        .then(function (data) {
          state.data = data;
          canEdit = canEdit && data.can_edit !== false;
          state.employees = {};
          (data.employees || []).forEach(function (employee) { state.employees[employee.id] = employee; });
          (data.contracts || []).forEach(decorate);
          fillFilters();
          setStatus("");
          if (panel) panel.setAttribute("data-scm-loaded", "1");
          render();
        })
        .catch(function (error) { setStatus(error.message, "error"); })
        .then(function () { state.pending = null; });
      return state.pending;
    }

    function employeeName(id, fallback) {
      if (!id) return "";
      return state.employees[id] ? state.employees[id].name : fallback || "Funcionario " + id;
    }
    function isActive(id) {
      return !!(id && state.employees[id] && state.employees[id].active);
    }

    function decorate(row) {
      var suggested = row.suggestion.employee_id;
      row.currentActive = isActive(row.employee_id);
      row.suggestedActive = isActive(suggested);
      row.status = !row.employee_id ? "sin_funcionario" : !suggested ? "sin_evidencia" : suggested === row.employee_id ? "coincide" : "difiere";
      row.retired = !!row.employee_id && !row.currentActive;
      row.blob = normalize([row.contrato, row.inmueble, row.direccion, row.propietario, row.arrendatario, employeeName(row.employee_id, row.employee_name)].join(" "));
    }

    function fillFilters() {
      var states = {};
      var owners = {};
      state.data.contracts.forEach(function (row) {
        if (row.estado) states[row.estado] = true;
        owners[row.employee_id || ""] = true;
      });
      filters.estado.innerHTML = '<option value="">Todos</option>' + Object.keys(states).sort().map(function (value) {
        return '<option value="' + esc(value) + '">' + esc(value) + "</option>";
      }).join("");
      filters.employee.innerHTML = '<option value="">Todos</option>' + Object.keys(owners)
        .map(function (id) { return { id: id, name: id ? employeeName(id) + (isActive(id) ? "" : " (retirado)") : "Sin funcionario" }; })
        .sort(function (a, b) { return a.name.localeCompare(b.name, "es"); })
        .map(function (option) { return '<option value="' + esc(option.id || "__none") + '">' + esc(option.name) + "</option>"; }).join("");
      var bulkSelect = module.querySelector("[data-scm-co-bulk-employee]");
      if (bulkSelect) bulkSelect.innerHTML = '<option value="">Elige un funcionario activo</option>' + activeOptions("");
    }

    function activeOptions(selected) {
      return (state.data.employees || []).filter(function (employee) { return employee.active; }).map(function (employee) {
        return '<option value="' + esc(employee.id) + '"' + (employee.id === selected ? " selected" : "") + ">" + esc(employee.name) +
          (employee.cargo ? " · " + esc(employee.cargo) : "") + "</option>";
      }).join("");
    }

    function filteredRows() {
      var status = filters.status.value;
      var estado = filters.estado.value;
      var owner = filters.employee.value;
      var confidence = filters.confidence.value;
      var query = normalize(state.search.trim());
      return state.data.contracts.filter(function (row) {
        if (status === "retirado" ? !row.retired : status && row.status !== status) return false;
        if (estado && row.estado !== estado) return false;
        if (owner && (owner === "__none" ? row.employee_id !== "" : row.employee_id !== owner)) return false;
        if (confidence && row.suggestion.confidence !== confidence) return false;
        return !query || row.blob.indexOf(query) !== -1;
      });
    }

    function render() {
      if (!state.data) return;
      renderKpis();
      renderTable();
      renderBulk();
    }

    function renderKpis() {
      var counts = { total: state.data.contracts.length, difiere: 0, sin_funcionario: 0, retirado: 0, coincide: 0, sin_evidencia: 0 };
      state.data.contracts.forEach(function (row) {
        counts[row.status] += 1;
        if (row.retired) counts.retirado += 1;
      });
      var active = filters.status.value;
      kpisEl.innerHTML = [["", "Contratos", counts.total, ""], ["difiere", "Difieren de la sugerencia", counts.difiere, "warn"],
        ["sin_funcionario", "Sin funcionario", counts.sin_funcionario, "bad"], ["retirado", "Con funcionario retirado", counts.retirado, "bad"],
        ["coincide", "Coinciden", counts.coincide, "good"], ["sin_evidencia", "Sin cierre ni mandato", counts.sin_evidencia, ""]]
        .map(function (kpi) {
          return '<button type="button" class="scm-co-kpi' + (kpi[3] ? " is-" + kpi[3] : "") + (active === kpi[0] ? " is-active" : "") +
            '" data-scm-co-kpi="' + kpi[0] + '"><span>' + esc(kpi[1]) + "</span><strong>" + kpi[2].toLocaleString("es-CO") + "</strong></button>";
        }).join("");
    }

    function ownerCell(row) {
      if (!row.employee_id) return '<span class="scm-co-muted">Sin funcionario</span>';
      return esc(employeeName(row.employee_id, row.employee_name)) + (row.retired ? ' <span class="scm-co-badge is-bad">Retirado</span>' : "");
    }

    function suggestionCell(row) {
      var s = row.suggestion;
      if (!s.employee_id) return '<span class="scm-co-muted">Sin evidencia</span>';
      var conf = CONFIDENCE[s.confidence] || CONFIDENCE.ninguna;
      return '<div class="scm-co-suggest">' + esc(employeeName(s.employee_id)) +
        (row.suggestedActive ? "" : ' <span class="scm-co-badge is-bad">Retirado</span>') +
        '<small><span class="scm-co-badge is-' + conf.tone + '">' + conf.label + "</span> " + esc(SOURCE_LABELS[s.source] || "") + "</small></div>";
    }

    function renderTable() {
      var rows = filteredRows();
      var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      state.page = Math.min(Math.max(1, state.page), pages);
      var slice = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);
      if (!slice.length) {
        tableEl.innerHTML = '<div class="scm-co-empty">Ningún contrato coincide con los filtros.</div>';
        return;
      }
      var allSelected = canEdit && slice.every(function (row) { return state.selected[row.id]; });
      var html = '<div class="scm-co-table-wrap"><table><thead><tr>' +
        (canEdit ? '<th class="is-check"><input type="checkbox" data-scm-co-check-page aria-label="Seleccionar la página"' + (allSelected ? " checked" : "") + "></th>" : "") +
        "<th>Contrato</th><th>Inmueble</th><th>Propietario / Arrendatario</th><th>Funcionario actual</th><th>Sugerencia</th><th></th></tr></thead><tbody>";
      html += slice.map(function (row) {
        var quick = canEdit && row.status === "difiere" && row.suggestedActive || canEdit && row.status === "sin_funcionario" && row.suggestedActive;
        return '<tr class="is-' + row.status + '">' +
          (canEdit ? '<td class="is-check"><input type="checkbox" data-scm-co-check="' + row.id + '" aria-label="Seleccionar contrato ' + esc(row.contrato) + '"' + (state.selected[row.id] ? " checked" : "") + "></td>" : "") +
          "<td><strong>" + esc(row.contrato || "#" + row.id) + '</strong><small class="scm-co-state">' + esc(row.estado || "Sin estado") + "</small></td>" +
          "<td>" + esc(row.inmueble) + "<small>" + esc(row.direccion) + "</small></td>" +
          "<td>" + esc(row.propietario || "—") + "<small>" + esc(row.arrendatario || "—") + "</small></td>" +
          "<td>" + ownerCell(row) + "</td><td>" + suggestionCell(row) + "</td>" +
          '<td class="is-actions"><button type="button" class="scm-co-btn scm-co-btn-sm" data-scm-co-help="' + row.id + '">' +
          '<span class="material-symbols-outlined" aria-hidden="true">contact_support</span>' + (canEdit ? "Revisar" : "Ver ayuda") + "</button>" +
          (quick ? '<button type="button" class="scm-co-btn scm-co-btn-sm scm-co-btn-primary" data-scm-co-quick="' + row.id + '" title="Asignar a ' + esc(employeeName(row.suggestion.employee_id)) + '">Aplicar</button>' : "") +
          "</td></tr>";
      }).join("");
      html += "</tbody></table></div>";
      html += '<div class="scm-co-pager"><span>' + rows.length.toLocaleString("es-CO") + (rows.length === 1 ? " contrato" : " contratos") + "</span>";
      if (pages > 1) {
        html += '<div><button type="button" class="scm-co-btn scm-co-btn-sm" data-scm-co-page="' + (state.page - 1) + '"' + (state.page === 1 ? " disabled" : "") +
          ">Anterior</button><span>Página " + state.page + " de " + pages + '</span><button type="button" class="scm-co-btn scm-co-btn-sm" data-scm-co-page="' +
          (state.page + 1) + '"' + (state.page === pages ? " disabled" : "") + ">Siguiente</button></div>";
      }
      tableEl.innerHTML = html + "</div>";
    }

    function selectedRows() {
      return state.data.contracts.filter(function (row) { return state.selected[row.id]; });
    }

    function renderBulk() {
      if (!bulkEl) return;
      var count = selectedRows().length;
      bulkEl.hidden = !count;
      bulkEl.querySelector("[data-scm-co-bulk-count]").textContent = count + (count === 1 ? " contrato seleccionado" : " contratos seleccionados");
    }

    function openModal(title, html) {
      state.lastFocus = document.activeElement;
      modalTitle.textContent = title;
      modalBody.innerHTML = html;
      modal.hidden = false;
      document.body.classList.add("scm-co-modal-open");
      var focusable = modalBody.querySelector("select, textarea, button") || modal.querySelector("[data-scm-co-close]");
      if (focusable) focusable.focus();
    }
    function closeModal() {
      if (state.saving) return;
      modal.hidden = true;
      document.body.classList.remove("scm-co-modal-open");
      if (state.lastFocus && state.lastFocus.focus) state.lastFocus.focus();
    }

    function evidenceCard(title, icon, evidence, rows) {
      if (!evidence) {
        return '<section class="scm-co-evidence is-missing"><h3><span class="material-symbols-outlined" aria-hidden="true">' + icon + "</span>" + esc(title) +
          "</h3><p>No se encontró " + esc(title.toLowerCase()) + " para este contrato.</p></section>";
      }
      var identified = evidence.employee_id
        ? esc(employeeName(evidence.employee_id)) + (isActive(evidence.employee_id) ? "" : ' <span class="scm-co-badge is-bad">Retirado</span>')
        : '<span class="scm-co-muted">No se pudo identificar</span>';
      return '<section class="scm-co-evidence"><h3><span class="material-symbols-outlined" aria-hidden="true">' + icon + "</span>" + esc(title) + " #" + esc(evidence.id) +
        "</h3><dl><dt>Funcionario identificado</dt><dd>" + identified + "</dd>" + rows.map(function (item) {
          return item[1] ? "<dt>" + esc(item[0]) + "</dt><dd>" + esc(item[1]) + "</dd>" : "";
        }).join("") + "</dl></section>";
    }

    function helpHtml(row) {
      var s = row.suggestion;
      var cierre = row.cierre;
      var mandato = row.mandato;
      var conf = CONFIDENCE[s.confidence] || CONFIDENCE.ninguna;
      var html = '<div class="scm-co-summary"><div><span>Contrato</span><strong>' + esc(row.contrato || "#" + row.id) + " · " + esc(row.estado) + "</strong></div>" +
        "<div><span>Inmueble</span><strong>" + esc(row.inmueble) + " · " + esc(row.direccion) + "</strong></div>" +
        "<div><span>Funcionario actual</span><strong>" + ownerCell(row) + "</strong></div></div>";
      html += '<div class="scm-co-evidence-grid">';
      html += evidenceCard("Hoja de cierre", "handshake", cierre, cierre ? [
        ["Fecha", cierre.fecha ? formatDate(cierre.fecha) : ""],
        ["Registrada por", cierre.creador],
        ["Aprobada por", cierre.aprobador],
        ["Identificado por", cierre.matched_by === "id_empleado" ? "id_empleado de la hoja de cierre" : cierre.matched_by === "nombre" ? "Nombre de quien la registró" : ""],
        ["Vínculo", cierre.via === "id_cierre" ? "Hoja de cierre enlazada en el contrato" : "Hoja de cierre que apunta a este contrato"],
      ] : []);
      html += evidenceCard("Mandato", "description", mandato, mandato ? [
        ["Fecha", mandato.fecha ? formatDate(mandato.fecha) : ""],
        ["Creado por", mandato.creador],
        ["Correo del creador", mandato.correo],
        ["Identificado por", mandato.matched_by === "nombre" ? "Nombre de quien lo creó" : mandato.matched_by === "correo" ? "Correo del creador" : ""],
        ["Vínculo", mandato.via === "id_contrato_mandato" ? "Mandato enlazado en el contrato" : "Mandato más reciente del mismo inmueble"],
      ] : []);
      html += "</div>";
      html += '<div class="scm-co-verdict is-' + conf.tone + '"><span class="material-symbols-outlined" aria-hidden="true">' + (s.employee_id ? "lightbulb" : "help") + "</span><div>";
      if (s.employee_id) {
        html += "<strong>Sugerencia: " + esc(employeeName(s.employee_id)) + "</strong> · Confianza " + conf.label.toLowerCase() + "<p>" + esc(s.reason) + "</p>";
        if (!row.suggestedActive) html += '<p class="scm-co-warning">Este funcionario está retirado: elige un funcionario activo que hoy atienda el contrato.</p>';
        if (row.status === "coincide") html += "<p>El contrato ya tiene asignado este funcionario.</p>";
      } else {
        html += "<strong>Sin sugerencia</strong><p>" + esc(s.reason) + " Asigna el funcionario según quien gestiona hoy el contrato.</p>";
      }
      html += "</div></div>";
      if (canEdit) {
        var preselect = row.suggestedActive ? s.employee_id : row.currentActive ? row.employee_id : "";
        var reason = s.employee_id && row.suggestedActive
          ? "Según " + (s.source === "mandato" ? "mandato #" + (mandato ? mandato.id : "") : "hoja de cierre #" + (cierre ? cierre.id : "")) + "."
          : "";
        html += '<form class="scm-co-form" data-scm-co-form="' + row.id + '"><label class="scm-co-field"><span>Funcionario responsable</span>' +
          '<select name="employee" required><option value="">Elige un funcionario activo</option>' + activeOptions(preselect) + "</select></label>" +
          '<label class="scm-co-field"><span>Motivo del cambio</span><textarea name="reason" rows="2" required minlength="5" placeholder="Ej.: Según hoja de cierre #420">' + esc(reason) + "</textarea></label>" +
          '<p class="scm-co-hint">Solo se cambian el funcionario y su nombre en el contrato; el cambio queda en el historial del inmueble.</p>' +
          '<div class="scm-co-form-actions"><button type="button" class="scm-co-btn" data-scm-co-close>Cancelar</button><button type="submit" class="scm-co-btn scm-co-btn-primary">Guardar cambio</button></div></form>';
      }
      return html;
    }

    function guideHtml() {
      return '<div class="scm-co-guide"><p>Para cada contrato la ayuda revisa dos documentos:</p><ol>' +
        "<li><strong>Hoja de cierre</strong> — registra quién <em>arrendó</em> el inmueble. Se usa su <code>id_empleado</code>; es la fuente principal.</li>" +
        "<li><strong>Mandato</strong> — registra quién <em>captó</em> el inmueble. Como su <code>id_empleado</code> no es confiable, el funcionario se identifica por el nombre de quien lo creó (y, si no, por su correo). Si el contrato no tiene mandato enlazado, se usa el más reciente del mismo inmueble.</li></ol>" +
        '<table class="scm-co-guide-table"><thead><tr><th>Situación</th><th>Sugerencia</th><th>Confianza</th></tr></thead><tbody>' +
        "<tr><td>Cierre y mandato señalan al mismo funcionario</td><td>Ese funcionario</td><td>Alta</td></tr>" +
        "<tr><td>Solo hay hoja de cierre (sin mandato)</td><td>Funcionario del cierre</td><td>Alta</td></tr>" +
        "<tr><td>Cierre y mandato difieren, o el mandato no se identifica</td><td>Funcionario del cierre</td><td>Media</td></tr>" +
        "<tr><td>Solo hay mandato</td><td>Funcionario del mandato</td><td>Baja</td></tr>" +
        "<tr><td>Ninguno de los dos</td><td>Elegir manualmente</td><td>—</td></tr></tbody></table>" +
        "<p>Si el funcionario sugerido está <strong>retirado</strong>, no se puede asignar: elige el funcionario activo que hoy atiende el contrato.</p>" +
        "<p>Las revisiones, gestiones de cobro y demás acciones del panel ya <strong>no</strong> cambian el funcionario del contrato; solo este módulo puede hacerlo y cada cambio queda en el historial del inmueble.</p></div>";
    }

    function confirmHtml(changes, skipped, preset) {
      var groups = {};
      changes.forEach(function (change) { groups[change.employee_id] = (groups[change.employee_id] || 0) + 1; });
      return '<form class="scm-co-form" data-scm-co-confirm><p>Vas a cambiar el funcionario de <strong>' + changes.length + (changes.length === 1 ? " contrato" : " contratos") + "</strong>:</p><ul>" +
        Object.keys(groups).map(function (id) { return "<li>" + esc(employeeName(id)) + ": " + groups[id] + "</li>"; }).join("") + "</ul>" +
        (skipped ? '<p class="scm-co-hint">' + skipped + (skipped === 1 ? " contrato seleccionado se omite" : " contratos seleccionados se omiten") + " porque no tienen una sugerencia activa distinta del funcionario actual.</p>" : "") +
        '<label class="scm-co-field"><span>Motivo del cambio</span><textarea name="reason" rows="2" required minlength="5">' + esc(preset) + "</textarea></label>" +
        '<div class="scm-co-form-actions"><button type="button" class="scm-co-btn" data-scm-co-close>Cancelar</button><button type="submit" class="scm-co-btn scm-co-btn-primary">Confirmar cambio</button></div></form>';
    }

    var pendingChanges = [];
    function askConfirm(changes, skipped, preset) {
      if (!changes.length) {
        openModal("Nada que cambiar", "<p>Los contratos seleccionados ya tienen el funcionario indicado o no tienen una sugerencia activa.</p>" +
          '<div class="scm-co-form-actions"><button type="button" class="scm-co-btn" data-scm-co-close>Entendido</button></div>');
        return;
      }
      pendingChanges = changes;
      openModal("Confirmar cambio de funcionario", confirmHtml(changes, skipped, preset));
    }

    function save(changes, reason, submitButton) {
      state.saving = true;
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Guardando…";
      }
      return request(actions.contract_owners_save || "", { changes: JSON.stringify(changes), reason: reason })
        .then(function (result) {
          var byId = {};
          state.data.contracts.forEach(function (row) { byId[row.id] = row; });
          (result.updated || []).forEach(function (change) {
            var row = byId[change.contract_id];
            if (!row) return;
            row.employee_id = change.employee_id;
            row.employee_name = change.employee_name;
            decorate(row);
            delete state.selected[row.id];
          });
          state.saving = false;
          closeModal();
          var count = (result.updated || []).length;
          setStatus(count + (count === 1 ? " contrato actualizado." : " contratos actualizados.") +
            (result.skipped ? " " + result.skipped + " ya tenían ese funcionario." : ""), "success");
          fillFilters();
          render();
        })
        .catch(function (error) {
          state.saving = false;
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = "Reintentar";
          }
          var holder = modalBody.querySelector(".scm-co-form-error") || document.createElement("p");
          holder.className = "scm-co-form-error";
          holder.textContent = error.message;
          modalBody.appendChild(holder);
        });
    }

    module.addEventListener("click", function (event) {
      var target = event.target;
      if (target.closest("[data-scm-co-close]")) { closeModal(); return; }
      if (target.closest("[data-scm-co-refresh]")) { state.selected = {}; load(true); return; }
      if (target.closest("[data-scm-co-guide]")) { openModal("¿Cómo se sugiere el funcionario?", guideHtml()); return; }
      if (!state.data) return;
      var kpi = target.closest("[data-scm-co-kpi]");
      if (kpi) {
        var value = kpi.getAttribute("data-scm-co-kpi");
        filters.status.value = filters.status.value === value ? "" : value;
        state.page = 1;
        render();
        return;
      }
      var help = target.closest("[data-scm-co-help]");
      if (help) {
        var row = state.data.contracts.find(function (item) { return item.id === Number(help.getAttribute("data-scm-co-help")); });
        if (row) openModal("Contrato " + (row.contrato || "#" + row.id), helpHtml(row));
        return;
      }
      var quick = target.closest("[data-scm-co-quick]");
      if (quick) {
        var quickRow = state.data.contracts.find(function (item) { return item.id === Number(quick.getAttribute("data-scm-co-quick")); });
        if (quickRow) {
          var src = quickRow.suggestion.source === "mandato" ? "mandato #" + (quickRow.mandato || {}).id : "hoja de cierre #" + (quickRow.cierre || {}).id;
          askConfirm([{ contract_id: quickRow.id, employee_id: quickRow.suggestion.employee_id }], 0, "Según " + src + ".");
        }
        return;
      }
      var page = target.closest("[data-scm-co-page]");
      if (page && !page.disabled) {
        state.page = Number(page.getAttribute("data-scm-co-page")) || 1;
        renderTable();
        tableEl.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }
      if (target.closest("[data-scm-co-bulk-clear]")) { state.selected = {}; render(); return; }
      if (target.closest("[data-scm-co-bulk-suggest]")) {
        var rows = selectedRows();
        var changes = rows.filter(function (item) { return item.suggestedActive && item.suggestion.employee_id !== item.employee_id; })
          .map(function (item) { return { contract_id: item.id, employee_id: item.suggestion.employee_id }; });
        askConfirm(changes, rows.length - changes.length, "Asignación según hoja de cierre y mandato.");
        return;
      }
      if (target.closest("[data-scm-co-bulk-assign]")) {
        var employeeId = module.querySelector("[data-scm-co-bulk-employee]").value;
        if (!employeeId) { setStatus("Elige el funcionario activo al que quieres asignar los contratos.", "error"); return; }
        var selected = selectedRows();
        var assign = selected.filter(function (item) { return item.employee_id !== employeeId; })
          .map(function (item) { return { contract_id: item.id, employee_id: employeeId }; });
        askConfirm(assign, selected.length - assign.length, "");
      }
    });

    module.addEventListener("change", function (event) {
      var target = event.target;
      if (target.matches("[data-scm-co-filter]")) { state.page = 1; render(); return; }
      if (target.matches("[data-scm-co-check]")) {
        var id = Number(target.getAttribute("data-scm-co-check"));
        if (target.checked) state.selected[id] = true; else delete state.selected[id];
        renderBulk();
        return;
      }
      if (target.matches("[data-scm-co-check-page]")) {
        var rows = filteredRows().slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);
        rows.forEach(function (row) { if (target.checked) state.selected[row.id] = true; else delete state.selected[row.id]; });
        renderTable();
        renderBulk();
      }
    });

    module.addEventListener("submit", function (event) {
      var form = event.target;
      event.preventDefault();
      if (state.saving) return;
      var reason = (form.querySelector("[name=reason]") || {}).value || "";
      if (reason.trim().length < 5) {
        form.querySelector("[name=reason]").focus();
        return;
      }
      if (form.hasAttribute("data-scm-co-confirm")) {
        save(pendingChanges, reason.trim(), form.querySelector("[type=submit]"));
        return;
      }
      if (form.hasAttribute("data-scm-co-form")) {
        var employeeId = form.querySelector("[name=employee]").value;
        var row = state.data.contracts.find(function (item) { return item.id === Number(form.getAttribute("data-scm-co-form")); });
        if (!employeeId || !row) return;
        if (employeeId === row.employee_id) {
          setStatus("El contrato ya tiene ese funcionario.", "");
          closeModal();
          return;
        }
        askConfirm([{ contract_id: row.id, employee_id: employeeId }], 0, reason.trim());
      }
    });

    var searchTimer = 0;
    searchEl.addEventListener("input", function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.search = searchEl.value;
        state.page = 1;
        if (state.data) renderTable();
      }, 150);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !modal.hidden) closeModal();
    });

    module.addEventListener("scm:load-contract-owners", function () { load(false); });
    if (panel && panel.classList.contains("active")) load(false);
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
