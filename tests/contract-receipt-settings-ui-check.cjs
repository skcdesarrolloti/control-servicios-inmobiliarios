const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const project = path.join(__dirname, '..');
  const runtime = fs.readFileSync(path.join(project, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
  const start = runtime.indexOf('    function bindInternalNotificationsSettings()');
  const end = runtime.indexOf('    bindInternalNotificationsSettings();', start);
  const source = fs.readFileSync(path.join(project, 'src/App/Concerns/RendersDashboard.php'), 'utf8');
  const sectionStart = source.indexOf('<section class="scm-pqr-settings-section" data-receipt-automation>');
  const section = source.slice(sectionStart, source.indexOf('</section>', sectionStart) + 10)
    .replace(/<\?php echo \(int\) \$receiptConfig\['contract_id'\]; \?>/g, '525')
    .replace(/<\?php echo \$receiptOptions\([^?]+\); \?>/g, '<option value="">Selecciona un funcionario</option><option value="EMP-13">Funcionario de prueba</option>')
    .replace(/<\?php[\s\S]*?\?>/g, '');
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage();
    await page.setContent(`<main id="scm-app"><button id="scm-open-internal-notifications">Configuración</button><div id="scm-internal-notifications-modal"><button id="scm-close-internal-notifications">Cerrar</button><form id="scm-internal-notifications-form">${section}<select name="settings[contrato_recibo_automatico][]" multiple><option value="13" selected>Funcionario interno</option></select><small id="scm-internal-notifications-msg"></small><button type="submit">Guardar</button></form></div></main>`);
    await page.addStyleTag({content: fs.readFileSync(path.join(project, 'public/assets/css/admin/08-modern-normalize.css'), 'utf8') + '\nbody{font:14px Arial;background:#f1f5f9;color:#0f1e36}main{max-width:760px;margin:20px auto;background:white;padding:24px;border-radius:16px}input,select{box-sizing:border-box;padding:8px;border:1px solid #cbd5e1;border-radius:8px}h4{font-size:20px;margin:12px 0}'});
    await page.addScriptTag({content: `var root=document.querySelector('main'), ajaxUrl='/test', nonce='synthetic', actionInternalNotificationsSave='settings-save'; function showToast(){}; window.fetch=async function(url,opts){window.saved=Object.fromEntries(opts.body);return {json:async()=>({success:true,data:{message:'Guardado'}})}}; ${runtime.slice(start, end)} bindInternalNotificationsSettings();`});
    assert.equal(await page.locator('[name="receipt_contract_id"]').inputValue(), '525');
    await page.locator('[name="receipt_enabled"]').check();
    await page.locator('[name="receipt_employee_id"]').selectOption('EMP-13');
    await page.locator('[name="receipt_coordinator_id"]').selectOption('EMP-13');
    await page.locator('button[type="submit"]').click();
    await page.waitForFunction(() => window.saved);
    const saved = await page.evaluate(() => window.saved);
    assert.deepEqual(JSON.parse(saved.receipt_automation), {enabled:true,contract_id:'525',employee_id:'EMP-13',coordinator_id:'EMP-13'});
    assert.deepEqual(JSON.parse(saved.settings), {contrato_recibo_automatico:['13']});
    const qaDir = path.join(require('node:os').tmpdir(), 'scm-receipt-letter-qa');
    fs.mkdirSync(qaDir, {recursive:true});
    await page.screenshot({path:path.join(qaDir,'settings-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth > innerWidth),false,'Configuration must fit mobile width');
    await page.screenshot({path:path.join(qaDir,'settings-mobile.png'),fullPage:true});
    console.log('PASS: configuration submits activation, exact contract scope, assigned employee, coordinator and configured internal recipients.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
