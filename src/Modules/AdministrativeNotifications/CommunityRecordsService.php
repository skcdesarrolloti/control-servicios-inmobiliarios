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

  /** JetEngine checkbox value used by the property form for "pertenece a copropiedad". */
  private const BELONGS_TO_COMMUNITY = 'a:1:{i:0;s:2:"Si";}';

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
   * Lease contracts matching a contract number or inmueble SIMI code.
   *
   * @return array<int,array<string,string>>
   */
  public function searchContracts(string $query): array
  {
    $query = trim(ltrim(trim($query), '#'));
    if ($query === '' || mb_strlen($query, 'UTF-8') > 60) {
      throw new \DomainException('Escribe un número de contrato o un código de inmueble SIMI.');
    }
    $table = $this->contractTable();
    $rows = $this->db->getResults(
      $this->contractSelectSql($table) . " WHERE TRIM(COALESCE(ca.`contrato`, '')) = ? OR TRIM(COALESCE(ca.`inmueble`, '')) = ? OR TRIM(COALESCE(ca.`id_inmueble`, '')) = ?
        ORDER BY ca.`_ID` DESC LIMIT 30",
      [$query, $query, $query]
    );
    return $this->contractRows($rows);
  }

  /**
   * Links a lease contract to the copropiedad and copies the copropiedad
   * data into it. Mandate contracts and closing sheets are not touched.
   *
   * @return array{message:string}
   */
  /** @return array<int,array<string,string>> */
  public function linkedWebProperties(int $id): array
  {
    $table = $this->webPropertyTable();
    if ($table === '') {
      return [];
    }
    return $this->webPropertyRows($this->db->getResults(
      $this->webPropertySelectSql($table) . " WHERE TRIM(COALESCE(i.`id_copropiedad`, '')) = ? ORDER BY i.`_ID` DESC LIMIT 200",
      [(string) $id]
    ));
  }

  /**
   * Web properties (jet_cct_inmuebles) matching the exact property code.
   *
   * @return array<int,array<string,string>>
   */
  public function searchWebProperties(string $query): array
  {
    $query = trim(ltrim(trim($query), '#'));
    $table = $this->webPropertyTable();
    if ($query === '' || $table === '') {
      return [];
    }
    return $this->webPropertyRows($this->db->getResults(
      $this->webPropertySelectSql($table) . " WHERE TRIM(COALESCE(i.`codigo`, '')) = ? ORDER BY i.`_ID` DESC LIMIT 30",
      [$query]
    ));
  }

  /**
   * Links a web property to the copropiedad. It does not create or change any
   * lease contract, so it does not make the copropiedad active.
   *
   * @return array{message:string}
   */
  public function linkWebProperty(int $id, int $propertyId): array
  {
    $community = $this->find($id);
    $table = $this->webPropertyTable();
    if ($table === '') {
      throw new \RuntimeException('La tabla de inmuebles no está disponible.');
    }
    $property = $this->db->getRow("SELECT `_ID`, `codigo` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$propertyId]);
    if (!is_array($property)) {
      throw new \DomainException('No se encontró el inmueble web.');
    }
    $data = [
      'id_copropiedad' => (string) $id,
      'copropiedad' => $community['copropiedad'],
    ];
    if ($this->schema->columnExists($table, 'pertenece_copropiedad')) {
      $data['pertenece_copropiedad'] = self::BELONGS_TO_COMMUNITY;
    }
    if ($this->schema->columnExists($table, 'cct_modified')) {
      $data['cct_modified'] = date('Y-m-d H:i:s');
    }
    $this->db->update($table, $data, ['_ID' => $propertyId]);
    $code = trim((string) ($property['codigo'] ?? ''));
    return ['message' => 'Inmueble web ' . ($code !== '' ? $code : '#' . $propertyId) . ' vinculado a ' . $community['copropiedad'] . '.'];
  }

  private function webPropertyTable(): string
  {
    $table = $this->db->table('jet_cct_inmuebles');
    return $this->schema->tableExists($table) && $this->schema->columnExists($table, 'id_copropiedad') ? $table : '';
  }

  private function webPropertySelectSql(string $table): string
  {
    $communityTable = $this->db->table('jet_cct_copropiedades');
    return "SELECT i.`_ID`, i.`codigo`, i.`estado`, i.`tipo_negocio`, i.`direccion`, i.`barrio`, i.`id_copropiedad`, cp.`copropiedad` AS copropiedad_actual
      FROM `{$table}` i
      LEFT JOIN `{$communityTable}` cp ON CAST(cp.`_ID` AS CHAR) = TRIM(COALESCE(i.`id_copropiedad`, ''))";
  }

  /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,string>> */
  private function webPropertyRows(array $rows): array
  {
    return array_map(static fn(array $row): array => [
      'kind' => 'inmueble',
      'id' => (string) (int) ($row['_ID'] ?? 0),
      'codigo' => trim((string) ($row['codigo'] ?? '')),
      'estado' => trim((string) ($row['estado'] ?? '')),
      'tipo_negocio' => trim((string) ($row['tipo_negocio'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'barrio' => trim((string) ($row['barrio'] ?? '')),
      'id_copropiedad' => trim((string) ($row['id_copropiedad'] ?? '')),
      'copropiedad_actual' => trim((string) ($row['copropiedad_actual'] ?? '')),
    ], $rows);
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
      'kind' => 'contrato',
      'id' => (string) (int) ($row['_ID'] ?? 0),
      'contrato' => trim((string) ($row['contrato'] ?? '')),
      'inmueble' => trim((string) ($row['inmueble'] ?? '')),
      'estado' => trim((string) ($row['estado'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'arrendatario' => trim((string) ($row['arrendatario'] ?? '')),
      'id_copropiedad' => trim((string) ($row['id_copropiedad'] ?? '')),
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
