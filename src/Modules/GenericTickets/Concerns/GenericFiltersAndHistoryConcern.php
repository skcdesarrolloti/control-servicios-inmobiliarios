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

trait GenericFiltersAndHistoryConcern
{
  public function get_generic_filter_options(array $temas): array
  {
    $tabla = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($tabla) || empty($temas)) {
      return ['estado_admin' => [], 'cotizacion_estado' => $this->get_cotizacion_estado_options(), 'funcionarios' => [], 'barrios' => $this->get_barrios_filter_options()];
    }

    $cacheKey = md5(json_encode(array_values($temas), JSON_UNESCAPED_UNICODE) ?: implode('|', $temas));
    if (isset($this->genericFilterOptionsCache[$cacheKey])) {
      return $this->genericFilterOptionsCache[$cacheKey];
    }

    $where = [];
    $args = [];
    $temaPhs = implode(',', array_fill(0, count($temas), '?'));
    $where[] = "CONVERT(`tema_ayuda` USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$temaPhs})";
    foreach ($temas as $t) {
      $args[] = $t;
    }
    $where[] = 'LOWER(TRIM(COALESCE(`estado`, \'\'))) IN (?, ?)';
    $args[] = 'nuevo';
    $args[] = 'en proceso';
    $whereStr = implode(' AND ', $where);

    $estadoAdmin = [];
    $adminCol = $this->detect_first_existing_column($tabla, ['estado_admin_ticket', 'estado_administrativo', 'estado_admin']);
    if ($adminCol !== '') {
      $sql = "SELECT DISTINCT `{$adminCol}` AS val FROM `{$tabla}` WHERE {$whereStr} AND TRIM(COALESCE(`{$adminCol}`, '')) <> '' ORDER BY val ASC";
      $rows = $this->db->getResults($sql, $args);
      foreach ($rows as $row) {
        $v = trim((string) ($row['val'] ?? ''));
        if ($v !== '') {
          $estadoAdmin[] = $v;
        }
      }
    }

    $funcionarios = [];
    $funcionariosTable = $this->db->table('jet_cct_funcionarios');
    if ($this->table_exists($funcionariosTable) && $this->column_exists($funcionariosTable, 'id_empleado')) {
      $nameCol = $this->detect_first_existing_column($funcionariosTable, ['nombre', 'empleado', 'nombre_empleado']);
      $nameExpr = $nameCol !== ''
        ? "TRIM(COALESCE(`{$nameCol}`, `id_empleado`, 'Sin nombre'))"
        : "TRIM(COALESCE(`id_empleado`, 'Sin nombre'))";

      $activeFilter = $this->column_exists($funcionariosTable, 'activo')
        ? " AND LOWER(TRIM(COALESCE(`activo`, 'si'))) <> 'no'"
        : '';

      $sql = "
        SELECT DISTINCT
          TRIM(COALESCE(`id_empleado`, '')) AS id,
          {$nameExpr} AS label
        FROM `{$funcionariosTable}`
        WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''{$activeFilter}
        ORDER BY label ASC
      ";
      $rows = $this->db->getResults($sql, []);
      foreach ($rows as $row) {
        $fid = trim((string) ($row['id'] ?? ''));
        if ($fid === '') {
          continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
          $label = $fid;
        }
        $funcionarios[] = ['id' => $fid, 'label' => $label . ' (' . $fid . ')'];
      }
    }

    $out = ['estado_admin' => $estadoAdmin, 'cotizacion_estado' => $this->get_cotizacion_estado_options(), 'funcionarios' => $funcionarios, 'barrios' => $this->get_barrios_filter_options()];
    $this->genericFilterOptionsCache[$cacheKey] = $out;
    return $out;
  }

  /** @return array<int,string> */
  private function get_cotizacion_estado_options(): array
  {
    $cotTable = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->table_exists($cotTable)) {
      return [];
    }

    $parts = [];
    foreach (['estado', 'estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta'] as $column) {
      if ($this->column_exists($cotTable, $column)) {
        $parts[] = "SELECT TRIM(COALESCE(`{$column}`, '')) AS estado FROM `{$cotTable}`";
      }
    }
    if (empty($parts)) {
      return [];
    }
    $rows = $this->db->getCol(
      'SELECT DISTINCT estado FROM (' . implode(' UNION ALL ', $parts) . ") AS scm_cot_estados WHERE estado <> '' ORDER BY estado ASC LIMIT 160"
    );
    return $this->unique_non_empty_values($rows);
  }

  /** @return array<int,string> */
  private function get_barrios_filter_options(): array
  {
    $barriosTable = $this->db->table('jet_cct_barrios');
    if ($this->table_exists($barriosTable) && $this->column_exists($barriosTable, 'barrio')) {
      $rows = $this->db->getCol(
        "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio FROM `{$barriosTable}` WHERE TRIM(COALESCE(`barrio`, '')) <> '' ORDER BY barrio ASC LIMIT 500"
      );
      return $this->unique_non_empty_values($rows);
    }

    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->table_exists($ticketsTable) || !$this->column_exists($ticketsTable, 'barrio')) {
      return [];
    }

    $rows = $this->db->getCol(
      "SELECT DISTINCT TRIM(COALESCE(`barrio`, '')) AS barrio FROM `{$ticketsTable}` WHERE TRIM(COALESCE(`barrio`, '')) <> '' ORDER BY barrio ASC LIMIT 500"
    );
    return $this->unique_non_empty_values($rows);
  }

  /** @param array<int,mixed> $items @return array<int,string> */
  private function unique_non_empty_values(array $items): array
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

  /** @param array<int,array<string,mixed>> $rows
   *  @return array<int,array<string,mixed>>
   */
  private function attach_historial_rows(array $rows): array
  {
    if (empty($rows)) {
      return $rows;
    }

    $keys = [];
    foreach ($rows as $row) {
      $pk = (int) ($row['_ID'] ?? 0);
      if ($pk > 0) {
        $keys[(string) $pk] = true;
      }
      $logical = trim((string) ($row['id_ticket'] ?? ''));
      if ($logical !== '') {
        $keys[$logical] = true;
      }
    }
    if (empty($keys)) {
      return $rows;
    }

    $kVals = array_keys($keys);
    $histTable = $this->db->table('jet_cct_historial_del_ticket');
    $items = $this->fetch_history_items_by_keys($histTable, $kVals);

    $map = [];
    foreach ($items as $item) {
      $itemKeys = is_array($item['_scm_ticket_keys'] ?? null) ? $item['_scm_ticket_keys'] : [];
      if (empty($itemKeys)) {
        continue;
      }
      foreach ($itemKeys as $k) {
        $map[$k][] = $item;
      }
    }

    foreach ($map as &$list) {
      usort(
        $list,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );
    }
    unset($list);

    foreach ($rows as &$row) {
      $bucket = [];
      $localKeys = [];
      $pk = (int) ($row['_ID'] ?? 0);
      if ($pk > 0) {
        $localKeys[] = (string) $pk;
      }
      $logical = trim((string) ($row['id_ticket'] ?? ''));
      if ($logical !== '') {
        $localKeys[] = $logical;
      }
      $localKeys = array_values(array_unique($localKeys));

      foreach ($localKeys as $k) {
        if (!empty($map[$k])) {
          $bucket = array_merge($bucket, $map[$k]);
        }
      }

      usort(
        $bucket,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );

      $inlineItems = $this->build_ticket_inline_history_items($row);
      foreach ($inlineItems as $inlineItem) {
        $bucket[] = $inlineItem;
      }
      usort(
        $bucket,
        static function (array $a, array $b): int {
          return (int) ($b['_scm_ts'] ?? 0) <=> (int) ($a['_scm_ts'] ?? 0);
        }
      );

      $row['_scm_historial_items'] = $bucket;
    }
    unset($row);

    return $rows;
  }

  /** @return array<int,string> */
  private function detect_history_ticket_columns(string $histTable): array
  {
    $cols = [];
    foreach (['id_ticket', 'ticket_id', 'id_tickets', 'tickets_id', 'id_ticket_mantenimiento', 'ticket', 'ticket_pk'] as $candidate) {
      if ($this->column_exists($histTable, $candidate)) {
        $cols[] = $candidate;
      }
    }
    return $cols;
  }

  /**
   * @param array<int,string> $keys
   * @return array<int,array<string,mixed>>
   */
  private function fetch_history_items_by_keys(string $histTable, array $keys): array
  {
    if (empty($keys) || !$this->table_exists($histTable)) {
      return [];
    }

    $ticketCols = $this->detect_history_ticket_columns($histTable);
    if (empty($ticketCols)) {
      return [];
    }

    $wanted = [
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
    foreach ($wanted as $c) {
      if ($this->column_exists($histTable, $c)) {
        $selectCols[] = $c;
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

    $ph = implode(',', array_fill(0, count($keys), 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci'));
    $select = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $selectCols));
    $whereParts = [];
    $queryArgs = [];
    foreach ($ticketCols as $ticketCol) {
      $whereParts[] = "CONVERT(TRIM(COALESCE(`{$ticketCol}`, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ({$ph})";
      foreach ($keys as $key) {
        $queryArgs[] = $key;
      }
    }
    $sql = "SELECT {$select} FROM `{$histTable}` WHERE " . implode(' OR ', $whereParts);
    $items = $this->db->getResults($sql, $queryArgs);

    $out = [];
    foreach ($items as $item) {
      $itemKeys = [];
      foreach ($ticketCols as $ticketCol) {
        $key = trim((string) ($item[$ticketCol] ?? ''));
        if ($key !== '') {
          $itemKeys[$key] = true;
        }
      }
      if (empty($itemKeys)) {
        continue;
      }
      $item['_scm_ticket_keys'] = array_keys($itemKeys);
      $item['_scm_ts'] = $this->parse_unix_ts($item['cct_created'] ?? $item['fecha'] ?? $item['cct_modified'] ?? '');
      $out[] = $item;
    }

    return $out;
  }

  /** @param array<string,mixed> $row
   *  @return array<string,mixed>
   */
  /** @return array<int,array<string,mixed>> */
  private function build_ticket_inline_history_items(array $row): array
  {
    $author = trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? ''));
    if ($author === '') {
      $author = 'Ticket';
    }

    $ts = $this->parse_unix_ts($row['fecha_actualizacion'] ?? $row['fecha'] ?? '');

    $items = [];
    $add = function (string $text, string $kind) use (&$items, $author, $ts): void {
      $clean = trim($text);
      if ($clean === '') {
        return;
      }
      $items[] = [
        '_ID' => '',
        'nombre' => $author,
        'respuesta' => $clean,
        '_scm_ts' => $ts,
        '_scm_kind' => $kind,
      ];
    };

    $add((string) ($row['respuesta'] ?? $row['observacion'] ?? $row['respuesta_ticket'] ?? $row['detalle_respuesta'] ?? ''), 'respuesta');
    $add((string) ($row['seguimiento_mantenimiento'] ?? ''), 'seguimiento');

    return $items;
  }
}
