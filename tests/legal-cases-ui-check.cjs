const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

// Casos jurídicos en el navegador: el servidor se simula con fetch; la estructura sale del PHP real.
const root = path.resolve(__dirname, '..');
const shell = execFileSync('php', ['-r', "require 'vendor/autoload.php'; echo (new SCM\\Modules\\LegalCases\\LegalCaseView())->renderShell(true);"], { cwd: root, encoding: 'utf8' });

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
  await page.setContent('<!doctype html><html><head></head><body><div id="scm-app"><div class="scm-tab-panel active">' + shell + '</div></div></body></html>');
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/legal-cases.css') });
  await page.evaluate(({ caseRow, detail }) => {
    window.calls = [];
    window.SCMAdminCore = { parseRuntime: () => ({ ajaxUrl: '/ajax', nonce: 'n1' }) };
    window.fetch = (url, opts) => {
      if (url === 'about:blank') return Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['%PDF-1.4'], { type: 'application/pdf' })) });
      const body = {};
      opts.body.forEach((value, key) => { if (!(key in body)) body[key] = value; else body[key] = [].concat(body[key], value); });
      window.calls.push(body);
      const responses = {
        scm_legal_case_bootstrap: { types: [{ id: 2, name: 'Siniestro', active: true }, { id: 3, name: 'Viejo', active: false }], responsibles: [{ id: '5007', name: 'Ana', cargo: 'Contractual' }], statuses: { abierto: 'Abierto', en_tramite: 'En trámite', cerrado: 'Cerrado' }, roles: { propietario: 'Propietario', arrendatario: 'Arrendatario', tercero: 'Tercero' }, employee_id: '5007' },
        scm_legal_case_list: { rows: [caseRow], total: 26, page: Number(body.page || 1), pages: 2, kpis: { abierto: 1, en_tramite: 0, vencidos: 1, proximos: 0 } },
        scm_legal_case_detail: detail,
        scm_legal_case_contract_search: { rows: [{ id: 9, contract: 'C-100', property: '401', address: 'Calle 1', owner: 'Pedro', tenant: 'Laura', status: 'Entregado' }] },
        scm_legal_case_contract_parties: { parties: [{ role: 'propietario', name: 'Pedro', email: 'p@example.com', phone: '', source: 'contrato' }, { role: 'arrendatario', name: 'Laura', email: '', phone: '+573111111111', source: 'contrato' }] },
        scm_legal_case_save: { case_id: 1, code: 'CASO-0001', message: 'ok' },
        scm_legal_case_status: { message: 'ok' },
        scm_legal_case_party_remove: { message: 'ok' },
        scm_legal_case_party_add: { message: 'ok' },
      };
      if (body.action === 'scm_legal_case_save' && window.failSave) return Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'El nombre ya existe.' } }) });
      return Promise.resolve({ json: () => Promise.resolve({ success: true, data: responses[body.action] || {} }) });
    };
  }, { caseRow, detail });
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
