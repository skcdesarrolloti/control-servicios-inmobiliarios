const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

// Evidencias de actas/revisiones: la compresión no debe romper el envío cuando el celular
// no puede decodificar la foto ("The source image could not be decoded").
const helper = fs.readFileSync(path.join(__dirname, '../public/assets/js/scm-photo-compress.js'), 'utf8');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
  let checks = 0;
  const pass = label => { checks++; console.log('PASS: ' + label); };
  try {
    const page = await browser.newPage();
    await page.setContent('<!doctype html><body></body>');
    await page.addScriptTag({ content: helper });
    await page.evaluate(() => {
      window.makePhoto = (w, h, type = 'image/jpeg', name = 'foto.jpg') => new Promise(resolve => {
        const c = document.createElement('canvas'); c.width = w; c.height = h;
        const x = c.getContext('2d'); x.fillStyle = '#c33'; x.fillRect(0, 0, w, h); x.fillStyle = '#39c'; x.fillRect(0, 0, w / 2, h / 2);
        c.toBlob(b => resolve(new File([b], name, { type })), type, 0.95);
      });
      window.describe = async file => {
        const bmp = await window.__realBitmap(file);
        return { name: file.name, type: file.type, size: file.size, w: bmp.width, h: bmp.height };
      };
      window.__realBitmap = window.createImageBitmap.bind(window);
    });

    const normal = await page.evaluate(async () => describe(await ScmPhotoCompress.compress(await makePhoto(4000, 3000))));
    assert.equal(normal.type, 'image/jpeg'); assert.equal(normal.w, 1600); assert.equal(normal.h, 1200);
    pass('large photo is reduced to 1600 px JPEG');

    const resized = await page.evaluate(async () => {
      const calls = [];
      const real = window.__realBitmap;
      window.createImageBitmap = (file, options) => { calls.push(options || {}); return real(file, options); };
      await ScmPhotoCompress.compress(await makePhoto(3200, 2400));
      window.createImageBitmap = real;
      return calls;
    });
    assert.equal(resized[0].resizeWidth, 1600, 'decodes already resized to save phone memory');
    pass('photo is decoded already resized instead of at full resolution');

    const bitmapFails = await page.evaluate(async () => {
      window.createImageBitmap = () => Promise.reject(new DOMException('The source image could not be decoded.', 'InvalidStateError'));
      try { return await describe(await ScmPhotoCompress.compress(await makePhoto(3000, 2000))); }
      finally { window.createImageBitmap = window.__realBitmap; }
    });
    assert.equal(bitmapFails.type, 'image/jpeg'); assert.equal(bitmapFails.w, 1600);
    pass('createImageBitmap decode error falls back to <img> and still compresses');

    const passthrough = await page.evaluate(async () => {
      const original = await makePhoto(800, 600);
      const proto = HTMLCanvasElement.prototype, realToBlob = proto.toBlob;
      window.createImageBitmap = () => Promise.reject(new DOMException('The source image could not be decoded.', 'InvalidStateError'));
      proto.toBlob = function (cb) { cb(null); };
      try {
        const out = await ScmPhotoCompress.compress(original);
        return { same: out === original, upload: ScmPhotoCompress.uploadBytes(out), size: out.size };
      } finally { window.createImageBitmap = window.__realBitmap; proto.toBlob = realToBlob; }
    });
    assert.ok(passthrough.same, 'original is sent for the server to compress');
    assert.ok(passthrough.upload <= 1500000);
    pass('when the phone cannot compress at all, the original is sent and counts as server-compressed');

    const broken = await page.evaluate(async () => {
      const file = new File([new Uint8Array(30 * 1024 * 1024)], 'enorme.jpg', { type: 'image/jpeg' });
      try { await ScmPhotoCompress.compress(file); return 'resolved'; } catch (e) { return e.message; }
    });
    assert.match(broken, /máximo 25 MB/);
    pass('oversized source keeps a clear Spanish error');

    const unreadable = await page.evaluate(async () => {
      const file = new File([new Uint8Array(12 * 1024 * 1024).fill(7)], 'dañada.jpg', { type: 'image/jpeg' });
      try { await ScmPhotoCompress.compress(file); return 'resolved'; } catch (e) { return e.message; }
    });
    assert.match(unreadable, /No se pudo procesar la foto dañada\.jpg/);
    assert.doesNotMatch(unreadable, /^The source image/);
    pass('undecodable large file explains which photo failed in Spanish');

    const typeless = await page.evaluate(async () => {
      const blob = await makePhoto(500, 400);
      const out = await ScmPhotoCompress.compress(new File([blob], 'camara.jpg', { type: '' }));
      return out.type;
    });
    assert.equal(typeless, 'image/jpeg');
    pass('camera files without MIME type are accepted by extension');

    const reuse = await page.evaluate(async () => {
      const file = await makePhoto(2000, 1500);
      const a = await ScmPhotoCompress.compress(file), b = await ScmPhotoCompress.compress(file);
      return a === b;
    });
    assert.ok(reuse);
    pass('draft and submit reuse the same compressed photo');

    const order = await page.evaluate(async () => {
      let active = 0, peak = 0; const real = window.__realBitmap;
      window.createImageBitmap = async (f, o) => { active++; peak = Math.max(peak, active); await new Promise(r => setTimeout(r, 30)); try { return await real(f, o); } finally { active--; } };
      const files = await Promise.all([1, 2, 3, 4].map(i => makePhoto(1800 + i, 1200, 'image/jpeg', 'f' + i + '.jpg')));
      await Promise.all(files.map(f => ScmPhotoCompress.compress(f)));
      window.createImageBitmap = real;
      return peak;
    });
    assert.equal(order, 1);
    pass('photos are decoded one at a time');

    console.log(checks + ' photo compression checks passed.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
