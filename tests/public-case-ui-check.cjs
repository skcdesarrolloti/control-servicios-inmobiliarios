const {chromium} = require('playwright');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'scm-public-case-'));
  const repo = path.resolve(__dirname, '..').replace(/\\/g, '/');
  const tempPosix = temp.replace(/\\/g, '/');
  const router = path.join(temp, 'router.php');
  fs.writeFileSync(router, `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) return false;
require '${repo}/bootstrap/app.php';
if ($path === '/fixture') { header('Content-Type: application/json'); echo json_encode(['reference' => SCM\\Support\\PublicCaseAccess::reference(1999999999), 'expired' => SCM\\Support\\PublicCaseAccess::reference(1999999999, time()-1), 'audiences' => array_combine(['funcionario','propietario','arrendatario','copropiedad'], array_map(fn($a) => SCM\\Support\\PublicCaseAccess::reference(1999999999, null, $a), ['funcionario','propietario','arrendatario','copropiedad']))]); exit; }
if ($path === '/file.php') { header('Content-Type: image/png'); echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII='); exit; }
$db = SCM\\Core\\App::db();
$db->pdo()->exec('CREATE TEMPORARY TABLE '.chr(96).$db->table('jet_cct_tickets').chr(96).' (_ID BIGINT, asunto TEXT, descripcion TEXT, tema_ayuda TEXT, estado TEXT, contrato TEXT, inmueble TEXT, direccion TEXT, fecha BIGINT, nombre_empleado TEXT, imagenes TEXT, archivos TEXT, sucursal TEXT, correo_propietario TEXT, observacion_interna TEXT)');
$files = SCM\\Support\\StoredFileService::fromRuntime();
$db->insert($db->table('jet_cct_tickets'), ['_ID'=>1999999999,'asunto'=>'Revisar fuga en cocina','descripcion'=>"La llave de la cocina presenta una fuga.\\nSe requiere revisar la conexión y coordinar la visita.",'tema_ayuda'=>'Reparaciones necesarias','estado'=>'Nuevo','contrato'=>'QA801','inmueble'=>'SIMI8001','direccion'=>'Calle de ejemplo 123','fecha'=>time(),'nombre_empleado'=>'Consultor de prueba','imagenes'=>$files->urlFor(str_repeat('a',24).'_123.png'),'archivos'=>serialize([['nombre_archivo'=>'Soporte PDF','archivo'=>$files->urlFor(str_repeat('b',24).'_123.pdf')]]),'sucursal'=>'SECRET_BRANCH','correo_propietario'=>'SECRET_CONTACT','observacion_interna'=>'SECRET_NOTE']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  // Respuestas: historial y cola en tablas temporales de esta conexión; nada llega a la base ni a la cola reales.
  $shared = require dirname('${repo}') . '/shared-notifications/config.php';
  foreach ([$db->table('jet_cct_historial_del_ticket'), $shared['queue']['queue_table'], $shared['queue']['attempts_table']] as $table) {
    $sql = $db->getRow('SHOW CREATE TABLE '.chr(96).$table.chr(96))['Create Table'] ?? '';
    if (!str_starts_with($sql, 'CREATE TABLE')) { http_response_code(500); exit; }
    $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
  }
  register_shutdown_function(static function () use ($db) {
    $row = $db->getRow('SELECT COUNT(*) AS total, MAX(nombre) AS nombre FROM '.chr(96).$db->table('jet_cct_historial_del_ticket').chr(96));
    file_put_contents('${tempPosix}/history.json', json_encode($row));
  });
}
$_SESSION['scm_logged_in'] = ($_GET['logged'] ?? '') !== '';
$_SESSION['scm_last_activity'] = ($_GET['logged'] ?? '') === 'expired' ? 1 : time();
require '${repo}/public/' . ($path === '/caso.php' ? 'caso.php' : 'index.php');
`);
  const base = 'http://127.0.0.1:18764';
  const server = spawn('php', ['-S', '127.0.0.1:18764', '-t', path.join(repo, 'public'), router], {stdio: 'ignore', windowsHide: true, env: {...process.env, APP_URL: base, APP_SECRET: 'q'.repeat(64)}});
  let browser;
  try {
    let fixture;
    for (let i = 0; i < 30 && !fixture; i++) {
      try { fixture = await (await fetch(base + '/fixture')).json(); } catch (_) { await new Promise(resolve => setTimeout(resolve, 150)); }
    }
    assert(fixture, 'isolated public route fixture starts');
    const url = base + '/?scm_case=' + fixture.reference;
    const legacy = await fetch(base + '/?scm_case=1999999999', {redirect: 'manual'});
    assert.equal(legacy.status, 302, 'old unsigned staff links preserve login flow');
    assert(legacy.headers.get('location').includes('next=index.php%3Fscm_case%3D1999999999'));
    assert.equal((await fetch(base + '/caso.php?scm_case=1999999999')).status, 403, 'public endpoint requires a signed reference');
    for (const reference of [fixture.expired, fixture.reference.replace('1999999999.', '1999999998.')]) {
      const response = await fetch(base + '/?scm_case=' + reference, {redirect: 'manual'});
      assert.equal(response.status, 403, 'unsigned, expired or tampered reference cannot access a case');
      const html = await response.text();
      assert(!html.includes('Revisar fuga'), 'denied response discloses no case data');
    }
    const logged = await fetch(url + '&logged=1', {redirect: 'manual'});
    assert.equal(logged.status, 302);
    assert.equal(logged.headers.get('location'), base + '/?scm_case=1999999999', 'logged-in signed link returns to canonical panel ID');
    const direct = await fetch(base + '/caso.php?scm_case=' + fixture.reference + '&logged=1', {redirect: 'manual'});
    assert.equal(direct.headers.get('location'), base + '/?scm_case=1999999999');
    assert.equal((await fetch(url + '&logged=expired')).status, 200, 'expired session falls back to signed public view');
    const forged = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'respuesta=Sin+token'});
    assert.equal(forged.status, 422, 'reply without CSRF token is rejected');
    assert((await forged.text()).includes('No se pudo validar el envío'));
    assert.equal(JSON.parse(fs.readFileSync(path.join(temp, 'history.json'), 'utf8')).total, 0, 'rejected reply writes nothing');
    assert.equal((await fetch(url, {method: 'PUT'})).status, 405, 'other methods stay closed');
    for (const [audience, reference] of Object.entries(fixture.audiences)) {
      const response = await fetch(base + '/?scm_case=' + reference);
      const html = await response.text();
      assert.equal(response.status, 200);
      assert.equal(html.includes('data-staff-access'), audience === 'funcionario', 'only signed staff links expose staff access');
      assert(html.includes('data-brand-logo') && html.includes('family=Poppins'), 'public view loads corporate logo and Poppins');
    }
    assert.equal((await fetch(base + '/?scm_case=' + fixture.audiences.propietario.replace('.propietario.', '.funcionario.'))).status, 403, 'recipient audience cannot be forged');
    browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const response = await page.goto(url);
    assert.equal(response.status(), 200);
    assert.equal(response.headers()['referrer-policy'], 'no-referrer');
    assert(response.headers()['cache-control'].includes('no-store'));
    const text = await page.locator('body').innerText();
    assert(text.includes('Revisar fuga en cocina') && text.includes('Consultor de prueba'));
    assert(!text.includes('SECRET_'), 'public page omits sensitive internal fields');
    assert(!text.includes('Acceso funcionarios'), 'generic public links omit internal navigation');
    assert((await page.locator('body').evaluate(node => getComputedStyle(node).fontFamily)).includes('Poppins'));
    assert.equal(await page.locator('[data-preview=image]').count(), 1);
    await page.locator('[data-preview=image]').click();
    assert(await page.locator('dialog').isVisible(), 'image opens inside same page');
    assert.equal(await page.locator('[data-preview-body] img').count(), 1);
    await page.keyboard.press('Escape');
    assert(await page.locator('dialog').isHidden());
    await page.locator('[data-preview=pdf]').click();
    assert.equal(await page.locator('[data-preview-body] iframe').count(), 1, 'PDF opens in the same preview');
    await page.locator('[data-close-preview]').click();
    const output = path.join(__dirname, '../output/public-case');
    fs.mkdirSync(output, {recursive: true});
    await page.screenshot({path: path.join(output, 'desktop.png'), fullPage: true});
    await page.setViewportSize({width: 390, height: 844});
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'public view fits mobile');
    await page.screenshot({path: path.join(output, 'mobile.png'), fullPage: true});
    await page.emulateMedia({media: 'print'});
    assert(await page.locator('[data-print]').isHidden(), 'print hides actions');
    assert.equal(await page.locator('.scm-public-case-evidence img').evaluate(img => getComputedStyle(img).height), '65px', 'print uses compact evidence');
    assert.equal(await page.locator('.scm-public-case-signoff').evaluate(node => getComputedStyle(node).breakInside), 'avoid', 'print preserves signoff');
    await page.pdf({path: path.join(output, 'print.pdf'), format: 'A4', preferCSSPageSize: true});
    assert.equal((fs.readFileSync(path.join(output, 'print.pdf'), 'latin1').match(/\/Type\s*\/Page\b/g) || []).length, 1, 'representative public case prints on one compact A4 page');
    await page.screenshot({path: path.join(output, 'print.png'), fullPage: true});
    await page.emulateMedia({media: 'screen'});
    await page.setViewportSize({width: 1280, height: 1000});
    assert.equal(await page.locator('[data-reply-form]').isHidden(), false, 'external links can reply');
    await page.goto(base + '/?scm_case=' + fixture.audiences.funcionario);
    assert.equal(await page.locator('[data-reply-form]').count(), 0, 'staff links do not get the public reply form');
    await page.goto(base + '/?scm_case=' + fixture.audiences.propietario);
    await page.locator('textarea[name=respuesta]').fill('Ya revisé la cocina.\nQuedo atento a la visita.');
    await Promise.all([page.waitForURL(/enviado=1/), page.locator('[data-reply-submit]').click()]);
    assert(await page.locator('text=Recibimos tu respuesta').isVisible(), 'confirmation is shown after the redirect');
    const saved = JSON.parse(fs.readFileSync(path.join(temp, 'history.json'), 'utf8'));
    assert.equal(Number(saved.total), 1, 'reply is stored once');
    assert.equal(saved.nombre, 'Propietario (Propietario)', 'reply is attributed to the signed audience');
    await page.screenshot({path: path.join(output, 'reply-sent.png'), fullPage: true});
    await page.goBack();
    assert.deepEqual(errors, [], 'no browser errors');
    console.log('PASS: signed public routing, panel session redirect, expiry, privacy, evidence previews, mobile and print');
  } finally {
    if (browser) await browser.close();
    server.kill();
    await new Promise(resolve => server.once('exit', resolve));
    fs.rmSync(temp, {recursive: true, force: true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
