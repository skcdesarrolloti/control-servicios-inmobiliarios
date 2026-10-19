<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/** Sub-tabs of "Preventivas pendientes": completed reviews and the due-date queues of the admin calendar. */
final class PreventiveWorkspace
{
  /** Sub-tab => [label, icon, due type used by the admin calendar]. */
  public const TABS = [
    'pending' => ['Pendientes', 'clock', ''],
    'history' => ['Revisiones realizadas', 'refresh', ''],
    'sin_cita' => ['Casos sin cita', 'calendar', 'ticket_preventiva_sin_cita'],
    'cita_sin_realizar' => ['Con cita sin realizar', 'alert', 'preventiva_cita_sin_realizar'],
    'sin_enviar' => ['Revisiones sin enviar', 'document', 'preventiva_sin_enviar'],
  ];
  private const PAGE = 30;

  public function __construct(private Database $db) {}

  public static function tabs(?int $count = null): string
  {
    return self::render('tabs', ['count' => $count]);
  }

  public static function dueType(string $section): string
  {
    $type = self::TABS[$section][2] ?? '';
    if ($type === '') throw new \DomainException('Sección de preventivas no válida.');
    return $type;
  }

  public function history(array $input, bool $canSend): string
  {
    $table = $this->db->table('jet_cct_revision_preventiva');
    $schema = new SchemaInspector($this->db);
    $columns = $schema->getTableColumns($table);
    if (!$columns) throw new \DomainException('No está disponible la tabla de revisiones preventivas.');
    $has = static fn(string $column): bool => in_array($column, $columns, true);
    $date = $has('fecha') ? "COALESCE(NULLIF(CAST(`fecha` AS UNSIGNED),0), UNIX_TIMESTAMP(`cct_created`))" : 'UNIX_TIMESTAMP(`cct_created`)';
    $sent = "(LOWER(TRIM(COALESCE(" . ($has('se_envio') ? '`se_envio`' : "''") . ",''))) IN ('si','sí') OR CAST(COALESCE(" . ($has('fecha_envio') ? '`fecha_envio`' : '0') . ",0) AS UNSIGNED) > 0)";
    $damages = $has('encontro_danos') ? "LOWER(TRIM(COALESCE(`encontro_danos`,''))) IN ('si','sí')" : '0';
    $where = ['1=1']; $args = [];
    foreach (['contrato', 'inmueble', 'arrendatario', 'propietario', 'id_ticket'] as $field) {
      $term = trim((string) ($input[$field] ?? ''));
      if ($term === '' || !$has($field)) continue;
      $where[] = '`' . $field . '` LIKE ?'; $args[] = '%' . $this->db->escapeLike($term) . '%';
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
      if (empty($input[$key])) continue;
      $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input[$key], new \DateTimeZone('America/Bogota'));
      if (!$day || $day->format('Y-m-d') !== $input[$key]) throw new \DomainException('Fecha del filtro no válida.');
      $where[] = $date . ' ' . $op . ' ?'; $args[] = $day->getTimestamp() + ($key === 'to' ? 86399 : 0);
    }
    $envio = (string) ($input['envio'] ?? '');
    if ($envio === 'enviadas') $where[] = $sent;
    if ($envio === 'sin_enviar') $where[] = 'NOT ' . $sent;
    $sqlWhere = implode(' AND ', $where);
    $monthStart = (new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('America/Bogota')))->getTimestamp();
    $kpis = array_map('intval', $this->db->getRow("SELECT COUNT(*) total, SUM({$date} >= ?) month, SUM({$sent}) sent, SUM({$damages}) damages FROM `{$table}` WHERE {$sqlWhere}", array_merge([$monthStart], $args)) ?? []);
    $total = $kpis['total'] ?? 0;
    $pages = max(1, (int) ceil($total / self::PAGE));
    $page = min($pages, max(1, (int) ($input['page'] ?? 1)));
    $order = ($input['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $rows = $this->db->getResults("SELECT *, {$date} AS `_scm_fecha`, {$sent} AS `_scm_sent` FROM `{$table}` WHERE {$sqlWhere} ORDER BY `_scm_fecha` {$order}, `_ID` {$order} LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE), $args);
    return self::render('history', compact('rows', 'input', 'kpis', 'total', 'page', 'pages', 'canSend'));
  }

  /** @param array<int,array<string,mixed>> $items admin calendar events of one due type */
  public static function agenda(string $section, array $items, array $input, bool $canSend): string
  {
    $type = self::dueType($section);
    $items = array_values(array_filter($items, static fn(array $item): bool => ($item['tipo_vencimiento'] ?? '') === $type));
    $today = date('Y-m-d');
    $kpis = ['total' => count($items), 'overdue' => 0, 'today' => 0];
    foreach ($items as $item) {
      if (strtolower((string) ($item['estado'] ?? '')) === 'vencido') $kpis['overdue']++;
      if (substr((string) ($item['fecha_vencimiento'] ?? ''), 0, 10) === $today) $kpis['today']++;
    }
    $term = mb_strtolower(trim((string) ($input['q'] ?? '')));
    $status = (string) ($input['estado'] ?? '');
    $items = array_values(array_filter($items, static function (array $item) use ($term, $status): bool {
      if ($status !== '' && strtolower((string) ($item['estado'] ?? '')) !== $status) return false;
      if ($term === '') return true;
      $case = (array) ($item['case'] ?? []);
      $haystack = implode(' ', array_map('strval', [$case['ticket'] ?? '', $case['contrato'] ?? '', $case['inmueble'] ?? '', $case['direccion'] ?? '', $case['arrendatario'] ?? '', $case['propietario'] ?? '', $case['empleado'] ?? '', $case['id_revision_preventiva'] ?? '']));
      return str_contains(mb_strtolower($haystack), $term);
    }));
    usort($items, static fn(array $a, array $b): int => [(int) ($b['dias_vencido'] ?? 0), (string) ($a['fecha_vencimiento'] ?? '')] <=> [(int) ($a['dias_vencido'] ?? 0), (string) ($b['fecha_vencimiento'] ?? '')]);
    $total = count($items);
    $pages = max(1, (int) ceil($total / self::PAGE));
    $page = min($pages, max(1, (int) ($input['page'] ?? 1)));
    $items = array_slice($items, ($page - 1) * self::PAGE, self::PAGE);
    return self::render('agenda', compact('section', 'type', 'items', 'input', 'kpis', 'total', 'page', 'pages', 'canSend'));
  }

  public static function reviewUrl(int $id): string
  {
    return \SCM\App\SuCasaControlServiciosInmobiliarios::signedPreventiveReviewPublicUrl($id);
  }

  public static function render(string $name, array $data): string
  {
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__, 3) . '/resources/views/preventive/' . $name . '.php';
    return (string) ob_get_clean();
  }
}
