// Borrador local de un formulario largo (hoja de cierre y revisión de servicios): guarda lo escrito en el navegador
// mientras se llena, ofrece recuperarlo al volver y lo borra al guardar con éxito. No se guardan campos ocultos
// (ids, nonce, firma del enlace) ni de solo lectura. Uso: SCMFormDraft.attach(form, clave, { beforeRestore, afterRestore }).
(function () {
  "use strict";
  var PREFIX = "scm:borrador:";
  var DAYS = 7;

  function storage() {
    try {
      var test = PREFIX + "prueba";
      window.localStorage.setItem(test, "1");
      window.localStorage.removeItem(test);
      return window.localStorage;
    } catch (error) {
      return null;
    }
  }

  function controls(form) {
    return Array.prototype.filter.call(form.elements, function (el) {
      return el.name && !el.readOnly && !el.closest("template") && ["hidden", "submit", "button", "file", "password", "search"].indexOf(el.type) === -1;
    });
  }

  // { nombre: [valores en orden] }; en casillas, los valores marcados.
  function snapshot(form) {
    var fields = {};
    controls(form).forEach(function (el) {
      var list = fields[el.name] || (fields[el.name] = []);
      if (el.type === "checkbox" || el.type === "radio") {
        if (el.checked) list.push(el.value);
      } else {
        list.push(el.value);
      }
    });
    return fields;
  }

  function apply(form, fields) {
    var seen = {};
    controls(form).forEach(function (el) {
      if (!Object.prototype.hasOwnProperty.call(fields, el.name)) return;
      var values = fields[el.name];
      if (el.type === "checkbox" || el.type === "radio") {
        el.checked = values.indexOf(el.value) !== -1;
        return;
      }
      var index = seen[el.name] || 0;
      seen[el.name] = index + 1;
      if (index < values.length) el.value = values[index];
    });
  }

  function when(timestamp) {
    try {
      return new Date(timestamp).toLocaleString("es-CO", { day: "2-digit", month: "2-digit", hour: "numeric", minute: "2-digit" });
    } catch (error) {
      return "";
    }
  }

  function attach(form, key, options) {
    options = options || {};
    var store = storage();
    var fullKey = PREFIX + key;
    var initial = JSON.stringify(snapshot(form));
    var dirty = false;
    var paused = false;
    var leaving = false;
    var timer = null;
    var noop = { clear: function () {}, markSaved: function () {}, keep: function () {} };
    if (!form || !key) return noop;

    function read() {
      if (!store) return null;
      try {
        var data = JSON.parse(store.getItem(fullKey) || "null");
        if (!data || !data.fields || !data.time) return null;
        if (Date.now() - data.time > DAYS * 86400000) {
          store.removeItem(fullKey);
          return null;
        }
        return data;
      } catch (error) {
        return null;
      }
    }

    function save() {
      timer = null;
      var current = JSON.stringify(snapshot(form));
      dirty = current !== initial;
      if (!store || paused) return;
      try {
        if (dirty) {
          store.setItem(fullKey, JSON.stringify({ time: Date.now(), fields: JSON.parse(current) }));
        } else {
          store.removeItem(fullKey);
        }
      } catch (error) {
        // Sin espacio o bloqueado: el formulario sigue funcionando sin borrador.
      }
    }

    function schedule() {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(save, 700);
    }

    function clear() {
      dirty = false;
      if (timer) window.clearTimeout(timer);
      timer = null;
      if (!store) return;
      try {
        store.removeItem(fullKey);
      } catch (error) {
        // nada que limpiar
      }
    }

    var draft = read();
    if (draft && JSON.stringify(draft.fields) !== initial) {
      // Mientras no se elija, lo nuevo no reemplaza el borrador anterior.
      paused = true;
      var banner = document.createElement("div");
      banner.className = "scm-draft-banner";
      banner.setAttribute("role", "status");
      banner.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">history</span>'
        + '<p><strong>' + ("Hay un borrador sin guardar" + (when(draft.time) ? " del " + when(draft.time) : "")).replace(/\.$/, "") + ".</strong> "
        + "Recupéralo para seguir donde ibas. Mientras no elijas, lo que escribas ahora no se guarda como borrador.</p>"
        + '<div><button type="button" class="cs-primary" data-draft-restore>Recuperar</button>'
        + '<button type="button" class="cs-secondary" data-draft-discard>Descartar</button></div>';
      form.insertBefore(banner, form.firstElementChild);
      banner.addEventListener("click", function (event) {
        if (event.target.closest("[data-draft-restore]")) {
          if (options.beforeRestore) options.beforeRestore(draft.fields);
          apply(form, draft.fields);
          if (options.afterRestore) options.afterRestore(draft.fields);
        } else if (!event.target.closest("[data-draft-discard]")) {
          return;
        }
        banner.remove();
        paused = false;
        save();
      });
    } else if (draft) {
      clear();
    }

    form.addEventListener("input", schedule);
    form.addEventListener("change", schedule);
    // Agregar o quitar filas y repartir porcentajes no disparan "input".
    form.addEventListener("click", function () { window.setTimeout(schedule, 0); });
    window.addEventListener("beforeunload", function (event) {
      if (timer) save();
      if (!dirty || leaving) return;
      event.preventDefault();
      event.returnValue = "";
    });

    return {
      clear: clear,
      // Tras guardar en el servidor: lo actual es lo guardado.
      markSaved: function () {
        clear();
        initial = JSON.stringify(snapshot(form));
      },
      // Antes de recargar la página a propósito (sesión vencida): guarda ya y no pregunta al salir.
      keep: function () {
        if (timer) window.clearTimeout(timer);
        save();
        leaving = true;
      },
    };
  }

  window.SCMFormDraft = { attach: attach };
})();
