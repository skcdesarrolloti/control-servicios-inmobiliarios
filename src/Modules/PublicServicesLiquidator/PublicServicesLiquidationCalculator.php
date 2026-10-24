<?php

declare(strict_types=1);

namespace SCM\Modules\PublicServicesLiquidator;

final class PublicServicesLiquidationCalculator
{
  /** @param array<string,mixed> $input @return array<string,mixed> */
  public function calculate(array $input): array
  {
    $previousDate = $this->dateTs((string) ($input['fecha_lectura_anterior'] ?? ''));
    $handoverDate = $this->dateTs((string) ($input['fecha_entrega'] ?? ''));
    $currentDate = $this->dateTs((string) ($input['fecha_lectura_actual'] ?? ''));

    $previousReading = $this->number($input['lectura_anterior'] ?? null);
    $handoverReading = $this->number($input['lectura_inicial'] ?? null);
    $currentReading = $this->number($input['lectura_actual'] ?? null);
    $billedConsumptionValue = $this->money($input['valor_consumo_facturado'] ?? null);
    $invoiceTotal = $this->money($input['total_factura'] ?? null);
    $tenantOtherCharges = $this->money($input['otros_cargos_inquilino'] ?? null);
    $ownerOtherCharges = $this->money($input['otros_cargos_no_inquilino'] ?? null);
    $fixedCharges = $this->fixedCharges($input);

    $totalConsumption = $this->blankIfNull($previousReading, $currentReading, static fn(float $a, float $b): float => $b - $a);
    $ownerConsumption = $this->blankIfNull($previousReading, $handoverReading, static fn(float $a, float $b): float => $b - $a);
    $tenantConsumption = $this->blankIfNull($handoverReading, $currentReading, static fn(float $a, float $b): float => $b - $a);
    $unitValue = ($totalConsumption !== null && abs($totalConsumption) > 0.000001 && $billedConsumptionValue > 0)
      ? $billedConsumptionValue / $totalConsumption
      : null;
    $ownerConsumptionValue = ($ownerConsumption !== null && $unitValue !== null) ? $ownerConsumption * $unitValue : null;
    $tenantConsumptionValue = ($tenantConsumption !== null && $unitValue !== null) ? $tenantConsumption * $unitValue : null;

    $cycleDays = $this->daysBetween($previousDate, $currentDate);
    $ownerDays = $this->daysBetween($previousDate, $handoverDate);
    $tenantDays = $this->daysBetween($handoverDate, $currentDate);
    $ownerFixedCharge = $ownerDays !== null ? min($fixedCharges, ($fixedCharges / 30) * $ownerDays) : null;
    $tenantFixedCharge = $ownerFixedCharge !== null ? max(0.0, $fixedCharges - $ownerFixedCharge) : null;

    $ownerPart = ($ownerConsumptionValue !== null && $ownerFixedCharge !== null)
      ? $ownerConsumptionValue + $ownerFixedCharge + $ownerOtherCharges
      : null;
    $tenantTotal = ($tenantConsumptionValue !== null && $tenantFixedCharge !== null)
      ? $tenantConsumptionValue + $tenantFixedCharge + $tenantOtherCharges
      : null;
    $calculatedTotal = ($ownerPart !== null && $tenantTotal !== null) ? $ownerPart + $tenantTotal : null;
    $difference = ($invoiceTotal > 0 && $calculatedTotal !== null) ? $invoiceTotal - $calculatedTotal : null;
    $reimbursement = ($invoiceTotal > 0 && $tenantTotal !== null) ? $invoiceTotal - $tenantTotal : null;

    return [
      'input' => [
        'fecha_lectura_anterior' => $previousDate,
        'fecha_entrega' => $handoverDate,
        'fecha_lectura_actual' => $currentDate,
        'lectura_anterior' => $previousReading,
        'lectura_inicial' => $handoverReading,
        'lectura_actual' => $currentReading,
        'valor_consumo_facturado' => $billedConsumptionValue,
        'total_factura' => $invoiceTotal,
        'otros_cargos_inquilino' => $tenantOtherCharges,
        'otros_cargos_no_inquilino' => $ownerOtherCharges,
        'total_cargos_fijos' => $fixedCharges,
      ],
      'consumo_total_periodo' => $totalConsumption,
      'consumo_antes_entrega' => $ownerConsumption,
      'consumo_inquilino' => $tenantConsumption,
      'valor_unidad' => $unitValue,
      'valor_consumo_antes_entrega' => $ownerConsumptionValue,
      'valor_consumo_inquilino' => $tenantConsumptionValue,
      'dias_ciclo' => $cycleDays,
      'dias_antes_entrega' => $ownerDays,
      'dias_inquilino' => $tenantDays,
      'cargo_fijo_antes_entrega' => $ownerFixedCharge,
      'cargo_fijo_inquilino' => $tenantFixedCharge,
      'parte_anterior_propietario' => $ownerPart,
      'total_pagar_inquilino' => $tenantTotal,
      'total_calculado' => $calculatedTotal,
      'diferencia_total_factura' => $difference,
      'valor_reembolsar' => $reimbursement,
      'validaciones' => [
        'lectura_inicial_rango' => $this->readingValidation($previousReading, $handoverReading, $currentReading),
        'fecha_entrega_ciclo' => $this->dateValidation($previousDate, $handoverDate, $currentDate),
        'cargos_fijos_prorrateados' => $ownerDays === null ? 'Pendiente' : ($ownerDays <= 30 ? 'OK' : 'REVISAR: mas de 30 dias'),
        'cuadre_total_factura' => $invoiceTotal <= 0 || $difference === null ? 'Sin total factura' : (abs($difference) < 1 ? 'OK' : 'REVISAR CARGOS'),
      ],
    ];
  }

  /**
   * Campos sin los que el prorrateo no puede calcularse.
   * @param array<string,mixed> $input @return array<int,string> etiquetas de los campos vacíos o inválidos
   */
  public function missingFields(array $input): array
  {
    $required = [
      'fecha_lectura_anterior' => ['Fecha lectura anterior', 'date'],
      'lectura_anterior' => ['Lectura anterior', 'number'],
      'fecha_entrega' => ['Fecha entrega inmueble', 'date'],
      'lectura_inicial' => ['Lectura entrega llaves', 'number'],
      'fecha_lectura_actual' => ['Fecha lectura actual', 'date'],
      'lectura_actual' => ['Lectura actual factura', 'number'],
      'valor_consumo_facturado' => ['Valor consumo facturado', 'money'],
      'total_factura' => ['Total factura liquidada', 'money'],
    ];
    $missing = [];
    foreach ($required as $field => [$label, $kind]) {
      $value = $input[$field] ?? null;
      $ok = match ($kind) {
        'date' => $this->dateTs((string) $value) !== null,
        'money' => $this->money($value) > 0,
        default => $this->number($value) !== null,
      };
      if (!$ok) {
        $missing[] = $label;
      }
    }
    return $missing;
  }

  /** @param mixed $value */
  private function number($value): ?float
  {
    if ($value === null) {
      return null;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
      return null;
    }
    // Formato colombiano: punto de miles y coma decimal ("1.250.000", "1.250,5", "80.000").
    $normalized = str_replace(['$', ' ', "\u{00A0}", 'COP', 'cop'], '', $raw);
    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');
    if ($lastComma !== false && $lastDot !== false) {
      $decimal = $lastComma > $lastDot ? ',' : '.';
      $normalized = str_replace($decimal === ',' ? '.' : ',', '', $normalized);
      $normalized = str_replace(',', '.', $normalized);
    } elseif ($lastComma !== false) {
      $normalized = substr_count($normalized, ',') > 1 ? str_replace(',', '', $normalized) : str_replace(',', '.', $normalized);
    } elseif ($lastDot !== false && (substr_count($normalized, '.') > 1 || preg_match('/^\d{1,3}\.\d{3}$/', $normalized) === 1)) {
      $normalized = str_replace('.', '', $normalized);
    }
    return is_numeric($normalized) ? (float) $normalized : null;
  }

  /** @param mixed $value */
  private function money($value): float
  {
    $number = $this->number($value);
    return $number !== null ? max(0.0, $number) : 0.0;
  }

  /** @param array<string,mixed> $input */
  private function fixedCharges(array $input): float
  {
    $total = 0.0;
    $charges = $input['cargos_fijos'] ?? [];
    if (is_array($charges)) {
      foreach ($charges as $value) {
        $total += $this->money($value);
      }
    }
    for ($i = 1; $i <= 8; $i++) {
      if (array_key_exists('cargo_fijo_' . $i, $input)) {
        $total += $this->money($input['cargo_fijo_' . $i]);
      }
    }
    return $total;
  }

  private function dateTs(string $value): ?int
  {
    $value = trim($value);
    if ($value === '') {
      return null;
    }
    $tz = new \DateTimeZone('America/Bogota');
    foreach (['Y-m-d', 'd/m/Y', 'Y-m-d H:i:s'] as $format) {
      $dt = \DateTimeImmutable::createFromFormat($format, $value, $tz);
      if ($dt instanceof \DateTimeImmutable) {
        return $dt->setTime(0, 0)->getTimestamp();
      }
    }
    $ts = strtotime($value);
    return $ts === false ? null : (int) $ts;
  }

  private function daysBetween(?int $start, ?int $end): ?int
  {
    if ($start === null || $end === null) {
      return null;
    }
    return max(0, (int) floor(($end - $start) / 86400));
  }

  /** @param callable(float,float):float $fn */
  private function blankIfNull(?float $a, ?float $b, callable $fn): ?float
  {
    return $a === null || $b === null ? null : $fn($a, $b);
  }

  private function readingValidation(?float $previous, ?float $handover, ?float $current): string
  {
    if ($previous === null || $handover === null || $current === null) {
      return 'Pendiente';
    }
    return $handover >= $previous && $handover <= $current ? 'OK' : 'REVISAR';
  }

  private function dateValidation(?int $previous, ?int $handover, ?int $current): string
  {
    if ($previous === null || $handover === null || $current === null) {
      return 'Pendiente';
    }
    return $handover >= $previous && $handover <= $current ? 'OK' : 'REVISAR';
  }
}
