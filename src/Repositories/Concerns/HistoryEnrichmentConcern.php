<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait HistoryEnrichmentConcern
{
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
}
