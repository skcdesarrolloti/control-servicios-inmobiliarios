<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Cotización vigente de un caso: la de mayor `_ID` entre las ligadas por `id_cotizacion_mantenimiento` o `id_ticket`.
 *
 * Envío, estado y respuesta se leen de esa cotización; las columnas del ticket
 * (`fue_enviada_cotizacion_mantenimiento`, `estado_respuesta_cotizacion_mantenimiento`, fechas) son solo un espejo.
 */
final class CurrentQuote
{
  public const SENT_VALUES = ['si', 'sí', '1', 'true', 'enviada', 'enviado', 'fue enviada'];

  /**
   * Subconsulta con el `_ID` de la cotización vigente, a partir de la relación EXISTS del listado (alias `cot_exists`).
   */
  public static function latestIdExpression(string $existsExpression): ?string
  {
    if (!str_starts_with($existsExpression, 'EXISTS (SELECT 1 FROM ')) {
      return null;
    }
    $query = substr($existsExpression, strlen('EXISTS ('), -1);
    $query = preg_replace('/^SELECT 1 FROM /', 'SELECT cot_exists.`_ID` FROM ', $query);
    return '(' . $query . ' ORDER BY cot_exists.`_ID` DESC LIMIT 1)';
  }

  /**
   * Filtros "Estado cotización" y "Fue enviada" evaluados solo sobre la cotización vigente (alias `cot_cur`).
   *
   * @param callable(string,string):bool $columnExists
   * @param array<int,mixed> $args Recibe los valores en el orden de los marcadores.
   */
  public static function filterCondition(callable $columnExists, string $existsExpression, string $quoteTable, string $state, string $sent, string $placeholder, array &$args): ?string
  {
    $latestId = self::latestIdExpression($existsExpression);
    if ($latestId === null || !$columnExists($quoteTable, '_ID')) {
      return '0 = 1';
    }

    $conds = [];
    $state = strtolower(trim($state));
    if ($state !== '') {
      $stateParts = [];
      foreach (['estado', 'estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta'] as $col) {
        if ($columnExists($quoteTable, $col)) {
          $stateParts[] = "LOWER(TRIM(COALESCE(cot_cur.`{$col}`, ''))) = {$placeholder}";
          $args[] = $state;
        }
      }
      $conds[] = $stateParts === [] ? '0 = 1' : '(' . implode(' OR ', $stateParts) . ')';
    }

    $sent = strtolower(trim($sent));
    $wantsSent = in_array($sent, ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true);
    $wantsUnsent = in_array($sent, ['no', '0', 'false', 'none', 'sin'], true);
    if ($wantsSent || $wantsUnsent) {
      $sentParts = [];
      foreach (['se_envio', 'fue_enviada_cotizacion_mantenimiento', 'fue_enviada'] as $col) {
        if ($columnExists($quoteTable, $col)) {
          $sentParts[] = "LOWER(TRIM(COALESCE(cot_cur.`{$col}`, ''))) IN ('si', 'sí', '1', 'true', 'enviada', 'enviado')";
        }
      }
      $sentExpr = $sentParts === [] ? '0 = 1' : '(' . implode(' OR ', $sentParts) . ')';
      $conds[] = $wantsSent ? $sentExpr : "NOT {$sentExpr}";
    }

    if ($conds === []) {
      return null;
    }
    return "EXISTS (SELECT 1 FROM `{$quoteTable}` cot_cur WHERE cot_cur.`_ID` = {$latestId} AND " . implode(' AND ', $conds) . ')';
  }

  /**
   * @param array<int,mixed> ...$candidateLists Listas de filas de cotización ligadas al caso.
   * @return array<string,mixed>|null
   */
  public static function pick(array ...$candidateLists): ?array
  {
    $best = null;
    foreach ($candidateLists as $list) {
      foreach ($list as $candidate) {
        if (is_array($candidate) && (int) ($candidate['_ID'] ?? 0) > (int) ($best['_ID'] ?? 0)) {
          $best = $candidate;
        }
      }
    }
    return $best;
  }

  /** @param array<string,mixed> $quote */
  public static function wasSent(array $quote): bool
  {
    foreach (['se_envio', 'fue_enviada_cotizacion_mantenimiento', 'fue_enviada'] as $field) {
      if (in_array(strtolower(trim((string) ($quote[$field] ?? ''))), self::SENT_VALUES, true)) {
        return true;
      }
    }
    return false;
  }

  /** @param array<string,mixed> $quote */
  public static function responseState(array $quote): string
  {
    foreach (['estado', 'estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta'] as $field) {
      $value = trim((string) ($quote[$field] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /**
   * Campos espejo que el ticket debe tener según su cotización vigente.
   *
   * @param array<string,mixed> $quote
   * Una fecha null significa que la cotización está enviada/respondida pero no registró la fecha (datos antiguos):
   * en ese caso se conserva la del ticket.
   *
   * @return array{fue_enviada_cotizacion_mantenimiento:string,estado_respuesta_cotizacion_mantenimiento:string,fecha_envio_cotizacion_mantenimiento:?int,fecha_respuesta_cotizacion_mantenimiento:?int}
   */
  public static function ticketMirror(array $quote): array
  {
    $sent = self::wasSent($quote);
    $state = self::responseState($quote);
    $answered = in_array(strtolower($state), ['aprobada', 'desaprobada'], true);
    return [
      'fue_enviada_cotizacion_mantenimiento' => $sent ? 'Si' : 'No',
      'estado_respuesta_cotizacion_mantenimiento' => $state,
      'fecha_envio_cotizacion_mantenimiento' => $sent ? (self::timestamp($quote['fecha_envio'] ?? 0) ?: null) : 0,
      'fecha_respuesta_cotizacion_mantenimiento' => $answered ? (self::timestamp($quote['fecha_respuesta'] ?? 0) ?: null) : 0,
    ];
  }

  /**
   * Aplica la cotización a la fila del caso (en memoria) si es más reciente que la ya aplicada.
   * Requiere que la fila de cotización traiga `se_envio`; sin ese dato no se pisa lo que diga el ticket.
   *
   * @param array<string,mixed> $row
   * @param array<string,mixed>|null $quote
   * @return array<string,mixed>
   */
  public static function applyToRow(array $row, ?array $quote): array
  {
    if (!is_array($quote) || !array_key_exists('se_envio', $quote)) {
      return $row;
    }
    $quoteId = (int) ($quote['_ID'] ?? 0);
    if ($quoteId <= 0 || $quoteId < (int) ($row['_scm_cot_current_id'] ?? 0)) {
      return $row;
    }

    $mirror = self::ticketMirror($quote);
    $row['_scm_cot_current_id'] = $quoteId;
    $row['_scm_cot_se_envio'] = $mirror['fue_enviada_cotizacion_mantenimiento'];
    $row['_scm_cot_estado'] = $mirror['estado_respuesta_cotizacion_mantenimiento'];
    $row['_scm_cot_respuesta_estado'] = $mirror['estado_respuesta_cotizacion_mantenimiento'];
    $row['fue_enviada_cotizacion_mantenimiento'] = $mirror['fue_enviada_cotizacion_mantenimiento'];
    $row['estado_respuesta_cotizacion_mantenimiento'] = $mirror['estado_respuesta_cotizacion_mantenimiento'];
    foreach (['fecha_envio_cotizacion_mantenimiento', 'fecha_respuesta_cotizacion_mantenimiento'] as $dateField) {
      if ($mirror[$dateField] !== null) {
        $row[$dateField] = $mirror[$dateField] > 0 ? $mirror[$dateField] : '';
      }
    }
    $sendTs = $mirror['fecha_envio_cotizacion_mantenimiento'];
    if ($sendTs !== null) {
      $row['_scm_cot_fecha_envio'] = is_int($row['_scm_cot_fecha_envio'] ?? null) ? $sendTs : ($sendTs > 0 ? (string) $sendTs : '');
    }
    return $row;
  }

  /**
   * Sincroniza las columnas espejo del ticket con su cotización vigente.
   *
   * @return bool true si el ticket cambió.
   */
  public static function syncTicket(\SCM\Core\Database $db, int $ticketPk): bool
  {
    if ($ticketPk <= 0) {
      return false;
    }
    try {
      $ticketTable = $db->table('jet_cct_tickets');
      $ticket = $db->getRow("SELECT * FROM `{$ticketTable}` WHERE `_ID` = ? LIMIT 1", [$ticketPk]);
      if (!is_array($ticket)) {
        return false;
      }
      $quote = self::currentForTicket($db, $ticket);
      if (!is_array($quote)) {
        return false;
      }
      $update = self::ticketChanges($ticket, $quote);
      if ($update === []) {
        return false;
      }
      if (array_key_exists('cct_modified', $ticket)) {
        $update['cct_modified'] = date('Y-m-d H:i:s');
      }
      return $db->update($ticketTable, $update, ['_ID' => $ticketPk]) > 0;
    } catch (\Throwable $error) {
      error_log('[current_quote_sync] ' . $error->getMessage());
      return false;
    }
  }

  /**
   * Sincroniza el ticket dueño de una cotización (por `id_ticket`). Nunca interrumpe el flujo que la llama.
   */
  public static function syncTicketForQuote(\SCM\Core\Database $db, int $quoteId): bool
  {
    try {
      $quoteTable = $db->table('jet_cct_cotizacion_mantenimiento');
      $ticketRef = trim((string) $db->getVar("SELECT `id_ticket` FROM `{$quoteTable}` WHERE `_ID` = ? LIMIT 1", [$quoteId]));
      if ($quoteId <= 0 || $ticketRef === '') {
        return false;
      }
      $ticketTable = $db->table('jet_cct_tickets');
      $ticketPk = ctype_digit($ticketRef)
        ? (int) $db->getVar("SELECT `_ID` FROM `{$ticketTable}` WHERE `_ID` = ? LIMIT 1", [(int) $ticketRef])
        : 0;
      if ($ticketPk <= 0) {
        $ticketPk = (int) $db->getVar("SELECT `_ID` FROM `{$ticketTable}` WHERE TRIM(COALESCE(`id_ticket`, '')) = ? LIMIT 1", [$ticketRef]);
      }
      return self::syncTicket($db, $ticketPk);
    } catch (\Throwable $error) {
      error_log('[current_quote_sync] ' . $error->getMessage());
      return false;
    }
  }

  /**
   * @param array<string,mixed> $ticket
   * @return array<string,mixed>|null
   */
  public static function currentForTicket(\SCM\Core\Database $db, array $ticket): ?array
  {
    $quoteTable = $db->table('jet_cct_cotizacion_mantenimiento');
    $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s;|]+/', (string) ($ticket['id_cotizacion_mantenimiento'] ?? '')) ?: [])));
    $refs = array_values(array_unique(array_filter([trim((string) ($ticket['_ID'] ?? '')), trim((string) ($ticket['id_ticket'] ?? ''))], static fn(string $v): bool => $v !== '')));
    $where = [];
    $args = [];
    if ($ids !== []) {
      $where[] = '`_ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
      array_push($args, ...$ids);
    }
    if ($refs !== []) {
      $where[] = "TRIM(COALESCE(`id_ticket`, '')) IN (" . implode(',', array_fill(0, count($refs), '?')) . ')';
      array_push($args, ...$refs);
    }
    if ($where === []) {
      return null;
    }
    $row = $db->getRow("SELECT * FROM `{$quoteTable}` WHERE " . implode(' OR ', $where) . ' ORDER BY `_ID` DESC LIMIT 1', $args);
    return is_array($row) ? $row : null;
  }

  /**
   * Columnas espejo del ticket que difieren de su cotización vigente.
   *
   * @param array<string,mixed> $ticket
   * @param array<string,mixed> $quote
   * @return array<string,mixed>
   */
  public static function ticketChanges(array $ticket, array $quote): array
  {
    $changes = [];
    foreach (self::ticketMirror($quote) as $column => $value) {
      if ($value === null || !array_key_exists($column, $ticket)) {
        continue;
      }
      $current = $ticket[$column];
      // Las fechas se comparan por día: una hora exacta del ticket no se cambia por la fecha sin hora de la cotización.
      $same = is_int($value)
        ? self::sameDay(self::timestamp($current), $value)
        : strcasecmp(trim((string) $current), $value) === 0;
      if (!$same) {
        $changes[$column] = $value;
      }
    }
    return $changes;
  }

  private static function sameDay(int $a, int $b): bool
  {
    if ($a <= 0 || $b <= 0) {
      return $a <= 0 && $b <= 0;
    }
    $zone = new \DateTimeZone('America/Bogota');
    return (new \DateTimeImmutable('@' . $a))->setTimezone($zone)->format('Y-m-d')
      === (new \DateTimeImmutable('@' . $b))->setTimezone($zone)->format('Y-m-d');
  }

  /** @param mixed $value */
  private static function timestamp($value): int
  {
    if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
      return max(0, (int) $value);
    }
    $raw = trim((string) $value);
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
      return 0;
    }
    $ts = strtotime($raw);
    return $ts !== false && $ts > 0 ? $ts : 0;
  }
}
