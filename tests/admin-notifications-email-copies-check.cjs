const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const view = fs.readFileSync(path.join(root, 'src/App/Concerns/RendersDashboard.php'), 'utf8');
const runtime = fs.readFileSync(path.join(root, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const markupStart = view.indexOf('            <fieldset class="scm-admin-notif-email-copies"');
const markupEnd = view.indexOf('</fieldset>', markupStart) + '</fieldset>'.length;
assert(markupStart > 0 && markupEnd > markupStart);
const rendered = spawnSync('php', [], { cwd: root, encoding: 'utf8', input: `<?php
  require 'src/Core/Helpers.php';
  $creator = ['employee_id'=>'97004','name'=>'Creador del envío','email'=>'author@example.invalid','cargo'=>'Operaciones'];
  $emailCopyContacts = ['creator'=>$creator,'employees'=>[$creator,
    ['employee_id'=>'97005','name'=>'María Gómez','email'=>'commercial@example.invalid','cargo'=>'Comercial'],
    ['employee_id'=>'97007','name'=>'Luis Ríos','email'=>'operations@example.invalid','cargo'=>'Operaciones']]];
  $view = file_get_contents('src/App/Concerns/RendersDashboard.php');
  $start = strpos($view, '            <fieldset class="scm-admin-notif-email-copies"');
  $end = strpos($view, '</fieldset>', $start) + strlen('</fieldset>');
  eval('?>'.substr($view, $start, $end-$start));
` });
assert.equal(rendered.status, 0, rendered.stderr);
const markup = rendered.stdout;
const functionsStart = runtime.indexOf('      function filterEmailCopyEmployees()');
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
      var emailCopySearch = panel.querySelector('[data-admin-notif-copy-search]');
      var emailCopyCount = panel.querySelector('[data-admin-notif-copy-count]');
      var emailCopyEmpty = panel.querySelector('[data-admin-notif-copy-empty]');
      var emailCopyEmployees = panel.querySelectorAll('[data-admin-notif-copy-employee]');
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
    assert.equal(await page.locator('[data-admin-notif-copy-employee]').count(), 2);
    assert.equal(await page.locator('[data-admin-notif-copy-employee][value="97004"]').count(), 0);
    const search = page.locator('[data-admin-notif-copy-search]');
    await search.fill('maria');
    assert.equal(await page.locator('[data-admin-notif-copy-option]:visible').count(), 1);
    await page.locator('[data-admin-notif-copy-employee][value="97005"]').check();
    await search.fill('operaciones');
    assert.equal(await page.locator('[data-admin-notif-copy-option]:visible').count(), 1);
    assert.equal(await page.locator('[data-admin-notif-copy-count]').textContent(), '1');
    await page.locator('[data-admin-notif-copy-employee][value="97007"]').check();
    assert.deepEqual(await page.evaluate(() => new FormData(document.querySelector('form')).getAll('email_copy_employee_ids[]')), ['97005','97007']);
    await search.fill('nobody');
    assert.equal(await page.locator('[data-admin-notif-copy-empty]').isVisible(), true);
    await search.fill('commercial@example');
    assert.equal(await page.locator('[data-admin-notif-copy-option]:visible').count(), 1);
    await search.fill('');
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
    assert.deepEqual(await page.evaluate(() => new FormData(document.querySelector('form')).getAll('email_copy_employee_ids[]')), []);
    assert.equal(await page.locator('[data-admin-notif-email-copy-test-note]').isVisible(), true);
    await page.locator('[data-admin-notif-test-mode]').uncheck();
    await page.locator('[data-admin-notif-channel]').uncheck();
    assert.equal(await page.locator('[data-admin-notif-email-copies]').isVisible(), false);
    assert.equal((await payload()).email_copy_enabled, undefined);
    await page.locator('[data-admin-notif-channel]').check();
    await copies.uncheck();
    assert.equal((await payload()).additional_emails, undefined);
    assert.deepEqual(errors, []);
    console.log('PASS: creator CC, employee search by name/cargo/email, retained selections, test mode isolation and mobile layout');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
