<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Políticas al control documental (Gestor organizacional). Ver OrganizationPolicyMigration y docs/gestor-organizacional.md.
// - «(Prov.)», «(Prov,)», «( Prov.)», «(Prov)»: audiencia Proveedores, título sin el sufijo y política base de empleados
//   con el mismo título normalizado (las que no tengan una sola coincidencia se listan y quedan sin base).
// - Las demás: audiencia Empleados.
// - Todas: estado Vigente, versión 1 y código POL-<área>-<NNN> (proveedores: el de su base + «-P»).
// Solo llena lo vacío, nunca cambia _ID. Con --aplicar también deja la versión 1 en el historial de cada política.
//
// Uso: php bin/migrate-policies-provider.php            (muestra cada cambio, no escribe)
//      php bin/migrate-policies-provider.php --aplicar  (lo hace; requiere bin/add-policy-control-fields.php --aplicar)

use SCM\Modules\OrganizationManager\OrganizationActivityLog;
use SCM\Modules\OrganizationManager\OrganizationPolicies;
use SCM\Modules\OrganizationManager\OrganizationPolicyMigration;
use SCM\Support\SchemaInspector;

$apply = in_array('--aplicar', $argv, true);
$db = \SCM\Core\App::db();
$schema = new SchemaInspector($db);
$table = $db->table('jet_cct_politicas');
$areasTable = $db->table('jet_cct_areas_organizacion');

$controlColumns = array_values(array_filter(['codigo', 'version', 'estado', 'audiencia', 'id_politica_base'], static fn(string $column): bool => $schema->columnExists($table, $column)));
$select = array_merge(['_ID', 'titulo', 'id_area', 'id_cargo', 'politica'], $controlColumns);
$rows = $db->getResults('SELECT `' . implode('`, `', $select) . "` FROM `{$table}` ORDER BY `_ID`");
$areas = [];
foreach ($db->getResults("SELECT `_ID`, `nombre` FROM `{$areasTable}`") as $area) {
  $areas[(int) $area['_ID']] = trim((string) $area['nombre']);
}
$plan = OrganizationPolicyMigration::plan($rows, $areas);

echo "== Abreviaturas de área para los códigos\n";
foreach ($plan['abbreviations'] as $id => $abbr) {
  echo sprintf("  POL-%-6s área #%d %s\n", $abbr, $id, $areas[$id] ?? '');
}
echo "  POL-GEN    políticas sin área\n";
if (count($controlColumns) < 5) {
  echo "\nAviso: faltan columnas de control (" . implode(', ', array_diff(['codigo', 'version', 'estado', 'audiencia', 'id_politica_base'], $controlColumns)) . "). El plan se calcula igual; para aplicarlo corre antes: php bin/add-policy-control-fields.php --aplicar\n";
}

echo "\n== Cambios por política\n";
foreach ($plan['changes'] as $change) {
  $parts = [];
  foreach ($change['set'] as $column => $value) {
    $parts[] = $column === 'titulo' ? 'título: «' . $change['titulo'] . '» → «' . $value . '»' : $column . '=' . $value;
  }
  echo sprintf("  #%-4d [%s] %s\n", $change['id'], $change['kind'], implode(' · ', $parts));
}

echo "\n== Proveedores sin política base (no se adivina; asignarla a mano en el panel)\n";
foreach ($plan['unmatched'] as $item) {
  $hint = $item['suggestion'] ? ' — parecida: #' . $item['suggestion']['id'] . ' «' . $item['suggestion']['titulo'] . '» (' . $item['suggestion']['score'] . ' %)' : ' — sin parecida';
  echo "  #{$item['id']} «{$item['titulo']}»{$hint}\n";
}
if ($plan['unmatched'] === []) echo "  (ninguna)\n";
echo "\n== Proveedores con varias bases posibles\n";
foreach ($plan['ambiguous'] as $item) {
  echo "  #{$item['id']} «{$item['titulo']}» — candidatas: #" . implode(', #', $item['candidates']) . "\n";
}
if ($plan['ambiguous'] === []) echo "  (ninguna)\n";
echo "\n== Revisar: títulos de empleados repetidos\n";
foreach ($plan['duplicates'] as $item) {
  echo "  «{$item['titulo']}» — #" . implode(', #', $item['ids']) . "\n";
}
if ($plan['duplicates'] === []) echo "  (ninguno)\n";
echo "\n== Revisar: políticas sin sufijo asignadas solo a cargos de proveedores (8 y 17); quedan como Empleados\n";
foreach ($plan['provider_like'] as $item) {
  echo "  #{$item['id']} «{$item['titulo']}» (cargos {$item['id_cargo']})\n";
}
if ($plan['provider_like'] === []) echo "  (ninguna)\n";

$c = $plan['counts'];
echo "\n== Resumen\n";
echo "  Políticas: {$c['total']} · proveedores: {$c['proveedores']} · empleados: {$c['empleados']}\n";
echo "  Proveedores con base: {$c['con_base']} · sin base: {$c['sin_base']} · ambiguas: {$c['ambiguas']}\n";
echo "  Políticas con cambios: {$c['cambios']}\n";

if (!$apply) {
  echo "\nSolo se mostró el plan. Para hacerlo: php bin/migrate-policies-provider.php --aplicar\n";
  exit(0);
}
if (count($controlColumns) < 5) {
  fwrite(STDERR, "No se aplicó: faltan columnas de control. Corre primero php bin/add-policy-control-fields.php --aplicar\n");
  exit(1);
}

// Respaldo de las filas tal como estaban (títulos con «(Prov.)» incluidos) antes de escribir: el historial se siembra
// después de los cambios, así que este archivo es lo único que conserva el estado anterior.
$backupDir = dirname(__DIR__) . '/storage/data/cct-backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
$backup = $backupDir . '/politicas-migracion-' . date('Ymd-His') . '.json';
$original = $db->getResults("SELECT * FROM `{$table}` ORDER BY `_ID`");
if (file_put_contents($backup, json_encode($original, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
  fwrite(STDERR, "No se aplicó: no se pudo escribir el respaldo en {$backup}\n");
  exit(1);
}
echo "\nRespaldo de " . count($original) . " políticas: {$backup}\n";

$policies = new OrganizationPolicies($db, $schema);
$log = new OrganizationActivityLog($db, $schema);
$policies->ensureSchema();
$log->ensureSchema();
$actor = ['id' => '', 'name' => 'Migración de políticas (bin/migrate-policies-provider.php)'];
$pdo = $db->pdo();
$pdo->beginTransaction();
try {
  foreach ($plan['changes'] as $change) {
    $db->update($table, $schema->filterTableData($table, $change['set'] + ['cct_modified' => date('Y-m-d H:i:s')]), ['_ID' => $change['id']]);
  }
  $seeded = 0;
  foreach ($db->getResults("SELECT * FROM `{$table}` ORDER BY `_ID`") as $row) {
    if (!$policies->hasHistory((int) $row['_ID'])) {
      $policies->record($row, 'migracion', $actor, 'Versión inicial al activar el control documental.');
      $seeded++;
    }
  }
  $log->log('politicas', 0, 'migracion', "Migró {$c['cambios']} políticas al control documental ({$c['proveedores']} de proveedores, {$c['con_base']} con base).", $actor, ['sin_base' => array_column($plan['unmatched'], 'id'), 'ambiguas' => array_column($plan['ambiguous'], 'id')]);
  $pdo->commit();
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'No se aplicó: ' . $error->getMessage() . "\n");
  exit(1);
}
echo "\nAplicado: {$c['cambios']} políticas actualizadas y {$seeded} versiones iniciales en el historial.\n";
