// Configuración › Configuración hoja de cierre (admin-tools.js, initClosingPolicySettings): catálogo para leer las
// políticas completas, agregarlas a un ítem, archivos obligatorios y lo que se envía al guardar.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const html = execFileSync('php', ['-r', `
  require ${JSON.stringify(path.join(__dirname, '../vendor/autoload.php'))};
  echo SCM\\Modules\\ClosingSheets\\ClosingSheetPolicyView::form(
    ['estado_amparo_integral' => ['policies' => [47], 'states' => ['No cumple'], 'note' => '']],
    [47 => ['id' => 47, 'title' => 'Garantía de Servicios Públicos', 'text' => 'Texto completo de la 47.'],
     66 => ['id' => 66, 'title' => 'Servicios Públicos Compartidos', 'text' => 'Inmuebles con servicios compartidos y medidor.'],
     124 => ['id' => 124, 'title' => 'Servicios Públicos Compartidos (Prov.)', 'text' => 'Versión para el proveedor.']],
    ['estado_documento_arre']
  );
`], { encoding: 'utf8' });
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-tools.js'), 'utf8');
const start = source.indexOf('  function initClosingPolicySettings(');
const fn = source.slice(start, source.indexOf('  // Configuración › Honorarios de cotización', start));

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setContent('<!doctype html><html><body><div id="root"></div><button data-closing-policy-settings>Abrir</button></body></html>');
    await page.addScriptTag({ content: `
      window.sent = [];
      window.formHtml = ${JSON.stringify(html)};
      function post(action, data) { window.sent.push({ action: action, data: data }); return Promise.resolve({ html: window.formHtml, message: data.mode === 'save' ? 'Guardado' : '' }); }
      ${fn}
      initClosingPolicySettings(document.getElementById('root'), post);
    ` });
    await page.click('[data-closing-policy-settings]');
    assert.equal(await page.locator('#scm-cpp-title').textContent(), 'Configuración hoja de cierre');

    // El catálogo muestra las políticas con su texto; las "(Prov.)" se marcan como de proveedores.
    assert.equal(await page.locator('[data-cpp-list] details').count(), 3);
    assert.equal(await page.locator('[data-cpp-list] details.is-prov').count(), 1, 'provider versions are flagged');
    await page.fill('[data-cpp-filter]', 'medidor');
    assert.equal(await page.locator('[data-cpp-list] details').count(), 1, 'search looks inside the policy text');
    assert.match(await page.locator('[data-cpp-list] details p').textContent(), /servicios compartidos y medidor/);

    // Ver una política asignada desde su chip.
    await page.locator('[data-cpp-item="estado_amparo_integral"] [data-cpp-view]').click();
    assert.equal(await page.locator('[data-cpp-list] details[open] p').textContent(), 'Texto completo de la 47.');

    // Agregar una política a un ítem desde el catálogo.
    const row = page.locator('[data-cpp-item="estado_servicios_compatidos"]');
    await row.locator('xpath=ancestor::details').evaluate((d) => { d.open = true; });
    await row.locator('[data-cpp-pick]').click();
    assert.match(await page.locator('[data-cpp-target]').textContent(), /Agregando a:/);
    await page.fill('[data-cpp-filter]', '66');
    await page.click('[data-cpp-add-policy="66"]');
    assert.equal(await row.locator('[data-cpp-policy="66"]').count(), 1, 'the policy is added as a chip');
    assert.equal(await page.locator('[data-cpp-add-policy="66"]').isDisabled(), true, 'it cannot be added twice');

    // Archivos obligatorios.
    await page.click('[data-cpp-tab="files"]');
    assert.equal(await page.locator('[data-cpp-panel="files"]').isVisible(), true);
    assert.equal(await page.locator('[data-cpp-file][value="estado_documento_arre"]').isChecked(), true);
    await page.locator('[data-cpp-file][value="estado_rut_arre"]').check();
    await page.locator('[data-cpp-file][value="estado_documento_arre"]').uncheck();

    await page.click('[data-cpp-form] button[type="submit"]');
    await page.waitForFunction(() => window.sent.length === 2);
    const payload = JSON.parse(await page.evaluate(() => window.sent[1].data.rules));
    assert.deepEqual(payload.rules.estado_servicios_compatidos, { policies: [66], states: ['No cumple'], note: '' });
    assert.deepEqual(payload.rules.estado_amparo_integral.policies, [47]);
    assert.deepEqual(payload.files, ['estado_rut_arre']);
    assert.deepEqual(errors, [], 'no script errors');
    console.log('Closing sheet config UI checks passed.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
