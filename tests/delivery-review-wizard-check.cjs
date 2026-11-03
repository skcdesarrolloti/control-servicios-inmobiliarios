const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

// Asistente de la revisión antes de la ocupación en el popup del caso: pasos, campos condicionales,
// fichas por habitación, filas repetibles, servicios, resumen, autoguardado y envío.
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-admin.js'), 'utf8');
const start = source.indexOf('  var DAMAGE_REVIEW_KINDS = {');
const editor = source.slice(start, source.indexOf('  function openTicketResponseEditor(', start));
assert(start > 0 && editor.includes('function bindDeliveryReviewForm'), 'Delivery editor code was not found');
const drafts = fs.readFileSync(path.join(__dirname, '../public/assets/js/ticket-completion-form-state.js'), 'utf8');
const css = ['preventive-review.css', 'delivery-review.css'].map(file => fs.readFileSync(path.join(__dirname, '../public/assets/css/', file), 'utf8')).join('\n');
const form = execFileSync('php', [path.join(__dirname, 'delivery-review-check.php')], { env: { ...process.env, DELIVERY_EMIT_FORM: '1' }, encoding: 'utf8' });
const shots = process.env.DELIVERY_SHOTS || '';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });
    const errors = [];
    const posts = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('https://panel.test/', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:sans-serif;margin:0;padding:16px}' + css + '</style></head><body><div id="app" data-scm-draft-user="qa"><div id="modal"><div id="sub"><h4 class="scm-case-submodal-title"></h4><div class="scm-case-submodal-body"></div></div></div><button id="case" data-ticket-pk="11106" data-tab-key="entrega"></button></div></body></html>' }));
    await page.route('https://panel.test/api.php', async route => {
      const body = route.request().postDataBuffer().toString('utf8');
      const operation = (body.match(/name="operation"\r\n\r\n([^\r]+)/) || [])[1];
      posts.push({ operation, body });
      const html = operation === 'read' ? form : '<div class="scm-acta"><p data-corrective-review-message></p>Guardada</div>';
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { html, message: operation === 'create' ? 'Revisión antes de la ocupación #1 guardada.' : '' } }) });
    });
    await page.goto('https://panel.test/');
    await page.addScriptTag({ content: drafts });
    await page.addScriptTag({ content: `
      window.saved = 0;
      function ensureCaseSubmodal() { return document.getElementById('sub'); }
      function findRootFromNode() { return document.getElementById('app'); }
      function parseRuntime() { return { ajaxUrl: 'https://panel.test/api.php', nonce: 'n', actions: {} }; }
      function setCaseSubmodalMeta() {}
      function scmNotify() {}
      function dispatchCaseActionSaved() { window.saved++; }
      function escHtml(v) { return String(v).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
      ${editor}
      window.openDelivery = function () { openDamageReviewEditor(document.getElementById('modal'), document.getElementById('case'), 'entrega'); };
    ` });
    await page.evaluate(() => window.openDelivery());
    await page.locator('[data-delivery-review-form]').waitFor();
    const visibleStep = () => page.evaluate(() => document.querySelector('[data-prev-step]:not([hidden])').getAttribute('data-prev-step'));
    const rows = key => page.evaluate(k => document.querySelectorAll('[data-delivery-list="' + k + '"] > [data-delivery-row]').length, key);
    assert.equal(await visibleStep(), 'inmueble');
    assert.equal(await page.textContent('.scm-case-submodal-title'), 'Revisión antes de la ocupación');
    assert.equal(await rows('esp-habitacion'), 3, 'One room card per room from the property');
    assert.equal(await rows('esp-bano'), 2, 'One bathroom card per bathroom');
    if (shots) await page.screenshot({ path: path.join(shots, 'entrega-1-inmueble.png'), fullPage: true });

    // Habitaciones: subir agrega fichas con nombre; bajar solo quita las vacías.
    await page.fill('input[name="habitaciones"]', '4');
    await page.dispatchEvent('input[name="habitaciones"]', 'change');
    assert.equal(await rows('esp-habitacion'), 4);
    assert.equal(await page.inputValue('input[name="espacios[habitacion][3][tipo]"]'), '#4');
    await page.fill('input[name="habitaciones"]', '2');
    await page.dispatchEvent('input[name="habitaciones"]', 'change');
    assert.equal(await rows('esp-habitacion'), 2, 'Empty extra room cards are removed');

    // Condicionales: sin parqueadero se ocultan sus campos y no bloquean el avance.
    await page.click('input[name="tiene_parqueadero"][value="No"] + span');
    assert.equal(await page.isVisible('select[name="tipo_parqueadero"]'), false);
    await page.click('input[name="tiene_parqueadero"][value="Si"] + span');
    assert.equal(await page.isVisible('select[name="tipo_parqueadero"]'), true);
    await page.fill('input[name="numero_parqueadero"]', '');
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'inmueble', 'Required parking number blocks the step');
    await page.fill('input[name="numero_parqueadero"]', '14');
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'linderos');
    assert.equal(await page.isVisible('[data-delivery-list="lin-parqueadero"]'), true);
    assert.equal(await page.isVisible('[data-delivery-list="lin-deposito"]'), false);
    await page.click('[data-delivery-add="lin-parqueadero"]');
    await page.fill('input[name="linderos[parqueadero][0][num]"]', '14');
    if (shots) await page.screenshot({ path: path.join(shots, 'entrega-2-linderos.png'), fullPage: true });

    // Espacios: "Sin novedad", "Marcar todo" y quitar una fila renumera.
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'espacios');
    const firstRoom = '[data-delivery-list="esp-habitacion"] > [data-delivery-row]:first-child';
    await page.click(firstRoom + ' [data-delivery-none]');
    assert.equal(await page.inputValue(firstRoom + ' textarea'), 'Ninguna');
    await page.click(firstRoom + ' [data-delivery-all]');
    assert.equal(await page.evaluate(sel => [...document.querySelectorAll(sel + ' .scm-delivery-chips input')].every(i => i.checked), firstRoom), true);
    await page.click('[data-delivery-add="esp-patio"]');
    await page.fill('textarea[name="espacios[patio][0][observacion]"]', 'Grieta en el muro');
    await page.click('[data-delivery-list="esp-habitacion"] > [data-delivery-row]:first-child [data-delivery-remove]');
    assert.equal(await rows('esp-habitacion'), 1);
    assert.equal(await page.inputValue('input[name="espacios[habitacion][0][tipo]"]'), '#2', 'Rows are renumbered after removing one');
    if (shots) await page.screenshot({ path: path.join(shots, 'entrega-3-espacios.png'), fullPage: true });

    // Servicios: activar energía exige sus datos; quitar gas lo oculta.
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'servicios');
    assert.equal(await page.isVisible('[data-delivery-service="gas"]'), true, 'Gas comes active from the contract');
    await page.click('[data-delivery-service-toggle="gas"] + span');
    assert.equal(await page.isVisible('[data-delivery-service="gas"]'), false);
    await page.click('[data-delivery-service-toggle="luz"] + span');
    assert.equal(await page.isVisible('[data-delivery-service="luz"]'), true);
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'servicios', 'Missing required service fields block the step');
    for (const [prefix, med] of [['ag', 'agua'], ['luz', 'luz']]) {
      if (prefix === 'luz') {
        await page.fill('input[name="servicios[num_nic_luz]"]', '1187772');
      }
      await page.fill(`input[name="servicios[num_med_${prefix}]"]`, '014298');
      await page.selectOption(`select[name="servicios[num_med_igual_${prefix}]"]`, 'Igual');
      await page.selectOption(`select[name="servicios[est_med_${prefix}]"]`, { index: 1 });
      await page.selectOption(`select[name="servicios[dir_${prefix}]"]`, 'Coincide');
      await page.fill(`input[name="servicios[lec_${prefix}]"]`, '100');
      assert(med);
    }
    if (shots) await page.screenshot({ path: path.join(shots, 'entrega-4-servicios.png'), fullPage: true });
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'resumen');
    const summary = await page.textContent('[data-delivery-summary]');
    assert.match(summary, /Habitaciones: 2 \(1 ficha\(s\)\)/);
    assert.match(summary, /Grieta en el muro/);
    assert.match(summary, /Agua, Energía/);
    assert.match(summary, /menos fichas/, 'Warns when there are fewer cards than rooms');

    // Autoguardado: reabrir el editor restaura filas y valores.
    await page.waitForTimeout(600);
    await page.evaluate(() => { document.querySelector('#sub .scm-case-submodal-body').innerHTML = ''; window.openDelivery(); });
    await page.locator('[data-delivery-review-form]').waitFor();
    await page.waitForFunction(() => {
      const field = document.querySelector('textarea[name="espacios[patio][0][observacion]"]');
      return field && field.value === 'Grieta en el muro';
    }, null, { timeout: 5000 });
    assert.equal(await rows('esp-habitacion'), 1, 'Draft restores the room cards');
    assert.equal(await page.inputValue('input[name="espacios[habitacion][0][tipo]"]'), '#2');
    assert.equal(await page.inputValue('input[name="linderos[parqueadero][0][num]"]'), '14');
    assert.equal(await page.isChecked('[data-delivery-service-toggle="luz"]'), true);
    assert.equal(await page.isVisible('[data-delivery-service="gas"]'), false, 'Restored service toggles keep their sections hidden');

    // Guardar.
    await page.click('[data-prev-goto="resumen"]');
    await page.click('[data-prev-submit]');
    await page.waitForFunction(() => window.saved === 1, null, { timeout: 5000 });
    const sent = posts.find(p => p.operation === 'create');
    assert(sent, 'Create request was not sent');
    assert.match(sent.body, /name="action"\r\n\r\nscm_revision_entrega/);
    assert.match(sent.body, /name="espacios\[habitacion\]\[0\]\[tipo\]"\r\n\r\n#2/);
    assert.match(sent.body, /name="espacios\[patio\]\[0\]\[observacion\]"\r\n\r\nGrieta en el muro/);
    assert.match(sent.body, /name="servicios_publicos\[\]"\r\n\r\nEnergia/);
    assert.match(sent.body, /name="piso"\r\n\r\n5/);
    assert.doesNotMatch(sent.body, /servicios_publicos\[\]"\r\n\r\nGas/);

    // Móvil: sin desbordamiento horizontal.
    await page.setViewportSize({ width: 380, height: 800 });
    await page.evaluate(() => { document.querySelector('#sub .scm-case-submodal-body').innerHTML = ''; window.openDelivery(); });
    await page.locator('[data-delivery-review-form]').waitFor();
    for (const step of ['inmueble', 'linderos', 'espacios', 'servicios']) {
      await page.click(`[data-prev-goto="${step}"]`).catch(() => {});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'Wizard overflows on mobile at ' + step);
    }
    if (shots) await page.screenshot({ path: path.join(shots, 'entrega-5-movil.png'), fullPage: true });

    assert.deepEqual(errors, []);
    console.log('PASS: delivery review wizard steps, conditionals, room cards, repeaters, services, summary, autosave restore, submit payload and mobile layout.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exit(1);
});
