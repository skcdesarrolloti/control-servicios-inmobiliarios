const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

// Métricas › Mantenimiento › Costo-beneficio: periodos, relación beneficio/costo, filtro por tipo y detalle.
const root = path.resolve(__dirname, '..');
const shell = execFileSync('php', ['-r', `require '${root.replace(/\\/g, '/')}/vendor/autoload.php'; echo (new SCM\\Modules\\Metrics\\MetricsPanelsView())->renderCostBenefitShell();`], { encoding: 'utf8' });
const currentMonth = shell.match(/data-current-month="(\d{4}-\d{2})"/)[1];
const year = Number(currentMonth.slice(0, 4));
const month = Number(currentMonth.slice(5, 7));
const ym = (m) => `${year}-${String(m).padStart(2, '0')}`;
const day = (m, d) => `${ym(m)}-${String(d).padStart(2, '0')}`;
const previous = month > 1 ? month - 1 : month;

const snapshot = {
  income_columns: ['id', 'ticket', 'contrato', 'inmueble', 'categoria', 'fecha', 'total', 'admin'],
  income: [
    [1, '10', '1001', '501', 'Inicial', day(month, 2), 1000000, 200000],
    [2, '11', '1002', '502', 'Inicial', day(month, 3), 500000, 100000],
  ].concat(previous !== month ? [[3, '12', '1003', '503', 'Nota', day(previous, 5), 400000, 50000]] : []),
  expense_columns: ['id', 'ticket', 'inmueble', 'categoria', 'funcionario', 'fecha', 'servicio', 'transporte', 'total', 'estado'],
  expense: [
    [7, '10', '501', 'Mantenimiento', 'Ana <img src=x onerror=alert(1)>', day(month, 2), 90000, 10000, 100000, 'pagado'],
    [8, '11', '502', 'Revision preventiva', 'Luis', day(month, 4), 80000, 20000, 100000, 'en_cobro'],
  ].concat(previous !== month ? [[9, '12', '503', 'Otro', 'Luis', day(previous, 6), 90000, 10000, 100000, 'sin_cobrar']] : []),
  undated: { income_count: 1, income_value: 30000, expense_count: 0, expense_value: 0 },
  employees: ['Libia Correa Rivera', 'Alvaro Florez De Oro'],
  excluded: { count: 4, value: 70000 },
  employees_not_found: [],
  generated_at: Math.floor(Date.now() / 1000),
};

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/ajax', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: snapshot }) }));
  await page.route('https://panel.test/', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }));
  await page.goto('https://panel.test/');
  await page.setContent('<!doctype html><html><body><div id="scm-app"><div class="scm-tab-panel active">' + shell + '</div></div></body></html>');
  await page.addStyleTag({ content: fs.readFileSync(path.join(root, 'public/assets/css/contract-movements.css'), 'utf8') });
  await page.evaluate(() => { window.SCMAdminCore = { parseRuntime: () => ({ actions: { maintenance_cost_benefit: 'scm_metricas_costo_beneficio' }, nonce: 'n', ajaxUrl: '/ajax' }) }; });
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/admin-cost-benefit-metrics.js') });
  await page.waitForSelector('[data-scm-cb-content] .scm-cm-kpi');

  const kpis = () => page.$$eval('[data-scm-cb-content] .scm-cm-kpi', (items) => Object.fromEntries(items.map((item) => [item.querySelector('span').textContent, item.querySelector('strong').textContent])));
  const digits = (text) => text.replace(/\D/g, '');

  let values = await kpis();
  assert.equal(digits(values.Ingresos), '300000', 'income is the administration of the month');
  assert.equal(digits(values.Gastos), '200000', 'expense is what employees billed in the month');
  assert.equal(digits(values['Beneficio neto']), '100000');
  assert.equal(values['Relación beneficio/costo'], '1,50');
  assert.equal(digits(values.Pagado), '100000');
  assert.equal(digits(values['En cuenta de cobro']), '100000');
  assert.match(await page.textContent('[data-scm-cb-content]'), /1 cotización aprobada sin fecha de respuesta/, 'undated rows are reported');
  assert.match(await page.textContent('[data-scm-cb-content]'), /Gastos de Libia Correa Rivera y Alvaro Florez De Oro\. En todo el historial no se cuentan 4 reportes de otros funcionarios/, 'it says whose expenses count and what was left out');
  assert.equal(await page.isHidden('[data-scm-cb-export]'), true, 'a single month has no monthly export');

  await page.click('[data-scm-cb-mode="anual"]');
  values = await kpis();
  const yearIncome = previous !== month ? 350000 : 300000;
  const yearExpense = previous !== month ? 300000 : 200000;
  assert.equal(digits(values.Ingresos), String(yearIncome), 'the year adds every month');
  assert.equal(digits(values.Gastos), String(yearExpense));
  assert.equal(await page.isHidden('[data-scm-cb-export]'), false, 'the year exports its months');
  assert.equal(await page.locator('.scm-cc-col').count(), month, 'the chart shows only elapsed months');
  assert.equal(await page.locator('.scm-cc-bar i').count(), 1, 'only the tallest bar carries a value');
  await page.setViewportSize({ width: 1280, height: 1400 });
  if (process.env.SCM_SCREENSHOT) await page.screenshot({ path: process.env.SCM_SCREENSHOT, fullPage: true });

  await page.click(`.scm-cc-col[data-scm-cb-month="${currentMonth}"]`);
  assert.equal(digits((await kpis()).Ingresos), '300000', 'touching a month opens that month');

  await page.selectOption('[data-scm-cb-filter="tipo"]', 'Mantenimiento');
  values = await kpis();
  assert.equal(digits(values.Gastos), '100000', 'the type filter narrows the expense');
  assert.equal(digits(values.Ingresos), '300000', 'the type filter does not change the income');
  assert.equal(values['Relación beneficio/costo'], '3,00');
  await page.selectOption('[data-scm-cb-filter="tipo"]', '');

  assert.equal(await page.locator('[data-scm-cb-detail] [data-scm-maintenance-quote-open-case]').count(), 2, 'income rows open the case');
  await page.click('[data-scm-cb-view="gastos"]');
  assert.equal(await page.locator('[data-scm-cb-detail] tbody tr').count(), 2, 'the expense detail lists the reports');
  assert.equal(await page.locator('[data-scm-cb-content] img').count(), 0, 'employee names are escaped');
  await page.fill('[data-scm-cb-search]', 'luis');
  await page.waitForFunction(() => document.querySelectorAll('[data-scm-cb-detail] tbody tr').length === 1);

  await page.setViewportSize({ width: 390, height: 800 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert.ok(overflow <= 0, 'no horizontal page scroll on a phone: ' + overflow);

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Maintenance cost-benefit checks passed.');
})().catch((error) => { console.error(error); process.exit(1); });
