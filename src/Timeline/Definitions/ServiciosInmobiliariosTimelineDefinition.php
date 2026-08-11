<?php

namespace SCM\Timeline\Definitions;

use SCM\Support\TimelineMaps\MaintenanceTimelineMap;
use SCM\Support\TimestampParser;

final class ServiciosInmobiliariosTimelineDefinition
{
  /**
   * @return array<int,array<string,mixed>>
   */
  public static function definitions(): array
  {
    $definitions = [];
    foreach (MaintenanceTimelineMap::steps() as $step) {
      $visibleWhen = (string)($step['visible_when'] ?? '');
      if ($visibleWhen === 'has_cotizacion') {
        $step['visible_when'] = [self::class, 'hasCotizacion'];
      } elseif ($visibleWhen === 'has_acta_or_cotizacion') {
        $step['visible_when'] = [self::class, 'hasActaOrCotizacion'];
      } elseif ($visibleWhen === 'has_cotizacion_aprobada') {
        $step['visible_when'] = [self::class, 'hasCotizacionAprobada'];
      } elseif ($visibleWhen === 'has_acta_cotizacion_aprobada') {
        $step['visible_when'] = [self::class, 'hasActaCotizacionAprobada'];
      } else {
        unset($step['visible_when']);
      }

      $tsFields = is_array($step['internal_date_fields'] ?? null) ? $step['internal_date_fields'] : [];
      if (empty($tsFields)) {
        $tsFields = is_array($step['date_columns'] ?? null) ? $step['date_columns'] : [];
      }
      $statusFields = is_array($step['internal_status_fields'] ?? null) ? $step['internal_status_fields'] : [];
      if (empty($statusFields)) {
        $statusFields = is_array($step['status_columns'] ?? null) ? $step['status_columns'] : [];
      }
      $alwaysDone = (string)($step['status'] ?? '') === 'always';
      $flagFields = is_array($step['internal_flag_fields'] ?? null) ? $step['internal_flag_fields'] : [];
      if (empty($flagFields)) {
        $flagFields = is_array($step['requires_flag_columns'] ?? null) ? $step['requires_flag_columns'] : [];
      }
      $flagValues = is_array($step['requires_flag_values'] ?? null)
        ? array_map('strval', $step['requires_flag_values'])
        : ['si', 'sí', '1', 'true', 'enviada', 'enviado', 'con danos', 'con daños'];
      $source = (string)($step['table'] ?? '');
      $dateColumns = is_array($step['date_columns'] ?? null) ? $step['date_columns'] : [];
      if ($source !== '' && !empty($dateColumns)) {
        $source .= '.' . implode('|', array_map('strval', $dateColumns));
      }

      $step['resolver'] = static function (array $row, TimestampParser $parser) use ($tsFields, $statusFields, $flagFields, $flagValues, $alwaysDone): array {
        $ts = 0;
        foreach ($tsFields as $field) {
          $ts = $parser->parse($row[$field] ?? null);
          if ($ts > 0) {
            break;
          }
        }

        $sub = '';
        foreach ($statusFields as $field) {
          $sub = trim((string)($row[$field] ?? ''));
          if ($sub !== '') {
            break;
          }
        }

        $flagOk = true;
        if (!empty($flagFields)) {
          $flagOk = false;
          $allowed = array_fill_keys(array_map(static function (string $value): string {
            return strtolower(trim($value));
          }, $flagValues), true);
          foreach ($flagFields as $field) {
            $value = strtolower(trim((string)($row[$field] ?? '')));
            if ($value !== '' && isset($allowed[$value])) {
              $flagOk = true;
              break;
            }
          }
        }

        return [
          'status' => ($alwaysDone || ($ts > 0 && $flagOk)) ? 'done' : 'none',
          'ts' => $ts,
          'sub' => $sub,
        ];
      };

      $step['source'] = $source;
      $definitions[] = $step;
    }

    return $definitions;
  }

  public static function hasCotizacion(array $row, TimestampParser $parser): bool
  {
    foreach ([
      'id_cotizacion_mantenimiento',
      'id_cotizacion',
      '_scm_cot_fecha_registro',
      'fecha_cotizacion_mantenimiento',
      '_scm_cot_fecha_envio',
      'fecha_envio_cotizacion_mantenimiento',
      '_scm_cot_fecha_respuesta',
      'fecha_respuesta_cotizacion_mantenimiento',
      '_scm_cot_final_trabajo',
      'final_trabajo',
    ] as $field) {
      if (trim((string)($row[$field] ?? '')) !== '') {
        return true;
      }
    }

    return false;
  }

  public static function hasActaOrCotizacion(array $row, TimestampParser $parser): bool
  {
    if (self::hasCotizacion($row, $parser)) {
      return true;
    }

    foreach ([
      '_scm_acta_fecha',
      '_scm_cot_id_acta',
      'id_acta_satisfaccion',
    ] as $field) {
      if (trim((string)($row[$field] ?? '')) !== '') {
        return true;
      }
    }

    return false;
  }

  public static function hasCotizacionAprobada(array $row, TimestampParser $parser): bool
  {
    $estado = strtolower(trim((string)(
      $row['estado_respuesta_cotizacion_mantenimiento']
      ?? $row['estado_respuesta']
      ?? $row['estado_cotizacion_mantenimiento']
      ?? $row['estado']
      ?? ''
    )));

    if ($estado === '') {
      return false;
    }

    return in_array($estado, ['aprobada', 'aprobado'], true);
  }

  public static function hasActaCotizacionAprobada(array $row, TimestampParser $parser): bool
  {
    $hasActa = trim((string) ($row['_scm_acta_fecha'] ?? '')) !== ''
      || trim((string) ($row['_scm_cot_id_acta'] ?? '')) !== ''
      || trim((string) ($row['id_acta_satisfaccion'] ?? '')) !== '';

    if (!$hasActa) {
      return false;
    }

    return self::hasCotizacionAprobada($row, $parser);
  }
}
