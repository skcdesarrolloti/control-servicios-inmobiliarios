<?php

declare(strict_types=1);

namespace SCM\Repositories\Concerns;

trait RepositoryUtilitiesConcern
{
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
      if ($activeRaw !== '' && !in_array($activeRaw, ['1', 'si', 'sí', 'true', 'activo', 'active', 'publish'], true)) {
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
