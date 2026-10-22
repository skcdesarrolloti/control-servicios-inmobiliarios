'use strict';

const http = require('node:http');
const { timingSafeEqual } = require('node:crypto');
const { chmodSync, existsSync, statSync } = require('node:fs');
const { basename, dirname, join } = require('node:path');
const { inspect } = require('node:util');
const Busboy = require('busboy');
const puppeteer = require('puppeteer');

const port = Number.parseInt(process.env.PORT || '3000', 10);
const username = process.env.PDF_SERVICE_USER || '';
const password = process.env.PDF_SERVICE_PASSWORD || '';
// Actas can contain up to 12 verified photographs embedded as data URIs.
const maxHtmlBytes = Math.max(8, Math.min(48, Number(process.env.PDF_MAX_HTML_MB) || 32)) * 1024 * 1024;
const maxActiveJobs = 2;
// Las peticiones que llegan con los 2 trabajos ocupados esperan turno en vez de fallar (el panel
// reintentaba y los usuarios volvían a pulsar, multiplicando trabajos).
const queueTimeoutMs = 25000;
// Chromium se cierra tras este tiempo sin trabajo: sus procesos (navegador, GPU, red, renderizadores)
// dejan de contar en el límite de procesos del hosting mientras no se generan PDF.
const idleCloseMs = Math.max(15, Number(process.env.PDF_BROWSER_IDLE_SECONDS) || 60) * 1000;
// Se reinicia cada cierto número de PDF para no acumular memoria ni procesos huérfanos.
const recycleAfterPages = 50;
let browser;
// Lanzamiento en curso: dos PDF simultáneos sin navegador abrían dos Chromium y uno quedaba huérfano.
let launching = null;
let browserPages = 0;
let idleTimer = null;
let activeJobs = 0;
const waiting = [];

function acquireSlot() {
  if (activeJobs < maxActiveJobs) {
    activeJobs++;
    return Promise.resolve();
  }
  return new Promise((resolve, reject) => {
    const entry = { resolve, timer: null };
    entry.timer = setTimeout(() => {
      const index = waiting.indexOf(entry);
      if (index !== -1) waiting.splice(index, 1);
      reject(new Error('Generador ocupado'));
    }, queueTimeoutMs);
    waiting.push(entry);
  });
}

function releaseSlot() {
  const next = waiting.shift();
  if (next) {
    clearTimeout(next.timer);
    next.resolve();
    return;
  }
  activeJobs--;
  if (activeJobs !== 0) return;
  // Reciclar solo sin trabajos en curso, para no cerrar un navegador que otro PDF está usando.
  if (browserPages >= recycleAfterPages) closeBrowser('reciclado');
  else scheduleIdleClose();
}

function scheduleIdleClose() {
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => { closeBrowser('inactivo'); }, idleCloseMs);
}

// Cierra el navegador y, si no responde, mata su proceso para no dejar hijos huérfanos.
async function closeBrowser(reason) {
  const current = browser;
  browser = undefined;
  browserPages = 0;
  if (!current) return;
  const child = current.process();
  try {
    await Promise.race([current.close(), new Promise((resolve) => setTimeout(resolve, 5000))]);
  } catch {}
  if (child && child.exitCode === null && !child.killed) {
    try { child.kill('SIGKILL'); } catch {}
  }
  console.log(`Chromium cerrado (${reason}).`);
}

function errorDetails(error) {
  return error?.stack || error?.message || inspect(error, { depth: 4 });
}

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
  clearTimeout(idleTimer);
  if (browser && browser.connected) return browser;
  if (!launching) {
    launching = launchBrowser().finally(() => { launching = null; });
  }
  return launching;
}

async function launchBrowser() {
  if (browser) await closeBrowser('desconectado');
  const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || await puppeteer.executablePath();
  if (process.platform === 'linux' && !process.env.PUPPETEER_EXECUTABLE_PATH) {
    for (const path of [executablePath, join(dirname(executablePath), 'chrome_crashpad_handler'), join(dirname(executablePath), 'chrome_sandbox')]) {
      if (!existsSync(path)) continue;
      const mode = statSync(path).mode & 0o777;
      if ((mode & 0o111) !== 0o111) {
        chmodSync(path, mode | 0o111);
        console.log('Permiso de ejecución corregido:', basename(path), (statSync(path).mode & 0o777).toString(8));
      }
    }
  }
  browser = await puppeteer.launch({
    headless: true,
    executablePath,
    // Avoid a localhost WebSocket: some hosted runtimes reject that connection.
    pipe: true,
    dumpio: process.env.PDF_BROWSER_DEBUG !== '0',
    // Menos procesos auxiliares: sin zygote, GPU, informes de fallos ni procesos por sitio.
    args: [
      '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--no-zygote', '--disable-gpu',
      '--disable-extensions', '--disable-background-networking', '--disable-breakpad', '--disable-crash-reporter',
      '--disable-component-update', '--no-first-run', '--renderer-process-limit=2',
      '--disable-features=site-per-process,Translate,BackForwardCache,MediaRouter',
    ],
  });
  browser.on('disconnected', () => { if (browser && !browser.connected) closeBrowser('desconectado'); });
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
      if (activeJobs === 0) scheduleIdleClose();
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify({ ok: instance.connected, activeJobs, waiting: waiting.length }));
    } catch (error) {
      console.error(`Chromium no inició: ${errorDetails(error)}`);
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
  try {
    await acquireSlot();
  } catch {
    response(res, 503, 'Generador ocupado');
    return;
  }
  let page;
  try {
    const html = await readHtml(req);
    page = await (await getBrowser()).newPage();
    browserPages++;
    await page.setContent(html, { waitUntil: 'networkidle2', timeout: 20000 });
    await page.emulateMediaType('print');
    const pdf = await page.pdf({ format: 'A4', printBackground: true, preferCSSPageSize: true, displayHeaderFooter: false, timeout: 30000 });
    res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Length': pdf.length, 'Cache-Control': 'no-store' });
    res.end(pdf);
  } catch (error) {
    console.error(`No se pudo generar el PDF: ${errorDetails(error)}`);
    if (!res.headersSent) response(res, 500, 'No se pudo generar el PDF');
  } finally {
    if (page) await page.close().catch(() => {});
    releaseSlot();
  }
});

if (!Number.isInteger(port) || port < 1 || port > 65535 || !username || !password) {
  console.error('Configura PORT, PDF_SERVICE_USER y PDF_SERVICE_PASSWORD.');
  process.exit(1);
}

server.listen(port, '0.0.0.0', () => console.log(`SKC PDF renderer listening on ${port} (Chromium transport: pipe)`));

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, async () => {
    server.close();
    await closeBrowser(signal);
    process.exit(0);
  });
}
