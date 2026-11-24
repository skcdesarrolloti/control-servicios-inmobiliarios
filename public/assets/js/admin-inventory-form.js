(function () {
  "use strict";

  // Formulario nativo del inventario del inmueble (src/App/Concerns/HandlesInventoryActions.php).
  // El estado completo vive en un objeto y se envía como un solo JSON: un inventario amoblado pasa de los
  // miles de campos y PHP cortaría el resto en silencio con max_input_vars.
  var SAVE_DELAY = 3500;
  var STATES = ["Bueno", "Regular", "Malo"];

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function money(value) {
    return "$" + Number(value || 0).toLocaleString("es-CO");
  }

  function confirmAction(title, text, confirmLabel) {
    if (window.Swal && typeof window.Swal.fire === "function") {
      return window.Swal.fire({ icon: "question", title: title, text: text, showCancelButton: true, confirmButtonText: confirmLabel, cancelButtonText: "Cancelar" }).then(function (r) {
        return !!(r && r.isConfirmed);
      });
    }
    return Promise.resolve(window.confirm(title + " " + text));
  }

  function bind(form, helpers) {
    var root = form.querySelector("[data-inventory-root]");
    var config = JSON.parse(form.querySelector("[data-inventory-config]").textContent);
    var catalog = config.catalog;
    var state = config.state;
    var extraSections = [];
    var paneId = "datos";
    var runtime = helpers.runtime || {};
    var ticket = helpers.caseBtn.dataset.ticketPk || "";
    var localKey = ["scm", "inventario", (document.querySelector("[data-scm-draft-user]") || { dataset: {} }).dataset.scmDraftUser || "", ticket, config.inventoryId || "nuevo"].join(":");
    var draft = { ready: false, blocked: false, revision: 0, last: "", timer: 0, saving: false, stopped: false };
    var voice = { target: "", mode: "", text: "", rows: [], message: "", dictation: false, recorder: null, stream: null, recognition: null, timer: 0, heard: "" };
    // Usabilidad: selector de espacios, fila quitada que se puede recuperar y errores que se marcan en vivo
    // después del primer intento de guardar.
    var spacePicker = false;
    var undo = null;
    var showErrors = false;
    var missing = {};
    var errorPanes = {};

    function normalize() {
      state.data = state.data || {};
      state.data.servicios = state.data.servicios || [];
      state.data.acuerdos = state.data.acuerdos || [];
      state.boundaries = state.boundaries || {};
      state.sections = state.sections || {};
      state.furniture = state.furniture || {};
      catalog.boundaries.forEach(function (group) {
        if (!Array.isArray(state.boundaries[group.key])) state.boundaries[group.key] = [];
        if (!group.multiple && !state.boundaries[group.key].length) state.boundaries[group.key].push({});
      });
      catalog.sections.forEach(function (section) {
        var entry = state.sections[section.key] || {};
        state.sections[section.key] = { rows: Array.isArray(entry.rows) ? entry.rows : [], floor: entry.floor || "", details: entry.details || "" };
        if (!Array.isArray(state.furniture[section.key])) state.furniture[section.key] = [];
      });
    }
    normalize();

    function d(key) {
      return state.data[key] == null ? "" : state.data[key];
    }
    function profile() {
      return catalog.profiles[d("tipo_de_inmueble")] || catalog.profiles[""];
    }
    function isFurnished() {
      return d("categoria_inventario") === "Amoblado";
    }
    function isYes(key) {
      return d(key) === "Si";
    }
    function floorsCount() {
      return Math.max(1, Math.min(10, Number(d("cantidad_pisos")) || 1));
    }
    function countField(group) {
      return group === "habitacion" ? "cantidad_habitaciones" : "cantidad_banos";
    }
    // Cantidad por piso. Si no cuadra con el total (cambió la cantidad de pisos), todo queda en el piso 1.
    function roomCounts(group) {
      var total = Number(d(countField(group))) || 0;
      var counts = (Array.isArray(state.data[group + "_pisos"]) ? state.data[group + "_pisos"] : []).map(Number).slice(0, floorsCount());
      while (counts.length < floorsCount()) counts.push(0);
      if (counts.reduce(function (a, b) { return a + b; }, 0) !== total) {
        counts = counts.map(function () { return 0; });
        counts[0] = total;
      }
      return counts;
    }
    // Cada habitación y baño toma su piso según la cantidad indicada por piso, y un tipo por defecto.
    function syncRooms() {
      state.data.tipos = state.data.tipos && typeof state.data.tipos === "object" && !Array.isArray(state.data.tipos) ? state.data.tipos : {};
      ["habitacion", "bano"].forEach(function (group) {
        var counts = roomCounts(group);
        state.data[group + "_pisos"] = counts;
        var index = 1;
        counts.forEach(function (count, floor) {
          for (var i = 0; i < count; i++, index++) {
            var section = catalog.sections.find(function (s) { return s.group === group && s.index === index; });
            if (!section) continue;
            state.sections[section.key].floor = String(floor + 1);
            if (!state.data.tipos[section.key]) state.data.tipos[section.key] = index === 1 ? "Principal" : group === "bano" ? "Social" : "Secundaria";
          }
        });
      });
    }
    function sectionLabel(section) {
      var base = profile().labels[section.key] || section.label;
      if (section.group === "general") return base;
      var parts = [base];
      if (state.data.tipos && state.data.tipos[section.key]) parts.push(state.data.tipos[section.key]);
      if (floorsCount() > 1) parts.push("Piso " + (state.sections[section.key].floor || "1"));
      return parts.join(" · ");
    }
    function visibleSections() {
      var p = profile();
      var byKey = {};
      catalog.sections.forEach(function (s) { byKey[s.key] = s; });
      var keys = [];
      p.order.forEach(function (item) {
        if (item === "habitaciones" || item === "banos") {
          var group = item === "habitaciones" ? "habitacion" : "bano";
          var count = Math.min(Number(d(countField(group))) || 0, item === "habitaciones" ? p.max_rooms : p.max_baths);
          catalog.sections.forEach(function (s) { if (s.group === group && s.index <= count) keys.push(s.key); });
        } else if (byKey[item]) {
          var need = (p.requires || {})[item];
          if (!need || isYes(need) || state.sections[item].rows.length || extraSections.indexOf(item) !== -1) keys.push(item);
        }
      });
      // Secciones fuera del tipo que ya tienen filas o que se agregaron a mano: antes de "Otros".
      catalog.sections.forEach(function (s) {
        if (s.group !== "general" || keys.indexOf(s.key) !== -1 || p.order.indexOf(s.key) !== -1) return;
        if (state.sections[s.key].rows.length || extraSections.indexOf(s.key) !== -1) {
          var at = keys.indexOf("otros");
          keys.splice(at === -1 ? keys.length : at, 0, s.key);
        }
      });
      return keys.map(function (k) { return byKey[k]; });
    }
    function stem(value) {
      return String(value || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().split(/\s+/).filter(Boolean).map(function (word) {
        if (word.length > 4 && /[rnld]es$/.test(word)) return word.slice(0, -2);
        if (word.length > 3 && /s$/.test(word)) return word.slice(0, -1);
        return word;
      }).join(" ");
    }
    // Misma regla que InventorySchema::isElectrical en el servidor.
    function isElectrical(name) {
      var value = stem(name);
      if (!value) return false;
      return (catalog.electrical || []).concat(["Telefonos", "Citofonos", "Interruptores", "Tomas", "Rosetas", "Contador luz", "Caja de fusibles", "Lamparas", "Apliques", "Plafon", "Ojo de buey", "Bombillo", "Tomacorriente"]).some(function (item) {
        var s = stem(item);
        return s && (value === s || value.indexOf(s + " ") === 0);
      });
    }
    function electricalFromSections() {
      var out = [];
      visibleSections().forEach(function (section) {
        if (section.key === "elementos_electricos") return;
        state.sections[section.key].rows.forEach(function (row) {
          if (isElectrical(row.d)) out.push({ row: row, where: sectionLabel(section) });
        });
      });
      return out;
    }
    function hiddenGeneralSections() {
      var visible = visibleSections().map(function (s) { return s.key; });
      return catalog.sections.filter(function (s) { return s.group === "general" && visible.indexOf(s.key) === -1; });
    }
    function panes() {
      var list = [{ id: "datos", label: "Datos" }, { id: "linderos", label: "Linderos" }];
      visibleSections().forEach(function (section) {
        list.push({ id: "s:" + section.key, label: sectionLabel(section), section: section, count: state.sections[section.key].rows.length + (section.key === "elementos_electricos" ? electricalFromSections().length : 0) });
      });
      if (isFurnished()) list.push({ id: "muebles", label: "Muebles y enseres" });
      list.push({ id: "cierre", label: "Cierre" });
      return list;
    }
    function bucketOf(section) {
      return section.group === "general" ? section.key : section.group;
    }
    function suggestionsFor(section) {
      var learned = catalog.suggestions[bucketOf(section)] || { items: [], materials: {}, quantities: {}, observations: {} };
      var names = [];
      learned.items.concat(section.glossary).forEach(function (name) {
        if (names.indexOf(name) === -1) names.push(name);
      });
      return { names: names, materials: learned.materials || {}, quantities: learned.quantities || {}, observations: learned.observations || {} };
    }

    // Campos ---------------------------------------------------------------
    function miss(key) {
      return missing[key] ? " is-missing" : "";
    }
    function input(label, key, options) {
      options = options || {};
      return '<label class="scm-inv-field' + (options.wide ? " is-wide" : "") + miss(key) + '"><span>' + esc(label) + (options.required ? " *" : "") + "</span>" +
        '<input type="' + (options.type || "text") + '" data-b="' + esc(key) + '" value="' + esc(d(key)) + '"' + (options.placeholder ? ' placeholder="' + esc(options.placeholder) + '"' : "") + "></label>";
    }
    function select(label, key, values, options) {
      options = options || {};
      var current = d(key);
      var list = values.slice();
      if (current !== "" && list.indexOf(current) === -1) list.unshift(current);
      return '<label class="scm-inv-field' + miss(key) + '"><span>' + esc(label) + (options.required ? " *" : "") + "</span>" +
        '<select data-b="' + esc(key) + '"' + (options.rerender ? " data-rerender" : "") + "><option value=\"\">Elige una opción</option>" +
        list.map(function (value) {
          var text = typeof value === "object" ? value.label : value;
          var val = typeof value === "object" ? value.value : value;
          return '<option value="' + esc(val) + '"' + (String(val) === String(current) ? " selected" : "") + ">" + esc(text) + "</option>";
        }).join("") + "</select></label>";
    }
    // Opciones cortas (Sí/No, Nuevo/Anterior…) como botones: un toque en lugar de abrir una lista.
    function choice(label, key, values, options) {
      options = options || {};
      var current = d(key);
      return '<div class="scm-inv-field' + miss(key) + '"><span>' + esc(label) + (options.required ? " *" : "") + "</span>" +
        '<div class="scm-inv-choice" role="radiogroup" aria-label="' + esc(label) + '">' + values.map(function (value) {
          var val = Array.isArray(value) ? value[0] : value;
          var text = Array.isArray(value) ? value[1] : value;
          var on = String(current) === String(val);
          return '<button type="button" role="radio" aria-checked="' + on + '" class="' + (on ? "is-on" : "") + '" data-choice="' + esc(key) + '" data-value="' + esc(val) + '">' + esc(text) + "</button>";
        }).join("") + "</div></div>";
    }
    var YES_NO = [["Si", "Sí"], ["No", "No"]];
    // Bloque que agrupa una pregunta Sí/No con los campos que dependen de ella.
    function toggleBlock(label, key, fields) {
      return '<div class="scm-inv-sub' + (isYes(key) ? " is-open" : "") + '">' + choice(label, key, YES_NO, { required: true }) +
        (isYes(key) && fields ? '<div class="scm-inv-grid">' + fields + "</div>" : "") + "</div>";
    }
    function range(from, to) {
      var out = [];
      for (var i = from; i <= to; i++) out.push(String(i));
      return out;
    }

    // Paneles --------------------------------------------------------------
    function paneData() {
      var p = profile();
      var html = "";
      if (config.additional) {
        html += '<p class="scm-inv-hint">Este inventario es de <b>otro inmueble del mismo contrato</b>. Escribe sus datos; irá también en los anexos.</p>';
      }
      if (config.receipt) {
        html += '<p class="scm-inv-hint">Caso de recibo: el contrato no tenía inventario. Se registra como <b>Anterior</b>, no genera cobro ni se envía.</p>';
      }
      if (config.previous && config.previous.length) {
        html += '<div class="scm-inv-card scm-inv-base"><label class="scm-inv-field is-wide"><span>Empezar desde otro inventario de este inmueble</span><select data-previous-source><option value="">No, empezar desde cero</option>' +
          config.previous.map(function (item) { return '<option value="' + esc(item.id) + '">' + esc(item.label) + "</option>"; }).join("") +
          '</select></label><button type="button" class="scm-acta-button scm-acta-secondary" data-load-previous>Cargar elementos</button></div>';
      }
      html += '<div class="scm-inv-card"><h4>Inventario</h4><div class="scm-inv-inline">' +
        choice("Tipo de inventario", "tipo_inventario", config.receipt ? ["Anterior"] : catalog.types, { required: true }) +
        choice("Categoría", "categoria_inventario", catalog.categories, { required: true }) +
        '</div><div class="scm-inv-grid">' +
        input("Registro fotográfico (enlace de Drive)", "registro_fotografico", { required: !config.editing, wide: true, placeholder: "https://drive.google.com/..." }) +
        "</div></div>";
      html += '<div class="scm-inv-card"><h4>Inmueble</h4><div class="scm-inv-grid">' +
        input("Contrato", "contrato", { required: true }) + input("Inmueble (código SIMI)", "inmueble", { required: true }) +
        select("Tipo de inmueble", "tipo_de_inmueble", Object.keys(catalog.profiles).filter(Boolean), { rerender: true }) +
        input("Dirección física", "direccion", { required: true, wide: true }) +
        input("Tipo de negocio", "tipo_negocio") + input("Destinación", "destinacion") +
        "</div></div>";
      html += '<div class="scm-inv-card"><h4>Distribución</h4><p class="scm-inv-hint">Cada habitación y cada baño tiene su propia pestaña.</p><div class="scm-inv-grid">' +
        select("Cantidad de pisos", "cantidad_pisos", range(1, 10), { rerender: true }) +
        roomCountFields("habitacion", p.rooms_label, p.max_rooms) +
        roomCountFields("bano", p.baths_label, p.max_baths) +
        "</div></div>";
      html += '<div class="scm-inv-card"><h4>Parqueadero y depósito</h4><div class="scm-inv-split">' +
        toggleBlock("¿Tiene parqueadero?", "tiene_parqueadero",
          select("Tipo", "tipo_parqueadero", catalog.parking, { required: true }) + input("Cantidad", "parqueaderos", { required: true, type: "number" }) +
          input("Número(s)", "numero_parqueadero", { required: true, placeholder: "Ej: 12, 13" })) +
        toggleBlock("¿Tiene depósito?", "tiene_deposito", input("Cantidad", "depositos", { required: true, type: "number" })) +
        "</div></div>";
      html += '<div class="scm-inv-card"><h4>Quien recibe</h4><div class="scm-inv-grid">' +
        input("Arrendatario", "arrendatario", { required: true }) + input("Documento", "cedula_arrendatario") + input("Correo", "correo_arrendatario", { required: true, type: "email" }) + input("Celular", "celular_arrendatario", { required: true, type: "tel" }) +
        "</div>" +
        toggleBlock("¿Recibe un delegado?", "tiene_delegado",
          input("Nombre", "delegado", { required: true }) + input("Documento", "cedula_delegado") + input("Correo", "correo_delegado", { required: true, type: "email" }) + input("Celular", "celular_delegado", { required: true, type: "tel" })) +
        "</div>";
      return html;
    }

    // Con un piso, un total; con varios, cuántas habitaciones o baños hay en cada piso.
    function roomCountFields(group, label, max) {
      if (!max) return "";
      if (floorsCount() === 1) return select(label, countField(group), range(0, max), { rerender: true });
      return roomCounts(group).map(function (count, floor) {
        return '<label class="scm-inv-field"><span>' + esc(label) + " · piso " + (floor + 1) + '</span><select data-room-count="' + group + ":" + floor + '">' +
          range(0, max).map(function (n) { return '<option value="' + n + '"' + (Number(n) === count ? " selected" : "") + ">" + n + "</option>"; }).join("") + "</select></label>";
      }).join("");
    }

    function paneBoundaries() {
      var sideLabels = { norte: "Norte", sur: "Sur", oriente: "Oriente", occidente: "Occidente", cenit: "Cenit", nadir: "Nadir" };
      var html = '<div class="scm-inv-card"><div class="scm-inv-grid">' + input("Fecha de toma de linderos", "fecha_linderos", { type: "date", required: true }) + "</div></div>";
      catalog.boundaries.forEach(function (group) {
        if (group.when && !isYes(group.when)) return;
        var rows = state.boundaries[group.key];
        html += '<div class="scm-inv-card"><h4>' + esc(group.label) + (group.required ? " *" : "") + "</h4>";
        rows.forEach(function (row, index) {
          html += '<div class="scm-inv-boundary">' + (group.key === "parqueadero_lin" ? boundaryInput("# Parqueadero", group.key, index, "num", row) : "") +
            group.sides.map(function (side) { return boundaryInput(sideLabels[side], group.key, index, side, row); }).join("") +
            (group.multiple ? '<button type="button" class="scm-inv-icon" data-boundary-remove="' + esc(group.key) + ":" + index + '" aria-label="Quitar">×</button>' : "") + "</div>";
        });
        if (group.multiple) html += '<button type="button" class="scm-acta-button scm-acta-secondary" data-boundary-add="' + esc(group.key) + '">Agregar ' + esc(group.label.toLowerCase()) + "</button>";
        html += "</div>";
      });
      return html;
    }
    function boundaryInput(label, group, index, side, row) {
      return '<label class="scm-inv-field"><span>' + esc(label) + '</span><input data-boundary="' + esc(group) + ":" + index + ":" + side + '" value="' + esc(row[side] || "") + '"></label>';
    }

    // Dictado ---------------------------------------------------------------
    function voiceButton(target) {
      if (!config.voice) return "";
      return '<button type="button" class="scm-inv-tool scm-inv-voice-btn" data-voice-start="' + esc(target) + '"' + (voice.mode === "recording" || voice.mode === "processing" ? " disabled" : "") + ">🎤 Dictar</button>";
    }
    function voicePanel(target) {
      if (voice.target !== target) return "";
      if (voice.mode === "recording") {
        return '<div class="scm-inv-voice is-recording" role="status">' +
          '<span class="scm-inv-voice-mic" aria-hidden="true">🎤</span>' +
          '<span class="scm-inv-voice-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>' +
          '<span class="scm-inv-voice-text"><b>Escuchando…</b> Di cada elemento con su cantidad, material y estado. Ej.: «dos tomas de plástico en buen estado».' +
          (voice.live ? '<em class="scm-inv-voice-live">' + esc(voice.live) + "</em>" : "") + "</span>" +
          '<button type="button" class="scm-acta-button" data-voice-stop>Listo</button><button type="button" class="scm-acta-button scm-acta-secondary" data-voice-cancel>Cancelar</button></div>';
      }
      if (voice.mode === "processing") return '<div class="scm-inv-voice is-processing" role="status"><span class="scm-inv-voice-spin" aria-hidden="true"></span><span class="scm-inv-voice-text">Llenando la tabla con lo que dijiste…</span></div>';
      if (voice.mode === "error") return '<div class="scm-inv-voice is-error" role="alert"><span class="scm-inv-voice-text">' + esc(voice.message) + '</span><button type="button" class="scm-acta-button scm-acta-secondary" data-voice-cancel>Cerrar</button></div>';
      if (voice.mode !== "done") return "";
      return '<div class="scm-inv-voice is-done" role="status"><span class="scm-inv-voice-text"><b>✓ Se ' + (voice.added.length === 1 ? "agregó 1 elemento" : "agregaron " + voice.added.length + " elementos") + ".</b> Si algo quedó mal, corrígelo directamente en la tabla." +
        (voice.text ? '<em class="scm-inv-voice-live">«' + esc(voice.text) + "»</em>" : "") + "</span>" +
        '<button type="button" class="scm-acta-button scm-acta-secondary" data-voice-undo>Deshacer</button><button type="button" class="scm-acta-button scm-acta-secondary" data-voice-cancel>Cerrar</button></div>';
    }
    function voiceTargetRows() {
      var parts = voice.target.split(":");
      return parts[0] === "sections" ? state.sections[parts[1]].rows : state.furniture[parts[1]];
    }
    function voiceSet(mode, message) {
      voice.mode = mode;
      voice.message = message || "";
      render();
    }
    function voiceRelease() {
      window.clearTimeout(voice.timer);
      if (voice.stream) voice.stream.getTracks().forEach(function (track) { track.stop(); });
      voice.stream = null;
      voice.recorder = null;
      voice.recognition = null;
    }
    function voiceClose() {
      voiceRelease();
      voice.target = "";
      voice.mode = "";
      voice.rows = [];
      voice.added = [];
      voice.live = "";
    }
    function voiceSend(fields) {
      voiceSet("processing");
      var data = new FormData();
      data.set("action", (runtime.actions && runtime.actions.inventory_voice) || "scm_inventario_voz");
      data.set("nonce", runtime.nonce || "");
      data.set("case_ticket", ticket);
      data.set("target", voice.target);
      Object.keys(fields).forEach(function (key) { data.set(key, fields[key]); });
      fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin", headers: { Accept: "application/json" } })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo procesar el dictado.");
          if (json.data.transcription_failed) {
            voice.dictation = !!speechRecognitionClass();
            voiceSet("error", json.data.message + (voice.dictation ? " Toca Dictar otra vez para usar el dictado del teléfono." : " Registra las filas a mano."));
            return;
          }
          voice.text = json.data.text || "";
          voice.rows = json.data.rows || [];
          if (!voice.rows.length) {
            voiceSet("error", "No se entendieron elementos" + (voice.text ? " en «" + voice.text + "»" : "") + ". Intenta otra vez diciendo el elemento, la cantidad y el estado.");
            return;
          }
          var targetRows = voiceTargetRows();
          voice.added = voice.rows.slice();
          voice.added.forEach(function (row) { targetRows.push(row); });
          voiceSet("done");
          changed();
        })
        .catch(function (error) { voiceSet("error", error.message); });
    }
    function speechRecognitionClass() {
      return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }
    function startDictation() {
      var Recognition = speechRecognitionClass();
      var recognition = new Recognition();
      recognition.lang = "es-CO";
      recognition.continuous = true;
      recognition.interimResults = true;
      voice.heard = "";
      voice.live = "";
      recognition.onresult = function (event) {
        var interim = "";
        for (var i = event.resultIndex; i < event.results.length; i++) {
          if (event.results[i].isFinal) voice.heard += " " + event.results[i][0].transcript;
          else interim += " " + event.results[i][0].transcript;
        }
        voice.live = (voice.heard + interim).trim();
        var live = root.querySelector(".scm-inv-voice.is-recording .scm-inv-voice-text");
        if (live) {
          var em = live.querySelector(".scm-inv-voice-live");
          if (!em) { em = document.createElement("em"); em.className = "scm-inv-voice-live"; live.appendChild(em); }
          em.textContent = voice.live;
        }
      };
      recognition.onerror = function (event) {
        var code = event && event.error;
        voiceSet("error", microphoneError({ name: code === "not-allowed" || code === "service-not-allowed" ? "NotAllowedError" : code === "audio-capture" ? "NotFoundError" : "" }));
      };
      recognition.onend = function () {
        if (voice.mode !== "recording") return;
        voice.recognition = null;
        if (voice.heard.trim()) voiceSend({ text: voice.heard.trim() });
        else voiceSet("error", "No se escuchó nada. Intenta otra vez.");
      };
      voice.recognition = recognition;
      recognition.start();
      voiceSet("recording");
    }
    function startRecording() {
      if (!navigator.mediaDevices || !window.MediaRecorder) {
        if (speechRecognitionClass()) { startDictation(); return; }
        voiceSet("error", "Este navegador no permite grabar audio. Registra las filas a mano.");
        return;
      }
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        var chunks = [];
        var recorder = new MediaRecorder(stream);
        voice.stream = stream;
        voice.recorder = recorder;
        recorder.ondataavailable = function (event) { if (event.data && event.data.size) chunks.push(event.data); };
        recorder.onstop = function () {
          if (voice.mode !== "recording") return;
          var blob = new Blob(chunks, { type: recorder.mimeType });
          voiceRelease();
          voiceSet("processing");
          toWav(blob).then(function (wav) { voiceSend({ audio: new File([wav], "dictado.wav", { type: "audio/wav" }) }); })
            .catch(function () { voiceSet("error", "No se pudo preparar el audio. Intenta otra vez."); });
        };
        recorder.start();
        voice.timer = window.setTimeout(function () { if (recorder.state === "recording") recorder.stop(); }, 120000);
        voiceSet("recording");
      }).catch(function (error) { voiceSet("error", microphoneError(error)); });
    }
    // Mensaje según la causa real para que el funcionario sepa qué hacer.
    function microphoneError(error) {
      var name = error && error.name;
      if (!window.isSecureContext) return "El dictado necesita abrir el panel con https.";
      if (name === "NotAllowedError" || name === "SecurityError") return "El navegador bloqueó el micrófono. Toca el candado junto a la dirección de la página, permite el micrófono y vuelve a tocar Dictar.";
      if (name === "NotFoundError" || name === "OverconstrainedError") return "No se encontró un micrófono en este equipo. Conecta uno o registra las filas a mano.";
      if (name === "NotReadableError" || name === "AbortError") return "Otra aplicación está usando el micrófono. Ciérrala y vuelve a tocar Dictar.";
      return "No se pudo usar el micrófono. Revisa el permiso del navegador.";
    }
    // MiniMax no acepta el webm que graban los navegadores: se envía WAV mono de 16 kHz.
    function toWav(blob) {
      var Context = window.AudioContext || window.webkitAudioContext;
      return blob.arrayBuffer().then(function (buffer) {
        var context = new Context();
        return context.decodeAudioData(buffer).then(function (decoded) { context.close(); return decoded; });
      }).then(function (decoded) {
        var offline = new OfflineAudioContext(1, Math.max(1, Math.ceil(decoded.duration * 16000)), 16000);
        var source = offline.createBufferSource();
        source.buffer = decoded;
        source.connect(offline.destination);
        source.start();
        return offline.startRendering();
      }).then(function (rendered) {
        var samples = rendered.getChannelData(0);
        var view = new DataView(new ArrayBuffer(44 + samples.length * 2));
        function text(offset, value) { for (var i = 0; i < value.length; i++) view.setUint8(offset + i, value.charCodeAt(i)); }
        text(0, "RIFF"); view.setUint32(4, 36 + samples.length * 2, true); text(8, "WAVE"); text(12, "fmt ");
        view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true); view.setUint32(24, 16000, true);
        view.setUint32(28, 32000, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true); text(36, "data"); view.setUint32(40, samples.length * 2, true);
        for (var i = 0; i < samples.length; i++) {
          var value = Math.max(-1, Math.min(1, samples[i]));
          view.setInt16(44 + i * 2, value < 0 ? value * 32768 : value * 32767, true);
        }
        return view.buffer;
      });
    }

    function rowHtml(kind, key, row, index, material, section) {
      var suggestion = section ? suggestionsFor(section) : null;
      var materials = suggestion && suggestion.materials[row.d] ? suggestion.materials[row.d] : [];
      var listId = "scm-inv-m-" + key + "-" + index;
      var withFloor = material && section && section.group === "general" && floorsCount() > 1;
      return '<div class="scm-inv-row' + (withFloor ? " has-floor" : "") + '" data-row="' + index + '">' +
        (withFloor ? '<select class="scm-inv-p" data-f="p" aria-label="Piso">' + range(1, floorsCount()).map(function (n) { return '<option value="' + n + '"' + (String(row.p || "1") === n ? " selected" : "") + ">Piso " + n + "</option>"; }).join("") + "</select>" : "") +
        '<input class="scm-inv-d" data-f="d" value="' + esc(row.d) + '" placeholder="Elemento" data-suggest="scm-inv-desc-' + esc(key) + '" autocomplete="off" aria-label="Descripción">' +
        '<input class="scm-inv-c" data-f="c" value="' + esc(row.c) + '" placeholder="Cant." data-suggest="scm-inv-qty" autocomplete="off" aria-label="Cantidad">' +
        (material ? '<input class="scm-inv-m" data-f="m" value="' + esc(row.m) + '" placeholder="Material" data-suggest="' + listId + '" autocomplete="off" aria-label="Tipo de material"><datalist id="' + listId + '">' + materials.map(function (m) { return '<option value="' + esc(m) + '">'; }).join("") + "</datalist>" : "") +
        '<div class="scm-inv-state" role="group" aria-label="Estado">' + STATES.map(function (s) { return '<button type="button" data-state="' + s + '" class="' + ((row.e || "Bueno") === s ? "is-on is-" + s.toLowerCase() : "") + '">' + s + "</button>"; }).join("") + "</div>" +
        '<input class="scm-inv-o" data-f="o" value="' + esc(row.o) + '" placeholder="Ninguna" data-suggest="' + listId + '-o" autocomplete="off" aria-label="Observaciones"><datalist id="' + listId + '-o">' + optionsHtml(observationsFor(suggestion, row.d)) + "</datalist>" +
        '<div class="scm-inv-row-actions"><button type="button" class="scm-inv-icon" data-row-copy aria-label="Duplicar fila" title="Duplicar">⧉</button><button type="button" class="scm-inv-icon" data-row-remove aria-label="Quitar fila" title="Quitar">×</button></div>' +
        "</div>";
    }

    function optionsHtml(values) {
      return values.map(function (v) { return '<option value="' + esc(v) + '">'; }).join("");
    }
    // Observaciones que más se han usado para ese elemento en inventarios anteriores.
    function observationsFor(suggestion, name) {
      return suggestion && suggestion.observations[name] ? suggestion.observations[name] : [];
    }

    function rowsBlock(kind, key, rows, material, section) {
      return '<div class="scm-inv-rows" data-rows="' + kind + ":" + esc(key) + '">' +
        rows.map(function (row, index) { return rowHtml(kind, key, row, index, material, section); }).join("") + "</div>";
    }

    function isOptionalSpace(section) {
      return section.group === "general" && extraSections.indexOf(section.key) !== -1 && profile().order.indexOf(section.key) === -1;
    }
    function paneSection(section) {
      var entry = state.sections[section.key];
      var suggestion = suggestionsFor(section);
      var used = entry.rows.map(function (r) { return r.d; });
      var pending = suggestion.names.filter(function (n) { return used.indexOf(n) === -1; });
      var multiFloor = Number(d("cantidad_pisos")) > 1;
      var others = visibleSections().filter(function (s) { return s.key !== section.key && state.sections[s.key].rows.length; });
      var html = '<div class="scm-inv-card"><div class="scm-inv-card-head"><h4>' + esc(sectionLabel(section)) + "</h4>" +
        (isOptionalSpace(section) ? '<button type="button" class="scm-inv-link is-danger" data-space-remove="' + esc(section.key) + '">Quitar este espacio</button>' : "") + "</div>";
      if (section.group !== "general") {
        var types = section.group === "habitacion" ? catalog.roomTypes : catalog.bathTypes;
        html += '<div class="scm-inv-grid"><label class="scm-inv-field"><span>Tipo</span><select data-room-type="' + esc(section.key) + '">' +
          types.map(function (t) { return '<option value="' + esc(t) + '"' + (state.data.tipos[section.key] === t ? " selected" : "") + ">" + esc(t) + "</option>"; }).join("") + "</select></label>" +
          '<label class="scm-inv-field is-wide"><span>Detalles de ubicación</span><input data-entry="details" value="' + esc(entry.details) + '"></label></div>';
      } else if (section.floor && (multiFloor || entry.floor || entry.details)) {
        html += '<div class="scm-inv-grid"><label class="scm-inv-field"><span>Piso</span><input data-entry="floor" value="' + esc(entry.floor) + '"></label>' +
          '<label class="scm-inv-field is-wide"><span>Detalles de ubicación</span><input data-entry="details" value="' + esc(entry.details) + '"></label></div>';
      }
      html += '<div class="scm-inv-tools">' +
        '<button type="button" class="scm-inv-tool" data-row-add>+ Fila</button>' +
        voiceButton("sections:" + section.key) +
        (entry.rows.length ? '<button type="button" class="scm-inv-tool" data-all-good>Todo en Bueno</button>' : "") +
        (others.length ? '<label class="scm-inv-copy"><span>Copiar elementos de</span><select data-copy-from><option value="">Elige un espacio</option>' + others.map(function (s) { return '<option value="' + esc(s.key) + '">' + esc(sectionLabel(s)) + "</option>"; }).join("") + "</select></label>" : "") +
        "</div>";
      if (pending.length) {
        html += '<div class="scm-inv-chips"><span>Agregar rápido:</span>' + pending.slice(0, 40).map(function (n) { return '<button type="button" data-add-item="' + esc(n) + '">' + esc(n) + "</button>"; }).join("") +
          '<button type="button" class="is-all" data-add-all>Agregar todos</button></div>';
      }
      html += voicePanel("sections:" + section.key);
      html += '<datalist id="scm-inv-desc-' + esc(section.key) + '">' + suggestion.names.map(function (n) { return '<option value="' + esc(n) + '">'; }).join("") + "</datalist>";
      if (section.key === "elementos_electricos") {
        var auto = electricalFromSections();
        html += auto.length
          ? '<div class="scm-inv-auto"><b>Se agregan solos desde las otras secciones (' + auto.length + "):</b><ul>" + auto.map(function (item) {
              return "<li>" + esc(item.row.d) + " · " + esc(item.row.c || "-") + " · " + esc(item.row.e || "Bueno") + " <small>(" + esc(item.where) + ")</small></li>";
            }).join("") + "</ul><small>Se cambian desde la sección donde se registraron. Aquí agrega solo lo que no esté en ninguna sección, como el contador o la caja de fusibles.</small></div>"
          : '<p class="scm-inv-hint">Las tomas, interruptores, lámparas y demás eléctricos que registres en las otras secciones se agregan aquí solos.</p>';
      }
      if (entry.rows.length) {
        html += '<div class="scm-inv-head' + (section.group === "general" && multiFloor ? " has-floor" : "") + '">' + (section.group === "general" && multiFloor ? "<span>Piso</span>" : "") + '<span>Elemento</span><span>Cant.</span><span>Material</span><span>Estado</span><span>Observaciones</span><span></span></div>';
      } else {
        html += '<p class="scm-inv-hint">Aún no hay elementos. Toca los sugeridos de arriba, usa «+ Fila» o copia los de otro espacio.</p>';
      }
      html += rowsBlock("sections", section.key, entry.rows, true, section) + "</div>";
      return html;
    }

    function paneFurniture() {
      var html = '<p class="scm-inv-hint">Registra los muebles y enseres en el lugar donde están. Solo se guardan las secciones con filas.</p>';
      visibleSections().forEach(function (section) {
        var rows = state.furniture[section.key];
        html += '<details class="scm-inv-card scm-inv-furniture"' + (rows.length || voice.target === "furniture:" + section.key ? " open" : "") + '><summary>' + esc(sectionLabel(section)) + ' <small>' + rows.length + "</small></summary>" +
          rowsBlock("furniture", section.key, rows, false, null) +
          '<button type="button" class="scm-inv-tool" data-furniture-add="' + esc(section.key) + '">+ Mueble o enser</button>' + voiceButton("furniture:" + section.key) + voicePanel("furniture:" + section.key) + "</details>";
      });
      return html;
    }

    function paneClosing() {
      var html = '<div class="scm-inv-card"><h4>Observaciones</h4><textarea rows="3" data-b="observaciones" placeholder="Ninguna">' + esc(d("observaciones")) + "</textarea></div>";
      var names = { Energia: ["Luz", "luz", "medidor_luz"], Agua: ["Agua", "agua", "medidor_agua"], Gas: ["Gas", "gas", "medidor_gas"] };
      html += '<div class="scm-inv-card"><h4>Servicios públicos</h4><div class="scm-inv-checks">' + catalog.services.map(function (service) {
        return '<label><input type="checkbox" data-list="servicios" value="' + esc(service) + '"' + (d("servicios").indexOf(service) !== -1 ? " checked" : "") + "> " + esc(service) + "</label>";
      }).join("") + '</div><div class="scm-inv-grid">';
      d("servicios").forEach(function (service) {
        if (names[service]) html += input(names[service][0] + " · póliza o cuenta", names[service][1], { required: true }) + input("Medidor " + names[service][0].toLowerCase(), names[service][2]);
      });
      html += "</div></div>";
      html += '<div class="scm-inv-card"><h4>Acuerdos precontractuales</h4><div class="scm-inv-checks">' + Object.keys(catalog.agreements).map(function (label) {
        return '<label><input type="checkbox" data-list="acuerdos" value="' + esc(label) + '"' + (d("acuerdos").indexOf(label) !== -1 ? " checked" : "") + "> " + esc(label) + "</label>";
      }).join("") + "</div>";
      d("acuerdos").forEach(function (label) {
        html += '<label class="scm-inv-field is-wide"><span>Acuerdos con ' + esc(label.toLowerCase()) + '</span><textarea rows="2" data-b="' + esc(catalog.agreements[label]) + '">' + esc(d(catalog.agreements[label])) + "</textarea></label>";
      });
      html += "</div>";
      if (d("tipo_inventario") === "Nuevo" && (!config.editing || config.hasReport)) html += billingHtml();
      return html;
    }

    function billingHtml() {
      var double = isFurnished() || Number(d("cantidad_pisos")) >= 2;
      var service = config.billing.base * (double ? 2 : 1);
      var transport = isYes("con_transporte") ? config.billing.transporte : 0;
      return '<div class="scm-inv-card scm-inv-billing"><h4>Reporte administrativo</h4><div class="scm-inv-grid">' +
        choice("¿Cobrar transporte?", "con_transporte", YES_NO) + "</div>" +
        '<ul><li><span>Servicio' + (double ? " (doble: " + (isFurnished() ? "amoblado" : "2 pisos o más") + ")" : "") + "</span><strong>" + money(service) + "</strong></li>" +
        "<li><span>Transporte</span><strong>" + money(transport) + "</strong></li>" +
        '<li class="is-total"><span>Total del reporte</span><strong>' + money(service + transport) + "</strong></li></ul>" +
        '<small>Máximo 2 reportes por caso. El valor final lo calcula el sistema con la tarifa vigente.</small></div>';
    }

    function render() {
      syncRooms();
      refreshErrors();
      var list = panes();
      if (!list.some(function (p) { return p.id === paneId; })) paneId = "datos";
      var index = list.findIndex(function (p) { return p.id === paneId; });
      var pane = list[index];
      var body = pane.id === "datos" ? paneData() : pane.id === "linderos" ? paneBoundaries() : pane.id === "muebles" ? paneFurniture() : pane.id === "cierre" ? paneClosing() : paneSection(pane.section);
      var hidden = hiddenGeneralSections();
      if (!hidden.length) spacePicker = false;
      var keepScroll = root.querySelector("[data-pane-nav]");
      var scrollLeft = keepScroll ? keepScroll.scrollLeft : 0;
      var paneErrors = showErrors ? validate().filter(function (e) { return e.pane === pane.id; }) : [];
      root.innerHTML =
        '<div data-draft-banner></div>' +
        '<nav class="scm-inv-nav" data-pane-nav aria-label="Pasos del inventario">' + list.map(function (p, position) {
          var cls = (p.id === paneId ? "is-active " : "") + (p.count ? "is-done " : "") + (errorPanes[p.id] ? "has-error" : "");
          return '<button type="button" data-pane="' + esc(p.id) + '" class="' + cls.trim() + '"' + (p.id === paneId ? ' aria-current="step"' : "") + ' title="Paso ' + (position + 1) + " de " + list.length + '">' + esc(p.label) + (p.count ? " <small>" + p.count + "</small>" : "") + "</button>";
        }).join("") +
        (hidden.length ? '<button type="button" class="scm-inv-add-space" data-space-open aria-expanded="' + spacePicker + '">+ Agregar espacio</button>' : "") + "</nav>" +
        (spacePicker ? spacePickerHtml(hidden) : "") +
        '<datalist id="scm-inv-qty">' + ["1", "2", "3", "4", "5", "6", "Todo el inmueble", "Todo el espacio", "Nada"].map(function (q) { return '<option value="' + q + '">'; }).join("") + "</datalist>" +
        (paneErrors.length ? '<div class="scm-inv-banner is-warn" role="alert"><span>Para guardar falta en este paso: ' + esc(paneErrors.map(function (e) { return e.text; }).join(", ")) + ".</span></div>" : "") +
        '<div class="scm-inv-pane">' + body + "</div>" +
        '<footer class="scm-inv-footer"><div class="scm-inv-status"><span class="scm-inv-progress">Paso ' + (index + 1) + " de " + list.length + '</span><span class="scm-inv-saved" data-draft-status></span>' +
        (undo ? '<span class="scm-inv-undo" role="status">Se quitó «' + esc(undo.row.d || "fila sin nombre") + '». <button type="button" class="scm-inv-link" data-undo>Deshacer</button></span>' : "") + "</div>" +
        (index > 0 ? '<button type="button" class="scm-acta-button scm-acta-secondary" data-step="-1">Volver</button>' : "") +
        (index < list.length - 1 ? '<button type="button" class="scm-acta-button scm-acta-secondary" data-goto-end>Ir al cierre</button><button type="button" class="scm-acta-button" data-step="1">Siguiente</button>' : '<button type="button" class="scm-acta-button" data-save>' + (config.editing ? "Guardar cambios" : "Guardar inventario") + "</button>") +
        (config.editing ? '<button type="button" class="scm-acta-button scm-acta-secondary" data-inventory-cancel>Cancelar</button>' : "") +
        "</footer>";
      var nav = root.querySelector("[data-pane-nav]");
      if (nav) {
        nav.scrollLeft = scrollLeft;
        var active = nav.querySelector(".is-active");
        if (active && active.scrollIntoView) active.scrollIntoView({ block: "nearest", inline: "center" });
      }
      renderBanner();
      setStatus(draft.statusText || "");
    }

    function spacePickerHtml(hidden) {
      return '<div class="scm-inv-card scm-inv-picker" role="group" aria-label="Agregar espacios">' +
        '<div class="scm-inv-card-head"><h4>¿Qué otros espacios tiene el inmueble?</h4><button type="button" class="scm-inv-icon" data-space-close aria-label="Cerrar">×</button></div>' +
        '<p class="scm-inv-hint">Marca todos los que necesites; cada uno queda como una pestaña más. Las habitaciones y los baños se agregan con su cantidad en Datos.</p>' +
        '<div class="scm-inv-picks">' + hidden.map(function (s) {
          return '<label><input type="checkbox" data-space-pick value="' + esc(s.key) + '"><span>' + esc(sectionLabel(s)) + "</span></label>";
        }).join("") + "</div>" +
        '<div class="scm-inv-picker-actions"><button type="button" class="scm-acta-button scm-acta-secondary" data-space-close>Cancelar</button><button type="button" class="scm-acta-button" data-space-add disabled>Agregar</button></div></div>';
    }
    function refreshErrors() {
      missing = {};
      errorPanes = {};
      if (!showErrors) return;
      validate().forEach(function (e) {
        if (e.key) missing[e.key] = true;
        errorPanes[e.pane] = true;
      });
    }

    function go(id) {
      if (voice.mode !== "recording") voiceClose();
      paneId = id;
      render();
      var top = root.querySelector("[data-pane-nav]");
      if (top && top.scrollIntoView) top.scrollIntoView({ block: "start" });
    }

    // Validación -----------------------------------------------------------
    var LABELS = { tipo_inventario: "el tipo de inventario", categoria_inventario: "la categoría", contrato: "el contrato", inmueble: "el código del inmueble", direccion: "la dirección",
      arrendatario: "el arrendatario", correo_arrendatario: "el correo del arrendatario", celular_arrendatario: "el celular del arrendatario", registro_fotografico: "el enlace del registro fotográfico",
      tiene_parqueadero: "si tiene parqueadero", tiene_deposito: "si tiene depósito", tiene_delegado: "si recibe un delegado", tipo_parqueadero: "el tipo de parqueadero",
      parqueaderos: "la cantidad de parqueaderos", numero_parqueadero: "el número de parqueadero", depositos: "la cantidad de depósitos", delegado: "el nombre del delegado",
      correo_delegado: "el correo del delegado", celular_delegado: "el celular del delegado", fecha_linderos: "la fecha de linderos" };
    function validate() {
      var errors = [];
      function need(pane, ok, key, text) {
        if (!ok) errors.push({ pane: pane, key: key, text: text || LABELS[key] || key.replace(/_/g, " ") });
      }
      function filled(key) {
        return String(d(key)).trim() !== "";
      }
      ["tipo_inventario", "categoria_inventario", "contrato", "inmueble", "direccion"].forEach(function (key) { need("datos", filled(key), key); });
      if (!config.editing) need("datos", /^https?:\/\//i.test(String(d("registro_fotografico"))), "registro_fotografico");
      ["tiene_parqueadero", "tiene_deposito"].forEach(function (key) { need("datos", filled(key), key); });
      if (isYes("tiene_parqueadero")) ["tipo_parqueadero", "parqueaderos", "numero_parqueadero"].forEach(function (key) { need("datos", filled(key), key); });
      if (isYes("tiene_deposito")) need("datos", filled("depositos"), "depositos");
      ["arrendatario", "correo_arrendatario", "celular_arrendatario", "tiene_delegado"].forEach(function (key) { need("datos", filled(key), key); });
      if (isYes("tiene_delegado")) ["delegado", "correo_delegado", "celular_delegado"].forEach(function (key) { need("datos", filled(key), key); });
      need("linderos", d("fecha_linderos"), "fecha_linderos");
      var main = state.boundaries.inmueble_lin[0] || {};
      need("linderos", Object.keys(main).some(function (k) { return String(main[k] || "").trim(); }), "inmueble_lin", "los linderos del inmueble");
      var total = visibleSections().reduce(function (sum, s) { return sum + state.sections[s.key].rows.filter(function (r) { return String(r.d || "").trim(); }).length; }, 0);
      need("datos", total > 0, "", "al menos un elemento en el inventario");
      var names = { Energia: "luz", Agua: "agua", Gas: "gas" };
      d("servicios").forEach(function (service) { if (names[service]) need("cierre", filled(names[service]), names[service], "la póliza de " + names[service]); });
      return errors;
    }

    function submit() {
      var errors = validate();
      if (errors.length) {
        showErrors = true;
        go(errors[0].pane);
        var first = root.querySelector(".is-missing input, .is-missing select, .is-missing button");
        if (first) first.focus();
        helpers.message("Falta completar: " + errors.slice(0, 4).map(function (e) { return e.text; }).join(", ") + (errors.length > 4 ? " y " + (errors.length - 4) + " más" : "") + ".", true);
        return;
      }
      saveServer();
      var data = new FormData();
      data.set("review_id", String(config.inventoryId || ""));
      data.set("version", config.version || "");
      data.set("payload", JSON.stringify({ data: state.data, boundaries: state.boundaries, sections: state.sections, furniture: state.furniture }));
      helpers.request(config.editing ? "update" : "create", data);
    }

    // Autoguardado ---------------------------------------------------------
    function post(action, fields) {
      var data = new FormData();
      data.set("action", action);
      data.set("nonce", runtime.nonce || "");
      data.set("ticket_pk", ticket);
      data.set("review_id", String(config.inventoryId || 0));
      Object.keys(fields || {}).forEach(function (k) { data.set(k, fields[k]); });
      return fetch(runtime.ajaxUrl || "api.php", { method: "POST", body: data, credentials: "same-origin", headers: { Accept: "application/json" } })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar la operación.");
          return json.data || {};
        });
    }
    function draftAction() {
      return (runtime.actions && runtime.actions.inventory_draft) || "scm_inventario_borrador";
    }
    function snapshot() {
      return JSON.stringify({ state: { data: state.data, boundaries: state.boundaries, sections: state.sections, furniture: state.furniture }, pane: paneId, extra: extraSections });
    }
    function setStatus(text) {
      draft.statusText = text;
      var node = root.querySelector("[data-draft-status]");
      if (node) node.textContent = text;
    }
    function localSave(json) {
      try { window.localStorage.setItem(localKey, JSON.stringify({ at: Date.now(), json: json })); } catch (e) { /* cuota o modo privado */ }
    }
    function localRead() {
      try { return JSON.parse(window.localStorage.getItem(localKey) || "null"); } catch (e) { return null; }
    }
    function localClear() {
      try { window.localStorage.removeItem(localKey); } catch (e) { /* nada */ }
    }
    function changed() {
      if (!draft.ready || draft.blocked || draft.stopped) return;
      localSave(snapshot());
      setStatus("Cambios sin guardar…");
      window.clearTimeout(draft.timer);
      draft.timer = window.setTimeout(saveServer, SAVE_DELAY);
    }
    function saveServer() {
      if (!draft.ready || draft.blocked || draft.stopped || draft.saving) return Promise.resolve();
      window.clearTimeout(draft.timer);
      var json = snapshot();
      if (json === draft.last) return Promise.resolve();
      draft.saving = true;
      setStatus("Guardando…");
      return post(draftAction(), { operation: "save", payload: json, revision: String(draft.revision) })
        .then(function (result) {
          if (result.status === "conflict") {
            draft.blocked = true;
            draft.conflict = true;
            renderBanner();
            setStatus("");
            return;
          }
          draft.revision = result.revision;
          draft.last = json;
          localClear();
          setStatus("Guardado " + new Date().toLocaleTimeString("es-CO", { hour: "numeric", minute: "2-digit" }));
        })
        .catch(function () { setStatus("Sin conexión: guardado en este dispositivo"); })
        .finally(function () {
          draft.saving = false;
          if (snapshot() !== draft.last && !draft.blocked && !draft.stopped) { draft.timer = window.setTimeout(saveServer, SAVE_DELAY); }
        });
    }
    function applyDraft(payload) {
      var saved = typeof payload === "string" ? JSON.parse(payload) : payload;
      if (!saved || !saved.state) return;
      state = saved.state;
      extraSections = saved.extra || [];
      normalize();
      paneId = saved.pane || "datos";
    }
    function renderBanner() {
      var node = root.querySelector("[data-draft-banner]");
      if (!node) return;
      if (draft.conflict) {
        node.innerHTML = '<div class="scm-inv-banner is-warn"><span>Este borrador se guardó desde otro dispositivo. Para no sobrescribirlo, el autoguardado se pausó.</span><button type="button" class="scm-acta-button scm-acta-secondary" data-draft-reload>Traer el otro borrador</button></div>';
      } else if (draft.pending) {
        node.innerHTML = '<div class="scm-inv-banner"><span>Tienes un borrador guardado el ' + esc(new Date(draft.pending.at).toLocaleString("es-CO", { dateStyle: "short", timeStyle: "short" })) + '. El autoguardado está en pausa hasta que elijas.</span><button type="button" class="scm-acta-button" data-draft-restore>Recuperar</button><button type="button" class="scm-acta-button scm-acta-secondary" data-draft-discard>Empezar de nuevo</button></div>';
      } else {
        node.innerHTML = "";
      }
    }
    function loadDraft() {
      post(draftAction(), { operation: "get" }).then(function (result) {
        var server = result.draft;
        var local = localRead();
        draft.revision = server ? server.revision : 0;
        var serverAt = server ? server.updated_at * 1000 : 0;
        if (local && local.json && local.at > serverAt) {
          draft.pending = { at: local.at, payload: local.json };
        } else if (server) {
          draft.pending = { at: serverAt, payload: server.payload };
        }
        draft.blocked = !!draft.pending;
        draft.ready = true;
        renderBanner();
      }).catch(function () {
        // Si no se pudo consultar, no se guarda: podría sobrescribir un borrador que no se alcanzó a ver.
        var local = localRead();
        draft.ready = true;
        draft.blocked = true;
        draft.pending = local && local.json ? { at: local.at, payload: local.json } : null;
        renderBanner();
        setStatus("No se pudo verificar el borrador. El autoguardado está en pausa.");
      });
    }

    // Eventos --------------------------------------------------------------
    function rowsTarget(node) {
      var container = node.closest("[data-rows]");
      if (!container) return null;
      var parts = container.getAttribute("data-rows").split(":");
      return parts[0] === "sections" ? state.sections[parts[1]].rows : state.furniture[parts[1]];
    }
    function currentSection() {
      var pane = panes().find(function (p) { return p.id === paneId; });
      return pane && pane.section ? pane.section : null;
    }
    function newRow(name, section) {
      var suggestion = section ? suggestionsFor(section) : null;
      return { p: "1", d: name || "", c: suggestion && suggestion.quantities[name] ? suggestion.quantities[name] : "", m: suggestion && suggestion.materials[name] && suggestion.materials[name][0] ? suggestion.materials[name][0] : "", e: "Bueno", o: "" };
    }

    form.addEventListener("input", function (event) {
      var target = event.target;
      if (target.dataset.b) {
        state.data[target.dataset.b] = target.value;
      } else if (target.dataset.boundary) {
        var parts = target.dataset.boundary.split(":");
        state.boundaries[parts[0]][Number(parts[1])][parts[2]] = target.value;
      } else if (target.dataset.entry) {
        state.sections[currentSection().key][target.dataset.entry] = target.value;
      } else if (target.dataset.f) {
        var rows = rowsTarget(target);
        rows[Number(target.closest("[data-row]").dataset.row)][target.dataset.f] = target.value;
        if (target.dataset.f === "d") {
          var rowEl = target.closest("[data-row]");
          var obsSection = target.closest("[data-rows]") && target.closest("[data-rows]").dataset.rows.indexOf("sections:") === 0 ? currentSection() : null;
          if (obsSection) {
            var learnedRow = suggestionsFor(obsSection);
            var name = target.value.trim();
            var obsList = rowEl.querySelector(".scm-inv-o + datalist");
            var matList = rowEl.querySelector(".scm-inv-m + datalist");
            if (obsList) obsList.innerHTML = optionsHtml(observationsFor(learnedRow, name));
            if (matList) matList.innerHTML = optionsHtml(learnedRow.materials[name] || []);
          }
        }
      } else {
        return;
      }
      var marked = target.closest(".is-missing");
      if (marked && String(target.value).trim()) marked.classList.remove("is-missing");
      changed();
    });

    // Sugerencias de los campos de fila --------------------------------------
    // Lista propia: al tocar el campo muestra todas las opciones (aunque ya tenga un valor) y filtra al escribir.
    var suggest = null;
    var picking = false;
    function plain(value) {
      return String(value || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();
    }
    function suggestClose() {
      if (suggest && suggest.box.parentNode) suggest.box.parentNode.removeChild(suggest.box);
      if (suggest) suggest.input.setAttribute("aria-expanded", "false");
      suggest = null;
    }
    function suggestOpen(input) {
      suggestClose();
      var list = document.getElementById(input.getAttribute("data-suggest") || "");
      var all = list ? [].map.call(list.options, function (o) { return o.value; }).filter(Boolean) : [];
      var query = plain(input.value);
      var exact = all.some(function (v) { return plain(v) === query; });
      var shown = !query || exact ? all : all.filter(function (v) { return plain(v).indexOf(query) !== -1; });
      if (!shown.length || !input.offsetParent) return;
      var box = document.createElement("div");
      box.className = "scm-inv-suggest";
      box.setAttribute("role", "listbox");
      box.innerHTML = shown.slice(0, 12).map(function (v) {
        return '<button type="button" role="option" tabindex="-1" data-suggest-pick class="' + (plain(v) === query ? "is-current" : "") + '">' + esc(v) + "</button>";
      }).join("");
      box.style.top = input.offsetTop + input.offsetHeight + 2 + "px";
      box.style.left = input.offsetLeft + "px";
      box.style.minWidth = Math.max(input.offsetWidth, 180) + "px";
      box.style.maxWidth = Math.min(360, input.parentNode.clientWidth) + "px";
      input.parentNode.appendChild(box);
      // Que no se salga por la derecha (columna de cantidad en el celular).
      var overflow = box.offsetLeft + box.offsetWidth - input.parentNode.clientWidth;
      if (overflow > 0) box.style.left = Math.max(0, box.offsetLeft - overflow) + "px";
      input.setAttribute("aria-expanded", "true");
      suggest = { box: box, input: input, index: -1 };
    }
    // Al girar la tablet o cambiar el tamaño, la posición ya no sirve.
    window.addEventListener("resize", function () { if (suggest) suggestClose(); });
    function suggestPick(button) {
      if (!suggest) return;
      var input = suggest.input;
      input.value = button.textContent;
      suggestClose();
      picking = true;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      picking = false;
    }
    function suggestMove(step) {
      var items = suggest.box.querySelectorAll("[data-suggest-pick]");
      suggest.index = (suggest.index + step + items.length) % items.length;
      [].forEach.call(items, function (item, i) { item.classList.toggle("is-active", i === suggest.index); });
      items[suggest.index].scrollIntoView({ block: "nearest" });
    }
    form.addEventListener("focusin", function (event) {
      var target = event.target;
      if (target.classList && target.classList.contains("scm-inv-o") && target.value === "Ninguna") target.select();
      if (target.hasAttribute && target.hasAttribute("data-suggest")) suggestOpen(target);
    });
    form.addEventListener("focusout", function (event) {
      if (suggest && event.target === suggest.input) suggestClose();
    });
    form.addEventListener("input", function (event) {
      if (event.target.hasAttribute && event.target.hasAttribute("data-suggest") && !picking && document.activeElement === event.target) suggestOpen(event.target);
    });
    form.addEventListener("click", function (event) {
      if (event.target.hasAttribute && event.target.hasAttribute("data-suggest") && !suggest) suggestOpen(event.target);
    });
    // mousedown para que el campo no pierda el foco antes de elegir.
    form.addEventListener("mousedown", function (event) {
      var pick = event.target.closest && event.target.closest("[data-suggest-pick]");
      if (!pick) return;
      event.preventDefault();
      suggestPick(pick);
    });

    form.addEventListener("change", function (event) {
      var target = event.target;
      if (target.dataset.list) {
        var list = state.data[target.dataset.list === "servicios" ? "servicios" : "acuerdos"];
        var at = list.indexOf(target.value);
        if (target.checked && at === -1) list.push(target.value);
        if (!target.checked && at !== -1) list.splice(at, 1);
        render();
        changed();
      } else if (target.dataset.roomCount) {
        var rc = target.dataset.roomCount.split(":");
        var counts = roomCounts(rc[0]);
        counts[Number(rc[1])] = Number(target.value) || 0;
        state.data[rc[0] + "_pisos"] = counts;
        state.data[countField(rc[0])] = String(counts.reduce(function (a, b) { return a + b; }, 0));
        render();
        changed();
      } else if (target.dataset.roomType) {
        state.data.tipos[target.dataset.roomType] = target.value;
        render();
        changed();
      } else if (target.hasAttribute("data-rerender")) {
        state.data[target.dataset.b] = target.value;
        render();
        changed();
      } else if (target.matches("[data-copy-from]") && target.value) {
        var section = currentSection();
        state.sections[target.value].rows.forEach(function (row) { state.sections[section.key].rows.push(Object.assign({}, row)); });
        render();
        changed();
      } else if (target.matches("[data-space-pick]")) {
        var picked = root.querySelectorAll("[data-space-pick]:checked").length;
        var addBtn = root.querySelector("[data-space-add]");
        if (addBtn) {
          addBtn.disabled = !picked;
          addBtn.textContent = picked ? "Agregar " + picked + (picked === 1 ? " espacio" : " espacios") : "Agregar";
        }
      }
    });

    form.addEventListener("click", function (event) {
      var button = event.target.closest("button");
      if (!button || !form.contains(button)) return;
      if (undo && !button.hasAttribute("data-undo") && !button.hasAttribute("data-row-remove")) undo = null;
      if (button.dataset.pane) { go(button.dataset.pane); return; }
      if (button.dataset.choice) {
        state.data[button.dataset.choice] = button.dataset.value;
        render();
        changed();
        return;
      }
      if (button.hasAttribute("data-space-open")) { spacePicker = !spacePicker; render(); return; }
      if (button.hasAttribute("data-space-close")) { spacePicker = false; render(); return; }
      if (button.hasAttribute("data-space-add")) {
        var keys = Array.prototype.map.call(root.querySelectorAll("[data-space-pick]:checked"), function (box) { return box.value; });
        if (!keys.length) return;
        keys.forEach(function (key) { if (extraSections.indexOf(key) === -1) extraSections.push(key); });
        spacePicker = false;
        go("s:" + keys[0]);
        changed();
        helpers.message(keys.length === 1 ? "Espacio agregado." : keys.length + " espacios agregados como pestañas.", false);
        return;
      }
      if (button.dataset.spaceRemove) {
        var spaceKey = button.dataset.spaceRemove;
        var count = state.sections[spaceKey].rows.length + state.furniture[spaceKey].length;
        (count ? confirmAction("¿Quitar este espacio?", "Se borran sus " + count + " elemento(s) registrados.", "Quitar") : Promise.resolve(true)).then(function (ok) {
          if (!ok) return;
          state.sections[spaceKey].rows = [];
          state.furniture[spaceKey] = [];
          extraSections = extraSections.filter(function (key) { return key !== spaceKey; });
          go("datos");
          changed();
        });
        return;
      }
      if (button.hasAttribute("data-undo")) {
        if (undo) undo.rows.splice(Math.min(undo.index, undo.rows.length), 0, undo.row);
        undo = null;
        render();
        changed();
        return;
      }
      if (button.dataset.step) {
        var list = panes();
        var next = list[list.findIndex(function (p) { return p.id === paneId; }) + Number(button.dataset.step)];
        if (next) go(next.id);
        return;
      }
      if (button.hasAttribute("data-goto-end")) { go("cierre"); return; }
      if (button.hasAttribute("data-save")) { submit(); return; }
      if (button.hasAttribute("data-inventory-cancel")) { helpers.request("read"); return; }
      if (button.hasAttribute("data-draft-restore")) {
        applyDraft(draft.pending.payload);
        draft.pending = null;
        draft.blocked = false;
        render();
        helpers.message("Borrador recuperado.", false);
        return;
      }
      if (button.hasAttribute("data-draft-discard")) {
        confirmAction("¿Descartar el borrador?", "Se borra lo guardado y empiezas con los datos del contrato.", "Descartar").then(function (ok) {
          if (!ok) return;
          localClear();
          post(draftAction(), { operation: "delete" }).then(function () {
            draft.pending = null;
            draft.blocked = false;
            draft.revision = 0;
            renderBanner();
          }).catch(function (error) { helpers.message(error.message, true); });
        });
        return;
      }
      if (button.hasAttribute("data-draft-reload")) {
        post(draftAction(), { operation: "get" }).then(function (result) {
          if (result.draft) { applyDraft(result.draft.payload); draft.revision = result.draft.revision; }
          draft.conflict = false;
          draft.blocked = false;
          draft.last = snapshot();
          render();
        }).catch(function (error) { helpers.message(error.message, true); });
        return;
      }
      if (button.hasAttribute("data-load-previous")) {
        var source = root.querySelector("[data-previous-source]").value;
        if (!source) { helpers.message("Elige el inventario base.", true); return; }
        confirmAction("¿Cargar los elementos de ese inventario?", "Reemplaza los elementos y muebles registrados hasta ahora. Revisa el estado de cada uno, porque el inmueble pudo cambiar.", "Cargar").then(function (ok) {
          if (!ok) return;
          post((runtime.actions && runtime.actions.inventario) || "scm_inventario", { operation: "previous", source_id: source }).then(function (result) {
            state.sections = result.state.sections;
            state.furniture = result.state.furniture;
            normalize();
            var filled = catalog.sections.filter(function (s) { return s.group !== "general" && state.sections[s.key].rows.length; });
            filled.forEach(function (s) {
              var field = s.group === "habitacion" ? "cantidad_habitaciones" : "cantidad_banos";
              if (Number(d(field)) < s.index) state.data[field] = String(s.index);
            });
            state.data.habitacion_pisos = null;
            state.data.bano_pisos = null;
            render();
            changed();
            helpers.message("Elementos cargados. Revísalos antes de guardar.", false);
          }).catch(function (error) { helpers.message(error.message, true); });
        });
        return;
      }
      if (button.dataset.boundaryAdd) { state.boundaries[button.dataset.boundaryAdd].push({}); render(); changed(); return; }
      if (button.dataset.boundaryRemove) {
        var bp = button.dataset.boundaryRemove.split(":");
        state.boundaries[bp[0]].splice(Number(bp[1]), 1);
        render();
        changed();
        return;
      }
      if (button.dataset.voiceStart) {
        voice.target = button.dataset.voiceStart;
        voice.rows = [];
        voice.added = [];
        voice.live = "";
        if (voice.dictation && speechRecognitionClass()) startDictation();
        else startRecording();
        return;
      }
      if (button.hasAttribute("data-voice-stop")) {
        if (voice.recognition) voice.recognition.stop();
        else if (voice.recorder && voice.recorder.state === "recording") voice.recorder.stop();
        return;
      }
      if (button.hasAttribute("data-voice-cancel")) {
        var pending = voice.recognition;
        voice.mode = "";
        if (pending) pending.abort();
        else if (voice.recorder && voice.recorder.state === "recording") voice.recorder.stop();
        voiceClose();
        render();
        return;
      }
      if (button.hasAttribute("data-voice-undo")) {
        var fromRows = voiceTargetRows();
        voice.added.forEach(function (row) {
          var at = fromRows.indexOf(row);
          if (at !== -1) fromRows.splice(at, 1);
        });
        voiceClose();
        render();
        changed();
        return;
      }
      var section = currentSection();
      if (button.hasAttribute("data-row-add")) { state.sections[section.key].rows.push(newRow("", section)); render(); changed(); return; }
      if (button.dataset.addItem) { state.sections[section.key].rows.push(newRow(button.dataset.addItem, section)); render(); changed(); return; }
      if (button.hasAttribute("data-add-all")) {
        var used = state.sections[section.key].rows.map(function (r) { return r.d; });
        suggestionsFor(section).names.filter(function (n) { return used.indexOf(n) === -1; }).slice(0, 40).forEach(function (n) { state.sections[section.key].rows.push(newRow(n, section)); });
        render();
        changed();
        return;
      }
      if (button.hasAttribute("data-all-good")) { state.sections[section.key].rows.forEach(function (r) { r.e = "Bueno"; }); render(); changed(); return; }
      if (button.dataset.furnitureAdd) {
        state.furniture[button.dataset.furnitureAdd].push({ d: "", c: "", m: "", e: "Bueno", o: "" });
        render();
        changed();
        return;
      }
      if (button.dataset.state) {
        rowsTarget(button)[Number(button.closest("[data-row]").dataset.row)].e = button.dataset.state;
        render();
        changed();
        return;
      }
      if (button.hasAttribute("data-row-copy") || button.hasAttribute("data-row-remove")) {
        var rows = rowsTarget(button);
        var index = Number(button.closest("[data-row]").dataset.row);
        if (button.hasAttribute("data-row-copy")) rows.splice(index + 1, 0, Object.assign({}, rows[index]));
        else undo = { rows: rows, index: index, row: rows.splice(index, 1)[0] };
        render();
        changed();
      }
    });

    form.addEventListener("submit", function (event) { event.preventDefault(); });
    form.addEventListener("keydown", function (event) {
      if (suggest && event.target === suggest.input) {
        if (event.key === "ArrowDown" || event.key === "ArrowUp") { event.preventDefault(); suggestMove(event.key === "ArrowDown" ? 1 : -1); return; }
        if (event.key === "Escape") { event.preventDefault(); suggestClose(); return; }
        if (event.key === "Enter" && suggest.index >= 0) { event.preventDefault(); suggestPick(suggest.box.querySelectorAll("[data-suggest-pick]")[suggest.index]); return; }
      }
      if (event.key === "Enter" && event.target.tagName === "INPUT") event.preventDefault();
    });

    // Hooks del editor: al guardar con éxito se limpia; si falla, el borrador se conserva.
    form._scmCorrectiveClearDraft = function () { draft.stopped = true; window.clearTimeout(draft.timer); localClear(); };
    form._scmCorrectiveSaveDraft = saveServer;
    window.addEventListener("pagehide", function () {
      if (!form.isConnected || !draft.ready || draft.blocked || draft.stopped) return;
      var json = snapshot();
      if (json === draft.last) return;
      localSave(json);
    });
    var observer = new MutationObserver(function () {
      if (!form.isConnected) { saveServer(); observer.disconnect(); }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    render();
    draft.last = snapshot();
    loadDraft();
  }

  window.ScmInventoryForm = { bind: bind };
})();
