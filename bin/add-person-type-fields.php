<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Campos de tipo de persona y representante legal (propietario, arrendatario y copropietarios 2 a 6) en los CCT
// "cierres" y "contratos_arrendamiento", cada grupo en la pestaña de su persona. Ver docs/cct-agregar-columnas.md.
// La primera versión los dejó en la pestaña "tipo_persona_representantes_tab"; si existe, se mueven y se quita.
//
// Uso: php bin/add-person-type-fields.php            (solo muestra lo que haría)
//      php bin/add-person-type-fields.php --aplicar  (lo hace)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);

/** @return array<string,array{0:string}> */
$personFields = static fn(string $suffix, string $who): array => [
  'tipo_persona_' . $suffix => ['Tipo de persona del ' . $who],
  'tipo_documento_' . $suffix => ['Tipo de documento del ' . $who],
  'representante_' . $suffix => ['Representante legal del ' . $who],
  'documento_representante_' . $suffix => ['Documento del representante legal del ' . $who],
];
$groups = static function (string $ownerTab, array $extraOwner = []) use ($personFields): array {
  $out = [$ownerTab => $extraOwner + $personFields('propietario', 'propietario'), 'arrendatario_tab' => $personFields('arrendatario', 'arrendatario')];
  for ($i = 2; $i <= 6; $i++) {
    $out['propietario_tab_' . $i] = $personFields('propietario_' . $i, 'propietario ' . $i);
  }
  return $out;
};
$plan = [
  'cierres' => $groups('propietario_tab_1'),
  // El contrato de arrendamiento ya tiene los copropietarios 2 a 6; le faltaba la cantidad de propietarios.
  'contratos_arrendamiento' => $groups('propietario_tab', ['propietarios' => ['Cantidad de propietarios']]),
];

$installer = new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups');
$failed = false;
foreach ($plan as $slug => $slugGroups) {
  try {
    echo implode("\n", $installer->run($slug, $slugGroups, $apply, ['tipo_persona_representantes_tab'])['lines']), "\n";
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $failed = true;
  }
}
if (!$apply) {
  echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-person-type-fields.php --aplicar\n";
}
exit($failed ? 1 : 0);
