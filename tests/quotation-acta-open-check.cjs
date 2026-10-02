const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const source=fs.readFileSync('public/assets/js/admin-dashboard-runtime.js','utf8');
const open=source.slice(source.indexOf('    function openCotizacionActaFromCard('),source.indexOf('    function openCotizacionNativeModal('));
const core=fs.readFileSync('public/assets/js/scm-admin.js','utf8');
const findRoot=core.slice(core.indexOf('  function findRootFromNode('),core.indexOf('  function getCaseModal('));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try{
 const page=await browser.newPage();
 await page.setContent('<div id="scm-app" class="scm-wrap" data-scm-runtime="{}"><div id="scm-case-modal"></div></div><div id="popup"></div>');
 await page.addScriptTag({content:findRoot+`
 const root=document.querySelector('#scm-app');window.errors=[];window.opened=[];window.closeCount=0;window.returned=0;
 function showToast(type,text){errors.push(text);}
 function loadCotizacionCardById(){return Promise.resolve(window.detached);}
 window.Swal={isVisible:()=>true,close:()=>{closeCount++;document.querySelector('#popup').replaceChildren();}};
 window.scmOpenCase=function(btn){const app=findRootFromNode(btn);if(!app)return;const modal=app.querySelector('#scm-case-modal');modal._scmCurrentCaseButton=btn;modal.classList.add('open');modal.innerHTML='<button data-scm-cotizacion-acta-button>Acta</button>';modal.querySelector('button').onclick=()=>{if(!findRootFromNode(btn))throw Error('Detached trigger');opened.push(btn.dataset.cotizacionId);window.returnAction=btn._scmActaOnClose;};};
 function card(id){const node=document.createElement('article');node.className='scm-cotizacion-card';node.dataset.cotizacionId=id;node.innerHTML='<button data-scm-manage-cotizacion-acta data-cotizacion-id="'+id+'">Gestionar</button><template class="scm-cotizacion-linked-ticket-source"><div class="scm-ticket-card"><button class="scm-btn-case"></button><div class="scm-case-source">Case</div></div></template>';return node;}
 `+open});
 await page.evaluate(()=>{const c=card('570');document.querySelector('#popup').appendChild(c);return openCotizacionActaFromCard(c.querySelector('button'),{closeCurrentSwal:true,onClose:()=>returned++});});
 assert.deepEqual(await page.evaluate(()=>opened),['570']);
 assert.equal(await page.evaluate(()=>closeCount),1);
 assert.equal(await page.locator('[data-scm-quote-acta-proxy]').count(),1);
 await page.evaluate(()=>returnAction());assert.equal(await page.locator('[data-scm-quote-acta-proxy]').count(),0);assert.equal(await page.evaluate(()=>returned),1);
 await page.evaluate(()=>{window.detached=card('571');const button=document.createElement('button');button.dataset.cotizacionId='571';return openCotizacionActaFromCard(button);});
 assert.deepEqual(await page.evaluate(()=>opened),['570','571']);
 await page.evaluate(()=>returnAction());
 await page.evaluate(()=>{const c=card('572');root.appendChild(c);return openCotizacionActaFromCard(c);});
 assert.deepEqual(await page.evaluate(()=>opened),['570','571','572']);
 await page.evaluate(()=>{window.scmOpenCase=()=>{};return openCotizacionActaFromCard(card('573'));});
 assert.deepEqual(await page.evaluate(()=>opened),['570','571','572']);
 assert.equal(await page.evaluate(()=>errors.length),1);
 assert.equal(await page.locator('[data-scm-quote-acta-proxy]').count(),0);
 console.log('Quotation act opening checks passed: popup, cached card, panel, return context, stale case rejected.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

