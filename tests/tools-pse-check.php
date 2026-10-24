<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\Tools\PseArchive;
use SCM\Modules\Tools\PsePaymentFile;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
};

// Filas como las entregaba el lector .xls de cargarpse (texto "$valor.00"); fila 0 = encabezados.
$rows = [
  ['Anulada', 'No Factura', 'NomCedula', 'Cedula', 'Valor', 'Iva', 'Retencion', 'ReteIva', 'ReteIca', 'AdminPh'],
  ['False', '000701210010', 'SILVA LEMA CAROLINA', '33333522', '$1347715.00', '$0.00', '$0.00', '$0.00', '$0.00', '$0.00'],
  ['True', '37326', 'ALCALDIA MAYOR', '890480184', '$11465398.00', '$1834464.00', '$401289.00', '$275170.00', '$91723.00', '$0.00'],
  ['False', '37331', 'MAPFRE COLOMBIA', '830054904', '$814842.00', '$130375.00', '$28519.00', '$19556.00', '$6519.00', '$120000.00'],
  ['False', '37332', 'SIN VALOR', '1', '$0.00', '$0.00', '$0.00', '$0.00', '$0.00', '$0.00'],
];
$params = [
  'tporcentaje1' => '0', 'tporcentaje2' => '1', 'tporcentaje21' => '2', 'tporcentaje22' => '3',
  'fecha1' => '20231014', 'fecha2' => '20231019', 'fechapubli' => '20231001',
  'fecha21' => '20231024', 'fecha22' => '20231029', 'fechapubli2' => '20231002',
];

// Copia del ciclo de herramientas/cargarpse/generar.php (fuente de verdad del formato).
$legacy = static function (array $rows, array $p): array {
  $contenido = '';
  $contenido2 = '';
  $totalR = 1;
  foreach (array_slice($rows, 1) as $row) {
    if ($row[0] !== 'False') continue;
    $factura = $row[1];
    $vals = [];
    foreach ([4, 5, 6, 7, 8, 9] as $col) $vals[] = (int) substr(str_replace('$', '', $row[$col]), 0, -3);
    [$valor, $iva, $Retencion, $ReteIva, $ReteIca, $AdminPh] = $vals;
    $adicional = ($iva + $AdminPh) - ($Retencion + $ReteIva + $ReteIca);
    $valor1 = ($valor + round($valor * $p['tporcentaje1'] / 100, 0)) + $adicional;
    $valor2 = ($valor + round($valor * $p['tporcentaje2'] / 100, 0)) + $adicional;
    $valor3 = ($valor + round($valor * $p['tporcentaje21'] / 100, 0)) + $adicional;
    $valor4 = ($valor + round($valor * $p['tporcentaje22'] / 100, 0)) + $adicional;
    if (round($valor1) > 0) {
      $totalR++;
      $contenido .= '02,' . $factura . ',' . $factura . ',' . $p['fecha1'] . ',' . $p['fecha2'] . ',' . $valor1 . '.00' . ',' . $valor2 . '.00' . "\r\n";
      $contenido2 .= '02,' . $factura . ',' . $factura . ',' . $p['fecha21'] . ',' . $p['fecha22'] . ',' . $valor3 . '.00' . ',' . $valor4 . '.00' . "\r\n";
    }
  }
  return [
    '01,900028884,0,48686,' . $p['fechapubli'] . ',' . $totalR . "\r\n" . $contenido,
    '01,900028884,0,48686,' . $p['fechapubli2'] . ',' . $totalR . "\r\n" . $contenido2,
  ];
};

$result = PsePaymentFile::build($rows, [
  'first' => ['pay1' => '2023-10-14', 'pay2' => '2023-10-19', 'pct1' => '0', 'pct2' => '1', 'published' => '2023-10-01'],
  'second' => ['pay1' => '2023-10-24', 'pay2' => '2023-10-29', 'pct1' => '2', 'pct2' => '3', 'published' => '2023-10-02'],
]);
[$expected1, $expected2] = $legacy($rows, $params);
$assert($result['files'][0]['content'] === $expected1, 'first period file matches generar.php byte for byte');
$assert($result['files'][1]['content'] === $expected2, 'second period file matches generar.php byte for byte');
$assert($result['files'][0]['name'] === '20231001_1Periodo.txt' && $result['files'][1]['name'] === '20231002_2Periodo.txt', 'file names follow <publicación>_<n>Periodo.txt');
$assert($result['records'] === 2 && $result['annulled'] === 1 && $result['without_value'] === 1, 'counts annulled and zero-value invoices apart');
$assert(str_contains($expected1, "02,000701210010,000701210010,20231014,20231019,1347715.00,1361192.00\r\n"), 'legacy sample line format');

// Celdas numéricas o con formato colombiano dan el mismo archivo que el texto "$valor.00".
$numeric = $rows;
$numeric[1][4] = 1347715.0;
$numeric[3][4] = '$ 814.842,00';
$numeric[3][9] = '120.000';
$same = PsePaymentFile::build($numeric, [
  'first' => ['pay1' => '20231014', 'pay2' => '20231019', 'pct1' => '0', 'pct2' => '1', 'published' => '20231001'],
  'second' => ['pay1' => '20231024', 'pay2' => '20231029', 'pct1' => '2', 'pct2' => '3', 'published' => '20231002'],
]);
$assert($same['files'][0]['content'] === $expected1, 'numeric and Colombian-formatted cells produce the same file');

$threw = false;
try {
  PsePaymentFile::build([$rows[0], $rows[2]], ['first' => ['pay1' => '2023-10-14', 'pay2' => '2023-10-19', 'published' => '2023-10-01'], 'second' => ['pay1' => '2023-10-24', 'pay2' => '2023-10-29', 'published' => '2023-10-02']]);
} catch (InvalidArgumentException $error) {
  $threw = str_contains($error->getMessage(), 'False');
}
$assert($threw, 'a sheet without payable invoices is rejected instead of writing an empty file');

$threw = false;
try {
  PsePaymentFile::build($rows, ['first' => ['pay1' => '', 'pay2' => '2023-10-19', 'published' => '2023-10-01'], 'second' => []]);
} catch (InvalidArgumentException $error) {
  $threw = str_contains($error->getMessage(), 'primer periodo');
}
$assert($threw, 'missing dates name the period');

// Fechas sugeridas: días de la última generación llevados al mes actual; fin de mes se conserva como fin de mes.
$today = new DateTimeImmutable('2026-02-10');
$suggested = PsePaymentFile::suggested(['p1_pay1' => '2025-10-15', 'p1_pay2' => '2025-10-20', 'p1_published' => '2025-10-01', 'p2_pay1' => '2025-10-26', 'p2_pay2' => '2025-10-31', 'p2_published' => '2025-10-02', 'p1_pct1' => '0,5', 'p2_pct2' => 'x'], $today);
$assert($suggested['p1_pay1'] === '2026-02-15' && $suggested['p2_pay1'] === '2026-02-26' && $suggested['p2_pay2'] === '2026-02-28', 'last generation days move to the current month (31 -> end of February)');
$assert($suggested['p1_pct1'] === '0,5' && $suggested['p2_pct2'] === '3' && $suggested['p1_pct2'] === '1', 'last percentages are kept; invalid or missing ones use 0, 1, 2, 3');
$fresh = PsePaymentFile::suggested([], $today);
$assert($fresh['p1_published'] === '2026-02-01' && $fresh['p1_pay1'] === '2026-02-15' && $fresh['p1_pay2'] === '2026-02-20' && $fresh['p2_published'] === '2026-02-02' && $fresh['p2_pay1'] === '2026-02-25' && $fresh['p2_pay2'] === '2026-02-28', 'without history the archive pattern (1/15/20 and 2/25/end) is suggested');

$dir = sys_get_temp_dir() . '/scm-pse-archive-' . bin2hex(random_bytes(3));
$archive = new PseArchive($dir);
$archive->save('20231001_1Periodo.txt', $expected1, ['origin' => 'historico', 'at' => 1696136400]);
$archive->save('20231001_1Periodo.txt', $expected1 . "02,x\r\n", ['actor_id' => '45', 'actor_name' => 'Funcionario QA', 'records' => 3]);
$files = $archive->files();
$assert(count($files) === 1 && $files[0]['records'] === 3, 'same name replaces the archived file');
$assert($files[0]['actor_name'] === 'Funcionario QA' && $files[0]['origin'] === 'panel' && $files[0]['versions'] === 2, 'history keeps who generated each version');
$history = $archive->history();
$assert($history['20231001_1Periodo.txt'][0]['origin'] === 'historico' && $history['20231001_1Periodo.txt'][1]['actor_id'] === '45', 'history lists every version in order');
$assert($archive->read('20231001_1Periodo.txt') === $expected1 . "02,x\r\n", 'archive returns the saved bytes');
foreach (['../config.php', 'a/b.txt', '20231001_1Periodo.php'] as $bad) {
  $rejected = false;
  try {
    $archive->read($bad);
  } catch (InvalidArgumentException $error) {
    $rejected = true;
  }
  $assert($rejected, 'archive rejects unsafe name ' . $bad);
}
array_map('unlink', glob($dir . '/*') ?: []);
@unlink($dir . '/history.json');
@rmdir($dir);

echo "OK tools PSE generator and archive\n";
