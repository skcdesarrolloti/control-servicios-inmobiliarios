<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * Cálculos del "Simulador de créditos a propietarios" (Excel versión 2), sin acceso a base de datos.
 *
 * - Ingreso neto (INO) = canon − comisión con IVA − garantía inmobiliaria − póliza de servicios públicos − otros.
 * - Política: monto máximo = INO de todos los contratos del propietario × meses de política; plazo máximo = meses completos
 *   (30 días) hasta el fin del contrato con la vigencia más larga, que la cobertura de pago (OwnerCreditCoverage) puede
 *   bajar; tasa de colocación = tasa de referencia
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
   * Plan de descuento de la cuota entre los contratos que respaldan el crédito, cuota por cuota. Un contrato cubre
   * las cuotas que vencen antes de su fin (covers). Se prefiere descontar todo de un solo contrato: primero uno que
   * cubra todo el plazo, luego el principal, luego el de vigencia más larga, siempre que su ingreso neto alcance. Si
   * ninguno alcanza solo, la cuota se reparte en proporción al ingreso neto; lo que no cubren los contratos (por
   * ejemplo, cuando ya terminaron) lo paga el propietario directo.
   * @param list<array{id:int,contrato:string,net_income:float,covers:int}> $contracts
   * @return list<array{from:int,to:int,parts:list<array{id:int,contrato:string,amount:float}>,direct:float}>
   */
  public static function discountPlan(array $contracts, float $payment, int $term, int $primaryId = 0): array
  {
    $rank = static fn(array $c): array => [(int) $c['covers'] >= $term ? 1 : 0, (int) $c['id'] === $primaryId ? 1 : 0, (int) $c['covers'], (float) $c['net_income']];
    usort($contracts, static fn(array $a, array $b): int => $rank($b) <=> $rank($a));
    $segments = [];
    for ($month = 1; $month <= $term && $payment > 0; $month++) {
      $eligible = array_values(array_filter($contracts, static fn(array $c): bool => (int) $c['covers'] >= $month && (float) $c['net_income'] > 0));
      $single = null;
      foreach ($eligible as $contract) {
        if ((float) $contract['net_income'] + 0.5 >= $payment) { $single = $contract; break; }
      }
      $parts = [];
      $direct = 0.0;
      if ($single !== null) {
        $parts[] = ['id' => (int) $single['id'], 'contrato' => (string) $single['contrato'], 'amount' => $payment];
      } else {
        $total = array_sum(array_map(static fn(array $c): float => (float) $c['net_income'], $eligible));
        $covered = min($payment, $total);
        foreach ($eligible as $contract) $parts[] = ['id' => (int) $contract['id'], 'contrato' => (string) $contract['contrato'], 'amount' => $covered * (float) $contract['net_income'] / $total];
        $direct = $payment - $covered;
      }
      $last = count($segments) - 1;
      if ($last >= 0 && $segments[$last]['parts'] == $parts && abs($segments[$last]['direct'] - $direct) < 0.005) {
        $segments[$last]['to'] = $month;
      } else {
        $segments[] = ['from' => $month, 'to' => $month, 'parts' => $parts, 'direct' => $direct];
      }
    }
    return $segments;
  }

  /**
   * Cupo preaprobado del solicitante con todos sus contratos: (suma de ingresos netos) × meses de política. El
   * plazo máximo por vigencia son los vencimientos del calendario (día 30) que caen antes del fin del contrato más
   * largo, sin pasar del tope de plazo configurado. Es un límite superior: con el valor pedido, la cobertura de pago
   * decide cuántas de esas cuotas se pueden pagar.
   * @param list<array{net_income:float,max_months:int,end_ts?:int}> $contracts
   * @param array<string,mixed> $settings OwnerCreditSettings::read()
   * @return array<string,mixed>
   */
  public static function preapproval(array $contracts, array $settings, int $disbursedTs = 0): array
  {
    $net = array_sum(array_map(static fn(array $row): float => max(0.0, (float) $row['net_income']), $contracts));
    $longest = $contracts === [] ? 0 : max(0, ...array_map(static fn(array $row): int => (int) $row['max_months'], $contracts));
    $longestEnd = $contracts === [] ? 0 : max(0, ...array_map(static fn(array $row): int => (int) ($row['end_ts'] ?? 0), $contracts));
    $margin = self::termMargin($settings);
    $firstDue = OwnerCreditCoverage::firstDueTs($disbursedTs ?: (int) strtotime('today'));
    $byDates = OwnerCreditCoverage::duesUntil($firstDue, $longestEnd, 600);
    return [
      'contracts' => count($contracts),
      'net_income' => $net,
      'income_months' => (int) $settings['income_months'],
      'amount' => $net * (int) $settings['income_months'],
      'longest_months' => $longest,
      'longest_end_ts' => $longestEnd,
      'first_due_ts' => $firstDue,
      'term_margin' => $margin,
      'max_term' => self::capTerm(max(0, $longest - $margin), $settings),
      // Sin cuotas por vigencia (contrato por vencer o fecha fin vieja) se deja registrar hasta el tope de plazo:
      // el analista revisa la fecha fin.
      'fallback_term' => self::fallbackTerm($settings),
      'term_basis' => self::termBasisText($longest, $margin, $longestEnd, count($contracts) > 1, self::maxTerm($settings)),
    ];
  }

  /** Tope de plazo de cualquier crédito (Configuración): el plazo máximo es el menor entre n − margen y este tope. */
  public static function maxTerm(array $settings): int
  {
    return max(1, (int) ($settings['max_term'] ?? 10));
  }

  public static function capTerm(int $term, array $settings): int
  {
    return min($term, self::maxTerm($settings));
  }

  /** Plazo cuando los contratos no dan cuotas: el tope de plazo. */
  public static function fallbackTerm(array $settings): int
  {
    return self::maxTerm($settings);
  }

  /** Meses de margen antes del fin del contrato: el plazo máximo es n − margen (Configuración). */
  public static function termMargin(array $settings): int
  {
    return max(0, (int) ($settings['term_margin_months'] ?? 2));
  }

  /** "24 meses de vigencia del contrato más largo (fin 14/11/2028) − 2 de margen, tope 10". */
  public static function termBasisText(int $months, int $margin, int $longestEnd, bool $several, int $cap = 0): string
  {
    $contract = $several ? 'del contrato más largo' : 'del contrato';
    $capped = $cap > 0 && $months - $margin > $cap ? ', tope ' . $cap . ' meses' : '';
    if ($longestEnd <= 0) return 'sin fecha fin ' . $contract;
    return $months . ' mes' . ($months === 1 ? '' : 'es') . ' de vigencia ' . $contract . ' (fin ' . date('d/m/Y', $longestEnd) . ')' . ($margin > 0 ? ' − ' . $margin . ' de margen' : '') . $capped;
  }

  /**
   * other_net_income y longest_months vienen de los demás contratos del propietario (opcionales). Con "coverage"
   * (availability y term_limit de OwnerCreditCoverage) el plazo máximo es el mayor con cobertura en todas sus
   * cuotas; sin ella (cálculo del Excel), la vigencia más larga sin pasar del tope.
   * @param array<string,mixed> $input gross, deductions, requested_amount, requested_term, request_ts, contract_end_ts, vacancy, other_net_income?, longest_months?, coverage?
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
    // Tope legal: la usura es efectiva anual; su equivalente mes vencido es (1 + E.A.)^(1/12) − 1.
    $usuryLegal = (1 + (float) $settings['usury_annual']) ** (1 / 12) - 1;
    $referenceRate = (float) $settings['reference_rate'];
    $policyRate = $referenceRate * (1 - (float) $settings['policy_discount']);
    $contractMonths = max(0, self::monthsUntil((int) $input['request_ts'], (int) $input['contract_end_ts']));
    $longestMonths = max($contractMonths, (int) ($input['longest_months'] ?? 0));
    $margin = self::termMargin($settings);
    $coverage = isset($input['coverage']) && is_array($input['coverage']) ? $input['coverage'] : null;
    $slots = $coverage !== null ? array_values((array) $coverage['availability']) : [];
    $minCoverage = $coverage !== null ? max(1.0, (float) $coverage['min_coverage']) : 1.0;
    // Plazo máximo por vigencia (límite superior); la cobertura lo puede bajar según el valor.
    $termLimit = self::capTerm($coverage !== null ? max(0, (int) $coverage['term_limit']) : max(0, $longestMonths - $margin), $settings);
    // Mayor plazo con cobertura para $amount a $rate, probando desde $from hasta 1 (0 si ninguno).
    $covered = static fn(float $amount, float $rate, int $from): int => $coverage === null || $amount <= 0 ? $from
      : OwnerCreditCoverage::bestTerm($amount, min($rate, $usuryLegal), $from, $slots, $minCoverage)['term'];
    $maxMonths = $covered($requested, $referenceRate, $termLimit);
    $otherNet = max(0.0, (float) ($input['other_net_income'] ?? 0));
    $maxAmount = ($net + $otherNet) * (int) $settings['income_months'];
    $exceedMonths = $maxMonths - $requestedTerm;
    $exceedAmount = $maxAmount - $requested;
    // La tasa para el riesgo (Excel B29) compensa lo que se pide por encima del tope cobrando la usura sobre ese
    // exceso. Si lo pedido cabe en el tope no hay exceso y no aplica.
    $riskApplies = $requested > 0 && $exceedAmount < 0;
    $riskRate = $riskApplies ? (($maxAmount * $policyRate) + (abs($exceedAmount) * $usuryMonthly)) / $requested : 0.0;

    $vacancy = in_array($input['vacancy'], ['Alta', 'Media', 'Baja'], true) ? $input['vacancy'] : 'Baja';
    $suggested = match ($vacancy) {
      // Sin exceso, el riesgo alto se cubre con la tasa de referencia sobre lo pedido.
      'Alta' => $riskApplies ? ['term' => 3, 'rate' => $riskRate, 'amount' => $maxAmount] : ['term' => 3, 'rate' => $referenceRate, 'amount' => $requested],
      'Media' => ['term' => $termLimit, 'rate' => $referenceRate, 'amount' => $maxAmount],
      // El descuento de tasa lo decide el analista (hasta el máximo de Configuración): se sugiere la referencia.
      default => ['term' => $requestedTerm, 'rate' => $referenceRate, 'amount' => $requested],
    };
    $suggested['rate_capped'] = $suggested['rate'] > $usuryLegal;
    $suggested['rate'] = min($suggested['rate'], $usuryLegal);
    // Con cobertura, el plazo sugerido es el mayor que se puede pagar sin pasar del de la condición (nunca lo sube).
    $suggestedFrom = $coverage !== null && $termLimit > 0 ? min((int) $suggested['term'], $termLimit) : (int) $suggested['term'];
    $suggestedTerm = $covered((float) $suggested['amount'], (float) $suggested['rate'], $suggestedFrom);
    $suggested['uncovered'] = $coverage !== null && $suggested['amount'] > 0 && $suggestedFrom > 0 && $suggestedTerm === 0;
    $suggested['term'] = $suggestedTerm > 0 ? $suggestedTerm : $suggestedFrom;
    $suggested['payment'] = $coverage !== null ? OwnerCreditCoverage::payment((float) $suggested['amount'], (float) $suggested['rate'], (int) $suggested['term'])
      : self::payment($suggested['rate'], $suggested['term'], $suggested['amount']);

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
      'term_margin' => $margin,
      'term_limit' => $termLimit,
      'max_months' => $maxMonths,
      'max_amount' => $maxAmount,
      'policy_payment' => self::payment($policyRate, $maxMonths, $maxAmount),
      'exceed_months' => $exceedMonths,
      'exceed_amount' => $exceedAmount,
      'risk_applies' => $riskApplies,
      'risk_rate' => $riskRate,
      'risk_payment' => self::payment($riskRate, $requestedTerm, $requested),
      'vacancy' => $vacancy,
      'suggested' => $suggested,
    ];
  }
}
