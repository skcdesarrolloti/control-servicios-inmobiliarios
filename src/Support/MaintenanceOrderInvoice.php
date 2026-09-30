<?php
declare(strict_types=1);

namespace SCM\Support;

/** Shared monetary rows for the order view and its payment PDF. */
final class MaintenanceOrderInvoice
{
  /** @return array<int,array{label:string,quote:mixed,order:mixed,current:bool}> */
  public static function rows(array $order, ?array $quote): array
  {
    $current = self::category((string) ($order['categoria'] ?? ''));
    $amount = $order['valor'] ?? 0;
    $types = [
      'Mano de obra' => 'total_mano_obra',
      'Materiales' => 'total_materiales',
      'Maquinarias' => 'total_maquinarias',
      'Otros costos' => 'total_otros_costos',
    ];
    $rows = [];
    foreach ($types as $label => $field) {
      $selected = $current === $label;
      $rows[] = [
        'label' => $label,
        'quote' => $quote !== null && array_key_exists($field, $quote) ? $quote[$field] : null,
        'order' => $selected ? $amount : 0,
        'current' => $selected,
      ];
    }
    if ($current === '') {
      $rows[] = [
        'label' => trim((string) ($order['categoria'] ?? '')) ?: 'Sin clasificar',
        'quote' => null,
        'order' => $amount,
        'current' => true,
      ];
    }
    foreach (['Administración' => 'total_admon', 'IVA administración' => 'iva_admon'] as $label => $field) {
      $rows[] = [
        'label' => $label,
        'quote' => $quote !== null && array_key_exists($field, $quote) ? $quote[$field] : null,
        'order' => 0,
        'current' => false,
      ];
    }
    return $rows;
  }

  public static function quoteTotal(?array $quote): mixed
  {
    return $quote !== null && array_key_exists('total', $quote) ? $quote['total'] : null;
  }

  private static function category(string $value): string
  {
    $value = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], mb_strtolower(trim($value), 'UTF-8'));
    return match ($value) {
      'mano de obra' => 'Mano de obra',
      'materiales' => 'Materiales',
      'maquinaria', 'maquinarias', 'equipos', 'equipos / maquinarias' => 'Maquinarias',
      'otros costo', 'otros costos' => 'Otros costos',
      default => '',
    };
  }
}
