const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

// Formulario del inventario en el popup del caso: secciones por tipo de inmueble, sugerencias, cobro,
// autoguardado con protección del borrador y envío en un solo JSON.
const js = file => fs.readFileSync(path.join(__dirname, '../public/assets/js', file), 'utf8');
const source = js('scm-admin.js');
const start = source.indexOf('  var DAMAGE_REVIEW_KINDS = {');
const editor = source.slice(start, source.indexOf('  function openTicketResponseEditor(', start));
assert(start > 0 && editor.includes('customForm'), 'Inventory editor config was not found');
const css = ['preventive-review.css', 'inventory-form.css'].map(file => fs.readFileSync(path.join(__dirname, '../public/assets/css', file), 'utf8')).join('\n');
const shots = process.env.INVENTORY_SHOTS || '';

const section = (key, label, group = 'general', index = 0, glossary = ['Puertas', 'Pisos']) => ({ key, label, group, index, glossary, floor: group !== 'general' });
const residential = { key: 'apartamento', rooms_label: 'Habitaciones', baths_label: 'Baños', labels: {}, requires: { garaje: 'tiene_parqueadero' }, order: ['balcon', 'elementos_principales', 'sala', 'habitaciones', 'banos', 'garaje', 'elementos_electricos', 'otros'], max_rooms: 10, max_baths: 10 };
const config = {
  editing: false, additional: false, inventoryId: 0, version: '', hasReport: false,
  state: { data: { tipo_inventario: 'Nuevo', categoria_inventario: 'No amoblado', tipo_de_inmueble: 'Apartamento', contrato: '900', inmueble: '77', direccion: 'Calle 1', registro_fotografico: 'https://drive.google.com/x', arrendatario: 'A', correo_arrendatario: 'a@example.invalid', celular_arrendatario: '300', tiene_parqueadero: 'No', tiene_deposito: 'No', tiene_delegado: '', fecha_linderos: '2026-10-02', cantidad_pisos: '1', cantidad_habitaciones: '1', cantidad_banos: '1', con_transporte: 'Si', servicios: [], acuerdos: [] }, boundaries: { inmueble_lin: [{ norte: 'Pasillo' }] }, sections: {}, furniture: {} },
  catalog: {
    sections: [section('balcon', 'Balcón'), section('elementos_principales', 'Elementos principales'), section('sala', 'Sala, comedor y hall'), section('bano_emergencia', 'Baño de emergencia'), section('habitacion_principal', 'Habitación 1', 'habitacion', 1), section('habitacion_dos', 'Habitación 2', 'habitacion', 2), section('bano_principal', 'Baño 1', 'bano', 1), section('garaje', 'Garaje'), section('bodega', 'Bodega'), section('elementos_electricos', 'Elementos eléctricos', 'general', 0, ['Tomas', 'Contador luz']), section('otros', 'Otros', 'general', 0, [])],
    roomTypes: ['Principal', 'Secundaria', 'Estudio', 'De servicio'], bathTypes: ['Principal', 'Social', 'Privado', 'Auxiliar', 'De servicio'], electrical: ['Tomas', 'Interruptores', 'Contador luz'],
    boundaries: [{ key: 'inmueble_lin', label: 'Inmueble', suffix: 'inmueble', sides: ['norte', 'sur'], multiple: false, when: '', required: true }, { key: 'parqueadero_lin', label: 'Parqueadero', suffix: 'parqueadero', sides: ['norte'], multiple: true, when: 'tiene_parqueadero', required: false }],
    profiles: { '': residential, Apartamento: residential, Local: { ...residential, key: 'local', labels: { sala: 'Sala' }, order: ['elementos_principales', 'sala', 'banos', 'garaje', 'elementos_electricos', 'bodega', 'otros'], max_rooms: 0 } },
    types: ['Nuevo', 'Anterior'], categories: ['No amoblado', 'Amoblado'], states: ['Bueno', 'Regular', 'Malo'], parking: ['Asignado', 'Privado'], services: ['Agua', 'Gas', 'Energia'], agreements: { Propietario: 'acuerdos_precontractuales_pro' },
    suggestions: { sala: { items: ['Ventanas'], materials: { Ventanas: ['Aluminio', 'PVC'] }, quantities: { Ventanas: '3' }, observations: { Ventanas: ['Vidrio rayado', 'Sin seguro'], Puerta: ['Con tope'] }, furniture: ['Sofá', 'Mesa de centro'] } },
  },
  previous: [], billing: { base: 76456, transporte: 8000 }, voice: false,
};
const formHtml = cfg => '<div class="scm-acta scm-inventory-review"><form data-inventory-form><script type="application/json" data-inventory-config>' + JSON.stringify(cfg).replace(/</g, '\\u003c') + '</script><div data-inventory-root></div><span data-corrective-review-message></span></form></div>';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });
    const errors = [];
    const posts = [];
    let serverDraft = null;
    let voiceReply = {};
    let audioHeader = '';
    page.on('pageerror', e => errors.push(e.message));
    await page.route('https://panel.test/', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><style>' + css + '</style></head><body><div id="app"><div id="modal"><div id="sub"><h3 class="scm-case-submodal-title"></h3><div class="scm-case-submodal-body"></div></div></div><button id="case" data-ticket-pk="5"></button></div></body></html>' }));
    await page.route('https://panel.test/api.php', async route => {
      const body = route.request().postDataBuffer().toString('utf8');
      const field = name => (body.match(new RegExp('name="' + name + '"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--')) || [])[1];
      const action = field('action');
      const operation = field('operation');
      posts.push({ action, operation, payload: field('payload'), revision: field('revision') });
      let data;
      if (action === 'scm_inventario_voz') {
        const buffer = route.request().postDataBuffer();
        const at = buffer.indexOf('RIFF');
        audioHeader = at >= 0 ? buffer.slice(at, at + 4).toString() + buffer.slice(at + 8, at + 12).toString() : '';
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: voiceReply }) });
        return;
      }
      if (action === 'scm_inventario_borrador') {
        data = operation === 'get' ? { draft: serverDraft } : operation === 'save' ? { status: 'saved', revision: Number(field('revision')) + 1 } : { status: 'deleted' };
      } else {
        data = { html: operation === 'read' ? formHtml(config) : '<div class="scm-acta"><p data-corrective-review-message></p>Guardado</div>', message: operation === 'create' ? 'Inventario guardado.' : '' };
      }
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await page.goto('https://panel.test/');
    await page.addScriptTag({ content: js('ticket-completion-form-state.js') });
    await page.addScriptTag({ content: js('admin-inventory-form.js') });
    await page.addScriptTag({ content: `
      window.saved = 0;
      function ensureCaseSubmodal() { return document.getElementById('sub'); }
      function findRootFromNode() { return document.getElementById('app'); }
      function parseRuntime() { return { ajaxUrl: 'https://panel.test/api.php', nonce: 'n', actions: {} }; }
      function setCaseSubmodalMeta() {}
      function scmNotify() {}
      function dispatchCaseActionSaved() { window.saved++; }
      function escHtml(v) { return String(v); }
      ${editor}
      window.openInventory = function () { openDamageReviewEditor(document.getElementById('modal'), document.getElementById('case'), 'inventario'); };
    ` });
    const open = async () => { await page.evaluate(() => window.openInventory()); await page.locator('[data-inventory-root] nav').waitFor(); };
    const chips = () => page.$$eval('[data-pane]', nodes => nodes.map(n => n.textContent.trim()));
    const go = name => page.locator('[data-pane]', { hasText: name }).first().click();
    const saves = () => posts.filter(p => p.action === 'scm_inventario_borrador' && p.operation === 'save');

    await open();
    assert.equal(await page.textContent('.scm-case-submodal-title'), 'Inventario del inmueble');
    assert.deepEqual(await chips(), ['Datos', 'Linderos', 'Balcón', 'Elementos principales', 'Sala, comedor y hall', 'Habitación 1 · Principal', 'Baño 1 · Principal', 'Elementos eléctricos', 'Otros', 'Cierre']);
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-1-datos.png'), fullPage: true });

    // Condicionales y secciones según la cantidad y el tipo de inmueble.
    assert.equal(await page.isVisible('select[data-b="tipo_parqueadero"]'), false);
    const pick = (key, value) => page.locator('[data-choice="' + key + '"][data-value="' + value + '"]').click();
    await pick('tiene_parqueadero', 'Si');
    assert.equal(await page.getAttribute('[data-choice="tiene_parqueadero"][data-value="Si"]', 'aria-checked'), 'true', 'Yes/No is a toggle');
    assert.equal(await page.isVisible('select[data-b="tipo_parqueadero"]'), true, 'Parking fields appear');
    await page.selectOption('select[data-b="tipo_parqueadero"]', 'Privado');
    await page.fill('input[data-b="parqueaderos"]', '1');
    await page.fill('input[data-b="numero_parqueadero"]', '12');
    assert.ok((await chips()).includes('Garaje'), 'Garage appears once there is parking');
    await page.selectOption('select[data-b="cantidad_habitaciones"]', '2');
    assert.ok((await chips()).includes('Habitación 2 · Secundaria'), 'A second room adds its section with a default type');
    await page.selectOption('select[data-b="tipo_de_inmueble"]', 'Local');
    let names = await chips();
    assert.deepEqual(names, ['Datos', 'Linderos', 'Elementos principales', 'Sala', 'Baño 1 · Principal', 'Garaje', 'Elementos eléctricos', 'Bodega', 'Otros', 'Cierre'], 'Local sections');
    assert.equal(await page.locator('select[data-b="cantidad_habitaciones"]').count(), 0, 'A local has no rooms');
    // Agregar espacios desde cualquier paso, varios a la vez, y quitarlos si sobran.
    await go('Sala');
    await page.locator('[data-space-open]').click();
    assert.equal(await page.locator('[data-space-pick][value="bano_emergencia"]').count(), 1, 'Hidden section can be added back');
    assert.equal(await page.isDisabled('[data-space-add]'), true, 'Nothing picked, nothing to add');
    await page.locator('[data-space-pick][value="bano_emergencia"]').check();
    await page.locator('[data-space-pick][value="balcon"]').check();
    assert.equal(await page.textContent('[data-space-add]'), 'Agregar 2 espacios');
    await page.locator('[data-space-add]').click();
    names = await chips();
    assert.ok(names.includes('Baño de emergencia') && names.includes('Balcón'), 'Several spaces added at once: ' + names.join(' | '));
    assert.equal(await page.locator('[data-pane].is-active').textContent(), 'Balcón', 'Opens the first added space in tab order');
    await go('Baño de emergencia');
    await page.locator('[data-space-remove="bano_emergencia"]').click();
    assert.ok(!(await chips()).includes('Baño de emergencia'), 'An empty added space can be removed');
    await go('Datos');
    await page.selectOption('select[data-b="tipo_de_inmueble"]', 'Apartamento');

    // Dos pisos: habitaciones por piso y piso por fila en las áreas comunes.
    await page.selectOption('select[data-b="cantidad_pisos"]', '2');
    await page.selectOption('select[data-room-count="habitacion:0"]', '1');
    await page.selectOption('select[data-room-count="habitacion:1"]', '1');
    names = await chips();
    assert.ok(names.includes('Habitación 1 · Principal · Piso 1') && names.includes('Habitación 2 · Secundaria · Piso 2'), 'Rooms by floor: ' + names.join(' | '));
    await go('Habitación 2');
    await page.selectOption('select[data-room-type="habitacion_dos"]', 'Estudio');
    assert.ok((await chips()).includes('Habitación 2 · Estudio · Piso 2'), 'Room type in the title');

    // Sugerencias: material y cantidad aprendidos.
    await go('Sala, comedor');
    await page.locator('[data-add-item="Ventanas"]').click();
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="m"]'), 'Aluminio');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="c"]'), '3');
    assert.equal(await page.locator('[data-add-item="Ventanas"]').count(), 0, 'A used suggestion leaves the chips');
    const obsOptions = () => page.evaluate(() => [...document.querySelectorAll('[data-rows="sections:sala"] [data-row]:last-child .scm-inv-o + datalist option')].map(o => o.value));
    assert.deepEqual(await obsOptions(), ['Vidrio rayado', 'Sin seguro'], 'Learned observations are offered for the element');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="o"]'), '', 'Observation starts empty (saved as Ninguna)');
    // Lista propia: aunque el material ya tenga valor, al tocarlo salen todas las opciones y se elige con un toque.
    const picks = () => page.evaluate(() => [...document.querySelectorAll('.scm-inv-suggest [data-suggest-pick]')].map(b => b.textContent));
    await page.locator('[data-rows="sections:sala"] [data-f="m"]').click();
    assert.deepEqual(await picks(), ['Aluminio', 'PVC'], 'Material field with a value still lists every learned material');
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-sugerencias.png') });
    await page.locator('.scm-inv-suggest [data-suggest-pick]', { hasText: 'PVC' }).click();
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="m"]'), 'PVC', 'Picking a suggestion fills the field');
    assert.equal(await page.locator('.scm-inv-suggest').count(), 0, 'The list closes after picking');
    await page.locator('[data-rows="sections:sala"] [data-f="o"]').click();
    assert.deepEqual(await picks(), ['Vidrio rayado', 'Sin seguro'], 'Observation field lists learned observations');
    await page.keyboard.type('seg');
    assert.deepEqual(await picks(), ['Sin seguro'], 'Typing filters the list');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="o"]'), 'Sin seguro', 'Keyboard picks a suggestion');
    await page.locator('[data-rows="sections:sala"] [data-f="o"]').fill('');
    await page.locator('[data-rows="sections:sala"] [data-f="m"]').fill('Aluminio');
    await page.locator('[data-row-add]').click();
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-row]:last-child [data-f="c"]'), '', 'A new row has no default quantity');
    await page.locator('[data-rows="sections:sala"] [data-row]:last-child [data-f="d"]').fill('Puerta');
    assert.deepEqual(await obsOptions(), ['Con tope'], 'Typing the element refreshes its observation list');
    // También recomienda lo ya escrito en otras filas del inventario.
    await page.locator('[data-rows="sections:sala"] [data-row]:last-child [data-f="m"]').click();
    assert.deepEqual(await page.evaluate(() => [...document.querySelectorAll('.scm-inv-suggest-group')].map(g => g.textContent)), ['Ya usados en este inventario'], 'Groups materials used in other rows');
    assert.deepEqual(await picks(), ['Aluminio'], 'Offers the material typed in another row');
    // Lista larga: cabe en la pantalla y se desplaza por dentro.
    await page.evaluate(() => { const l = document.getElementById(document.querySelector('[data-rows="sections:sala"] [data-row]:last-child [data-f="m"]').getAttribute('data-suggest')); l.innerHTML = Array.from({ length: 40 }, (_, i) => '<option value="Material ' + i + '">').join(''); });
    await page.locator('[data-rows="sections:sala"] [data-row]:last-child [data-f="d"]').click();
    await page.locator('[data-rows="sections:sala"] [data-row]:last-child [data-f="m"]').click();
    const box = await page.evaluate(() => { const b = document.querySelector('.scm-inv-suggest'); const r = b.getBoundingClientRect(); b.scrollTop = 9999; return { top: r.top, bottom: r.bottom, scrolls: b.scrollHeight > b.clientHeight, scrolled: b.scrollTop > 0, h: innerHeight }; });
    assert.ok(box.top >= 0 && box.bottom <= box.h && box.scrolls && box.scrolled, 'Long list stays on screen and scrolls: ' + JSON.stringify(box));
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-sugerencias-larga.png') });
    await page.locator('[data-rows="sections:sala"] [data-row]:last-child [data-f="d"]').click();
    await page.locator('[data-rows="sections:sala"] [data-row]:last-child [data-row-remove]').click();
    await page.selectOption('[data-rows="sections:sala"] [data-f="p"]', '2');
    await page.locator('[data-rows="sections:sala"] [data-state="Regular"]').click();
    await page.locator('[data-rows="sections:sala"] [data-f="o"]').fill('Vidrio "rayado"');
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-2-seccion.png'), fullPage: true });
    assert.ok((await chips()).some(c => c.startsWith('Sala') && c.endsWith('1')), 'Section chip shows its count');

    // Autoguardado en servidor: un solo guardado tras dejar de escribir.
    await page.waitForFunction(() => /Guardado/.test(document.querySelector('[data-draft-status]').textContent), null, { timeout: 8000 });
    assert.equal(saves().length, 1, 'Debounced into one save');
    assert.ok(saves()[0].payload.includes('Vidrio \\"rayado\\"'), 'Draft holds the row');

    // Un eléctrico registrado en una sección se agrega solo a Elementos eléctricos.
    await page.locator('[data-row-add]').click();
    await page.locator('[data-rows="sections:sala"] [data-row="1"] [data-f="d"]').fill('Tomas');
    await go('Elementos eléctricos');
    assert.ok((await page.textContent('.scm-inv-auto')).includes('Tomas'), 'Electrical item copied from the section');
    assert.ok((await chips()).some(c => c.startsWith('Elementos eléctricos') && c.endsWith('1')), 'Counted in the electrical chip');
    await go('Sala, comedor');
    await page.locator('[data-rows="sections:sala"] [data-row="1"] [data-row-remove]').click();
    await page.locator('[data-undo]').click();
    assert.equal(await page.locator('[data-rows="sections:sala"] [data-row]').count(), 2, 'Undo brings the row back');
    await page.locator('[data-rows="sections:sala"] [data-row="1"] [data-row-remove]').click();

    // Copiar filas entre secciones.
    await go('Habitación 1');
    await page.selectOption('[data-copy-from]', 'sala');
    assert.equal(await page.locator('[data-rows="sections:habitacion_principal"] [data-row]').count(), 1, 'Rows copied');
    await page.locator('[data-row-remove]').click();

    // Amoblado: muebles y cobro doble.
    await go('Datos');
    await pick('categoria_inventario', 'Amoblado');
    assert.ok(!(await chips()).includes('Muebles y enseres'), 'No separate furniture tab');
    await go('Sala, comedor');
    assert.equal(await page.textContent('.scm-inv-furniture h4'), 'Muebles y enseres 0', 'Furniture block inside the space tab');
    assert.deepEqual(await page.evaluate(() => [...document.querySelectorAll('[data-furniture-item]')].map(b => b.textContent)), ['Sofá', 'Mesa de centro'], 'Learned furniture chips for the space');
    await page.locator('[data-furniture-item="Mesa de centro"]').click();
    assert.equal(await page.inputValue('[data-rows="furniture:sala"] [data-f="d"]'), 'Mesa de centro', 'Chip adds the furniture row');
    assert.equal(await page.locator('[data-furniture-item="Mesa de centro"]').count(), 0, 'A used furniture chip leaves the list');
    await page.locator('[data-rows="furniture:sala"] [data-state="Malo"]').click();
    await page.locator('[data-furniture-all-good="sala"]').click();
    assert.equal(await page.getAttribute('[data-rows="furniture:sala"] [data-state="Bueno"]', 'class'), 'is-on is-bueno', 'Todo en Bueno for furniture');
    await page.locator('[data-rows="furniture:sala"] [data-row-remove]').click();
    await page.locator('[data-furniture-add="sala"]').click();
    await page.locator('[data-rows="furniture:sala"] [data-f="d"]').fill('Sofá');
    assert.equal(await page.evaluate(() => document.querySelector('[data-rows="furniture:sala"] [data-f="d"]').tagName), 'TEXTAREA', 'Furniture description allows several lines');
    assert.equal(await page.locator('[data-rows="furniture:sala"] [data-f="m"]').count(), 0, 'Furniture has no material');
    assert.ok((await chips()).some(c => c.startsWith('Sala')), 'Space tab counts its furniture');
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-muebles.png'), fullPage: true });
    await go('Datos');
    await pick('categoria_inventario', 'No amoblado');
    await go('Sala, comedor');
    assert.equal(await page.locator('.scm-inv-furniture').count(), 0, 'No furniture block when not furnished');
    await go('Datos');
    await pick('categoria_inventario', 'Amoblado');
    await go('Cierre');
    let billing = await page.textContent('.scm-inv-billing');
    assert.ok(billing.includes('152.912') && billing.includes('160.912') && billing.includes('doble'), 'Double service: ' + billing);
    await pick('con_transporte', 'No');
    billing = await page.textContent('.scm-inv-billing');
    assert.ok(/Total del reporte\s*\$\s*152\.912/.test(billing), 'Without transport: ' + billing);
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-3-cierre.png'), fullPage: true });

    // Validación antes de enviar y envío como un solo JSON.
    await page.locator('[data-save]').click();
    assert.ok((await page.textContent('[data-corrective-review-message]')).includes('si recibe un delegado'), 'Reports the missing answer');
    assert.equal(await page.locator('[data-pane].is-active').textContent(), 'Datos', 'Goes to the pane with the error');
    assert.equal(await page.locator('.scm-inv-field.is-missing [data-choice="tiene_delegado"]').count(), 2, 'Marks the missing field');
    assert.ok((await page.getAttribute('[data-pane="datos"]', 'class')).includes('has-error'), 'Marks the step with errors');
    await pick('tiene_delegado', 'No');
    assert.equal(await page.locator('.is-missing').count(), 0, 'The mark clears once answered');
    await go('Cierre');
    await page.locator('[data-save]').click();
    await page.locator('text=Guardado').waitFor();
    const created = posts.find(p => p.operation === 'create');
    assert.ok(created, 'Create was sent');
    const sent = JSON.parse(created.payload);
    assert.equal(sent.sections.sala.rows[0].e, 'Regular');
    assert.equal(sent.sections.sala.rows[0].p, '2', 'Row floor is sent');
    assert.equal(sent.sections.habitacion_dos.floor, '2', 'Room floor is sent');
    assert.equal(sent.data.tipos.habitacion_dos, 'Estudio', 'Room type is sent');
    assert.equal(sent.sections.sala.rows[0].o, 'Vidrio "rayado"');
    assert.equal(sent.furniture.sala[0].d, 'Sofá');
    assert.equal(sent.data.con_transporte, 'No');
    assert.equal(await page.evaluate(() => window.saved), 1, 'Case refreshed after saving');

    // Borrador existente: el autoguardado no pisa nada hasta elegir.
    serverDraft = { revision: 7, updated_at: Math.floor(Date.now() / 1000) - 600, payload: { state: { data: { ...config.state.data, contrato: 'DEL-BORRADOR' }, boundaries: config.state.boundaries, sections: {}, furniture: {} }, pane: 'datos', extra: [] } };
    const before = saves().length;
    await open();
    await page.locator('[data-draft-restore]').waitFor();
    await page.fill('input[data-b="direccion"]', 'Cambio sin restaurar');
    await page.waitForTimeout(4200);
    assert.equal(saves().length, before, 'Blocked until the user decides');
    assert.equal(await page.inputValue('input[data-b="contrato"]'), '900');
    await page.locator('[data-draft-restore]').click();
    assert.equal(await page.inputValue('input[data-b="contrato"]'), 'DEL-BORRADOR', 'Draft restored');
    await page.fill('input[data-b="direccion"]', 'Ya restaurado');
    await page.waitForFunction(() => /Guardado/.test(document.querySelector('[data-draft-status]').textContent), null, { timeout: 8000 });
    assert.equal(saves().at(-1).revision, '7', 'Save continues from the draft revision');

    // Dictado: anima mientras escucha, envía WAV (MiniMax no acepta webm) y llena la tabla sola; se puede deshacer.
    config.voice = true;
    serverDraft = null;
    voiceReply = { text: 'dos tomas y una puerta', rows: [{ d: 'Tomas', c: '2', m: 'Plástico', e: 'Bueno', o: 'Ninguna' }, { d: 'Puerta', c: '1', m: 'Madera', e: 'Malo', o: 'Rota' }] };
    await open();
    await go('Sala, comedor');
    await page.locator('[data-voice-start="sections:sala"]').click();
    await page.locator('[data-voice-stop]').waitFor();
    assert.equal(await page.locator('.scm-inv-voice.is-recording .scm-inv-voice-wave i').count(), 5, 'Shows the listening animation');
    assert.ok((await page.textContent('.scm-inv-voice')).includes('Escuchando'), 'Says it is listening');
    await page.waitForTimeout(700);
    await page.locator('[data-voice-stop]').click();
    await page.locator('.scm-inv-voice.is-done').waitFor({ timeout: 15000 });
    assert.equal(audioHeader, 'RIFFWAVE', 'Sends a WAV file');
    assert.ok((await page.textContent('.scm-inv-voice')).includes('Se agregaron 2 elementos'), 'Confirms what was added');
    assert.equal(await page.locator('[data-rows="sections:sala"] [data-row]').count(), 2, 'Fills the table without an extra step');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-row="1"] [data-f="d"]'), 'Puerta');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-row="1"] [data-f="o"]'), 'Rota');
    await page.locator('[data-voice-undo]').click();
    assert.equal(await page.locator('[data-rows="sections:sala"] [data-row]').count(), 0, 'Undo removes the dictated rows');
    voiceReply = { text: 'eh no sé', rows: [] };
    await page.locator('[data-voice-start="sections:sala"]').click();
    await page.locator('[data-voice-stop]').waitFor();
    await page.waitForTimeout(500);
    await page.locator('[data-voice-stop]').click();
    await page.locator('.scm-inv-voice.is-error').waitFor({ timeout: 15000 });
    assert.ok((await page.textContent('.scm-inv-voice')).includes('No se entendieron elementos'), 'Explains when nothing was understood');
    await page.locator('[data-voice-cancel]').click();
    voiceReply = { text: 'una puerta rota', rows: [{ d: 'Puerta', c: '1', m: 'Madera', e: 'Malo', o: 'Rota' }] };
    await page.locator('[data-voice-start="sections:sala"]').click();
    await page.locator('[data-voice-stop]').waitFor();
    await page.waitForTimeout(500);
    await page.locator('[data-voice-stop]').click();
    await page.locator('.scm-inv-voice.is-done').waitFor({ timeout: 15000 });
    await page.locator('.scm-inv-voice [data-voice-cancel]').click();
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="d"]'), 'Puerta', 'Closing the notice keeps the rows');
    voiceReply = { transcription_failed: true, message: 'MiniMax no pudo transcribir el audio.' };
    await page.locator('[data-voice-start="sections:sala"]').click();
    await page.locator('[data-voice-stop]').waitFor();
    await page.waitForTimeout(500);
    await page.locator('[data-voice-stop]').click();
    await page.locator('.scm-inv-voice.is-error').waitFor({ timeout: 15000 });
    assert.ok((await page.textContent('.scm-inv-voice')).includes('dictado del teléfono'), 'Offers browser dictation');
    await page.locator('[data-voice-cancel]').click();
    config.voice = false;

    // Computador, tablet horizontal/vertical y celular: sin desborde y con campos de ancho usable.
    await go('Sala, comedor');
    await page.locator('[data-row-add]').click();
    await page.locator('[data-rows="sections:sala"] [data-row]').last().locator('[data-f="c"]').fill('Todo el espacio');
    for (const width of [1366, 1024, 768, 390]) {
      await page.setViewportSize({ width, height: 900 });
      const sizes = await page.evaluate(() => {
        const row = [...document.querySelectorAll('[data-rows="sections:sala"] [data-row]')].pop();
        const w = sel => Math.round(row.querySelector(sel).getBoundingClientRect().width);
        return { d: w('.scm-inv-d'), c: w('.scm-inv-c'), m: w('.scm-inv-m'), o: w('.scm-inv-o'), state: w('.scm-inv-state'), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
      });
      assert.ok(sizes.overflow <= 1, width + 'px: no horizontal scroll (' + sizes.overflow + ')');
      assert.ok(sizes.d >= 140 && sizes.c >= 124 && sizes.m >= 110 && sizes.o >= 120 && sizes.state >= 150, width + 'px: usable field widths ' + JSON.stringify(sizes));
      if (shots) await page.screenshot({ path: path.join(shots, 'inv-ancho-' + width + '.png'), fullPage: true });
    }

    // Vista de celular sin desborde horizontal.
    await page.setViewportSize({ width: 390, height: 800 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(overflow <= 1, 'No horizontal scroll on a phone: ' + overflow);
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-4-celular.png'), fullPage: true });

    assert.deepEqual(errors, [], 'No page errors');
    console.log('Inventory form checks passed: sections by type, suggestions, autosave, copy, furniture, billing, validation, single JSON submit, draft protection, mobile.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
