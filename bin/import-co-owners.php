<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Carga en los contratos de arrendamiento los copropietarios del listado de SIMI («COPROPETARIOS ACTIVOS 2026»).
// Manda el archivo: reemplaza propietarios, id_propietario_2..8 y porcentaje_1..8 de los contratos Entregado o Por
// entregar de cada inmueble. Sin --aplicar solo muestra lo que haría. Ver docs/copropietarios-fases-pendientes.md.
//
//   php bin/import-co-owners.php "COPROPETARIOS ACTIVOS 2026.xlsx"
//   php bin/import-co-owners.php "COPROPETARIOS ACTIVOS 2026.xlsx" --aplicar --funcionario=<id_empleado>
//
// Formatos: .xlsx, .csv (separado por ; o ,) y .xls. Para 7 u 8 propietarios corre antes
// php bin/add-co-owner-7-8-fields.php --aplicar.

use SCM\Modules\LeaseContracts\CoOwnersImport;
use SCM\Support\LegacyXlsReader;
use SCM\Support\XlsxReader;

$apply = in_array('--aplicar', $argv, true);
$author = '';
$file = '';
foreach (array_slice($argv, 1) as $arg) {
  if (str_starts_with($arg, '--funcionario=')) $author = trim(substr($arg, strlen('--funcionario=')));
  elseif (!str_starts_with($arg, '--')) $file = $arg;
}
if ($file === '' || !is_file($file)) {
  fwrite(STDERR, "Uso: php bin/import-co-owners.php <archivo.xlsx|.csv|.xls> [--aplicar --funcionario=<id_empleado>]\n");
  exit(1);
}
$db = \SCM\Core\App::db();
if ($apply) {
  // cct_author_id de las fichas nuevas y del historial: el id_empleado real de un funcionario activo.
  $name = $author !== '' ? $db->getVar("SELECT `nombre` FROM `{$db->table('jet_cct_funcionarios')}` WHERE TRIM(`id_empleado`) = ? AND `activo` = 'Si' LIMIT 1", [$author]) : null;
  if (!is_string($name) || $name === '') {
    fwrite(STDERR, "Indica el id_empleado de un funcionario activo: --funcionario=<id_empleado>.\n");
    exit(1);
  }
  echo "Aplicando como {$name} ({$author}).\n";
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($extension === 'xlsx') {
  $rows = XlsxReader::rows($file);
} elseif ($extension === 'csv') {
  $handle = fopen($file, 'rb');
  $first = (string) fgets($handle);
  $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
  rewind($handle);
  $rows = [];
  while (($line = fgetcsv($handle, 0, $delimiter)) !== false) $rows[] = array_map(static fn($cell): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell)), $line);
  fclose($handle);
} elseif ($extension === 'xls') {
  $book = new LegacyXlsReader($file);
  if (!$book->success()) { fwrite(STDERR, 'No se pudo leer el XLS: ' . $book->error() . "\n"); exit(1); }
  $rows = array_map(static fn(array $row): array => array_map(static fn($cell): string => trim((string) $cell), $row), $book->rows(0, 20000));
} else {
  fwrite(STDERR, "Formato no soportado: usa .xlsx, .csv o .xls.\n");
  exit(1);
}

$groups = CoOwnersImport::groups($rows);
echo 'Archivo: ' . basename($file) . ' · ' . count($groups) . ' inmuebles · ' . array_sum(array_map(static fn(array $g): int => count($g['owners']), $groups)) . " copropietarios.\n";
$result = (new CoOwnersImport($db))->run($groups, $apply, $author, basename($file));

$changed = array_values(array_filter($result['contracts'], static fn(array $row): bool => $row['cambios'] !== []));
echo "\n== Contratos que se " . ($apply ? 'actualizaron' : 'actualizan') . ': ' . count($changed) . "\n";
foreach ($changed as $row) {
  echo "  inmueble {$row['inmueble']} · contrato {$row['contrato']} (#{$row['id']}) · {$row['propietarios']} propietarios: "
    . implode(', ', array_map(static fn(string $column, array $change): string => "{$column} " . ($change[0] !== '' ? $change[0] : '∅') . ' → ' . ($change[1] !== '' ? $change[1] : '∅'), array_keys($row['cambios']), $row['cambios'])) . "\n";
  foreach ($row['avisos'] as $note) echo "      aviso: {$note}\n";
}
$same = array_values(array_filter($result['contracts'], static fn(array $row): bool => $row['cambios'] === []));
echo "\n== Contratos ya al día: " . count($same) . "\n";
foreach ($same as $row) foreach ($row['avisos'] as $note) echo "  inmueble {$row['inmueble']} · contrato {$row['contrato']}: aviso: {$note}\n";
echo "\n== Fichas de copropietarios por registrar en Propietarios: " . count($result['profiles']) . "\n";
foreach ($result['profiles'] as $row) echo "  inmueble {$row['inmueble']}: {$row['nombre']} ({$row['documento']})\n";
echo "\n== Inmuebles o contratos omitidos: " . count($result['skipped']) . "\n";
foreach ($result['skipped'] as $row) echo "  inmueble {$row['inmueble']} ({$row['principal']}): {$row['motivo']}\n";

echo $apply ? "\nListo.\n" : "\nModo de prueba: no se escribió nada. Para aplicar: php bin/import-co-owners.php \"" . basename($file) . "\" --aplicar --funcionario=<id_empleado>\n";
