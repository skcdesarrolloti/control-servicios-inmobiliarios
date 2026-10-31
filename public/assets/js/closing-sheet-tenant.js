// Hoja de cierre nueva (hoja-cierre.php) › Arrendatario: buscarlo o crearlo en la misma hoja, sin la página intermedia
// de WordPress. El elegido llena los campos del arrendatario y el id_arrendatario oculto.
(function () {
  "use strict";
  const form = document.querySelector("[data-cs-form]");
  const picker = form && form.querySelector("[data-cs-tenant]");
  if (!picker) return;
  const dialog = document.querySelector("[data-cs-tenant-dialog]");
  const dialogForm = dialog && dialog.querySelector("[data-cs-tenant-form]");
  const idInput = form.querySelector('input[name="id_arrendatario"]');
  const query = picker.querySelector("[data-cs-tenant-q]");
  const results = picker.querySelector("[data-cs-tenant-results]");
  const api = form.dataset.csApi;
  const nonce = () => (form.querySelector('input[name="nonce"]') || {}).value || "";

  async function post(action, body) {
    body.append("action", action);
    body.append("nonce", nonce());
    const response = await fetch(api, { method: "POST", body, credentials: "same-origin" });
    if (response.status === 401) throw new Error("Tu sesión venció. Recarga la página e inicia sesión.");
    const json = await response.json().catch(() => null);
    if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar. Intenta de nuevo.");
    return json.data || {};
  }

  // Campo de la hoja => dato de la ficha del arrendatario.
  const MAP = {
    tipo_persona_arrendatario: "tipo_persona",
    tipo_documento_arrendatario: "tipo_documento",
    arrendatario: "nombre",
    documento_arrendatario: "documento",
    correo_arrendatario: "correo",
    celular_arrendatario: "celular",
    representante_arrendatario: "representante",
    documento_representante_arrendatario: "documento_representante",
  };
  function setField(name, value) {
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    if (!input) return;
    if (input.tagName === "SELECT" && value !== "" && !Array.from(input.options).some((option) => option.value === value)) {
      input.add(new Option(value, value));
    }
    input.value = value;
    input.classList.remove("is-invalid");
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  const card = picker.querySelector("[data-cs-tenant-current]");
  let timer = 0;
  let last = "";
  function showCurrent(tenant, note) {
    card.classList.toggle("is-empty", !tenant);
    card.classList.remove("is-missing");
    picker.querySelector("[data-cs-tenant-icon]").textContent = tenant ? "how_to_reg" : "person_search";
    picker.querySelector("[data-cs-tenant-name]").textContent = tenant ? tenant.nombre : "Sin arrendatario elegido";
    const detail = tenant ? [((tenant.tipo_documento || "") + " " + (tenant.documento || "")).trim(), tenant.celular].filter(Boolean).join(" · ") : "";
    picker.querySelector("[data-cs-tenant-detail]").textContent = note ? detail + (detail ? ". " : "") + note : detail;
  }

  function choose(tenant, note) {
    clearTimeout(timer);
    last = "";
    idInput.value = tenant.id;
    Object.keys(MAP).forEach((name) => setField(name, tenant[MAP[name]] || ""));
    showCurrent(tenant, note || "");
    results.hidden = true;
    results.innerHTML = "";
    query.value = "";
  }

  // Búsqueda mientras se escribe.
  let found = [];
  function render(items, term) {
    results.innerHTML = "";
    found = items;
    if (!items.length) {
      const empty = document.createElement("p");
      empty.className = "cs-tenant-empty";
      empty.textContent = "No hay arrendatarios con \"" + term + "\". ";
      const create = document.createElement("button");
      create.type = "button";
      create.className = "cs-link";
      create.dataset.csTenantNew = "";
      create.textContent = "Crear arrendatario";
      empty.appendChild(create);
      results.appendChild(empty);
    }
    items.forEach((item, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "cs-tenant-option";
      button.dataset.csTenantPick = String(index);
      const name = document.createElement("strong");
      name.textContent = item.nombre || "(sin nombre)";
      const detail = document.createElement("small");
      detail.textContent = [((item.tipo_documento || "") + " " + (item.documento || "")).trim(), item.celular, item.ciudad].filter(Boolean).join(" · ");
      button.append(name, detail);
      results.appendChild(button);
    });
    results.hidden = false;
  }
  query.addEventListener("input", () => {
    clearTimeout(timer);
    const term = query.value.trim();
    if (term.length < 2) {
      results.hidden = true;
      return;
    }
    timer = setTimeout(async () => {
      last = term;
      const body = new FormData();
      body.append("q", term);
      try {
        const data = await post(picker.dataset.csTenantSearchAction, body);
        if (last === term) render(data.items || [], term);
      } catch (error) {
        results.innerHTML = "";
        const p = document.createElement("p");
        p.className = "cs-tenant-empty is-error";
        p.textContent = error.message;
        results.appendChild(p);
        results.hidden = false;
      }
    }, 300);
  });
  query.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      if (found.length === 1) choose(found[0]);
    }
  });
  results.addEventListener("click", (event) => {
    const pick = event.target.closest("[data-cs-tenant-pick]");
    if (pick) choose(found[Number(pick.dataset.csTenantPick)]);
  });

  // Antes de pasar a la lista de chequeo: debe haber un arrendatario elegido (o al menos su documento, que el
  // servidor busca; pasa al recuperar un borrador).
  form.addEventListener("click", (event) => {
    if (!event.target.closest("[data-cs-next]") || idInput.value !== "") return;
    const document_ = form.querySelector('[name="documento_arrendatario"]');
    if (document_ && document_.value.trim() !== "") return;
    event.stopPropagation();
    card.classList.add("is-missing");
    picker.querySelector("[data-cs-tenant-detail]").textContent = "Elige el arrendatario de la lista o créalo con \"Crear arrendatario\".";
    picker.scrollIntoView({ behavior: "smooth", block: "center" });
    query.focus({ preventScroll: true });
  }, true);

  if (!dialog || !dialogForm) return;

  // Ventana "Crear arrendatario".
  const errorBox = dialogForm.querySelector("[data-cs-tenant-error]");
  const field = (name) => dialogForm.elements.namedItem(name);
  function syncCompany() {
    const company = field("tipo_persona").value === "Juridica";
    dialogForm.querySelectorAll("[data-cs-tenant-company]").forEach((box) => {
      box.hidden = !company;
      box.querySelectorAll("input").forEach((input) => {
        input.disabled = !company;
        input.required = company;
      });
    });
  }
  function syncCities() {
    const department = field("departamento").value;
    const city = field("ciudad");
    Array.from(city.options).forEach((option) => {
      option.hidden = option.value !== "" && department !== "" && option.dataset.departamento !== department;
    });
    if (city.selectedOptions[0] && city.selectedOptions[0].hidden) city.value = "";
  }
  function syncCountry() {
    const option = field("indicativo").selectedOptions[0];
    field("pais").value = option && option.dataset.pais ? option.dataset.pais : "";
  }
  field("tipo_persona").addEventListener("change", () => {
    // Una empresa se identifica con NIT; una persona natural, con cédula.
    const company = field("tipo_persona").value === "Juridica";
    const type = field("tipo_documento");
    if (company && type.value === "Cédula de Ciudadania") type.value = "Nit";
    if (!company && type.value === "Nit") type.value = "Cédula de Ciudadania";
    syncCompany();
  });
  field("departamento").addEventListener("change", syncCities);
  field("indicativo").addEventListener("change", syncCountry);

  function open() {
    const term = query.value.trim();
    if (term !== "") {
      if (/^[\d.\s-]+$/.test(term)) {
        if (field("documento").value === "") field("documento").value = term.replace(/[.\s-]/g, "");
      } else if (field("nombre").value === "") {
        field("nombre").value = term;
      }
    }
    syncCompany();
    syncCities();
    syncCountry();
    errorBox.hidden = true;
    dialog.showModal();
    field("nombre").focus();
  }
  picker.addEventListener("click", (event) => {
    if (event.target.closest("[data-cs-tenant-new]")) open();
  });
  dialog.addEventListener("click", (event) => {
    if (event.target.closest("[data-cs-tenant-close]") || event.target === dialog) dialog.close();
  });

  let sending = false;
  dialogForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (sending) return;
    const missing = [];
    Array.from(dialogForm.elements).forEach((input) => {
      if (!input.name || input.disabled || input.type === "hidden") return;
      input.classList.remove("is-invalid");
      if (!input.checkValidity()) {
        input.classList.add("is-invalid");
        const label = dialogForm.querySelector(`label[for="${CSS.escape(input.id)}"]`);
        missing.push(label ? label.textContent.replace("*", "").trim() : input.name);
      }
    });
    if (missing.length) {
      errorBox.textContent = "Revisa: " + missing.join(", ") + ".";
      errorBox.hidden = false;
      dialogForm.querySelector(".is-invalid")?.focus();
      return;
    }
    sending = true;
    const button = dialogForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const data = await post(picker.dataset.csTenantSaveAction, new FormData(dialogForm));
      choose(data.tenant, data.message || "");
      dialogForm.reset();
      dialog.close();
    } catch (error) {
      errorBox.textContent = error.message;
      errorBox.hidden = false;
    } finally {
      sending = false;
      button.disabled = false;
    }
  });
})();
