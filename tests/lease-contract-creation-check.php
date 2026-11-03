<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL', 'https://example.test/panel');

use SCM\Core\Database;
use SCM\Modules\LeaseContracts\LeaseContractCreateView;
use SCM\Modules\LeaseContracts\LeaseContractCreationService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

final class LeaseCreationTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare(str_replace([' FOR UPDATE', 'AS UNSIGNED'], ['', 'AS INTEGER'], $query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$coOwnerColumns = ['propietarios'];
for ($i = 1; $i <= 6; $i++) {
  $coOwnerColumns[] = 'porcentaje_' . $i;
  if ($i > 1) $coOwnerColumns[] = 'id_propietario_' . $i;
}

/**
 * Base nueva con un contrato "No asignado" (#10), su hoja de cierre (#20) y su mandato (#31).
 * @return array{0:PDO,1:Database}
 */
$setup = static function (array $mandate, array $closing = [], bool $withCoOwnerColumns = true, array $contract = [], array $profiles = []) use ($coOwnerColumns): array {
  $pdo = new LeaseCreationTestPdo();
  $db = new Database($pdo, 'wp_');
  $table = static function (string $name, array $columns) use ($pdo): void {
    $columns = array_values(array_unique(array_diff($columns, ['_ID'])));
    $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', $columns)) . ')');
  };
  $insert = static function (string $name, array $row) use ($pdo): void {
    $pdo->prepare("INSERT INTO {$name}(" . implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', array_keys($row))) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
  };
  $cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];

  $pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT)');
  $pdo->exec('CREATE TABLE wp_jet_post_types(id INTEGER PRIMARY KEY, status TEXT, meta_fields TEXT)');
  foreach ([783 => ['Apartamento'], 785 => ['Vivienda'], 784 => ['Arriendo'], 175 => ['Si', 'No']] as $id => $values) {
    $insert('wp_jet_post_types', ['id' => $id, 'status' => 'glossary', 'meta_fields' => serialize(array_map(static fn(string $v): array => ['value' => $v, 'label' => $v], $values))]);
  }

  $contractColumns = array_merge($cct, ['contrato', 'inmueble', 'direccion', 'registro_fotografico', 'fecha', 'estado', 'tipo', 'propietario', 'documento_propietario', 'correo_propietario', 'celular_propietario', 'arrendatario', 'documento_arrendatario', 'correo_arrendatario', 'celular_arrendatario',
    'id_propietario', 'id_arrendatario', 'id_contrato_mandato', 'id_cierre', 'id_inmueble', 'valor_canon', 'link_documentos'], $withCoOwnerColumns ? $coOwnerColumns : []);
  $table('wp_jet_cct_contratos_arrendamiento', $contractColumns);
  $insert('wp_jet_cct_contratos_arrendamiento', array_intersect_key(['_ID' => 10, 'contrato' => 'No asignado', 'id_propietario' => '500', 'id_contrato_mandato' => '31', 'propietario' => 'Ana'] + $contract, array_flip(array_merge(['_ID'], $contractColumns))));

  $closingColumns = array_merge($cct, ['estado', 'id_contrato', 'id_contrato_arrendamiento', 'id_propietario', 'id_arrendatario', 'propietario', 'documento_propietario', 'direccion'], $coOwnerColumns);
  for ($i = 2; $i <= 6; $i++) array_push($closingColumns, 'propietario_' . $i, 'documento_propietario_' . $i);
  $table('wp_jet_cct_cierres', $closingColumns);
  $insert('wp_jet_cct_cierres', ['_ID' => 20, 'estado' => 'Aprobada', 'id_contrato' => '31', 'id_contrato_arrendamiento' => '10', 'id_propietario' => '500', 'propietario' => 'Ana', 'documento_propietario' => '111'] + $closing);

  $mandateColumns = array_merge($cct, ['propietarios', 'id_propietario', 'estado_hoja_cierre']);
  for ($i = 1; $i <= 6; $i++) array_push($mandateColumns, 'nombre_' . $i, 'documento_' . $i, 'id_propietario_nuevo_' . $i, 'porcentaje_' . $i);
  $table('wp_jet_cct_contrato_mandato', $mandateColumns);
  $insert('wp_jet_cct_contrato_mandato', ['_ID' => 31, 'id_propietario' => '500'] + $mandate);

  $table('wp_jet_cct_propietarios', array_merge($cct, ['nombre', 'documento']));
  foreach ($profiles as $profile) $insert('wp_jet_cct_propietarios', $profile);
  return [$pdo, $db];
};
$three = static function (array $percents = [], array $ids = ['500', '501', '502']): array {
  $row = ['propietarios' => '3'];
  foreach ([1 => ['Ana', '111'], 2 => ['Luis', '222'], 3 => ['Marta', '333']] as $i => [$name, $doc]) {
    $row += ['nombre_' . $i => $name, 'documento_' . $i => $doc, 'id_propietario_nuevo_' . $i => $ids[$i - 1] ?? '', 'porcentaje_' . $i => $percents[$i - 1] ?? ''];
  }
  return $row;
};
$input = [
  'contrato' => '777', 'inmueble' => '1234', 'direccion' => 'Calle 1 # 2-3', 'registro_fotografico' => 'https://drive.google.com/x',
  'tipo_inmueble' => 'Apartamento', 'destinacion_inmueble' => 'Vivienda', 'gestion_inmueble' => 'Arriendo', 'propiedad_horizontal' => 'No', 'tiene_parqueadero' => 'No', 'tiene_deposito' => 'No',
  'tasa_administracion' => '10', 'incremento_pactado' => 'IPC', 'meses_contrato' => '12', 'valor_canon' => '1.200.000', 'valor_administracion' => '0', 'derechos_inmobiliarios' => '50',
  'propietario' => 'Ana', 'documento_propietario' => '111', 'correo_propietario' => 'ana@example.invalid', 'celular_propietario' => '3000000001',
  'arrendatario' => 'Pedro', 'documento_arrendatario' => '999', 'correo_arrendatario' => 'pedro@example.invalid', 'celular_arrendatario' => '3000000009',
  'num_codeudores' => 'Ninguno',
  // Lo que llegue en el formulario no cuenta: los % son de solo lectura.
  'porcentaje_1' => '1', 'porcentaje_2' => '99', 'propietarios' => '2',
];
$sender = ['employee_id' => '7', 'name' => 'Royner Guardo', 'cargo' => 'Asistente de Desarrollo TI', 'phone' => '3015232054'];
$contractRow = static fn(PDO $pdo): array => $pdo->query('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 10')->fetch(PDO::FETCH_ASSOC);

// 1. Columnas inexistentes: el contrato se crea igual, sin escribir copropietarios.
[$pdo, $db] = $setup($three(['50', '30', '20']), [], false);
$result = (new LeaseContractCreationService($db))->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($result['contrato'] === '777' && $row['contrato'] === '777' && !array_key_exists('porcentaje_1', $row), 'without co-owner columns the contract is created without errors');

// 2. Un propietario: 100 %, sin avisos.
[$pdo, $db] = $setup(['propietarios' => '1', 'nombre_1' => 'Ana', 'documento_1' => '111', 'id_propietario_nuevo_1' => '500']);
$service = new LeaseContractCreationService($db);
$co = $service->coOwners(['_ID' => 10, 'id_contrato_mandato' => '31'], $service->closing(['_ID' => 10]));
$check(count($co['owners']) === 1 && $co['warnings'] === [] && $co['valid'], 'a single owner has no warnings');
$result = $service->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['propietarios'] === '1' && $row['porcentaje_1'] === '100' && $row['id_propietario_2'] === null && $result['warnings'] === [], 'a single owner is copied as 1 owner at 100 %');
$html = (new LeaseContractCreateView())->render(['_ID' => 10], $service->prefill(['_ID' => 10, 'id_contrato_mandato' => '31']) + ['_closing_state' => ''], array_fill_keys(['tipo_inmueble', 'destinacion_inmueble', 'gestion_inmueble', 'si_no', 'servicios_publicos', 'tipo_parqueadero', 'zonas_sociales', 'tipo_mascota', 'tipo_vehiculo', 'parentesco', 'ocupacion', 'acuerdos_precontractuales'], []));
$check(!str_contains($html, 'data-lease-co-owners'), 'the co-owners block is hidden for a single owner');

// 3. Tres con %: ids desde id_propietario_nuevo_N y porcentajes del mandato.
[$pdo, $db] = $setup($three(['50', '30,5', '19.5']));
$service = new LeaseContractCreationService($db);
$prefill = $service->prefill(['_ID' => 10, 'id_contrato_mandato' => '31']);
$check($prefill['_co_owners']['source'] === 'mandato' && $prefill['_co_owners']['valid'] && $prefill['_co_owners']['warnings'] === [] && array_column($prefill['_co_owners']['owners'], 'percent') === ['50', '30.5', '19.5'], 'three owners with percentages come from the mandate');
$html = (new LeaseContractCreateView())->render(['_ID' => 10], $prefill, array_fill_keys(['tipo_inmueble', 'destinacion_inmueble', 'gestion_inmueble', 'si_no', 'servicios_publicos', 'tipo_parqueadero', 'zonas_sociales', 'tipo_mascota', 'tipo_vehiculo', 'parentesco', 'ocupacion', 'acuerdos_precontractuales'], []));
$check(str_contains($html, 'data-lease-co-owners') && str_contains($html, 'Luis') && str_contains($html, '30,5 %') && str_contains($html, 'Suma 100 %') && str_contains($html, 'contrato de mandato #31') && !str_contains($html, 'name="porcentaje_'), 'the read-only block lists the co-owners with their percentage and no inputs');
$result = $service->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['propietarios'] === '3' && $row['id_propietario_2'] === '501' && $row['id_propietario_3'] === '502' && $row['porcentaje_1'] === '50' && $row['porcentaje_2'] === '30.5' && $row['porcentaje_3'] === '19.5', 'three owners and their percentages are copied to the contract, ignoring the form input');
$check($row['id_propietario'] === '500', 'the main owner stays as before');

// 4. Sin %: se copian los ids, los % quedan vacíos y se avisa. Lo que el contrato ya tenía no se borra.
[$pdo, $db] = $setup($three(), [], true, ['porcentaje_2' => '45']);
$result = (new LeaseContractCreationService($db))->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['propietarios'] === '3' && $row['id_propietario_2'] === '501' && $row['porcentaje_1'] === null && $row['porcentaje_2'] === '45', 'without percentages the ids are copied and existing values are kept');
$check(count($result['warnings']) === 1 && str_contains($result['warnings'][0], 'partes iguales'), 'a missing percentage is a warning, not an error');

// 5. Mandato sin copropietarios: se toman de la hoja de cierre.
[$pdo, $db] = $setup(['propietarios' => '1', 'nombre_1' => 'Ana', 'documento_1' => '111'], ['propietarios' => '2', 'propietario_2' => 'Luis', 'documento_propietario_2' => '222', 'id_propietario_2' => '501', 'porcentaje_1' => '60', 'porcentaje_2' => '40']);
$result = (new LeaseContractCreationService($db))->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['propietarios'] === '2' && $row['id_propietario_2'] === '501' && $row['porcentaje_1'] === '60' && $row['porcentaje_2'] === '40' && $result['warnings'] === [], 'without co-owners in the mandate they come from the closing sheet');

// 6. Mandato y hoja distintos: se usa el mandato y se avisa.
[$pdo, $db] = $setup($three(['50', '30', '20']), ['propietarios' => '3', 'propietario_2' => 'Luis', 'documento_propietario_2' => '222', 'propietario_3' => 'Marta', 'documento_propietario_3' => '333', 'porcentaje_1' => '40', 'porcentaje_2' => '40', 'porcentaje_3' => '20']);
$result = (new LeaseContractCreationService($db))->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['porcentaje_1'] === '50' && count($result['warnings']) === 1 && str_contains($result['warnings'][0], 'se copian los del mandato'), 'a closing sheet that differs from the mandate is a warning and the mandate wins');

// 7. Mandato viejo con propietarios=2 y el dueño 2 vacío: queda un solo propietario.
[$pdo, $db] = $setup(['propietarios' => '2', 'nombre_1' => 'Ana', 'documento_1' => '111', 'nombre_2' => '', 'documento_2' => '']);
$result = (new LeaseContractCreationService($db))->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['propietarios'] === '1' && $row['porcentaje_1'] === '100' && count($result['warnings']) === 1 && str_contains($result['warnings'][0], 'propietario 2 no tiene nombre'), 'an old mandate with an empty owner 2 still works');

// 8. Copropietario sin id: se busca su ficha por documento; sin ficha queda el aviso.
[$pdo, $db] = $setup($three(['50', '25', '25'], ['500', '', '']), [], true, [], [['_ID' => 601, 'nombre' => 'Luis', 'documento' => '2.22']]);
$result = (new LeaseContractCreationService($db))->create(10, $input, $sender);
$row = $contractRow($pdo);
$check($row['id_propietario_2'] === '601' && $row['id_propietario_3'] === null && $row['porcentaje_3'] === '25', 'a co-owner without id is matched by document');
$check(count($result['warnings']) === 1 && str_contains($result['warnings'][0], 'Marta no tiene ficha en Propietarios'), 'a co-owner without profile is a warning');

// Sin mandato ni hoja: no se escribe nada de copropietarios.
[$pdo, $db] = $setup(['propietarios' => '1']);
$service = new LeaseContractCreationService($db);
$co = $service->coOwners(['_ID' => 99], []);
$check($co['owners'] === [] && $co['columns'] === [], 'without mandate nor closing sheet nothing is copied');

echo "Lease contract creation checks passed.\n";
