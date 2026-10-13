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
    assert.equal(await page.locator('[name="email_actor_cc_enabled"]').count(),0,'automatic author CC control is removed');
    assert.equal(await copies.count(), 1, 'case creation event still configures internal copies');
    await copies.evaluate(select => select.add(new Option('Funcionario de prueba', '902')));
    await copies.selectOption('902');
    assert.equal(await page.locator('[data-receipt-automation], [name^=receipt_]').count(),0,'receipt automation is configured in Contracts');
    await page.locator('[type=submit]').click();
    await page.waitForFunction(() => window.saved);
    const saved = await page.evaluate(() => window.saved);
    assert.equal(saved.nonce, 'test-nonce');
    assert.equal(saved.case_notifications, undefined, 'saving recipients does not overwrite internal template settings');
    assert.deepEqual(JSON.parse(saved.settings).nuevo_caso_panel, ['902']);
    assert.equal(saved.receipt_automation,undefined,'saving internal recipients cannot overwrite receipt automation');
    assert.equal(saved.email_actor_cc_enabled, undefined, 'copies are defined only by event recipients');
    for (const width of [390,1440]) {
      await page.setViewportSize({width,height:844});
      const scrolls=await page.locator('#scm-internal-notifications-modal').evaluate(modal=>Array.from(modal.querySelectorAll('*')).filter(el=>el.tagName!=='SELECT' && ['auto','scroll'].includes(getComputedStyle(el).overflowY) && el.scrollHeight>el.clientHeight+2).map(el=>el.className));
      assert.deepEqual(scrolls,['scm-internal-notifications-body'],'only one content container scrolls at '+width+'px');
      const header=page.locator('.scm-pqr-settings-head'),footer=page.locator('.scm-internal-notif-actions');
      const before={header:await header.boundingBox(),footer:await footer.boundingBox()};
      await page.locator('.scm-internal-notifications-body').evaluate(body=>{body.scrollTop=body.scrollHeight;});
      assert.deepEqual(await header.boundingBox(),before.header,'header remains fixed');
      assert.deepEqual(await footer.boundingBox(),before.footer,'save action remains fixed');
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
      await page.locator('.scm-internal-notifications-body').evaluate(body=>{body.scrollTop=0;});
      await page.screenshot({path:path.join(__dirname,'../output/panel-case/notification-settings-'+width+'.png')});
    }
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'settings fit mobile width');
    fs.mkdirSync(path.join(__dirname, '../output/panel-case'), {recursive: true});
    await page.screenshot({path: path.join(__dirname, '../output/panel-case/notification-settings.png'), fullPage: true});
    assert.deepEqual(errors, []);
    console.log('PASS: template controls removed; configured event recipients serialize independently; notification modal has one scroll and no receipt automation');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
