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

// Pestaña Vencimientos: "En control" y el globo cuentan igual, y una revisión de servicios públicos no se cuenta dos veces.
{
  const dueCounts = slice(runtime, '    // En control: lo vencido', '    function updateDashboardDueNavBadge(');
  const { dashboardDueStats, dashboardDueTotal } = new Function('dashboardDateKey', dueCounts + '; return { dashboardDueStats, dashboardDueTotal };')(() => '2026-11-09');
  const dueRows = [
    { estado: 'Vencido', fecha_vencimiento: '2026-11-01' },
    { estado: 'Pendiente', fecha_vencimiento: '2026-11-09' },
    { estado: 'Pendiente', fecha_vencimiento: '2026-11-20' },
    { estado: 'Pendiente', fecha_vencimiento: '2026-11-09', cuenta_pendiente: 1 },
  ];
  assert.deepEqual(dashboardDueStats(dueRows), { total: 4, vencidos: 2, hoy: 1 }, 'pending public service reviews count as controlled, not as due today');
  assert.equal(dashboardDueTotal(dueRows), dashboardDueStats(dueRows).vencidos, 'the tab badge equals its En control card');
  assert.equal(dashboardDueTotal(dueRows.slice(0, 3), [{ count: 3, count_pending: true }]), 4, 'the popup keeps adding its summary groups');
}

const rows = n => Array.from({ length: n }, (_, i) => ({ date: '06/11/2026', title: 'Crédito #' + (i + 1) + ' · ROYNER PROPIETARIO', meta: '$500.000 a 10 meses · Libre inversión · Contrato #3000', badge: 'Hace 2 días', action: { type: i % 2 ? 'owner_credit_detail' : 'owner_credit_open', id: i + 1 }, button: i % 2 ? 'Ver crédito' : 'Ver solicitud' }));
const groups = [
  { key: 'credito_solicitudes', label: 'Solicitudes de crédito', hint: 'Sin aprobar ni rechazar', icon: 'request_quote', count: 20, rows: rows(20) },
  { key: 'credito_contabilidad', label: 'Créditos sin ingresar a contabilidad', hint: 'Con la respuesta ya enviada', icon: 'account_balance', count: 1, rows: rows(1) },
];

const harness = (groupsJson) => `
  const root = document.querySelector('#scm-app');
  const escHtml = v => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  var funcionarioBridgeMode = false, ajaxUrl = '/ajax', actionDashboardAlerts = 'scm_dashboard_alerts';
  var alertPopupConfig = { enabled: true }, duePopupConfig = { enabled: false }, dashboardAlertsShown = {}, dashboardDuePopupShown = {}, dashboardDueActionTaken = false, dashboardDuePopupRestoreAfterCase = false;
  var DASHBOARD_REMINDER_EVERY_MS = 3600000, DASHBOARD_REMINDER_STORAGE_KEY = 'scm-dashboard-reminder-at', dashboardReminderAt = 0;
  window.calls = [];
  window.log = [];
  window.config = alertPopupConfig;
  window.setRestore = v => { dashboardDuePopupRestoreAfterCase = v; };
  window.resetShown = () => { dashboardAlertsShown = {}; };
  let onHome = true;
  window.setOnHome = v => { onHome = v; };
  const shouldAutoShowDashboardDuePopup = source => source === 'hourly' || onHome;
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
  window.tick = dashboardReminderTick;
  window.setReminderAt = v => { dashboardReminderAt = v; };
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
  assert.equal(await page.locator('[data-owner-credit-go-tab]').count(), 19, 'every alert button goes to the credits tab');
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
  // Cerrar pide confirmación y recuerda las tareas; cancelar conserva el popup.
  await page.click('[data-scm-dashboard-alert-close]');
  assert.match(await page.textContent('[data-scm-popup-close-confirm]'), /¿Estás seguro de cerrar\?.*informativo.*tareas pendientes/);
  // Se ve centrada aunque la lista sea larga, sin tener que bajar en el popup.
  const confirmBox = await page.locator('[data-scm-popup-close-yes]').boundingBox();
  assert.ok(confirmBox.y > 100 && confirmBox.y + confirmBox.height < 800, 'the confirmation stays in the middle of the screen, got y=' + confirmBox.y);
  assert.equal(await page.evaluate(() => { const box = document.querySelector('[data-scm-popup-close-yes]').getBoundingClientRect(); return document.elementFromPoint(box.x + 2, box.y + 2).closest('[data-scm-popup-close-yes]') !== null; }), true, 'nothing covers the confirmation');
  if (process.env.SCM_CONFIRM_SHOT) await page.screenshot({ path: process.env.SCM_CONFIRM_SHOT });
  await page.click('[data-scm-popup-close-no]');
  assert.equal(await page.locator('[data-scm-popup-close-confirm]').count(), 0);
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 1, 'keeping it open leaves the popup');
  await page.click('[data-scm-dashboard-alert-close]');
  await page.click('[data-scm-popup-close-yes]');
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 0);
  console.log('OK the alerts open after closing the due popup and closing asks to confirm');

  // Cada hora vuelve a salir, sin importar si ya salió; antes de la hora no.
  await page.evaluate(() => { window.resetShown(); window.log = []; window.dueOpen = false; window.tick(); });
  await page.waitForTimeout(50);
  assert.deepEqual(await page.evaluate(() => window.log), [], 'it does not repeat before the hour');
  await page.evaluate(() => { window.setReminderAt(Date.now() - 3600001); window.tick(); });
  await page.waitForSelector('[data-scm-alert-group]');
  assert.deepEqual(await page.evaluate(() => window.log), ['alerts'], 'after an hour it shows again');
  await page.click('[data-scm-dashboard-alert-close]');
  await page.click('[data-scm-popup-close-yes]');
  // Si el funcionario está escribiendo, espera.
  await page.evaluate(() => { window.log = []; window.setReminderAt(Date.now() - 3600001); document.querySelector('#scm-app').insertAdjacentHTML('beforeend', '<input id="typing">'); document.getElementById('typing').focus(); window.tick(); });
  await page.waitForTimeout(50);
  assert.deepEqual(await page.evaluate(() => window.log), [], 'it does not interrupt someone typing');
  await page.evaluate(() => { document.getElementById('typing').remove(); });
  console.log('OK the reminder repeats every hour without interrupting');

  // Cargar una página fuera de Inicio no muestra popups y no debe reiniciar la hora.
  await page.evaluate(() => { window.resetShown(); window.log = []; window.setOnHome(false); window.setReminderAt(Date.now() - 3600001); window.entry('login'); });
  await page.waitForTimeout(50);
  assert.deepEqual(await page.evaluate(() => window.log), [], 'nothing shows outside Inicio');
  await page.evaluate(() => { window.tick(); });
  await page.waitForSelector('[data-scm-alert-group]');
  assert.deepEqual(await page.evaluate(() => window.log), ['alerts'], 'the hour was not restarted by loading another tab');
  await page.click('[data-scm-dashboard-alert-close]');
  await page.click('[data-scm-popup-close-yes]');
  await page.evaluate(() => { window.setOnHome(true); window.dueOpen = true; });
  console.log('OK loading another tab does not restart the hour');

  await page.evaluate(() => { window.resetShown(); window.log = []; window.entry('login'); });
  await page.waitForSelector('[data-scm-dashboard-due-open-calendar]');
  await page.click('[data-scm-dashboard-due-open-calendar]');
  await page.waitForTimeout(100);
  assert.deepEqual(await page.evaluate(() => window.log), ['due'], 'going to work on something skips the alerts');
  console.log('OK the alerts do not interrupt someone who went to attend a due item');

  await page.evaluate(() => { window.resetShown(); window.log = []; window.dueOpen = false; window.config.enabled = false; window.entry('login'); });
  await page.waitForTimeout(50);
  assert.deepEqual(await page.evaluate(() => window.log), [], 'a cargo without alerts sees nothing');
  const callsBefore = await page.evaluate(() => window.calls.length);
  await page.evaluate(() => { window.resetShown(); window.entry('login'); });
  await page.waitForTimeout(50);
  assert.equal(await page.evaluate(() => window.calls.length), callsBefore, 'a cargo without alerts does not query them');
  console.log('OK a cargo without alerts does not see or query them');

  await open([]);
  await page.evaluate(() => { window.entry('login'); });
  await page.waitForTimeout(50);
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 0, 'nothing pending shows nothing');
  console.log('OK nothing pending shows no popup');

  // Cada tema es su propio popup: salen en orden, cada uno con su título, y si va a atender algo el resto espera.
  const contracts = [
    { key: 'contratos_por_desocupar', label: 'Por desocupar este mes', hint: 'Con aviso de salida', icon: 'logout', count: 25, popup: 'Contratos por desocupar', popup_note: '26 contratos terminan en noviembre de 2026.', show_all: true, rows: rows(25).map(row => ({ date: '20/11/2026', title: row.title, meta: 'Inmueble 501', badge: 'Recibo de inmueble' })) },
    { key: 'contratos_sin_aviso', label: 'Terminan sin aviso de salida', hint: 'Pueden prorrogarse', icon: 'event_upcoming', count: 1, popup: 'Contratos por desocupar', popup_note: '26 contratos terminan en noviembre de 2026.', show_all: true, rows: rows(1).map(row => ({ date: '21/11/2026', title: row.title, meta: 'Inmueble 502', badge: 'Faltan 12 días' })) },
  ];
  const credits = groups.map(group => ({ ...group, popup: 'Créditos a propietarios' }));
  await open(credits.concat(contracts));
  await page.evaluate(() => { window.entry('login'); });
  await page.waitForSelector('[data-scm-alert-group="credito_solicitudes"]');
  assert.match(await page.textContent('.scm-due-entry-swal h3'), /Créditos a propietarios/);
  assert.equal(await page.locator('[data-scm-alert-group="contratos_por_desocupar"]').count(), 0, 'each theme stays in its own popup');
  await page.click('[data-scm-dashboard-alert-close]');
  await page.click('[data-scm-popup-close-yes]');
  await page.waitForSelector('[data-scm-alert-group="contratos_por_desocupar"]');
  assert.match(await page.textContent('.scm-due-entry-swal h3'), /Contratos por desocupar/);
  assert.match(await page.textContent('.scm-due-entry-swal header'), /26 contratos terminan en noviembre de 2026/);
  assert.equal(await page.locator('[data-scm-alert-group="contratos_por_desocupar"] [data-scm-alert-row]').count(), 25, 'cartera sees every contract of the month');
  assert.equal(await page.locator('[data-scm-alert-group="contratos_sin_aviso"]').count(), 1, 'groups of the same theme stay together');
  assert.deepEqual(await page.evaluate(() => window.log), ['alerts', 'alerts']);
  if (process.env.SCM_CONTRACTS_SHOT) await page.screenshot({ path: process.env.SCM_CONTRACTS_SHOT });
  await page.click('[data-scm-dashboard-alert-close]');
  await page.click('[data-scm-popup-close-yes]');
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 0);
  await page.evaluate(() => { window.resetShown(); window.log = []; window.entry('login'); });
  await page.waitForSelector('[data-owner-credit-detail]');
  await page.click('[data-owner-credit-detail]');
  await page.waitForTimeout(100);
  assert.deepEqual(await page.evaluate(() => window.log), ['alerts'], 'going to work on a credit leaves the next theme waiting');
  console.log('OK each theme opens its own popup, in order');

  // El botón del popup va a la vista por la misma opción del menú y deja esperando los siguientes temas.
  const withView = contracts.map(group => ({ ...group, popup_action: { label: 'Ver contratos por terminar', tab: 'metricas_por_terminar' } }));
  await open(withView.concat(credits.map(group => ({ ...group, popup: 'Otro tema' }))));
  await page.evaluate(() => {
    document.body.insertAdjacentHTML('beforeend', '<a class="nav-tab-dropdown-item" data-tab-key="metricas_por_terminar" href="#destino">Menu</a>');
    window.menuClicks = 0;
    document.querySelector('.nav-tab-dropdown-item').addEventListener('click', (event) => { event.preventDefault(); window.menuClicks++; });
    window.entry('login');
  });
  await page.waitForSelector('[data-scm-dashboard-alert-go="metricas_por_terminar"]');
  assert.match(await page.textContent('[data-scm-dashboard-alert-go]'), /Ver contratos por terminar/);
  await page.click('[data-scm-dashboard-alert-go]');
  await page.waitForTimeout(100);
  assert.equal(await page.locator('.scm-due-entry-swal').count(), 0, 'the popup closes without asking to confirm');
  assert.equal(await page.evaluate(() => window.menuClicks), 1, 'it opens the view through the menu option');
  assert.deepEqual(await page.evaluate(() => window.log), ['alerts'], 'the next theme waits while the view is open');
  await open(contracts);
  await page.evaluate(() => { window.entry('login'); });
  await page.waitForSelector('[data-scm-alert-group]');
  assert.equal(await page.locator('[data-scm-dashboard-alert-go]').count(), 0, 'without permission for the view there is no button');
  console.log('OK the popup button opens its view');

  // Popup de Vencimientos: las tarjetas siguen a lo que atiende el cargo y la lista, a los casos de la persona.
  const dueHtml = (dueRows, summary, allowed) => page.evaluate(({ uiSource, source, dueRows, summary, allowed }) => {
    const helpers = 'var escHtml = v => String(v); var dashboardDueStats = r => ({ total: r.length, vencidos: r.length, hoy: 0 }); var dashboardDueGroupControlled = g => g.vencidos || 0; var dashboardDueTypeLabel = t => "tipo:" + t + ";"; var dashboardDueCaseAttrsHtml = () => "";';
    return new Function('r', 's', 'a', helpers + uiSource + source + '; return dashboardDueEntryPopupHtml(r, s, a);')(dueRows, summary, allowed);
  }, { uiSource: ui, source: slice(runtime, '    function dashboardDueEntryPopupHtml(', '    function openDashboardDueCalendar('), dueRows, summary, allowed });
  const dueRow = (titulo, tipo_vencimiento, asignado) => ({ titulo, tipo_vencimiento, asignado, estado: 'Vencido', dias_vencido: 3, fecha_vencimiento: '2026-11-01', case: {} });
  let due = await dueHtml([dueRow('Mio', 'cotizacion_sin_enviar', true), dueRow('Ajeno', 'cotizacion_sin_enviar', false), dueRow('Orden', 'orden_mantenimiento_sin_respuesta', false)], [], ['cotizacion_sin_enviar', 'orden_mantenimiento_sin_respuesta', 'preventiva_sin_enviar']);
  assert.ok(due.includes('Mio') && !due.includes('Ajeno') && due.includes('Mostrando 1 de 1'), 'the list only has the cases assigned to the person');
  assert.ok(due.includes('tipo:preventiva_sin_enviar;'), 'a type the cargo attends with nothing pending stays in 0');
  assert.ok(!due.includes('tipo:cotizacion_enviada_sin_respuesta;') && !due.includes('tipo:contrato_anexos_sin_enviar;') && !due.includes('tipo:preventiva_pendiente;'), 'unchecked types are hidden, not shown in 0');
  due = await dueHtml([dueRow('Ajeno', 'cotizacion_sin_enviar', false)], [], ['cotizacion_sin_enviar']);
  assert.ok(!due.includes('Ajeno') && due.includes('No tienes casos asignados') && due.includes('tipo:cotizacion_sin_enviar;'), 'with nothing assigned the summary stays and the list says so');
  console.log('OK the due popup hides unchecked types and lists only assigned cases');

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

  // Lo que se guarda por cargo: cada campo por separado, con las listas vacías incluidas.
  const runtimeSource = fs.readFileSync(path.join(root, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
  const collector = runtimeSource.match(/function collectAlertCardValues\(field\) \{[\s\S]*?\n      \}/);
  assert.ok(collector, 'the alert card collector exists');
  assert.match(runtimeSource, /\["alert_permissions", "due_type_permissions", "due_reminder_types"\]/, 'the three alert fields are saved');
  const collected = await page.evaluate((source) => {
    const form = document.querySelector('form');
    const card = form.querySelector('[data-scm-perm-kind="alerts"]');
    card.insertAdjacentHTML('beforeend', '<input type="checkbox" name="due_type_permissions[13][]" value="cotizacion_sin_enviar" checked><input type="checkbox" name="due_type_permissions[13][]" value="preventiva_sin_enviar">');
    const collect = new Function('form', source + '; return collectAlertCardValues;')(form);
    return { types: collect('due_type_permissions'), reminder: collect('due_reminder_types') };
  }, collector[0]);
  assert.deepEqual(collected, { types: { 13: ['cotizacion_sin_enviar'] }, reminder: { 13: [] } });
  console.log('OK due types are collected per cargo, keeping empty lists');

  const phpSource = fs.readFileSync(path.join(root, 'src/App/Concerns/RendersDashboard.php'), 'utf8');
  assert.ok(!phpSource.includes('<b>Popup de vencimientos</b>'), 'the switch moved to the Alertas pane');
  assert.ok(phpSource.includes('data-scm-perm-pane-body="alerts"') && phpSource.includes('value="credito_contabilidad_pendiente"'), 'the Alertas pane and the calendar chips exist');
  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Dashboard alerts checks passed.');
})().catch((error) => { console.error('FAIL', error.message); process.exit(1); });
