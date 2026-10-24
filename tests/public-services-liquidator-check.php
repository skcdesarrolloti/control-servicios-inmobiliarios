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

// Valores escritos con formato colombiano (punto de miles, coma decimal) deben dar el mismo resultado.
$colombian = $calculator->calculate([
  'fecha_lectura_anterior' => '01/09/2026',
  'lectura_anterior' => '1.100',
  'fecha_entrega' => '2026-09-11',
  'lectura_inicial' => '1.130',
  'fecha_lectura_actual' => '2026-10-01',
  'lectura_actual' => '1.190',
  'valor_consumo_facturado' => '$ 90.000',
  'total_factura' => '150.000,00',
  'otros_cargos_inquilino' => '5.000',
  'otros_cargos_no_inquilino' => '3000',
  'cargos_fijos' => ['10.000', '20.000'],
]);
$assertNear('consumo_total_periodo (formato colombiano)', $colombian['consumo_total_periodo'] ?? null, 90.0);
$assertNear('valor_reembolsar (formato colombiano)', $colombian['valor_reembolsar'] ?? null, 65000.0);
$assertNear('lectura con coma decimal', $calculator->calculate(['lectura_anterior' => '10,5', 'lectura_actual' => '20,5'])['consumo_total_periodo'] ?? null, 10.0);
$assertNear('monto con varios puntos', $calculator->calculate(['total_factura' => '1.250.000'])['input']['total_factura'] ?? null, 1250000.0);

// Un servicio marcado sin datos debe reportar qué falta en lugar de liquidarse en $0.
$missing = $calculator->missingFields(['fecha_lectura_anterior' => '2026-09-01', 'lectura_anterior' => '0', 'total_factura' => '0']);
$assertSame('campos faltantes', implode('|', $missing), 'Fecha entrega inmueble|Lectura entrega llaves|Fecha lectura actual|Lectura actual factura|Valor consumo facturado|Total factura liquidada');
$assertSame('sin faltantes', implode('|', $calculator->missingFields([
  'fecha_lectura_anterior' => '2026-09-01', 'lectura_anterior' => '100', 'fecha_entrega' => '2026-09-11', 'lectura_inicial' => '130',
  'fecha_lectura_actual' => '2026-10-01', 'lectura_actual' => '190', 'valor_consumo_facturado' => '90000', 'total_factura' => '150000',
])), '');

echo "OK public services liquidator calculator\n";
