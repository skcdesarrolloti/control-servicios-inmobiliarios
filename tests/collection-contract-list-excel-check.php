<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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

echo "PASS: lista desde Excel (encabezados, anuladas, duplicados, NIT con DV, inmueble como respaldo y filas sin contrato).\n";
