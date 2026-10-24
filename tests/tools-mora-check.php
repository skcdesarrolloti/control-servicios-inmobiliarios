<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\Tools\LateFeeCalculator;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
};

// 25 % E.A. → mora anual 37,5 % → mensual 3,125 %; 364 días de mora sobre 2.500.000.
$result = LateFeeCalculator::run([
  'annual_effective_rate_percent' => 25,
  'global_penalty_percent' => 0,
  'installments' => [['concept' => 'Canon enero', 'amount' => '2500000', 'due_date' => '2025-01-01', 'payment_date' => '2025-12-31']],
]);
$item = $result['items'][0];
$assert($result['rates']['mora_monthly_rate_percent'] === 3.125, 'mora mensual = 1,5 × E.A. / 12');
$assert($item['days_late'] === 364 && $item['interest_mora'] === 947916.67 && $item['recommended_method'] === 'interes_mora', 'interés = capital × tasa mensual / 30 × días');

// La cláusula penal gana cuando es mayor; servicios públicos se totalizan aparte; fechas dd/mm/aaaa y miles con coma decimal.
$result = LateFeeCalculator::run([
  'annual_effective_rate_percent' => '19.06',
  'global_penalty_percent' => 4,
  'global_payment_date' => '2026-10-24',
  'installments' => [
    ['concept' => 'Canon agosto', 'amount' => '1.500.000,00', 'due_date' => '2026-08-05', 'payment_date' => '', 'paid_amount' => 200000],
    ['concept' => 'Servicio de energia', 'amount' => 180000, 'due_date' => '05/09/2026', 'payment_date' => '2026-10-01', 'penalty_percent' => '10'],
    ['concept' => 'Canon octubre', 'amount' => 1500000, 'due_date' => '2026-10-30', 'payment_date' => '2026-10-24'],
  ],
]);
[$august, $energy, $october] = $result['items'];
$assert($august['payment_date'] === '2026-10-24' && $august['days_late'] === 80, 'empty payment date uses the global one');
$assert($august['interest_mora'] === 95300.0 && $august['clausula_penal'] === 60000.0 && $august['recommended_charge'] === 95300.0, 'mayor entre interés y cláusula penal');
$assert($energy['installment_type'] === 'servicios_publicos' && $energy['recommended_method'] === 'clausula_penal' && $energy['recommended_charge'] === 18000.0, 'per-row penalty and services type');
$assert($october['days_late'] === 0 && $october['recommended_charge'] === 0.0, 'not yet due charges nothing');
$assert($result['summary']['total_recommended_charge'] === 113300.0 && $result['summary']['by_type']['servicios_publicos']['total_capital'] === 180000.0, 'summary totals');

foreach ([
  [[], 'La tasa efectiva anual debe ser mayor a cero.'],
  [['annual_effective_rate_percent' => 1], 'Debes registrar al menos un canon.'],
  [['annual_effective_rate_percent' => 1, 'installments' => [['amount' => 1]]], 'La fecha de vencimiento es obligatoria en la fila 1.'],
] as [$input, $message]) {
  $error = '';
  try {
    LateFeeCalculator::run($input);
  } catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
  }
  $assert($error === $message, 'validation: ' . $message);
}

echo "OK tools late fee calculator\n";
