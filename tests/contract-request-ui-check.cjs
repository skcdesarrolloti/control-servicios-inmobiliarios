const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');

// Render the real PHP panels and run their real JS with synthetic requests only.
const rootDir = path.join(__dirname, '..');
const runtime = fs.readFileSync(path.join(rootDir, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const functions = runtime.slice(runtime.indexOf('    function contractTerminationEmpty('), runtime.indexOf('    function contractsEndingEmployeeOptions('));
const markup = execFileSync('php', ['-r', String.raw`
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
$activeHomeSection = 'scm-home-calendar-section-contract-termination';
$source = file_get_contents('src/App/Concerns/RendersDashboard.php');
$start = strpos($source, "            <?php foreach (['termination' =>");
$end = strpos($source, '<?php endforeach; ?>', $start) + strlen('<?php endforeach; ?>');
eval('?>' . substr($source, $start, $end - $start));
`], { cwd: rootDir, encoding:'utf8' });

(async () => {
  const browser = await chromium.launch({ headless:true, executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const errors = [];
  const qaDir = process.env.SCM_CONTRACT_QA_DIR || path.join(os.tmpdir(), 'scm-contract-ui-qa');
  fs.mkdirSync(qaDir, { recursive:true });
  try {
    const page = await browser.newPage({ viewport:{ width:1280, height:1100 }, acceptDownloads:true });
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"></head><body><div id="scm-app">' + markup + '</div></body></html>');
    for (const file of ['tailwind-admin.css', 'tailwind-services.css', 'admin/01-core.css', 'admin/08-modern-normalize.css']) {
      await page.addStyleTag({ content:fs.readFileSync(path.join(rootDir,'public/assets/css',file),'utf8') });
    }
    await page.addScriptTag({ url:'https://cdn.jsdelivr.net/npm/sweetalert2@11' });
    await page.addScriptTag({ content:String.raw`
      var root = document.querySelector('#scm-app');
      var contractTerminationRowsByPk = {}, contractNonRenewalRowsByPk = {};
      var ajaxUrl = 'synthetic', actionContractTerminationRequests = 'termination-list', actionContractNonRenewalRequests = 'non-renewal-list';
      var actionContractTerminationRespond = 'termination-save', actionContractNonRenewalRespond = 'non-renewal-save';
      var requests = [], messages = [];
      function escHtml(value) { var el = document.createElement('div'); el.textContent = String(value == null ? '' : value); return el.innerHTML.replace(/"/g, '&quot;'); }
      function formatDashboardCount(n) { return String(n); }
      function dashboardDueCaseAttrsHtml() { return ' data-ticket-pk="41"'; }
      function showToast(kind, text) { messages.push({kind,text}); }
      function dashboardAction() { return Promise.resolve(fixture); }
      function dashboardFormAction(action, fill) { var fd = new FormData(); fill(fd); requests.push({action,data:Array.from(fd.entries())}); return Promise.resolve({message:'Guardado simulado'}); }
      var base = {solicitud_id:'41',ticket_pk:'41',id_ticket:'10863',titulo:'Ticket #10863',asunto:'Solicitud de terminación de contrato de arrendamiento',creado:'05/10/2026 08:54',contrato:'2000',inmueble:'204578',direccion:'Urbanización Simón Bolívar manzana 20 lote 7',solicitante:'ARRENDATARIO DE EJEMPLO',estado:'Nuevo',estado_administrativo:'Nuevo',estado_solicitud:'Pendiente',fecha_solicitud:'2026-10-05',fin_contrato:'2027-01-09',fin_contrato_label:'09/01/2027',fecha_limite_label:'09/10/2026',term_status:'dentro',term_label:'Dentro de término',term_hint:'Solicitud recibida antes o el 09/10/2026.',case:{case_source_html:'<p>Detalle del caso</p>'},retention_ticket:{enabled:true,default_employee_id:'13',funcionarios:[{id:'13',name:'Funcionario de Ejemplo',cargo:'Asistente de Desarrollo TI'}],assignment_help:[{label:'Contrato',value:'#2000'},{label:'Contrato de',value:'Arrendatario: CLIENTE / Propietario: PROPIETARIO'},{label:'Inmueble',value:'Inmueble 204578 · Urbanización Simón Bolívar'},{label:'Funcionario relacionado',value:'Funcionario de Ejemplo · Asistente de Desarrollo TI'},{label:'Sugerido para asignar',value:'Funcionario de Ejemplo · Asistente de Desarrollo TI'}]},recipients:[{value:'tenant',label:'Arrendatario',name:'CLIENTE',email:'cliente@example.invalid',available:true},{value:'owner',label:'Propietario',name:'PROPIETARIO',email:'propietario@example.invalid',available:true},{value:'staff',label:'Funcionario configurado',name:'Funcionario',available:false}]};
      var fixture = {generated_at:'05/10/2026 11:03',count:3,items:[base,Object.assign({},base,{solicitud_id:'42',ticket_pk:'42',titulo:'Ticket #10864',id_ticket:'10864',contrato:'2001',term_status:'fuera',term_label:'Fuera de término'}),Object.assign({},base,{solicitud_id:'43',ticket_pk:'43',titulo:'Ticket #10865',id_ticket:'10865',term_status:'unknown',term_label:'Sin cálculo de término',asunto:'<img src=x onerror=alert(1)>',solicitante:'=1+1'})]};
    ` + functions });
    await page.evaluate(() => { renderContractTermination(fixture); renderContractNonRenewal(fixture); document.querySelector('#scm-home-calendar-section-contract-non-renewal').style.display='none'; });
    const termination = page.locator('[data-scm-contract-termination-panel]');
    assert.equal(await termination.locator('.scm-contract-card').count(),3);
    assert.equal(await termination.locator('.scm-contract-card img').count(),0,'untrusted ticket text is escaped');
    assert.equal(await termination.locator('[data-scm-contract-termination-open-case]').count(),3);
    assert((await termination.locator('[data-scm-contract-termination-status]').innerText()).includes('1 Sin cálculo'));
    await termination.locator('[data-contract-filter-toggle]').click();
    await termination.locator('[data-contract-search]').fill('2001');
    assert.equal(await termination.locator('.scm-contract-card').count(),1);
    const downloadPromise = page.waitForEvent('download');
    await termination.locator('[data-contract-export]').click();
    const download = await downloadPromise;
    await download.saveAs(path.join(qaDir,'filtered.csv'));
    const csv = fs.readFileSync(path.join(qaDir,'filtered.csv'),'utf8');
    assert(csv.includes('2001') && !csv.includes('2000'),'export respects current search');
    await termination.locator('[data-contract-search]').fill('');
    await termination.locator('[data-contract-term-filter]').selectOption('fuera');
    assert.equal(await termination.locator('.scm-contract-card').count(),1);
    await termination.locator('[data-contract-term-filter]').selectOption('unknown');
    assert.equal(await termination.locator('.scm-contract-card').count(),1);
    await termination.locator('[data-contract-term-filter]').selectOption('');
    await termination.locator('[data-contract-filter-toggle]').click();
    await page.screenshot({path:path.join(qaDir,'termination-desktop.png'),fullPage:true});
    for (const kind of ['termination','non-renewal']) {
      await page.evaluate(kind => kind === 'termination' ? openContractTerminationResponse('41') : openContractNonRenewalResponse('41'),kind);
      const popup = page.locator('.scm-contract-response-swal');
      await popup.waitFor();
      await page.mouse.move(0,0);
      await page.waitForTimeout(350);
      await page.screenshot({path:path.join(qaDir,kind+'-modal-initial.png')});
      assert.equal(await popup.locator('[name="fecha_solicitud"]').getAttribute('readonly'),'');
      assert.equal(await popup.locator('[name="retencion_id_empleado"]').inputValue(),'13');
      assert.equal(await popup.locator('[name="notify_recipients[]"][value="staff"]').isDisabled(),true);
      assert.equal(await popup.locator('.swal2-confirm').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(248, 207, 74)','global modal rules cannot override reference yellow');
      await popup.locator('.swal2-confirm').click();
      assert((await popup.locator('.swal2-validation-message').innerText()).includes('clasificación'));
      await popup.locator('[name="termino"]').selectOption('dentro');
      await popup.locator('[name="crear_ticket_retencion"]').uncheck();
      assert(await popup.locator('[name="retencion_id_empleado"]').isDisabled());
      await popup.locator('[name="crear_ticket_retencion"]').check();
      assert(await popup.locator('[name="retencion_id_empleado"]').isEnabled());
      await popup.locator('[name="retencion_id_empleado"]').selectOption('');
      await popup.locator('.swal2-confirm').click();
      assert((await popup.locator('.swal2-validation-message').innerText()).includes('responsable'));
      await popup.locator('[name="retencion_id_empleado"]').selectOption('13');
      await popup.locator('[name="notify_recipients[]"][value="none"]').check();
      assert.equal(await popup.locator('[name="notify_recipients[]"]:checked').count(),1);
      await popup.locator('[name="notify_recipients[]"][value="tenant"]').check();
      assert.equal(await popup.locator('[name="notify_recipients[]"][value="none"]').isChecked(),false);
      await popup.locator('[name="notify_recipients[]"][value="tenant"]').uncheck();
      await popup.locator('.swal2-confirm').click();
      assert((await popup.locator('.swal2-validation-message').innerText()).includes('notificar'));
      await popup.locator('[name="notify_recipients[]"][value="none"]').check();
      await page.screenshot({path:path.join(qaDir,kind+'-modal-desktop.png')});
      await popup.locator('.swal2-confirm').click();
      await page.waitForFunction(() => !Swal.isVisible());
      await page.waitForTimeout(350);
    }
    const sent = await page.evaluate(() => requests);
    assert.deepEqual(sent.map(r=>r.action),['termination-save','non-renewal-save']);
    sent.forEach(r => { const data = Object.fromEntries(r.data); assert.equal(data.ticket_pk,'41'); assert.equal(data.retencion_id_empleado,'13'); assert.equal(data['notify_recipients[]'],'none'); assert.equal(data.termino,'dentro'); });
    await page.setViewportSize({width:390,height:844});
    await page.evaluate(() => openContractTerminationResponse('41'));
    await page.locator('.scm-contract-response-swal').waitFor();
    await page.waitForTimeout(350);
    assert(await page.locator('.swal2-confirm').isVisible());
    const confirmBounds = await page.locator('.swal2-confirm').boundingBox();
    assert(confirmBounds.y >= 0 && confirmBounds.y + confirmBounds.height <= 844,'mobile footer stays in viewport');
    assert(await page.locator('.scm-contract-response-swal').evaluate(el=>el.scrollWidth <= el.clientWidth),'mobile dialog has no horizontal overflow');
    await page.screenshot({path:path.join(qaDir,'termination-modal-mobile.png')});
    await page.locator('.swal2-close').click();
    await page.waitForFunction(() => !Swal.isVisible());
    assert(await termination.evaluate(el=>el.scrollWidth <= el.clientWidth),'mobile workspace has no horizontal overflow');
    await page.screenshot({path:path.join(qaDir,'termination-mobile.png'),fullPage:true});
    await page.evaluate(() => { document.querySelector('#scm-home-calendar-section-contract-termination').style.display='none'; document.querySelector('#scm-home-calendar-section-contract-non-renewal').style.display='block'; renderContractNonRenewal({items:[],count:0,generated_at:'test'}); });
    assert.equal(await page.locator('[data-scm-contract-non-renewal-panel]').getAttribute('data-scm-loaded'),'1','empty responses are cached');
    assert((await page.locator('[data-scm-contract-non-renewal-list]').innerText()).includes('No hay solicitudes'));
    assert.deepEqual(errors,[]);
    console.log('PASS: both panels, escaping, filters, CSV, cached empty state, both modal validations, retention toggle, recipient exclusivity, payloads, yellow CTA and mobile layout. QA: '+qaDir);
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
