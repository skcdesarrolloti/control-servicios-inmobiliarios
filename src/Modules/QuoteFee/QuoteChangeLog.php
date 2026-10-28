<?php

declare(strict_types=1);

namespace SCM\Modules\QuoteFee;

use SCM\Core\Database;

/**
 * Registro interno de cambios de una cotización de mantenimiento (p. ej. % de administración frente al sugerido).
 * No va al historial del ticket ni del inmueble porque esos los puede ver el cliente; solo se muestra en el panel.
 */
final class QuoteChangeLog
{
  private static bool $ready = false;

  public function __construct(private Database $db) {}

  public function table(): string
  {
    return $this->db->table('scm_quote_change_log');
  }

  public function ensure(): void
  {
    if (self::$ready) return;
    $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS `' . $this->table() . '` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cotizacion_id BIGINT UNSIGNED NOT NULL, id_ticket VARCHAR(40) NOT NULL DEFAULT \'\', campo VARCHAR(60) NOT NULL, valor_anterior VARCHAR(60) NOT NULL DEFAULT \'\', valor_nuevo VARCHAR(60) NOT NULL DEFAULT \'\', valor_sugerido VARCHAR(60) NOT NULL DEFAULT \'\', id_empleado VARCHAR(80) NOT NULL DEFAULT \'\', empleado_nombre VARCHAR(190) NOT NULL DEFAULT \'\', created_at BIGINT NOT NULL, KEY cotizacion_id(cotizacion_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    self::$ready = true;
  }

  /** @param array{id_ticket?:string,campo:string,valor_anterior?:string,valor_nuevo?:string,valor_sugerido?:string,id_empleado?:string,empleado_nombre?:string} $entry */
  public function record(int $quoteId, array $entry): void
  {
    $this->ensure();
    $this->db->insert($this->table(), [
      'cotizacion_id' => $quoteId,
      'id_ticket' => (string) ($entry['id_ticket'] ?? ''),
      'campo' => $entry['campo'],
      'valor_anterior' => (string) ($entry['valor_anterior'] ?? ''),
      'valor_nuevo' => (string) ($entry['valor_nuevo'] ?? ''),
      'valor_sugerido' => (string) ($entry['valor_sugerido'] ?? ''),
      'id_empleado' => (string) ($entry['id_empleado'] ?? ''),
      'empleado_nombre' => (string) ($entry['empleado_nombre'] ?? ''),
      'created_at' => time(),
    ]);
  }

  /**
   * Cambios por cotización, el más reciente primero. Vacío si la tabla aún no existe.
   * @param array<int,string> $quoteIds
   * @return array<string,array<int,array<string,mixed>>>
   */
  public function forQuotes(array $quoteIds): array
  {
    $ids = array_values(array_filter(array_map('intval', $quoteIds)));
    if (empty($ids)) return [];
    try {
      $rows = $this->db->getResults(
        'SELECT * FROM `' . $this->table() . '` WHERE cotizacion_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC',
        $ids
      );
    } catch (\Throwable $e) {
      return [];
    }
    $map = [];
    foreach ($rows as $row) {
      $map[(string) $row['cotizacion_id']][] = $row;
    }
    return $map;
  }

  /** "28/10/2026 14:03 · Ana — % administración 61,84 % → 12 % (sugerido 61,84 %)" */
  public static function describe(array $row): string
  {
    $pct = static fn(string $value): string => $value === '' ? '—' : str_replace('.', ',', $value) . ' %';
    $labels = ['porcentaje_admon' => '% administración'];
    $campo = (string) ($row['campo'] ?? '');
    $text = ($labels[$campo] ?? $campo) . ' ';
    $before = trim((string) ($row['valor_anterior'] ?? ''));
    $after = trim((string) ($row['valor_nuevo'] ?? ''));
    $text .= $before !== '' && $before !== $after ? $pct($before) . ' → ' . $pct($after) : $pct($after);
    $suggested = trim((string) ($row['valor_sugerido'] ?? ''));
    if ($suggested !== '') {
      $text .= abs((float) $suggested - (float) $after) < 0.005 ? ' (el sugerido)' : ' (sugerido ' . $pct($suggested) . ')';
    }
    return $text;
  }
}
