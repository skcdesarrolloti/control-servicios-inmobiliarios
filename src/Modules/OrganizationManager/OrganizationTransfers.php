<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Traslado de registros entre funcionarios (inmuebles con su postmeta, precaptaciones, retoques y tickets), igual que
 * el plugin: previsualización, «todos los filtrados» o «solo los seleccionados», motivo y una sola transacción.
 * Cada traslado queda en wp_gpo_transfer_log, la misma tabla del plugin, para que el historial continúe.
 * No cambia estado ni estado_administrativo de los tickets: solo el funcionario asignado.
 */
final class OrganizationTransfers
{
  /** @var array<string,bool> tablas creadas en esta petición (SchemaInspector guarda en caché que no existían). */
  private array $created = [];

  private function ownTableExists(string $table): bool
  {
    return isset($this->created[$table]) || $this->schema->tableExists($table);
  }

  public const MODULES = [
    'inmuebles' => [
      'label' => 'Inmuebles', 'description' => 'Cartera inmobiliaria y postmeta del inmueble.',
      'table' => 'jet_cct_inmuebles', 'where' => 'id_funcionario', 'updates' => ['id_funcionario' => 'id_empleado', 'funcionario' => 'nombre'],
      'sample' => ['_ID', 'codigo', 'funcionario', 'id_funcionario', 'tipo_negocio', 'estado', 'direccion'], 'postmeta' => true,
    ],
    'precaptaciones' => [
      'label' => 'Precaptación', 'description' => 'Registros de precaptación asignados al funcionario.',
      'table' => 'jet_cct_precaptaciones', 'where' => 'id_empleado', 'updates' => ['id_empleado' => 'id_empleado', 'creador' => 'nombre', 'sucursal' => 'id_sucursal'],
      'sample' => ['_ID', 'id_empleado', 'creador', 'id_ticket', 'barrio', 'direccion', 'resultado'], 'postmeta' => false,
    ],
    'retoques' => [
      'label' => 'Retoques', 'description' => 'Retoques asignados al funcionario.',
      'table' => 'jet_cct_retoques', 'where' => 'id_empleado', 'updates' => ['id_empleado' => 'id_empleado', 'creador' => 'nombre', 'sucursal' => 'id_sucursal'],
      'sample' => ['_ID', 'id_empleado', 'creador', 'id_ticket', 'id_inmueble', 'tipo_aviso', 'barrio'], 'postmeta' => false,
    ],
    'tickets' => [
      'label' => 'Tickets', 'description' => 'Tickets principales asignados al funcionario.',
      'table' => 'jet_cct_tickets', 'where' => 'id_empleado',
      'updates' => ['id_empleado' => 'id_empleado', 'empleado' => 'nombre', 'nombre_empleado' => 'nombre', 'correo_empleado' => 'correo', 'celular_empleado' => 'celular', 'sucursal' => 'id_sucursal'],
      'sample' => ['_ID', 'id_ticket', 'id_empleado', 'empleado', 'asunto', 'estado', 'id_inmueble'], 'postmeta' => false,
    ],
  ];
  public const BUSINESS_TYPES = ['Venta', 'Arriendo', 'Arriendo/Venta'];
  private const PREVIEW_LIMIT = 300;

  public function __construct(private Database $db, private SchemaInspector $schema)
  {
  }

  public function logTable(): string
  {
    return $this->db->table('gpo_transfer_log');
  }

  /** La tabla ya existe en producción (la creó el plugin); se crea igual solo si falta. */
  public function ensureSchema(): void
  {
    if ($this->ownTableExists($this->logTable())) {
      return;
    }
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->logTable()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, user_id BIGINT UNSIGNED NULL,
      origin_employee_id TEXT NULL, origin_name TEXT NULL, destination_employee_id TEXT NULL, destination_name TEXT NULL,
      modules LONGTEXT NULL, summary LONGTEXT NULL, total_records INT NOT NULL DEFAULT 0,
      postmeta_updated INT NOT NULL DEFAULT 0, postmeta_skipped INT NOT NULL DEFAULT 0, reason TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $this->created[$this->logTable()] = true;
  }

  /** Funcionarios (activos e inactivos: el origen suele ser quien se retira), módulos y últimos traslados. */
  public function data(): array
  {
    return [
      'funcionarios' => array_values($this->funcionarios()),
      'modules' => array_map(static fn(string $key, array $module): array => ['key' => $key, 'label' => $module['label'], 'description' => $module['description']], array_keys(self::MODULES), self::MODULES),
      'business_types' => self::BUSINESS_TYPES,
      'logs' => $this->logs(),
    ];
  }

  /** @return array<string,array{id_empleado:string,nombre:string,correo:string,celular:string,id_sucursal:string,activo:string}> */
  private function funcionarios(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $out = [];
    foreach ($this->db->getResults("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_empleado`, '')) <> '' ORDER BY `nombre` ASC") as $row) {
      $id = trim((string) $row['id_empleado']);
      $out[$id] = [
        'id_empleado' => $id, 'nombre' => trim((string) ($row['nombre'] ?? '')), 'correo' => trim((string) ($row['correo'] ?? '')),
        'celular' => trim((string) ($row['celular'] ?? '')), 'id_sucursal' => trim((string) ($row['id_sucursal'] ?? '')),
        'activo' => strtolower(trim((string) ($row['activo'] ?? ''))) === 'no' ? 'No' : 'Si',
      ];
    }
    return $out;
  }

  /** @param array<mixed> $modules @return list<string> */
  public static function normalizeModules(array $modules): array
  {
    $out = [];
    foreach ($modules as $module) {
      $key = sanitize_key((string) $module);
      if (isset(self::MODULES[$key])) {
        $out[$key] = $key;
      }
    }
    return array_values($out);
  }

  /** @param array<string,mixed> $options @return array{mode:string,selected_ids:list<int>,tipo_negocio:string} */
  public static function moduleOptions(string $module, array $options): array
  {
    $raw = is_array($options['modules'][$module] ?? null) ? $options['modules'][$module] : (is_array($options[$module] ?? null) ? $options[$module] : []);
    $mode = ($raw['mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $selected = OrganizationRepository::ids($raw['selected_ids'] ?? []);
    $type = trim((string) ($raw['filters']['tipo_negocio'] ?? ''));
    return ['mode' => $mode, 'selected_ids' => $selected, 'tipo_negocio' => in_array($type, self::BUSINESS_TYPES, true) ? $type : ''];
  }

  /** @param array{mode:string,selected_ids:list<int>,tipo_negocio:string} $options @return array{0:string,1:list<string|int>}|null */
  private function where(array $module, string $origin, array $options, bool $applySelected): ?array
  {
    $table = $this->db->table($module['table']);
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, $module['where'])) {
      return null;
    }
    $parts = ["TRIM(COALESCE(`{$module['where']}`, '')) = ?"];
    $args = [$origin];
    if ($options['tipo_negocio'] !== '' && $module['table'] === 'jet_cct_inmuebles' && $this->schema->columnExists($table, 'tipo_negocio')) {
      $parts[] = "TRIM(COALESCE(`tipo_negocio`, '')) = ?";
      $args[] = $options['tipo_negocio'];
    }
    if ($applySelected && $options['mode'] === 'selected') {
      if ($options['selected_ids'] === []) {
        $parts[] = '1 = 0';
      } else {
        $parts[] = '`_ID` IN (' . implode(',', array_fill(0, count($options['selected_ids']), '?')) . ')';
        array_push($args, ...$options['selected_ids']);
      }
    }
    return [implode(' AND ', $parts), $args];
  }

  /** @return list<array<string,string>> */
  private function sample(array $module, string $origin, array $options, bool $applySelected): array
  {
    $where = $this->where($module, $origin, $options, $applySelected);
    if ($where === null) {
      return [];
    }
    $table = $this->db->table($module['table']);
    $columns = array_values(array_filter($module['sample'], fn(string $column): bool => $this->schema->columnExists($table, $column)));
    $rows = $this->db->getResults('SELECT `' . implode('`,`', $columns) . "` FROM `{$table}` WHERE {$where[0]} ORDER BY `_ID` DESC LIMIT " . self::PREVIEW_LIMIT, $where[1]);
    return array_map(static fn(array $row): array => array_map(static fn($value): string => is_scalar($value) ? (string) $value : '', $row), $rows);
  }

  /** @param list<string> $modules @param array<string,mixed> $options */
  public function preview(string $originId, array $modules, array $options = []): array
  {
    $origin = $this->funcionarios()[trim($originId)] ?? null;
    if ($origin === null) {
      throw new \DomainException('Selecciona un funcionario origen válido.');
    }
    $preview = [];
    $total = 0;
    foreach (self::normalizeModules($modules) as $key) {
      $module = self::MODULES[$key];
      $moduleOptions = self::moduleOptions($key, $options);
      $where = $this->where($module, $origin['id_empleado'], $moduleOptions, false);
      $count = $where === null ? 0 : (int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->db->table($module['table'])}` WHERE {$where[0]}", $where[1]);
      $total += $count;
      $preview[] = ['key' => $key, 'label' => $module['label'], 'count' => $count, 'records' => $this->sample($module, $origin['id_empleado'], $moduleOptions, false)];
    }
    return ['origin' => $origin, 'modules' => $preview, 'total' => $total, 'message' => $total > 0 ? 'Previsualización lista.' : 'No hay registros para trasladar con ese origen.'];
  }

  /**
   * Ejecuta el traslado en una transacción y lo registra en wp_gpo_transfer_log.
   * @param list<string> $modules @param array{id:string,name:string} $actor @param array<string,mixed> $options
   */
  public function execute(string $originId, string $destinationId, array $modules, string $reason, array $actor, array $options = []): array
  {
    $people = $this->funcionarios();
    $origin = $people[trim($originId)] ?? null;
    $destination = $people[trim($destinationId)] ?? null;
    if ($origin === null || $destination === null) {
      throw new \DomainException('Funcionario origen o destino inválido.');
    }
    if ($origin['id_empleado'] === $destination['id_empleado']) {
      throw new \DomainException('El funcionario origen y destino no pueden ser el mismo.');
    }
    if ($destination['activo'] === 'No') {
      throw new \DomainException('El funcionario destino está inactivo.');
    }
    $selected = self::normalizeModules($modules);
    if ($selected === []) {
      throw new \DomainException('Selecciona al menos un módulo para trasladar.');
    }
    $this->ensureSchema();
    $summary = [];
    $total = 0;
    $metaUpdated = 0;
    $metaSkipped = 0;
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($selected as $key) {
        $module = self::MODULES[$key];
        $moduleOptions = self::moduleOptions($key, $options);
        if ($moduleOptions['mode'] === 'selected' && $moduleOptions['selected_ids'] === []) {
          throw new \DomainException('Selecciona al menos un registro de ' . $module['label'] . '.');
        }
        $result = $this->executeModule($module, $origin, $destination, $moduleOptions);
        $total += $result['updated'];
        $metaUpdated += $result['postmeta_updated'];
        $metaSkipped += $result['postmeta_skipped'];
        $summary[] = ['key' => $key, 'label' => $module['label'], 'updated' => $result['updated'], 'tables' => [$result]];
      }
      $this->db->insert($this->logTable(), [
        'created_at' => date('Y-m-d H:i:s'), 'user_id' => (int) $actor['id'],
        'origin_employee_id' => $origin['id_empleado'], 'origin_name' => $origin['nombre'],
        'destination_employee_id' => $destination['id_empleado'], 'destination_name' => $destination['nombre'],
        'modules' => (string) json_encode($selected, JSON_UNESCAPED_UNICODE), 'summary' => (string) json_encode($summary, JSON_UNESCAPED_UNICODE),
        'total_records' => $total, 'postmeta_updated' => $metaUpdated, 'postmeta_skipped' => $metaSkipped, 'reason' => $reason,
      ]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return ['message' => 'Traslado realizado correctamente.', 'total' => $total, 'postmeta_updated' => $metaUpdated, 'postmeta_skipped' => $metaSkipped, 'modules' => $summary, 'logs' => $this->logs()];
  }

  /** @return array{label:string,table:string,updated:int,postmeta_updated:int,postmeta_skipped:int,records:list<array<string,string>>,skipped?:bool} */
  private function executeModule(array $module, array $origin, array $destination, array $options): array
  {
    $table = $this->db->table($module['table']);
    $base = ['label' => $module['label'], 'table' => $table, 'updated' => 0, 'postmeta_updated' => 0, 'postmeta_skipped' => 0, 'records' => []];
    $where = $this->where($module, $origin['id_empleado'], $options, true);
    if ($where === null) {
      return $base + ['skipped' => true];
    }
    $records = $this->sample($module, $origin['id_empleado'], $options, true);
    $posts = $module['postmeta'] && $this->schema->columnExists($table, 'codigo')
      ? $this->db->getResults("SELECT `_ID`, `codigo` FROM `{$table}` WHERE {$where[0]}", $where[1])
      : [];
    $data = [];
    foreach ($module['updates'] as $column => $source) {
      if ($this->schema->columnExists($table, $column)) {
        $data[$column] = (string) ($destination[$source] ?? '');
      }
    }
    if ($this->schema->columnExists($table, 'cct_modified')) {
      $data['cct_modified'] = date('Y-m-d H:i:s');
    }
    if ($data === []) {
      return $base + ['skipped' => true];
    }
    $set = implode(', ', array_map(static fn(string $column): string => "`{$column}` = ?", array_keys($data)));
    $statement = $this->db->pdo()->prepare("UPDATE `{$table}` SET {$set} WHERE {$where[0]}");
    $statement->execute(array_merge(array_values($data), $where[1]));
    $updated = $statement->rowCount();
    [$metaUpdated, $metaSkipped] = $posts !== [] ? $this->syncPostmeta($posts, $destination) : [0, 0];
    return ['label' => $module['label'], 'table' => $table, 'updated' => $updated, 'postmeta_updated' => $metaUpdated, 'postmeta_skipped' => $metaSkipped, 'records' => $records];
  }

  /**
   * Postmeta del inmueble en WordPress (codigo = ID del post): id_empleado, empleado, email-empleado y
   * celular-empleado; y el autor del post si el destino es un usuario de WordPress. Igual que update_post_meta.
   * @param list<array<string,mixed>> $rows @return array{0:int,1:int}
   */
  private function syncPostmeta(array $rows, array $destination): array
  {
    $posts = $this->db->table('posts');
    $meta = $this->db->table('postmeta');
    if (!$this->schema->tableExists($posts) || !$this->schema->tableExists($meta)) {
      return [0, count($rows)];
    }
    $users = $this->db->table('users');
    $destinationUser = (int) $destination['id_empleado'];
    $canReassign = $destinationUser > 0 && $this->schema->tableExists($users) && (int) $this->db->getVar("SELECT COUNT(*) FROM `{$users}` WHERE `ID` = ?", [$destinationUser]) > 0;
    $values = ['id_empleado' => $destination['id_empleado'], 'empleado' => $destination['nombre'], 'email-empleado' => $destination['correo'], 'celular-empleado' => $destination['celular']];
    $updated = 0;
    $skipped = 0;
    foreach ($rows as $row) {
      $postId = (int) ($row['codigo'] ?? 0);
      if ($postId <= 0 || (int) $this->db->getVar("SELECT COUNT(*) FROM `{$posts}` WHERE `ID` = ?", [$postId]) === 0) {
        $skipped++;
        continue;
      }
      foreach ($values as $key => $value) {
        $changed = $this->db->update($meta, ['meta_value' => (string) $value], ['post_id' => $postId, 'meta_key' => $key]);
        if ($changed === 0 && (int) $this->db->getVar("SELECT COUNT(*) FROM `{$meta}` WHERE `post_id` = ? AND `meta_key` = ?", [$postId, $key]) === 0) {
          $this->db->insert($meta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => (string) $value]);
        }
      }
      if ($canReassign) {
        $this->db->update($posts, ['post_author' => $destinationUser], ['ID' => $postId]);
      }
      $updated++;
    }
    return [$updated, $skipped];
  }

  /** Últimos traslados con el detalle de los registros movidos. @return list<array<string,mixed>> */
  public function logs(int $limit = 8): array
  {
    if (!$this->ownTableExists($this->logTable())) {
      return [];
    }
    $limit = max(1, min(50, $limit));
    return array_map(static function (array $row): array {
      $summary = json_decode((string) ($row['summary'] ?? ''), true);
      return [
        'id' => (int) $row['id'], 'created_at' => (string) $row['created_at'],
        'origin_name' => (string) ($row['origin_name'] ?? ''), 'destination_name' => (string) ($row['destination_name'] ?? ''),
        'total_records' => (int) ($row['total_records'] ?? 0), 'postmeta_updated' => (int) ($row['postmeta_updated'] ?? 0),
        'postmeta_skipped' => (int) ($row['postmeta_skipped'] ?? 0), 'reason' => (string) ($row['reason'] ?? ''),
        'details' => self::logDetails(is_array($summary) ? $summary : []),
      ];
    }, $this->db->getResults("SELECT * FROM `{$this->logTable()}` ORDER BY `id` DESC LIMIT {$limit}"));
  }

  /** @param array<mixed> $summary @return list<array{label:string,updated:int,values:list<string>,more:int,legacy:bool}> */
  public static function logDetails(array $summary): array
  {
    $details = [];
    foreach ($summary as $module) {
      foreach ((is_array($module) ? (array) ($module['tables'] ?? []) : []) as $table) {
        if (!is_array($table)) {
          continue;
        }
        $values = [];
        foreach ((array) ($table['records'] ?? []) as $record) {
          $value = is_array($record) ? self::recordLabel($record) : '';
          if ($value !== '') {
            $values[$value] = $value;
          }
        }
        $updated = (int) ($table['updated'] ?? 0);
        if ($values === [] && $updated <= 0) {
          continue;
        }
        $visible = array_slice(array_values($values), 0, 30);
        $details[] = [
          'label' => (string) ($table['label'] ?? ($module['label'] ?? 'Registros')), 'updated' => $updated, 'values' => $visible,
          'more' => $values !== [] ? max(0, $updated - count($visible)) : 0, 'legacy' => $values === [] && $updated > 0,
        ];
      }
    }
    return $details;
  }

  /** @param array<string,mixed> $record */
  private static function recordLabel(array $record): string
  {
    foreach (['codigo' => 'Código ', 'id_ticket' => 'Ticket ', 'id_inmueble' => 'Inmueble ', '_ID' => '#'] as $key => $prefix) {
      if (trim((string) ($record[$key] ?? '')) !== '') {
        return $prefix . trim((string) $record[$key]);
      }
    }
    return '';
  }
}
