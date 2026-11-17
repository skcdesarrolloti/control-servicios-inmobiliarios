// Configuración › Configuración hoja de cierre (admin-tools.js, initClosingPolicySettings): popup «Agregar política»
// con el catálogo (buscar, filtrar, leer completa, agregar y quitar), edición y creación con el formulario del Gestor
// organizacional (window.scmOrgPolicies, simulado), archivos obligatorios y lo que se envía al guardar.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const html = execFileSync('php', ['-r', `
  require ${JSON.stringify(path.join(__dirname, '../vendor/autoload.php'))};
  echo SCM\\Modules\\ClosingSheets\\ClosingSheetPolicyView::form(
    ['estado_amparo_integral' => ['policies' => [47], 'states' => ['No cumple'], 'note' => '']],
    [47 => ['id' => 47, 'title' => 'Garantía de Servicios Públicos', 'text' => 'Texto completo de la 47.', 'code' => 'POL-CCO-004', 'audience' => 'Empleados', 'state' => 'Vigente'],
     66 => ['id' => 66, 'title' => 'Servicios Públicos Compartidos', 'text' => 'Inmuebles con servicios compartidos y medidor.', 'state' => 'Vigente'],
     124 => ['id' => 124, 'title' => 'Servicios Públicos Compartidos (Prov.)', 'text' => 'Versión para el proveedor.', 'state' => 'Derogada']],
    ['estado_documento_arre']
  );
`], { encoding: 'utf8' });
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-tools.js'), 'utf8');
const start = source.indexOf('  // Configuración › Configuración hoja de cierre (ClosingSheetPolicyView');
const fn = source.slice(start, source.indexOf('  // Configuración › Honorarios de cotización', start));
const css = ['tailwind-services.css', 'admin/06-canon-insurance-audit.css'].map((file) => fs.readFileSync(path.join(__dirname, '../public/assets/css', file), 'utf8')).join('\n');
const shots = process.env.SCM_SHOTS || '';

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 860 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setContent('<!doctype html><html><body><div id="scm-app"></div><button data-closing-policy-settings>Abrir</button></body></html>');
    await page.addStyleTag({ content: css });
    await page.addScriptTag({ content: `
      window.sent = [];
      window.formHtml = ${JSON.stringify(html)};
      window.catalog = [
        { id: 47, title: 'Garantía de Servicios Públicos (editada)', text: 'Texto nuevo de la 47.', code: 'POL-CCO-004', state: 'Vigente' },
        { id: 66, title: 'Servicios Públicos Compartidos', text: 'Inmuebles con servicios compartidos y medidor.', state: 'Vigente' },
        { id: 124, title: 'Servicios Públicos Compartidos (Prov.)', text: 'Versión para el proveedor.', state: 'Derogada' },
        { id: 200, title: 'Política nueva de cierre', text: 'Creada desde la hoja de cierre.', state: 'Vigente' },
      ];
      // Formulario del Gestor organizacional: guarda de inmediato y avisa con el resultado del servidor.
      window.gestor = [];
      window.scmOrgPolicies = {
        access: function () { return Promise.resolve({ view: true, manage: true }); },
        create: function (done) { window.gestor.push('create'); done({ id: 200, created: true }); return Promise.resolve(); },
        edit: function (id, done) { window.gestor.push('edit:' + id); done({ id: id, created: false }); return Promise.resolve(); },
        detail: function (id) { window.gestor.push('detail:' + id); return Promise.resolve(); },
      };
      function post(action, data) {
        window.sent.push({ action: action, data: data });
        if (data.mode === 'catalog') return Promise.resolve({ catalog: window.catalog });
        return Promise.resolve({ html: window.formHtml, message: data.mode === 'save' ? 'Guardado' : '' });
      }
      ${fn}
      initClosingPolicySettings(document.getElementById('scm-app'), post);
    ` });
    await page.click('[data-closing-policy-settings]');
    assert.equal(await page.locator('#scm-cpp-title').textContent(), 'Configuración hoja de cierre');
    assert.equal(await page.locator('[data-cpp-form] [data-cpp-list], [data-cpp-browser]').count(), 0, 'the catalog is no longer a side panel');

    // «Agregar política» abre el popup con el catálogo y lee la primera.
    const row = page.locator('[data-cpp-item="estado_servicios_compatidos"]');
    await row.locator('xpath=ancestor::details').evaluate((d) => { d.open = true; });
    await row.locator('[data-cpp-pick]').click();
    const picker = page.locator('[data-cpp-picker]');
    assert.equal(await picker.isVisible(), true);
    assert.match(await picker.locator('[data-cpp-picker-title]').textContent(), /servicio/i, 'the popup names the item');
    assert.equal(await picker.locator('[data-cpp-picker-item]').count(), 3);
    assert.equal(await picker.locator('[data-cpp-picker-item][aria-selected="true"]').getAttribute('data-cpp-picker-item'), '47');
    assert.equal(await picker.locator('[data-cpp-picker-item] .is-prov').count(), 1, 'provider versions are flagged');
    assert.equal(await picker.locator('[data-cpp-picker-item="124"] .is-off').textContent(), 'Derogada', 'non-current policies say so');
    assert.equal(await page.evaluate(() => document.activeElement.matches('[data-cpp-picker-search]')), true, 'the search box gets the focus');
    if (shots) await page.screenshot({ path: path.join(shots, 'agregar-politica.png') });

    // Buscar dentro del texto y agregar con Enter.
    await page.fill('[data-cpp-picker-search]', 'medidor');
    assert.equal(await picker.locator('[data-cpp-picker-item]').count(), 1, 'search looks inside the policy text');
    assert.match(await picker.locator('.cpp-pk-text').textContent(), /servicios compartidos y medidor/);
    await page.press('[data-cpp-picker-search]', 'Enter');
    assert.equal(await row.locator('[data-cpp-policy="66"]').count(), 1, 'the policy is added as a chip');
    assert.equal(await picker.locator('[data-cpp-picker-remove="66"]').count(), 1, 'once added it offers to remove it instead');
    assert.match(await picker.locator('[data-cpp-picker-status]').textContent(), /Agregada: #66/);
    await page.press('[data-cpp-picker-search]', 'Enter');
    assert.equal(await row.locator('[data-cpp-policy="66"]').count(), 1, 'it cannot be added twice');

    // Filtros: En este ítem / Proveedores.
    await page.fill('[data-cpp-picker-search]', '');
    await picker.locator('[data-cpp-picker-filter="asignadas"]').click();
    assert.deepEqual(await picker.locator('[data-cpp-picker-item]').evaluateAll((els) => els.map((el) => el.getAttribute('data-cpp-picker-item'))), ['66']);
    await picker.locator('[data-cpp-picker-filter="proveedores"]').click();
    assert.deepEqual(await picker.locator('[data-cpp-picker-item]').evaluateAll((els) => els.map((el) => el.getAttribute('data-cpp-picker-item'))), ['124']);

    // Escape cierra solo el popup.
    await page.keyboard.press('Escape');
    assert.equal(await picker.isVisible(), false);
    assert.equal(await page.locator('#scm-cpp-title').isVisible(), true, 'the settings stay open');

    // Tocar el chip de una política del ítem la abre en el popup; desde ahí se quita, se edita o se crea otra.
    const amparo = page.locator('[data-cpp-item="estado_amparo_integral"]');
    await amparo.locator('[data-cpp-view]').click();
    assert.equal(await picker.locator('[data-cpp-picker-item][aria-selected="true"]').getAttribute('data-cpp-picker-item'), '47');
    assert.equal(await picker.locator('.cpp-pk-text').textContent(), 'Texto completo de la 47.');
    assert.equal(await picker.locator('[data-cpp-picker-new]').isVisible(), true, 'with Gestor permission it can create');
    await picker.locator('[data-cpp-picker-edit="47"]').click();
    await page.waitForFunction(() => /editada/.test(document.querySelector('[data-cpp-item="estado_amparo_integral"] [data-cpp-view]').textContent));
    assert.equal(await picker.locator('.cpp-pk-text').textContent(), 'Texto nuevo de la 47.', 'the catalog reloads after editing');
    await picker.locator('[data-cpp-picker-new]').click();
    await page.waitForFunction(() => document.querySelector('[data-cpp-item="estado_amparo_integral"] [data-cpp-policy="200"]'));
    assert.match(await picker.locator('[data-cpp-picker-status]').textContent(), /creada y agregada/);
    await picker.locator('[data-cpp-picker-item="47"]').click();
    await picker.locator('[data-cpp-picker-remove="47"]').click();
    assert.equal(await amparo.locator('[data-cpp-policy="47"]').count(), 0, 'removed from the item');
    await picker.locator('[data-cpp-picker-detail="47"]').click();
    assert.deepEqual(await page.evaluate(() => window.gestor), ['edit:47', 'create', 'detail:47']);
    await picker.locator('footer [data-cpp-picker-close]').click();

    // Archivos obligatorios.
    await page.click('[data-cpp-tab="files"]');
    assert.equal(await page.locator('[data-cpp-panel="files"]').isVisible(), true);
    assert.equal(await page.locator('[data-cpp-file][value="estado_documento_arre"]').isChecked(), true);
    await page.locator('[data-cpp-file][value="estado_rut_arre"]').check();
    await page.locator('[data-cpp-file][value="estado_documento_arre"]').uncheck();

    await page.click('[data-cpp-form] button[type="submit"]');
    await page.waitForFunction(() => window.sent.some((item) => item.data.mode === 'save'));
    const payload = JSON.parse(await page.evaluate(() => window.sent.find((item) => item.data.mode === 'save').data.rules));
    assert.deepEqual(payload.rules.estado_servicios_compatidos, { policies: [66], states: ['No cumple'], note: '' });
    assert.deepEqual(payload.rules.estado_amparo_integral.policies, [200]);
    assert.deepEqual(payload.files, ['estado_rut_arre']);

    // En el celular la lista va arriba y la lectura abajo.
    await page.setViewportSize({ width: 390, height: 800 });
    await page.click('[data-cpp-tab="policies"]');
    await page.locator('[data-cpp-item="estado_servicios_compatidos"]').evaluate((el) => { el.closest('details').open = true; });
    await page.locator('[data-cpp-item="estado_servicios_compatidos"] [data-cpp-pick]').click();
    const box = await picker.locator('.cpp-pk').boundingBox();
    assert.ok(box.width <= 390, 'the popup fits the phone width');
    const list = await picker.locator('[data-cpp-picker-list]').boundingBox();
    const view = await picker.locator('[data-cpp-picker-view]').boundingBox();
    assert.ok(view.y >= list.y + list.height - 1, 'reading pane goes below the list');
    if (shots) await page.screenshot({ path: path.join(shots, 'agregar-politica-movil.png') });
    assert.deepEqual(errors, [], 'no script errors');
    console.log('Closing sheet config UI checks passed.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
