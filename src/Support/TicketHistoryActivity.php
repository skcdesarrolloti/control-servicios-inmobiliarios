<?php

declare(strict_types=1);

namespace SCM\Support;

final class TicketHistoryActivity
{
  /** Empty legacy creation placeholders are not responses; retain media and linked activities. */
  public static function visible(array $items): array
  {
    return array_values(array_filter($items, static function ($item): bool {
      if (!is_array($item)) return false;
      foreach (['respuesta', 'observacion', 'descripcion', 'tipo_reporte', 'imagen', 'imagenes', 'evidencia', 'archivos', 'seguimiento_reparaciones', 'fecha_cita'] as $field) {
        $value = $item[$field] ?? '';
        if (is_array($value) ? $value !== [] : trim((string) $value) !== '') return true;
      }
      foreach ($item as $field => $value) {
        if (str_starts_with((string) $field, 'id_') && !in_array($field, ['id_ticket', 'id_empleado'], true)
          && is_scalar($value) && trim((string) $value) !== '' && (string) $value !== '0') return true;
      }
      return false;
    }));
  }
}
