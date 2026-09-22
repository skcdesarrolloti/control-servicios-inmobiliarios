<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\PublicServicesLiquidator\PublicServicesLiquidationCalculator;

$calculator = new PublicServicesLiquidationCalculator();
$result = $calculator->calculate([
  'fecha_lectura_anterior' => '2026-09-01',
  'lectura_anterior' => '100',
  'fecha_entrega' => '2026-09-11',
  'lectura_inicial' => '130',
  'fecha_lectura_actual' => '2026-10-01',
  'lectura_actual' => '190',
  'valor_consumo_facturado' => '90000',
  'total_factura' => '150000',
  'otros_cargos_inquilino' => '5000',
  'otros_cargos_no_inquilino' => '3000',
  'cargos_fijos' => ['10000', '20000'],
]);

$assertNear = static function (string $label, $actual, float $expected): void {
  if (!is_numeric($actual) || abs((float) $actual - $expected) > 0.01) {
    fwrite(STDERR, $label . ' esperado ' . $expected . ', recibido ' . var_export($actual, true) . PHP_EOL);
    exit(1);
  }
};

$assertSame = static function (string $label, $actual, string $expected): void {
  if ((string) $actual !== $expected) {
    fwrite(STDERR, $label . ' esperado ' . $expected . ', recibido ' . var_export($actual, true) . PHP_EOL);
    exit(1);
  }
};

$assertNear('consumo_total_periodo', $result['consumo_total_periodo'] ?? null, 90.0);
$assertNear('consumo_antes_entrega', $result['consumo_antes_entrega'] ?? null, 30.0);
$assertNear('consumo_inquilino', $result['consumo_inquilino'] ?? null, 60.0);
$assertNear('valor_unidad', $result['valor_unidad'] ?? null, 1000.0);
$assertNear('cargo_fijo_antes_entrega', $result['cargo_fijo_antes_entrega'] ?? null, 10000.0);
$assertNear('cargo_fijo_inquilino', $result['cargo_fijo_inquilino'] ?? null, 20000.0);
$assertNear('parte_anterior_propietario', $result['parte_anterior_propietario'] ?? null, 43000.0);
$assertNear('total_pagar_inquilino', $result['total_pagar_inquilino'] ?? null, 85000.0);
$assertNear('valor_reembolsar', $result['valor_reembolsar'] ?? null, 65000.0);
$assertSame('validacion lectura', $result['validaciones']['lectura_inicial_rango'] ?? '', 'OK');
$assertSame('validacion fecha', $result['validaciones']['fecha_entrega_ciclo'] ?? '', 'OK');

echo "OK public services liquidator calculator\n";
