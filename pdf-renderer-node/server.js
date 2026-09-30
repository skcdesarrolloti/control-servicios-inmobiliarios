'use strict';

const http = require('node:http');
const { timingSafeEqual } = require('node:crypto');
const Busboy = require('busboy');
const puppeteer = require('puppeteer');

const port = Number.parseInt(process.env.PORT || '3000', 10);
const username = process.env.PDF_SERVICE_USER || '';
const password = process.env.PDF_SERVICE_PASSWORD || '';
const maxHtmlBytes = 8 * 1024 * 1024;
const maxActiveJobs = 2;
let browser;
let activeJobs = 0;

function authorized(header) {
  if (!username || !password || typeof header !== 'string' || !header.startsWith('Basic ')) return false;
  let supplied;
  try {
    supplied = Buffer.from(header.slice(6), 'base64').toString('utf8');
  } catch {
    return false;
  }
  const expected = Buffer.from(`${username}:${password}`);
  const actual = Buffer.from(supplied);
  return actual.length === expected.length && timingSafeEqual(actual, expected);
}

function response(res, status, message) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify({ error: message }));
}

async function getBrowser() {
  if (browser && browser.connected) return browser;
  browser = await puppeteer.launch({
    headless: true,
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  return browser;
}

function readHtml(req) {
  return new Promise((resolve, reject) => {
    let found = false;
    let length = 0;
    const chunks = [];
    let parser;
    try {
      parser = Busboy({ headers: req.headers, limits: { files: 1, fileSize: maxHtmlBytes, fields: 8 } });
    } catch (error) {
      reject(error);
      return;
    }
    parser.on('file', (field, file, info) => {
      if (field !== 'files' || info.filename !== 'index.html' || found) {
        file.resume();
        return;
      }
      found = true;
      file.on('data', chunk => {
        length += chunk.length;
        chunks.push(chunk);
      });
      file.on('limit', () => reject(new Error('HTML demasiado grande')));
    });
    parser.on('error', reject);
    parser.on('close', () => {
      if (!found || length === 0 || length > maxHtmlBytes) reject(new Error('Falta index.html'));
      else resolve(Buffer.concat(chunks).toString('utf8'));
    });
    req.pipe(parser);
  });
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    try {
      const instance = await getBrowser();
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify({ ok: instance.connected }));
    } catch (error) {
      console.error('Chromium no inició:', error);
      response(res, 503, 'Chromium no disponible');
    }
    return;
  }
  if (req.method !== 'POST' || req.url !== '/forms/chromium/convert/html') {
    response(res, 404, 'Ruta no encontrada');
    return;
  }
  if (!authorized(req.headers.authorization)) {
    res.setHeader('WWW-Authenticate', 'Basic realm="SKC PDF"');
    response(res, 401, 'No autorizado');
    return;
  }
  if (activeJobs >= maxActiveJobs) {
    response(res, 503, 'Generador ocupado');
    return;
  }
  activeJobs++;
  let page;
  try {
    const html = await readHtml(req);
    page = await (await getBrowser()).newPage();
    await page.setContent(html, { waitUntil: 'networkidle2', timeout: 20000 });
    await page.emulateMediaType('print');
    const pdf = await page.pdf({ format: 'A4', printBackground: true, preferCSSPageSize: true, displayHeaderFooter: false, timeout: 30000 });
    res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Length': pdf.length, 'Cache-Control': 'no-store' });
    res.end(pdf);
  } catch (error) {
    console.error('No se pudo generar el PDF:', error);
    if (!res.headersSent) response(res, 500, 'No se pudo generar el PDF');
  } finally {
    if (page) await page.close().catch(() => {});
    activeJobs--;
  }
});

if (!Number.isInteger(port) || port < 1 || port > 65535 || !username || !password) {
  console.error('Configura PORT, PDF_SERVICE_USER y PDF_SERVICE_PASSWORD.');
  process.exit(1);
}

server.listen(port, '0.0.0.0', () => console.log(`SKC PDF renderer listening on ${port}`));

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, async () => {
    server.close();
    if (browser) await browser.close().catch(() => {});
    process.exit(0);
  });
}
