(function () {
  'use strict';
  let dialog = null;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const option = (value, label) => `<option value="${escape(value)}">${escape(label ?? value)}</option>`;

  window.addEventListener('scm:open-nuevo-ticket', async () => {
    if (dialog) { if (!dialog.open) dialog.showModal(); return; }
    const root = document.querySelector('[data-scm-runtime]');
    if (!root) return;
    const runtime = JSON.parse(root.dataset.scmRuntime || '{}');
    const opener = document.activeElement;
    let busy = false;
    let selected = null;
    let sequence = 0;
    const requestId = crypto.randomUUID();
    dialog = document.createElement('dialog');
    dialog.className = 'scm-new-case';
    dialog.setAttribute('aria-labelledby', 'scm-new-case-title');
    dialog.innerHTML = `<header class="scm-new-case-header">
      <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">SKC SuCasa Inmobiliaria</p><h2 id="scm-new-case-title" class="text-xl font-bold text-slate-900 mt-1">Crear nuevo caso</h2><p class="text-sm text-slate-500 mt-1">Vincula un contrato y asigna la gestión a tu equipo.</p></div>
      <button type="button" data-close class="scm-new-case-icon" aria-label="Cerrar popup"><span class="material-symbols-outlined">close</span></button>
    </header><div data-content class="p-6"><p role="status" class="text-sm text-slate-500">Cargando temas y funcionarios…</p></div>`;
    document.body.append(dialog);
    dialog.showModal();
    const close = () => {
      if (busy) return;
      dialog.close();
    };
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('close', () => { sequence++; dialog.remove(); dialog = null; opener?.focus(); });
    dialog.querySelector('[data-close]').onclick = close;
    const current = dialog;
    const api = async (action, data = new FormData()) => {
      data.set('action', action);
      data.set('nonce', runtime.nonce);
      const response = await fetch(runtime.ajaxUrl, {method: 'POST', body: data, credentials: 'same-origin'});
      let json;
      try { json = await response.json(); } catch (_) { throw new Error('El servidor no respondió correctamente. Revisa la conexión e inténtalo nuevamente.'); }
      if (!json.success) throw new Error(json.data?.message || 'No se pudo completar la solicitud.');
      return json.data;
    };
    try {
      const config = await api('scm_panel_case_options');
      if (!current.isConnected) return;
      current.querySelector('[data-content]').innerHTML = `<form data-case-form class="flex flex-col gap-6">
        <section class="flex flex-col gap-3"><h3 class="scm-new-case-section"><span>1</span> Encuentra el contrato</h3>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="scm-new-case-label">Buscar por<select data-search-by>${option('contrato', 'Contrato')}${option('inmueble', 'Inmueble SIMI')}${option('propietario', 'Propietario')}${option('arrendatario', 'Arrendatario')}</select></label>
            <label class="scm-new-case-label sm:col-span-2">Número, código o nombre<div class="flex gap-2"><input type="search" data-search maxlength="100" placeholder="Escribe al menos 2 caracteres" class="min-w-0 flex-1"><button type="button" data-search-button class="scm-new-case-button">Buscar</button></div></label>
          </div><p data-search-status role="status" aria-live="polite" class="text-xs text-slate-500">Busca y selecciona el contrato correspondiente.</p>
          <div data-results class="flex flex-col gap-2 max-h-56 overflow-y-auto"></div>
          <div data-selected hidden class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-slate-700"></div>
        </section>
        <fieldset data-contract-steps hidden disabled class="min-w-0"><div class="flex flex-col gap-6">
        <section class="flex flex-col gap-4"><h3 class="scm-new-case-section"><span>2</span> Describe y asigna el caso</h3>
          <label class="scm-new-case-label">Título<input name="asunto" required maxlength="200" placeholder="Resume la solicitud"></label>
          <label class="scm-new-case-label">Descripción<textarea name="descripcion" rows="4" required maxlength="10000" placeholder="Describe qué ocurre y qué gestión se necesita"></textarea></label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="scm-new-case-label">Tema<select name="tema_ayuda" required>${option('', 'Selecciona un tema')}${config.themes.map(t => option(t)).join('')}</select></label>
            <label class="scm-new-case-label">Departamento<select name="departamento" required>${option('', 'Selecciona el departamento')}${config.departments.map(t => option(t)).join('')}</select></label>
          </div>
          <label class="scm-new-case-label">Asignar a<select name="id_empleado" required>${option('', 'Selecciona un funcionario activo')}${config.employees.map(e => option(e.employee_id, `${e.name} · ${e.cargo}${e.email ? '' : ' · Sin correo'}`)).join('')}</select></label>
          <p data-assignee class="text-xs text-slate-500">El responsable recibirá un correo con los datos del caso.</p>
        </section>
        <section class="flex flex-col gap-4"><h3 class="scm-new-case-section"><span>3</span> Adjunta las evidencias</h3>
          <label class="scm-new-case-label">¿El caso tiene adjuntos?<select name="has_attachments" required>${option('', 'Selecciona una opción')}${option('No', 'No, continuar sin adjuntos')}${option('Si', 'Sí, agregar imágenes o documentos')}</select></label>
          <div data-attachments hidden><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="scm-new-case-label">Imágenes<input type="file" name="imagenes[]" accept="image/jpeg,image/png,image/webp" multiple></label>
            <label class="scm-new-case-label">Documentos PDF<input type="file" name="archivos[]" accept="application/pdf,.pdf" multiple></label>
          </div><p class="text-xs text-slate-500 mt-3">Hasta 10 archivos, ${Math.floor(config.max_file_bytes / 1024 / 1024)} MB por archivo y 25 MB en total. Imágenes JPG, PNG o WebP de máximo 16 megapíxeles.</p><ul data-files class="mt-3 text-xs text-slate-600 flex flex-col gap-1"></ul></div>
        </section>
        </div></fieldset>
        <p data-error hidden role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></p>
        <footer class="border-t border-slate-200 pt-4 flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-slate-500 max-w-sm">Los avisos se encolan al guardar. Las copias se configuran en Notificaciones internas.</p><div class="flex gap-2"><button type="button" data-cancel class="scm-new-case-button">Cancelar</button><span data-create-action hidden><button type="submit" disabled class="scm-new-case-primary">Crear caso</button></span></div></footer>
      </form>`;
      const form = current.querySelector('form');
      const error = current.querySelector('[data-error]');
      const showError = message => { error.textContent = message; error.hidden = !message; };
      const searchInput = current.querySelector('[data-search]');
      const searchBy = current.querySelector('[data-search-by]');
      const results = current.querySelector('[data-results]');
      const status = current.querySelector('[data-search-status]');
      const summary = current.querySelector('[data-selected]');
      const contractSteps = current.querySelector('[data-contract-steps]');
      const setContractStepsVisible = visible => {
        contractSteps.hidden = !visible;
        contractSteps.disabled = !visible;
        current.querySelector('[data-create-action]').hidden = !visible;
        form.querySelector('[type=submit]').disabled = !visible;
      };
      const invalidateSelection = () => { sequence++; selected = null; summary.hidden = true; setContractStepsVisible(false); showError(''); results.replaceChildren(); status.textContent = 'Busca y selecciona el contrato correspondiente.'; };
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
            button.onclick = () => {
              selected = contract;
              results.replaceChildren();
              summary.innerHTML = `<strong>Contrato ${escape(contract.contrato)} · SIMI ${escape(contract.inmueble)}</strong><p class="mt-1">${escape(contract.direccion)}</p><p class="text-xs mt-1">Propietario: ${escape(contract.propietario)} · Arrendatario: ${escape(contract.arrendatario)}</p>`;
              summary.hidden = false; status.textContent = 'Contrato seleccionado. Puedes cambiarlo realizando otra búsqueda.';
              setContractStepsVisible(true);
              form.elements.asunto.focus();
            };
            results.append(button);
          });
        } catch (e) { if (run === sequence && current.isConnected) status.textContent = e.message; }
      };
      current.querySelector('[data-search-button]').onclick = search;
      searchInput.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); search(); } };
      form.elements.tema_ayuda.onchange = () => { form.elements.departamento.value = config.theme_departments[form.elements.tema_ayuda.value] || ''; };
      form.elements.id_empleado.onchange = () => {
        const employee = config.employees.find(e => e.employee_id === form.elements.id_empleado.value);
        current.querySelector('[data-assignee]').textContent = employee ? (employee.email ? `Aviso de asignación: ${employee.email}` : 'Este funcionario necesita un correo válido para recibir la asignación.') : 'El responsable recibirá un correo con los datos del caso.';
      };
      const fileInputs = [...form.querySelectorAll('input[type=file]')];
      const files = () => fileInputs.flatMap(input => [...input.files]);
      fileInputs.forEach(input => { input.onchange = () => {
        current.querySelector('[data-files]').innerHTML = files().map(file => `<li>${escape(file.name)} · ${(file.size / 1024 / 1024).toFixed(1)} MB</li>`).join('');
      }; input.disabled = true; });
      form.elements.has_attachments.onchange = () => {
        const enabled = form.elements.has_attachments.value === 'Si';
        current.querySelector('[data-attachments]').hidden = !enabled;
        fileInputs.forEach(input => { input.disabled = !enabled; if (!enabled) input.value = ''; });
        current.querySelector('[data-files]').replaceChildren();
      };
      current.querySelector('[data-cancel]').onclick = close;
      form.onsubmit = async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        showError('');
        if (!selected) { showError('Busca y selecciona un contrato antes de crear el caso.'); searchInput.focus(); return; }
        const attachments = files();
        if ((form.elements.has_attachments.value === 'Si' && !attachments.length) || attachments.length > 10 || attachments.some(f => f.size > config.max_file_bytes) || attachments.reduce((sum, f) => sum + f.size, 0) > 25 * 1024 * 1024) {
          showError('Revisa los adjuntos: agrega al menos uno si elegiste Sí, máximo 10 archivos y respeta los límites de tamaño.'); return;
        }
        const data = new FormData(form); data.set('contract_id', selected._ID); data.set('request_id', requestId);
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
          current.removeAttribute('aria-busy');
          current.querySelector('[data-close]').disabled = false;
          current.querySelector('[data-content]').innerHTML = `<div class="p-6 text-center flex flex-col items-center gap-4"><span class="material-symbols-outlined text-emerald-700 text-4xl">check_circle</span><h3 class="text-xl font-bold text-slate-900">Caso #${escape(response.ticket_id)} creado</h3><p role="status" class="text-sm text-slate-600">${escape(response.message)}</p><button type="button" data-done class="scm-new-case-primary">Volver al panel</button></div>`;
          current.querySelector('[data-done]').onclick = close;
          current.querySelector('[data-done]').focus();
          root.dispatchEvent(new CustomEvent('scm:panel-case-created', {detail: response}));
        } catch (e) {
          busy = false; current.removeAttribute('aria-busy');
          controls.forEach((control, index) => { control.disabled = states[index]; });
          submit.textContent = 'Crear caso'; showError(e.message);
        }
      };
      searchInput.focus();
    } catch (e) {
      if (current.isConnected) current.querySelector('[data-content]').innerHTML = `<p role="alert" class="text-sm text-rose-700">${escape(e.message)}</p>`;
    }
  });
})();
