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
const residential = { key: 'residencial', rooms_label: 'Habitaciones', baths_label: 'Baños', labels: {}, hidden: [], max_rooms: 10, max_baths: 10 };
const config = {
  editing: false, additional: false, inventoryId: 0, version: '', hasReport: false,
  state: { data: { tipo_inventario: 'Nuevo', categoria_inventario: 'No amoblado', tipo_de_inmueble: 'Apartamento', contrato: '900', inmueble: '77', direccion: 'Calle 1', registro_fotografico: 'https://drive.google.com/x', arrendatario: 'A', correo_arrendatario: 'a@example.invalid', celular_arrendatario: '300', tiene_parqueadero: 'No', tiene_deposito: 'No', tiene_delegado: '', fecha_linderos: '2026-10-02', cantidad_pisos: '1', cantidad_habitaciones: '1', cantidad_banos: '1', con_transporte: 'Si', servicios: [], acuerdos: [] }, boundaries: { inmueble_lin: [{ norte: 'Pasillo' }] }, sections: {}, furniture: {} },
  catalog: {
    sections: [section('elementos_principales', 'Elementos principales'), section('sala', 'Sala, comedor y hall'), section('bano_emergencia', 'Baño de emergencia'), section('habitacion_principal', 'Habitación principal', 'habitacion', 1), section('habitacion_dos', 'Habitación dos', 'habitacion', 2), section('bano_principal', 'Baño principal', 'bano', 1), section('otros', 'Otros', 'general', 0, [])],
    boundaries: [{ key: 'inmueble_lin', label: 'Inmueble', suffix: 'inmueble', sides: ['norte', 'sur'], multiple: false, when: '', required: true }, { key: 'parqueadero_lin', label: 'Parqueadero', suffix: 'parqueadero', sides: ['norte'], multiple: true, when: 'tiene_parqueadero', required: false }],
    profiles: { '': residential, Apartamento: residential, Local: { ...residential, key: 'comercial', rooms_label: 'Áreas u oficinas', labels: { habitacion_principal: 'Área u oficina 1', sala: 'Área principal o recepción' }, hidden: ['bano_emergencia'] } },
    types: ['Nuevo', 'Anterior'], categories: ['No amoblado', 'Amoblado'], states: ['Bueno', 'Regular', 'Malo'], parking: ['Asignado', 'Privado'], services: ['Agua', 'Gas', 'Energia'], agreements: { Propietario: 'acuerdos_precontractuales_pro' },
    suggestions: { sala: { items: ['Ventanas'], materials: { Ventanas: ['Aluminio', 'PVC'] }, quantities: { Ventanas: '3' } } },
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
    assert.deepEqual(await chips(), ['Datos', 'Linderos', 'Elementos principales', 'Sala, comedor y hall', 'Baño de emergencia', 'Habitación principal', 'Baño principal', 'Otros', 'Cierre']);
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-1-datos.png'), fullPage: true });

    // Condicionales y secciones según la cantidad y el tipo de inmueble.
    assert.equal(await page.isVisible('select[data-b="tipo_parqueadero"]'), false);
    await page.selectOption('select[data-b="tiene_parqueadero"]', 'Si');
    assert.equal(await page.isVisible('select[data-b="tipo_parqueadero"]'), true, 'Parking fields appear');
    await page.selectOption('select[data-b="tipo_parqueadero"]', 'Privado');
    await page.fill('input[data-b="parqueaderos"]', '1');
    await page.fill('input[data-b="numero_parqueadero"]', '12');
    await page.selectOption('select[data-b="cantidad_habitaciones"]', '2');
    assert.ok((await chips()).includes('Habitación dos'), 'A second room adds its section');
    await page.selectOption('select[data-b="tipo_de_inmueble"]', 'Local');
    let names = await chips();
    assert.ok(names.includes('Área u oficina 1') && names.includes('Área principal o recepción'), 'Commercial labels');
    assert.ok(!names.includes('Baño de emergencia'), 'Hidden section for a local');
    assert.equal(await page.locator('[data-extra-section] option', { hasText: 'Baño de emergencia' }).count(), 1, 'Hidden section can be added back');
    await page.selectOption('select[data-b="tipo_de_inmueble"]', 'Apartamento');

    // Sugerencias: material y cantidad aprendidos.
    await go('Sala, comedor');
    await page.locator('[data-add-item="Ventanas"]').click();
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="m"]'), 'Aluminio');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="c"]'), '3');
    assert.equal(await page.locator('[data-add-item="Ventanas"]').count(), 0, 'A used suggestion leaves the chips');
    await page.locator('[data-rows="sections:sala"] [data-state="Regular"]').click();
    await page.locator('[data-rows="sections:sala"] [data-f="o"]').fill('Vidrio "rayado"');
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-2-seccion.png'), fullPage: true });
    assert.ok((await chips()).some(c => c.startsWith('Sala') && c.endsWith('1')), 'Section chip shows its count');

    // Autoguardado en servidor: un solo guardado tras dejar de escribir.
    await page.waitForFunction(() => /Guardado/.test(document.querySelector('[data-draft-status]').textContent), null, { timeout: 8000 });
    assert.equal(saves().length, 1, 'Debounced into one save');
    assert.ok(saves()[0].payload.includes('Vidrio \\"rayado\\"'), 'Draft holds the row');

    // Copiar filas entre secciones.
    await go('Habitación principal');
    await page.selectOption('[data-copy-from]', 'sala');
    assert.equal(await page.locator('[data-rows="sections:habitacion_principal"] [data-row]').count(), 1, 'Rows copied');
    await page.locator('[data-row-remove]').click();

    // Amoblado: muebles y cobro doble.
    await go('Datos');
    await page.selectOption('select[data-b="categoria_inventario"]', 'Amoblado');
    assert.ok((await chips()).includes('Muebles y enseres'));
    await go('Muebles y enseres');
    await page.locator('.scm-inv-furniture summary', { hasText: 'Sala, comedor' }).click();
    await page.locator('[data-furniture-add="sala"]').click();
    await page.locator('[data-rows="furniture:sala"] [data-f="d"]').fill('Sofá');
    assert.equal(await page.locator('[data-rows="furniture:sala"] [data-f="m"]').count(), 0, 'Furniture has no material');
    await go('Cierre');
    let billing = await page.textContent('.scm-inv-billing');
    assert.ok(billing.includes('152.912') && billing.includes('160.912') && billing.includes('doble'), 'Double service: ' + billing);
    await page.selectOption('select[data-b="con_transporte"]', 'No');
    billing = await page.textContent('.scm-inv-billing');
    assert.ok(/Total del reporte\s*\$\s*152\.912/.test(billing), 'Without transport: ' + billing);
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-3-cierre.png'), fullPage: true });

    // Validación antes de enviar y envío como un solo JSON.
    await page.locator('[data-save]').click();
    assert.ok((await page.textContent('[data-corrective-review-message]')).includes('tiene delegado'), 'Reports the missing answer');
    assert.equal(await page.locator('[data-pane].is-active').textContent(), 'Datos', 'Goes to the pane with the error');
    await page.selectOption('select[data-b="tiene_delegado"]', 'No');
    await go('Cierre');
    await page.locator('[data-save]').click();
    await page.locator('text=Guardado').waitFor();
    const created = posts.find(p => p.operation === 'create');
    assert.ok(created, 'Create was sent');
    const sent = JSON.parse(created.payload);
    assert.equal(sent.sections.sala.rows[0].e, 'Regular');
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

    // Dictado: graba, envía WAV (MiniMax no acepta webm), muestra lo entendido y agrega solo lo aceptado.
    config.voice = true;
    serverDraft = null;
    voiceReply = { text: 'dos tomas y una puerta', rows: [{ d: 'Tomas', c: '2', m: 'Plástico', e: 'Bueno', o: 'Ninguna' }, { d: 'Puerta', c: '1', m: 'Madera', e: 'Malo', o: 'Rota' }] };
    await open();
    await go('Sala, comedor');
    await page.locator('[data-voice-start="sections:sala"]').click();
    await page.locator('[data-voice-stop]').waitFor();
    await page.waitForTimeout(700);
    await page.locator('[data-voice-stop]').click();
    await page.locator('[data-voice-accept]').waitFor({ timeout: 15000 });
    assert.equal(audioHeader, 'RIFFWAVE', 'Sends a WAV file');
    assert.ok((await page.textContent('.scm-inv-voice')).includes('dos tomas y una puerta'), 'Shows what was understood');
    assert.equal(await page.locator('[data-rows="sections:sala"] [data-row]').count(), 0, 'Nothing is added before accepting');
    await page.locator('[data-voice-row="0"]').uncheck();
    await page.locator('[data-voice-accept]').click();
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="d"]'), 'Puerta', 'Adds only the accepted row');
    assert.equal(await page.inputValue('[data-rows="sections:sala"] [data-f="o"]'), 'Rota');
    voiceReply = { transcription_failed: true, message: 'MiniMax no pudo transcribir el audio.' };
    await page.locator('[data-voice-start="sections:sala"]').click();
    await page.locator('[data-voice-stop]').waitFor();
    await page.waitForTimeout(500);
    await page.locator('[data-voice-stop]').click();
    await page.locator('.scm-inv-voice.is-error').waitFor({ timeout: 15000 });
    assert.ok((await page.textContent('.scm-inv-voice')).includes('dictado del teléfono'), 'Offers browser dictation');
    await page.locator('[data-voice-cancel]').click();
    config.voice = false;

    // Vista de celular sin desborde horizontal.
    await page.setViewportSize({ width: 390, height: 800 });
    await go('Sala, comedor');
    await page.locator('[data-row-add]').click();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(overflow <= 1, 'No horizontal scroll on a phone: ' + overflow);
    if (shots) await page.screenshot({ path: path.join(shots, 'inv-4-celular.png'), fullPage: true });

    assert.deepEqual(errors, [], 'No page errors');
    console.log('Inventory form checks passed: sections by type, suggestions, autosave, copy, furniture, billing, validation, single JSON submit, draft protection, mobile.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
