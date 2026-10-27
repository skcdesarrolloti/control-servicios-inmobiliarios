<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

/**
 * Simulación de abonos extraordinarios a un crédito aprobado (paquete Abonos_Anticipados_PHP_v1, versión
 * ABONOS_SIMULACION_V1), sin acceso a base de datos. No registra pagos ni cambia el crédito: compara alternativas.
 *
 * - Liquidación: el dinero recibido salda primero el interés causado a la fecha; el resto va a capital y lo que
 *   exceda el saldo queda como excedente por conciliar (no se devuelve ni se registra como ingreso).
 * - Reducir plazo: se conserva la cuota y se termina antes (la última cuota puede ser menor).
 * - Reducir cuota: se conservan los vencimientos y se recalcula una cuota uniforme.
 * - Entre vencimientos, el interés causado es una PROPUESTA (prorrata simple sobre los días reales del periodo):
 *   la convención debe validarla contabilidad antes de cobrar con ella.
 * Se asume el crédito al día (cuotas vencidas descontadas) y sin otros cargos pendientes.
 */
final class OwnerCreditPrepayment
{
  public const VERSION = 'ABONOS_SIMULACION_V1';
  private const SCALE = 24;

  /**
   * Interés causado entre la última liquidación y el abono, y tasa del tramo que falta hasta el vencimiento:
   * interés = saldo × i × d / D y r1 = i × f / D, con D = días reales del periodo contractual.
   * @return array{interest:float,first_rate:float,days_period:int,days_elapsed:int,days_left:int}
   */
  public static function accrual(float $balance, float $rate, int $periodStartTs, int $prepayTs, int $periodEndTs): array
  {
    $days = static fn(int $from, int $to): int => (int) round(((int) strtotime(date('Y-m-d', $to)) - (int) strtotime(date('Y-m-d', $from))) / 86400);
    if (!($periodStartTs <= $prepayTs && $prepayTs < $periodEndTs)) throw new \InvalidArgumentException('El abono debe caer dentro del periodo de la cuota siguiente.');
    $total = max(1, $days($periodStartTs, $periodEndTs));
    $elapsed = $days($periodStartTs, $prepayTs);
    $left = $days($prepayTs, $periodEndTs);
    $rateText = self::rate($rate);
    $accrued = bcdiv(bcmul($rateText, (string) $elapsed, self::SCALE), (string) $total, self::SCALE);
    $remaining = bcdiv(bcmul($rateText, (string) $left, self::SCALE), (string) $total, self::SCALE);
    return [
      'interest' => (float) self::round(bcmul(self::money($balance), $accrued, self::SCALE)),
      'first_rate' => (float) $remaining,
      'days_period' => $total,
      'days_elapsed' => $elapsed,
      'days_left' => $left,
    ];
  }

  /**
   * Compara reducir plazo y reducir cuota tras un abono.
   * @param list<int> $dueDates vencimientos pendientes (posteriores al abono)
   * @return array<string,mixed>
   */
  public static function simulate(float $balance, float $currentPayment, float $rate, float $firstRate, float $pendingInterest, float $received, array $dueDates, int $nextNumber): array
  {
    $balanceText = self::money($balance);
    $receivedText = self::money($received);
    $interestText = self::money($pendingInterest);
    if ($dueDates === [] || bccomp($balanceText, '0', 2) <= 0) throw new \DomainException('El crédito no tiene cuotas pendientes para abonar.');
    if (bccomp($receivedText, $interestText, 2) <= 0) throw new \DomainException('El valor no alcanza a cubrir los intereses causados a la fecha (' . self::pesos((float) $interestText) . '): no queda abono a capital.');
    $available = bcsub($receivedText, $interestText, 2);
    $capital = bccomp($available, $balanceText, 2) <= 0 ? $available : $balanceText;
    $newBalance = bcsub($balanceText, $capital, 2);
    $surplus = bcsub($available, $capital, 2);
    $count = count($dueDates);
    $reference = self::plan((float) $balanceText, $currentPayment, $rate, $firstRate, $dueDates, $nextNumber);
    $options = [];
    foreach (['reduce_term', 'reduce_payment'] as $option) {
      $payment = $currentPayment;
      if (bccomp($newBalance, '0', 2) === 0) $payment = 0.0;
      elseif ($option === 'reduce_payment') $payment = OwnerCreditCoverage::paymentWithFirstRate((float) $newBalance, $rate, $firstRate, $count);
      $plan = self::plan((float) $newBalance, $payment, $rate, $firstRate, $dueDates, $nextNumber);
      $plan['removed'] = $count - $plan['installments'];
      // Un residuo de centavos puede extinguirse antes aun conservando los vencimientos: se informa.
      $plan['early_close'] = $option === 'reduce_payment' && bccomp($newBalance, '0', 2) > 0 && $plan['installments'] < $count;
      $plan['savings'] = round($reference['interest'] - $plan['interest'], 2);
      $options[$option] = $plan;
    }
    return [
      'version' => self::VERSION,
      'status' => 'SIMULACION_NO_CONTABILIZADA',
      'liquidation' => [
        'previous_balance' => (float) $balanceText, 'received' => (float) $receivedText, 'interest_paid' => (float) $interestText,
        'capital' => (float) $capital, 'new_balance' => (float) $newBalance, 'surplus' => (float) $surplus,
      ],
      'paid_off' => bccomp($newBalance, '0', 2) === 0,
      'reference' => $reference,
      'options' => $options,
    ];
  }

  /**
   * @param list<int> $dueDates
   * @return array{payment:float,rows:list<array<string,mixed>>,installments:int,last_ts:int,interest:float,total:float}
   */
  private static function plan(float $balance, float $payment, float $rate, float $firstRate, array $dueDates, int $nextNumber): array
  {
    $result = $balance > 0 ? OwnerCreditCoverage::amortize($balance, $payment, $rate, $firstRate, count($dueDates)) : ['payment' => 0.0, 'rows' => [], 'total_interest' => 0.0];
    $rows = [];
    foreach ($result['rows'] as $index => $row) $rows[] = ['numero' => $nextNumber + $index, 'fecha' => (int) $dueDates[$index]] + $row;
    $last = $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['fecha'];
    return [
      'payment' => $payment, 'rows' => $rows, 'installments' => count($rows), 'last_ts' => $last,
      'interest' => (float) $result['total_interest'], 'total' => round(array_sum(array_column($rows, 'cuota')), 2),
    ];
  }

  private static function money(float $value): string
  {
    return number_format(max(0.0, $value), 2, '.', '');
  }

  private static function rate(float $value): string
  {
    return rtrim(rtrim(number_format(max(0.0, $value), 15, '.', ''), '0'), '.') ?: '0';
  }

  private static function round(string $value): string
  {
    return bcadd(bcadd($value, '0.005', self::SCALE), '0', 2);
  }

  private static function pesos(float $value): string
  {
    return '$' . number_format($value, 2, ',', '.');
  }
}
