<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * Cálculos del "Simulador de créditos a propietarios" (Excel versión 2), sin acceso a base de datos.
 *
 * - Ingreso neto (INO) = canon − comisión con IVA − garantía inmobiliaria − póliza de servicios públicos − otros.
 * - Política: monto máximo = INO de todos los contratos del propietario × meses de política; plazo máximo = meses completos
 *   (30 días) hasta el fin del contrato con la vigencia más larga − meses de margen; tasa de colocación = tasa de referencia
 *   N.M.V × (1 − descuento).
 * - Tasa de riesgo (cuando se supera la política) = (monto máx × tasa política + |exceso| × usura/12) / valor solicitado,
 *   como en el Excel; el control legal compara contra la usura efectiva anual llevada a mes vencido.
 * - Condición sugerida por probabilidad de desocupación: Alta = 3 meses, tasa de riesgo, monto máximo;
 *   Media = plazo máximo, tasa de referencia, monto máximo; Baja = plazo y valor solicitados con la tasa de política.
 *   La tasa sugerida nunca supera la usura legal mes vencido: si la de la condición es mayor, se sugiere la usura.
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
   * Cupo preaprobado del solicitante con todos sus contratos: (suma de ingresos netos) × meses de política. El
   * plazo máximo es la vigencia más larga entre esos contratos menos los meses de margen configurados.
   * @param list<array{net_income:float,max_months:int}> $contracts
   * @param array<string,mixed> $settings OwnerCreditSettings::read()
   * @return array{contracts:int,net_income:float,income_months:int,amount:float,longest_months:int,margin_months:int,max_term:int}
   */
  public static function preapproval(array $contracts, array $settings): array
  {
    $net = array_sum(array_map(static fn(array $row): float => max(0.0, (float) $row['net_income']), $contracts));
    $longest = $contracts === [] ? 0 : max(0, ...array_map(static fn(array $row): int => (int) $row['max_months'], $contracts));
    $margin = max(0, (int) $settings['preapproval_margin_months']);
    return [
      'contracts' => count($contracts),
      'net_income' => $net,
      'income_months' => (int) $settings['income_months'],
      'amount' => $net * (int) $settings['income_months'],
      'longest_months' => $longest,
      'margin_months' => $margin,
      'max_term' => max(0, $longest - $margin),
    ];
  }

  /**
   * other_net_income y longest_months vienen de los demás contratos del propietario (opcionales).
   * @param array{gross:float,deductions:array<string,float>,requested_amount:float,requested_term:int,request_ts:int,contract_end_ts:int,vacancy:string,other_net_income?:float,longest_months?:int} $input
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
    $contractMonths = max(0, self::monthsUntil((int) $input['request_ts'], (int) $input['contract_end_ts']));
    $longestMonths = max($contractMonths, (int) ($input['longest_months'] ?? 0));
    $margin = max(0, (int) $settings['preapproval_margin_months']);
    $maxMonths = max(0, $longestMonths - $margin);
    $otherNet = max(0.0, (float) ($input['other_net_income'] ?? 0));
    $maxAmount = ($net + $otherNet) * (int) $settings['income_months'];
    $exceedMonths = $maxMonths - $requestedTerm;
    $exceedAmount = $maxAmount - $requested;
    $riskRate = $requested > 0 ? (($maxAmount * $policyRate) + (abs($exceedAmount) * $usuryMonthly)) / $requested : 0.0;

    $vacancy = in_array($input['vacancy'], ['Alta', 'Media', 'Baja'], true) ? $input['vacancy'] : 'Baja';
    $suggested = match ($vacancy) {
      'Alta' => ['term' => 3, 'rate' => $riskRate, 'amount' => $maxAmount],
      'Media' => ['term' => $maxMonths, 'rate' => (float) $settings['reference_rate'], 'amount' => $maxAmount],
      default => ['term' => $requestedTerm, 'rate' => $policyRate, 'amount' => $requested],
    };
    // Tope legal: la usura es efectiva anual; su equivalente mes vencido es (1 + E.A.)^(1/12) − 1.
    $usuryLegal = (1 + (float) $settings['usury_annual']) ** (1 / 12) - 1;
    $suggested['rate_capped'] = $suggested['rate'] > $usuryLegal;
    $suggested['rate'] = min($suggested['rate'], $usuryLegal);
    $suggested['payment'] = self::payment($suggested['rate'], $suggested['term'], $suggested['amount']);

    return [
      'gross' => $gross,
      'deductions' => $deductions,
      'deductions_total' => array_sum($deductions),
      'net_income' => $net,
      'other_net_income' => $otherNet,
      'total_net_income' => $net + $otherNet,
      'usury_monthly' => $usuryMonthly,
      'usury_monthly_legal' => $usuryLegal,
      'policy_rate' => $policyRate,
      'contract_months' => $contractMonths,
      'longest_months' => $longestMonths,
      'margin_months' => $margin,
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
