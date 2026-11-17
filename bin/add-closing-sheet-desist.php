<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Hoja de cierre desistida: columnas razon_desistido, fecha_desistido y desistido_por en el CCT "cierres" (pestaña
// Administracion) y la opción "Desistida" en el glosario 1022 "Estado hoja de cierre" (lo usan `estado` de cierres y
// `estado_hoja_cierre` del mandato). Ver docs/cct-agregar-columnas.md y docs/hoja-cierre-nativa.md.
//
// Uso: php bin/add-closing-sheet-desist.php            (solo muestra lo que haría)
//      php bin/add-closing-sheet-desist.php --aplicar  (lo hace; respalda antes en storage/data/cct-backups/)

use SCM\Modules\ClosingSheets\ClosingSheetWorkflow;
use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);
$db = \SCM\Core\App::db();
$backupDir = dirname(__DIR__) . '/storage/data/cct-backups';
$failed = false;

try {
  echo implode("\n", (new CctFieldInstaller($db, $backupDir))->run('cierres', ['administracion_tab' => [
    'razon_desistido' => ['Razón de desistir', 'textarea'],
    'fecha_desistido' => ['Fecha de desistido', 'fecha'],
    'desistido_por' => ['Desistido por'],
  ]], $apply)['lines']), "\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  $failed = true;
}

// Glosario 1022: agrega "Desistida" al final si no está.
$glossaryId = 1022;
$value = ClosingSheetWorkflow::DESISTED;
$postTypes = $db->table('jet_post_types');
$row = $db->getRow("SELECT `id`, `meta_fields` FROM `{$postTypes}` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [$glossaryId]);
$options = is_array($row) ? @unserialize((string) $row['meta_fields'], ['allowed_classes' => false]) : null;
if (!is_array($options)) {
  fwrite(STDERR, "Glosario {$glossaryId}: no se encontró o no se pudo leer; no se toca.\n");
  $failed = true;
} elseif (in_array($value, array_map(static fn($option): string => (string) ($option['value'] ?? ''), $options), true)) {
  echo "Glosario {$glossaryId}: ya tiene \"{$value}\".\n";
} else {
  echo "Glosario {$glossaryId}: agregar \"{$value}\" (" . count($options) . " opciones hoy).\n";
  if ($apply) {
    if (!is_dir($backupDir)) {
      mkdir($backupDir, 0775, true);
    }
    $backup = $backupDir . '/glossary-' . $glossaryId . '-' . date('Ymd-His') . '.json';
    file_put_contents($backup, json_encode(['id' => $glossaryId, 'meta_fields' => $row['meta_fields']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $options[] = ['value' => $value, 'label' => $value, 'is_checked' => false];
    $db->update($postTypes, ['meta_fields' => serialize(array_values($options))], ['id' => $glossaryId]);
    echo "Glosario {$glossaryId}: listo (respaldo en {$backup}).\n";
  }
}

if (!$apply) {
  echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-closing-sheet-desist.php --aplicar\n";
}
exit($failed ? 1 : 0);
