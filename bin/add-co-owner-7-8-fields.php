<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Copropietarios 7 y 8 en el contrato de arrendamiento (inmuebles con hasta 8 propietarios en SIMI): id_propietario_7,
// id_propietario_8, porcentaje_7 y porcentaje_8, cada uno en la misma pestaña de su par 6 (id_propietario_6 y
// porcentaje_6). Ver docs/cct-agregar-columnas.md.
//
// Uso: php bin/add-co-owner-7-8-fields.php            (solo muestra lo que haría)
//      php bin/add-co-owner-7-8-fields.php --aplicar  (lo hace)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);
$db = \SCM\Core\App::db();
$slug = 'contratos_arrendamiento';
$raw = $db->getVar("SELECT `meta_fields` FROM `{$db->table('jet_post_types')}` WHERE `status` = 'content-type' AND `slug` = ? LIMIT 1", [$slug]);
$fields = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
if (!is_array($fields)) {
  fwrite(STDERR, "No se encontró el CCT {$slug}.\n");
  exit(1);
}
$tabOf = [];
$tab = '';
foreach ($fields as $field) {
  if (($field['object_type'] ?? '') === 'accordion') $tab = (string) $field['name'];
  else $tabOf[(string) ($field['name'] ?? '')] = $tab;
}
$groups = [];
foreach (['id_propietario_' => 'Id del propietario ', 'porcentaje_' => 'Porcentaje de participación del propietario '] as $prefix => $title) {
  $tab = $tabOf[$prefix . '6'] ?? '';
  if ($tab === '') {
    fwrite(STDERR, "{$slug}: {$prefix}6 no está en ninguna pestaña; crea primero los copropietarios 2 a 6.\n");
    exit(1);
  }
  foreach ([7, 8] as $i) $groups[$tab][$prefix . $i] = [$title . $i];
}

try {
  $result = (new CctFieldInstaller($db, dirname(__DIR__) . '/storage/data/cct-backups'))->run($slug, $groups, $apply);
  echo implode("\n", $result['lines']), "\n";
} catch (\Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
echo $apply ? "Listo.\n" : "Modo de prueba: no se escribió nada. Para aplicar: php bin/add-co-owner-7-8-fields.php --aplicar\n";
