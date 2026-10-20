<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * `inicio_trabajo` del caso: fecha en que el caso pasó por primera vez a "En ejecucion"
 * (orden de mantenimiento o acta de solución). Con `final_trabajo` (firma del acta) da la duración real.
 */
final class CaseWorkStart
{
  /** Guarda la fecha solo si el caso aún no tiene inicio; nunca interrumpe el flujo que lo llama. */
  public static function markIfEmpty(\SCM\Core\Database $db, int $ticketPk, int $timestamp): bool
  {
    if ($ticketPk <= 0 || $timestamp <= 0) {
      return false;
    }
    try {
      $table = $db->table('jet_cct_tickets');
      $row = $db->getRow("SELECT `inicio_trabajo` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
      if (!is_array($row) || !self::isEmpty($row['inicio_trabajo'] ?? null)) {
        return false;
      }
      return $db->update($table, ['inicio_trabajo' => $timestamp], ['_ID' => $ticketPk]) > 0;
    } catch (\Throwable $error) {
      error_log('[case_work_start] ' . $error->getMessage());
      return false;
    }
  }

  /** @param mixed $value */
  public static function isEmpty($value): bool
  {
    return $value === null || in_array(trim((string) $value), ['', '0'], true);
  }
}
