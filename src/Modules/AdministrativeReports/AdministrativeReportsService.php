<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeReports;

use SCM\Core\Database;
use SCM\Modules\TicketCompletion\CompletionPolicy;
use SCM\Support\SchemaInspector;

/**
 * Reportes administrativos ya aprobados (wp_jet_cct_reportes_administrativos) y cuentas de cobro
 * (wp_jet_cct_cuentas_cobro). Reemplaza el plugin "Reportes Administrativos SKC":
 * - Mis reportes: el funcionario ve sus reportes sin exportar, en cobro y pagados, y arma la cuenta de cobro.
 * - Consolidado: reportes exportados (en una cuenta de cobro) pendientes de pago o pagados.
 * - Cuentas de cobro: todas las cuentas; al pagar una se pagan sus reportes incluidos.
 * Los totales se calculan siempre aquí desde la base de datos, nunca con valores enviados por el navegador.
 */
final class AdministrativeReportsService
{
  /** Estados de "Mis reportes". */
  public const OWN_STATES = ['no_exportados', 'en_cobro', 'pagados'];
  public const PER_PAGE = 40;
  public const COMPANY_NAME = 'SOLUCIONES COMERCIALES Y CONSTRUCTIVAS S.A.S.';
  public const COMPANY_NIT = '900623242-4';

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  private function reportsTable(): string
  {
    return $this->db->table('jet_cct_reportes_administrativos');
  }

  private function accountsTable(): string
  {
    return $this->db->table('jet_cct_cuentas_cobro');
  }

  private function staffTable(): string
  {
    return $this->db->table('jet_cct_funcionarios');
  }

  public function available(): bool
  {
    return $this->schema->tableExists($this->reportsTable());
  }

  // Valores -------------------------------------------------------------------

  /** Pesos sin decimales: acepta "30933", "30933.00" y miles con punto o coma ("$1.200.000"). */
  public static function money(mixed $value): int
  {
    $raw = preg_replace('/[^\d.,\-]/', '', (string) $value) ?? '';
    if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $raw) === 1) $raw = str_replace(['.', ','], '', $raw);
    return max(0, (int) round((float) str_replace(',', '.', $raw)));
  }

  /** Valor guardado en la base (texto numérico, p. ej. "30933" o "30933.00"), como lo leía el plugin con floatval. */
  public static function stored(mixed $value): int
  {
    return max(0, (int) round((float) (preg_replace('/[^\d.\-]/', '', (string) $value) ?? '')));
  }

  /** Columna donde se guarda el valor del servicio según la categoría (igual que el plugin). */
  public static function serviceColumn(string $category): string
  {
    $category = mb_strtolower($category, 'UTF-8');
    if (str_contains($category, 'mantenimiento')) return 'valor_mantenimiento';
    if (str_contains($category, 'otro') || str_contains($category, 'adicional')) return 'valor_otro';
    return 'valor_revision';
  }

  /** Valor del servicio: la columna de su categoría o, si está en cero, la primera con valor. */
  public static function serviceValue(array $row): int
  {
    $values = [
      'valor_mantenimiento' => self::stored($row['valor_mantenimiento'] ?? 0),
      'valor_otro' => self::stored($row['valor_otro'] ?? 0),
      'valor_revision' => self::stored($row['valor_revision'] ?? 0),
    ];
    $value = $values[self::serviceColumn((string) ($row['categoria'] ?? ''))];
    if ($value > 0) return $value;
    foreach ($values as $candidate) {
      if ($candidate > 0) return $candidate;
    }
    return 0;
  }

  public static function isYes(mixed $value): bool
  {
    return strcasecmp(trim((string) $value), 'Si') === 0;
  }

  public static function timestamp(mixed $value): int
  {
    $value = trim((string) $value);
    if ($value === '') return 0;
    if (ctype_digit($value)) return (int) $value;
    $ts = strtotime($value);
    return $ts === false ? 0 : $ts;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  public static function present(array $row): array
  {
    $service = self::serviceValue($row);
    $transport = self::stored($row['transporte'] ?? 0);
    $total = self::stored($row['valor'] ?? 0);
    return [
      'id' => (int) ($row['_ID'] ?? 0),
      'fecha' => self::timestamp($row['fecha'] ?? ''),
      'categoria' => trim((string) ($row['categoria'] ?? '')),
      'servicio' => $service,
      'transporte' => $transport,
      'total' => $total > 0 ? $total : $service + $transport,
      'id_ticket' => trim((string) ($row['id_ticket'] ?? '')),
      'contrato' => trim((string) ($row['contrato'] ?? '')),
      'solicitud' => trim((string) ($row['numero_solicitud'] ?? '')),
      'inmueble' => trim((string) ($row['inmueble'] ?? '')),
      'arrendatario' => trim((string) ($row['arrendatario'] ?? '')),
      'descripcion' => (string) ($row['descripcion'] ?? ''),
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'funcionario' => trim((string) ($row['nombre_funcionario'] ?? '')) ?: trim((string) ($row['creador'] ?? '')),
      'exportado' => self::isYes($row['exportado'] ?? ''),
      'pagado' => self::isYes($row['fue_pagado'] ?? ''),
    ];
  }

  /** @param array<int,array<string,mixed>> $items @return array<string,int> */
  public static function summarize(array $items): array
  {
    $summary = ['count' => count($items), 'total' => 0, 'servicio' => 0, 'transporte' => 0, 'servicio_count' => 0, 'transporte_count' => 0];
    foreach ($items as $item) {
      $summary['total'] += (int) $item['total'];
      $summary['servicio'] += (int) $item['servicio'];
      $summary['transporte'] += (int) $item['transporte'];
      if ((int) $item['servicio'] > 0) $summary['servicio_count']++;
      if ((int) $item['transporte'] > 0) $summary['transporte_count']++;
    }
    return $summary;
  }

  // Funcionarios --------------------------------------------------------------

  /** @return array{id:string,nombre:string,documento:string,correo:string} */
  public function employee(string $employeeId): array
  {
    $profile = ['id' => $employeeId, 'nombre' => '', 'documento' => '', 'correo' => ''];
    $table = $this->staffTable();
    if ($employeeId === '' || !$this->schema->tableExists($table)) return $profile;
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_empleado`, '')) = ? ORDER BY `_ID` DESC LIMIT 1", [$employeeId]) ?: [];
    $profile['nombre'] = trim((string) ($row['nombre'] ?? ''));
    $profile['documento'] = trim((string) ($row['documento'] ?? ''));
    $profile['correo'] = trim((string) ($row['correo_dian'] ?? '')) ?: trim((string) ($row['correo'] ?? ''));
    return $profile;
  }

  private function staffReady(): bool
  {
    return $this->schema->tableExists($this->staffTable()) && $this->schema->columnExists($this->staffTable(), 'id_empleado');
  }

  /** Nombre y documento por id_empleado; subconsulta porque un id puede repetirse en funcionarios y no debe duplicar filas. */
  private function staffJoin(string $alias): string
  {
    if (!$this->staffReady()) return '';
    $table = $this->staffTable();
    $column = fn(string $name): string => $this->schema->columnExists($table, $name) ? "MAX(`{$name}`)" : "''";
    return " LEFT JOIN (SELECT TRIM(COALESCE(`id_empleado`, '')) AS emp, " . $column('nombre') . ' AS nombre, ' . $column('documento') . " AS documento FROM `{$table}` GROUP BY emp) f"
      . " ON f.emp = TRIM(COALESCE({$alias}.`id_empleado`, ''))";
  }

  private function staffNameSelect(): string
  {
    return $this->staffReady() ? ', f.nombre AS nombre_funcionario, f.documento AS documento_funcionario' : ", '' AS nombre_funcionario, '' AS documento_funcionario";
  }

  // Mis reportes --------------------------------------------------------------

  /** @return array{items:array<int,array<string,mixed>>,summary:array<string,int>,counts:array<string,int>} */
  public function ownReports(string $employeeId, string $state, array $filters = []): array
  {
    $state = in_array($state, self::OWN_STATES, true) ? $state : 'no_exportados';
    $empty = ['items' => [], 'summary' => self::summarize([]), 'counts' => array_fill_keys(self::OWN_STATES, 0)];
    if ($employeeId === '' || !$this->available()) return $empty;

    $table = $this->reportsTable();
    $counts = $empty['counts'];
    $rows = $this->db->getResults(
      "SELECT r.* FROM `{$table}` r WHERE TRIM(COALESCE(r.`id_empleado`, '')) = ? ORDER BY r.`_ID` DESC",
      [$employeeId]
    );
    $items = [];
    foreach ($rows as $row) {
      $item = self::present($row);
      $itemState = $item['pagado'] ? 'pagados' : ($item['exportado'] ? 'en_cobro' : 'no_exportados');
      $counts[$itemState]++;
      if ($itemState === $state && $this->matches($item, $filters)) $items[] = $item;
    }
    return ['items' => $items, 'summary' => self::summarize($items), 'counts' => $counts];
  }

  /** @param array<string,mixed> $item @param array<string,string> $filters */
  private function matches(array $item, array $filters): bool
  {
    $map = ['consecutivo' => 'id', 'tipo' => 'categoria', 'ticket' => 'id_ticket', 'contrato' => 'contrato', 'solicitud' => 'solicitud', 'inmueble' => 'inmueble', 'arrendatario' => 'arrendatario'];
    foreach ($map as $filter => $field) {
      $needle = mb_strtolower(trim((string) ($filters[$filter] ?? '')), 'UTF-8');
      if ($needle !== '' && !str_contains(mb_strtolower((string) $item[$field], 'UTF-8'), $needle)) return false;
    }
    $employee = trim((string) ($filters['funcionario'] ?? ''));
    return $employee === '' || $employee === (string) $item['id_empleado'];
  }

  // Consolidado de pagos ------------------------------------------------------

  /** @return array<string,mixed> */
  public function consolidated(string $state, array $filters, int $page): array
  {
    $state = $state === 'pagados' ? 'pagados' : 'no_pagados';
    $result = ['items' => [], 'summary' => self::summarize([]), 'counts' => ['no_pagados' => 0, 'pagados' => 0], 'page' => 1, 'pages' => 1];
    if (!$this->available()) return $result;

    $table = $this->reportsTable();
    $rows = $this->db->getResults(
      "SELECT r.*" . $this->staffNameSelect() . " FROM `{$table}` r" . $this->staffJoin('r') . " WHERE r.`exportado` = 'Si' ORDER BY r.`_ID` DESC"
    );
    $items = [];
    foreach ($rows as $row) {
      $item = self::present($row);
      $itemState = $item['pagado'] ? 'pagados' : 'no_pagados';
      $result['counts'][$itemState]++;
      if ($itemState === $state && $this->matches($item, $filters)) $items[] = $item;
    }
    $pages = max(1, (int) ceil(count($items) / self::PER_PAGE));
    $page = min(max(1, $page), $pages);
    $result['summary'] = self::summarize($items);
    $result['items'] = array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
    $result['page'] = $page;
    $result['pages'] = $pages;
    $result['total_items'] = count($items);
    return $result;
  }

  /** Marca como pagados reportes exportados pendientes. @param array<int,int> $ids */
  public function markReportsPaid(array $ids): int
  {
    $ids = self::cleanIds($ids);
    if (!$ids || !$this->available()) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $this->db->pdo()->prepare(
      "UPDATE `{$this->reportsTable()}` SET `fue_pagado` = 'Si' WHERE `_ID` IN ({$placeholders}) AND COALESCE(`fue_pagado`, '') <> 'Si'"
    );
    $stmt->execute($ids);
    return $stmt->rowCount();
  }

  /** @param array<int,mixed> $ids @return array<int,int> */
  public static function cleanIds(array $ids): array
  {
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
  }

  /** Acepta JSON (["1","2"]), listas y texto con números (formato de reportes_incluidos). @return array<int,int> */
  public static function parseIds(mixed $raw): array
  {
    if (is_array($raw)) return self::cleanIds($raw);
    $decoded = json_decode((string) $raw, true);
    $text = is_array($decoded) ? json_encode($decoded) : (string) $raw;
    preg_match_all('/\d+/', (string) $text, $matches);
    return self::cleanIds($matches[0] ?? []);
  }

  // Cuentas de cobro ----------------------------------------------------------

  public function accountsAvailable(): bool
  {
    return $this->schema->tableExists($this->accountsTable());
  }

  public static function accountPaid(array $account): bool
  {
    return strcasecmp(trim((string) ($account['fue_pagada'] ?? '')), 'Pagada') === 0;
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private static function presentAccount(array $row): array
  {
    $created = trim((string) ($row['fecha_creacion'] ?? ''));
    return [
      'id' => (int) ($row['_ID'] ?? 0),
      'numero' => trim((string) ($row['numero_cuenta'] ?? '')) ?: 'CC-' . (int) ($row['_ID'] ?? 0),
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'funcionario' => trim((string) ($row['nombre_funcionario'] ?? '')),
      'documento' => trim((string) ($row['documento_funcionario'] ?? '')),
      'fecha_creacion' => $created,
      'fecha' => substr($created, 0, 10),
      'fecha_inicio' => trim((string) ($row['fecha_inicio'] ?? '')),
      'fecha_fin' => trim((string) ($row['fecha_fin'] ?? '')),
      'servicio' => self::stored($row['total_servicio'] ?? 0),
      'transporte' => self::stored($row['total_transporte'] ?? 0),
      'total' => self::stored($row['total_general'] ?? 0),
      'cantidad' => (int) ($row['cantidad_reportes'] ?? 0),
      'pagada' => self::accountPaid($row),
      'report_ids' => self::parseIds($row['reportes_incluidos'] ?? ''),
    ];
  }

  private function accountSelect(): string
  {
    return 'SELECT c.*' . $this->staffNameSelect() . " FROM `{$this->accountsTable()}` c" . $this->staffJoin('c');
  }

  /** @return array{items:array<int,array<string,mixed>>,counts:array<string,int>,totals:array<string,int>} */
  public function accounts(array $filters, string $employeeId = ''): array
  {
    $result = ['items' => [], 'counts' => ['total' => 0, 'pagadas' => 0, 'pendientes' => 0], 'totals' => ['pendiente' => 0, 'pagado' => 0]];
    if (!$this->accountsAvailable()) return $result;
    $where = '';
    $args = [];
    if ($employeeId !== '') {
      $where = " WHERE TRIM(COALESCE(c.`id_empleado`, '')) = ?";
      $args[] = $employeeId;
    }
    $numero = mb_strtolower(trim((string) ($filters['numero'] ?? '')), 'UTF-8');
    $funcionario = mb_strtolower(trim((string) ($filters['funcionario'] ?? '')), 'UTF-8');
    $desde = trim((string) ($filters['desde'] ?? ''));
    $hasta = trim((string) ($filters['hasta'] ?? ''));
    $estado = trim((string) ($filters['estado'] ?? ''));
    foreach ($this->db->getResults($this->accountSelect() . $where . ' ORDER BY c.`_ID` DESC', $args) as $row) {
      $account = self::presentAccount($row);
      $result['counts']['total']++;
      $result['counts'][$account['pagada'] ? 'pagadas' : 'pendientes']++;
      $result['totals'][$account['pagada'] ? 'pagado' : 'pendiente'] += $account['total'];
      if ($numero !== '' && !str_contains(mb_strtolower($account['numero'], 'UTF-8'), $numero)) continue;
      if ($funcionario !== '' && !str_contains(mb_strtolower($account['funcionario'], 'UTF-8'), $funcionario)) continue;
      if ($desde !== '' && $account['fecha'] < $desde) continue;
      if ($hasta !== '' && $account['fecha'] > $hasta) continue;
      if ($estado === 'pagada' && !$account['pagada']) continue;
      if ($estado === 'pendiente' && $account['pagada']) continue;
      $result['items'][] = $account;
    }
    return $result;
  }

  /** @return array<string,mixed>|null */
  public function account(int $id): ?array
  {
    if ($id <= 0 || !$this->accountsAvailable()) return null;
    $row = $this->db->getRow($this->accountSelect() . ' WHERE c.`_ID` = ? LIMIT 1', [$id]);
    if (!is_array($row)) return null;
    $account = self::presentAccount($row);
    $account['reportes'] = [];
    if ($account['report_ids'] && $this->available()) {
      $placeholders = implode(',', array_fill(0, count($account['report_ids']), '?'));
      foreach ($this->db->getResults("SELECT * FROM `{$this->reportsTable()}` WHERE `_ID` IN ({$placeholders}) ORDER BY `_ID` DESC", $account['report_ids']) as $report) {
        $account['reportes'][] = self::present($report);
      }
    }
    return $account;
  }

  /**
   * Marca cuentas como pagadas y, con ellas, todos sus reportes incluidos.
   * @param array<int,int> $ids @return array{accounts:int,reports:int,report_ids:array<int,int>}
   */
  public function markAccountsPaid(array $ids): array
  {
    $ids = self::cleanIds($ids);
    $result = ['accounts' => 0, 'reports' => 0, 'report_ids' => []];
    if (!$ids || !$this->accountsAvailable()) return $result;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $reportIds = [];
      foreach ($this->db->getResults("SELECT `reportes_incluidos` FROM `{$this->accountsTable()}` WHERE `_ID` IN ({$placeholders})", $ids) as $row) {
        $reportIds = array_merge($reportIds, self::parseIds($row['reportes_incluidos'] ?? ''));
      }
      $stmt = $pdo->prepare("UPDATE `{$this->accountsTable()}` SET `fue_pagada` = 'Pagada' WHERE `_ID` IN ({$placeholders}) AND COALESCE(`fue_pagada`, '') <> 'Pagada'");
      $stmt->execute($ids);
      $result['accounts'] = $stmt->rowCount();
      $result['report_ids'] = self::cleanIds($reportIds);
      if ($result['report_ids']) {
        $reportPlaceholders = implode(',', array_fill(0, count($result['report_ids']), '?'));
        $reports = $pdo->prepare("UPDATE `{$this->reportsTable()}` SET `fue_pagado` = 'Si' WHERE `_ID` IN ({$reportPlaceholders}) AND COALESCE(`fue_pagado`, '') <> 'Si'");
        $reports->execute($result['report_ids']);
        $result['reports'] = $reports->rowCount();
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
    return $result;
  }

  /**
   * Crea la cuenta de cobro con los reportes seleccionados del funcionario y los marca como exportados.
   * Solo entran reportes propios, sin exportar y sin pagar; los totales salen de la base de datos.
   * @param array<int,int> $ids @return array<string,mixed>
   */
  public function createAccount(string $employeeId, array $ids): array
  {
    $ids = self::cleanIds($ids);
    if ($employeeId === '') throw new \DomainException('Tu usuario no tiene un id de empleado asociado.');
    if (!$ids) throw new \DomainException('Selecciona al menos un reporte.');
    if (!$this->available() || !$this->accountsAvailable()) throw new \DomainException('No se encontraron las tablas de reportes o cuentas de cobro.');

    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $rows = $this->db->getResults(
        "SELECT * FROM `{$this->reportsTable()}` WHERE `_ID` IN ({$placeholders}) AND TRIM(COALESCE(`id_empleado`, '')) = ?"
        . " AND COALESCE(`exportado`, '') <> 'Si' AND COALESCE(`fue_pagado`, '') <> 'Si' FOR UPDATE",
        array_merge($ids, [$employeeId])
      );
      if (count($rows) !== count($ids)) {
        throw new \DomainException('Algunos reportes ya están en otra cuenta de cobro o no te pertenecen. Recarga la lista e intenta de nuevo.');
      }
      $items = array_map([self::class, 'present'], $rows);
      $summary = self::summarize($items);
      $dates = array_filter(array_column($items, 'fecha'));
      $now = date('Y-m-d H:i:s');
      $payload = $this->schema->filterTableData($this->accountsTable(), [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $now,
        'cct_modified' => $now,
        'numero_cuenta' => '',
        'id_empleado' => $employeeId,
        'fecha_creacion' => $now,
        'fecha_inicio' => $dates ? date('Y-m-d', min($dates)) : date('Y-m-d'),
        'fecha_fin' => $dates ? date('Y-m-d', max($dates)) : date('Y-m-d'),
        'total_servicio' => (string) $summary['servicio'],
        'total_transporte' => (string) $summary['transporte'],
        'total_general' => (string) $summary['total'],
        'cantidad_reportes' => (string) count($items),
        'reportes_incluidos' => json_encode(array_map('strval', array_column($items, 'id'))),
        'fue_pagada' => 'No pagada',
      ]);
      if (!$payload || !$this->db->insert($this->accountsTable(), $payload)) {
        throw new \RuntimeException('No se pudo registrar la cuenta de cobro.');
      }
      $accountId = (int) $this->db->lastInsertId();
      $number = 'CC-' . str_pad((string) $accountId, 6, '0', STR_PAD_LEFT);
      $this->db->update($this->accountsTable(), $this->schema->filterTableData($this->accountsTable(), [
        'numero_cuenta' => $number,
        'archivo_pdf' => 'cuenta_cobro_' . $number . '.pdf',
      ]), ['_ID' => $accountId]);
      $stmt = $pdo->prepare("UPDATE `{$this->reportsTable()}` SET `exportado` = 'Si' WHERE `_ID` IN ({$placeholders})");
      $stmt->execute($ids);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
    return ['id' => $accountId, 'numero' => $number, 'summary' => $summary];
  }

  // Edición y creación (funcionarios con permiso) ------------------------------

  /** @return array<string,int> */
  public function updateValues(int $id, int $service, int $transport): array
  {
    $table = $this->reportsTable();
    $row = $id > 0 && $this->available() ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]) : null;
    if (!is_array($row)) throw new \DomainException('Reporte no encontrado.');
    if (self::isYes($row['exportado'] ?? '') || self::isYes($row['fue_pagado'] ?? '')) {
      throw new \DomainException('El reporte ya está en una cuenta de cobro o pagado; no se pueden cambiar sus valores.');
    }
    $total = $service + $transport;
    $this->db->update($table, $this->schema->filterTableData($table, [
      self::serviceColumn((string) ($row['categoria'] ?? '')) => (string) $service,
      'transporte' => (string) $transport,
      'valor' => (string) $total,
      'cct_modified' => date('Y-m-d H:i:s'),
    ]), ['_ID' => $id]);
    return ['id' => $id, 'servicio' => $service, 'transporte' => $transport, 'total' => $total];
  }

  /** @return array<string,string>|null */
  public function ticket(int $ticketId): ?array
  {
    $table = $this->db->table('jet_cct_tickets');
    if ($ticketId <= 0 || !$this->schema->tableExists($table)) return null;
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$ticketId]);
    if (!is_array($row)) return null;
    $pick = static function (array $keys) use ($row): string {
      foreach ($keys as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') return $value;
      }
      return '';
    };
    return [
      'id_ticket' => (string) $ticketId,
      'asunto' => $pick(['asunto', 'titulo']),
      'id_inmueble' => $pick(['id_inmueble', 'inmueble_id']),
      'id_estudio_aseguradora' => $pick(['id_estudio_aseguradora', 'estudio_aseguradora']),
      'numero_solicitud' => $pick(['numero_solicitud', 'solicitud']),
      'id_contrato' => $pick(['id_contrato', 'contrato_id']),
      'contrato' => $pick(['contrato', 'numero_contrato', 'id_contrato']),
      'inmueble' => $pick(['inmueble', 'id_inmueble']),
      'arrendatario' => $pick(['arrendatario', 'nombre_arrendatario']),
      'sucursal' => $pick(['sucursal']),
      'estado' => $pick(['estado']),
    ];
  }

  /** Tarifa de revisión y transporte configurados en el sistema, como valores sugeridos del formulario. @return array{servicio:int,transporte:int} */
  public function suggestedValues(): array
  {
    $config = [];
    $table = $this->db->table('jet_cct_confi_sistema');
    if ($this->schema->tableExists($table)) {
      foreach ($this->db->getResults("SELECT `funcion`, `valor` FROM `{$table}` WHERE `funcion` IN ('salario','dias_trabajo','porcentaje_smlmv_co_pre','porcentaje_smlmv','valor_transporte') ORDER BY `_ID`") as $row) {
        $config[(string) ($row['funcion'] ?? '')] = $row['valor'] ?? '';
      }
    }
    return ['servicio' => (int) (CompletionPolicy::fee($config) ?? 0), 'transporte' => (int) (CompletionPolicy::transportBase($config) ?? 0)];
  }

  /** @return array<int,string> */
  public function categories(): array
  {
    $defaults = ['Revision preventiva', 'Revision correctiva', 'Mantenimiento', 'Otro'];
    if (!$this->available()) return $defaults;
    $found = $this->db->getCol("SELECT DISTINCT TRIM(`categoria`) FROM `{$this->reportsTable()}` WHERE TRIM(COALESCE(`categoria`, '')) <> '' ORDER BY 1 LIMIT 50");
    return $found ? array_values(array_map('strval', $found)) : $defaults;
  }

  /**
   * Reporte creado directamente por un funcionario autorizado (antes, botones "Crear reporte administrativo"
   * y "Añadir transporte por visita" del plugin). Solo toca el caso si se pide cerrarlo.
   * @param array<string,string> $input @param array<string,string> $actor @return array<string,mixed>
   */
  public function createReport(array $input, string $employeeId, string $employeeName, array $actor): array
  {
    $transportOnly = ($input['form'] ?? '') === 'transporte';
    $ticketId = (int) ($input['id_ticket'] ?? 0);
    $category = trim((string) ($input['categoria'] ?? ''));
    $description = trim((string) ($input['descripcion'] ?? ''));
    $service = $transportOnly ? 0 : self::money($input['servicio'] ?? 0);
    $transport = self::money($input['transporte'] ?? 0);
    $closeTicket = !$transportOnly && ($input['cerrar_ticket'] ?? '') === 'Si';

    if ($employeeId === '') throw new \DomainException('Selecciona el funcionario del reporte.');
    if ($category === '') throw new \DomainException('El tipo de reporte es obligatorio.');
    if ($description === '') throw new \DomainException('La evidencia escrita es obligatoria.');
    if ($service + $transport <= 0) throw new \DomainException('Ingresa el valor del servicio o del transporte.');
    $ticket = $this->ticket($ticketId);
    if ($ticket === null) throw new \DomainException('No se encontró el caso #' . $ticketId . '.');
    if (!$this->available()) throw new \DomainException('No existe la tabla de reportes administrativos.');

    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $actorEmployee = (string) ($actor['employee_id'] ?? $employeeId);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      if ($closeTicket) {
        $this->db->getRow('SELECT `_ID` FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE `_ID` = ? FOR UPDATE', [$ticketId]);
        $actError = \SCM\Modules\TicketCompletion\CompletionRepository::workflowError($this->db, $this->schema, $ticketId, true);
        if ($actError !== '') throw new \DomainException($actError);
      }
      $report = [
        'cct_status' => 'publish',
        'cct_author_id' => $actorEmployee,
        'cct_created' => $nowSql,
        'cct_modified' => $nowSql,
        'fecha' => (string) $now,
        'id_empleado' => $employeeId,
        'creador' => $employeeName,
        'nombre_empleado' => $employeeName,
        'id_ticket' => (string) $ticketId,
        'id_inmueble' => $ticket['id_inmueble'],
        'id_estudio_aseguradora' => $ticket['id_estudio_aseguradora'],
        'numero_solicitud' => $ticket['numero_solicitud'],
        'id_contrato' => $ticket['id_contrato'],
        'contrato' => $ticket['contrato'],
        'inmueble' => $ticket['inmueble'],
        'arrendatario' => $ticket['arrendatario'],
        'sucursal' => $ticket['sucursal'],
        'categoria' => $category,
        'descripcion' => $description,
        'valor_revision' => '0',
        'valor_mantenimiento' => '0',
        'valor_otro' => '0',
        'transporte' => (string) $transport,
        'valor' => (string) ($service + $transport),
        'estado' => 'Pendiente',
        'cerrar_ticket' => $closeTicket ? 'Si' : 'No',
        'exportado' => 'No',
        'fue_pagado' => 'No',
      ];
      $report[self::serviceColumn($category)] = (string) $service;
      $payload = $this->schema->filterTableData($this->reportsTable(), $report);
      if (!$payload || !$this->db->insert($this->reportsTable(), $payload)) {
        throw new \RuntimeException('No se pudo crear el reporte.');
      }
      $reportId = (int) $this->db->lastInsertId();

      $history = $this->db->table('jet_cct_historial_del_ticket');
      if ($this->schema->tableExists($history)) {
        $entry = $this->schema->filterTableData($history, [
          'cct_status' => 'publish',
          'cct_author_id' => $actorEmployee,
          'cct_created' => $nowSql,
          'cct_modified' => $nowSql,
          'id_ticket' => (string) $ticketId,
          'fecha' => (string) $now,
          'nombre' => (string) ($actor['name'] ?? $employeeName),
          'correo' => (string) ($actor['email'] ?? ''),
          'celular' => (string) ($actor['phone'] ?? ''),
          'id_empleado' => $actorEmployee,
          'respuesta' => '<p>Reporte administrativo #' . $reportId . ' (' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '): '
            . nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) . '</p>',
          'fue_editada' => 'No',
        ]);
        if ($entry) $this->db->insert($history, $entry);
      }

      $tickets = $this->db->table('jet_cct_tickets');
      $ticketUpdate = ['fecha_actualizacion' => (string) $now, 'cct_modified' => $nowSql];
      if ($closeTicket) {
        $ticketUpdate['estado'] = 'Cerrado';
        $ticketUpdate['estado_administrativo'] = 'Finalizado';
      }
      $ticketUpdate = $this->schema->filterTableData($tickets, $ticketUpdate);
      if ($ticketUpdate) $this->db->update($tickets, $ticketUpdate, ['_ID' => $ticketId]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
    return ['id' => $reportId, 'total' => $service + $transport];
  }

  // Completar contrato, solicitud y arrendatario -------------------------------

  private static function missing(string $column): string
  {
    return "(r.`{$column}` IS NULL OR r.`{$column}` = '' OR r.`{$column}` = '0' OR r.`{$column}` = 'No asignado')";
  }

  private static function hasSource(string $column): string
  {
    return "(c.`{$column}` IS NOT NULL AND c.`{$column}` <> '' AND c.`{$column}` <> '0' AND c.`{$column}` <> 'No asignado')";
  }

  private function backfillWhere(): string
  {
    $parts = [];
    foreach (['contrato', 'numero_solicitud', 'arrendatario'] as $column) {
      $parts[] = '(' . self::missing($column) . ' AND ' . self::hasSource($column) . ')';
    }
    return "r.`id_contrato` IS NOT NULL AND r.`id_contrato` <> '' AND (" . implode(' OR ', $parts) . ')';
  }

  private function backfillReady(): bool
  {
    return $this->available() && $this->schema->tableExists($this->db->table('jet_cct_contratos_arrendamiento'));
  }

  public function backfillPending(): int
  {
    if (!$this->backfillReady()) return 0;
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->reportsTable()}` r INNER JOIN `{$contracts}` c ON CAST(r.`id_contrato` AS UNSIGNED) = c.`_ID` WHERE " . $this->backfillWhere());
  }

  /** Completa contrato, solicitud y arrendatario vacíos desde el contrato de arrendamiento (id_contrato). */
  public function backfill(): int
  {
    if (!$this->backfillReady()) return 0;
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $set = [];
    foreach (['contrato', 'numero_solicitud', 'arrendatario'] as $column) {
      $set[] = "r.`{$column}` = CASE WHEN " . self::missing($column) . ' AND ' . self::hasSource($column) . " THEN c.`{$column}` ELSE r.`{$column}` END";
    }
    $stmt = $this->db->pdo()->prepare("UPDATE `{$this->reportsTable()}` r INNER JOIN `{$contracts}` c ON CAST(r.`id_contrato` AS UNSIGNED) = c.`_ID` SET " . implode(', ', $set) . ' WHERE ' . $this->backfillWhere());
    $stmt->execute();
    return $stmt->rowCount();
  }
}
