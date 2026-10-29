<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Modules\LeaseContracts\ContractCoOwners;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$near = static fn(float $a, float $b): bool => abs($a - $b) < 1e-9;
$mandate = static function (array $extra = []): array {
  $row = ['_ID' => 31, 'propietarios' => '3'];
  foreach ([1 => ['Ana', '111', '500'], 2 => ['Luis', '222', '501'], 3 => ['Marta', '333', '502']] as $i => [$name, $doc, $id]) {
    $row += ['tipo_' . $i => 'Natural', 'nombre_' . $i => $name, 'documento_' . $i => $doc, 'id_propietario_nuevo_' . $i => $id, 'correo_' . $i => strtolower($name) . '@example.invalid', 'celular_' . $i => '300000000' . $i, 'indicativo_' . $i => '+57'];
  }
  return $extra + $row;
};
$contract = ['_ID' => 9, 'propietario' => 'Ana', 'id_propietario' => '500', 'correo_propietario' => 'ana@example.invalid', 'celular_propietario' => '3000000001', 'indicativo_propietario' => '57'];

// Sin columnas de porcentaje (todavía no creadas): partes iguales, marcado como no registrado.
$owners = ContractCoOwners::resolve($contract, $mandate());
$check(array_column($owners, 'key') === ['mandato_1', 'mandato_2', 'mandato_3'] && array_column($owners, 'index') === [1, 2, 3], 'owner keys stay mandato_N as before');
$check($near(array_sum(array_column($owners, 'share')), 1.0) && $near($owners[0]['share'], 1 / 3) && $owners[0]['share_source'] === 'partes_iguales' && $owners[0]['share_detail'] === 'sin porcentaje registrado', 'without percentage columns the shares are equal and flagged');

// Porcentajes del mandato.
$owners = ContractCoOwners::resolve($contract, $mandate(['porcentaje_1' => '50', 'porcentaje_2' => '30', 'porcentaje_3' => '20']));
$check($near($owners[0]['share'], 0.5) && $near($owners[1]['share'], 0.3) && $near($owners[2]['share'], 0.2) && $owners[1]['share_source'] === 'mandato', 'mandate percentages give each owner its share');

// El contrato manda sobre el mandato cuando sus propietarios coinciden.
$withShares = $contract + ['propietarios' => '3', 'id_propietario_2' => '501', 'id_propietario_3' => '502', 'porcentaje_1' => '40', 'porcentaje_2' => '40', 'porcentaje_3' => '20'];
$owners = ContractCoOwners::resolve($withShares, $mandate(['porcentaje_1' => '50', 'porcentaje_2' => '30', 'porcentaje_3' => '20']));
$check($near($owners[0]['share'], 0.4) && $owners[0]['share_source'] === 'contrato', 'the lease contract percentages win over the mandate');

// Si el contrato tiene otros propietarios (ids distintos), se usa el mandato.
$owners = ContractCoOwners::resolve(['id_propietario_2' => '999'] + $withShares, $mandate(['porcentaje_1' => '50', 'porcentaje_2' => '30', 'porcentaje_3' => '20']));
$check($near($owners[0]['share'], 0.5) && $owners[0]['share_source'] === 'mandato', 'contract percentages for different owners are ignored');

// Suma distinta de 100, un 0 o uno faltante: partes iguales con el motivo.
$owners = ContractCoOwners::resolve($contract, $mandate(['porcentaje_1' => '50', 'porcentaje_2' => '30', 'porcentaje_3' => '10']));
$check($owners[0]['share_source'] === 'partes_iguales' && str_contains($owners[0]['share_detail'], 'suman 90 %'), 'percentages that do not add up to 100 fall back to equal shares with the reason');
$owners = ContractCoOwners::resolve($contract, $mandate(['porcentaje_1' => '50', 'porcentaje_2' => '50']));
$check($owners[0]['share_source'] === 'partes_iguales' && str_contains($owners[0]['share_detail'], 'falta el porcentaje del propietario 3'), 'a missing percentage is explained');

// Formatos: coma decimal, "%", 33,33 × 3 y fracciones.
$owners = ContractCoOwners::resolve($contract, $mandate(['porcentaje_1' => '33,34 %', 'porcentaje_2' => '33.33', 'porcentaje_3' => '33,33']));
$check($owners[0]['share_source'] === 'mandato' && $near(array_sum(array_column($owners, 'share')), 1.0), 'comma decimals and the % sign are accepted');
$owners = ContractCoOwners::resolve($contract, $mandate(['porcentaje_1' => '33.33', 'porcentaje_2' => '33.33', 'porcentaje_3' => '33.33']));
$check($owners[0]['share_source'] === 'mandato', '33,33 × 3 = 99,99 counts as 100 %');
$owners = ContractCoOwners::resolve($contract, $mandate(['porcentaje_1' => '0.5', 'porcentaje_2' => '0.25', 'porcentaje_3' => '0.25']));
$check($owners[0]['share_source'] === 'mandato' && $near($owners[0]['share'], 0.5), 'fractions are read as percentages');

// Un solo propietario: siempre 100 %.
$single = ContractCoOwners::resolve($contract, $mandate(['propietarios' => '1']));
$check(count($single) === 1 && $single[0]['share'] === 1.0 && $single[0]['share_source'] === 'unico', 'a single owner always has 100 %');
$noMandate = ContractCoOwners::resolve($contract, null);
$check(count($noMandate) === 1 && $noMandate[0]['key'] === 'contrato' && $noMandate[0]['phone'] === '573000000001' && $noMandate[0]['share'] === 1.0, 'a contract without mandate keeps its single owner');

// Contrato sin mandato con copropietarios registrados en el contrato.
$profiles = ['501' => ['_ID' => '501', 'nombre' => 'Luis', 'documento' => '222', 'correo' => 'luis@example.invalid', 'celular' => '3000000002', 'indicativo' => '57']];
$owners = ContractCoOwners::resolve($contract + ['propietarios' => '2', 'id_propietario_2' => '501', 'porcentaje_1' => '60', 'porcentaje_2' => '40'], null, $profiles);
$check(array_column($owners, 'key') === ['contrato', 'contrato_2'] && $owners[1]['document'] === '222' && $near($owners[1]['share'], 0.4) && $owners[1]['share_source'] === 'contrato', 'without mandate, contract co-owners come from their profile with the contract percentage');

// Mandato viejo con un propietario vacío: se salta como antes.
$owners = ContractCoOwners::resolve($contract, $mandate(['nombre_2' => '']));
$check(array_column($owners, 'key') === ['mandato_1', 'mandato_3'] && $near($owners[0]['share'], 0.5), 'an empty mandate owner is skipped as before');

$check(ContractCoOwners::equalSplit(3) === [33.34, 33.33, 33.33] && ContractCoOwners::equalSplit(1) === [100.0] && ContractCoOwners::equalSplit(4) === [25.0, 25.0, 25.0, 25.0], 'equal split gives the extra cent to the first owner');
$check(ContractCoOwners::sameDocument('900.123.456-7', '900123456') && !ContractCoOwners::sameDocument('', ''), 'documents are compared normalized');
$check(ContractCoOwners::percentLabel(1 / 3) === '33,33 %' && ContractCoOwners::percentLabel(0.5) === '50 %', 'percentage labels');

echo "Contract co-owner checks passed.\n";
