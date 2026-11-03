<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Fase 3 de copropietarios: completa los datos existentes (ver docs/copropietarios-fases-pendientes.md).
// Sin --aplicar solo muestra lo que haría.
//
//   php bin/sync-co-owners.php
//   php bin/sync-co-owners.php --aplicar --funcionario=<id_empleado>
//
// 1. Mandatos con más de un propietario: registra en Propietarios a los 2..N sin ficha y llena id_propietario_nuevo_N.
// 2. Contratos de arrendamiento: llena propietarios, id_propietario_2..6 y porcentaje_1..6 desde su mandato (solo
//    columnas vacías; un valor distinto se lista como conflicto y no se toca).
// 3. Lista los contratos con copropietarios sin % válido, para cargarlo en el mandato o en la hoja de cierre.

use SCM\Modules\LeaseContracts\CoOwnersBackfill;

$apply = in_array('--aplicar', $argv, true);
$author = '';
foreach ($argv as $arg) {
  if (str_starts_with($arg, '--funcionario=')) {
    $author = trim(substr($arg, strlen('--funcionario=')));
  }
}
$db = \SCM\Core\App::db();
if ($apply) {
  // cct_author_id de las fichas nuevas: el id_empleado real de un funcionario activo.
  $name = $author !== '' ? $db->getVar("SELECT `nombre` FROM `{$db->table('jet_cct_funcionarios')}` WHERE TRIM(`id_empleado`) = ? AND `activo` = 'Si' LIMIT 1", [$author]) : null;
  if (!is_string($name) || $name === '') {
    fwrite(STDERR, "Indica el id_empleado de un funcionario activo: --funcionario=<id_empleado>.\n");
    exit(1);
  }
  echo "Aplicando como {$name} ({$author}).\n";
}

$result = (new CoOwnersBackfill($db))->run($apply, $author);
$print = static function (string $title, array $rows, callable $line): void {
  echo "\n== {$title}: " . count($rows) . "\n";
  foreach ($rows as $row) {
    echo '  ' . $line($row) . "\n";
  }
};
$columns = static fn(array $data): string => implode(', ', array_map(static fn(string $k, string $v): string => "{$k}={$v}", array_keys($data), $data));

$print('Fichas de copropietarios (1)', $result['profiles'], static fn(array $r): string => "mandato #{$r['mandato']} propietario {$r['indice']}: {$r['nombre']} ({$r['documento']}) → "
  . ($r['accion'] === 'vincular' ? "ficha existente #{$r['id']}" : ($r['id'] !== '' ? "registrado #{$r['id']}" : 'se registra en Propietarios')));
$print('Mandatos que se actualizan (1)', $result['mandates'], static fn(array $r): string => "mandato #{$r['mandato']}: " . $columns($r['columnas']));
$print('Contratos que se completan (2)', $result['contracts'], static fn(array $r): string => "contrato {$r['contrato']} (#{$r['id']}, mandato #{$r['mandato']}): " . $columns($r['columnas']));
$print('Conflictos sin tocar (2)', $result['conflicts'], static fn(array $r): string => "contrato {$r['contrato']} (#{$r['id']}, mandato #{$r['mandato']}): {$r['columna']} contrato={$r['contrato_valor']} mandato={$r['mandato_valor']}");
$print('Contratos con copropietarios sin % (3)', $result['missing_shares'], static fn(array $r): string => "contrato {$r['contrato']} (#{$r['id']}, {$r['estado']}, mandato #{$r['mandato']}): {$r['propietarios']} — {$r['motivo']}");

echo $apply ? "\nListo.\n" : "\nModo de prueba: no se escribió nada. Para aplicar: php bin/sync-co-owners.php --aplicar --funcionario=<id_empleado>\n";
