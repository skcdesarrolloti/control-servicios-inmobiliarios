<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$root = dirname(__DIR__);
$output = $root . '/output/pdf';
if (!is_dir($output) && !mkdir($output, 0755, true) && !is_dir($output)) {
  throw new RuntimeException('No se pudo crear output/pdf.');
}

define('SCM_UPLOAD_PATH', $output);
define('SCM_BASE_URL', 'https://ejemplo.sucasainmobiliaria.com.co');
define('SCM_APP_SECRET', str_repeat('e', 64));
define('SCM_UPLOAD_MAX_BYTES', 10485760);

require $root . '/vendor/autoload.php';

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
$sender = [
  'name' => 'DILSA ROSA BABILONIA TEJEDOR',
  'cargo' => 'Servicio al Arrendatario - Cartera',
  'phone' => '313 5007154',
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
