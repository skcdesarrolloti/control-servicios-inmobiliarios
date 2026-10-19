<?php

declare(strict_types=1);

/**
 * Copia "¿Quién ejecuta?" (`ejecutado`) de las cotizaciones a los casos que no lo tienen.
 *
 * Usa la cotización aprobada más reciente; si no hay, la última cotización con ejecutor.
 * Nunca sobrescribe un caso que ya tiene valor (solo reporta diferencias).
 *
 *   php bin/sync-case-executor-from-quotes.php          # simulación
 *   php bin/sync-case-executor-from-quotes.php --apply  # guarda cambios
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$apply = in_array('--apply', $argv, true);
$db = \SCM\Core\App::db();
$tickets = $db->table('jet_cct_tickets');
$quotes = $db->table('jet_cct_cotizacion_mantenimiento');

$rows = $db->getResults(
  "SELECT q.`_ID`, TRIM(q.`id_ticket`) AS ticket_ref, TRIM(q.`ejecutado`) AS ejecutado, LOWER(TRIM(COALESCE(q.`estado`, ''))) AS estado
     FROM `{$quotes}` q
    WHERE TRIM(COALESCE(q.`ejecutado`, '')) <> '' AND TRIM(COALESCE(q.`id_ticket`, '')) <> ''
    ORDER BY q.`_ID` DESC"
);

// Por caso: primero la aprobada más reciente, si no la más reciente con ejecutor.
$byTicket = [];
foreach ($rows as $row) {
  $ref = (string) $row['ticket_ref'];
  $approved = $row['estado'] === 'aprobada';
  if (!isset($byTicket[$ref]) || ($approved && !$byTicket[$ref]['approved'])) {
    $byTicket[$ref] = ['executor' => (string) $row['ejecutado'], 'quote' => (int) $row['_ID'], 'approved' => $approved];
  }
}

$updated = 0;
$conflicts = [];
foreach ($byTicket as $ref => $source) {
  $ref = (string) $ref;
  if (!ctype_digit($ref)) {
    continue;
  }
  $ticket = $db->getRow("SELECT `_ID`, TRIM(COALESCE(`ejecutado`, '')) AS ejecutado FROM `{$tickets}` WHERE `_ID` = ? LIMIT 1", [(int) $ref]);
  if (!is_array($ticket)) {
    continue;
  }
  $current = (string) $ticket['ejecutado'];
  if ($current !== '') {
    if (strcasecmp($current, $source['executor']) !== 0) {
      $conflicts[] = "Caso #{$ref}: caso={$current} / cotización #{$source['quote']}={$source['executor']}";
    }
    continue;
  }
  echo ($apply ? 'Marcado' : 'Se marcaría') . " caso #{$ref} → {$source['executor']} (cotización #{$source['quote']}" . ($source['approved'] ? ', aprobada' : '') . ")\n";
  if ($apply) {
    $db->update($tickets, ['ejecutado' => $source['executor']], ['_ID' => (int) $ref]);
  }
  $updated++;
}

echo "\n" . ($apply ? 'Casos marcados: ' : 'Casos por marcar: ') . $updated . "\n";
if ($conflicts !== []) {
  echo "Casos con valor distinto al de su cotización (no se tocan):\n- " . implode("\n- ", $conflicts) . "\n";
}
if (!$apply) {
  echo "Simulación: ejecuta con --apply para guardar.\n";
}
