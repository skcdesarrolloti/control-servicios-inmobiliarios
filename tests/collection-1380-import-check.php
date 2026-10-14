<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Modules\CanonInsuranceAudit\SpreadsheetReader;
use SCM\Modules\CollectionManagement\CollectionPortfolioService;

function check1380(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$service = (new ReflectionClass(CollectionPortfolioService::class))->newInstanceWithoutConstructor();
$parse = new ReflectionMethod($service, 'parseAuxiliary');
$status = new ReflectionMethod($service, 'statusFromBalance');
$money = new ReflectionMethod($service, 'money');
$headers = ['Tipo', '', '', 'No. Transaccion', 'Nit', 'Nombre Tercero', 'Fecha', 'Detalle', 'Debito', 'Credito', 'Saldo Contable', 'Centro C.', 'No. Inm'];
$movement = static fn(string $property, string $document, mixed $balance, string $date = '05/10/2026', string $detail = 'Movimiento real'): array =>
  ['', '', 'F', '1', $document, 'Arrendatario de prueba', $date, $detail, '', '', $balance, '', $property];
$summary = static fn(mixed $balance, string $detail = ''): array => ['', '', '', '', '', '', '', $detail, '', '', $balance, '', ''];

$rows = [
  $headers,
  ['-', 'No. Inm : 10001 (Total Agrupación)'],
  ['', '-', 'Nit : 900001 (Total Agrupación)'],
  $movement('', '', '$1,315,801.88'),
  $movement('', '', '$1.88', '06/10/2026', 'Abono parcial'),
  $summary('$1,315,802'), // Subtotal del NIT: redondeado, no es saldo de la cuenta.
  ['', '-', 'Nit : 900002 (Total Agrupación)'],
  $movement('', '', '-$12.3'),
  $summary('$1,315,789.58'), // Total del inmueble: mezcla dos terceros.
  ['-', 'No. Inm : 10002 (Total Agrupación)'],
  ['', '-', 'Nit : 900003 (Total Agrupación)'],
  $movement('', '', '-$1,857,000.00'),
  $movement('', '', '-$607,000.00'),
  $movement('', '', '-$357,000.00'),
  $movement('', '', '-$57,000.00'),
  $movement('', '', '$0.00', '05/10/2026', 'Pago completo'),
  $summary('$'),
  $summary('$'),
  $summary('$1,328,638,930.06', 'Registros : 8'),
];
$parsed = $parse->invoke($service, $rows);
check1380(count($parsed['accounts']) === 3, 'Las agrupaciones y totales no deben crear cuentas.');
foreach (['10001|900001' => [1.88, 'deuda'], '10001|900002' => [-12.3, 'saldo_favor'], '10002|900003' => [0.0, 'al_dia']] as $key => [$balance, $expectedStatus]) {
  $account = $parsed['accounts'][$key];
  check1380(abs($account['balance'] - $balance) < 0.00001, 'El saldo debe ser el último movimiento de ' . $key);
  check1380($status->invoke($service, $account['balance']) === $expectedStatus, 'Clasificación incorrecta de ' . $key);
}
check1380($parsed['accounts']['10002|900003']['source_detail'] === 'Pago completo', 'El total general no debe reemplazar la evidencia del saldo.');
check1380($parsed['source_date'] === '2026-10-06', 'La fecha del auxiliar debe provenir de los movimientos.');

foreach ([[7.4, 7.4], [-4.2, -4.2], [1.234, 1.234], [1.0e-7, 1.0e-7], ['$7.4', 7.4], ['$7,4', 7.4], ['$1,234.5', 1234.5], ['$1.234,5', 1234.5], ['$1.234', 1234.0], ['($1,234.50)', -1234.5]] as [$raw, $expected]) {
  check1380(abs($money->invoke($service, $raw) - $expected) < 0.00000001, 'Decimales incorrectos: ' . (string) $raw);
}
$native = $parse->invoke($service, [$headers, $movement('10003', '900004', 7.4, '2026-10-05 00:00:00')]);
check1380($native['accounts']['10003|900004']['balance'] === 7.4, 'Conservar el tipo numérico de las celdas XLSX.');
check1380($native['source_date'] === '2026-10-05', 'Aceptar la fecha que devuelve el lector XLSX.');

$expectInvalid = static function (array $input, int $line) use ($parse, $service): void {
  try {
    $parse->invoke($service, $input);
    throw new LogicException('Se aceptó un movimiento sin identidad, fecha o saldo válido.');
  } catch (RuntimeException $exception) {
    check1380(str_contains($exception->getMessage(), 'fila ' . $line), 'El error debe identificar la fila incompleta.');
  }
};
$expectInvalid([$headers, $movement('10003', '900004', '$')], 2);
$expectInvalid([$headers, $movement('10003', '900004', '7.4', '')], 2);
$expectInvalid([$headers, $movement('10003', '900004', '7.4', '31/02/2026')], 2);
$expectInvalid([$headers, ['-', 'No. Inm : 10001'], ['', '-', 'Nit : 900001'], $movement('', '', '1'), ['-', 'No. Inm : 10002'], $movement('', '', '2')], 6);
echo "PASS: movimientos finales, totales, múltiples NIT por inmueble, ceros, signos, decimales, fechas XLSX y rechazo de movimientos incompletos.\n";

// Opcional: prueba completa contra una 1380 local, sin conectar a producción.
if (!isset($argv[1])) {
  exit(0);
}
$path = $argv[1];
$fileRows = (new SpreadsheetReader())->read($path, basename($path));
$expected = [];
$netCents = 0;
$recordCount = 0;
$footerCents = null;
$sourceMoney = static function (mixed $raw): int {
  $text = str_replace(['$', ','], '', (string) $raw);
  return (int) round((float) $text * 100);
};
foreach (array_slice($fileRows, 1) as $row) {
  if (($row[4] ?? '') !== '' && ($row[12] ?? '') !== '' && ($row[6] ?? '') !== '') {
    $key = $row[12] . '|' . $row[4];
    $debit = $sourceMoney($row[8]);
    $credit = $sourceMoney($row[9]);
    $expected[$key] = ($expected[$key] ?? 0) + $debit - $credit;
    check1380($expected[$key] === $sourceMoney($row[10]), 'No concilia el saldo acumulado de la fila ' . ($recordCount + 1));
    $netCents += $debit - $credit;
    $recordCount++;
  } elseif (str_starts_with((string) ($row[7] ?? ''), 'Registros :')) {
    $footerCents = $sourceMoney($row[10]);
  }
}
check1380($footerCents !== null && $footerCents === $netCents, 'Los movimientos deben conciliar con el total general del archivo.');
$actual = $parse->invoke($service, $fileRows)['accounts'];
check1380(count($actual) === count($expected), 'No se deben perder ni crear cuentas durante la lectura completa.');
foreach ($expected as $key => $cents) {
  check1380(isset($actual[$key]) && (int) round($actual[$key]['balance'] * 100) === $cents, 'Saldo final incorrecto para ' . $key);
}

final class Collection1380TestPdo extends PDO
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

$pdo = new Collection1380TestPdo();
$db = new Database($pdo);
$importer = new CollectionPortfolioService($db);
$importer->migrateSchema();
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento (_ID INTEGER PRIMARY KEY, estado TEXT, contrato TEXT, inmueble TEXT, documento_arrendatario TEXT)');
$id = 0;
foreach ($expected as $key => $cents) {
  [$property, $document] = explode('|', $key);
  $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => ++$id, 'estado' => 'Entregado', 'contrato' => (string) $id, 'inmueble' => $property, 'documento_arrendatario' => $document]);
}
$db->insert('wp_scm_collection_imports', [
  'source_filename' => basename($path),
  'source_hash' => hash('sha256', 'auxiliar-1380-v1|' . hash_file('sha256', $path)),
  'uploaded_at' => '2026-10-01 00:00:00',
]);
$file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => basename($path), 'size' => filesize($path)];
$result = $importer->import($file);
check1380($result['duplicate'] === false, 'Debe permitir reprocesar archivos importados con la versión anterior.');
$counts = ['deuda' => 0, 'al_dia' => 0, 'saldo_favor' => 0];
foreach ($db->getResults('SELECT * FROM wp_scm_collection_portfolio WHERE is_current = 1') as $account) {
  $key = $account['property_code'] . '|' . $account['tenant_document'];
  $cents = $expected[$key];
  $expectedStatus = $cents > 0 ? 'deuda' : ($cents < 0 ? 'saldo_favor' : 'al_dia');
  check1380((int) round((float) $account['balance'] * 100) === $cents, 'Persistencia incorrecta de ' . $key);
  check1380($account['status'] === $expectedStatus && $account['match_status'] === 'exacto', 'Cruce o clasificación incorrectos de ' . $key);
  $counts[$expectedStatus]++;
}
check1380(array_sum($counts) === count($expected), 'Persistir todas las cuentas conciliadas.');
check1380($result['summary']['debtors'] === $counts['deuda'] && $result['summary']['current'] === $counts['al_dia'] && $result['summary']['credits'] === $counts['saldo_favor'], 'El panel debe reflejar la clasificación persistida.');
$repeat = $importer->import($file);
check1380($repeat['duplicate'] === true && $repeat['import_id'] === $result['import_id'], 'La nueva versión también debe evitar importaciones duplicadas.');
check1380((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_collection_imports') === 2, 'No crear otro cargue al repetir el mismo archivo.');
echo 'PASS: ' . count($fileRows) . ' filas, ' . $recordCount . ' movimientos, ' . count($expected) . ' cuentas; conciliación por movimiento y total, importación transaccional, cruces, clasificación, panel y duplicados. Estados: ' . json_encode($counts) . ".\n";
