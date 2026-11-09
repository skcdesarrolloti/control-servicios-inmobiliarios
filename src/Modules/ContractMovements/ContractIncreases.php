<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

use SCM\Core\Database;
use SCM\Modules\Metrics\MaintenanceMetricsReport;
use SCM\Support\SchemaInspector;

/**
 * Aumentos de canon y administración según las cartas de aumento, que son las que guardan el valor nuevo:
 * `valor_canon` del contrato no cambia porque es el valor oficial que se audita contra SIMI.
 */
final class ContractIncreases
{
  public function __construct(private Database $db) {}

  /** @return list<array<string,mixed>> */
  public function all(): array
  {
    $table = $this->db->table('jet_cct_cartas_aumento');
    $columns = (new SchemaInspector($this->db))->getTableColumns($table);
    if (!in_array('tipo_carta', $columns, true)) {
      return [];
    }
    $wanted = ['_ID', 'fecha', 'id_contrato', 'contrato', 'inmueble', 'direccion', 'arrendatario', 'tipo_carta', 'incremento', 'canon', 'canon_anterior', 'administracion', 'administracion_anterior', 'vigencia_administracion', 'texto_retroactivos'];
    $select = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', array_values(array_intersect($wanted, $columns))));
    return self::fromLetters($this->db->getResults(
      "SELECT {$select}, COALESCE(NULLIF(CAST(`fecha` AS UNSIGNED), 0), UNIX_TIMESTAMP(`cct_created`)) AS `_scm_ts`
       FROM `{$table}`
       WHERE LOWER(TRIM(COALESCE(`cct_status`, 'publish'))) <> 'trash'
       ORDER BY `_scm_ts`, `_ID`"
    ));
  }

  /**
   * El valor anterior es el de la carta; las cartas antiguas no lo guardan, y entonces es el de la carta
   * previa del contrato o el nuevo ÷ (1 + %), marcado como calculado para que cartera lo revise.
   * @param list<array<string,mixed>> $letters En orden cronológico.
   * @return list<array<string,mixed>>
   */
  public static function fromLetters(array $letters): array
  {
    $out = [];
    $last = [];
    $seen = [];
    foreach ($letters as $letter) {
      $kind = mb_strtolower((string) ($letter['tipo_carta'] ?? ''), 'UTF-8');
      $type = str_contains($kind, 'canon') ? 'canon' : (str_contains($kind, 'admin') ? 'admin' : '');
      $date = ContractMovementsReport::isoDate($letter['_scm_ts'] ?? ($letter['fecha'] ?? null));
      if ($type === '' || $date === null) {
        continue;
      }
      $field = $type === 'canon' ? 'canon' : 'administracion';
      $new = ContractMovementsReport::parseMoney($letter[$field] ?? null);
      if ($new <= 0) {
        continue;
      }
      $contractId = trim((string) ($letter['id_contrato'] ?? ''));
      $contract = trim((string) ($letter['contrato'] ?? ''));
      $key = $type . '|' . ($contractId !== '' ? $contractId : $contract);
      // La misma carta generada dos veces no es otro aumento. Varias cartas con valores distintos sí lo son: ponen al día varios años.
      $fingerprint = $key . '|' . $date . '|' . $new;
      if (isset($seen[$fingerprint])) {
        continue;
      }
      $seen[$fingerprint] = true;

      $percent = MaintenanceMetricsReport::percent((string) ($letter['incremento'] ?? ''));
      $stored = ContractMovementsReport::parseMoney($letter[$field . '_anterior'] ?? null);
      $previous = $last[$key] ?? 0;
      $calculated = false;
      if ($stored > 0) {
        $before = $stored;
      } elseif ($previous > 0 && $previous <= $new) {
        $before = $previous;
      } elseif ($percent > 0) {
        $before = (int) round($new / (1 + $percent / 100));
        $calculated = true;
      } else {
        $before = 0;
      }
      $last[$key] = $new;
      if ($percent <= 0 && $before > 0) {
        $percent = round(($new - $before) / $before * 100, 2);
      }

      $validFrom = ContractMovementsReport::isoDate($letter['vigencia_administracion'] ?? null);
      $out[] = [
        'fecha' => $date,
        'vigencia' => $validFrom !== null && $validFrom !== $date ? $validFrom : '',
        'tipo_aumento' => $type,
        'id_contrato' => $contractId,
        'contrato' => $contract,
        'inmueble' => trim((string) ($letter['inmueble'] ?? '')),
        'direccion' => trim((string) preg_replace('/\s+/u', ' ', (string) ($letter['direccion'] ?? ''))),
        'arrendatario' => trim((string) preg_replace('/\s+/u', ' ', (string) ($letter['arrendatario'] ?? ''))),
        'anterior' => $before,
        'nuevo' => $new,
        'diferencia' => $before > 0 ? max(0, $new - $before) : null,
        'porcentaje' => $percent > 0 ? $percent : null,
        'calculado' => $calculated,
        'retroactivo' => trim((string) ($letter['texto_retroactivos'] ?? '')),
      ];
    }
    return $out;
  }
}
