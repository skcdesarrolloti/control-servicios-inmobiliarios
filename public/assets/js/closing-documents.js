// Documentos de la hoja de cierre y de la carpeta del contrato (ClosingSheetDocumentsView): adjuntar en cada casilla
// (de a un archivo por petición; las fotos se comprimen antes), eliminar con confirmación y ver PDF o imágenes dentro de
// la página. En la hoja (hoja-cierre.php) avisa los documentos en «Cumple» sin archivo antes de guardar.
(function () {
  "use strict";

  const viewer = document.querySelector("[data-cd-viewer]");
  const confirmDialog = document.querySelector("[data-cd-confirm]");

  // Ver un documento sin salir de la página.
  function preview(url, kind, name) {
    if (!viewer) return;
    const body = viewer.querySelector("[data-cd-viewer-body]");
    viewer.querySelector("[data-cd-viewer-title]").textContent = name || "Documento";
    body.replaceChildren();
    if (kind === "image") {
      const img = document.createElement("img");
      img.src = url;
      img.alt = name || "Documento";
      body.appendChild(img);
    } else {
      const frame = document.createElement("iframe");
      frame.src = url;
      frame.title = name || "Documento";
      frame.setAttribute("referrerpolicy", "no-referrer");
      body.appendChild(frame);
    }
    viewer.showModal();
  }
  if (viewer) {
    viewer.addEventListener("click", (event) => {
      if (event.target.closest("[data-cd-viewer-close]") || event.target === viewer) viewer.close();
    });
    viewer.addEventListener("close", () => viewer.querySelector("[data-cd-viewer-body]").replaceChildren());
  }
  document.addEventListener("click", (event) => {
    const open = event.target.closest("[data-cd-preview]");
    if (!open) return;
    event.preventDefault();
    preview(open.dataset.cdPreview, open.dataset.cdKind, open.dataset.cdName);
  });

  // «Documentos por actor» (ClosingSheetDocumentsView::actorBar): un botón por persona o zona con sus archivos y un
  // popup con solo sus documentos, armado con las casillas o listas de la página para que siga al día.
  const actorBar = document.querySelector("[data-cd-actors]");
  const actorDialog = document.querySelector("[data-cd-actor-dialog]");
  let actorMap = null;
  try {
    actorMap = actorBar ? JSON.parse(actorBar.dataset.cdActors || "{}") : null;
  } catch (error) {
    actorMap = null;
  }
  let openActorKey = "";
  const toneOf = (state) => ({ cumple: "good", "no cumple": "bad" })[state.trim().toLowerCase()] || "muted";
  // Estado del ítem: el selector de la hoja, o la etiqueta de la vista y de la carpeta.
  function stateOf(node) {
    const select = document.querySelector(`select[name="${CSS.escape(node.dataset.cdItem || "")}"]`);
    if (select) return select.disabled ? "" : select.value;
    const row = node.closest(".csf-checklist > li, .cd-item");
    const badge = row ? row.querySelector(".csf-result") : null;
    return badge ? badge.textContent.trim() : "";
  }
  function actorGroups() {
    const groups = new Map((actorMap.order || []).map((key) => [key, []]));
    document.querySelectorAll("[data-cd-slot][data-cd-item], .cd-read[data-cd-item]").forEach((node) => {
      // Lo que la hoja oculta (codeudores de más, copropiedad sin PH) no cuenta.
      if (node.closest("[data-cs-when][hidden]") || node.closest("[data-cd-actor-dialog]")) return;
      const info = (actorMap.items || {})[node.dataset.cdItem];
      if (!info) return;
      const key = info[0] === "propietario" ? "propietario_" + Math.max(1, Number(node.dataset.cdPerson) || 1) : info[0];
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push({ node, label: info[1] });
    });
    return groups;
  }
  const actorLabel = (key) => (actorMap.actors || {})[key] || key;
  const filesOf = (node) => node.querySelectorAll("[data-cd-docs] [data-cd-doc], .cd-docs [data-cd-doc]");
  const isMissing = (entry) => toneOf(stateOf(entry.node)) === "good" && filesOf(entry.node).length === 0;
  function refreshActors() {
    if (!actorBar || !actorMap) return;
    const buttons = [];
    actorGroups().forEach((entries, key) => {
      if (!entries.length) return;
      const files = entries.reduce((total, entry) => total + filesOf(entry.node).length, 0);
      const missing = entries.filter(isMissing).length;
      const button = document.createElement("button");
      button.type = "button";
      button.className = "cd-actor" + (missing ? " has-missing" : "");
      button.dataset.cdActor = key;
      const name = document.createElement("span");
      name.textContent = actorLabel(key);
      const count = document.createElement("small");
      count.textContent = files + (files === 1 ? " archivo" : " archivos") + (missing ? " · " + missing + " sin archivo" : "");
      button.append(name, count);
      buttons.push(button);
    });
    actorBar.querySelector("[data-cd-actor-list]").replaceChildren(...buttons);
    actorBar.hidden = buttons.length === 0;
    if (openActorKey && actorDialog && actorDialog.open) renderActor(openActorKey);
  }
  function renderActor(key) {
    const entries = actorGroups().get(key) || [];
    actorDialog.querySelector("[data-cd-actor-title]").textContent = actorLabel(key);
    const body = actorDialog.querySelector("[data-cd-actor-body]");
    body.replaceChildren(...entries.map((entry) => {
      const item = document.createElement("section");
      item.className = "cd-actor-item";
      const head = document.createElement("div");
      head.className = "cd-actor-item-head";
      const title = document.createElement("p");
      title.textContent = entry.label;
      head.appendChild(title);
      const state = stateOf(entry.node);
      if (state) {
        const badge = document.createElement("span");
        badge.className = "csf-result is-" + toneOf(state);
        badge.textContent = state;
        head.appendChild(badge);
      }
      const input = entry.node.querySelector("[data-cd-input]");
      if (input) {
        const add = document.createElement("button");
        add.type = "button";
        add.className = "cd-add";
        add.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">attach_file</span> Adjuntar';
        add.addEventListener("click", () => input.click());
        head.appendChild(add);
      }
      item.appendChild(head);
      const files = Array.from(filesOf(entry.node));
      if (files.length) {
        const list = document.createElement("ul");
        list.className = "cd-docs";
        files.forEach((file) => {
          const copy = file.cloneNode(true);
          copy.querySelectorAll("[data-cd-delete]").forEach((button) => button.remove());
          list.appendChild(copy);
        });
        item.appendChild(list);
      } else {
        const empty = document.createElement("p");
        empty.className = isMissing(entry) ? "cd-warn" : "cd-empty";
        empty.textContent = isMissing(entry) ? "Está en «Cumple» y no tiene archivo." : "Sin archivo.";
        item.appendChild(empty);
      }
      const busy = entry.node.querySelector("[data-cd-status]:not([hidden])");
      if (busy) {
        const status = document.createElement("p");
        status.className = busy.className;
        status.textContent = busy.textContent;
        item.appendChild(status);
      }
      return item;
    }));
  }
  if (actorBar && actorMap && actorDialog) {
    actorBar.addEventListener("click", (event) => {
      const button = event.target.closest("[data-cd-actor]");
      if (!button) return;
      openActorKey = button.dataset.cdActor;
      renderActor(openActorKey);
      actorDialog.showModal();
    });
    actorDialog.addEventListener("click", (event) => {
      if (event.target.closest("[data-cd-actor-close]") || event.target === actorDialog) actorDialog.close();
    });
    actorDialog.addEventListener("close", () => {
      openActorKey = "";
    });
    // Los campos condicionales de la hoja se recalculan en el mismo cambio: se espera a que terminen.
    document.addEventListener("change", () => setTimeout(refreshActors, 0));
    refreshActors();
  }

  function ask(text) {
    if (!confirmDialog) return Promise.resolve(false);
    confirmDialog.querySelector("[data-cd-confirm-text]").textContent = text;
    confirmDialog.returnValue = "";
    confirmDialog.showModal();
    return new Promise((resolve) => {
      confirmDialog.addEventListener("close", () => resolve(confirmDialog.returnValue === "ok"), { once: true });
    });
  }

  document.querySelectorAll("[data-cd-root][data-cd-api]").forEach((root) => {
    const scope = (() => {
      try {
        return JSON.parse(root.dataset.cdScope || "{}");
      } catch (error) {
        return {};
      }
    })();

    // Una subida colgada no puede bloquear el guardado de la hoja para siempre: se corta a los 3 minutos.
    async function post(action, body) {
      body.append("action", action);
      body.append("nonce", root.dataset.cdNonce || "");
      const controller = window.AbortController ? new AbortController() : null;
      const timer = controller ? setTimeout(() => controller.abort(), 180000) : 0;
      let response;
      try {
        response = await fetch(root.dataset.cdApi, { method: "POST", body, credentials: "same-origin", signal: controller ? controller.signal : undefined });
      } catch (error) {
        throw new Error(error && error.name === "AbortError" ? "La subida tardó demasiado. Revisa tu conexión e intenta de nuevo." : "No se pudo conectar. Revisa tu conexión e intenta de nuevo.");
      } finally {
        clearTimeout(timer);
      }
      if (response.status === 401) throw new Error("Tu sesión venció. Recarga la página e inicia sesión.");
      const json = await response.json().catch(() => null);
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || "No se pudo completar. Intenta de nuevo.");
      return json.data || {};
    }

    function status(slot, text, isError) {
      const box = slot.querySelector("[data-cd-status]");
      box.textContent = text;
      box.hidden = text === "";
      box.classList.toggle("is-error", !!isError);
    }

    let busy = 0;
    let waiting = null; // Guardar que se pidió mientras subían documentos: { submitter, failed }.
    async function upload(slot, files) {
      const title = slot.querySelector("[data-cd-title]");
      const errors = [];
      busy += 1;
      slot.classList.add("is-busy");
      for (let i = 0; i < files.length; i += 1) {
        status(slot, "Subiendo " + (i + 1) + " de " + files.length + ": " + files[i].name + "…");
        refreshActors();
        try {
          const file = window.ScmPhotoCompress ? await window.ScmPhotoCompress.prepare(files[i]) : files[i];
          const body = new FormData();
          Object.keys(scope).forEach((key) => body.append(key, String(scope[key])));
          body.append("item", slot.dataset.cdItem);
          body.append("persona", slot.dataset.cdPerson || "0");
          if (title) body.append("titulo", title.value.trim());
          body.append("archivo", file, file.name || files[i].name);
          const data = await post(root.dataset.cdUploadAction, body);
          slot.querySelector("[data-cd-docs]").insertAdjacentHTML("beforeend", data.html);
        } catch (error) {
          errors.push(error.message);
        }
      }
      if (title && !errors.length) title.value = "";
      busy -= 1;
      slot.classList.remove("is-busy");
      status(slot, errors.join(" "), errors.length > 0);
      sync();
      if (waiting) finishWaiting(errors.length > 0);
    }

    // Al terminar las subidas pendientes, la hoja se guarda sola; si alguna falló, se avisa y no se guarda.
    function saveMessage(text) {
      const box = form && form.querySelector("[data-cs-error]");
      if (!box) return;
      box.textContent = text;
      box.hidden = text === "";
    }
    function finishWaiting(failed) {
      waiting.failed = waiting.failed || failed;
      if (busy > 0) {
        saveMessage("Subiendo " + busy + (busy === 1 ? " documento" : " documentos") + "… la hoja se guardará apenas termine.");
        return;
      }
      const { submitter, failed: anyFailed } = waiting;
      waiting = null;
      if (anyFailed) {
        saveMessage("Algún documento no se pudo subir (mira el aviso en rojo en su casilla). Corrígelo o quítalo y vuelve a guardar.");
        return;
      }
      saveMessage("");
      form.requestSubmit(submitter && submitter.isConnected ? submitter : undefined);
    }

    root.addEventListener("click", async (event) => {
      const add = event.target.closest("[data-cd-add]");
      if (add) {
        add.closest("[data-cd-slot]").querySelector("[data-cd-input]").click();
        return;
      }
      const remove = event.target.closest("[data-cd-delete]");
      if (!remove) return;
      const chip = remove.closest("[data-cd-doc]");
      const slot = remove.closest("[data-cd-slot]");
      const name = (chip.querySelector(".cd-doc-name") || {}).textContent || "este documento";
      if (!(await ask("«" + name + "»"))) return;
      remove.disabled = true;
      const body = new FormData();
      body.append("id", chip.dataset.cdDoc);
      try {
        await post(root.dataset.cdDeleteAction, body);
        chip.remove();
        status(slot, "");
      } catch (error) {
        remove.disabled = false;
        status(slot, error.message, true);
      }
      sync();
    });
    root.addEventListener("change", (event) => {
      const input = event.target.closest("[data-cd-input]");
      if (!input) return;
      const files = Array.from(input.files || []);
      input.value = "";
      if (files.length) upload(input.closest("[data-cd-slot]"), files);
    });

    // Hoja de cierre: ítem de documento en «Cumple» sin ningún archivo (en ninguno de los propietarios).
    const form = root.closest("[data-cs-form]");
    const pending = root.querySelector("[data-cd-pending]");
    const stateOf = (slot) => {
      const select = form && slot.dataset.cdState ? form.querySelector(`[name="${CSS.escape(slot.dataset.cdState)}"]`) : null;
      return select && !select.disabled ? String(select.value).trim().toLowerCase() : "";
    };
    function missing() {
      const out = [];
      const seen = new Set();
      root.querySelectorAll("[data-cd-slot][data-cd-state]").forEach((slot) => {
        const item = slot.dataset.cdItem;
        const all = root.querySelectorAll(`[data-cd-slot][data-cd-item="${CSS.escape(item)}"]`);
        const empty = Array.from(all).every((node) => !node.querySelector("[data-cd-doc]"));
        const warn = stateOf(slot) === "cumple" && empty && !slot.closest("[hidden]");
        slot.querySelector("[data-cd-warn]").hidden = !warn;
        if (warn && !seen.has(item)) {
          seen.add(item);
          const row = slot.closest(".cs-check-item");
          out.push({ slot, text: row ? row.querySelector("p").textContent.trim() : item });
        }
      });
      return out;
    }
    function sync() {
      const list = missing();
      if (pending && !pending.hidden && !list.length) pending.hidden = true;
      refreshActors();
    }
    if (!form) return;
    form.addEventListener("change", (event) => {
      if (event.target.matches("select")) sync();
    });
    sync();

    let accepted = false;
    form.addEventListener("submit", (event) => {
      if (busy > 0) {
        event.preventDefault();
        event.stopImmediatePropagation();
        waiting = { submitter: event.submitter || null, failed: false };
        saveMessage("Subiendo " + busy + (busy === 1 ? " documento" : " documentos") + "… la hoja se guardará apenas termine.");
        return;
      }
      const list = missing();
      if (accepted || !list.length || !pending) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const ul = pending.querySelector("[data-cd-pending-list]");
      ul.replaceChildren(...list.slice(0, 12).map((entry) => {
        const li = document.createElement("li");
        li.textContent = entry.text.length > 90 ? entry.text.slice(0, 90) + "…" : entry.text;
        return li;
      }));
      pending.hidden = false;
      pending.scrollIntoView({ behavior: "smooth", block: "center" });
    }, true);
    pending.querySelector("[data-cd-pending-go]").addEventListener("click", () => {
      const first = missing()[0];
      pending.hidden = true;
      if (first) first.slot.scrollIntoView({ behavior: "smooth", block: "center" });
    });
    pending.querySelector("[data-cd-pending-save]").addEventListener("click", () => {
      accepted = true;
      pending.hidden = true;
      form.requestSubmit();
      accepted = false;
    });
  });
})();
