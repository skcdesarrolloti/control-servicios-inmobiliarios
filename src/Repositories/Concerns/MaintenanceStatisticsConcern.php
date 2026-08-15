<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait MaintenanceStatisticsConcern
{
  private function buildMaintenanceWhereParts(array $filters): array
  {
    $table = $this->ticketsTable();
    $where = [];
    $args = [];

    $topicPlaceholders = implode(',', array_fill(0, count(self::MAINTENANCE_TOPICS), '%s'));
    $where[] = '('
      . "LOWER(TRIM(COALESCE(t.departamento, ''))) = %s"
      . " OR LOWER(TRIM(COALESCE(t.tema_ayuda, ''))) IN ({$topicPlaceholders})"
      . " OR LOWER(COALESCE(t.tema_ayuda, '')) LIKE %s"
      . " OR LOWER(COALESCE(t.tema_ayuda, '')) LIKE %s"
      . ')';
    $args[] = 'mantenimiento';
    foreach (self::MAINTENANCE_TOPICS as $topic) {
      $args[] = $topic;
    }
    $args[] = '%reparacion%';
    $args[] = '%mantenimiento%';

    $tema = (string) ($filters['fTema'] ?? '');
    if ($tema !== '') {
      $where[] = "LOWER(TRIM(COALESCE(t.tema_ayuda, ''))) = %s";
      $args[] = strtolower(trim($tema));
    }

    $statusBucket = strtolower(trim((string) ($filters['_scmStatusBucket'] ?? 'active')));
    if (!in_array($statusBucket, ['active', 'postergados', 'cerrados'], true)) {
      $statusBucket = 'active';
    }
    if ($statusBucket === 'postergados') {
      if ($this->schema->columnExists($table, 'estado_administrativo')) {
        $where[] = "LOWER(TRIM(COALESCE(t.estado_administrativo, ''))) = %s";
        $args[] = 'postergado';
        $where[] = "LOWER(TRIM(COALESCE(t.estado, ''))) NOT IN (%s, %s, %s)";
        $args[] = 'cerrado';
        $args[] = 'resuelto';
        $args[] = 'finalizado';
      } else {
        $where[] = '1 = 0';
      }
    } elseif ($statusBucket === 'cerrados') {
      $closedValues = ['cerrado', 'resuelto', 'finalizado'];
      $closedWhere = ["LOWER(TRIM(COALESCE(t.estado, ''))) IN (%s, %s, %s)"];
      foreach ($closedValues as $value) {
        $args[] = $value;
      }
      if ($this->schema->columnExists($table, 'estado_administrativo')) {
        $closedWhere[] = "LOWER(TRIM(COALESCE(t.estado_administrativo, ''))) IN (%s, %s, %s)";
        foreach ($closedValues as $value) {
          $args[] = $value;
        }
      }
      $where[] = '(' . implode(' OR ', $closedWhere) . ')';
    } else {
      $where[] = "LOWER(TRIM(COALESCE(t.estado, ''))) IN (%s, %s)";
      $args[] = 'nuevo';
      $args[] = 'en proceso';

      $estado = (string) ($filters['fEstado'] ?? '');
      if ($estado !== '') {
        $estadoFilter = strtolower(trim($estado));
        if (in_array($estadoFilter, ['nuevo', 'en proceso'], true)) {
          $where[] = "LOWER(TRIM(COALESCE(t.estado, ''))) = %s";
          $args[] = $estadoFilter;
        }
      }
    }

    $estadoAdmin = (string) ($filters['fEstadoAdmin'] ?? '');
    if ($statusBucket === 'active' && $estadoAdmin !== '') {
      $where[] = "LOWER(TRIM(COALESCE(t.estado_administrativo, ''))) = %s";
      $args[] = strtolower(trim($estadoAdmin));
    }

    $origenFilter = strtolower(trim((string) ($filters['fOrigen'] ?? '')));
    if (in_array($origenFilter, ['web', 'interno'], true)) {
      $webExpr = $this->maintenanceWebOriginExpression('t');
      $where[] = $origenFilter === 'web' ? $webExpr : "NOT ({$webExpr})";
    }

    $prioridad = (string) ($filters['fPrioridad'] ?? '');
    if ($prioridad !== '') {
      $where[] = "LOWER(TRIM(COALESCE(t.prioridad, ''))) = %s";
      $args[] = strtolower(trim($prioridad));
    }

    $magnitudCasoFilter = (string) (($filters['fMagnitudCaso'] ?? '') !== '' ? $filters['fMagnitudCaso'] : ($filters['fMagnitud'] ?? ''));
    if ($magnitudCasoFilter !== '' && $this->schema->columnExists($table, 'magnitud_caso')) {
      $where[] = "LOWER(TRIM(COALESCE(t.magnitud_caso, ''))) = %s";
      $args[] = strtolower(trim($magnitudCasoFilter));
    }

    $cotizacion = (string) ($filters['fCotizacion'] ?? '');
    if ($cotizacion !== '') {
      $cotFilter = trim($cotizacion);
      $cotExistsExpr = $this->maintenanceCotizacionExistsExpression('t');
      if ($cotFilter === 'has') {
        $where[] = $cotExistsExpr;
      } elseif ($cotFilter === 'none') {
        $where[] = "NOT ({$cotExistsExpr})";
      } elseif (strpos($cotFilter, 'state:') === 0) {
        $state = strtolower(trim(substr($cotFilter, 6)));
        if ($state !== '') {
          $where[] = "(LOWER(TRIM(COALESCE(t.estado_cotizacion_mantenimiento, ''))) = %s OR LOWER(TRIM(COALESCE(t.estado_respuesta_cotizacion_mantenimiento, ''))) = %s)";
          $args[] = $state;
          $args[] = $state;
        }
      }
    }

    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (
      (($filters['fCotizacionEstado'] ?? '') !== '' || ($filters['fCotizacionEnviada'] ?? '') !== '')
      && $this->schema->tableExists($cotTable)
      && $this->schema->columnExists($cotTable, '_ID')
    ) {
      $cotExtra = [];
      if (($filters['fCotizacionEstado'] ?? '') !== '') {
        $stateParts = [];
        foreach (['estado', 'estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta'] as $cotStateCol) {
          if ($this->schema->columnExists($cotTable, $cotStateCol)) {
            $stateParts[] = "LOWER(TRIM(COALESCE(cot_dyn.`{$cotStateCol}`, ''))) = %s";
            $args[] = strtolower(trim((string) $filters['fCotizacionEstado']));
          }
        }
        if (!empty($stateParts)) {
          $cotExtra[] = '(' . implode(' OR ', $stateParts) . ')';
        }
      }
      if (($filters['fCotizacionEnviada'] ?? '') !== '') {
        $sent = strtolower(trim((string) $filters['fCotizacionEnviada']));
        $sentColumns = [];
        foreach (['se_envio', 'fue_enviada_cotizacion_mantenimiento', 'fue_enviada'] as $sentCol) {
          if ($this->schema->columnExists($cotTable, $sentCol)) {
            $sentColumns[] = $sentCol;
          }
        }
        if (in_array($sent, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true)) {
          $sentParts = [];
          foreach ($sentColumns as $sentCol) {
            $sentParts[] = "LOWER(TRIM(COALESCE(cot_dyn.`{$sentCol}`, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado')";
          }
          if (!empty($sentParts)) {
            $cotExtra[] = '(' . implode(' OR ', $sentParts) . ')';
          }
        } elseif (in_array($sent, ['no', '0', 'false', 'none', 'sin'], true)) {
          $sentParts = [];
          foreach ($sentColumns as $sentCol) {
            $sentParts[] = "(TRIM(COALESCE(cot_dyn.`{$sentCol}`, '')) = '' OR LOWER(TRIM(COALESCE(cot_dyn.`{$sentCol}`, ''))) IN ('no', '0', 'false'))";
          }
          if (!empty($sentParts)) {
            $cotExtra[] = '(' . implode(' AND ', $sentParts) . ')';
          }
        }
      }
      if (!empty($cotExtra)) {
        $joinParts = [];
        if ($this->schema->columnExists($table, 'id_cotizacion_mantenimiento')) {
          $joinParts[] = "FIND_IN_SET(CAST(cot_dyn._ID AS CHAR), REPLACE(COALESCE(t.id_cotizacion_mantenimiento, ''), ' ', '')) > 0";
        }
        if ($this->schema->columnExists($cotTable, 'id_ticket')) {
          $joinParts[] = "(TRIM(COALESCE(cot_dyn.id_ticket, '')) <> '' AND TRIM(COALESCE(cot_dyn.id_ticket, '')) = CAST(t._ID AS CHAR))";
          if ($this->schema->columnExists($table, 'id_ticket')) {
            $joinParts[] = "(TRIM(COALESCE(t.id_ticket, '')) <> '' AND TRIM(COALESCE(cot_dyn.id_ticket, '')) <> '' AND TRIM(COALESCE(cot_dyn.id_ticket, '')) = TRIM(COALESCE(t.id_ticket, '')))";
          }
        }
        if (!empty($joinParts)) {
          $where[] = "EXISTS (SELECT 1 FROM `{$cotTable}` cot_dyn WHERE (" . implode(' OR ', $joinParts) . ') AND ' . implode(' AND ', $cotExtra) . ')';
        }
      }
    }

    if (($filters['fPerturbacion'] ?? '') !== '') {
      $perturbacionFilter = strtolower(trim((string) $filters['fPerturbacion']));
      $isConFilter = in_array($perturbacionFilter, ['has', 'si', 'sí', 'con'], true);
      $isSinFilter = in_array($perturbacionFilter, ['none', 'no', 'sin'], true);
      if ($this->schema->columnExists($table, 'perturbacion')) {
        $pertExpr = "LOWER(TRIM(COALESCE(t.perturbacion, '')))";
        if ($isConFilter) {
          $where[] = "(TRIM(COALESCE(t.perturbacion, '')) <> '' AND {$pertExpr} NOT IN ('0', 'no', 'false', 'null'))";
        } elseif ($isSinFilter) {
          $where[] = "(TRIM(COALESCE(t.perturbacion, '')) = '' OR {$pertExpr} IN ('0', 'no', 'false', 'null'))";
        } else {
          $where[] = "(TRIM(COALESCE(t.perturbacion, '')) <> '')";
        }
      } else {
        $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
        if (
          $this->schema->tableExists($cotTable)
          && $this->schema->columnExists($cotTable, '_ID')
          && $this->schema->columnExists($cotTable, 'perturbacion')
          && $this->schema->columnExists($table, 'id_cotizacion_mantenimiento')
        ) {
          $pertExpr = "LOWER(TRIM(COALESCE(cot_filter.perturbacion, '')))";
          if ($isConFilter) {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) <> '' AND {$pertExpr} NOT IN ('0', 'no', 'false', 'null'))";
          } elseif ($isSinFilter) {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) = '' OR {$pertExpr} IN ('0', 'no', 'false', 'null'))";
          } else {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) <> '')";
          }
          $where[] =
            "EXISTS (SELECT 1 FROM `{$cotTable}` cot_filter"
            . " WHERE FIND_IN_SET(CAST(cot_filter._ID AS CHAR), REPLACE(COALESCE(t.id_cotizacion_mantenimiento, ''), ' ', '')) > 0"
            . " AND {$condExpr})";
        }
      }
    }

    $revision = (string) ($filters['fRevision'] ?? '');
    if ($revision !== '') {
      $revFilter = trim($revision);
      if ($revFilter === 'has') {
        $where[] = "(TRIM(COALESCE(t.id_revision_preventiva, '')) <> '' OR TRIM(COALESCE(t.id_revision_correctiva, '')) <> '')";
      } elseif ($revFilter === 'none') {
        $where[] = "(TRIM(COALESCE(t.id_revision_preventiva, '')) = '' AND TRIM(COALESCE(t.id_revision_correctiva, '')) = '')";
      } elseif ($revFilter === 'prev') {
        $where[] = "TRIM(COALESCE(t.id_revision_preventiva, '')) <> ''";
      } elseif ($revFilter === 'corr') {
        $where[] = "TRIM(COALESCE(t.id_revision_correctiva, '')) <> ''";
      } elseif (strpos($revFilter, 'state:') === 0) {
        $state = strtolower(trim(substr($revFilter, 6)));
        if ($state !== '') {
          $where[] = "(LOWER(TRIM(COALESCE(t.estado_rev_preventiva, ''))) = %s OR LOWER(TRIM(COALESCE(t.estado_rev_correctiva, ''))) = %s)";
          $args[] = $state;
          $args[] = $state;
        }
      }
    }

    if (($filters['fInmueble'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike(trim((string) $filters['fInmueble'])) . '%';
      $where[] = "(COALESCE(t.id_inmueble, '') LIKE %s OR COALESCE(t.inmueble, '') LIKE %s)";
      $args[] = $term;
      $args[] = $term;
    }
    $this->buildLikeCondition($where, $args, "COALESCE(t.contrato, '')", (string) ($filters['fContrato'] ?? ''));
    if (($filters['fEmpleado'] ?? '') !== '') {
      if (!empty($filters['_scmEmpleadoExact'])) {
        $where[] = "TRIM(COALESCE(t.id_empleado, '')) = ?";
        $args[] = trim((string) $filters['fEmpleado']);
      } else {
        $this->buildLikeCondition($where, $args, "COALESCE(t.id_empleado, '')", (string) ($filters['fEmpleado'] ?? ''));
      }
    }
    $this->buildLikeCondition($where, $args, "COALESCE(t.arrendatario, '')", (string) ($filters['fArrendatario'] ?? ''));
    $this->buildLikeCondition($where, $args, "COALESCE(t.propietario, '')", (string) ($filters['fPropietario'] ?? ''));
    $this->buildLikeCondition($where, $args, "COALESCE(t.asunto, '')", (string) ($filters['fAsunto'] ?? ''));
    $this->buildLikeCondition($where, $args, "COALESCE(t.barrio, '')", (string) ($filters['fBarrio'] ?? ''));

    if (($filters['fAseguradora'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $filters['fAseguradora']) . '%';
      $where[] = "(COALESCE(t.id_estudio_aseguradora,'') LIKE %s OR COALESCE(t.numero_solicitud,'') LIKE %s)";
      $args[] = $term;
      $args[] = $term;
    }

    if (($filters['fBusqueda'] ?? '') !== '') {
      $term = '%' . $this->db->escapeLike((string) $filters['fBusqueda']) . '%';
      $where[] = "(COALESCE(t.asunto,'') LIKE %s OR COALESCE(t.direccion,'') LIKE %s OR COALESCE(t.arrendatario,'') LIKE %s OR COALESCE(t.propietario,'') LIKE %s)";
      $args[] = $term;
      $args[] = $term;
      $args[] = $term;
      $args[] = $term;
    }

    if (($filters['fFecha'] ?? '') !== '') {
      $tsStart = strtotime((string) $filters['fFecha'] . ' 00:00:00');
      $tsEnd = strtotime((string) $filters['fFecha'] . ' 23:59:59');
      if ($tsStart !== false && $tsEnd !== false) {
        $where[] = 'COALESCE(t.fecha, 0) BETWEEN %d AND %d';
        $args[] = (int) $tsStart;
        $args[] = (int) $tsEnd;
      }
    } else {
      if (($filters['fFechaDesde'] ?? '') !== '') {
        $ts = strtotime((string) $filters['fFechaDesde'] . ' 00:00:00');
        if ($ts !== false) {
          $where[] = 'COALESCE(t.fecha, 0) >= %d';
          $args[] = (int) $ts;
        }
      }

      if (($filters['fFechaHasta'] ?? '') !== '') {
        $ts = strtotime((string) $filters['fFechaHasta'] . ' 23:59:59');
        if ($ts !== false) {
          $where[] = 'COALESCE(t.fecha, 0) <= %d';
          $args[] = (int) $ts;
        }
      }
    }

    if (($filters['fAtraso'] ?? '') !== '') {
      $days = (int) $filters['fAtraso'];
      if ($days > 0) {
        $where[] = 'COALESCE(t.fecha, 0) <= %d';
        $args[] = time() - ($days * 86400);
      }
    }

    if (($filters['fSinActualizar'] ?? '') !== '') {
      $days = (int) $filters['fSinActualizar'];
      if ($days > 0) {
        $where[] = 'COALESCE(NULLIF(t.fecha_actualizacion, 0), t.fecha, 0) <= %d';
        $args[] = time() - ($days * 86400);
      }
    }

    if (($filters['fTuvoSeguimiento'] ?? '') !== '') {
      $seg = strtolower(trim((string) $filters['fTuvoSeguimiento']));
      if (in_array($seg, ['si', 'no'], true)) {
        $where[] = "LOWER(TRIM(COALESCE(t.tuvo_seguimiento, ''))) = %s";
        $args[] = $seg;
      }
    }

    if (empty($where)) {
      $where[] = '1=1';
    }

    return [$where, $args];
  }

  /** @return array<string,mixed> */
  private function emptyMaintenanceStats(): array
  {
    return [
      'total' => 0,
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
      'seg_por_funcionario' => [],
      'abiertos_por_funcionario' => [],
      'actualizados_por_funcionario' => [],
      'magnitud_critico' => 0,
      'magnitud_alto' => 0,
      'magnitud_medio' => 0,
      'magnitud_bajo' => 0,
    ];
  }

  private function maintenanceClosedExpr(string $alias = 't'): string
  {
    $table = $this->ticketsTable();
    $parts = [];
    if ($this->schema->columnExists($table, 'estado')) {
      $parts[] = "LOWER(TRIM(COALESCE({$alias}.`estado`, ''))) IN ('cerrado', 'resuelto', 'finalizado')";
    }
    if ($this->schema->columnExists($table, 'estado_administrativo')) {
      $parts[] = "LOWER(TRIM(COALESCE({$alias}.`estado_administrativo`, ''))) IN ('cerrado', 'resuelto', 'finalizado')";
    }
    return empty($parts) ? '0' : '(' . implode(' OR ', $parts) . ')';
  }

  private function maintenanceEmployeeExpr(string $alias = 't'): string
  {
    $table = $this->ticketsTable();
    $parts = [];
    foreach (['nombre_empleado', 'empleado', 'id_empleado'] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $parts[] = "NULLIF(TRIM(COALESCE({$alias}.`{$column}`, '')), '')";
      }
    }
    $parts[] = "'Sin asignar'";
    return 'COALESCE(' . implode(', ', $parts) . ')';
  }

  /**
   * @param array<int,string> $where
   * @param array<int,mixed> $args
   * @return array<string,int>
   */
  private function aggregateTicketsByEmployee(array $where, array $args, string $mode, int $monthStart = 0, int $monthEnd = 0): array
  {
    $table = $this->ticketsTable();
    $whereParts = $where;
    $queryArgs = $args;
    $closedExpr = $this->maintenanceClosedExpr('t');
    $updatedExpr = $this->schema->columnExists($table, 'fecha_actualizacion')
      ? "COALESCE(NULLIF(t.`fecha_actualizacion`, 0), NULLIF(t.`fecha`, 0), 0)"
      : "COALESCE(NULLIF(t.`fecha`, 0), 0)";

    if ($mode === 'abiertos') {
      $whereParts[] = "NOT ({$closedExpr})";
    } elseif ($mode === 'actualizados_mes') {
      $whereParts[] = "{$updatedExpr} BETWEEN ? AND ?";
      $queryArgs[] = $monthStart;
      $queryArgs[] = $monthEnd;
    }

    $labelExpr = $this->maintenanceEmployeeExpr('t');
    $sql = "SELECT {$labelExpr} AS label, COUNT(1) AS total
      FROM `{$table}` t
      WHERE " . implode(' AND ', $whereParts) . "
      GROUP BY label
      ORDER BY total DESC
      LIMIT 30";

    return $this->rowsToCountMap($this->db->getResults($this->convertSql($sql), $queryArgs));
  }

  /**
   * @param array<int,string> $where
   * @param array<int,mixed> $args
   * @return array<string,int>
   */
  private function aggregateMonthlySeguimientosByEmployee(array $where, array $args, int $monthStart, int $monthEnd): array
  {
    $table = $this->ticketsTable();
    $segTable = $this->db->table('jet_cct_seguimiento_ticket');
    if (!$this->schema->tableExists($segTable) || !$this->schema->columnExists($segTable, 'id_ticket')) {
      return [];
    }

    $joinParts = ["TRIM(COALESCE(s.`id_ticket`, '')) = CAST(t.`_ID` AS CHAR)"];
    if ($this->schema->columnExists($table, 'id_ticket')) {
      $joinParts[] = "TRIM(COALESCE(s.`id_ticket`, '')) = TRIM(COALESCE(t.`id_ticket`, ''))";
    }

    $segTsParts = [];
    if ($this->schema->columnExists($segTable, 'fecha')) {
      $segTsParts[] = "NULLIF(s.`fecha`, 0)";
    }
    if ($this->schema->columnExists($segTable, 'cct_created')) {
      $segTsParts[] = "UNIX_TIMESTAMP(NULLIF(s.`cct_created`, ''))";
    }
    if ($this->schema->columnExists($segTable, 'cct_modified')) {
      $segTsParts[] = "UNIX_TIMESTAMP(NULLIF(s.`cct_modified`, ''))";
    }
    if (empty($segTsParts)) {
      return [];
    }
    $segTsExpr = 'COALESCE(' . implode(', ', $segTsParts) . ', 0)';
    $segNameExpr = $this->schema->columnExists($segTable, 'nombre')
      ? "COALESCE(NULLIF(TRIM(COALESCE(s.`nombre`, '')), ''), {$this->maintenanceEmployeeExpr('t')})"
      : $this->maintenanceEmployeeExpr('t');

    $sql = "SELECT {$segNameExpr} AS label, COUNT(1) AS total
      FROM `{$table}` t
      INNER JOIN `{$segTable}` s ON (" . implode(' OR ', $joinParts) . ")
      WHERE " . implode(' AND ', $where) . "
        AND {$segTsExpr} BETWEEN ? AND ?
      GROUP BY label
      ORDER BY total DESC
      LIMIT 30";
    $queryArgs = array_merge($args, [$monthStart, $monthEnd]);

    return $this->rowsToCountMap($this->db->getResults($this->convertSql($sql), $queryArgs));
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<string,int>
   */
  private function rowsToCountMap(array $rows): array
  {
    $out = [];
    foreach ($rows as $row) {
      $label = trim((string) ($row['label'] ?? ''));
      if ($label === '') {
        $label = 'Sin asignar';
      }
      $out[$label] = (int) ($row['total'] ?? 0);
    }
    return $out;
  }

  private function buildLikeCondition(array &$where, array &$args, string $expression, string $value): void
  {
    $term = trim($value);
    if ($term === '') {
      return;
    }

    $where[] = "{$expression} LIKE ?";
    $args[] = '%' . $this->db->escapeLike($term) . '%';
  }

  /** Convert %s/%d/%f WordPress-style placeholders to PDO ? */
  private function convertSql(string $sql): string
  {
    return (string) preg_replace('/%[sdf]/', '?', $sql);
  }

  /**
   * @param array<int,string> $ids
   * @param array<int,string> $idCandidates
   * @param array<int,string> $wantedColumns
   * @return array{id_col:string,rows:array<string,array<string,mixed>>}
   */
}
