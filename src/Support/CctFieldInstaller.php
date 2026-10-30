<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;

/**
 * Agrega campos a un Custom Content Type de JetEngine igual que si se crearan en JetEngine › Custom Content Types,
 * sin pasar por la pantalla de JetEngine (docs/cct-agregar-columnas.md):
 * - la definición del campo en wp_jet_post_types.meta_fields, dentro de la pestaña (accordion) indicada, y
 * - la columna en wp_jet_cct_<slug> con el tipo que JetEngine usa para ese campo.
 *
 * Nunca cambia ni borra campos o columnas existentes: lo que ya está se salta, y si un campo administrado está en otra
 * pestaña se mueve a la indicada (mover solo cambia el orden de la definición). Antes de escribir guarda un respaldo
 * de la definición en storage/data/cct-backups/.
 */
final class CctFieldInstaller
{
  /** Tipo de campo => [tipo JetEngine, columna SQL, extras de la definición]. */
  public const TYPES = [
    'text' => ['text', 'text DEFAULT NULL', []],
    'textarea' => ['textarea', 'text DEFAULT NULL', []],
    'fecha' => ['date', 'bigint(20) DEFAULT NULL', ['is_timestamp' => true]],
  ];

  public function __construct(private Database $db, private string $backupDir)
  {
  }

  /**
   * Revisa (y con $apply escribe) un CCT.
   * @param array<string,array<string,array{0:string,1?:string}>> $groups pestaña => [columna => [título, tipo]]
   * @param list<string> $removeTabs pestañas propias que deben quitarse si quedan vacías
   * @return array{lines:list<string>,changed:bool}
   */
  public function run(string $slug, array $groups, bool $apply, array $removeTabs = []): array
  {
    $postTypes = $this->db->table('jet_post_types');
    $table = $this->db->table('jet_cct_' . $slug);
    $definition = $this->db->getRow("SELECT `id`, `meta_fields` FROM `{$postTypes}` WHERE `status` = 'content-type' AND `slug` = ? LIMIT 1", [$slug]);
    if (!$definition) {
      throw new \RuntimeException("{$slug}: no se encontró el CCT en jet_post_types.");
    }
    $metaFields = @unserialize((string) $definition['meta_fields'], ['allowed_classes' => false]);
    if (!is_array($metaFields)) {
      throw new \RuntimeException("{$slug}: la definición de campos no se pudo leer; no se toca.");
    }
    $tabs = array_column(array_filter($metaFields, static fn($field): bool => ($field['object_type'] ?? '') === 'accordion'), 'name');
    $missingTabs = array_diff(array_keys($groups), $tabs);
    if ($missingTabs !== []) {
      throw new \RuntimeException("{$slug}: no existen las pestañas " . implode(', ', $missingTabs) . '. Pestañas: ' . implode(', ', $tabs));
    }
    $wanted = [];
    foreach ($groups as $fields) {
      foreach ($fields as $name => $spec) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
          throw new \RuntimeException("{$slug}: \"{$name}\" no es un nombre de columna válido (minúsculas, números y _).");
        }
        $type = $spec[1] ?? 'text';
        if (!isset(self::TYPES[$type])) {
          throw new \RuntimeException("{$slug}: tipo \"{$type}\" no soportado (" . implode(', ', array_keys(self::TYPES)) . ').');
        }
        $wanted[$name] = [$spec[0], $type];
      }
    }
    $defined = array_flip(array_map('strval', array_column($metaFields, 'name')));
    $columns = array_flip(array_column($this->db->getResults("SHOW COLUMNS FROM `{$table}`"), 'Field'));
    $missingDefinition = array_diff_key($wanted, $defined);
    $missingColumns = array_diff_key($wanted, $columns);

    $usedIds = array_flip(array_map('intval', array_column($metaFields, 'id')));
    $final = $metaFields;
    foreach ($missingDefinition as $name => [$title, $type]) {
      do {
        $id = random_int(1000, 9999);
      } while (isset($usedIds[$id]));
      $usedIds[$id] = true;
      $final[] = [
        'title' => $title, 'name' => $name, 'object_type' => 'field', 'width' => '100%', 'options' => [],
        'repeater-fields' => [], 'type' => self::TYPES[$type][0], 'id' => $id, 'isNested' => true, 'options_source' => 'manual',
      ] + self::TYPES[$type][2];
    }
    $final = self::organize($final, array_map('array_keys', $groups), $removeTabs);
    $reorder = array_column($final, 'name') !== array_column($metaFields, 'name');

    $lines = [
      "== {$slug} (definición #{$definition['id']}, " . count($metaFields) . ' campos)',
      '  Definición: ' . ($missingDefinition ? count($missingDefinition) . ' por agregar: ' . implode(', ', array_keys($missingDefinition)) : 'nada que agregar'),
      '  Columnas:   ' . ($missingColumns ? count($missingColumns) . ' por agregar: ' . implode(', ', array_keys($missingColumns)) : 'nada que agregar'),
      '  Pestañas:   ' . ($reorder ? 'se ordenan en ' . implode(', ', array_keys($groups)) : 'en orden'),
    ];
    if (!$apply || (!$reorder && $missingColumns === [])) {
      return ['lines' => $lines, 'changed' => false];
    }

    if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
      throw new \RuntimeException("No se pudo crear {$this->backupDir}; no se toca {$slug}.");
    }
    $backup = $this->backupDir . '/' . $slug . '-' . date('Ymd-His') . '.json';
    file_put_contents($backup, json_encode(['id' => $definition['id'], 'slug' => $slug, 'meta_fields' => $definition['meta_fields'], 'columns' => array_keys($columns)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $lines[] = "  Respaldo: {$backup}";
    // Primero las columnas (como hace JetEngine al guardar el CCT) y luego la definición.
    foreach ($missingColumns as $name => [, $type]) {
      $this->db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` " . self::TYPES[$type][1]);
    }
    if ($reorder) {
      $this->db->update($postTypes, ['meta_fields' => serialize($final)], ['id' => (int) $definition['id']]);
      $check = @unserialize((string) $this->db->getVar("SELECT `meta_fields` FROM `{$postTypes}` WHERE `id` = ?", [(int) $definition['id']]), ['allowed_classes' => false]);
      if (!is_array($check) || array_column($check, 'name') !== array_column($final, 'name')) {
        throw new \RuntimeException("{$slug}: la definición guardada no coincide; restaura el respaldo {$backup}");
      }
    }
    $lines[] = '  Listo.';
    return ['lines' => $lines, 'changed' => true];
  }

  /**
   * Deja cada campo administrado en su pestaña: el que ya está ahí no se mueve; el que está en otra pestaña (o es
   * nuevo) va al final de la suya, en el orden pedido. Quita las pestañas de $removeTabs.
   * @param list<array<string,mixed>> $metaFields @param array<string,list<string>> $groups pestaña => columnas
   * @param list<string> $removeTabs @return list<array<string,mixed>>
   */
  public static function organize(array $metaFields, array $groups, array $removeTabs = []): array
  {
    $target = [];
    foreach ($groups as $tab => $names) {
      foreach ($names as $name) {
        $target[$name] = $tab;
      }
    }
    $entries = [];
    $rest = [];
    $tab = null;
    foreach ($metaFields as $field) {
      $name = (string) ($field['name'] ?? '');
      if (($field['object_type'] ?? '') === 'accordion') {
        $tab = $name;
      }
      if (isset($target[$name]) && $target[$name] !== $tab) {
        $entries[$name] = $field;
      } elseif (!in_array($name, $removeTabs, true)) {
        $rest[] = $field;
      }
    }
    $out = [];
    $flush = static function (?string $tab) use (&$out, $groups, $entries): void {
      foreach ($groups[$tab] ?? [] as $name) {
        if (isset($entries[$name])) {
          $entry = $entries[$name];
          $entry['isNested'] = true;
          $out[] = $entry;
        }
      }
    };
    $currentTab = null;
    foreach ($rest as $field) {
      if (($field['object_type'] ?? '') === 'accordion') {
        $flush($currentTab);
        $currentTab = (string) $field['name'];
      }
      $out[] = $field;
    }
    $flush($currentTab);
    return $out;
  }
}
