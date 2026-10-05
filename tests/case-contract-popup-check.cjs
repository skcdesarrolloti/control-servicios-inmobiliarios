const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-admin.js'), 'utf8');
const start = source.indexOf('  function openCaseSubmodal(');
const handler = source.slice(start, source.indexOf('  function renderCaseDamageItems(', start));

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage();
    await page.setContent('<button id="case-a" data-ticket="10866"></button><button id="case-b" data-ticket="10867"></button><div id="modal"><main id="case-body"></main><aside id="sub"><h3 class="scm-case-submodal-title"></h3><div class="scm-case-submodal-body"></div></aside></div>');
    await page.addScriptTag({content: `
      function ensureCaseSubmodal() { return document.getElementById('sub'); }
      function setCaseSubmodalMeta(sub, btn) { sub.dataset.ticket = btn ? btn.dataset.ticket : ''; }
      function initCotizacionResponseFields() {}
      function scmNotify() { throw new Error('Contract section was not found'); }
      function fallbackCaseSectionSource() { return null; }
      ${handler}
      window.openContract = function (buttonId, contractNumber) {
        var modal = document.getElementById('modal');
        modal._scmCurrentCaseButton = document.getElementById(buttonId);
        document.getElementById('case-body').innerHTML = '<section id="scm-sec-contrato" style="display:none"><h4>Contrato</h4><p>' + contractNumber + '</p></section>';
        openCaseSubmodal(modal, null, 'scm-sec-contrato');
      };
    `});
    for (const [button, ticket, contract] of [['case-a', '10866', '600'], ['case-b', '10867', '820'], ['case-a', '10866', '600']]) {
      await page.evaluate(([id, number]) => window.openContract(id, number), [button, contract]);
      assert.equal(await page.locator('#sub').getAttribute('data-ticket'), ticket, 'Popup retained a different case');
      assert.equal(await page.locator('#sub .scm-case-submodal-body p').textContent(), contract, 'Popup retained a different contract');
      assert.equal(await page.locator('#sub #scm-sec-contrato').count(), 0, 'Cloned section must not reuse the source ID');
    }
    console.log('Case contract popup checks passed.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
