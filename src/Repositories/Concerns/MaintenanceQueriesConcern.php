<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait MaintenanceQueriesConcern
{
  public function ticketsTable(): string
  {
    return $this->db->table('jet_cct_tickets');
  }

  public function hasTicketsTable(): bool
  {
    return $this->schema->tableExists($this->ticketsTable());
  }

  /** @return array<string,int> */
  public function getLastMaintenancePagination(): array
  {
    return [
      'total' => $this->lastMaintenanceTotal,
      'page' => $this->lastMaintenancePage,
      'per_page' => $this->lastMaintenancePerPage,
      'total_pages' => $this->lastMaintenanceTotalPages,
    ];
  }

  /** @return array<string,mixed> */
  public function getMaintenanceFilterOptions(): array
  {
    $table = $this->ticketsTable();

    return [
      'tema' => $this->formatTopicOptions(self::MAINTENANCE_TOPICS),
      'estado' => ['Nuevo', 'En proceso'],
      'estado_admin' => $this->getDistinctValuesFromCandidates($table, ['estado_administrativo']),
      'prioridad' => $this->getDistinctValuesFromCandidates($table, ['prioridad']),
      'cotizacion_estado' => $this->getMaintenanceCotizacionEstadoOptions($table),
      'revision_estado' => $this->getDistinctValuesFromCandidates($table, ['estado_rev_preventiva', 'estado_rev_correctiva']),
      'funcionarios' => $this->getFuncionariosFilterOptions(),
      'barrios' => $this->getBarriosFilterOptions(),
    ];
  }

  /**
   * @param array<string,string> $filters
   * @return array<int,array<string,mixed>>
   */
  public function queryMaintenance(array $filters): array
  {
    $table = $this->ticketsTable();
    if (!$this->schema->tableExists($table)) {
      $this->lastMaintenanceTotal = 0;
      $this->lastMaintenancePage = 1;
      $this->lastMaintenancePerPage = 20;
      $this->lastMaintenanceTotalPages = 1;
      return [];
    }

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

    if ($filters['fTema'] !== '') {
      $where[] = "LOWER(TRIM(COALESCE(t.tema_ayuda, ''))) = %s";
      $args[] = strtolower(trim((string) $filters['fTema']));
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

      if (($filters['fEstado'] ?? '') !== '') {
        $estadoFilter = strtolower(trim((string) $filters['fEstado']));
        if (in_array($estadoFilter, ['nuevo', 'en proceso'], true)) {
          $where[] = "LOWER(TRIM(COALESCE(t.estado, ''))) = %s";
          $args[] = $estadoFilter;
        }
      }
    }

    if ($statusBucket === 'active' && ($filters['fEstadoAdmin'] ?? '') !== '') {
      $where[] = "LOWER(TRIM(COALESCE(t.estado_administrativo, ''))) = %s";
      $args[] = strtolower(trim((string) $filters['fEstadoAdmin']));
    }

    $origenFilter = strtolower(trim((string) ($filters['fOrigen'] ?? '')));
    if (in_array($origenFilter, ['web', 'interno'], true)) {
      $webExpr = $this->maintenanceWebOriginExpression('t');
      $where[] = $origenFilter === 'web' ? $webExpr : "NOT ({$webExpr})";
    }

    if ($filters['fPrioridad'] !== '') {
      $where[] = "LOWER(TRIM(COALESCE(t.prioridad, ''))) = %s";
      $args[] = strtolower(trim((string) $filters['fPrioridad']));
    }

    $magnitudCasoFilter = (string) (($filters['fMagnitudCaso'] ?? '') !== '' ? $filters['fMagnitudCaso'] : ($filters['fMagnitud'] ?? ''));

    if ($magnitudCasoFilter !== '' && $this->schema->columnExists($table, 'magnitud_caso')) {
      $where[] = "LOWER(TRIM(COALESCE(t.magnitud_caso, ''))) = %s";
      $args[] = strtolower(trim($magnitudCasoFilter));
    }

    if ($filters['fCotizacion'] !== '') {
      $cotFilter = trim((string) $filters['fCotizacion']);
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
      if (($filters['fCotizacionEstado'] ?? '') !== '' && $this->schema->columnExists($cotTable, 'estado')) {
        $cotExtra[] = "LOWER(TRIM(COALESCE(cot_dyn.estado, ''))) = %s";
        $args[] = strtolower(trim((string) $filters['fCotizacionEstado']));
      }
      if (($filters['fCotizacionEnviada'] ?? '') !== '' && $this->schema->columnExists($cotTable, 'se_envio')) {
        $sent = strtolower(trim((string) $filters['fCotizacionEnviada']));
        if (in_array($sent, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true)) {
          $cotExtra[] = "LOWER(TRIM(COALESCE(cot_dyn.se_envio, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado')";
        } elseif (in_array($sent, ['no', '0', 'false', 'none', 'sin'], true)) {
          $cotExtra[] = "(TRIM(COALESCE(cot_dyn.se_envio, '')) = '' OR LOWER(TRIM(COALESCE(cot_dyn.se_envio, ''))) IN ('no', '0', 'false'))";
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

    if ($filters['fRevision'] !== '') {
      $revFilter = trim((string) $filters['fRevision']);
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
    $this->buildLikeCondition($where, $args, "COALESCE(t.contrato, '')", $filters['fContrato']);
    if (($filters['fEmpleado'] ?? '') !== '') {
      if (!empty($filters['_scmEmpleadoExact'])) {
        $where[] = "TRIM(COALESCE(t.id_empleado, '')) = ?";
        $args[] = trim((string) $filters['fEmpleado']);
      } else {
        $this->buildLikeCondition($where, $args, "COALESCE(t.id_empleado, '')", $filters['fEmpleado']);
      }
    }
    $this->buildLikeCondition($where, $args, "COALESCE(t.arrendatario, '')", $filters['fArrendatario']);
    $this->buildLikeCondition($where, $args, "COALESCE(t.propietario, '')", $filters['fPropietario']);
    $this->buildLikeCondition($where, $args, "COALESCE(t.asunto, '')", $filters['fAsunto']);
    $this->buildLikeCondition($where, $args, "COALESCE(t.barrio, '')", $filters['fBarrio']);

    if ($filters['fAseguradora'] !== '') {
      $term = '%' . $this->db->escapeLike($filters['fAseguradora']) . '%';
      $where[] = "(COALESCE(t.id_estudio_aseguradora,'') LIKE %s OR COALESCE(t.numero_solicitud,'') LIKE %s)";
      $args[] = $term;
      $args[] = $term;
    }

    if ($filters['fBusqueda'] !== '') {
      $term = '%' . $this->db->escapeLike($filters['fBusqueda']) . '%';
      $where[] = "(COALESCE(t.asunto,'') LIKE %s OR COALESCE(t.direccion,'') LIKE %s OR COALESCE(t.arrendatario,'') LIKE %s OR COALESCE(t.propietario,'') LIKE %s)";
      $args[] = $term;
      $args[] = $term;
      $args[] = $term;
      $args[] = $term;
    }

    if ($filters['fFechaDesde'] !== '') {
      $ts = strtotime($filters['fFechaDesde'] . ' 00:00:00');
      if ($ts !== false) {
        $where[] = 'COALESCE(t.fecha, 0) >= %d';
        $args[] = (int) $ts;
      }
    }

    if ($filters['fFechaHasta'] !== '') {
      $ts = strtotime($filters['fFechaHasta'] . ' 23:59:59');
      if ($ts !== false) {
        $where[] = 'COALESCE(t.fecha, 0) <= %d';
        $args[] = (int) $ts;
      }
    }

    if ($filters['fAtraso'] !== '') {
      $days = (int) $filters['fAtraso'];
      if ($days > 0) {
        $where[] = 'COALESCE(t.fecha, 0) <= %d';
        $args[] = time() - ($days * 86400);
      }
    }

    if ($filters['fSinActualizar'] !== '') {
      $days = (int) $filters['fSinActualizar'];
      if ($days > 0) {
        $where[] = 'COALESCE(NULLIF(t.fecha_actualizacion, 0), t.fecha, 0) <= %d';
        $args[] = time() - ($days * 86400);
      }
    }

    if ($filters['fTuvoSeguimiento'] !== '') {
      $seg = strtolower(trim((string) $filters['fTuvoSeguimiento']));
      if (in_array($seg, ['si', 'no'], true)) {
        $where[] = "LOWER(TRIM(COALESCE(t.tuvo_seguimiento, ''))) = %s";
        $args[] = $seg;
      }
    }

    if (empty($where)) {
      $where[] = '1=1';
    }

    $columns = [
      '_ID',
      'id_ticket',
      'id_contrato',
      'id_inmueble',
      'id_inmueble_data',
      'id_propietario',
      'id_arrendatario',
      'asunto',
      'descripcion',
      'respuesta',
      'observacion',
      'estado',
      'creador_por',
      'tema_ayuda',
      'prioridad',
      'fecha',
      'fecha_actualizacion',
      'inmueble',
      'contrato',
      'direccion',
      'barrio',
      'arrendatario',
      'correo_arrendatario',
      'celular_arrendatario',
      'propietario',
      'correo_propietario',
      'celular_propietario',
      'indicativo',
      'indicativo_propietario',
      'indicativo_arrendatario',
      'empleado',
      'id_empleado',
      'nombre_empleado',
      'id_revision_correctiva',
      'estado_rev_correctiva',
      'id_revision_preventiva',
      'estado_rev_preventiva',
      'id_cotizacion_mantenimiento',
      'estado_cotizacion_mantenimiento',
      'fue_enviada_cotizacion_mantenimiento',
      'estado_respuesta_cotizacion_mantenimiento',
      'fecha_envio_cotizacion_mantenimiento',
      'fecha_respuesta_cotizacion_mantenimiento',
      'inicio_trabajo',
      'final_trabajo',
      'fecha_cita',
      'fecha_recordatorio',
      'seguimientos',
      'tuvo_seguimiento',
      'fecha_seguimiento',
      'estado_administrativo',
      'magnitud_caso',
      'perturbacion',
      'justificacion_perturbacion',
      'area_afectada',
      'ejecutado',
      'cct_created',
      'cct_modified',
    ];

    $countSql  = "SELECT COUNT(1) FROM `{$table}` t WHERE " . implode(' AND ', $where);
    $totalRows = (int) $this->db->getVar($this->convertSql($countSql), $args);

    [$page, $perPage] = $this->resolveMaintenancePagination($filters);
    $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = max(0, ($page - 1) * $perPage);

    $this->lastMaintenanceTotal = max(0, $totalRows);
    $this->lastMaintenancePage = $page;
    $this->lastMaintenancePerPage = $perPage;
    $this->lastMaintenanceTotalPages = $totalPages;

    $selectableColumns = [];
    foreach ($columns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $selectableColumns[] = $column;
      }
    }
    if (empty($selectableColumns)) {
      $selectableColumns = ['_ID'];
    }

    $select = implode(', ', array_map(static fn(string $col): string => "t.`{$col}`", $selectableColumns));
    $sql  = "SELECT {$select} FROM `{$table}` t WHERE " . implode(' AND ', $where) . ' ORDER BY t.fecha DESC LIMIT ? OFFSET ?';
    $rows = $this->db->getResults($this->convertSql($sql), array_merge($args, [$perPage, $offset]));
    if (empty($rows)) {
      return [];
    }

    /** @var array<int,array<string,mixed>> $rows */
    return $this->enrichRowsWithRelatedTables($rows);
  }

  /** @return array<int,string> */
  private function getMaintenanceCotizacionEstadoOptions(string $ticketTable): array
  {
    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if ($this->schema->tableExists($cotTable) && $this->schema->columnExists($cotTable, 'estado')) {
      $rows = $this->db->getCol(
        "SELECT DISTINCT TRIM(COALESCE(`estado`, '')) AS estado FROM `{$cotTable}` WHERE TRIM(COALESCE(`estado`, '')) <> '' ORDER BY estado ASC LIMIT 100"
      );
      return $this->uniqueNonEmptyValues($rows);
    }

    return $this->getDistinctValuesFromCandidates($ticketTable, ['estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento']);
  }

  /**
   * Devuelve tickets puntuales hidratados con las mismas tablas relacionadas que el listing normal.
   *
   * @param array<int,int|string> $ticketIds
   * @return array<int,array<string,mixed>>
   */
  public function queryMaintenanceByPrimaryKeys(array $ticketIds): array
  {
    $table = $this->ticketsTable();
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $ids = [];
    foreach ($ticketIds as $ticketId) {
      $id = (int) $ticketId;
      if ($id > 0) {
        $ids[$id] = $id;
      }
    }
    $ids = array_values($ids);
    if (empty($ids)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $this->db->getResults("SELECT t.* FROM `{$table}` t WHERE t.`_ID` IN ({$placeholders})", $ids);
    if (empty($rows)) {
      return [];
    }

    $rows = $this->enrichRowsWithRelatedTables($rows);
    $order = array_flip(array_map('strval', $ids));
    usort($rows, static function (array $a, array $b) use ($order): int {
      $aKey = (string) ($a['_ID'] ?? '');
      $bKey = (string) ($b['_ID'] ?? '');
      return ($order[$aKey] ?? PHP_INT_MAX) <=> ($order[$bKey] ?? PHP_INT_MAX);
    });

    return $rows;
  }

  private function maintenanceCotizacionExistsExpression(string $alias = 't'): string
  {
    $ticketTable = $this->ticketsTable();
    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $parts = [];

    if ($this->schema->columnExists($ticketTable, 'id_cotizacion_mantenimiento')) {
      $parts[] = "TRIM(COALESCE({$alias}.`id_cotizacion_mantenimiento`, '')) <> ''";
    }

    if ($this->schema->tableExists($cotTable)) {
      $joinParts = [];
      if (
        $this->schema->columnExists($ticketTable, 'id_cotizacion_mantenimiento')
        && $this->schema->columnExists($cotTable, '_ID')
      ) {
        $joinParts[] = "FIND_IN_SET(CAST(cot_exists.`_ID` AS CHAR), REPLACE(COALESCE({$alias}.`id_cotizacion_mantenimiento`, ''), ' ', '')) > 0";
      }
      if ($this->schema->columnExists($cotTable, 'id_ticket')) {
        $joinParts[] = "(TRIM(COALESCE(cot_exists.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_exists.`id_ticket`, '')) = CAST({$alias}.`_ID` AS CHAR))";
        if ($this->schema->columnExists($ticketTable, 'id_ticket')) {
          $joinParts[] = "(TRIM(COALESCE({$alias}.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_exists.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_exists.`id_ticket`, '')) = TRIM(COALESCE({$alias}.`id_ticket`, '')))";
        }
      }
      if (!empty($joinParts)) {
        $parts[] = "EXISTS (SELECT 1 FROM `{$cotTable}` cot_exists WHERE " . implode(' OR ', $joinParts) . ")";
      }
    }

    if (empty($parts)) {
      return '0';
    }

    return '(' . implode(' OR ', $parts) . ')';
  }

  private function maintenanceWebOriginExpression(string $alias = 't'): string
  {
    $table = $this->ticketsTable();
    if (!$this->schema->columnExists($table, 'creador_por')) {
      return '0 = 1';
    }
    $creadorExpr = "LOWER(TRIM(COALESCE({$alias}.`creador_por`, '')))";
    return "({$creadorExpr} <> '' AND {$creadorExpr} <> 'funcionario')";
  }

  /**
   * Calcula KPIs del universo filtrado sin hidratar cards, historial ni datos relacionados.
   *
   * @param array<string,string> $filters
   * @return array<string,mixed>
   */
  public function aggregateMaintenanceStats(array $filters, int $slaRiskDays = 8, int $slaOverdueDays = 15): array
  {
    $table = $this->ticketsTable();
    if (!$this->schema->tableExists($table)) {
      return $this->emptyMaintenanceStats();
    }

    [$where, $args] = $this->buildMaintenanceWhereParts($filters);
    $whereSql = implode(' AND ', $where);

    $nowTs = time();
    $monthStart = (int) strtotime(date('Y-m-01 00:00:00'));
    $monthEnd = (int) strtotime(date('Y-m-t 23:59:59'));
    $slaRiskDays = max(1, $slaRiskDays);
    $slaOverdueDays = max($slaRiskDays, $slaOverdueDays);

    $fechaExpr = $this->schema->columnExists($table, 'fecha')
      ? 'COALESCE(NULLIF(t.`fecha`, 0), 0)'
      : '0';
    $updatedExpr = $this->schema->columnExists($table, 'fecha_actualizacion')
      ? "COALESCE(NULLIF(t.`fecha_actualizacion`, 0), {$fechaExpr})"
      : $fechaExpr;
    $firstExpr = $this->schema->columnExists($table, 'fecha_seguimiento')
      ? "COALESCE(NULLIF(t.`fecha_seguimiento`, 0), {$updatedExpr}, {$fechaExpr})"
      : "COALESCE({$updatedExpr}, {$fechaExpr})";
    $closedExpr = $this->maintenanceClosedExpr('t');
    $cotExpr = $this->maintenanceCotizacionExistsExpression('t');
    $prevExpr = $this->schema->columnExists($table, 'id_revision_preventiva')
      ? "TRIM(COALESCE(t.`id_revision_preventiva`, '')) <> ''"
      : '0';
    $corrExpr = $this->schema->columnExists($table, 'id_revision_correctiva')
      ? "TRIM(COALESCE(t.`id_revision_correctiva`, '')) <> ''"
      : '0';
    $revisionExpr = "({$prevExpr} OR {$corrExpr})";
    $magnitudExpr = $this->schema->columnExists($table, 'magnitud_caso')
      ? "LOWER(TRIM(COALESCE(t.`magnitud_caso`, '')))"
      : "''";

    $aggSql = "SELECT
        COUNT(1) AS total,
        SUM(CASE WHEN {$cotExpr} THEN 1 ELSE 0 END) AS con_cotizacion,
        SUM(CASE WHEN {$prevExpr} THEN 1 ELSE 0 END) AS con_preventiva,
        SUM(CASE WHEN {$revisionExpr} THEN 1 ELSE 0 END) AS con_revision,
        SUM(CASE WHEN {$closedExpr} THEN 1 ELSE 0 END) AS cerrados,
        SUM(CASE WHEN NOT ({$closedExpr}) THEN 1 ELSE 0 END) AS abiertos,
        SUM(CASE WHEN NOT ({$closedExpr}) AND {$fechaExpr} > 0 AND ({$nowTs} - {$fechaExpr}) >= ? THEN 1 ELSE 0 END) AS sla_vencido,
        SUM(CASE WHEN NOT ({$closedExpr}) AND {$fechaExpr} > 0 AND ({$nowTs} - {$fechaExpr}) >= ? AND ({$nowTs} - {$fechaExpr}) < ? THEN 1 ELSE 0 END) AS sla_riesgo,
        AVG(CASE WHEN {$fechaExpr} > 0 AND {$firstExpr} >= {$fechaExpr} THEN ({$firstExpr} - {$fechaExpr}) / 3600 ELSE NULL END) AS avg_first_h,
        AVG(CASE WHEN {$closedExpr} AND {$fechaExpr} > 0 AND {$updatedExpr} >= {$fechaExpr} THEN ({$updatedExpr} - {$fechaExpr}) / 3600 ELSE NULL END) AS avg_close_h,
        AVG(CASE WHEN {$updatedExpr} > 0 THEN GREATEST(0, ({$nowTs} - {$updatedExpr}) / 3600) ELSE NULL END) AS avg_stale_h,
        SUM(CASE WHEN {$updatedExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) AS mes_actualizados,
        SUM(CASE WHEN {$closedExpr} AND {$updatedExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) AS mes_cerrados,
        SUM(CASE WHEN {$magnitudExpr} IN ('critico', 'crítico') THEN 1 ELSE 0 END) AS magnitud_critico,
        SUM(CASE WHEN {$magnitudExpr} = 'alto' THEN 1 ELSE 0 END) AS magnitud_alto,
        SUM(CASE WHEN {$magnitudExpr} = 'medio' THEN 1 ELSE 0 END) AS magnitud_medio,
        SUM(CASE WHEN {$magnitudExpr} = 'bajo' THEN 1 ELSE 0 END) AS magnitud_bajo
      FROM `{$table}` t
      WHERE {$whereSql}";

    $aggArgs = array_merge(
      [$slaOverdueDays * 86400, $slaRiskDays * 86400, $slaOverdueDays * 86400, $monthStart, $monthEnd, $monthStart, $monthEnd],
      $args
    );
    $row = $this->db->getRow($this->convertSql($aggSql), $aggArgs) ?: [];

    $total = (int) ($row['total'] ?? 0);
    $conCot = (int) ($row['con_cotizacion'] ?? 0);
    $conRevision = (int) ($row['con_revision'] ?? 0);
    $conPreventiva = (int) ($row['con_preventiva'] ?? 0);
    $segPorFuncionario = $this->aggregateMonthlySeguimientosByEmployee($where, $args, $monthStart, $monthEnd);
    $abiertosPorFuncionario = $this->aggregateTicketsByEmployee($where, $args, 'abiertos');
    $actualizadosPorFuncionario = $this->aggregateTicketsByEmployee($where, $args, 'actualizados_mes', $monthStart, $monthEnd);

    return [
      'total' => $total,
      'sin_cotizacion' => max(0, $total - $conCot),
      'con_cotizacion' => $conCot,
      'sin_preventiva' => max(0, $total - $conPreventiva),
      'con_preventiva' => $conPreventiva,
      'sin_revision' => max(0, $total - $conRevision),
      'con_revision' => $conRevision,
      'abiertos' => (int) ($row['abiertos'] ?? 0),
      'cerrados' => (int) ($row['cerrados'] ?? 0),
      'vencidos' => (int) ($row['sla_vencido'] ?? 0),
      'en_riesgo' => (int) ($row['sla_riesgo'] ?? 0),
      'sla_vencido' => (int) ($row['sla_vencido'] ?? 0),
      'sla_riesgo' => (int) ($row['sla_riesgo'] ?? 0),
      'promedio_primera_gestion_horas' => isset($row['avg_first_h']) ? (float) $row['avg_first_h'] : null,
      'promedio_cierre_horas' => isset($row['avg_close_h']) ? (float) $row['avg_close_h'] : null,
      'promedio_desactualizacion_horas' => isset($row['avg_stale_h']) ? (float) $row['avg_stale_h'] : null,
      'avg_first_h' => isset($row['avg_first_h']) ? (float) $row['avg_first_h'] : null,
      'avg_close_h' => isset($row['avg_close_h']) ? (float) $row['avg_close_h'] : null,
      'avg_stale_h' => isset($row['avg_stale_h']) ? (float) $row['avg_stale_h'] : null,
      'mes_actualizados' => (int) ($row['mes_actualizados'] ?? 0),
      'mes_cerrados' => (int) ($row['mes_cerrados'] ?? 0),
      'mes_seguimientos' => array_sum($segPorFuncionario),
      'seg_por_funcionario' => $segPorFuncionario,
      'abiertos_por_funcionario' => $abiertosPorFuncionario,
      'actualizados_por_funcionario' => $actualizadosPorFuncionario,
      'magnitud_critico' => (int) ($row['magnitud_critico'] ?? 0),
      'magnitud_alto' => (int) ($row['magnitud_alto'] ?? 0),
      'magnitud_medio' => (int) ($row['magnitud_medio'] ?? 0),
      'magnitud_bajo' => (int) ($row['magnitud_bajo'] ?? 0),
    ];
  }

  /**
   * @param array<string,string> $filters
   * @return array{0:int,1:int}
   */
  private function resolveMaintenancePagination(array $filters): array
  {
    $page = isset($filters['fPage']) ? (int) $filters['fPage'] : 1;
    if ($page <= 0) {
      $page = 1;
    }

    $perPage = isset($filters['fPerPage']) ? (int) $filters['fPerPage'] : 24;
    if ($perPage <= 0) {
      $perPage = 24;
    }

    if ($perPage < 24) {
      $perPage = 24;
    }
    if ($perPage > 100) {
      $perPage = 100;
    }

    return [$page, $perPage];
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
}
