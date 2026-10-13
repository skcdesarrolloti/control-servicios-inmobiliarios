const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const source=fs.readFileSync(path.join(__dirname,'../public/assets/js/admin-dashboard-runtime.js'),'utf8');
const slice=(start,end,offset=0)=>{const a=source.indexOf(start,offset),b=source.indexOf(end,a);assert(a>=0&&b>a);return source.slice(a,b);};
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page=await browser.newPage({viewport:{width:1100,height:900}}),errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    const html=execFileSync('php',[path.join(__dirname,'my-tickets-status-check.php'),'--html'],{encoding:'utf8'});
    await page.setContent('<main id="scm-app" class="scm-wrap scm-daisy" data-theme="scm-daisy"><button class="scm-tab" data-tab="scm-panel-mis-tickets">Mis casos</button><section class="scm-tab-panel active" id="scm-panel-mis-tickets">'+html+'</section></main>');
    for(const file of ['tailwind-admin.css','modern-ui.css','admin/01-core.css','admin/08-modern-normalize.css'])await page.addStyleTag({content:fs.readFileSync(path.join(__dirname,'../public/assets/css',file),'utf8')});
    await page.addScriptTag({content:`
      var root=document.querySelector('#scm-app'),ajaxUrl='/api',nonce='qa',config={},tabFetchers={};
      window.requests=[];window.held=[];window.hold=false;window.opened=[];
      function setListLoading(el,busy){el.dataset.busy=String(busy);}
      function updateGenericKPI(key,name,value){var el=document.getElementById('scm-'+key+'-kpi-'+name);if(el)el.textContent=value;}
      function applyRevisionKpiVisibility(){} function applyBinaryFilterKpiVisibility(){} function applyCotizacionDependentFilters(){}
      window.fetch=async function(url,options){var data=Object.fromEntries(options.body),bucket=data.scm_my_bucket;requests.push(data);var json={success:true,data:{cards:'<p>'+bucket+'</p>',count:bucket==='active'?'15':bucket==='postergados'?'2':'3',kpi_total:bucket==='active'?'15':bucket==='postergados'?'2':'3',pagination:'<button class="scm-page-btn-generic" data-page="2">2</button>'}};if(hold)return new Promise(resolve=>held.push(()=>resolve({json:async()=>json})));return {json:async()=>json};};
      ${slice('    function makeTabFetcher(', '    function makeStatusFetcher(')}
      tabFetchers.mis_tickets=makeTabFetcher('mis_tickets','my-cases');
      ${slice('    function selectMyTicketsBucket(', '    function cssAttrValue(')}
      function loadActiveLazyPanelWithFeedback(){return tabFetchers.mis_tickets.fetchTab(new FormData(tabFetchers.mis_tickets.form));}
      ${slice('    root.querySelectorAll(".scm-tab[data-tab]")', '    root.addEventListener("click", function (event)')}
      function dashboardAction(action,data){if(window.rejectCase)return Promise.reject(new Error('No permission'));window.nativeCaseId=data.case_id;return Promise.resolve({case:{ticket_pk:data.case_id,case_source_html:'<p>Detalle</p>'}});}
      function dashboardApplyDueCaseData(button,data){button.dataset.ticketPk=data.ticket_pk;}
      function openDashboardDueCaseFromButton(button){opened.push(button.dataset.ticketPk);}
      function showToast(){}
      ${slice('    function openPanelCaseById(', '    root.addEventListener("scm:open-panel-case"')}
      ${slice('    function cssAttrValue(', '    // Seguimiento form',source.indexOf('    function selectMyTicketsBucket('))}
      loadActiveLazyPanelWithFeedback();
    `});
    const tabs=page.locator('[data-my-tickets-status]');
    assert.deepEqual(errors,[]);
    await page.waitForFunction(()=>requests.length===1);
    assert.equal(await page.evaluate(()=>requests[0].scm_my_bucket),'active');
    assert.equal(await page.locator('[aria-selected=true][data-my-tickets-status]').textContent(),'Abiertos');
    await page.locator('[data-my-tickets-status=postergados]').click();
    await page.waitForFunction(()=>document.querySelector('#scm-cards-mis_tickets').textContent==='postergados');
    assert.equal(await page.locator('[data-my-tickets-total]').textContent(),'2');
    assert.equal(await page.locator('#scm-mis_tickets-kpi-total').textContent(),'2');
    assert.equal(await page.locator('#scm_my_estado_bloqueado').inputValue(),'Mis casos · Postergados');
    await page.locator('.scm-page-btn-generic').click();
    await page.waitForFunction(()=>requests.at(-1).scm_my_page==='2');
    assert.equal(await page.evaluate(()=>requests.at(-1).scm_my_bucket),'postergados');
    await page.locator('#scm-clear-mis_tickets').click();
    await page.waitForFunction(()=>requests.at(-1).scm_my_page==='1');
    assert.equal(await page.locator('#scm_my_estado_bloqueado').inputValue(),'Mis casos · Postergados','clearing filters retains status label');
    await page.locator('[data-my-tickets-status=cerrados]').click();
    await page.waitForFunction(()=>document.querySelector('#scm-cards-mis_tickets').textContent==='cerrados');
    assert.equal(await page.evaluate(()=>requests.at(-1).scm_my_page),'1');
    await page.locator('[data-tab=scm-panel-mis-tickets]').click();
    await page.waitForFunction(()=>document.querySelector('#scm-cards-mis_tickets').textContent==='active');
    assert.equal(await page.locator('[aria-selected=true][data-my-tickets-status]').textContent(),'Abiertos','entering My cases resets to open');
    await page.locator('[data-my-tickets-status=active]').focus();await page.keyboard.press('End');
    await page.waitForFunction(()=>document.querySelector('#scm-cards-mis_tickets').textContent==='cerrados');
    assert.equal(await page.locator('[data-my-tickets-status=cerrados]').evaluate(el=>el===document.activeElement),true);
    await page.evaluate(()=>{hold=true;});
    await page.locator('[data-my-tickets-status=postergados]').click();await page.locator('[data-my-tickets-status=active]').click();
    await page.waitForFunction(()=>held.length===2);await page.evaluate(()=>held[1]());
    await page.waitForFunction(()=>document.querySelector('#scm-cards-mis_tickets').textContent==='active');
    await page.evaluate(()=>held[0]());await page.waitForFunction(()=>document.querySelector('#scm-cards-mis_tickets').dataset.busy==='false');
    assert.equal(await page.locator('#scm-cards-mis_tickets').textContent(),'active','older response cannot overwrite newly selected status');
    assert.equal(await page.evaluate(()=>document.querySelector('#scm-my-tickets-results').getAttribute('aria-labelledby')),'scm-my-state-active');
    await page.evaluate(async()=>{await scmOpenOperationalNotificationCase({isMine:true,ticketPk:'31',logicalId:'999931'});});
    assert.equal(await page.evaluate(()=>nativeCaseId),'31','notification opens native case by PK across status tabs');
    assert.deepEqual(await page.evaluate(()=>opened),['31']);
    await page.evaluate(async()=>{rejectCase=true;window.rejected=false;try{await scmOpenOperationalNotificationCase({isMine:true,ticketPk:'33'});}catch(e){window.rejected=true;}});
    assert(await page.evaluate(()=>rejected),'native case permission errors remain visible to notification caller');
    for(const width of [390,1100]){
      await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'tabs fit '+width+'px');
      fs.mkdirSync(path.join(__dirname,'../output/my-tickets'),{recursive:true});await page.screenshot({path:path.join(__dirname,'../output/my-tickets/status-'+width+'.png'),fullPage:true});
    }
    assert.equal(await tabs.count(),3);assert.deepEqual(errors,[]);
    console.log('PASS: initial/re-entry open status, tabs, KPIs, pagination, filters, keyboard, stale requests, notification popup and responsive layout. No network used.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
