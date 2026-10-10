(function () {
  'use strict';
  let dialog = null;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const option = (value, label) => `<option value="${escape(value)}">${escape(label ?? value)}</option>`;
  const mediaIcon = kind => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">${kind === 'image' ? '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/>' : kind === 'paste' ? '<rect x="5" y="5" width="14" height="16" rx="2"/><rect x="9" y="2" width="6" height="5" rx="1"/>' : '<path d="M8 3h7l5 5v13H4V3h4Zm6 0v6h6M8 13h8M8 17h6"/>'}</svg>`;
  const pickMedia = (target, label, kind) => `<button type="button" data-pick-media="${target}" class="scm-case-media-button">${mediaIcon(kind)}${label}</button>`;
  let draftDatabase;
  const draftStore = async (scope, value) => {
    if (!draftDatabase) draftDatabase = new Promise((resolve, reject) => {
      const request = indexedDB.open('scm-panel-case-drafts', 1);
      request.onupgradeneeded = () => request.result.createObjectStore('drafts', {keyPath: 'scope'});
      request.onerror = () => { draftDatabase = null; reject(new Error('No se pudo abrir el autoguardado.')); };
      request.onblocked = () => reject(new Error('El autoguardado está bloqueado por otra pestaña.'));
      request.onsuccess = () => {
        const db = request.result;
        db.onversionchange = () => { db.close(); draftDatabase = null; };
        const cleanup = db.transaction('drafts', 'readwrite').objectStore('drafts').openCursor();
        cleanup.onsuccess = () => { const cursor = cleanup.result; if (cursor) { if (cursor.value.expires <= Date.now()) cursor.delete(); cursor.continue(); } };
        resolve(db);
      };
    });
    const db = await draftDatabase;
    return new Promise((resolve, reject) => {
      const transaction = db.transaction('drafts', value === undefined ? 'readonly' : 'readwrite');
      const store = transaction.objectStore('drafts');
      const request = value === undefined ? store.get(scope) : value === null ? store.delete(scope) : store.put({...value, scope});
      transaction.oncomplete = () => resolve(request.result);
      transaction.onerror = transaction.onabort = () => reject(new Error('No se pudo guardar el borrador en este navegador.'));
    });
  };

  window.addEventListener('scm:open-nuevo-ticket', async () => {
    if (dialog) { if (!dialog.open) dialog.showModal(); return; }
    const root = document.querySelector('[data-scm-runtime]');
    if (!root) return;
    const runtime = JSON.parse(root.dataset.scmRuntime || '{}');
    const opener = document.activeElement;
    let busy = false;
    let selected = null;
    let sequence = 0;
    let aiSequence = 0;
    let aiController = null;
    let requestId = crypto.randomUUID();
    let persistDraft = async () => {};
    dialog = document.createElement('dialog');
    dialog.className = 'scm-new-case';
    dialog.setAttribute('aria-labelledby', 'scm-new-case-title');
    dialog.setAttribute('closedby', 'none');
    dialog.innerHTML = `<header class="scm-new-case-header">
      <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">SKC SuCasa Inmobiliaria</p><h2 id="scm-new-case-title" class="text-xl font-bold text-slate-900 mt-1">Crear nuevo caso</h2><p class="text-sm text-slate-500 mt-1">Vincula un contrato y asigna la gestión a tu equipo.</p></div>
      <button type="button" data-close class="scm-new-case-icon" aria-label="Cerrar popup"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </header><div data-content class="p-6"><p role="status" class="text-sm text-slate-500">Cargando temas y funcionarios…</p></div>`;
    document.body.append(dialog);
    dialog.showModal();
    const close = async () => {
      if (busy) return;
      if (await persistDraft() === false) return;
      if (current.isConnected) current.close();
    };
    dialog.addEventListener('cancel', event => { event.preventDefault(); event.stopPropagation(); });
    dialog.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
    ['click', 'pointerdown'].forEach(type => dialog.addEventListener(type, event => event.stopPropagation()));
    dialog.addEventListener('close', () => { sequence++; aiSequence++; aiController?.abort(); dialog.remove(); dialog = null; opener?.focus(); });
    dialog.querySelector('[data-close]').onclick = close;
    const current = dialog;
    const api = async (action, data = new FormData(), signal) => {
      data.set('action', action);
      data.set('nonce', runtime.nonce);
      const response = await fetch(runtime.ajaxUrl, {method: 'POST', body: data, credentials: 'same-origin', signal});
      let json;
      try { json = await response.json(); } catch (_) { throw new Error('El servidor no respondió correctamente. Revisa la conexión e inténtalo nuevamente.'); }
      if (!json.success) throw new Error(json.data?.message || 'No se pudo completar la solicitud.');
      return json.data;
    };
    try {
      const config = await api('scm_panel_case_options');
      if (!current.isConnected) return;
      current.querySelector('[data-content]').innerHTML = `<form data-case-form class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-2"><p data-draft-status role="status" aria-live="polite" class="text-xs text-slate-500">El borrador se guardará en este navegador durante 24 horas.</p><button type="button" data-discard-draft class="text-xs font-semibold text-slate-500 underline">Descartar borrador</button></div>
        <div data-discard-confirm hidden class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-slate-700"><p>¿Descartar los datos y archivos de este borrador? Esta acción no se puede deshacer.</p><div class="mt-3 flex gap-2"><button type="button" data-confirm-discard class="scm-new-case-button">Sí, descartar</button><button type="button" data-keep-draft class="scm-new-case-button">Conservar borrador</button></div></div>
        <section class="flex flex-col gap-3"><h3 class="scm-new-case-section"><span>1</span> Encuentra el contrato</h3>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="scm-new-case-label">Buscar por<select data-search-by>${option('contrato', 'Contrato')}${option('inmueble', 'Inmueble SIMI')}${option('propietario', 'Propietario')}${option('arrendatario', 'Arrendatario')}</select></label>
            <label class="scm-new-case-label sm:col-span-2">Número, código o nombre<div class="flex gap-2"><input type="search" data-search maxlength="100" placeholder="Escribe al menos 2 caracteres" class="min-w-0 flex-1"><button type="button" data-search-button class="scm-new-case-button">Buscar</button></div></label>
          </div><p data-search-status role="status" aria-live="polite" class="text-xs text-slate-500">Busca y selecciona el contrato correspondiente.</p>
          <div data-results class="flex flex-col gap-2 max-h-56 overflow-y-auto"></div>
          <div data-selected hidden class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-slate-700"></div>
        </section>
        <fieldset data-contract-steps hidden disabled class="min-w-0"><div class="flex flex-col gap-6">
        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4"><h3 class="text-sm font-semibold text-slate-900">¿Quieres rellenar la información del caso con el asistente?</h3><div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3"><button type="button" data-assistant-choice="yes" aria-pressed="false" class="scm-new-case-button">Sí, usar el asistente</button><button type="button" data-assistant-choice="no" aria-pressed="false" class="scm-new-case-button">No, completar manualmente</button></div></section>
        <fieldset data-case-details hidden disabled class="min-w-0"><div class="flex flex-col gap-6">
        <section data-ai-section hidden class="rounded-xl border border-blue-200 bg-blue-50 p-4">
          <h3 class="text-sm font-semibold text-slate-900">Asistente para preparar el caso</h3>
          <p class="mt-2 text-sm text-slate-600">Pega un correo o una conversación, o agrega capturas. Revisa la propuesta y úsala para completar los datos del caso.</p>
          ${config.ai?.enabled ? '' : `<p class="mt-3 text-sm text-slate-600">El asistente está pendiente de activar. Puedes elegir completar el caso manualmente.</p>`}
          <fieldset data-ai-inputs ${config.ai?.enabled ? '' : 'disabled'} class="mt-4 min-w-0 flex flex-col gap-3">
            <label class="scm-new-case-label">Contenido del correo o WhatsApp<textarea data-ai-text rows="4" maxlength="20000" placeholder="Pega aquí el mensaje que explica la solicitud. También puedes añadir contexto para interpretar las capturas."></textarea></label>
            <input hidden data-ai-images type="file" accept="image/jpeg,image/png,image/webp" multiple aria-label="Capturas para analizar">
            <div class="flex flex-wrap items-center gap-2">${pickMedia('assistant', 'Adjuntar captura', 'image')}<button data-ai-paste type="button" class="scm-case-media-button">${mediaIcon('paste')}Pegar (Ctrl+V)</button></div><p class="text-xs text-slate-500">Máximo 4 capturas, 5 MB cada una. También puedes pegar texto de correos o WhatsApp.</p>
            <div data-ai-previews class="grid grid-cols-2 gap-3"></div>
            <p class="text-xs text-slate-500">Al analizar, el texto y las capturas se envían a MiniMax. Las capturas de esta sección no se guardan como evidencias del caso.</p>
            <div><button data-ai-analyze type="button" class="scm-new-case-primary">Analizar solicitud</button></div>
          </fieldset>
          <p data-ai-status role="status" aria-live="polite" class="mt-3 text-sm text-slate-600"></p>
          <div data-ai-result hidden class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
            <h4 class="text-sm font-semibold text-slate-900">Propuesta para revisar</h4><div data-ai-draft class="mt-3 flex flex-col gap-3 text-sm text-slate-700"></div>
            <div class="mt-4 flex flex-wrap gap-3"><button data-ai-apply type="button" class="scm-new-case-primary">Completar formulario</button><button data-ai-undo hidden type="button" class="scm-new-case-button">Deshacer autocompletado</button></div>
          </div>
        </section>
        <section class="flex flex-col gap-4"><h3 class="scm-new-case-section"><span>2</span> Describe y asigna el caso</h3>
          <label class="scm-new-case-label">Título<input name="asunto" required maxlength="200" placeholder="Resume la solicitud"></label>
          <label class="scm-new-case-label">Descripción<textarea name="descripcion" rows="4" required maxlength="10000" placeholder="Describe qué ocurre y qué gestión se necesita"></textarea></label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="scm-new-case-label">Tema<select name="tema_ayuda" required>${option('', 'Selecciona un tema')}${config.themes.map(t => option(t)).join('')}</select></label>
            <label class="scm-new-case-label">Departamento<select name="departamento" required>${option('', 'Selecciona el departamento')}${config.departments.map(t => option(t)).join('')}</select></label>
          </div>
          <label class="scm-new-case-label">Asignar a<select name="id_empleado" required>${option('', 'Selecciona un funcionario activo')}${config.employees.map(e => option(e.employee_id, `${e.name} · ${e.cargo}${e.email ? '' : ' · Sin correo'}`)).join('')}</select></label>
          <p data-assignee class="text-xs text-slate-500">El responsable recibirá correo${config.whatsapp_enabled ? ' y WhatsApp' : '. WhatsApp pendiente de activar en la configuración interna del sistema'}.</p>
        </section>
        <section class="flex flex-col gap-4"><h3 class="scm-new-case-section"><span>3</span> Adjunta las evidencias</h3>
          <label class="scm-new-case-label">¿El caso tiene adjuntos?<select name="has_attachments" required>${option('', 'Selecciona una opción')}${option('No', 'No, continuar sin adjuntos')}${option('Si', 'Sí, agregar imágenes o documentos')}</select></label>
          <div data-attachments hidden><input hidden type="file" name="imagenes[]" accept="image/jpeg,image/png,image/webp" multiple aria-label="Imágenes del caso"><input hidden type="file" name="archivos[]" accept="application/pdf,.pdf" multiple aria-label="Documentos PDF del caso">
          <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4"><div class="flex flex-wrap gap-2">${pickMedia('images', 'Adjuntar imagen', 'image')}<button type="button" data-paste-image class="scm-case-media-button">${mediaIcon('paste')}Pegar (Ctrl+V)</button>${pickMedia('documents', 'Adjuntar documento', 'document')}</div><p data-paste-status role="status" aria-live="polite" class="mt-3 text-xs text-slate-500">Copia una captura y presiona Ctrl+V, o selecciona un archivo con los botones.</p><div data-pasted-images class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3"></div></div><p class="text-xs text-slate-500 mt-3">Hasta 10 archivos, ${Math.floor(config.max_file_bytes / 1024 / 1024)} MB por archivo y 25 MB en total. Imágenes JPG, PNG o WebP de máximo 16 megapíxeles.</p><ul data-files class="mt-3 text-xs text-slate-600 flex flex-col gap-1"></ul></div>
        </section>
        <section class="flex flex-col gap-3"><h3 class="scm-new-case-section"><span>4</span> Notifica a los interesados</h3>
          <p class="text-sm text-slate-500">Opcional. Selecciona a quién avisar por correo y WhatsApp con el título, tema, contrato y responsable. El correo también incluye la descripción.</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">${[['propietario', 'Propietario'], ['arrendatario', 'Arrendatario'], ['copropiedad', 'Copropiedad']].map(([value, label]) => `<label class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm text-slate-700"><input type="checkbox" name="notify_roles[]" value="${value}" class="h-4 w-4">${label}</label>`).join('')}</div>
          <p class="text-xs text-slate-500">Se usan los datos del contrato y su ficha. Deben tener correo y celular válidos.${config.whatsapp_enabled ? '' : ' WhatsApp está pendiente de activar; por ahora se encolará solo el correo.'}</p>
        </section>
        </div></fieldset></div></fieldset>
        <p data-error hidden role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></p>
        <footer class="border-t border-slate-200 pt-4 flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-500 max-w-sm">Los avisos se encolan al guardar. Las copias se configuran en Notificaciones internas.</p><div class="flex gap-2"><button type="button" data-cancel class="scm-new-case-button">Cancelar</button><span data-create-action hidden><button type="submit" disabled class="scm-new-case-primary">Crear caso</button></span></div></footer>
      </form>`;
      const form = current.querySelector('form');
      let assistantChoice = '';
      let scheduleDraft = () => {};
      const error = current.querySelector('[data-error]');
      const showError = message => { error.textContent = message; error.hidden = !message; };
      const searchInput = current.querySelector('[data-search]');
      const searchBy = current.querySelector('[data-search-by]');
      const results = current.querySelector('[data-results]');
      const status = current.querySelector('[data-search-status]');
      const summary = current.querySelector('[data-selected]');
      const contractSteps = current.querySelector('[data-contract-steps]');
      const caseDetails = current.querySelector('[data-case-details]');
      const setContractStepsVisible = visible => {
        contractSteps.hidden = !visible;
        contractSteps.disabled = !visible;
        current.querySelector('[data-create-action]').hidden = !visible || !assistantChoice;
        form.querySelector('[type=submit]').disabled = !visible || !assistantChoice;
      };
      const invalidateSelection = () => { sequence++; resetAi(); assistantChoice = ''; selected = null; setAssistantChoice(''); summary.hidden = true; setContractStepsVisible(false); showError(''); results.replaceChildren(); status.textContent = 'Busca y selecciona el contrato correspondiente.'; };
      const selectContract = contract => {
        selected = contract;
        results.replaceChildren();
        summary.innerHTML = `<strong>Contrato ${escape(contract.contrato)} · SIMI ${escape(contract.inmueble)}</strong><p class="mt-1">${escape(contract.direccion)}</p><p class="text-xs mt-1">Propietario: ${escape(contract.propietario)} · Arrendatario: ${escape(contract.arrendatario)}</p>`;
        summary.hidden = false; status.textContent = 'Contrato seleccionado. Puedes cambiarlo realizando otra búsqueda.';
        setContractStepsVisible(true);
        current.querySelector('[data-assistant-choice="yes"]').focus();
        scheduleDraft();
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
            button.type = 'button'; button.className = 'scm-new-case-result';
            button.innerHTML = `<strong>Contrato ${escape(contract.contrato)} · SIMI ${escape(contract.inmueble)}</strong><span>${escape(contract.direccion)} · ${escape(contract.estado)}</span><span>Propietario: ${escape(contract.propietario)} · Arrendatario: ${escape(contract.arrendatario)}</span>`;
            button.onclick = () => selectContract(contract);
            results.append(button);
          });
        } catch (e) { if (run === sequence && current.isConnected) status.textContent = e.message; }
      };
      current.querySelector('[data-search-button]').onclick = search;
      searchInput.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); search(); } };
      form.elements.tema_ayuda.onchange = () => {
        const suggested = config.theme_departments[form.elements.tema_ayuda.value];
        if (config.departments.includes(suggested)) form.elements.departamento.value = suggested;
      };
      form.elements.id_empleado.onchange = () => {
        const employee = config.employees.find(e => e.employee_id === form.elements.id_empleado.value);
        current.querySelector('[data-assignee]').textContent = employee ? (employee.email ? `Correo: ${employee.email}. ${config.whatsapp_enabled ? `WhatsApp: ${employee.phone || 'falta celular válido en su ficha'}.` : 'WhatsApp pendiente de activar en la configuración interna del sistema.'}` : 'Este funcionario necesita un correo válido para recibir la asignación.') : 'Selecciona un funcionario para consultar los canales de aviso.';
      };
      const aiSection = current.querySelector('[data-ai-section]');
      const aiInputs = current.querySelector('[data-ai-inputs]');
      const aiText = current.querySelector('[data-ai-text]');
      const aiFileInput = current.querySelector('[data-ai-images]');
      const aiStatus = current.querySelector('[data-ai-status]');
      const aiResult = current.querySelector('[data-ai-result]');
      const aiAnalyze = current.querySelector('[data-ai-analyze]');
      const aiApply = current.querySelector('[data-ai-apply]');
      const aiUndo = current.querySelector('[data-ai-undo]');
      const aiSources = [];
      const aiFields = ['asunto', 'descripcion', 'tema_ayuda', 'departamento'];
      let aiDraft = null;
      let aiUndoValues = null;
      const setAssistantChoice = value => {
        assistantChoice = ['yes', 'no'].includes(value) ? value : '';
        caseDetails.hidden = !assistantChoice; caseDetails.disabled = !assistantChoice;
        aiSection.hidden = assistantChoice !== 'yes';
        aiInputs.disabled = !config.ai?.enabled || assistantChoice !== 'yes';
        current.querySelectorAll('[data-assistant-choice]').forEach(button => {
          const active = button.dataset.assistantChoice === assistantChoice;
          button.setAttribute('aria-pressed', String(active)); button.classList.toggle('is-active', active);
        });
        setContractStepsVisible(!!selected);
      };
      current.querySelectorAll('[data-assistant-choice]').forEach(button => button.onclick = () => {
        if (button.dataset.assistantChoice === 'no') invalidateAiDraft();
        setAssistantChoice(button.dataset.assistantChoice); scheduleDraft();
        if (assistantChoice === 'yes' && config.ai?.enabled) aiText.focus(); else form.elements.asunto.focus();
      });
      const invalidateAiDraft = () => {
        aiSequence++; aiController?.abort(); aiController = null;
        aiInputs.disabled = !config.ai?.enabled || assistantChoice !== 'yes';
        aiAnalyze.textContent = 'Analizar solicitud'; aiSection.removeAttribute('aria-busy');
        aiDraft = null; aiUndoValues = null; aiResult.hidden = true; aiStatus.textContent = '';
        aiApply.disabled = false; aiUndo.hidden = true;
      };
      const renderAiSources = () => {
        current.querySelector('[data-ai-previews]').innerHTML = aiSources.map((image, index) => `<figure class="min-w-0 rounded-xl border border-slate-200 bg-white p-2"><img src="${image.url}" alt="Captura para analizar ${index + 1}" class="h-32 w-full rounded-lg object-contain"><figcaption class="mt-2 flex flex-wrap items-center justify-between gap-2"><span class="break-all text-xs text-slate-600">${escape(image.file.name)}</span><button type="button" data-ai-remove="${index}" class="scm-new-case-button" aria-label="Quitar captura ${index + 1}">Quitar</button></figcaption></figure>`).join('');
        scheduleDraft();
      };
      const clearAiSources = () => { aiSources.forEach(image => URL.revokeObjectURL(image.url)); aiSources.length = 0; };
      const resetAi = () => { invalidateAiDraft(); clearAiSources(); aiText.value = ''; aiFileInput.value = ''; renderAiSources(); };
      current.addEventListener('close', clearAiSources);
      aiText.oninput = invalidateAiDraft;
      const addAiImages = blobs => {
        if (busy || aiController || !selected || !config.ai?.enabled || !current.isConnected) return;
        const types = {'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp'};
        const combined = [...aiSources.map(image => image.file), ...blobs];
        if (combined.length > 4 || blobs.some(blob => !types[blob.type] || !blob.size || blob.size > 5 * 1024 * 1024)
          || combined.reduce((sum, file) => sum + file.size, 0) > 12 * 1024 * 1024) {
          aiStatus.textContent = 'Usa máximo 4 capturas JPG, PNG o WebP, 5 MB por captura y 12 MB en total.'; return;
        }
        invalidateAiDraft();
        blobs.forEach((blob, index) => {
          const file = new File([blob], blob.name || `captura-asistente-${Date.now()}-${index + 1}.${types[blob.type]}`, {type: blob.type});
          aiSources.push({file, url: URL.createObjectURL(file)});
        });
        renderAiSources(); aiStatus.textContent = 'Capturas listas para analizar. Puedes añadir contexto en el texto.';
      };
      aiFileInput.onchange = () => { addAiImages([...aiFileInput.files]); aiFileInput.value = ''; };
      current.querySelector('[data-ai-previews]').onclick = event => {
        const button = event.target.closest('[data-ai-remove]');
        if (!button || busy || aiController) return;
        invalidateAiDraft();
        const [removed] = aiSources.splice(Number(button.dataset.aiRemove), 1);
        if (removed) URL.revokeObjectURL(removed.url);
        renderAiSources();
      };
      aiSection.addEventListener('paste', event => {
        if (!config.ai?.enabled || !selected || busy || aiController) return;
        const images = [...(event.clipboardData?.items || [])].filter(item => item.type.startsWith('image/')).map(item => item.getAsFile()).filter(Boolean);
        if (!images.length) return;
        event.preventDefault(); event.stopPropagation(); addAiImages(images);
      });
      current.querySelector('[data-ai-paste]').onclick = async () => {
        if (!navigator.clipboard?.read) { aiStatus.textContent = 'Copia la captura y presiona Ctrl+V dentro del contenido del asistente.'; aiText.focus(); return; }
        try {
          const items = await navigator.clipboard.read();
          const images = [];
          for (const item of items) {
            const type = item.types.find(type => ['image/png', 'image/jpeg', 'image/webp'].includes(type));
            if (type) images.push(await item.getType(type));
          }
          if (images.length) addAiImages(images);
          else aiStatus.textContent = 'No hay una captura en el portapapeles. Copia una imagen o pega el texto del mensaje.';
        } catch (_) { aiStatus.textContent = 'No se pudo leer el portapapeles. Usa Ctrl+V dentro del contenido del asistente.'; aiText.focus(); }
      };
      aiAnalyze.onclick = async () => {
        if (busy || aiController || !selected || !config.ai?.enabled) return;
        if (!aiText.value.trim() && !aiSources.length) { aiStatus.textContent = 'Pega el contenido del mensaje o agrega una captura.'; aiText.focus(); return; }
        invalidateAiDraft();
        const run = ++aiSequence;
        const contractId = String(selected._ID);
        const data = new FormData(); data.set('contract_id', contractId); data.set('source_text', aiText.value);
        aiSources.forEach(image => data.append('ai_images[]', image.file, image.file.name));
        aiController = new AbortController();
        aiInputs.disabled = true; aiAnalyze.textContent = 'Analizando…'; aiSection.setAttribute('aria-busy', 'true');
        aiStatus.textContent = 'Leyendo la solicitud y preparando una propuesta…';
        try {
          const response = await api('scm_panel_case_analyze', data, aiController.signal);
          if (!current.isConnected || run !== aiSequence || String(selected?._ID) !== contractId || String(response.contract_id) !== contractId) return;
          aiDraft = response.draft;
          const labels = {asunto: 'Título', descripcion: 'Descripción', tema_ayuda: 'Tema', departamento: 'Departamento', observaciones: 'Por revisar'};
          current.querySelector('[data-ai-draft]').innerHTML = Object.entries(labels).map(([key, label]) => `<div><p class="text-xs font-semibold text-slate-500">${label}</p><p class="mt-1 whitespace-pre-wrap break-words">${escape(aiDraft[key] || (key === 'observaciones' ? 'Revisa que el resumen corresponda a la solicitud.' : 'Seleccionar manualmente'))}</p></div>`).join('');
          aiResult.hidden = false; aiStatus.textContent = 'Propuesta lista. Al aplicarla se reemplazan título y descripción; el responsable y los avisos los eliges tú.';
        } catch (e) {
          if (run === aiSequence && current.isConnected && e.name !== 'AbortError') aiStatus.textContent = e.message;
        } finally {
          if (run === aiSequence && current.isConnected) {
            aiController = null; aiInputs.disabled = false; aiAnalyze.textContent = 'Analizar solicitud'; aiSection.removeAttribute('aria-busy');
          }
        }
      };
      aiApply.onclick = () => {
        if (!aiDraft || busy || !selected) return;
        aiUndoValues = Object.fromEntries(aiFields.map(key => [key, form.elements[key].value]));
        form.elements.asunto.value = aiDraft.asunto;
        form.elements.descripcion.value = aiDraft.descripcion;
        if (config.themes.includes(aiDraft.tema_ayuda)) form.elements.tema_ayuda.value = aiDraft.tema_ayuda;
        if (config.departments.includes(aiDraft.departamento)) form.elements.departamento.value = aiDraft.departamento;
        aiUndo.hidden = false; aiApply.disabled = true;
        aiStatus.textContent = 'Datos completados. Revisa los campos, selecciona el responsable y decide los adjuntos y avisos antes de crear el caso.';
        form.elements.asunto.focus();
        scheduleDraft();
      };
      aiUndo.onclick = () => {
        if (!aiUndoValues || busy) return;
        aiFields.forEach(key => { form.elements[key].value = aiUndoValues[key]; });
        aiUndoValues = null; aiUndo.hidden = true; aiApply.disabled = false;
        aiStatus.textContent = 'Se restauraron los datos que tenías antes del autocompletado.';
        scheduleDraft();
      };
      const fileInputs = [...form.querySelectorAll('[name="imagenes[]"], [name="archivos[]"]')];
      const pastedImages = [];
      const pastedPreview = current.querySelector('[data-pasted-images]');
      const pasteStatus = current.querySelector('[data-paste-status]');
      let captureNumber = 0;
      const files = () => [...fileInputs.flatMap(input => [...input.files]), ...pastedImages.map(image => image.file)];
      const renderFiles = () => {
        current.querySelector('[data-files]').innerHTML = files().map(file => `<li>${escape(file.name)} · ${(file.size / 1024 / 1024).toFixed(1)} MB</li>`).join('');
        pastedPreview.innerHTML = pastedImages.map((image, index) => `<figure class="min-w-0 rounded-xl border border-slate-200 bg-white p-3"><img src="${image.url}" alt="Vista previa de ${escape(image.file.name)}" class="h-32 w-full rounded-lg object-contain"><figcaption class="mt-2 flex items-center justify-between gap-2"><span class="min-w-0 break-all text-xs text-slate-600">${escape(image.file.name)}</span><button type="button" data-remove-pasted="${index}" class="scm-new-case-button" aria-label="Quitar ${escape(image.file.name)}">Quitar</button></figcaption></figure>`).join('');
        scheduleDraft();
      };
      const clearPastedImages = () => {
        pastedImages.forEach(image => URL.revokeObjectURL(image.url));
        pastedImages.length = 0;
      };
      current.addEventListener('close', clearPastedImages);
      fileInputs.forEach(input => { input.onchange = renderFiles; input.disabled = true; });
      current.querySelectorAll('[data-pick-media]').forEach(button => button.onclick = () => {
        const target = button.dataset.pickMedia;
        (target === 'assistant' ? aiFileInput : form.querySelector(target === 'images' ? '[name="imagenes[]"]' : '[name="archivos[]"]')).click();
      });
      form.elements.has_attachments.onchange = () => {
        const enabled = form.elements.has_attachments.value === 'Si';
        current.querySelector('[data-attachments]').hidden = !enabled;
        fileInputs.forEach(input => { input.disabled = !enabled; if (!enabled) input.value = ''; });
        if (!enabled) clearPastedImages();
        renderFiles();
      };
      const addPastedImages = blobs => {
        if (busy || !selected || !assistantChoice || !current.isConnected) return;
        const allowedTypes = {'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp'};
        if (blobs.some(blob => !allowedTypes[blob.type])) { showError('Pega imágenes en formato JPG, PNG o WebP.'); return; }
        const combined = [...files(), ...blobs];
        if (combined.length > 10 || blobs.some(blob => !blob.size || blob.size > config.max_file_bytes) || combined.reduce((sum, file) => sum + file.size, 0) > 25 * 1024 * 1024) {
          showError('La captura supera los límites: máximo 10 adjuntos, el límite indicado por archivo y 25 MB en total.'); return;
        }
        form.elements.has_attachments.value = 'Si';
        form.elements.has_attachments.onchange();
        blobs.forEach(blob => {
          const file = new File([blob], `captura-${Date.now()}-${++captureNumber}.${allowedTypes[blob.type]}`, {type: blob.type});
          pastedImages.push({file, url: URL.createObjectURL(file)});
        });
        renderFiles();
        showError('');
        pasteStatus.textContent = blobs.length === 1 ? 'Captura agregada. Puedes pegar otra o quitarla antes de guardar.' : `${blobs.length} capturas agregadas. Puedes revisarlas antes de guardar.`;
      };
      pastedPreview.onclick = event => {
        const button = event.target.closest('[data-remove-pasted]');
        if (!button || busy) return;
        const [removed] = pastedImages.splice(Number(button.dataset.removePasted), 1);
        if (removed) URL.revokeObjectURL(removed.url);
        renderFiles();
        pasteStatus.textContent = 'Captura quitada. Puedes pegar otra con Ctrl+V.';
      };
      form.addEventListener('paste', event => {
        if (busy || !selected || !assistantChoice) return;
        if (event.target.closest('[data-ai-section]')) return;
        const clipboard = event.clipboardData;
        if (!clipboard) return;
        let images = [...(clipboard.items || [])].filter(item => item.type.startsWith('image/')).map(item => item.getAsFile()).filter(Boolean);
        if (!images.length) images = [...(clipboard.files || [])].filter(file => file.type.startsWith('image/'));
        if (!images.length) return;
        event.preventDefault();
        addPastedImages(images);
      });
      current.querySelector('[data-paste-image]').onclick = async () => {
        pasteStatus.textContent = 'Si el navegador pide acceso, permite leer la captura; también puedes presionar Ctrl+V.';
        if (!navigator.clipboard?.read) { pasteStatus.textContent = 'Copia una captura y presiona Ctrl+V dentro de este popup.'; return; }
        try {
          const items = await navigator.clipboard.read();
          const images = [];
          for (const item of items) {
            const type = item.types.find(type => ['image/png', 'image/jpeg', 'image/webp'].includes(type));
            if (type) images.push(await item.getType(type));
          }
          if (images.length) addPastedImages(images);
          else pasteStatus.textContent = 'No hay una imagen en el portapapeles. Copia una captura y presiona Ctrl+V.';
        } catch (_) { pasteStatus.textContent = 'No se pudo leer el portapapeles. Presiona Ctrl+V dentro de este popup para pegar la captura.'; }
      };
      current.querySelector('[data-cancel]').onclick = close;
      const draftStatus = current.querySelector('[data-draft-status]');
      const draftScope = typeof config.draft_scope === 'string' ? config.draft_scope : '';
      const draftFields = [...aiFields, 'id_empleado', 'has_attachments'];
      let draftReady = false;
      let draftFinished = false;
      let draftTimer;
      let draftWrites = Promise.resolve();
      const snapshotDraft = () => ({
        version: 1, expires: Date.now() + 24 * 3600 * 1000, requestId,
        selected, query: searchInput.value, by: searchBy.value, assistantChoice,
        fields: Object.fromEntries(draftFields.map(key => [key, form.elements[key].value])),
        notifyRoles: [...form.querySelectorAll('[name="notify_roles[]"]:checked')].map(input => input.value),
        sourceText: aiText.value, sourceImages: aiSources.map(image => image.file),
        images: [...fileInputs[0].files, ...pastedImages.map(image => image.file)], documents: [...fileInputs[1].files],
      });
      persistDraft = async () => {
        clearTimeout(draftTimer);
        if (!draftReady || draftFinished || !draftScope) return;
        const snapshot = snapshotDraft();
        const hasContent = selected || snapshot.query || snapshot.sourceText || snapshot.images.length || snapshot.documents.length || snapshot.sourceImages.length || Object.values(snapshot.fields).some(Boolean);
        draftStatus.textContent = 'Guardando borrador…';
        draftWrites = draftWrites.then(() => draftStore(draftScope, hasContent ? snapshot : null)).then(() => {
          if (current.isConnected && !draftFinished) draftStatus.textContent = hasContent ? 'Borrador guardado en este navegador. Se puede recuperar durante 24 horas, incluidos los archivos.' : 'El borrador se guardará en este navegador durante 24 horas.';
          return true;
        }).catch(() => { if (current.isConnected) draftStatus.textContent = 'No se pudo autoguardar. Mantén este formulario abierto para conservar los datos y archivos.'; return false; });
        return await draftWrites;
      };
      scheduleDraft = () => { if (draftReady && !draftFinished) { clearTimeout(draftTimer); draftTimer = setTimeout(persistDraft, 200); } };
      form.addEventListener('input', scheduleDraft);
      form.addEventListener('change', scheduleDraft);
      const onPageHide = () => { persistDraft(); };
      const onVisibility = () => { if (document.visibilityState === 'hidden') persistDraft(); };
      window.addEventListener('pagehide', onPageHide);
      document.addEventListener('visibilitychange', onVisibility);
      current.addEventListener('close', () => {
        clearTimeout(draftTimer); window.removeEventListener('pagehide', onPageHide); document.removeEventListener('visibilitychange', onVisibility);
      });
      current.querySelector('[data-discard-draft]').onclick = () => { current.querySelector('[data-discard-confirm]').hidden = false; };
      current.querySelector('[data-keep-draft]').onclick = () => { current.querySelector('[data-discard-confirm]').hidden = true; };
      current.querySelector('[data-confirm-discard]').onclick = async () => {
        if (busy) return;
        clearTimeout(draftTimer); draftReady = false;
        await draftWrites;
        try { if (draftScope) await draftStore(draftScope, null); }
        catch (_) { draftStatus.textContent = 'No se pudo descartar el borrador. Intenta nuevamente.'; draftReady = true; return; }
        form.reset(); invalidateSelection(); clearPastedImages(); fileInputs.forEach(input => { input.value = ''; input.disabled = true; }); renderFiles();
        requestId = crypto.randomUUID(); current.querySelector('[data-discard-confirm]').hidden = true;
        draftStatus.textContent = 'Borrador descartado. Puedes empezar un caso nuevo.'; draftReady = true; searchInput.focus();
      };
      form.onsubmit = async event => {
        event.preventDefault();
        if (aiController) { showError('Espera a que termine el análisis antes de crear el caso.'); return; }
        if (busy || !form.reportValidity()) return;
        if (!assistantChoice) { showError('Elige si quieres usar el asistente o completar el caso manualmente.'); return; }
        showError('');
        if (!selected) { showError('Busca y selecciona un contrato antes de crear el caso.'); searchInput.focus(); return; }
        const attachments = files();
        if ((form.elements.has_attachments.value === 'Si' && !attachments.length) || attachments.length > 10 || attachments.some(f => f.size > config.max_file_bytes) || attachments.reduce((sum, f) => sum + f.size, 0) > 25 * 1024 * 1024) {
          showError('Revisa los adjuntos: agrega al menos uno si elegiste Sí, máximo 10 archivos y respeta los límites de tamaño.'); return;
        }
        const data = new FormData(form); data.set('contract_id', selected._ID); data.set('request_id', requestId);
        pastedImages.forEach(image => data.append('imagenes[]', image.file, image.file.name));
        busy = true;
        const submit = form.querySelector('[type=submit]');
        submit.textContent = 'Creando caso…';
        const controls = [...current.querySelectorAll('input, textarea, select, button')];
        const states = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        current.setAttribute('aria-busy', 'true');
        try {
          const response = await api('scm_panel_case_create', data);
          busy = false;
          draftFinished = true; clearTimeout(draftTimer); await draftWrites;
          try { if (draftScope) await draftStore(draftScope, null); } catch (_) { /* The saved request ID still prevents duplicate case creation on a later recovery. */ }
          clearPastedImages();
          current.removeAttribute('aria-busy');
          current.querySelector('[data-close]').disabled = false;
          current.querySelector('[data-content]').innerHTML = `<div class="p-6 text-center flex flex-col items-center gap-4"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" class="text-emerald-700"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg><h3 class="text-xl font-bold text-slate-900">Caso #${escape(response.ticket_id)} creado</h3><p role="status" class="text-sm text-slate-600">${escape(response.message)}</p>${response.notification_details?.length ? `<div class="w-full rounded-xl border border-slate-200 bg-slate-50 p-4 text-left"><h4 class="text-sm font-semibold text-slate-900">Detalle de los avisos de WhatsApp</h4><ul class="mt-3 text-sm text-slate-600 flex flex-col gap-2">${response.notification_details.map(detail => `<li>${escape(detail)}</li>`).join('')}</ul></div>` : ''}<button type="button" data-done class="scm-new-case-primary">Aceptar</button></div>`;
          let openingCreatedCase = false;
          const openCreatedCase = async () => {
            if (openingCreatedCase) return;
            openingCreatedCase = true;
            current.querySelector('[data-done]').disabled = true;
            await close();
            root.dispatchEvent(new CustomEvent('scm:open-panel-case', {detail: {ticket_id: response.ticket_id}}));
          };
          current.querySelector('[data-done]').onclick = openCreatedCase;
          current.querySelector('[data-done]').focus();
          root.dispatchEvent(new CustomEvent('scm:panel-case-created', {detail: response}));
        } catch (e) {
          busy = false; current.removeAttribute('aria-busy');
          controls.forEach((control, index) => { control.disabled = states[index]; });
          submit.textContent = 'Crear caso'; showError(e.message);
        }
      };
      if (draftScope) {
        searchInput.disabled = searchBy.disabled = current.querySelector('[data-search-button]').disabled = true;
        try {
          const saved = await draftStore(draftScope);
          if (!current.isConnected) return;
          if (saved?.version === 1 && saved.expires > Date.now()) {
            if (/^[a-f0-9-]{36}$/.test(saved.requestId)) requestId = saved.requestId;
            searchInput.value = saved.query || ''; searchBy.value = saved.by || 'contrato';
            if (saved.selected?._ID) {
              const data = new FormData();
              const useNumber = String(saved.selected.contrato || '').length >= 2;
              data.set('by', useNumber ? 'contrato' : saved.by); data.set('query', useNumber ? saved.selected.contrato : saved.query);
              try {
                const response = await api('scm_panel_case_search', data);
                if (!current.isConnected) return;
                const contract = response.contracts.find(item => String(item._ID) === String(saved.selected._ID));
                if (contract) selectContract(contract);
              } catch (_) { /* Recover fields and files even when contract revalidation must be retried. */ }
            }
            draftFields.forEach(key => { if (typeof saved.fields?.[key] === 'string') form.elements[key].value = saved.fields[key]; });
            form.querySelectorAll('[name="notify_roles[]"]').forEach(input => { input.checked = (saved.notifyRoles || []).includes(input.value); });
            aiText.value = typeof saved.sourceText === 'string' ? saved.sourceText : '';
            (saved.sourceImages || []).filter(file => file instanceof File).forEach(file => aiSources.push({file, url: URL.createObjectURL(file)}));
            (saved.images || []).filter(file => file instanceof File).forEach(file => pastedImages.push({file, url: URL.createObjectURL(file)}));
            const documents = new DataTransfer(); (saved.documents || []).filter(file => file instanceof File).forEach(file => documents.items.add(file)); fileInputs[1].files = documents.files;
            setAssistantChoice(selected ? saved.assistantChoice : '');
            current.querySelector('[data-attachments]').hidden = form.elements.has_attachments.value !== 'Si';
            fileInputs.forEach(input => { input.disabled = form.elements.has_attachments.value !== 'Si'; });
            renderAiSources(); renderFiles(); form.elements.id_empleado.onchange();
            draftStatus.textContent = selected ? 'Borrador recuperado, incluidos los archivos. Revisa los datos antes de crear el caso.' : 'Borrador recuperado. Selecciona nuevamente el contrato para continuar.';
          }
        } catch (_) { draftStatus.textContent = 'El autoguardado no está disponible en este navegador. Mantén abierto el formulario para conservarlo.'; }
        finally { searchInput.disabled = searchBy.disabled = current.querySelector('[data-search-button]').disabled = false; }
      } else draftStatus.textContent = 'El autoguardado no está disponible. Mantén abierto el formulario para conservar los datos.';
      draftReady = true;
      searchInput.focus();
    } catch (e) {
      if (current.isConnected) current.querySelector('[data-content]').innerHTML = `<p role="alert" class="text-sm text-rose-700">${escape(e.message)}</p>`;
    }
  });
})();
