// Respuesta pública de la cotización con la simulación de la financiación y Herramientas › Simulador de crédito.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const fixture = JSON.parse(execFileSync('php', ['tests/credit-simulator-ui-fixture.php'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }));
const publicPage = fs.readFileSync('public/cotizacion-mantenimiento.php', 'utf8');
const publicStyle = publicPage.slice(publicPage.indexOf('<style>') + 7, publicPage.indexOf('</style>'));
const publicScript = publicPage.slice(publicPage.lastIndexOf('<script>') + 8, publicPage.lastIndexOf('</script>'));
const tools = fs.readFileSync('public/assets/js/admin-tools.js', 'utf8');
const sharedPreview = fs.readFileSync('public/assets/js/quote-financing-preview.js', 'utf8');
const runtime = fs.readFileSync('public/assets/js/admin-dashboard-runtime.js', 'utf8');
const runtimeTerm = runtime.slice(runtime.indexOf('    function loadQuoteFinancingTermLimit('), runtime.indexOf('    function openCotizacionActaFromCard('));
const extract = (start, end) => tools.slice(tools.indexOf(start), tools.indexOf(end, tools.indexOf(start)));
const toolFunctions = extract('  function initCreditSimulator(', '  function initDuplicateInventory(') + extract('  function escapeHtml(', '\n  }\n') + '\n  }\n';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    // ── Respuesta pública ──
    const page = await browser.newPage();
    await page.setContent('<style>' + publicStyle + '</style><main id="scm-app">' + fixture.publicHtml + '</main>');
    await page.addScriptTag({ content: sharedPreview });
    await page.addScriptTag({ content: publicScript });
    const financing = page.locator('[data-public-quote-financing]');
    assert.equal(await financing.isVisible(), false, 'the simulation stays hidden until the owner approves with financing');
    await page.locator('[name=estado]').selectOption('Aprobada');
    assert.equal(await financing.isVisible(), false, 'approving alone does not show the simulation');
    await page.locator('[name=financiacion]').selectOption('Si');
    assert.equal(await financing.isVisible(), true, 'financing Sí shows the simulation');
    assert.equal(await page.locator('[data-scm-qfp-term]').count(), 3);
    assert.match(await page.locator('[data-scm-qfp-plan]').innerText(), /Elige un plazo/);
    const option = page.locator('[data-scm-qfp-term="3"]');
    const optionStyle = await option.evaluate((node) => getComputedStyle(node).backgroundColor);
    assert.notEqual(optionStyle, 'rgb(255, 138, 0)', 'term options do not inherit the orange submit button style');
    await option.click();
    assert.equal(await page.locator('[name=plazo_financiacion]').inputValue(), '3', 'choosing an option fills the months');
    assert.equal(await option.getAttribute('aria-pressed'), 'true');
    assert.equal(await page.locator('[data-scm-qfp-plan] tbody tr').count(), 3, 'the plan lists one row per installment');
    const planText = await page.locator('[data-scm-qfp-plan]').innerText();
    assert.match(planText, /Cuota mensual estimada/i);
    assert.match(planText, /893\.723/, 'the plan total row shows the financed amount as principal');
    await page.locator('[name=plazo_financiacion]').fill('2');
    assert.equal(await page.locator('[data-scm-qfp-plan] tbody tr').count(), 2, 'typing the months updates the plan');
    assert.equal(await page.locator('[data-scm-qfp-term="2"]').getAttribute('aria-pressed'), 'true');
    await page.locator('[name=plazo_financiacion]').fill('9');
    assert.match(await page.locator('[data-scm-qfp-plan]').innerText(), /No hay simulación para 9 meses/);
    assert.equal(await page.locator('[data-public-quote-response-term-error]').isVisible(), true, 'more months than the maximum is still flagged');
    await page.locator('[name=financiacion]').selectOption('No');
    assert.equal(await financing.isVisible(), false, 'financing No hides the simulation');
    await page.setViewportSize({ width: 390, height: 800 });
    await page.locator('[name=financiacion]').selectOption('Si');
    await page.locator('[data-scm-qfp-term="1"]').click();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(overflow <= 1, 'the simulation fits a phone screen without horizontal scroll');

    // ── Popup del panel "Financiamiento de cotización" (y el de respuesta): misma simulación bajo "¿A cuántos meses?" ──
    const popup = await browser.newPage();
    await popup.setContent('<div class="scm-cotizacion-response-form"><label data-scm-quote-financing-term hidden><span>¿A cuántos meses?</span><input type="number" min="1" data-scm-quote-financing-term-input><small data-scm-quote-financing-term-hint></small></label><label>Observaciones</label></div>');
    await popup.addScriptTag({ content: sharedPreview });
    await popup.addScriptTag({ content: `
      var nonce = 'n', ajaxUrl = '/api.php'; window.sent = [];
      window.fetch = async function (url, options) { sent.push(Object.fromEntries(options.body)); return { json: async function () { return { success: true, data: { max: 3, preview: ${JSON.stringify(fixture.panelPreview)} } }; } }; };
      ${runtimeTerm}
      window.syncQuoteFinancingTerm = syncQuoteFinancingTerm; window.quoteFinancingTermError = quoteFinancingTermError; window.quoteFinancingTermValue = quoteFinancingTermValue;
    ` });
    await popup.evaluate(() => syncQuoteFinancingTerm(document.body, true, { id_cotizacion: '608' }));
    await popup.waitForSelector('[data-scm-qfp-term]');
    const sentTerm = await popup.evaluate(() => window.sent[0]);
    assert.equal(sentTerm.action, 'scm_cotizacion_financing_term');
    assert.equal(sentTerm.id_cotizacion, '608');
    assert.match(await popup.locator('[data-scm-quote-financing-term-hint]').innerText(), /Máximo 3 meses/);
    assert.equal(await popup.locator('[data-scm-qfp-term]').count(), 3, 'the panel popup lists the payment of every term');
    await popup.locator('[data-scm-qfp-term="2"]').click();
    assert.equal(await popup.locator('[data-scm-quote-financing-term-input]').inputValue(), '2', 'choosing a term fills the months field of the popup');
    assert.equal(await popup.locator('[data-scm-qfp-plan] tbody tr').count(), 2);
    assert.equal(await popup.evaluate(() => quoteFinancingTermValue(document.body)), '2');
    await popup.locator('[data-scm-quote-financing-term-input]').fill('3');
    assert.equal(await popup.locator('[data-scm-qfp-plan] tbody tr').count(), 3, 'typing the months updates the plan in the popup');
    await popup.locator('[data-scm-quote-financing-term-input]').fill('4');
    assert.equal(await popup.evaluate(() => quoteFinancingTermError(document.body)), 'El plazo máximo es de 3 meses.');
    await popup.evaluate(() => syncQuoteFinancingTerm(document.body, false, { id_cotizacion: '608' }));
    assert.equal(await popup.locator('[data-scm-qfp]').isVisible(), false, 'financing No hides the simulation in the popup');
    await popup.evaluate(() => syncQuoteFinancingTerm(document.body, true, { id_cotizacion: '608' }));
    assert.equal(await popup.locator('[data-scm-qfp]').isVisible(), true);
    assert.equal(await popup.evaluate(() => window.sent.length), 1, 'the maximum and the simulation are requested once per popup');

    // ── Herramienta ──
    const tool = await browser.newPage();
    await tool.setContent('<div id="root">' + fixture.toolHtml + '</div>');
    await tool.addScriptTag({ content: `
      window.calls = []; window.toasts = [];
      window.fixture = ${JSON.stringify(fixture.responses)};
      ${toolFunctions}
      window.rateResponse = ${JSON.stringify(fixture.rateResponse)};
      var post = function (action, data) {
        if (action === 'scm_tools_credit_rate') { calls.push({ action: action }); return Promise.resolve(rateResponse); }
        calls.push({ action: action, plazo: data.get('plazo'), valor: data.get('valor') });
        return Promise.resolve(fixture[data.get('plazo')]);
      };
      var request = function () { return Promise.reject(new Error('sin PDF en la prueba')); };
      initCreditSimulator(document.querySelector('[data-scm-tool="credit-simulator"]'), { scmNotify: function (type, message) { toasts.push(type + ':' + message); } }, request, post);
    ` });
    assert.equal(await tool.locator('[data-credit-sim-pdf]').isDisabled(), true, 'the PDF waits for a simulation');
    assert.equal(await tool.locator('[name=tasa]').inputValue(), '2,42', 'the rate starts at the reference rate');
    const rateCard = await tool.locator('[data-credit-sim-rate]').innerText();
    assert.match(rateCard, /Tasa actualizada/, 'the tool says whether the rate is current');
    assert.match(rateCard, /Última actualización/);
    await tool.evaluate(() => document.querySelector('[data-scm-tool="credit-simulator"]').dispatchEvent(new CustomEvent('scm:load-tool')));
    await tool.waitForFunction(() => /23\/11\/2026 08:15/.test(document.querySelector('[data-credit-sim-rate]').innerText));
    assert.equal(await tool.locator('[name=tasa]').inputValue(), '2,1', 'opening the tool verifies the rate and uses the current one');
    await tool.evaluate(() => { window.calls.length = 0; });
    await tool.locator('[name=valor]').pressSequentially('2500000');
    assert.equal(await tool.locator('[name=valor]').inputValue(), '2.500.000', 'the amount gets thousands separators');
    await tool.locator('[name=plazo]').fill('12');
    await tool.locator('[data-credit-sim-submit]').click();
    await tool.waitForFunction(() => document.querySelectorAll('[data-credit-sim-result] tbody tr').length > 0);
    const result = await tool.locator('[data-credit-sim-result]').innerText();
    assert.match(result, /Cuota mensual/i);
    assert.match(result, /30\/12\/2026/, 'a disbursement on the 20th starts paying the next month');
    assert.match(result, /tope de la política/, 'the policy warning is shown');
    assert.equal(await tool.locator('[data-credit-sim-copy]').isDisabled(), false);
    assert.equal(await tool.locator('[data-credit-sim-pdf]').isDisabled(), false);
    await tool.locator('[data-credit-sim-term="6"]').click();
    await tool.waitForFunction(() => window.calls.length === 2);
    assert.equal(await tool.locator('[name=plazo]').inputValue(), '6', 'a term from the comparison is simulated');
    await tool.waitForFunction(() => /A 6 meses/.test(document.querySelector('[data-credit-sim-result]').innerText));
    await tool.locator('[name=nombre]').fill('Ana');
    assert.equal(await tool.locator('[data-credit-sim-pdf]').isDisabled(), true, 'changing the data asks to simulate again');
    assert.match(await tool.locator('[data-credit-sim-status]').innerText(), /simula de nuevo/);
  } finally {
    await browser.close();
  }
  console.log('Credit simulator UI checks passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
