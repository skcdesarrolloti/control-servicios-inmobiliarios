const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

// Asistente de la revisión preventiva en el popup del caso: pasos, validación, "Todo sin novedad",
// daños condicionales, total del cobro, envío y autoguardado.
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-admin.js'), 'utf8');
const start = source.indexOf('  var DAMAGE_REVIEW_KINDS = {');
const editor = source.slice(start, source.indexOf('  function openTicketResponseEditor(', start));
assert(start > 0 && editor.includes('function openDamageReviewEditor'), 'Preventive editor code was not found');
const drafts = fs.readFileSync(path.join(__dirname, '../public/assets/js/ticket-completion-form-state.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../public/assets/css/preventive-review.css'), 'utf8');
const forms = JSON.parse(execFileSync('php', [path.join(__dirname, 'preventive-review-check.php')], { env: { ...process.env, PREV_EMIT_FORM: '1' }, encoding: 'utf8' }));

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });
    const errors = [];
    const posts = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('https://panel.test/', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><style>' + css + '</style></head><body><div id="app" data-scm-draft-user="qa"><div id="modal"><div id="sub"><h4 class="scm-case-submodal-title"></h4><div class="scm-case-submodal-body"></div></div></div><button id="case" data-ticket-pk="10883"></button></div></body></html>' }));
    await page.route('https://panel.test/api.php', async route => {
      const body = route.request().postDataBuffer().toString('latin1');
      const operation = (body.match(/name="operation"\r\n\r\n([^\r]+)/) || [])[1];
      posts.push({ operation, body });
      const html = operation === 'read' ? forms.create : '<div class="scm-acta"><p data-corrective-review-message></p>Guardada</div>';
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { html, message: operation === 'create' ? 'Revisión preventiva #1 guardada.' : '' } }) });
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
      window.openPrev = function () { openPreventiveReviewEditor(document.getElementById('modal'), document.getElementById('case')); };
    ` });
    await page.evaluate(() => window.openPrev());
    const form = page.locator('[data-preventive-review-form]');
    await form.waitFor();
    const visibleStep = () => page.evaluate(() => document.querySelector('[data-prev-step]:not([hidden])').getAttribute('data-prev-step'));
    assert.equal(await visibleStep(), 'inicio');
    assert.equal(await page.isVisible('[data-prev-back]'), false, 'Back button hidden on the first step');
    assert.equal(await page.isVisible('[data-prev-submit]'), false, 'Submit only on the last step');

    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'inmueble-estructura');
    await page.click('[data-prev-step="inmueble-estructura"] .scm-prev-group:first-child [data-prev-all-ok]');
    const firstGroup = await page.evaluate(() => {
      const questions = [...document.querySelectorAll('[data-prev-step="inmueble-estructura"] .scm-prev-group:first-child .scm-prev-question[data-prev-alert]')];
      return questions.map(q => (q.querySelector('input:checked') || {}).value + '|' + q.getAttribute('data-prev-alert'));
    });
    assert(firstGroup.length > 0 && firstGroup.every(pair => { const [value, alert] = pair.split('|'); return value !== 'undefined' && value !== alert; }), 'Todo sin novedad must choose the non-finding answer');
    // Marcar un hallazgo lo resalta.
    await page.check('input[name="checklist[suelos][pregunta_2_suelos]"][value="Si"]', { force: true });
    assert.equal(await page.evaluate(() => document.querySelector('input[name="checklist[suelos][pregunta_2_suelos]"]').closest('.scm-prev-question').classList.contains('is-alert')), true);

    // Saltar al contrato: el uso principal es obligatorio.
    await page.click('[data-prev-goto="contrato"]');
    assert.equal(await visibleStep(), 'contrato');
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'contrato', 'Required usage must block the step');
    await page.fill('textarea[name="checklist[cumplimiento][observaciones_cumplimiento]"]', 'Vivienda familiar');
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'danos');

    // Daños: obligatorio responder; con "Sí" se exigen los campos del daño; con "No" se ocultan y deshabilitan.
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'danos', 'Found damages answer is required');
    await page.check('[data-prev-found][value="Si"]', { force: true });
    assert.equal(await page.isVisible('[data-prev-damages]'), true);
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'danos', 'Empty damage must block the step');
    await page.check('[data-prev-found][value="No"]', { force: true });
    assert.equal(await page.isVisible('[data-prev-damages]'), false);
    assert.equal(await page.evaluate(() => [...document.querySelectorAll('[data-prev-damages] select, [data-prev-damages] textarea')].every(f => f.disabled)), true, 'Hidden damage fields must be disabled');
    await page.click('[data-prev-next]');
    assert.equal(await visibleStep(), 'cobro');
    assert.equal(await page.isVisible('[data-prev-submit]'), true);
    const fee = Number(await page.getAttribute('[data-prev-fee]', 'data-prev-fee'));
    assert(fee > 0, 'Fee must be shown');
    const transport = await page.$eval('[data-prev-transport]', s => s.value);
    assert.equal(await page.textContent('[data-prev-total]'), '$' + (fee + Number(transport)).toLocaleString('es-CO'));
    await page.selectOption('[data-prev-transport]', '0');
    assert.equal(await page.textContent('[data-prev-total]'), '$' + fee.toLocaleString('es-CO'));
    assert.match(await page.textContent('[data-prev-review]'), /Hallazgos que requieren atención: 1/);

    // Autoguardado: reabrir el editor restaura lo escrito.
    await page.waitForTimeout(600);
    await page.evaluate(() => { document.querySelector('#sub .scm-case-submodal-body').innerHTML = ''; window.openPrev(); });
    await page.locator('[data-preventive-review-form]').waitFor();
    await page.waitForFunction(() => document.querySelector('textarea[name="checklist[cumplimiento][observaciones_cumplimiento]"]').value === 'Vivienda familiar', null, { timeout: 5000 });
    assert.equal(await page.isChecked('input[name="checklist[suelos][pregunta_2_suelos]"][value="Si"]'), true, 'Draft restores checklist answers');
    assert.equal(await page.isChecked('[data-prev-found][value="No"]'), true, 'Draft restores damages answer');
    assert.equal(await page.isVisible('[data-prev-damages]'), false, 'Restored "No" keeps damages hidden');

    // Enviar.
    await page.click('[data-prev-goto="cobro"]');
    await page.click('[data-prev-submit]');
    await page.waitForFunction(() => window.saved === 1, null, { timeout: 5000 });
    const sent = posts.find(p => p.operation === 'create');
    assert(sent, 'Create request was not sent');
    assert.match(sent.body, /name="checklist\[suelos\]\[pregunta_2_suelos\]"\r\n\r\nSi/);
    assert.match(sent.body, /name="encontro_danos"\r\n\r\nNo/);
    assert.match(sent.body, /name="transporte"\r\n\r\n0/);
    assert.match(sent.body, /name="action"\r\n\r\nscm_revision_preventiva/);
    assert.doesNotMatch(sent.body, /name="items\[0\]/, 'Disabled damage fields must not be sent');

    // Móvil: sin desbordamiento horizontal.
    await page.setViewportSize({ width: 380, height: 800 });
    await page.evaluate(() => { document.querySelector('#sub .scm-case-submodal-body').innerHTML = ''; window.openPrev(); });
    await page.locator('[data-preventive-review-form]').waitFor();
    await page.click('[data-prev-next]');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true, 'Wizard overflows on mobile');

    assert.deepEqual(errors, []);
    console.log('PASS: preventive wizard steps, validation, quick fill, finding highlight, conditional damages, charge total, autosave restore, submit payload and mobile layout.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
