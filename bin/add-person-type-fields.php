<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Agrega a los CCT "cierres" y "contratos_arrendamiento" los campos de tipo de persona y representante legal
// (propietario, arrendatario y copropietarios 2 a 6), como si se crearan en JetEngine › Custom Content Types:
// - la definición del campo (texto) en wp_jet_post_types.meta_fields, dentro de la pestaña de esa persona
//   (Propietario, Propietario N o Arrendatario), y
// - la columna `text NULL` en la tabla wp_jet_cct_<slug>.
// No cambia ni borra campos existentes; lo que ya existe se salta. Si los campos están en otra pestaña (la primera
// versión los dejó todos en "Tipo de persona y representantes"), los mueve a la de su persona y quita esa pestaña
// vacía; mover solo cambia el orden de la definición, no las columnas ni los datos. Antes de escribir guarda un
// respaldo de la definición en storage/data/cct-backups/.
//
// Uso: php bin/add-person-type-fields.php            (solo muestra lo que haría)
//      php bin/add-person-type-fields.php --aplicar  (lo hace)

$apply = in_array('--aplicar', $argv, true);
$db = \SCM\Core\App::db();
$pdo = $db->pdo();

/** @return array<string,string> columna => título */
$personFields = static function (string $suffix, string $who): array {
  return [
    'tipo_persona_' . $suffix => 'Tipo de persona del ' . $who,
    'tipo_documento_' . $suffix => 'Tipo de documento del ' . $who,
    'representante_' . $suffix => 'Representante legal del ' . $who,
    'documento_representante_' . $suffix => 'Documento del representante legal del ' . $who,
  ];
};
/**
 * Campos por pestaña de cada CCT: pestaña => [columna => título].
 * @return array<string,array<string,string>>
 */
$groups = static function (string $ownerTab, array $extraOwner = []) use ($personFields): array {
  $out = [$ownerTab => $extraOwner + $personFields('propietario', 'propietario'), 'arrendatario_tab' => $personFields('arrendatario', 'arrendatario')];
  for ($i = 2; $i <= 6; $i++) {
    $out['propietario_tab_' . $i] = $personFields('propietario_' . $i, 'propietario ' . $i);
  }
  return $out;
};
$plan = [
  'cierres' => $groups('propietario_tab_1'),
  // El contrato de arrendamiento ya tiene los copropietarios 2 a 6; le falta la cantidad de propietarios.
  'contratos_arrendamiento' => $groups('propietario_tab', ['propietarios' => 'Cantidad de propietarios']),
];
const OLD_TAB = 'tipo_persona_representantes_tab';

/**
 * Deja cada campo administrado al final de la pestaña de su persona y quita la pestaña vieja si queda vacía.
 * @param list<array<string,mixed>> $metaFields @param array<string,array<string,string>> $groups @return list<array<string,mixed>>
 */
$organize = static function (array $metaFields, array $groups): array {
  $managed = [];
  foreach ($groups as $tab => $fields) {
    foreach (array_keys($fields) as $name) {
      $managed[$name] = $tab;
    }
  }
  $entries = [];
  $rest = [];
  foreach ($metaFields as $field) {
    $name = (string) ($field['name'] ?? '');
    if (isset($managed[$name])) {
      $entries[$name] = $field;
    } elseif ($name !== OLD_TAB) {
      $rest[] = $field;
    }
  }
  $out = [];
  $currentTab = null;
  $flush = static function (?string $tab) use (&$out, $groups, $entries): void {
    foreach (array_keys($groups[$tab] ?? []) as $name) {
      if (isset($entries[$name])) {
        $entry = $entries[$name];
        $entry['isNested'] = true;
        $out[] = $entry;
      }
    }
  };
  foreach ($rest as $field) {
    if (($field['object_type'] ?? '') === 'accordion') {
      $flush($currentTab);
      $currentTab = (string) $field['name'];
    }
    $out[] = $field;
  }
  $flush($currentTab);
  return $out;
};

$failed = false;
foreach ($plan as $slug => $groupsForSlug) {
  $wanted = array_merge(...array_values($groupsForSlug));
  $table = $db->table('jet_cct_' . $slug);
  $definition = $db->getRow("SELECT `id`, `meta_fields` FROM `{$db->table('jet_post_types')}` WHERE `status` = 'content-type' AND `slug` = ? LIMIT 1", [$slug]);
  if (!$definition) {
    fwrite(STDERR, "{$slug}: no se encontró el CCT en jet_post_types.\n");
    $failed = true;
    continue;
  }
  $metaFields = @unserialize((string) $definition['meta_fields'], ['allowed_classes' => false]);
  if (!is_array($metaFields)) {
    fwrite(STDERR, "{$slug}: la definición de campos no se pudo leer; no se toca.\n");
    $failed = true;
    continue;
  }
  $tabs = array_column(array_filter($metaFields, static fn($field): bool => ($field['object_type'] ?? '') === 'accordion'), 'name');
  $missingTabs = array_diff(array_keys($groupsForSlug), $tabs);
  if ($missingTabs) {
    fwrite(STDERR, "{$slug}: faltan las pestañas " . implode(', ', $missingTabs) . "; no se toca.\n");
    $failed = true;
    continue;
  }
  $defined = array_flip(array_map('strval', array_column($metaFields, 'name')));
  $columns = array_flip(array_column($db->getResults("SHOW COLUMNS FROM `{$table}`"), 'Field'));
  $missingDefinition = array_diff_key($wanted, $defined);
  $missingColumns = array_diff_key($wanted, $columns);

  // Definición final: los que faltan se agregan y todo queda en la pestaña de su persona.
  $usedIds = array_flip(array_map('intval', array_column($metaFields, 'id')));
  $newId = static function () use (&$usedIds): int {
    do {
      $id = random_int(1000, 9999);
    } while (isset($usedIds[$id]));
    $usedIds[$id] = true;
    return $id;
  };
  $final = $metaFields;
  foreach ($missingDefinition as $name => $title) {
    $final[] = [
      'title' => $title, 'name' => $name, 'object_type' => 'field', 'width' => '100%', 'options' => [],
      'repeater-fields' => [], 'type' => 'text', 'id' => $newId(), 'isNested' => true, 'options_source' => 'manual',
    ];
  }
  $final = $organize($final, $groupsForSlug);
  $reorder = array_column($final, 'name') !== array_column($metaFields, 'name');

  echo "== {$slug} (definición #{$definition['id']}, " . count($metaFields) . " campos)\n";
  echo '  Definición: ' . ($missingDefinition ? count($missingDefinition) . ' por agregar: ' . implode(', ', array_keys($missingDefinition)) : 'nada que agregar') . "\n";
  echo '  Columnas:   ' . ($missingColumns ? count($missingColumns) . ' por agregar: ' . implode(', ', array_keys($missingColumns)) : 'nada que agregar') . "\n";
  echo '  Pestañas:   ' . ($reorder ? 'se ordenan en ' . implode(', ', array_keys($groupsForSlug)) . (isset($defined[OLD_TAB]) ? ' y se quita ' . OLD_TAB : '') : 'en orden') . "\n";
  if (!$apply || (!$reorder && !$missingColumns)) {
    continue;
  }

  $backupDir = dirname(__DIR__) . '/storage/data/cct-backups';
  if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "  No se pudo crear {$backupDir}; no se toca {$slug}.\n");
    $failed = true;
    continue;
  }
  $backup = $backupDir . '/' . $slug . '-' . date('Ymd-His') . '.json';
  file_put_contents($backup, json_encode(['id' => $definition['id'], 'slug' => $slug, 'meta_fields' => $definition['meta_fields'], 'columns' => array_keys($columns)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  echo "  Respaldo: {$backup}\n";

  try {
    // Primero las columnas (como hace JetEngine al guardar el CCT): text, admite NULL.
    foreach (array_keys($missingColumns) as $column) {
      $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` text DEFAULT NULL");
    }
    if ($reorder) {
      $db->update($db->table('jet_post_types'), ['meta_fields' => serialize($final)], ['id' => (int) $definition['id']]);
      $check = @unserialize((string) $db->getVar("SELECT `meta_fields` FROM `{$db->table('jet_post_types')}` WHERE `id` = ?", [(int) $definition['id']]), ['allowed_classes' => false]);
      if (!is_array($check) || array_column($check, 'name') !== array_column($final, 'name')) {
        throw new RuntimeException('la definición guardada no coincide; restaura el respaldo ' . $backup);
      }
    }
    echo "  Listo.\n";
  } catch (Throwable $error) {
    fwrite(STDERR, "  ERROR en {$slug}: " . $error->getMessage() . "\n");
    $failed = true;
  }
}
if (!$apply) {
  echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-person-type-fields.php --aplicar\n";
}
exit($failed ? 1 : 0);
