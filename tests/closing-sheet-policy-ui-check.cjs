// Lista de chequeo de la hoja de cierre (closing-sheet.js): estados sugeridos por el mandato y el paso 1, advertencia de
// políticas en una ventana de la misma página y justificación obligatoria.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

const html = execFileSync('php', [path.join(__dirname, 'closing-sheet-policy-ui-fixture.php')], { encoding: 'utf8' });
const script = fs.readFileSync(path.join(__dirname, '../public/assets/js/closing-sheet.js'), 'utf8');
const documents = fs.readFileSync(path.join(__dirname, '../public/assets/js/closing-documents.js'), 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setContent('<!doctype html><html><body>' + html + '</body></html>');
    await page.addScriptTag({ content: script });
    await page.addScriptTag({ content: documents });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    const state = (name) => page.locator(`.cs-check-item select[name="${name}"]`).inputValue();

    // Sugerencias al pasar a la lista de chequeo.
    await page.click('[data-cs-next]');
    assert.equal(await state('estado_servicio_energia'), 'Cumple', 'energy with its account is suggested as met');
    assert.equal(await state('estado_servicio_agua'), '', 'water without an account is left to the employee');
    assert.equal(await state('estado_servicio_gas'), 'No aplica', 'a service not in the sheet is not applicable');
    assert.equal(await state('estado_servicio_internet'), 'No aplica', 'no internet in the mandate');
    assert.equal(await state('estado_servicio_telefonia'), 'Cumple', 'landline in the mandate');
    assert.equal(await state('estado_reglamento_copropiedad'), 'No aplica', 'no horizontal property: building items do not apply');
    assert.equal(await state('estado_certificado_empresa_pro'), 'No aplica', 'a natural-person owner needs no company certificate');
    assert.equal(await state('estado_certificado_empresa_arre'), '', 'a company tenant keeps its certificate pending');
    assert.equal(await state('estado_dias_gracia'), 'No aplica', 'no grace days');
    assert.equal(await state('estado_amparo_integral'), 'Cumple', 'the mandate includes the utilities guarantee');
    assert.equal(await state('estado_cobertura'), 'Cumple', '36 months of coverage');
    assert.equal(await state('estado_asegurable'), 'Cumple', 'the sheet has its insurance study');
    assert.equal(await state('estado_termino_contrato'), 'No cumple', '6 months is under the 12-month minimum');
    assert.match(await page.locator('[data-cs-item="estado_termino_contrato"] [data-cs-suggest]').textContent(), /Sugerido: la hoja dice 6 meses/);
    assert.equal(await page.locator('[data-cs-policy-dialog]').evaluate((d) => d.open), false, 'a suggestion does not pop the warning');
    assert.equal(await page.locator('[data-cs-item="estado_termino_contrato"] [data-cs-policy]').isVisible(), true, 'a suggested breach shows its justification box');
    assert.equal(await page.locator('[data-cs-item="estado_termino_contrato"] [data-cs-justification]').getAttribute('data-cs-required'), '', 'and the justification is required');

    // Documentos: el botón Adjuntar sigue activo y un documento sugerido en «Cumple» sin archivo se marca enseguida.
    const energy = page.locator('[data-cs-item="estado_servicio_energia"]');
    assert.equal(await energy.locator('[data-cd-add]').isEnabled(), true, 'attach stays enabled on a suggested item');
    assert.equal(await energy.locator('[data-cd-warn]').isVisible(), true, 'a suggested «Cumple» without a file is flagged right away');
    assert.equal(await page.locator('[data-cs-item="estado_servicio_gas"] [data-cd-warn]').isVisible(), false, '«No aplica» needs no file');
    // Archivo obligatorio: en «Cumple» sin archivo pide decir por qué no se adjunta ahora.
    assert.equal(await energy.locator('[data-cs-file-why]').isVisible(), true, 'a required file asks for a reason');
    assert.equal(await energy.locator('[data-cs-file-reason]').getAttribute('data-cs-required'), '', 'and the reason is required');
    assert.equal(await page.locator('[data-cs-item="estado_servicio_telefonia"] [data-cs-file-why]').count(), 0, 'an optional document has no reason box');

    // Volver al paso 1 y cambiar los meses: la sugerencia que nadie tocó se actualiza.
    await page.evaluate(() => { document.querySelector('[data-cs-step="1"]').hidden = false; document.querySelector('[data-cs-step="2"]').hidden = true; });
    await page.fill('input[name="meses_contrato"]', '12');
    await page.click('[data-cs-next]');
    assert.equal(await state('estado_termino_contrato'), 'Cumple', 'an untouched suggestion follows step 1');
    assert.equal(await page.locator('[data-cs-item="estado_termino_contrato"] [data-cs-policy]').isVisible(), false, 'no breach, no justification box');

    // Marcar a mano un incumplimiento abre la advertencia; "Corregir selección" lo deshace.
    const amparo = page.locator('.cs-check-item select[name="estado_amparo_integral"]');
    await amparo.focus();
    await amparo.selectOption('No cumple');
    assert.equal(await page.locator('[data-cs-policy-dialog]').evaluate((d) => d.open), true, 'the warning opens in the same page');
    assert.match(await page.locator('[data-cs-policy-list]').textContent(), /Política #47: Garantía de Servicios Públicos/);
    assert.match(await page.locator('[data-cs-policy-list]').textContent(), /Todos los contratos deben incluir/);
    await page.click('[data-cs-policy-undo]');
    assert.equal(await state('estado_amparo_integral'), 'Cumple', 'undo restores the previous state');
    assert.equal(await page.locator('[data-cs-policy-dialog]').evaluate((d) => d.open), false);

    // Aceptar la excepción exige justificación y la copia a la casilla del ítem.
    await amparo.focus();
    await amparo.selectOption('No cumple');
    await page.click('[data-cs-policy-accept]');
    assert.equal(await page.locator('[data-cs-policy-error]').isVisible(), true, 'accepting without a reason is blocked');
    await page.fill('[data-cs-policy-reason]', 'La afianzadora no ofrece el amparo en este estrato.');
    await page.click('[data-cs-policy-accept]');
    assert.equal(await page.locator('[data-cs-policy-dialog]').evaluate((d) => d.open), false);
    assert.equal(await page.locator('[data-cs-item="estado_amparo_integral"] [data-cs-justification]').inputValue(), 'La afianzadora no ofrece el amparo en este estrato.');
    assert.equal(await page.locator('[data-cs-item="estado_amparo_integral"]').evaluate((n) => n.classList.contains('is-breach')), true, 'the item is shown in red');
    assert.equal(await page.locator('[data-cs-item="estado_amparo_integral"] [data-cs-suggest]').isVisible(), false, 'a manual choice clears the suggestion');

    // Escape equivale a corregir.
    await page.locator('.cs-check-item select[name="estado_cobertura"]').focus();
    await page.locator('.cs-check-item select[name="estado_cobertura"]').selectOption('No cumple');
    // estado_cobertura no tiene política en esta prueba: no abre nada.
    assert.equal(await page.locator('[data-cs-policy-dialog]').evaluate((d) => d.open), false, 'an item without a policy does not warn');

    // Al guardar se listan los documentos en «Cumple» sin archivo antes de enviar.
    await page.locator('.cs-check-item select').evaluateAll((selects) => selects.forEach((s) => { if (!s.value) s.value = 'No aplica'; }));
    await page.fill('[data-cs-item="estado_servicio_energia"] [data-cs-file-reason]', 'El propietario la envía el lunes.');
    await page.click('button[type="submit"]');
    assert.equal(await page.locator('[data-cd-pending]').isVisible(), true, 'saving lists the documents in «Cumple» without a file');
    assert.match(await page.locator('[data-cd-pending-list]').textContent(), /teléfono/i, 'optional documents are listed');
    assert.doesNotMatch(await page.locator('[data-cd-pending-list]').textContent(), /energía/i, 'a required document with its reason is not asked again');

    assert.deepEqual(errors, [], 'no script errors');
    console.log('Closing sheet policy UI checks passed.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
