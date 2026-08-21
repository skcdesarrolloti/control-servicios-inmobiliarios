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

trait PreventiveQueryConcern
{
  public function run_query_preventiva(array $p): array
  {
    $tabla    = $this->db->table('jet_cct_revision_preventiva');
    $cotTabla = $this->db->table('jet_cct_cotizacion_mantenimiento');

    $empty = [
      'rows' => [],
      'stats' => ['total' => 0, 'abiertos' => 0, 'cerrados' => 0, 'con_cotizacion' => 0, 'sin_cotizacion' => 0, 'con_revision' => 0, 'sin_revision' => 0],
      'pagination' => ['page' => 1, 'per_page' => 24, 'total' => 0, 'total_pages' => 1],
    ];

    if (!$this->table_exists($tabla)) {
      return $empty;
    }

    $where = [];
    $args  = [];

    if (!empty($p['fBusqueda'])) {
      $likeVal = '%' . $this->db->escapeLike($p['fBusqueda']) . '%';
      $searchParts = [];
      foreach (['inmueble', 'contrato', 'propietario', 'arrendatario', 'direccion', 'id_ticket'] as $col) {
        if ($this->column_exists($tabla, $col)) {
          $searchParts[] = "`{$col}` LIKE ?";
          $args[] = $likeVal;
        }
      }
      if (!empty($searchParts)) {
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
      }
    }

    if (!empty($p['fInmueble'])) {
      $where[] = '`inmueble` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fInmueble']) . '%';
    }
    if (!empty($p['fCaso'])) {
      $term = '%' . $this->db->escapeLike((string) $p['fCaso']) . '%';
      $caseParts = [];
      foreach (['_ID', 'id_ticket', 'ticket_id', 'numero_ticket'] as $candidate) {
        if ($this->column_exists($tabla, $candidate)) {
          $caseParts[] = "CAST(`{$candidate}` AS CHAR) LIKE ?";
          $args[] = $term;
        }
      }
      if (!empty($caseParts)) {
        $where[] = '(' . implode(' OR ', $caseParts) . ')';
      }
    }
    if (!empty($p['fContrato'])) {
      $term = '%' . $this->db->escapeLike((string) $p['fContrato']) . '%';
      if ($this->column_exists($tabla, 'contrato')) {
        $where[] = 'CAST(`contrato` AS CHAR) LIKE ?';
        $args[] = $term;
      }
    }
    if (!empty($p['fArrendatario'])) {
      $where[] = '`arrendatario` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fArrendatario']) . '%';
    }
    if (!empty($p['fPropietario'])) {
      $where[] = '`propietario` LIKE ?';
      $args[]  = '%' . $this->db->escapeLike($p['fPropietario']) . '%';
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
          $args[]  = $p['fEmpleado'];
        }
        $where[] = '(' . implode(' OR ', $empWhere) . ')';
      }
    }
    if (!empty($p['fEstado'])) {
      $where[] = "LOWER(TRIM(COALESCE(`cct_status`, ''))) = ?";
      $args[]  = strtolower(trim((string) $p['fEstado']));
    }
    $hasFechaColumn = $this->column_exists($tabla, 'fecha');
    if (!empty($p['fFecha'])) {
      $tsStart = strtotime((string) $p['fFecha'] . ' 00:00:00');
      $tsEnd = strtotime((string) $p['fFecha'] . ' 23:59:59');
      if ($tsStart !== false && $tsEnd !== false) {
        if ($hasFechaColumn) {
          $where[] = 'COALESCE(`fecha`, 0) BETWEEN ? AND ?';
          $args[] = (int) $tsStart;
          $args[] = (int) $tsEnd;
        } else {
          $where[] = '`cct_created` BETWEEN ? AND ?';
          $args[]  = $p['fFecha'] . ' 00:00:00';
          $args[]  = $p['fFecha'] . ' 23:59:59';
        }
      }
    } else {
      if (!empty($p['fFechaDesde'])) {
        if ($hasFechaColumn) {
          $ts = strtotime((string) $p['fFechaDesde'] . ' 00:00:00');
          if ($ts !== false) {
            $where[] = 'COALESCE(`fecha`, 0) >= ?';
            $args[]  = (int) $ts;
          }
        } else {
          $where[] = '`cct_created` >= ?';
          $args[]  = $p['fFechaDesde'] . ' 00:00:00';
        }
      }
      if (!empty($p['fFechaHasta'])) {
        if ($hasFechaColumn) {
          $ts = strtotime((string) $p['fFechaHasta'] . ' 23:59:59');
          if ($ts !== false) {
            $where[] = 'COALESCE(`fecha`, 0) <= ?';
            $args[]  = (int) $ts;
          }
        } else {
          $where[] = '`cct_created` <= ?';
          $args[]  = $p['fFechaHasta'] . ' 23:59:59';
        }
      }
    }
    if (!empty($p['fCotizacion']) && $this->table_exists($cotTabla) && $this->column_exists($cotTabla, 'id_ticket') && $this->column_exists($tabla, 'id_ticket')) {
      $cotExists = "EXISTS (
        SELECT 1
        FROM `{$cotTabla}` cot_has
        WHERE TRIM(COALESCE(cot_has.id_ticket, '')) = TRIM(COALESCE(`{$tabla}`.`id_ticket`, ''))
      )";
      if ($p['fCotizacion'] === 'has') {
        $where[] = $cotExists;
      } elseif ($p['fCotizacion'] === 'none') {
        $where[] = 'NOT ' . $cotExists;
      }
    }
    if ((!empty($p['fCotizacionEstado']) || !empty($p['fCotizacionEnviada'])) && $this->table_exists($cotTabla) && $this->column_exists($cotTabla, 'id_ticket')) {
      $cotConds = [];
      if (!empty($p['fCotizacionEstado']) && $this->column_exists($cotTabla, 'estado')) {
        $cotConds[] = "LOWER(TRIM(COALESCE(cot_dyn.estado, ''))) = ?";
        $args[] = strtolower(trim((string) $p['fCotizacionEstado']));
      }
      if (!empty($p['fCotizacionEnviada']) && $this->column_exists($cotTabla, 'se_envio')) {
        $sent = strtolower(trim((string) $p['fCotizacionEnviada']));
        if (in_array($sent, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true)) {
          $cotConds[] = "LOWER(TRIM(COALESCE(cot_dyn.se_envio, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado')";
        } elseif (in_array($sent, ['no', '0', 'false', 'none', 'sin'], true)) {
          $cotConds[] = "(TRIM(COALESCE(cot_dyn.se_envio, '')) = '' OR LOWER(TRIM(COALESCE(cot_dyn.se_envio, ''))) IN ('no', '0', 'false'))";
        }
      }
      if (!empty($cotConds) && $this->column_exists($tabla, 'id_ticket')) {
        $where[] = "EXISTS (
          SELECT 1
          FROM `{$cotTabla}` cot_dyn
          WHERE TRIM(COALESCE(cot_dyn.id_ticket, '')) = TRIM(COALESCE(`{$tabla}`.`id_ticket`, ''))
            AND " . implode(' AND ', $cotConds) . '
        )';
      }
    }

    $whereStr = empty($where) ? '1=1' : implode(' AND ', $where);

    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}", $args);

    $damageVals = ['si', '1', 'true', 'con danos'];
    $damagePhs  = implode(',', array_fill(0, count($damageVals), '?'));
    $conDanosSql = "SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}"
      . " AND (LOWER(TRIM(COALESCE(`encontro_danos`,''))) IN ({$damagePhs})"
      . " OR LOWER(TRIM(COALESCE(`tiene_cotizacion`,''))) IN ({$damagePhs}))";
    $conDanosArgs = array_merge($args, $damageVals, $damageVals);
    $conDanos = (int) $this->db->getVar($conDanosSql, $conDanosArgs);

    $enviadaVals = ['si', '1', 'true', 'enviada'];
    $enviadaPhs  = implode(',', array_fill(0, count($enviadaVals), '?'));
    $conEnviadaSql = "SELECT COUNT(*) FROM `{$tabla}` WHERE {$whereStr}"
      . " AND LOWER(TRIM(COALESCE(`se_envio`,''))) IN ({$enviadaPhs})";
    $conEnviada = (int) $this->db->getVar($conEnviadaSql, array_merge($args, $enviadaVals));

    $perPage    = max(24, min(100, (int) ($p['fPerPage'] ?? 24)));
    $page       = max(1, (int) ($p['fPage'] ?? 1));
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT *,
        `_ID`          AS id_revision_preventiva,
        UNIX_TIMESTAMP(`cct_created`) AS fecha,
        UNIX_TIMESTAMP(`cct_created`) AS fecha_actualizacion,
        `cct_status`   AS estado,
        `cct_status`   AS asunto,
        'Revision Preventiva' AS tema_ayuda,
        CASE
          WHEN LOWER(TRIM(COALESCE(`tiene_cotizacion`,''))) IN ('si','1','true','con danos')
            OR LOWER(TRIM(COALESCE(`encontro_danos`,''))) IN ('si','1','true','con danos')
          THEN 'Si' ELSE COALESCE(`encontro_danos`,'')
        END AS se_encontraron_danos
      FROM `{$tabla}` WHERE {$whereStr}
      ORDER BY `cct_created` DESC LIMIT ? OFFSET ?";
    $rows = $this->db->getResults($sql, array_merge($args, [$perPage, $offset]));

    if (!empty($rows) && $this->table_exists($cotTabla)) {
      $rows = $this->enrich_preventiva_rows_with_cotizacion($rows, $cotTabla);
    } else {
      foreach ($rows as &$row) {
        $row['ticket_cct_created'] = '';
        $row['cita_fecha_inicio'] = '';
        $row['cita_evento_id'] = '';
        $row['id_cita_agendada'] = '';
        $row['prev_id'] = (string) ($row['_ID'] ?? '');
        $row['id_revision_preventiva'] = (string) ($row['_ID'] ?? '');
        $row['prev_fecha_registro'] = '';
        $row['prev_fecha_envio'] = '';
        $row['prev_se_envio'] = '';
        $row['prev_encontro_danos'] = '';
        $row['cot_id'] = '';
        $row['id_cotizacion_mantenimiento'] = '';
        $row['id_cotizacion'] = '';
        $row['cot_fecha_registro'] = '';
        $row['cot_fecha_envio'] = '';
        $row['cot_fecha_respuesta'] = '';
        $row['cot_estado'] = '';
        $row['acta_id'] = '';
        $row['id_acta_satisfaccion'] = '';
        $row['acta_fecha_creada'] = '';
      }
      unset($row);
    }

    $rows = $this->attach_historial_rows($rows);

    return [
      'rows' => $rows,
      'stats' => [
        'total'          => $total,
        'abiertos'       => $total,
        'cerrados'       => 0,
        'con_cotizacion' => $conDanos,
        'sin_cotizacion' => max(0, $total - $conDanos),
        'con_revision'   => $conEnviada,
        'sin_revision'   => max(0, $total - $conEnviada),
      ],
      'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
      ],
    ];
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @return array<int,array<string,mixed>>
   */
  private function enrich_preventiva_rows_with_cotizacion(array $rows, string $cotTabla): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    $calendarTable = 'calendario_actividades';
    $actaTable = $this->db->table('jet_cct_actas_de_satisfaccion');

    foreach ($rows as &$row) {
      $row['ticket_cct_created'] = '';
      $row['cita_fecha_inicio'] = '';
      $row['cita_evento_id'] = '';
      $row['id_cita_agendada'] = '';
      $row['prev_id'] = (string) ($row['_ID'] ?? '');
      $row['id_revision_preventiva'] = (string) ($row['_ID'] ?? '');
      $row['prev_fecha_registro'] = '';
      $row['prev_fecha_envio'] = '';
      $row['prev_se_envio'] = '';
      $row['prev_encontro_danos'] = '';
      $row['cot_id'] = '';
      $row['id_cotizacion_mantenimiento'] = '';
      $row['id_cotizacion'] = '';
      $row['cot_fecha_registro'] = '';
      $row['cot_fecha_envio'] = '';
      $row['cot_fecha_respuesta'] = '';
      $row['cot_estado'] = '';
      $row['acta_id'] = '';
      $row['id_acta_satisfaccion'] = '';
      $row['acta_fecha_creada'] = '';
    }
    unset($row);

    $ticketIds = [];
    foreach ($rows as $row) {
      $tid = trim((string) ($row['id_ticket'] ?? ''));
      if ($tid !== '') {
        $ticketIds[$tid] = true;
      }
    }
    if (empty($ticketIds)) {
      return $rows;
    }

    $ids = array_keys($ticketIds);
    $phs = implode(',', array_fill(0, count($ids), '?'));

    $ticketMap = [];
    if ($this->table_exists($ticketsTable)) {
      $ticketRows = $this->db->getResults(
        "SELECT `_ID`, `cct_created`, `id_empleado`, `empleado`, `nombre_empleado` FROM `{$ticketsTable}` WHERE `_ID` IN ({$phs})",
        $ids
      );
      foreach ($ticketRows as $tRow) {
        $tid = trim((string) ($tRow['_ID'] ?? ''));
        if ($tid !== '') {
          $ticketMap[$tid] = $tRow;
        }
      }
    }

    $calendarMap = [];
    if ($this->table_exists($calendarTable) && $this->column_exists($calendarTable, 'id_ticket')) {
      $calendarRows = $this->db->getResults(
        "SELECT `id`, `id_ticket`, `fecha_inicio` FROM `{$calendarTable}` WHERE `id_ticket` IN ({$phs}) ORDER BY `id` DESC",
        $ids
      );
      foreach ($calendarRows as $calRow) {
        $tid = trim((string) ($calRow['id_ticket'] ?? ''));
        if ($tid !== '' && !isset($calendarMap[$tid])) {
          $calendarMap[$tid] = $calRow;
        }
      }
    }

    $cotMap = [];
    if ($this->table_exists($cotTabla) && $this->column_exists($cotTabla, 'id_ticket')) {
      $cotRows = $this->db->getResults(
        "SELECT `_ID`, `id_ticket`, `cct_created`, `fecha_envio`, `fecha_respuesta`, `estado`, `se_envio`
         FROM `{$cotTabla}`
         WHERE `id_ticket` IN ({$phs})
         ORDER BY `_ID` DESC",
        $ids
      );
      foreach ($cotRows as $cRow) {
        $tid = trim((string) ($cRow['id_ticket'] ?? ''));
        if ($tid !== '' && !isset($cotMap[$tid])) {
          $cotMap[$tid] = $cRow;
        }
      }
    }

    $actaMap = [];
    if ($this->table_exists($actaTable) && $this->column_exists($actaTable, 'id_ticket')) {
      $actaRows = $this->db->getResults(
        "SELECT `_ID`, `id_ticket`, `cct_created`
         FROM `{$actaTable}`
         WHERE `id_ticket` IN ({$phs})
         ORDER BY `_ID` DESC",
        $ids
      );
      foreach ($actaRows as $aRow) {
        $tid = trim((string) ($aRow['id_ticket'] ?? ''));
        if ($tid !== '' && !isset($actaMap[$tid])) {
          $actaMap[$tid] = $aRow;
        }
      }
    }

    foreach ($rows as &$row) {
      $tid = trim((string) ($row['id_ticket'] ?? ''));
      if ($tid === '') {
        continue;
      }

      if (isset($ticketMap[$tid])) {
        $ts = $this->parse_unix_ts((string) ($ticketMap[$tid]['cct_created'] ?? ''));
        if ($ts > 0) {
          $row['ticket_cct_created'] = (string) $ts;
        }

        // Mantener el mismo comportamiento visual de Asignado a que en mantenimiento.
        if (trim((string) ($row['id_empleado'] ?? '')) === '') {
          $row['id_empleado'] = trim((string) ($ticketMap[$tid]['id_empleado'] ?? ''));
        }
        if (trim((string) ($row['empleado'] ?? '')) === '') {
          $row['empleado'] = trim((string) ($ticketMap[$tid]['empleado'] ?? ''));
        }
        if (trim((string) ($row['nombre_empleado'] ?? '')) === '') {
          $row['nombre_empleado'] = trim((string) ($ticketMap[$tid]['nombre_empleado'] ?? ''));
        }
      }

      $prevRegTs = $this->parse_unix_ts((string) ($row['cct_created'] ?? ''));
      if ($prevRegTs > 0) {
        $row['prev_fecha_registro'] = (string) $prevRegTs;
      }

      $prevEnvTs = $this->parse_unix_ts((string) ($row['fecha_envio'] ?? ''));
      if ($prevEnvTs > 0) {
        $row['prev_fecha_envio'] = (string) $prevEnvTs;
      }

      $row['prev_se_envio'] = trim((string) ($row['se_envio'] ?? ''));
      $row['prev_encontro_danos'] = trim((string) ($row['encontro_danos'] ?? ''));

      if (isset($calendarMap[$tid])) {
        $calendar = $calendarMap[$tid];
        $row['cita_evento_id'] = trim((string) ($calendar['id'] ?? ''));
        $row['id_cita_agendada'] = $row['cita_evento_id'];
        $ts = $this->parse_unix_ts((string) ($calendar['fecha_inicio'] ?? ''));
        if ($ts > 0) {
          $row['cita_fecha_inicio'] = (string) $ts;
        }
      }

      if (isset($cotMap[$tid])) {
        $cot = $cotMap[$tid];
        $row['cot_id'] = trim((string) ($cot['_ID'] ?? ''));
        $row['id_cotizacion_mantenimiento'] = $row['cot_id'];
        $row['id_cotizacion'] = $row['cot_id'];
        $row['cot_estado'] = trim((string) ($cot['estado'] ?? ''));

        $ts = $this->parse_unix_ts((string) ($cot['cct_created'] ?? ''));
        if ($ts > 0) {
          $row['cot_fecha_registro'] = (string) $ts;
        }

        $seEnvio = strtolower(trim((string) ($cot['se_envio'] ?? '')));
        if (in_array($seEnvio, ['si', 'sí', '1', 'true'], true)) {
          $ts = $this->parse_unix_ts((string) ($cot['fecha_envio'] ?? ''));
          if ($ts > 0) {
            $row['cot_fecha_envio'] = (string) $ts;
          }
        }

        $ts = $this->parse_unix_ts((string) ($cot['fecha_respuesta'] ?? ''));
        if ($ts > 0) {
          $row['cot_fecha_respuesta'] = (string) $ts;
        }
      }

      if (isset($actaMap[$tid])) {
        $acta = $actaMap[$tid];
        $row['acta_id'] = trim((string) ($acta['_ID'] ?? ''));
        $ts = $this->parse_unix_ts((string) ($acta['cct_created'] ?? ''));
        $row['id_acta_satisfaccion'] = $row['acta_id'];
        if ($ts > 0) {
          $row['acta_fecha_creada'] = (string) $ts;
        }
      }
    }
    unset($row);

    return $rows;
  }
}
