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
$w = ['credit'=>['id'=>12,'status'=>'pendiente','quote_id'=>574,'owner_name'=>'Propietario','property'=>'10420','purpose'=>'','requested_amount'=>893723,'requested_term'=>12,'created_at'=>time(),'decision_note'=>'','decided_by_name'=>'','decided_at'=>0],
'contract'=>['code'=>'817','estado'=>'Entregado','inicio_ts'=>0,'fin_ts'=>0,'aseguradora'=>'','siniestrado'=>false],'income_source'=>'','settings'=>$s,'evaluation'=>$ev,
'conditions'=>['amount'=>893723,'term'=>12,'rate'=>0.023,'payment'=>86070,'total_interest'=>139117],'schedule'=>[],'policies'=>[],'policies_ok'=>true,'other_credits'=>[],'events'=>[]];
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
  await browser.close();
  console.log('Owner credit UI checks passed.');
})().catch((e) => { console.error('FAIL', e.message); process.exit(1); });
