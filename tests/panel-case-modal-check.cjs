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
    await page.route('https://panel.test/api.php', async route => {
      const request = route.request();
      const body = request.postDataBuffer().toString();
      const field = name => body.match(new RegExp(`name="${name}"\\r\\n\\r\\n([^\\r]+)`))?.[1];
      const action = field('action');
      let data;
      if (action === 'scm_panel_case_options') data = {themes: ['Reparaciones necesarias', 'Solicitud contractual', 'Servicio publico critico'], departments: ['Mantenimiento', 'Contractual', 'Servicio al arrendatario'], theme_departments: {'Reparaciones necesarias': 'Mantenimiento', 'Solicitud contractual': 'Contractual', 'Servicio publico critico': 'Servicio al arrendatario'}, employees: [{employee_id: '9002', name: 'Responsable Ejemplo', cargo: 'Coordinación', email: 'responsable@example.invalid'}], max_file_bytes: 10485760};
      if (action === 'scm_panel_case_search') data = {contracts: [{_ID: '801', contrato: 'QA801', inmueble: 'SIMI8001', direccion: 'Calle de ejemplo 123', estado: 'Entregado', propietario: 'Propietario Ejemplo', arrendatario: 'Arrendatario Ejemplo'}]};
      if (action === 'scm_panel_case_create') {
        await page.evaluate(payload => { window.submissions.push(payload); }, {id: field('request_id'), contract: field('contract_id'), employee: field('id_empleado'), attachments: body.includes('evidencia.png'), pasted: body.includes('filename="captura-'), imageCount: [...body.matchAll(/name="imagenes\[\]"; filename="[^"]+"/g)].length});
        const fail = await page.evaluate(() => window.failOnce);
        if (fail) {
          await page.evaluate(() => { window.failOnce = false; });
          return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: false, data: {message: 'Fallo de prueba. Reintenta.'}})});
        }
        data = {ticket_id: 10999, queued: 1, message: 'Caso #10999 creado. Correos encolados: 1.'};
      }
      assert(data, `unexpected action: ${action}`);
      return route.fulfill({contentType: 'application/json', body: JSON.stringify({success: true, data})});
    });
    await page.route('https://panel.test/', route => route.fulfill({contentType: 'text/html', body: '<html></html>'}));
    await page.goto('https://panel.test', {waitUntil: 'domcontentloaded'});
    await page.setContent('<div data-scm-runtime="{&quot;ajaxUrl&quot;:&quot;https://panel.test/api.php&quot;,&quot;nonce&quot;:&quot;qa-nonce&quot;}"><button id="open" onclick="window.dispatchEvent(new CustomEvent(\'scm:open-nuevo-ticket\'))">Nuevo Caso</button></div>');
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/tailwind-admin.css'), 'utf8')});
    await page.evaluate(() => { window.submissions = []; window.failOnce = true; });
    await page.addScriptTag({path: path.join(__dirname, '../public/assets/js/admin-case-create.js')});
    const paste = async (count = 1, type = 'image/png') => page.evaluate(({count, type}) => {
      const clipboard = new DataTransfer();
      const binary = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII='), c => c.charCodeAt(0));
      for (let i = 0; i < count; i++) clipboard.items.add(new File([binary], `clipboard-${i}.png`, {type}));
      const event = new ClipboardEvent('paste', {clipboardData: clipboard, bubbles: true, cancelable: true});
      document.querySelector('[data-case-form]').dispatchEvent(event);
      return event.defaultPrevented;
    }, {count, type});
    await page.locator('#open').click();
    await page.locator('[data-case-form]').waitFor();
    assert.equal(await page.locator('dialog').count(), 1, 'native modal opens inside same page');
    assert(await page.locator('[data-contract-steps]').isHidden(), 'later steps stay hidden before selecting a contract');
    assert(await page.locator('[name=asunto]').isDisabled(), 'hidden required fields do not block contract lookup');
    assert(await page.locator('[type=submit]').isHidden(), 'creation action stays hidden until a contract is selected');
    assert.equal(await paste(), false, 'pasting before contract selection does not activate later steps');
    assert.equal(await page.locator('[data-pasted-images] img').count(), 0);
    await page.locator('[data-search-by]').selectOption('inmueble');
    await page.locator('[data-search]').fill('SIMI8001');
    await page.locator('[data-search]').press('Enter');
    await page.locator('[data-results] button').waitFor();
    assert(await page.locator('[data-contract-steps]').isHidden(), 'search results alone do not complete the first step');
    await page.locator('[data-results] button').click();
    assert(await page.locator('[data-contract-steps]').isVisible(), 'selecting a contract reveals later steps');
    assert(await page.locator('[name=asunto]').isEnabled(), 'selecting a contract enables case fields');
    await page.locator('[data-search]').fill('SIMI8002');
    assert(await page.locator('[data-contract-steps]').isHidden(), 'editing lookup hides later steps again');
    assert(await page.locator('[type=submit]').isHidden(), 'editing lookup prevents premature creation');
    await page.locator('[data-search]').press('Enter');
    await page.locator('[data-results] button').click();
    await page.locator('[data-search-by]').selectOption('contrato');
    assert(await page.locator('[data-contract-steps]').isHidden(), 'changing lookup type invalidates the first step');
    await page.locator('[data-search]').fill('QA801');
    await page.locator('[data-search]').press('Enter');
    await page.locator('[data-results] button').click();
    await page.locator('[name=asunto]').fill('Revisar fuga');
    await page.locator('[name=descripcion]').fill('Se presenta una fuga de agua en cocina.');
    await page.locator('[name=tema_ayuda]').selectOption('Servicio publico critico');
    assert.equal(await page.locator('[name=departamento]').inputValue(), 'Servicio al arrendatario', 'critical service theme suggests its existing workflow department');
    await page.locator('[name=tema_ayuda]').selectOption('Reparaciones necesarias');
    assert.equal(await page.locator('[name=departamento]').inputValue(), 'Mantenimiento', 'theme suggests department');
    await page.locator('[name=id_empleado]').selectOption('9002');
    await page.locator('[name=has_attachments]').selectOption('No');
    assert(await page.locator('[data-selected]').isVisible(), 'selected contract remains in popup');
    assert.equal(await paste(), true, 'clipboard image paste is captured after selecting contract');
    assert.equal(await page.locator('[name=has_attachments]').inputValue(), 'Si', 'pasting activates attachments');
    assert.equal(await page.locator('[data-pasted-images] img').count(), 1, 'pasted screenshot has preview');
    assert(await page.locator('[data-pasted-images] img').evaluate(image => image.complete && image.naturalWidth > 0), 'preview decodes the actual pasted image');
    await page.locator('[data-remove-pasted]').click();
    assert.equal(await page.locator('[data-pasted-images] img').count(), 0, 'pasted image can be removed');
    await paste();
    await page.locator('[name=has_attachments]').selectOption('No');
    assert.equal(await page.locator('[data-pasted-images] img').count(), 0, 'No attachments clears pasted images');
    const textHandled = await page.evaluate(() => {
      const data = new DataTransfer(); data.setData('text/plain', 'Texto normal');
      const event = new ClipboardEvent('paste', {clipboardData: data, bubbles: true, cancelable: true});
      document.querySelector('[name=descripcion]').dispatchEvent(event);
      return event.defaultPrevented;
    });
    assert.equal(textHandled, false, 'normal text paste remains available');
    await page.locator('[name=has_attachments]').selectOption('Si');
    await page.locator('[type=submit]').click();
    assert((await page.locator('[data-error]').innerText()).includes('adjuntos'), 'yes requires evidence');
    await paste(2);
    assert.equal(await page.locator('[data-pasted-images] img').count(), 2, 'multiple screenshots can be appended');
    await page.locator('[data-remove-pasted]').first().click();
    await page.locator('input[name="imagenes[]"]').setInputFiles({name: 'evidencia.png', mimeType: 'image/png', buffer: Buffer.from('qa')});
    await paste(10);
    assert.equal(await page.locator('[data-pasted-images] img').count(), 1, 'paste shares ten-file limit with selected evidence');
    assert((await page.locator('[data-error]').innerText()).includes('límites'), 'oversized clipboard batch displays validation');
    await paste(1, 'image/gif');
    assert.equal(await page.locator('[data-pasted-images] img').count(), 1, 'unsupported image format is not appended');
    await page.locator('[type=submit]').click();
    await page.waitForFunction(() => document.querySelector('[data-error]').textContent.includes('Fallo de prueba'));
    assert(await page.locator('[type=submit]').isEnabled(), 'failure permits retry without losing fields');
    await page.locator('[type=submit]').click();
    await page.locator('[data-done]').waitFor();
    const submissions = await page.evaluate(() => window.submissions);
    assert.equal(submissions.length, 2);
    assert.equal(submissions[0].id, submissions[1].id, 'retry uses stable idempotency token');
    assert.equal(submissions[1].contract, '801');
    assert.equal(submissions[1].employee, '9002');
    assert(submissions[1].attachments, 'multipart evidence is submitted');
    assert(submissions[1].pasted && submissions[1].imageCount === 2, 'pasted screenshots and selected images share multipart upload and survive retry');
    await page.locator('[data-done]').click();
    await page.locator('dialog').waitFor({state: 'detached'});
    assert.equal(await page.locator('dialog').count(), 0);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'open', 'focus returns to opener');
    await page.locator('#open').click();
    await page.locator('[data-case-form]').waitFor();
    await page.locator('[data-search]').fill('QA801');
    await page.locator('[data-search-button]').click();
    await page.locator('[data-results] button').click();
    await page.locator('[name=asunto]').fill('Revisar fuga en cocina');
    await page.locator('[name=descripcion]').fill('Se presenta una fuga de agua. Adjuntamos el registro fotográfico para coordinar la visita.');
    await page.locator('[name=tema_ayuda]').selectOption('Reparaciones necesarias');
    await page.locator('[name=id_empleado]').selectOption('9002');
    await page.locator('[name=has_attachments]').selectOption('Si');
    await page.evaluate(() => {
      Object.defineProperty(navigator, 'clipboard', {configurable: true, value: {read: async () => [{types: ['image/png'], getType: async () => new Blob([Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII='), c => c.charCodeAt(0))], {type: 'image/png'})}]}});
    });
    await page.locator('[data-paste-image]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-pasted-images] img').length === 1);
    assert.equal(await page.locator('[data-pasted-images] img').count(), 1, 'Paste screenshot button appends clipboard image');
    await page.evaluate(() => { navigator.clipboard.read = async () => { throw new Error('Permission denied'); }; });
    await page.locator('[data-paste-image]').click();
    assert((await page.locator('[data-paste-status]').innerText()).includes('Ctrl+V'), 'clipboard permission failure offers keyboard paste');
    fs.mkdirSync(path.join(__dirname, '../output/panel-case'), {recursive: true});
    await page.locator('[data-pasted-images]').scrollIntoViewIfNeeded();
    await page.screenshot({path: path.join(__dirname, '../output/panel-case/desktop.png'), fullPage: true});
    await page.setViewportSize({width: 390, height: 844});
    await page.locator('[data-pasted-images]').scrollIntoViewIfNeeded();
    await page.screenshot({path: path.join(__dirname, '../output/panel-case/mobile.png'), fullPage: true});
    assert(await page.evaluate(() => document.querySelector('dialog').scrollWidth <= document.querySelector('dialog').clientWidth), 'no horizontal overflow on mobile');
    await page.keyboard.press('Escape');
    await page.locator('dialog').waitFor({state: 'detached'});
    assert.equal(await page.locator('dialog').count(), 0, 'Escape closes modal');
    assert.deepEqual(errors, [], 'no browser runtime errors');
    const runtime = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
    const start = runtime.indexOf('    function openPanelCaseById(');
    const end = runtime.indexOf('    root.addEventListener("scm:refresh-active-tab"', start);
    await page.addScriptTag({content: `
      var root = document.querySelector('[data-scm-runtime]');
      window.nativeCases = [];
      function dashboardAction(action, payload) { return Promise.resolve({case: {ticket_pk: payload.case_id, case_source_html: '<section>Detalle del caso</section>'}}); }
      function dashboardApplyDueCaseData(button, data) { button.dataset.ticketPk = data.ticket_pk; }
      function openDashboardDueCaseFromButton(button, source) { nativeCases.push({id: button.dataset.ticketPk, source: source}); }
      function showToast() { throw Error('Unexpected native popup failure'); }
      ${runtime.slice(start, end)}
    `});
    await page.evaluate(() => document.querySelector('[data-scm-runtime]').dispatchEvent(new CustomEvent('scm:open-panel-case', {detail: {ticket_id: '10999'}})));
    await page.waitForFunction(() => nativeCases.length === 1);
    assert.equal(await page.evaluate(() => nativeCases[0].id), '10999', 'email deep link uses native case context');
    console.log('PASS: modal, validation, assignment, uploads, retries, focus, desktop and mobile');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
