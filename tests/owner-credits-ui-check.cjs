const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

// Liquidador de créditos: abrir desde la tarjeta, simular en el servidor, formatear valores,
// confirmar la decisión dentro del popup y cerrar con Escape. El servidor se simula con fetch.
const root = path.resolve(__dirname, '..');
const fixture = String.raw`require 'vendor/autoload.php';
use SCM\Modules\OwnerCredits\{OwnerCreditCalculator, OwnerCreditSettings, OwnerCreditView};
$s = OwnerCreditSettings::defaults();
$ev = OwnerCreditCalculator::evaluate(['gross'=>1075000,'deductions'=>['comision'=>142800],'requested_amount'=>893723,'requested_term'=>12,'request_ts'=>time(),'contract_end_ts'=>strtotime('+14 months'),'vacancy'=>'Baja'], $s);
$ev['other_contracts'] = [['id'=>99,'contrato'=>'818','inmueble'=>'10421','net_income'=>900000,'max_months'=>20]];
$w = ['credit'=>['id'=>12,'status'=>'pendiente','quote_id'=>574,'owner_name'=>'Propietario','property'=>'10420','purpose'=>'','requested_amount'=>893723,'requested_term'=>12,'created_at'=>time(),'decision_note'=>'','decided_by_name'=>'','decided_at'=>0],
'contract'=>['id'=>7,'code'=>'817','estado'=>'Entregado','inicio_ts'=>0,'fin_ts'=>0,'aseguradora'=>'','siniestrado'=>false],'income_source'=>'','settings'=>$s,'evaluation'=>$ev,
'conditions'=>['amount'=>893723,'term'=>12,'rate'=>0.023,'payment'=>86070,'total_interest'=>139117],'schedule'=>[],'policies'=>[],'policies_ok'=>true,'other_credits'=>[],'events'=>[],
'owners'=>[['key'=>'mandato_1','name'=>'Primer Dueño','document'=>'111','current'=>false],['key'=>'mandato_2','name'=>'Segunda Dueña','document'=>'222','current'=>true]]];
echo (new OwnerCreditView())->workspace($w, true, true);`;
(async () => {
  const workspace = execFileSync('php', ['-r', fixture], { cwd: root, encoding: 'utf8' });
  const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  const page = await browser.newPage({viewport:{width:1280,height:900}});
  await page.setContent('<!doctype html><html><head></head><body><div id="scm-app"><button data-owner-credit-open data-quote-id="574">Liquidador</button></div></body></html>');
  await page.evaluate((workspace) => {
    window.calls = [];
    window.SCMAdminCore = { parseRuntime: () => ({ ajaxUrl: '/ajax', nonce: 'n1' }) };
    window.fetch = (url, opts) => {
      const body = Object.fromEntries(opts.body.entries());
      window.calls.push(body);
      if (body.action === 'scm_owner_credit_pdf') {
        return new Promise(r => setTimeout(() => r({ headers: { get: () => 'application/pdf' }, blob: () => Promise.resolve(new Blob(['%PDF-1.4'], { type: 'application/pdf' })) }), 400));
      }
      const data = body.action === 'scm_owner_credit_open' ? { title: 'Crédito #12 · Pendiente', html: workspace }
        : body.action === 'scm_owner_credit_simulate' ? { results_html: '<p data-sim>recalculado ' + body.amount + '</p>', schedule_html: '<p>tabla</p>' }
        : body.action === 'scm_owner_credit_decide' ? { message: 'Crédito aprobado.' } : {};
      return Promise.resolve({ json: () => Promise.resolve({ success: true, data }) });
    };
  }, workspace);
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/admin-owner-credits.js') });
  await page.click('[data-owner-credit-open]');
  await page.waitForSelector('[data-owner-credit-form]');
  let calls = await page.evaluate(() => window.calls);
  assert.equal(calls[0].action, 'scm_owner_credit_open'); assert.equal(calls[0].quote_id, '574'); assert.equal(calls[0].nonce, 'n1');
  assert.equal(await page.textContent('[data-owner-credit-title]'), 'Crédito #12 · Pendiente');
  console.log('OK open from quote card');

  await page.fill('[name="amount"]', '1000000');
  await page.waitForSelector('[data-sim]');
  calls = await page.evaluate(() => window.calls);
  const sim = calls.find(c => c.action === 'scm_owner_credit_simulate');
  assert.equal(sim.credit_id, '12'); assert.equal(sim.amount, '$1.000.000');
  console.log('OK edits are simulated on the server with the credit id');

  await page.fill('[name="contract_end"]', '2028-05-31');
  await page.waitForFunction(() => window.calls.some(c => c.action === 'scm_owner_credit_simulate' && c.contract_end === '2028-05-31'));
  console.log('OK the contract end date is simulated before saving');

  const simsBefore = (await page.evaluate(() => window.calls)).filter(c => c.action === 'scm_owner_credit_simulate').length;
  assert.equal(await page.inputValue('[name="owner_key"]'), 'mandato_2');
  await page.selectOption('[name="owner_key"]', 'mandato_1');
  await page.waitForFunction(() => window.calls.filter(c => c.action === 'scm_owner_credit_open').length === 2);
  calls = await page.evaluate(() => window.calls);
  const ownerChange = calls.find(c => c.action === 'scm_owner_credit_owner');
  assert.equal(ownerChange.credit_id, '12'); assert.equal(ownerChange.owner_key, 'mandato_1');
  assert.equal(calls.filter(c => c.action === 'scm_owner_credit_simulate').length, simsBefore, 'changing the owner does not simulate');
  assert.equal(calls[calls.length - 1].credit_id, '12');
  await page.waitForSelector('[data-owner-credit-form]');
  console.log('OK the owner of a pending credit is changed and the liquidator reloads');

  await page.selectOption('[name="main_contract_id"]', '99');
  await page.waitForFunction(() => window.calls.filter(c => c.action === 'scm_owner_credit_open').length === 3);
  calls = await page.evaluate(() => window.calls);
  const mainChange = calls.find(c => c.action === 'scm_owner_credit_contract');
  assert.equal(mainChange.credit_id, '12'); assert.equal(mainChange.contract_id, '99');
  assert.equal(calls.filter(c => c.action === 'scm_owner_credit_simulate').length, simsBefore, 'changing the main contract does not simulate');
  await page.waitForSelector('[data-owner-credit-form]');
  assert.equal(await page.inputValue('[name="siniestro"]'), 'no');
  await page.selectOption('[name="siniestro"]', 'si');
  await page.waitForFunction(() => window.calls.some(c => c.action === 'scm_owner_credit_simulate' && c.siniestro === 'si'));
  console.log('OK the main contract is changed in the liquidator and the siniestro Sí/No is simulated');

  await page.fill('[name="gross"]', '');
  await page.type('[name="gross"]', '1250000');
  assert.equal(await page.inputValue('[name="gross"]'), '$1.250.000');
  await page.evaluate(() => { const i = document.querySelector('[name="gross"]'); i.setSelectionRange(2, 2); });
  await page.keyboard.type('9');
  assert.equal(await page.inputValue('[name="gross"]'), '$19.250.000');
  assert.equal(await page.evaluate(() => document.querySelector('[name="gross"]').selectionStart), 3);
  console.log('OK money inputs are formatted as Colombian pesos while typing and keep the caret');

  await page.click('[data-owner-credit-pdf]');
  await page.waitForSelector('[data-owner-credit-pdf-viewer]');
  assert.match(await page.textContent('[data-owner-credit-pdf-viewer]'), /Generando PDF/);
  assert.equal(await page.isDisabled('[data-owner-credit-pdf]'), true);
  await page.waitForSelector('[data-owner-credit-pdf-viewer] iframe');
  assert.equal(await page.isDisabled('[data-owner-credit-pdf]'), false);
  await page.click('[data-owner-credit-pdf-close]');
  console.log('OK Ver PDF shows "Generando PDF…" until the PDF arrives');

  await page.fill('[name="note"]', 'Justificación de prueba suficiente.');
  await page.click('[data-owner-credit-decide="aprobado"]');
  await page.waitForSelector('[data-owner-credit-confirm]');
  calls = await page.evaluate(() => window.calls);
  assert.ok(!calls.some(c => c.action === 'scm_owner_credit_decide'), 'decision waits for confirmation');
  await page.click('[data-owner-credit-confirm-yes]');
  await page.waitForFunction(() => window.calls.some(c => c.action === 'scm_owner_credit_decide'));
  calls = await page.evaluate(() => window.calls);
  const decide = calls.find(c => c.action === 'scm_owner_credit_decide');
  assert.equal(decide.decision, 'aprobado'); assert.equal(decide.note, 'Justificación de prueba suficiente.'); assert.equal(decide.credit_id, '12');
  await page.waitForFunction(() => (document.querySelector('[data-owner-credit-status]') || {}).textContent === 'Crédito aprobado.');
  console.log('OK approval asks for confirmation, sends the note and reloads the credit');

  await page.keyboard.press('Escape');
  assert.equal(await page.evaluate(() => document.querySelector('[data-owner-credit-modal]').hidden), true);
  console.log('OK Escape closes the popup');

  // Nueva solicitud: se busca al propietario por documento; queda elegido el contrato de vigencia más larga (el
  // servidor lo envía primero) y se puede cambiar. Sin fecha fin. Se puede simular y ver el PDF sin registrar.
  const newForm = execFileSync('php', ['-r', String.raw`require 'vendor/autoload.php'; echo (new SCM\Modules\OwnerCredits\OwnerCreditView())->newForm();`], { cwd: root, encoding: 'utf8' });
  await page.evaluate((newForm) => {
    const button = document.createElement('button');
    button.setAttribute('data-owner-credit-new', '');
    document.getElementById('scm-app').appendChild(button);
    const owner = { key: 'mandato_2', name: 'Segunda Dueña', document: '22233344', email: 'segunda@example.invalid', phone: '573001110000' };
    window.fetch = (url, opts) => {
      const body = Object.fromEntries(opts.body.entries());
      window.calls.push(body);
      const data = body.action === 'scm_owner_credit_new_form' ? { html: newForm }
        : body.action === 'scm_owner_credit_contracts' ? { contracts: [
          { id: 9, contrato: '950', inmueble: '10600', direccion: 'Calle 1', estado: 'Entregado', arrendatario: 'Inquilino', fin: '15/01/2027', max_months: 2, owner },
          { id: 10, contrato: '951', inmueble: '10601', direccion: 'Calle 2', estado: 'Entregado', arrendatario: '', fin: '', max_months: 0, owner }],
          preapproval: { contracts: 2, net_income: 3440000, income_months: 3, amount: 10320000, longest_months: 2, margin_months: 2, max_term: 0 },
          checks: [{ label: 'Contrato activo', ok: true, detail: '2 de 2 contratos Entregado.' }, { label: 'Sin siniestro', ok: false, detail: 'Cartera marcó como siniestrado: #951.' }] }
        : body.action === 'scm_owner_credit_create' ? { credit_id: 40 } : {};
      if (body.action === 'scm_owner_credit_simulation_pdf') {
        return Promise.resolve({ headers: { get: () => 'application/pdf' }, blob: () => Promise.resolve(new Blob(['%PDF-1.4'], { type: 'application/pdf' })) });
      }
      return Promise.resolve({ json: () => Promise.resolve({ success: true, data }) });
    };
  }, newForm);
  await page.click('[data-owner-credit-new]');
  await page.waitForSelector('[data-owner-credit-new-form]');
  assert.equal(await page.$('[data-owner-credit-new-form] [name="contract_end"]'), null);
  await page.fill('[data-owner-credit-search-query]', '22.233.344');
  await page.press('[data-owner-credit-search-query]', 'Enter');
  await page.waitForSelector('[data-owner-credit-contract-card]');
  calls = await page.evaluate(() => window.calls);
  assert.equal(calls.find(c => c.action === 'scm_owner_credit_contracts').document, '22.233.344');
  assert.match(await page.textContent('[data-owner-credit-person]'), /Segunda Dueña/);
  assert.match(await page.textContent('[data-owner-credit-preapproval]'), /\$10\.320\.000/);
  assert.equal(await page.isVisible('[data-owner-credit-request]'), true, 'the request opens without choosing a contract');
  assert.equal(await page.$('[data-owner-credit-pick]'), null, 'there is nothing to choose');
  assert.equal(await page.$$eval('[data-owner-credit-contract-card]', cards => cards.filter(card => card.offsetParent !== null).length), 2, 'every contract is listed');
  assert.match(await page.textContent('[data-owner-credit-contract-card]:first-child'), /950.*Principal/);
  assert.equal(await page.inputValue('[data-owner-credit-new-form] [name="contract_id"]'), '9');
  assert.equal(await page.isVisible('[data-owner-credit-checks]'), true);
  assert.equal(await page.$$eval('[data-owner-credit-check="bad"]', items => items.length), 1);
  assert.match(await page.textContent('[data-owner-credit-checks]'), /Sin siniestro:.*#951/);
  console.log('OK the longest contract is the main one without choosing, and the policy pre-check is shown');
  await page.click('[data-owner-credit-simulate-pdf]');
  await page.waitForSelector('[data-owner-credit-pdf-viewer] iframe');
  calls = await page.evaluate(() => window.calls);
  const simulation = calls.find(c => c.action === 'scm_owner_credit_simulation_pdf');
  assert.equal(simulation.document, '22.233.344'); assert.equal(simulation.contract_id, '9'); assert.equal(simulation.owner_key, 'mandato_2');
  assert.match(await page.textContent('[data-owner-credit-pdf-viewer]'), /Simulación de crédito · Contrato 950/);
  await page.click('[data-owner-credit-pdf-close]');
  console.log('OK the request can be simulated and shown as a PDF without registering it');
  await page.fill('[name="term"]', '12');
  assert.match(await page.textContent('[data-owner-credit-term-hint]'), /Puedes registrar la solicitud/);
  await page.fill('[name="amount"]', '2000000');
  await page.click('[data-owner-credit-new-form] [type="submit"]');
  await page.waitForFunction(() => window.calls.some(c => c.action === 'scm_owner_credit_create'));
  calls = await page.evaluate(() => window.calls);
  const create = calls.find(c => c.action === 'scm_owner_credit_create');
  assert.equal(create.contract_id, '9'); assert.equal(create.owner_key, 'mandato_2'); assert.equal(create.term, '12'); assert.equal(create.contract_end, undefined);
  console.log('OK new request: owner found by document, contract chosen, long term only warns, no end date field');
  await browser.close();
  console.log('Owner credit UI checks passed.');
})().catch((e) => { console.error('FAIL', e.message); process.exit(1); });
