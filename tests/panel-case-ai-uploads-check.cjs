const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');

(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'scm-case-ai-'));
  const autoload = path.resolve(__dirname, '../vendor/autoload.php').replace(/\\/g, '/');
  const endpoint = path.join(temp, 'index.php');
  const phpBinary = execFileSync('php', ['-r', 'echo PHP_BINARY;'], {windowsHide: true}).toString();
  const phpArgs = fs.existsSync(path.join(path.dirname(phpBinary), 'ext/php_gd.dll')) ? ['-d', 'extension=gd'] : [];
  fs.writeFileSync(endpoint, `<?php
require '${autoload}';
header('Content-Type: application/json');
try {
  $images = (new SCM\\Support\\PanelCaseAi())->images($_FILES['ai_images'] ?? []);
  $result = [];
  foreach ($images as $image) {
    $bytes = base64_decode(explode(',', $image, 2)[1]);
    $info = getimagesizefromstring($bytes);
    $result[] = ['width' => $info[0], 'height' => $info[1], 'mime' => $info['mime']];
  }
  echo json_encode(['ok' => true, 'images' => $result, 'gd' => extension_loaded('gd')]);
} catch (Throwable $e) { echo json_encode(['ok' => false, 'message' => $e->getMessage()]); }
`);
  const server = spawn('php', [...phpArgs, '-d', 'post_max_size=25M', '-d', 'upload_max_filesize=10M', '-S', '127.0.0.1:18765', '-t', temp], {stdio: 'ignore', windowsHide: true});
  try {
    let ready = false;
    for (let i = 0; i < 30 && !ready; i++) {
      try { await fetch('http://127.0.0.1:18765/'); ready = true; } catch (_) { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    assert(ready);
    const png = phpArgs.length ? execFileSync('php', [...phpArgs, '-r', '$image=imagecreatetruecolor(3000,1200); imagepng($image); imagedestroy($image);'], {windowsHide: true}) : Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1kAAAAASUVORK5CYII=', 'base64');
    const post = async images => {
      const body = new FormData();
      for (const image of images) body.append('ai_images[]', new Blob([image], {type: 'image/png'}), 'source.png');
      return (await fetch('http://127.0.0.1:18765/', {method: 'POST', body})).json();
    };
    const result = await post([png]);
    assert(result.ok, result.message);
    assert.equal(result.images.length, 1);
    if (result.gd) { assert.equal(result.images[0].width, 2400); assert.equal(result.images[0].mime, 'image/jpeg'); }
    assert.equal((await post([])).ok, true);
    assert.equal((await post([Buffer.from('<svg onload="bad()"></svg>')])).ok, false, 'SVG renamed PNG rejected');
    assert.equal((await post([Buffer.alloc(6 * 1024 * 1024)])).ok, false, 'oversized screenshot rejected');
    assert.equal((await post(Array(5).fill(png))).ok, false, 'too many screenshots rejected');
    assert.equal((await post([png, Buffer.from('bad-image')])).ok, false, 'mixed valid and invalid screenshots fail together');
    assert.deepEqual(fs.readdirSync(temp), ['index.php'], 'source screenshots never stored as public evidence');
    console.log('PASS: real multipart AI screenshots, MIME validation, compression, upload limits and no saved evidence');
  } finally {
    server.kill(); await new Promise(resolve => server.once('exit', resolve)); fs.unlinkSync(endpoint); fs.rmdirSync(temp);
  }
})().catch(e => { console.error(e); process.exit(1); });
