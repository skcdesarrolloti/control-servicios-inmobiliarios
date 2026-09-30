<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');

use SCM\Core\Database;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Support\SchemaInspector;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE wp_jet_cct_revision_correctiva (_ID INTEGER PRIMARY KEY, evaluacion_de_danos TEXT, area_afectada TEXT, cct_modified TEXT, cct_author_id TEXT, id_empleado TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_ticket (_ID INTEGER PRIMARY KEY AUTOINCREMENT, id_ticket INTEGER, respuesta TEXT, nombre TEXT, id_empleado TEXT, cct_author_id TEXT)');
$original = ['indice' => 'Elementos arquitectónicos', 'area_afectada_1' => 'Muros', 'descripcion_dano' => 'Fisura antigua'];
$statement = $pdo->prepare('INSERT INTO wp_jet_cct_revision_correctiva (_ID, evaluacion_de_danos) VALUES (?, ?)');
$statement->execute([7, serialize([$original])]);
$db = new Database($pdo);
$schema = new SchemaInspector($db);
$tableExistsCache = new ReflectionProperty($schema, 'tableExistsCache');
$tableExistsCache->setValue($schema, [
  'wp_jet_cct_revision_correctiva' => true,
  'wp_jet_cct_historial_del_ticket' => true,
]);
$tableColumnsCache = new ReflectionProperty($schema, 'tableColumnsCache');
$tableColumnsCache->setValue($schema, [
  'wp_jet_cct_revision_correctiva' => ['_ID', 'evaluacion_de_danos', 'area_afectada', 'cct_modified', 'cct_author_id', 'id_empleado'],
  'wp_jet_cct_historial_del_ticket' => ['_ID', 'id_ticket', 'respuesta', 'nombre', 'id_empleado', 'cct_author_id'],
]);
$service = new CompletionService(new CompletionRepository($db, $schema), str_repeat('x', 32), 'https://example.test');
$method = new ReflectionMethod($service, 'syncCorrectiveDamages');
$ticket = ['_ID' => 21, 'id_revision_correctiva' => '7'];
$actor = ['name' => 'Funcionario', 'employee_id' => 'EMP-42'];
$damage = [
  'indice' => 'Evaluacion de los daños en elementos arquitectonicos',
  'area_afectada_1' => 'Muros divisorios',
  'descripcion_dano' => 'Fisura nueva',
  'consecuencia' => 'Filtración',
  'nivel_dano' => 'Moderado',
  'tiempo_atencion' => '2 dias',
  'a_quien_corresponde' => 'Propietario',
];
$saved = $method->invoke($service, $ticket, [['damage' => 'Fisura nueva', 'solution' => 'Se selló', 'corrective' => $damage]], $actor);
$stored = unserialize((string) $db->getVar('SELECT evaluacion_de_danos FROM wp_jet_cct_revision_correctiva WHERE _ID = 7'), ['allowed_classes' => false]);
if (count($stored) !== 2 || $stored[1]['descripcion_dano'] !== 'Fisura nueva' || !preg_match('/^[a-f0-9]{32}$/', $saved[0]['corrective_sync_id'])) {
  throw new RuntimeException('El daño nuevo no quedó en la revisión.');
}
$method->invoke($service, $ticket, $saved, $actor, $saved);
$stored = unserialize((string) $db->getVar('SELECT evaluacion_de_danos FROM wp_jet_cct_revision_correctiva WHERE _ID = 7'), ['allowed_classes' => false]);
if (count($stored) !== 2) { throw new RuntimeException('La edición duplicó el daño en la revisión.'); }
echo "Corrective review act sync checks passed.\n";
