(function () {
  "use strict";

  // Casos jurídicos: el servidor filtra y pagina; los detalles y formularios se abren en popups apilados.
  var MONTHS = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  var EVENT_ICONS = {
    caso_creado: "add_circle", caso_editado: "edit", estado_cambiado: "swap_horiz", parte_agregada: "person_add", parte_retirada: "person_remove",
    nota: "sticky_note_2", respuesta_registrada: "reply", documento: "attach_file", comunicacion_enviada: "send", pdf_exportado: "picture_as_pdf",
  };
  var EVENT_TITLES = {
    caso_creado: "Caso creado", caso_editado: "Caso editado", estado_cambiado: "Cambio de estado", parte_agregada: "Parte agregada", parte_retirada: "Parte retirada",
    nota: "Nota interna", respuesta_registrada: "Respuesta recibida", documento: "Documentos cargados", comunicacion_enviada: "Comunicación enviada", pdf_exportado: "Constancia exportada",
  };

  var TIMELINE_FILTERS = [["todo", "Todo"], ["comunicaciones", "Comunicaciones"], ["respuestas", "Respuestas"], ["notas", "Notas"], ["documentos", "Documentos"], ["estados", "Estados"], ["bitacora", "Bitácora"]];

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function formatDate(iso) {
    if (!iso) return "Sin fecha";
    return Number(iso.slice(8, 10)) + " " + MONTHS[Number(iso.slice(5, 7)) - 1] + " " + iso.slice(0, 4);
  }
  function formatDateTime(timestamp) {
    var date = new Date(timestamp * 1000);
    var minutes = String(date.getMinutes()).padStart(2, "0");
    return date.getDate() + " " + MONTHS[date.getMonth()] + " " + date.getFullYear() + ", " + date.getHours() + ":" + minutes;
  }
  function today() {
    var now = new Date();
    return now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") + "-" + String(now.getDate()).padStart(2, "0");
  }
  function options(items, selected, placeholder) {
    var html = placeholder === undefined ? "" : '<option value="">' + esc(placeholder) + "</option>";
    return html + items.map(function (item) {
      return '<option value="' + esc(item.value) + '"' + (String(item.value) === String(selected) ? " selected" : "") + ">" + esc(item.label) + "</option>";
    }).join("");
  }

  function init(root) {
    var module = root.querySelector("[data-scm-legal-cases]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.lcInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.lcInit = "1";

    var panel = module.closest(".scm-tab-panel");
    var canManage = module.getAttribute("data-can-manage") === "1";
    var statusEl = module.querySelector("[data-scm-lc-status]");
    var listEl = module.querySelector("[data-scm-lc-list]");
    var kpisEl = module.querySelector("[data-scm-lc-kpis]");
    var pagerEl = module.querySelector("[data-scm-lc-pager]");
    var modalsEl = module.querySelector("[data-scm-lc-modals]");
    var filtersEl = module.querySelector("[data-scm-lc-filters]");
    var state = { boot: null, page: 1, loading: null, modals: [] };
    var searchTimer = 0;

    function setStatus(message, tone) {
      statusEl.textContent = message || "";
      statusEl.hidden = !message;
      statusEl.className = "scm-lc-status" + (tone ? " is-" + tone : "");
    }

    function request(action, fields) {
      var body = fields instanceof FormData ? fields : new FormData();
      body.append("action", action);
      body.append("nonce", runtime.nonce || "");
      if (!(fields instanceof FormData)) Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: body, credentials: "same-origin" })
        .then(function (response) {
          return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
        })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
          return json.data || {};
        });
    }

    // --- Popups apilados: cada formulario conserva el detalle del caso que tiene debajo.
    function openModal(title, html, wide) {
      var holder = document.createElement("div");
      holder.className = "scm-lc-modal";
      holder.innerHTML = '<div class="scm-lc-modal-backdrop"></div>'
        + '<div class="scm-lc-modal-card' + (wide ? " is-wide" : "") + '" role="dialog" aria-modal="true" aria-label="' + esc(title) + '">'
        + '<header><h2 data-scm-lc-modal-title>' + esc(title) + '</h2><button type="button" class="scm-lc-icon-btn" data-scm-lc-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>'
        + '<div class="scm-lc-modal-body">' + html + "</div></div>";
      modalsEl.appendChild(holder);
      var modal = {
        el: holder,
        body: holder.querySelector(".scm-lc-modal-body"),
        lastFocus: document.activeElement,
        setTitle: function (text) { holder.querySelector("[data-scm-lc-modal-title]").textContent = text; },
        close: function () {
          state.modals = state.modals.filter(function (item) { return item !== modal; });
          holder.remove();
          if (!state.modals.length) document.body.classList.remove("scm-lc-modal-open");
          if (modal.lastFocus && modal.lastFocus.focus && document.contains(modal.lastFocus)) modal.lastFocus.focus();
        },
      };
      holder.addEventListener("click", function (event) {
        if (event.target.closest("[data-scm-lc-close]")) modal.close();
      });
      state.modals.push(modal);
      document.body.classList.add("scm-lc-modal-open");
      var focusable = modal.body.querySelector("input, select, textarea, button");
      if (focusable) focusable.focus();
      return modal;
    }

    function formError(container, message) {
      var holder = container.querySelector(".scm-lc-form-error");
      if (!holder) {
        holder = document.createElement("p");
        holder.className = "scm-lc-form-error";
        holder.setAttribute("role", "alert");
        var actions = container.querySelector(".scm-lc-form-actions");
        if (actions) actions.parentNode.insertBefore(holder, actions); else container.appendChild(holder);
      }
      holder.textContent = message;
    }

    function confirmAction(title, message, label, onConfirm) {
      var modal = openModal(title, "<p>" + esc(message) + '</p><div class="scm-lc-form-actions"><button type="button" class="scm-lc-btn" data-scm-lc-close>Cancelar</button><button type="button" class="scm-lc-btn scm-lc-btn-primary" data-scm-lc-accept>' + esc(label) + "</button></div>");
      var button = modal.body.querySelector("[data-scm-lc-accept]");
      button.addEventListener("click", function () {
        button.disabled = true;
        onConfirm().then(function () { modal.close(); }).catch(function (error) {
          button.disabled = false;
          formError(modal.body, error.message);
        });
      });
    }

    // --- Listado
    function filterValues() {
      var values = {};
      new FormData(filtersEl).forEach(function (value, key) { values[key] = value; });
      return values;
    }

    function fillFilters() {
      filtersEl.elements.status.innerHTML = options(Object.keys(state.boot.statuses).map(function (key) { return { value: key, label: state.boot.statuses[key] }; }), "", "Todos");
      filtersEl.elements.type_id.innerHTML = options(state.boot.types.map(function (type) { return { value: type.id, label: type.name }; }), "", "Todos");
      filtersEl.elements.responsible.innerHTML = options(state.boot.responsibles.map(function (person) { return { value: person.id, label: person.name }; }), "", "Todos");
    }

    function loadList() {
      var fields = filterValues();
      fields.page = state.page;
      setStatus("Cargando casos…", "loading");
      return request("scm_legal_case_list", fields).then(function (data) {
        state.page = data.page;
        setStatus("");
        renderList(data);
      }).catch(function (error) { setStatus(error.message, "error"); });
    }

    function load(force) {
      if (state.loading) return state.loading;
      if (state.boot && !force) return Promise.resolve();
      setStatus("Cargando casos…", "loading");
      state.loading = request("scm_legal_case_bootstrap", {}).then(function (data) {
        var selected = filterValues();
        state.boot = data;
        fillFilters();
        Object.keys(selected).forEach(function (key) { filtersEl.elements[key].value = selected[key]; });
        if (panel) panel.setAttribute("data-scm-loaded", "1");
        return loadList();
      }).catch(function (error) { setStatus(error.message, "error"); }).then(function () { state.loading = null; });
      return state.loading;
    }

    function renderList(data) {
      var kpis = data.kpis;
      kpisEl.innerHTML = kpi(kpis.abierto, "Abiertos", "") + kpi(kpis.en_tramite, "En trámite", "") + kpi(kpis.proximos, "Vencen en 7 días", kpis.proximos ? "is-warn" : "") + kpi(kpis.vencidos, "Vencidos", kpis.vencidos ? "is-bad" : "");
      if (!data.rows.length) {
        listEl.innerHTML = '<p class="scm-lc-empty">No hay casos con estos filtros.</p>';
        pagerEl.innerHTML = "";
        return;
      }
      listEl.innerHTML = '<table class="scm-lc-table"><thead><tr><th>Caso</th><th>Contrato / inmueble</th><th>Responsable</th><th>Estado</th><th>Fecha límite</th></tr></thead><tbody>'
        + data.rows.map(function (row) {
          return '<tr><td><button type="button" class="scm-lc-link" data-scm-lc-open="' + row.id + '">' + esc(row.code) + "</button><div>" + esc(row.name) + "</div><small>" + esc(row.type_name) + "</small></td>"
            + '<td data-label="Contrato">' + esc(row.contract_code || "Sin contrato") + "<small>" + esc([row.property, row.address].filter(Boolean).join(" · ")) + "</small></td>"
            + '<td data-label="Responsable">' + esc(row.responsible_name) + "</td>"
            + '<td data-label="Estado"><span class="scm-lc-badge is-' + esc(row.status) + '">' + esc(row.status_label) + "</span></td>"
            + '<td data-label="Fecha límite"' + (row.overdue ? ' class="scm-lc-overdue"' : "") + ">" + esc(row.due_date ? formatDate(row.due_date) : "Sin fecha") + "<small>" + esc(row.next_action) + "</small></td></tr>";
        }).join("") + "</tbody></table>";
      pagerEl.innerHTML = '<span>' + data.total + (data.total === 1 ? " caso" : " casos") + " · Página " + data.page + " de " + data.pages + "</span>"
        + '<button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-page="' + (data.page - 1) + '"' + (data.page <= 1 ? " disabled" : "") + ">Anterior</button>"
        + '<button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? " disabled" : "") + ">Siguiente</button>";
    }

    function kpi(value, label, tone) {
      return '<div class="scm-lc-kpi ' + tone + '"><strong>' + Number(value || 0) + "</strong><span>" + esc(label) + "</span></div>";
    }

    // --- Formulario del caso
    function partyCard(party, index) {
      return '<div class="scm-lc-party"><div><strong>' + esc(party.name) + "</strong><small>" + esc(state.boot.roles[party.role] || party.role) + (party.company ? " · " + esc(party.company) : "") + "</small><small>" + esc(party.email || "Sin correo") + " · " + esc(party.phone || "Sin celular") + "</small></div>"
        + '<button type="button" class="scm-lc-icon-btn" data-scm-lc-draft-remove="' + index + '" aria-label="Quitar a ' + esc(party.name) + '"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>';
    }

    function partyFields() {
      return '<div class="scm-lc-grid">'
        + '<label class="scm-lc-field"><span>Rol</span><select name="role" data-party-field="role">' + options(Object.keys(state.boot.roles).map(function (key) { return { value: key, label: state.boot.roles[key] }; }), "tercero") + "</select></label>"
        + '<label class="scm-lc-field"><span>Nombre</span><input name="name" data-party-field="name" maxlength="190"></label>'
        + '<label class="scm-lc-field"><span>Empresa</span><input name="company" data-party-field="company" maxlength="190"></label>'
        + '<label class="scm-lc-field"><span>Documento</span><input name="document" data-party-field="document" maxlength="40"></label>'
        + '<label class="scm-lc-field"><span>Correo</span><input type="email" name="email" data-party-field="email" maxlength="190"></label>'
        + '<label class="scm-lc-field"><span>Celular</span><input name="phone" data-party-field="phone" maxlength="40"></label></div>';
    }

    function readPartyFields(container) {
      var party = {};
      ["role", "name", "company", "document", "email", "phone"].forEach(function (name) { party[name] = container.querySelector('[data-party-field="' + name + '"]').value.trim(); });
      return party;
    }

    function openCaseForm(existing, onSaved) {
      var data = existing || { opened_date: today(), responsible_id: state.boot.employee_id };
      var draft = { contract: existing && existing.contract_id ? { id: existing.contract_id, contract: existing.contract_code, property: existing.property, address: existing.address } : null, property: existing && !existing.contract_id && existing.property ? { code: existing.property, address: existing.address } : null, parties: [] };
      var types = state.boot.types.filter(function (type) { return type.active || type.id === data.type_id; }).map(function (type) { return { value: type.id, label: type.name }; });
      var people = state.boot.responsibles.map(function (person) { return { value: person.id, label: person.name + " · " + person.cargo }; });
      var html = '<form class="scm-lc-form" autocomplete="off"><div class="scm-lc-grid">'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Nombre del caso</span><input name="name" maxlength="190" required value="' + esc(data.name) + '" placeholder="Ej. Caso incendio apto 401"></label>'
        + '<label class="scm-lc-field"><span>Tipo</span><select name="type_id" required>' + options(types, data.type_id, "Elige un tipo") + "</select></label>"
        + '<label class="scm-lc-field"><span>Responsable</span><select name="responsible_id" required>' + options(people, data.responsible_id, "Elige un funcionario") + "</select></label>"
        + '<label class="scm-lc-field"><span>Fecha de apertura</span><input type="date" name="opened_date" required value="' + esc(data.opened_date) + '"></label>'
        + '<label class="scm-lc-field"><span>Fecha límite</span><input type="date" name="due_date" value="' + esc(data.due_date) + '"></label>'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Próxima acción</span><input name="next_action" maxlength="255" value="' + esc(data.next_action) + '"></label>'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Descripción</span><textarea name="description" maxlength="10000">' + esc(data.description) + "</textarea></label></div>"
        + '<section class="scm-lc-section"><h3>Contrato o inmueble (opcional)</h3><div data-scm-lc-link></div></section>'
        + (existing ? "" : '<section class="scm-lc-section"><div class="scm-lc-section-head"><h3>Partes involucradas</h3></div><p class="scm-lc-hint">Al elegir un contrato se cargan sus propietarios y arrendatario.</p><div class="scm-lc-parties" data-scm-lc-draft-parties></div><details><summary>Agregar otra parte</summary><div data-scm-lc-party-fields>' + partyFields() + '</div><div class="scm-lc-form-actions"><button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-draft-add>Agregar a la lista</button></div></details></section>')
        + '<div class="scm-lc-form-actions"><button type="button" class="scm-lc-btn" data-scm-lc-close>Cancelar</button><button type="submit" class="scm-lc-btn scm-lc-btn-primary">' + (existing ? "Guardar cambios" : "Crear caso") + "</button></div></form>";
      var modal = openModal(existing ? "Editar " + existing.code : "Nuevo caso", html, true);
      var form = modal.body.querySelector("form");
      var linkEl = form.querySelector("[data-scm-lc-link]");
      var partiesEl = form.querySelector("[data-scm-lc-draft-parties]");
      // Los campos de la parte en borrador no van en el envío: llegan como JSON en `parties`.
      form.querySelectorAll("[data-party-field]").forEach(function (field) { field.removeAttribute("name"); });

      function renderParties() {
        if (partiesEl) partiesEl.innerHTML = draft.parties.length ? draft.parties.map(partyCard).join("") : '<p class="scm-lc-hint">Aún no hay partes.</p>';
      }

      function renderLink() {
        var selected = draft.contract
          ? "Contrato " + draft.contract.contract + " · " + [draft.contract.property, draft.contract.address].filter(Boolean).join(" · ")
          : draft.property ? "Inmueble " + draft.property.code + " · " + draft.property.address : "";
        if (selected) {
          linkEl.innerHTML = '<div class="scm-lc-selected"><span>' + esc(selected) + '</span><button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-link-clear>Quitar</button></div>';
          return;
        }
        linkEl.innerHTML = '<div class="scm-lc-search"><label class="scm-lc-field"><span>Buscar por</span><select data-scm-lc-search-by><option value="contrato">Contrato</option><option value="inmueble">Inmueble del contrato</option><option value="propietario">Propietario</option><option value="arrendatario">Arrendatario</option><option value="documento">Documento</option><option value="solo_inmueble">Inmueble sin contrato</option></select></label>'
          + '<label class="scm-lc-field"><span>Texto</span><input type="search" data-scm-lc-search-query maxlength="100"></label><button type="button" class="scm-lc-btn" data-scm-lc-search>Buscar</button></div><div class="scm-lc-results" data-scm-lc-results></div>';
      }

      function search() {
        var by = linkEl.querySelector("[data-scm-lc-search-by]").value;
        var query = linkEl.querySelector("[data-scm-lc-search-query]").value.trim();
        var results = linkEl.querySelector("[data-scm-lc-results]");
        results.innerHTML = '<p class="scm-lc-hint">Buscando…</p>';
        var call = by === "solo_inmueble" ? request("scm_legal_case_property_search", { query: query }) : request("scm_legal_case_contract_search", { query: query, by: by });
        call.then(function (result) {
          results.innerHTML = result.rows.length ? result.rows.map(function (row, index) {
            return by === "solo_inmueble"
              ? '<button type="button" class="scm-lc-result" data-scm-lc-pick-property="' + index + '"><strong>' + esc(row.code) + "</strong><small>" + esc(row.address) + "</small></button>"
              : '<button type="button" class="scm-lc-result" data-scm-lc-pick-contract="' + index + '"><strong>Contrato ' + esc(row.contract) + "</strong> · " + esc(row.status) + "<small>" + esc([row.property, row.address].filter(Boolean).join(" · ")) + "</small><small>" + esc([row.owner, row.tenant].filter(Boolean).join(" / ")) + "</small></button>";
          }).join("") : '<p class="scm-lc-hint">Sin resultados.</p>';
          draft.results = result.rows;
        }).catch(function (error) { results.innerHTML = '<p class="scm-lc-form-error">' + esc(error.message) + "</p>"; });
      }

      form.addEventListener("click", function (event) {
        var target = event.target;
        if (target.closest("[data-scm-lc-search]")) return search();
        if (target.closest("[data-scm-lc-link-clear]")) {
          draft.contract = null;
          draft.property = null;
          draft.parties = draft.parties.filter(function (party) { return party.source !== "contrato"; });
          renderLink();
          return renderParties();
        }
        var pickProperty = target.closest("[data-scm-lc-pick-property]");
        if (pickProperty) {
          draft.property = draft.results[Number(pickProperty.getAttribute("data-scm-lc-pick-property"))];
          draft.contract = null;
          return renderLink();
        }
        var pickContract = target.closest("[data-scm-lc-pick-contract]");
        if (pickContract) {
          draft.contract = draft.results[Number(pickContract.getAttribute("data-scm-lc-pick-contract"))];
          draft.property = null;
          renderLink();
          if (!partiesEl) return undefined;
          request("scm_legal_case_contract_parties", { contract_id: draft.contract.id }).then(function (result) {
            draft.parties = draft.parties.filter(function (party) { return party.source !== "contrato"; }).concat(result.parties);
            renderParties();
          }).catch(function (error) { formError(form, error.message); });
          return undefined;
        }
        var remove = target.closest("[data-scm-lc-draft-remove]");
        if (remove) {
          draft.parties.splice(Number(remove.getAttribute("data-scm-lc-draft-remove")), 1);
          return renderParties();
        }
        if (target.closest("[data-scm-lc-draft-add]")) {
          var container = form.querySelector("[data-scm-lc-party-fields]");
          var party = readPartyFields(container);
          if (!party.name) return formError(form, "Escribe el nombre de la parte.");
          draft.parties.push(party);
          container.querySelectorAll("input").forEach(function (input) { input.value = ""; });
          return renderParties();
        }
        return undefined;
      });
      form.addEventListener("keydown", function (event) {
        if (event.key !== "Enter") return;
        if (event.target.matches("[data-scm-lc-search-query]")) {
          event.preventDefault();
          search();
        } else if (event.target.closest("[data-scm-lc-party-fields]")) {
          event.preventDefault();
        }
      });
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var body = new FormData(form);
        if (existing) body.append("case_id", existing.id); else body.append("parties", JSON.stringify(draft.parties));
        body.append("contract_id", draft.contract ? draft.contract.id : 0);
        body.append("property", draft.property ? draft.property.code : "");
        body.append("address", draft.property ? draft.property.address : "");
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        request("scm_legal_case_save", body).then(function (result) {
          modal.close();
          loadList();
          if (onSaved) onSaved(result.case_id);
        }).catch(function (error) {
          submit.disabled = false;
          formError(form, error.message);
        });
      });
      renderLink();
      renderParties();
    }

    // --- Detalle
    function eventBody(event) {
      var details = event.details || {};
      switch (event.action) {
        case "caso_creado": return "Responsable: " + esc(details.responsable);
        case "caso_editado": return (details.cambios || []).map(function (change) { return esc(change.campo) + ": " + esc(change.antes || "vacío") + " → " + esc(change.despues || "vacío"); }).join("<br>");
        case "estado_cambiado": return esc(details.antes) + " → " + esc(details.despues) + (details.nota ? '<div class="scm-lc-event-text">' + esc(details.nota) + "</div>" : "");
        case "parte_agregada":
        case "parte_retirada": return esc(details.nombre) + " · " + esc(details.rol);
        case "nota": return '<div class="scm-lc-event-text">' + esc(details.texto) + "</div>";
        case "respuesta_registrada": return "De " + esc(details.remitente) + " · " + esc(details.canal) + " · recibida el " + esc(formatDate(details.fecha_recepcion)) + (details.asunto ? "<br><strong>" + esc(details.asunto) + "</strong>" : "") + '<div class="scm-lc-event-text">' + esc(details.texto) + "</div>";
        default: return "";
      }
    }

    function fileSize(bytes) {
      return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(bytes / 1024)) + " KB";
    }

    function documentButton(item) {
      return '<button type="button" class="scm-lc-file" data-scm-lc-doc="' + item.id + '"><span class="material-symbols-outlined" aria-hidden="true">description</span><span>' + esc(item.name) + "</span><small>" + esc(fileSize(item.bytes)) + "</small></button>";
    }

    function eventDocuments(event, documents) {
      var linked = documents.filter(function (item) { return item.event_id === event.id; });
      return linked.length ? '<div class="scm-lc-files">' + linked.map(documentButton).join("") + "</div>" : "";
    }

    function eventGroup(action) {
      if (action === "comunicacion_enviada") return "comunicaciones";
      if (action === "respuesta_registrada") return "respuestas";
      if (action === "nota") return "notas";
      if (action === "documento") return "documentos";
      if (action === "estado_cambiado") return "estados";
      return "bitacora";
    }

    function renderTimeline(data, filter) {
      var events = data.events.filter(function (event) { return filter === "todo" || eventGroup(event.action) === filter; });
      if (!events.length) return '<p class="scm-lc-hint">No hay registros de este tipo.</p>';
      return '<ol class="scm-lc-timeline">' + events.map(function (event) {
        return '<li class="scm-lc-event"><span class="scm-lc-event-icon"><span class="material-symbols-outlined" aria-hidden="true">' + esc(EVENT_ICONS[event.action] || "info") + '</span></span><div class="scm-lc-event-body"><strong>' + esc(EVENT_TITLES[event.action] || event.action) + '</strong><div class="scm-lc-event-meta">' + esc(event.actor_name) + " · " + esc(formatDateTime(event.created_at)) + "</div><div>" + eventBody(event) + "</div>" + eventDocuments(event, data.documents) + "</div></li>";
      }).join("") + "</ol>";
    }

    function previewDocument(item) {
      var isPdf = item.mime === "application/pdf";
      var preview = isPdf
        ? '<iframe class="scm-lc-preview" title="' + esc(item.name) + '"></iframe>'
        : item.mime.indexOf("image/") === 0
          ? '<img class="scm-lc-preview-image" alt="' + esc(item.name) + '" src="' + esc(item.url) + '">'
          : '<p class="scm-lc-hint">Este tipo de archivo no se puede ver aquí. Descárgalo para abrirlo.</p>';
      var modal = openModal(item.name, preview + '<p class="scm-lc-hint">Cargado por ' + esc(item.uploaded_by) + " · " + esc(formatDateTime(item.created_at)) + '<br>SHA-256: <code>' + esc(item.sha256) + '</code></p><div class="scm-lc-form-actions"><a class="scm-lc-btn" download href="' + esc(item.url) + '">Descargar</a></div>', true);
      if (!isPdf) return;
      // file.php responde con CSP sandbox, que impide al visor de PDF del navegador abrirlo directo en un iframe.
      fetch(item.url, { credentials: "same-origin" }).then(function (response) {
        if (!response.ok) throw new Error("No disponible");
        return response.blob();
      }).then(function (blob) {
        var objectUrl = URL.createObjectURL(new Blob([blob], { type: "application/pdf" }));
        var frame = modal.body.querySelector("iframe");
        if (frame) frame.src = objectUrl;
      }).catch(function () { formError(modal.body, "No se pudo cargar la vista previa. Descarga el archivo."); });
    }

    function submitForm(modal, form, action, extra, after) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var body = new FormData(form);
        Object.keys(extra).forEach(function (key) { body.append(key, extra[key]); });
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        request(action, body).then(function () {
          modal.close();
          return after();
        }).catch(function (error) {
          submit.disabled = false;
          formError(form, error.message);
        });
      });
    }

    var FORM_ACTIONS = '<div class="scm-lc-form-actions"><button type="button" class="scm-lc-btn" data-scm-lc-close>Cancelar</button><button type="submit" class="scm-lc-btn scm-lc-btn-primary">Guardar</button></div>';
    var FILE_INPUT = '<input type="file" name="files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.jpg,.jpeg,.png,.webp">';

    function openNoteForm(item, detailModal) {
      var modal = openModal("Nota interna · " + item.code, '<form class="scm-lc-form"><label class="scm-lc-field"><span>Nota</span><textarea name="text" maxlength="5000" required></textarea></label><p class="scm-lc-hint">Las notas no se envían a nadie.</p>' + FORM_ACTIONS + "</form>");
      submitForm(modal, modal.body.querySelector("form"), "scm_legal_case_note", { case_id: item.id }, function () { return reloadDetail(detailModal, item.id); });
    }

    function openReplyForm(item, detailModal) {
      var channels = ["Correo", "WhatsApp", "Físico", "Otro"].map(function (name) { return { value: name, label: name }; });
      var modal = openModal("Registrar respuesta · " + item.code, '<form class="scm-lc-form" autocomplete="off"><div class="scm-lc-grid">'
        + '<label class="scm-lc-field"><span>Llegó por</span><select name="channel" required>' + options(channels, "Correo") + "</select></label>"
        + '<label class="scm-lc-field"><span>Fecha de recepción</span><input type="date" name="received_date" required max="' + today() + '" value="' + today() + '"></label>'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Quién respondió</span><input name="from" maxlength="190" required></label>'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Asunto (opcional)</span><input name="subject" maxlength="255"></label>'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Contenido de la respuesta</span><textarea name="body" maxlength="20000" required></textarea></label>'
        + '<label class="scm-lc-field scm-lc-field-full"><span>Adjuntos (opcional)</span>' + FILE_INPUT + "</label></div>" + FORM_ACTIONS + "</form>");
      submitForm(modal, modal.body.querySelector("form"), "scm_legal_case_reply", { case_id: item.id }, function () { return reloadDetail(detailModal, item.id); });
    }

    function openDocumentsForm(item, detailModal) {
      var modal = openModal("Cargar documentos · " + item.code, '<form class="scm-lc-form"><label class="scm-lc-field"><span>Archivos (máximo 10)</span>' + FILE_INPUT.replace("<input", "<input required") + "</label>" + FORM_ACTIONS + "</form>");
      submitForm(modal, modal.body.querySelector("form"), "scm_legal_case_documents", { case_id: item.id }, function () { return reloadDetail(detailModal, item.id); });
    }

    function renderDetail(modal, data) {
      var item = data.case;
      modal.setTitle(item.code + " · " + item.name);
      var actions = canManage
        ? '<div class="scm-lc-actions"><button type="button" class="scm-lc-btn" data-scm-lc-edit>Editar</button><button type="button" class="scm-lc-btn" data-scm-lc-status-open>Cambiar estado</button></div>'
        : "";
      modal.body.innerHTML = '<div class="scm-lc-section-head"><span class="scm-lc-badge is-' + esc(item.status) + '">' + esc(item.status_label) + "</span>" + actions + "</div>"
        + '<dl class="scm-lc-info">' + info("Tipo", item.type_name) + info("Responsable", item.responsible_name) + info("Contrato", item.contract_code || "Sin contrato") + info("Inmueble", [item.property, item.address].filter(Boolean).join(" · ") || "Sin inmueble") + info("Apertura", formatDate(item.opened_date)) + info("Fecha límite", item.due_date ? formatDate(item.due_date) : "Sin fecha") + info("Próxima acción", item.next_action || "Sin definir") + "</dl>"
        + (item.description ? '<div class="scm-lc-section"><h3>Descripción</h3><div class="scm-lc-description">' + esc(item.description) + "</div></div>" : "")
        + '<section class="scm-lc-section"><div class="scm-lc-section-head"><h3>Partes involucradas</h3>' + (canManage ? '<button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-party-new>Agregar parte</button>' : "") + "</div>"
        + '<div class="scm-lc-parties">' + (data.parties.length ? data.parties.map(function (party) {
          return '<div class="scm-lc-party"><div><strong>' + esc(party.name) + "</strong><small>" + esc(party.role_label) + (party.company ? " · " + esc(party.company) : "") + "</small><small>" + esc(party.email || "Sin correo") + " · " + esc(party.phone || "Sin celular") + "</small></div>"
            + (canManage ? '<button type="button" class="scm-lc-icon-btn" data-scm-lc-party-remove="' + party.id + '" data-name="' + esc(party.name) + '" aria-label="Retirar a ' + esc(party.name) + '"><span class="material-symbols-outlined" aria-hidden="true">person_remove</span></button>' : "") + "</div>";
        }).join("") : '<p class="scm-lc-hint">Este caso no tiene partes.</p>') + "</div></section>"
        + '<section class="scm-lc-section"><h3>Documentos</h3>' + (data.documents.length ? '<div class="scm-lc-files">' + data.documents.map(documentButton).join("") + "</div>" : '<p class="scm-lc-hint">Aún no hay documentos.</p>') + "</section>"
        + '<section class="scm-lc-section"><div class="scm-lc-section-head"><h3>Línea de tiempo</h3>' + (canManage ? '<div class="scm-lc-actions"><button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-note>Nota interna</button><button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-reply>Registrar respuesta</button><button type="button" class="scm-lc-btn scm-lc-btn-sm" data-scm-lc-upload>Cargar documentos</button></div>' : "") + "</div>"
        + '<div class="scm-lc-chips" role="group" aria-label="Filtrar la línea de tiempo">' + TIMELINE_FILTERS.map(function (filter) { return '<button type="button" class="scm-lc-chip' + (filter[0] === "todo" ? " is-active" : "") + '" data-scm-lc-timeline-filter="' + filter[0] + '">' + filter[1] + "</button>"; }).join("") + "</div>"
        + '<div data-scm-lc-timeline>' + renderTimeline(data, "todo") + "</div></section>";
      modal.data = data;
    }

    function info(label, value) {
      return "<div><dt>" + esc(label) + "</dt><dd>" + esc(value) + "</dd></div>";
    }

    function reloadDetail(modal, id) {
      return request("scm_legal_case_detail", { case_id: id }).then(function (data) { renderDetail(modal, data); });
    }

    function openDetail(id) {
      var modal = openModal("Cargando caso…", '<p class="scm-lc-hint">Cargando…</p>', true);
      reloadDetail(modal, id).catch(function (error) { modal.body.innerHTML = '<p class="scm-lc-form-error">' + esc(error.message) + "</p>"; });
      modal.body.addEventListener("click", function (event) {
        var target = event.target;
        var data = modal.data;
        if (!data) return;
        if (target.closest("[data-scm-lc-edit]")) openCaseForm(data.case, function () { reloadDetail(modal, id); });
        if (target.closest("[data-scm-lc-status-open]")) openStatusForm(data.case, modal);
        if (target.closest("[data-scm-lc-party-new]")) openPartyForm(data.case, modal);
        if (target.closest("[data-scm-lc-note]")) openNoteForm(data.case, modal);
        if (target.closest("[data-scm-lc-reply]")) openReplyForm(data.case, modal);
        if (target.closest("[data-scm-lc-upload]")) openDocumentsForm(data.case, modal);
        var doc = target.closest("[data-scm-lc-doc]");
        if (doc) {
          var docId = Number(doc.getAttribute("data-scm-lc-doc"));
          previewDocument(data.documents.filter(function (item) { return item.id === docId; })[0]);
        }
        var chip = target.closest("[data-scm-lc-timeline-filter]");
        if (chip) {
          modal.body.querySelectorAll("[data-scm-lc-timeline-filter]").forEach(function (other) { other.classList.toggle("is-active", other === chip); });
          modal.body.querySelector("[data-scm-lc-timeline]").innerHTML = renderTimeline(data, chip.getAttribute("data-scm-lc-timeline-filter"));
        }
        var remove = target.closest("[data-scm-lc-party-remove]");
        if (remove) {
          confirmAction("Retirar parte", "¿Retirar a " + remove.getAttribute("data-name") + " de este caso? Las comunicaciones ya enviadas se conservan.", "Retirar", function () {
            return request("scm_legal_case_party_remove", { case_id: id, party_id: remove.getAttribute("data-scm-lc-party-remove") }).then(function () { return reloadDetail(modal, id); });
          });
        }
      });
    }

    function openStatusForm(item, detailModal) {
      var choices = Object.keys(state.boot.statuses).filter(function (key) { return key !== item.status; }).map(function (key) { return { value: key, label: state.boot.statuses[key] }; });
      var modal = openModal("Cambiar estado de " + item.code, '<form class="scm-lc-form"><label class="scm-lc-field"><span>Nuevo estado</span><select name="status">' + options(choices, "") + '</select></label><label class="scm-lc-field"><span>Observación (opcional)</span><textarea name="note" maxlength="2000"></textarea></label><div class="scm-lc-form-actions"><button type="button" class="scm-lc-btn" data-scm-lc-close>Cancelar</button><button type="submit" class="scm-lc-btn scm-lc-btn-primary">Cambiar estado</button></div></form>');
      var form = modal.body.querySelector("form");
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var body = new FormData(form);
        body.append("case_id", item.id);
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        request("scm_legal_case_status", body).then(function () {
          modal.close();
          loadList();
          return reloadDetail(detailModal, item.id);
        }).catch(function (error) {
          submit.disabled = false;
          formError(form, error.message);
        });
      });
    }

    function openPartyForm(item, detailModal) {
      var modal = openModal("Agregar parte a " + item.code, '<form class="scm-lc-form" autocomplete="off">' + partyFields() + '<div class="scm-lc-form-actions"><button type="button" class="scm-lc-btn" data-scm-lc-close>Cancelar</button><button type="submit" class="scm-lc-btn scm-lc-btn-primary">Agregar</button></div></form>');
      var form = modal.body.querySelector("form");
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var body = new FormData(form);
        body.append("case_id", item.id);
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        request("scm_legal_case_party_add", body).then(function () {
          modal.close();
          return reloadDetail(detailModal, item.id);
        }).catch(function (error) {
          submit.disabled = false;
          formError(form, error.message);
        });
      });
    }

    // --- Eventos del panel
    module.addEventListener("click", function (event) {
      var target = event.target;
      if (target.closest("[data-scm-lc-refresh]")) load(true);
      if (target.closest("[data-scm-lc-new]") && state.boot) openCaseForm(null, openDetail);
      var open = target.closest("[data-scm-lc-open]");
      if (open) openDetail(Number(open.getAttribute("data-scm-lc-open")));
      var page = target.closest("[data-scm-lc-page]");
      if (page && !page.disabled) {
        state.page = Number(page.getAttribute("data-scm-lc-page"));
        loadList();
      }
    });
    function onFilterChange() {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.page = 1;
        if (state.boot) loadList();
      }, 300);
    }
    filtersEl.addEventListener("input", onFilterChange);
    filtersEl.addEventListener("change", onFilterChange);
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && state.modals.length) state.modals[state.modals.length - 1].close();
    });
    module.addEventListener("scm:load-legal-cases", function () { load(false); });
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
