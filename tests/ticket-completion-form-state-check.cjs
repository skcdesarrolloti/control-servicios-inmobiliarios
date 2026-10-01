// Run with NODE_PATH pointing to the installed Playwright package directory.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/ticket-completion-form-state.js'), 'utf8');
let checks = 0;
function check(value, label) { assert(value, label); checks++; console.log('PASS: ' + label); }
const photo = (name) => ({ name, mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhmkAAAAASUVORK5CYII=', 'base64') });
function fixture(user = '1', quote = '570', revision = 'v1', rows = 1) {
  const row = (i) => `<fieldset data-acta-item><textarea name="items[${i}][damage]">Daño ${i}</textarea><textarea name="items[${i}][solution]"></textarea><input type="file" multiple data-corrective-photos name="acta_damage_photos_${i}[]"><div data-corrective-photo-preview><figure data-acta-existing-photo data-acta-damage-photo><input type="hidden" name="items[${i}][damage_photos][0][name]" value="damage-${i}.jpg"><button type="button" data-acta-remove-existing-photo>Quitar daño</button></figure></div><input type="file" multiple data-acta-photos name="acta_item_photos_${i}[]"><div data-acta-photo-preview><figure data-acta-existing-photo><input type="hidden" name="items[${i}][photos][0][name]" value="solution-${i}.jpg"><button type="button" data-acta-remove-existing-photo>Quitar solución</button></figure></div><button type="button" data-acta-remove-item>Quitar detalle</button></fieldset>`;
  return `<form data-acta-create data-acta-id="9" data-acta-draft-user="${user}" data-acta-draft-ticket="10841" data-acta-draft-revision="${revision}"><input type="hidden" name="source_flow" value="cotizacion"><input type="hidden" name="source_cotizacion_id" value="${quote}"><textarea name="observations"></textarea><div data-acta-items>${Array.from({length: rows}, (_, i) => row(i)).join('')}</div><button type="button" data-acta-add-item>Agregar</button><button>Guardar</button></form><script>
  let sequence=${rows}; const form=document.querySelector('form'), wrap=form.querySelector('[data-acta-items]');
  form.querySelector('[data-acta-add-item]').onclick=()=>{if(wrap.children.length>=30)return;const item=wrap.firstElementChild.cloneNode(true);item.querySelectorAll('[name]').forEach(f=>{f.name=f.name.replace(/items\\[\\d+\\]/,'items['+sequence+']');f.value='';});item.querySelectorAll('[data-acta-existing-photo],[data-acta-photo-preview] [data-acta-selected-photo]').forEach(f=>f.remove());sequence++;wrap.append(item);};
  form.addEventListener('click',e=>{if(e.target.matches('[data-acta-remove-item]')&&wrap.children.length>1)e.target.closest('[data-acta-item]').remove();});
  </script><script>${source}</script><script>ScmActaFormState.bind(document.querySelector('form'));</script>`;
}
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage(); let html = fixture();
    await page.route('http://acta.test/**', route => route.fulfill({contentType: 'text/html; charset=utf-8', body: html}));
    async function open() { await page.goto('http://acta.test/form'); await page.waitForFunction(() => document.querySelector('[data-acta-photo-count]').textContent.includes('Fotos del acta:')); }
    async function save() { await page.evaluate(() => document.querySelector('form')._actaState.save()); }
    const count = () => page.evaluate(() => ScmActaFormState.count(document.querySelector('form')));
    await open(); check(await count() === 2, 'stored damage and solution count toward total');
    await page.locator('[data-acta-photos]').setInputFiles(photo('new-solution.png'));
    check(await page.locator('[data-acta-photo-preview] [data-acta-existing-photo]').count() === 1, 'new solution preserves stored descriptor');
    await page.locator('[data-corrective-photos]').setInputFiles(photo('new-damage.png'));
    check(await count() === 4, 'both new photo kinds count');
    await page.locator('[data-acta-unselect][data-acta-photo-kind="damage"]').click();
    check(await count() === 3, 'individual new damage removal');
    await page.locator('[data-acta-unselect][data-acta-photo-kind="solution"]').click();
    check(await count() === 2, 'individual new solution removal');
    await page.locator('[data-acta-existing-photo] button').first().click();
    await page.locator('[data-acta-existing-photo] button').first().click();
    check(await count() === 0, 'both stored photo kinds can be removed');
    await page.locator('[data-corrective-photos]').setInputFiles(photo('damage-restored.png'));
    await page.locator('[data-acta-photos]').setInputFiles(photo('solution-restored.png'));
    await page.locator('[name="observations"]').fill('Texto pendiente <b>literal</b>');
    await page.locator('[data-acta-add-item]').click();
    check(await page.locator('[data-acta-item]').last().locator('[data-acta-selected-photo]').count() === 0, 'new detail does not inherit damage thumbnails from cloned source');
    await page.locator('[name$="[solution]"]').last().fill('Segundo detalle');
    await save(); await open();
    check(await count() === 2, 'new damage and solution files survive reload');
    check(await page.locator('[data-acta-existing-photo]').count() === 0, 'stored photo removals survive reload');
    check(await page.locator('[name="observations"]').inputValue() === 'Texto pendiente <b>literal</b>', 'text survives reload without becoming HTML');
    check(await page.locator('[data-acta-item]').count() === 2 && await page.locator('[name$="[solution]"]').last().inputValue() === 'Segundo detalle', 'new detail has separate draft identity');
    check(await page.evaluate(() => { const f=document.querySelector('[data-acta-photos]').files[0]; return f.name==='solution-restored.png' && f.size>0; }), 'restored files populate native submission');
    html=fixture('2'); await open(); check(await count() === 2 && await page.locator('[name="observations"]').inputValue() === '', 'draft isolated by employee');
    html=fixture('1','571'); await open(); check(await page.locator('[name="observations"]').inputValue() === '', 'draft isolated by quotation');
    html=fixture('1','570','v2'); await open(); check(await page.locator('[name="observations"]').inputValue() === '', 'changed server revision prevents stale draft restore');
    html=fixture(); await open(); await page.evaluate(() => document.querySelector('form')._actaState.clear()); await open();
    check(await page.locator('[name="observations"]').inputValue() === '' && await count() === 2, 'successful save clears draft and pending photos');
    html=fixture('4','570','v1',2); await open();
    await page.locator('[data-corrective-photos]').first().setInputFiles(Array.from({length: 35}, (_,i)=>photo('damage-'+i+'.png')));
    await page.locator('[data-acta-photos]').first().setInputFiles(Array.from({length: 35}, (_,i)=>photo('solution-'+i+'.png')));
    check(await count() === 74, 'damage and solution allow more than all old quantity limits');
    await save(); await open();
    check(await count() === 74, 'large photo selection survives draft restore');
    await page.evaluate(() => document.querySelector('form')._actaState.clear());
    // A draft retaining 29 server items plus a new item must restore even when the source had 30.
    html=fixture('3','570','v1',30); await open();
    await page.locator('[data-acta-remove-item]').last().click(); await page.locator('[data-acta-add-item]').click();
    await page.locator('[name$="[solution]"]').last().fill('Nuevo a treinta'); await save(); await open();
    check(await page.locator('[data-acta-item]').count() === 30 && await page.locator('[name$="[solution]"]').last().inputValue() === 'Nuevo a treinta', '30 detail draft restores replacement item correctly');
    await page.evaluate(() => document.querySelector('form')._actaState.clear());
    console.log(`${checks} checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
