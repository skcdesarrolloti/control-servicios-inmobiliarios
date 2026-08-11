<?php


namespace SCM\Metrics;

use SCM\Support\TimestampParser;

final class ServiciosInmobiliariosMetricsService
{
  private TimestampParser $parser;
  private int $slaRiskDays;
  private int $slaOverdueDays;
  /** @var array<string,mixed> */
  private array $kpiRules;

  public function __construct(TimestampParser $parser, int $slaRiskDays = 8, int $slaOverdueDays = 15, array $kpiRules = [])
  {
    $this->parser = $parser;
    $this->slaRiskDays = max(1, $slaRiskDays);
    $this->slaOverdueDays = max($this->slaRiskDays, $slaOverdueDays);
    $this->kpiRules = $kpiRules;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  public function enrichRows(array $rows): array
  {
    $nowTs = time();

    foreach ($rows as &$row) {
      $createdTs = $this->parser->parse($row['fecha'] ?? null);
      $isClosed = $this->isClosed((string) ($row['estado'] ?? ''));
      $closeTs = $this->resolveCloseTimestamp($row, $createdTs, $nowTs);
      $firstContactTs = $this->resolveFirstContactTimestamp($row);

      $daysOpen = null;
      if ($createdTs > 0) {
        $referenceTs = $isClosed && $closeTs > 0 ? $closeTs : $nowTs;
        $daysOpen = (int) floor(($referenceTs - $createdTs) / 86400);
        if ($daysOpen < 0) {
          $daysOpen = 0;
        }
      }

      $slaStatus = 'sin_referencia';
      if ($daysOpen !== null) {
        if ($isClosed) {
          $slaStatus = 'cerrado';
        } elseif ($daysOpen >= $this->slaOverdueDays) {
          $slaStatus = 'vencido';
        } elseif ($daysOpen >= $this->slaRiskDays) {
          $slaStatus = 'en_riesgo';
        } else {
          $slaStatus = 'en_tiempo';
        }
      }

      $timeToFirstContactHours = null;
      if ($createdTs > 0 && $firstContactTs > 0 && $firstContactTs >= $createdTs) {
        $timeToFirstContactHours = ($firstContactTs - $createdTs) / 3600;
      }

      $timeToCloseHours = null;
      if ($isClosed && $createdTs > 0 && $closeTs > 0 && $closeTs >= $createdTs) {
        $timeToCloseHours = ($closeTs - $createdTs) / 3600;
      }

      $row['_scm_created_ts'] = $createdTs;
      $row['_scm_close_ts'] = $closeTs;
      $row['_scm_first_contact_ts'] = $firstContactTs;
      $row['_scm_days_open'] = $daysOpen;
      $row['_scm_is_closed'] = $isClosed ? '1' : '0';
      $row['_scm_sla_status'] = $slaStatus;
      $row['_scm_time_to_first_contact_hours'] = $timeToFirstContactHours;
      $row['_scm_time_to_close_hours'] = $timeToCloseHours;
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<string,mixed>
   */
  public function calculate(array $rows): array
  {
    // Los KPIs se calculan solo sobre tickets activos (Nuevo / En proceso)
    $rows = array_values(array_filter($rows, static function (array $row): bool {
      $estado = strtolower(trim((string) ($row['estado'] ?? '')));
      return in_array($estado, ['nuevo', 'en proceso'], true);
    }));

    $stats = [
      'total' => count($rows),
      'sin_cotizacion' => 0,
      'con_cotizacion' => 0,
      'sin_preventiva' => 0,
      'con_preventiva' => 0,
      'sin_revision' => 0,
      'con_revision' => 0,
      'abiertos' => 0,
      'cerrados' => 0,
      'vencidos' => 0,
      'en_riesgo' => 0,
      'sla_vencido' => 0,
      'sla_riesgo' => 0,
      'promedio_primera_gestion_horas' => null,
      'promedio_cierre_horas' => null,
      'promedio_desactualizacion_horas' => null,
      'avg_first_h' => null,
      'avg_close_h' => null,
      'avg_stale_h' => null,
      'mes_actualizados' => 0,
      'mes_cerrados' => 0,
      'mes_seguimientos' => 0,
    ];

    $firstContactSamples = [];
    $closeSamples = [];
    $staleSamples = [];
    $monthStart = strtotime(date('Y-m-01 00:00:00'));
    $monthEnd = strtotime(date('Y-m-t 23:59:59'));

    /** @var array<string,int> */
    $segPorAsesor = [];
    /** @var array<string,int> */
    $abiertosPorAsesor = [];
    /** @var array<string,int> */
    $actualizadosPorAsesor = [];

    foreach ($rows as $row) {
      $hasCot = $this->evaluateKpiRule('con_cotizacion', $row, static function (array $r): bool {
        return trim((string) ($r['id_cotizacion_mantenimiento'] ?? '')) !== '';
      });
      $hasPrev = trim((string) ($row['id_revision_preventiva'] ?? '')) !== '';
      $hasCorr = trim((string) ($row['id_revision_correctiva'] ?? '')) !== '';
      $hasRevision = $this->evaluateKpiRule('con_revision', $row, static function (array $r) use ($hasPrev, $hasCorr): bool {
        return $hasPrev || $hasCorr;
      });
      $isClosed = $this->evaluateKpiRule('cerrados', $row, static function (array $r): bool {
        return (string) ($r['_scm_is_closed'] ?? '0') === '1';
      });
      $slaStatus = (string) ($row['_scm_sla_status'] ?? 'sin_referencia');

      if ($hasCot) {
        $stats['con_cotizacion']++;
      } else {
        $stats['sin_cotizacion']++;
      }

      if ($hasPrev) {
        $stats['con_preventiva']++;
      } else {
        $stats['sin_preventiva']++;
      }

      if ($hasRevision) {
        $stats['con_revision']++;
      } else {
        $stats['sin_revision']++;
      }

      if ($isClosed) {
        $stats['cerrados']++;
      } else {
        $stats['abiertos']++;
      }

      // Asesor asignado al ticket (para desglose por empleado)
      $asesor = trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? ''));
      if ($asesor === '') {
        $asesor = 'Sin asignar';
      }

      $updatedTs = $this->parser->parse($row['fecha_actualizacion'] ?? null);
      if ($updatedTs > 0 && $updatedTs >= $monthStart && $updatedTs <= $monthEnd) {
        $stats['mes_actualizados']++;
        $actualizadosPorAsesor[$asesor] = ($actualizadosPorAsesor[$asesor] ?? 0) + 1;
      }

      $closeTs = (int) ($row['_scm_close_ts'] ?? 0);
      if ($isClosed && $closeTs > 0 && $closeTs >= $monthStart && $closeTs <= $monthEnd) {
        $stats['mes_cerrados']++;
      }

      if (!$isClosed) {
        $abiertosPorAsesor[$asesor] = ($abiertosPorAsesor[$asesor] ?? 0) + 1;
      }

      // Seguimientos del mes por asesor
      $seguimientos = $row['_scm_seguimientos_ticket'] ?? [];
      if (is_array($seguimientos)) {
        foreach ($seguimientos as $segItem) {
          if (!is_array($segItem)) {
            continue;
          }
          $segTs = $this->parser->parse($segItem['fecha'] ?? $segItem['cct_created'] ?? $segItem['cct_modified'] ?? null);
          if ($segTs <= 0 || $segTs < $monthStart || $segTs > $monthEnd) {
            continue;
          }
          $stats['mes_seguimientos']++;
          $segAsesor = trim((string) ($segItem['nombre'] ?? $segItem['id_empleado'] ?? ''));
          if ($segAsesor === '') {
            $segAsesor = $asesor;
          }
          $segPorAsesor[$segAsesor] = ($segPorAsesor[$segAsesor] ?? 0) + 1;
        }
      }

      if ($slaStatus === 'vencido') {
        $stats['vencidos']++;
        $stats['sla_vencido']++;
      }
      if ($slaStatus === 'en_riesgo') {
        $stats['en_riesgo']++;
        $stats['sla_riesgo']++;
      }

      $firstContact = $row['_scm_time_to_first_contact_hours'] ?? null;
      if (is_numeric($firstContact)) {
        $firstContactSamples[] = (float) $firstContact;
      }

      $close = $row['_scm_time_to_close_hours'] ?? null;
      if (is_numeric($close)) {
        $closeSamples[] = (float) $close;
      }

      $updatedTs = $this->parser->parse($row['fecha_actualizacion'] ?? null);
      $createdTs = (int) ($row['_scm_created_ts'] ?? 0);
      $baseTs = $updatedTs > 0 ? $updatedTs : $createdTs;
      if ($baseTs > 0) {
        $staleSamples[] = max(0.0, (time() - $baseTs) / 3600);
      }
    }

    arsort($segPorAsesor);
    arsort($abiertosPorAsesor);
    arsort($actualizadosPorAsesor);

    $stats['promedio_primera_gestion_horas'] = $this->average($firstContactSamples);
    $stats['promedio_cierre_horas'] = $this->average($closeSamples);
    $stats['promedio_desactualizacion_horas'] = $this->average($staleSamples);
    $stats['avg_first_h'] = $stats['promedio_primera_gestion_horas'];
    $stats['avg_close_h'] = $stats['promedio_cierre_horas'];
    $stats['avg_stale_h'] = $stats['promedio_desactualizacion_horas'];
    $stats['seg_por_funcionario'] = $segPorAsesor;
    $stats['abiertos_por_funcionario'] = $abiertosPorAsesor;
    $stats['actualizados_por_funcionario'] = $actualizadosPorAsesor;

    return $stats;
  }

  /** @param array<string,mixed> $row */
  private function resolveFirstContactTimestamp(array $row): int
  {
    $candidates = [
      $this->parser->parse($row['_scm_cita_cal_fecha'] ?? null),
      $this->parser->parse($row['_scm_prev_fecha'] ?? null),
      $this->parser->parse($row['_scm_corr_fecha'] ?? null),
      $this->parser->parse($row['fecha_seguimiento'] ?? null),
      $this->parser->parse($row['fecha_actualizacion'] ?? null),
    ];

    $positive = array_filter($candidates, static fn(int $value): bool => $value > 0);
    if (empty($positive)) {
      return 0;
    }

    return (int) min($positive);
  }

  /** @param array<string,mixed> $row */
  private function resolveCloseTimestamp(array $row, int $createdTs, int $nowTs): int
  {
    $candidates = [
      $this->parser->parse($row['_scm_acta_fecha'] ?? null),
      $this->parser->parse($row['_scm_cot_final_trabajo'] ?? null),
      $this->parser->parse($row['final_trabajo'] ?? null),
      $this->parser->parse($row['fecha_actualizacion'] ?? null),
    ];

    $positive = array_filter($candidates, static fn(int $value): bool => $value > 0);
    if (!empty($positive)) {
      $closeTs = (int) max($positive);
      if ($createdTs > 0 && $closeTs < $createdTs) {
        return $nowTs;
      }
      return $closeTs;
    }

    return 0;
  }

  private function isClosed(string $estado): bool
  {
    $value = strtolower(trim($estado));
    return in_array($value, ['cerrado', 'resuelto', 'finalizado'], true);
  }

  /** @param array<int,float> $values */
  private function average(array $values): ?float
  {
    if (empty($values)) {
      return null;
    }

    return array_sum($values) / count($values);
  }

  /** @param array<string,mixed> $row */
  private function countMonthlySeguimientos(array $row, int $monthStart, int $monthEnd): int
  {
    $count = 0;
    $seguimientos = $row['_scm_seguimientos_ticket'] ?? [];
    if (is_array($seguimientos)) {
      foreach ($seguimientos as $item) {
        if (!is_array($item)) {
          continue;
        }
        $ts = $this->parser->parse($item['fecha'] ?? $item['cct_created'] ?? $item['cct_modified'] ?? null);
        if ($ts > 0 && $ts >= $monthStart && $ts <= $monthEnd) {
          $count++;
        }
      }
    }

    return $count;
  }

  /** @param array<string,mixed> $row */
  private function evaluateKpiRule(string $kpiKey, array $row, callable $fallback): bool
  {
    $def = $this->kpiRules[$kpiKey] ?? null;
    if (!is_array($def) || empty($def['conditions']) || !is_array($def['conditions'])) {
      return (bool) $fallback($row);
    }

    $mode = (($def['mode'] ?? 'all') === 'any') ? 'any' : 'all';
    $checks = [];
    foreach ($def['conditions'] as $cond) {
      if (!is_array($cond)) {
        continue;
      }
      $field = trim((string) ($cond['field'] ?? ''));
      $op = trim((string) ($cond['op'] ?? 'equals'));
      if ($field === '') {
        continue;
      }
      $checks[] = $this->matchCondition((string) ($row[$field] ?? ''), $op, $cond['value'] ?? '');
    }
    if (empty($checks)) {
      return (bool) $fallback($row);
    }
    return $mode === 'any'
      ? in_array(true, $checks, true)
      : !in_array(false, $checks, true);
  }

  /** @param mixed $expected */
  private function matchCondition(string $actualRaw, string $op, $expected): bool
  {
    $actual = trim($actualRaw);
    $actualLow = strtolower($actual);
    switch ($op) {
      case 'empty':
        return $actual === '';
      case 'not_empty':
        return $actual !== '';
      case 'equals':
        return $actualLow === strtolower(trim((string) $expected));
      case 'not_equals':
        return $actualLow !== strtolower(trim((string) $expected));
      case 'contains':
        return strpos($actualLow, strtolower(trim((string) $expected))) !== false;
      case 'in':
      case 'not_in':
        $values = is_array($expected) ? $expected : explode(',', (string) $expected);
        $set = array_map(static fn($v): string => strtolower(trim((string) $v)), $values);
        $ok = in_array($actualLow, $set, true);
        return $op === 'in' ? $ok : !$ok;
      case 'gt':
      case 'gte':
      case 'lt':
      case 'lte':
        $a = (float) preg_replace('/[^0-9\\.-]/', '', $actual);
        $b = (float) preg_replace('/[^0-9\\.-]/', '', (string) $expected);
        if ($op === 'gt') return $a > $b;
        if ($op === 'gte') return $a >= $b;
        if ($op === 'lt') return $a < $b;
        return $a <= $b;
      default:
        return $actualLow === strtolower(trim((string) $expected));
    }
  }
}
