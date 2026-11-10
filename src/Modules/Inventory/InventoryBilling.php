<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

/**
 * Cobro del inventario inicial (revisión antes de la ocupación). La tarifa se calcula cada vez con la configuración
 * del sistema (salario / días de trabajo × porcentaje), así que cambia sola cuando cambia el salario.
 */
final class InventoryBilling
{
  public const REPORT_CATEGORY = 'Revision antes de la ocupacion';
  public const MAX_REPORTS_PER_CASE = 2;
  public const CONFIG_KEYS = ['salario', 'dias_trabajo', 'porcentaje_smlmv_entre_rec', 'valor_transporte'];

  /** @param array<string,mixed> $config */
  public static function fee(array $config): int
  {
    $salary = self::number($config['salario'] ?? 0);
    $days = self::number($config['dias_trabajo'] ?? 0);
    $percentage = self::number($config['porcentaje_smlmv_entre_rec'] ?? 0);
    // Se guarda como factor (1.31); un valor escrito como porcentaje (131) también se entiende.
    $factor = $percentage >= 10 ? $percentage / 100 : $percentage;
    return $salary > 0 && $days > 0 && $factor > 0 ? (int) round($salary / $days * $factor) : 0;
  }

  /** Transporte de ida y vuelta. @param array<string,mixed> $config */
  public static function transport(array $config): int
  {
    return (int) round(self::number($config['valor_transporte'] ?? 0)) * 2;
  }

  /**
   * @param array<string,mixed> $config
   * @return array{base:int,servicio:int,transporte:int,total:int,doble:bool}
   */
  public static function amounts(array $config, string $category, mixed $floors, bool $withTransport): array
  {
    $base = self::fee($config);
    $double = InventorySchema::doubleCharge($category, $floors);
    $service = $double ? $base * 2 : $base;
    $transport = $withTransport ? self::transport($config) : 0;
    return ['base' => $base, 'servicio' => $service, 'transporte' => $transport, 'total' => $service + $transport, 'doble' => $double];
  }

  public static function description(string $contract, string $request): string
  {
    return 'Contrato #' . ($contract !== '' ? $contract : 'No asignado') . ' con asegurable #' . ($request !== '' ? $request : 'No asignado')
      . ': Se realiza revisión antes de la ocupación que consiste en realizar el registro fotografico y el inventario inicial.';
  }

  private static function number(mixed $value): float
  {
    return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
  }
}
