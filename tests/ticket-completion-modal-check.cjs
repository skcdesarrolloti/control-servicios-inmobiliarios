const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname,'../public/assets/js/scm-admin.js'),'utf8');
const editor = source.slice(source.indexOf('  function openTicketCompletionEditor('), source.indexOf('  function openCorrectiveReviewEditor('));
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try {
  const page=await browser.newPage();
  await page.setContent('<div id="root"><div id="modal"><button data-scm-open-ticket-acta>Acta</button><div id="sub"><h2 class="scm-case-submodal-title"></h2><button class="scm-case-submodal-close">Cerrar</button><div class="scm-case-submodal-body"></div></div></div><button id="case" data-ticket-pk="10841" data-cotizacion-id="570" data-cot-estado="Aprobada"></button></div>');
  await page.evaluate(()=>{
   window.requests=[]; window.dialogs=[]; window.confirmNext=true;
   window.ensureCaseSubmodal=()=>document.querySelector('#sub');
   window.findRootFromNode=()=>document.querySelector('#root');
   window.parseRuntime=()=>({ajaxUrl:'https://example.test/api.php',nonce:'test'});
   window.setCaseSubmodalMeta=()=>{}; window.scmNotify=()=>{}; window.openIframeModal=()=>{throw Error('Unexpected popup');};
   window.Swal={fire: async options=>{dialogs.push(options);return {isConfirmed:confirmNext};}};
   window.record='<section class="scm-acta"><p data-acta-message></p><a href="#" data-acta-edit="11" data-acta-signed="1">Editar acta</a><button data-acta-resend="11">Reenviar copia firmada</button></section>';
   window.edit='<section class="scm-acta"><p data-acta-message></p><form data-acta-create data-acta-operation="update" data-acta-id="11"><select data-acta-signer><option selected>Propietario</option></select><input data-acta-signer-name><input data-acta-signer-email><input data-acta-signer-phone><input data-acta-fee value="0"><input data-acta-transport value="0"><div data-acta-items><fieldset data-acta-item><input name="items[0][corrective_sync_id]" value="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"><div data-acta-corrective-wrap><fieldset data-acta-corrective-fields><select data-corrective-indice name="items[0][corrective][indice]"><option selected>Elementos estructurales</option></select><div data-corrective-area-group data-corrective-area-for="area_afectada_1"><input data-corrective-area-field name="items[0][corrective][area_afectada_1]" value="Muros"></div><div data-corrective-area-group data-corrective-area-for="area_afectada_2"><input data-corrective-area-field name="items[0][corrective][area_afectada_2]" value="Vigas"></div><input name="items[0][corrective][descripcion_dano]" value="Fisura"><input name="items[0][corrective][consecuencia]" value="Filtración"><input name="items[0][corrective][nivel_dano]" value="Moderado"><input name="items[0][corrective][tiempo_atencion]" value="2 días"></fieldset></div><textarea name="items[0][damage]"></textarea><textarea name="items[0][solution]">Sellado</textarea></fieldset></div><button type="button" data-acta-add-item>Agregar</button><input type="checkbox" name="confirm_reopen" value="1" checked><button type="submit">Guardar</button></form></section>';
   window.fetch=async (_url,options)=>{
    const data=Object.fromEntries(options.body.entries()); requests.push(data);
    return {json:async()=>({success:true,data:{html:data.operation==='read'&&data.act_id?edit:record,queued:true,message:data.operation==='resend'?'Reenvío registrado':''}})};
   };
  });
  await page.addScriptTag({content:editor+'\nopenTicketCompletionEditor(document.querySelector("#modal"),document.querySelector("#case"));'});
  await page.locator('[data-acta-edit]').waitFor();
  await page.evaluate(()=>{confirmNext=false;});
  await page.locator('[data-acta-resend]').click();
  await page.waitForTimeout(30);
  assert.equal(await page.evaluate(()=>requests.filter(r=>r.operation==='resend').length),0,'cancelled resend sends nothing');
  await page.evaluate(()=>{confirmNext=true;});
  await page.locator('[data-acta-resend]').click();
  await page.waitForFunction(()=>requests.some(r=>r.operation==='resend'));
  assert.equal(await page.evaluate(()=>requests.find(r=>r.operation==='resend').act_id),'11');
  assert(await page.evaluate(()=>dialogs.some(d=>d.title==='Reenvío registrado')),'resend result uses SweetAlert');
  await page.locator('[data-acta-edit]').click();
  await page.locator('[data-acta-create]').waitFor();
  assert(await page.evaluate(()=>dialogs.some(d=>d.title==='Editar acta firmada')),'signed edit explains reopening in SweetAlert');
  assert(await page.locator('[name="items[0][corrective][descripcion_dano]"]').isEnabled(),'synced corrective damage fields are editable');
  await page.locator('[name="items[0][corrective][descripcion_dano]"]').fill('Fisura corregida');
  assert((await page.locator('[name="items[0][damage]"]').inputValue()).includes('Fisura corregida'),'editing corrective fields updates act damage summary');
  assert(await page.locator('[name="items[0][corrective][area_afectada_1]"]').isDisabled(),'only selected corrective area is submitted');
  await page.locator('[data-acta-create] button[type=submit]').click();
  await page.waitForFunction(()=>requests.some(r=>r.operation==='update'));
  const update=await page.evaluate(()=>requests.find(r=>r.operation==='update'));
  assert.equal(update['items[0][corrective][descripcion_dano]'],'Fisura corregida');
  assert.equal(update['items[0][corrective_sync_id]'],'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
  assert.equal(update.act_id,'11');assert.equal(update.source_cotizacion_id,'570');assert.equal(update.confirm_reopen,'1');
  assert.equal(await page.evaluate(()=>requests.filter(r=>r.operation==='create').length),0,'editing never creates another act');
  console.log('PASS: cancelled resend, confirmed resend, SweetAlert feedback, signed edit warning, update operation, original act id, quote context and reopening consent (13 checks, including corrective damage editing).');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
