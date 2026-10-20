<?php

declare(strict_types=1);

/**
 * Corrige "Ejecutado por" en casos con cotización.
 *
 * Antes la cotización copiaba al caso su "¿Quién ejecutará y aprobará?", que en realidad dice quién aprueba.
 * Para cada caso con cotización gana el evento más reciente:
 *   - cotización (creada o editada)            → Inmobiliaria
 *   - acta de solución y firma (pendiente/firmada) → quien realizó la solución
 *     (si viene de una cotización aprobada, Inmobiliaria)
 *   - cambio manual con nota interna            → se conserva el valor actual
 * Casos sin cotización no se tocan. Cada corrección deja nota interna.
 *
 *   php bin/repair-case-executor.php          # simulación
 *   php bin/repair-case-executor.php --apply  # guarda cambios
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\TicketCompletion\CompletionPolicy;
use SCM\Support\CaseExecutorSql;

$apply = in_array('--apply', $argv, true);
$db = \SCM\Core\App::db();
$schema = new \SCM\Support\SchemaInspector($db);
$tickets = $db->table('jet_cct_tickets');
$quotes = $db->table('jet_cct_cotizacion_mantenimiento');
$acts = $db->table('scm_ticket_completion_acts');
$notes = $db->table('jet_cct_notas_ticket');

$toTs = static function ($value): int {
  if (is_numeric($value)) {
    return max(0, (int) $value);
  }
  $ts = strtotime(trim((string) $value));
  return $ts !== false && $ts > 0 ? $ts : 0;
};

// Última cotización por caso (fecha de creación o última edición).
$quoteTs = [];
foreach ($db->getResults("SELECT TRIM(`id_ticket`) AS ref, `fecha`, `cct_created`, `cct_modified` FROM `{$quotes}` WHERE TRIM(COALESCE(`id_ticket`, '')) <> ''") as $row) {
  $ts = max($toTs($row['fecha']), $toTs($row['cct_created']));
  $ref = (string) $row['ref'];
  $quoteTs[$ref] = max($quoteTs[$ref] ?? 0, $ts > 0 ? $ts : 1);
}

// Acta de solución y firma vigente por caso.
$actByTicket = [];
if ($schema->tableExists($acts)) {
  foreach ($db->getResults("SELECT `ticket_pk`, `payload_json`, `created_at` FROM `{$acts}` WHERE `status` IN ('pending', 'signed') ORDER BY `id`") as $row) {
    $payload = json_decode((string) $row['payload_json'], true);
    $key = is_array($payload) ? (string) ($payload['executor'] ?? '') : '';
    if (!isset(CompletionPolicy::EXECUTORS[$key])) {
      continue;
    }
    if ((string) ($payload['source']['flow'] ?? '') === 'approved_quote') {
      $key = 'inmobiliaria';
    }
    $actByTicket[(string) $row['ticket_pk']] = ['ts' => (int) $row['created_at'], 'executor' => CompletionPolicy::EXECUTORS[$key]];
  }
}

// Último cambio manual de "Ejecutado por" (Editar ejecutado, Responder, Seguimiento).
$manualTs = [];
if ($schema->tableExists($notes)) {
  foreach ($db->getResults("SELECT TRIM(`id_ticket`) AS ref, `fecha`, `cct_created` FROM `{$notes}` WHERE `observacion` LIKE 'Quién ejecuta el caso actualizado%'") as $row) {
    $ref = (string) $row['ref'];
    $manualTs[$ref] = max($manualTs[$ref] ?? 0, $toTs($row['fecha']) ?: $toTs($row['cct_created']));
  }
}

$rows = $db->getResults("SELECT `_ID`, TRIM(COALESCE(`ejecutado`, '')) AS ejecutado FROM `{$tickets}` ORDER BY `_ID`");
$changed = 0;
$keptManual = 0;
$summary = [];
foreach ($rows as $row) {
  $id = (string) $row['_ID'];
  if (!isset($quoteTs[$id])) {
    continue;
  }
  $events = [['ts' => $quoteTs[$id], 'executor' => CaseExecutorSql::QUOTE_EXECUTOR, 'source' => 'cotización']];
  if (isset($actByTicket[$id])) {
    $events[] = $actByTicket[$id] + ['source' => 'acta de solución'];
  }
  if (isset($manualTs[$id])) {
    $events[] = ['ts' => $manualTs[$id], 'executor' => null, 'source' => 'cambio manual'];
  }
  usort($events, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
  $winner = $events[0];
  $current = (string) $row['ejecutado'];
  if ($winner['executor'] === null) {
    $keptManual++;
    continue;
  }
  if (strcasecmp($current, $winner['executor']) === 0) {
    continue;
  }

  $change = 'Ejecutado por: "' . ($current !== '' ? $current : 'Sin definir') . '" → "' . $winner['executor'] . '" (según ' . $winner['source'] . ')';
  echo ($apply ? 'Corregido' : 'Se corregiría') . " caso #{$id}: {$change}\n";
  $summary[$current . ' → ' . $winner['executor']] = ($summary[$current . ' → ' . $winner['executor']] ?? 0) + 1;
  $changed++;
  if (!$apply) {
    continue;
  }
  $now = time();
  $nowSql = date('Y-m-d H:i:s', $now);
  $db->update($tickets, $schema->filterTableData($tickets, ['ejecutado' => $winner['executor'], 'cct_modified' => $nowSql]), ['_ID' => (int) $id]);
  if ($schema->tableExists($notes)) {
    $note = $schema->filterTableData($notes, [
      'cct_status' => 'publish',
      'id_ticket' => (int) $id,
      'fecha' => $now,
      'nombre' => 'Sistema',
      'observacion' => "Corrección de quién ejecuta el caso (la cotización indica quién aprueba; si hay cotización ejecuta la inmobiliaria):\n" . $change,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
    ]);
    if ($note !== []) {
      $db->insert($notes, $note);
    }
  }
}

echo "\n" . ($apply ? 'Casos corregidos: ' : 'Casos por corregir: ') . $changed . ' de ' . count($quoteTs) . " referencias con cotización.\n";
foreach ($summary as $label => $count) {
  echo "  {$label}: {$count}\n";
}
echo "Casos con cambio manual más reciente (se conservan): {$keptManual}\n";
if (!$apply && $changed > 0) {
  echo "Simulación: ejecuta con --apply para guardar.\n";
}
