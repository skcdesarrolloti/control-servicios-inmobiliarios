const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

// Métricas › Contratos por terminar y Aumentos: abren en el mes en curso, usan casos de recibo y cartas, y no tocan las otras vistas.
const root = path.resolve(__dirname, '..');
const now = new Date();
const year = now.getFullYear();
const month = now.getMonth() + 1;
const iso = (y, m, d) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
const [nextY, nextM] = month === 12 ? [year + 1, 1] : [year, month + 1];
const columns = ['id', 'contrato', 'estado', 'ingreso', 'egreso', 'fin', 'canon', 'tipo', 'destinacion', 'gestion', 'funcionario', 'direccion', 'arrendatario', 'inmueble', 'entrega', 'inicio', 'recibo', 'admin', 'meses', 'dias_gracia', 'dias_gracia_fuente', 'estado_simi', 'obs_simi', 'derechos', 'tasa_admin'];
const contract = (id, funcionario, canon) => [id, String(1000 + id), 'entregado', iso(year - 1, 1, 10), null, iso(year + 1, 1, 1), canon, 'Apartamento', 'Vivienda', '', funcionario, 'CL ' + id, 'Inquilino ' + id, String(500 + id), iso(year - 1, 1, 10), iso(year - 1, 1, 10), null, 100000, 12, null, '', '', '', null, null];
const receipt = (id, fin, abierto, estado) => ({ caso: String(9000 + id), id_contrato: String(id), contrato: String(1000 + id), inmueble: String(500 + id), direccion: 'CL ' + id, arrendatario: 'Inquilino ' + id, fin, abierto, estado });
const increase = (id, fecha, tipo, anterior, nuevo, extra = {}) => ({ fecha, vigencia: '', tipo_aumento: tipo, id_contrato: String(id), contrato: String(1000 + id), inmueble: String(500 + id), direccion: 'CL ' + id, arrendatario: 'Inquilino ' + id, anterior, nuevo, diferencia: nuevo - anterior, porcentaje: 5, calculado: false, retroactivo: '', ...extra });
const snapshot = {
  columns,
  rows: [contract(1, '7', 2000000), contract(2, '7', 1500000), contract(3, '8', 1000000), contract(4, '8', 900000)],
  receipts: [
    receipt(1, iso(year, month, 15), true, 'Por recibir'),
    receipt(2, iso(year, month, 16), false, 'Recibido'),
    receipt(3, iso(year, month, 20), true, 'Por recibir'),
    receipt(4, iso(nextY, nextM, 3), true, 'Por recibir'),
  ],
  increases: [
    increase(1, iso(year, month, 2), 'canon', 2000000, 2100000),
    increase(1, iso(year, month, 2), 'admin', 100000, 110000, { vigencia: iso(year, 1, 1), retroactivo: 'Retroactivo por $50.000' }),
    increase(2, iso(year, month, 4), 'canon', 1000000, 1070000, { calculado: true }),
    increase(3, iso(nextY, nextM, 2), 'canon', 1000000, 1050000),
  ],
  funcionarios: { 7: 'Ana Funcionaria', 8: 'Luis Funcionario' },
  simi_enabled: false,
  correctable: [],
  generated_at: Math.floor(Date.now() / 1000),
};
const shell = (view) => `<div id="scm-app"><div class="scm-tab-panel active"><section data-scm-contract-movements data-initial-view="${view}">
  <h1 data-scm-cm-title></h1><p data-scm-cm-subtitle></p><span data-scm-cm-updated></span>
  <button data-scm-cm-export></button><button data-scm-cm-refresh></button>
  <button data-scm-subview-target="ocupaciones"></button><button data-scm-subview-target="por_terminar"></button><button data-scm-subview-target="aumentos"></button>
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
  const column = (index) => page.$$eval('[data-scm-cm-detail] tbody tr', (trs, i) => trs.map((tr) => tr.children[i].textContent), index);
  const digits = (text) => text.replace(/\D/g, '');

  await open('por_terminar');
  assert.equal(await page.textContent('[data-scm-cm-title]'), 'Contratos por terminar');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), String(month), 'it opens on the current month');
  let values = await kpis();
  assert.equal(values['Contratos por terminar'], '3', 'only the receipt cases of the month count');
  assert.equal(values['Por recibir'], '2');
  assert.equal(values['Ya cerrados'], '1');
  assert.deepEqual(await column(2), ['1001', '1002', '1003'], 'the detail is ordered by the receipt date');
  assert.deepEqual(await column(1), ['9001', '9002', '9003'], 'each row shows its case');
  assert.match(await page.textContent('[data-scm-cm-detail] tbody tr:first-child'), /Ana Funcionaria/, 'contract data fills what the case does not have');
  console.log('OK Contratos por terminar lists the receipt cases of the month');

  await page.selectOption('[data-scm-cm-sub-filter]', 'cerrado');
  assert.deepEqual(await column(2), ['1002'], 'the status filter narrows the detail');
  assert.equal((await kpis())['Contratos por terminar'], '3', 'the filter does not change the summary');
  await page.selectOption('[data-scm-cm-filter="funcionario"]', '8');
  values = await kpis();
  assert.equal(values['Contratos por terminar'], '1', 'the shared filters apply to the receipt cases');
  await page.selectOption('[data-scm-cm-filter="funcionario"]', '');
  await page.click('[data-scm-cm-pick="month"][data-value="' + month + '"]');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), '', 'tapping the month again shows the whole year');
  if (nextY === year) {
    await page.click('[data-scm-cm-pick="month"][data-value="' + nextM + '"]');
    assert.deepEqual(await column(2), ['1004'], 'tapping a month filters the detail');
  }
  console.log('OK the filters and the monthly summary narrow the cases');

  await page.click('[data-scm-subview-target="aumentos"]');
  assert.equal(await page.textContent('[data-scm-cm-title]'), 'Aumentos');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), String(month), 'Aumentos opens on the current month');
  values = await kpis();
  assert.equal(values['Contratos con aumento'], '2', 'a contract with two letters counts once');
  assert.equal(values['Aumentos de canon'], '2');
  assert.equal(values['Aumentos de administración'], '1');
  assert.equal(digits(values['Canon adicional al mes']), '170000');
  assert.equal(digits(values['Administración adicional al mes']), '10000');
  const detail = await page.textContent('[data-scm-cm-detail]');
  assert.match(detail, /calculado/, 'a calculated previous value is flagged');
  assert.match(detail, /Retroactivo por \$50\.000/, 'the retroactive text is visible');
  assert.match(await page.textContent('[data-scm-cm-detail] tfoot'), /3 aumentos/);
  await page.selectOption('[data-scm-cm-sub-filter]', 'admin');
  assert.deepEqual(await column(2), ['Administración'], 'the type filter narrows the detail');
  await page.selectOption('[data-scm-cm-filter="month"]', '');
  await page.selectOption('[data-scm-cm-sub-filter]', '');
  assert.equal((await kpis())['Aumentos de canon'], nextY === year ? '3' : '2', 'without a month it shows the whole year');
  assert.ok(await page.locator('[data-scm-cm-pick="month"]').count() >= 1, 'the monthly summary is available');
  if (process.env.SCM_INCREASES_SHOT) await page.screenshot({ path: process.env.SCM_INCREASES_SHOT, fullPage: true });
  console.log('OK Aumentos shows the increases by month and year');

  await page.click('[data-scm-subview-target="ocupaciones"]');
  assert.equal(await page.textContent('[data-scm-cm-title]'), 'Ocupaciones');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), '', 'leaving the view releases the month');
  assert.equal(await page.locator('[data-scm-cm-sub-filter]').count(), 0);
  await page.click('[data-scm-subview-target="por_terminar"]');
  assert.equal(await page.inputValue('[data-scm-cm-filter="month"]'), String(month), 'coming back returns to the current month');
  console.log('OK switching views keeps each period');

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Contracts ending metric checks passed.');
})().catch((error) => { console.error('FAIL', error.message); process.exit(1); });
