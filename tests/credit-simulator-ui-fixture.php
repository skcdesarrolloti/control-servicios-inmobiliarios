<?php

declare(strict_types=1);

// HTML real para tests/credit-simulator-ui-check.cjs: respuesta pública con simulación y Herramientas › Simulador de crédito.
require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Modules\OwnerCredits\OwnerCreditSettings;
use SCM\Modules\OwnerCredits\OwnerCreditSimulator;
use SCM\Modules\Tools\CreditSimulationService;
use SCM\Modules\Tools\ToolsView;

$app = (new ReflectionClass(\SCM\App\SuCasaControlServiciosInmobiliarios::class))->newInstanceWithoutConstructor();
// Sin base de datos el panel sale sin simulación: se agrega la de una cotización de $893.723 con máximo 3 meses.
$panel = (new ReflectionMethod($app, 'render_public_cotizacion_response_panel'))->invoke($app, ['_ID' => '574', 'estado' => 'Esperando respuesta', 'destinatario' => 'Ana Pérez']);
$plans = [];
for ($term = 1; $term <= 3; $term++) $plans[$term] = OwnerCreditSimulator::simulate(893723, $term, 0.0242);
$preview = (new ReflectionMethod($app, 'render_public_quote_financing_preview'))->invoke($app, ['amount' => 893723.0, 'rate' => 0.0242, 'effective_annual' => OwnerCreditSimulator::effectiveAnnual(0.0242), 'max_term' => 3, 'plans' => $plans]);
$panel = str_replace('name="plazo_financiacion" min="1"', 'name="plazo_financiacion" min="1" max="3"', $panel);
$panel = str_replace('<label class="is-wide"><span>Observaciones', $preview . '<label class="is-wide"><span>Observaciones', $panel);

$settings = ['usury_annual' => 0.35, 'usury_updated_at' => date('Y-m-01'), 'usury_valid_until' => date('Y-m-t')] + OwnerCreditSettings::defaults();
$service = new CreditSimulationService($settings);
$simulate = static function (array $raw) use ($service): array {
  $result = $service->simulate($service->input($raw));
  $sim = $result['simulation'];
  $sim['rows'] = array_map(static fn(array $row): array => ['fecha_texto' => date('d/m/Y', (int) $row['fecha'])] + $row, $sim['rows']);
  return ['simulation' => ['first_due' => date('d/m/Y', (int) $sim['first_due_ts']), 'last_due' => date('d/m/Y', (int) $sim['last_due_ts'])] + $sim, 'compare' => $result['compare'], 'warnings' => $result['warnings'], 'summary' => CreditSimulationService::summary($result, ['name' => 'Royner Guardo', 'cargo' => 'Asistente de Desarrollo TI', 'phone' => '3015232054'])];
};

echo json_encode([
  'publicHtml' => $panel,
  'toolHtml' => (new ToolsView())->creditSimulator($service->defaults()),
  'responses' => [
    '12' => $simulate(['valor' => '2.500.000', 'plazo' => '12', 'tasa' => '2,42', 'desembolso' => '2026-11-20']),
    '6' => $simulate(['valor' => '2.500.000', 'plazo' => '6', 'tasa' => '2,42', 'desembolso' => '2026-11-20']),
  ],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
