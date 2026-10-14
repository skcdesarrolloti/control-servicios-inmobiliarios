const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync('public/assets/js/admin-dashboard-runtime.js','utf8');
const extract = (start,end) => source.slice(source.indexOf(start),source.indexOf(end,source.indexOf(start)));
const functions = extract('    function updateAdministrativeStatusOptions(', '    function applyBinaryFilterKpiVisibility(') + extract('    function saveQuoteFinancing(', '    function openCotizacionActaFromCard(');
(async()=>{
 const browser = await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try {
  const page=await browser.newPage();
  await page.setContent('<div id="root"><form><select name="scm_estado_admin"><option>Estado ajeno</option></select></form><article class="scm-cotizacion-card" data-cotizacion-id="573"><select data-scm-quote-financing><option value="">Sin definir</option><option value="Si">Sí</option><option value="No">No</option></select><button data-scm-save-quote-financing>Guardar financiamiento</button><span role="status" data-scm-quote-financing-status></span></article></div>');
  await page.addScriptTag({content:`const root=document.querySelector('#root'),nonce='test',ajaxUrl='/api.php',cotizacionCardCache={573:{stale:true}};window.requests=[];window.refreshes=0;root.addEventListener('scm:refresh-active-tab',()=>refreshes++);window.fail=false;window.fetch=async(url,options)=>{requests.push(Object.fromEntries(options.body));return {json:async()=>fail?{success:false,data:{message:'Sin permiso'}}:{success:true,data:{financiacion:'Si'}}};};`+functions});
  await page.evaluate(()=>updateAdministrativeStatusOptions(document.querySelector('form'),['Por inspeccionar','En ejecución']));
  assert.deepEqual(await page.locator('form option').allTextContents(),['Todos','Por inspeccionar','En ejecución']);
  await page.locator('form select').selectOption('Por inspeccionar');
  await page.evaluate(()=>updateAdministrativeStatusOptions(document.querySelector('form'),['Por inspeccionar']));
  assert.equal(await page.locator('form select').inputValue(),'Por inspeccionar');
  await page.locator('[data-scm-quote-financing]').selectOption('Si');
  await page.evaluate(()=>saveQuoteFinancing(document.querySelector('[data-scm-save-quote-financing]')));
  assert.deepEqual(await page.evaluate(()=>requests[0]),{action:'scm_cotizacion_financing',nonce:'test',id_cotizacion:'573',financiacion:'Si'});
  assert.equal(await page.locator('[role=status]').textContent(),'Financiamiento guardado.');
  assert.equal(await page.evaluate(()=>refreshes),1);
  assert.equal(await page.evaluate(()=>cotizacionCardCache[573]),undefined);
  await page.evaluate(()=>{fail=true;return saveQuoteFinancing(document.querySelector('[data-scm-save-quote-financing]'));});
  assert.equal(await page.locator('[role=status]').textContent(),'Sin permiso');
  assert.equal(await page.locator('button').isEnabled(),true);
  assert.equal(await page.locator('[data-scm-quote-financing]').isEnabled(),true);
  console.log('Financing UI checks passed: scoped options, exact quotation submission, confirmation, cache refresh, denied action recovery.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
