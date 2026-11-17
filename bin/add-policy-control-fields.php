<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Control documental de las políticas (Gestor organizacional): código, versión, estado, audiencia, política base,
// responsable, fechas de vigencia y revisión, motivo del cambio, categoría y proceso. El CCT "politicas" no tiene
// pestañas, así que los campos quedan sueltos como los demás. Ver docs/cct-agregar-columnas.md y
// docs/gestor-organizacional.md.
//
// Uso: php bin/add-policy-control-fields.php            (solo muestra lo que haría)
//      php bin/add-policy-control-fields.php --aplicar  (lo hace)
//
// Después de aplicarlo, correr la migración de políticas de proveedores: php bin/migrate-policies-provider.php

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);

$fields = [
  'codigo' => ['Código'],
  'version' => ['Versión'],
  // Borrador / Vigente / Derogada (el panel solo guarda esos valores).
  'estado' => ['Estado'],
  // Empleados / Proveedores / Ambos.
  'audiencia' => ['Audiencia'],
  // _ID de la política de empleados de la que sale la versión para proveedores.
  'id_politica_base' => ['ID política base'],
  // id_empleado del funcionario responsable.
  'id_responsable' => ['ID responsable'],
  'vigencia_desde' => ['Vigente desde', 'fecha'],
  'fecha_revision' => ['Fecha de revisión', 'fecha'],
  'fecha_proxima_revision' => ['Fecha de próxima revisión', 'fecha'],
  'motivo_cambio' => ['Motivo del cambio', 'textarea'],
  'categoria' => ['Categoría'],
  'id_proceso' => ['ID proceso'],
];

$installer = new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups');
try {
  echo implode("\n", $installer->run('politicas', [CctFieldInstaller::NO_TAB => $fields], $apply)['lines']), "\n";
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
if (!$apply) {
  echo "\nSolo se mostró el plan. Para hacerlo: php bin/add-policy-control-fields.php --aplicar\n";
}
