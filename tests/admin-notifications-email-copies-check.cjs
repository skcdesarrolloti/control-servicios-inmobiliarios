const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.join(__dirname, '..');
const view = fs.readFileSync(path.join(root, 'src/App/Concerns/RendersDashboard.php'), 'utf8');
const runtime = fs.readFileSync(path.join(root, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const markupStart = view.indexOf('            <fieldset class="scm-admin-notif-email-copies"');
const markupEnd = view.indexOf('</fieldset>', markupStart) + '</fieldset>'.length;
assert(markupStart > 0 && markupEnd > markupStart);
const markup = view.slice(markupStart, markupEnd).replace(/<\?php[\s\S]*?\?>/g, 'admin@example.invalid');
const functionsStart = runtime.indexOf('      function updateEmailCopyFields()');
const functionsEnd = runtime.indexOf('      function renderTestModeRealContacts()', functionsStart);
const eventsStart = runtime.indexOf('        if (testModeInput) {', functionsEnd);
const eventsEnd = runtime.indexOf('        [testEmailInput, testPhoneInput]', eventsStart);
assert(functionsStart > 0 && functionsEnd > functionsStart && eventsEnd > eventsStart);

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    const page = await browser.newPage({ viewport: { width: 960, height: 650 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(`<main id="scm-app"><h2>Enviar notificación</h2><form><label><input type="checkbox" value="email" data-admin-notif-channel checked>Email</label><label><input type="checkbox" data-admin-notif-test-mode>Modo prueba</label><div data-admin-notif-test-fields hidden></div>${markup}</form></main>`);
    await page.addStyleTag({ content: fs.readFileSync(path.join(root, 'public/assets/css/admin/04-dashboard-pending.css'), 'utf8') });
    await page.addStyleTag({ content: 'body{font-family:Arial,sans-serif;margin:0;padding:16px}main{max-width:760px;margin:auto}form{display:grid;gap:20px}textarea{box-sizing:border-box;width:100%;padding:10px}p{margin:0;line-height:1.6}.scm-field{display:grid;gap:8px}' });
    await page.addScriptTag({ content: `
      var panel = document.querySelector('#scm-app');
      var testModeInput = panel.querySelector('[data-admin-notif-test-mode]');
      var testFields = panel.querySelector('[data-admin-notif-test-fields]');
      var emailCopiesWrap = panel.querySelector('[data-admin-notif-email-copies]');
      var emailCopyEnabledInput = panel.querySelector('[data-admin-notif-email-copy-enabled]');
      var emailCopyFields = panel.querySelector('[data-admin-notif-email-copy-fields]');
      var additionalEmailsInput = panel.querySelector('[data-admin-notif-additional-emails]');
      var emailCopyTestNote = panel.querySelector('[data-admin-notif-email-copy-test-note]');
      function markComposerDirty(){} function renderTestModeRealContacts(){}
      ${runtime.slice(functionsStart, functionsEnd)}
      ${runtime.slice(eventsStart, eventsEnd)}
      panel.querySelector('[data-admin-notif-channel]').addEventListener('change', updateEmailCopyFields);
      updateEmailCopyFields();
    ` });
    const copies = page.locator('[data-admin-notif-email-copy-enabled]');
    const fields = page.locator('[data-admin-notif-email-copy-fields]');
    const extra = page.locator('[data-admin-notif-additional-emails]');
    const payload = () => page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form'))));
    assert.equal(await copies.isChecked(), false);
    assert.equal(await fields.isVisible(), false);
    assert.equal((await payload()).additional_emails, undefined);
    await copies.check();
    assert.equal(await fields.isVisible(), true);
    await extra.fill('external@example.invalid; other@example.invalid');
    assert.equal((await payload()).email_copy_enabled, '1');
    assert.equal((await payload()).additional_emails, 'external@example.invalid; other@example.invalid');
    const output = path.join(root, 'outputs');
    fs.mkdirSync(output, { recursive: true });
    await page.screenshot({ path: path.join(output, 'admin-notifications-email-copies-desktop.png') });
    await page.setViewportSize({ width: 390, height: 740 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    await page.screenshot({ path: path.join(output, 'admin-notifications-email-copies-mobile.png') });
    await page.locator('[data-admin-notif-test-mode]').check();
    assert.equal(await copies.isDisabled(), true);
    assert.equal(await extra.isDisabled(), true);
    assert.equal(await fields.isVisible(), false);
    assert.equal((await payload()).email_copy_enabled, undefined);
    assert.equal((await payload()).additional_emails, undefined);
    assert.equal(await page.locator('[data-admin-notif-email-copy-test-note]').isVisible(), true);
    await page.locator('[data-admin-notif-test-mode]').uncheck();
    await page.locator('[data-admin-notif-channel]').uncheck();
    assert.equal(await page.locator('[data-admin-notif-email-copies]').isVisible(), false);
    assert.equal((await payload()).email_copy_enabled, undefined);
    await page.locator('[data-admin-notif-channel]').check();
    await copies.uncheck();
    assert.equal((await payload()).additional_emails, undefined);
    assert.deepEqual(errors, []);
    console.log('PASS: optional CC, form payload, test mode isolation, email channel visibility and mobile layout');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
