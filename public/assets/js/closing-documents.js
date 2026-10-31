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

    async function post(action, body) {
      body.append("action", action);
      body.append("nonce", root.dataset.cdNonce || "");
      const response = await fetch(root.dataset.cdApi, { method: "POST", body, credentials: "same-origin" });
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
    async function upload(slot, files) {
      const title = slot.querySelector("[data-cd-title]");
      const errors = [];
      busy += 1;
      slot.classList.add("is-busy");
      for (let i = 0; i < files.length; i += 1) {
        status(slot, "Subiendo " + (i + 1) + " de " + files.length + ": " + files[i].name + "…");
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
        const error = form.querySelector("[data-cs-error]");
        if (error) {
          error.textContent = "Espera a que terminen de subir los documentos y vuelve a guardar.";
          error.hidden = false;
        }
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
