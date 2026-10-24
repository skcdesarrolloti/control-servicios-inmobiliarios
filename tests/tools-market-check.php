<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Modules\Tools\MarketStatsService;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
};

// Área por tipo (regla del shortcode); un apartamento sin área privada usa la construida.
$assert(MarketStatsService::area(['tipo_inmueble' => 'Apartamento', 'area_privada' => '76', 'area_construida' => '80']) === 76.0, 'apartment uses private area');
$assert(MarketStatsService::area(['tipo_inmueble' => 'Apartamento', 'area_privada' => '', 'area_construida' => '51.22']) === 51.22, 'apartment without private area falls back to built area');
$assert(MarketStatsService::area(['tipo_inmueble' => 'Casa lote', 'area_terreno' => '300', 'area_construida' => '90']) === 300.0, 'casa lote uses land area');
$assert(MarketStatsService::area(['tipo_inmueble' => 'Local', 'area_construida' => '42']) === 42.0, 'local uses built area');
$assert(MarketStatsService::area(['tipo_inmueble' => 'Parqueadero', 'area_construida' => '12']) === 0.0, 'unknown types are left out');
$assert(MarketStatsService::number('1.500.000') === 1500000.0 && MarketStatsService::number('51,22') === 51.22 && MarketStatsService::number('$ 2.300.000') === 2300000.0 && MarketStatsService::number('') === 0.0, 'numbers from the table');

// Base SQLite en memoria con las columnas que usa el servicio.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE wp_jet_cct_barrios(_ID INTEGER PRIMARY KEY, barrio TEXT, ruta_asignada TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_barrios(barrio, ruta_asignada) VALUES ('Manga','8'),('Crespo','8'),('Bocagrande','1')");
$pdo->exec('CREATE TABLE wp_jet_cct_inmuebles(_ID INTEGER PRIMARY KEY, cct_status TEXT, codigo TEXT, barrio TEXT, tipo_inmueble TEXT, tipo_negocio TEXT, destinacion TEXT, estrato TEXT, estado TEXT, precio_venta TEXT, precio_arriendo TEXT, area_construida TEXT, area_privada TEXT, area_terreno TEXT, habitaciones TEXT, edad TEXT, parqueadero TEXT, deposito TEXT, vigilancia TEXT)');
$insert = $pdo->prepare('INSERT INTO wp_jet_cct_inmuebles(_ID, cct_status, codigo, barrio, tipo_inmueble, tipo_negocio, destinacion, estrato, estado, precio_venta, precio_arriendo, area_construida, area_privada, area_terreno, habitaciones, edad, parqueadero, deposito, vigilancia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$yes = 'a:1:{i:0;s:2:"Si";}';
$no = 'a:1:{i:0;s:2:"No";}';
foreach ([
  [1, 'publish', 'A1', 'Manga', 'Apartamento', 'Arriendo/Venta', 'Vivienda', '5', 'Publico', '400000000', '2000000', '80', '', '', '3', '8', $yes, $no, $yes],
  [2, 'publish', '', 'Crespo', 'Casa', 'Arriendo', 'Vivienda', '4', 'Arrendado', '', '3000000', '150', '', '', '3', '10', 'Si', $no, $no],
  [3, 'publish', 'C3', 'Manga', 'Lote', 'Venta', 'Comercio', 'Comercial', 'Publico', '900000000', '', '', '', '', '', '', $yes, $no, $no],
  [4, 'publish', 'D4', 'Manga', 'Apartamento', 'Arriendo', 'Vivienda', '5', 'Desistido', '', '9000000', '60', '60', '', '3', '2', $yes, $no, $yes],
  [5, 'publish', 'E5', 'Bocagrande', 'Apartamento', 'Arriendo', 'Vivienda', '6', 'Publico', '', '5000000', '100', '100', '', '3', '5', $yes, $no, $yes],
  [6, 'publish', 'F6', 'Manga', 'Apartamento', 'Arriendo', 'Vivienda', '5', 'Publico', '', '1800000', '70', '70', '', '3', '20', $no, $no, $yes],
] as $row) $insert->execute($row);
$service = new MarketStatsService(new \SCM\Core\Database($pdo, 'wp_'));

$options = $service->options();
$assert($options['rutas'] === ['1' => ['Bocagrande'], '8' => ['Manga', 'Crespo']] || $options['rutas'] === ['1' => ['Bocagrande'], '8' => ['Crespo', 'Manga']], 'routes group their neighborhoods');
$assert(array_column(array_filter($options['estados'], static fn(array $s): bool => $s['default']), 'value') === ['Arrendado', 'Publico'], 'Desistido is not a default state');

$result = $service->analyze(['ruta' => '8', 'parqueadero' => 'Si', 'edad' => '10', 'habitaciones' => '3', 'estados' => ['Publico', 'Arrendado']]);
$ids = array_column($result['items'], 'id');
sort($ids);
$assert($ids === [1, 1, 2], 'route + serialized "Si" + max age + rooms + states: apartment 1 (sale and rent) and house 2 (plain "Si")');
$assert($result['venta'] === [5000000.0] && in_array(25000.0, $result['arriendo'], true) && in_array(20000.0, $result['arriendo'], true), 'price per m2 by mode; both prices of one property count');

$all = $service->analyze([]);
$assert($all['without_area'] === 1 && !in_array(4, array_column($all['items'], 'id'), true), 'without states the defaults apply (Desistido out) and properties without area are counted apart');
$onlyRent = $service->analyze(['categoria' => 'Arriendo', 'parqueadero' => 'No']);
$assert($onlyRent['venta'] === [] && array_column($onlyRent['items'], 'id') === [6], 'category limits the mode and serialized "No" matches');
$bocagrande = $service->analyze(['barrio' => 'Bocagrande', 'ruta' => '8']);
$assert(array_column($bocagrande['items'], 'id') === [5], 'a selected neighborhood wins over the route');

echo "OK tools market calculator\n";
