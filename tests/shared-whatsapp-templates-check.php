<?php
declare(strict_types=1);
// Plantillas de WhatsApp compartidas con control-servicios-comerciales (pqr_trasladada_funcionario_v1,
// scm_cita_funcionario_v1, scm_cita_solicitante_v1): en Meta el botón es https://sucasainmobiliaria.com.co/{{1}}
// y cada panel completa su ruta. Ejecutar: php tests/shared-whatsapp-templates-check.php
require dirname(__DIR__) . '/bootstrap/app.php';
$check = static function (bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); echo 'PASS: ' . $label . PHP_EOL; };
use SCM\Support\PublicCaseAccess;
use SCM\Support\SharedButtonUrl;

$check(SharedButtonUrl::suffix('https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=9') === 'control-servicios-inmobiliarios/public/?scm_case=9', 'the button keeps only the path after the template domain');
$check(SharedButtonUrl::suffix('https://otro.test/public/?scm_case=9') === 'public/?scm_case=9', 'outside production the button keeps path and query');

$referenceOf = static function (string $suffix): string { parse_str((string) parse_url('https://x/' . $suffix, PHP_URL_QUERY), $query); return (string) ($query['scm_case'] ?? ''); };
$staff = SharedButtonUrl::caseSuffix(10945, '1047');
$check(PublicCaseAccess::route($referenceOf($staff), false) === ['mode' => 'login', 'id' => 10945, 'audience' => 'funcionario', 'employee_id' => '1047'], 'staff button signs in the employee and opens the case');
$client = SharedButtonUrl::caseSuffix(10945, '', 'arrendatario');
$check(PublicCaseAccess::route($referenceOf($client), false) === ['mode' => 'public', 'id' => 10945, 'audience' => 'arrendatario'], 'client button opens the public case for its audience');
$check(SharedButtonUrl::caseSuffix(0) !== '', 'without a case the button is never empty (Meta rejects it)');

$root = dirname(__DIR__);
foreach ([
  'src/App/Concerns/HandlesTicketWorkflowActions.php' => ['scm_cita_funcionario_v1', 'scm_cita_solicitante_v1'],
  'src/Modules/ServiciosInmobiliarios/Concerns/WorkflowCommandsConcern.php' => ['pqr_trasladada_funcionario_v1'],
  'src/App/Concerns/HandlesPublicPqrActions.php' => ['pqr_trasladada_funcionario_v1'],
] as $file => $templates) {
  $source = (string) file_get_contents($root . '/' . $file);
  foreach ($templates as $template) {
    $block = substr($source, (int) strpos($source, "'" . $template . "'"), 2500);
    $check(str_contains($block, 'SharedButtonUrl::caseSuffix(') && !preg_match("/'button'.{0,200}'text' => \\\$logicalTicket\\]/s", $block), $template . ' sends the case path, not only the number, in ' . $file);
  }
}

// Los correos de traslado tampoco llevan a la página de tickets anterior.
$functionBody = static function (string $file, string $function) use ($root): string {
  $source = (string) file_get_contents($root . '/' . $file);
  $start = (int) strpos($source, 'function ' . $function . '(');
  $next = strpos($source, "\n  public function ", $start + 10);
  $nextPrivate = strpos($source, "\n  private function ", $start + 10);
  $end = min(array_filter([$next, $nextPrivate, strlen($source)], static fn($value): bool => $value !== false));
  return substr($source, $start, $end - $start);
};
foreach ([
  ['src/Modules/ServiciosInmobiliarios/Concerns/WorkflowCommandsConcern.php', 'notifyTrasladoCaso'],
  ['src/App/Concerns/HandlesPublicPqrActions.php', 'notifyPublicPqrTransferToEmployee'],
] as [$file, $function]) {
  $body = $functionBody($file, $function);
  $check($body !== '' && !str_contains($body, 'sucasainmobiliaria.com.co/ticket/') && !str_contains($body, 'DEFAULT_TICKET_URL'), $function . ' links the signed case, never the old ticket page');
}

// La página de tickets anterior ya no existe: ningún enlace del panel debe llevar a ella.
$legacy = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
  if ($file->getExtension() === 'php' && $file->getFilename() !== 'CaseLink.php' && str_contains((string) file_get_contents($file->getPathname()), 'sucasainmobiliaria.com.co/ticket/')) $legacy[] = $file->getFilename();
}
foreach (glob($root . '/public/{*.php,assets/js/*.js}', GLOB_BRACE) ?: [] as $file) {
  if (!str_contains($file, '.min.') && str_contains((string) file_get_contents($file), 'sucasainmobiliaria.com.co/ticket/')) $legacy[] = basename($file);
}
$check($legacy === [], 'no link points to the old ticket page' . ($legacy !== [] ? ': ' . implode(', ', $legacy) : ''));
$check(SCM\Support\CaseLink::panelUrl(10945) === rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=10945' && SCM\Support\CaseLink::panelUrl('abc') === '', 'case links open ?scm_case=<_ID> in the panel');
