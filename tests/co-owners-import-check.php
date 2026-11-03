<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Modules\LeaseContracts\ContractCoOwners;
use SCM\Modules\LeaseContracts\CoOwnersImport;
use SCM\Support\XlsxReader;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

final class CoOwnersImportTestPdo extends PDO
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
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

// .xlsx mínimo (ZIP sin compresión) con la hoja y sus textos compartidos, para probar el lector sin librerías.
$xlsx = static function (array $rows): string {
  $strings = [];
  $sheet = '';
  foreach ($rows as $r => $row) {
    $sheet .= '<row r="' . ($r + 1) . '">';
    foreach (array_values($row) as $c => $value) {
      $ref = chr(65 + $c) . ($r + 1);
      if (is_numeric($value)) {
        $sheet .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
      } else {
        $strings[] = htmlspecialchars((string) $value, ENT_XML1);
        $sheet .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
      }
    }
    $sheet .= '</row>';
  }
  $files = [
    'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
    'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . implode('', array_map(static fn(string $s): string => '<si><t>' . $s . '</t></si>', $strings)) . '</sst>',
    'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet . '</sheetData></worksheet>',
  ];
  $data = '';
  $central = '';
  foreach ($files as $name => $content) {
    $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, crc32($content), strlen($content), strlen($content), strlen($name), 0);
    $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, crc32($content), strlen($content), strlen($content), strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
    $data .= $header . $name . $content;
  }
  $path = tempnam(sys_get_temp_dir(), 'xlsx');
  file_put_contents($path, $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0));
  return $path;
};

$header = ['Inmueble', 'Coop Propietario', 'Porcentaje', 'Ciudad', 'Cedula Coop', 'Direccion Correspon', 'Expedida', 'Telefono Correspon', 'Email', 'Girar Saldo a', 'Cedula Saldo a', 'Tipo Doc Giro', 'TipoDocGiro', 'Nom Propietario', 'Cedula Pro', '% Propie'];
$line = static fn(string $code, string $name, string $percent, string $doc, string $phone, string $email, string $main, string $mainDoc, string $mainPercent): array => [$code, $name, $percent, 'Cartagena de Indias', $doc, 'Calle 1', 'Cartagena de Indias', $phone, $email, $name, $doc, '1', 'Cédula de Ciudadania', $main, $mainDoc, $mainPercent];
$rows = [$header,
  // 9001: Ana 40 % + Luis (ya tiene ficha) 30 % + Marta 20 % + EMPRESA EJEMPLO SAS 10 %.
  $line('9001', 'LUIS  DOS', '30', '2222222', '3000000001', 'luis@example.invalid', 'ANA UNO', '111111', '40'),
  $line('9001', 'MARTA TRES', '20', '3333333', '(5)0000000', 'marta@example.invalid', 'ANA UNO', '111111', '40'),
  $line('9001', 'EMPRESA EJEMPLO SAS', '10', '900000001', '(5)6000001', 'empresa@example.invalid', 'ANA UNO', '111111', '40'),
  // 9002: no suma 100. 9003: el contrato tiene otro principal. 9004: sin contrato activo.
  $line('9002', 'PEDRO', '30', '4444444', '', '', 'ROSA', '555555', '50'),
  $line('9003', 'JUAN', '50', '6666666', '', '', 'OTRO DUEÑO', '999999', '50'),
  $line('9004', 'LINA', '50', '7777777', '', '', 'TOMAS', '888888', '50'),
];
// 9005: ocho propietarios (12,5 % cada uno).
foreach (range(2, 8) as $i) $rows[] = $line('9005', 'SOCIO ' . $i, '12.5', '10' . $i . '0000', '', '', 'SOCIO PRINCIPAL', '1000000', '12.5');
$rows[] = ['', 'Registros: 13', '', '', '', '', '', '', '', '', '', '', '', '', '', ''];
$file = $xlsx($rows);

// Lector .xlsx y agrupación.
$read = XlsxReader::rows($file);
$check(count($read) === count($rows) && $read[1][1] === 'LUIS  DOS' && $read[1][2] === '30', 'the pure PHP reader reads the xlsx rows and shared strings');
$groups = CoOwnersImport::groups($read);
$codes = array_column($groups, 'inmueble');
$check($codes === ['9001', '9002', '9003', '9004', '9005'], 'rows are grouped by property and the footer is skipped');
$check($groups[0]['main'] === ['name' => 'ANA UNO', 'document' => '111111', 'percent' => '40'] && $groups[0]['owners'][0]['name'] === 'LUIS DOS' && $groups[0]['owners'][1]['phone'] === '' && $groups[0]['owners'][2]['phone'] === '56000001', 'each group keeps the main owner, the co-owners and clean phones');
$check(CoOwnersImport::personType('EMPRESA EJEMPLO SAS', '900000001') === 'Juridica' && CoOwnersImport::personType('PERSONA DE EJEMPLO', '12345678') === 'Natural', 'companies are detected by name or NIT');
try {
  CoOwnersImport::groups([['Inmueble', 'Nombre']]);
  $check(false, 'a file without the expected columns is rejected');
} catch (InvalidArgumentException $error) {
  $check(str_contains($error->getMessage(), 'coop propietario'), 'a file without the expected columns is rejected');
}

$setup = static function (int $max): array {
  $pdo = new CoOwnersImportTestPdo();
  $db = new Database($pdo, 'wp_');
  $table = static function (string $name, array $columns) use ($pdo): void {
    $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', $columns)) . ')');
  };
  $insert = static function (string $name, array $row) use ($pdo): void {
    $pdo->prepare("INSERT INTO {$name}(" . implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', array_keys($row))) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
  };
  $cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];
  $columns = array_merge($cct, ['contrato', 'estado', 'inmueble', 'id_inmueble', 'propietario', 'documento_propietario', 'id_propietario', 'id_contrato_mandato', 'propietarios']);
  for ($i = 1; $i <= $max; $i++) {
    $columns[] = 'porcentaje_' . $i;
    if ($i > 1) array_push($columns, 'id_propietario_' . $i, 'propietario_' . $i, 'documento_propietario_' . $i, 'correo_propietario_' . $i, 'celular_propietario_' . $i, 'indicativo_propietario_' . $i, 'tipo_persona_propietario_' . $i);
  }
  $table('wp_jet_cct_contratos_arrendamiento', $columns);
  $table('wp_jet_cct_propietarios', array_merge($cct, ['nombre', 'documento', 'correo', 'celular', 'indicativo', 'tipo_persona', 'direccion', 'expedicion']));
  $table('wp_jet_cct_historial_contrato_arrendamiento', array_merge($cct, ['fecha', 'id_empleado', 'tipo_reporte', 'observacion', 'id_contrato', 'contrato', 'inmueble']));
  $mandate = array_merge($cct, ['propietarios', 'id_propietario']);
  for ($i = 1; $i <= 6; $i++) array_push($mandate, 'nombre_' . $i, 'documento_' . $i, 'id_propietario_nuevo_' . $i, 'porcentaje_' . $i);
  $table('wp_jet_cct_contrato_mandato', $mandate);

  $insert('wp_jet_cct_propietarios', ['_ID' => 500, 'nombre' => 'Ana Uno', 'documento' => '111.111']);
  $insert('wp_jet_cct_propietarios', ['_ID' => 501, 'nombre' => 'Luis Dos', 'documento' => '2.222.222']);
  // Mandato #31 de un solo dueño; #32 con otros copropietarios.
  $insert('wp_jet_cct_contrato_mandato', ['_ID' => 31, 'propietarios' => '1', 'nombre_1' => 'Ana Uno', 'documento_1' => '111111']);
  $insert('wp_jet_cct_contrato_mandato', ['_ID' => 32, 'propietarios' => '2', 'nombre_1' => 'Ana Uno', 'documento_1' => '111111', 'nombre_2' => 'Otra', 'documento_2' => '1212121']);
  $insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 10, 'contrato' => '701', 'estado' => 'Entregado', 'inmueble' => '9001', 'propietario' => 'Ana Uno', 'id_propietario' => '500', 'id_contrato_mandato' => '31', 'propietarios' => '1']);
  $insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 11, 'contrato' => '702', 'estado' => 'Por entregar', 'inmueble' => '9001', 'propietario' => 'Ana Uno', 'documento_propietario' => '111111', 'id_contrato_mandato' => '32', 'propietarios' => '5', 'id_propietario_5' => '777', 'porcentaje_5' => '9']);
  $insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 12, 'contrato' => '690', 'estado' => 'Terminado', 'inmueble' => '9001', 'propietario' => 'Ana Uno', 'documento_propietario' => '111111']);
  $insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 13, 'contrato' => '703', 'estado' => 'Entregado', 'inmueble' => '9003', 'propietario' => 'Rosa', 'documento_propietario' => '555555']);
  $insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 14, 'contrato' => '704', 'estado' => 'Entregado', 'inmueble' => '9005', 'propietario' => 'Socio Principal', 'documento_propietario' => '1000000']);
  return [$pdo, $db];
};
$contract = static fn(PDO $pdo, int $id): array => $pdo->query("SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = {$id}")->fetch(PDO::FETCH_ASSOC);
$snapshot = static fn(PDO $pdo): string => json_encode([$pdo->query('SELECT * FROM wp_jet_cct_contratos_arrendamiento')->fetchAll(PDO::FETCH_ASSOC), $pdo->query('SELECT * FROM wp_jet_cct_propietarios')->fetchAll(PDO::FETCH_ASSOC)]);
$reasons = static fn(array $result): array => array_column($result['skipped'], 'motivo', 'inmueble');

// Modo de prueba: lista todo y no escribe.
[$pdo, $db] = $setup(8);
$before = $snapshot($pdo);
$plan = (new CoOwnersImport($db))->run($groups, false);
$check($snapshot($pdo) === $before, 'the dry run writes nothing');
$check(array_column($plan['contracts'], 'id') === ['10', '11', '14'] && array_column($plan['profiles'], 'nombre') === ['MARTA TRES', 'EMPRESA EJEMPLO SAS', 'SOCIO 2', 'SOCIO 3', 'SOCIO 4', 'SOCIO 5', 'SOCIO 6', 'SOCIO 7', 'SOCIO 8'], 'the dry run lists the active contracts and the profiles to register');
$why = $reasons($plan);
$check(str_contains($why['9002'], 'suman 80 %') && str_contains($why['9003'], 'otro propietario principal') && str_contains($why['9004'], 'sin contrato'), 'properties that do not add up, have another main owner or no active contract are skipped with the reason');
try {
  (new CoOwnersImport($db))->run($groups, true);
  $check(false, 'applying requires the employee that runs it');
} catch (InvalidArgumentException $error) {
  $check(str_contains($error->getMessage(), '--funcionario'), 'applying requires the employee that runs it');
}

// Aplicar.
$result = (new CoOwnersImport($db))->run($groups, true, 'EMP-7', 'COPROPETARIOS ACTIVOS 2026.xlsx');
$c = $contract($pdo, 10);
$marta = $pdo->query("SELECT * FROM wp_jet_cct_propietarios WHERE documento = '3333333'")->fetch(PDO::FETCH_ASSOC);
$ideas = $pdo->query("SELECT * FROM wp_jet_cct_propietarios WHERE documento = '900000001'")->fetch(PDO::FETCH_ASSOC);
$check($c['propietarios'] === '4' && $c['porcentaje_1'] === '40' && $c['id_propietario_2'] === '501' && $c['porcentaje_2'] === '30' && $c['id_propietario_3'] === (string) $marta['_ID'] && $c['id_propietario_4'] === (string) $ideas['_ID'] && $c['porcentaje_4'] === '10', 'the contract gets the main share and every co-owner with their share (existing profile reused)');
$check($marta['cct_author_id'] === 'EMP-7' && $marta['tipo_persona'] === 'Natural' && $marta['celular'] === '' && $ideas['tipo_persona'] === 'Juridica' && $ideas['celular'] === '56000001', 'new profiles are registered with the employee as author');
$c = $contract($pdo, 11);
$check($c['propietarios'] === '4' && $c['id_propietario_5'] === '' && $c['porcentaje_5'] === '' && $c['id_propietario_3'] === (string) $marta['_ID'], 'the file wins: extra slots are cleared and the same profile is used in every contract');
$check($c['propietario_3'] === 'MARTA TRES' && $c['documento_propietario_3'] === '3333333' && $c['correo_propietario_3'] === 'marta@example.invalid' && $c['indicativo_propietario_2'] === '+57' && $c['tipo_persona_propietario_4'] === 'Juridica' && $c['propietario_5'] === '' && $c['correo_propietario_5'] === '', 'the contract also gets each co-owner name, document and contact, and the extra slot contact is cleared');
$check($contract($pdo, 12)['propietarios'] === null, 'finished contracts are not touched');
$c = $contract($pdo, 14);
$check($c['propietarios'] === '8' && $c['porcentaje_8'] === '12.5' && $c['id_propietario_8'] !== '', 'a property with 8 owners fits in the contract');
$notes = array_column($result['contracts'], 'avisos', 'id');
$check($notes['11'] !== [] && str_contains($notes['11'][0], 'el mandato #32 tiene otros copropietarios') && $notes['10'] === [], 'a mandate with other co-owners is a warning');
$history = $pdo->query("SELECT * FROM wp_jet_cct_historial_contrato_arrendamiento WHERE id_contrato = '10'")->fetch(PDO::FETCH_ASSOC);
$check($history['tipo_reporte'] === 'Copropietarios' && str_contains($history['observacion'], 'LUIS DOS 30 %') && $history['cct_author_id'] === 'EMP-7', 'the change is written to the contract history');

// Repetir no cambia nada.
$before = $snapshot($pdo);
$again = (new CoOwnersImport($db))->run($groups, true, 'EMP-7');
$check($snapshot($pdo) === $before && $again['profiles'] === [] && array_filter(array_column($again['contracts'], 'cambios')) === [], 'running it again changes nothing');

// El liquidador y los avisos leen los copropietarios del contrato aunque el mandato tenga un solo dueño.
$profiles = [];
foreach ($pdo->query('SELECT * FROM wp_jet_cct_propietarios')->fetchAll(PDO::FETCH_ASSOC) as $row) $profiles[(string) $row['_ID']] = $row;
$mandate = $pdo->query('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = 31')->fetch(PDO::FETCH_ASSOC);
$owners = ContractCoOwners::resolve($contract($pdo, 10), $mandate, $profiles);
$check(array_column($owners, 'name') === ['Ana Uno', 'Luis Dos', 'MARTA TRES', 'EMPRESA EJEMPLO SAS'] && $owners[0]['share_source'] === 'contrato' && abs($owners[3]['share'] - 0.1) < 1e-9, 'with a single-owner mandate the contract co-owners and their share are used');
$check(count(ContractCoOwners::resolve($contract($pdo, 14), null, $profiles)) === 8, 'up to 8 contract owners are read');

// Sin las columnas 7 y 8, el inmueble de 8 propietarios se omite y pide crearlas.
[$pdo, $db] = $setup(6);
$why = $reasons((new CoOwnersImport($db))->run($groups, false));
$check(str_contains($why['9005'], 'faltan las columnas porcentaje_7') && str_contains($why['9005'], 'add-co-owner-7-8-fields.php'), 'without columns 7 and 8 the 8-owner property asks to create them');

@unlink($file);
echo "Co-owners import checks passed.\n";
