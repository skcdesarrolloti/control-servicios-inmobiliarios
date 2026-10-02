const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const shared=fs.readFileSync('public/assets/js/ticket-completion-form-state.js','utf8');
const corrective=fs.readFileSync('public/assets/js/scm-admin.js','utf8');
const quote=fs.readFileSync('public/assets/js/admin-dashboard-runtime.js','utf8');
const slice=(source,start,end)=>source.slice(source.indexOf(start),source.indexOf(end,source.indexOf(start)));
const correctiveAdapter=slice(corrective,'        function correctiveDraftKey()','        var correctiveDraftTimer = null;');
const quoteAdapter=slice(quote,'    function maintenanceQuoteDraftPayload(','    function quotePerturbationLevel(');
const quoteOffers=slice(quote,'    function maintenanceQuoteGeneratedMaterialOffers(','    function maintenanceQuoteGeneratedOfferByKey(');
let checks=0;
function check(value,label){assert(value,label);checks++;console.log('PASS: '+label);}
function fixture(kind,user='1',ticket='10841',record='9',revision='v1'){
 const row=i=>`<fieldset data-corrective-item><textarea name="items[${i}][damage]">Daño ${i}</textarea><input type="file" multiple data-corrective-photos name="corrective_review_photos_${i}[]"><div data-corrective-photo-preview><figure data-corrective-existing-photo><input type="hidden" name="items[${i}][existing_fotos][]" value="photo-${i}.jpg"></figure></div></fieldset>`;
 const markup=kind==='corrective'?`<form data-corrective-review-edit data-corrective-review-version="${revision}"><input name="review_id" value="${record}"><div data-corrective-review-items>${row(0)}${row(1)}</div></form>`:`<form><input name="destinatario"><input name="materiales_oferta_image"><input type="file" name="mejor_oferta[]" multiple><input type="file" name="otras_oferta[]" multiple><input data-material-support-provider><div data-quote-repeater="materiales" data-rows="[]"><div class="scm-maint-quote-rows"></div></div><div data-quote-repeater="materiales_soporte" data-rows="[]"><div class="scm-maint-quote-rows"></div></div></form>`;
 const setup=kind==='corrective'?`
 const caseBtn={dataset:{ticketPk:'${ticket}'}};
 function filesOf(i){return i._scmFiles||Array.from(i.files);}
 function syncInput(i,files){const dt=new DataTransfer();files.forEach(f=>dt.items.add(f));i.files=dt.files;i._scmFiles=files;}
 function preview(i){i.dataset.previewCount=i.files.length;}
 function syncCorrectiveAreaFields(){} function message(){} function appendCorrectiveItem(){throw Error('unexpected extra row');}
 ${correctiveAdapter}
 window.state=ScmPanelDrafts.bind(form,correctiveDraftKey(),{revision:'${revision}',snapshot:correctiveDraftPayload,restore:restoreCorrectiveDraft});
 `:`
 const context={cotizacion:{id:'${record}',version:'${revision}'},unit_options:[]};
 function syncMaintenanceQuotePerturbation(){} function restoreMaintenanceQuotePerturbationSelections(){} function refreshMaterialAutoItems(){} function renderMaintenanceQuoteGeneratedMaterialOffers(){} function syncMaintenanceQuoteTotals(){}
 function parseCotizacionOrderMoney(v){return Number(v)||0;}
 function collectQuoteRows(f,type){const section=f.querySelector('[data-quote-repeater="'+type+'"]');return section?JSON.parse(section.dataset.rows):[];}
 function renderCotizacionRepeaterRows(type,items){return '<div class="scm-maint-quote-rows"><input data-restored-rows value="'+encodeURIComponent(JSON.stringify(items))+'"></div>';}
 ${quoteOffers}
 ${quoteAdapter}
 wireMaintenanceQuoteDraft(form,context,'quote:'+ScmPanelDrafts.user()+':${ticket}:${record}');window.state=form._scmQuoteDraft;
 `;
 return `<script data-scm-draft-user="${user}"></script>${markup}<script>${shared}</script><script>const form=document.querySelector('form');${setup}</script>`;
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.EDGE_PATH||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try{
  const page=await browser.newPage();let html;
  await page.route('http://draft.test/**',r=>r.fulfill({contentType:'text/html',body:html}));
  async function open(){await page.goto('http://draft.test/form');await page.evaluate(()=>state.ready);}
  const photo={name:'original.png',mimeType:'image/png',buffer:Buffer.from('real binary photo bytes')};
  html=fixture('corrective');await open();
  await page.locator('textarea').last().fill('Daño pendiente');
  await page.locator('[data-corrective-photos]').last().setInputFiles(photo);
  await page.evaluate(()=>{document.querySelector('[data-corrective-item]').remove();return state.save();});await open();
  check(await page.locator('textarea').inputValue()==='Daño pendiente','corrective damage rows survive reload and removal');
  check(await page.locator('[data-corrective-existing-photo] input').inputValue()==='photo-1.jpg','retained evidence follows remaining damage rather than removed row');
  check(await page.evaluate(()=>{const f=document.querySelector('[data-corrective-photos]').files[0];return f.name==='original.png'&&f.size===23;}),'corrective photo binary and filename restored for submission');
  html=fixture('corrective','2');await open();check(await page.locator('textarea').count()===2,'corrective draft isolated by employee');
  html=fixture('corrective','1','10842');await open();check(await page.locator('textarea').count()===2,'corrective draft isolated by case');
  html=fixture('corrective','1','10841','10');await open();check(await page.locator('textarea').count()===2,'corrective draft isolated by review');
  html=fixture('corrective','1','10841','9','v2');await open();check(await page.locator('textarea').count()===2,'updated review rejects stale draft');
  html=fixture('quote');await open();
  await page.locator('[name=destinatario]').fill('Destinatario pendiente');
  await page.locator('[name="mejor_oferta[]"]').setInputFiles(photo);await page.locator('[name="otras_oferta[]"]').setInputFiles({...photo,name:'otra.png'});
  await page.evaluate(()=>{
   document.querySelector('[data-quote-repeater="materiales"]').dataset.rows=JSON.stringify([{proveedor:'Almacén',valor_total:100000}]);
   document.querySelector('[data-quote-repeater="materiales_soporte"]').dataset.rows=JSON.stringify([{descripcion:'Pintura',cantidad:2,valor:50000}]);
   setMaintenanceQuoteGeneratedMaterialOffers(form,[{key:'offer1',provider:'Almacén',total:100000,image:'data:image/png;base64,AAAA'}]);
   return state.save();
  });await open();
  check(await page.locator('[name=destinatario]').inputValue()==='Destinatario pendiente','quotation text recovered');
  check(await page.evaluate(()=>document.querySelector('[name="mejor_oferta[]"]').files[0].size===23&&document.querySelector('[name="otras_oferta[]"]').files[0].name==='otra.png'),'both quotation attachment groups survive reload');
  check(await page.evaluate(()=>maintenanceQuoteGeneratedMaterialOffers(form)[0].provider==='Almacén'&&maintenanceQuoteGeneratedMaterialOffers(form)[0].total===100000),'generated offer image, provider and total recovered');
  check(await page.locator('[data-quote-repeater="materiales"] [data-restored-rows]').inputValue()===encodeURIComponent(JSON.stringify([{proveedor:'Almacén',valor_total:100000}])),'material cost rows recovered');
  check((await page.locator('[data-quote-repeater="materiales_soporte"] [data-restored-rows]').inputValue()).includes('Pintura'),'material offer builder rows recovered');
  html=fixture('quote','1','10842');await open();check(await page.locator('[name=destinatario]').inputValue()==='','quotation isolated by case');
  html=fixture('quote','2');await open();check(await page.locator('[name=destinatario]').inputValue()==='','quotation isolated by employee');
  html=fixture('quote');await open();await page.evaluate(()=>{state.save();return state.clear();});await open();
  check(await page.locator('[name=destinatario]').inputValue()===''&&await page.evaluate(()=>document.querySelector('input[type=file]').files.length===0),'successful save clears quotation draft without late write resurrection');
  console.log(`${checks} checks passed.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

