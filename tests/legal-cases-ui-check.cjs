const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

// Casos jurídicos en el navegador: el servidor se simula con fetch; la estructura sale del PHP real.
const root = path.resolve(__dirname, '..');
const shell = execFileSync('php', ['-r', "require 'vendor/autoload.php'; echo (new SCM\\Modules\\LegalCases\\LegalCaseView())->renderShell(['manage'=>true,'send'=>true,'config'=>true,'export'=>true], 'https://panel.test/quill.js', 'https://panel.test/quill.css');"], { cwd: root, encoding: 'utf8' });
const communication = {
  id: 4, case_id: 1, case_code: 'CASO-0001', subject: 'Aviso', channels: ['Correo', 'WhatsApp'], sender_name: 'Ana', created_at: 1762000300, reply_to: 'ana@example.com', whatsapp_template: 'scm_comunicacion_caso_v1',
  attachments: [{ name: 'acta.pdf', url: 'about:blank', mime: 'application/pdf', bytes: 10, sha256: 'hash-del-acta' }],
  recipients: [
    { id: 1, kind: 'to', channel: 'email', channel_label: 'Correo', name: 'Pedro', destination: 'p@example.com', subject: 'Aviso Pedro', html: '<p>Hola Pedro</p>', status: 'enviado', status_label: 'Aceptado por el servidor', error: '', provider_id: '', accepted_at: 1762000400 },
    { id: 2, kind: 'to', channel: 'whatsapp', channel_label: 'WhatsApp', name: 'Laura', destination: '+573111111111', subject: 'Aviso Laura', html: '<p>Hola Laura</p>', status: 'fallido', status_label: 'Fallido', error: 'Número inválido', provider_id: '', accepted_at: 0 },
  ],
};

const caseRow = { id: 1, code: 'CASO-0001', name: '<b>Incendio</b> 401', type_id: 2, type_name: 'Siniestro', description: 'Hechos', contract_id: 9, contract_code: 'C-100', property: '401', address: 'Calle 1', responsible_id: '5007', responsible_name: 'Ana', status: 'abierto', status_label: 'Abierto', opened_date: '2026-11-01', due_date: '2026-11-20', next_action: 'Llamar', overdue: true };
const detail = {
  case: caseRow,
  parties: [{ id: 3, role: 'propietario', role_label: 'Propietario', name: 'Pedro', company: '', document: '', email: 'p@example.com', phone: '' }],
  events: [{ id: 1, action: 'caso_creado', actor_name: 'Ana', created_at: 1762000000, details: { responsable: 'Ana' } }, { id: 2, action: 'nota', actor_name: 'Ana', created_at: 1762000100, details: { texto: '<img src=x onerror=alert(1)>' } }, { id: 3, action: 'respuesta_registrada', actor_name: 'Ana', created_at: 1762000200, details: { remitente: 'Gigante', canal: 'Correo', fecha_recepcion: '2026-11-05', asunto: 'Re', texto: 'Aceptamos' } }],
  documents: [{ id: 8, event_id: 3, name: 'carta.pdf', mime: 'application/pdf', bytes: 2048, sha256: 'abc', uploaded_by: 'Ana', created_at: 1762000200, url: 'about:blank' }],
};

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('https://panel.test/quill.js', (route) => route.fulfill({ path: path.join(root, 'public/assets/vendor/quill/quill.js'), contentType: 'text/javascript' }));
  await page.route('https://panel.test/quill.css', (route) => route.fulfill({ path: path.join(root, 'public/assets/vendor/quill/quill.snow.css'), contentType: 'text/css' }));
  await page.setContent('<!doctype html><html><head></head><body><button id="settings" data-legal-case-settings>Config</button><div id="scm-app"><div class="scm-tab-panel active">' + shell + '</div></div></body></html>');
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/legal-cases.css') });
  await page.evaluate(({ caseRow, detail, communication }) => {
    window.calls = [];
    window.SCMAdminCore = { parseRuntime: () => ({ ajaxUrl: '/ajax', nonce: 'n1' }) };
    window.fetch = (url, opts) => {
      if (url === 'about:blank') return Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['%PDF-1.4'], { type: 'application/pdf' })) });
      const body = {};
      opts.body.forEach((value, key) => { if (!(key in body)) body[key] = value; else body[key] = [].concat(body[key], value); });
      window.calls.push(body);
      const responses = {
        scm_legal_case_bootstrap: { types: [{ id: 2, name: 'Siniestro', active: true }, { id: 3, name: 'Viejo', active: false }], responsibles: [{ id: '5007', name: 'Ana', cargo: 'Contractual' }], statuses: { abierto: 'Abierto', en_tramite: 'En trámite', cerrado: 'Cerrado' }, roles: { propietario: 'Propietario', arrendatario: 'Arrendatario', tercero: 'Tercero' }, employee_id: '5007', templates: [{ id: 6, name: 'Requerimiento', subject: 'Requerimiento {{codigo_caso}}', body_html: '<p>Texto de la plantilla</p>', active: true }], variables: ['nombre_destinatario', 'fecha'] },
        scm_legal_case_preview: { subject: 'Requerimiento CASO-0001', html: '<p>Hola Pedro</p>', addressee: 'Pedro', email_count: 1, whatsapp_count: 0, recipients: [{ name: 'Pedro', email: 'p@example.com', phone: '' }], cc: [], reply_to: 'ana@example.com' },
        scm_legal_case_send: { communication_id: 4, email: 1, whatsapp: 0, message: 'ok' },
        scm_legal_case_communication: communication,
        scm_legal_case_communications: { rows: [{ id: 4, case_id: 1, case_code: 'CASO-0001', subject: 'Aviso', channels: ['Correo'], sender_name: 'Ana', created_at: 1762000300, recipients: ['Pedro'], failed: 1, queued: 0, accepted: 1 }], total: 1, page: 1, pages: 1 },
        scm_legal_case_config: { types: [{ id: 2, name: 'Siniestro', active: true }], templates: [{ id: 6, name: 'Requerimiento', subject: 'R', body_html: '<p>Cuerpo</p>', active: true }], variables: ['nombre_destinatario', 'fecha'] },
        scm_legal_case_type_save: { message: 'ok' },
        scm_legal_case_template_save: { message: 'ok' },
        scm_legal_case_list: { rows: [caseRow], total: 26, page: Number(body.page || 1), pages: 2, kpis: { abierto: 1, en_tramite: 0, vencidos: 1, proximos: 0 } },
        scm_legal_case_detail: detail,
        scm_legal_case_contract_search: { rows: [{ id: 9, contract: 'C-100', property: '401', address: 'Calle 1', owner: 'Pedro', tenant: 'Laura', status: 'Entregado' }] },
        scm_legal_case_contract_parties: { parties: [{ role: 'propietario', name: 'Pedro', email: 'p@example.com', phone: '', source: 'contrato' }, { role: 'arrendatario', name: 'Laura', email: '', phone: '+573111111111', source: 'contrato' }] },
        scm_legal_case_save: { case_id: 1, code: 'CASO-0001', message: 'ok' },
        scm_legal_case_status: { message: 'ok' },
        scm_legal_case_party_remove: { message: 'ok' },
        scm_legal_case_party_add: { message: 'ok' },
      };
      if (body.action === 'scm_legal_case_send' && window.failSend) return Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'No se pudo encolar. No se envió nada.' } }) });
      if (body.action === 'scm_legal_case_save' && window.failSave) return Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'El nombre ya existe.' } }) });
      return Promise.resolve({ json: () => Promise.resolve({ success: true, data: responses[body.action] || {} }) });
    };
  }, { caseRow, detail, communication });
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/admin-legal-cases.js') });
  await page.waitForSelector('[data-scm-lc-open]');

  const lastCall = (action) => page.evaluate((name) => window.calls.filter((call) => call.action === name).pop(), action);
  assert.match(await page.textContent('[data-scm-lc-kpis]'), /Vencidos/);
  assert.equal(await page.locator('.scm-lc-table b').count(), 0, 'list escapes case names');
  assert.match(await page.textContent('.scm-lc-table'), /<b>Incendio<\/b>/);
  assert.equal(await page.locator('[data-scm-lc-filters] [name=type_id] option').count(), 3);
  console.log('OK list renders, escapes and fills filters');

  await page.click('[data-scm-lc-page="2"]');
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_list' && call.page === '2'));
  await page.selectOption('[data-scm-lc-filters] [name=status]', 'abierto');
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_list' && call.status === 'abierto' && call.page === '1'));
  console.log('OK pagination and filters go to the server');

  await page.click('[data-scm-lc-new]');
  const form = page.locator('.scm-lc-modal form');
  await form.locator('[data-scm-lc-search-query]').fill('C-100');
  await form.locator('[data-scm-lc-search]').click();
  await form.locator('[data-scm-lc-pick-contract="0"]').click();
  await form.locator('.scm-lc-party').first().waitFor();
  assert.equal(await form.locator('.scm-lc-party').count(), 2, 'contract loads owner and tenant');
  await form.locator('[data-scm-lc-draft-remove="0"]').click();
  await form.locator('details summary').click();
  await form.locator('[data-party-field=name]').fill('Gigante del Hogar');
  await form.locator('[data-party-field=email]').fill('g@example.com');
  await form.locator('[data-scm-lc-draft-add]').click();
  assert.equal(await form.locator('[name=name]').count(), 1, 'draft party fields do not collide with the case name');
  await form.locator('[name=name]').fill('Caso Gigante');
  await form.locator('[name=type_id]').selectOption('2');
  assert.equal(await form.locator('[name=type_id] option[value="3"]').count(), 0, 'inactive types are not offered');
  await page.evaluate(() => { window.failSave = true; });
  await form.locator('[type=submit]').click();
  await page.waitForSelector('.scm-lc-form-error');
  assert.match(await page.textContent('.scm-lc-form-error'), /ya existe/);
  assert.equal(await form.locator('[name=name]').inputValue(), 'Caso Gigante', 'a failed save keeps what was typed');
  await page.evaluate(() => { window.failSave = false; });
  await form.locator('[type=submit]').click();
  await page.waitForFunction(() => document.querySelector('.scm-lc-modal-card h2') && document.querySelector('.scm-lc-modal-card h2').textContent.includes('CASO-0001'));
  const saved = await lastCall('scm_legal_case_save');
  assert.equal(saved.name, 'Caso Gigante');
  assert.equal(saved.contract_id, '9');
  const parties = JSON.parse(saved.parties);
  assert.deepEqual(parties.map((party) => party.name), ['Laura', 'Gigante del Hogar']);
  console.log('OK new case links a contract, edits parties and opens the case after saving');

  assert.equal(await page.locator('.scm-lc-timeline img').count(), 0, 'timeline escapes notes');
  assert.match(await page.textContent('.scm-lc-timeline'), /onerror/);
  await page.click('[data-scm-lc-status-open]');
  await page.locator('.scm-lc-modal >> nth=-1').locator('[name=status]').selectOption('cerrado');
  await page.locator('.scm-lc-modal >> nth=-1').locator('[type=submit]').click();
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_status'));
  assert.equal((await lastCall('scm_legal_case_status')).status, 'cerrado');
  await page.waitForFunction(() => document.querySelectorAll('.scm-lc-modal').length === 1);
  console.log('OK status change keeps the case open behind it');

  await page.click('[data-scm-lc-party-remove="3"]');
  assert.match(await page.locator('.scm-lc-modal >> nth=-1').textContent(), /Retirar a Pedro/);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('.scm-lc-modal').count(), 1, 'Escape closes only the top popup');
  assert.equal(await lastCall('scm_legal_case_party_remove'), undefined, 'cancelling does not remove the party');
  await page.click('[data-scm-lc-party-remove="3"]');
  await page.click('[data-scm-lc-accept]');
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_party_remove'));
  console.log('OK removing a party asks for confirmation');

  assert.equal(await page.locator('.scm-lc-timeline .scm-lc-file').count(), 1, 'the reply shows its attachment');
  await page.click('[data-scm-lc-timeline-filter="notas"]');
  assert.equal(await page.locator('.scm-lc-event').count(), 1, 'timeline filter narrows the events');
  await page.click('[data-scm-lc-timeline-filter="todo"]');
  assert.equal(await page.locator('.scm-lc-event').count(), 3);
  await page.click('.scm-lc-timeline [data-scm-lc-doc="8"]');
  await page.waitForFunction(() => (document.querySelector('iframe.scm-lc-preview') || {}).src?.startsWith('blob:'));
  console.log('OK PDF previews inside a popup from a local copy');
  await page.keyboard.press('Escape');
  await page.click('[data-scm-lc-note]');
  await page.locator('.scm-lc-modal >> nth=-1').locator('[name=text]').fill('Llamó el propietario');
  await page.locator('.scm-lc-modal >> nth=-1').locator('[type=submit]').click();
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_note' && call.text === 'Llamó el propietario' && call.case_id === '1'));
  await page.waitForFunction(() => document.querySelectorAll('.scm-lc-modal').length === 1);
  await page.click('[data-scm-lc-reply]');
  const reply = page.locator('.scm-lc-modal >> nth=-1');
  await reply.locator('[name=from]').fill('Gigante');
  await reply.locator('[name=body]').fill('Aceptamos');
  await reply.locator('[type=submit]').click();
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_reply' && call.channel === 'Correo' && call.from === 'Gigante'));
  await page.waitForFunction(() => document.querySelectorAll('.scm-lc-modal').length === 1);
  console.log('OK notes, manual replies, documents and timeline filters');

  const topModal = () => page.locator('.scm-lc-modal >> nth=-1');
  assert.equal(await page.evaluate(() => !!window.Quill), false, 'the editor is not loaded until it is needed');
  await page.click('[data-scm-lc-compose]');
  await page.waitForSelector('.scm-lc-editor .ql-editor');
  const compose = topModal();
  await compose.locator('[data-scm-lc-party="3"]').selectOption('to');
  await compose.locator('[data-scm-lc-template]').selectOption('6');
  assert.equal(await compose.locator('[name=subject]').inputValue(), 'Requerimiento {{codigo_caso}}');
  assert.match(await compose.locator('.ql-editor').textContent(), /Texto de la plantilla/);
  await compose.locator('.ql-editor').click();
  await page.keyboard.type(' Más texto ');
  await compose.locator('[data-scm-lc-var="fecha"]').click();
  assert.match(await compose.locator('.ql-editor').textContent(), /Más texto \{\{fecha\}\}/);
  await compose.locator('details summary').click();
  await compose.locator('[data-scm-lc-manual-add]').click();
  await page.waitForSelector('.scm-lc-form-error');
  await compose.locator('[data-scm-lc-manual="email"]').fill('otro@example.com');
  await compose.locator('[data-scm-lc-manual="kind"]').selectOption('cc');
  await compose.locator('[data-scm-lc-manual-add]').click();
  assert.equal(await compose.locator('[data-scm-lc-manual-list] .scm-lc-party').count(), 1);
  await compose.locator('[data-scm-lc-attach="8"]').check();
  // Cambiar de plantilla con texto escrito pregunta antes de borrar.
  await compose.locator('[data-scm-lc-template]').selectOption('');
  await compose.locator('[data-scm-lc-template]').selectOption('6');
  await topModal().locator('[data-scm-lc-close]:not(.scm-lc-icon-btn)').click();
  assert.match(await compose.locator('.ql-editor').textContent(), /Más texto/, 'keeping what was written');
  assert.equal(await compose.locator('[data-scm-lc-template]').inputValue(), '');
  await compose.locator('[type=submit]').click();
  await page.waitForSelector('[data-scm-lc-accept]');
  assert.match(await topModal().textContent(), /no se puede editar ni borrar/);
  assert.equal(await lastCall('scm_legal_case_send'), undefined, 'review does not send');
  await page.evaluate(() => { window.failSend = true; });
  await topModal().locator('[data-scm-lc-accept]').click();
  await page.waitForFunction(() => document.querySelector('.scm-lc-modal:last-child .scm-lc-form-error'));
  await page.evaluate(() => { window.failSend = false; });
  await topModal().locator('[data-scm-lc-accept]').click();
  await page.waitForFunction(() => window.calls.filter((call) => call.action === 'scm_legal_case_send').length === 2 && document.querySelectorAll('.scm-lc-modal').length === 1);
  const sends = await page.evaluate(() => window.calls.filter((call) => call.action === 'scm_legal_case_send'));
  assert.equal(sends[0].request_id, sends[1].request_id, 'a retry reuses the request id');
  assert.match(sends[1].request_id, /^[a-f0-9-]{36}$/);
  assert.deepEqual(JSON.parse(sends[1].channels), ['email']);
  assert.deepEqual(JSON.parse(sends[1].recipients), [{ party_id: 3, kind: 'to' }, { name: '', email: 'otro@example.com', phone: '', kind: 'cc' }]);
  assert.deepEqual(JSON.parse(sends[1].document_ids), [8]);
  assert.match(sends[1].body.replaceAll('&nbsp;', ' '), /Texto de la plantilla/, 'the server turns the hard spaces from the editor back into normal ones');
  console.log('OK compose: template, variables, manual recipients, review and retry');

  await page.keyboard.press('Escape');
  await page.click('[data-scm-lc-view-btn="comms"]');
  await page.waitForSelector('[data-scm-lc-comm="4"]');
  assert.equal(await page.isVisible('[data-scm-lc-filters]'), false);
  assert.match(await page.textContent('[data-scm-lc-comm-list]'), /1 fallido/);
  await page.fill('[data-scm-lc-comm-filters] [name=recipient]', 'pedro');
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_communications' && call.recipient === 'pedro'));
  await page.waitForSelector('[data-scm-lc-comm="4"]');
  await page.click('[data-scm-lc-comm="4"]');
  await page.waitForSelector('[data-scm-lc-rendered-body] p');
  assert.match(await topModal().textContent(), /No confirma que haya llegado/);
  assert.match(await topModal().textContent(), /Número inválido/);
  assert.match(await page.textContent('[data-scm-lc-rendered-body]'), /Hola Pedro/);
  await page.click('[data-scm-lc-rendered="1"]');
  assert.match(await page.textContent('[data-scm-lc-rendered-body]'), /Hola Laura/);
  assert.equal(await topModal().locator('text=/editar|borrar/i').count(), 0, 'a sent communication offers no edit or delete');
  await page.keyboard.press('Escape');
  console.log('OK sent communications: search, status and exact content per recipient');

  await page.click('#settings');
  await page.waitForSelector('[data-scm-lc-template-edit="6"]');
  await page.click('[data-scm-lc-type-new]');
  await topModal().locator('[name=name]').fill('Tutela');
  await topModal().locator('[type=submit]').click();
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_type_save' && call.name === 'Tutela' && call.active === '1'));
  await page.waitForFunction(() => document.querySelectorAll('.scm-lc-modal').length === 1);
  await page.click('[data-scm-lc-template-edit="6"]');
  await page.waitForSelector('.scm-lc-editor .ql-editor');
  assert.match(await topModal().locator('.ql-editor').textContent(), /Cuerpo/);
  await topModal().locator('[type=submit]').click();
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_legal_case_template_save' && call.id === '6' && /Cuerpo/.test(call.body)));
  await page.waitForFunction(() => document.querySelectorAll('.scm-lc-modal').length === 1);
  await page.keyboard.press('Escape');
  await page.click('[data-scm-lc-view-btn="cases"]');
  console.log('OK settings: case types and templates');

  await page.click('[data-scm-lc-open="1"]');
  await page.waitForSelector('.scm-lc-timeline');

  await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 375, height: 760 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, 'no horizontal scroll on a phone, got ' + overflow);
  await page.click('[data-scm-lc-open="1"]');
  await page.waitForSelector('.scm-lc-timeline');
  const box = await page.locator('.scm-lc-modal-card').boundingBox();
  assert.equal(Math.round(box.width), 375, 'popup fills the phone');
  console.log('OK phone layout');

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Casos jurídicos UI: verificación completa.');
})().catch((error) => { console.error(error); process.exit(1); });
