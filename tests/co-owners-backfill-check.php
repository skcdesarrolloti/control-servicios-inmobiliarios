<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Modules\LeaseContracts\CoOwnersBackfill;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

final class CoOwnersBackfillTestPdo extends PDO
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
    return parent::prepare(str_replace('AS UNSIGNED', 'AS INTEGER', $query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$pdo = new CoOwnersBackfillTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', $columns)) . ')');
};
$insert = static function (string $name, array $row) use ($pdo): void {
  $pdo->prepare("INSERT INTO {$name}(" . implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', array_keys($row))) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
};
$cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];

$mandateColumns = array_merge($cct, ['propietarios', 'id_propietario']);
for ($i = 1; $i <= 6; $i++) array_push($mandateColumns, 'tipo_' . $i, 'nombre_' . $i, 'documento_' . $i, 'correo_' . $i, 'celular_' . $i, 'empresa_' . $i, 'nit_' . $i, 'id_propietario_nuevo_' . $i, 'porcentaje_' . $i);
$table('wp_jet_cct_contrato_mandato', $mandateColumns);
$contractColumns = array_merge($cct, ['contrato', 'estado', 'propietario', 'id_propietario', 'id_contrato_mandato', 'propietarios']);
for ($i = 1; $i <= 6; $i++) {
  $contractColumns[] = 'porcentaje_' . $i;
  if ($i > 1) $contractColumns[] = 'id_propietario_' . $i;
}
$table('wp_jet_cct_contratos_arrendamiento', $contractColumns);
$table('wp_jet_cct_propietarios', array_merge($cct, ['nombre', 'documento', 'correo', 'celular', 'tipo_persona', 'nombre_juridico', 'documento_juridico']));

$insert('wp_jet_cct_propietarios', ['_ID' => 500, 'nombre' => 'Ana', 'documento' => '111.111']);
$insert('wp_jet_cct_propietarios', ['_ID' => 501, 'nombre' => 'Luis', 'documento' => '2.222.222']);
// #31: tres dueños con %; Luis tiene ficha (sin id en el mandato) y Marta, empresa, no tiene ficha.
$insert('wp_jet_cct_contrato_mandato', ['_ID' => 31, 'propietarios' => '3', 'id_propietario' => '500',
  'nombre_1' => 'Ana', 'documento_1' => '111111', 'id_propietario_nuevo_1' => '500', 'porcentaje_1' => '50',
  'nombre_2' => 'Luis', 'documento_2' => '2222222', 'porcentaje_2' => '30,5',
  'tipo_3' => 'Juridico', 'nombre_3' => 'Inversiones Marta SAS', 'documento_3' => '900.123.456-7', 'empresa_3' => 'Marta Rep', 'nit_3' => '3333333', 'correo_3' => 'marta@example.invalid', 'porcentaje_3' => '19.5']);
// #32: dos dueños sin %.
$insert('wp_jet_cct_contrato_mandato', ['_ID' => 32, 'propietarios' => '2', 'id_propietario' => '500', 'nombre_1' => 'Ana', 'documento_1' => '111111', 'nombre_2' => 'Luis', 'documento_2' => '2222222', 'id_propietario_nuevo_2' => '501']);
// #33: mandato viejo con propietarios=2 y el dueño 2 vacío. #34: un solo dueño (no se toca).
$insert('wp_jet_cct_contrato_mandato', ['_ID' => 33, 'propietarios' => '2', 'id_propietario' => '500', 'nombre_1' => 'Ana', 'documento_1' => '111111']);
$insert('wp_jet_cct_contrato_mandato', ['_ID' => 34, 'propietarios' => '1', 'id_propietario' => '500', 'nombre_1' => 'Ana', 'documento_1' => '111111']);

$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 10, 'contrato' => '701', 'estado' => 'Activo', 'id_propietario' => '500', 'id_contrato_mandato' => '31', 'propietarios' => '1']);
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 11, 'contrato' => '702', 'estado' => 'Activo', 'id_propietario' => '500', 'id_contrato_mandato' => '31', 'porcentaje_1' => '60']);
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 12, 'contrato' => '703', 'estado' => 'Activo', 'id_propietario' => '500', 'id_contrato_mandato' => '32']);
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 13, 'contrato' => '704', 'estado' => 'Activo', 'id_propietario' => '500', 'id_contrato_mandato' => '33']);
$insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 14, 'contrato' => '705', 'estado' => 'Activo', 'id_propietario' => '500', 'id_contrato_mandato' => '34']);

$snapshot = static fn(): string => json_encode([
  $pdo->query('SELECT * FROM wp_jet_cct_contrato_mandato ORDER BY _ID')->fetchAll(PDO::FETCH_ASSOC),
  $pdo->query('SELECT * FROM wp_jet_cct_contratos_arrendamiento ORDER BY _ID')->fetchAll(PDO::FETCH_ASSOC),
  $pdo->query('SELECT * FROM wp_jet_cct_propietarios ORDER BY _ID')->fetchAll(PDO::FETCH_ASSOC),
]);
$contract = static fn(int $id): array => $pdo->query("SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = {$id}")->fetch(PDO::FETCH_ASSOC);
$mandate = static fn(int $id): array => $pdo->query("SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = {$id}")->fetch(PDO::FETCH_ASSOC);

// Modo de prueba: muestra lo que haría y no escribe nada.
$before = $snapshot();
$plan = (new CoOwnersBackfill($db))->run(false);
$check($snapshot() === $before, 'the dry run writes nothing');
$actions = array_map(static fn(array $r): string => $r['mandato'] . ':' . $r['indice'] . ':' . $r['accion'] . ':' . $r['id'], $plan['profiles']);
$check($actions === ['31:2:vincular:501', '31:3:registrar:'], 'the dry run links Luis by document and plans to register the company');
$check(array_column($plan['contracts'], 'id') === ['10', '11', '12', '13'] &&!in_array('14', array_column($plan['contracts'], 'id'), true), 'the dry run lists the contracts to fill and skips single-owner mandates');

$thrown = '';
try {
  (new CoOwnersBackfill($db))->run(true);
} catch (\InvalidArgumentException $error) {
  $thrown = $error->getMessage();
}
$check(str_contains($thrown, '--funcionario'), 'applying requires the employee that runs it');

// Aplicar.
$result = (new CoOwnersBackfill($db))->run(true, 'EMP-7');
$marta = $pdo->query("SELECT * FROM wp_jet_cct_propietarios WHERE nombre = 'Inversiones Marta SAS'")->fetch(PDO::FETCH_ASSOC);
$check(is_array($marta) && $marta['cct_author_id'] === 'EMP-7' && $marta['tipo_persona'] === 'Juridica' && $marta['nombre_juridico'] === 'Marta Rep' && $marta['correo'] === 'marta@example.invalid', 'a co-owner without profile is registered with the employee as author');
$check($mandate(31)['id_propietario_nuevo_2'] === '501' && $mandate(31)['id_propietario_nuevo_3'] === (string) $marta['_ID'], 'the mandate gets id_propietario_nuevo_N');

$c = $contract(10);
$check($c['propietarios'] === '3' && $c['id_propietario_2'] === '501' && $c['id_propietario_3'] === (string) $marta['_ID'] && $c['porcentaje_1'] === '50' && $c['porcentaje_2'] === '30.5' && $c['porcentaje_3'] === '19.5', 'the contract gets the co-owners and percentages from the mandate (a stale "1" is replaced)');
$c = $contract(11);
$check($c['porcentaje_1'] === '60' && $c['porcentaje_2'] === '30.5' && $c['id_propietario_2'] === '501', 'a different contract value is kept and only empty columns are filled');
$check(count(array_filter($result['conflicts'], static fn(array $r): bool => $r['id'] === '11' && $r['columna'] === 'porcentaje_1' && $r['contrato_valor'] === '60' && $r['mandato_valor'] === '50')) === 1, 'the different value is listed as a conflict');
$c = $contract(12);
$check($c['propietarios'] === '2' && $c['id_propietario_2'] === '501' && $c['porcentaje_1'] === null, 'a mandate without percentages copies the ids only');
$c = $contract(13);
$check($c['propietarios'] === '1' && $c['id_propietario_2'] === null, 'an old mandate with an empty owner 2 leaves a single owner');
$check($contract(14)['propietarios'] === null, 'single-owner mandates are not touched');

$missing = array_column($result['missing_shares'], 'motivo', 'id');
$check(isset($missing['12']) && str_contains($missing['12'], 'sin porcentaje registrado') && !isset($missing['10']) && !isset($missing['13']), 'contracts with co-owners without a valid percentage are listed');
// #11 suma 110 en el contrato: el liquidador cae al % del mandato, así que no falta %; queda en conflictos.
$check(!isset($missing['11']), 'a contract whose own percentages do not add up falls back to the mandate');

// Repetir no cambia nada.
$before = $snapshot();
$again = (new CoOwnersBackfill($db))->run(true, 'EMP-7');
$check($snapshot() === $before && $again['profiles'] === [] && $again['contracts'] === [], 'running it again changes nothing');

echo "Co-owners backfill checks passed.\n";
