<?php

declare(strict_types=1);

/**
 * Alinea las columnas espejo del ticket con su cotización vigente (la de mayor `_ID` ligada al caso):
 * `fue_enviada_cotizacion_mantenimiento`, `estado_respuesta_cotizacion_mantenimiento`,
 * `fecha_envio_cotizacion_mantenimiento` y `fecha_respuesta_cotizacion_mantenimiento`.
 *
 * No toca `estado_administrativo` ni otros campos del caso.
 *
 *   php bin/sync-ticket-quote-mirror.php          # simulación
 *   php bin/sync-ticket-quote-mirror.php --apply  # guarda cambios
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Support\CurrentQuote;

$apply = in_array('--apply', $argv, true);
$db = \SCM\Core\App::db();
$tickets = $db->table('jet_cct_tickets');
$quotes = $db->table('jet_cct_cotizacion_mantenimiento');

// Casos con al menos una cotización ligada por id o por id_ticket.
$rows = $db->getResults(
  "SELECT t.* FROM `{$tickets}` t
    WHERE TRIM(COALESCE(t.`id_cotizacion_mantenimiento`, '')) <> ''
       OR EXISTS (SELECT 1 FROM `{$quotes}` q WHERE TRIM(COALESCE(q.`id_ticket`, '')) = CAST(t.`_ID` AS CHAR))"
);

$changed = 0;
$byColumn = [];
$skipped = [];
foreach ($rows as $ticket) {
  $quote = CurrentQuote::currentForTicket($db, $ticket);
  if (!is_array($quote)) {
    continue;
  }
  $diff = CurrentQuote::ticketChanges($ticket, $quote);
  if ($diff === []) {
    continue;
  }
  // Notas antiguas con estado "Sin estado" tienen fechas pero no bandera de envío: se revisan a mano.
  if (strcasecmp(trim((string) ($quote['estado'] ?? '')), 'Sin estado') === 0) {
    $skipped[] = '#' . $ticket['_ID'] . ' (cotización #' . $quote['_ID'] . ')';
    continue;
  }
  $changed++;
  foreach (array_keys($diff) as $column) {
    $byColumn[$column] = ($byColumn[$column] ?? 0) + 1;
  }
  $parts = [];
  foreach ($diff as $column => $value) {
    $parts[] = $column . ': ' . json_encode((string) ($ticket[$column] ?? ''), JSON_UNESCAPED_UNICODE) . ' -> ' . json_encode((string) $value, JSON_UNESCAPED_UNICODE);
  }
  echo 'Caso #' . $ticket['_ID'] . ' (cotización #' . $quote['_ID'] . '): ' . implode('; ', $parts) . PHP_EOL;
  if ($apply) {
    CurrentQuote::syncTicket($db, (int) $ticket['_ID']);
  }
}

echo PHP_EOL . ($apply ? 'Actualizados' : 'Por actualizar') . ": {$changed} de " . count($rows) . ' casos con cotización.' . PHP_EOL;
foreach ($byColumn as $column => $count) {
  echo "  {$column}: {$count}" . PHP_EOL;
}
if ($skipped !== []) {
  echo 'Omitidos para revisión manual (cotización "Sin estado"): ' . implode(', ', $skipped) . PHP_EOL;
}
if (!$apply && $changed > 0) {
  echo 'Simulación. Ejecuta con --apply para guardar.' . PHP_EOL;
}
