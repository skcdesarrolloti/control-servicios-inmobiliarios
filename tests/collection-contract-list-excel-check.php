<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Modules\CollectionManagement\CollectionPortfolioService;

function checkContractList(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$service = (new ReflectionClass(CollectionPortfolioService::class))->newInstanceWithoutConstructor();

// Estructura del reporte de facturación de SIMI.
$rows = [
  ['Sucursal', 'Anulada', 'No Factura', 'NomCedula', 'Cedula', 'NoInm', 'Total'],
  ['1115', 'False', '43486', 'RAMIREZ  AGUAS  LUIS', '1007882830', '10507', '$67846.00'],
  ['1115', 'False', '43509', 'TINOCO MARTINEZ MARIA', '1047470422', '10669', '$1408000.00'],
  ['1115', 'False', '43511', 'TINOCO MARTINEZ MARIA', '1047470422', '10669', '$335104.00'], // Repetida: misma persona e inmueble.
  ['1115', 'True', '43520', 'ANULADA SA', '5555', '10800', '$1.00'],
  ['1115', 'False', '43512', 'BANCO WWB S.A.', '900378212', '10126', '$15286308.00'],
  ['1115', 'False', '43600', 'SIN CONTRATO', '777', '99999', '$1.00'],
  ['1115', 'False', '43601', 'DOC DISTINTO', '888', '10300', '$1.00'],
  ['', '', '', '342', '', '', '$1010061385.00'], // Fila de totales.
];
$entries = $service->parseContractListRows($rows);
checkContractList(count($entries) === 5, 'Debe omitir anuladas, repetidas y la fila de totales: ' . count($entries));
checkContractList($entries[0]['name'] === 'RAMIREZ AGUAS LUIS', 'Debe normalizar espacios del nombre.');

$contracts = [
  ['_ID' => 1, 'documento_arrendatario' => '1007882830', 'inmueble' => '10507'],
  ['_ID' => 2, 'documento_arrendatario' => '1047470422', 'inmueble' => '10669'],
  ['_ID' => 3, 'documento_arrendatario' => '1047470422', 'inmueble' => '10400'], // Otro contrato de la misma persona.
  ['_ID' => 4, 'documento_arrendatario' => '900.378.212-1', 'inmueble' => '10126'],
  ['_ID' => 5, 'documento_arrendatario' => '123', 'inmueble' => '10300'],
  ['_ID' => 6, 'documento_arrendatario' => '5555', 'inmueble' => '10800'],
];
$result = $service->matchContractListEntries($entries, $contracts);
$ids = $result['contract_ids'];
sort($ids);
checkContractList($ids === [1, 2, 4, 5], 'Cruce incorrecto: ' . json_encode($ids));
checkContractList($result['matched_entries'] === 4, 'Filas cruzadas incorrectas.');
checkContractList(count($result['unmatched']) === 1 && $result['unmatched'][0]['document'] === '777', 'Debe reportar la fila sin contrato.');

$failed = false;
try {
  $service->parseContractListRows([['Columna A', 'Columna B'], ['1', '2']]);
} catch (RuntimeException $exception) {
  $failed = true;
}
checkContractList($failed, 'Debe rechazar archivos sin columnas de documento o inmueble.');

// Ciclo completo: subir, guardar la lista en la tabla del módulo y filtrar.
final class ContractListTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) {
      $query = "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1";
    }
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $match)) {
      $query = "SELECT name FROM pragma_table_info('{$match[1]}')";
    }
    return parent::prepare($query, $options);
  }

  public function exec(string $statement): int|false
  {
    if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS')) {
      $statement = preg_replace('/`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $statement);
      $statement = preg_replace('/,\s*PRIMARY KEY \(`id`\)/', '', $statement);
      $statement = preg_replace('/,\s*UNIQUE KEY `[^`]+` (\([^)]*\))/', ', UNIQUE $1', $statement);
      $statement = preg_replace('/,\s*KEY `[^`]+`\s*\([^)]*\)/', '', $statement);
      $statement = preg_replace('/\) ENGINE=InnoDB.*$/s', ')', $statement);
    }
    return parent::exec($statement);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $statement = $this->prepare($query);
    $statement->execute();
    if ($fetchMode !== null) {
      $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
    }
    return $statement;
  }
}

$pdo = new ContractListTestPdo();
$db = new Database($pdo);
$portfolio = new CollectionPortfolioService($db);
$portfolio->migrateSchema();
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento (_ID INTEGER PRIMARY KEY, estado TEXT, contrato TEXT, inmueble TEXT, documento_arrendatario TEXT, arrendatario TEXT)');
foreach ($contracts as $contract) {
  $db->insert('wp_jet_cct_contratos_arrendamiento', $contract + ['estado' => 'Entregado', 'contrato' => 'C' . $contract['_ID'], 'arrendatario' => 'Arrendatario ' . $contract['_ID']]);
}
$token = bin2hex(random_bytes(8));
$store = new ReflectionMethod($portfolio, 'storeContractList');
$store->invoke($portfolio, $token, [
  'file_name' => 'facturacion noviembre.xls',
  'entries' => count($entries),
  'matched_entries' => $result['matched_entries'],
  'contracts' => count($result['contract_ids']),
  'unmatched' => $result['unmatched'],
  'unmatched_count' => count($result['unmatched']),
  'contract_ids' => $result['contract_ids'],
]);
$list = ['token' => $token, 'contracts' => count($result['contract_ids'])];
checkContractList(preg_match('/^[a-f0-9]{16}$/', (string) $list['token']) === 1 && $list['contracts'] === 4, 'La lista debe guardarse con su token.');
$filtered = $portfolio->contractsDashboard(['list' => $list['token']]);
$filteredIds = array_map(static fn(array $row): int => (int) $row['contract_id'], $filtered['rows']);
sort($filteredIds);
checkContractList($filteredIds === [1, 2, 4, 5], 'El filtro por lista debe mostrar solo los contratos cruzados: ' . json_encode($filteredIds));
checkContractList(($filtered['list']['file_name'] ?? '') === 'facturacion noviembre.xls' && ($filtered['list']['unmatched_count'] ?? 0) === 1, 'El panel debe recibir el resumen de la lista.');
$expired = $portfolio->contractsDashboard(['list' => 'ffffffffffffffff']);
checkContractList($expired['rows'] === [] && !empty($expired['list']['expired']), 'Una lista inexistente o vencida no debe mostrar contratos.');

echo "PASS: lista desde Excel (encabezados, anuladas, duplicados, NIT con DV, inmueble como respaldo y filas sin contrato, guardado y filtro del panel).\n";
