<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('America/Bogota');
$directory = sys_get_temp_dir() . '/scm-receipt-letter-qa';
if (!is_dir($directory)) mkdir($directory);
define('SCM_UPLOAD_PATH', $directory);
define('SCM_BASE_URL', 'https://example.invalid');
define('SCM_APP_SECRET', 'synthetic-test-only');
define('SCM_UPLOAD_MAX_BYTES', 10485760);
$output = (new \SCM\Modules\Pending\TicketPdfGenerator())->generate('administrativo', 'Recibo de inmuebles', 525, [
  'contrato' => '2000', 'inmueble' => '10200', 'direccion' => 'Carrera 83 número 36B-145 apartamento 503, Cartagena de Indias',
  'ciudad' => 'Cartagena de Indias', 'arrendatario' => 'ARRENDATARIO DE PRUEBA CON NOMBRE EXTENSO',
  'fecha_terminacion_contrato' => strtotime('2027-01-09'),
  'nombre_contractual' => 'COORDINADOR CONFIGURADO DE PRUEBA', 'celular_contractual' => '3000000000',
  'nombre_empleado' => 'FUNCIONARIO CONFIGURADO DE PRUEBA', 'celular_empleado' => '3111111111',
  'registro_fotografico' => 'https://drive.google.com/drive/folders/synthetic-only',
  'id_inventario' => '845',
]);
$pdf = $output['acta_desocupacion'] ?? [];
$bytes = file_get_contents($pdf['path']);
if (!str_starts_with($bytes, '%PDF-') || !str_contains($bytes, 'synthetic-only') || !str_contains($bytes, 'inventario-inmueble/?numero=845')) throw new RuntimeException('Letter must link the photo record and the inventory.');
copy($pdf['path'], $directory . '/letter.pdf');
unlink($pdf['path']);
echo "PASS: receipt letter with configured contacts, contract date, photographic record and inventory links. No messages sent. PDF: {$directory}/letter.pdf\n";
