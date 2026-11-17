const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Mis actividades (menú del usuario): popup propio con las tareas asignadas por urgencia y, aparte, lo del cargo sin
// responsable. Se arma con el código real del runtime; SweetAlert se simula con lo mínimo que usa.
const root = path.resolve(__dirname, '..');
const runtime = fs.readFileSync(path.join(root, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/assets/css/tailwind-services.css'), 'utf8');
const slice = (from, to) => {
  const start = runtime.indexOf(from);
  assert.notEqual(start, -1, 'missing marker: ' + from);
  const end = runtime.indexOf(to, start);
  assert.notEqual(end, -1, 'missing marker: ' + to);
  return runtime.slice(start, end);
};
const code = slice('    var DUE_POPUP_UI = {', '    function dashboardDueEntryPopupHtml(')
  + slice('    // Botones de los popups de vencimientos', '    function maybeShowDashboardDuePopup(')
  + slice('    // --- Mis actividades (menú del usuario)', '    window.scmMyViews = window.scmMyViews');

const rows = [
  { titulo: 'Caso #10 · Cotización sin enviar', tipo_vencimiento: 'cotizacion_sin_enviar', estado: 'Vencido', dias_vencido: 4, fecha_vencimiento: '2026-11-13', asignado: true, case: { ticket_pk: '10', case_source_html: '<p>Caso 10</p>' } },
  { titulo: 'Caso #11 · Preventiva sin cita', tipo_vencimiento: 'ticket_preventiva_sin_cita', estado: 'Pendiente', dias_vencido: 0, fecha_vencimiento: '2026-11-17', asignado: true, case: {} },
  { titulo: 'Caso #12 · Orden sin respuesta', tipo_vencimiento: 'orden_mantenimiento_sin_respuesta', estado: 'Pendiente', dias_vencido: 0, fecha_vencimiento: '2026-11-25', asignado: true, case: {} },
  { titulo: 'Caso #13 · De otra persona', tipo_vencimiento: 'cotizacion_sin_enviar', estado: 'Vencido', dias_vencido: 9, fecha_vencimiento: '2026-11-08', asignado: false, sin_responsable: false, case: {} },
  { titulo: 'Crédito #5 sin decidir · <b>Ana</b>', tipo_vencimiento: 'credito_solicitud_pendiente', estado: 'Vencido', dias_vencido: 1, fecha_vencimiento: '2026-11-16', asignado: false, sin_responsable: true, case: { owner_credit_id: '5', owner_credit_view: 'open' } },
];

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setContent('<!doctype html><html><head></head><body><div id="scm-app"></div></body></html>');
  await page.addStyleTag({ content: css });
  await page.evaluate(({ code, rows }) => {
    const root = document.querySelector('#scm-app');
    let current = null;
    window.Swal = {
      fire(opts) {
        if (current) current.remove();
        current = document.createElement('div');
        current.className = 'swal2-popup';
        current.innerHTML = opts.html;
        root.appendChild(current);
        if (opts.didOpen) opts.didOpen();
        return Promise.resolve({});
      },
      getPopup: () => current,
      isVisible: () => !!current,
      close() { if (current) current.remove(); current = null; },
    };
    window.opened = [];
    const escHtml = (v) => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const api = new Function('root', 'escHtml', 'rows', 'opened', `
      var funcionarioBridgeMode = false, ajaxUrl = '/ajax', actionAdminDueCalendar = 'due', dashboardDueActionTaken = false;
      function dashboardDateKey() { return '2026-11-17'; }
      function dashboardDueTypeLabel(type) { return 'Tipo ' + type; }
      function dashboardDueCaseAttrsHtml(c) { return ' data-ticket-pk="' + escHtml(c.ticket_pk || '') + '"'; }
      function loadDashboardDuePopupRows() { return Promise.resolve({ rows: rows }); }
      function dashboardOpenDueCase(button) { opened.push('case:' + button.getAttribute('data-ticket-pk')); }
      function openAdminTicketModal() { opened.push('ticket'); }
      function openPublicServicesReviewModal() { opened.push('services'); }
      function openAdministrativeActivityTab(key) { opened.push('tab:' + key); }
      function openDashboardDueCalendar() { opened.push('calendar'); }
      function showToast(type, message) { opened.push('toast:' + message); }
      ${code}
      return { show: showMyActivitiesPopup, restore: function () { return myActivitiesRestore; } };
    `)(root, escHtml, rows, window.opened);
    window.myActivities = api;
  }, { code, rows });

  await page.evaluate(() => window.myActivities.show());
  await page.waitForSelector('.scm-my-activities-swal, .swal2-popup [data-my-activities-filter]');
  const text = await page.textContent('.swal2-popup');
  assert.match(text, /Mis actividades/);
  assert.ok(!text.includes('De otra persona'), 'no lista tareas asignadas a otra persona');
  assert.equal(await page.locator('[data-my-activities-section]').count(), 3, 'vencidas, hoy y próximas');
  assert.deepEqual(await page.$$eval('[data-my-activities-section]', (els) => els.map((el) => el.getAttribute('data-my-activities-section'))), ['vencidas', 'hoy', 'proximas'], 'de la más urgente a la más próxima');
  assert.match(await page.textContent('[data-my-activities-section="vencidas"]'), /4 días de atraso/);
  assert.match(await page.textContent('[data-my-activities-section="hoy"]'), /Vence 17\/11\/2026/);
  assert.equal(await page.locator('[data-my-activities-unowned] [data-scm-due-row]').count(), 1, 'lo del cargo sin responsable va aparte');
  assert.ok((await page.innerHTML('[data-my-activities-unowned]')).includes('&lt;b&gt;Ana'), 'el título se escapa');
  assert.equal(await page.locator('[data-my-activities-unowned] [data-owner-credit-open][data-credit-id="5"]').count(), 1, 'el crédito tiene su botón');
  console.log('OK Mis actividades: solo lo propio, por urgencia, y lo del cargo sin responsable aparte');

  await page.click('[data-my-activities-filter="hoy"]');
  assert.equal(await page.locator('[data-my-activities-section]:visible').count(), 1, 'el filtro deja solo Hoy');
  assert.equal(await page.getAttribute('[data-my-activities-filter="hoy"]', 'aria-pressed'), 'true');
  await page.click('[data-my-activities-filter="todas"]');
  assert.equal(await page.locator('[data-my-activities-section]:visible').count(), 3);
  console.log('OK Mis actividades: filtros por urgencia');

  await page.click('[data-my-activities-section="vencidas"] [data-scm-dashboard-due-open-case]');
  assert.deepEqual(await page.evaluate(() => window.opened), ['case:10'], 'abre el caso con el mismo flujo del calendario');
  assert.equal(await page.evaluate(() => window.myActivities.restore()), true, 'al cerrar el caso vuelve a Mis actividades');
  await page.click('[data-my-activities-section="hoy"] [data-scm-dashboard-due-open-admin-tab]');
  assert.equal(await page.evaluate(() => window.opened[1]), 'tab:ticket_preventiva_sin_cita');
  await page.evaluate(() => window.myActivities.show());
  await page.waitForSelector('[data-my-activities-filter]');
  await page.click('footer [data-scm-dashboard-due-close]');
  assert.equal(await page.locator('.swal2-popup').count(), 0, 'Cerrar cierra sin pedir confirmación');
  console.log('OK Mis actividades: abrir caso, pestaña y cerrar');

  await page.evaluate(() => { window.myActivities = null; });
  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Mis actividades: verificación completa.');
})().catch((error) => { console.error(error); process.exit(1); });
