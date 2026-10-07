const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');

const rootDir = path.join(__dirname, '..');
const runtime = fs.readFileSync(path.join(rootDir, 'public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const functions = runtime.slice(runtime.indexOf('    function contractsEndingEmployeeOptions('), runtime.indexOf('    function loadDashboardHome()', runtime.indexOf('    function contractsEndingEmployeeOptions(')));
const caseFunction = runtime.slice(runtime.indexOf('    function openDashboardDueCaseFromButton('), runtime.indexOf('    function dashboardOpenDueCase('));
const render = fs.readFileSync(path.join(rootDir, 'src/App/Concerns/RendersDashboard.php'), 'utf8');
const start = render.indexOf('<section class="scm-contract-termination-panel scm-contracts-ending-panel"');
const markup = render.slice(start, render.indexOf('</section>', start) + '</section>'.length);

(async () => {
  const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  const qaDir = path.join(os.tmpdir(), 'scm-contracts-ending-qa');
  fs.mkdirSync(qaDir, {recursive:true});
  try {
    const page = await browser.newPage({viewport:{width:1400,height:1100}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy">' + markup + '</div></body></html>');
    for (const file of ['tailwind-admin.css','tailwind-services.css','admin/01-core.css','admin/04-dashboard-pending.css','admin/08-modern-normalize.css','modern-ui.css']) await page.addStyleTag({content:fs.readFileSync(path.join(rootDir,'public/assets/css',file),'utf8')});
    await page.addScriptTag({url:'https://cdn.jsdelivr.net/npm/sweetalert2@11'});
    await page.addScriptTag({content:`
      var root = document.querySelector('#scm-app'), actions = {contracts_ending_case:'case',contracts_ending_history:'history',contracts_ending_history_manage:'history-manage',contracts_ending_renewal_save:'save'};
      var contractsEndingRowsByPk = {}, contractsEndingData = null, contractsEndingView = 'ending', contractsEndingRequestId = 0, contractsEndingOnlyNoExit = true;
      var ajaxUrl = 'synthetic', actionContractsEndingMonths = 'list', actionContractsEndingCreateRetention='retention', actionContractsEndingImportApply='import';
      var sent = [], opened = [], messages = [], pendingLists = null;
      function escHtml(value) { var el = document.createElement('div'); el.textContent = String(value == null ? '' : value); return el.innerHTML.replace(/"/g,'&quot;'); }
      function formatDashboardCount(n) { return String(n); }
      function showToast(kind,text,title) { messages.push({kind,text,title}); }
      function dashboardAction(action,payload) {
        sent.push({action,payload});
        if (action === 'case') return Promise.resolve({case:{ticket_pk:'77',ticket:'77',case_source_html:'<p>Detalle del caso #77</p>'}});
        if (action === 'history') return Promise.resolve(historyFixture);
        if (pendingLists) return new Promise(resolve => pendingLists.push(resolve));
        return Promise.resolve(fixture);
      }
      function dashboardFormAction(action,fill) { var fd = new FormData(); fill(fd); sent.push({action,payload:Object.fromEntries(fd)}); return Promise.resolve({message:'Guardado simulado'}); }
      function dashboardApplyDueCaseData(button,data) { button.setAttribute('data-ticket-pk',data.ticket_pk); }
      function dashboardDueCaseSourceHtml() { return ''; }
      function elevateDashboardDueCaseModal() {}
      window.scmOpenCase = function(button) { opened.push({ticket:button.getAttribute('data-ticket-pk'),html:button.closest('article').querySelector('.scm-case-source').innerHTML}); };
      var base = {receipt_in_scope:true,receipt_automation_enabled:true,receipt_default_employee_id:'EMP-13',contract_pk:'1',contrato:'193',inmueble:'10200',direccion:'Dirección de prueba',arrendatario:'<img src=x onerror=alert(1)>',propietario:'Propietario de prueba',fin_contrato_label:'20/10/2026',end_ts:1792472400,days_left:15,canon:1000000,probability:80,weighted_value:800000,renewal:{probability:80,no_exit:0,revision:2,reminder_days:'30,7,0'},receipt_due_label:'05/10/2026',receipt_funcionarios:[{id:'EMP-13',name:'Funcionario activo'}],retention_ticket:{enabled:true,default_employee_id:'EMP-13',funcionarios:[{id:'EMP-13',name:'Funcionario activo'}]}};
      var historyFixture={can_manage:false,items:[{id:1,action:'retention_created',actor:'EMP-13',actor_name:'Funcionario de prueba',created_at:1791210875,details_json:JSON.stringify({ticket_id:77}),revision:'synthetic-revision'}]};
      var fixture = {receipt_automation:{enabled:true,contract_id:1},can_write:true,year:2026,month:10,count:3,generated_at:'05/10/2026 14:20',groups:[{label:'Octubre 2026',count:3,items:[base,Object.assign({},base,{receipt_in_scope:false,contract_pk:'2',contrato:'656',probability:100,weighted_value:1000000}),Object.assign({},base,{receipt_in_scope:false,contract_pk:'3',contrato:'888',existing_retention_ticket_id:'77',renewal:{no_exit:1,note:'Reporte confirmado',revision:1}})]}]};
    ` + caseFunction + functions + '\ninitContractsEndingFilters(root.querySelector("[data-scm-contracts-ending-panel]"));renderContractsEnding(fixture);'});
    assert.equal(await page.locator('[data-scm-contracts-ending-create]').count(),1);
    assert.equal(await page.locator('.scm-contracts-ending-main img').count(),0);
    await page.locator('[data-contracts-ending-case="retention"]').click();
    assert.deepEqual(await page.evaluate(() => opened),[{ticket:'77',html:'<p>Detalle del caso #77</p>'}]);
    assert.equal(await page.locator('[data-scm-contracts-ending-panel]').count(),1,'Case popup preserves panel context');
    assert.equal(await page.locator('[data-contracts-ending-configure="probability"]').count(),3);
    assert.equal(await page.locator('[data-contracts-ending-configure="no-exit"]').count(),3);
    assert.match(await page.locator('.scm-contracts-ending-main').first().innerText(),/80 %/);
    await page.screenshot({path:path.join(qaDir,'ending-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:1000});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Main list must not overflow on mobile');
    await page.screenshot({path:path.join(qaDir,'ending-mobile.png'),fullPage:true});
    await page.setViewportSize({width:1400,height:1100});
    await page.locator('[data-contracts-ending-view="renewal"]').click();
    assert.match(await page.locator('[data-scm-contracts-ending-summary]').innerText(), /2.600.000/);
    assert.equal(await page.locator('[data-contracts-ending-view="renewal"]').getAttribute('aria-selected'),'true');
    assert.equal(await page.locator('.scm-contract-renewal-table tbody tr').count(),3);
    assert.equal(await page.locator('[data-contracts-ending-configure]').count(),0,'Probability report has no editing actions');
    assert.equal(await page.locator('[data-scm-contracts-ending-create]').count(),0);
    assert.equal(await page.locator('[data-scm-contracts-ending-import]').isVisible(),false);
    await page.screenshot({path:path.join(qaDir,'renewal-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:1000});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Renewal table must scroll inside its container on mobile');
    await page.screenshot({path:path.join(qaDir,'renewal-mobile.png'),fullPage:true});
    await page.setViewportSize({width:1400,height:1100});
    await page.locator('[data-contracts-ending-view="ending"]').click();
    await page.locator('[data-contracts-ending-configure="probability"][data-contract-pk="3"]').click();
    const popup=page.locator('.swal2-popup');
    assert.equal(await popup.locator('[name="no_exit"]').count(),0);
    await popup.locator('[name="probability"]').fill('95');
    await popup.locator('.swal2-confirm').click();
    await popup.waitFor({state:'hidden'});
    const probabilitySaved=await page.evaluate(()=>sent.find(item=>item.action==='save').payload);
    assert.equal(probabilitySaved.probability,'95');assert.equal(probabilitySaved.no_exit,'1');assert.equal(probabilitySaved.note,'Reporte confirmado');assert.equal(probabilitySaved.revision,'1');
    await page.locator('[data-contracts-ending-configure="no-exit"][data-contract-pk="1"]').click();
    assert.equal(await popup.locator('[name="probability"]').count(),0);
    assert.equal(await popup.locator('[name="no_exit"]').isChecked(),true);
    await popup.locator('.swal2-confirm').click();
    assert.match(await popup.locator('.swal2-validation-message').innerText(), /Describe/);
    await popup.locator('[name="note"]').fill('El arrendatario confirmó que permanecerá.');
    await popup.locator('.swal2-confirm').click();
    await popup.waitFor({state:'hidden'});
    const saved=await page.evaluate(()=>sent.filter(item=>item.action==='save')[1].payload);
    assert.equal(saved.probability,'80'); assert.equal(saved.no_exit,'1'); assert.equal(saved.end_ts,'1792472400'); assert.equal(saved.revision,'2');assert.equal(saved.receipt_employee_id,'');
    // Read-only access keeps values visible while hiding both editing buttons.
    await page.evaluate(()=>{fixture.can_write=false;renderContractsEnding(fixture);});
    assert.equal(await page.locator('[data-contracts-ending-configure]').count(),0);
    assert.match(await page.locator('.scm-contracts-ending-main').first().innerText(),/80 %/);
    await page.evaluate(()=>{fixture.can_write=true;renderContractsEnding(fixture);});
    await page.locator('[data-contracts-ending-view="no-exit"]').click();
    assert.equal(await page.locator('[data-contracts-only-no-exit]').isChecked(),true);
    assert.equal(await page.locator('[data-scm-contracts-ending-create]').count(),0);
    await page.locator('[data-contracts-only-no-exit]').check();
    assert.equal(await page.locator('.scm-contracts-ending-row').count(),1);
    await page.locator('[data-contracts-ending-configure]').click();
    await popup.locator('[name="no_exit"]').uncheck();
    await popup.locator('.swal2-confirm').click();
    assert.match(await popup.locator('.swal2-validation-message').innerText(), /Confirma/);
    await popup.locator('.swal2-cancel').click();
    await page.locator('[data-contracts-ending-view="receipt"]').click();
    assert.equal(await page.locator('.scm-contracts-ending-row').count(),1);
    assert.equal(await page.locator('[data-scm-contracts-ending-create]').count(),0);
    assert.equal(await page.locator('[data-contracts-ending-configure]').count(),0);
    assert.match(await page.locator('[data-scm-contracts-ending-list]').innerText(), /Pendiente/);
    await page.setViewportSize({width:390,height:1000});
    await page.screenshot({path:path.join(qaDir,'receipt-mobile.png'),fullPage:true});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Mobile must not overflow');
    await page.evaluate(()=>{fixture.groups[0].items[0].receipt_in_scope=false;renderContractsEnding(fixture);});
    assert.equal(await page.locator('.scm-contracts-ending-row').count(),0);
    assert.match(await page.locator('[data-scm-contracts-ending-list]').innerText(), /cron no termina en este mes/);
    await page.evaluate(()=>{fixture.groups[0].items[0].receipt_in_scope=true;renderContractsEnding(fixture);});
    await page.locator('[data-contracts-ending-history]').first().click();
    await page.locator('.swal2-popup').waitFor();
    assert.equal(await popup.locator('[data-history-edit]').count(),0,'Read-only viewers cannot edit history');
    await popup.locator('.swal2-confirm').click();
    await page.evaluate(()=>{historyFixture.can_manage=true;});
    await page.locator('[data-contracts-ending-history]').first().click();
    await popup.locator('[data-history-edit]').click();
    await popup.locator('[name="text"]').fill('Nota contractual corregida');
    await popup.locator('.swal2-confirm').click();
    assert.match(await popup.locator('.swal2-validation-message').innerText(),/motivo/);
    await popup.locator('[name="reason"]').fill('Corrección de prueba');
    await popup.locator('.swal2-confirm').click();
    await popup.locator('[data-history-void]').waitFor();
    await popup.locator('[data-history-void]').click();
    await popup.locator('[name="reason"]').fill('Limpiar prueba');
    await popup.locator('.swal2-confirm').click();
    assert.match(await popup.locator('.swal2-validation-message').innerText(),/Confirma/);
    await popup.locator('[name="confirm"]').check();
    await popup.locator('.swal2-confirm').click();
    await popup.locator('[data-history-void]').waitFor();
    const corrections=await page.evaluate(()=>sent.filter(item=>item.action==='history-manage'));
    assert.equal(corrections.length,2);assert.equal(corrections[0].payload.text,'Nota contractual corregida');assert.equal(corrections[1].payload.confirm,'1');
    await popup.locator('.swal2-confirm').click();
    // All preview pages must be accessible even though the modal displays 80 at once.
    await page.evaluate(()=>openContractsEndingImportPreview({filename:'synthetic.xlsx',expires:123,token:'test',stats:{total:81,changes:81},changes:Array.from({length:81},(_,i)=>({contract_id:String(i)})),rows:Array.from({length:81},(_,i)=>({line:String(i+1),status:'change',contrato_excel:String(i+1),new_fin_label:'01/12/2026'}))}));
    assert.equal(await popup.locator('tbody tr').count(),80);
    await popup.locator('[data-import-page="1"]').click();
    assert.equal(await popup.locator('tbody tr').count(),1);
    assert.equal(await popup.locator('tbody tr td').first().innerText(),'81');
    await popup.locator('.swal2-cancel').click();
    // A late response from an old month cannot overwrite the newest result.
    await page.evaluate(()=>{pendingLists=[];loadContractsEnding(true);loadContractsEnding(true);pendingLists[1](Object.assign({},fixture,{generated_at:'NEW'}));});
    await page.waitForFunction(()=>document.querySelector('[data-scm-contracts-ending-status]').textContent.includes('NEW'));
    await page.evaluate(()=>pendingLists[0](Object.assign({},fixture,{generated_at:'OLD'})));
    assert.match(await page.locator('[data-scm-contracts-ending-status]').innerText(), /NEW/);
    await page.evaluate(async()=>{dashboardAction=()=>Promise.reject(new Error('Consulta no disponible'));await loadContractsEnding(true);});
    assert.equal(await page.evaluate(()=>messages[messages.length-1].title),'No se pudo cargar el listado');
    assert.deepEqual(errors,[]);
    console.log('PASS: four subtabs, weighted amounts, 100% suppression, existing-case popup, saves and retirement validation, escaping, all Excel preview pages, request ordering and mobile layout. QA: '+qaDir);
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
