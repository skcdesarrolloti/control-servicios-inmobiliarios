const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');

(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'scm-panel-case-qa-'));
  const uploadDir = path.join(temp, 'uploads');
  fs.mkdirSync(uploadDir);
  const autoload = path.resolve(__dirname, '../vendor/autoload.php').replace(/\\/g, '/');
  const endpoint = path.join(temp, 'index.php');
  const phpBinary = execFileSync('php', ['-r', 'echo PHP_BINARY;'], {windowsHide: true}).toString();
  const phpArgs = fs.existsSync(path.join(path.dirname(phpBinary), 'ext/php_gd.dll')) ? ['-d', 'extension=gd'] : [];
  fs.writeFileSync(endpoint, `<?php
require '${autoload}';
define('SCM_UPLOAD_PATH', __DIR__ . '/uploads');
define('SCM_BASE_URL', 'https://example.invalid');
define('SCM_APP_SECRET', str_repeat('q', 64));
define('SCM_UPLOAD_MAX_BYTES', 10485760);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo '{}'; exit; }
$attachments = new SCM\\Support\\PanelCaseAttachments();
try {
  $attachments->validate($_POST);
  $stored = $attachments->store();
  echo json_encode(['ok' => true, 'stored' => $stored, 'gd' => extension_loaded('gd')]);
  $attachments->cleanup($stored);
} catch (Throwable $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()]); }
`);
  const port = 18763;
  const server = spawn('php', [...phpArgs, '-S', `127.0.0.1:${port}`, '-t', temp], {stdio: 'ignore', windowsHide: true});
  try {
    let ready = false;
    for (let i = 0; i < 30 && !ready; i++) {
      try { await fetch(`http://127.0.0.1:${port}/`); ready = true; } catch (_) { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    assert(ready, 'isolated upload fixture server starts');
    const png = phpArgs.length ? execFileSync('php', [...phpArgs, '-r', '$image=imagecreatetruecolor(2000,1000); imagepng($image); imagedestroy($image);'], {windowsHide: true}) : Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII=', 'base64');
    const post = async (choice, image, document) => {
      const body = new FormData(); body.set('has_attachments', choice);
      if (image) body.append('imagenes[]', new Blob([image], {type: 'image/png'}), 'evidencia.png');
      if (document) body.append('archivos[]', new Blob([document], {type: 'application/pdf'}), 'informe.pdf');
      return (await fetch(`http://127.0.0.1:${port}/`, {method: 'POST', body})).json();
    };
    const result = await post('Si', png, '%PDF-1.4\n%fixture\n%%EOF');
    assert(result.ok, result.message);
    assert.equal(result.stored.images.length, 1);
    assert.equal(result.stored.documents.length, 1);
    assert(result.stored.images[0].url.includes('file.php?n='), 'image uses signed storage URL');
    assert(result.stored.documents[0].archivo.includes('&s='), 'document uses signed storage URL');
    if (result.gd) assert.equal(result.stored.images[0].width, 1600, 'large image compressed to supported dimensions');
    assert.equal((await post('Si', Buffer.from('fake-image'))).ok, false, 'fake image rejected');
    assert.equal((await post('Si', png, '<html>not a pdf</html>')).ok, false, 'fake PDF rejected before storing images');
    assert.equal((await post('No', png)).ok, false, 'No attachments rejects unexpected upload');
    assert.equal((await post('Si')).ok, false, 'Yes attachments requires files');
    assert.equal((await post('No')).ok, true, 'case without attachments is accepted');
    assert.deepEqual(fs.readdirSync(uploadDir), [], 'stored evidence cleanup leaves no orphan files');
    console.log('PASS: real multipart images and PDF, compression, signatures, invalid uploads and cleanup');
  } finally {
    server.kill();
    await new Promise(resolve => server.once('exit', resolve));
    fs.unlinkSync(endpoint);
    fs.rmdirSync(uploadDir);
    fs.rmdirSync(temp);
  }
})().catch(e => { console.error(e); process.exit(1); });
