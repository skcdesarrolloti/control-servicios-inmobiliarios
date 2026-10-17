(function () {
  'use strict';
  // "+ Añadir nota al contrato": busca el contrato como en Nuevo Caso y registra la gestión
  // (tipo de reporte, observación, imágenes y un documento) en el historial del contrato.
  let dialog = null;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const option = (value, label) => `<option value="${escape(value)}">${escape(label ?? value)}</option>`;
  const IMAGE_TYPES = {'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp'};
  const MAX_TOTAL = 25 * 1024 * 1024;
  const icon = name => `<span class="material-symbols-outlined" aria-hidden="true">${name}</span>`;

  window.addEventListener('scm:open-contract-note', async () => {
    if (dialog) { if (!dialog.open) dialog.showModal(); return; }
    const root = document.querySelector('[data-scm-runtime]');
    if (!root) return;
    const runtime = JSON.parse(root.dataset.scmRuntime || '{}');
    const opener = document.activeElement;
    let busy = false;
    let selected = null;
    let sequence = 0;
    let requestId = crypto.randomUUID();
    const images = [];
    let documentFile = null;

    dialog = document.createElement('dialog');
    dialog.className = 'scm-new-case scm-contract-note';
    dialog.setAttribute('aria-labelledby', 'scm-contract-note-title');
    dialog.innerHTML = `<header class="scm-new-case-header">
      <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Historial del contrato</p><h2 id="scm-contract-note-title" class="text-xl font-bold text-slate-900 mt-1">Añadir nota al contrato de arrendamiento</h2><p class="text-sm text-slate-500 mt-1">Registra una gestión con su soporte en imágenes o documento.</p></div>
      <button type="button" data-close class="scm-new-case-icon" aria-label="Cerrar popup"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </header><div data-content class="p-6"><p role="status" class="text-sm text-slate-500">Cargando…</p></div>`;
    document.body.append(dialog);
    dialog.showModal();
    const current = dialog;
    const revokeImages = () => { images.forEach(image => URL.revokeObjectURL(image.url)); images.length = 0; };
    const close = () => { if (!busy && current.isConnected) current.close(); };
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    ['click', 'pointerdown'].forEach(type => dialog.addEventListener(type, event => event.stopPropagation()));
    dialog.addEventListener('close', () => { sequence++; revokeImages(); dialog.remove(); dialog = null; opener?.focus(); });
    dialog.querySelector('[data-close]').onclick = close;

    const api = async (action, data = new FormData()) => {
      data.set('action', action);
      data.set('nonce', runtime.nonce);
      const response = await fetch(runtime.ajaxUrl, {method: 'POST', body: data, credentials: 'same-origin'});
      let json;
      try { json = await response.json(); } catch (_) { throw new Error('El servidor no respondió correctamente. Revisa la conexión e inténtalo nuevamente.'); }
      if (!json.success) throw new Error(json.data?.message || 'No se pudo completar la solicitud.');
      return json.data;
    };

    let config;
    try {
      config = await api('scm_contract_note_options');
    } catch (e) {
      if (current.isConnected) current.querySelector('[data-content]').innerHTML = `<p role="alert" class="text-sm text-rose-700">${escape(e.message)}</p>`;
      return;
    }
    if (!current.isConnected) return;
    if (!config.enabled) {
      current.querySelector('[data-content]').innerHTML = '<p role="alert" class="text-sm text-rose-700">Falta la tabla del historial del contrato de arrendamiento (wp_jet_cct_historial_contrato_arrendamiento).</p>';
      return;
    }
    const maxMb = Math.floor(config.max_file_bytes / 1024 / 1024);
    const docAccept = config.document_extensions.map(ext => '.' + ext).join(',');
    const attachmentsEnabled = config.images_enabled || config.document_enabled;

    current.querySelector('[data-content]').innerHTML = `<form data-note-form class="flex flex-col gap-6" novalidate>
      <section class="flex flex-col gap-3"><h3 class="scm-new-case-section"><span>1</span> Encuentra el contrato</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <label class="scm-new-case-label">Buscar por<select data-search-by>${option('contrato', 'Contrato')}${option('inmueble', 'Inmueble SIMI')}${option('propietario', 'Propietario')}${option('arrendatario', 'Arrendatario')}</select></label>
          <label class="scm-new-case-label sm:col-span-2">Número, código o nombre<div class="flex gap-2"><input type="search" data-search maxlength="100" placeholder="Escribe al menos 2 caracteres" class="min-w-0 flex-1"><button type="button" data-search-button class="scm-new-case-button">Buscar</button></div></label>
        </div><p data-search-status role="status" aria-live="polite" class="text-xs text-slate-500">Busca y selecciona el contrato.</p>
        <div data-results class="flex flex-col gap-2 max-h-56 overflow-y-auto"></div>
        <div data-selected hidden class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-slate-700"></div>
        <details data-recent hidden class="scm-contract-note-recent"><summary>Últimas notas del contrato</summary><div data-recent-list></div></details>
      </section>
      <fieldset data-note-steps hidden disabled class="min-w-0"><div class="flex flex-col gap-6">
        <section class="flex flex-col gap-4"><h3 class="scm-new-case-section"><span>2</span> Qué se hizo</h3>
          <label class="scm-new-case-label">Tipo de reporte<input name="tipo_reporte" list="scm-contract-note-types" required maxlength="80" placeholder="Elige o escribe el tipo de gestión"><datalist id="scm-contract-note-types">${config.types.map(type => option(type)).join('')}</datalist></label>
          <label class="scm-new-case-label">Observación<textarea name="observacion" rows="5" required maxlength="5000" placeholder="Describe la gestión realizada, acuerdos y próximos pasos"></textarea></label>
        </section>
        <section class="flex flex-col gap-3"><h3 class="scm-new-case-section"><span>3</span> Soportes (opcional)</h3>
          ${attachmentsEnabled ? `<div class="scm-contract-note-drop" data-drop tabindex="0">
            <div class="flex flex-wrap gap-2">
              ${config.images_enabled ? `<button type="button" data-pick-images class="scm-case-media-button">${icon('image')}Adjuntar imagen</button><button type="button" data-paste class="scm-case-media-button">${icon('content_paste')}Pegar (Ctrl+V)</button>` : ''}
              ${config.document_enabled ? `<button type="button" data-pick-document class="scm-case-media-button">${icon('description')}Adjuntar documento</button>` : ''}
            </div>
            <p data-attach-status role="status" aria-live="polite" class="mt-3 text-xs text-slate-500">${config.images_enabled ? 'Copia una captura y presiona Ctrl+V, o arrastra los archivos aquí.' : 'Arrastra el documento aquí o usa el botón.'}</p>
            <input hidden type="file" data-images-input accept="image/jpeg,image/png,image/webp" multiple>
            <input hidden type="file" data-document-input accept="${escape(docAccept)}">
            <div data-previews class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
            <div data-document class="mt-3"></div>
          </div>
          <p class="text-xs text-slate-500">${config.images_enabled ? `Hasta ${config.max_images} imágenes JPG, PNG o WebP (se comprimen al guardar)` : ''}${config.images_enabled && config.document_enabled ? ' y ' : ''}${config.document_enabled ? `un documento PDF, Word o Excel` : ''}. Máximo ${maxMb} MB por archivo y 25 MB en total.</p>`
          : '<p class="text-xs text-slate-500">Para adjuntar soportes crea en JetEngine los campos imagen (galería) y archivo (media) del historial del contrato.</p>'}
        </section>
      </div></fieldset>
      <p data-error hidden role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></p>
      <footer class="border-t border-slate-200 pt-4 flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-500 max-w-sm">La nota queda en el historial del contrato con tu nombre y la fecha.</p><div class="flex gap-2"><button type="button" data-cancel class="scm-new-case-button">Cancelar</button><button type="submit" data-submit hidden disabled class="scm-new-case-primary">Guardar nota</button></div></footer>
    </form>`;

    const form = current.querySelector('form');
    const error = current.querySelector('[data-error]');
    const showError = message => { error.textContent = message; error.hidden = !message; };
    const searchInput = current.querySelector('[data-search]');
    const searchBy = current.querySelector('[data-search-by]');
    const results = current.querySelector('[data-results]');
    const status = current.querySelector('[data-search-status]');
    const summary = current.querySelector('[data-selected]');
    const steps = current.querySelector('[data-note-steps]');
    const submit = current.querySelector('[data-submit]');
    const recent = current.querySelector('[data-recent]');
    const attachStatus = current.querySelector('[data-attach-status]');
    current.querySelector('[data-cancel]').onclick = close;

    const setStepsVisible = visible => {
      steps.hidden = !visible; steps.disabled = !visible;
      submit.hidden = !visible; submit.disabled = !visible;
    };
    const loadRecent = async contract => {
      recent.hidden = true;
      const data = new FormData(); data.set('contract_id', contract._ID);
      try {
        const response = await api('scm_contract_note_history', data);
        if (!current.isConnected || selected !== contract || !response.notes.length) return;
        current.querySelector('[data-recent-list]').innerHTML = response.notes.map(note => `<article><header><strong>${escape(note.tipo || 'Nota')}</strong><span>${escape(note.fecha)}${note.funcionario ? ' · ' + escape(note.funcionario) : ''}</span></header><p>${escape(note.observacion)}</p></article>`).join('');
        recent.querySelector('summary').textContent = `Últimas notas del contrato (${response.notes.length})`;
        recent.hidden = false;
      } catch (_) { /* El historial reciente es solo de apoyo. */ }
    };
    const invalidateSelection = () => {
      sequence++; selected = null; summary.hidden = true; recent.hidden = true; setStepsVisible(false); showError('');
      results.replaceChildren(); status.textContent = 'Busca y selecciona el contrato.';
    };
    const selectContract = contract => {
      selected = contract;
      results.replaceChildren();
      summary.innerHTML = `<strong>Contrato ${escape(contract.contrato)} · SIMI ${escape(contract.inmueble)}</strong><p class="mt-1">${escape(contract.direccion)}</p><p class="text-xs mt-1">Propietario: ${escape(contract.propietario)} · Arrendatario: ${escape(contract.arrendatario)}</p>`;
      summary.hidden = false; status.textContent = 'Contrato seleccionado. Puedes cambiarlo realizando otra búsqueda.';
      setStepsVisible(true);
      loadRecent(contract);
      form.elements.tipo_reporte.focus();
    };
    searchInput.oninput = invalidateSelection;
    searchBy.onchange = invalidateSelection;
    const search = async () => {
      if (busy) return;
      const query = searchInput.value.trim();
      if (query.length < 2) { status.textContent = 'Escribe al menos 2 caracteres para buscar.'; searchInput.focus(); return; }
      invalidateSelection();
      const run = ++sequence;
      status.textContent = 'Buscando contratos…';
      const data = new FormData(); data.set('query', query); data.set('by', searchBy.value);
      try {
        const response = await api('scm_panel_case_search', data);
        if (!current.isConnected || run !== sequence) return;
        status.textContent = response.contracts.length ? `${response.contracts.length} resultado(s). Selecciona un contrato.${response.contracts.length === 30 ? ' Si no lo encuentras, precisa la búsqueda.' : ''}` : 'No se encontraron contratos. Revisa el dato o cambia el tipo de búsqueda.';
        response.contracts.forEach(contract => {
          const button = document.createElement('button');
          button.type = 'button'; button.className = 'scm-new-case-result'; button.dataset.contractId = contract._ID;
          button.innerHTML = `<strong>Contrato ${escape(contract.contrato)} · SIMI ${escape(contract.inmueble)}</strong><span>${escape(contract.direccion)} · ${escape(contract.estado)}</span><span>Propietario: ${escape(contract.propietario)} · Arrendatario: ${escape(contract.arrendatario)}</span>`;
          button.onclick = () => selectContract(contract);
          results.append(button);
        });
      } catch (e) { if (run === sequence && current.isConnected) status.textContent = e.message; }
    };
    current.querySelector('[data-search-button]').onclick = search;
    searchInput.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); search(); } };

    // Adjuntos: imágenes (seleccionadas, pegadas o arrastradas) y un documento.
    const totalBytes = extra => images.reduce((sum, image) => sum + image.file.size, 0) + (documentFile ? documentFile.size : 0) + extra;
    const renderAttachments = () => {
      const previews = current.querySelector('[data-previews]');
      if (previews) previews.innerHTML = images.map((image, index) => `<figure class="scm-contract-note-thumb"><img src="${image.url}" alt="Imagen ${index + 1}"><figcaption><span>${escape(image.file.name)}</span><button type="button" data-remove-image="${index}" aria-label="Quitar ${escape(image.file.name)}">×</button></figcaption></figure>`).join('');
      const doc = current.querySelector('[data-document]');
      if (doc) doc.innerHTML = documentFile ? `<div class="scm-contract-note-doc">${icon('description')}<span>${escape(documentFile.name)} · ${(documentFile.size / 1024 / 1024).toFixed(1)} MB</span><button type="button" data-remove-document aria-label="Quitar documento">×</button></div>` : '';
    };
    const addImages = blobs => {
      if (busy || !selected || !config.images_enabled) return;
      if (blobs.some(blob => !IMAGE_TYPES[blob.type])) { attachStatus.textContent = 'Usa imágenes JPG, PNG o WebP.'; return; }
      if (images.length + blobs.length > config.max_images) { attachStatus.textContent = `Puedes adjuntar hasta ${config.max_images} imágenes.`; return; }
      if (blobs.some(blob => !blob.size || blob.size > config.max_file_bytes) || totalBytes(blobs.reduce((sum, blob) => sum + blob.size, 0)) > MAX_TOTAL) {
        attachStatus.textContent = `Cada archivo debe pesar máximo ${maxMb} MB y el total 25 MB.`; return;
      }
      blobs.forEach((blob, index) => {
        const file = blob instanceof File && blob.name ? blob : new File([blob], `captura-${Date.now()}-${index + 1}.${IMAGE_TYPES[blob.type]}`, {type: blob.type});
        images.push({file, url: URL.createObjectURL(file)});
      });
      renderAttachments();
      attachStatus.textContent = blobs.length === 1 ? 'Imagen agregada.' : `${blobs.length} imágenes agregadas.`;
    };
    const setDocument = file => {
      if (busy || !selected || !config.document_enabled || !file) return;
      const extension = (file.name.split('.').pop() || '').toLowerCase();
      if (!config.document_extensions.includes(extension)) { attachStatus.textContent = 'El documento debe ser PDF, Word o Excel.'; return; }
      if (file.size > config.max_file_bytes || totalBytes(file.size - (documentFile ? documentFile.size : 0)) > MAX_TOTAL) { attachStatus.textContent = `El documento debe pesar máximo ${maxMb} MB y el total 25 MB.`; return; }
      documentFile = file;
      renderAttachments();
      attachStatus.textContent = 'Documento agregado. Solo se guarda uno por nota; uno nuevo reemplaza al anterior.';
    };
    const imagesInput = current.querySelector('[data-images-input]');
    const documentInput = current.querySelector('[data-document-input]');
    current.querySelector('[data-pick-images]')?.addEventListener('click', () => imagesInput.click());
    current.querySelector('[data-pick-document]')?.addEventListener('click', () => documentInput.click());
    if (imagesInput) imagesInput.onchange = () => { addImages([...imagesInput.files]); imagesInput.value = ''; };
    if (documentInput) documentInput.onchange = () => { setDocument(documentInput.files[0]); documentInput.value = ''; };
    const drop = current.querySelector('[data-drop]');
    if (drop) {
      ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-over')));
      drop.addEventListener('drop', event => {
        event.preventDefault();
        const files = [...(event.dataTransfer?.files || [])];
        const imageFiles = files.filter(file => IMAGE_TYPES[file.type]);
        if (imageFiles.length) addImages(imageFiles);
        const doc = files.find(file => !IMAGE_TYPES[file.type]);
        if (doc) setDocument(doc);
      });
      drop.addEventListener('click', event => {
        const removeImage = event.target.closest('[data-remove-image]');
        if (removeImage && !busy) {
          const [removed] = images.splice(Number(removeImage.dataset.removeImage), 1);
          if (removed) URL.revokeObjectURL(removed.url);
          renderAttachments(); attachStatus.textContent = 'Imagen quitada.';
        }
        if (event.target.closest('[data-remove-document]') && !busy) { documentFile = null; renderAttachments(); attachStatus.textContent = 'Documento quitado.'; }
      });
    }
    // Ctrl+V en cualquier parte del formulario pega capturas; el texto se pega normal.
    form.addEventListener('paste', event => {
      if (busy || !selected || !config.images_enabled) return;
      let pasted = [...(event.clipboardData?.items || [])].filter(item => item.type.startsWith('image/')).map(item => item.getAsFile()).filter(Boolean);
      if (!pasted.length) pasted = [...(event.clipboardData?.files || [])].filter(file => file.type.startsWith('image/'));
      if (!pasted.length) return;
      event.preventDefault();
      addImages(pasted);
    });
    current.querySelector('[data-paste]')?.addEventListener('click', async () => {
      if (!navigator.clipboard?.read) { attachStatus.textContent = 'Copia la captura y presiona Ctrl+V dentro de este popup.'; return; }
      try {
        const items = await navigator.clipboard.read();
        const blobs = [];
        for (const item of items) {
          const type = item.types.find(type => IMAGE_TYPES[type]);
          if (type) blobs.push(await item.getType(type));
        }
        if (blobs.length) addImages(blobs);
        else attachStatus.textContent = 'No hay una imagen en el portapapeles. Copia una captura y presiona Ctrl+V.';
      } catch (_) { attachStatus.textContent = 'No se pudo leer el portapapeles. Presiona Ctrl+V dentro de este popup.'; }
    });

    form.onsubmit = async event => {
      event.preventDefault();
      if (busy) return;
      showError('');
      if (!selected) { showError('Busca y selecciona un contrato.'); searchInput.focus(); return; }
      if (!form.elements.tipo_reporte.value.trim()) { showError('Indica el tipo de reporte.'); form.elements.tipo_reporte.focus(); return; }
      if (!form.elements.observacion.value.trim()) { showError('Describe lo que se hizo.'); form.elements.observacion.focus(); return; }
      const data = new FormData();
      data.set('contract_id', selected._ID);
      data.set('request_id', requestId);
      data.set('tipo_reporte', form.elements.tipo_reporte.value.trim());
      data.set('observacion', form.elements.observacion.value.trim());
      images.forEach(image => data.append('imagenes[]', image.file, image.file.name));
      if (documentFile) data.set('archivo', documentFile, documentFile.name);
      busy = true;
      submit.textContent = 'Guardando…';
      const controls = [...current.querySelectorAll('input, textarea, select, button')];
      const states = controls.map(control => control.disabled);
      controls.forEach(control => { control.disabled = true; });
      current.setAttribute('aria-busy', 'true');
      try {
        const response = await api('scm_contract_note_create', data);
        busy = false;
        current.removeAttribute('aria-busy');
        current.querySelector('[data-close]').disabled = false;
        const contract = selected;
        current.querySelector('[data-content]').innerHTML = `<div class="p-6 text-center flex flex-col items-center gap-4"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" class="text-emerald-700"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg><h3 class="text-xl font-bold text-slate-900">Nota registrada</h3><p role="status" class="text-sm text-slate-600">${escape(response.message)}</p><div class="flex flex-wrap justify-center gap-2"><button type="button" data-another class="scm-new-case-button">Añadir otra nota a este contrato</button><button type="button" data-done class="scm-new-case-primary">Aceptar</button></div></div>`;
        current.querySelector('[data-done]').onclick = close;
        current.querySelector('[data-another]').onclick = () => { current.close(); setTimeout(() => window.dispatchEvent(new CustomEvent('scm:open-contract-note', {detail: {contract}})), 0); };
        current.querySelector('[data-done]').focus();
        root.dispatchEvent(new CustomEvent('scm:contract-note-created', {detail: response}));
      } catch (e) {
        busy = false; current.removeAttribute('aria-busy');
        controls.forEach((control, index) => { control.disabled = states[index]; });
        submit.textContent = 'Guardar nota'; showError(e.message);
      }
    };

    searchInput.focus();
  });

  // "Añadir otra nota" reabre el popup con el mismo contrato ya elegido.
  window.addEventListener('scm:open-contract-note', event => {
    const contract = event.detail?.contract;
    if (!contract) return;
    const waitForForm = (tries = 0) => {
      const form = dialog?.querySelector('[data-note-form]');
      if (!form) { if (tries < 40) setTimeout(() => waitForForm(tries + 1), 50); return; }
      const searchBy = form.querySelector('[data-search-by]');
      const searchInput = form.querySelector('[data-search]');
      searchBy.value = 'contrato';
      searchInput.value = contract.contrato;
      form.querySelector('[data-search-button]').click();
      const pick = (tries2 = 0) => {
        const button = [...form.querySelectorAll('.scm-new-case-result')].find(item => item.dataset.contractId === String(contract._ID));
        if (button) button.click(); else if (tries2 < 40) setTimeout(() => pick(tries2 + 1), 100);
      };
      pick();
    };
    waitForForm();
  });
})();
