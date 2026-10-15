<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeNotifications;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Create and edit copropiedades from the Notificaciones panel.
 *
 * Mirrors the JetForm "Agregar copropiedad" (barrio from jet_cct_barrios,
 * banco from jet_cct_bancos, tipo de cuenta Ahorros/Corriente) and adds the
 * indicativo from jet_cct_paises. Edits are copied to the lease contracts,
 * mandate contracts and closing sheets linked through id_copropiedad.
 */
final class CommunityRecordsService
{
  public const ACCOUNT_TYPES = ['Ahorros', 'Corriente'];

  /** Copropiedad column => column in each linked table. */
  private const LINKED_FIELDS = [
    'jet_cct_contratos_arrendamiento' => [
      'copropiedad' => 'copropiedad',
      'nit' => 'nit_copropiedad',
      'administrador' => 'administrador',
      'correo' => 'correo_copropiedad',
      'contacto' => 'celular_copropiedad',
    ],
    'jet_cct_contrato_mandato' => [
      'copropiedad' => 'copropiedad',
      'nit' => 'nit_co',
      'administrador' => 'administrador_co',
      'contacto' => 'contacto_co',
      'correo' => 'correo_co',
      'direccion' => 'direccion_co',
      'tipo_cuenta' => 'tipo_cuenta_co',
      'numero_cuenta' => 'numero_cuenta_co',
      'banco' => 'banco_cuenta_co',
    ],
    'jet_cct_cierres' => [
      'copropiedad' => 'copropiedad',
      'nit' => 'nit_copropiedad',
      'administrador' => 'administrador',
      'contacto' => 'celular_copropiedad',
      'correo' => 'correo_copropiedad',
      'tipo_cuenta' => 'tipo_cuenta_copropiedad',
      'numero_cuenta' => 'cuenta_copropiedad',
      'banco' => 'banco_copropiedad',
    ],
    'jet_cct_inmuebles' => [
      'copropiedad' => 'copropiedad',
    ],
  ];

  private const LINKED_LABELS = [
    'jet_cct_contratos_arrendamiento' => 'contratos de arrendamiento',
    'jet_cct_contrato_mandato' => 'contratos de mandato',
    'jet_cct_cierres' => 'hojas de cierre',
    'jet_cct_inmuebles' => 'inmuebles web',
  ];

  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
  }

  /** @return array{barrios:array<int,string>,bancos:array<int,string>,indicativos:array<int,array{codigo:string,pais:string}>,tipos_cuenta:array<int,string>} */
  public function options(): array
  {
    return [
      'barrios' => $this->catalog('jet_cct_barrios', 'barrio'),
      'bancos' => $this->catalog('jet_cct_bancos', 'banco'),
      'indicativos' => $this->countryCodes(),
      'tipos_cuenta' => self::ACCOUNT_TYPES,
    ];
  }

  /** @return array<string,string> */
  public function find(int $id): array
  {
    $table = $this->communityTable();
    $row = $this->db->getRow(
      "SELECT `_ID`, `copropiedad`, `nit`, `administrador`, `contacto`, `indicativo`, `pais`, `correo`, `barrio`, `direccion`, `tipo_cuenta`, `numero_cuenta`, `banco`, `id_inmueble`
         FROM `{$table}` WHERE `_ID` = ? LIMIT 1",
      [$id]
    );
    if (!is_array($row)) {
      throw new \DomainException('No se encontró la copropiedad.');
    }
    $out = ['id' => (string) (int) $row['_ID']];
    foreach ($row as $key => $value) {
      if ($key !== '_ID') {
        $out[$key] = trim((string) $value);
      }
    }
    $out['linked'] = $this->linkedCounts($id);
    $out['linked_records'] = $this->linkedRecords($id);
    return $out;
  }

  /**
   * @param array<string,string> $input
   * @return array{id:int,created:bool,linked:array<string,int>,message:string}
   */
  public function save(array $input, int $id = 0): array
  {
    $data = $this->validate($input);
    $table = $this->communityTable();
    $now = date('Y-m-d H:i:s');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      if ($id > 0) {
        if ((int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE `_ID` = ?", [$id]) === 0) {
          throw new \DomainException('No se encontró la copropiedad.');
        }
        $this->db->update($table, $data + ['cct_modified' => $now], ['_ID' => $id]);
        $linked = $this->propagate($id, $data, $now);
        $created = false;
      } else {
        $employeeId = trim(Auth::employeeId());
        if ($employeeId === '') {
          throw new \DomainException('No se pudo identificar al funcionario que crea la copropiedad.');
        }
        if (!$this->db->insert($table, $data + [
          'cct_status' => 'publish',
          'cct_author_id' => $employeeId,
          'cct_created' => $now,
          'cct_modified' => $now,
        ])) {
          throw new \RuntimeException('No se pudo crear la copropiedad.');
        }
        $id = (int) $this->db->lastInsertId();
        $linked = [];
        $created = true;
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }

    $parts = [];
    foreach ($linked as $tableKey => $count) {
      if ($count > 0) {
        $parts[] = $count . ' ' . (self::LINKED_LABELS[$tableKey] ?? $tableKey);
      }
    }
    $message = $created
      ? 'Copropiedad creada.'
      : 'Copropiedad actualizada.' . ($parts !== [] ? ' También se actualizaron: ' . implode(', ', $parts) . '.' : ' No tiene registros vinculados para actualizar.');

    return ['id' => $id, 'created' => $created, 'linked' => $linked, 'message' => $message];
  }

  /** @return array<int,array<string,string>> */
  public function linkedContracts(int $id): array
  {
    $table = $this->contractTable();
    $rows = $this->db->getResults(
      $this->contractSelectSql($table) . " WHERE TRIM(COALESCE(ca.`id_copropiedad`, '')) = ? ORDER BY ca.`_ID` DESC LIMIT 200",
      [(string) $id]
    );
    return $this->contractRows($rows);
  }

  /**
   * Lease contracts the recipient list associates with the copropiedad (same
   * property id, NIT or name) but that are not formally linked by
   * id_copropiedad. Shown so they can be linked with one click.
   *
   * @return array<int,array<string,string>>
   */
  public function detectedContracts(int $id): array
  {
    $community = $this->db->getRow(
      "SELECT `copropiedad`, `nit`, `id_inmueble` FROM `{$this->communityTable()}` WHERE `_ID` = ? LIMIT 1",
      [$id]
    );
    if (!is_array($community)) {
      return [];
    }
    $collate = static fn(string $expr): string => "LOWER(CONVERT(TRIM(COALESCE({$expr}, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci)";
    $parts = [];
    $args = [];
    $propertyId = (int) preg_replace('/\D+/', '', (string) ($community['id_inmueble'] ?? ''));
    if ($propertyId > 0) {
      $parts[] = 'CAST(ca.`id_inmueble` AS UNSIGNED) = ?';
      $args[] = $propertyId;
    }
    foreach (['nit' => 'nit_copropiedad', 'copropiedad' => 'copropiedad'] as $source => $target) {
      $value = trim((string) ($community[$source] ?? ''));
      if ($value === '' || AdministrativeNotificationsService::isPlaceholderValue($value)) {
        continue;
      }
      $parts[] = $collate("ca.`{$target}`") . ' = ' . $collate('?');
      $args[] = $value;
    }
    if ($parts === []) {
      return [];
    }
    $table = $this->contractTable();
    $args[] = (string) $id;
    $rows = $this->db->getResults(
      $this->contractSelectSql($table) . ' WHERE (' . implode(' OR ', $parts) . ") AND TRIM(COALESCE(ca.`id_copropiedad`, '')) <> ? ORDER BY ca.`_ID` DESC LIMIT 50",
      $args
    );
    return $this->contractRows($rows);
  }

  /**
   * Lease contracts matching the exact contract number.
   *
   * @return array<int,array<string,string>>
   */
  public function searchContracts(string $query): array
  {
    $query = trim(ltrim(trim($query), '#'));
    if ($query === '' || mb_strlen($query, 'UTF-8') > 60) {
      throw new \DomainException('Escribe el número de contrato.');
    }
    $table = $this->contractTable();
    $rows = $this->db->getResults(
      $this->contractSelectSql($table) . " WHERE TRIM(COALESCE(ca.`contrato`, '')) = ? ORDER BY ca.`_ID` DESC LIMIT 30",
      [$query]
    );
    return $this->contractRows($rows);
  }

  /**
   * Links a lease contract to the copropiedad and copies the copropiedad
   * data into it. Mandate contracts and closing sheets are not touched.
   *
   * @return array{message:string}
   */
  /**
   * Adds a bank to jet_cct_bancos unless an equivalent name already exists
   * (case, accents and spacing are ignored).
   *
   * @param array<string,string> $input
   * @return array{value:string,created:bool,options:array<int,string>,message:string}
   */
  public function addBank(array $input): array
  {
    $name = $this->cleanText((string) ($input['banco'] ?? ''));
    $country = $this->cleanText((string) ($input['pais'] ?? '')) ?: 'Colombia';
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
      throw new \DomainException('Escribe el nombre del banco (máximo 160 caracteres).');
    }
    $result = $this->addCatalogEntry('jet_cct_bancos', 'banco', $name, [
      'banco' => $name,
      'pais' => $country,
    ]);
    return $result + ['options' => $this->catalog('jet_cct_bancos', 'banco')];
  }

  /**
   * Adds a barrio with the same data the JetForm "Agregar barrio" asks for,
   * unless an equivalent barrio already exists in the same city.
   *
   * @param array<string,string> $input
   * @return array{value:string,created:bool,options:array<int,string>,message:string}
   */
  public function addNeighborhood(array $input): array
  {
    $name = $this->cleanText((string) ($input['barrio'] ?? ''));
    $city = $this->cleanText((string) ($input['ciudad'] ?? '')) ?: 'Cartagena';
    $state = $this->cleanText((string) ($input['departamento'] ?? '')) ?: 'Bolívar';
    $country = $this->cleanText((string) ($input['pais'] ?? '')) ?: 'Colombia';
    $lat = str_replace(',', '.', trim((string) ($input['latitud'] ?? '')));
    $lng = str_replace(',', '.', trim((string) ($input['longitud'] ?? '')));
    $postal = trim((string) ($input['codigo_postal'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
      throw new \DomainException('Escribe el nombre del barrio (máximo 160 caracteres).');
    }
    if (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
      throw new \DomainException('La latitud debe ser un número entre -90 y 90 (ej. 10.3910).');
    }
    if (!is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
      throw new \DomainException('La longitud debe ser un número entre -180 y 180 (ej. -75.4794).');
    }
    if (preg_match('/^\d{5,6}$/', $postal) !== 1) {
      throw new \DomainException('El código postal debe tener 5 o 6 dígitos.');
    }
    foreach (['ciudad' => $city, 'departamento' => $state, 'país' => $country] as $label => $value) {
      if (mb_strlen($value, 'UTF-8') > 80) {
        throw new \DomainException('El campo ' . $label . ' supera los 80 caracteres.');
      }
    }
    $result = $this->addCatalogEntry('jet_cct_barrios', 'barrio', $name, [
      'barrio' => $name,
      'ciudad' => $city,
      'departamento' => $state,
      'pais' => $country,
      'latitud' => $lat,
      'longitud' => $lng,
      'codigo_postal' => $postal,
    ], $city);
    return $result + ['options' => $this->catalog('jet_cct_barrios', 'barrio')];
  }

  /**
   * @param array<string,string> $row
   * @return array{value:string,created:bool,message:string}
   */
  private function addCatalogEntry(string $tableKey, string $column, string $name, array $row, string $city = ''): array
  {
    $table = $this->db->table($tableKey);
    if (!$this->schema->tableExists($table)) {
      throw new \RuntimeException('El catálogo ' . $tableKey . ' no está disponible.');
    }
    $employeeId = trim(Auth::employeeId());
    if ($employeeId === '') {
      throw new \DomainException('No se pudo identificar al funcionario que agrega el registro.');
    }
    $label = $column === 'barrio' ? 'barrio' : 'banco';
    $key = $this->catalogKey($name);
    // Serialize catalog writes so two people cannot create the same entry at once.
    $lock = 'scm:catalog:' . sha1($table);
    if ((int) $this->db->getVar('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
      throw new \DomainException('El catálogo está ocupado. Intenta nuevamente.');
    }
    try {
      $hasCity = $city !== '' && $this->schema->columnExists($table, 'ciudad');
      $rows = $this->db->getResults(
        "SELECT `_ID`, `{$column}` AS nombre, `cct_status`" . ($hasCity ? ', `ciudad`' : '') . " FROM `{$table}` WHERE TRIM(COALESCE(`{$column}`, '')) <> ''"
      );
      foreach ($rows as $existing) {
        if ($this->catalogKey((string) $existing['nombre']) !== $key) {
          continue;
        }
        $existingCity = $hasCity ? $this->catalogKey((string) ($existing['ciudad'] ?? '')) : '';
        if ($hasCity && $existingCity !== '' && $existingCity !== $this->catalogKey($city)) {
          continue;
        }
        if (!in_array(strtolower(trim((string) ($existing['cct_status'] ?? ''))), ['', 'publish'], true)) {
          throw new \DomainException('Ese ' . $label . ' ya existe pero no está activo en el catálogo. Solicita su revisión.');
        }
        $value = trim((string) $existing['nombre']);
        return ['value' => $value, 'created' => false, 'message' => 'El ' . $label . ' "' . $value . '" ya existía; se seleccionó.'];
      }
      $now = date('Y-m-d H:i:s');
      if (!$this->db->insert($table, $row + [
        'cct_status' => 'publish',
        'cct_author_id' => $employeeId,
        'cct_created' => $now,
        'cct_modified' => $now,
      ])) {
        throw new \RuntimeException('No se pudo agregar el ' . $label . '.');
      }
      return ['value' => $name, 'created' => true, 'message' => ucfirst($label) . ' "' . $name . '" agregado.'];
    } finally {
      $this->db->getVar('SELECT RELEASE_LOCK(?)', [$lock]);
    }
  }

  /** Comparison key: lowercase, no accents, single spaces. */
  private function catalogKey(string $value): string
  {
    $value = mb_strtolower($this->cleanText($value), 'UTF-8');
    return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '.' => '', ',' => '']);
  }

  private function cleanText(string $value): string
  {
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

  /**
   * Removes a lease contract from the copropiedad: clears id_copropiedad and
   * the copropiedad data copied into the contract. propiedad_horizontal is
   * left as is because the property may still belong to a building.
   *
   * @return array{message:string}
   */
  public function unlinkContract(int $id, int $contractId): array
  {
    $community = $this->find($id);
    $table = $this->contractTable();
    $contract = $this->db->getRow(
      "SELECT `_ID`, `contrato`, `id_copropiedad` FROM `{$table}` WHERE `_ID` = ? LIMIT 1",
      [$contractId]
    );
    if (!is_array($contract)) {
      throw new \DomainException('No se encontró el contrato de arrendamiento.');
    }
    if (trim((string) ($contract['id_copropiedad'] ?? '')) !== (string) $id) {
      throw new \DomainException('Ese contrato ya no está vinculado a esta copropiedad.');
    }
    $data = ['id_copropiedad' => ''];
    foreach (self::LINKED_FIELDS['jet_cct_contratos_arrendamiento'] as $target) {
      if ($this->schema->columnExists($table, $target)) {
        $data[$target] = '';
      }
    }
    if ($this->schema->columnExists($table, 'cct_modified')) {
      $data['cct_modified'] = date('Y-m-d H:i:s');
    }
    $this->db->update($table, $data, ['_ID' => $contractId]);
    error_log(sprintf('[copropiedades] Contrato %d desvinculado de la copropiedad %d por el funcionario %s', $contractId, $id, Auth::employeeId()));

    $label = trim((string) ($contract['contrato'] ?? '')) !== '' && strcasecmp(trim((string) $contract['contrato']), 'No asignado') !== 0
      ? 'Contrato #' . trim((string) $contract['contrato'])
      : 'Contrato ID ' . $contractId;
    return ['message' => $label . ' desvinculado de ' . $community['copropiedad'] . '.'];
  }

  public function linkContract(int $id, int $contractId): array
  {
    $community = $this->find($id);
    $contractTable = $this->contractTable();
    $contract = $this->db->getRow(
      "SELECT `_ID`, `contrato` FROM `{$contractTable}` WHERE `_ID` = ? LIMIT 1",
      [$contractId]
    );
    if (!is_array($contract)) {
      throw new \DomainException('No se encontró el contrato de arrendamiento.');
    }

    $data = array_intersect_key($community, array_flip(['copropiedad', 'nit', 'administrador', 'contacto', 'correo']));
    $this->linkRows('jet_cct_contratos_arrendamiento', [$contractId], $id, $data, date('Y-m-d H:i:s'));

    $label = trim((string) ($contract['contrato'] ?? '')) !== '' && strcasecmp(trim((string) $contract['contrato']), 'No asignado') !== 0
      ? 'Contrato #' . trim((string) $contract['contrato'])
      : 'Contrato ID ' . $contractId;
    return ['message' => $label . ' vinculado a ' . $community['copropiedad'] . '.'];
  }

  /** @param array<int,int> $ids @param array<string,string> $data */
  private function linkRows(string $tableKey, array $ids, int $communityId, array $data, string $now): int
  {
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $value): bool => $value > 0));
    $table = $this->db->table($tableKey);
    if ($ids === [] || !$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_copropiedad')) {
      return 0;
    }
    $set = ['`id_copropiedad` = ?'];
    $args = [(string) $communityId];
    if ($this->schema->columnExists($table, 'propiedad_horizontal')) {
      $set[] = '`propiedad_horizontal` = ?';
      $args[] = 'Si';
    }
    foreach (self::LINKED_FIELDS[$tableKey] as $source => $target) {
      if ($this->schema->columnExists($table, $target)) {
        $set[] = "`{$target}` = ?";
        $args[] = (string) ($data[$source] ?? '');
      }
    }
    if ($this->schema->columnExists($table, 'cct_modified')) {
      $set[] = '`cct_modified` = ?';
      $args[] = $now;
    }
    $stmt = $this->db->pdo()->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE `_ID` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute(array_merge($args, $ids));
    return $stmt->rowCount();
  }

  private function contractTable(): string
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_copropiedad')) {
      throw new \RuntimeException('La tabla de contratos de arrendamiento no está disponible.');
    }
    return $table;
  }

  private function contractSelectSql(string $table): string
  {
    $communityTable = $this->db->table('jet_cct_copropiedades');
    return "SELECT ca.`_ID`, ca.`contrato`, ca.`inmueble`, ca.`estado`, ca.`direccion`, ca.`arrendatario`, ca.`id_copropiedad`, cp.`copropiedad` AS copropiedad_actual
      FROM `{$table}` ca
      LEFT JOIN `{$communityTable}` cp ON CAST(cp.`_ID` AS CHAR) = TRIM(COALESCE(ca.`id_copropiedad`, ''))";
  }

  /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,string>> */
  private function contractRows(array $rows): array
  {
    return array_map(static fn(array $row): array => [
      'id' => (string) (int) ($row['_ID'] ?? 0),
      'contrato' => trim((string) ($row['contrato'] ?? '')),
      'inmueble' => trim((string) ($row['inmueble'] ?? '')),
      'estado' => trim((string) ($row['estado'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'arrendatario' => trim((string) ($row['arrendatario'] ?? '')),
      // Some legacy contracts store "0" for "no copropiedad".
      'id_copropiedad' => (int) trim((string) ($row['id_copropiedad'] ?? '')) > 0 ? trim((string) $row['id_copropiedad']) : '',
      'copropiedad_actual' => trim((string) ($row['copropiedad_actual'] ?? '')),
    ], $rows);
  }

  /** @param array<string,string> $input @return array<string,string> */
  private function validate(array $input): array
  {
    $clean = static fn(string $key): string => trim(preg_replace('/\s+/u', ' ', (string) ($input[$key] ?? '')) ?? '');
    $data = [
      'copropiedad' => $clean('copropiedad'),
      'nit' => $clean('nit'),
      'administrador' => $clean('administrador'),
      'contacto' => $clean('contacto'),
      'indicativo' => $clean('indicativo'),
      'correo' => strtolower($clean('correo')),
      'barrio' => $clean('barrio'),
      'direccion' => $clean('direccion'),
      'tipo_cuenta' => $clean('tipo_cuenta'),
      'numero_cuenta' => $clean('numero_cuenta'),
      'banco' => $clean('banco'),
    ];

    $required = ['copropiedad' => 'Copropiedad', 'nit' => 'NIT', 'administrador' => 'Administrador', 'contacto' => 'Contacto', 'correo' => 'Correo', 'barrio' => 'Barrio'];
    $missing = [];
    foreach ($required as $key => $label) {
      if ($data[$key] === '') {
        $missing[] = $label;
      }
    }
    if ($missing !== []) {
      throw new \DomainException('Completa los campos obligatorios: ' . implode(', ', $missing) . '.');
    }
    foreach ($data as $key => $value) {
      if (mb_strlen($value, 'UTF-8') > 255) {
        throw new \DomainException('El campo ' . $key . ' supera los 255 caracteres.');
      }
    }
    // NIT sin puntos ni dígito de verificación: el ingreso de usuarios usa solo los números base.
    if (preg_match('/^\d{5,15}$/', $data['nit']) !== 1) {
      throw new \DomainException('El NIT debe tener solo números (5 a 15 dígitos), sin puntos, guiones ni dígito de verificación.');
    }
    if (filter_var($data['correo'], FILTER_VALIDATE_EMAIL) === false) {
      throw new \DomainException('Escribe un correo válido para la copropiedad.');
    }
    if ($data['tipo_cuenta'] !== '' && !in_array($data['tipo_cuenta'], self::ACCOUNT_TYPES, true)) {
      throw new \DomainException('El tipo de cuenta debe ser Ahorros o Corriente.');
    }

    $codes = [];
    foreach ($this->countryCodes() as $country) {
      $codes[$country['codigo']] = $country['pais'];
    }
    if ($data['indicativo'] === '') {
      $data['indicativo'] = '+57';
    }
    $data['indicativo'] = '+' . ltrim($data['indicativo'], '+');
    if (!isset($codes[$data['indicativo']])) {
      throw new \DomainException('Selecciona un indicativo de la lista de países.');
    }
    $data['pais'] = $codes[$data['indicativo']];

    return $data;
  }

  /** @param array<string,string> $data @return array<string,int> */
  private function propagate(int $id, array $data, string $now): array
  {
    $counts = [];
    foreach (self::LINKED_FIELDS as $tableKey => $map) {
      $table = $this->db->table($tableKey);
      if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_copropiedad')) {
        continue;
      }
      $set = [];
      $args = [];
      foreach ($map as $source => $target) {
        if ($this->schema->columnExists($table, $target)) {
          $set[] = "`{$target}` = ?";
          $args[] = $data[$source];
        }
      }
      if ($set === []) {
        continue;
      }
      if ($this->schema->columnExists($table, 'cct_modified')) {
        $set[] = '`cct_modified` = ?';
        $args[] = $now;
      }
      $args[] = (string) $id;
      $stmt = $this->db->pdo()->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . " WHERE TRIM(COALESCE(`id_copropiedad`, '')) = ?");
      $stmt->execute($args);
      $counts[$tableKey] = $stmt->rowCount();
    }
    return $counts;
  }

  /** @return array<string,int> */
  private function linkedCounts(int $id): array
  {
    $counts = [];
    foreach (array_keys(self::LINKED_FIELDS) as $tableKey) {
      $table = $this->db->table($tableKey);
      if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_copropiedad')) {
        continue;
      }
      $counts[self::LINKED_LABELS[$tableKey]] = (int) $this->db->getVar(
        "SELECT COUNT(1) FROM `{$table}` WHERE TRIM(COALESCE(`id_copropiedad`, '')) = ?",
        [(string) $id]
      );
    }
    return $counts;
  }

  /** Columns used to identify each linked record in the preview. */
  private const LINKED_IDENTITY = [
    'jet_cct_contratos_arrendamiento' => ['contrato', 'estado', 'inmueble', 'arrendatario'],
    'jet_cct_contrato_mandato' => ['estado', 'codigo_inmueble', 'id_inmueble', 'direccion'],
    'jet_cct_cierres' => ['estado', 'id_inmueble', 'direccion', 'arrendatario'],
    'jet_cct_inmuebles' => ['codigo', 'estado', 'direccion'],
  ];

  /**
   * Linked records with the current value of every copropiedad field they
   * mirror, so the panel can show what an edit will change.
   *
   * @return array<int,array{table:string,label:string,items:array<int,array{id:string,title:string,subtitle:string,fields:array<string,string>}>}>
   */
  public function linkedRecords(int $id): array
  {
    $groups = [];
    foreach (self::LINKED_FIELDS as $tableKey => $map) {
      $table = $this->db->table($tableKey);
      if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_copropiedad')) {
        continue;
      }
      $columns = ['_ID'];
      foreach (array_merge(self::LINKED_IDENTITY[$tableKey] ?? [], array_values($map)) as $column) {
        if ($this->schema->columnExists($table, $column)) {
          $columns[] = $column;
        }
      }
      $rows = $this->db->getResults(
        'SELECT `' . implode('`, `', array_unique($columns)) . "` FROM `{$table}` WHERE TRIM(COALESCE(`id_copropiedad`, '')) = ? ORDER BY `_ID` DESC LIMIT 100",
        [(string) $id]
      );
      if ($rows === []) {
        continue;
      }
      $items = [];
      foreach ($rows as $row) {
        $fields = [];
        foreach ($map as $source => $target) {
          if (array_key_exists($target, $row)) {
            $fields[$source] = trim((string) $row[$target]);
          }
        }
        [$title, $subtitle] = $this->linkedRecordIdentity($tableKey, $row);
        $items[] = ['id' => (string) (int) $row['_ID'], 'title' => $title, 'subtitle' => $subtitle, 'fields' => $fields];
      }
      $groups[] = ['table' => $tableKey, 'label' => ucfirst(self::LINKED_LABELS[$tableKey]), 'items' => $items];
    }
    return $groups;
  }

  /** @param array<string,mixed> $row @return array{0:string,1:string} */
  private function linkedRecordIdentity(string $tableKey, array $row): array
  {
    $value = static function (string $key) use ($row): string {
      $text = trim((string) ($row[$key] ?? ''));
      return strcasecmp($text, 'No asignado') === 0 ? '' : $text;
    };
    $id = (string) (int) $row['_ID'];
    $join = static fn(array $parts): string => implode(' · ', array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    return match ($tableKey) {
      'jet_cct_contratos_arrendamiento' => [
        $value('contrato') !== '' ? 'Contrato #' . $value('contrato') : 'Contrato ID ' . $id,
        $join([$value('estado'), $value('inmueble') !== '' ? 'SIMI ' . $value('inmueble') : '', $value('arrendatario')]),
      ],
      'jet_cct_contrato_mandato' => [
        'Mandato ID ' . $id,
        $join([$value('estado'), ($value('codigo_inmueble') ?: $value('id_inmueble')) !== '' ? 'Inmueble ' . ($value('codigo_inmueble') ?: $value('id_inmueble')) : '', $value('direccion')]),
      ],
      'jet_cct_cierres' => [
        'Hoja de cierre ID ' . $id,
        $join([$value('estado'), $value('arrendatario'), $value('direccion')]),
      ],
      default => [
        'Inmueble web ' . ($value('codigo') ?: '#' . $id),
        $join([$value('estado'), $value('direccion')]),
      ],
    };
  }

  private function communityTable(): string
  {
    $table = $this->db->table('jet_cct_copropiedades');
    if (!$this->schema->tableExists($table)) {
      throw new \RuntimeException('La tabla de copropiedades no está disponible.');
    }
    return $table;
  }

  /** @return array<int,string> */
  private function catalog(string $tableKey, string $column): array
  {
    $table = $this->db->table($tableKey);
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $values = $this->db->getCol(
      "SELECT DISTINCT TRIM(`{$column}`) FROM `{$table}`
        WHERE (`cct_status` = 'publish' OR `cct_status` IS NULL OR `cct_status` = '')
          AND TRIM(COALESCE(`{$column}`, '')) <> ''
        ORDER BY TRIM(`{$column}`) ASC"
    );
    return array_values(array_map('strval', $values));
  }

  /** @return array<int,array{codigo:string,pais:string}> */
  private function countryCodes(): array
  {
    $table = $this->db->table('jet_cct_paises');
    $fallback = [['codigo' => '+57', 'pais' => 'Colombia']];
    if (!$this->schema->tableExists($table)) {
      return $fallback;
    }
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(`codigo`, '')) AS codigo, TRIM(COALESCE(`pais`, '')) AS pais FROM `{$table}`
        WHERE TRIM(COALESCE(`codigo`, '')) <> ''
        ORDER BY CASE WHEN TRIM(`codigo`) = '+57' THEN 0 ELSE 1 END, pais ASC"
    );
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      $code = '+' . ltrim((string) $row['codigo'], '+');
      if (isset($seen[$code])) {
        continue;
      }
      $seen[$code] = true;
      $out[] = ['codigo' => $code, 'pais' => (string) $row['pais']];
    }
    return $out !== [] ? $out : $fallback;
  }
}
