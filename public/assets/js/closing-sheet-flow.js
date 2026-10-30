/* Páginas del flujo de la hoja de cierre: revisión de servicios (revision-servicios-cierre.php) y respuesta
   (hoja-cierre-ver.php). Los formularios se envían a la misma página, que responde JSON. */
(function () {
  "use strict";

  function setService(fieldset) {
    var toggle = fieldset.querySelector("[data-csf-toggle]");
    var on = !toggle || toggle.checked;
    fieldset.toggleAttribute("data-off", !on);
    fieldset.querySelectorAll("[data-csf-fields] input, [data-csf-fields] select").forEach(function (input) {
      input.disabled = !on;
    });
  }

  function validate(form) {
    var missing = [];
    form.querySelectorAll("[data-csf-required]").forEach(function (input) {
      input.classList.remove("is-invalid");
      if (input.disabled || String(input.value || "").trim() !== "") return;
      input.classList.add("is-invalid");
      var label = form.querySelector('label[for="' + input.id + '"]');
      var service = input.closest("[data-csf-service]");
      missing.push((service ? service.querySelector("legend").textContent + ": " : "") + (label ? label.textContent.replace("*", "").trim() : input.name));
    });
    if (form.querySelector("[data-csf-toggle]") && !form.querySelector("[data-csf-toggle]:checked")) {
      return "Marca al menos un servicio para revisar.";
    }
    return missing.length ? "Completa:\n- " + missing.join("\n- ") : "";
  }

  function showError(form, text) {
    var box = form.querySelector("[data-csf-error]");
    if (!box) return window.alert(text);
    box.textContent = text;
    box.hidden = !text;
    if (text) box.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function showDone(data) {
    var done = document.querySelector("[data-csf-done]");
    if (!done) return;
    done.querySelector("[data-csf-done-title]").textContent = data.title || "Listo";
    done.querySelector("[data-csf-done-text]").textContent = data.message || "";
    var links = done.querySelector("[data-csf-done-links]");
    links.innerHTML = "";
    (data.links || []).forEach(function (link) {
      var a = document.createElement("a");
      a.className = link.primary ? "cs-primary" : "cs-secondary";
      a.href = link.url;
      a.textContent = link.label;
      links.appendChild(a);
    });
    if (data.reload) {
      var again = document.createElement("a");
      again.className = "cs-primary";
      again.href = window.location.href.replace(/#.*$/, "");
      again.textContent = "Ver hoja actualizada";
      links.appendChild(again);
    }
    document.querySelectorAll("[data-csf-form], .csf-sheet").forEach(function (node) { node.hidden = true; });
    done.hidden = false;
    done.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function submit(form, submitter) {
    var error = validate(form);
    if (error) return showError(form, error);
    var body = new FormData(form);
    if (submitter && submitter.name) body.set(submitter.name, submitter.value);
    if (form.hasAttribute("data-csf-confirm")) {
      var state = body.get("estado");
      if (state === "No aprobada" && !String(body.get("observacion") || "").trim()) {
        return showError(form, "Escribe por qué no se aprueba: lo recibe quien hizo la hoja para corregirla.");
      }
      if (!window.confirm("¿Confirmas que la hoja queda " + String(state).toLowerCase() + "? Se avisará a quien la hizo.")) return;
    }
    showError(form, "");
    var buttons = form.querySelectorAll("button[type=submit]");
    buttons.forEach(function (button) { button.disabled = true; });
    fetch(window.location.href.replace(/#.*$/, ""), { method: "POST", body: body, credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (response) {
        return response.json().catch(function () { throw new Error("Respuesta inesperada del servidor (" + response.status + ")."); });
      })
      .then(function (json) {
        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo guardar.");
        var draft = draftOf(form);
        if (draft) draft.markSaved();
        // Dentro de la ventana del panel: avisa para recargar el listado de hojas de cierre.
        if (window.parent !== window) window.parent.postMessage({ type: "scm-closing-sheet-saved" }, window.location.origin);
        showDone(json.data || {});
      })
      .catch(function (err) {
        showError(form, err.message || "No se pudo guardar.");
      })
      .finally(function () {
        buttons.forEach(function (button) { button.disabled = false; });
      });
  }

  var drafts = [];
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-csf-service]").forEach(setService);
    // Borrador en el navegador de la revisión de servicios.
    document.querySelectorAll("[data-csf-draft]").forEach(function (form) {
      if (!window.SCMFormDraft) return;
      drafts.push({ form: form, draft: window.SCMFormDraft.attach(form, form.getAttribute("data-csf-draft"), {
        afterRestore: function () { form.querySelectorAll("[data-csf-service]").forEach(setService); },
      }) });
    });
  });
  function draftOf(form) {
    for (var i = 0; i < drafts.length; i += 1) if (drafts[i].form === form) return drafts[i].draft;
    return null;
  }

  document.addEventListener("change", function (event) {
    var toggle = event.target.closest("[data-csf-toggle]");
    if (toggle) setService(toggle.closest("[data-csf-service]"));
    var status = event.target.closest("[data-csf-status]");
    if (status && status.value === "Al dia") {
      var value = status.closest("[data-csf-fields]").querySelector('input[name^="valor_"]');
      if (value && !value.value.trim()) value.value = "0";
    }
  });

  document.addEventListener("submit", function (event) {
    var form = event.target.closest("[data-csf-form]");
    if (!form) return;
    event.preventDefault();
    submit(form, event.submitter || null);
  });

  document.addEventListener("click", function (event) {
    if (event.target.closest("[data-csf-print]")) {
      window.print();
      return;
    }
    var dialog = document.querySelector("[data-csf-dialog]");
    var preview = event.target.closest("[data-csf-preview]");
    if (preview && dialog) {
      dialog.querySelector("iframe").src = preview.getAttribute("data-csf-preview");
      if (dialog.showModal) dialog.showModal(); else dialog.setAttribute("open", "");
      return;
    }
    if (event.target.closest("[data-csf-dialog-close]") && dialog) {
      dialog.querySelector("iframe").src = "about:blank";
      if (dialog.close) dialog.close(); else dialog.removeAttribute("open");
    }
  });
})();
