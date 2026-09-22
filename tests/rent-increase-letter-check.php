<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\RentIncrease\RentIncreasePdfGenerator;
use SCM\Modules\RentIncrease\RentIncreaseService;
use SCM\Modules\RentIncrease\RentIncreaseView;

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

$view = new RentIncreaseView();
$moneyMethod = new ReflectionMethod($view, 'money');
$moneyMethod->setAccessible(true);
$assert((string) $moneyMethod->invoke($view, '500.000') === '$500.000', 'table formats Colombian thousands as pesos');
$assert((string) $moneyMethod->invoke($view, '1.250.000') === '$1.250.000', 'table keeps million values in pesos');
$assert((string) $moneyMethod->invoke($view, 'No aplica') === 'No aplica', 'table keeps non-money administration values readable');

$tableHtml = $view->renderTable([[
  'contrato' => 'TEST-1',
  'inmueble' => '100',
  'valor_canon' => '1.048.400',
  'valor_administracion' => '251.600',
  'direccion' => 'Direccion',
  'propietario' => 'Propietario',
  'arrendatario' => 'Arrendatario',
]], 'contracts');
$assert(!str_contains($tableHtml, '<small>-</small>'), 'table hides empty increment date dash below money values');

$normalizeUrl = new ReflectionMethod($service, 'normalizeStoredFileUrl');
$normalizeUrl->setAccessible(true);
$normalizedUrl = (string) $normalizeUrl->invoke($service, 'https://sucasainmobiliaria.com.co/file.php?n=abc_123.pdf&s=firma');
$assert(
  str_starts_with($normalizedUrl, rtrim((string) SCM_BASE_URL, '/') . '/file.php?n=abc_123.pdf&s=firma'),
  'stored file URLs are rebuilt against the app base URL'
);
