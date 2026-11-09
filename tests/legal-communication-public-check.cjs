const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

// Vista pública de una comunicación: previsualizar adjuntos en la misma página e impresión compacta.
const root = path.resolve(__dirname, '..');
const markup = `<main class="lcp-page"><div class="lcp-actions"><button class="lcp-button" data-lcp-print>Imprimir</button></div>
<article class="lcp-card"><p class="lcp-brand">SKC SuCasa Inmobiliaria</p><h1>Requerimiento</h1><dl class="lcp-meta"><div><dt>Para</dt><dd>Pedro</dd></div><div><dt>Referencia</dt><dd>CASO-0001</dd></div><div><dt>Fecha</dt><dd>9 de noviembre</dd></div></dl>
<div class="lcp-letter"><p>Contenido</p><p>Atentamente,<br><strong>Ana</strong></p></div>
<section class="lcp-attachments"><h2>Documentos adjuntos</h2><ul><li><button class="lcp-file" data-lcp-file="https://panel.test/a.pdf" data-lcp-name="acta.pdf" data-lcp-mime="application/pdf">acta.pdf</button></li><li><button class="lcp-file" data-lcp-file="https://panel.test/b.docx" data-lcp-name="b.docx" data-lcp-mime="application/msword">b.docx</button></li></ul></section></article></main>
<dialog class="lcp-dialog" data-lcp-dialog><header><strong data-lcp-title>Documento</strong><span><a class="lcp-button" data-lcp-download download>Descargar</a> <button class="lcp-button" data-lcp-close>Cerrar</button></span></header><div data-lcp-body></div></dialog>`;

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  const page = await browser.newPage({ viewport: { width: 1000, height: 800 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setContent('<!doctype html><html><head></head><body>' + markup + '</body></html>');
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/legal-communication-public.css') });
  await page.evaluate(() => { window.fetch = () => Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['%PDF-1.4'], { type: 'application/pdf' })) }); });
  await page.addScriptTag({ path: path.join(root, 'public/assets/js/legal-communication-public.js') });

  const pages = browser.contexts()[0].pages().length;
  await page.click('[data-lcp-file*="a.pdf"]');
  await page.waitForFunction(() => (document.querySelector('[data-lcp-body] iframe') || {}).src?.startsWith('blob:'));
  assert.equal(await page.textContent('[data-lcp-title]'), 'acta.pdf');
  assert.equal(browser.contexts()[0].pages().length, pages, 'the document opens in the same page');
  await page.click('[data-lcp-close]');
  assert.equal(await page.locator('[data-lcp-body] iframe').count(), 0);
  await page.click('[data-lcp-file*="b.docx"]');
  assert.match(await page.textContent('[data-lcp-body]'), /Descargar/);
  assert.equal(await page.getAttribute('[data-lcp-download]', 'href'), 'https://panel.test/b.docx');
  await page.click('[data-lcp-close]');
  console.log('OK attachments preview inside the page');

  await page.emulateMedia({ media: 'print' });
  const print = await page.evaluate(() => {
    const style = (selector) => getComputedStyle(document.querySelector(selector));
    return {
      actions: style('.lcp-actions').display, cardBorder: style('.lcp-card').borderTopWidth, meta: style('.lcp-meta').gridTemplateColumns.split(' ').length,
      attachmentsBreak: style('.lcp-attachments').breakInside, signatureBreak: style('.lcp-letter > p:last-child').breakInside, hasPage: Array.from(document.styleSheets).some((sheet) => Array.from(sheet.cssRules).some((rule) => rule.media && Array.from(rule.cssRules).some((inner) => inner.constructor.name === 'CSSPageRule'))),
    };
  });
  assert.equal(print.actions, 'none', 'actions are hidden when printing');
  assert.equal(print.cardBorder, '0px');
  assert.equal(print.meta, 3, 'compact three-column header');
  assert.equal(print.attachmentsBreak, 'avoid');
  assert.equal(print.signatureBreak, 'avoid', 'the signature is not cut across pages');
  assert.equal(print.hasPage, true, 'declares page margins');
  const pdf = await page.pdf({ format: 'Letter', preferCSSPageSize: true });
  assert.ok(pdf.length > 1000, 'renders a printable document');
  console.log('OK compact print layout');

  await page.emulateMedia({ media: 'screen' });
  await page.setViewportSize({ width: 360, height: 700 });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal scroll on a phone');
  console.log('OK phone layout');

  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Vista pública de comunicaciones: verificación completa.');
})().catch((error) => { console.error(error); process.exit(1); });
