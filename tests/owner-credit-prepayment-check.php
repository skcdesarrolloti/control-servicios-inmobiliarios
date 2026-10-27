<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\OwnerCredits\OwnerCreditPrepayment as Prepayment;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$ts = static fn(string $date): int => (int) strtotime($date);
$day = static fn(int $value): string => date('Y-m-d', $value);
$cents = static fn(float $a, float $b): bool => abs($a - $b) < 0.001;
$dates = [$ts('2026-11-30'), $ts('2026-12-30'), $ts('2027-01-30')];

// Ejemplo del paquete (datos_ejemplo.php): cuota ordinaria pagada, saldo 758.100,41 y abono de 300.000 el 30/10.
$r = Prepayment::simulate(758100.41, 263899.59, 0.022, 0.022, 0.0, 300000, $dates, 2);
$l = $r['liquidation'];
$assert($cents($l['capital'], 300000) && $cents($l['new_balance'], 458100.41) && $cents($l['surplus'], 0), 'the whole payment goes to capital: new balance $458.100,41');
$term = $r['options']['reduce_term'];
$assert($term['installments'] === 2 && $day($term['last_ts']) === '2026-12-30' && $cents($term['rows'][0]['cuota'], 263899.59) && $cents($term['rows'][1]['cuota'], 208773.17), 'reduce term: 2 payments of $263.899,59 and $208.773,17, ends 30/12/2026');
$pay = $r['options']['reduce_payment'];
$assert($cents($pay['payment'], 159467.68) && $pay['installments'] === 3 && $day($pay['last_ts']) === '2027-01-30' && $cents($pay['rows'][2]['cuota'], 159467.67), 'reduce payment: 3 payments of $159.467,68 (last $159.467,67), ends 30/01/2027');
$assert($cents($term['interest'], 14572.35) && $cents($pay['interest'], 20302.62), 'future interest: $14.572,35 (term) and $20.302,62 (payment)');
$assert($cents($term['savings'], 19026.01) && $cents($pay['savings'], 13295.74) && $term['removed'] === 1 && $pay['removed'] === 0, 'interest saved against no prepayment: $19.026,01 and $13.295,74');
$assert($r === Prepayment::simulate(758100.41, 263899.59, 0.022, 0.022, 0.0, 300000, $dates, 2), 'the simulation is repeatable, without hidden state');

// Tasa cero (pruebas.php).
$zero = Prepayment::simulate(1000, 250, 0.0, 0.0, 0.0, 300, array_merge($dates, [$ts('2027-02-28')]), 2);
$assert($cents($zero['options']['reduce_payment']['payment'], 175) && array_column($zero['options']['reduce_term']['rows'], 'cuota') == [250.0, 250.0, 200.0] && $cents($zero['options']['reduce_payment']['interest'], 0), 'zero rate: 700 / 4 = 175, or 250 + 250 + 200');

// Cancelación total con excedente.
$all = Prepayment::simulate(758100.41, 263899.59, 0.022, 0.022, 0.0, 800000, $dates, 2);
$assert($all['paid_off'] && $cents($all['liquidation']['new_balance'], 0) && $cents($all['liquidation']['surplus'], 41899.59) && $all['options']['reduce_payment']['rows'] === [], 'paying more than the balance cancels it and separates $41.899,59 of surplus');

// Entre vencimientos (REGLAS_FINANCIERAS.txt y pruebas.php): prorrata simple sobre los días reales del periodo.
$a = Prepayment::accrual(1000, 0.03, $ts('2027-01-30'), $ts('2027-02-14'), $ts('2027-02-28'));
$assert($a['days_period'] === 29 && $a['days_elapsed'] === 15 && $a['days_left'] === 14 && $cents($a['interest'], 15.52), '1000 × 3 % × 15 / 29 = $15,52 accrued, 14 days left');
$mid = Prepayment::accrual(758100.41, 0.022, $ts('2026-10-30'), $ts('2026-11-15'), $ts('2026-11-30'));
$assert($cents($mid['interest'], 8608.11), 'a prepayment on 15/11 accrues $8.608,11 (16 of 31 days)');
$between = Prepayment::simulate(758100.41, 263899.59, 0.022, $mid['first_rate'], $mid['interest'], 300000, $dates, 2);
$assert($cents($between['liquidation']['capital'], 291391.89) && $cents($between['liquidation']['new_balance'], 466708.52), 'the accrued interest is paid first: $291.391,89 to capital, balance $466.708,52');
$assert($cents($between['options']['reduce_term']['rows'][0]['interes'], round(466708.52 * 0.022 * 15 / 31, 2)), 'the next installment charges only the 15 days left');

try {
  Prepayment::simulate(758100.41, 263899.59, 0.022, 0.022, 9000, 8000, $dates, 2);
  $assert(false, 'a payment below the accrued interest is rejected');
} catch (DomainException) {
  $assert(true, 'a payment below the accrued interest is rejected');
}

echo "Owner credit prepayment checks passed.\n";
