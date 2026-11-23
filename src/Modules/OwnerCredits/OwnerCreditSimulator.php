<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * Simulación de cuotas sin registrar nada: la usan la vista pública de la cotización (para que el propietario decida si
 * pide financiarla) y Herramientas › Simulador de crédito. Mismo plan del liquidador (OwnerCreditCoverage::plan) y
 * mismo calendario (corte 15/16, vencimientos el día 30), sin acceso a base de datos.
 */
final class OwnerCreditSimulator
{
  /** Plazo máximo que acepta la herramienta (el de la política se avisa aparte). */
  public const MAX_TERM = 60;
  /** Plazos del comparativo de la herramienta, además del elegido. */
  public const COMPARE_TERMS = [3, 6, 9, 12, 18, 24, 36];

  /** Tasa de la simulación: la de referencia de Configuración, nunca por encima de la usura mes vencido. */
  public static function rate(array $settings): float
  {
    return min(max(0.0, (float) ($settings['reference_rate'] ?? 0)), OwnerCreditUsuryRate::monthly($settings));
  }

  /** Tasa efectiva anual equivalente a una mensual vencida. */
  public static function effectiveAnnual(float $monthly): float
  {
    return (1 + max(0.0, $monthly)) ** 12 - 1;
  }

  /**
   * Plan de pagos de un crédito. Con fecha de desembolso, cada cuota lleva su vencimiento.
   * @return array{amount:float,term:int,rate:float,effective_annual:float,payment:float,total_interest:float,total:float,first_due_ts:int,last_due_ts:int,rows:list<array{mes:int,fecha:int,saldo_inicial:float,cuota:float,interes:float,abono:float,saldo:float}>}
   */
  public static function simulate(float $amount, int $term, float $rate, int $disbursedTs = 0): array
  {
    $plan = OwnerCreditCoverage::plan($amount, $rate, $term);
    $first = OwnerCreditCoverage::firstDueTs($disbursedTs);
    $rows = [];
    foreach ($plan['rows'] as $row) $rows[] = ['mes' => (int) $row['mes'], 'fecha' => OwnerCreditCoverage::installmentTs($first, (int) $row['mes'])] + $row;
    $total = array_sum(array_column($rows, 'cuota'));
    return [
      'amount' => round($amount, 2), 'term' => $term, 'rate' => $rate, 'effective_annual' => self::effectiveAnnual($rate),
      'payment' => $plan['payment'], 'total_interest' => $plan['total_interest'], 'total' => round($total, 2),
      'first_due_ts' => $rows === [] ? 0 : (int) $rows[0]['fecha'], 'last_due_ts' => $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['fecha'],
      'rows' => $rows,
    ];
  }

  /**
   * Cuota de cada plazo para comparar. @param list<int> $terms
   * @return list<array{term:int,payment:float,total_interest:float,total:float}>
   */
  public static function compare(float $amount, float $rate, array $terms): array
  {
    $terms = array_values(array_unique(array_filter(array_map('intval', $terms), static fn(int $term): bool => $term >= 1 && $term <= self::MAX_TERM)));
    sort($terms);
    $list = [];
    foreach ($terms as $term) {
      $plan = OwnerCreditCoverage::plan($amount, $rate, $term);
      $total = array_sum(array_column($plan['rows'], 'cuota'));
      $list[] = ['term' => $term, 'payment' => $plan['payment'], 'total_interest' => $plan['total_interest'], 'total' => round($total, 2)];
    }
    return $list;
  }
}
