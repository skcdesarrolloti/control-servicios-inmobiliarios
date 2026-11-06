const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Popup de alertas por cargo: sale después de vencimientos, no estorba si el funcionario ya fue a atender algo,
// y la pestaña Alertas de Permisos por cargo cuenta sus opciones como Pestañas y Acciones.
const root = path.resolve(__dirname, '..');
const runtime = fs.readFileSync(path.join(root, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/assets/css/tailwind-services.css'), 'utf8');
const slice = (text, from, to) => {
  const start = text.indexOf(from);
  assert.notEqual(start, -1, 'missing marker: ' + from);
  const end = text.indexOf(to, start);
  assert.notEqual(end, -1, 'missing marker: ' + to);
  return text.slice(start, end);
};
const ui = slice(runtime, '    var DUE_POPUP_UI = {', '    function dashboardDueEntryPopupHtml(');
const popup = slice(runtime, '    // Popup de alertas por cargo', '    function loadDashboardDuePopupRows() {');
const rows = n => Array.from({ length: n }, (_, i) => ({ date: '06/11/2026', title: 'Crédito #' + (i + 1) + ' · ROYNER PROPIETARIO', meta: '$500.000 a 10 meses · Libre inversión · Contrato #3000', badge: 'Hace 2 días', action: { type: i % 2 ? 'owner_credit_detail' : 'owner_credit_open', id: i + 1 }, button: i % 2 ? 'Ver crédito' : 'Ver solicitud' }));
const groups = [
  { key: 'credito_solicitudes', label: 'Solicitudes de crédito', hint: 'Sin aprobar ni rechazar', icon: 'request_quote', count: 20, rows: rows(20) },
  { key: 'credito_contabilidad', label: 'Créditos sin ingresar a contabilidad', hint: 'Con la respuesta ya enviada', icon: 'account_balance', count: 1, rows: rows(1) },
];

const harness = (groupsJson) => `
  const root = document.querySelector('#scm-app');
  const escHtml = v => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  var funcionarioBridgeMode = false, ajaxUrl = '/ajax', actionDashboardAlerts = 'scm_dashboard_alerts';
  var alertPopupConfig = { enabled: true }, dashboardAlertsShown = {}, dashboardDueActionTaken = false, dashboardDuePopupRestoreAfterCase = false;
  window.calls = [];
  window.log = [];
  window.config = alertPopupConfig;
  window.setRestore = v => { dashboardDuePopupRestoreAfterCase = v; };
  window.resetShown = () => { dashboardAlertsShown = {}; };
  const shouldAutoShowDashboardDuePopup = () => true;
  const dashboardAction = (action) => { window.calls.push(action); return Promise.resolve({ groups: ${groupsJson} }); };
  let closeCurrent = null;
  window.Swal = {
    isVisible: () => !!closeCurrent,
    getPopup: () => document.querySelector('.scm-due-entry-swal'),
    close: () => { const done = closeCurrent; closeCurrent = null; const el = document.querySelector('.scm-due-entry-swal'); if (el) el.remove(); if (done) done(); },
    fire: (o) => new Promise(resolve => {
      closeCurrent = resolve;
      window.log.push('alerts');
      root.insertAdjacentHTML('beforeend', '<div class="scm-due-entry-swal">' + o.html + '</div>');
      o.didOpen();
    }),
  };
  // Vencimientos simulado: abre su popup y resuelve al cerrarlo.
  window.dueOpen = false;
  function maybeShowDashboardDuePopup() {
    if (!window.dueOpen) return Promise.resolve();
    window.log.push('due');
    return new Promise(resolve => {
      closeCurrent = resolve;
      root.insertAdjacentHTML('beforeend', '<div class="scm-due-entry-swal"><button data-scm-dashboard-due-close>Cerrar</button><button data-scm-dashboard-due-open-calendar>Calendario</button></div>');
      document.querySelector('.scm-due-entry-swal').addEventListener('click', () => window.Swal.close());
    });
  }
  ${ui}
  ${popup}
  window.entry = maybeShowDashboardEntryPopups;
`;

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  const open = async (data) => {
    await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="scm-app"></div></body></html>');
    await page.addStyleTag({ content: css });
    await page.addScriptTag({ content: '(function(){' + harness(JSON.stringify(data)) + '})();' });
  };

  await open(groups);
  await page.evaluate(() => { window.entry('login'); });
  await page.waitForSelector('[data-scm-alert-group="credito_solicitudes"]');
  assert.equal(await page.locator('[data-scm-alert-group="credito_solicitudes"] [data-scm-alert-row]').count(), 18, 'the list is capped like the due popup');
  assert.match(await page.textContent('[data-scm-alert-group="credito_solicitudes"]'), /\+2 adicionales/);
  assert.equal(await page.getAttribute('[data-owner-credit-open]', 'data-credit-id'), '1');
  assert.equal(await page.getAttribute('[data-owner-credit-detail]', 'data-credit-id'), '2');
  assert.match(await page.textContent('.scm-due-entry-swal'), /21 tarea\(s\) por atender/);
  console.log('OK the alerts popup lists each group with its action buttons');
  if (process.env.SCM_ALERTS_SHOT) await page.screenshot({ path: process.env.SCM_ALERTS_SHOT });
  await page.click('[data-owner-credit-detail]');
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 0, 'opening a credit closes the popup so the credit modal is not covered');
  console.log('OK opening a credit from the alerts closes the popup');

  await page.evaluate(() => { window.resetShown(); window.log = []; window.dueOpen = true; window.entry('login'); });
  await page.waitForSelector('[data-scm-dashboard-due-close]');
  assert.deepEqual(await page.evaluate(() => window.log), ['due'], 'alerts wait for the due popup');
  await page.click('[data-scm-dashboard-due-close]');
  await page.waitForSelector('[data-scm-alert-group]');
  assert.deepEqual(await page.evaluate(() => window.log), ['due', 'alerts']);
  await page.click('[data-scm-dashboard-alert-close]');
  console.log('OK the alerts open after closing the due popup');

  await page.evaluate(() => { window.resetShown(); window.log = []; window.entry('login'); });
  await page.waitForSelector('[data-scm-dashboard-due-open-calendar]');
  await page.click('[data-scm-dashboard-due-open-calendar]');
  await page.waitForTimeout(100);
  assert.deepEqual(await page.evaluate(() => window.log), ['due'], 'going to work on something skips the alerts');
  console.log('OK the alerts do not interrupt someone who went to attend a due item');

  await page.evaluate(() => { window.resetShown(); window.log = []; window.dueOpen = false; window.config.enabled = false; window.entry('login'); });
  await page.waitForTimeout(50);
  assert.deepEqual(await page.evaluate(() => [window.log, window.calls.length]), [[], 2], 'a cargo without alerts does not query them');
  console.log('OK a cargo without alerts does not see or query them');

  await open([]);
  await page.evaluate(() => { window.entry('login'); });
  await page.waitForTimeout(50);
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 0, 'nothing pending shows nothing');
  console.log('OK nothing pending shows no popup');

  // Pestaña Alertas: mismo conteo y selector de cargo que Pestañas y Acciones.
  const paneHtml = `<form id="scm-permissions-form" data-scm-perm-form>
    <select id="scm-permissions-cargo"><option value="13" selected>13</option></select>
    <button type="button" data-scm-perm-pane="tabs">P <em data-scm-perm-pane-count="tabs"></em></button>
    <button type="button" data-scm-perm-pane="alerts">A <em data-scm-perm-pane-count="alerts"></em></button>
    <div data-scm-perm-pane-body="tabs"><section class="scm-permission-card" data-scm-perm-kind="tabs" data-permission-cargo="13"></section></div>
    <div data-scm-perm-pane-body="alerts" hidden><section class="scm-permission-card" data-scm-perm-kind="alerts" data-permission-cargo="13"><span data-scm-perm-card-count></span>
      <label class="scm-permissions-check"><input type="checkbox" name="admin_due_popup_cargo_ids[]" value="13" checked></label>
      <label class="scm-permissions-check"><input type="checkbox" name="alert_permissions[13][]" value="credito_solicitudes"></label>
      <label class="scm-permissions-check"><input type="checkbox" name="alert_permissions[13][]" value="credito_contabilidad"></label>
    </section></div></form>`;
  const modalsSource = fs.readFileSync(path.join(root, 'public/assets/js/admin-config-modals.js'), 'utf8');
  assert.match(modalsSource, /\["tabs", "actions", "alerts"\]/, 'the pane count includes alerts');
  await page.setContent('<!doctype html><html><body><div id="scm-app">' + paneHtml + '</div></body></html>');
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/admin-config-modals.js') });
  await page.waitForFunction(() => document.querySelector('form').dataset.scmPermEnhanced === '1');
  await page.waitForFunction(() => document.querySelector('[data-scm-perm-pane-count="alerts"]').textContent !== '');
  assert.equal(await page.textContent('[data-scm-perm-pane-count="alerts"]'), '1/3');
  await page.click('[data-scm-perm-pane="alerts"]');
  assert.equal(await page.locator('[name="alert_permissions[13][]"]').first().isVisible(), true, 'the alerts pane opens');
  console.log('OK the Alertas pane opens and counts its options');

  const phpSource = fs.readFileSync(path.join(root, 'src/App/Concerns/RendersDashboard.php'), 'utf8');
  assert.ok(!phpSource.includes('<b>Popup de vencimientos</b>'), 'the switch moved to the Alertas pane');
  assert.ok(phpSource.includes('data-scm-perm-pane-body="alerts"') && phpSource.includes('value="credito_contabilidad_pendiente"'), 'the Alertas pane and the calendar chips exist');
  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Dashboard alerts checks passed.');
})().catch((error) => { console.error('FAIL', error.message); process.exit(1); });
