<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

/*
 * Cartas de aumento generadas desde el panel antes de dejar de modificar el contrato (desde el 2026-09-17).
 * Reconstruye el valor anterior: canon = valor nuevo / (1 + %) cuando el cálculo es exacto; si no, y para
 * administración, el último valor del sistema registrado en la Auditoría de canon y seguro antes de la carta.
 *   Sin opciones: solo muestra la revisión.
 *   --apply: guarda canon_anterior / administracion_anterior en la carta y deja la nota en el historial del contrato.
 *   --restore-contract (con --apply): además devuelve valor_canon / valor_administracion al valor anterior,
 *     solo si el contrato sigue con el valor de la carta.
 */
use SCM\Modules\Contracts\ContractRenewalService;
use SCM\Support\SchemaInspector;

$options = getopt('', ['apply', 'restore-contract', 'since:']);
$apply = array_key_exists('apply', $options);
$restore = $apply && array_key_exists('restore-contract', $options);
$since = (string) ($options['since'] ?? '2026-09-17');
$db = \SCM\Core\App::db();
$schema = new SchemaInspector($db);
$letters = $db->table('jet_cct_cartas_aumento');
$contracts = $db->table('jet_cct_contratos_arrendamiento');
$history = $db->table('jet_cct_historial_contrato_arrendamiento');
$items = $db->table('scm_canon_insurance_audit_items');
$audits = $db->table('scm_canon_insurance_audits');
$hasAudit = $schema->tableExists($items) && $schema->tableExists($audits);
$money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');

$rows = $db->getResults("SELECT * FROM `{$letters}` WHERE `cct_created` >= ? ORDER BY `_ID` ASC", [$since . ' 00:00:00']);
$report = [];
foreach ($rows as $letter) {
  $isCanon = stripos((string) ($letter['tipo_carta'] ?? ''), 'canon') !== false;
  $valueField = $isCanon ? 'canon' : 'administracion';
  $contractField = $isCanon ? 'valor_canon' : 'valor_administracion';
  $previousField = $isCanon ? 'canon_anterior' : 'administracion_anterior';
  $new = (float) (ContractRenewalService::canon($letter[$valueField] ?? '') ?? 0);
  $percent = (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', (string) ($letter['incremento'] ?? '')) ?? '');
  $previous = 0.0;
  $source = '';
  if ($isCanon && $percent > 0 && $new > 0) {
    $candidate = round($new / (1 + $percent / 100));
    if (round($candidate * (1 + $percent / 100)) === round($new)) {
      $previous = $candidate;
      $source = 'porcentaje';
    }
  }
  if ($previous <= 0 && $hasAudit) {
    $column = $isCanon ? 'system_canon' : 'system_administration';
    $value = $db->getVar(
      "SELECT i.`{$column}` FROM `{$items}` i JOIN `{$audits}` a ON a.`id` = i.`audit_id` WHERE i.`contract_id` = ? AND a.`uploaded_at` <= ? AND i.`{$column}` IS NOT NULL ORDER BY a.`uploaded_at` DESC, i.`id` DESC LIMIT 1",
      [(string) ($letter['id_contrato'] ?? ''), (string) $letter['cct_created']]
    );
    if ($value !== null && (float) $value > 0) {
      $previous = (float) $value;
      $source = 'auditoría canon y seguro';
    }
  }
  $contract = $db->getRow("SELECT * FROM `{$contracts}` WHERE `_ID` = ? LIMIT 1", [(int) ($letter['id_contrato'] ?? 0)]) ?: [];
  $current = (float) (ContractRenewalService::canon($contract[$contractField] ?? '') ?? 0);
  $line = [
    'carta' => (int) $letter['_ID'], 'contrato' => (string) ($letter['contrato'] ?? ''), 'tipo' => $isCanon ? 'canon' : 'administracion',
    'anterior' => $previous > 0 ? $money($previous) : 'sin dato', 'nuevo' => $money($new), 'contrato_hoy' => $money($current),
    'fuente' => $source ?: '-', 'acciones' => [],
  ];
  if ($apply && $previous > 0) {
    if ($schema->columnExists($letters, $previousField) && trim((string) ($letter[$previousField] ?? '')) === '') {
      $db->update($letters, [$previousField => (string) $previous], ['_ID' => (int) $letter['_ID']]);
      $line['acciones'][] = 'carta';
    }
    $type = $isCanon ? 'Aumento de canon' : 'Aumento de administracion';
    $letterTs = (int) ($letter['fecha'] ?? 0) ?: (int) strtotime((string) $letter['cct_created']);
    $exists = $schema->tableExists($history) && (int) $db->getVar(
      "SELECT COUNT(1) FROM `{$history}` WHERE TRIM(COALESCE(`id_contrato`, '')) = ? AND `tipo_reporte` = ? AND `fecha` = ?",
      [(string) ($letter['id_contrato'] ?? ''), $type, $letterTs]
    ) > 0;
    if ($schema->tableExists($history) && !$exists && $contract !== []) {
      $now = date('Y-m-d H:i:s');
      $text = 'Carta de aumento de ' . ($isCanon ? 'canon' : 'administración') . ' #' . (int) $letter['_ID'] . ': ' . $money($previous) . ' → ' . $money($new)
        . ($percent > 0 ? ' (' . rtrim((string) $letter['incremento'], '% ') . ' %)' : '') . '. Registro recuperado; valor anterior según ' . $source . '.';
      $db->insert($history, $schema->filterTableData($history, [
        'cct_status' => 'publish', 'cct_author_id' => (string) ($letter['id_empleado'] ?? ''), 'cct_created' => $now, 'cct_modified' => $now,
        'id_empleado' => (string) ($letter['id_empleado'] ?? ''), 'funcionario' => (string) ($letter['creador'] ?? ''),
        'id_contrato' => (string) $contract['_ID'], 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
        'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? ''),
        'fecha' => $letterTs, 'tipo_reporte' => $type, 'observacion' => $text,
      ]));
      $line['acciones'][] = 'historial';
    }
    if ($restore && $contract !== [] && round($current) === round($new)) {
      $db->update($contracts, [$contractField => (string) $previous, 'cct_modified' => date('Y-m-d H:i:s')], ['_ID' => (int) $contract['_ID']]);
      $line['acciones'][] = 'contrato restaurado';
    }
  }
  $line['acciones'] = implode(', ', $line['acciones']) ?: '-';
  $report[] = $line;
}

foreach ($report as $line) {
  echo implode(' | ', array_map('strval', $line)), PHP_EOL;
}
echo count($report) . ' cartas revisadas' . ($apply ? '' : ' (solo revisión; usa --apply para guardar)') . '.' . PHP_EOL;
