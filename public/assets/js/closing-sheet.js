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
      },
    })
    : null;

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
