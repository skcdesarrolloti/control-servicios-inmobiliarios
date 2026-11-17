<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetPolicies;
use SCM\Support\SchemaInspector;

/**
 * Actividades contractuales › Gestor organizacional (antes plugin de WordPress [gestor_organizacional]).
 *
 * Lee y escribe los CCT de la estructura de la empresa: áreas, cargos, políticas, procesos, funciones, compensaciones
 * y funcionarios, con los mismos filtros del plugin. Las listas de ids (id_area, id_cargo, cargos_a_cargo) son texto
 * separado por comas, como las guarda JetEngine.
 *
 * Diferencias con el plugin:
 * - Eliminar deja rastro en wp_gpo_actividad (con copia del registro); un funcionario no se elimina: se desactiva.
 * - Las políticas llevan control documental (OrganizationPolicies).
 * - Funcionarios: solo se escribe el CCT. El usuario de WordPress (crear, roles y metas) no se sincroniza desde el panel
 *   porque el panel no corre WordPress; ver docs/gestor-organizacional.md › «Usuario de WordPress».
 */
final class OrganizationRepository
{
  /** tipo => [singular, plural] */
  public const TYPES = [
    'areas_organizacion' => ['Área', 'Áreas'],
    'cargos' => ['Cargo', 'Cargos'],
    'politicas' => ['Política', 'Políticas'],
    'procesos' => ['Proceso', 'Procesos'],
    'funciones' => ['Función', 'Funciones'],
    'compensaciones' => ['Compensación', 'Compensaciones'],
    'funcionarios' => ['Funcionario', 'Funcionarios'],
  ];
  /** Columna con el nombre visible del registro (confirmaciones y bitácora). */
  public const NAME_COLUMN = [
    'areas_organizacion' => 'nombre', 'cargos' => 'nombre_cargo', 'politicas' => 'titulo', 'procesos' => 'nombre',
    'funciones' => 'funcion', 'compensaciones' => 'base_compensacion', 'funcionarios' => 'nombre',
  ];
  /** Búsqueda por título y por detalle, igual que el plugin. */
  private const SEARCH = [
    'areas_organizacion' => ['title' => ['nombre'], 'detail' => ['descripcion']],
    'cargos' => ['title' => ['nombre_cargo'], 'detail' => ['mision_cargo', 'funcion_principal']],
    'politicas' => ['title' => ['titulo', 'codigo'], 'detail' => ['politica']],
    'procesos' => ['title' => ['nombre'], 'detail' => ['definicion', 'alcance', 'entregable']],
    'funciones' => ['title' => ['funcion'], 'detail' => ['frecuencia']],
    'compensaciones' => ['title' => ['base_compensacion'], 'detail' => ['condicionado_a']],
    'funcionarios' => ['title' => ['nombre'], 'detail' => ['correo', 'correo_dian', 'celular', 'rol', 'gestion', 'rol_academia', 'documento']],
  ];
  /** Nunca salen al navegador. */
  private const HIDDEN_COLUMNS = ['pass_others_apss', 'user_others_apss'];
  /** Perfil del cargo: columna => título (solo se muestran las que tengan texto). */
  public const CARGO_PROFILE = [
    'funcion_principal' => 'Función principal', 'mision_cargo' => 'Misión', 'kpis' => 'KPIs',
    'competencias_tecnicas' => 'Competencias técnicas', 'competencias_blandas' => 'Competencias blandas',
    'requisitos_academicos' => 'Requisitos académicos', 'experiencia_requerida' => 'Experiencia requerida',
    'tipo_contrato' => 'Tipo de contrato', 'modalidad_trabajo' => 'Modalidad de trabajo',
    'valores_institucionales' => 'Valores institucionales', 'beneficios' => 'Beneficios',
  ];
  public const LIST_LIMIT = 500;
  /** Cargo comercial cuyo funcionario queda como comercial de la sucursal (como en el plugin). */
  private const COMMERCIAL_CARGO = 1;

  private SchemaInspector $schema;
  private OrganizationActivityLog $log;
  private OrganizationPolicies $policies;

  public function __construct(private Database $db, ?SchemaInspector $schema = null)
  {
    $this->schema = $schema ?? new SchemaInspector($db);
    $this->log = new OrganizationActivityLog($db, $this->schema);
    $this->policies = new OrganizationPolicies($db, $this->schema);
  }

  public function policies(): OrganizationPolicies
  {
    return $this->policies;
  }

  public function activityLog(): OrganizationActivityLog
  {
    return $this->log;
  }

  // --- Utilidades ------------------------------------------------------------------------------------------------

  /** «7, 11;9|6» o [7, '11'] → [7, 11, 9, 6] sin repetidos. @return list<int> */
  public static function ids(mixed $raw): array
  {
    $values = is_array($raw) ? $raw : (preg_split('/[,\s;|]+/', trim((string) $raw)) ?: []);
    $out = [];
    foreach ($values as $value) {
      $id = is_scalar($value) ? (int) trim((string) $value) : 0;
      if ($id > 0) {
        $out[$id] = $id;
      }
    }
    return array_values($out);
  }

  public static function csv(mixed $raw): string
  {
    return implode(',', self::ids($raw));
  }

  /** Lista de ids normalizada en SQL, para FIND_IN_SET. */
  private static function setExpr(string $column): string
  {
    return "REPLACE(REPLACE(REPLACE(COALESCE(`{$column}`, ''), ' ', ''), ';', ','), '|', ',')";
  }

  public function isType(string $type): bool
  {
    return isset(self::TYPES[$type]);
  }

  private function assertType(string $type): void
  {
    if (!$this->isType($type)) {
      throw new \DomainException('Tipo de registro inválido.');
    }
  }

  public function table(string $type): string
  {
    return $this->db->table('jet_cct_' . $type);
  }

  private function has(string $table, string $column): bool
  {
    return $this->schema->columnExists($table, $column);
  }

  /** @param array<string,mixed> $input */
  private static function text(array $input, string $key, int $max = 250): string
  {
    $value = $input[$key] ?? '';
    return is_scalar($value) ? mb_substr(trim(sanitize_text_field((string) $value)), 0, $max, 'UTF-8') : '';
  }

  /** @param array<string,mixed> $input */
  private static function longText(array $input, string $key): string
  {
    $value = $input[$key] ?? '';
    return is_scalar($value) ? trim(strip_tags(sanitize_textarea_field(str_replace(["\r\n", "\r"], "\n", (string) $value)))) : '';
  }

  public static function recordName(string $type, array $row): string
  {
    $name = trim((string) ($row[self::NAME_COLUMN[$type] ?? 'nombre'] ?? ''));
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if (mb_strlen($name, 'UTF-8') > 90) {
      $name = rtrim(mb_substr($name, 0, 90, 'UTF-8')) . '…';
    }
    return $name !== '' ? $name : (self::TYPES[$type][0] ?? 'Registro') . ' #' . (int) ($row['_ID'] ?? 0);
  }

  // --- Catálogos -------------------------------------------------------------------------------------------------

  /** Áreas, cargos, sucursales, procesos, funcionarios activos y roles de WordPress (solo lectura) para los formularios. */
  public function catalog(): array
  {
    $rows = function (string $type, string $select, string $order): array {
      $table = $this->table($type);
      return $this->schema->tableExists($table) ? $this->db->getResults("SELECT {$select} FROM `{$table}` ORDER BY {$order}") : [];
    };
    $cargoTable = $this->table('cargos');
    $cargoSelect = '`_ID` AS id, `nombre_cargo` AS nombre'
      . ($this->has($cargoTable, 'id_area') ? ', `id_area`' : ", '' AS id_area")
      . ($this->has($cargoTable, 'cargos_a_cargo') ? ', `cargos_a_cargo`' : ", '' AS cargos_a_cargo");
    $sucursalTable = $this->db->table('jet_cct_sucursales');
    return [
      'areas' => array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'nombre' => trim((string) $row['nombre'])], $rows('areas_organizacion', '`_ID` AS id, `nombre`', '`nombre` ASC')),
      'cargos' => array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'nombre' => trim((string) $row['nombre']), 'id_area' => self::csv($row['id_area'] ?? ''), 'cargos_a_cargo' => self::csv($row['cargos_a_cargo'] ?? '')], $rows('cargos', $cargoSelect, '`nombre_cargo` ASC')),
      'procesos' => array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'nombre' => trim((string) $row['nombre'])], $rows('procesos', '`_ID` AS id, `nombre`', '`nombre` ASC')),
      'sucursales' => $this->schema->tableExists($sucursalTable)
        ? array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'nombre' => trim((string) $row['nombre'])], $this->db->getResults("SELECT `_ID` AS id, `nombre` FROM `{$sucursalTable}` ORDER BY `nombre` ASC"))
        : [],
      'funcionarios' => array_map(static fn(array $row): array => ['id' => (string) $row['id_empleado'], 'nombre' => (string) $row['nombre']], $this->activeFuncionarios()),
      'wp_roles' => $this->wordpressRoles(),
    ];
  }

  /** Funcionarios activos (activo = Si) con id_empleado. @return list<array{id_empleado:string,nombre:string,id_cargo:string}> */
  public function activeFuncionarios(): array
  {
    $table = $this->table('funcionarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    return array_map(static fn(array $row): array => [
      'id_empleado' => trim((string) $row['id_empleado']), 'nombre' => trim((string) $row['nombre']), 'id_cargo' => self::csv($row['id_cargo'] ?? ''),
    ], $this->db->getResults(
      "SELECT `id_empleado`, `nombre`, " . ($this->has($table, 'id_cargo') ? '`id_cargo`' : "''") . " AS id_cargo FROM `{$table}`
        WHERE TRIM(COALESCE(`id_empleado`, '')) <> '' AND LOWER(TRIM(COALESCE(`activo`, ''))) = 'si' ORDER BY `nombre` ASC"
    ));
  }

  /** Roles de WordPress leídos de wp_options (solo para mostrar el rol de cada funcionario). @return list<array{id:string,nombre:string}> */
  private function wordpressRoles(): array
  {
    $options = $this->db->table('options');
    if (!$this->schema->tableExists($options)) {
      return [];
    }
    $raw = $this->db->getVar("SELECT `option_value` FROM `{$options}` WHERE `option_name` = ? LIMIT 1", [$this->db->prefix() . 'user_roles']);
    $roles = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
    if (!is_array($roles)) {
      return [];
    }
    $out = [];
    foreach ($roles as $slug => $role) {
      $out[] = ['id' => (string) $slug, 'nombre' => (string) (is_array($role) ? ($role['name'] ?? $slug) : $slug)];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a['nombre'], $b['nombre']));
    return $out;
  }

  // --- Listados --------------------------------------------------------------------------------------------------

  /**
   * Registros del tipo con los filtros del plugin (área, cargo, activo, título y detalle) y, en políticas, estado,
   * audiencia y «sin área». Máximo LIST_LIMIT, los más recientes primero.
   * @param array<string,mixed> $filters @return array{rows:list<array<string,mixed>>,total:int,limited:bool}
   */
  public function list(string $type, array $filters = []): array
  {
    $this->assertType($type);
    $table = $this->table($type);
    if (!$this->schema->tableExists($table)) {
      return ['rows' => [], 'total' => 0, 'limited' => false];
    }
    $where = ['1=1'];
    $args = [];
    $area = (int) ($filters['id_area'] ?? 0);
    if ($area > 0) {
      $areaWhere = [];
      if ($this->has($table, 'id_area')) {
        $areaWhere[] = 'FIND_IN_SET(?, ' . self::setExpr('id_area') . ') > 0';
        $args[] = (string) $area;
      }
      // Funcionarios sin áreas propias: las de sus cargos (como las muestra el listado).
      if ($type === 'funcionarios' && $this->has($table, 'id_cargo')) {
        foreach ($this->cargoIdsByArea($area) as $cargoId) {
          $areaWhere[] = 'FIND_IN_SET(?, ' . self::setExpr('id_cargo') . ') > 0';
          $args[] = (string) $cargoId;
        }
      }
      $where[] = $areaWhere !== [] ? '(' . implode(' OR ', $areaWhere) . ')' : '1=0';
    }
    $cargo = (int) ($filters['id_cargo'] ?? 0);
    if ($cargo > 0 && $this->has($table, 'id_cargo')) {
      $where[] = 'FIND_IN_SET(?, ' . self::setExpr('id_cargo') . ') > 0';
      $args[] = (string) $cargo;
    }
    $activo = strtolower(trim((string) ($filters['activo'] ?? '')));
    if ($type === 'funcionarios' && in_array($activo, ['si', 'no'], true) && $this->has($table, 'activo')) {
      $where[] = "LOWER(TRIM(COALESCE(`activo`, ''))) = ?";
      $args[] = $activo;
    }
    foreach (['title' => 'titulo', 'detail' => 'detalle'] as $scope => $key) {
      $term = trim(sanitize_text_field((string) ($filters[$key] ?? '')));
      $columns = array_values(array_filter(self::SEARCH[$type][$scope], fn(string $column): bool => $this->has($table, $column)));
      if ($term === '' || $columns === []) {
        continue;
      }
      $like = '%' . $this->db->escapeLike($term) . '%';
      $where[] = '(' . implode(' OR ', array_map(static fn(string $column): string => "`{$column}` LIKE ?", $columns)) . ')';
      array_push($args, ...array_fill(0, count($columns), $like));
    }
    if ($type === 'politicas') {
      foreach (['estado' => OrganizationPolicies::STATES, 'audiencia' => OrganizationPolicies::AUDIENCES] as $column => $allowed) {
        $value = trim((string) ($filters[$column] ?? ''));
        if ($value === '' || !$this->has($table, $column)) {
          continue;
        }
        if ($value === 'sin') {
          $where[] = "TRIM(COALESCE(`{$column}`, '')) = ''";
        } elseif (in_array($value, $allowed, true)) {
          $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
          $args[] = $value;
        }
      }
      if (($filters['sin_area'] ?? '') === '1' && $this->has($table, 'id_area')) {
        $where[] = "TRIM(COALESCE(`id_area`, '')) = ''";
      }
    }
    $sqlWhere = implode(' AND ', $where);
    $total = (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE {$sqlWhere}", $args);
    $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE {$sqlWhere} ORDER BY `_ID` DESC LIMIT " . self::LIST_LIMIT, $args);
    if ($type === 'funcionarios') {
      $rows = $this->hydrateFuncionarios($rows);
    }
    return [
      'rows' => array_map(fn(array $row): array => $this->present($type, $row, false), $rows),
      'total' => $total,
      'limited' => $total > count($rows),
    ];
  }

  /** @return array<string,mixed>|null */
  public function find(string $type, int $id): ?array
  {
    $this->assertType($type);
    if ($id <= 0 || !$this->schema->tableExists($this->table($type))) {
      return null;
    }
    return $this->db->getRow("SELECT * FROM `{$this->table($type)}` WHERE `_ID` = ? LIMIT 1", [$id]);
  }

  /** Fila lista para el navegador: sin credenciales y, en políticas, con HTML limpio. @param array<string,mixed> $row */
  public function present(string $type, array $row, bool $full = true): array
  {
    foreach (self::HIDDEN_COLUMNS as $column) {
      unset($row[$column]);
    }
    $row['_ID'] = (int) ($row['_ID'] ?? 0);
    $row['_nombre'] = self::recordName($type, $row);
    if ($type === 'politicas') {
      return OrganizationPolicies::present($row, $full);
    }
    return $row;
  }

  /** @return list<int> */
  private function cargoIdsByArea(int $areaId): array
  {
    $table = $this->table('cargos');
    if (!$this->has($table, 'id_area')) {
      return [];
    }
    return array_map('intval', $this->db->getCol("SELECT `_ID` FROM `{$table}` WHERE FIND_IN_SET(?, " . self::setExpr('id_area') . ') > 0', [(string) $areaId]));
  }

  /**
   * Como el plugin: si el CCT no tiene cargo, área o sucursal, se usan los metas del usuario de WordPress; y se muestra
   * su rol de WordPress. Solo lectura.
   * @param list<array<string,mixed>> $rows @return list<array<string,mixed>>
   */
  private function hydrateFuncionarios(array $rows): array
  {
    $usermeta = $this->db->table('usermeta');
    $ids = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id_empleado'] ?? 0), $rows)));
    if ($ids === [] || !$this->schema->tableExists($usermeta)) {
      return $rows;
    }
    $capabilities = $this->db->prefix() . 'capabilities';
    $keys = ['id-cargo', 'id-area', 'id-sucursal', 'sucursal-user', $capabilities];
    $meta = [];
    $sql = "SELECT `user_id`, `meta_key`, `meta_value` FROM `{$usermeta}` WHERE `user_id` IN (" . implode(',', array_fill(0, count($ids), '?')) . ') AND `meta_key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
    foreach ($this->db->getResults($sql, array_merge($ids, $keys)) as $item) {
      $meta[(int) $item['user_id']][(string) $item['meta_key']] = (string) $item['meta_value'];
    }
    $roleNames = array_column($this->wordpressRoles(), 'nombre', 'id');
    foreach ($rows as &$row) {
      $user = $meta[(int) ($row['id_empleado'] ?? 0)] ?? [];
      foreach (['id_cargo' => ['id-cargo'], 'id_area' => ['id-area'], 'id_sucursal' => ['id-sucursal', 'sucursal-user']] as $column => $metaKeys) {
        if (trim((string) ($row[$column] ?? '')) !== '') {
          continue;
        }
        foreach ($metaKeys as $key) {
          if (trim((string) ($user[$key] ?? '')) !== '') {
            $row[$column] = self::csv($user[$key]);
            break;
          }
        }
      }
      $caps = isset($user[$capabilities]) ? @unserialize($user[$capabilities], ['allowed_classes' => false]) : null;
      $role = is_array($caps) ? (string) (array_keys(array_filter($caps))[0] ?? '') : '';
      $row['user_role'] = $role;
      $row['user_role_label'] = $role !== '' ? (string) ($roleNames[$role] ?? $role) : '';
    }
    unset($row);
    return $rows;
  }

  // --- Guardar y eliminar ----------------------------------------------------------------------------------------

  /**
   * Crea o edita un registro. En políticas, $newVersion sube la versión (exige motivo). Devuelve el registro guardado
   * y avisos (por ejemplo, que el usuario de WordPress no se sincronizó).
   * @param array<string,mixed> $input @param array{id:string,name:string} $actor
   * @return array{id:int,record:array<string,mixed>,warnings:list<string>,created:bool}
   */
  public function save(string $type, int $id, array $input, array $actor, bool $newVersion = false): array
  {
    $this->assertType($type);
    $table = $this->table($type);
    if (!$this->schema->tableExists($table)) {
      throw new \DomainException('No existe la tabla de ' . mb_strtolower(self::TYPES[$type][1], 'UTF-8') . '.');
    }
    $existing = $id > 0 ? $this->find($type, $id) : null;
    if ($id > 0 && $existing === null) {
      throw new \DomainException('El registro ya no existe. Actualiza la lista.');
    }
    $warnings = [];
    $data = match ($type) {
      'areas_organizacion' => $this->required(['nombre' => self::text($input, 'nombre'), 'descripcion' => self::longText($input, 'descripcion'), 'responsable' => self::text($input, 'responsable', 50)], 'nombre', 'Escribe el nombre del área.'),
      'cargos' => $this->required([
        'nombre_cargo' => self::text($input, 'nombre_cargo'), 'mision_cargo' => self::longText($input, 'mision_cargo'),
        'funcion_principal' => self::longText($input, 'funcion_principal'), 'kpis' => self::longText($input, 'kpis'),
        'id_area' => self::csv($input['id_area'] ?? ''), 'cargos_a_cargo' => self::csv(array_diff(self::ids($input['cargos_a_cargo'] ?? ''), [$id])),
      ], 'nombre_cargo', 'Escribe el nombre del cargo.'),
      'procesos' => $this->required([
        'nombre' => self::text($input, 'nombre'), 'definicion' => self::longText($input, 'definicion'), 'alcance' => self::longText($input, 'alcance'),
        'entregable' => self::longText($input, 'entregable'), 'id_area' => self::csv($input['id_area'] ?? ''),
      ], 'nombre', 'Escribe el nombre del proceso.'),
      'funciones' => $this->required([
        'funcion' => self::longText($input, 'funcion'), 'frecuencia' => self::text($input, 'frecuencia'),
        'id_cargo' => self::csv($input['id_cargo'] ?? ''), 'id_area' => self::csv($input['id_area'] ?? ''),
      ], 'funcion', 'Escribe la función.'),
      'compensaciones' => $this->required([
        'base_compensacion' => self::longText($input, 'base_compensacion'), 'condicionado_a' => self::longText($input, 'condicionado_a'),
        'id_area' => self::csv($input['id_area'] ?? ''), 'id_cargo' => self::csv($input['id_cargo'] ?? ''),
      ], 'base_compensacion', 'Escribe la base de la compensación.'),
      'politicas' => $this->policies->payload($input, $existing, $newVersion),
      'funcionarios' => $this->funcionarioPayload($input, $existing, $warnings),
    };
    $now = date('Y-m-d H:i:s');
    $data['cct_modified'] = $now;
    if ($existing === null) {
      $data['cct_created'] = $now;
      // cct_author_id: el id_empleado real de quien crea (AGENTS.md).
      $data['cct_author_id'] = (int) $actor['id'];
    }
    $data = $this->schema->filterTableData($table, $data);

    $this->log->ensureSchema();
    if ($type === 'politicas') {
      $this->policies->ensureSchema();
    }
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      if ($existing !== null) {
        $this->db->update($table, $data, ['_ID' => $id]);
      } else {
        $this->db->insert($table, $data);
        $id = (int) $this->db->lastInsertId();
      }
      $saved = $this->find($type, $id) ?? [];
      if ($type === 'funcionarios') {
        $this->syncSucursalCommercial($id, $saved);
      }
      $action = $existing === null ? 'crear' : ($newVersion ? 'nueva_version' : 'editar');
      if ($type === 'politicas') {
        $this->policies->record($saved, $existing === null ? 'creada' : ($newVersion ? 'nueva_version' : 'editada'), $actor, $newVersion ? (string) ($data['motivo_cambio'] ?? '') : '');
      }
      $this->log->log($type, $id, $action, $this->summary($type, $action, $saved, $existing), $actor, $existing !== null ? ['cambios' => $this->changes($existing, $saved)] : []);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return ['id' => $id, 'record' => $this->present($type, $saved), 'warnings' => $warnings, 'created' => $existing === null];
  }

  /** @param array<string,string> $data @return array<string,string> */
  private function required(array $data, string $key, string $message): array
  {
    if (trim($data[$key] ?? '') === '') {
      throw new \DomainException($message);
    }
    return $data;
  }

  /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<string> columnas que cambiaron */
  private function changes(array $before, array $after): array
  {
    $out = [];
    foreach ($after as $key => $value) {
      if (in_array($key, ['cct_modified', 'fecha_actualizacion'], true) || in_array($key, self::HIDDEN_COLUMNS, true)) {
        continue;
      }
      if ((string) ($before[$key] ?? '') !== (string) $value) {
        $out[] = (string) $key;
      }
    }
    return $out;
  }

  private function summary(string $type, string $action, array $row, ?array $before): string
  {
    $label = mb_strtolower(self::TYPES[$type][0], 'UTF-8');
    $text = (OrganizationActivityLog::ACTIONS[$action] ?? $action) . ' ' . $label . ' «' . self::recordName($type, $row) . '»';
    if ($type === 'politicas' && $action === 'nueva_version') {
      $text .= ' (versión ' . max(1, (int) ($row['version'] ?? 1)) . ')';
    }
    if ($before !== null && $action === 'editar') {
      $changed = $this->changes($before, $row);
      $text .= $changed !== [] ? ': ' . implode(', ', $changed) : ' sin cambios';
    }
    return $text;
  }

  /**
   * Elimina el registro (borrado físico, como el plugin) y deja en la bitácora quién lo hizo con una copia del registro.
   * Un funcionario no se elimina: se desactiva.
   * @param array{id:string,name:string} $actor @return array{message:string,action:string}
   */
  public function delete(string $type, int $id, array $actor): array
  {
    $this->assertType($type);
    if ($type === 'funcionarios') {
      return $this->deactivate($id, $actor);
    }
    $row = $this->find($type, $id);
    if ($row === null) {
      throw new \DomainException('El registro ya no existe. Actualiza la lista.');
    }
    $name = self::recordName($type, $row);
    $this->log->ensureSchema();
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->delete($this->table($type), ['_ID' => $id]);
      $this->log->log($type, $id, 'eliminar', $this->summary($type, 'eliminar', $row, null), $actor, ['registro' => array_diff_key($row, array_flip(self::HIDDEN_COLUMNS))]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return ['message' => self::TYPES[$type][0] . ' «' . $name . '» eliminada.', 'action' => 'eliminar'];
  }

  /**
   * Desactiva un funcionario: activo = No en el CCT (ya no entra al panel ni aparece en los selectores de activos).
   * El meta activo-empleado de WordPress, que usaba el plugin para bloquear el ingreso a WordPress, queda pendiente.
   * @param array{id:string,name:string} $actor @return array{message:string,action:string}
   */
  public function deactivate(int $id, array $actor): array
  {
    $row = $this->find('funcionarios', $id);
    if ($row === null) {
      throw new \DomainException('El funcionario ya no existe. Actualiza la lista.');
    }
    if (trim((string) ($row['id_empleado'] ?? '')) !== '' && trim((string) $row['id_empleado']) === trim((string) $actor['id'])) {
      throw new \DomainException('No puedes desactivarte a ti mismo.');
    }
    $name = self::recordName('funcionarios', $row);
    if (strtolower(trim((string) ($row['activo'] ?? ''))) === 'no') {
      return ['message' => $name . ' ya estaba inactivo.', 'action' => 'ninguna'];
    }
    $this->log->ensureSchema();
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->update($this->table('funcionarios'), $this->schema->filterTableData($this->table('funcionarios'), ['activo' => 'No', 'cct_modified' => date('Y-m-d H:i:s')]), ['_ID' => $id]);
      $this->log->log('funcionarios', $id, 'desactivar', 'Desactivó funcionario «' . $name . '»', $actor, ['activo_antes' => (string) ($row['activo'] ?? '')]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return ['message' => $name . ' quedó inactivo.', 'action' => 'desactivar'];
  }

  /**
   * Qué queda apuntando al registro si se elimina (para el aviso de confirmación).
   * @return array{name:string,type_label:string,items:list<string>}
   */
  public function deleteImpact(string $type, int $id): array
  {
    $row = $this->find($type, $id);
    if ($row === null) {
      throw new \DomainException('El registro ya no existe. Actualiza la lista.');
    }
    $items = [];
    $count = function (string $other, string $column) use ($id): int {
      $table = $this->table($other);
      return $this->schema->tableExists($table) && $this->has($table, $column)
        ? (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE FIND_IN_SET(?, " . self::setExpr($column) . ') > 0', [(string) $id])
        : 0;
    };
    $add = static function (int $total, string $one, string $many) use (&$items): void {
      if ($total > 0) {
        $items[] = $total . ' ' . ($total === 1 ? $one : $many);
      }
    };
    if ($type === 'areas_organizacion') {
      $add($count('cargos', 'id_area'), 'cargo', 'cargos');
      $add($count('politicas', 'id_area'), 'política', 'políticas');
      $add($count('procesos', 'id_area'), 'proceso', 'procesos');
      $add($count('funciones', 'id_area'), 'función', 'funciones');
      $add($count('compensaciones', 'id_area'), 'compensación', 'compensaciones');
      $add($count('funcionarios', 'id_area'), 'funcionario', 'funcionarios');
    } elseif ($type === 'cargos') {
      $add($count('funcionarios', 'id_cargo'), 'funcionario', 'funcionarios');
      $add($count('politicas', 'id_cargo'), 'política', 'políticas');
      $add($count('funciones', 'id_cargo'), 'función', 'funciones');
      $add($count('compensaciones', 'id_cargo'), 'compensación', 'compensaciones');
      $add($count('cargos', 'cargos_a_cargo'), 'cargo que lo tiene a cargo', 'cargos que lo tienen a cargo');
    } elseif ($type === 'politicas') {
      try {
        $rules = (new ClosingSheetPolicies($this->db))->rules();
        $uses = count(array_filter($rules, static fn(array $rule): bool => in_array($id, $rule['policies'], true)));
        $add($uses, 'regla de la lista de chequeo de la hoja de cierre', 'reglas de la lista de chequeo de la hoja de cierre');
      } catch (\Throwable $error) {
        // Sin configuración de la hoja de cierre no hay reglas que avisar.
      }
      if ($this->has($this->table('politicas'), 'id_politica_base')) {
        $add((int) $this->db->getVar("SELECT COUNT(*) FROM `{$this->table('politicas')}` WHERE TRIM(COALESCE(`id_politica_base`, '')) = ?", [(string) $id]), 'versión para proveedores', 'versiones para proveedores');
      }
    }
    return ['name' => self::recordName($type, $row), 'type_label' => self::TYPES[$type][0], 'items' => $items];
  }

  // --- Funcionarios ----------------------------------------------------------------------------------------------

  /**
   * Datos del funcionario para el CCT. Al crear, se enlaza con un usuario de WordPress que ya exista (por id o por
   * correo): el panel no puede crear usuarios de WordPress todavía.
   * @param array<string,mixed> $input @param array<string,mixed>|null $existing @param list<string> $warnings
   * @return array<string,string>
   */
  private function funcionarioPayload(array $input, ?array $existing, array &$warnings): array
  {
    $email = static fn(string $key): string => strtolower(trim(sanitize_email((string) ($input[$key] ?? ''))));
    $data = [
      'nombre' => self::text($input, 'nombre'),
      'documento' => self::text($input, 'documento', 40),
      'correo' => $email('correo'),
      'correo_dian' => $email('correo_dian'),
      'celular' => self::text($input, 'celular', 30),
      'rol' => self::normalizeRole(self::text($input, 'rol')),
      'gestion' => self::normalizeGestion(self::text($input, 'gestion')),
      'rol_academia' => self::normalizeAcademia(self::text($input, 'rol_academia')),
      'id_sucursal' => (string) (self::ids($input['id_sucursal'] ?? '')[0] ?? ''),
      'id_cargo' => self::csv($input['id_cargo'] ?? ''),
      'activo' => strtolower(self::text($input, 'activo')) === 'no' ? 'No' : 'Si',
    ];
    if ($data['nombre'] === '') {
      throw new \DomainException('Escribe el nombre del funcionario.');
    }
    foreach (['correo' => 'El correo', 'correo_dian' => 'El correo DIAN'] as $key => $label) {
      if ($data[$key] !== '' && !filter_var($data[$key], FILTER_VALIDATE_EMAIL)) {
        throw new \DomainException($label . ' no es válido.');
      }
    }
    // Áreas: las elegidas o, si no se eligió ninguna, las de sus cargos (como el plugin).
    $areas = self::ids($input['id_area'] ?? '');
    $data['id_area'] = $areas !== [] ? implode(',', $areas) : implode(',', $this->areasOfCargos(self::ids($data['id_cargo'])));

    if ($existing === null) {
      $data['id_empleado'] = $this->linkWordPressUser(self::text($input, 'id_empleado', 20), $data['correo']);
    }
    $warnings[] = 'El usuario de WordPress (rol, sucursal y metas) no se actualiza desde el panel: queda pendiente de la sincronización con WordPress.';
    return $data;
  }

  /** id del usuario de WordPress que ya existe (por id o por correo) y que aún no es funcionario. */
  private function linkWordPressUser(string $requestedId, string $email): string
  {
    $users = $this->db->table('users');
    if (!$this->schema->tableExists($users)) {
      throw new \DomainException('No se encontró la tabla de usuarios de WordPress.');
    }
    $userId = 0;
    if ((int) $requestedId > 0) {
      $userId = (int) $this->db->getVar("SELECT `ID` FROM `{$users}` WHERE `ID` = ? LIMIT 1", [(int) $requestedId]);
      if ($userId <= 0) {
        throw new \DomainException('No existe el usuario de WordPress #' . (int) $requestedId . '.');
      }
    } elseif ($email !== '') {
      $userId = (int) $this->db->getVar("SELECT `ID` FROM `{$users}` WHERE LOWER(`user_email`) = ? LIMIT 1", [$email]);
    }
    if ($userId <= 0) {
      throw new \DomainException('No hay un usuario de WordPress con ese correo. Mientras la creación de usuarios se habilita en el panel, créalo primero en WordPress y escribe aquí su ID o su mismo correo.');
    }
    $table = $this->table('funcionarios');
    if ((int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE TRIM(COALESCE(`id_empleado`, '')) = ?", [(string) $userId]) > 0) {
      throw new \DomainException('El usuario de WordPress #' . $userId . ' ya es un funcionario.');
    }
    return (string) $userId;
  }

  /** @param list<int> $cargoIds @return list<int> */
  private function areasOfCargos(array $cargoIds): array
  {
    $table = $this->table('cargos');
    if ($cargoIds === [] || !$this->has($table, 'id_area')) {
      return [];
    }
    $areas = [];
    foreach ($this->db->getCol("SELECT `id_area` FROM `{$table}` WHERE `_ID` IN (" . implode(',', array_fill(0, count($cargoIds), '?')) . ')', $cargoIds) as $csv) {
      $areas = array_merge($areas, self::ids($csv));
    }
    return array_values(array_unique($areas));
  }

  /** Funcionario del cargo comercial (1): queda como comercial de su sucursal (solo el CCT de sucursales). */
  private function syncSucursalCommercial(int $recordId, array $row): void
  {
    $sucursal = self::ids($row['id_sucursal'] ?? '')[0] ?? 0;
    $table = $this->db->table('jet_cct_sucursales');
    if ($recordId <= 0 || $sucursal <= 0 || !in_array(self::COMMERCIAL_CARGO, self::ids($row['id_cargo'] ?? ''), true) || !$this->schema->tableExists($table)) {
      return;
    }
    if (strtolower(trim((string) ($row['activo'] ?? 'si'))) === 'no') {
      return;
    }
    $email = trim((string) ($row['correo'] ?? ''));
    $update = ['id_comercial' => (string) $recordId];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $update['correo_comercial'] = $email;
    }
    $update = $this->schema->filterTableData($table, $update);
    if ($update !== []) {
      $this->db->update($table, $update, ['_ID' => $sucursal]);
    }
  }

  private static function normalizeRole(string $value): string
  {
    $map = [
      'captacion y actualizacion' => 'Comercial', 'comercial' => 'Comercial', 'promocion y colocacion' => 'Promocion', 'promocion' => 'Promocion',
      'servicio al cliente' => 'Servicio al cliente', 'servicio al propietario' => 'Servicio al propietario', 'servicio al arrendatario' => 'Servicio al arrendatario',
      'contractual' => 'Contractual', 'mantenimiento' => 'Mantenimiento', 'servicios inmobiliarios' => 'Servicios inmobiliarios', 'servicios inmibiliarios' => 'Servicios inmobiliarios',
      'gerencia' => 'Gerencia', 'gerencia externa' => 'Gerencia externa', 'desarrollo' => 'Desarrollo', 'soporte' => 'Soporte', 'avaluo' => 'Avaluo',
      'contable' => 'Contable', 'todos' => 'Todos', 'precaptador' => 'Precaptador',
    ];
    $key = (string) preg_replace('/\s+/', ' ', OrganizationPolicies::fold($value));
    return $map[$key] ?? trim($value);
  }

  private static function normalizeGestion(string $value): string
  {
    $key = str_replace(' ', '', OrganizationPolicies::fold($value));
    return match (true) {
      $key === 'arriendo' => 'Arriendo',
      $key === 'venta' => 'Venta',
      in_array($key, ['arriendo/venta', 'arriendo-venta', 'arriendoyventa'], true) => 'Arriendo/Venta',
      default => trim($value),
    };
  }

  private static function normalizeAcademia(string $value): string
  {
    return match (OrganizationPolicies::fold($value)) {
      'estudiante' => 'Estudiante',
      'administrador' => 'Administrador',
      default => trim($value),
    };
  }

  // --- Mi gestión y gestión por cargo -----------------------------------------------------------------------------

  /** @return array<string,mixed>|null */
  public function funcionarioByEmployee(string $employeeId): ?array
  {
    $employeeId = trim($employeeId);
    $table = $this->table('funcionarios');
    if ($employeeId === '' || !$this->schema->tableExists($table)) {
      return null;
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_empleado`, '')) = ? LIMIT 1", [$employeeId]);
  }

  /** Cargos propios y los que tienen a cargo. @return array{own:list<int>,managed:list<int>} */
  public function cargoScope(string $employeeId): array
  {
    $own = self::ids($this->funcionarioByEmployee($employeeId)['id_cargo'] ?? '');
    $managed = [];
    foreach ($this->cargos($own) as $cargo) {
      $managed = array_merge($managed, self::ids($cargo['cargos_a_cargo'] ?? ''));
    }
    return ['own' => $own, 'managed' => array_values(array_diff(array_unique($managed), $own))];
  }

  /** Como el plugin: su propio cargo o uno de los que tiene a cargo. */
  public function canInspectCargo(string $employeeId, int $cargoId): bool
  {
    $scope = $this->cargoScope($employeeId);
    return $cargoId > 0 && in_array($cargoId, array_merge($scope['own'], $scope['managed']), true);
  }

  /** @param list<int> $ids @return list<array<string,mixed>> */
  private function cargos(array $ids): array
  {
    $table = $this->table('cargos');
    if ($ids === [] || !$this->schema->tableExists($table)) {
      return [];
    }
    return $this->db->getResults("SELECT * FROM `{$table}` WHERE `_ID` IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY `nombre_cargo`', $ids);
  }

  /** @param list<int> $ids @return list<array<string,mixed>> */
  private function byIds(string $type, string $column, array $ids): array
  {
    $table = $this->table($type);
    if ($ids === [] || !$this->schema->tableExists($table) || !$this->has($table, $column)) {
      return [];
    }
    $clauses = array_fill(0, count($ids), 'FIND_IN_SET(?, ' . self::setExpr($column) . ') > 0');
    return $this->db->getResults("SELECT * FROM `{$table}` WHERE " . implode(' OR ', $clauses) . ' ORDER BY `_ID` DESC', array_map('strval', $ids));
  }

  /** @param array<string,mixed> $cargo */
  private function presentCargo(array $cargo): array
  {
    $profile = [];
    foreach (self::CARGO_PROFILE as $column => $label) {
      $value = trim((string) ($cargo[$column] ?? ''));
      if ($value !== '') {
        $profile[] = ['label' => $label, 'text' => OrganizationPolicies::plain($value)];
      }
    }
    return [
      '_ID' => (int) $cargo['_ID'], 'nombre_cargo' => (string) ($cargo['nombre_cargo'] ?? ''), 'mision_cargo' => OrganizationPolicies::plain((string) ($cargo['mision_cargo'] ?? '')),
      'id_area' => self::csv($cargo['id_area'] ?? ''), 'cargos_a_cargo' => self::csv($cargo['cargos_a_cargo'] ?? ''), 'perfil' => $profile,
    ];
  }

  /**
   * Gestión de unos cargos: perfil, políticas vigentes (con la confirmación de lectura del funcionario), funciones,
   * compensaciones y procesos de sus áreas.
   * @param list<int> $cargoIds @return array<string,mixed>
   */
  private function management(array $cargoIds, string $employeeId): array
  {
    $cargos = $this->cargos($cargoIds);
    $areas = [];
    foreach ($cargos as $cargo) {
      $areas = array_merge($areas, self::ids($cargo['id_area'] ?? ''));
    }
    $policies = array_values(array_filter($this->byIds('politicas', 'id_cargo', $cargoIds), [OrganizationPolicies::class, 'visibleInManagement']));
    $reads = $this->policies->readsFor(array_map(static fn(array $row): int => (int) $row['_ID'], $policies), $employeeId);
    return [
      'cargos' => array_map(fn(array $cargo): array => $this->presentCargo($cargo), $cargos),
      'politicas' => array_map(static function (array $row) use ($reads): array {
        $policy = OrganizationPolicies::present($row);
        $policy['_ID'] = (int) $row['_ID'];
        $policy['leida'] = $reads[(int) $row['_ID']][(int) $policy['version']] ?? '';
        unset($policy['politica']);
        return $policy;
      }, $policies),
      'funciones' => $this->byIds('funciones', 'id_cargo', $cargoIds),
      'compensaciones' => $this->byIds('compensaciones', 'id_cargo', $cargoIds),
      'procesos' => $this->byIds('procesos', 'id_area', array_values(array_unique($areas))),
    ];
  }

  /** Mi gestión: lo del funcionario y su equipo (cargos que tiene a cargo, sin abrir). @return array<string,mixed>|null */
  public function managementForEmployee(string $employeeId): ?array
  {
    $person = $this->funcionarioByEmployee($employeeId);
    if ($person === null) {
      return null;
    }
    $scope = $this->cargoScope($employeeId);
    $data = $this->management($scope['own'], $employeeId);
    $data['funcionario'] = ['nombre' => (string) ($person['nombre'] ?? ''), 'id_empleado' => (string) $person['id_empleado']];
    $data['equipo'] = array_map(fn(array $cargo): array => $this->presentCargo($cargo), $this->cargos($scope['managed']));
    return $data;
  }

  /** @return array<string,mixed> */
  public function managementForCargo(int $cargoId, string $employeeId): array
  {
    $data = $this->management([$cargoId], $employeeId);
    if ($data['cargos'] === []) {
      throw new \DomainException('El cargo ya no existe.');
    }
    return $data;
  }

  /** Política para el detalle o el «Leí y entiendo». @return array<string,mixed>|null */
  public function policy(int $id): ?array
  {
    return $this->find('politicas', $id);
  }

  /** ¿La política está asignada a alguno de los cargos del funcionario? */
  public function policyAssignedTo(array $policy, string $employeeId): bool
  {
    return array_intersect(self::ids($policy['id_cargo'] ?? ''), $this->cargoScope($employeeId)['own']) !== [];
  }
}
