const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const project = path.join(__dirname,'..');
const runtime = fs.readFileSync(path.join(project,'public/assets/js/admin-dashboard-runtime.js'),'utf8');
const core = fs.readFileSync(path.join(project,'public/assets/js/scm-admin.js'),'utf8');
const escape = core.slice(core.indexOf('  function escHtml(value)'),core.indexOf('  function scmNotify('));
const start = runtime.indexOf('    function openContractReceiptSettings()');
const editor = runtime.slice(start,runtime.indexOf('    function openContractsEndingManagement(',start));
const classStart = runtime.indexOf('    function contractsEndingDialogClasses(');
const classes = runtime.slice(classStart,runtime.indexOf('\n    function ',classStart+10));
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:1000}});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setContent('<main id="scm-app">Contratos → Contratos por terminar → Recibo automático</main>');
    for(const file of ['tailwind-admin.css','admin/01-core.css','admin/04-dashboard-pending.css','admin/08-modern-normalize.css','modern-ui.css']) await page.addStyleTag({content:fs.readFileSync(path.join(project,'public/assets/css',file),'utf8')});
    await page.addScriptTag({url:'https://cdn.jsdelivr.net/npm/sweetalert2@11'});
    await page.addScriptTag({content:`
      var root=document.querySelector('main'),contractsEndingData={can_configure_receipts:true};
      var actions={contract_receipt_settings_read:'receipt-read',contract_receipt_settings_save:'receipt-save'};
      var calls=[],saved=null,refreshes=0;
      function dashboardAction(action,payload){calls.push(action);return Promise.resolve({config:{enabled:false,contract_id:525,employee_id:'',coordinator_id:''},employees:[{id:'EMP-13',name:'Funcionario activo <script>QA</script>'}]});}
      function dashboardFormAction(action,fill){calls.push(action);var fd=new FormData();fill(fd);saved=Object.fromEntries(fd);return Promise.resolve({message:'Guardado'});}
      function loadContractsEnding(){refreshes++;} function showToast(){}
      ${escape} ${classes} ${editor}
    `});
    await page.evaluate(()=>{openContractReceiptSettings();});
    await page.locator('[name=receipt_contract_id]').waitFor();
    assert.equal(await page.locator('[name=receipt_contract_id]').inputValue(),'525');
    assert.equal(await page.locator('form script').count(),0,'staff names are escaped');
    await page.locator('[name=receipt_enabled]').check();
    await page.locator('.swal2-confirm').click();
    assert.match(await page.locator('.swal2-validation-message').innerText(),/Selecciona el funcionario/);
    assert.equal(await page.evaluate(()=>saved),null,'incomplete activation cannot submit');
    await page.locator('[name=receipt_employee_id]').selectOption('EMP-13');
    await page.locator('[name=receipt_coordinator_id]').selectOption('EMP-13');
    await page.evaluate(()=>Swal.resetValidationMessage());
    const output=path.join(project,'output/panel-case');fs.mkdirSync(output,{recursive:true});
    for(const width of [1440,390]) {
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'configuration fits viewport');
      await page.screenshot({path:path.join(output,'receipt-settings-'+width+'.png')});
    }
    await page.locator('.swal2-confirm').click();
    await page.waitForFunction(()=>refreshes===1);
    const saved=await page.evaluate(()=>window.saved);
    assert.deepEqual(JSON.parse(saved.receipt_automation),{enabled:true,contract_id:'525',employee_id:'EMP-13',coordinator_id:'EMP-13'});
    assert.equal(saved.settings,undefined,'contract automation never submits notification recipients');
    assert.deepEqual(await page.evaluate(()=>calls),['receipt-read','receipt-save']);
    await page.evaluate(async()=>{contractsEndingData.can_configure_receipts=false;await openContractReceiptSettings();});
    assert.deepEqual(await page.evaluate(()=>calls),['receipt-read','receipt-save'],'configuration requires permission');
    assert.deepEqual(errors,[]);
    console.log('PASS: receipt configuration opens independently, validates activation, saves contract and staff, refreshes Contracts and fits mobile/desktop.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
