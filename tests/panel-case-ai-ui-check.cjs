const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const analyses = [], creations = [];
    let mode = 'success', finishDelayed;
    const draft = {asunto: 'Revisar fuga en cocina', descripcion: 'El arrendatario informa una fuga bajo el lavaplatos. Solicita revisión.', tema_ayuda: 'Reparaciones necesarias', departamento: 'Servicio al propietario', observaciones: '<script>bad()</script> Confirmar disponibilidad para la visita.'};
    await page.route('https://panel.test/api.php', async route => {
      const body = route.request().postDataBuffer().toString();
      const field = name => body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]*)`))?.[1];
      const action = field('action');
      let data;
      if (action === 'scm_panel_case_options') data = {ai: {enabled: true}, themes: ['Reparaciones necesarias'], departments: ['Servicio al propietario', 'Servicio al arrendatario'], theme_departments: {}, employees: [{employee_id: '9002', name: 'Consultor Ejemplo', cargo: 'Coordinación', email: 'qa@example.invalid'}], max_file_bytes: 10485760};
      if (action === 'scm_panel_case_search') data = {contracts: [{_ID: field('query') === 'otro' ? '802' : '801', contrato: '2000', inmueble: '204578', direccion: 'Calle de ejemplo', propietario: 'Propietario Ejemplo', arrendatario: 'Arrendatario Ejemplo', estado: 'Entregado'}]};
      if (action === 'scm_panel_case_analyze') {
        analyses.push({contract: field('contract_id'), text: field('source_text'), images: [...body.matchAll(/name="ai_images\[\]"; filename=/g)].length});
        if (mode === 'fail') return route.fulfill({json: {success: false, data: {message: 'MiniMax no tiene cuota disponible.'}}});
        if (mode === 'delay') await new Promise(resolve => { finishDelayed = resolve; });
        data = {draft, contract_id: field('contract_id')};
      }
      if (action === 'scm_panel_case_create') {
        creations.push(body);
        data = {ticket_id: 10999, queued: 3, whatsapp_queued: 1, notification_details: ['Propietario: Ejemplo comparte celular con Consultor Ejemplo; se encoló un solo mensaje.'], message: 'Caso #10999 creado. Correos encolados: 3. WhatsApp encolados: 1.'};
      }
      assert(data, action);
      try { await route.fulfill({json: {success: true, data}}); } catch (e) { if (mode !== 'delay') throw e; }
    });
    await page.route('https://panel.test/', route => route.fulfill({contentType: 'text/html', body: '<html></html>'}));
    await page.goto('https://panel.test/');
    await page.setContent('<div data-scm-runtime="{&quot;ajaxUrl&quot;:&quot;https://panel.test/api.php&quot;,&quot;nonce&quot;:&quot;qa&quot;}"><button id="open">Nuevo caso</button></div>');
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/tailwind-admin.css'), 'utf8')});
    await page.addScriptTag({path: path.join(__dirname, '../public/assets/js/admin-case-create.js')});
    await page.evaluate(() => {
      document.querySelector('#open').onclick = () => window.dispatchEvent(new CustomEvent('scm:open-nuevo-ticket'));
      window.openedReceipts = [];
      document.querySelector('[data-scm-runtime]').addEventListener('scm:open-panel-case', e => openedReceipts.push(e.detail));
    });
    await page.locator('#open').click();
    await page.locator('[data-case-form]').waitFor();
    assert(await page.locator('[data-ai-section]').isHidden(), 'AI is gated by selected contract');
    const select = async query => { await page.locator('[data-search]').fill(query); await page.locator('[data-search-button]').click(); await page.locator('[data-results] button').click(); await page.locator('[data-assistant-choice=yes]').click(); };
    await select('2000');
    await page.locator('[data-ai-analyze]').click();
    assert((await page.locator('[data-ai-status]').innerText()).includes('Pega el contenido'), 'empty input has inline feedback');
    assert.equal(analyses.length, 0);
    await page.locator('[name=asunto]').fill('Título manual');
    await page.locator('[name=descripcion]').fill('Descripción manual');
    await page.locator('[name=id_empleado]').selectOption('9002');
    await page.locator('[name=has_attachments]').selectOption('No');
    await page.locator('[name="notify_roles[]"][value=propietario]').check();
    await page.locator('[data-ai-text]').fill('El arrendatario reporta una fuga debajo del lavaplatos.');
    const paste = () => page.evaluate(() => {
      const data = new DataTransfer();
      data.items.add(new File([Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII='), c => c.charCodeAt(0))], 'correo.png', {type: 'image/png'}));
      document.querySelector('[data-ai-text]').dispatchEvent(new ClipboardEvent('paste', {clipboardData: data, bubbles: true, cancelable: true}));
    });
    await paste();
    assert.equal(await page.locator('[data-ai-previews] img').count(), 1);
    assert.equal(await page.locator('[data-pasted-images] img').count(), 0, 'source capture never becomes public evidence');
    assert.equal(await page.locator('[name=has_attachments]').inputValue(), 'No');
    await page.locator('[data-ai-analyze]').click();
    await page.locator('[data-ai-result]').waitFor({state: 'visible'});
    assert.equal(analyses[0].images, 1); assert.equal(analyses[0].contract, '801');
    assert.equal(await page.locator('[name=asunto]').inputValue(), 'Título manual', 'analysis preserves entered values until explicitly applied');
    assert.equal(await page.locator('[data-ai-result] script').count(), 0, 'generated output is escaped');
    await page.locator('[data-ai-apply]').click();
    assert.equal(await page.locator('[name=asunto]').inputValue(), draft.asunto);
    assert.equal(await page.locator('[name=tema_ayuda]').inputValue(), draft.tema_ayuda);
    assert.equal(await page.locator('[name=id_empleado]').inputValue(), '9002', 'AI never changes assignment');
    assert(await page.locator('[name="notify_roles[]"][value=propietario]').isChecked(), 'AI never changes notification selections');
    await page.locator('[data-ai-undo]').click();
    assert.equal(await page.locator('[name=asunto]').inputValue(), 'Título manual');
    assert.equal(await page.locator('[name=descripcion]').inputValue(), 'Descripción manual');
    await page.locator('[data-ai-apply]').click();
    mode = 'fail';
    await page.locator('[data-ai-analyze]').click();
    await page.waitForFunction(() => document.querySelector('[data-ai-status]').textContent.includes('cuota'));
    assert.equal(await page.locator('[name=asunto]').inputValue(), draft.asunto, 'provider failure keeps current draft');
    mode = 'delay';
    await page.locator('[data-ai-analyze]').click();
    await page.waitForFunction(() => document.querySelector('[data-ai-section]').getAttribute('aria-busy') === 'true');
    assert(await page.locator('[data-ai-analyze]').isDisabled(), 'duplicate provider request blocked');
    await select('otro');
    assert(await page.locator('[data-ai-result]').isHidden());
    assert.equal(await page.locator('[data-ai-previews] img').count(), 0, 'changing contract discards source screenshots');
    assert.equal(await page.locator('[data-ai-text]').inputValue(), '');
    if (finishDelayed) finishDelayed();
    mode = 'success';
    await page.locator('[data-ai-text]').fill('La fuga continúa.');
    await page.locator('[data-ai-images]').setInputFiles({name: 'whatsapp.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII=', 'base64')});
    await page.locator('[data-ai-analyze]').click();
    await page.locator('[data-ai-result]').waitFor({state: 'visible'});
    assert.equal(analyses.at(-1).contract, '802', 'new analysis uses new contract');
    fs.mkdirSync(path.join(__dirname, '../output/panel-case-ai'), {recursive: true});
    await page.locator('[data-ai-result]').scrollIntoViewIfNeeded();
    await page.screenshot({path: path.join(__dirname, '../output/panel-case-ai/desktop.png')});
    await page.setViewportSize({width: 390, height: 844});
    await page.locator('[data-ai-section]').scrollIntoViewIfNeeded();
    assert(await page.evaluate(() => document.querySelector('dialog').scrollWidth <= document.querySelector('dialog').clientWidth), 'AI layout fits mobile');
    await page.screenshot({path: path.join(__dirname, '../output/panel-case-ai/mobile.png')});
    await page.locator('[data-ai-apply]').click();
    await page.evaluate(() => { window.scmOpenCase = () => {}; });
    await page.locator('[type=submit]').click();
    await page.locator('dialog').waitFor({state: 'detached'});
    assert.equal(creations.length, 1);
    assert(!creations[0].includes('ai_images') && !creations[0].includes('source_text'), 'analysis material excluded from case creation');
    assert.equal(await page.evaluate(() => openedReceipts.length), 1, 'creation automatically requests native case popup once');
    assert.equal(await page.evaluate(() => openedReceipts[0].ticket_id), 10999);
    assert((await page.evaluate(() => openedReceipts[0].notification_details[0])).includes('comparte celular'), 'notification receipt accompanies case opening');
    const runtime = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
    const start = runtime.indexOf('    function openPanelCaseById(');
    const end = runtime.indexOf('    root.addEventListener("scm:refresh-active-tab"', start);
    await page.addScriptTag({content: `
      var root = document.querySelector('[data-scm-runtime]');
      function dashboardAction(action, payload) { return Promise.resolve({case: {ticket_pk: payload.case_id, case_source_html: '<section>Detalle</section>'}}); }
      function dashboardApplyDueCaseData() {}
      function openDashboardDueCaseFromButton() { root.insertAdjacentHTML('beforeend', '<div id="scm-case-modal" class="open"><div id="scm-case-body"></div></div>'); }
      function showToast() { throw Error('Unexpected popup failure'); }
      ${runtime.slice(start, end)}
    `});
    await page.evaluate(() => document.querySelector('[data-scm-runtime]').dispatchEvent(new CustomEvent('scm:open-panel-case', {detail: openedReceipts[0]})));
    await page.locator('#scm-case-body [role=status]').waitFor();
    assert((await page.locator('#scm-case-body [role=status]').innerText()).includes('WhatsApp encolados: 1'));
    await page.locator('#scm-case-body summary').click();
    assert((await page.locator('#scm-case-body li').innerText()).includes('comparte celular'), 'native case preserves readable notification reasons');
    assert.deepEqual(errors, []);
    console.log('PASS: AI gating, paste/upload isolation, preview, apply/undo, failures, stale requests, mobile and automatic case opening');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
