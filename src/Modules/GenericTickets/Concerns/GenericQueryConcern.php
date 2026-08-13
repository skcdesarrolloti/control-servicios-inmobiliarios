<?php

declare(strict_types=1);

namespace SCM\Modules\GenericTickets\Concerns;

use SCM\Core\Auth;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimelineMaps\CertificacionesTimelineMap;
use SCM\Support\TimelineMaps\ContableTimelineMap;
use SCM\Support\TimelineMaps\ContractualTimelineMap;
use SCM\Support\TimelineMaps\EntregaTimelineMap;
use SCM\Support\TimelineMaps\PreventivaTimelineMap;
use SCM\Support\TimelineMaps\ReciboTimelineMap;

trait GenericQueryConcern
{
  public function parse_params_generic(array $input, string $prefix): array
  {
    $clean = static function ($v): string {
      return sanitize_text_field((string) ($v ?? ''));
    };
    $singleDate = $clean($input[$prefix . 'fecha'] ?? '');
    $dateFrom = $singleDate !== '' ? $singleDate : $clean($input[$prefix . 'fecha_desde'] ?? '');
    $dateTo = $singleDate !== '' ? $singleDate : $clean($input[$prefix . 'fecha_hasta'] ?? '');

    return [
      'fEstado' => $clean($input[$prefix . 'estado'] ?? ''),
      'fEstadoAdmin' => $clean($input[$prefix . 'estado_admin'] ?? ''),
      'fOrigen' => $clean($input[$prefix . 'origen'] ?? ''),
      'fBusqueda' => $clean($input[$prefix . 'busqueda'] ?? ''),
      'fInmueble' => $clean($input[$prefix . 'inmueble'] ?? ''),
      'fContrato' => $clean($input[$prefix . 'contrato'] ?? ''),
      'fEmpleado' => $clean($input[$prefix . 'empleado'] ?? ''),
      'fAsunto' => $clean($input[$prefix . 'asunto'] ?? ''),
      'fArrendatario' => $clean($input[$prefix . 'arrendatario'] ?? ''),
      'fPropietario' => $clean($input[$prefix . 'propietario'] ?? ''),
      'fBarrio' => $clean($input[$prefix . 'barrio'] ?? ''),
      'fAseguradora' => $clean($input[$prefix . 'aseguradora'] ?? ''),
      'fConsultorEntrega' => $clean($input[$prefix . 'consultor_entrega'] ?? ''),
      'fFecha' => $singleDate,
      'fFechaDesde' => $dateFrom,
      'fFechaHasta' => $dateTo,
      'fCotizacion' => $clean($input[$prefix . 'cotizacion'] ?? ''),
      'fCotizacionEstado' => $clean($input[$prefix . 'cotizacion_estado'] ?? ''),
      'fCotizacionEnviada' => $clean($input[$prefix . 'cotizacion_enviada'] ?? ''),
      'fRevision' => $clean($input[$prefix . 'revision'] ?? ''),
      'fDanos' => $clean($input[$prefix . 'danos'] ?? ''),
      'fMagnitud' => $clean($input[$prefix . 'magnitud'] ?? ''),
      'fMagnitudCaso' => $clean($input[$prefix . 'magnitud_caso'] ?? ''),
      'fPerturbacion' => $clean($input[$prefix . 'perturbacion'] ?? ''),
      'fAtraso' => $clean($input[$prefix . 'atraso'] ?? ''),
      'fSinActualizar' => $clean($input[$prefix . 'sin_actualizar'] ?? ''),
      'fTuvoSeguimiento' => $clean($input[$prefix . 'tuvo_seguimiento'] ?? ''),
      'fTema' => $clean($input[$prefix . 'tema'] ?? ''),
      'fPage' => max(1, (int) $clean($input[$prefix . 'page'] ?? '1')),
    ];
  }

  public function run_query_generic(array $temas, array $p, array $config = [], bool $includeRows = true): array
  {
    $tabla = $this->db->table('jet_cct_tickets');

    if (!$this->table_exists($tabla) || empty($temas)) {
      return ['rows' => [], 'stats' => ['total' => 0, 'abiertos' => 0, 'cerrados' => 0], 'pagination' => ['page' => 1, 'per_page' => 24, 'total' => 0, 'total_pages' => 1]];
    }

    $where = [];
    $args = [];

    $temaPhs = implode(',', array_fill(0, count($temas), '?'));
    $where[] = "CONVERT(`tema_ayuda` USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$temaPhs})";
    foreach ($temas as $t) {
      $args[] = $t;
    }

    if (!empty($p['fTema'])) {
      $where[] = 'CONVERT(`tema_ayuda` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci';
      $args[] = $p['fTema'];
    }

    $statusBucket = strtolower(trim((string) ($p['_scmStatusBucket'] ?? 'active')));
    if (!in_array($statusBucket, ['active', 'postergados', 'cerrados'], true)) {
      $statusBucket = 'active';
    }

    if ($statusBucket === 'postergados') {
      $col = $this->detect_first_existing_column($tabla, ['estado_administrativo', 'estado_admin_ticket', 'estado_admin']);
      if ($col !== '') {
        $where[] = "LOWER(TRIM(COALESCE(`{$col}`, ''))) = ?";
        $args[] = 'postergado';
        $where[] = "LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN (?, ?, ?)";
        $args[] = 'cerrado';
        $args[] = 'resuelto';
        $args[] = 'finalizado';
      } else {
        $where[] = '1 = 0';
      }
    } elseif ($statusBucket === 'cerrados') {
      $closedValues = ['cerrado', 'resuelto', 'finalizado'];
      $closedWhere = ["LOWER(TRIM(COALESCE(`estado`, ''))) IN (?, ?, ?)"];
      foreach ($closedValues as $value) {
        $args[] = $value;
      }
      $adminCol = $this->detect_first_existing_column($tabla, ['estado_admin_ticket', 'estado_administrativo', 'estado_admin']);
      if ($adminCol !== '') {
        $closedWhere[] = "LOWER(TRIM(COALESCE(`{$adminCol}`, ''))) IN (?, ?, ?)";
        foreach ($closedValues as $value) {
          $args[] = $value;
        }
      }
      $where[] = '(' . implode(' OR ', $closedWhere) . ')';
    } else {
      $where[] = 'LOWER(TRIM(COALESCE(`estado`, \'\'))) IN (?, ?)';
      $args[] = 'nuevo';
      $args[] = 'en proceso';

      if (!empty($p['fEstado'])) {
        $estadoFilter = strtolower(trim((string) $p['fEstado']));
        if (in_array($estadoFilter, ['nuevo', 'en proceso'], true)) {
          $where[] = 'LOWER(TRIM(COALESCE(`estado`, \'\'))) = ?';
          $args[] = $estadoFilter;
        }
      }
      if (!empty($p['fEstadoAdmin'])) {
        $col = $this->detect_first_existing_column($tabla, ['estado_admin_ticket', 'estado_administrativo', 'estado_admin']);
        if ($col !== '') {
          $where[] = "CONVERT(`{$col}` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
          $args[] = $p['fEstadoAdmin'];
        }
      }
    }

    $origenFilter = strtolower(trim((string) ($p['fOrigen'] ?? '')));
    if (in_array($origenFilter, ['web', 'interno'], true)) {
      $webExpr = $this->generic_web_origin_expression($tabla);
      $where[] = $origenFilter === 'web' ? $webExpr : "NOT ({$webExpr})";
    }

    if (!empty($p['fInmueble'])) {
      $term = '%' . $this->db->escapeLike($p['fInmueble']) . '%';
      $parts = [];
      foreach (['id_inmueble', 'inmueble', 'numero_inmueble'] as $candidate) {
        if ($this->column_exists($tabla, $candidate)) {
          $parts[] = "`{$candidate}` LIKE ?";
          $args[] = $term;
        }
      }
      if (!empty($parts)) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
      }
    }

    if (!empty($p['fContrato'])) {
      $col = $this->detect_first_existing_column($tabla, ['id_contrato', 'contrato', 'numero_contrato']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fContrato']) . '%';
      }
    }
    if (!empty($p['fAsunto'])) {
      $col = $this->detect_first_existing_column($tabla, ['asunto', 'descripcion']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fAsunto']) . '%';
      }
    }
    if (!empty($p['fArrendatario'])) {
      $col = $this->detect_first_existing_column($tabla, ['arrendatario', 'nombre_arrendatario', 'nombre']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fArrendatario']) . '%';
      }
    }
    if (!empty($p['fPropietario'])) {
      $col = $this->detect_first_existing_column($tabla, ['propietario', 'nombre_propietario']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fPropietario']) . '%';
      }
    }
    if (!empty($p['fBarrio']) && $this->column_exists($tabla, 'barrio')) {
      $where[] = '`barrio` LIKE ?';
      $args[] = '%' . $this->db->escapeLike($p['fBarrio']) . '%';
    }
    if (!empty($p['fAseguradora'])) {
      $col = $this->detect_first_existing_column($tabla, ['numero_aseguradora', 'aseguradora']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fAseguradora']) . '%';
      }
    }
    if (!empty($p['fConsultorEntrega'])) {
      $col = $this->detect_first_existing_column($tabla, ['consultor_entrega']);
      if ($col !== '') {
        $where[] = "`{$col}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike($p['fConsultorEntrega']) . '%';
      }
    }

    if (!empty($p['fEmpleado'])) {
      $empCols = [];
      foreach (['id_empleado', 'empleado'] as $candidate) {
        if ($this->column_exists($tabla, $candidate)) {
          $empCols[] = $candidate;
        }
      }
      if (!empty($empCols)) {
        $empWhere = [];
        foreach ($empCols as $empCol) {
          $empWhere[] = "TRIM(COALESCE(`{$empCol}`, '')) = ?";
          $args[] = $p['fEmpleado'];
        }
        $where[] = '(' . implode(' OR ', $empWhere) . ')';
      }
    }

    if (!empty($p['fFechaDesde'])) {
      $ts = strtotime($p['fFechaDesde']);
      if ($ts !== false && $ts > 0) {
        $where[] = 'fecha >= ?';
        $args[] = $ts;
      }
    }

    if (!empty($p['fFechaHasta'])) {
      $ts = strtotime($p['fFechaHasta'] . ' 23:59:59');
      if ($ts !== false && $ts > 0) {
        $where[] = 'fecha <= ?';
        $args[] = $ts;
      }
    }
    if (!empty($p['fCotizacion'])) {
      $cotExistsExpr = $this->generic_cotizacion_exists_expression($tabla);
      if ($p['fCotizacion'] === 'has') {
        $where[] = $cotExistsExpr;
      } elseif ($p['fCotizacion'] === 'none') {
        $where[] = "NOT ({$cotExistsExpr})";
      }
    }
    if (!empty($p['fCotizacionEstado']) || !empty($p['fCotizacionEnviada'])) {
      $cotTabla = $this->db->table('jet_cct_cotizacion_mantenimiento');
      if ($this->table_exists($cotTabla) && $this->column_exists($cotTabla, '_ID')) {
        $cotConds = [];
        if (!empty($p['fCotizacionEstado'])) {
          $stateParts = [];
          foreach (['estado', 'estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta'] as $cotStateCol) {
            if ($this->column_exists($cotTabla, $cotStateCol)) {
              $stateParts[] = "LOWER(TRIM(COALESCE(cot_dyn.`{$cotStateCol}`, ''))) = ?";
              $args[] = strtolower(trim((string) $p['fCotizacionEstado']));
            }
          }
          if (!empty($stateParts)) {
            $cotConds[] = '(' . implode(' OR ', $stateParts) . ')';
          }
        }
        if (!empty($p['fCotizacionEnviada'])) {
          $sent = strtolower(trim((string) $p['fCotizacionEnviada']));
          $sentColumns = [];
          foreach (['se_envio', 'fue_enviada_cotizacion_mantenimiento', 'fue_enviada'] as $sentCol) {
            if ($this->column_exists($cotTabla, $sentCol)) {
              $sentColumns[] = $sentCol;
            }
          }
          if (in_array($sent, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true)) {
            $sentParts = [];
            foreach ($sentColumns as $sentCol) {
              $sentParts[] = "LOWER(TRIM(COALESCE(cot_dyn.`{$sentCol}`, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado')";
            }
            if (!empty($sentParts)) {
              $cotConds[] = '(' . implode(' OR ', $sentParts) . ')';
            }
          } elseif (in_array($sent, ['no', '0', 'false', 'none', 'sin'], true)) {
            $sentParts = [];
            foreach ($sentColumns as $sentCol) {
              $sentParts[] = "(TRIM(COALESCE(cot_dyn.`{$sentCol}`, '')) = '' OR LOWER(TRIM(COALESCE(cot_dyn.`{$sentCol}`, ''))) IN ('no', '0', 'false'))";
            }
            if (!empty($sentParts)) {
              $cotConds[] = '(' . implode(' AND ', $sentParts) . ')';
            }
          }
        }
        if (!empty($cotConds)) {
          $joinParts = [];
          $cotCol = $this->detect_first_existing_column($tabla, ['id_cotizacion_mantenimiento', 'id_cotizacion']);
          if ($cotCol !== '') {
            $joinParts[] = "FIND_IN_SET(CAST(cot_dyn._ID AS CHAR), REPLACE(COALESCE(`{$tabla}`.`{$cotCol}`, ''), ' ', '')) > 0";
          }
          if ($this->column_exists($cotTabla, 'id_ticket')) {
            $joinParts[] = "(TRIM(COALESCE(cot_dyn.id_ticket, '')) <> '' AND TRIM(COALESCE(cot_dyn.id_ticket, '')) = CAST(`{$tabla}`.`_ID` AS CHAR))";
            if ($this->column_exists($tabla, 'id_ticket')) {
              $joinParts[] = "(TRIM(COALESCE(`{$tabla}`.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_dyn.id_ticket, '')) <> '' AND TRIM(COALESCE(cot_dyn.id_ticket, '')) = TRIM(COALESCE(`{$tabla}`.`id_ticket`, '')))";
            }
          }
          if (!empty($joinParts)) {
            $where[] = "EXISTS (SELECT 1 FROM `{$cotTabla}` cot_dyn WHERE (" . implode(' OR ', $joinParts) . ') AND ' . implode(' AND ', $cotConds) . ')';
          }
        }
      }
    }
    $magnitudCasoFilter = (string) (!empty($p['fMagnitudCaso']) ? $p['fMagnitudCaso'] : ($p['fMagnitud'] ?? ''));

    if ($magnitudCasoFilter !== '' && $this->column_exists($tabla, 'magnitud_caso')) {
      $where[] = "LOWER(TRIM(COALESCE(`magnitud_caso`, ''))) = ?";
      $args[] = strtolower(trim($magnitudCasoFilter));
    }
    if (!empty($p['fPerturbacion'])) {
      $cotTabla = $this->db->table('jet_cct_cotizacion_mantenimiento');
      if ($this->table_exists($cotTabla)) {
        $cotIdCol = $this->detect_first_existing_column($tabla, ['id_cotizacion_mantenimiento', 'id_cotizacion']);
        if ($this->column_exists($cotTabla, 'perturbacion')) {
          $ticketIdCol = $this->detect_first_existing_column($tabla, ['id_ticket']);
          $perturbacionFilter = strtolower(trim((string) $p['fPerturbacion']));
          $isConFilter = in_array($perturbacionFilter, ['has', 'si', 'sí', 'con'], true);
          $isSinFilter = in_array($perturbacionFilter, ['none', 'no', 'sin'], true);
          $pertExpr = "LOWER(TRIM(COALESCE(cot_filter.perturbacion, '')))";
          $condExpr = '';
          if ($isConFilter) {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) <> '' AND {$pertExpr} NOT IN ('0', 'no', 'false', 'null'))";
          } elseif ($isSinFilter) {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) = '' OR {$pertExpr} IN ('0', 'no', 'false', 'null'))";
          }
          if ($condExpr === '') {
            $condExpr = "(TRIM(COALESCE(cot_filter.perturbacion, '')) <> '')";
          }
          $existsByCotId = '';
          if ($cotIdCol !== '') {
            $existsByCotId = "EXISTS (
              SELECT 1
              FROM `{$cotTabla}` cot_filter
              WHERE CAST(NULLIF(TRIM(COALESCE(`{$tabla}`.`{$cotIdCol}`, '')), '') AS UNSIGNED) = cot_filter._ID
                AND {$condExpr}
            )";
          }
          $existsByTicket = '';
          if ($ticketIdCol !== '' && $this->column_exists($cotTabla, 'id_ticket')) {
            $existsByTicket = "EXISTS (
              SELECT 1
              FROM `{$cotTabla}` cot_filter
              WHERE TRIM(COALESCE(cot_filter.id_ticket, '')) <> ''
                AND TRIM(COALESCE(cot_filter.id_ticket, '')) = TRIM(COALESCE(`{$tabla}`.`{$ticketIdCol}`, ''))
                AND {$condExpr}
            )";
          }
          if ($existsByCotId !== '' && $existsByTicket !== '') {
            $where[] = "({$existsByCotId} OR {$existsByTicket})";
          } elseif ($existsByCotId !== '') {
            $where[] = $existsByCotId;
          } elseif ($existsByTicket !== '') {
            $where[] = $existsByTicket;
          }
        }
      }
    }
    if (!empty($p['fAtraso']) && is_numeric($p['fAtraso'])) {
      $days = max(0, (int) $p['fAtraso']);
      if ($days > 0) {
        $where[] = '(UNIX_TIMESTAMP() - COALESCE(fecha, 0)) >= ?';
        $args[] = $days * 86400;
      }
    }
    if (!empty($p['fSinActualizar']) && is_numeric($p['fSinActualizar'])) {
      $days = max(0, (int) $p['fSinActualizar']);
      if ($days > 0) {
        $where[] = '(UNIX_TIMESTAMP() - COALESCE(NULLIF(fecha_actualizacion, 0), fecha, 0)) >= ?';
        $args[] = $days * 86400;
      }
    }
    if (!empty($p['fTuvoSeguimiento'])) {
      $seg = strtolower(trim((string) $p['fTuvoSeguimiento']));
      if (in_array($seg, ['si', 'no'], true) && $this->column_exists($tabla, 'tuvo_seguimiento')) {
        $where[] = "LOWER(TRIM(COALESCE(`tuvo_seguimiento`, ''))) = ?";
        $args[] = $seg;
      }
    }
    if (!empty($p['fRevision'])) {
      $revisionFilter = strtolower(trim((string) $p['fRevision']));
      $revisionCols = [];
      foreach (['id_revision_preventiva', 'id_revision_correctiva', 'id_revision_entrega', 'id_revision_recibo'] as $rc) {
        if ($this->column_exists($tabla, $rc)) {
          $revisionCols[] = $rc;
        }
      }
      if (!empty($revisionCols)) {
        $parts = [];
        foreach ($revisionCols as $rc) {
          $parts[] = "TRIM(COALESCE(`{$rc}`, '')) <> ''";
        }
        $revHasExpr = '(' . implode(' OR ', $parts) . ')';
        if ($revisionFilter === 'has') {
          $where[] = $revHasExpr;
        } elseif ($revisionFilter === 'none') {
          $where[] = 'NOT ' . $revHasExpr;
        }
      }
    }
    if (!empty($p['fDanos'])) {
      $damageFilter = strtolower(trim((string) $p['fDanos']));
      $damageCond = '';
      if (in_array($damageFilter, ['has', 'si', 'sí', 'con'], true)) {
        $damageCond = "LOWER(TRIM(COALESCE(prev_filter.encontro_danos, ''))) IN ('si', 'sí', '1', 'true', 'yes', 'con danos')";
      } elseif (in_array($damageFilter, ['none', 'no', 'sin'], true)) {
        $damageCond = "LOWER(TRIM(COALESCE(prev_filter.encontro_danos, ''))) IN ('no', '0', 'false', 'sin danos')";
      }

      if ($damageCond !== '') {
        $prevTabla = $this->db->table('jet_cct_revision_preventiva');
        if (
          $this->table_exists($prevTabla)
          && $this->column_exists($tabla, 'id_revision_preventiva')
          && $this->column_exists($prevTabla, '_ID')
          && $this->column_exists($prevTabla, 'encontro_danos')
        ) {
          $where[] = "EXISTS (
            SELECT 1
            FROM `{$prevTabla}` prev_filter
            WHERE FIND_IN_SET(CAST(prev_filter._ID AS CHAR), REPLACE(COALESCE(`{$tabla}`.`id_revision_preventiva`, ''), ' ', '')) > 0
              AND {$damageCond}
          )";
        } elseif ($this->column_exists($tabla, 'se_encontraron_danos')) {
          if (in_array($damageFilter, ['has', 'si', 'sí', 'con'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`se_encontraron_danos`, ''))) IN ('si', 'sí', '1', 'true', 'yes', 'con danos')";
          } elseif (in_array($damageFilter, ['none', 'no', 'sin'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`se_encontraron_danos`, ''))) IN ('no', '0', 'false', 'sin danos')";
          }
        } elseif ($this->column_exists($tabla, 'encontro_danos')) {
          if (in_array($damageFilter, ['has', 'si', 'sí', 'con'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`encontro_danos`, ''))) IN ('si', 'sí', '1', 'true', 'yes', 'con danos')";
          } elseif (in_array($damageFilter, ['none', 'no', 'sin'], true)) {
            $where[] = "LOWER(TRIM(COALESCE(`encontro_danos`, ''))) IN ('no', '0', 'false', 'sin danos')";
          }
        }
      }
    }

    if (!empty($p['fBusqueda'])) {
      $likeVal = '%' . $this->db->escapeLike($p['fBusqueda']) . '%';
      $searchCols = [];
      foreach (['asunto', 'descripcion', 'direccion', 'barrio', 'nombre', 'correo', 'id_ticket'] as $col) {
        if ($this->column_exists($tabla, $col)) {
          $searchCols[] = "`{$col}` LIKE ?";
          $args[] = $likeVal;
        }
      }
      if (!empty($searchCols)) {
        $where[] = '(' . implode(' OR ', $searchCols) . ')';
      }
    }

    $whereStr = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}";
    $total = (int) $this->db->getVar($countSql, $args);

    $revCols = [];
    foreach (['id_revision_preventiva', 'id_revision_correctiva', 'id_revision_entrega', 'id_revision_recibo'] as $rc) {
      if ($this->column_exists($tabla, $rc)) {
        $revCols[] = $rc;
      }
    }
    $revExpr = '0';
    if (!empty($revCols)) {
      $parts = [];
      foreach ($revCols as $rc) {
        $parts[] = "TRIM(COALESCE(`{$rc}`, '')) <> ''";
      }
      $revExpr = '(' . implode(' OR ', $parts) . ')';
    }
    $cotExpr = $this->generic_cotizacion_exists_expression($tabla);
    $staleExpr = "AVG((UNIX_TIMESTAMP() - COALESCE(NULLIF(`fecha_actualizacion`, 0), `fecha`, UNIX_TIMESTAMP())) / 3600)";
    $firstExpr = "AVG((COALESCE(NULLIF(`fecha_actualizacion`, 0), `fecha`) - COALESCE(`fecha`, 0)) / 3600)";
    $magnitudDanoExpr = $this->column_exists($tabla, 'magnitud_caso')
      ? "LOWER(TRIM(COALESCE(`magnitud_caso`, '')))"
      : "''";
    $prevTabla = $this->db->table('jet_cct_revision_preventiva');
    if (
      $this->table_exists($prevTabla)
      && $this->column_exists($tabla, 'id_revision_preventiva')
      && $this->column_exists($prevTabla, '_ID')
      && $this->column_exists($prevTabla, 'encontro_danos')
    ) {
      $danosValueParts = [];
      if ($this->column_exists($tabla, 'se_encontraron_danos')) {
        $danosValueParts[] = "NULLIF(`se_encontraron_danos`, '')";
      }
      if ($this->column_exists($tabla, 'encontro_danos')) {
        $danosValueParts[] = "NULLIF(`encontro_danos`, '')";
      }
      $danosValueParts[] = "(
          SELECT COALESCE(prev_stats.encontro_danos, '')
          FROM `{$prevTabla}` prev_stats
          WHERE FIND_IN_SET(CAST(prev_stats._ID AS CHAR), REPLACE(COALESCE(`{$tabla}`.`id_revision_preventiva`, ''), ' ', '')) > 0
          ORDER BY prev_stats._ID DESC
          LIMIT 1
        )";
      $danosValueParts[] = "''";
      $danosExpr = "LOWER(TRIM(COALESCE(
        " . implode(",\n        ", $danosValueParts) . "
      )))";
    } else {
      $danosExpr = $this->column_exists($tabla, 'se_encontraron_danos')
        ? "LOWER(TRIM(COALESCE(`se_encontraron_danos`, '')))"
        : ($this->column_exists($tabla, 'encontro_danos') ? "LOWER(TRIM(COALESCE(`encontro_danos`, '')))" : "''");
    }
    $revEntregaExpr = $this->column_exists($tabla, 'id_revision_entrega') ? "TRIM(COALESCE(`id_revision_entrega`, '')) <> ''" : '0';
    $invEntregaExpr = $this->column_exists($tabla, 'id_inventario_entrega') ? "TRIM(COALESCE(`id_inventario_entrega`, '')) <> ''" : '0';
    $revReciboExpr = $this->column_exists($tabla, 'id_revision_recibo') ? "TRIM(COALESCE(`id_revision_recibo`, '')) <> ''" : '0';
    $calActTable = 'calendario_actividades';
    $conCitaExpr = $this->table_exists($calActTable)
      ? "EXISTS (SELECT 1 FROM `{$calActTable}` WHERE `id_ticket` = `{$tabla}`.`_ID`)"
      : '0';
    $aggSql = "SELECT
        SUM(CASE WHEN {$cotExpr} THEN 1 ELSE 0 END) AS con_cotizacion,
        SUM(CASE WHEN {$revExpr} THEN 1 ELSE 0 END) AS con_revision,
        SUM(CASE WHEN {$revEntregaExpr} THEN 1 ELSE 0 END) AS con_revision_entrega,
        SUM(CASE WHEN {$invEntregaExpr} THEN 1 ELSE 0 END) AS con_inventario,
        SUM(CASE WHEN {$revReciboExpr} THEN 1 ELSE 0 END) AS con_revision_recibo,
        SUM(CASE WHEN {$conCitaExpr} THEN 1 ELSE 0 END) AS con_cita,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(`estado`, ''))) = 'nuevo' THEN 1 ELSE 0 END) AS estado_nuevo,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(`estado`, ''))) = 'en proceso' THEN 1 ELSE 0 END) AS estado_en_proceso,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'critico' THEN 1 ELSE 0 END) AS magnitud_critico,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'alto' THEN 1 ELSE 0 END) AS magnitud_alto,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'medio' THEN 1 ELSE 0 END) AS magnitud_medio,
        SUM(CASE WHEN {$magnitudDanoExpr} = 'bajo' THEN 1 ELSE 0 END) AS magnitud_bajo,
        SUM(CASE WHEN {$danosExpr} IN ('si', 'sí', '1', 'true', 'yes') THEN 1 ELSE 0 END) AS danos_si,
        SUM(CASE WHEN {$danosExpr} IN ('no', '0', 'false') THEN 1 ELSE 0 END) AS danos_no,
        {$staleExpr} AS avg_stale_h,
        {$firstExpr} AS avg_first_h
      FROM `{$tabla}` WHERE {$whereStr}";
    $agg = $this->db->getRow($aggSql, $args);

    $perPage = max(24, min(100, (int) ($p['fPerPage'] ?? 24)));
    $page = max(1, (int) ($p['fPage'] ?? 1));
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $rows = [];
    if ($includeRows) {
      $sql = "SELECT * FROM `{$tabla}` WHERE {$whereStr} ORDER BY fecha DESC LIMIT ? OFFSET ?";
      $queryArgs = array_merge($args, [$perPage, $offset]);

      $rows = $this->db->getResults($sql, $queryArgs);
      $rows = $this->enrich_ticket_rows_with_related_data($rows);
      $rows = $this->attach_historial_rows($rows);
      $rows = $this->enrich_generic_case_details($rows);
    }

    $conCot = (int) ($agg['con_cotizacion'] ?? 0);
    $conRev = (int) ($agg['con_revision'] ?? 0);
    $conRevEntrega = (int) ($agg['con_revision_entrega'] ?? 0);
    $conInventario = (int) ($agg['con_inventario'] ?? 0);
    $conRevRecibo = (int) ($agg['con_revision_recibo'] ?? 0);
    $conCita = (int) ($agg['con_cita'] ?? 0);
    $estadoNuevo = (int) ($agg['estado_nuevo'] ?? 0);
    $estadoEnProceso = (int) ($agg['estado_en_proceso'] ?? 0);
    $avgStale = isset($agg['avg_stale_h']) ? (float) $agg['avg_stale_h'] : 0.0;
    $avgFirst = isset($agg['avg_first_h']) ? (float) $agg['avg_first_h'] : 0.0;
    $abiertos = $statusBucket === 'cerrados' ? 0 : $total;
    $cerrados = $statusBucket === 'cerrados' ? $total : 0;

    return [
      'rows' => $rows,
      'stats' => [
        'total' => $total,
        'abiertos' => $abiertos,
        'cerrados' => $cerrados,
        'con_cotizacion' => $conCot,
        'sin_cotizacion' => max(0, $total - $conCot),
        'con_revision' => $conRev,
        'sin_revision' => max(0, $total - $conRev),
        'con_revision_entrega' => $conRevEntrega,
        'sin_revision_entrega' => max(0, $total - $conRevEntrega),
        'con_inventario' => $conInventario,
        'sin_inventario' => max(0, $total - $conInventario),
        'con_revision_recibo' => $conRevRecibo,
        'sin_revision_recibo' => max(0, $total - $conRevRecibo),
        'con_cita' => $conCita,
        'sin_cita' => max(0, $total - $conCita),
        'estado_nuevo' => $estadoNuevo,
        'estado_en_proceso' => $estadoEnProceso,
        'avg_first_h' => $avgFirst,
        'avg_stale_h' => $avgStale,
        'magnitud_critico' => (int) ($agg['magnitud_critico'] ?? 0),
        'magnitud_alto' => (int) ($agg['magnitud_alto'] ?? 0),
        'magnitud_medio' => (int) ($agg['magnitud_medio'] ?? 0),
        'magnitud_bajo' => (int) ($agg['magnitud_bajo'] ?? 0),
        'danos_si' => (int) ($agg['danos_si'] ?? 0),
        'danos_no' => (int) ($agg['danos_no'] ?? 0),
      ],
      'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
    ];
  }

  private function generic_web_origin_expression(string $tabla): string
  {
    if (!$this->column_exists($tabla, 'creador_por')) {
      return '0 = 1';
    }
    $creadorExpr = "LOWER(TRIM(COALESCE(`creador_por`, '')))";
    return "({$creadorExpr} <> '' AND {$creadorExpr} <> 'funcionario')";
  }

  private function generic_cotizacion_exists_expression(string $tabla): string
  {
    $cotTabla = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($cotTabla)) {
      return '0 = 1';
    }

    $joinParts = [];
    $cotCol = $this->detect_first_existing_column($tabla, ['id_cotizacion_mantenimiento', 'id_cotizacion']);
    if ($cotCol !== '' && $this->column_exists($cotTabla, '_ID')) {
      $joinParts[] = "FIND_IN_SET(CAST(cot_exists.`_ID` AS CHAR), REPLACE(COALESCE(`{$tabla}`.`{$cotCol}`, ''), ' ', '')) > 0";
    }
    if ($this->column_exists($cotTabla, 'id_ticket')) {
      $joinParts[] = "(TRIM(COALESCE(cot_exists.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_exists.`id_ticket`, '')) = CAST(`{$tabla}`.`_ID` AS CHAR))";
      if ($this->column_exists($tabla, 'id_ticket')) {
        $joinParts[] = "(TRIM(COALESCE(`{$tabla}`.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_exists.`id_ticket`, '')) <> '' AND TRIM(COALESCE(cot_exists.`id_ticket`, '')) = TRIM(COALESCE(`{$tabla}`.`id_ticket`, '')))";
      }
    }

    if (empty($joinParts)) {
      return '0 = 1';
    }

    return "EXISTS (SELECT 1 FROM `{$cotTabla}` cot_exists WHERE " . implode(' OR ', $joinParts) . ')';
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
}
