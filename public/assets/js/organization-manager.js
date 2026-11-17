(function () {
  "use strict";

  // Actividades contractuales › Gestor organizacional (antes plugin [gestor_organizacional]).
  // El servidor filtra y valida permisos (acciones scm_org_*); aquí se dibujan las pestañas, los formularios y los
  // popups. Todo texto de la base se escapa; solo el HTML de las políticas llega ya limpio desde el servidor.

  var UI = {
    card: "!sp-bg-white !sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-card !sp-overflow-hidden",
    input: "!sp-block !sp-box-border !sp-w-full !sp-h-10 !sp-px-3 !sp-border !sp-border-solid !sp-border-slate-300 !sp-rounded-lg !sp-bg-slate-50 !sp-text-service-navy !sp-text-xs !sp-font-sans focus:!sp-outline-none focus:!sp-ring-2 focus:!sp-ring-service-yellow focus:!sp-border-service-navy disabled:!sp-opacity-50",
    textarea: "!sp-block !sp-box-border !sp-w-full !sp-h-auto !sp-px-3 !sp-py-2 !sp-border !sp-border-solid !sp-border-slate-300 !sp-rounded-lg !sp-bg-slate-50 !sp-text-service-navy !sp-text-xs !sp-font-sans !sp-resize-y focus:!sp-outline-none focus:!sp-ring-2 focus:!sp-ring-service-yellow focus:!sp-border-service-navy",
    label: "!sp-block !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-navy !sp-mb-2",
    primary: "!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-4 !sp-py-2.5 !sp-rounded-lg !sp-bg-service-yellow !sp-text-service-navy !sp-border !sp-border-solid !sp-border-service-yellow !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-amber-300 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-blue disabled:!sp-opacity-50",
    secondary: "!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-text-service-navy !sp-border !sp-border-solid !sp-border-slate-300 !sp-text-xs !sp-font-medium !sp-font-sans !sp-cursor-pointer hover:!sp-bg-slate-50 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow disabled:!sp-opacity-50",
    navy: "!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-service-navy !sp-text-white !sp-border !sp-border-solid !sp-border-service-navy !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-service-blue focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow disabled:!sp-opacity-50",
    tabActive: ["!sp-bg-service-navy", "!sp-text-white", "!sp-border-service-navy"],
    tabIdle: ["!sp-bg-white", "!sp-text-service-navy", "!sp-border-slate-200", "hover:!sp-bg-slate-50"],
    muted: "!sp-m-0 !sp-text-xs !sp-text-service-muted",
    empty: "!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted",
  };

  var CATALOG_TABS = ["areas_organizacion", "cargos", "politicas", "procesos", "funciones", "compensaciones", "funcionarios"];
  var SEARCH = {
    areas_organizacion: ["Buscar nombre del área…", "Buscar en la descripción…"],
    cargos: ["Buscar nombre del cargo…", "Buscar misión o función principal…"],
    politicas: ["Buscar título o código…", "Buscar en el texto de la política…"],
    procesos: ["Buscar nombre del proceso…", "Buscar definición, alcance o entregable…"],
    funciones: ["Buscar función…", "Buscar frecuencia…"],
    compensaciones: ["Buscar base de compensación…", "Buscar condición…"],
    funcionarios: ["Buscar nombre del funcionario…", "Buscar correo, celular, rol o gestión…"],
  };
  var FILTERS = {
    areas_organizacion: [],
    cargos: ["id_area"],
    politicas: ["id_area", "id_cargo", "estado", "audiencia", "sin_area"],
    procesos: ["id_area"],
    funciones: ["id_area", "id_cargo"],
    compensaciones: ["id_area", "id_cargo"],
    funcionarios: ["id_area", "id_cargo", "activo"],
  };
  var CARGO_PROFILE = [
    ["funcion_principal", "Función principal"], ["mision_cargo", "Misión"], ["kpis", "KPIs"], ["competencias_tecnicas", "Competencias técnicas"],
    ["competencias_blandas", "Competencias blandas"], ["requisitos_academicos", "Requisitos académicos"], ["experiencia_requerida", "Experiencia requerida"],
    ["tipo_contrato", "Tipo de contrato"], ["modalidad_trabajo", "Modalidad de trabajo"], ["valores_institucionales", "Valores institucionales"], ["beneficios", "Beneficios"],
  ];
  var ROLES = [["Precaptador", "Precaptador"], ["Comercial", "Captación y actualización"], ["Promocion", "Promoción y colocación"], ["Servicio al cliente", "Servicio al cliente"],
    ["Servicio al propietario", "Servicio al propietario"], ["Servicio al arrendatario", "Servicio al arrendatario"], ["Contractual", "Contractual"], ["Mantenimiento", "Mantenimiento"],
    ["Servicios inmobiliarios", "Servicios inmobiliarios"], ["Gerencia", "Gerencia"], ["Gerencia externa", "Gerencia externa"], ["Desarrollo", "Desarrollo"], ["Soporte", "Soporte"],
    ["Avaluo", "Avalúo"], ["Contable", "Contable"], ["Todos", "Todos"]];
  var ACTIVITY_TYPES = { areas_organizacion: "Área", cargos: "Cargo", politicas: "Política", procesos: "Proceso", funciones: "Función", compensaciones: "Compensación", funcionarios: "Funcionario" };
  var HISTORY_ACTIONS = { creada: "Creada", editada: "Editada", nueva_version: "Nueva versión", migracion: "Versión inicial" };

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
    });
  }
  function fold(value) {
    return String(value || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\s+/g, " ").trim();
  }
  function ids(value) {
    var seen = {};
    return String(value == null ? "" : value).replace(/[;|\s]/g, ",").split(",").map(function (part) { return String(Number(part) || ""); })
      .filter(function (part) { if (!part || part === "0" || seen[part]) return false; seen[part] = true; return true; });
  }
  function icon(name) {
    return '<span class="material-symbols-outlined" aria-hidden="true">' + esc(name) + "</span>";
  }
  function spinner() {
    return '<span class="!sp-inline-block !sp-w-3.5 !sp-h-3.5 !sp-rounded-full !sp-border-2 !sp-border-solid !sp-border-current !sp-border-t-transparent !sp-animate-spin" aria-hidden="true"></span>';
  }
  function badge(text, kind) {
    return text ? '<span class="scm-om-badge' + (kind ? " is-" + esc(kind) : "") + '">' + esc(text) + "</span>" : "";
  }
  function options(items, selected, placeholder) {
    var html = placeholder === undefined ? "" : '<option value="">' + esc(placeholder) + "</option>";
    var found = false;
    items.forEach(function (item) {
      var isSelected = String(item[0]) === String(selected == null ? "" : selected);
      found = found || isSelected;
      html += '<option value="' + esc(item[0]) + '"' + (isSelected ? " selected" : "") + ">" + esc(item[1]) + "</option>";
    });
    if (!found && selected) html += '<option value="' + esc(selected) + '" selected>' + esc(selected) + "</option>";
    return html;
  }

  function init(root) {
    var module = root.querySelector("[data-scm-org-manager]");
    var core = window.SCMAdminCore;
    if (!module || !core || module.dataset.omInit === "1") return;
    var runtime = core.parseRuntime(root);
    if (!runtime) return;
    module.dataset.omInit = "1";

    var body = module.querySelector("[data-om-body]");
    var statusEl = module.querySelector("[data-om-status]");
    var can = {
      view: module.getAttribute("data-can-view") === "1", manage: module.getAttribute("data-can-manage") === "1", del: module.getAttribute("data-can-delete") === "1",
      transfer: module.getAttribute("data-can-transfer") === "1", exportPdf: module.getAttribute("data-can-export") === "1",
    };
    // Los popups van en la raíz del panel: un panel oculto escondería el popup.
    var modalsEl = document.createElement("div");
    modalsEl.setAttribute("data-om-modals", "");
    root.appendChild(modalsEl);
    var state = { boot: null, loading: null, tab: "", rows: {}, timer: 0, seq: 0, modals: [], quill: null, allPolicies: null, transfer: null, cargoView: "" };

    // --- Servidor ---------------------------------------------------------------------------------------------
    function request(action, fields) {
      var data = fields instanceof FormData ? fields : new FormData();
      if (!(fields instanceof FormData)) {
        Object.keys(fields || {}).forEach(function (key) {
          var value = fields[key];
          if (Array.isArray(value)) value.forEach(function (item) { data.append(key + "[]", item); });
          else data.append(key, value == null ? "" : value);
        });
      }
      data.set("action", action);
      data.set("nonce", runtime.nonce || "");
      return fetch(runtime.ajaxUrl || "", { method: "POST", body: data, credentials: "same-origin" });
    }
    function post(action, fields) {
      return request(action, fields).then(function (response) {
        return response.json().catch(function () { throw new Error("El servidor devolvió una respuesta no válida."); });
      }).then(function (json) {
        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
        return json.data || {};
      });
    }
    function notify(type, text) {
      if (typeof core.scmNotify === "function") core.scmNotify(type, text);
    }
    function setStatus(message, tone) {
      statusEl.textContent = message || "";
      statusEl.hidden = !message;
      statusEl.className = "scm-om-status" + (tone ? " is-" + tone : "");
    }
    function busy(button, label) {
      if (!button) return function () {};
      var original = button.innerHTML;
      button.disabled = true;
      button.innerHTML = spinner() + "<span>" + esc(label) + "</span>";
      return function () { button.disabled = false; button.innerHTML = original; };
    }

    // --- Popups apilados (conservan el contexto de lo que hay debajo) -----------------------------------------
    function openModal(opts) {
      var holder = document.createElement("div");
      holder.className = "scm-om-modal";
      holder.innerHTML = '<div class="scm-om-modal-backdrop" data-om-close></div>'
        + '<section class="scm-om-modal-card' + (opts.size ? " is-" + opts.size : "") + '" role="dialog" aria-modal="true" tabindex="-1">'
        + '<header><div><p>' + esc(opts.kicker || "Gestor organizacional") + '</p><h3 data-om-modal-title></h3></div>'
        + '<button type="button" class="scm-om-icon-btn" data-om-close aria-label="Cerrar">' + icon("close") + "</button></header>"
        + '<div class="scm-om-modal-body" data-om-modal-body></div>'
        + (opts.actions ? '<footer class="scm-om-modal-actions" data-om-modal-actions>' + opts.actions + "</footer>" : "") + "</section>";
      holder.querySelector("[data-om-modal-title]").textContent = opts.title || "";
      var modalBody = holder.querySelector("[data-om-modal-body]");
      if (typeof opts.body === "string") modalBody.innerHTML = opts.body;
      else if (opts.body) modalBody.appendChild(opts.body);
      modalsEl.appendChild(holder);
      var modal = {
        el: holder, body: modalBody, footer: holder.querySelector("[data-om-modal-actions]"), lastFocus: document.activeElement,
        setTitle: function (text) { holder.querySelector("[data-om-modal-title]").textContent = text; },
        error: function (message) {
          var box = modalBody.querySelector(".scm-om-form-error");
          if (!box) {
            box = document.createElement("p");
            box.className = "scm-om-form-error";
            box.setAttribute("role", "alert");
            modalBody.insertBefore(box, modalBody.firstChild);
          }
          box.textContent = message;
          box.scrollIntoView({ block: "nearest" });
        },
        close: function () {
          state.modals = state.modals.filter(function (item) { return item !== modal; });
          holder.remove();
          if (!state.modals.length) document.body.classList.remove("scm-om-modal-open");
          if (modal.onClose) modal.onClose();
          if (modal.lastFocus && modal.lastFocus.focus && document.contains(modal.lastFocus)) modal.lastFocus.focus();
        },
      };
      holder.addEventListener("click", function (event) { if (event.target.closest("[data-om-close]")) modal.close(); });
      state.modals.push(modal);
      document.body.classList.add("scm-om-modal-open");
      var focusable = holder.querySelector(".scm-om-modal-body input, .scm-om-modal-body select, .scm-om-modal-body textarea") || holder.querySelector("section");
      if (focusable) focusable.focus();
      return modal;
    }
    function closeButton(label) {
      return '<button type="button" class="' + UI.secondary + '" data-om-close>' + esc(label || "Cerrar") + "</button>";
    }
    /** Confirmación clara: nombra el registro y avisa qué pasa. onConfirm devuelve una promesa. */
    function confirmModal(opts) {
      var modal = openModal({
        title: opts.title, kicker: opts.kicker, size: "narrow", body: opts.body,
        actions: closeButton("Cancelar") + '<button type="button" class="' + (opts.danger ? "scm-om-btn-danger" : UI.primary) + '" data-om-accept>' + esc(opts.confirm || "Confirmar") + "</button>",
      });
      var accept = modal.footer.querySelector("[data-om-accept]");
      accept.addEventListener("click", function () {
        var done = busy(accept, "Procesando…");
        Promise.resolve(opts.onConfirm()).then(function () { modal.close(); }).catch(function (error) { done(); modal.error(error.message); });
      });
      return modal;
    }
    function viewPdf(title, action, fields, filename) {
      var modal = openModal({ title: title, size: "wide", body: '<p class="' + UI.muted + '">' + spinner() + " Generando el PDF…</p>", actions: closeButton() });
      request(action, fields).then(function (response) {
        if ((response.headers.get("Content-Type") || "").indexOf("application/pdf") === 0) return response.blob();
        return response.json().catch(function () { return null; }).then(function (json) { throw new Error((json && json.data && json.data.message) || "No se pudo generar el PDF."); });
      }).then(function (blob) {
        var url = URL.createObjectURL(blob);
        modal.body.innerHTML = '<iframe class="scm-om-pdf" title="' + esc(title) + '" src="' + url + '"></iframe>';
        modal.footer.insertAdjacentHTML("afterbegin", '<a class="' + UI.navy + ' !sp-no-underline" download="' + esc(filename) + '" href="' + url + '">' + icon("download") + "Descargar</a>");
        modal.onClose = function () { window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000); };
      }).catch(function (error) { modal.body.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
    }

    // --- Selector múltiple con búsqueda (también de una sola opción) ------------------------------------------
    /** @param {HTMLElement} holder @param {{name:string,items:Array<{id:string,label:string}>,selected:Array<string>,single?:boolean,placeholder?:string,onChange?:Function}} cfg */
    function multiSelect(holder, cfg) {
      var selected = (cfg.selected || []).map(String).filter(function (id) { return cfg.items.some(function (item) { return String(item.id) === id; }); });
      var wrap = document.createElement("div");
      wrap.className = "scm-om-ms";
      var hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = cfg.name;
      var box = document.createElement("div");
      box.className = "scm-om-ms-box";
      var input = document.createElement("input");
      input.type = "text";
      input.className = "scm-om-ms-input";
      input.setAttribute("autocomplete", "off");
      input.setAttribute("role", "combobox");
      input.setAttribute("aria-expanded", "false");
      if (cfg.labelId) input.setAttribute("aria-labelledby", cfg.labelId);
      var list = document.createElement("ul");
      list.className = "scm-om-ms-list";
      list.setAttribute("role", "listbox");
      list.hidden = true;
      if (!cfg.single) list.setAttribute("aria-multiselectable", "true");
      wrap.appendChild(hidden);
      wrap.appendChild(box);
      wrap.appendChild(list);
      holder.innerHTML = "";
      holder.appendChild(wrap);
      var active = -1;

      function labelOf(id) {
        var found = cfg.items.find(function (item) { return String(item.id) === String(id); });
        return found ? found.label : "#" + id;
      }
      function sync() {
        hidden.value = selected.join(",");
        box.innerHTML = "";
        selected.forEach(function (id) {
          var chip = document.createElement("span");
          chip.className = "scm-om-ms-chip";
          chip.appendChild(document.createTextNode(labelOf(id)));
          var remove = document.createElement("button");
          remove.type = "button";
          remove.setAttribute("aria-label", "Quitar " + labelOf(id));
          remove.textContent = "×";
          remove.addEventListener("click", function (event) { event.stopPropagation(); toggle(id); });
          chip.appendChild(remove);
          box.appendChild(chip);
        });
        input.placeholder = selected.length ? "" : (cfg.placeholder || "Buscar y seleccionar…");
        box.appendChild(input);
      }
      function matches() {
        var term = fold(input.value);
        return cfg.items.filter(function (item) { return !term || fold(item.label).indexOf(term) !== -1 || String(item.id) === term.replace("#", ""); });
      }
      function renderList() {
        var found = matches();
        list.innerHTML = "";
        if (!found.length) {
          var empty = document.createElement("li");
          empty.className = "scm-om-ms-empty";
          empty.textContent = "Sin resultados";
          list.appendChild(empty);
        }
        found.slice(0, 300).forEach(function (item, index) {
          var li = document.createElement("li");
          li.className = "scm-om-ms-option" + (index === active ? " is-active" : "");
          li.setAttribute("role", "option");
          li.setAttribute("aria-selected", selected.indexOf(String(item.id)) !== -1 ? "true" : "false");
          li.textContent = item.label;
          li.addEventListener("mousedown", function (event) { event.preventDefault(); toggle(String(item.id)); });
          list.appendChild(li);
        });
      }
      function open() { list.hidden = false; wrap.classList.add("is-open"); input.setAttribute("aria-expanded", "true"); renderList(); }
      function close() { list.hidden = true; wrap.classList.remove("is-open"); input.setAttribute("aria-expanded", "false"); active = -1; }
      function toggle(id) {
        var position = selected.indexOf(id);
        if (position !== -1) selected.splice(position, 1);
        else if (cfg.single) selected = [id];
        else selected.push(id);
        input.value = "";
        sync();
        if (cfg.single) close(); else { renderList(); input.focus(); }
        if (cfg.onChange) cfg.onChange(selected.slice());
      }
      box.addEventListener("click", function () { input.focus(); open(); });
      input.addEventListener("focus", open);
      input.addEventListener("blur", function () { window.setTimeout(close, 120); });
      input.addEventListener("input", function () { active = -1; open(); });
      input.addEventListener("keydown", function (event) {
        var found = matches();
        if (event.key === "ArrowDown") { event.preventDefault(); active = Math.min(found.length - 1, active + 1); renderList(); }
        else if (event.key === "ArrowUp") { event.preventDefault(); active = Math.max(0, active - 1); renderList(); }
        else if (event.key === "Enter") { event.preventDefault(); var pick = found[active >= 0 ? active : 0]; if (pick) toggle(String(pick.id)); }
        else if (event.key === "Escape") { if (!list.hidden) { event.stopPropagation(); close(); } }
        else if (event.key === "Backspace" && input.value === "" && selected.length) toggle(selected[selected.length - 1]);
      });
      sync();
      return { get: function () { return selected.slice(); }, set: function (values) { selected = values.map(String); sync(); }, input: input };
    }

    // --- Catálogos --------------------------------------------------------------------------------------------
    function catalog() { return (state.boot && state.boot.catalog) || { areas: [], cargos: [], procesos: [], sucursales: [], funcionarios: [], wp_roles: [] }; }
    function items(list) { return (list || []).map(function (item) { return { id: String(item.id), label: item.nombre || ("#" + item.id) }; }); }
    function nameOf(list, id) {
      var found = (list || []).find(function (item) { return String(item.id) === String(id); });
      return found ? found.nombre : "";
    }
    function chips(csv, list) {
      var html = ids(csv).map(function (id) { var name = nameOf(list, id); return name ? '<span class="scm-om-chip">' + esc(name) + "</span>" : ""; }).join("");
      return html || '<span class="!sp-text-service-muted">—</span>';
    }
    function policyColumn(column) { return state.boot && state.boot.policy.columns.indexOf(column) !== -1; }
    function typeLabel(type, plural) { var t = state.boot && state.boot.types[type]; return t ? t[plural ? 1 : 0] : type; }

    function boot() {
      if (state.boot) return Promise.resolve(state.boot);
      if (state.loading) return state.loading;
      state.loading = post("scm_org_bootstrap", {}).then(function (data) {
        state.boot = data;
        can.view = !!data.can.view; can.manage = !!data.can.manage; can.del = !!data.can.delete; can.transfer = !!data.can.transfer; can.exportPdf = !!data.can.export;
        return data;
      }).catch(function (error) { state.loading = null; throw error; });
      return state.loading;
    }

    // Accesos del menú del usuario (Mis políticas, Mis funciones): abren Mi gestión en esa sección. Llegan por el enlace
    // pulsado (window.scmOrgFocus) o, al entrar por URL, por ?seccion=.
    var FOCUS_SECTIONS = ["cargo", "equipo", "politicas", "funciones", "procesos", "compensaciones"];
    function takeFocus() {
      var focus = String(window.scmOrgFocus || "");
      window.scmOrgFocus = "";
      if (!focus && !state.urlFocusUsed) {
        state.urlFocusUsed = true;
        focus = new URLSearchParams(window.location.search).get("seccion") || "";
      }
      return FOCUS_SECTIONS.indexOf(focus) !== -1 ? focus : "";
    }

    function load() {
      boot().then(function () {
        var focus = takeFocus();
        if (focus) {
          state.focus = focus;
          return selectTab("mi_gestion");
        }
        var first = module.querySelector("[data-om-tab]");
        if (!state.tab) selectTab(first ? first.getAttribute("data-om-tab") : "mi_gestion");
      }).catch(function (error) {
        body.innerHTML = '<p class="' + UI.empty + '">' + esc(error.message) + "</p>";
      });
    }

    function selectTab(tab) {
      state.tab = tab;
      setStatus("");
      module.querySelectorAll("[data-om-tab]").forEach(function (button) {
        var isActive = button.getAttribute("data-om-tab") === tab;
        button.setAttribute("aria-selected", isActive ? "true" : "false");
        UI.tabActive.forEach(function (cls) { button.classList.toggle(cls, isActive); });
        UI.tabIdle.forEach(function (cls) { button.classList.toggle(cls, !isActive); });
      });
      if (tab === "mi_gestion") return renderMyManagement();
      if (tab === "gestion_cargos") return renderCargoManagement();
      if (tab === "traslados") return renderTransfers();
      if (tab === "bitacora") return renderActivity();
      if (CATALOG_TABS.indexOf(tab) !== -1) return renderCatalog(tab);
    }

    // --- Pestañas de registros --------------------------------------------------------------------------------
    function filterField(name, type) {
      var c = catalog();
      if (name === "id_area") return '<label class="!sp-block"><span class="' + UI.label + '">Área</span><select class="' + UI.input + '" name="id_area">' + options(c.areas.map(function (a) { return [a.id, a.nombre]; }), "", "Todas las áreas") + "</select></label>";
      if (name === "id_cargo") return '<label class="!sp-block"><span class="' + UI.label + '">Cargo</span><select class="' + UI.input + '" name="id_cargo">' + options(c.cargos.map(function (a) { return [a.id, a.nombre]; }), "", "Todos los cargos") + "</select></label>";
      if (name === "activo") return '<label class="!sp-block"><span class="' + UI.label + '">Estado</span><select class="' + UI.input + '" name="activo">' + options([["si", "Activos"], ["no", "Inactivos"]], "", "Todos") + "</select></label>";
      if (name === "estado") return '<label class="!sp-block"><span class="' + UI.label + '">Estado</span><select class="' + UI.input + '" name="estado">' + options(state.boot.policy.states.map(function (s) { return [s, s]; }).concat([["sin", "Sin estado"]]), "", "Todos") + "</select></label>";
      if (name === "audiencia") return '<label class="!sp-block"><span class="' + UI.label + '">Audiencia</span><select class="' + UI.input + '" name="audiencia">' + options(state.boot.policy.audiences.map(function (s) { return [s, s]; }).concat([["sin", "Sin audiencia"]]), "", "Todas") + "</select></label>";
      if (name === "sin_area") return '<label class="!sp-flex !sp-items-center !sp-gap-2 !sp-h-10 !sp-text-xs !sp-font-semibold"><input type="checkbox" name="sin_area" value="1" class="!sp-accent-service-navy"> Sin área asignada</label>';
      return "";
    }

    function renderCatalog(type) {
      var search = SEARCH[type];
      var filters = FILTERS[type].filter(function (name) {
        return type !== "politicas" || ["estado", "audiencia"].indexOf(name) === -1 || policyColumn(name);
      });
      var actions = "";
      if (can.manage) actions += '<button type="button" class="' + UI.primary + '" data-om-manage data-om-new>' + icon("add") + "Nuevo registro</button>";
      if (type === "politicas" && can.exportPdf) actions += '<button type="button" class="' + UI.secondary + '" data-om-export data-om-policies-pdf>' + icon("picture_as_pdf") + "Exportar políticas PDF</button>";
      body.innerHTML = '<div class="!sp-flex !sp-flex-col !sp-gap-4">'
        + '<form class="' + UI.card + ' !sp-p-4" data-om-filters autocomplete="off" onsubmit="return false;">'
        + '<div class="!sp-flex !sp-flex-col md:!sp-flex-row md:!sp-items-center !sp-justify-between !sp-gap-3 !sp-mb-3"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">' + esc(typeLabel(type, true)) + "</h3>"
        + '<div class="!sp-flex !sp-flex-wrap !sp-gap-2">' + actions + "</div></div>"
        + '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 xl:!sp-grid-cols-5 !sp-gap-3 !sp-items-end">'
        + '<label class="!sp-block"><span class="' + UI.label + '">Título</span><input type="search" class="' + UI.input + '" name="titulo" placeholder="' + esc(search[0]) + '"></label>'
        + '<label class="!sp-block"><span class="' + UI.label + '">Detalle</span><input type="search" class="' + UI.input + '" name="detalle" placeholder="' + esc(search[1]) + '"></label>'
        + filters.map(function (name) { return filterField(name); }).join("") + "</div></form>"
        + '<div class="' + UI.card + '" data-om-results><p class="' + UI.empty + '">' + spinner() + " Cargando…</p></div></div>";
      loadRows(type);
    }

    function currentFilters() {
      var form = body.querySelector("[data-om-filters]");
      var values = {};
      if (form) new FormData(form).forEach(function (value, key) { values[key] = value; });
      return values;
    }

    function loadRows(type) {
      var seq = ++state.seq;
      var results = body.querySelector("[data-om-results]");
      var fields = currentFilters();
      fields.tipo = type;
      return post("scm_org_list", fields).then(function (data) {
        if (seq !== state.seq || state.tab !== type) return;
        state.rows[type] = data.rows;
        results.innerHTML = renderTable(type, data);
      }).catch(function (error) {
        if (seq !== state.seq) return;
        results.innerHTML = '<p class="' + UI.empty + '">' + esc(error.message) + "</p>";
      });
    }

    function rowActions(type, row) {
      var html = "";
      var id = esc(row._ID);
      if (type === "cargos") html += '<button type="button" class="scm-om-icon-btn" data-om-profile="' + id + '" title="Perfil del cargo" aria-label="Perfil del cargo">' + icon("badge") + "</button>";
      if (type === "politicas") {
        html += '<button type="button" class="scm-om-icon-btn" data-om-policy-view="' + id + '" title="Ver política" aria-label="Ver política">' + icon("visibility") + "</button>";
        html += '<button type="button" class="scm-om-icon-btn" data-om-policy-history="' + id + '" title="Historial de versiones" aria-label="Historial de versiones">' + icon("history") + "</button>";
        html += '<button type="button" class="scm-om-icon-btn" data-om-policy-readers="' + id + '" title="Confirmaciones de lectura" aria-label="Confirmaciones de lectura">' + icon("fact_check") + "</button>";
        if (can.manage && policyColumn("version")) html += '<button type="button" class="scm-om-icon-btn" data-om-manage data-om-policy-version="' + id + '" title="Nueva versión" aria-label="Nueva versión">' + icon("upgrade") + "</button>";
      }
      if (can.manage) html += '<button type="button" class="scm-om-icon-btn" data-om-manage data-om-edit="' + id + '" title="Editar" aria-label="Editar">' + icon("edit") + "</button>";
      if (can.del) {
        if (type === "funcionarios") {
          if (String(row.activo || "").toLowerCase() !== "no") html += '<button type="button" class="scm-om-icon-btn is-danger" data-om-delete data-om-remove="' + id + '" title="Desactivar" aria-label="Desactivar">' + icon("person_off") + "</button>";
        } else {
          html += '<button type="button" class="scm-om-icon-btn is-danger" data-om-delete data-om-remove="' + id + '" title="Eliminar" aria-label="Eliminar">' + icon("delete") + "</button>";
        }
      }
      return html;
    }

    function renderTable(type, data) {
      var rows = data.rows || [];
      if (!rows.length) return '<p class="' + UI.empty + '">No hay registros con estos filtros.</p>';
      var c = catalog();
      var head = { areas_organizacion: ["Área", "Descripción"], cargos: ["Cargo", "Áreas"], politicas: ["Política", "Relaciones"], procesos: ["Proceso", "Áreas"], funciones: ["Función", "Relaciones"], compensaciones: ["Compensación", "Relaciones"], funcionarios: ["Funcionario", "Relaciones"] }[type];
      var html = '<div class="!sp-overflow-x-auto"><table class="scm-om-table"><thead><tr><th>' + esc(head[0]) + "</th><th>" + esc(head[1]) + '</th><th class="!sp-text-right">Acciones</th></tr></thead><tbody>';
      rows.forEach(function (row) {
        var main = "";
        var rel = "";
        if (type === "areas_organizacion") {
          main = '<div class="scm-om-title">' + esc(row.nombre) + "</div>" + (row.responsable ? '<div class="scm-om-sub">Responsable: ' + esc(nameOf(c.funcionarios, row.responsable) || row.responsable) + "</div>" : "");
          rel = '<div class="scm-om-sub">' + esc(row.descripcion || "—") + "</div>";
        } else if (type === "cargos") {
          main = '<div class="scm-om-title">' + esc(row.nombre_cargo) + "</div>" + (row.mision_cargo ? '<div class="scm-om-sub">' + esc(excerpt(row.mision_cargo)) + "</div>" : "");
          rel = chips(row.id_area, c.areas) + (ids(row.cargos_a_cargo).length ? '<span class="scm-om-rel">Tiene a cargo</span>' + chips(row.cargos_a_cargo, c.cargos) : "");
        } else if (type === "politicas") {
          main = '<div class="scm-om-title">' + esc(row.titulo) + "</div>" + policyBadges(row) + '<div class="scm-om-sub">' + esc(row.extracto) + "</div>"
            + (row.fecha_actualizacion_texto ? '<div class="scm-om-sub">Actualizada: ' + esc(row.fecha_actualizacion_texto) + "</div>" : "");
          rel = '<span class="scm-om-rel">Áreas</span>' + chips(row.id_area, c.areas) + '<span class="scm-om-rel">Cargos</span>' + chips(row.id_cargo, c.cargos);
        } else if (type === "procesos") {
          main = '<div class="scm-om-title">' + esc(row.nombre) + "</div>" + (row.definicion ? '<div class="scm-om-sub">' + esc(excerpt(row.definicion)) + "</div>" : "") + (row.entregable ? '<div class="scm-om-sub">Entregable: ' + esc(row.entregable) + "</div>" : "");
          rel = chips(row.id_area, c.areas);
        } else if (type === "funciones") {
          main = '<div class="scm-om-title scm-om-pre">' + esc(row.funcion) + "</div>" + (row.frecuencia ? '<div class="scm-om-sub">Frecuencia: ' + esc(row.frecuencia) + "</div>" : "");
          rel = '<span class="scm-om-rel">Áreas</span>' + chips(row.id_area, c.areas) + '<span class="scm-om-rel">Cargos</span>' + chips(row.id_cargo, c.cargos);
        } else if (type === "compensaciones") {
          main = '<div class="scm-om-title scm-om-pre">' + esc(row.base_compensacion) + "</div>" + (row.condicionado_a ? '<div class="scm-om-sub">Condicionado a: ' + esc(row.condicionado_a) + "</div>" : "");
          rel = '<span class="scm-om-rel">Áreas</span>' + chips(row.id_area, c.areas) + '<span class="scm-om-rel">Cargos</span>' + chips(row.id_cargo, c.cargos);
        } else if (type === "funcionarios") {
          var details = [row.correo, row.celular ? "Cel: " + row.celular : "", row.rol ? "Rol: " + row.rol : "", row.user_role ? "Rol WordPress: " + (row.user_role_label || row.user_role) : "", row.rol_academia ? "Academia: " + row.rol_academia : "", nameOf(c.sucursales, ids(row.id_sucursal)[0]) ? "Sucursal: " + nameOf(c.sucursales, ids(row.id_sucursal)[0]) : ""].filter(Boolean);
          main = '<div class="scm-om-title">' + esc(row.nombre || "—") + "</div>" + details.map(function (line) { return '<div class="scm-om-sub">' + esc(line) + "</div>"; }).join("");
          var inactive = String(row.activo || "").toLowerCase() === "no";
          rel = '<span class="scm-om-rel">Áreas</span>' + chips(funcionarioAreas(row), c.areas) + '<span class="scm-om-rel">Cargos</span>' + chips(row.id_cargo, c.cargos) + '<div class="scm-om-badges">' + badge(inactive ? "Inactivo" : "Activo", inactive ? "inactivo" : "activo") + "</div>";
        }
        html += "<tr><td>" + main + "</td><td>" + rel + '</td><td class="scm-om-actions">' + rowActions(type, row) + "</td></tr>";
      });
      html += "</tbody></table></div>";
      if (data.limited) html += '<p class="' + UI.muted + ' !sp-p-3">Se muestran los ' + rows.length + " más recientes de " + data.total + ". Usa los filtros para acotar.</p>";
      return html;
    }
    function excerpt(text) {
      var value = String(text || "").replace(/\s+/g, " ").trim();
      return value.length > 220 ? value.slice(0, 220) + "…" : value;
    }
    function policyBadges(row) {
      return '<div class="scm-om-badges">' + badge(row.codigo, "code") + (policyColumn("version") ? badge("v" + (row.version || 1), "") : "")
        + badge(row.estado, String(row.estado || "").toLowerCase()) + badge(row.audiencia, String(row.audiencia || "").toLowerCase()) + "</div>";
    }
    function funcionarioAreas(row) {
      if (ids(row.id_area).length) return row.id_area;
      var areas = [];
      ids(row.id_cargo).forEach(function (id) { var cargo = catalog().cargos.find(function (c) { return String(c.id) === id; }); if (cargo) areas = areas.concat(ids(cargo.id_area)); });
      return areas.join(",");
    }
    function findRow(type, id) {
      return (state.rows[type] || []).find(function (row) { return String(row._ID) === String(id); }) || null;
    }

    // --- Formularios ------------------------------------------------------------------------------------------
    function fieldWrap(label, inner, opts) {
      opts = opts || {};
      return '<div class="' + (opts.wide ? "md:!sp-col-span-2" : "") + '"><span class="scm-om-field-label"' + (opts.id ? ' id="' + esc(opts.id) + '"' : "") + ">" + esc(label) + (opts.required ? ' <span class="is-required">*</span>' : "") + "</span>" + inner + (opts.hint ? '<p class="scm-om-hint">' + esc(opts.hint) + "</p>" : "") + "</div>";
    }
    function textInput(name, value, opts) {
      opts = opts || {};
      return '<input class="' + UI.input + '" type="' + (opts.type || "text") + '" name="' + esc(name) + '" value="' + esc(value || "") + '"' + (opts.required ? " required" : "") + (opts.placeholder ? ' placeholder="' + esc(opts.placeholder) + '"' : "") + (opts.maxlength ? ' maxlength="' + opts.maxlength + '"' : "") + ">";
    }
    function textArea(name, value, rows) {
      return '<textarea class="' + UI.textarea + '" name="' + esc(name) + '" rows="' + (rows || 3) + '">' + esc(value || "") + "</textarea>";
    }
    function select(name, list, value, placeholder) {
      return '<select class="' + UI.input + '" name="' + esc(name) + '">' + options(list, value, placeholder) + "</select>";
    }
    var msCounter = 0;
    function msHolder(name) { return '<div data-om-ms="' + esc(name) + '"></div>'; }
    function mountMulti(form, name, list, selected, opts) {
      opts = opts || {};
      var holder = form.querySelector('[data-om-ms="' + name + '"]');
      var labelId = "scm-om-ms-" + (++msCounter);
      var label = holder.parentNode.querySelector(".scm-om-field-label");
      if (label) label.id = labelId;
      return multiSelect(holder, { name: name, items: list, selected: ids(selected), single: !!opts.single, placeholder: opts.placeholder, onChange: opts.onChange, labelId: labelId });
    }

    function openForm(type, row, newVersion) {
      if (type === "politicas") return openPolicyForm(row, !!newVersion);
      var c = catalog();
      var r = row || {};
      var editing = !!row;
      var html = '<form class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4" data-om-form autocomplete="off" novalidate>';
      if (type === "areas_organizacion") {
        html += fieldWrap("Nombre", textInput("nombre", r.nombre, { required: true }), { required: true })
          + fieldWrap("Responsable", select("responsable", c.funcionarios.map(function (f) { return [f.id, f.nombre]; }), r.responsable, "Sin responsable"))
          + fieldWrap("Descripción", textArea("descripcion", r.descripcion, 4), { wide: true });
      } else if (type === "cargos") {
        html += fieldWrap("Nombre del cargo", textInput("nombre_cargo", r.nombre_cargo, { required: true }), { required: true, wide: true })
          + fieldWrap("Áreas", msHolder("id_area")) + fieldWrap("Equipo a cargo", msHolder("cargos_a_cargo"))
          + fieldWrap("Misión", textArea("mision_cargo", r.mision_cargo, 3), { wide: true })
          + fieldWrap("Función principal", textArea("funcion_principal", r.funcion_principal, 3), { wide: true })
          + fieldWrap("KPIs", textArea("kpis", r.kpis, 3), { wide: true });
      } else if (type === "procesos") {
        html += fieldWrap("Nombre del proceso", textInput("nombre", r.nombre, { required: true }), { required: true }) + fieldWrap("Áreas", msHolder("id_area"))
          + fieldWrap("Definición", textArea("definicion", r.definicion, 3), { wide: true }) + fieldWrap("Alcance", textArea("alcance", r.alcance, 3), { wide: true })
          + fieldWrap("Entregable", textArea("entregable", r.entregable, 2), { wide: true });
      } else if (type === "funciones") {
        html += fieldWrap("Función", textArea("funcion", r.funcion, 3), { required: true, wide: true }) + fieldWrap("Cargos", msHolder("id_cargo")) + fieldWrap("Áreas", msHolder("id_area"))
          + fieldWrap("Frecuencia", textInput("frecuencia", r.frecuencia, { placeholder: "Ej: Semanal" }));
      } else if (type === "compensaciones") {
        html += fieldWrap("Base de compensación", textArea("base_compensacion", r.base_compensacion, 2), { required: true, wide: true }) + fieldWrap("Áreas", msHolder("id_area")) + fieldWrap("Cargos", msHolder("id_cargo"))
          + fieldWrap("Condicionado a", textArea("condicionado_a", r.condicionado_a, 3), { wide: true });
      } else if (type === "funcionarios") {
        html = '<p class="scm-om-notice !sp-col-span-2">' + esc("Se guarda la ficha del funcionario (CCT). La sincronización con su usuario de WordPress (rol, sucursal y metas) está pendiente: por ahora hazla en WordPress si cambia el rol o la sucursal.") + "</p>" + html.replace("<form", '<form data-om-funcionario');
        html += fieldWrap("Nombre", textInput("nombre", r.nombre, { required: true }), { required: true }) + fieldWrap("Documento", textInput("documento", r.documento))
          + fieldWrap("Correo", textInput("correo", r.correo, { type: "email" })) + fieldWrap("Correo DIAN", textInput("correo_dian", r.correo_dian, { type: "email" }))
          + fieldWrap("Celular", textInput("celular", r.celular))
          + (editing ? fieldWrap("Usuario de WordPress", '<p class="' + UI.muted + ' !sp-py-2">#' + esc(r.id_empleado || "—") + (r.user_role ? " · Rol: " + esc(r.user_role_label || r.user_role) : "") + "</p>")
            : fieldWrap("ID del usuario de WordPress", textInput("id_empleado", "", { placeholder: "Opcional: si no, se busca por el correo" }), { hint: "El usuario debe existir en WordPress." }))
          + fieldWrap("Rol (meta empleado)", select("rol", ROLES, r.rol, "Elige una opción"))
          + fieldWrap("Gestión", select("gestion", [["Arriendo", "Arriendo"], ["Arriendo/Venta", "Arriendo/Venta"], ["Venta", "Venta"]], r.gestion, "Elige una opción"))
          + fieldWrap("Sucursal", select("id_sucursal", c.sucursales.map(function (s) { return [s.id, s.nombre]; }), ids(r.id_sucursal)[0] || "", "Elige una opción"))
          + fieldWrap("Rol academia", select("rol_academia", [["Estudiante", "Estudiante"], ["Administrador", "Administrador"]], r.rol_academia, "Elige una opción"))
          + fieldWrap("Áreas", msHolder("id_area"), { hint: "Si no eliges áreas se usan las de sus cargos." }) + fieldWrap("Cargos", msHolder("id_cargo"))
          + fieldWrap("Estado", select("activo", [["Si", "Activo"], ["No", "Inactivo"]], r.activo === "No" ? "No" : "Si"));
      }
      html += "</form>";
      var modal = openModal({
        title: (editing ? "Editar " : "Nuevo ") + typeLabel(type).toLowerCase(), kicker: typeLabel(type, true), body: html,
        actions: closeButton("Cancelar") + '<button type="button" class="' + UI.primary + '" data-om-save>' + icon("save") + "Guardar</button>",
      });
      var form = modal.body.querySelector("[data-om-form]");
      if (form.querySelector('[data-om-ms="id_area"]')) mountMulti(form, "id_area", items(c.areas), type === "funcionarios" && editing && !ids(r.id_area).length ? funcionarioAreas(r) : r.id_area);
      if (form.querySelector('[data-om-ms="id_cargo"]')) mountMulti(form, "id_cargo", items(c.cargos), r.id_cargo);
      if (form.querySelector('[data-om-ms="cargos_a_cargo"]')) mountMulti(form, "cargos_a_cargo", items(c.cargos).filter(function (item) { return item.id !== String(r._ID || ""); }), r.cargos_a_cargo);
      modal.footer.querySelector("[data-om-save]").addEventListener("click", function (event) {
        var missing = Array.prototype.find.call(form.querySelectorAll("[required]"), function (input) { return !input.value.trim(); });
        if (missing) { modal.error("Completa los campos obligatorios."); missing.focus(); return; }
        var data = new FormData(form);
        data.set("tipo", type);
        data.set("id", editing ? r._ID : 0);
        saveRecord(type, data, modal, event.currentTarget);
      });
    }

    function saveRecord(type, data, modal, button) {
      var done = busy(button, "Guardando…");
      return post("scm_org_save", data).then(function (result) {
        modal.close();
        notify("success", result.message || "Guardado.");
        setStatus((result.message || "Guardado.") + ((result.warnings || []).length ? " " + result.warnings.join(" ") : ""), (result.warnings || []).length ? "warning" : "success");
        refreshCatalogs(type);
        if (CATALOG_TABS.indexOf(state.tab) !== -1) loadRows(state.tab);
        return result;
      }).catch(function (error) { done(); modal.error(error.message); });
    }

    /** Áreas, cargos y procesos alimentan los selectores: tras guardarlos se recargan. */
    function refreshCatalogs(type) {
      if (["areas_organizacion", "cargos", "procesos", "funcionarios"].indexOf(type) === -1 && type !== "politicas") return;
      if (type === "politicas") { state.allPolicies = null; return; }
      // Se reemplaza cuando llega, para no dejar la pantalla sin catálogo mientras tanto.
      post("scm_org_bootstrap", {}).then(function (data) { state.boot = data; }).catch(function () {});
    }

    // --- Políticas --------------------------------------------------------------------------------------------
    function loadQuill() {
      if (window.Quill) return Promise.resolve();
      if (state.quill) return state.quill;
      state.quill = new Promise(function (resolve, reject) {
        var link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = module.getAttribute("data-quill-css");
        document.head.appendChild(link);
        var script = document.createElement("script");
        script.src = module.getAttribute("data-quill-js");
        script.onload = resolve;
        script.onerror = function () { state.quill = null; reject(new Error("No se pudo cargar el editor. Recarga la página.")); };
        document.head.appendChild(script);
      });
      return state.quill;
    }
    function allPolicies() {
      if (state.allPolicies) return Promise.resolve(state.allPolicies);
      return post("scm_org_list", { tipo: "politicas" }).then(function (data) { state.allPolicies = data.rows || []; return state.allPolicies; });
    }

    function openPolicyForm(row, newVersion) {
      var editing = !!row;
      var modal = openModal({ title: newVersion ? "Nueva versión de la política" : (editing ? "Editar política" : "Nueva política"), kicker: "Políticas", size: "wide", body: '<p class="' + UI.muted + '">' + spinner() + " Cargando…</p>" });
      var recordPromise = editing ? post("scm_org_record", { tipo: "politicas", id: row._ID }).then(function (data) { return data.record; }) : Promise.resolve({});
      Promise.all([recordPromise, allPolicies(), loadQuill()]).then(function (results) {
        var r = results[0];
        var c = catalog();
        var version = Number(r.version || 1);
        var html = "";
        if (newVersion) html += '<p class="scm-om-notice">Se publicará la versión ' + (version + 1) + " de «" + esc(r.titulo) + "». Queda en el historial con el motivo y los funcionarios deberán confirmar de nuevo que la leyeron.</p>";
        html += '<form class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4" data-om-form autocomplete="off" novalidate>'
          + fieldWrap("Título", textInput("titulo", r.titulo, { required: true, maxlength: 250 }), { required: true, wide: true });
        if (newVersion) html += fieldWrap("Motivo del cambio", textArea("motivo_cambio", "", 2), { required: true, wide: true, hint: "Qué cambia en esta versión y por qué." });
        if (policyColumn("codigo")) html += fieldWrap("Código", '<div class="!sp-flex !sp-gap-2">' + textInput("codigo", r.codigo, { placeholder: "Se sugiere según el área" }) + '<button type="button" class="' + UI.secondary + ' !sp-shrink-0" data-om-suggest>Sugerir</button></div>', { hint: "Formato POL-<área>-<número>." });
        if (policyColumn("version")) html += fieldWrap("Versión", '<p class="' + UI.muted + ' !sp-py-2">' + (editing ? (newVersion ? version + " → " + (version + 1) : String(version) + " (para cambiarla usa «Nueva versión»)") : "1") + "</p>");
        if (policyColumn("estado")) html += fieldWrap("Estado", select("estado", state.boot.policy.states.map(function (s) { return [s, s]; }), r.estado || "Vigente"), { hint: "Borrador y Derogada no aparecen en Mi gestión." });
        if (policyColumn("audiencia")) html += fieldWrap("Audiencia", select("audiencia", state.boot.policy.audiences.map(function (s) { return [s, s]; }), r.audiencia || "Empleados"));
        html += fieldWrap("Áreas", msHolder("id_area")) + fieldWrap("Cargos", msHolder("id_cargo"));
        if (policyColumn("id_politica_base")) html += fieldWrap("Política base (versión de empleados)", msHolder("id_politica_base"), { hint: "Para las versiones dirigidas a proveedores." });
        if (policyColumn("id_responsable")) html += fieldWrap("Responsable", msHolder("id_responsable"));
        if (policyColumn("vigencia_desde")) html += fieldWrap("Vigente desde", textInput("vigencia_desde", r.vigencia_desde, { type: "date" }));
        if (policyColumn("fecha_revision")) html += fieldWrap("Fecha de revisión", textInput("fecha_revision", r.fecha_revision, { type: "date" }));
        if (policyColumn("fecha_proxima_revision")) html += fieldWrap("Próxima revisión", textInput("fecha_proxima_revision", r.fecha_proxima_revision, { type: "date" }));
        if (policyColumn("categoria")) html += fieldWrap("Categoría", textInput("categoria", r.categoria, { maxlength: 120 }));
        if (policyColumn("id_proceso")) html += fieldWrap("Proceso", select("id_proceso", c.procesos.map(function (p) { return [p.id, p.nombre]; }), r.id_proceso, "Sin proceso"));
        html += fieldWrap("Texto de la política", '<div class="scm-om-editor"><div data-om-editor></div></div>', { required: true, wide: true }) + "</form>";
        modal.body.innerHTML = html;
        modal.el.querySelector(".scm-om-modal-card").insertAdjacentHTML("beforeend", '<footer class="scm-om-modal-actions">' + closeButton("Cancelar") + '<button type="button" class="' + UI.secondary + '" data-om-preview>' + icon("preview") + "Vista previa</button>"
          + '<button type="button" class="' + UI.primary + '" data-om-save>' + icon(newVersion ? "upgrade" : "save") + (newVersion ? "Publicar versión " + (version + 1) : "Guardar") + "</button></footer>");
        var footer = modal.el.querySelector(".scm-om-modal-actions");
        var form = modal.body.querySelector("[data-om-form]");
        var codeInput = form.querySelector('[name="codigo"]');
        var autoCode = !editing;
        function suggest() {
          if (!codeInput) return;
          post("scm_org_policy_code", { id_area: form.querySelector('[name="id_area"]').value, id: editing ? r._ID : 0 }).then(function (data) { codeInput.value = data.codigo; }).catch(function (error) { modal.error(error.message); });
        }
        mountMulti(form, "id_area", items(c.areas), r.id_area, { onChange: function () { if (autoCode) suggest(); } });
        mountMulti(form, "id_cargo", items(c.cargos), r.id_cargo);
        if (form.querySelector('[data-om-ms="id_politica_base"]')) {
          mountMulti(form, "id_politica_base", results[1].filter(function (p) { return String(p._ID) !== String(r._ID || ""); }).map(function (p) { return { id: String(p._ID), label: (p.codigo ? p.codigo + " · " : "") + p.titulo + (p.audiencia ? " (" + p.audiencia + ")" : "") }; }), r.id_politica_base, { single: true, placeholder: "Buscar política…" });
        }
        if (form.querySelector('[data-om-ms="id_responsable"]')) mountMulti(form, "id_responsable", items(c.funcionarios), r.id_responsable, { single: true, placeholder: "Buscar funcionario…" });
        if (codeInput) {
          codeInput.addEventListener("input", function () { autoCode = false; });
          form.querySelector("[data-om-suggest]").addEventListener("click", function () { autoCode = true; suggest(); });
          if (!editing) suggest();
        }
        var quill = new window.Quill(form.querySelector("[data-om-editor]"), { theme: "snow", modules: { toolbar: [[{ header: [2, 3, false] }], ["bold", "italic", "underline"], [{ list: "ordered" }, { list: "bullet" }], ["blockquote", "link"], ["clean"]] } });
        if (r.politica) quill.clipboard.dangerouslyPasteHTML(r.politica);
        function editorHtml() { return quill.getText().trim() === "" ? "" : (typeof quill.getSemanticHTML === "function" ? quill.getSemanticHTML() : quill.root.innerHTML); }
        footer.querySelector("[data-om-preview]").addEventListener("click", function () {
          var title = form.querySelector('[name="titulo"]').value.trim() || "Sin título";
          var preview = openModal({ title: title, kicker: "Vista previa", size: "wide", body: '<div class="scm-om-rich" data-om-preview-body></div>', actions: closeButton() });
          // El HTML es el que el mismo editor ya está mostrando; el servidor lo vuelve a limpiar al guardar.
          preview.body.querySelector("[data-om-preview-body]").innerHTML = editorHtml() || '<p class="' + UI.muted + '">Sin texto.</p>';
        });
        footer.querySelector("[data-om-save]").addEventListener("click", function (event) {
          if (!form.querySelector('[name="titulo"]').value.trim()) { modal.error("Escribe el título de la política."); return; }
          if (!editorHtml()) { modal.error("Escribe el texto de la política."); return; }
          var reason = form.querySelector('[name="motivo_cambio"]');
          if (reason && reason.value.trim().length < 5) { modal.error("Escribe el motivo del cambio de versión."); reason.focus(); return; }
          var data = new FormData(form);
          data.set("politica", editorHtml());
          data.set("tipo", "politicas");
          data.set("id", editing ? r._ID : 0);
          if (newVersion) data.set("nueva_version", "1");
          saveRecord("politicas", data, modal, event.currentTarget);
        });
      }).catch(function (error) { modal.body.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
    }

    function policyFacts(r) {
      var c = catalog();
      var facts = [];
      var add = function (label, value) { if (value) facts.push("<div><dt>" + esc(label) + "</dt><dd>" + esc(value) + "</dd></div>"); };
      add("Código", r.codigo);
      if (policyColumn("version")) add("Versión", String(r.version || 1));
      add("Estado", r.estado);
      add("Audiencia", r.audiencia);
      add("Vigente desde", r.vigencia_desde);
      add("Revisión", r.fecha_revision);
      add("Próxima revisión", r.fecha_proxima_revision);
      add("Responsable", nameOf(c.funcionarios, r.id_responsable) || r.id_responsable);
      add("Categoría", r.categoria);
      add("Proceso", nameOf(c.procesos, r.id_proceso));
      add("Política base", r.base ? (r.base.codigo ? r.base.codigo + " · " : "") + r.base.titulo : "");
      add("Actualizada", r.fecha_actualizacion_texto);
      return facts.length ? '<dl class="scm-om-facts">' + facts.join("") + "</dl>" : "";
    }

    function openPolicyDetail(id, onRead) {
      var modal = openModal({ title: "Política", kicker: "Políticas", size: "wide", body: '<p class="' + UI.muted + '">' + spinner() + " Cargando…</p>", actions: closeButton() });
      post("scm_org_policy_detail", { id: id }).then(function (data) {
        var r = data.record;
        var c = catalog();
        modal.setTitle(r.titulo || "Política");
        modal.body.innerHTML = policyBadges(r) + '<div class="!sp-mt-3">' + policyFacts(r) + "</div>"
          + '<p class="scm-om-rel">Áreas</p><div>' + chips(r.id_area, c.areas) + '</div><p class="scm-om-rel">Cargos</p><div class="!sp-mb-4">' + chips(r.id_cargo, c.cargos) + "</div>"
          + '<div class="' + UI.card + ' !sp-p-4 scm-om-rich" data-om-policy-text></div>'
          + (r.leida ? '<p class="' + UI.muted + ' !sp-mt-3">' + badge("Leída", "leida") + " Confirmaste la lectura de la versión " + esc(r.version) + " el " + esc(r.leida) + ".</p>" : "");
        // HTML limpio en el servidor (lista blanca).
        modal.body.querySelector("[data-om-policy-text]").innerHTML = r.html || "<p>Sin texto.</p>";
        var buttons = "";
        if (r.asignada && !r.leida && ["Borrador", "Derogada"].indexOf(r.estado) === -1) buttons += '<button type="button" class="' + UI.primary + '" data-om-confirm-read>' + icon("task_alt") + "Leí y entiendo</button>";
        if (can.view) buttons += '<button type="button" class="' + UI.secondary + '" data-om-detail-history>' + icon("history") + "Historial</button>" + '<button type="button" class="' + UI.secondary + '" data-om-detail-readers>' + icon("fact_check") + "Lecturas</button>";
        if (can.manage) buttons += '<button type="button" class="' + UI.secondary + '" data-om-detail-edit>' + icon("edit") + "Editar</button>" + (policyColumn("version") ? '<button type="button" class="' + UI.navy + '" data-om-detail-version>' + icon("upgrade") + "Nueva versión</button>" : "");
        modal.footer.insertAdjacentHTML("afterbegin", buttons);
        modal.footer.addEventListener("click", function (event) {
          if (event.target.closest("[data-om-detail-history]")) openPolicyHistory(r._ID, r.titulo);
          if (event.target.closest("[data-om-detail-readers]")) openPolicyReaders(r._ID);
          if (event.target.closest("[data-om-detail-edit]")) { modal.close(); openPolicyForm(r, false); }
          if (event.target.closest("[data-om-detail-version]")) { modal.close(); openPolicyForm(r, true); }
          var confirmBtn = event.target.closest("[data-om-confirm-read]");
          if (confirmBtn) {
            var done = busy(confirmBtn, "Confirmando…");
            post("scm_org_policy_confirm", { id: r._ID }).then(function (result) {
              confirmBtn.remove();
              modal.body.insertAdjacentHTML("beforeend", '<p class="' + UI.muted + ' !sp-mt-3">' + badge("Leída", "leida") + " Lectura de la versión " + esc(result.version) + " confirmada el " + esc(result.confirmed_at) + ".</p>");
              notify("success", "Lectura confirmada.");
              if (onRead) onRead(result);
            }).catch(function (error) { done(); modal.error(error.message); });
          }
        });
      }).catch(function (error) { modal.body.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
    }

    function openPolicyHistory(id, title) {
      var modal = openModal({ title: "Historial: " + (title || "política"), kicker: "Políticas", size: "wide", body: '<p class="' + UI.muted + '">' + spinner() + " Cargando…</p>", actions: closeButton() });
      post("scm_org_policy_history", { id: id }).then(function (data) {
        var history = data.history || [];
        var html = '<div class="scm-om-history">';
        if (!history.length) html += '<p class="' + UI.muted + '">Sin versiones registradas todavía: el historial empieza con el primer guardado desde el panel (o con la migración).</p>';
        history.forEach(function (item, index) {
          html += "<details" + (index === 0 ? " open" : "") + "><summary><strong>Versión " + esc(item.version) + "</strong> · " + esc(HISTORY_ACTIONS[item.accion] || item.accion) + " · " + esc(item.created_at) + " · " + esc(item.nombre || "—")
            + (item.motivo ? '<div class="scm-om-sub">Motivo: ' + esc(item.motivo) + "</div>" : "") + "</summary><div>"
            + '<p class="scm-om-title">' + esc(item.titulo) + "</p>" + (item.datos && item.datos.codigo ? '<div class="scm-om-badges">' + badge(item.datos.codigo, "code") + badge(item.datos.estado, String(item.datos.estado || "").toLowerCase()) + badge(item.datos.audiencia, String(item.datos.audiencia || "").toLowerCase()) + "</div>" : "")
            + '<div class="scm-om-rich !sp-mt-2" data-om-history-text="' + index + '"></div></div></details>';
        });
        html += "</div>";
        var activity = data.activity || [];
        if (activity.length) {
          html += '<h4 class="!sp-mt-4 !sp-mb-2 !sp-text-sm">Bitácora</h4>' + activity.map(function (item) { return '<div class="scm-om-log"><strong>' + esc(item.resumen) + "</strong><small>" + esc(item.fecha) + " · " + esc(item.nombre || "—") + "</small></div>"; }).join("");
        }
        modal.body.innerHTML = html;
        history.forEach(function (item, index) { modal.body.querySelector('[data-om-history-text="' + index + '"]').innerHTML = item.html || "<p>Sin texto.</p>"; });
      }).catch(function (error) { modal.body.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
    }

    function openPolicyReaders(id) {
      var modal = openModal({ title: "Confirmaciones de lectura", kicker: "Políticas", size: "wide", body: '<p class="' + UI.muted + '">' + spinner() + " Cargando…</p>", actions: closeButton() });
      post("scm_org_policy_readers", { id: id }).then(function (data) {
        modal.setTitle("Lecturas: " + data.titulo);
        var confirmed = data.confirmed || [];
        var pending = data.pending || [];
        var assigned = confirmed.filter(function (item) { return item.asignado; }).length;
        modal.body.innerHTML = '<p class="' + UI.muted + ' !sp-mb-3">Versión vigente: <strong>' + esc(data.version) + "</strong> · " + assigned + " de " + data.expected + " funcionarios con la política asignada la confirmaron.</p>"
          + '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-4">'
          + '<div class="' + UI.card + ' !sp-p-4"><h4 class="!sp-m-0 !sp-mb-2 !sp-text-sm">' + badge("Leída", "leida") + " Confirmaron (" + confirmed.length + ")</h4>"
          + (confirmed.length ? confirmed.map(function (item) { return '<div class="scm-om-log"><strong>' + esc(item.nombre || item.id_empleado) + "</strong><small>" + esc(item.confirmed_at) + (item.asignado ? "" : " · ya no tiene la política asignada") + "</small></div>"; }).join("") : '<p class="' + UI.muted + '">Nadie ha confirmado esta versión.</p>') + "</div>"
          + '<div class="' + UI.card + ' !sp-p-4"><h4 class="!sp-m-0 !sp-mb-2 !sp-text-sm">' + badge("Pendiente", "pendiente") + " Faltan (" + pending.length + ")</h4>"
          + (pending.length ? pending.map(function (item) { return '<div class="scm-om-log"><strong>' + esc(item.nombre) + "</strong><small>" + esc(item.cargo) + "</small></div>"; }).join("") : '<p class="' + UI.muted + '">No falta nadie.</p>') + "</div></div>";
      }).catch(function (error) { modal.body.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
    }

    function openProfile(cargo) {
      var parts = cargo.perfil || CARGO_PROFILE.map(function (item) { return { label: item[1], text: cargo[item[0]] || "" }; }).filter(function (item) { return String(item.text).trim() !== ""; });
      openModal({
        title: "Perfil: " + (cargo.nombre_cargo || ""), kicker: "Cargos", size: "wide", actions: closeButton(),
        body: parts.length ? '<div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-3">' + parts.map(function (part) {
          return '<div class="' + UI.card + ' !sp-p-4"><p class="scm-om-rel !sp-mb-2">' + esc(part.label) + '</p><div class="scm-om-pre !sp-text-xs">' + esc(part.text) + "</div></div>";
        }).join("") + "</div>" : '<p class="' + UI.muted + '">El cargo no tiene perfil registrado.</p>',
      });
    }

    // --- Eliminar (confirmación con el nombre del registro y lo que queda apuntándole) ------------------------
    function confirmDelete(type, row) {
      post("scm_org_delete_impact", { tipo: type, id: row._ID }).then(function (impact) {
        var isPerson = type === "funcionarios";
        var bodyHtml = isPerson
          ? "<p class=\"!sp-text-sm\">¿Desactivar a <strong>" + esc(impact.name) + "</strong>?</p><p class=\"" + UI.muted + "\">Queda con activo = No: ya no entra al panel ni aparece en los selectores de funcionarios activos. No se borra su información y puedes reactivarlo editándolo. Queda registrado en la bitácora con tu nombre.</p>"
          : "<p class=\"!sp-text-sm\">¿Eliminar " + esc(impact.type_label.toLowerCase()) + " <strong>«" + esc(impact.name) + "»</strong>?</p>"
            + (impact.items.length ? '<p class="scm-om-notice">Todavía le apuntan: ' + esc(impact.items.join(", ")) + ". Esas referencias quedarán con un id que ya no existe.</p>" : "")
            + '<p class="' + UI.muted + '">Esta acción no se puede deshacer. Queda registrada en la bitácora con tu nombre y una copia del registro.</p>';
        confirmModal({
          title: isPerson ? "Desactivar funcionario" : "Eliminar " + impact.type_label.toLowerCase(), kicker: typeLabel(type, true), body: bodyHtml, danger: true,
          confirm: isPerson ? "Sí, desactivar" : "Sí, eliminar",
          onConfirm: function () {
            return post("scm_org_delete", { tipo: type, id: row._ID }).then(function (result) {
              notify("success", result.message);
              setStatus(result.message, "success");
              refreshCatalogs(type);
              loadRows(type);
            });
          },
        });
      }).catch(function (error) { setStatus(error.message, "error"); });
    }

    // --- Mi gestión y gestión por cargo -----------------------------------------------------------------------
    function areaSelect(attr) {
      return '<select class="' + UI.input + ' !sp-max-w-[260px]" ' + attr + ">" + options(catalog().areas.map(function (a) { return [a.id, a.nombre]; }), "", "Todas las áreas") + "</select>";
    }
    function groupedByArea(list, renderItem, emptyText) {
      if (!list.length) return '<p class="' + UI.muted + '">' + esc(emptyText) + "</p>";
      var groups = {};
      var order = [];
      list.forEach(function (item) {
        var areaIds = ids(item.id_area);
        (areaIds.length ? areaIds : ["_sin"]).forEach(function (key) {
          if (!groups[key]) { groups[key] = []; order.push(key); }
          groups[key].push(item);
        });
      });
      return order.map(function (key) {
        return '<div data-om-group><p class="scm-om-group-title">' + esc(key === "_sin" ? "Sin área asignada" : (nameOf(catalog().areas, key) || "Área #" + key)) + "</p>"
          + groups[key].map(function (item) { return '<div class="scm-om-item" data-om-item data-areas="' + esc(ids(item.id_area).join(",")) + '">' + renderItem(item) + "</div>"; }).join("") + "</div>";
      }).join("");
    }
    function section(key, title, iconName, count, content, open, filters) {
      return '<details class="scm-om-acc" data-om-section="' + esc(key) + '"' + (open ? " open" : "") + "><summary>" + icon(iconName) + "<span>" + esc(title) + '</span><span class="scm-om-count">' + count + "</span></summary>"
        + '<div class="scm-om-acc-body">' + (filters ? '<div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mb-3"><input type="search" class="' + UI.input + ' !sp-max-w-[320px]" placeholder="Buscar…" data-om-section-search>' + areaSelect("data-om-section-area") + "</div>" : "")
        + content + "</div></details>";
    }
    function policyItem(item, mine) {
      var read = item.leida ? badge("Leída v" + item.version, "leida") : (mine ? badge("Por confirmar lectura", "pendiente") : "");
      return '<h4>' + esc(item.titulo) + "</h4>" + '<div class="scm-om-badges">' + badge(item.codigo, "code") + (policyColumn("version") ? badge("v" + item.version, "") : "") + badge(item.audiencia, String(item.audiencia || "").toLowerCase()) + read + "</div>"
        + '<div class="scm-om-sub">' + esc(item.extracto) + "</div>"
        + '<div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mt-2"><button type="button" class="' + UI.secondary + '" data-om-read-policy="' + esc(item._ID) + '">' + icon("menu_book") + "Leer política</button>"
        + (mine && !item.leida ? '<button type="button" class="' + UI.primary + '" data-om-quick-confirm="' + esc(item._ID) + '">' + icon("task_alt") + "Leí y entiendo</button>" : "") + "</div>";
    }
    function managementSections(data, mine) {
      var html = "";
      var cargos = data.cargos || [];
      html += section("cargo", mine ? "Mi información del cargo" : "Información del cargo", "badge", cargos.length, cargos.length ? cargos.map(function (cargo) {
        return '<div class="scm-om-item"><h4>' + esc(cargo.nombre_cargo) + '</h4><div class="scm-om-sub"><strong>Misión:</strong> ' + esc(cargo.mision_cargo || "Sin misión registrada.") + "</div>"
          + '<button type="button" class="' + UI.secondary + ' !sp-mt-2" data-om-cargo-profile="' + esc(cargo._ID) + '">' + icon("badge") + (mine ? "Ver mi perfil" : "Ver perfil") + "</button></div>";
      }).join("") : '<p class="' + UI.muted + '">Sin cargo asignado.</p>', true, false);
      if (mine && (data.equipo || []).length) {
        html += section("equipo", "Mi equipo (cargos a cargo)", "groups", data.equipo.length, data.equipo.map(function (cargo) {
          return '<details class="scm-om-acc" data-om-subordinate="' + esc(cargo._ID) + '"><summary>' + icon("person") + "<span>" + esc(cargo.nombre_cargo) + '</span></summary><div class="scm-om-acc-body" data-om-subordinate-body><p class="' + UI.muted + '">' + spinner() + " Cargando gestión integral…</p></div></details>";
        }).join(""), false, false);
      }
      var policies = data.politicas || [];
      var pendingReads = mine ? policies.filter(function (p) { return !p.leida; }).length : 0;
      html += section("politicas", (mine ? "Mis políticas" : "Políticas") + (pendingReads ? " · " + pendingReads + " por confirmar" : ""), "policy", policies.length, groupedByArea(policies, function (item) { return policyItem(item, mine); }, "Sin políticas asignadas."), mine && pendingReads > 0, true);
      html += section("funciones", mine ? "Mis funciones" : "Funciones", "checklist", (data.funciones || []).length, groupedByArea(data.funciones || [], function (item) {
        return '<div class="scm-om-title scm-om-pre">' + esc(item.funcion) + "</div>" + (item.frecuencia ? '<div class="scm-om-sub">Frecuencia: ' + esc(item.frecuencia) + "</div>" : "");
      }, "Sin funciones asignadas."), false, true);
      html += section("procesos", "Procesos del área", "account_tree", (data.procesos || []).length, groupedByArea(data.procesos || [], function (item) {
        return "<h4>" + esc(item.nombre) + "</h4>" + (item.alcance ? '<div class="scm-om-sub">Alcance: ' + esc(item.alcance) + "</div>" : "") + (item.definicion ? '<div class="scm-om-sub">Definición: ' + esc(item.definicion) + "</div>" : "");
      }, "Sin procesos asignados."), false, true);
      html += section("compensaciones", mine ? "Mis compensaciones" : "Compensaciones", "payments", (data.compensaciones || []).length, groupedByArea(data.compensaciones || [], function (item) {
        return '<div class="scm-om-title scm-om-pre">' + esc(item.base_compensacion) + "</div>" + (item.condicionado_a ? '<div class="scm-om-sub">Condicionado a: ' + esc(item.condicionado_a) + "</div>" : "");
      }, "Sin compensaciones registradas."), false, true);
      return html;
    }
    function bindManagement(container, data, mine) {
      // Cada bloque (mi gestión o un cargo de mi equipo) atiende solo sus botones.
      container.setAttribute("data-om-scope", "");
      container.addEventListener("input", filterSections);
      container.addEventListener("change", filterSections);
      container.addEventListener("click", function (event) {
        if (event.target.closest("[data-om-scope]") !== container) return;
        var profile = event.target.closest("[data-om-cargo-profile]");
        if (profile) {
          var cargo = (data.cargos || []).concat(data.equipo || []).find(function (c) { return String(c._ID) === profile.getAttribute("data-om-cargo-profile"); });
          if (cargo) openProfile(cargo);
        }
        var read = event.target.closest("[data-om-read-policy]");
        if (read) openPolicyDetail(read.getAttribute("data-om-read-policy"), mine ? function () { markRead(container, read.getAttribute("data-om-read-policy")); } : null);
        var quick = event.target.closest("[data-om-quick-confirm]");
        if (quick) {
          var title = (data.politicas || []).find(function (p) { return String(p._ID) === quick.getAttribute("data-om-quick-confirm"); });
          confirmModal({
            title: "Confirmar lectura", kicker: "Mis políticas", confirm: "Leí y entiendo",
            body: "<p class=\"!sp-text-sm\">Confirmas que leíste y entiendes la política <strong>«" + esc(title ? title.titulo : "") + "»</strong> (versión " + esc(title ? title.version : "1") + ").</p><p class=\"" + UI.muted + "\">Si la política cambia de versión se te pedirá confirmarla de nuevo.</p>",
            onConfirm: function () {
              return post("scm_org_policy_confirm", { id: quick.getAttribute("data-om-quick-confirm") }).then(function () { notify("success", "Lectura confirmada."); markRead(container, quick.getAttribute("data-om-quick-confirm")); });
            },
          });
        }
      });
      container.querySelectorAll("[data-om-subordinate]").forEach(function (details) {
        details.addEventListener("toggle", function () {
          if (!details.open || details.dataset.loaded === "1") return;
          details.dataset.loaded = "1";
          var holder = details.querySelector("[data-om-subordinate-body]");
          post("scm_org_cargo_management", { id_cargo: details.getAttribute("data-om-subordinate") }).then(function (sub) {
            holder.innerHTML = managementSections(sub, false);
            bindManagement(holder, sub, false);
          }).catch(function (error) { details.dataset.loaded = "0"; holder.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
        });
      });
    }
    function markRead(container, id) {
      container.querySelectorAll('[data-om-quick-confirm="' + id + '"]').forEach(function (button) {
        var item = button.closest("[data-om-item]");
        button.remove();
        var pendingBadge = item && item.querySelector(".scm-om-badge.is-pendiente");
        if (pendingBadge) { pendingBadge.className = "scm-om-badge is-leida"; pendingBadge.textContent = "Leída"; }
      });
    }
    function filterSections(event) {
      var details = event.target.closest("[data-om-section]");
      if (!details || !(event.target.matches("[data-om-section-search]") || event.target.matches("[data-om-section-area]"))) return;
      var term = fold(details.querySelector("[data-om-section-search]").value);
      var area = details.querySelector("[data-om-section-area]").value;
      details.querySelectorAll(":scope > .scm-om-acc-body > [data-om-group]").forEach(function (group) {
        var visible = 0;
        group.querySelectorAll(":scope > [data-om-item]").forEach(function (item) {
          var ok = (!term || fold(item.textContent).indexOf(term) !== -1) && (!area || (item.getAttribute("data-areas") || "").split(",").indexOf(area) !== -1);
          item.hidden = !ok;
          if (ok) visible++;
        });
        group.hidden = visible === 0;
      });
    }

    function renderMyManagement() {
      body.innerHTML = '<p class="' + UI.empty + '">' + spinner() + " Cargando tu gestión…</p>";
      post("scm_org_my_management", {}).then(function (data) {
        if (state.tab !== "mi_gestion") return;
        body.innerHTML = '<div class="' + UI.card + ' !sp-p-4 !sp-mb-4 !sp-flex !sp-flex-col md:!sp-flex-row md:!sp-items-center !sp-justify-between !sp-gap-3">'
          + '<div><p class="scm-om-rel">Mi gestión</p><p class="!sp-m-0 !sp-text-base !sp-font-bold">' + esc(data.funcionario.nombre) + '</p><p class="' + UI.muted + '">Tu cargo, tus políticas, funciones, procesos y compensaciones.</p></div>'
          + '<button type="button" class="' + UI.navy + '" data-om-management-pdf>' + icon("picture_as_pdf") + "Descargar gestión completa</button></div>"
          + '<div data-om-management>' + managementSections(data, true) + "</div>";
        bindManagement(body.querySelector("[data-om-management]"), data, true);
        if (state.focus) {
          var target = body.querySelector('[data-om-section="' + state.focus + '"]');
          state.focus = "";
          if (target) {
            target.open = true;
            target.scrollIntoView({ behavior: "smooth", block: "start" });
          }
        }
        body.querySelector("[data-om-management-pdf]").addEventListener("click", function () { viewPdf("Reporte integral de gestión", "scm_org_management_pdf", { id_cargo: 0 }, "gestion.pdf"); });
      }).catch(function (error) {
        body.innerHTML = '<p class="' + UI.empty + '">' + esc(error.message) + "</p>";
      });
    }

    function renderCargoManagement() {
      var allowed = (state.boot.inspectable_cargos || []).map(String);
      var cargos = catalog().cargos.filter(function (c) { return allowed.indexOf(String(c.id)) !== -1; });
      body.innerHTML = '<div class="' + UI.card + ' !sp-p-4 !sp-mb-4"><div class="!sp-flex !sp-flex-col md:!sp-flex-row md:!sp-items-end !sp-gap-3">'
        + '<label class="!sp-block !sp-flex-1"><span class="' + UI.label + '">Cargo</span><select class="' + UI.input + '" data-om-cargo-select>' + options(cargos.map(function (c) { return [c.id, c.nombre]; }), state.cargoView, "Selecciona un cargo") + "</select></label>"
        + '<button type="button" class="' + UI.navy + '" data-om-cargo-pdf disabled>' + icon("picture_as_pdf") + "Descargar gestión del cargo</button></div>"
        + '<p class="' + UI.muted + ' !sp-mt-2">' + (can.view ? "Puedes consultar cualquier cargo." : "Puedes consultar tu cargo y los que tienes a cargo.") + "</p></div>"
        + '<div data-om-cargo-body><p class="' + UI.empty + '">Selecciona un cargo para ver su gestión.</p></div>';
      var selectEl = body.querySelector("[data-om-cargo-select]");
      var pdfButton = body.querySelector("[data-om-cargo-pdf]");
      function show() {
        state.cargoView = selectEl.value;
        pdfButton.disabled = !selectEl.value;
        var holder = body.querySelector("[data-om-cargo-body]");
        if (!selectEl.value) { holder.innerHTML = '<p class="' + UI.empty + '">Selecciona un cargo para ver su gestión.</p>'; return; }
        holder.innerHTML = '<p class="' + UI.empty + '">' + spinner() + " Cargando…</p>";
        post("scm_org_cargo_management", { id_cargo: selectEl.value }).then(function (data) {
          var fresh = document.createElement("div");
          fresh.setAttribute("data-om-cargo-body", "");
          fresh.innerHTML = managementSections(data, false);
          holder.replaceWith(fresh);
          bindManagement(fresh, data, false);
        }).catch(function (error) { holder.innerHTML = '<p class="' + UI.empty + '">' + esc(error.message) + "</p>"; });
      }
      selectEl.addEventListener("change", show);
      pdfButton.addEventListener("click", function () { viewPdf("Reporte integral de gestión", "scm_org_management_pdf", { id_cargo: selectEl.value }, "gestion-cargo.pdf"); });
      if (state.cargoView) show();
    }

    // --- Traslados --------------------------------------------------------------------------------------------
    function renderTransfers() {
      body.innerHTML = '<p class="' + UI.empty + '">' + spinner() + " Cargando traslados…</p>";
      post("scm_org_transfer_data", {}).then(function (data) {
        state.transfer = { data: data, module: (data.modules[0] || {}).key || "inmuebles", preview: null, origin: "", destination: "", mode: "all", tipo: "", reason: "" };
        drawTransfers();
      }).catch(function (error) { body.innerHTML = '<p class="' + UI.empty + '">' + esc(error.message) + "</p>"; });
    }
    function pillClass(active) {
      return "!sp-inline-flex !sp-items-center !sp-gap-2 !sp-px-4 !sp-py-2 !sp-rounded-full !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer !sp-whitespace-nowrap !sp-border !sp-border-solid " + (active ? UI.tabActive : UI.tabIdle).join(" ");
    }
    function transferPeople(excludeInactive) {
      return state.transfer.data.funcionarios.filter(function (f) { return !excludeInactive || f.activo !== "No"; }).map(function (f) {
        return { id: f.id_empleado, label: "#" + f.id_empleado + " · " + f.nombre + (f.activo === "No" ? " (inactivo)" : "") };
      });
    }
    function drawTransfers(message, tone) {
      var t = state.transfer;
      var module = t.data.modules.find(function (m) { return m.key === t.module; }) || t.data.modules[0];
      var html = '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-3 !sp-gap-4">'
        + '<div class="' + UI.card + ' !sp-p-4 lg:!sp-col-span-2" data-om-transfer>'
        + '<h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">Traslado de registros por funcionario</h3><p class="' + UI.muted + ' !sp-mb-4">Mueve inmuebles, precaptaciones, retoques y tickets de un funcionario a otro. No cambia el estado de los tickets.</p>'
        + (message ? '<div class="scm-om-status is-' + esc(tone || "info") + ' !sp-mb-3">' + esc(message) + "</div>" : "")
        + '<p class="scm-om-rel">1. Funcionarios</p><div class="!sp-grid !sp-grid-cols-1 md:!sp-grid-cols-2 !sp-gap-3 !sp-mb-4">'
        + fieldWrap("Origen", msHolder("origin")) + fieldWrap("Destino", msHolder("destination")) + "</div>"
        + '<p class="scm-om-rel">2. Tipo de traslado</p><div class="scm-om-transfer-tabs !sp-mb-3">'
        + t.data.modules.map(function (m) { return '<button type="button" class="' + pillClass(m.key === t.module) + '" aria-pressed="' + (m.key === t.module ? "true" : "false") + '" data-om-transfer-module="' + esc(m.key) + '">' + esc(m.label) + "</button>"; }).join("") + "</div>"
        + '<p class="' + UI.muted + ' !sp-mb-3"><strong>' + esc(module.label) + "</strong> · " + esc(module.description) + "</p>"
        + (module.key === "inmuebles" ? '<div class="!sp-max-w-[260px] !sp-mb-3">' + fieldWrap("Tipo de negocio", select("tipo_negocio", t.data.business_types.map(function (b) { return [b, b]; }), t.tipo, "Todos")) + "</div>" : "")
        + '<div class="!sp-flex !sp-flex-wrap !sp-gap-4 !sp-mb-3 !sp-text-xs">'
        + '<label class="!sp-flex !sp-items-center !sp-gap-2"><input type="radio" name="om_transfer_mode" value="all"' + (t.mode !== "selected" ? " checked" : "") + "> Todos los registros filtrados</label>"
        + '<label class="!sp-flex !sp-items-center !sp-gap-2"><input type="radio" name="om_transfer_mode" value="selected"' + (t.mode === "selected" ? " checked" : "") + "> Solo los registros seleccionados</label></div>"
        + fieldWrap("Motivo / nota", '<textarea class="' + UI.textarea + '" rows="2" data-om-transfer-reason placeholder="Opcional">' + esc(t.reason) + "</textarea>")
        + '<div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mt-3"><button type="button" class="' + UI.secondary + '" data-om-transfer-preview>' + icon("search") + "Previsualizar</button>"
        + '<button type="button" class="' + UI.primary + '" data-om-transfer data-om-transfer-execute>' + icon("swap_horiz") + "Ejecutar traslado</button></div>"
        + (t.preview ? transferPreviewHtml(t.preview) : "") + "</div>"
        + '<div class="' + UI.card + ' !sp-p-4"><h3 class="!sp-m-0 !sp-mb-2 !sp-text-sm !sp-font-bold">Últimos traslados</h3>' + transferLogsHtml(t.data.logs || []) + "</div></div>";
      body.innerHTML = html;
      var holder = body.querySelector("[data-om-transfer]");
      mountMulti(holder, "origin", transferPeople(false), t.origin, { single: true, placeholder: "Buscar funcionario…", onChange: function (v) { transferPayload(); t.origin = v[0] || ""; t.preview = null; drawTransfers(); } });
      mountMulti(holder, "destination", transferPeople(true), t.destination, { single: true, placeholder: "Buscar funcionario…", onChange: function (v) { t.destination = v[0] || ""; } });
    }
    function transferPreviewHtml(preview) {
      var html = '<div class="!sp-mt-4"><div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-2"><h4 class="!sp-m-0 !sp-text-sm">Previsualización</h4>' + badge(preview.total + " registros encontrados", "code") + "</div>";
      (preview.modules || []).forEach(function (m) {
        var records = m.records || [];
        var columns = records[0] ? Object.keys(records[0]) : [];
        html += '<div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mb-2"><span class="!sp-text-xs"><strong>' + esc(m.label) + "</strong> (" + m.count + " encontrados" + (m.count > records.length ? ", mostrando " + records.length : "") + ")</span>"
          + (records.length ? '<button type="button" class="' + UI.secondary + '" data-om-transfer-all>Seleccionar todos</button>' : "") + "</div>"
          + '<div class="scm-om-scroll"><table class="scm-om-table"><thead><tr><th></th>' + columns.map(function (col) { return "<th>" + esc(col) + "</th>"; }).join("") + "</tr></thead><tbody>"
          + (records.length ? records.map(function (row) {
            return '<tr><td><input type="checkbox" data-om-transfer-record value="' + esc(row._ID || "") + '" aria-label="Seleccionar registro ' + esc(row._ID || "") + '"></td>' + columns.map(function (col) { return "<td>" + esc(row[col]) + "</td>"; }).join("") + "</tr>";
          }).join("") : '<tr><td colspan="8" class="' + UI.muted + '">Sin registros con los filtros actuales.</td></tr>') + "</tbody></table></div>";
      });
      return html + "</div>";
    }
    function transferLogsHtml(logs) {
      if (!logs.length) return '<p class="' + UI.muted + '">Sin traslados registrados todavía.</p>';
      return logs.map(function (log) {
        return '<div class="scm-om-log"><strong>' + esc(log.origin_name) + " → " + esc(log.destination_name) + "</strong><small>" + esc(log.created_at) + " · " + log.total_records + " registros" + (log.postmeta_updated ? " · postmeta: " + log.postmeta_updated : "") + "</small>"
          + (log.reason ? "<small>" + esc(log.reason) + "</small>" : "")
          + (log.details || []).map(function (d) {
            return "<small><b>" + esc(d.label) + ":</b> " + (d.values.length ? esc(d.values.join(", ")) : (d.legacy ? d.updated + " registros. Detalle no guardado en traslados anteriores." : d.updated + " registros")) + (d.more > 0 ? " +" + d.more + " más" : "") + "</small>";
          }).join("") + "</div>";
      }).join("");
    }
    function transferPayload() {
      var t = state.transfer;
      var mode = body.querySelector('[name="om_transfer_mode"]:checked');
      var tipo = body.querySelector('[name="tipo_negocio"]');
      var reason = body.querySelector("[data-om-transfer-reason]");
      t.mode = mode ? mode.value : "all";
      t.tipo = tipo ? tipo.value : "";
      t.reason = reason ? reason.value : "";
      var selectedIds = Array.prototype.map.call(body.querySelectorAll("[data-om-transfer-record]:checked"), function (input) { return input.value; });
      var opts = { modules: {} };
      opts.modules[t.module] = { mode: t.mode, selected_ids: selectedIds, filters: { tipo_negocio: t.tipo } };
      return { origin: t.origin, destination: t.destination, modules: [t.module], options: JSON.stringify(opts), reason: t.reason, selected: selectedIds };
    }
    function previewTransfer() {
      var payload = transferPayload();
      if (!payload.origin) { setStatus("Selecciona el funcionario origen.", "error"); return Promise.reject(new Error("Selecciona el funcionario origen.")); }
      return post("scm_org_transfer_preview", { origin: payload.origin, modules: payload.modules, options: payload.options }).then(function (data) {
        state.transfer.preview = data;
        drawTransfers(data.message, "info");
        return data;
      });
    }
    function executeTransfer() {
      var t = state.transfer;
      var payload = transferPayload();
      var problem = !payload.origin || !payload.destination ? "Selecciona el funcionario origen y el destino." : (payload.origin === payload.destination ? "El origen y el destino no pueden ser el mismo funcionario." : (t.mode === "selected" && !payload.selected.length ? "Selecciona al menos un registro o cambia a todos los registros filtrados." : ""));
      if (problem) { setStatus(problem, "error"); return; }
      var name = function (id) { var f = t.data.funcionarios.find(function (p) { return p.id_empleado === id; }); return f ? f.nombre : id; };
      var count = t.mode === "selected" ? payload.selected.length : (t.preview && t.preview.modules[0] && t.preview.modules[0].key === t.module ? t.preview.total : null);
      var module = t.data.modules.find(function (m) { return m.key === t.module; });
      confirmModal({
        title: "Confirmar traslado", kicker: "Traslados", confirm: "Sí, trasladar",
        body: "<p class=\"!sp-text-sm\">Vas a trasladar " + (count === null ? "todos los registros filtrados" : "<strong>" + count + "</strong> " + (count === 1 ? "registro" : "registros")) + " de <strong>" + esc(module ? module.label : t.module) + "</strong> de <strong>" + esc(name(payload.origin)) + "</strong> a <strong>" + esc(name(payload.destination)) + "</strong>.</p>"
          + '<p class="' + UI.muted + '">Se hace en una sola operación: si algo falla no se mueve nada. Queda en el historial de traslados' + (payload.reason ? " con el motivo indicado" : "") + ".</p>",
        onConfirm: function () {
          return post("scm_org_transfer_execute", { origin: payload.origin, destination: payload.destination, modules: payload.modules, options: payload.options, reason: payload.reason }).then(function (result) {
            t.preview = null;
            t.data.logs = result.logs || t.data.logs;
            notify("success", result.message);
            drawTransfers(result.message + " Registros: " + result.total + (result.postmeta_skipped ? " · inmuebles sin post en WordPress: " + result.postmeta_skipped : ""), "success");
          });
        },
      });
    }

    // --- Bitácora ---------------------------------------------------------------------------------------------
    function renderActivity() {
      body.innerHTML = '<div class="' + UI.card + ' !sp-p-4"><div class="!sp-flex !sp-flex-col md:!sp-flex-row md:!sp-items-end !sp-justify-between !sp-gap-3 !sp-mb-3">'
        + '<div><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">Bitácora</h3><p class="' + UI.muted + '">Quién creó, editó, eliminó o desactivó cada registro.</p></div>'
        + '<label class="!sp-block !sp-max-w-[260px]"><span class="' + UI.label + '">Tipo</span><select class="' + UI.input + '" data-om-activity-type>' + options(Object.keys(ACTIVITY_TYPES).map(function (k) { return [k, ACTIVITY_TYPES[k]]; }), "", "Todos") + "</select></label></div>"
        + "<div data-om-activity></div></div>";
      var selectEl = body.querySelector("[data-om-activity-type]");
      function loadActivity() {
        var holder = body.querySelector("[data-om-activity]");
        holder.innerHTML = '<p class="' + UI.muted + '">' + spinner() + " Cargando…</p>";
        post("scm_org_activity", { tipo: selectEl.value }).then(function (data) {
          holder.innerHTML = (data.items || []).length ? data.items.map(function (item) {
            return '<div class="scm-om-log"><strong>' + esc(item.resumen) + "</strong><small>" + esc(item.fecha) + " · " + esc(item.nombre || "—") + (item.registro_id ? " · " + esc(ACTIVITY_TYPES[item.tipo] || item.tipo) + " #" + item.registro_id : "") + "</small></div>";
          }).join("") : '<p class="' + UI.muted + '">Sin movimientos registrados.</p>';
        }).catch(function (error) { holder.innerHTML = '<p class="scm-om-form-error">' + esc(error.message) + "</p>"; });
      }
      selectEl.addEventListener("change", loadActivity);
      loadActivity();
    }

    // --- Eventos ----------------------------------------------------------------------------------------------
    module.addEventListener("click", function (event) {
      var tab = event.target.closest("[data-om-tab]");
      if (tab) return selectTab(tab.getAttribute("data-om-tab"));
      var type = state.tab;
      if (event.target.closest("[data-om-new]")) return openForm(type, null);
      var edit = event.target.closest("[data-om-edit]");
      if (edit) return openForm(type, findRow(type, edit.getAttribute("data-om-edit")));
      var remove = event.target.closest("[data-om-remove]");
      if (remove) { var row = findRow(type, remove.getAttribute("data-om-remove")); if (row) confirmDelete(type, row); return; }
      var profile = event.target.closest("[data-om-profile]");
      if (profile) { var cargo = findRow("cargos", profile.getAttribute("data-om-profile")); if (cargo) openProfile(cargo); return; }
      var view = event.target.closest("[data-om-policy-view]");
      if (view) return openPolicyDetail(view.getAttribute("data-om-policy-view"));
      var history = event.target.closest("[data-om-policy-history]");
      if (history) { var p = findRow("politicas", history.getAttribute("data-om-policy-history")); return openPolicyHistory(history.getAttribute("data-om-policy-history"), p ? p.titulo : ""); }
      var readers = event.target.closest("[data-om-policy-readers]");
      if (readers) return openPolicyReaders(readers.getAttribute("data-om-policy-readers"));
      var version = event.target.closest("[data-om-policy-version]");
      if (version) return openPolicyForm(findRow("politicas", version.getAttribute("data-om-policy-version")), true);
      if (event.target.closest("[data-om-policies-pdf]")) {
        var fields = currentFilters();
        fields.tipo = "politicas";
        return viewPdf("Políticas organizacionales", "scm_org_policies_pdf", fields, "politicas-organizacionales.pdf");
      }
      if (state.transfer && state.tab === "traslados") {
        var moduleBtn = event.target.closest("[data-om-transfer-module]");
        if (moduleBtn) { transferPayload(); state.transfer.module = moduleBtn.getAttribute("data-om-transfer-module"); state.transfer.preview = null; state.transfer.mode = "all"; return drawTransfers(); }
        var previewBtn = event.target.closest("[data-om-transfer-preview]");
        if (previewBtn) { var done = busy(previewBtn, "Consultando…"); return previewTransfer().catch(function (error) { done(); setStatus(error.message, "error"); }); }
        if (event.target.closest("[data-om-transfer-execute]")) return executeTransfer();
        if (event.target.closest("[data-om-transfer-all]")) {
          body.querySelectorAll("[data-om-transfer-record]").forEach(function (input) { input.checked = true; });
          var selectedRadio = body.querySelector('[name="om_transfer_mode"][value="selected"]');
          if (selectedRadio) selectedRadio.checked = true;
        }
      }
    });
    module.addEventListener("change", function (event) {
      if (event.target.matches("[data-om-transfer-record]") && event.target.checked) {
        var selectedRadio = body.querySelector('[name="om_transfer_mode"][value="selected"]');
        if (selectedRadio) selectedRadio.checked = true;
      }
      if (event.target.matches('[name="om_transfer_mode"][value="all"]')) {
        body.querySelectorAll("[data-om-transfer-record]").forEach(function (input) { input.checked = false; });
      }
      if (event.target.matches('[name="tipo_negocio"]') && state.transfer) { state.transfer.preview = null; }
      // Las búsquedas de texto ya recargan con espera al escribir (evento input).
      if (event.target.closest("[data-om-filters]") && ["search", "text"].indexOf(event.target.type) === -1) { window.clearTimeout(state.timer); loadRows(state.tab); }
    });
    module.addEventListener("input", function (event) {
      if (!event.target.closest("[data-om-filters]") || event.target.tagName === "SELECT") return;
      window.clearTimeout(state.timer);
      var tab = state.tab;
      state.timer = window.setTimeout(function () { if (state.tab === tab) loadRows(tab); }, 300);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && state.modals.length) state.modals[state.modals.length - 1].close();
    });
    module.addEventListener("scm:load-org-manager", load);
    // Antes de que el panel cambie de pestaña se guarda la sección pedida; si el panel ya estaba abierto, se recarga.
    document.addEventListener("click", function (event) {
      var link = event.target.closest && event.target.closest("[data-org-focus]");
      if (!link) return;
      window.scmOrgFocus = link.getAttribute("data-org-focus");
      var current = module.closest(".scm-tab-panel");
      if (current && current.classList.contains("active")) window.setTimeout(load, 0);
    }, true);
    var panel = module.closest(".scm-tab-panel");
    if (panel && panel.classList.contains("active")) load();
  }

  function start() {
    document.querySelectorAll("#scm-app").forEach(init);
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
})();
