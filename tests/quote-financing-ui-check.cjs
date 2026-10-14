const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync('public/assets/js/admin-dashboard-runtime.js','utf8');
const extract = (start,end) => source.slice(source.indexOf(start),source.indexOf(end,source.indexOf(start)));
const functions = extract('    function syncCaseFinancingFilter(', '    function applyBinaryFilterKpiVisibility(') + extract('    function saveQuoteFinancing(', '    function openCotizacionActaFromCard(');
(async()=>{
 const browser = await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try {
  const page=await browser.newPage();
  await page.setContent('<div id="root"><form><select name="scm_estado_admin"><option>Estado ajeno</option></select></form><article class="scm-cotizacion-card" data-cotizacion-id="573"><button data-scm-save-quote-financing data-financiacion="">Financiamiento · Sin definir</button></article></div>');
  await page.addScriptTag({content:`
   const root=document.querySelector('#root'),nonce='test',ajaxUrl='/api.php',cotizacionCardCache={573:{stale:true}};
   window.requests=[];window.refreshes=0;window.returns=0;window.toasts=[];window.validation='';
   function showToast(type,message){toasts.push({type,message});}
   root.addEventListener('scm:refresh-active-tab',()=>refreshes++);
   window.fail=false;
   window.fetch=async(url,options)=>{requests.push(Object.fromEntries(options.body));return {json:async()=>fail?{success:false,data:{message:'Sin permiso'}}:{success:true,data:{financiacion:'Si'}}};};
   window.Swal={
    fire:function(options){window.popupOptions=options;var host=document.querySelector('#popup');if(!host){host=document.createElement('div');host.id='popup';document.body.append(host);}host.innerHTML=options.html;options.didOpen();return new Promise(resolve=>{window.finishPopup=resolve;});},
    getPopup:()=>document.querySelector('#popup'),showValidationMessage:text=>validation=text,isLoading:()=>false
   };
  `+functions});
  await page.evaluate(()=>document.querySelector('form').insertAdjacentHTML('beforeend','<select name="scm_cotizacion"><option value="">Todas</option><option value="has">Con cotización</option><option value="none">Sin cotización</option></select><div data-scm-financing-filter hidden><select name="scm_financiacion" disabled><option value="">Todas</option><option value="Si">Sí</option></select></div>'));
  assert.equal(await page.locator('[name=scm_financiacion]').isVisible(),false);
  await page.locator('[name=scm_cotizacion]').selectOption('has');
  assert.equal(await page.locator('[name=scm_financiacion]').isVisible(),true);
  assert.equal(await page.locator('[name=scm_financiacion]').isEnabled(),true);
  await page.locator('[name=scm_financiacion]').selectOption('Si');
  await page.locator('[name=scm_cotizacion]').selectOption('none');
  assert.equal(await page.locator('[name=scm_financiacion]').inputValue(),'');
  assert.equal(await page.evaluate(()=>new FormData(document.querySelector('form')).has('scm_financiacion')),false);
  await page.locator('[name=scm_cotizacion]').selectOption('has');
  await page.locator('[name=scm_financiacion]').selectOption('Si');
  await page.evaluate(()=>document.querySelector('form').reset());
  assert.equal(await page.locator('[name=scm_financiacion]').isVisible(),false);
  await page.evaluate(()=>updateAdministrativeStatusOptions(document.querySelector('form'),['Por inspeccionar','En ejecución']));
  assert.deepEqual(await page.locator('[name=scm_estado_admin] option').allTextContents(),['Todos','Por inspeccionar','En ejecución']);
  await page.locator('[name=scm_estado_admin]').selectOption('Por inspeccionar');
  await page.evaluate(()=>updateAdministrativeStatusOptions(document.querySelector('form'),['Por inspeccionar']));
  assert.equal(await page.locator('[name=scm_estado_admin]').inputValue(),'Por inspeccionar');
  assert.equal(await page.locator('.scm-cotizacion-card select').count(),0);
  await page.evaluate(()=>{window.opening=saveQuoteFinancing(document.querySelector('[data-scm-save-quote-financing]'),{onClose:()=>returns++});});
  assert.equal(await page.evaluate(()=>popupOptions.title),'Financiamiento de cotización #573');
  assert.equal(await page.evaluate(()=>popupOptions.customClass.popup),'scm-cotizacion-dialog scm-cotizacion-response-swal');
  assert.equal(await page.evaluate(()=>popupOptions.preConfirm()),false);
  assert.equal(await page.evaluate(()=>requests.length),0);
  await page.locator('[data-scm-quote-financing]').selectOption('Si');
  const saved=await page.evaluate(()=>popupOptions.preConfirm());
  assert.equal(saved,'Si');
  await page.evaluate(()=>{finishPopup({isConfirmed:true,value:'Si'});return opening;});
  assert.deepEqual(await page.evaluate(()=>requests[0]),{action:'scm_cotizacion_financing',nonce:'test',id_cotizacion:'573',financiacion:'Si'});
  assert.equal(await page.locator('[data-scm-save-quote-financing]').textContent(),'Financiamiento · Sí');
  assert.equal(await page.evaluate(()=>refreshes),1);
  assert.equal(await page.evaluate(()=>returns),1);
  assert.equal(await page.evaluate(()=>cotizacionCardCache[573]),undefined);
  await page.evaluate(()=>{fail=true;window.opening=saveQuoteFinancing(document.querySelector('[data-scm-save-quote-financing]'),{onClose:()=>returns++});});
  assert.equal(await page.locator('[data-scm-quote-financing]').inputValue(),'Si');
  await page.locator('[data-scm-quote-financing]').selectOption('No');
  assert.equal(await page.evaluate(()=>popupOptions.preConfirm()),false);
  assert.equal(await page.evaluate(()=>validation),'Sin permiso');
  assert.equal(await page.locator('[data-scm-quote-financing]').inputValue(),'No');
  await page.evaluate(()=>{finishPopup({isConfirmed:false});return opening;});
  assert.equal(await page.evaluate(()=>returns),2);
  assert.equal(await page.evaluate(()=>refreshes),1);
  assert.equal(await page.locator('[data-scm-save-quote-financing]').getAttribute('data-financiacion'),'Si');
  console.log('Financing UI checks passed: scoped options, exact quotation submission, popup validation, return context, cache refresh, denied action recovery.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
