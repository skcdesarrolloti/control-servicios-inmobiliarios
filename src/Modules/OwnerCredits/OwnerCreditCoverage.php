<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * Calendario de cobro y cobertura de pago de los créditos a propietarios (regla PLAZO_INICIAL_MAS_COBERTURA_V2 del
 * paquete SIMI_Calendario_PHP_v2), sin acceso a base de datos. El dinero se calcula con BCMath a centavos.
 *
 * - Calendario: desembolso del día 1 al 15 → primera cuota ese mismo mes; del 16 en adelante → el mes siguiente.
 *   Vencen el día 30 (en febrero, su último día), sin traslado por festivos.
 * - Plan: cuota fija redondeada a centavos (HALF_UP), interés de cada mes sobre el saldo redondeado a centavos y la
 *   última cuota ajustada para dejar el saldo en cero.
 * - Disponible en cada vencimiento = (canon − deducciones) × % utilizable de cada contrato vigente ese día − otras
 *   obligaciones. Un contrato que termina antes de la fecha deja de aportar: no se presume renovación.
 * - Un plazo cumple solo si en todas sus cuotas disponible ≥ cuota × cobertura mínima. Se prueban todos los plazos
 *   (un fallo no detiene la búsqueda: acortar sube la cuota) y se toma el mayor que cumple.
 */
final class OwnerCreditCoverage
{
  public const RULE = 'PLAZO_INICIAL_MAS_COBERTURA_V2';
  private const SCALE = 24;

  /** Primer vencimiento según el día del desembolso (corte 15/16). */
  public static function firstDueTs(int $disbursedTs): int
  {
    if ($disbursedTs <= 0) return 0;
    $month = (int) strtotime(date('Y-m-01', $disbursedTs));
    if ((int) date('j', $disbursedTs) > 15) $month = (int) strtotime('+1 month', $month);
    return self::dueInMonth($month);
  }

  /** Vencimiento de la cuota $number contando desde el primero: día 30 o último día de febrero. */
  public static function installmentTs(int $firstDueTs, int $number): int
  {
    if ($firstDueTs <= 0) return 0;
    return self::dueInMonth((int) strtotime('+' . max(0, $number - 1) . ' month', (int) strtotime(date('Y-m-01', $firstDueTs))));
  }

  /** @return list<int> */
  public static function dueDates(int $firstDueTs, int $count): array
  {
    $dates = [];
    for ($number = 1; $number <= $count; $number++) $dates[] = self::installmentTs($firstDueTs, $number);
    return $dates;
  }

  /** Cuántos vencimientos caen hasta $endTs (inclusive), sin pasar de $max. */
  public static function duesUntil(int $firstDueTs, int $endTs, int $max): int
  {
    $count = 0;
    while ($count < $max && $endTs > 0 && self::installmentTs($firstDueTs, $count + 1) <= $endTs) $count++;
    return $count;
  }

  /** Cuota fija a centavos (valor presente de cuotas iguales; con tasa cero, capital / cuotas). */
  public static function payment(float $amount, float $rate, int $months): float
  {
    if ($amount <= 0 || $months <= 0) return 0.0;
    return (float) self::paymentText(self::money($amount), self::rate($rate), $months);
  }

  /**
   * Plan de amortización a centavos. Si el saldo se extingue antes por redondeo, el plan termina antes.
   * @return array{payment:float,rows:list<array{mes:int,saldo_inicial:float,cuota:float,interes:float,abono:float,saldo:float}>,total_interest:float}
   */
  public static function plan(float $amount, float $rate, int $months): array
  {
    if ($amount <= 0 || $months <= 0) return ['payment' => 0.0, 'rows' => [], 'total_interest' => 0.0];
    return self::amortize($amount, self::payment($amount, $rate, $months), $rate, $rate, $months);
  }

  /**
   * Cuota uniforme para un saldo con el primer tramo a otra tasa (abono entre vencimientos): saldo / Σ descuentos,
   * con d1 = 1/(1+r1) y d(k) = d(k−1)/(1+i).
   */
  public static function paymentWithFirstRate(float $balance, float $rate, float $firstRate, int $months): float
  {
    if ($balance <= 0 || $months <= 0) return 0.0;
    return (float) self::paymentText(self::money($balance), self::rate($rate), $months, self::rate($firstRate));
  }

  /**
   * Amortiza un saldo con una cuota dada: cada mes paga lo menor entre la cuota y lo que falta (saldo + interés) y
   * termina al llegar a cero; la última cuota del plazo cancela el saldo. Sirve para el plan del desembolso y para
   * los abonos (conservar la cuota y terminar antes, o recalcularla y conservar los vencimientos).
   * @return array{payment:float,rows:list<array{mes:int,saldo_inicial:float,cuota:float,interes:float,abono:float,saldo:float}>,total_interest:float}
   */
  public static function amortize(float $amount, float $payment, float $rate, float $firstRate, int $months): array
  {
    $balance = self::money($amount);
    $paymentText = self::money($payment);
    $rateText = self::rate($rate);
    $firstText = self::rate($firstRate);
    $rows = [];
    $interestTotal = '0.00';
    for ($month = 1; $month <= $months && bccomp($balance, '0', 2) > 0; $month++) {
      $interest = self::round(bcmul($balance, $month === 1 ? $firstText : $rateText, self::SCALE));
      $payoff = bcadd($balance, $interest, 2);
      $pay = $month === $months || bccomp($paymentText, $payoff, 2) >= 0 ? $payoff : $paymentText;
      $capital = bcsub($pay, $interest, 2);
      $next = bcsub($balance, $capital, 2);
      $rows[] = ['mes' => $month, 'saldo_inicial' => (float) $balance, 'cuota' => (float) $pay, 'interes' => (float) $interest, 'abono' => (float) $capital, 'saldo' => (float) $next];
      $interestTotal = bcadd($interestTotal, $interest, 2);
      $balance = $next;
    }
    return ['payment' => (float) $paymentText, 'rows' => $rows, 'total_interest' => (float) $interestTotal];
  }

  /**
   * Ingreso disponible para el crédito en cada vencimiento.
   * @param list<int> $dueDates
   * @param list<array{id:int,contrato:string,inmueble:string,net_income:float,start_ts:int,end_ts:int,active:bool}> $contracts
   * @param list<float> $obligations otras obligaciones de cada vencimiento (mismo orden que $dueDates)
   * @return list<array{fecha:int,ingreso:float,obligaciones:float,disponible:float,vigentes:list<string>,bajas:list<array{contrato:string,inmueble:string,end_ts:int}>}>
   */
  public static function availability(array $dueDates, array $contracts, float $factor, array $obligations): array
  {
    $factorText = self::rate(max(0.0, min(1.0, $factor)));
    $usable = [];
    // Conservador: el aporte de cada contrato se trunca a centavos, no se redondea hacia arriba.
    foreach ($contracts as $index => $contract) $usable[$index] = bcmul(self::money(max(0.0, (float) $contract['net_income'])), $factorText, 2);
    $rows = [];
    $previous = 0;
    foreach ($dueDates as $index => $date) {
      $income = '0.00';
      $current = [];
      $ended = [];
      foreach ($contracts as $key => $contract) {
        $start = (int) $contract['start_ts'];
        $end = (int) $contract['end_ts'];
        if ($contract['active'] && ($start <= 0 || $start <= $date) && $end > 0 && $date <= $end) {
          $income = bcadd($income, $usable[$key], 2);
          $current[] = (string) $contract['contrato'];
        } elseif ($end > 0 && $end < $date && $end >= $previous && bccomp($usable[$key], '0', 2) > 0) {
          $ended[] = ['contrato' => (string) $contract['contrato'], 'inmueble' => (string) $contract['inmueble'], 'end_ts' => $end];
        }
      }
      $obligation = self::money(max(0.0, (float) ($obligations[$index] ?? 0)));
      $available = bcsub($income, $obligation, 2);
      if (bccomp($available, '0', 2) < 0) $available = '0.00';
      $rows[] = ['fecha' => $date, 'ingreso' => (float) $income, 'obligaciones' => (float) $obligation, 'disponible' => (float) $available, 'vigentes' => $current, 'bajas' => $ended];
      $previous = $date;
    }
    return $rows;
  }

  /**
   * Cobertura de un plazo: cada cuota real (incluida la última ajustada) contra el disponible de su fecha.
   * @param list<array<string,mixed>> $availability availability()
   * @return array{term:int,ok:bool,payment:float,installments:int,last_ts:int,min_ratio:?float,rows:list<array<string,mixed>>,shortfall:?array<string,mixed>}
   */
  public static function check(float $amount, float $rate, int $term, array $availability, float $minCoverage): array
  {
    $plan = self::plan($amount, $rate, $term);
    $coverage = self::rate(max(1.0, $minCoverage));
    $rows = [];
    $shortfall = null;
    $minRatio = null;
    foreach ($plan['rows'] as $index => $line) {
      $slot = $availability[$index] ?? ['fecha' => 0, 'ingreso' => 0.0, 'obligaciones' => 0.0, 'disponible' => 0.0, 'vigentes' => [], 'bajas' => []];
      $pay = self::money($line['cuota']);
      $available = self::money($slot['disponible']);
      // Lo requerido se sube al centavo: con cobertura mayor que 1 no se pierde el margen por redondeo.
      $exact = bcmul($pay, $coverage, self::SCALE);
      $required = bcadd($exact, '0', 2);
      if (bccomp($exact, $required, self::SCALE) > 0) $required = bcadd($required, '0.01', 2);
      $ratio = bccomp($pay, '0', 2) > 0 ? (float) bcdiv($available, $pay, 6) : null;
      $ok = bccomp($available, $required, 2) >= 0;
      $row = $slot + [
        'mes' => (int) $line['mes'], 'cuota' => (float) $pay, 'requerido' => (float) $required, 'ratio' => $ratio, 'ok' => $ok,
        'faltante_pago' => bccomp($pay, $available, 2) > 0 ? (float) bcsub($pay, $available, 2) : 0.0,
        'faltante_cobertura' => bccomp($required, $available, 2) > 0 ? (float) bcsub($required, $available, 2) : 0.0,
      ];
      if ($ratio !== null && ($minRatio === null || $ratio < $minRatio)) $minRatio = $ratio;
      if (!$ok && $shortfall === null) $shortfall = $row;
      $rows[] = $row;
    }
    $last = $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['fecha'];
    return ['term' => $term, 'ok' => $rows !== [] && $shortfall === null, 'payment' => $plan['payment'], 'installments' => count($rows), 'last_ts' => $last, 'min_ratio' => $minRatio, 'rows' => $rows, 'shortfall' => $shortfall];
  }

  /**
   * Mayor plazo con cobertura entre $from y 1 (de mayor a menor, sin detenerse en el primer fallo). 0 si ninguno.
   * @param list<array<string,mixed>> $availability
   * @return array{term:int,payment:float,last_ts:int}
   */
  public static function bestTerm(float $amount, float $rate, int $from, array $availability, float $minCoverage): array
  {
    for ($term = min($from, count($availability)); $term >= 1; $term--) {
      $check = self::check($amount, $rate, $term, $availability, $minCoverage);
      if ($check['ok']) return ['term' => $check['installments'], 'payment' => $check['payment'], 'last_ts' => $check['last_ts']];
    }
    return ['term' => 0, 'payment' => 0.0, 'last_ts' => 0];
  }

  /**
   * Capital máximo (en pesos enteros) que conserva el plazo con cobertura en todas sus cuotas.
   * @param list<array<string,mixed>> $availability
   */
  public static function maxCapital(float $rate, int $term, array $availability, float $minCoverage): float
  {
    if ($term <= 0 || $term > count($availability)) return 0.0;
    $coverage = self::rate(max(1.0, $minCoverage));
    $lowest = null;
    foreach (array_slice($availability, 0, $term) as $slot) {
      $allowed = bcdiv(self::money($slot['disponible']), $coverage, 2);
      if ($lowest === null || bccomp($allowed, $lowest, 2) < 0) $lowest = $allowed;
    }
    if ($lowest === null || bccomp($lowest, '0', 2) <= 0) return 0.0;
    $capital = (float) bcdiv(bcmul($lowest, self::annuity(self::rate($rate), $term), self::SCALE), '1', 0);
    // Los redondeos de cuota e intereses pueden pasarse por centavos: se baja de a un peso hasta que cumpla.
    for ($guard = 0; $capital > 0 && $guard < 500; $guard++, $capital -= 1) {
      if (self::check($capital, $rate, $term, $availability, $minCoverage)['ok']) return $capital;
    }
    return 0.0;
  }

  private static function paymentText(string $balance, string $rate, int $months, ?string $firstRate = null): string
  {
    $payment = self::round(bcdiv($balance, self::annuity($rate, $months, $firstRate), self::SCALE));
    return bccomp($payment, '0', 2) > 0 ? $payment : '0.01';
  }

  /** Suma de los factores de descuento de $months cuotas: 1/(1+r1) + 1/((1+r1)(1+i)) + … (r1 = i salvo abono). */
  private static function annuity(string $rate, int $months, ?string $firstRate = null): string
  {
    $step = bcadd('1', $rate, self::SCALE);
    $discount = bcdiv('1', bcadd('1', $firstRate ?? $rate, self::SCALE), self::SCALE);
    $sum = '0';
    for ($month = 0; $month < $months; $month++) {
      $sum = bcadd($sum, $discount, self::SCALE);
      $discount = bcdiv($discount, $step, self::SCALE);
    }
    return $sum;
  }

  private static function dueInMonth(int $monthTs): int
  {
    return (int) strtotime(date('Y-m-', $monthTs) . str_pad((string) min(30, (int) date('t', $monthTs)), 2, '0', STR_PAD_LEFT));
  }

  private static function money(float $value): string
  {
    return number_format(max(0.0, $value), 2, '.', '');
  }

  private static function rate(float $value): string
  {
    return rtrim(rtrim(number_format(max(0.0, $value), 15, '.', ''), '0'), '.') ?: '0';
  }

  /** HALF_UP a centavos para importes positivos. */
  private static function round(string $value): string
  {
    return bcadd(bcadd($value, '0.005', self::SCALE), '0', 2);
  }
}
