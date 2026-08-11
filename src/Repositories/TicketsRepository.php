<?php


namespace SCM\Repositories;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;
use SCM\Support\TimestampParser;

final class TicketsRepository
{
  public const MAINTENANCE_TOPICS = [
    'reparaciones necesarias',
    'reparaciones locativas',
    'mejoras utiles',
    'mejoras útiles',
    'reparaciones voluntarias',
    'reparaciones antes de la entrega',
    'reparaciones antes del recibo',
  ];

  private Database $db;
  private SchemaInspector $schema;
  private TimestampParser $parser;
  private int $lastMaintenanceTotal = 0;
  private int $lastMaintenancePage = 1;
  private int $lastMaintenancePerPage = 20;
  private int $lastMaintenanceTotalPages = 1;

  public function __construct(Database $db, SchemaInspector $schema, TimestampParser $parser)
  {
    $this->db     = $db;
    $this->schema = $schema;
    $this->parser = $parser;
  }

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
      'cotizacion_estado' => $this->getDistinctValuesFromCandidates($table, ['estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento']),
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
      if ($cotFilter === 'has') {
        $where[] = "TRIM(COALESCE(t.id_cotizacion_mantenimiento, '')) <> ''";
      } elseif ($cotFilter === 'none') {
        $where[] = "TRIM(COALESCE(t.id_cotizacion_mantenimiento, '')) = ''";
      } elseif (strpos($cotFilter, 'state:') === 0) {
        $state = strtolower(trim(substr($cotFilter, 6)));
        if ($state !== '') {
          $where[] = "(LOWER(TRIM(COALESCE(t.estado_cotizacion_mantenimiento, ''))) = %s OR LOWER(TRIM(COALESCE(t.estado_respuesta_cotizacion_mantenimiento, ''))) = %s)";
          $args[] = $state;
          $args[] = $state;
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
    $this->buildLikeCondition($where, $args, "COALESCE(t.id_empleado, '')", $filters['fEmpleado']);
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
    $cotExpr = $this->schema->columnExists($table, 'id_cotizacion_mantenimiento')
      ? "TRIM(COALESCE(t.`id_cotizacion_mantenimiento`, '')) <> ''"
      : '0';
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

    $perPage = isset($filters['fPerPage']) ? (int) $filters['fPerPage'] : 20;
    if ($perPage <= 0) {
      $perPage = 20;
    }

    if ($perPage < 5) {
      $perPage = 5;
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
  public function enrichRowsWithRelatedTables(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $allCotIds = [];
    $allPrevIds = [];
    $allCorrIds = [];

    foreach ($rows as $row) {
      foreach ($this->splitIds($row['id_cotizacion_mantenimiento'] ?? '') as $id) {
        $allCotIds[$id] = true;
      }
      foreach ($this->splitIds($row['id_revision_preventiva'] ?? '') as $id) {
        $allPrevIds[$id] = true;
      }
      foreach ($this->splitIds($row['id_revision_correctiva'] ?? '') as $id) {
        $allCorrIds[$id] = true;
      }
    }

    $cotTable  = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $prevTable = $this->db->table('jet_cct_revision_preventiva');
    $corrTable = $this->db->table('jet_cct_revision_correctiva');

    $cotData = $this->fetchRelatedRows(
      $cotTable,
      array_keys($allCotIds),
      ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id'],
      [
        '_ID',
        'id_cotizacion_mantenimiento',
        'id_cotizacion',
        'estado',
        'estado_cotizacion_mantenimiento',
        'estado_respuesta_cotizacion_mantenimiento',
        'estado_respuesta',
        'respuesta',
        'fecha',
        'fecha_envio',
        'fecha_respuesta',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'inicio_trabajo',
        'final_trabajo',
        'id_acta_satisfaccion',
        'perturbacion',
        'justificacion_perturbacion',
        'valor_bonificacion',
        'area_afectada',
        'resumen_calculo_perturbacion',
      ]
    );

    $prevData = $this->fetchRelatedRows(
      $prevTable,
      array_keys($allPrevIds),
      ['_ID', 'id_revision_preventiva', 'id_revision', 'id'],
      [
        '_ID',
        'id_revision_preventiva',
        'estado',
        'estado_rev_preventiva',
        'estado_revision_preventiva',
        'fecha',
        'fecha_revision_preventiva',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'encontro_danos',
        'se_envio',
      ]
    );

    $corrData = $this->fetchRelatedRows(
      $corrTable,
      array_keys($allCorrIds),
      ['_ID', 'id_revision_correctiva', 'id_revision', 'id'],
      [
        '_ID',
        'id_revision_correctiva',
        'estado',
        'estado_rev_correctiva',
        'estado_revision_correctiva',
        'fecha',
        'fecha_revision_correctiva',
        'fecha_actualizacion',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
      ]
    );

    foreach ($rows as &$row) {
      $row['_scm_cot_fecha_registro'] = 0;
      $row['_scm_cot_fecha_envio'] = 0;
      $row['_scm_cot_fecha_respuesta'] = 0;
      $row['_scm_prev_fecha'] = 0;
      $row['_scm_prev_encontro_danos'] = '';
      $row['_scm_prev_se_envio'] = '';
      $row['_scm_corr_fecha'] = 0;
      $row['_scm_cot_inicio_trabajo'] = 0;
      $row['_scm_cot_final_trabajo'] = 0;
      $row['_scm_cot_id_acta'] = '';
      $row['_scm_cot_perturbacion'] = '';
      $row['_scm_cot_justificacion_perturbacion'] = '';
      $row['_scm_cot_valor_bonificacion'] = '';
      $row['_scm_cot_area_afectada'] = '';
      $row['_scm_cot_resumen_calculo_perturbacion'] = '';
      $row['_scm_cot_estado'] = '';
      $row['_scm_cita_cal_fecha'] = 0;
      $row['_scm_acta_fecha'] = 0;

      $cotRow = null;
      foreach ($this->splitIds($row['id_cotizacion_mantenimiento'] ?? '') as $id) {
        if (isset($cotData['rows'][$id])) {
          $cotRow = $cotData['rows'][$id];
          break;
        }
      }

      if (is_array($cotRow)) {
        $cotEstado = $this->firstExistingValue($cotRow, ['estado_cotizacion_mantenimiento', 'estado']);
        $row['_scm_cot_estado'] = $cotEstado;
        if ($cotEstado !== '' && trim((string) ($row['estado_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_cotizacion_mantenimiento'] = $cotEstado;
        }

        $cotResp = $this->firstExistingValue($cotRow, ['estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta', 'respuesta']);
        if ($cotResp !== '' && trim((string) ($row['estado_respuesta_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_respuesta_cotizacion_mantenimiento'] = $cotResp;
        }

        $cotSentFlag = $this->firstExistingValue($cotRow, ['fue_enviada_cotizacion_mantenimiento', 'fue_enviada', 'se_envio']);
        if ($cotSentFlag !== '' && trim((string) ($row['fue_enviada_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['fue_enviada_cotizacion_mantenimiento'] = $cotSentFlag;
        }

        $regRaw = $this->firstExistingValue($cotRow, ['cct_created', 'created_at', 'fecha', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $sendFlag = strtolower(trim((string) $cotSentFlag));
        $wasSent = in_array($sendFlag, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
        $sendRaw = $wasSent ? $this->firstExistingValue($cotRow, ['fecha_envio_cotizacion_mantenimiento', 'fecha_envio', 'fecha_enviada', 'fecha_envio_correo']) : '';
        $respRaw = $this->firstExistingValue($cotRow, ['fecha_respuesta', 'fecha_actualizacion', 'cct_modified', 'updated_at']);

        $row['_scm_cot_fecha_registro'] = $this->parser->parse($regRaw);
        $row['_scm_cot_fecha_envio'] = $this->parser->parse($sendRaw);
        $row['_scm_cot_fecha_respuesta'] = $this->parser->parse($respRaw);

        if ((int) $row['_scm_cot_fecha_envio'] > 0) {
          $row['fecha_envio_cotizacion_mantenimiento'] = $row['_scm_cot_fecha_envio'];
        }
        if ((int) $row['_scm_cot_fecha_respuesta'] > 0) {
          $row['fecha_respuesta_cotizacion_mantenimiento'] = $row['_scm_cot_fecha_respuesta'];
        }

        $inicioRaw = $this->firstExistingValue($cotRow, ['inicio_trabajo']);
        $finalRaw = $this->firstExistingValue($cotRow, ['final_trabajo']);
        if ($inicioRaw !== '') {
          $row['_scm_cot_inicio_trabajo'] = $this->parser->parse($inicioRaw);
        }
        if ($finalRaw !== '') {
          $row['_scm_cot_final_trabajo'] = $this->parser->parse($finalRaw);
        }

        $row['_scm_cot_id_acta'] = trim((string) ($cotRow['id_acta_satisfaccion'] ?? ''));
        $row['_scm_cot_perturbacion'] = trim((string) ($cotRow['perturbacion'] ?? ''));
        $row['_scm_cot_justificacion_perturbacion'] = trim((string) ($cotRow['justificacion_perturbacion'] ?? ''));
        $row['_scm_cot_valor_bonificacion'] = trim((string) ($cotRow['valor_bonificacion'] ?? ''));
        $row['_scm_cot_area_afectada'] = trim((string) ($cotRow['area_afectada'] ?? ''));
        $row['_scm_cot_resumen_calculo_perturbacion'] = trim((string) ($cotRow['resumen_calculo_perturbacion'] ?? ''));
        if (trim((string) ($row['perturbacion'] ?? '')) === '' && $row['_scm_cot_perturbacion'] !== '') {
          $row['perturbacion'] = $row['_scm_cot_perturbacion'];
        }
        if (trim((string) ($row['justificacion_perturbacion'] ?? '')) === '' && $row['_scm_cot_justificacion_perturbacion'] !== '') {
          $row['justificacion_perturbacion'] = $row['_scm_cot_justificacion_perturbacion'];
        }
        if (trim((string) ($row['area_afectada'] ?? '')) === '' && $row['_scm_cot_area_afectada'] !== '') {
          $row['area_afectada'] = $row['_scm_cot_area_afectada'];
        }
      }

      $prevRow = null;
      foreach ($this->splitIds($row['id_revision_preventiva'] ?? '') as $id) {
        if (isset($prevData['rows'][$id])) {
          $prevRow = $prevData['rows'][$id];
          break;
        }
      }

      if (is_array($prevRow)) {
        $prevEstado = $this->firstExistingValue($prevRow, ['estado_rev_preventiva', 'estado_revision_preventiva', 'estado']);
        if ($prevEstado !== '' && trim((string) ($row['estado_rev_preventiva'] ?? '')) === '') {
          $row['estado_rev_preventiva'] = $prevEstado;
        }

        $prevDateRaw = $this->firstExistingValue($prevRow, ['fecha_revision_preventiva', 'fecha', 'cct_created', 'created_at', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $row['_scm_prev_fecha'] = $this->parser->parse($prevDateRaw);
        $row['_scm_prev_encontro_danos'] = trim((string) ($prevRow['encontro_danos'] ?? ''));
        $row['_scm_prev_se_envio'] = trim((string) ($prevRow['se_envio'] ?? ''));
        if (trim((string) ($row['se_encontraron_danos'] ?? '')) === '' && $row['_scm_prev_encontro_danos'] !== '') {
          $row['se_encontraron_danos'] = $row['_scm_prev_encontro_danos'];
        }
      }

      $corrRow = null;
      foreach ($this->splitIds($row['id_revision_correctiva'] ?? '') as $id) {
        if (isset($corrData['rows'][$id])) {
          $corrRow = $corrData['rows'][$id];
          break;
        }
      }

      if (is_array($corrRow)) {
        $corrEstado = $this->firstExistingValue($corrRow, ['estado_rev_correctiva', 'estado_revision_correctiva', 'estado']);
        if ($corrEstado !== '' && trim((string) ($row['estado_rev_correctiva'] ?? '')) === '') {
          $row['estado_rev_correctiva'] = $corrEstado;
        }

        $corrDateRaw = $this->firstExistingValue($corrRow, ['cct_created', 'created_at', 'fecha_revision_correctiva', 'fecha', 'fecha_actualizacion', 'cct_modified', 'updated_at']);
        $row['_scm_corr_fecha'] = $this->parser->parse($corrDateRaw);
      }
    }
    unset($row);

    $allTicketPks = [];
    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $allTicketPks[$ticketPk] = true;
      }
    }

    $calendarMap = [];
    $calendarTable = 'calendario_actividades';
    if (!empty($allTicketPks) && $this->schema->tableExists($calendarTable)) {
      $ticketIds = array_keys($allTicketPks);
      $ph    = implode(',', array_fill(0, count($ticketIds), '?'));
      $sql   = "SELECT `id_ticket`, MIN(`fecha_inicio`) AS `fecha_inicio` FROM `{$calendarTable}` WHERE `id_ticket` IN ({$ph}) GROUP BY `id_ticket`";
      $items = $this->db->getResults($sql, $ticketIds);
      foreach ($items as $item) {
        $ticketPk = (int) ($item['id_ticket'] ?? 0);
        if ($ticketPk > 0) {
          $calendarMap[$ticketPk] = $item['fecha_inicio'] ?? '';
        }
      }
    }

    $allActaIds = [];
    foreach ($rows as $row) {
      $actaId = trim((string) ($row['_scm_cot_id_acta'] ?? ''));
      if ($actaId !== '') {
        $allActaIds[$actaId] = true;
      }
    }

    $actaTable = $this->db->table('jet_cct_actas_de_satisfaccion');
    $actaData = $this->fetchRelatedRows(
      $actaTable,
      array_keys($allActaIds),
      ['_ID', 'id_acta_satisfaccion'],
      ['_ID', 'fecha', 'fecha_satisfaccion', 'cct_created', 'created_at']
    );
    $actaByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $actaTable,
      ['id_ticket'],
      array_map('strval', array_keys($allTicketPks)),
      ['_ID', 'id_ticket', 'fecha', 'fecha_satisfaccion', 'cct_created', 'created_at']
    );

    foreach ($rows as &$row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0 && isset($calendarMap[$ticketPk])) {
        $row['_scm_cita_cal_fecha'] = $this->parser->parse($calendarMap[$ticketPk]);
      }

      $actaId = trim((string) ($row['_scm_cot_id_acta'] ?? ''));
      if ($actaId !== '' && isset($actaData['rows'][$actaId])) {
        $actaRow = $actaData['rows'][$actaId];
        $actaDateRaw = $this->firstExistingValue($actaRow, ['fecha_satisfaccion', 'fecha', 'cct_created', 'created_at']);
        $row['_scm_acta_fecha'] = $this->parser->parse($actaDateRaw);
      }

      if ($ticketPk > 0 && !empty($actaByTicket[(string) $ticketPk])) {
        $bestTs = (int) ($row['_scm_acta_fecha'] ?? 0);
        foreach ((array) $actaByTicket[(string) $ticketPk] as $actaTicketRow) {
          if (!is_array($actaTicketRow)) {
            continue;
          }
          $candidateRaw = $this->firstExistingValue($actaTicketRow, ['cct_created', 'fecha_satisfaccion', 'fecha', 'created_at']);
          $candidateTs = $this->parser->parse($candidateRaw);
          if ($candidateTs > $bestTs) {
            $bestTs = $candidateTs;
          }
        }
        if ($bestTs > 0) {
          $row['_scm_acta_fecha'] = $bestTs;
        }
      }
    }
    unset($row);

    $rows = $this->applyTimelineEnrichmentByTicket($rows);
    $rows = $this->enrichRowsWithHistorial($rows);
    $rows = $this->enrichRowsWithCaseDetails($rows);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrichRowsWithCaseDetails(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $ticketKeys = [];
    $contractIds = [];
    $propertyIds = [];
    $ownerIds = [];
    $tenantIds = [];
    foreach ($rows as $row) {
      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        $ticketKeys[$key] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['contrato', 'id_contrato'])) as $id) {
        $contractIds[$id] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['id_inmueble'])) as $id) {
        $propertyIds[$id] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['id_propietario'])) as $id) {
        $ownerIds[$id] = true;
      }
      foreach ($this->splitIds($this->firstExistingValue($row, ['id_arrendatario'])) as $id) {
        $tenantIds[$id] = true;
      }
    }

    $segByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_seguimiento_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified', 'id_coordinador', 'id_empleado', 'evidencia']
    );
    $notesByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_notas_ticket'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'cct_status', 'id_ticket', 'id_empleado', 'fecha', 'nombre', 'observacion', 'cct_author_id', 'cct_created', 'cct_modified']
    );
    $contractById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_contratos_arrendamiento'),
      ['_ID', 'contrato'],
      array_keys($contractIds)
    );
    $propertyById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_inmuebles'),
      ['codigo'],
      array_keys($propertyIds)
    );
    $ownerById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_propietarios'),
      ['id_propietario'],
      array_keys($ownerIds)
    );
    $tenantById = $this->fetchSingleRowsByIdCandidates(
      $this->db->table('jet_cct_arrendatarios'),
      ['id_arrendatario'],
      array_keys($tenantIds)
    );
    $histByProperty = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_inmueble', 'id_inmueble_data'],
      array_keys($propertyIds),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );
    $histByTicket = $this->fetchRowsGroupedByColumnCandidates(
      $this->db->table('jet_cct_historial_del_inmueble'),
      ['id_ticket'],
      array_keys($ticketKeys),
      ['_ID', 'id_inmueble', 'id_inmueble_data', 'id_ticket', 'fecha', 'tipo_reporte', 'observacion', 'funcionario', 'cct_created', 'cct_modified', 'cct_author_id']
    );

    foreach ($rows as &$row) {
      $row['_scm_seguimientos_ticket'] = [];
      $row['_scm_notas_ticket'] = [];
      $row['_scm_historial_inmueble'] = [];
      $row['_scm_contrato_data'] = [];
      $row['_scm_inmueble_data'] = [];
      $row['_scm_propietario_data'] = [];
      $row['_scm_arrendatario_data'] = [];

      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        if (!empty($segByTicket[$key])) {
          $row['_scm_seguimientos_ticket'] = array_merge($row['_scm_seguimientos_ticket'], $segByTicket[$key]);
        }
        if (!empty($notesByTicket[$key])) {
          $row['_scm_notas_ticket'] = array_merge($row['_scm_notas_ticket'], $notesByTicket[$key]);
        }
      }

      $contractRaw = $this->firstExistingValue($row, ['contrato', 'id_contrato']);
      $contractId = $this->first_id_value($contractRaw);
      if ($contractId !== '' && isset($contractById[$contractId])) {
        $row['_scm_contrato_data'] = $contractById[$contractId];
      }

      $propertyRaw = $this->firstExistingValue($row, ['id_inmueble']);
      $propertyId = $this->first_id_value($propertyRaw);
      if ($propertyId === '' && !empty($row['_scm_contrato_data']) && is_array($row['_scm_contrato_data'])) {
        $propertyId = $this->first_id_value($this->firstExistingValue(
          $row['_scm_contrato_data'],
          ['id_inmueble']
        ));
      }
      if ($propertyId !== '' && isset($propertyById[$propertyId])) {
        $row['_scm_inmueble_data'] = $propertyById[$propertyId];
      }
      $ownerId = $this->first_id_value($this->firstExistingValue($row, ['id_propietario']));
      if ($ownerId !== '' && isset($ownerById[$ownerId])) {
        $row['_scm_propietario_data'] = $ownerById[$ownerId];
        if (trim((string)($row['indicativo_propietario'] ?? '')) === '') {
          $row['indicativo_propietario'] = trim((string)($ownerById[$ownerId]['indicativo'] ?? ''));
        }
      }
      $tenantId = $this->first_id_value($this->firstExistingValue($row, ['id_arrendatario']));
      if ($tenantId !== '' && isset($tenantById[$tenantId])) {
        $row['_scm_arrendatario_data'] = $tenantById[$tenantId];
        if (trim((string)($row['indicativo_arrendatario'] ?? '')) === '') {
          $row['indicativo_arrendatario'] = trim((string)($tenantById[$tenantId]['indicativo'] ?? ''));
        }
      }
      if ($propertyId !== '' && isset($histByProperty[$propertyId])) {
        $row['_scm_historial_inmueble'] = $histByProperty[$propertyId];
      }
      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        if (!empty($histByTicket[$key])) {
          $row['_scm_historial_inmueble'] = array_merge($row['_scm_historial_inmueble'], $histByTicket[$key]);
        }
      }
      if (!empty($row['_scm_historial_inmueble'])) {
        $uniqueHist = [];
        foreach ($row['_scm_historial_inmueble'] as $histItem) {
          if (!is_array($histItem)) {
            continue;
          }
          $histKey = trim((string)($histItem['_ID'] ?? ''));
          if ($histKey === '') {
            $histKey = md5(json_encode($histItem));
          }
          $uniqueHist[$histKey] = $histItem;
        }
        $row['_scm_historial_inmueble'] = array_values($uniqueHist);
      }

      usort($row['_scm_seguimientos_ticket'], fn(array $a, array $b): int => $this->parser->parse($b['fecha'] ?? $b['cct_created'] ?? '') <=> $this->parser->parse($a['fecha'] ?? $a['cct_created'] ?? ''));
      usort($row['_scm_notas_ticket'], fn(array $a, array $b): int => $this->parser->parse($b['fecha'] ?? $b['cct_created'] ?? '') <=> $this->parser->parse($a['fecha'] ?? $a['cct_created'] ?? ''));
      usort($row['_scm_historial_inmueble'], fn(array $a, array $b): int => $this->parser->parse($b['fecha'] ?? $b['cct_created'] ?? '') <=> $this->parser->parse($a['fecha'] ?? $a['cct_created'] ?? ''));
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function applyTimelineEnrichmentByTicket(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $pkStringKeys = [];
    $logicalStringKeys = [];
    $calendarIntKeys = [];

    foreach ($rows as $row) {
      $ticketPk = (int) ($row['_ID'] ?? 0);
      if ($ticketPk > 0) {
        $pkStringKeys[(string) $ticketPk] = true;
        $calendarIntKeys[$ticketPk] = true;
      }

      $logicalId = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalId !== '') {
        $logicalStringKeys[$logicalId] = true;
        if (ctype_digit($logicalId)) {
          $calendarIntKeys[(int) $logicalId] = true;
        }
      }
    }

    $lookupStringKeys = array_keys($pkStringKeys + $logicalStringKeys);

    $calendarMap = [];
    $calendarTable = 'calendario_actividades';
    if (!empty($calendarIntKeys) && $this->schema->tableExists($calendarTable)) {
      $ticketIds = array_keys($calendarIntKeys);
      $ph    = implode(',', array_fill(0, count($ticketIds), '?'));
      $hasEventId = $this->schema->columnExists($calendarTable, 'id');
      $eventSelect = $hasEventId ? ", MIN(`id`) AS `evento_id`" : '';
      $sql   = "SELECT `id_ticket`, MIN(`fecha_inicio`) AS `fecha_inicio`{$eventSelect} FROM `{$calendarTable}` WHERE `id_ticket` IN ({$ph}) GROUP BY `id_ticket`";
      $items = $this->db->getResults($sql, $ticketIds);
      foreach ($items as $item) {
        $k = trim((string) ($item['id_ticket'] ?? ''));
        if ($k === '') {
          continue;
        }
        $calendarMap[$k] = [
          'fecha_inicio' => $item['fecha_inicio'] ?? '',
          'evento_id' => $hasEventId ? trim((string) ($item['evento_id'] ?? '')) : '',
        ];
      }
    }

    $corrTable = $this->db->table('jet_cct_revision_correctiva');
    $corrRowsByTicket = $this->fetchRowsByTicketForeignKey(
      $corrTable,
      $lookupStringKeys,
      [
        '_ID',
        'id_ticket',
        'fecha',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
        'estado',
        'estado_rev_correctiva',
        'estado_revision_correctiva',
      ]
    );

    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $cotRowsByTicket = $this->fetchRowsByTicketForeignKey(
      $cotTable,
      $lookupStringKeys,
      [
        '_ID',
        'id_ticket',
        'fecha',
        'fecha_envio',
        'fecha_respuesta',
        'inicio_trabajo',
        'final_trabajo',
        'estado',
        'estado_respuesta',
        'estado_cotizacion_mantenimiento',
        'estado_respuesta_cotizacion_mantenimiento',
        'se_envio',
        'fue_enviada_cotizacion_mantenimiento',
        'fue_enviada',
        'cct_created',
        'cct_modified',
        'created_at',
        'updated_at',
      ]
    );

    foreach ($rows as &$row) {
      $keys = $this->resolveTicketLookupKeys($row);
      if (empty($keys)) {
        continue;
      }

      foreach ($keys as $key) {
        if (isset($calendarMap[$key])) {
          $row['_scm_cita_cal_fecha'] = $this->parser->parse((string) ($calendarMap[$key]['fecha_inicio'] ?? ''));
          $row['_scm_cita_cal_id'] = trim((string) ($calendarMap[$key]['evento_id'] ?? ''));
          break;
        }
      }

      $corrCandidates = [];
      foreach ($keys as $key) {
        if (!empty($corrRowsByTicket[$key])) {
          $corrCandidates = array_merge($corrCandidates, $corrRowsByTicket[$key]);
        }
      }
      $latestCorr = $this->pickLatestRowByDateCandidates(
        $corrCandidates,
        ['fecha', 'cct_created', 'cct_modified', 'created_at', 'updated_at']
      );
      if (!empty($latestCorr)) {
        $corrRaw = $this->firstExistingValue($latestCorr, ['fecha', 'cct_created', 'cct_modified', 'created_at', 'updated_at']);
        $corrTs = $this->parser->parse($corrRaw);
        if ($corrTs > 0) {
          $row['_scm_corr_fecha'] = $corrTs;
        }

        $corrEstado = $this->firstExistingValue($latestCorr, ['estado_rev_correctiva', 'estado_revision_correctiva', 'estado']);
        if ($corrEstado !== '' && trim((string) ($row['estado_rev_correctiva'] ?? '')) === '') {
          $row['estado_rev_correctiva'] = $corrEstado;
        }
      }

      $cotCandidates = [];
      foreach ($keys as $key) {
        if (!empty($cotRowsByTicket[$key])) {
          $cotCandidates = array_merge($cotCandidates, $cotRowsByTicket[$key]);
        }
      }
      $latestCot = $this->pickLatestRowByDateCandidates(
        $cotCandidates,
        ['cct_created', 'created_at', 'fecha', 'cct_modified', 'updated_at']
      );
      if (!empty($latestCot)) {
        $cotRegRaw = $this->firstExistingValue($latestCot, ['cct_created', 'created_at', 'fecha', 'cct_modified', 'updated_at']);
        $cotSentFlag = $this->firstExistingValue($latestCot, ['se_envio', 'fue_enviada_cotizacion_mantenimiento', 'fue_enviada']);
        $cotSentOk = in_array(strtolower(trim((string) $cotSentFlag)), ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
        $cotSendRaw = $cotSentOk ? $this->firstExistingValue($latestCot, ['fecha_envio', 'fecha_envio_cotizacion_mantenimiento', 'fecha_enviada', 'fecha_envio_correo']) : '';
        $cotRespRaw = $this->firstExistingValue($latestCot, ['fecha_respuesta', 'cct_modified', 'updated_at']);
        $cotStartRaw = $this->firstExistingValue($latestCot, ['inicio_trabajo']);
        $cotEndRaw = $this->firstExistingValue($latestCot, ['final_trabajo']);

        $cotRegTs = $this->parser->parse($cotRegRaw);
        if ($cotRegTs > 0) {
          $row['_scm_cot_fecha_registro'] = $cotRegTs;
        }

        $cotSendTs = $this->parser->parse($cotSendRaw);
        if ($cotSendTs > 0) {
          $row['_scm_cot_fecha_envio'] = $cotSendTs;
        }

        $cotRespTs = $this->parser->parse($cotRespRaw);
        if ($cotRespTs > 0) {
          $row['_scm_cot_fecha_respuesta'] = $cotRespTs;
          $row['fecha_respuesta_cotizacion_mantenimiento'] = $cotRespTs;
        }

        $cotStartTs = $this->parser->parse($cotStartRaw);
        if ($cotStartTs > 0) {
          $row['_scm_cot_inicio_trabajo'] = $cotStartTs;
        }

        $cotEndTs = $this->parser->parse($cotEndRaw);
        if ($cotEndTs > 0) {
          $row['_scm_cot_final_trabajo'] = $cotEndTs;
        }

        $cotEstado = $this->firstExistingValue($latestCot, ['estado_cotizacion_mantenimiento', 'estado']);
        if ($cotEstado !== '' && trim((string) ($row['estado_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_cotizacion_mantenimiento'] = $cotEstado;
        }
        if ($cotSentFlag !== '' && trim((string) ($row['fue_enviada_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['fue_enviada_cotizacion_mantenimiento'] = $cotSentFlag;
        }
        if ($cotSentFlag !== '' && trim((string) ($row['se_envio'] ?? '')) === '') {
          $row['se_envio'] = $cotSentFlag;
        }

        $cotRespEstado = $this->firstExistingValue($latestCot, ['estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta']);
        if ($cotRespEstado !== '' && trim((string) ($row['estado_respuesta_cotizacion_mantenimiento'] ?? '')) === '') {
          $row['estado_respuesta_cotizacion_mantenimiento'] = $cotRespEstado;
        }
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrichRowsWithHistorial(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $lookupKeys = [];
    foreach ($rows as $row) {
      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        $lookupKeys[$key] = true;
      }
    }

    if (empty($lookupKeys)) {
      foreach ($rows as &$row) {
        $row['_scm_historial_items'] = [];
      }
      unset($row);
      return $rows;
    }

    $keys = array_keys($lookupKeys);
    $items  = $this->fetchHistoryItemsByKeys($this->db->table('jet_cct_historial_del_ticket'), $keys);

    $byTicket = [];
    if (is_array($items)) {
      foreach ($items as $item) {
        $itemKeys = is_array($item['_scm_ticket_keys'] ?? null) ? $item['_scm_ticket_keys'] : [];
        if (empty($itemKeys)) {
          continue;
        }
        foreach ($itemKeys as $ticketKey) {
          $byTicket[$ticketKey][] = $item;
        }
      }
    }

    foreach ($byTicket as &$list) {
      usort(
        $list,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );
    }
    unset($list);

    foreach ($rows as &$row) {
      $merged = [];
      $seen = [];
      foreach ($this->resolveTicketLookupKeys($row) as $key) {
        if (empty($byTicket[$key])) {
          continue;
        }
        foreach ($byTicket[$key] as $item) {
          $histPk = trim((string) ($item['_ID'] ?? ''));
          $sig = $histPk !== '' ? ('id:' . $histPk) : md5((string) json_encode($item));
          if (isset($seen[$sig])) {
            continue;
          }
          $seen[$sig] = true;
          $merged[] = $item;
        }
      }

      usort(
        $merged,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );

      $inlineItems = $this->buildTicketInlineHistoryItems($row);
      foreach ($inlineItems as $inlineItem) {
        $merged[] = $inlineItem;
      }
      usort(
        $merged,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );

      $row['_scm_historial_items'] = $merged;
    }
    unset($row);

    return $rows;
  }

  /**
   * @param array<int,string> $keys
   * @return array<int,array<string,mixed>>
   */
  private function fetchHistoryItemsByKeys(string $histTable, array $keys): array
  {
    if (empty($keys) || !$this->schema->tableExists($histTable)) {
      return [];
    }

    $ticketCols = $this->detectHistoryTicketColumns($histTable);
    if (empty($ticketCols)) {
      return [];
    }

    $wantedCols = [
      '_ID',
      'fecha',
      'nombre',
      'respuesta',
      'observacion',
      'descripcion',
      'imagen',
      'archivos',
      'id_empleado',
      'cct_author_id',
      'cct_created',
      'cct_modified',
      'id_ticket',
      'id_revision_preventiva',
      'id_revision_correctiva',
      'id_revision_entrega',
      'id_revision_recibo',
      'id_revision_sp',
      'id_revision_servicios_publicos',
      'id_cotizacion_mantenimiento',
      'id_cotizacion_comercial',
      'id_acta_satisfaccion',
      'id_acta_entrega',
      'id_acta_revision',
      'id_acta_revision_notificacion',
      'id_acta_recibo',
      'id_inventario',
      'id_orden',
      'id_contrato',
      'id_hoja_cierre',
      'id_estudio_aseguradora',
      'id_ticket_danos_entrega',
      'id_ticket_danos_recibo',
      'seguimiento_reparaciones',
      'id_inmueble',
    ];
    $selectCols = [];
    foreach ($wantedCols as $col) {
      if ($this->schema->columnExists($histTable, $col)) {
        $selectCols[] = $col;
      }
    }
    foreach ($ticketCols as $ticketCol) {
      if (!in_array($ticketCol, $selectCols, true)) {
        $selectCols[] = $ticketCol;
      }
    }
    if (empty($selectCols)) {
      return [];
    }

    $ph         = implode(',', array_fill(0, count($keys), 'CAST(? AS BINARY)'));
    $select     = implode(', ', array_map(static fn(string $col): string => "`{$col}`", $selectCols));
    $whereParts = [];
    $args       = [];
    foreach ($ticketCols as $ticketCol) {
      $whereParts[] = "CAST(TRIM(COALESCE(`{$ticketCol}`, '')) AS BINARY) IN ({$ph})";
      foreach ($keys as $key) {
        $args[] = $key;
      }
    }
    $sql   = "SELECT {$select} FROM `{$histTable}` WHERE " . implode(' OR ', $whereParts);
    $items = $this->db->getResults($sql, $args);
    if (empty($items)) {
      return [];
    }

    $out = [];
    foreach ($items as $item) {
      $itemKeys = [];
      foreach ($ticketCols as $ticketCol) {
        $ticketKey = trim((string) ($item[$ticketCol] ?? ''));
        if ($ticketKey !== '') {
          $itemKeys[$ticketKey] = true;
        }
      }
      if (empty($itemKeys)) {
        continue;
      }
      $item['_scm_ticket_keys'] = array_keys($itemKeys);
      $item['_scm_ts'] = $this->parser->parse(
        $this->firstExistingValue($item, ['cct_created', 'fecha', 'cct_modified'])
      );
      $out[] = $item;
    }

    return $out;
  }

  /** @param array<string,mixed> $row
   *  @return array<string,mixed>
   */
  /** @return array<int,array<string,mixed>> */
  private function buildTicketInlineHistoryItems(array $row): array
  {
    $author = trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? ''));
    if ($author === '') {
      $author = 'Ticket';
    }

    $ts = $this->parser->parse($this->firstExistingValue($row, ['fecha_actualizacion', 'fecha']));
    if ($ts <= 0) {
      $ts = $this->parser->parse($row['fecha'] ?? null);
    }

    $items = [];
    $add = function (string $text, string $kind) use (&$items, $author, $ts, $row): void {
      $clean = trim($text);
      if ($clean === '') {
        return;
      }
      $items[] = [
        '_ID' => '',
        'nombre' => $author,
        'respuesta' => $clean,
        '_scm_ts' => $ts,
        '_scm_ticket_keys' => $this->resolveTicketLookupKeys($row),
        '_scm_kind' => $kind,
      ];
    };

    $add($this->firstExistingValue($row, ['respuesta', 'observacion', 'respuesta_ticket', 'detalle_respuesta']), 'respuesta');
    $add($this->firstExistingValue($row, ['seguimiento_mantenimiento']), 'seguimiento');

    return $items;
  }

  /** @return array<int,string> */
  private function detectHistoryTicketColumns(string $histTable): array
  {
    $preferred = [
      'id_ticket',
      'ticket_id',
      'id_tickets',
      'tickets_id',
      'id_ticket_mantenimiento',
      'ticket',
      'ticket_pk',
    ];

    $cols = [];
    foreach ($preferred as $candidate) {
      if ($this->schema->columnExists($histTable, $candidate)) {
        $cols[] = $candidate;
      }
    }

    $tableCols = $this->schema->getTableColumns($histTable);
    foreach ($tableCols as $column) {
      $col = trim((string) $column);
      if ($col === '' || in_array($col, $cols, true)) {
        continue;
      }
      if (stripos($col, 'ticket') !== false) {
        $cols[] = $col;
      }
    }

    return $cols;
  }

  /**
   * @param array<int,string> $ticketKeys
   * @param array<int,string> $wantedColumns
   * @return array<string,array<int,array<string,mixed>>>
   */
  private function fetchRowsByTicketForeignKey(string $table, array $ticketKeys, array $wantedColumns): array
  {
    $ticketKeys = array_values(array_filter(array_map('strval', $ticketKeys), static fn(string $v): bool => trim($v) !== ''));
    if (empty($ticketKeys) || !$this->schema->tableExists($table)) {
      return [];
    }

    if (!$this->schema->columnExists($table, 'id_ticket')) {
      return [];
    }

    $selectCols = [];
    foreach ($wantedColumns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $selectCols[] = $column;
      }
    }
    if (!in_array('id_ticket', $selectCols, true)) {
      $selectCols[] = 'id_ticket';
    }

    if (empty($selectCols)) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($ticketKeys), 'CAST(%s AS BINARY)'));
    $select = implode(', ', array_map(static fn(string $col): string => "`{$col}`", $selectCols));
    $sql    = "SELECT {$select} FROM `{$table}` WHERE CAST(TRIM(COALESCE(`id_ticket`, '')) AS BINARY) IN ({$ph})";
    $items  = $this->db->getResults($this->convertSql($sql), $ticketKeys);

    if (empty($items)) {
      return [];
    }

    $grouped = [];
    foreach ($items as $item) {
      $key = trim((string) ($item['id_ticket'] ?? ''));
      if ($key === '') {
        continue;
      }
      $grouped[$key][] = $item;
    }

    return $grouped;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @param array<int,string> $dateCandidates
   * @return array<string,mixed>
   */
  private function pickLatestRowByDateCandidates(array $rows, array $dateCandidates): array
  {
    if (empty($rows)) {
      return [];
    }

    $latest = [];
    $latestTs = 0;
    foreach ($rows as $row) {
      $raw = $this->firstExistingValue($row, $dateCandidates);
      $ts = $this->parser->parse($raw);
      if ($ts <= 0) {
        $fallback = $this->firstExistingValue($row, ['cct_created', 'cct_modified', 'created_at', 'updated_at']);
        $ts = $this->parser->parse($fallback);
      }

      if ($ts >= $latestTs) {
        $latestTs = $ts;
        $latest = $row;
        $latest['_scm_sort_ts'] = $ts;
      }
    }

    return $latest;
  }

  /** @param array<string,mixed> $row
   *  @return array<int,string>
   */
  private function resolveTicketLookupKeys(array $row): array
  {
    $keys = [];

    $ticketPk = (int) ($row['_ID'] ?? 0);
    if ($ticketPk > 0) {
      $keys[] = (string) $ticketPk;
    }

    $logicalId = trim((string) ($row['id_ticket'] ?? ''));
    if ($logicalId !== '' && !in_array($logicalId, $keys, true)) {
      $keys[] = $logicalId;
    }

    return $keys;
  }

  /** @return array<int,string> */
  private function formatTopicOptions(array $topics): array
  {
    $out = [];
    foreach ($topics as $topic) {
      $out[] = $this->toDisplayLabel($topic);
    }

    return $out;
  }

  private function toDisplayLabel(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    return ucfirst($value);
  }

  /**
   * @param array<string,string> $filters
   * @return array{0:array<int,string>,1:array<int,mixed>}
   */
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
      if ($cotFilter === 'has') {
        $where[] = "TRIM(COALESCE(t.id_cotizacion_mantenimiento, '')) <> ''";
      } elseif ($cotFilter === 'none') {
        $where[] = "TRIM(COALESCE(t.id_cotizacion_mantenimiento, '')) = ''";
      } elseif (strpos($cotFilter, 'state:') === 0) {
        $state = strtolower(trim(substr($cotFilter, 6)));
        if ($state !== '') {
          $where[] = "(LOWER(TRIM(COALESCE(t.estado_cotizacion_mantenimiento, ''))) = %s OR LOWER(TRIM(COALESCE(t.estado_respuesta_cotizacion_mantenimiento, ''))) = %s)";
          $args[] = $state;
          $args[] = $state;
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
    $this->buildLikeCondition($where, $args, "COALESCE(t.id_empleado, '')", (string) ($filters['fEmpleado'] ?? ''));
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
  private function fetchRelatedRows(string $table, array $ids, array $idCandidates, array $wantedColumns): array
  {
    $ids = array_values(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== ''));
    if (empty($ids) || !$this->schema->tableExists($table)) {
      return ['id_col' => '', 'rows' => []];
    }

    $idCol = $this->schema->detectFirstExistingColumn($table, $idCandidates);
    if ($idCol === '') {
      return ['id_col' => '', 'rows' => []];
    }

    $selectCols = [];
    foreach ($wantedColumns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $selectCols[] = $column;
      }
    }

    if (!in_array($idCol, $selectCols, true)) {
      $selectCols[] = $idCol;
    }

    if (empty($selectCols)) {
      return ['id_col' => $idCol, 'rows' => []];
    }

    $ph     = implode(',', array_fill(0, count($ids), '?'));
    $select = implode(', ', array_map(static fn(string $col): string => "`{$col}`", $selectCols));
    $sql    = "SELECT {$select} FROM `{$table}` WHERE `{$idCol}` IN ({$ph})";

    $items = $this->db->getResults($sql, $ids);
    if (empty($items)) {
      return ['id_col' => $idCol, 'rows' => []];
    }

    $map = [];
    foreach ($items as $item) {
      $key = trim((string) ($item[$idCol] ?? ''));
      if ($key === '') {
        continue;
      }

      if (!isset($map[$key])) {
        $map[$key] = $item;
      }
    }

    return ['id_col' => $idCol, 'rows' => $map];
  }

  /** @param array<string,mixed> $row */
  private function firstExistingValue(array $row, array $keys): string
  {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $row)) {
        continue;
      }

      $value = trim((string) $row[$key]);
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  /** @return array<int,string> */
  private function splitIds($raw): array
  {
    $text = trim((string) $raw);
    if ($text === '') {
      return [];
    }

    $parts = preg_split('/[,\s;|]+/', $text);
    if (!is_array($parts)) {
      return [];
    }

    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id === '') {
        continue;
      }
      $ids[$id] = true;
    }

    return array_keys($ids);
  }

  private function first_id_value(string $raw): string
  {
    $parts = preg_split('/[,\|;]+/', $raw);
    if (!is_array($parts)) {
      return trim($raw);
    }
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id !== '') {
        return $id;
      }
    }
    return '';
  }

  /**
   * @param array<int,string> $keyColumns
   * @param array<int,string> $keys
   * @param array<int,string> $wantedColumns
   * @return array<string,array<int,array<string,mixed>>>
   */
  private function fetchRowsGroupedByColumnCandidates(string $table, array $keyColumns, array $keys, array $wantedColumns): array
  {
    $keys = array_values(array_filter(array_map('strval', $keys), static fn(string $v): bool => trim($v) !== ''));
    if (empty($keys) || !$this->schema->tableExists($table)) {
      return [];
    }

    $realKeyColumns = [];
    foreach ($keyColumns as $col) {
      if ($this->schema->columnExists($table, $col)) {
        $realKeyColumns[] = $col;
      }
    }
    if (empty($realKeyColumns)) {
      return [];
    }

    $selectCols = [];
    foreach ($wantedColumns as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $selectCols[] = $column;
      }
    }
    foreach ($realKeyColumns as $col) {
      if (!in_array($col, $selectCols, true)) {
        $selectCols[] = $col;
      }
    }
    if (empty($selectCols)) {
      return [];
    }

    $select = implode(', ', array_map(static fn(string $col): string => "`{$col}`", $selectCols));
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $whereParts = [];
    $args = [];
    foreach ($realKeyColumns as $col) {
      $whereParts[] = "CAST(TRIM(COALESCE(`{$col}`, '')) AS BINARY) IN ({$ph})";
      foreach ($keys as $k) {
        $args[] = $k;
      }
    }
    $sql = "SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts);
    $items = $this->db->getResults($sql, $args);
    if (empty($items)) {
      return [];
    }

    $grouped = [];
    foreach ($items as $item) {
      foreach ($realKeyColumns as $col) {
        $key = trim((string) ($item[$col] ?? ''));
        if ($key === '') {
          continue;
        }
        $grouped[$key][] = $item;
      }
    }

    return $grouped;
  }

  /**
   * @param array<int,string> $idCandidates
   * @param array<int,string> $ids
   * @return array<string,array<string,mixed>>
   */
  private function fetchSingleRowsByIdCandidates(string $table, array $idCandidates, array $ids): array
  {
    $ids = array_values(array_filter(array_map('strval', $ids), static fn(string $v): bool => trim($v) !== ''));
    if (empty($ids) || !$this->schema->tableExists($table)) {
      return [];
    }

    $realIdCols = [];
    foreach ($idCandidates as $col) {
      if ($this->schema->columnExists($table, $col)) {
        $realIdCols[] = $col;
      }
    }
    if (empty($realIdCols)) {
      return [];
    }

    $selectCols = $this->schema->getTableColumns($table);
    if (empty($selectCols)) {
      return [];
    }

    $select = implode(', ', array_map(static fn(string $col): string => "`{$col}`", $selectCols));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $whereParts = [];
    $args = [];
    foreach ($realIdCols as $col) {
      $whereParts[] = "CAST(TRIM(COALESCE(`{$col}`, '')) AS BINARY) IN ({$ph})";
      foreach ($ids as $id) {
        $args[] = $id;
      }
    }
    $sql = "SELECT {$select} FROM `{$table}` WHERE " . implode(' OR ', $whereParts);
    $items = $this->db->getResults($sql, $args);
    if (empty($items)) {
      return [];
    }

    $map = [];
    foreach ($items as $item) {
      foreach ($realIdCols as $col) {
        $id = trim((string) ($item[$col] ?? ''));
        if ($id !== '' && !isset($map[$id])) {
          $map[$id] = $item;
        }
      }
    }
    return $map;
  }

  /**
   * @param array<int,mixed> $items
   * @return array<int,string>
   */
  private function uniqueNonEmptyValues(array $items): array
  {
    $seen = [];
    $out = [];

    foreach ($items as $item) {
      $value = trim((string) $item);
      if ($value === '') {
        continue;
      }

      $key = strtolower($value);
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $out[] = $value;
    }

    natcasesort($out);
    return array_values($out);
  }

  /**
   * @param array<int,string> $candidates
   * @return array<int,string>
   */
  private function getDistinctValuesFromCandidates(string $table, array $candidates, int $limit = 120): array
  {
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $parts = [];
    foreach ($candidates as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $parts[] = "SELECT TRIM(COALESCE(`{$column}`, '')) AS v FROM `{$table}`";
      }
    }

    if (empty($parts)) {
      return [];
    }

    $limit = max(1, (int) $limit);
    $sql = 'SELECT DISTINCT v FROM (' . implode(' UNION ALL ', $parts) . ") AS scm_dist WHERE v <> '' ORDER BY v ASC LIMIT {$limit}";

    $rows = $this->db->getCol($sql);
    if (empty($rows)) {
      return [];
    }

    return $this->uniqueNonEmptyValues($rows);
  }

  /**
   * @return array<int,array{id:string,label:string}>
   */
  private function getFuncionariosFilterOptions(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $idCol = $this->schema->detectFirstExistingColumn($table, ['id_empleado', '_ID']);
    if ($idCol === '') {
      return [];
    }

    $nameCol = $this->schema->detectFirstExistingColumn($table, ['nombre']);
    $activeCol = $this->schema->detectFirstExistingColumn($table, ['activo', 'cct_status']);

    $select = ["TRIM(COALESCE(`{$idCol}`, '')) AS id"];
    if ($nameCol !== '') {
      $select[] = "TRIM(COALESCE(`{$nameCol}`, '')) AS nombre";
    } else {
      $select[] = "'' AS nombre";
    }
    if ($activeCol !== '') {
      $select[] = "TRIM(COALESCE(`{$activeCol}`, '')) AS activo";
    } else {
      $select[] = "'' AS activo";
    }

    $sql = 'SELECT ' . implode(', ', $select) . " FROM `{$table}`";
    $rows = $this->db->getResults($sql);
    if (empty($rows)) {
      return [];
    }

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['id'] ?? ''));
      if ($id === '') {
        continue;
      }

      $activeRaw = strtolower(trim((string) ($row['activo'] ?? '')));
      if ($activeRaw !== '' && !in_array($activeRaw, ['1', 'si', 'sí', 'true', 'activo', 'active'], true)) {
        continue;
      }

      $name = trim((string) ($row['nombre'] ?? ''));
      $label = $name !== '' ? ($id . ' - ' . $name) : $id;
      $key = strtolower($id);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = ['id' => $id, 'label' => $label];
    }

    usort(
      $out,
      static fn(array $a, array $b): int => strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''))
    );

    return $out;
  }

  /** @return array<int,string> */
  private function getBarriosFilterOptions(): array
  {
    $barriosTable = $this->db->table('jet_cct_barrios');
    if ($this->schema->tableExists($barriosTable) && $this->schema->columnExists($barriosTable, 'barrio')) {
      $rows = $this->db->getCol(
        "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio FROM `{$barriosTable}` WHERE TRIM(COALESCE(`barrio`, '')) <> '' ORDER BY barrio ASC LIMIT 500"
      );
      return $this->uniqueNonEmptyValues($rows);
    }

    return $this->getDistinctValuesFromCandidates($this->ticketsTable(), ['barrio'], 500);
  }
}
