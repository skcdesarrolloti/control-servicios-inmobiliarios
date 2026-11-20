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
