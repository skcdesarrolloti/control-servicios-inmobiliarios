const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

// Gestor organizacional en el navegador: el servidor se simula con fetch; la estructura sale del PHP real.
const root = path.resolve(__dirname, '..');
const shell = execFileSync('php', ['-r', "require 'vendor/autoload.php'; echo (new SCM\\Modules\\OrganizationManager\\OrganizationManagerView())->renderShell(['view'=>true,'manage'=>true,'delete'=>true,'transfer'=>true,'export'=>true], 'Ana <Admin>', 'https://panel.test/quill.js', 'https://panel.test/quill.css');"], { cwd: root, encoding: 'utf8' });

const boot = {
  can: { view: true, manage: true, delete: true, transfer: true, export: true },
  catalog: {
    areas: [{ id: 1, nombre: 'Mantenimiento' }, { id: 3, nombre: 'Contractual' }],
    cargos: [{ id: 7, nombre: 'Coordinador', id_area: '3', cargos_a_cargo: '4' }, { id: 4, nombre: 'Asistente', id_area: '3', cargos_a_cargo: '' }],
    procesos: [], sucursales: [{ id: 1, nombre: 'Cartagena' }], funcionarios: [{ id: '9', nombre: 'Julián' }, { id: '10', nombre: 'Dilsa' }], wp_roles: [],
  },
  types: { areas_organizacion: ['Área', 'Áreas'], cargos: ['Cargo', 'Cargos'], politicas: ['Política', 'Políticas'], procesos: ['Proceso', 'Procesos'], funciones: ['Función', 'Funciones'], compensaciones: ['Compensación', 'Compensaciones'], funcionarios: ['Funcionario', 'Funcionarios'] },
  inspectable_cargos: [7, 4], own_cargos: [7], employee: { id: '9', name: 'Julián' },
  policy: { columns: ['codigo', 'version', 'estado', 'audiencia', 'id_politica_base', 'id_responsable', 'vigencia_desde', 'fecha_revision', 'fecha_proxima_revision', 'motivo_cambio', 'categoria', 'id_proceso'], states: ['Borrador', 'Vigente', 'Derogada'], audiences: ['Empleados', 'Proveedores', 'Ambos'] },
};
const policyRow = { _ID: 47, titulo: '<img src=x onerror=alert(1)>Garantía', codigo: 'POL-CON-001', version: '2', estado: 'Vigente', audiencia: 'Proveedores', extracto: 'Texto <b>corto</b>', id_area: '3', id_cargo: '7,4', fecha_actualizacion_texto: '2026-11-01' };
const management = {
  funcionario: { nombre: 'Julián <b>', id_empleado: '9' },
  cargos: [{ _ID: 7, nombre_cargo: 'Coordinador', mision_cargo: 'Coordinar', id_area: '3', cargos_a_cargo: '4', perfil: [{ label: 'Misión', text: 'Coordinar <script>' }] }],
  equipo: [{ _ID: 4, nombre_cargo: 'Asistente', mision_cargo: '', id_area: '3', cargos_a_cargo: '', perfil: [] }],
  politicas: [
    { _ID: 47, titulo: 'Garantía', codigo: 'POL-CON-001', version: '2', estado: 'Vigente', audiencia: 'Empleados', extracto: 'Texto', id_area: '3', html: '<p>Texto</p>', leida: '' },
    { _ID: 48, titulo: 'Celular', codigo: 'POL-GEN-001', version: '1', estado: 'Vigente', audiencia: 'Empleados', extracto: 'Sin celular', id_area: '', html: '<p>Sin celular</p>', leida: '2026-11-02 10:00:00' },
  ],
  funciones: [{ funcion: 'Revisar contratos', frecuencia: 'Semanal', id_area: '3' }], procesos: [], compensaciones: [],
};

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('dialog', (dialog) => { errors.push('dialog nativo: ' + dialog.message()); dialog.dismiss(); });
  await page.route('https://panel.test/quill.js', (route) => route.fulfill({ path: path.join(root, 'public/assets/vendor/quill/quill.js'), contentType: 'text/javascript' }));
  await page.route('https://panel.test/quill.css', (route) => route.fulfill({ path: path.join(root, 'public/assets/vendor/quill/quill.snow.css'), contentType: 'text/css' }));
  await page.setContent('<!doctype html><html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined"></head><body><div id="scm-app"><div class="scm-tab-panel active">' + shell + '</div></div></body></html>');
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/tailwind-services.css') });
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/organization-manager.css') });
  await page.evaluate(({ boot, policyRow, management }) => {
    window.calls = [];
    window.SCMAdminCore = { parseRuntime: () => ({ ajaxUrl: '/api', nonce: 'n1' }), scmNotify: () => {} };
    window.fetch = (url, opts) => {
      const body = {};
      opts.body.forEach((value, key) => { if (!(key in body)) body[key] = value; else body[key] = [].concat(body[key], value); });
      window.calls.push(body);
      const responses = {
        scm_org_bootstrap: boot,
        scm_org_my_management: management,
        scm_org_cargo_management: { cargos: [{ _ID: 4, nombre_cargo: 'Asistente', mision_cargo: 'Atender', id_area: '3', perfil: [] }], politicas: [], funciones: [{ funcion: 'Atender casos', frecuencia: '', id_area: '3' }], procesos: [], compensaciones: [] },
        scm_org_policy_confirm: { confirmed_at: '2026-11-17 09:00:00', version: 2, message: 'Lectura confirmada.' },
        scm_org_list: body.tipo === 'politicas' ? { rows: [policyRow], total: 1, limited: false } : { rows: [{ _ID: 5, nombre_cargo: 'Coordinador <i>', mision_cargo: 'Coordinar', id_area: '3', cargos_a_cargo: '4', kpis: 'KPIs al día' }], total: 1, limited: false },
        scm_org_policy_detail: { record: Object.assign({}, policyRow, { html: '<p><strong>Texto</strong> limpio</p>', asignada: true, leida: '' }) },
        scm_org_record: { record: Object.assign({}, policyRow, { politica: '<p>Texto</p>' }) },
        scm_org_policy_code: { codigo: 'POL-MAN-002' },
        scm_org_save: { id: 99, record: { version: '3' }, warnings: [], created: true, message: 'Política creada.' },
        scm_org_delete_impact: { name: 'Coordinador <i>', type_label: 'Cargo', items: ['3 funcionarios'] },
        scm_org_delete: { message: 'Cargo «Coordinador» eliminada.', action: 'eliminar' },
        scm_org_policy_readers: { version: 2, confirmed: [{ id_empleado: '9', nombre: 'Julián', confirmed_at: '2026-11-10', asignado: true }], pending: [{ id_empleado: '10', nombre: 'Dilsa', cargo: 'Asistente' }], expected: 2, titulo: 'Garantía' },
        scm_org_policy_history: { history: [{ id: 2, version: 2, accion: 'nueva_version', titulo: 'Garantía', html: '<p>v2</p>', datos: { codigo: 'POL-CON-001' }, motivo: 'Cambio de tope', nombre: 'Ana', created_at: '2026-11-10 10:00:00' }], activity: [] },
        scm_org_transfer_data: { funcionarios: [{ id_empleado: '10', nombre: 'Dilsa', activo: 'Si' }, { id_empleado: '11', nombre: 'Clara', activo: 'Si' }], modules: [{ key: 'inmuebles', label: 'Inmuebles', description: 'Cartera' }, { key: 'tickets', label: 'Tickets', description: 'Tickets' }], business_types: ['Venta', 'Arriendo'], logs: [] },
        scm_org_transfer_preview: { total: 2, message: 'Previsualización lista.', modules: [{ key: 'inmuebles', label: 'Inmuebles', count: 2, records: [{ _ID: '1', codigo: '500' }, { _ID: '2', codigo: '<b>501</b>' }] }] },
        scm_org_transfer_execute: { message: 'Traslado realizado correctamente.', total: 1, postmeta_updated: 1, postmeta_skipped: 0, logs: [{ id: 1, created_at: '2026-11-17', origin_name: 'Dilsa', destination_name: 'Clara', total_records: 1, reason: 'Retiro', details: [] }] },
      };
      return Promise.resolve({ json: () => Promise.resolve({ success: true, data: responses[body.action] || {} }) });
    };
  }, { boot, policyRow, management });
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/organization-manager.js') });
  const lastCall = (action) => page.evaluate((name) => window.calls.filter((call) => call.action === name).pop(), action);

  // Mi gestión: secciones, escape y «Leí y entiendo» con confirmación propia (sin diálogos del navegador).
  await page.waitForSelector('[data-om-section="politicas"]');
  assert.equal(await page.locator('[data-om-tab]').count(), 11, 'todas las pestañas con todos los permisos');
  assert.match(await page.textContent('[data-om-body]'), /Julián <b>/, 'el nombre se escapa');
  assert.equal(await page.locator('[data-om-section="politicas"][open]').count(), 1, 'con lecturas pendientes, Mis políticas se abre');
  assert.match(await page.textContent('[data-om-section="politicas"] summary'), /1 por confirmar/);
  await page.click('[data-om-quick-confirm="47"]');
  await page.waitForSelector('.scm-om-modal [data-om-accept]');
  assert.match(await page.textContent('.scm-om-modal'), /Garantía/);
  await page.click('.scm-om-modal [data-om-accept]');
  await page.waitForFunction(() => !document.querySelector('.scm-om-modal'));
  assert.equal((await lastCall('scm_org_policy_confirm')).id, '47');
  assert.equal(await page.locator('[data-om-quick-confirm="47"]').count(), 0, 'tras confirmar desaparece el botón');
  await page.click('[data-om-section="funciones"] summary');
  await page.fill('[data-om-section="funciones"] [data-om-section-search]', 'nada que coincida');
  assert.equal(await page.locator('[data-om-section="funciones"] [data-om-item]:visible').count(), 0, 'el filtro de la sección oculta lo que no coincide');
  await page.click('[data-om-section="equipo"] summary');
  await page.click('[data-om-subordinate="4"] summary');
  await page.waitForSelector('[data-om-subordinate="4"] [data-om-section="funciones"]');
  assert.equal((await lastCall('scm_org_cargo_management')).id_cargo, '4', 'Mi equipo se carga al abrirlo');
  console.log('OK Mi gestión: secciones, filtros, equipo y confirmación de lectura');

  // Políticas: listado con insignias y escape; detalle en popup con HTML limpio.
  await page.click('[data-om-tab="politicas"]');
  await page.waitForSelector('.scm-om-table');
  assert.equal(await page.locator('.scm-om-table img').count(), 0, 'el título no inyecta HTML');
  assert.match(await page.textContent('.scm-om-table'), /POL-CON-001/);
  assert.match(await page.textContent('.scm-om-table'), /Proveedores/);
  await page.fill('[data-om-filters] [name=titulo]', 'garan');
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_org_list' && call.titulo === 'garan'));
  await page.selectOption('[data-om-filters] [name=estado]', 'Vigente');
  await page.waitForFunction(() => window.calls.some((call) => call.action === 'scm_org_list' && call.estado === 'Vigente'));
  await page.waitForTimeout(400);
  await page.click('[data-om-policy-view="47"]');
  await page.waitForSelector('[data-om-policy-text] strong');
  assert.equal(await page.locator('.scm-om-modal [data-om-confirm-read]').count(), 1, 'quien la tiene asignada puede confirmarla desde el detalle');
  await page.click('.scm-om-modal [data-om-detail-readers]');
  await page.waitForFunction(() => document.querySelectorAll('.scm-om-modal').length === 2 && /Dilsa/.test(document.querySelectorAll('.scm-om-modal')[1].textContent));
  assert.match(await page.locator('.scm-om-modal').nth(1).textContent(), /1 de 2/);
  await page.keyboard.press('Escape');
  await page.click('.scm-om-modal [data-om-detail-history]');
  await page.waitForFunction(() => /Cambio de tope/.test(document.querySelectorAll('.scm-om-modal')[1].textContent));
  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
  console.log('OK políticas: listado, filtros con espera, detalle, lecturas e historial en popups');

  // Nueva política: título y texto obligatorios, código sugerido por el área, vista previa y guardado.
  await page.click('[data-om-new]');
  await page.waitForSelector('.scm-om-modal .ql-editor');
  await page.waitForFunction(() => document.querySelector('.scm-om-modal [name=codigo]').value === 'POL-MAN-002');
  await page.click('.scm-om-modal [data-om-save]');
  assert.match(await page.textContent('.scm-om-modal .scm-om-form-error'), /título/);
  await page.fill('.scm-om-modal [name=titulo]', 'Llaves');
  await page.click('.scm-om-modal [data-om-save]');
  assert.match(await page.textContent('.scm-om-modal .scm-om-form-error'), /texto/);
  await page.click('.scm-om-modal [data-om-ms="id_area"] .scm-om-ms-box');
  await page.keyboard.type('mante');
  await page.keyboard.press('Enter');
  assert.equal(await page.inputValue('.scm-om-modal [name=id_area]'), '1', 'el selector múltiple busca y elige con Enter');
  await page.click('.scm-om-modal .ql-editor');
  await page.keyboard.type('Guardar las llaves');
  await page.click('.scm-om-modal [data-om-preview]');
  await page.waitForFunction(() => document.querySelectorAll('.scm-om-modal').length === 2);
  assert.match(await page.locator('.scm-om-modal').nth(1).textContent(), /Guardar(\s|&nbsp;)+las(\s|&nbsp;)+llaves/);
  await page.keyboard.press('Escape');
  await page.click('.scm-om-modal [data-om-save]');
  await page.waitForFunction(() => !document.querySelector('.scm-om-modal'));
  const saved = await lastCall('scm_org_save');
  assert.equal(saved.tipo, 'politicas');
  assert.equal(saved.titulo, 'Llaves');
  // Quill escribe los espacios como &nbsp;; el servidor los vuelve espacios al limpiar el HTML.
  assert.match(saved.politica, /Guardar(\s|&nbsp;)+las(\s|&nbsp;)+llaves/);
  assert.equal(saved.id_area, '1');
  assert.ok(!('nueva_version' in saved));
  console.log('OK nueva política: validación, código sugerido, selector múltiple, vista previa y guardado');

  // Nueva versión: exige motivo.
  await page.click('[data-om-policy-version="47"]');
  await page.waitForSelector('.scm-om-modal [name=motivo_cambio]');
  assert.match(await page.textContent('.scm-om-modal'), /versión 3/);
  await page.click('.scm-om-modal [data-om-save]');
  assert.match(await page.textContent('.scm-om-modal .scm-om-form-error'), /motivo/);
  await page.fill('.scm-om-modal [name=motivo_cambio]', 'Nuevo tope de cobro');
  await page.click('.scm-om-modal [data-om-save]');
  await page.waitForFunction(() => !document.querySelector('.scm-om-modal'));
  assert.equal((await lastCall('scm_org_save')).nueva_version, '1');
  console.log('OK nueva versión pide el motivo');

  // Eliminar: popup que nombra el registro y lo que le apunta.
  await page.click('[data-om-tab="cargos"]');
  await page.waitForSelector('[data-om-remove="5"]');
  await page.click('[data-om-remove="5"]');
  await page.waitForSelector('.scm-om-modal [data-om-accept]');
  const confirmText = await page.textContent('.scm-om-modal');
  assert.match(confirmText, /Coordinador <i>/);
  assert.match(confirmText, /3 funcionarios/);
  assert.match(confirmText, /no se puede deshacer/);
  await page.click('.scm-om-modal [data-om-accept]');
  await page.waitForFunction(() => !document.querySelector('.scm-om-modal'));
  assert.equal((await lastCall('scm_org_delete')).id, '5');
  await page.click('[data-om-profile="5"]');
  await page.waitForSelector('.scm-om-modal');
  assert.match(await page.textContent('.scm-om-modal'), /KPIs al día/);
  await page.keyboard.press('Escape');
  console.log('OK eliminar con confirmación y perfil del cargo');

  // Traslados: previsualizar, seleccionar y confirmar.
  await page.click('[data-om-tab="traslados"]');
  await page.waitForSelector('[data-om-ms="origin"]');
  await page.click('[data-om-ms="origin"] .scm-om-ms-box');
  await page.keyboard.type('dilsa');
  await page.keyboard.press('Enter');
  await page.click('[data-om-transfer-preview]');
  await page.waitForSelector('[data-om-transfer-record]');
  assert.equal(await page.locator('.scm-om-table b').count(), 0, 'la previsualización escapa los datos');
  await page.click('[data-om-ms="destination"] .scm-om-ms-box');
  await page.keyboard.type('clara');
  await page.keyboard.press('Enter');
  await page.check('[data-om-transfer-record][value="2"]');
  assert.ok(await page.isChecked('[name=om_transfer_mode][value=selected]'), 'marcar un registro cambia a solo seleccionados');
  await page.fill('[data-om-transfer-reason]', 'Retiro');
  await page.click('[data-om-transfer-execute]');
  await page.waitForSelector('.scm-om-modal [data-om-accept]');
  assert.match(await page.textContent('.scm-om-modal'), /1<\/strong>|1 registro/);
  await page.click('.scm-om-modal [data-om-accept]');
  await page.waitForFunction(() => /Traslado realizado/.test(document.querySelector('[data-om-body]').textContent));
  const executed = await lastCall('scm_org_transfer_execute');
  assert.equal(executed.origin, '10');
  assert.equal(executed.destination, '11');
  assert.equal(executed.reason, 'Retiro');
  assert.deepEqual(JSON.parse(executed.options).modules.inmuebles.selected_ids, ['2']);
  console.log('OK traslados: previsualización, selección y confirmación');

  // Celular: sin desbordar el ancho.
  await page.setViewportSize({ width: 390, height: 800 });
  await page.click('[data-om-tab="politicas"]');
  await page.waitForSelector('.scm-om-table');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 1, 'sin desborde horizontal en celular: ' + overflow);
  console.log('OK vista de celular sin desborde');

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Gestor organizacional UI: verificación completa.');
})().catch((error) => { console.error(error); process.exit(1); });
