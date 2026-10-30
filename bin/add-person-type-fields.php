<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Agrega a los CCT "cierres" y "contratos_arrendamiento" los campos de tipo de persona y representante legal
// (propietario, arrendatario y copropietarios 2 a 6), como si se crearan en JetEngine › Custom Content Types:
// - la definición del campo (texto) en wp_jet_post_types.meta_fields, dentro de una pestaña nueva, y
// - la columna `text NULL` en la tabla wp_jet_cct_<slug>.
// No cambia ni borra campos existentes; lo que ya existe se salta. Antes de escribir guarda un respaldo de la
// definición en storage/data/cct-backups/.
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
$fields = $personFields('propietario', 'propietario') + $personFields('arrendatario', 'arrendatario');
for ($i = 2; $i <= 6; $i++) {
  $fields += $personFields('propietario_' . $i, 'propietario ' . $i);
}
$plan = [
  'cierres' => $fields,
  // El contrato de arrendamiento ya tiene los copropietarios 2 a 6; le falta la cantidad de propietarios.
  'contratos_arrendamiento' => ['propietarios' => 'Cantidad de propietarios'] + $fields,
];
const TAB_NAME = 'tipo_persona_representantes_tab';

$failed = false;
foreach ($plan as $slug => $wanted) {
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
  $defined = array_flip(array_map('strval', array_column($metaFields, 'name')));
  $columns = array_flip(array_column($db->getResults("SHOW COLUMNS FROM `{$table}`"), 'Field'));
  $missingDefinition = array_diff_key($wanted, $defined);
  $missingColumns = array_diff_key($wanted, $columns);

  echo "== {$slug} (definición #{$definition['id']}, " . count($metaFields) . " campos)\n";
  echo '  Definición: ' . ($missingDefinition ? count($missingDefinition) . ' por agregar: ' . implode(', ', array_keys($missingDefinition)) : 'nada que agregar') . "\n";
  echo '  Columnas:   ' . ($missingColumns ? count($missingColumns) . ' por agregar: ' . implode(', ', array_keys($missingColumns)) : 'nada que agregar') . "\n";
  if (!$apply || (!$missingDefinition && !$missingColumns)) {
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
    if ($missingDefinition) {
      $usedIds = array_flip(array_map('intval', array_column($metaFields, 'id')));
      $newId = static function () use (&$usedIds): int {
        do {
          $id = random_int(1000, 9999);
        } while (isset($usedIds[$id]));
        $usedIds[$id] = true;
        return $id;
      };
      $entry = static fn(string $name, string $title, string $objectType, bool $nested) => [
        'title' => $title, 'name' => $name, 'object_type' => $objectType, 'width' => '100%', 'options' => [],
        'repeater-fields' => [], 'type' => 'text', 'id' => $newId(), 'isNested' => $nested, 'options_source' => 'manual',
      ];
      if (!isset($defined[TAB_NAME])) {
        $metaFields[] = $entry(TAB_NAME, 'Tipo de persona y representantes', 'accordion', false);
      }
      foreach ($missingDefinition as $name => $title) {
        $metaFields[] = $entry($name, $title, 'field', true);
      }
      $serialized = serialize($metaFields);
      $db->update($db->table('jet_post_types'), ['meta_fields' => $serialized], ['id' => (int) $definition['id']]);
      $check = @unserialize((string) $db->getVar("SELECT `meta_fields` FROM `{$db->table('jet_post_types')}` WHERE `id` = ?", [(int) $definition['id']]), ['allowed_classes' => false]);
      if (!is_array($check) || count($check) !== count($metaFields)) {
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
