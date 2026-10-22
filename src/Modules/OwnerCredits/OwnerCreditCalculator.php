<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * Cálculos del "Simulador de créditos a propietarios" (Excel versión 2), sin acceso a base de datos.
 *
 * - Ingreso neto (INO) = canon − comisión con IVA − garantía inmobiliaria − póliza de servicios públicos − otros.
 * - Política: monto máximo = INO × meses de política; plazo máximo = meses completos (30 días) hasta el fin del contrato;
 *   tasa de colocación = tasa de referencia N.M.V × (1 − descuento).
 * - Tasa de riesgo (cuando se supera la política) = (monto máx × tasa política + |exceso| × usura mensual) / valor solicitado.
 * - Condición sugerida por probabilidad de desocupación: Alta = 3 meses, tasa de riesgo, monto máximo;
 *   Media = plazo máximo, tasa de referencia, monto máximo; Baja = plazo y valor solicitados con la tasa de política.
 */
final class OwnerCreditCalculator
{
  /** Cuota fija mensual, equivalente a PMT(tasa, n, -valor) de Excel. */
  public static function payment(float $rate, int $months, float $amount): float
  {
    if ($months <= 0 || $amount <= 0) {
      return 0.0;
    }
    if (abs($rate) < 1e-12) {
      return $amount / $months;
    }
    return $amount * $rate / (1 - (1 + $rate) ** (-$months));
  }

  /** @return list<array{mes:int,saldo_inicial:float,cuota:float,interes:float,abono:float,saldo:float}> */
  public static function schedule(float $rate, int $months, float $amount): array
  {
    $payment = self::payment($rate, $months, $amount);
    $balance = $amount;
    $rows = [];
    for ($month = 1; $month <= $months; $month++) {
      $interest = $balance * $rate;
      $capital = $month === $months ? $balance : $payment - $interest;
      $rows[] = [
        'mes' => $month,
        'saldo_inicial' => $balance,
        'cuota' => $month === $months ? $capital + $interest : $payment,
        'interes' => $interest,
        'abono' => $capital,
        'saldo' => max(0.0, $balance - $capital),
      ];
      $balance -= $capital;
    }
    return $rows;
  }

  /** Meses completos de 30 días entre la solicitud y el fin del contrato (=INT((fin − solicitud)/30)). */
  public static function monthsUntil(int $fromTs, int $toTs): int
  {
    if ($fromTs <= 0 || $toTs <= 0) {
      return 0;
    }
    $days = (int) floor(((int) strtotime(date('Y-m-d', $toTs)) - (int) strtotime(date('Y-m-d', $fromTs))) / 86400);
    return (int) floor($days / 30);
  }

  /**
   * @param array{gross:float,deductions:array<string,float>,requested_amount:float,requested_term:int,request_ts:int,contract_end_ts:int,vacancy:string} $input
   * @param array<string,mixed> $settings OwnerCreditSettings::read()
   * @return array<string,mixed>
   */
  public static function evaluate(array $input, array $settings): array
  {
    $gross = max(0.0, (float) $input['gross']);
    $deductions = array_map(static fn($value): float => max(0.0, (float) $value), $input['deductions']);
    $net = max(0.0, $gross - array_sum($deductions));
    $requested = max(0.0, (float) $input['requested_amount']);
    $requestedTerm = max(0, (int) $input['requested_term']);
    $usuryMonthly = (float) $settings['usury_annual'] / 12;
    $policyRate = (float) $settings['reference_rate'] * (1 - (float) $settings['policy_discount']);
    $maxMonths = self::monthsUntil((int) $input['request_ts'], (int) $input['contract_end_ts']);
    $maxAmount = $net * (int) $settings['income_months'];
    $exceedMonths = $maxMonths - $requestedTerm;
    $exceedAmount = $maxAmount - $requested;
    $riskRate = $requested > 0 ? (($maxAmount * $policyRate) + (abs($exceedAmount) * $usuryMonthly)) / $requested : 0.0;

    $vacancy = in_array($input['vacancy'], ['Alta', 'Media', 'Baja'], true) ? $input['vacancy'] : 'Baja';
    $suggested = match ($vacancy) {
      'Alta' => ['term' => 3, 'rate' => $riskRate, 'amount' => $maxAmount],
      'Media' => ['term' => $maxMonths, 'rate' => (float) $settings['reference_rate'], 'amount' => $maxAmount],
      default => ['term' => $requestedTerm, 'rate' => $policyRate, 'amount' => $requested],
    };
    $suggested['payment'] = self::payment($suggested['rate'], $suggested['term'], $suggested['amount']);

    return [
      'gross' => $gross,
      'deductions' => $deductions,
      'deductions_total' => array_sum($deductions),
      'net_income' => $net,
      'usury_monthly' => $usuryMonthly,
      'policy_rate' => $policyRate,
      'max_months' => $maxMonths,
      'max_amount' => $maxAmount,
      'policy_payment' => self::payment($policyRate, $maxMonths, $maxAmount),
      'exceed_months' => $exceedMonths,
      'exceed_amount' => $exceedAmount,
      'risk_rate' => $riskRate,
      'risk_payment' => self::payment($riskRate, $requestedTerm, $requested),
      'vacancy' => $vacancy,
      'suggested' => $suggested,
    ];
  }
}
