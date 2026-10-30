<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Agrega campos a un CCT de JetEngine (definición + columna) como si se crearan en JetEngine › Custom Content Types.
// Ver docs/cct-agregar-columnas.md. Sin --aplicar solo muestra lo que haría.
//
//   php bin/cct-add-fields.php cierres                                   (lista las pestañas del CCT)
//   php bin/cct-add-fields.php cierres arrendatario_tab correo_2="Correo 2" fecha_firma:fecha="Fecha de firma"
//   php bin/cct-add-fields.php cierres arrendatario_tab correo_2="Correo 2" --aplicar
//
// Tipos: text (por defecto), textarea, fecha (fecha guardada como timestamp, columna bigint).

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => $arg !== '--aplicar'));
$slug = (string) ($args[0] ?? '');
$tab = (string) ($args[1] ?? '');
if ($slug === '') {
  fwrite(STDERR, "Uso: php bin/cct-add-fields.php <cct> [<pestaña> <columna>[:tipo]=\"Título\" ...] [--aplicar]\n");
  exit(1);
}
$db = \SCM\Core\App::db();

if ($tab === '') {
  $raw = $db->getVar("SELECT `meta_fields` FROM `{$db->table('jet_post_types')}` WHERE `status` = 'content-type' AND `slug` = ? LIMIT 1", [$slug]);
  $fields = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
  if (!is_array($fields)) {
    fwrite(STDERR, "No se encontró el CCT {$slug}.\n");
    exit(1);
  }
  $current = '(sin pestaña)';
  $count = [];
  foreach ($fields as $field) {
    if (($field['object_type'] ?? '') === 'accordion') {
      $current = $field['name'] . ' — ' . $field['title'];
      $count[$current] = 0;
    } else {
      $count[$current] = ($count[$current] ?? 0) + 1;
    }
  }
  echo "Pestañas de {$slug}:\n";
  foreach ($count as $name => $total) {
    echo "  {$name} ({$total} campos)\n";
  }
  exit(0);
}

$fields = [];
foreach (array_slice($args, 2) as $spec) {
  if (preg_match('/^([a-z][a-z0-9_]*)(?::([a-z]+))?=(.+)$/', $spec, $match) !== 1) {
    fwrite(STDERR, "No entiendo \"{$spec}\". Formato: columna=\"Título\" o columna:tipo=\"Título\".\n");
    exit(1);
  }
  $fields[$match[1]] = [trim($match[3]), $match[2] !== '' ? $match[2] : 'text'];
}
if ($fields === []) {
  fwrite(STDERR, "Indica al menos un campo: columna=\"Título\".\n");
  exit(1);
}

try {
  $result = (new CctFieldInstaller($db, dirname(__DIR__) . '/storage/data/cct-backups'))->run($slug, [$tab => $fields], $apply);
  echo implode("\n", $result['lines']), "\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
if (!$apply) {
  echo "\nSolo se mostró el plan. Para hacerlo, repite el comando con --aplicar.\n";
}
