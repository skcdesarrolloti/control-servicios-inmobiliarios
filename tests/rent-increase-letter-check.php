<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\RentIncrease\RentIncreasePdfGenerator;
use SCM\Modules\RentIncrease\RentIncreaseService;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

$service = new RentIncreaseService(App::db());
$method = new ReflectionMethod($service, 'moneyToSpanish');
$method->setAccessible(true);

$letters = (string) $method->invoke($service, 14620525.0);
$assert(
  $letters === 'CATORCE MILLONES SEISCIENTOS VEINTE MIL QUINIENTOS VEINTICINCO PESOS',
  'rent increase amount is written in legal Spanish words'
);

$context = [
  'fecha_ts' => strtotime('2026-09-17'),
  'ciudad' => 'Cartagena de Indias',
  'arrendatario' => 'Arrendatario de prueba',
  'contrato' => 'PRUEBA-840',
  'inmueble' => '10661',
  'direccion' => 'Dirección de prueba',
  'representante_legal' => 'Representante Legal',
  'contractual' => 'Julian Maturana Ortega',
  'correo_contractual' => 'sucasa.inmobiliaria@hotmail.com',
  'celular_contractual' => '3145965047',
  'incremento' => '8.1%',
  'canon' => 14620525,
  'canon_letras' => $letters,
];

$document = (new RentIncreasePdfGenerator())->generate('canon', $context);
$content = (string) file_get_contents((string) $document['path']);
@unlink((string) $document['path']);

$assert(str_contains($content, 'CATORCE MILLONES SEISCIENTOS'), 'generated PDF stores amount words before numeric pesos');
$assert(str_contains($content, '$' . chr(160) . '14.620.525'), 'generated PDF keeps numeric pesos together');
$assert(preg_match('/cl.usula cuarta/i', $content) === 1, 'generated PDF uses clause-fourth canon text');
