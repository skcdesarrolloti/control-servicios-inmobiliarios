<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * SQL compartido para "¿Quién ejecuta?" (`ejecutado`) en las pestañas de casos.
 *
 * El valor del caso manda; si está vacío se usa el de la cotización más reciente con ejecutor
 * (flujos JetForm antiguos no lo copiaban al caso). Ver docs/ejecutado-caso.md.
 */
final class CaseExecutorSql
{
  /** Claves normalizadas en el orden de las tarjetas KPI. */
  public const KEYS = ['inmobiliaria', 'propietario', 'arrendatario', 'copropiedad', 'externo', 'sin_definir'];

  public const LABELS = [
    'inmobiliaria' => 'Inmobiliaria',
    'propietario' => 'Propietario',
    'arrendatario' => 'Arrendatario',
    'copropiedad' => 'Copropiedad',
    'externo' => 'Externo',
    'sin_definir' => 'Sin definir',
  ];

  public static function isKey(string $value): bool
  {
    return in_array($value, self::KEYS, true);
  }

  /**
   * @param callable(string,string):bool $columnExists Verifica tabla/columna (la tabla inexistente devuelve false).
   * @param string $ticketRef Referencia SQL de la fila del caso: alias (`t`) o tabla entre comillas.
   * @param string|null $quoteExecutorSql Expresión ya resuelta del ejecutor de la última cotización
   *   (p. ej. desde countsJoin()); null usa una subconsulta correlacionada.
   */
  public static function keyExpression(callable $columnExists, string $ticketsTable, string $quoteTable, string $ticketRef, ?string $quoteExecutorSql = null): string
  {
    $sources = [];
    if ($columnExists($ticketsTable, 'ejecutado')) {
      $sources[] = "NULLIF(TRIM(COALESCE({$ticketRef}.`ejecutado`, '')), '')";
    }
    if ($quoteExecutorSql !== null) {
      $sources[] = "NULLIF(TRIM(COALESCE({$quoteExecutorSql}, '')), '')";
    } elseif (self::quoteAvailable($columnExists, $quoteTable)) {
      $sources[] = "(SELECT TRIM(cot_exec.`ejecutado`) FROM `{$quoteTable}` cot_exec"
        . " WHERE TRIM(COALESCE(cot_exec.`id_ticket`, '')) = CAST({$ticketRef}.`_ID` AS CHAR)"
        . " AND TRIM(COALESCE(cot_exec.`ejecutado`, '')) <> ''"
        . ' ORDER BY cot_exec.`_ID` DESC LIMIT 1)';
    }
    if ($sources === []) {
      return "'sin_definir'";
    }
    $raw = 'LOWER(COALESCE(' . implode(', ', $sources) . ", ''))";
    return "(CASE WHEN {$raw} LIKE '%propiet%' THEN 'propietario'"
      . " WHEN {$raw} LIKE '%arrend%' THEN 'arrendatario'"
      . " WHEN {$raw} LIKE '%coprop%' THEN 'copropiedad'"
      . " WHEN {$raw} LIKE '%inmobil%' THEN 'inmobiliaria'"
      . " WHEN {$raw} LIKE '%extern%' THEN 'externo'"
      . " ELSE 'sin_definir' END)";
  }

  /** Condición de filtro; la clave va en línea (lista blanca) porque un parámetro mezcla collations en MySQL. */
  public static function filterCondition(callable $columnExists, string $ticketsTable, string $quoteTable, string $ticketRef, string $key): string
  {
    if (!self::isKey($key)) {
      return '1 = 1';
    }
    return self::keyExpression($columnExists, $ticketsTable, $quoteTable, $ticketRef) . " = '{$key}'";
  }

  /**
   * Cuenta casos por ejecutor uniendo una sola vez la última cotización con ejecutor por caso.
   * El JOIN solo aporta `exec_latest.ticket_ref`/`quote_id`, así no choca con columnas sin calificar del WHERE.
   *
   * @param array<int,mixed> $args
   * @return array<string,int> ['ejecuta_<clave>' => total]
   */
  public static function counts(\SCM\Core\Database $db, callable $columnExists, string $ticketsTable, string $quoteTable, string $ticketRef, string $fromSql, string $whereSql, array $args): array
  {
    $join = '';
    $quoteExecutorSql = null;
    if (self::quoteAvailable($columnExists, $quoteTable)) {
      $join = " LEFT JOIN (SELECT TRIM(COALESCE(q.`id_ticket`, '')) AS ticket_ref, MAX(q.`_ID`) AS quote_id FROM `{$quoteTable}` q"
        . " WHERE TRIM(COALESCE(q.`ejecutado`, '')) <> '' GROUP BY TRIM(COALESCE(q.`id_ticket`, ''))) exec_latest"
        . " ON exec_latest.ticket_ref = CAST({$ticketRef}.`_ID` AS CHAR)";
      $quoteExecutorSql = "(SELECT exec_quote.`ejecutado` FROM `{$quoteTable}` exec_quote WHERE exec_quote.`_ID` = exec_latest.quote_id)";
    }
    $sql = 'SELECT exec_key, COUNT(1) AS total FROM (SELECT '
      . self::keyExpression($columnExists, $ticketsTable, $quoteTable, $ticketRef, $quoteExecutorSql)
      . " AS exec_key FROM {$fromSql}{$join} WHERE {$whereSql}) executor_rows GROUP BY exec_key";
    $counts = array_fill_keys(self::KEYS, 0);
    foreach ($db->getResults($sql, $args) as $row) {
      $key = (string) ($row['exec_key'] ?? '');
      if (array_key_exists($key, $counts)) {
        $counts[$key] = (int) ($row['total'] ?? 0);
      }
    }
    $out = [];
    foreach ($counts as $key => $total) {
      $out['ejecuta_' . $key] = $total;
    }
    return $out;
  }

  private static function quoteAvailable(callable $columnExists, string $quoteTable): bool
  {
    return $columnExists($quoteTable, 'ejecutado') && $columnExists($quoteTable, 'id_ticket');
  }
}
