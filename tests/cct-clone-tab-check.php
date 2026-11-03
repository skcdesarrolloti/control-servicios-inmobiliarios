<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Support\CctFieldInstaller;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

// Definición como la de contratos_arrendamiento: la pestaña del propietario 6 quedó con id_propietario_7/8 y
// porcentaje_7/8 al final (los creó la primera versión de bin/add-co-owner-7-8-fields.php).
$id = 100;
$field = static function (string $name, string $title, string $type = 'text', array $extra = []) use (&$id): array {
  return ['title' => $title, 'name' => $name, 'object_type' => 'field', 'type' => $type, 'id' => $id++, 'isNested' => true] + $extra;
};
$tab = static function (string $name, string $title) use (&$id): array {
  return ['title' => $title, 'name' => $name, 'object_type' => 'accordion', 'type' => 'text', 'id' => $id++, 'is_required' => false];
};
$definition = [
  $tab('propietario_tab_5', 'Propietario 5'), $field('id_propietario_5', 'ID propietario 5'),
  $tab('propietario_tab_6', 'Propietario 6'),
  $field('id_propietario_6', 'ID propietario 6'), $field('porcentaje_6', 'Porcentaje 6'), $field('propietario_6', 'Propietario 6'),
  $field('documento_propietario_6', 'Documento del propietario 6'), $field('correo_propietario_6', 'Correo del propietario 6'),
  $field('indicativo_propietario_6', 'Indicativo del propietario 6'), $field('celular_propietario_6', 'Celular del propietario 6'),
  $field('acta_propietario_6', 'Acta de propietario 6', 'media', ['value_format' => 'url']),
  $field('acta_revision_preventiva_propietario_6', 'Acta de revision preventiva propietario 6', 'media', ['value_format' => 'url']),
  $field('tipo_persona_propietario_6', 'Tipo de persona del propietario 6'), $field('tipo_documento_propietario_6', 'Tipo de documento del propietario 6'),
  $field('representante_propietario_6', 'Representante legal del propietario 6'), $field('documento_representante_propietario_6', 'Documento del representante legal del propietario 6'),
  $field('id_propietario_7', 'Id del propietario 7'), $field('id_propietario_8', 'Id del propietario 8'),
  $field('porcentaje_7', 'Porcentaje de participación del propietario 7'), $field('porcentaje_8', 'Porcentaje de participación del propietario 8'),
  $tab('arrendatario_tab', 'Arrendatario'), $field('arrendatario', 'Arrendatario'),
];
$ids = array_column($definition, 'id', 'name');

$result = CctFieldInstaller::cloneTabFields($definition, 'id_propietario_6', 6, [7, 8]);
$names = array_column($result['fields'], 'name');
$tabOf = [];
$current = '';
foreach ($result['fields'] as $row) {
  if ($row['object_type'] === 'accordion') $current = $row['name'];
  else $tabOf[$row['name']] = $current;
}
$sixth = array_keys(array_filter($tabOf, static fn(string $t): bool => $t === 'propietario_tab_6'));
$check(count($sixth) === 13 && !in_array('id_propietario_7', $sixth, true) && !in_array('porcentaje_8', $sixth, true), 'the owner 6 tab keeps only its own 13 fields');
$check(array_search('propietario_tab_7', $names, true) === array_search('documento_representante_propietario_6', $names, true) + 1 && array_search('propietario_tab_8', $names, true) < array_search('arrendatario_tab', $names, true), 'tabs 7 and 8 go right after tab 6, before the next tab');
$seventh = array_keys(array_filter($tabOf, static fn(string $t): bool => $t === 'propietario_tab_7'));
$check($seventh === ['id_propietario_7', 'porcentaje_7', 'propietario_7', 'documento_propietario_7', 'correo_propietario_7', 'indicativo_propietario_7', 'celular_propietario_7', 'acta_propietario_7', 'acta_revision_preventiva_propietario_7', 'tipo_persona_propietario_7', 'tipo_documento_propietario_7', 'representante_propietario_7', 'documento_representante_propietario_7'], 'tab 7 has the same fields as tab 6, in the same order');
$byName = array_column($result['fields'], null, 'name');
$check($byName['id_propietario_7']['id'] === $ids['id_propietario_7'] && $byName['porcentaje_8']['id'] === $ids['porcentaje_8'] && $result['moved'] === ['id_propietario_7', 'porcentaje_7', 'id_propietario_8', 'porcentaje_8'], 'the fields created before keep their id and move to their tab');
$check($byName['acta_propietario_8']['type'] === 'media' && $byName['acta_propietario_8']['value_format'] === 'url' && $byName['acta_propietario_8']['title'] === 'Acta de propietario 8' && $byName['propietario_tab_7']['title'] === 'Propietario 7', 'media fields keep their options and titles get the new number');
$allIds = array_column($result['fields'], 'id');
$check(count($allIds) === count(array_unique($allIds)) && count($result['added']) === 22 && ($result['map']['celular_propietario_8'] ?? '') === 'celular_propietario_6', 'new fields get unique ids and map to their source column');

// Repetirlo sobre el resultado no cambia nada.
$again = CctFieldInstaller::cloneTabFields($result['fields'], 'id_propietario_6', 6, [7, 8]);
$check(array_column($again['fields'], 'name') === $names && $again['added'] === [] && $again['moved'] === [], 'running it again changes nothing');

echo "CCT clone tab checks passed.\n";
