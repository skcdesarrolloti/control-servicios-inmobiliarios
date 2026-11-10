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
    function sectionLabel(section) {
      return profile().labels[section.key] || section.label;
    }
    function visibleSections() {
      var rooms = Number(d("cantidad_habitaciones")) || 0;
      var baths = Number(d("cantidad_banos")) || 0;
      var hidden = profile().hidden || [];
      return catalog.sections.filter(function (section) {
        if (section.group === "habitacion") return section.index <= rooms;
        if (section.group === "bano") return section.index <= baths;
        return hidden.indexOf(section.key) === -1 || state.sections[section.key].rows.length > 0 || extraSections.indexOf(section.key) !== -1;
      });
    }
    function hiddenGeneralSections() {
      var visible = visibleSections().map(function (s) { return s.key; });
      return catalog.sections.filter(function (s) { return s.group === "general" && visible.indexOf(s.key) === -1; });
    }
    function panes() {
      var list = [{ id: "datos", label: "Datos" }, { id: "linderos", label: "Linderos" }];
      visibleSections().forEach(function (section) {
        list.push({ id: "s:" + section.key, label: sectionLabel(section), section: section, count: state.sections[section.key].rows.length });
      });
      if (isFurnished()) list.push({ id: "muebles", label: "Muebles y enseres" });
      list.push({ id: "cierre", label: "Cierre" });
      return list;
    }
    function bucketOf(section) {
      return section.group === "general" ? section.key : section.group;
    }
    function suggestionsFor(section) {
      var learned = catalog.suggestions[bucketOf(section)] || { items: [], materials: {}, quantities: {} };
      var names = [];
      learned.items.concat(section.glossary).forEach(function (name) {
        if (names.indexOf(name) === -1) names.push(name);
      });
      return { names: names, materials: learned.materials || {}, quantities: learned.quantities || {} };
    }

    // Campos ---------------------------------------------------------------
    function input(label, key, options) {
      options = options || {};
      return '<label class="scm-inv-field' + (options.wide ? " is-wide" : "") + '"><span>' + esc(label) + (options.required ? " *" : "") + "</span>" +
        '<input type="' + (options.type || "text") + '" data-b="' + esc(key) + '" value="' + esc(d(key)) + '"' + (options.placeholder ? ' placeholder="' + esc(options.placeholder) + '"' : "") + "></label>";
    }
    function select(label, key, values, options) {
      options = options || {};
      var current = d(key);
      var list = values.slice();
      if (current !== "" && list.indexOf(current) === -1) list.unshift(current);
      return '<label class="scm-inv-field"><span>' + esc(label) + (options.required ? " *" : "") + "</span>" +
        '<select data-b="' + esc(key) + '"' + (options.rerender ? " data-rerender" : "") + "><option value=\"\">Elige una opción</option>" +
        list.map(function (value) {
          var text = typeof value === "object" ? value.label : value;
          var val = typeof value === "object" ? value.value : value;
          return '<option value="' + esc(val) + '"' + (String(val) === String(current) ? " selected" : "") + ">" + esc(text) + "</option>";
        }).join("") + "</select></label>";
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
      if (config.previous && config.previous.length) {
        html += '<div class="scm-inv-card scm-inv-base"><label class="scm-inv-field is-wide"><span>Empezar desde otro inventario de este inmueble</span><select data-previous-source><option value="">No, empezar desde cero</option>' +
          config.previous.map(function (item) { return '<option value="' + esc(item.id) + '">' + esc(item.label) + "</option>"; }).join("") +
          '</select></label><button type="button" class="scm-acta-button scm-acta-secondary" data-load-previous>Cargar elementos</button></div>';
      }
      html += '<div class="scm-inv-card"><h4>Inventario</h4><div class="scm-inv-grid">' +
        select("Tipo de inventario", "tipo_inventario", catalog.types, { required: true, rerender: true }) +
        select("Categoría", "categoria_inventario", catalog.categories, { required: true, rerender: true }) +
        input("Registro fotográfico (enlace)", "registro_fotografico", { required: !config.editing, wide: true, placeholder: "https://drive.google.com/..." }) +
        "</div></div>";
      html += '<div class="scm-inv-card"><h4>Inmueble</h4><div class="scm-inv-grid">' +
        input("Contrato", "contrato", { required: true }) + input("Inmueble", "inmueble", { required: true }) + input("Dirección física", "direccion", { required: true, wide: true }) +
        select("Tipo de inmueble", "tipo_de_inmueble", Object.keys(catalog.profiles).filter(Boolean), { rerender: true }) +
        input("Tipo de negocio", "tipo_negocio") + input("Destinación", "destinacion") +
        select("Cantidad de pisos", "cantidad_pisos", range(1, 10), { rerender: true }) +
        select(p.rooms_label, "cantidad_habitaciones", range(0, p.max_rooms), { rerender: true }) +
        select(p.baths_label, "cantidad_banos", range(0, p.max_baths), { rerender: true }) +
        select("¿Tiene parqueadero?", "tiene_parqueadero", ["Si", "No"], { required: true, rerender: true }) +
        (isYes("tiene_parqueadero") ? select("Tipo de parqueadero", "tipo_parqueadero", catalog.parking, { required: true }) + input("Cantidad", "parqueaderos", { required: true }) + input("# Parqueadero", "numero_parqueadero", { required: true, placeholder: "Separa con coma si son varios" }) : "") +
        select("¿Tiene depósito?", "tiene_deposito", ["Si", "No"], { required: true, rerender: true }) +
        (isYes("tiene_deposito") ? input("Depósitos", "depositos", { required: true }) : "") +
        "</div></div>";
      html += '<div class="scm-inv-card"><h4>Quien recibe</h4><div class="scm-inv-grid">' +
        input("Arrendatario", "arrendatario", { required: true }) + input("Documento", "cedula_arrendatario") + input("Correo", "correo_arrendatario", { required: true, type: "email" }) + input("Celular", "celular_arrendatario", { required: true, type: "tel" }) +
        select("¿Tiene delegado?", "tiene_delegado", ["Si", "No"], { required: true, rerender: true }) +
        (isYes("tiene_delegado") ? input("Delegado", "delegado", { required: true }) + input("Documento del delegado", "cedula_delegado") + input("Correo del delegado", "correo_delegado", { required: true, type: "email" }) + input("Celular del delegado", "celular_delegado", { required: true, type: "tel" }) : "") +
        "</div></div>";
      return html;
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
        return '<div class="scm-inv-voice is-recording" role="status"><span>Te escucho. Di cada elemento con su cantidad, material y estado.</span><button type="button" class="scm-acta-button" data-voice-stop>Terminar</button><button type="button" class="scm-acta-button scm-acta-secondary" data-voice-cancel>Cancelar</button></div>';
      }
      if (voice.mode === "processing") return '<div class="scm-inv-voice" role="status"><span>Organizando lo dictado…</span></div>';
      if (voice.mode === "error") return '<div class="scm-inv-voice is-error" role="alert"><span>' + esc(voice.message) + '</span><button type="button" class="scm-acta-button scm-acta-secondary" data-voice-cancel>Cerrar</button></div>';
      if (voice.mode !== "review") return "";
      var furniture = target.indexOf("furniture:") === 0;
      return '<div class="scm-inv-voice"><p><b>Se entendió:</b> ' + esc(voice.text) + "</p>" +
        (voice.rows.length ? '<ul class="scm-inv-voice-rows">' + voice.rows.map(function (row, index) {
          return '<li><label><input type="checkbox" data-voice-row="' + index + '" checked> <b>' + esc(row.d) + "</b> · " + esc(row.c || "-") + (furniture ? "" : " · " + esc(row.m || "sin material")) + " · " + esc(row.e) + " · " + esc(row.o) + "</label></li>";
        }).join("") + "</ul>" : "<p>No se encontraron elementos en el dictado.</p>") +
        '<div class="scm-inv-voice-actions">' + (voice.rows.length ? '<button type="button" class="scm-acta-button" data-voice-accept>Agregar seleccionadas</button>' : "") + '<button type="button" class="scm-acta-button scm-acta-secondary" data-voice-cancel>Descartar</button></div></div>';
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
          voiceSet("review");
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
      recognition.interimResults = false;
      voice.heard = "";
      recognition.onresult = function (event) {
        for (var i = event.resultIndex; i < event.results.length; i++) voice.heard += " " + event.results[i][0].transcript;
      };
      recognition.onerror = function () { voiceSet("error", "No se pudo usar el dictado. Revisa el permiso del micrófono."); };
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
      }).catch(function () { voiceSet("error", "No se pudo usar el micrófono. Revisa el permiso del navegador."); });
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
      return '<div class="scm-inv-row" data-row="' + index + '">' +
        '<input class="scm-inv-d" data-f="d" value="' + esc(row.d) + '" placeholder="Elemento" list="scm-inv-desc-' + esc(key) + '" aria-label="Descripción">' +
        '<input class="scm-inv-c" data-f="c" value="' + esc(row.c) + '" placeholder="Cant." list="scm-inv-qty" aria-label="Cantidad">' +
        (material ? '<input class="scm-inv-m" data-f="m" value="' + esc(row.m) + '" placeholder="Material" list="' + listId + '" aria-label="Tipo de material"><datalist id="' + listId + '">' + materials.map(function (m) { return '<option value="' + esc(m) + '">'; }).join("") + "</datalist>" : "") +
        '<div class="scm-inv-state" role="group" aria-label="Estado">' + STATES.map(function (s) { return '<button type="button" data-state="' + s + '" class="' + ((row.e || "Bueno") === s ? "is-on is-" + s.toLowerCase() : "") + '">' + s + "</button>"; }).join("") + "</div>" +
        '<input class="scm-inv-o" data-f="o" value="' + esc(row.o) + '" placeholder="Ninguna" aria-label="Observaciones">' +
        '<div class="scm-inv-row-actions"><button type="button" class="scm-inv-icon" data-row-copy aria-label="Duplicar fila" title="Duplicar">⧉</button><button type="button" class="scm-inv-icon" data-row-remove aria-label="Quitar fila" title="Quitar">×</button></div>' +
        "</div>";
    }

    function rowsBlock(kind, key, rows, material, section) {
      return '<div class="scm-inv-rows" data-rows="' + kind + ":" + esc(key) + '">' +
        rows.map(function (row, index) { return rowHtml(kind, key, row, index, material, section); }).join("") + "</div>";
    }

    function paneSection(section) {
      var entry = state.sections[section.key];
      var suggestion = suggestionsFor(section);
      var used = entry.rows.map(function (r) { return r.d; });
      var pending = suggestion.names.filter(function (n) { return used.indexOf(n) === -1; });
      var multiFloor = Number(d("cantidad_pisos")) > 1;
      var others = visibleSections().filter(function (s) { return s.key !== section.key && state.sections[s.key].rows.length; });
      var html = '<div class="scm-inv-card"><h4>' + esc(sectionLabel(section)) + '</h4>';
      if (section.floor && (multiFloor || entry.floor || entry.details)) {
        html += '<div class="scm-inv-grid"><label class="scm-inv-field"><span>Piso</span><input data-entry="floor" value="' + esc(entry.floor) + '"></label>' +
          '<label class="scm-inv-field is-wide"><span>Detalles de ubicación</span><input data-entry="details" value="' + esc(entry.details) + '"></label></div>';
      }
      html += '<div class="scm-inv-tools">' +
        '<button type="button" class="scm-inv-tool" data-row-add>+ Fila</button>' +
        voiceButton("sections:" + section.key) +
        (entry.rows.length ? '<button type="button" class="scm-inv-tool" data-all-good>Todo en Bueno</button>' : "") +
        (others.length ? '<label class="scm-inv-copy"><span>Copiar de</span><select data-copy-from><option value=""></option>' + others.map(function (s) { return '<option value="' + esc(s.key) + '">' + esc(sectionLabel(s)) + "</option>"; }).join("") + "</select></label>" : "") +
        "</div>";
      if (pending.length) {
        html += '<div class="scm-inv-chips"><span>Agregar:</span>' + pending.slice(0, 40).map(function (n) { return '<button type="button" data-add-item="' + esc(n) + '">' + esc(n) + "</button>"; }).join("") +
          '<button type="button" class="is-all" data-add-all>Agregar todos</button></div>';
      }
      html += voicePanel("sections:" + section.key);
      html += '<datalist id="scm-inv-desc-' + esc(section.key) + '">' + suggestion.names.map(function (n) { return '<option value="' + esc(n) + '">'; }).join("") + "</datalist>";
      if (entry.rows.length) {
        html += '<div class="scm-inv-head"><span>Elemento</span><span>Cant.</span><span>Material</span><span>Estado</span><span>Observaciones</span><span></span></div>';
      } else {
        html += '<p class="scm-inv-hint">Toca los elementos de arriba para registrarlos o carga «Copiar de» otra sección.</p>';
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
        select("¿Cobrar transporte?", "con_transporte", ["Si", "No"], { rerender: true }) + "</div>" +
        '<ul><li><span>Servicio' + (double ? " (doble: " + (isFurnished() ? "amoblado" : "2 pisos o más") + ")" : "") + "</span><strong>" + money(service) + "</strong></li>" +
        "<li><span>Transporte</span><strong>" + money(transport) + "</strong></li>" +
        '<li class="is-total"><span>Total del reporte</span><strong>' + money(service + transport) + "</strong></li></ul>" +
        '<small>Máximo 2 reportes por caso. El valor final lo calcula el sistema con la tarifa vigente.</small></div>';
    }

    function render() {
      var list = panes();
      if (!list.some(function (p) { return p.id === paneId; })) paneId = "datos";
      var index = list.findIndex(function (p) { return p.id === paneId; });
      var pane = list[index];
      var body = pane.id === "datos" ? paneData() : pane.id === "linderos" ? paneBoundaries() : pane.id === "muebles" ? paneFurniture() : pane.id === "cierre" ? paneClosing() : paneSection(pane.section);
      var hidden = pane.section ? [] : hiddenGeneralSections();
      var keepScroll = root.querySelector("[data-pane-nav]");
      var scrollLeft = keepScroll ? keepScroll.scrollLeft : 0;
      root.innerHTML =
        '<div data-draft-banner></div>' +
        '<nav class="scm-inv-nav" data-pane-nav>' + list.map(function (p) {
          return '<button type="button" data-pane="' + esc(p.id) + '" class="' + (p.id === paneId ? "is-active " : "") + (p.count ? "is-done" : "") + '">' + esc(p.label) + (p.count ? " <small>" + p.count + "</small>" : "") + "</button>";
        }).join("") + "</nav>" +
        '<datalist id="scm-inv-qty"><option value="Todo el inmueble"><option value="Nada"></datalist>' +
        '<div class="scm-inv-pane">' + body + "</div>" +
        (pane.id === "datos" && hidden.length ? '<div class="scm-inv-card"><label class="scm-inv-copy"><span>Agregar sección oculta para este tipo de inmueble</span><select data-extra-section><option value=""></option>' + hidden.map(function (s) { return '<option value="' + esc(s.key) + '">' + esc(sectionLabel(s)) + "</option>"; }).join("") + "</select></label></div>" : "") +
        '<footer class="scm-inv-footer"><span class="scm-inv-saved" data-draft-status></span>' +
        (index > 0 ? '<button type="button" class="scm-acta-button scm-acta-secondary" data-step="-1">Volver</button>' : "") +
        (index < list.length - 1 ? '<button type="button" class="scm-acta-button scm-acta-secondary" data-goto-end>Ir al final</button><button type="button" class="scm-acta-button" data-step="1">Siguiente</button>' : '<button type="button" class="scm-acta-button" data-save>' + (config.editing ? "Guardar cambios" : "Guardar inventario") + "</button>") +
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

    function go(id) {
      if (voice.mode !== "recording") voiceClose();
      paneId = id;
      render();
      var top = root.querySelector("[data-pane-nav]");
      if (top && top.scrollIntoView) top.scrollIntoView({ block: "start" });
    }

    // Validación -----------------------------------------------------------
    function validate() {
      var errors = [];
      function need(pane, ok, text) {
        if (!ok) errors.push({ pane: pane, text: text });
      }
      need("datos", d("tipo_inventario"), "el tipo de inventario");
      need("datos", d("categoria_inventario"), "la categoría");
      ["contrato", "inmueble", "direccion", "arrendatario", "correo_arrendatario", "celular_arrendatario"].forEach(function (key) {
        need("datos", String(d(key)).trim(), key.replace(/_/g, " "));
      });
      if (!config.editing) need("datos", /^https?:\/\//i.test(String(d("registro_fotografico"))), "el enlace del registro fotográfico");
      ["tiene_parqueadero", "tiene_deposito", "tiene_delegado"].forEach(function (key) {
        need("datos", d(key), "responder " + key.replace(/_/g, " "));
      });
      if (isYes("tiene_parqueadero")) ["tipo_parqueadero", "parqueaderos", "numero_parqueadero"].forEach(function (key) { need("datos", String(d(key)).trim(), key.replace(/_/g, " ")); });
      if (isYes("tiene_deposito")) need("datos", String(d("depositos")).trim(), "los depósitos");
      if (isYes("tiene_delegado")) ["delegado", "correo_delegado", "celular_delegado"].forEach(function (key) { need("datos", String(d(key)).trim(), key.replace(/_/g, " ")); });
      need("linderos", d("fecha_linderos"), "la fecha de linderos");
      var main = state.boundaries.inmueble_lin[0] || {};
      need("linderos", Object.keys(main).some(function (k) { return String(main[k] || "").trim(); }), "los linderos del inmueble");
      var total = visibleSections().reduce(function (sum, s) { return sum + state.sections[s.key].rows.filter(function (r) { return String(r.d || "").trim(); }).length; }, 0);
      need("datos", total > 0, "al menos un elemento en el inventario");
      var names = { Energia: "luz", Agua: "agua", Gas: "gas" };
      d("servicios").forEach(function (service) { if (names[service]) need("cierre", String(d(names[service])).trim(), "la póliza de " + names[service]); });
      return errors;
    }

    function submit() {
      var errors = validate();
      if (errors.length) {
        go(errors[0].pane);
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
      return { d: name || "", c: suggestion && suggestion.quantities[name] ? suggestion.quantities[name] : "1", m: suggestion && suggestion.materials[name] && suggestion.materials[name][0] ? suggestion.materials[name][0] : "", e: "Bueno", o: "Ninguna" };
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
      } else {
        return;
      }
      changed();
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
      } else if (target.hasAttribute("data-rerender")) {
        state.data[target.dataset.b] = target.value;
        render();
        changed();
      } else if (target.matches("[data-copy-from]") && target.value) {
        var section = currentSection();
        state.sections[target.value].rows.forEach(function (row) { state.sections[section.key].rows.push(Object.assign({}, row)); });
        render();
        changed();
      } else if (target.matches("[data-extra-section]") && target.value) {
        extraSections.push(target.value);
        go("s:" + target.value);
        changed();
      }
    });

    form.addEventListener("click", function (event) {
      var button = event.target.closest("button");
      if (!button || !form.contains(button)) return;
      if (button.dataset.pane) { go(button.dataset.pane); return; }
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
      if (button.hasAttribute("data-voice-accept")) {
        var picked = voice.rows.filter(function (row, position) {
          var box = root.querySelector('[data-voice-row="' + position + '"]');
          return box && box.checked;
        });
        var targetParts = voice.target.split(":");
        var targetRows = targetParts[0] === "sections" ? state.sections[targetParts[1]].rows : state.furniture[targetParts[1]];
        picked.forEach(function (row) { targetRows.push(row); });
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
        state.furniture[button.dataset.furnitureAdd].push({ d: "", c: "1", m: "", e: "Bueno", o: "Ninguna" });
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
        else rows.splice(index, 1);
        render();
        changed();
      }
    });

    form.addEventListener("submit", function (event) { event.preventDefault(); });
    form.addEventListener("keydown", function (event) {
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
