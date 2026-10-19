<?php

declare(strict_types=1);

/**
 * Retira los estados administrativos "En ejecucion por …": el caso pasa a "En ejecucion"
 * y quién ejecuta se guarda en `ejecutado` (ver docs/ejecutado-caso.md).
 *
 * - `ejecutado` vacío: se llena con lo que decía el estado.
 * - `ejecutado` con otro valor: se conserva (viene de cotización/acta) y se reporta;
 *   con --prefer-state se reemplaza por el del estado.
 * - Cada caso migrado recibe una nota interna con el cambio.
 *
 *   php bin/migrate-execution-states.php                  # simulación
 *   php bin/migrate-execution-states.php --apply
 *   php bin/migrate-execution-states.php --apply --prefer-state
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\TicketCompletion\CompletionPolicy;

$apply = in_array('--apply', $argv, true);
$preferState = in_array('--prefer-state', $argv, true);
$db = \SCM\Core\App::db();
$schema = new \SCM\Support\SchemaInspector($db);
$tickets = $db->table('jet_cct_tickets');
$notes = $db->table('jet_cct_notas_ticket');
$labels = ['inmobiliaria' => 'Inmobiliaria', 'propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'copropiedad' => 'Copropiedad'];

$rows = $db->getResults(
  "SELECT `_ID`, TRIM(`estado_administrativo`) AS estado_administrativo, TRIM(COALESCE(`ejecutado`, '')) AS ejecutado
     FROM `{$tickets}`
    WHERE LOWER(TRIM(COALESCE(`estado_administrativo`, ''))) LIKE 'en ejecucion por %'
    ORDER BY `_ID`"
);

$migrated = 0;
$filled = 0;
$kept = [];
foreach ($rows as $row) {
  $id = (int) $row['_ID'];
  $previousState = (string) $row['estado_administrativo'];
  $role = strtolower(trim(substr($previousState, strlen(CompletionPolicy::LEGACY_EXECUTION_PREFIX))));
  if (!isset($labels[$role])) {
    echo "Caso #{$id}: estado no reconocido \"{$previousState}\", se omite.\n";
    continue;
  }
  $stateExecutor = $labels[$role];
  $current = (string) $row['ejecutado'];
  $update = ['estado_administrativo' => CompletionPolicy::EXECUTION_STATE];
  $changes = ['Estado administrativo: "' . $previousState . '" → "' . CompletionPolicy::EXECUTION_STATE . '"'];
  if ($current === '' || ($preferState && strcasecmp($current, $stateExecutor) !== 0)) {
    $update['ejecutado'] = $stateExecutor;
    $changes[] = 'Ejecutado por: "' . ($current !== '' ? $current : '-') . '" → "' . $stateExecutor . '"';
    $filled++;
  } elseif (strcasecmp($current, $stateExecutor) !== 0) {
    $kept[] = "Caso #{$id}: el estado decía {$stateExecutor}, se conserva Ejecutado por = {$current}";
  }

  echo ($apply ? 'Migrado' : 'Se migraría') . " caso #{$id}: " . implode('; ', $changes) . "\n";
  if (!$apply) {
    $migrated++;
    continue;
  }
  $now = time();
  $nowSql = date('Y-m-d H:i:s', $now);
  $db->update($tickets, $schema->filterTableData($tickets, $update + ['cct_modified' => $nowSql]), ['_ID' => $id]);
  if ($schema->tableExists($notes)) {
    $note = $schema->filterTableData($notes, [
      'cct_status' => 'publish',
      'id_ticket' => $id,
      'fecha' => $now,
      'nombre' => 'Sistema',
      'observacion' => "Migración de estados \"En ejecucion por …\" (quién ejecuta ahora se registra en Ejecutado por):\n" . implode("\n", $changes),
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
    ]);
    if ($note !== []) {
      $db->insert($notes, $note);
    }
  }
  $migrated++;
}

echo "\n" . ($apply ? 'Casos migrados: ' : 'Casos por migrar: ') . $migrated . " (Ejecutado por llenado: {$filled})\n";
if ($kept !== []) {
  echo "Casos donde el estado y Ejecutado por no coincidían (se conserva Ejecutado por):\n- " . implode("\n- ", $kept) . "\n";
}
if (!$apply) {
  echo "Simulación: ejecuta con --apply para guardar.\n";
}
