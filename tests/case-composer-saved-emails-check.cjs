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
    await page.setContent('<main id="scm-app"><section id="case-modal"></section><button id="case" data-ticket-pk="41" data-correos-adicionales="uno@ej.co; dos@ej.co" data-arrendatario="Cliente de ejemplo"></button></main>');
    await page.addStyleTag({content: fs.readFileSync(path.join(__dirname, '../public/assets/css/modern-ui.css'), 'utf8')});
    await page.addStyleTag({content: '#scm-app{max-width:1000px;margin:auto;padding:12px;box-sizing:border-box} .material-symbols-outlined{display:none!important}'});
    await page.addScriptTag({content: `
      var btn=document.querySelector('#case'),isPublicPqr=false,statusBucket='';
      function isPreventivaCase(){return false;} function caseHasCotizacion(){return false;}
      function renderComposerResponseOptions(){return '<div data-scm-composer-response-options><select data-scm-composer-admin-state><option value="__keep__">Sin cambio</option></select><input type="checkbox" data-scm-composer-close-ticket></div>';}
      function escHtml(v){return String(v).replace(/[&<>"']/g,function(c){return '&#'+c.charCodeAt(0)+';';});} function renderComposerExecutorField(){return '';} function findRootFromNode(){return document.querySelector('#scm-app');}
      function parseRuntime(){return {ajaxUrl:'/api.php',nonce:'qa',actions:{ticket_response:'reply',seg:'followup',nota:'note'}};}
      window.submissions=[];window.notices=[];window.failSave=false;
      function scmNotify(type,message){notices.push({type,message});}
      window.fetch=async function(url,options){submissions.push(Array.from(options.body.entries()));return {json:async()=>({success:!failSave,data:{message:'ok',correos_adicionales:['tres@ej.co','uno@ej.co','dos@ej.co']}})};};
      ${source.slice(markupStart, markupEnd)}
      document.querySelector('#case-modal').innerHTML=composerHtml;
      ${source.slice(start, end)}
      initCaseComposer(document.querySelector('#case-modal'),btn);
    `});
    const extra = page.locator('[data-scm-composer-additional-emails]');
    await page.waitForSelector('[data-scm-saved-email-pick="uno@ej.co"]');
    await page.click('[data-scm-saved-email-pick="dos@ej.co"]');
    assert.equal(await extra.inputValue(), 'dos@ej.co');
    await page.click('[data-scm-saved-email-all]');
    assert.equal(await extra.inputValue(), 'dos@ej.co; uno@ej.co');
    await page.click('[data-scm-saved-email-pick="dos@ej.co"]');
    assert.equal(await extra.inputValue(), 'uno@ej.co');
    await page.fill('[data-scm-composer-input]', 'Respuesta');
    await page.click('[data-scm-composer-submit]');
    await page.waitForFunction(() => submissions.length===1);
    await page.waitForSelector('[data-scm-saved-email-pick="tres@ej.co"]');
    assert.equal(await extra.inputValue(), '');
    assert.deepEqual(errors, []);
    console.log('OK');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
