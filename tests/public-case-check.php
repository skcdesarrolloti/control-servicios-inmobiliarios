<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
$check = static function(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo 'PASS: ' . $label . PHP_EOL; };
$reference = SCM\Support\PublicCaseAccess::reference(10945);
$check(SCM\Support\PublicCaseAccess::route($reference, false) === ['mode' => 'public', 'id' => 10945], 'signed case opens publicly');
$check(SCM\Support\PublicCaseAccess::route($reference, true) === ['mode' => 'panel', 'id' => 10945], 'same signed case opens in authenticated panel');
$check(SCM\Support\PublicCaseAccess::route('10945', true)['mode'] === 'panel', 'legacy numeric internal links remain valid with session');
foreach (['10945', str_replace('10945.', '10946.', $reference), substr($reference, 0, -1) . (str_ends_with($reference, 'a') ? 'b' : 'a'), SCM\Support\PublicCaseAccess::reference(10945, time() - 1), '1<script>', '0', 'https://evil.invalid', '1.0000000000.' . str_repeat('a', 64)] as $invalid) {
  $check(SCM\Support\PublicCaseAccess::route($invalid, false)['mode'] === 'denied', 'unsigned, changed, expired or malformed case is denied');
}
$data = SCM\Support\PublicCaseData::fromTicket([
  '_ID' => 10945, 'asunto' => 'Revisión', 'descripcion' => '<p>Descripción visible</p><script>alert(1)</script>', 'fecha' => time(),
  'sucursal' => 'SECRET_BRANCH', 'correo_propietario' => 'SECRET_EMAIL', 'observacion_interna' => 'SECRET_NOTE', 'estado_administrativo' => 'SECRET_ADMIN',
  'imagenes' => 'javascript:alert(1),https://evil.invalid/image.png,https://sucasainmobiliaria.com.co/wp-content/uploads/qa.png',
  'archivos' => serialize([['archivo' => 'https://evil.invalid/file.pdf'], ['archivo' => 'https://sucasainmobiliaria.com.co/wp-content/uploads/qa.pdf']]),
]);
$json = json_encode($data);
$check(!str_contains($json, 'SECRET_') && !str_contains($data['descripcion'], 'alert'), 'public allowlist excludes branch, contacts, administrative state, private notes and scripts');
$check(count($data['images']) === 1 && count($data['documents']) === 1, 'attachment allowlist rejects executable and unknown origins');
$check(SCM\Support\PublicCaseAccess::route(SCM\Support\PublicCaseAccess::reference(10945, time() - 1), true)['mode'] === 'panel', 'expired public link still uses normal authenticated panel permissions');
