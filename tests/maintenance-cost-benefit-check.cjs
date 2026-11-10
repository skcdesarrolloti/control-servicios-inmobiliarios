const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

// Métricas › Mantenimiento: periodos, relación beneficio/costo, filtro por tipo, detalle e ingresos por contratos.
const root = path.resolve(__dirname, '..');
const shellFor = (scope) => execFileSync('php', ['-r', `require '${root.replace(/\\/g, '/')}/vendor/autoload.php'; echo (new SCM\\Modules\\Metrics\\MetricsPanelsView())->renderCostBenefitShell('${scope}');`], { encoding: 'utf8' });
const shells = { mantenimiento: shellFor('mantenimiento'), entrega_recibo: shellFor('entrega_recibo') };
const currentMonth = shells.mantenimiento.match(/data-current-month="(\d{4}-\d{2})"/)[1];
const year = Number(currentMonth.slice(0, 4));
const month = Number(currentMonth.slice(5, 7));
const ym = (m) => `${year}-${String(m).padStart(2, '0')}`;
const day = (m, d) => `${ym(m)}-${String(d).padStart(2, '0')}`;
const previous = month > 1 ? month - 1 : month;
const expenseColumns = ['id', 'ticket', 'inmueble', 'categoria', 'funcionario', 'fecha', 'servicio', 'transporte', 'total', 'estado'];

const maintenance = {
  income_source: 'cotizaciones',
  income_columns: ['id', 'ticket', 'contrato', 'inmueble', 'tipo', 'fecha', 'total', 'admin'],
  income: [
    [1, '10', '1001', '501', 'Correctiva', day(month, 2), 1000000, 200000],
    [2, '11', '1002', '502', 'Preventiva', day(month, 3), 500000, 100000],
  ].concat(previous !== month ? [[3, '12', '1003', '503', 'Correctiva', day(previous, 5), 400000, 50000]] : []),
  expense_columns: expenseColumns,
  expense: [
    [7, '10', '501', 'Revision correctiva', 'Ana <img src=x onerror=alert(1)>', day(month, 2), 90000, 10000, 100000, 'pagado'],
    [8, '11', '502', 'Revision preventiva', 'Luis', day(month, 4), 80000, 20000, 100000, 'en_cobro'],
  ].concat(previous !== month ? [[9, '12', '503', 'Revision correctiva', 'Luis', day(previous, 6), 90000, 10000, 100000, 'sin_cobrar']] : []),
  undated: { income_count: 1, income_value: 30000, expense_count: 0, expense_value: 0 },
  expense_note: 'Gastos de Alvaro Florez De Oro en revisiones correctivas y preventivas.',
  excluded: { count: 4, value: 70000 },
  employees_not_found: [],
  generated_at: Math.floor(Date.now() / 1000),
};
const delivery = {
  income_source: 'contratos',
  income_columns: maintenance.income_columns,
  income: [],
  contract_columns: ['id', 'contrato', 'inmueble', 'ingreso', 'egreso', 'canon', 'tasa', 'derechos'],
  contracts: [
    [1, '1001', '501', `${year - 1}-06-10`, null, 2000000, 10, 5],
    [2, '1002', '502', day(month, 3), null, 1000000, 10, 50],
    [3, '1003', '503', `${year - 1}-01-10`, `${year - 1}-03-20`, 3000000, 10, 5],
    [4, '1004', '504', day(month, 4), null, 900000, 0, 0],
  ],
  contracts_without_rate: 1,
  expense_columns: expenseColumns,
  expense: [
    [30, '20', '501', 'Revision entrega', 'Libia Correa Rivera', day(month, 2), 150000, 0, 150000, 'pagado'],
    [31, '21', '502', 'Recibo', 'Libia Correa Rivera', day(month, 3), 50000, 0, 50000, 'sin_cobrar'],
  ],
  undated: { income_count: 1, income_value: 0, expense_count: 0, expense_value: 0 },
  expense_note: 'Gastos de Libia Correa Rivera.',
  excluded: { count: 0, value: 0 },
  employees_not_found: [],
  generated_at: Math.floor(Date.now() / 1000),
};

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/ajax', (route) => {
    const snapshot = route.request().postData().includes('entrega_recibo') ? delivery : maintenance;
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: snapshot }) });
  });
  await page.route('https://panel.test/', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
  const open = async (scope) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('https://panel.test/');
    await page.setContent('<!doctype html><html><body><div id="scm-app"><div class="scm-tab-panel active">' + shells[scope] + '</div></div></body></html>');
    await page.addStyleTag({ content: fs.readFileSync(path.join(root, 'public/assets/css/contract-movements.css'), 'utf8') });
    await page.evaluate(() => { window.SCMAdminCore = { parseRuntime: () => ({ actions: { maintenance_cost_benefit: 'scm_metricas_costo_beneficio' }, nonce: 'n', ajaxUrl: '/ajax' }) }; });
    await page.addScriptTag({ path: path.join(root, 'public/assets/js/admin-cost-benefit-metrics.js') });
    await page.waitForSelector('[data-scm-cb-content] .scm-cm-kpi');
  };
  const kpis = () => page.$$eval('[data-scm-cb-content] .scm-cm-kpi', (items) => Object.fromEntries(items.map((item) => [item.querySelector('span').textContent, item.querySelector('strong').textContent])));
  const digits = (text) => text.replace(/\D/g, '');

  await open('mantenimiento');
  let values = await kpis();
  assert.equal(digits(values.Ingresos), '300000', 'income is the administration of the month');
  assert.equal(digits(values.Gastos), '200000', 'expense is what employees billed in the month');
  assert.equal(digits(values['Beneficio neto']), '100000');
  assert.equal(values['Relación beneficio/costo'], '1,50');
  assert.equal(digits(values.Pagado), '100000');
  assert.equal(digits(values['En cuenta de cobro']), '100000');
  assert.match(await page.textContent('[data-scm-cb-content]'), /1 cotización aprobada sin fecha de respuesta/, 'undated rows are reported');
  assert.match(await page.textContent('[data-scm-cb-content]'), /Gastos de Alvaro Florez De Oro en revisiones correctivas y preventivas\. En todo el historial no se cuentan 4 reportes que no corresponden a esta vista/, 'it says what counts and what was left out');
  assert.equal(await page.isHidden('[data-scm-cb-export]'), true, 'a single month has no monthly export');

  await page.click('[data-scm-cb-mode="anual"]');
  values = await kpis();
  const yearIncome = previous !== month ? 350000 : 300000;
  const yearExpense = previous !== month ? 300000 : 200000;
  assert.equal(digits(values.Ingresos), String(yearIncome), 'the year adds every month');
  assert.equal(digits(values.Gastos), String(yearExpense));
  const yearRows = previous !== month ? 3 : 2;
  assert.equal(await page.locator('[data-scm-cb-detail] tbody tr').count(), yearRows, 'the income detail follows the period');
  await page.click('[data-scm-cb-view="gastos"]');
  assert.equal(await page.locator('[data-scm-cb-detail] tbody tr').count(), yearRows, 'the expense detail follows the period');
  await page.click('[data-scm-cb-view="ingresos"]');
  assert.equal(await page.isHidden('[data-scm-cb-export]'), false, 'the year exports its months');
  assert.equal(await page.locator('.scm-cc-col').count(), month, 'the chart shows only elapsed months');
  assert.equal(await page.locator('.scm-cc-bar i').count(), 1, 'only the tallest bar carries a value');

  await page.click(`.scm-cc-col[data-scm-cb-month="${currentMonth}"]`);
  assert.equal(digits((await kpis()).Ingresos), '300000', 'touching a month opens that month');

  assert.equal(await page.isHidden('[data-scm-cb-filter="tipo"]'), true, 'the tabs replace the type filter in maintenance');
  assert.match(await page.textContent('[data-scm-cb-detail] thead'), /Tipo/);
  assert.doesNotMatch(await page.textContent('[data-scm-cb-detail] thead'), /Categoría/, 'the income shows the maintenance type, not the quote category');
  await page.click('[data-scm-cb-kind="correctiva"]');
  values = await kpis();
  assert.equal(digits(values.Ingresos), '200000', 'corrective tab keeps only corrective quotes');
  assert.equal(digits(values.Gastos), '100000', 'corrective tab keeps only corrective reports');
  assert.equal(values['Relación beneficio/costo'], '2,00');
  assert.match(await page.textContent('[data-scm-cb-content]'), /Resultado de .* · correctivas/);
  await page.click('[data-scm-cb-kind="preventiva"]');
  values = await kpis();
  assert.equal(digits(values.Ingresos), '100000', 'preventive tab keeps only preventive quotes');
  assert.equal(digits(values.Gastos), '100000', 'preventive tab keeps only preventive reports');
  assert.equal(await page.locator('[data-scm-cb-detail] tbody tr').count(), 1, 'the detail follows the tab');
  await page.click('[data-scm-cb-kind="general"]');
  values = await kpis();
  assert.equal(digits(values.Ingresos), '300000', 'general adds both types');
  assert.equal(digits(values.Gastos), '200000');

  assert.equal(await page.locator('[data-scm-cb-detail] [data-scm-maintenance-quote-open-case]').count(), 2, 'income rows open the case');
  await page.click('[data-scm-cb-view="gastos"]');
  assert.equal(await page.locator('[data-scm-cb-detail] tbody tr').count(), 2, 'the expense detail lists the reports');
  assert.equal(await page.locator('[data-scm-cb-content] img').count(), 0, 'employee names are escaped');
  await page.fill('[data-scm-cb-search]', 'luis');
  await page.waitForFunction(() => document.querySelectorAll('[data-scm-cb-detail] tbody tr').length === 1);

  await page.setViewportSize({ width: 390, height: 800 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert.ok(overflow <= 0, 'no horizontal page scroll on a phone: ' + overflow);

  await open('entrega_recibo');
  values = await kpis();
  assert.equal(digits(values.Ingresos), String(200000 + 100000 + 500000), 'one month of commission on both contracts plus the rights of the contract delivered this month');
  assert.equal(digits(values.Gastos), '200000');
  await page.selectOption('[data-scm-cb-filter="tipo"]', 'Recibo');
  values = await kpis();
  assert.equal(digits(values.Gastos), '50000', 'the type filter narrows the expense');
  assert.equal(digits(values.Ingresos), String(200000 + 100000 + 500000), 'the type filter does not change the income');
  await page.selectOption('[data-scm-cb-filter="tipo"]', '');
  assert.equal(await page.locator('[data-scm-cb-kind]').count(), 0, 'delivery has no maintenance tabs');
  assert.match(await page.textContent('[data-scm-cb-content]'), /Gastos de Libia Correa Rivera\./);
  assert.match(await page.textContent('[data-scm-cb-content]'), /1 contrato no tiene % de administración ni de derechos/);
  assert.match(await page.textContent('[data-scm-cb-content]'), /1 contrato sin fecha de entrega/);
  assert.equal(await page.locator('[data-scm-cb-detail] tbody tr').count(), 2, 'contracts without income are not listed; the closed one is out of the period');
  assert.match(await page.textContent('[data-scm-cb-detail] thead'), /Comisión.*Derechos/);
  assert.match(await page.textContent('[data-scm-cb-content]'), /Detalle de .*\(mes actual\)/, 'the detail names its period');
  assert.match(await page.textContent('[data-scm-cb-detail]'), /Lista los contratos vigentes en el periodo/, 'it explains why older contracts appear');

  await page.click('[data-scm-cb-mode="anual"]');
  values = await kpis();
  const commission = month * 200000 + 100000;
  assert.equal(digits(values.Ingresos), String(commission + 500000), 'the year counts each elapsed month of commission once');
  assert.match(await page.textContent('.scm-qm-monthly thead'), /Contratos/);
  if (process.env.SCM_SCREENSHOT) await page.screenshot({ path: process.env.SCM_SCREENSHOT, fullPage: true });

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Maintenance cost-benefit checks passed.');
})().catch((error) => { console.error(error); process.exit(1); });
