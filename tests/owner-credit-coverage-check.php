<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\OwnerCredits\OwnerCreditCoverage as Coverage;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$ts = static fn(string $date): int => (int) strtotime($date);
$day = static fn(int $value): string => date('Y-m-d', $value);

// Calendario (pruebas.php del paquete): corte 15/16, día 30 y último día de febrero.
$assert($day(Coverage::firstDueTs($ts('2026-10-15'))) === '2026-10-30' && $day(Coverage::firstDueTs($ts('2026-10-16'))) === '2026-11-30', 'disbursement up to the 15th pays that month; from the 16th, the next one');
$assert(array_map($day, Coverage::dueDates($ts('2027-01-30'), 3)) === ['2027-01-30', '2027-02-28', '2027-03-30'], 'installments fall on the 30th and on the last day of February');
$assert($day(Coverage::installmentTs($ts('2027-12-30'), 3)) === '2028-02-29', 'leap February ends on the 29th');
$assert($day(Coverage::firstDueTs($ts('2026-01-31'))) === '2026-02-28', 'a disbursement on the 31st pays at the end of February');

// Caso de demostración del paquete (datos_ejemplo_cobertura.php): 9 → 6 meses. Factor 50 % SOLO de prueba.
$first = Coverage::firstDueTs($ts('2026-10-26'));
$contracts = [
  ['id' => 1, 'contrato' => 'C1', 'inmueble' => 'INM-001', 'net_income' => 3000000.0, 'start_ts' => $ts('2026-01-01'), 'end_ts' => $ts('2027-01-31'), 'active' => true],
  ['id' => 2, 'contrato' => 'C2', 'inmueble' => 'INM-002', 'net_income' => 800000.0, 'start_ts' => $ts('2026-01-01'), 'end_ts' => $ts('2027-09-30'), 'active' => true],
  ['id' => 3, 'contrato' => 'cr', 'inmueble' => 'INM-003', 'net_income' => 1200000.0, 'start_ts' => $ts('2026-01-01'), 'end_ts' => $ts('2027-04-30'), 'active' => true],
];
$slots = Coverage::availability(Coverage::dueDates($first, 12), $contracts, 0.5, array_fill(0, 12, 0.0));
$initial = Coverage::check(4000000, 0.022, 9, $slots, 1.0);
$assert(abs($initial['payment'] - 494750.94) < 0.001 && !$initial['ok'], 'the 9-month plan pays $494.750,94 and is not covered');
$gap = $initial['shortfall'];
$assert($day($gap['fecha']) === '2027-05-30' && abs($gap['disponible'] - 400000) < 0.001 && abs($gap['faltante_pago'] - 94750.94) < 0.001, 'first shortfall on 30/05/2027: $400.000 available, $94.750,94 missing');
$assert(array_column($gap['bajas'], 'contrato') === ['cr'], 'the shortfall is explained by contract cr ending on 30/04/2027');
foreach ([9, 8, 7] as $term) $assert(!Coverage::check(4000000, 0.022, $term, $slots, 1.0)['ok'], "{$term} months is not covered");
$best = Coverage::bestTerm(4000000, 0.022, 9, $slots, 1.0);
$six = Coverage::check(4000000, 0.022, 6, $slots, 1.0);
$assert($best['term'] === 6 && abs($best['payment'] - 718930.64) < 0.001 && $day($best['last_ts']) === '2027-04-30', 'the longest covered term is 6 months at $718.930,64, last payment 30/04/2027');
$sixPlan = Coverage::plan(4000000, 0.022, 6)['rows'];
$assert(abs($six['rows'][5]['cuota'] - 718930.62) < 0.001 && abs($sixPlan[5]['saldo']) < 0.001, 'the last installment is adjusted to $718.930,62 and leaves no balance');

// Con cr hasta junio (caso del Excel anterior) baja de 9 a 8; al 100 % conserva 9 y nunca pasa del plazo pedido.
$june = $contracts;
$june[2]['end_ts'] = $ts('2027-06-30');
$juneSlots = Coverage::availability(Coverage::dueDates($first, 12), $june, 0.5, array_fill(0, 12, 0.0));
$eight = Coverage::bestTerm(4000000, 0.022, 9, $juneSlots, 1.0);
$assert($eight['term'] === 8 && abs($eight['payment'] - 550756.08) < 0.001, 'with cr until June the term goes from 9 to 8 at $550.756,08');
$full = Coverage::availability(Coverage::dueDates($first, 12), $contracts, 1.0, array_fill(0, 12, 0.0));
$assert(Coverage::bestTerm(4000000, 0.022, 9, $full, 1.0)['term'] === 9, 'with 100 % usable income the requested 9 months are kept, never more');

// Cobertura mínima mayor que 1, otras obligaciones y ningún plazo posible.
$assert(!Coverage::check(4000000, 0.022, 6, $slots, 1.5)['ok'] && Coverage::check(4000000, 0.022, 6, $slots, 1.0)['ok'], 'a minimum coverage above 1 demands a margin over the installment');
$withDebt = Coverage::availability(Coverage::dueDates($first, 12), $contracts, 0.5, array_fill(0, 12, 300000.0));
$assert(abs($withDebt[0]['disponible'] - 2200000) < 0.001, 'other obligations are subtracted from the available income');
$assert(Coverage::bestTerm(40000000, 0.022, 12, $slots, 1.0)['term'] === 0, 'no term is covered when the amount is too high for the income');

// Capital máximo que conserva el plazo pedido: cumple y un peso más ya no.
$capital = Coverage::maxCapital(0.022, 9, $slots, 1.0);
$assert($capital > 0 && Coverage::check($capital, 0.022, 9, $slots, 1.0)['ok'] && !Coverage::check($capital + 1, 0.022, 9, $slots, 1.0)['ok'], 'max capital for 9 months is covered and one peso more is not');
$assert(Coverage::maxCapital(0.022, 9, $slots, 1.0) < 4000000, 'the max capital for the requested term is below the requested amount');

// Tasa cero y contratos inactivos o sin iniciar.
$assert(abs(Coverage::payment(1200, 0.0, 12) - 100) < 0.001, 'zero rate splits the capital evenly');
$idle = Coverage::availability(Coverage::dueDates($first, 2), [['id' => 9, 'contrato' => 'X', 'inmueble' => 'I', 'net_income' => 1000000.0, 'start_ts' => 0, 'end_ts' => $ts('2030-01-01'), 'active' => false], ['id' => 8, 'contrato' => 'Y', 'inmueble' => 'J', 'net_income' => 500000.0, 'start_ts' => $ts('2027-01-01'), 'end_ts' => $ts('2030-01-01'), 'active' => true]], 1.0, [0.0, 0.0]);
$assert($idle[0]['disponible'] == 0.0 && $idle[1]['disponible'] == 0.0, 'inactive contracts and contracts not started yet add nothing');

echo "Owner credit coverage checks passed.\n";
