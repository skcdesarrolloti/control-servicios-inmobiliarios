const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-admin.js'), 'utf8');
const start = source.indexOf('  function initCaseComposer(');
const end = source.indexOf('  window.scmCloseCase =', start);
const markupStart = source.indexOf('        var composerHtml =');
const markupEnd = source.indexOf('        var timelineHeaderHtml =', markupStart);
assert(start > 0 && end > start && markupEnd > markupStart);

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 1100, height: 850}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<main id="scm-app"><section id="case-modal"></section><button id="case" data-ticket-pk="41" data-arrendatario="Cliente de ejemplo"></button></main>');
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/modern-ui.css'), 'utf8')});
    await page.addStyleTag({content: '#scm-app{max-width:1000px;margin:auto;padding:12px;box-sizing:border-box} .material-symbols-outlined{display:none!important}'});
    await page.addScriptTag({content: `
      var btn=document.querySelector('#case'),isPublicPqr=false,statusBucket='';
      function isPreventivaCase(){return false;} function caseHasCotizacion(){return false;}
      function renderComposerResponseOptions(){return '<div data-scm-composer-response-options><select data-scm-composer-admin-state><option value="__keep__">Sin cambio</option></select><input type="checkbox" data-scm-composer-close-ticket></div>';}
      function findRootFromNode(){return document.querySelector('#scm-app');}
      function parseRuntime(){return {ajaxUrl:'/api.php',nonce:'qa',actions:{ticket_response:'reply',seg:'followup',nota:'note'}};}
      window.submissions=[];window.notices=[];window.failSave=false;
      function scmNotify(type,message){notices.push({type,message});}
      window.fetch=async function(url,options){submissions.push(Array.from(options.body.entries()));return {json:async()=>({success:!failSave,data:{message:failSave?'Error de prueba':'Guardado'}})};};
      ${source.slice(markupStart, markupEnd)}
      document.querySelector('#case-modal').innerHTML=composerHtml;
      ${source.slice(start, end)}
      initCaseComposer(document.querySelector('#case-modal'),btn);
    `});
    const extra = page.locator('[data-scm-composer-additional-emails]');
    const response = page.locator('[data-scm-composer-input]');
    const submit = page.locator('[data-scm-composer-submit]');
    const admin = page.locator('[name="composer_notify[]"][value=admin]');
    const none = page.locator('[name="composer_notify[]"][value=none]');
    assert(await admin.isChecked(), 'administration defaults on in initial reply');
    await extra.fill('extra@example.invalid; second@example.invalid');
    await response.fill('Respuesta de ejemplo para todos los destinatarios seleccionados.');
    await page.evaluate(() => { failSave=true; });
    await submit.click();await page.waitForFunction(() => submissions.length===1 && !document.querySelector('[data-scm-composer-submit]').disabled);
    let fields = await page.evaluate(() => submissions[0]);
    assert.deepEqual(fields.filter(([key])=>key==='notify_recipients[]').map(([,value])=>value),['arrendatario','admin']);
    assert(fields.some(([key,value])=>key==='additional_emails' && value.includes('second@example.invalid')));
    assert.equal(await extra.inputValue(),'extra@example.invalid; second@example.invalid','failed save preserves extra copies');
    await none.check();assert(await extra.isDisabled());await submit.click();
    await page.waitForFunction(() => submissions.length===2 && !document.querySelector('[data-scm-composer-submit]').disabled);
    fields=await page.evaluate(() => submissions[1]);
    assert.deepEqual(fields.filter(([key])=>key==='notify_recipients[]').map(([,value])=>value),['none']);
    assert(fields.some(([key,value])=>key==='additional_emails' && value===''),'None suppresses hidden extra copies');
    await page.locator('[data-composer-tab=note]').click();assert(await extra.isHidden() && await extra.isDisabled());
    await submit.click();await page.waitForFunction(() => submissions.length===3 && !document.querySelector('[data-scm-composer-submit]').disabled);
    fields=await page.evaluate(() => submissions[2]);assert(!fields.some(([key])=>key==='additional_emails'),'private notes never submit extra addresses');
    await page.locator('[data-composer-tab=reply]').click();assert(await admin.isChecked() && await extra.isEnabled());
    await extra.fill('invalid');await submit.click();assert.equal(await page.evaluate(()=>submissions.length),3,'invalid copy blocks before saving');
    await extra.fill('only@example.invalid');
    await admin.uncheck();await page.locator('[name="composer_notify[]"][value=arrendatario]').uncheck();
    await submit.click();await page.waitForFunction(() => submissions.length===4 && !document.querySelector('[data-scm-composer-submit]').disabled);
    fields=await page.evaluate(() => submissions[3]);assert(fields.some(([key,value])=>key==='notify_recipients[]'&&value==='additional'),'additional-only response does not default to all actors');
    await page.locator('[data-composer-tab=followup]').click();assert(await admin.isChecked());
    await page.evaluate(()=>{failSave=false;});await extra.fill('followup@example.invalid');await submit.click();
    await page.waitForFunction(() => submissions.length===5 && !document.querySelector('[data-scm-composer-submit]').disabled);
    fields=await page.evaluate(() => submissions[4]);assert(fields.some(([key,value])=>key==='action'&&value==='followup'));assert(fields.some(([key,value])=>key==='additional_emails'&&value==='followup@example.invalid'));
    assert.equal(await extra.inputValue(),'','successful save clears copies for next message');
    await extra.fill('discard@example.invalid');await page.locator('[data-scm-composer-discard]').click();assert.equal(await extra.inputValue(),'');
    await page.locator('[data-composer-tab=reply]').click();
    for (const width of [390,1100]) {
      await page.setViewportSize({width,height:850});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'composer fits '+width+'px');
      fs.mkdirSync(path.join(__dirname,'../output/case-composer'),{recursive:true});
      await page.screenshot({path:path.join(__dirname,'../output/case-composer/email-copies-'+width+'.png'),fullPage:true});
    }
    assert.deepEqual(errors,[]);
    console.log('PASS: reply defaults, extra CC serialization, None, private notes, validation, additional-only sends, follow-up, retry/discard clearing and mobile layout. No network used.');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
