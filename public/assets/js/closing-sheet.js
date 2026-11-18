// Hoja de cierre de negocio (hoja-cierre.php): campos condicionales, repetidores, porcentajes de los copropietarios,
// pasos y guardado. Lo que esté oculto se deshabilita, así no se envía ni se valida (igual que en el servidor).
(function () {
  "use strict";
  const form = document.querySelector("[data-cs-form]");
  if (!form) return;
  const errorBox = form.querySelector("[data-cs-error]");

  const valuesOf = (name) => {
    const inputs = Array.from(form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`));
    return inputs
      .filter((input) => !input.disabled && (input.type !== "checkbox" || input.checked))
      .map((input) => String(input.value).trim())
      .filter((value) => value !== "");
  };
  const matches = (when) => when.every(([field, accepted]) => valuesOf(field).some((value) => accepted.includes(value)));

  function syncConditions() {
    // Varias pasadas: un campo oculto puede ocultar a otros que dependen de él.
    for (let pass = 0; pass < 3; pass += 1) {
      form.querySelectorAll("[data-cs-when]").forEach((node) => {
        let when = [];
        try {
          when = JSON.parse(node.dataset.csWhen || "[]");
        } catch (error) {
          when = [];
        }
        const show = matches(when);
        node.hidden = !show;
        node.querySelectorAll("input, select, textarea").forEach((input) => {
          if (!input.closest("[data-cs-template]") && input.closest("[data-cs-when]") === node) input.disabled = !show || hiddenByParent(input);
        });
      });
    }
    syncOthers();
  }
  const hiddenByParent = (input) => {
    for (let node = input.parentElement; node && node !== form; node = node.parentElement) {
      if (node.hasAttribute("data-cs-when") && node.hidden) return true;
    }
    return false;
  };

  // Repetidores (mascotas, vehículos, personas).
  form.addEventListener("click", (event) => {
    const add = event.target.closest("[data-cs-add]");
    if (add) {
      const box = add.closest("[data-cs-repeat]");
      const rows = box.querySelector("[data-cs-rows]");
      const index = Date.now().toString(36) + rows.children.length;
      const html = box.querySelector("[data-cs-template]").innerHTML.replace(/__i__/g, index);
      rows.insertAdjacentHTML("beforeend", html);
      return;
    }
    const remove = event.target.closest("[data-cs-remove]");
    if (remove) remove.closest("[data-cs-row]").remove();
  });

  // Listas con «Otro»: al elegirlo aparece el campo para escribir el valor (obligatorio mientras esté visible). Va después
  // de syncConditions, que habilita todo lo de una sección visible.
  function syncOthers() {
    form.querySelectorAll("select[data-cs-other]").forEach((select) => {
      const typed = select.parentElement.querySelector(".cs-other");
      if (!typed || typed.closest("[data-cs-template]")) return;
      const show = !select.disabled && select.value !== "" && select.value === select.dataset.csOther;
      typed.hidden = !show;
      typed.disabled = !show;
      typed.toggleAttribute("data-cs-required", show);
      if (!show) typed.classList.remove("is-invalid");
    });
  }
  form.addEventListener("change", (event) => {
    if (!event.target.matches("select[data-cs-other]")) return;
    syncConditions();
    const typed = event.target.parentElement.querySelector(".cs-other");
    if (typed && !typed.hidden) typed.focus();
  });

  // Porcentajes de participación.
  const percents = () => Array.from(form.querySelectorAll("[data-cs-percent]"));
  const parse = (text) => {
    const clean = String(text || "").replace(/[%\s]/g, "").replace(",", ".");
    return clean === "" ? null : Number(clean);
  };
  const format = (value) => String(Math.round(value * 100) / 100).replace(".", ",");
  function syncPercent() {
    const label = form.querySelector("[data-cs-percent-sum]");
    if (!label) return;
    const values = percents().map((input) => parse(input.value));
    const sum = values.reduce((total, value) => total + (value || 0), 0);
    const ok = values.every((value) => value !== null && value > 0) && Math.abs(sum - 100) < 0.001;
    label.textContent = "Suma: " + format(sum) + " %" + (ok ? " ✓" : " (debe sumar 100 %)");
    label.className = ok ? "cs-ok" : "cs-warn";
  }
  form.addEventListener("click", (event) => {
    if (!event.target.closest("[data-cs-split]")) return;
    const inputs = percents();
    const base = Math.floor(10000 / inputs.length) / 100;
    inputs.forEach((input, i) => {
      input.value = format(i === 0 ? Math.round((100 - base * (inputs.length - 1)) * 100) / 100 : base);
    });
    syncPercent();
  });

  // Listas largas (aliados PPH, bancos): un buscador encima que filtra las opciones.
  form.querySelectorAll("select.cs-input").forEach((select) => {
    if (select.options.length < 40) return;
    const search = document.createElement("input");
    search.type = "search";
    search.className = "cs-input cs-search";
    search.placeholder = "Buscar…";
    search.setAttribute("aria-label", "Buscar en la lista");
    search.addEventListener("input", () => {
      const term = search.value.trim().toLowerCase();
      Array.from(select.options).forEach((option) => {
        option.hidden = term !== "" && option.value !== "" && !option.text.toLowerCase().includes(term);
      });
    });
    select.before(search);
  });

  form.addEventListener("input", syncPercent);
  form.addEventListener("change", syncConditions);
  syncConditions();
  syncPercent();

  // Borrador en el navegador: al recuperar, primero se recrean las filas de los repetidores que tenía.
  const draft = window.SCMFormDraft
    ? window.SCMFormDraft.attach(form, form.dataset.csDraft || "", {
      beforeRestore(fields) {
        form.querySelectorAll("[data-cs-repeat]").forEach((box) => {
          const name = box.dataset.csRepeat;
          const indexes = new Set();
          Object.keys(fields).forEach((key) => {
            const match = key.slice(0, name.length + 1) === name + "[" ? key.slice(name.length + 1).match(/^([^\]]+)\]\[/) : null;
            if (match) indexes.add(match[1]);
          });
          const rows = box.querySelector("[data-cs-rows]");
          rows.innerHTML = "";
          const template = box.querySelector("[data-cs-template]").innerHTML;
          indexes.forEach((index) => rows.insertAdjacentHTML("beforeend", template.replace(/__i__/g, index)));
        });
      },
      afterRestore() {
        syncConditions();
        syncConditions();
        syncPercent();
        syncPolicies();
      },
    })
    : null;

  // ---------------------------------------------------------------- Lista de chequeo: sugerencias y políticas
  // Los estados se sugieren con el mandato y lo escrito en el paso 1 (solo en ítems vacíos o que siguen sugeridos), y un
  // ítem que incumple una política pide justificación: al marcarlo se abre la advertencia con la política.
  const checklistData = (() => {
    const node = form.querySelector("[data-cs-checklist-data]");
    try {
      return node ? JSON.parse(node.textContent || "{}") : {};
    } catch (error) {
      return {};
    }
  })();
  const policyRules = checklistData.policies || {};
  const facts = checklistData.facts || {};
  const itemBox = (item) => form.querySelector(`[data-cs-item="${CSS.escape(item)}"]`);
  const stateSelect = (item) => form.querySelector(`.cs-check-item select[name="${CSS.escape(item)}"]`);
  const fieldValue = (name) => valuesOf(name)[0] || "";
  const COPROPIEDAD = ["estado_nombre_administrador", "estado_correo_copropiedad", "estado_celular_copropiedad", "estado_reglamento_copropiedad", "estado_manual_copropiedad",
    "estado_rut_copropiedad", "estado_certificacion_copropiedad", "estado_cuota_copropiedad", "estado_paz_salvo_copropiedad", "estado_encargado_copropiedad"];

  function suggestions() {
    const out = {};
    const add = (item, state, why) => (out[item] = [state, why]);
    const services = valuesOf("servicios_publicos");
    const accounts = facts.accounts || {};
    [["estado_servicio_agua", "Agua", "agua"], ["estado_servicio_energia", "Energia", "luz"], ["estado_servicio_gas", "Gas", "gas"]].forEach(([item, service, account]) => {
      if (!services.includes(service)) add(item, "No aplica", "el servicio no está marcado en la hoja");
      else if (accounts[service] || fieldValue(account)) add(item, "Cumple", "el mandato o la hoja tiene el número del servicio");
    });
    add("estado_servicio_internet", facts.internet ? "Cumple" : "No aplica", facts.internet ? "el mandato tiene internet" : "el mandato no tiene internet");
    add("estado_servicio_telefonia", facts.telefono ? "Cumple" : "No aplica", facts.telefono ? "el mandato tiene teléfono fijo" : "el mandato no tiene teléfono fijo");
    const ph = fieldValue("propiedad_horizontal");
    if (ph === "No") {
      COPROPIEDAD.forEach((item) => add(item, "No aplica", "no pertenece a propiedad horizontal"));
    } else if (ph === "Si") {
      [["estado_nombre_administrador", "administrador"], ["estado_correo_copropiedad", "correo_copropiedad"], ["estado_celular_copropiedad", "celular_copropiedad"]].forEach(([item, field]) => {
        if (fieldValue(field)) add(item, "Cumple", "está en la sección Copropiedad");
      });
    }
    const ownerTypes = [fieldValue("tipo_persona_propietario")].concat(
      Array.from(form.querySelectorAll('[name^="copropietario["][name$="[tipo_persona]"]')).filter((input) => !input.disabled).map((input) => String(input.value || "").trim())
    );
    if (ownerTypes.every((type) => type === "Natural")) {
      const why = ownerTypes.length > 1 ? "todos los propietarios son persona natural" : "el propietario es persona natural";
      ["estado_certificado_empresa_pro", "estado_certificado_camara_pro"].forEach((item) => add(item, "No aplica", why));
    }
    if (fieldValue("tipo_persona_arrendatario") === "Natural") {
      ["estado_certificado_empresa_arre", "estado_certificado_camara_arre"].forEach((item) => add(item, "No aplica", "el arrendatario es persona natural"));
    }
    const grace = parseFloat(fieldValue("dias_gracia").replace(",", ".")) || 0;
    add("estado_dias_gracia", grace > 0 ? "Cumple" : "No aplica", grace > 0 ? "la hoja tiene días de gracia" : "la hoja no tiene días de gracia");
    if (facts.amparo === "Si") add("estado_amparo_integral", "Cumple", "el mandato incluye la garantía de servicios públicos");
    if (facts.amparo === "No") add("estado_amparo_integral", "No cumple", "el mandato no incluye la garantía de servicios públicos");
    if (facts.bank) add("estado_bancaria_pro", "Cumple", "el mandato tiene la cuenta bancaria del propietario");
    if (facts.cobertura) add("estado_cobertura", facts.cobertura >= 36 ? "Cumple" : "No cumple", `el mandato dice ${facts.cobertura} meses de cobertura`);
    const months = parseInt(fieldValue("meses_contrato"), 10);
    if (months > 0) add("estado_termino_contrato", months >= 12 ? "Cumple" : "No cumple", `la hoja dice ${months} meses (el mínimo es 12)`);
    if (facts.study) add("estado_asegurable", "Cumple", "la hoja tiene el estudio de la aseguradora");
    return out;
  }

  function showSuggestion(item, text) {
    const tag = itemBox(item)?.querySelector("[data-cs-suggest]");
    if (!tag) return;
    tag.textContent = text ? "Sugerido: " + text + "." : "";
    tag.hidden = !text;
  }

  function applySuggestions() {
    const list = suggestions();
    Object.keys(list).forEach((item) => {
      const select = stateSelect(item);
      const [state, why] = list[item];
      // Solo ítems vacíos o que siguen con una sugerencia anterior; lo que eligió el funcionario no se toca.
      if (!select || select.disabled || (select.value !== "" && select.dataset.csAuto !== "1")) return;
      if (!Array.from(select.options).some((option) => option.value === state)) return;
      select.value = state;
      select.dataset.csAuto = "1";
      select.dataset.prev = state;
      showSuggestion(item, why);
      syncPolicy(item);
    });
    syncFiles();
    // Sugerencias que ya no aplican (cambió el paso 1) se limpian si nadie las tocó.
    form.querySelectorAll('.cs-check-item select[data-cs-auto="1"]').forEach((select) => {
      if (list[select.name]) return;
      select.value = "";
      delete select.dataset.csAuto;
      showSuggestion(select.name, "");
      syncPolicy(select.name);
    });
    checklistChanged();
  }

  // Los estados cambiados por código no disparan "change": se avisa a los documentos (closing-documents.js) para que
  // marquen los ítems en «Cumple» sin archivo y actualicen la barra de documentos.
  function checklistChanged() {
    form.dispatchEvent(new CustomEvent("cs:checklist-change"));
  }

  function syncPolicy(item) {
    const rule = policyRules[item];
    const box = itemBox(item);
    const panel = box && box.querySelector("[data-cs-policy]");
    if (!rule || !panel) return;
    const select = stateSelect(item);
    const on = !!select && !select.disabled && rule.states.includes(select.value);
    panel.hidden = !on;
    box.classList.toggle("is-breach", on);
    const text = panel.querySelector("[data-cs-justification]");
    // Una hoja anterior a la regla que conserva ese estado no exige justificación.
    text.toggleAttribute("data-cs-required", on && panel.dataset.csLegacy !== select.value);
  }
  function syncPolicies() {
    Object.keys(policyRules).forEach(syncPolicy);
    syncFiles();
  }

  // Archivo obligatorio: un documento en «Cumple» sin archivo pide decir por qué no se adjunta ahora.
  function syncFile(box) {
    const panel = box.querySelector("[data-cs-file-why]");
    const select = box.querySelector("select");
    if (!panel || !select) return;
    const hasFile = box.querySelector("[data-cd-doc]") !== null;
    const on = !select.disabled && select.value === "Cumple" && !hasFile;
    panel.hidden = !on;
    box.classList.toggle("is-file-missing", on);
    panel.querySelector("[data-cs-file-reason]").toggleAttribute("data-cs-required", on && panel.dataset.csLegacy !== select.value);
  }
  function syncFiles() {
    form.querySelectorAll("[data-cs-file-required]").forEach(syncFile);
  }
  // Al subir o quitar archivos (closing-documents.js).
  form.addEventListener("cd:documents-change", syncFiles);

  // Ventana de advertencia (en la misma página).
  const policyDialog = form.querySelector("[data-cs-policy-dialog]");
  let pending = null;
  function openDialog(dialog) {
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }
  function closeDialog(dialog) {
    if (typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }
  function openPolicy(item, previous) {
    const rule = policyRules[item];
    const box = itemBox(item);
    if (!rule || !box || !policyDialog) return;
    pending = { item, previous };
    const select = stateSelect(item);
    policyDialog.querySelector("[data-cs-policy-item]").textContent = `«${box.querySelector("p").textContent.trim()}» queda en «${select.value}».`;
    const list = policyDialog.querySelector("[data-cs-policy-list]");
    list.replaceChildren();
    if (rule.note) {
      const note = document.createElement("p");
      note.className = "cs-policy-note";
      note.textContent = rule.note;
      list.appendChild(note);
    }
    rule.policies.forEach((policy, index) => {
      const details = document.createElement("details");
      details.open = index === 0;
      const summary = document.createElement("summary");
      summary.textContent = `Política #${policy.id}: ${policy.title}`;
      const text = document.createElement("p");
      text.textContent = policy.text || "Sin texto en la tabla de políticas.";
      details.append(summary, text);
      list.appendChild(details);
    });
    const reason = policyDialog.querySelector("[data-cs-policy-reason]");
    reason.value = box.querySelector("[data-cs-justification]")?.value || "";
    policyDialog.querySelector("[data-cs-policy-error]").hidden = true;
    openDialog(policyDialog);
    reason.focus();
  }
  function undoPolicy() {
    if (!pending) return;
    const select = stateSelect(pending.item);
    if (select) {
      select.value = pending.previous;
      select.dataset.prev = pending.previous;
      syncPolicy(pending.item);
      checklistChanged();
      select.focus();
    }
    pending = null;
    closeDialog(policyDialog);
  }
  if (policyDialog) {
    policyDialog.addEventListener("click", (event) => {
      if (event.target.closest("[data-cs-policy-undo]")) undoPolicy();
      if (!event.target.closest("[data-cs-policy-accept]") || !pending) return;
      const reason = policyDialog.querySelector("[data-cs-policy-reason]").value.trim();
      const error = policyDialog.querySelector("[data-cs-policy-error]");
      if (reason.length < 10) {
        error.textContent = "Escribe la justificación (mínimo 10 caracteres). Si no hay razón, corrige la selección.";
        error.hidden = false;
        return;
      }
      const target = itemBox(pending.item)?.querySelector("[data-cs-justification]");
      if (target) {
        target.value = reason;
        target.classList.remove("is-invalid");
        target.dispatchEvent(new Event("input", { bubbles: true }));
      }
      pending = null;
      closeDialog(policyDialog);
    });
    // Escape equivale a corregir la selección: no queda un incumplimiento sin haberlo aceptado.
    policyDialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      undoPolicy();
    });
  }

  // Leer las políticas del ítem (enlaces de la guía y «Ver política» del recuadro de justificación).
  const ruleDialog = form.querySelector("[data-cs-rule-dialog]");
  function openRule(item, focusId) {
    const rule = policyRules[item];
    const box = itemBox(item);
    if (!rule || !box || !ruleDialog) return;
    ruleDialog.querySelector("[data-cs-rule-title]").textContent = box.querySelector("p").textContent.trim();
    const list = ruleDialog.querySelector("[data-cs-rule-list]");
    list.replaceChildren();
    if (rule.note) {
      const note = document.createElement("p");
      note.className = "cs-policy-note";
      note.textContent = `Regla: ${rule.note}`;
      list.appendChild(note);
    }
    rule.policies.forEach((policy, index) => {
      const details = document.createElement("details");
      details.open = focusId ? String(policy.id) === String(focusId) : index === 0;
      const summary = document.createElement("summary");
      summary.textContent = `Política #${policy.id}: ${policy.title}`;
      const text = document.createElement("p");
      text.textContent = policy.text || "Sin texto en la tabla de políticas.";
      details.append(summary, text);
      list.appendChild(details);
    });
    openDialog(ruleDialog);
    ruleDialog.querySelector("[data-cs-rule-close]").focus();
  }
  form.addEventListener("click", (event) => {
    const link = event.target.closest("[data-cs-rule-view], [data-cs-policy-open]");
    if (link) {
      const box = link.closest("[data-cs-item]");
      if (box) openRule(box.getAttribute("data-cs-item"), link.getAttribute("data-cs-rule-view"));
      return;
    }
    if (ruleDialog && event.target.closest("[data-cs-rule-close]")) closeDialog(ruleDialog);
  });

  form.addEventListener("focusin", (event) => {
    const select = event.target.closest(".cs-check-item select");
    if (select) select.dataset.prev = select.value;
  });
  form.addEventListener("change", (event) => {
    const select = event.target.closest(".cs-check-item select");
    if (!select) return;
    delete select.dataset.csAuto;
    showSuggestion(select.name, "");
    syncPolicy(select.name);
    const fileBox = select.closest("[data-cs-file-required]");
    if (fileBox) syncFile(fileBox);
    const rule = policyRules[select.name];
    if (rule && rule.states.includes(select.value) && select.dataset.prev !== select.value) openPolicy(select.name, select.dataset.prev || "");
    select.dataset.prev = select.value;
  });
  syncPolicies();

  // Validación de un paso: requeridos visibles y porcentajes.
  function check(step) {
    const missing = [];
    step.querySelectorAll("[data-cs-required]").forEach((input) => {
      input.classList.remove("is-invalid");
      if (input.disabled || input.readOnly) return;
      if (String(input.value || "").trim() === "") {
        input.classList.add("is-invalid");
        const field = input.closest("[data-cs-field], .cs-check-item");
        const label = field ? (field.querySelector(".cs-label, p")?.textContent || "").replace("*", "").trim() : input.name;
        const person = input.closest(".cs-person");
        missing.push((person && person.dataset.csPerson ? person.dataset.csPerson + ": " : "") + label);
      }
    });
    if (step.contains(form.querySelector("[data-cs-percent]")) && percents().length > 1) {
      const values = percents().map((input) => parse(input.value));
      const sum = values.reduce((total, value) => total + (value || 0), 0);
      if (!values.every((value) => value !== null && value > 0) || Math.abs(sum - 100) >= 0.001) {
        missing.push("Porcentajes de participación (deben sumar 100 %)");
      }
    }
    if (missing.length) {
      showError("Revisa estos campos:\n• " + missing.slice(0, 10).join("\n• ") + (missing.length > 10 ? "\n…" : ""));
      step.querySelector(".is-invalid")?.scrollIntoView({ behavior: "smooth", block: "center" });
      return false;
    }
    hideError();
    return true;
  }
  const showError = (text) => {
    errorBox.textContent = text;
    errorBox.hidden = false;
  };
  const hideError = () => {
    errorBox.hidden = true;
  };

  function goTo(number) {
    form.querySelectorAll("[data-cs-step]").forEach((step) => (step.hidden = step.dataset.csStep !== String(number)));
    form.querySelectorAll("[data-cs-step-tab]").forEach((tab) => tab.classList.toggle("is-active", tab.dataset.csStepTab === String(number)));
    syncConditions();
    // Al llegar a la lista de chequeo se sugieren los estados con lo escrito en el paso 1.
    if (String(number) === "2") applySuggestions();
    syncPolicies();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  form.querySelector("[data-cs-next]")?.addEventListener("click", () => {
    if (check(form.querySelector('[data-cs-step="1"]'))) goTo(2);
  });
  form.querySelector("[data-cs-prev]")?.addEventListener("click", () => goTo(1));

  let sending = false;
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (sending) return;
    const steps = Array.from(form.querySelectorAll("[data-cs-step]"));
    for (const step of steps) {
      if (!check(step)) {
        goTo(step.dataset.csStep);
        check(step);
        return;
      }
    }
    sending = true;
    const button = form.querySelector('[data-cs-step]:not([hidden]) button[type="submit"]');
    if (button) button.disabled = true;
    try {
      const response = await fetch(form.dataset.csApi, { method: "POST", body: new FormData(form), credentials: "same-origin" });
      if (response.status === 401) {
        showError("Tu sesión venció. Lo escrito quedó como borrador en este navegador: inicia sesión y recupéralo. La página se recargará.");
        if (draft) draft.keep();
        setTimeout(() => window.location.reload(), 2500);
        return;
      }
      const json = await response.json().catch(() => null);
      if (!json || !json.success) {
        showError((json && json.data && json.data.message) || "No se pudo guardar la hoja de cierre. Intenta de nuevo.");
        return;
      }
      done(json.data);
    } catch (error) {
      showError("No se pudo conectar. Revisa tu conexión e intenta de nuevo.");
    } finally {
      sending = false;
      if (button) button.disabled = false;
    }
  });

  function done(data) {
    if (draft) draft.markSaved();
    // Dentro de la ventana del panel: avisa para recargar el listado de hojas de cierre.
    if (window.parent !== window) window.parent.postMessage({ type: "scm-closing-sheet-saved" }, window.location.origin);
    const card = document.querySelector("[data-cs-done]");
    card.querySelector("[data-cs-done-title]").textContent = data.title || "Hoja de cierre guardada";
    card.querySelector("[data-cs-done-text]").textContent = data.message || "";
    card.querySelector("[data-cs-done-view]").href = data.url;
    card.querySelector("[data-cs-done-edit]").href = data.edit_url;
    form.hidden = true;
    card.hidden = false;
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
})();
