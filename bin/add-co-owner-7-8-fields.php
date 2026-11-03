<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Propietarios 7 y 8 del contrato de arrendamiento (inmuebles con hasta 8 propietarios en SIMI): copia la pestaña del
// propietario 6 como pestañas 7 y 8 con los mismos campos (id, porcentaje, nombre, documento, correo, indicativo,
// celular, actas, tipo de persona y representante) y crea sus columnas. Si id_propietario_7/8 y porcentaje_7/8 ya
// existen (la primera versión los dejó en la pestaña 6), pasan a su pestaña con su id. Ver docs/cct-agregar-columnas.md.
//
// Uso: php bin/add-co-owner-7-8-fields.php            (solo muestra lo que haría)
//      php bin/add-co-owner-7-8-fields.php --aplicar  (lo hace)

use SCM\Support\CctFieldInstaller;

$apply = in_array('--aplicar', $argv, true);
try {
  $result = (new CctFieldInstaller(\SCM\Core\App::db(), dirname(__DIR__) . '/storage/data/cct-backups'))->cloneTab('contratos_arrendamiento', 'id_propietario_6', 6, [7, 8], $apply);
  echo implode("\n", $result['lines']), "\n";
} catch (\Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
echo $apply ? "Listo. Revisa en JetEngine › Custom Content Types que el CCT no muestre el aviso de esquema.\n" : "Modo de prueba: no se escribió nada. Para aplicar: php bin/add-co-owner-7-8-fields.php --aplicar\n";
