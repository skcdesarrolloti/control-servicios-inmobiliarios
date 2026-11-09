const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

// Métricas › Contratos por terminar: abre en el mes en curso, separa la salida real y no toca las otras vistas.
const root = path.resolve(__dirname, '..');
const now = new Date();
const year = now.getFullYear();
const month = now.getMonth() + 1;
const iso = (y, m, d) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
const columns = ['id', 'contrato', 'estado', 'ingreso', 'egreso', 'fin', 'canon', 'tipo', 'destinacion', 'gestion', 'funcionario', 'direccion', 'arrendatario', 'inmueble', 'entrega', 'inicio', 'recibo', 'admin', 'meses', 'dias_gracia', 'dias_gracia_fuente', 'estado_simi', 'obs_simi', 'derechos', 'tasa_admin', 'situacion', 'situacion_detalle'];
const row = (id, estado, fin, situacion, detalle, canon = 1000000) => [id, String(1000 + id), estado, iso(year - 1, 1, 10), estado === 'recibido' ? iso(year, 1, 5) : null, fin, canon, 'Apartamento', 'Vivienda', '', '7', 'CL ' + id, 'Inquilino ' + id, String(500 + id), iso(year - 1, 1, 10), iso(year - 1, 1, 10), null, 0, 12, null, '', '', '', null, null, situacion, detalle];
const nextMonth = month === 12 ? [year + 1, 1] : [year, month + 1];
const snapshot = {
  columns,
  rows: [
    row(1, 'entregado', iso(year, month, 15), 'salida', 'Recibo de inmueble', 2000000),
    row(2, 'por_recibir', iso(year, month, 16), 'salida', 'Por recibir', 1500000),
    row(3, 'entregado', iso(year, month, 20), 'sin_aviso', 'Sin aviso de salida'),
    row(4, 'entregado', iso(year, month, 21), 'continua', 'Renueva'),
    row(5, 'entregado', iso(nextMonth[0], nextMonth[1], 3), 'sin_aviso', 'Sin aviso de salida'),
    row(6, 'recibido', iso(year, month, 18), '', ''),
  ],
  funcionarios: { 7: 'Ana Funcionaria' },
  simi_enabled: false,
  correctable: [],
  generated_at: Math.floor(Date.now() / 1000),
};
const shell = (view) => `<div id="scm-app"><div class="scm-tab-panel active"><section data-scm-contract-movements data-initial-view="${view}">
  <h1 data-scm-cm-title></h1><p data-scm-cm-subtitle></p><span data-scm-cm-updated></span>
  <button data-scm-cm-export></button><button data-scm-cm-refresh></button>
  <button data-scm-subview-target="ocupaciones"></button><button data-scm-subview-target="por_terminar"></button>
  <div data-scm-cm-kpis></div>
  <select data-scm-cm-filter="year"><option value="">Todos</option></select>
  <select data-scm-cm-filter="month"><option value="">Todos</option>${Array.from({ length: 12 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join('')}</select>
  <select data-scm-cm-filter="funcionario"><option value="">Todos</option></select><select data-scm-cm-filter="tipo"><option value="">Todos</option></select><select data-scm-cm-filter="destinacion"><option value="">Todas</option></select><select data-scm-cm-filter="estado"><option value="">Todos</option></select>
  <input data-scm-cm-filter="arrendatario" data-scm-cm-text><input data-scm-cm-filter="inmueble" data-scm-cm-text><input data-scm-cm-filter="contrato" data-scm-cm-text>
  <button data-scm-cm-clear></button><div data-scm-cm-status></div><div data-scm-cm-content></div></section></div></div>`;

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/ajax', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: snapshot }) }));
  await page.route('https://panel.test/', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
  const open = async (view) => {
    await page.goto('https://panel.test/');
    await page.evaluate(() => window.sessionStorage.clear());
    await page.setContent('<!doctype html><html><body>' + shell(view) + '</body></html>');
    await page.evaluate(() => { window.SCMAdminCore = { parseRuntime: () => ({ actions: { contract_movements: 'scm_contract_movements' }, nonce: 'n', ajaxUrl: '/ajax' }) }; });
    await page.addScriptTag({ path: path.join(root, 'public/assets/js/admin-contract-movements.js') });
    await page.waitForFunction(() => document.querySelector('[data-scm-cm-kpis]').textContent !== '');
  };
  const kpis = () => page.$$eval('[data-scm-cm-kpis] .scm-cm-kpi', (items) => Object.fromEntries(items.map((item) => [item.querySelector('span').textContent, item.querySelector('strong').textContent])));
  const contracts = () => page.$$eval('[data-scm-cm-detail] tbody tr', (trs) => trs.map((tr) => tr.children[1].textContent));

  await open('por_terminar');
  assert.equal(await page.textContent('[data-scm-cm-title]'), 'Contratos por terminar');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), String(month), 'it opens on the current month');
  let values = await kpis();
  assert.equal(values['Por terminar'], '4', 'a received contract is not pending to end');
  assert.equal(values['Con aviso de salida'], '2');
  assert.equal(values['Sin aviso de salida'], '1');
  assert.equal(values['Renuevan o no salen'], '1');
  assert.deepEqual(await contracts(), ['1001', '1002', '1003', '1004'], 'the detail is ordered by end date');
  console.log('OK it opens on the current month and classifies each contract');

  await page.selectOption('[data-scm-cm-exit-filter]', 'salida');
  assert.deepEqual(await contracts(), ['1001', '1002'], 'the situation filter narrows the detail');
  assert.equal((await kpis())['Por terminar'], '4', 'the filter does not change the summary');
  assert.equal(await page.inputValue('[data-scm-cm-exit-filter]'), 'salida');
  assert.match(await page.textContent('[data-scm-cm-detail] tfoot'), /2 contratos/);
  console.log('OK the situation filter narrows the detail');

  await page.click('[data-scm-cm-pick="month"][data-value="' + month + '"]');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), '', 'tapping the month again shows the whole year');
  if (nextMonth[0] === year) {
    await page.click('[data-scm-cm-pick="month"][data-value="' + nextMonth[1] + '"]');
    assert.deepEqual(await contracts(), ['1005'], 'tapping a month filters the detail');
  }
  console.log('OK the monthly summary filters the detail');

  assert.ok(await page.$eval('[data-scm-cm-filter="year"]', (select, future) => Array.from(select.options).some((option) => option.value === String(future)), year), 'the end-date years are offered');
  await page.click('[data-scm-subview-target="ocupaciones"]');
  assert.equal(await page.textContent('[data-scm-cm-title]'), 'Ocupaciones');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), '', 'leaving the view releases the month');
  assert.equal(await page.locator('[data-scm-cm-exit-filter]').count(), 0);
  await page.click('[data-scm-subview-target="por_terminar"]');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), String(month), 'coming back returns to the current month');
  console.log('OK switching views keeps each period');

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Contracts ending metric checks passed.');
})().catch((error) => { console.error('FAIL', error.message); process.exit(1); });
