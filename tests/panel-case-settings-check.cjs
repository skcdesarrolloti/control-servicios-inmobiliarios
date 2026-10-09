const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const html = execFileSync('php', [path.join(__dirname, 'panel-case-settings-ui.php')], {encoding: 'utf8'});
    await page.setContent(html);
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/tailwind-admin.css'), 'utf8')});
    const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
    const start = source.indexOf('    function bindInternalNotificationsSettings()');
    const end = source.indexOf('    bindInternalNotificationsSettings();', start);
    assert(start >= 0 && end > start);
    await page.addScriptTag({content: `
      var root = document.querySelector('#root'), ajaxUrl = '/api.php', nonce = 'test-nonce', actionInternalNotificationsSave = 'save';
      function showToast() {}
      window.fetch = async (url, options) => { window.saved = Object.fromEntries(options.body); return {json: async () => ({success: true, data: {message: 'Guardado'}})}; };
      ${source.slice(start, end)}
      bindInternalNotificationsSettings();
    `});
    assert.equal(await page.locator('[name=case_whatsapp_enabled]').isChecked(), false, 'WhatsApp defaults off until templates are approved');
    assert.equal(await page.locator('[name=case_assigned_template]').inputValue(), 'scm_caso_asignado_v1');
    assert.equal(await page.locator('[name=case_external_template]').inputValue(), 'scm_caso_registrado_v2');
    await page.locator('[name=case_whatsapp_enabled]').check();
    await page.locator('[name=case_assigned_template]').fill('qa_asignado');
    await page.locator('[name=case_external_template]').fill('qa_externo');
    await page.locator('[name=case_template_language]').fill('es');
    await page.locator('[type=submit]').click();
    await page.waitForFunction(() => window.saved);
    const saved = await page.evaluate(() => window.saved);
    assert.equal(saved.nonce, 'test-nonce');
    assert.deepEqual(JSON.parse(saved.case_notifications), {enabled: true, assigned_template: 'qa_asignado', external_template: 'qa_externo', language: 'es'});
    await page.locator('[name=case_assigned_template]').fill('Invalid template');
    assert.equal(await page.locator('form').evaluate(form => form.checkValidity()), false, 'invalid template names fail native validation');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'settings fit mobile width');
    fs.mkdirSync(path.join(__dirname, '../output/panel-case'), {recursive: true});
    await page.screenshot({path: path.join(__dirname, '../output/panel-case/notification-settings.png'), fullPage: true});
    assert.deepEqual(errors, []);
    console.log('PASS: notification settings render, defaults, validation and save serialization');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
