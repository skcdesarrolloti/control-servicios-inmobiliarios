<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use SCM\Core\Database;
use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Modules\Pending\PublicServicesCritical;
use SCM\Modules\Pending\PublicServicesCriticalTicket;
use SCM\Support\SchemaInspector;

/**
 * Métricas de revisiones de servicios públicos y de revisiones preventivas.
 *
 * Las revisiones, críticos, daños y cotizaciones se cuentan dentro del periodo (fecha de la revisión);
 * los pendientes, sin cita y sin enviar son la foto actual que recibe del panel (mismas reglas de las
 * pestañas y de Vencimientos administrativos).
 */
final class MaintenanceMetricsReport
{
  public const SERVICES = ['luz' => 'Energía eléctrica', 'agua' => 'Acueducto y alcantarillado', 'gas' => 'Gas natural'];
  public const STATUSES = ['al_dia' => 'Al día', 'm30' => '30 días', 'm60' => '60 días', 'critico' => 'Crítico'];

  private \DateTimeZone $timezone;
  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->timezone = new \DateTimeZone('America/Bogota');
    $this->schema = new SchemaInspector($db);
  }

  /** @return array{0:int,1:int,2:string,3:string} [fromTs, toTs, from, to] */
  public function period(string $from, string $to): array
  {
    $today = new \DateTimeImmutable('today', $this->timezone);
    $parse = fn(string $value): ?\DateTimeImmutable => ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone)) && $date->format('Y-m-d') === $value ? $date : null;
    $fromDate = $parse($from) ?? $today->setDate((int) $today->format('Y'), 1, 1);
    $toDate = $parse($to) ?? $today;
    if ($toDate < $fromDate) [$fromDate, $toDate] = [$toDate, $fromDate];
    return [$fromDate->getTimestamp(), $toDate->getTimestamp() + 86399, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
  }

  public static function status(string $value): string
  {
    // Manual accent folding: iconv transliteration differs between servers.
    $value = strtr(mb_strtolower(trim($value), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    if ($value === '') return '';
    if (str_contains($value, 'critic')) return 'critico';
    if (str_contains($value, '60')) return 'm60';
    if (str_contains($value, '30')) return 'm30';
    if (str_contains($value, 'al d')) return 'al_dia';
    return '';
  }

  /**
   * @param array<int,array<string,mixed>> $pendingItems items de la pestaña Pendientes
   * @param array<int,array<string,mixed>> $configurationItems contratos sin servicios configurados
   */
  public function publicServices(int $fromTs, int $toTs, array $pendingItems, array $configurationItems): array
  {
    $todayTs = (new \DateTimeImmutable('today', $this->timezone))->getTimestamp();
    $month = (new \DateTimeImmutable('now', $this->timezone))->format('Y-m');
    $pending = ['total' => count($pendingItems), 'overdue' => 0, 'month' => 0, 'unconfigured' => count($configurationItems)];
    foreach ($pendingItems as $item) {
      $due = (int) ($item['due'] ?? 0);
      if ($due > 0 && $due < $todayTs) $pending['overdue']++;
      if ($due > 0 && date('Y-m', $due) === $month) $pending['month']++;
    }

    $table = $this->db->table('jet_cct_revisiones_servicios');
    $columns = $this->schema->getTableColumns($table);
    $pick = array_values(array_intersect(['_ID', 'fecha', 'cct_created', 'realizado_por', 'resultado_tiempo_luz', 'resultado_tiempo_agua', 'resultado_tiempo_gas', 'resultado_valores_luz', 'resultado_valores_agua', 'resultado_valores_gas'], $columns));
    $rows = $pick ? $this->rowsInPeriod($table, $columns, $pick, $fromTs, $toTs) : [];

    $reviews = ['total' => count($rows), 'none' => 0, 'debt' => 0] + array_fill_keys(array_keys(self::STATUSES), 0);
    $services = [];
    foreach (self::SERVICES as $key => $label) $services[$key] = ['label' => $label, 'reviewed' => 0, 'debt' => 0] + array_fill_keys(array_keys(self::STATUSES), 0);
    $staff = [];
    $trend = [];
    $rank = ['' => 0, 'al_dia' => 1, 'm30' => 2, 'm60' => 3, 'critico' => 4];
    foreach ($rows as $row) {
      $worst = '';
      foreach (self::SERVICES as $key => $label) {
        $status = self::status((string) ($row['resultado_tiempo_' . $key] ?? ''));
        if ($status === '') continue;
        $amount = $status === 'al_dia' ? 0 : ContractMovementsReport::parseMoney($row['resultado_valores_' . $key] ?? null);
        $services[$key]['reviewed']++;
        $services[$key][$status]++;
        $services[$key]['debt'] += $amount;
        $reviews['debt'] += $amount;
        if ($rank[$status] > $rank[$worst]) $worst = $status;
      }
      $worst === '' ? $reviews['none']++ : $reviews[$worst]++;
      $name = trim((string) ($row['realizado_por'] ?? '')) ?: 'Sin registrar';
      $staff[$name] ??= ['name' => $name, 'total' => 0, 'critico' => 0, 'mora' => 0];
      $staff[$name]['total']++;
      if ($worst === 'critico') $staff[$name]['critico']++;
      if (in_array($worst, ['m30', 'm60'], true)) $staff[$name]['mora']++;
      $bucket = date('Y-m', (int) $row['_scm_ts']);
      $trend[$bucket] ??= ['total' => 0, 'mora' => 0, 'critico' => 0];
      $trend[$bucket]['total']++;
      if (in_array($worst, ['m30', 'm60'], true)) $trend[$bucket]['mora']++;
      if ($worst === 'critico') $trend[$bucket]['critico']++;
    }

    $critical = ['created' => 0, 'open' => 0, 'overdue' => 0, 'closed' => 0, 'open_now' => 0, 'overdue_now' => 0];
    $cases = new PublicServicesCritical($this->db);
    if ($cases->available()) {
      $now = time();
      foreach ($this->db->getResults('SELECT c.created_at, c.deadline_at, t._ID ticket_id, t.estado, t.estado_administrativo FROM `' . $cases->table() . '` c LEFT JOIN `' . $this->db->table('jet_cct_tickets') . '` t ON t._ID=CAST(JSON_UNQUOTE(JSON_EXTRACT(c.payload_json,\'$.ticket_id\')) AS UNSIGNED)') as $case) {
        $open = !empty($case['ticket_id']) && PublicServicesCriticalTicket::open(['_ID' => $case['ticket_id'], 'estado' => $case['estado'], 'estado_administrativo' => $case['estado_administrativo']]);
        $late = $open && (int) $case['deadline_at'] < $now;
        if ($open) $critical['open_now']++;
        if ($late) $critical['overdue_now']++;
        if ((int) $case['created_at'] < $fromTs || (int) $case['created_at'] > $toTs) continue;
        $critical['created']++;
        $open ? $critical['open']++ : $critical['closed']++;
        if ($late) $critical['overdue']++;
      }
    }

    return ['pending' => $pending, 'reviews' => $reviews, 'services' => $services, 'staff' => self::topStaff($staff), 'trend' => self::sortTrend($trend), 'critical' => $critical];
  }

  /**
   * @param array<int,array<string,mixed>> $pendingItems contratos sin revisión preventiva este año
   * @param array<string,array{total:int,overdue:int}> $queues sin_cita, cita_sin_realizar, sin_enviar
   */
  public function preventive(int $fromTs, int $toTs, array $pendingItems, array $queues): array
  {
    $pending = ['total' => count($pendingItems), 'without_case' => 0];
    foreach ($pendingItems as $item) if (empty($item['ticket'])) $pending['without_case']++;

    $table = $this->db->table('jet_cct_revision_preventiva');
    $columns = $this->schema->getTableColumns($table);
    $pick = array_values(array_intersect(['_ID', 'fecha', 'cct_created', 'id_ticket', 'creador', 'se_envio', 'fecha_envio', 'encontro_danos', 'tiene_cotizacion'], $columns));
    $rows = $pick ? $this->rowsInPeriod($table, $columns, $pick, $fromTs, $toTs) : [];

    $quotesByReview = $this->preventiveQuotes($rows);
    $reviews = ['total' => count($rows), 'sent' => 0, 'unsent' => 0, 'damages' => 0, 'no_damages' => 0, 'with_quote' => 0, 'without_quote' => 0];
    $quotes = ['total' => 0, 'aprobada' => 0, 'esperando' => 0, 'sin_enviar' => 0, 'desaprobada' => 0, 'quoted' => 0, 'approved_value' => 0, 'admin' => 0, 'admin_pending' => 0];
    $staff = [];
    $trend = [];
    $seenQuotes = [];
    foreach ($rows as $row) {
      $sent = in_array(self::lower($row['se_envio'] ?? ''), ['si', 'sí'], true) || (int) ($row['fecha_envio'] ?? 0) > 0;
      $damages = in_array(self::lower($row['encontro_danos'] ?? ''), ['si', 'sí'], true);
      $sent ? $reviews['sent']++ : $reviews['unsent']++;
      $damages ? $reviews['damages']++ : $reviews['no_damages']++;
      $reviewQuotes = $quotesByReview[(int) $row['_ID']] ?? [];
      if ($damages) {
        ($reviewQuotes || self::lower($row['tiene_cotizacion'] ?? '') === 'si') ? $reviews['with_quote']++ : $reviews['without_quote']++;
      }
      foreach ($reviewQuotes as $quote) {
        $id = (int) $quote['_ID'];
        if (isset($seenQuotes[$id])) continue;
        $seenQuotes[$id] = true;
        $state = self::quoteState($quote);
        $total = ContractMovementsReport::parseMoney($quote['total'] ?? null);
        $admin = QuoteMetricsReport::adminAmount($quote);
        $quotes['total']++;
        $quotes[$state]++;
        $quotes['quoted'] += $total;
        if ($state === 'aprobada') { $quotes['approved_value'] += $total; $quotes['admin'] += $admin; }
        if (in_array($state, ['esperando', 'sin_enviar'], true)) $quotes['admin_pending'] += $admin;
      }
      $name = trim((string) ($row['creador'] ?? '')) ?: 'Sin registrar';
      $staff[$name] ??= ['name' => $name, 'total' => 0, 'damages' => 0, 'unsent' => 0];
      $staff[$name]['total']++;
      if ($damages) $staff[$name]['damages']++;
      if (!$sent) $staff[$name]['unsent']++;
      $bucket = date('Y-m', (int) $row['_scm_ts']);
      $trend[$bucket] ??= ['total' => 0, 'damages' => 0, 'sent' => 0];
      $trend[$bucket]['total']++;
      if ($damages) $trend[$bucket]['damages']++;
      if ($sent) $trend[$bucket]['sent']++;
    }

    return ['pending' => $pending, 'queues' => $queues, 'reviews' => $reviews, 'quotes' => $quotes, 'staff' => self::topStaff($staff), 'trend' => self::sortTrend($trend)];
  }

  /** Quotes linked by `id_revision` or, for legacy rows, by the review's case. @return array<int,array<int,array<string,mixed>>> */
  private function preventiveQuotes(array $reviews): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $columns = $this->schema->getTableColumns($table);
    if (!$reviews || !$columns) return [];
    $byId = []; $byTicket = [];
    foreach ($reviews as $review) {
      $byId[(string) $review['_ID']] = (int) $review['_ID'];
      $ticket = trim((string) ($review['id_ticket'] ?? ''));
      if ($ticket !== '') $byTicket[$ticket][] = (int) $review['_ID'];
    }
    $where = []; $args = [];
    if (in_array('id_revision', $columns, true)) {
      $where[] = 'TRIM(COALESCE(`id_revision`,\'\')) IN (' . implode(',', array_fill(0, count($byId), '?')) . ')';
      $args = array_merge($args, array_keys($byId));
    }
    if ($byTicket && in_array('id_ticket', $columns, true)) {
      $where[] = 'TRIM(COALESCE(`id_ticket`,\'\')) IN (' . implode(',', array_fill(0, count($byTicket), '?')) . ')';
      $args = array_merge($args, array_map('strval', array_keys($byTicket)));
    }
    if (!$where) return [];
    $select = implode(',', array_map(static fn($c) => '`' . $c . '`', array_values(array_intersect(['_ID', 'id_revision', 'id_ticket', 'estado', 'se_envio', 'total', 'total_admon', 'porcentaje_admon', 'total_materiales', 'total_mano_obra', 'total_maquinarias', 'total_otros_costos'], $columns))));
    $status = in_array('cct_status', $columns, true) ? " AND LOWER(TRIM(COALESCE(`cct_status`,'publish')))='publish'" : '';
    $out = [];
    foreach ($this->db->getResults("SELECT {$select} FROM `{$table}` WHERE (" . implode(' OR ', $where) . "){$status}", $args) as $quote) {
      $revision = trim((string) ($quote['id_revision'] ?? ''));
      $targets = isset($byId[$revision]) ? [$byId[$revision]] : ($byTicket[trim((string) ($quote['id_ticket'] ?? ''))] ?? []);
      foreach ($targets as $reviewId) $out[$reviewId][] = $quote;
    }
    return $out;
  }

  public static function quoteState(array $quote): string
  {
    $state = self::lower($quote['estado'] ?? '');
    if (str_starts_with($state, 'aprobad')) return 'aprobada';
    if (str_starts_with($state, 'desaprobad') || str_starts_with($state, 'rechazad')) return 'desaprobada';
    return in_array(self::lower($quote['se_envio'] ?? ''), ['si', 'sí'], true) ? 'esperando' : 'sin_enviar';
  }

  /** @return array<int,array<string,mixed>> rows with `_scm_ts` inside the period */
  private function rowsInPeriod(string $table, array $columns, array $pick, int $fromTs, int $toTs): array
  {
    $hasDate = in_array('fecha', $columns, true);
    $date = $hasDate ? "COALESCE(NULLIF(CAST(`fecha` AS UNSIGNED),0), UNIX_TIMESTAMP(`cct_created`))" : 'UNIX_TIMESTAMP(`cct_created`)';
    $status = in_array('cct_status', $columns, true) ? " AND LOWER(TRIM(COALESCE(`cct_status`,'publish'))) <> 'trash'" : '';
    $select = implode(',', array_map(static fn($c) => '`' . $c . '`', $pick));
    return $this->db->getResults("SELECT {$select}, {$date} AS `_scm_ts` FROM `{$table}` WHERE {$date} BETWEEN ? AND ?{$status}", [$fromTs, $toTs]);
  }

  private static function lower(mixed $value): string
  {
    return mb_strtolower(trim((string) $value), 'UTF-8');
  }

  private static function topStaff(array $staff): array
  {
    usort($staff, static fn(array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
    return array_slice($staff, 0, 12);
  }

  private static function sortTrend(array $trend): array
  {
    ksort($trend);
    return $trend;
  }
}
