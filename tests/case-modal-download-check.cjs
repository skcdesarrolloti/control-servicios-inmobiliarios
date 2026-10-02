const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const http = require('node:http');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-dashboard-runtime.js'), 'utf8');
const start = source.indexOf('      var extLink =');
const handler = source.slice(start, source.indexOf('      var adminTicketBtn =', start));
(async () => {
  let origin;
  const server = http.createServer((request, response) => {
    if (request.url.includes('format=pdf')) {
      response.writeHead(200, {'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="acta-11-firmada.pdf"'});
      response.end('%PDF-1.4\n%%EOF');
      return;
    }
    response.writeHead(200, {'Content-Type': 'text/html'});
    response.end('<main id="root"><section class="scm-case-modal"><a id="pdf" href="' + origin + '/ticket-acta.php?id=11&format=pdf" download>Descargar PDF firmado</a><a id="view" href="' + origin + '/ticket-acta.php?id=11">Ver acta</a><a id="edit" href="' + origin + '/crear-acta.php?act_id=11">Editar acta</a></section></main>');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage();
    await page.goto(origin + '/');
    await page.evaluate(() => {
      window.popups = [];
      window.openIframeModal = (url, title) => popups.push({url, title});
      document.querySelector('#edit').addEventListener('click', event => event.preventDefault());
    });
    await page.addScriptTag({content: 'document.querySelector("#root").addEventListener("click",function(e){' + handler + '});'});
    const pendingDownload = page.waitForEvent('download', {timeout: 5000});
    await page.locator('#pdf').click();
    const download = await pendingDownload;
    assert.equal(download.suggestedFilename(), 'acta-11-firmada.pdf');
    assert.equal(await download.failure(), null);
    assert.equal(await page.evaluate(() => popups.length), 0, 'download does not open an iframe');
    assert.equal(page.url(), origin + '/', 'download preserves the case');
    await page.locator('#edit').click();
    assert.equal(await page.evaluate(() => popups.length), 0, 'handled edit is not intercepted again');
    await page.locator('#view').click();
    assert.equal(await page.evaluate(() => popups.length), 1, 'normal view still opens inside the panel');
    console.log('PASS: completed download, filename, no download popup, preserved case, handled edit, internal view (6 checks).');
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
