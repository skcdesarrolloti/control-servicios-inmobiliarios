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
$original = ['indice' => 'Elementos arquitectónicos', 'area_afectada_1' => 'Muros', 'descripcion_dano' => 'Fisura antigua', 'consecuencia'=>'Filtración', 'nivel_dano'=>'Moderado', 'tiempo_atencion'=>'2 dias'];
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
  'registro_foto_dano' => implode(',', array_map(static fn(int $i): string => 'https://example.test/photo-' . $i . '.jpg', range(1, 35))),
];
$saved = $method->invoke($service, $ticket, [['damage' => 'Fisura nueva', 'solution' => 'Se selló', 'corrective' => $damage]], $actor);
$stored = unserialize((string) $db->getVar('SELECT evaluacion_de_danos FROM wp_jet_cct_revision_correctiva WHERE _ID = 7'), ['allowed_classes' => false]);
if (count($stored) !== 2 || $stored[1]['descripcion_dano'] !== 'Fisura nueva' || !preg_match('/^[a-f0-9]{32}$/', $saved[0]['corrective_sync_id'])) {
  throw new RuntimeException('El daño nuevo no quedó en la revisión.');
}
if (count(\SCM\Modules\CorrectiveReview\CorrectiveReviewPhotos::refs($stored[1]['registro_foto_dano'])) !== 35) {
  throw new RuntimeException('Las fotos se limitaron al sincronizar el acta con la revisión correctiva.');
}
$suggestions = new ReflectionMethod($service, 'uniqueSuggestedItems');
$suggested = $suggestions->invoke($service, [['damage' => 'Daño', 'damage_photos' => array_fill(0, 35, ['name' => 'photo.jpg'])]]);
if (count($suggested[0]['damage_photos']) !== 35) { throw new RuntimeException('La precarga del acta truncó las fotos.'); }
$method->invoke($service, $ticket, $saved, $actor, $saved);
$stored = unserialize((string) $db->getVar('SELECT evaluacion_de_danos FROM wp_jet_cct_revision_correctiva WHERE _ID = 7'), ['allowed_classes' => false]);
if (count($stored) !== 2) { throw new RuntimeException('La edición duplicó el daño en la revisión.'); }
$edited = $saved;
$edited[0]['corrective']['descripcion_dano'] = 'Fisura corregida desde acta';
$edited[0]['corrective']['area_afectada_1'] = 'Cielos rasos y luminarias';
$edited = $method->invoke($service, $ticket, $edited, $actor, $saved);
$stored = unserialize((string) $db->getVar('SELECT evaluacion_de_danos FROM wp_jet_cct_revision_correctiva WHERE _ID = 7'), ['allowed_classes' => false]);
if (count($stored) !== 2 || $stored[0] !== $original || $stored[1]['descripcion_dano'] !== 'Fisura corregida desde acta' || !str_contains($edited[0]['damage'], 'Fisura corregida desde acta')) throw new RuntimeException('La edición no actualizó el daño original sin duplicar ni modificar otros daños.');
if ((string) $db->getVar('SELECT cct_author_id FROM wp_jet_cct_revision_correctiva WHERE _ID = 7') !== 'EMP-42') throw new RuntimeException('La edición no registró al funcionario real.');
$suggestedMethod = new ReflectionMethod($service, 'suggestedItemsFromCorrectiveReview');
$suggestedItems = $suggestedMethod->invoke($service, '7');
if (empty($suggestedItems[1]['corrective']) || empty($suggestedItems[1]['corrective_sync_id'])) throw new RuntimeException('La precarga perdió el formato correctivo.');
$again = $method->invoke($service, $ticket, $suggestedItems, $actor);
$stored = unserialize((string) $db->getVar('SELECT evaluacion_de_danos FROM wp_jet_cct_revision_correctiva WHERE _ID = 7'), ['allowed_classes' => false]);
if (count($stored) !== 2) throw new RuntimeException('La precarga duplicó daños existentes.');
$view = new \SCM\Modules\TicketCompletion\CompletionView(static fn(int $index, array $data): string => '<input name="items[' . $index . '][corrective][descripcion_dano]" value="' . htmlspecialchars((string) ($data['descripcion_dano'] ?? ''), ENT_QUOTES, 'UTF-8') . '">');
$itemHtml = $view->item(0, $edited[0]);
if (str_contains($itemHtml, '<fieldset data-acta-corrective-fields disabled') || !str_contains($itemHtml, 'Fisura corregida desde acta')) throw new RuntimeException('Los daños sincronizados siguen bloqueados en el formulario.');
$stale = $saved;
try {
  $method->invoke($service, $ticket, $stale, $actor, $saved);
  throw new RuntimeException('Una edición antigua sobrescribió cambios de correctiva.');
} catch (DomainException $expected) {}
echo "Corrective review act sync checks passed: append, photos, stable edit, preserved siblings and author, structured preload.\n";
