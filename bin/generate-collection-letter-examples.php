<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['employee-id:']);
$employeeId = max(0, (int) ($options['employee-id'] ?? 0));
if ($employeeId <= 0) {
  throw new RuntimeException('Indica el funcionario con --employee-id=ID para resolver su cargo desde id_cargo.');
}

require $root . '/bootstrap/app.php';

$output = $root . '/output/pdf';
if (!is_dir($output) && !mkdir($output, 0755, true) && !is_dir($output)) {
  throw new RuntimeException('No se pudo crear output/pdf.');
}

$_SESSION['scm_user_id'] = $employeeId;
$_SESSION['scm_user'] = '';
$_SESSION['scm_user_rol'] = '';
$_SESSION['scm_user_cargo'] = '';

$sender = (new \SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService($scmDb))->senderProfile();
if ((string) ($sender['name'] ?? '') === 'Funcionario') {
  throw new RuntimeException('No se encontró el funcionario indicado.');
}

$item = [
  'tenant_name' => 'ARRENDATARIO DE EJEMPLO S.A.S.',
  'tenant_document' => '900123456',
  'tenant_email' => 'arrendatario@example.com',
  'contract_number' => '1380-EJEMPLO',
  'property_code' => '1042',
  'property_address' => 'Carrera 2 No. 41-29, Cartagena',
  'balance' => 3360000,
  'source_date' => '2026-08-29',
  'landlord_name' => 'MARÍA CLAUDIA PROPIETARIA DE EJEMPLO',
  'landlord_email' => 'propietaria@example.com',
  'codeudores' => [
    ['nombre' => 'GLORIA STELLA CODEUDORA DE EJEMPLO', 'correo' => 'codeudora@example.com'],
  ],
];
$generator = new \SCM\Modules\CollectionManagement\CollectionLetterPdfGenerator();
$targets = [
  'prejuridico' => $output . '/ejemplo-carta-prejuridica.pdf',
  'siniestro' => $output . '/ejemplo-carta-siniestro.pdf',
];

foreach ($targets as $type => $target) {
  $document = $generator->generate($item, $type, $sender);
  if (!copy((string) $document['path'], $target)) {
    throw new RuntimeException('No se pudo crear ' . basename($target) . '.');
  }
  @unlink((string) $document['path']);
  fwrite(STDOUT, $target . PHP_EOL);
}
