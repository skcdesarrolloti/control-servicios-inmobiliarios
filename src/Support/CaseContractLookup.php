<?php

declare(strict_types=1);

namespace SCM\Support;

final class CaseContractLookup
{
  /**
   * Keep CCT primary keys separate from visible contract numbers: both can
   * contain the same value while identifying different contracts.
   *
   * @param array<int,array<string,mixed>> $rows
   * @param callable(string,array):array $fetch Fetch records keyed by one column.
   * @return array<string,array<string,mixed>>
   */
  public static function load(array $rows, callable $fetch): array
  {
    $ids = ['_ID' => [], 'contrato' => []];
    foreach ($rows as $row) {
      [$column, $value] = self::reference($row);
      if ($value !== '') {
        $ids[$column][$value] = true;
      }
    }

    $records = [];
    foreach ($ids as $column => $values) {
      if (empty($values)) {
        continue;
      }
      foreach ($fetch($column, array_keys($values)) as $value => $record) {
        $records[$column . ':' . $value] = $record;
      }
    }
    return $records;
  }

  /** @param array<string,mixed> $row @param array<string,array<string,mixed>> $records */
  public static function resolve(array $row, array $records): array
  {
    [$column, $value] = self::reference($row);
    return $value !== '' ? ($records[$column . ':' . $value] ?? []) : [];
  }

  /** @param array<string,mixed> $row @return array{string,string} */
  private static function reference(array $row): array
  {
    foreach (['id_contrato' => '_ID', 'contrato' => 'contrato'] as $field => $column) {
      $parts = preg_split('/[,|;]+/', (string) ($row[$field] ?? '')) ?: [];
      foreach ($parts as $part) {
        $value = trim($part);
        if ($value !== '') {
          return [$column, $value];
        }
      }
    }
    return ['_ID', ''];
  }
}
