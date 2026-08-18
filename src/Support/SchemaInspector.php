<?php


namespace SCM\Support;

use SCM\Core\Database;

final class SchemaInspector
{
  private Database $db;

  /** @var array<string,bool> */
  private array $tableExistsCache = [];

  /** @var array<string,bool> */
  private array $columnExistsCache = [];

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  public function tableExists(string $table): bool
  {
    if (array_key_exists($table, $this->tableExistsCache)) {
      return (bool) $this->tableExistsCache[$table];
    }

    $found = $this->db->getVar(
      'SELECT 1
         FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1',
      [$table]
    );
    $ok = !empty($found);

    $this->tableExistsCache[$table] = $ok;
    return $ok;
  }

  public function columnExists(string $table, string $column): bool
  {
    $key = $table . '::' . $column;
    if (array_key_exists($key, $this->columnExistsCache)) {
      return (bool) $this->columnExistsCache[$key];
    }

    if (!$this->tableExists($table)) {
      return false;
    }

    $found = $this->db->getVar(
      'SELECT 1
         FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1',
      [$table, $column]
    );
    $ok = !empty($found);

    $this->columnExistsCache[$key] = $ok;
    return $ok;
  }

  /** @return string[] */
  public function getTableColumns(string $table): array
  {
    if (!$this->tableExists($table)) {
      return [];
    }

    return $this->db->getCol("DESCRIBE `{$table}`", [], 0);
  }

  /** @param array<string,mixed> $data
   *  @return array<string,mixed>
   */
  public function filterTableData(string $table, array $data): array
  {
    $columns = $this->getTableColumns($table);
    if (empty($columns)) {
      return [];
    }

    $filtered = [];
    foreach ($data as $key => $value) {
      if (in_array($key, $columns, true)) {
        $filtered[$key] = $value;
      }
    }

    return $filtered;
  }

  /**
   * @param string[] $candidates
   */
  public function detectFirstExistingColumn(string $table, array $candidates): string
  {
    foreach ($candidates as $candidate) {
      if ($this->columnExists($table, $candidate)) {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Retorna todas las tablas del esquema actual con sus columnas en un mapa.
   * Usa una sola consulta a information_schema para eficiencia.
   *
   * @return array<string, string[]>  ['tabla' => ['col1', 'col2', ...], ...]
   */
  public function getSchemaMap(): array
  {
    $rows = $this->db->getResults(
      'SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, ORDINAL_POSITION',
      []
    );

    $map = [];
    foreach ($rows as $row) {
      $table  = (string) ($row['TABLE_NAME']  ?? '');
      $column = (string) ($row['COLUMN_NAME'] ?? '');
      if ($table !== '' && $column !== '') {
        $map[$table][] = $column;
      }
    }

    return $map;
  }
}
