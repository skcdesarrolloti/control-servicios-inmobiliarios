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
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/admin/01-core.css'), 'utf8')});
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/tailwind-admin.css'), 'utf8')});
    const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
    const start = source.indexOf('    function bindInternalNotificationsSettings()');
    const end = source.indexOf('    bindInternalNotificationsSettings();', start);
    assert(start >= 0 && end > start);
    await page.addScriptTag({content: `
      var root = document.querySelector('#scm-app'), ajaxUrl = '/api.php', nonce = 'test-nonce', actionInternalNotificationsSave = 'save';
      function showToast() {}
      window.fetch = async (url, options) => { window.saved = Object.fromEntries(options.body); return {json: async () => ({success: true, data: {message: 'Guardado'}})}; };
      ${source.slice(start, end)}
      bindInternalNotificationsSettings();
    `});
    await page.locator('#scm-open-internal-notifications').click();
    assert.equal(await page.locator('[data-case-notifications], [name^=case_]').count(), 0, 'template configuration is not exposed in the administrative modal');
    const copies = page.locator('[name="settings[nuevo_caso_panel][]"]');
    assert.equal(await copies.count(), 1, 'case creation event still configures internal copies');
    await copies.evaluate(select => select.add(new Option('Funcionario de prueba', '902')));
    await copies.selectOption('902');
    await page.locator('[name=receipt_contract_id]').fill('525');
    await page.locator('[name=receipt_employee_id]').selectOption({index: 0});
    await page.locator('[type=submit]').click();
    await page.waitForFunction(() => window.saved);
    const saved = await page.evaluate(() => window.saved);
    assert.equal(saved.nonce, 'test-nonce');
    assert.equal(saved.case_notifications, undefined, 'saving recipients does not overwrite internal template settings');
    assert.deepEqual(JSON.parse(saved.settings).nuevo_caso_panel, ['902']);
    assert(saved.receipt_automation, 'existing receipt automation settings remain supported');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'settings fit mobile width');
    fs.mkdirSync(path.join(__dirname, '../output/panel-case'), {recursive: true});
    await page.screenshot({path: path.join(__dirname, '../output/panel-case/notification-settings.png'), fullPage: true});
    assert.deepEqual(errors, []);
    console.log('PASS: template controls removed; configured event recipients and receipt automation still serialize without changing internal templates');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
