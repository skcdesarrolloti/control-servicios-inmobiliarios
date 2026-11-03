<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Modules\Tools\OwnerNetIncomeSimulator;
use SCM\Modules\Tools\OwnerPreapprovalPdf;
use SCM\Modules\Tools\OwnerPreapprovalService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

// Tarifas como las filas de jet_cct_confi_sistema.
$rates = OwnerNetIncomeSimulator::rates(['iva' => '19', 'tasa_administracion_arriendo' => '10', 'tasa_libertador_seguro' => '2,5', 'tasa_libertador_servicios' => '1', 'tasa_prestamo_garantias_servicios' => '2', 'tasa_berkley' => '3', 'gasto_expedicion_berkley' => '$50.000']);
$check($rates['comision'] === 10.0 && $rates['aseguradoras']['El Libertador']['seguro'] === 2.5 && $rates['berkley']['expedicion'] === 50000.0, 'rates are read from the system rows');

$sim = OwnerNetIncomeSimulator::simulate(['canon' => 2000000, 'administracion' => 200000, 'modalidad' => 'defecto', 'aseguradora' => 'El Libertador', 'garantia' => true, 'servicios' => true, 'valor_asegurado' => 3000000, 'cuotas' => 1], $rates);
// Comisión 10 % de 2.200.000 = 220.000 + IVA 19 % = 261.800; garantía 2,5 % de 2.200.000 = 55.000; servicios 1 % de 3.000.000.
$check($sim['comision'] === 261800.0 && $sim['garantia'] === 55000.0 && $sim['seguro'] === 30000.0 && $sim['intereses'] === 0.0, 'commission and guarantee are charged on canon + administration');
$financed = OwnerNetIncomeSimulator::simulate(['canon' => 2000000, 'modalidad' => 'defecto', 'aseguradora' => 'El Libertador', 'servicios' => true, 'valor_asegurado' => 3000000, 'cuotas' => 6], $rates);
$check($financed['cuotas'] === 6 && $financed['intereses'] > 0, 'from 5 installments the services insurance is financed');

// El cálculo del PDF no usa la base de datos cuando las tarifas ya están cargadas.
$service = (new ReflectionClass(OwnerPreapprovalService::class))->newInstanceWithoutConstructor();
$property = new ReflectionProperty(OwnerPreapprovalService::class, 'rates');
$property->setAccessible(true);
$property->setValue($service, $rates);
$settings = new ReflectionProperty(OwnerPreapprovalService::class, 'settings');
$settings->setAccessible(true);
$settings->setValue($service, null);
$estimate = null;
try {
  $estimate = $service->estimate(['owner' => ['name' => 'Ana', 'document' => '111', 'phone' => '3000000000', 'email' => 'ana@example.invalid'],
    'sources' => [['calc' => 'sim', 'canon' => 2000000, 'administracion' => 200000, 'modalidad' => 'defecto', 'aseguradora' => 'El Libertador', 'garantia' => true, 'servicios' => true, 'valor_asegurado' => 3000000, 'cuotas' => 1, 'duracion' => 12]]]);
} catch (\Throwable $error) {
  // Sin App configurada, settings() usa los valores por defecto del liquidador.
  fwrite(STDERR, $error->getMessage() . "\n");
}
$expectedNet = 2000000 - 261800 - 55000 - 30000 / 12;
$check(is_array($estimate) && abs($estimate['net'] - $expectedNet) < 0.01 && abs($estimate['amount'] - $expectedNet * $estimate['income_months']) < 0.01, 'net income = canon − commission − guarantee − insurance / 12, credit = net × policy months');
$check($estimate['term'] === 12 - $estimate['term_margin_months'], 'the term is the contract duration minus the margin');

$failed = '';
try {
  $service->estimate(['owner' => ['name' => 'Ana', 'document' => '111', 'phone' => '300', 'email' => 'no-es-correo'], 'sources' => [['canon' => 1000000]]]);
} catch (\InvalidArgumentException $error) {
  $failed = $error->getMessage();
}
$check(str_contains($failed, 'correo'), 'an invalid owner email is rejected');

$html = OwnerPreapprovalPdf::html($estimate, ['name' => 'Royner Guardo', 'cargo' => 'Asistente de Desarrollo TI']);
$check(str_contains($html, 'Crédito preaprobado estimado') && str_contains($html, 'SKC SuCasa Inmobiliaria') && str_contains($html, 'Royner Guardo'), 'the PDF names the credit, the company and who signs');

echo "Owner preapproval checks passed.\n";
