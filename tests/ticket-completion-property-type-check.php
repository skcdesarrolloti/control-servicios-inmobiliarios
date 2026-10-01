<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');

use SCM\Core\Database;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Support\SchemaInspector;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento (_ID INTEGER PRIMARY KEY, contrato TEXT, tipo_inmueble TEXT, id_inmueble_data TEXT, id_inmueble TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_inmuebles (_ID INTEGER PRIMARY KEY, codigo TEXT, id_ticket TEXT, tipo_inmueble TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_contratos_arrendamiento VALUES (525, '2000', 'Apartamento', '12466', '84016'), (2000, '9999', 'Local', '', '')");
$pdo->exec("INSERT INTO wp_jet_cct_inmuebles VALUES (12466, '84016', '', 'Casa'), (84016, '99999', '', 'Bodega'), (204578, '88888', '', 'Oficina')");
$db = new Database($pdo);
$schema = new SchemaInspector($db);
(new ReflectionProperty($schema, 'tableExistsCache'))->setValue($schema, ['wp_jet_cct_contratos_arrendamiento' => true, 'wp_jet_cct_inmuebles' => true]);
(new ReflectionProperty($schema, 'tableColumnsCache'))->setValue($schema, [
  'wp_jet_cct_contratos_arrendamiento' => ['_ID', 'contrato', 'tipo_inmueble', 'id_inmueble_data', 'id_inmueble'],
  'wp_jet_cct_inmuebles' => ['_ID', 'codigo', 'id_ticket', 'tipo_inmueble'],
]);
$service = new CompletionService(new CompletionRepository($db, $schema), str_repeat('x', 32), 'https://example.test');
$method = new ReflectionMethod($service, 'propertyMeta');
$ticket = ['id_contrato' => '525', 'contrato' => '2000', 'id_inmueble' => '84016', 'id_inmueble_data' => '12466', 'inmueble' => '204578', 'tipo_inmueble' => 'Dato viejo', 'tipo_negocio' => 'Arrendamiento', 'destinacion' => 'Vivienda'];
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) { throw new RuntimeException($label); } $checks++; echo "PASS: $label\n"; };
$meta = $method->invoke($service, $ticket);
$assert($meta['tipo_inmueble'] === 'Apartamento', 'contract type precedes ticket and web property');
$assert($meta['tipo_negocio'] === 'Arrendamiento' && $meta['destinacion'] === 'Vivienda', 'unrelated metadata preserved');
$assert($method->invoke($service, array_replace($ticket, ['id_contrato' => '']))['tipo_inmueble'] === 'Apartamento', 'contract number is not confused with another contract primary id');
$pdo->exec("UPDATE wp_jet_cct_contratos_arrendamiento SET tipo_inmueble = '  ' WHERE _ID = 525");
$assert($method->invoke($service, $ticket)['tipo_inmueble'] === 'Casa', 'empty contract falls back to linked web property');
$pdo->exec("UPDATE wp_jet_cct_contratos_arrendamiento SET id_inmueble_data = '' WHERE _ID = 525");
$assert($method->invoke($service, array_replace($ticket, ['id_inmueble_data' => '']))['tipo_inmueble'] === 'Casa', 'web code lookup never matches an unrelated primary id');
$pdo->exec("UPDATE wp_jet_cct_contratos_arrendamiento SET id_inmueble = '' WHERE _ID = 525");
$missing = array_replace($ticket, ['id_inmueble' => '', 'id_inmueble_data' => '']);
$assert($method->invoke($service, $missing)['tipo_inmueble'] === '', 'SIMI code and stale ticket type cannot supply a different property');
$missing['id_inmueble'] = '84016';
$assert($method->invoke($service, $missing)['tipo_inmueble'] === 'Casa', 'ticket web code works when contract has no property reference');
echo "$checks checks passed, local fixtures only.\n";
