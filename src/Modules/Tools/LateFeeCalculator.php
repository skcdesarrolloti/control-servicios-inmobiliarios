<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Herramientas › Calculadora de mora: compara, por cada canon vencido, el interés de mora (1,5 × tasa
 * efectiva anual de consumo y ordinario certificada por la SFC) con la cláusula penal y recomienda el mayor.
 * Basada en herramientas/cal-mora/lib/Calculator.php; acepta montos y tasas con formato colombiano.
 */
final class LateFeeCalculator
{
  /**
   * @param array<string, mixed> $input
   * @return array<string, mixed>
   */
  public static function run(array $input): array
  {
    $annualEffectiveRatePercent = self::num($input['annual_effective_rate_percent'] ?? 0.0);
    $globalPenaltyPercent = self::num($input['global_penalty_percent'] ?? 0.0);
    $globalPaymentDate = self::dateOrNull($input['global_payment_date'] ?? null);
    $rows = is_array($input['installments'] ?? null) ? $input['installments'] : [];

    if ($annualEffectiveRatePercent <= 0) {
      throw new InvalidArgumentException('La tasa efectiva anual debe ser mayor a cero.');
    }

    if ($globalPenaltyPercent < 0) {
      throw new InvalidArgumentException('La clausula penal no puede ser negativa.');
    }

    if ($rows === []) {
      throw new InvalidArgumentException('Debes registrar al menos un canon.');
    }

    $annualEffectiveRate = $annualEffectiveRatePercent / 100.0;
    $moraAnnualRate = $annualEffectiveRate * 1.5;
    $moraMonthlyRate = $moraAnnualRate / 12.0;

    $today = new DateTimeImmutable('today', new DateTimeZone('America/Bogota'));
    $items = [];

    $totalCapital = 0.0;
    $totalInterest = 0.0;
    $totalPenalty = 0.0;
    $totalRecommended = 0.0;
    $totalPaid = 0.0;
    $byType = [
      'canon' => [
        'total_capital' => 0.0,
        'total_interest_mora' => 0.0,
        'total_clausula_penal' => 0.0,
        'total_recommended_charge' => 0.0,
      ],
      'servicios_publicos' => [
        'total_capital' => 0.0,
        'total_interest_mora' => 0.0,
        'total_clausula_penal' => 0.0,
        'total_recommended_charge' => 0.0,
      ],
    ];

    foreach ($rows as $index => $row) {
      if (!is_array($row)) {
        continue;
      }

      $concept = trim((string) ($row['concept'] ?? 'Canon ' . ($index + 1)));
      $installmentType = self::normalizeInstallmentType($row['installment_type'] ?? null, $concept);
      $capital = self::num($row['amount'] ?? 0.0);
      $paidAmount = self::num($row['paid_amount'] ?? 0.0);
      $penaltyPercent = $globalPenaltyPercent;
      if (array_key_exists('penalty_percent', $row) && $row['penalty_percent'] !== null && $row['penalty_percent'] !== '') {
        $penaltyPercent = self::num($row['penalty_percent']);
      }

      $dueDate = self::dateOrNull($row['due_date'] ?? null);
      if ($dueDate === null) {
        throw new InvalidArgumentException('La fecha de vencimiento es obligatoria en la fila ' . ($index + 1) . '.');
      }

      $paymentDate = self::dateOrNull($row['payment_date'] ?? null)
        ?? $globalPaymentDate
        ?? $today;

      if ($capital < 0) {
        throw new InvalidArgumentException('El valor del canon no puede ser negativo en la fila ' . ($index + 1) . '.');
      }
      if ($paidAmount < 0) {
        throw new InvalidArgumentException('El valor pagado no puede ser negativo en la fila ' . ($index + 1) . '.');
      }

      $daysLate = self::daysLate($dueDate, $paymentDate);
      $monthsLate = $daysLate / 30.0;

      $interest = 0.0;
      $penalty = 0.0;
      if ($daysLate > 0 && $capital > 0) {
        $interest = (($capital * $moraMonthlyRate) / 30.0) * $daysLate;
        $penalty = $capital * ($penaltyPercent / 100.0);
      }

      $recommended = max($interest, $penalty);
      $method = $interest >= $penalty ? 'interes_mora' : 'clausula_penal';

      $interestRounded = self::money($interest);
      $penaltyRounded = self::money($penalty);
      $recommendedRounded = self::money($recommended);

      $totalCapital += $capital;
      $totalInterest += $interestRounded;
      $totalPenalty += $penaltyRounded;
      $totalRecommended += $recommendedRounded;
      $totalPaid += $paidAmount;
      $byType[$installmentType]['total_capital'] += $capital;
      $byType[$installmentType]['total_interest_mora'] += $interestRounded;
      $byType[$installmentType]['total_clausula_penal'] += $penaltyRounded;
      $byType[$installmentType]['total_recommended_charge'] += $recommendedRounded;

      $items[] = [
        'index' => $index,
        'concept' => $concept,
        'installment_type' => $installmentType,
        'amount' => self::money($capital),
        'paid_amount' => self::money($paidAmount),
        'due_date' => $dueDate->format('Y-m-d'),
        'payment_date' => $paymentDate->format('Y-m-d'),
        'penalty_percent' => round($penaltyPercent, 4),
        'days_late' => $daysLate,
        'months_late' => round($monthsLate, 4),
        'interest_mora' => $interestRounded,
        'clausula_penal' => $penaltyRounded,
        'recommended_charge' => $recommendedRounded,
        'recommended_method' => $method,
      ];
    }

    return [
      'rates' => [
        'annual_effective_rate_percent' => round($annualEffectiveRatePercent, 6),
        'mora_annual_rate_percent' => round($moraAnnualRate * 100.0, 6),
        'mora_monthly_rate_percent' => round($moraMonthlyRate * 100.0, 6),
      ],
      'summary' => [
        'total_capital' => self::money($totalCapital),
        'total_interest_mora' => self::money($totalInterest),
        'total_clausula_penal' => self::money($totalPenalty),
        'total_recommended_charge' => self::money($totalRecommended),
        'total_paid_amount' => self::money($totalPaid),
        'by_type' => [
          'canon' => [
            'total_capital' => self::money($byType['canon']['total_capital']),
            'total_interest_mora' => self::money($byType['canon']['total_interest_mora']),
            'total_clausula_penal' => self::money($byType['canon']['total_clausula_penal']),
            'total_recommended_charge' => self::money($byType['canon']['total_recommended_charge']),
          ],
          'servicios_publicos' => [
            'total_capital' => self::money($byType['servicios_publicos']['total_capital']),
            'total_interest_mora' => self::money($byType['servicios_publicos']['total_interest_mora']),
            'total_clausula_penal' => self::money($byType['servicios_publicos']['total_clausula_penal']),
            'total_recommended_charge' => self::money($byType['servicios_publicos']['total_recommended_charge']),
          ],
        ],
      ],
      'items' => $items,
    ];
  }

  private static function normalizeInstallmentType($value, string $concept): string
  {
    if (is_string($value)) {
      $raw = trim(strtolower($value));
      if ($raw === 'servicios_publicos' || $raw === 'servicios' || $raw === 'servicio_publico' || $raw === 'servicios públicos') {
        return 'servicios_publicos';
      }
      if ($raw === 'canon' || $raw === 'arriendo') {
        return 'canon';
      }
    }

    $conceptLower = strtolower($concept);
    if (
      strpos($conceptLower, 'servicio') !== false ||
      strpos($conceptLower, 'acueducto') !== false ||
      strpos($conceptLower, 'energia') !== false ||
      strpos($conceptLower, 'luz') !== false ||
      strpos($conceptLower, 'gas') !== false
    ) {
      return 'servicios_publicos';
    }

    return 'canon';
  }

  private static function daysLate(DateTimeImmutable $dueDate, DateTimeImmutable $paymentDate): int
  {
    $diffDays = (int) $dueDate->diff($paymentDate)->format('%r%a');
    return max(0, $diffDays);
  }

  private static function num($value): float
  {
    if (is_float($value) || is_int($value)) {
      return (float) $value;
    }

    if (is_string($value)) {
      // Formato colombiano: punto de miles y coma decimal ("1.500.000", "1.500.000,50", "19,06", "$ 80.000").
      $clean = str_replace(['$', ' ', "\u{00A0}", '%'], '', trim($value));
      if ($clean === '') {
        return 0.0;
      }
      $lastComma = strrpos($clean, ',');
      $lastDot = strrpos($clean, '.');
      if ($lastComma !== false && $lastDot !== false) {
        $decimal = $lastComma > $lastDot ? ',' : '.';
        $clean = str_replace($decimal === ',' ? '.' : ',', '', $clean);
        $clean = str_replace(',', '.', $clean);
      } elseif ($lastComma !== false) {
        $clean = substr_count($clean, ',') > 1 ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
      } elseif ($lastDot !== false && (substr_count($clean, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $clean) === 1)) {
        $clean = str_replace('.', '', $clean);
      }
      return is_numeric($clean) ? (float) $clean : 0.0;
    }

    return 0.0;
  }

  private static function money(float $value): float
  {
    return round($value, 2);
  }

  private static function dateOrNull($value): ?DateTimeImmutable
  {
    if (!is_string($value)) {
      return null;
    }

    $raw = trim($value);
    if ($raw === '') {
      return null;
    }

    $tz = new DateTimeZone('America/Bogota');
    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y'];
    foreach ($formats as $format) {
      $d = DateTimeImmutable::createFromFormat('!' . $format, $raw, $tz);
      if ($d instanceof DateTimeImmutable) {
        return $d;
      }
    }

    try {
      return new DateTimeImmutable($raw, $tz);
    } catch (Throwable $e) {
      return null;
    }
  }
}
