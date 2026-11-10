<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
define('SCM_BASE_URL', 'https://panel.example');
define('SCM_APP_SECRET', 'synthetic-only');

use SCM\App\SuCasaControlServiciosInmobiliarios as App;
use SCM\Modules\Inventory\InventoryPublicView;
use SCM\Modules\Inventory\InventorySchema;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

// Bloque de columnas reales del CCT usadas por el documento.
$columns = ['cct_status','fecha','fecha_entrega','propietario','direccion','contrato','arrendatario','inmueble','tipo_de_inmueble','categoria_inventario','tipo_inventario','cantidad_pisos','cantidad_habitaciones','cantidad_banos','tiene_parqueadero','tipo_parqueadero','numero_parqueadero','tiene_deposito','depositos','registro_fotografico','fecha_linderos','conjunto_lin','torre_lin','inmueble_lin','parqueadero_lin','deposito_lin','observaciones','servicios_publicos','luz','agua','gas','medidor_luz','medidor_agua','medidor_gas','tiene_delegado','delegado','correo_delegado','celular_delegado','correo_arrendatario','celular_arrendatario','coordinador','email_coordinador','celular_coordinador','creador','email_creador','celular_creador','sucursal'];
foreach (InventorySchema::sections() as $key => $section) {
  $columns[] = $key; $columns[] = $section['furniture'];
  if ($section['floor'] !== '') { $columns[] = $section['floor']; $columns[] = $section['details']; }
}
foreach (InventorySchema::agreements() as $column) $columns[] = $column;
$columns = array_values(array_unique($columns));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE wp_jet_cct_inventario (_ID INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', array_map(static fn(string $c): string => "`$c` TEXT", $columns)) . ')');
$db = new \SCM\Core\Database($pdo);

$insert = static function (array $row) use ($db): int {
  $db->insert('wp_jet_cct_inventario', $row);
  return (int) $db->lastInsertId();
};
$furnished = $insert([
  'cct_status' => 'publish', 'fecha' => (string) strtotime('2026-10-02'), 'propietario' => 'Propietaria <script>x</script>', 'contrato' => '900', 'arrendatario' => 'Arrendatario Prueba', 'inmueble' => '77',
  'tipo_de_inmueble' => 'Apartamento', 'categoria_inventario' => 'Amoblado', 'tipo_inventario' => 'Nuevo', 'cantidad_pisos' => '2', 'sucursal' => 'SUCURSAL-OCULTA',
  'inmueble_lin' => serialize([['norte_inmueble' => 'Pasillo', 'sur_inmueble' => 'Zona verde']]),
  'sala' => serialize([['descripcion_sala' => 'Puertas', 'cantidad_sala' => '2', 'texto_cantidad_sala' => '', 'tipo_de_material_sala' => 'Madera', 'estado_sala' => 'Bueno', 'observaciones_sala' => 'Ninguna', 'ninguna_sala' => '']]),
  'patio' => InventorySchema::rowsToStored([['d' => 'Lavadero', 'c' => '1', 'm' => 'Granito', 'e' => 'Regular', 'o' => 'Rayón']], 'patio'),
  'sala_muebles' => InventorySchema::rowsToStored([['d' => 'Sofá', 'c' => '1', 'e' => 'Bueno', 'o' => '']], 'sala_muebles', false),
  'servicios_publicos' => serialize(['Agua', 'Energia']), 'agua' => '111', 'luz' => '222', 'medidor_luz' => 'M-9',
  'acuerdos_precontractuales_pro' => 'Paz y salvo pendiente', 'coordinador' => 'Coordinadora Prueba', 'email_coordinador' => 'c@example.invalid',
  'tiene_delegado' => 'Si', 'delegado' => 'Delegado Prueba',
  'registro_fotografico' => 'https://drive.google.com/drive/folders/abcdefghijkl',
]);
$legacy = $insert(['cct_status' => 'publish', 'tipo_de_inmueble' => 'a:1:{i:0;i:106;}', 'categoria_inventario' => 'No amoblado', 'tipo_inventario' => 'Anterior']);
$voided = $insert(['cct_status' => 'trash']);

$view = new InventoryPublicView($db);
$html = (string) $view->render($furnished)['content'];
foreach (['Inventario #' . $furnished, 'Sala, comedor y hall', 'Puertas', 'Madera', 'Lavadero', 'Muebles y enseres', 'Sofá', 'Paz y salvo pendiente', 'mérito ejecutivo suficiente', 'Delegado Prueba', 'Quien recibe en representación del arrendatario', 'Coordinadora Prueba', 'Inventario realizado por SKC SuCasa Inmobiliaria', 'embeddedfolderview?id=abcdefghijkl', 'data-scm-print', 'Medidor', 'Pisos'] as $needle) {
  $assert(str_contains($html, $needle), 'Falta en el documento: ' . $needle);
}
$assert(!str_contains($html, 'SUCURSAL-OCULTA'), 'El documento no debe mostrar la sucursal.');
$assert(!str_contains($html, '<script>x'), 'El documento debe escapar los datos.');
$assert(!str_contains($html, 'Tipo de material</th><th>Estado</th><th>Observaciones</th></tr></thead><tbody><tr><td>Sofá'), 'Muebles no lleva columna de material.');
$assert(!str_contains($html, 'Habitación principal'), 'Las secciones vacías no se muestran.');

$old = (string) $view->render($legacy)['content'];
$assert(!str_contains($old, 'a:1:{'), 'El tipo serializado antiguo no se muestra.');
$assert($view->render($voided)['status'] === 404, 'Un inventario anulado no se puede consultar.');
$assert($view->render(0)['status'] === 400, 'Un número inválido se rechaza.');

// Enlace firmado.
$signed = App::signedInventoryPublicUrl(55, time() + 3600);
parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);
$assert(str_starts_with($signed, 'https://panel.example/inventario.php?numero=55&expires='), 'Enlace firmado inválido.');
$assert(App::inventoryPublicSignatureValid(55, (int) $query['expires'], (string) $query['sig']), 'La firma válida debe aceptarse.');
$assert(!App::inventoryPublicSignatureValid(56, (int) $query['expires'], (string) $query['sig']), 'La firma no sirve para otro número.');
$assert(!App::inventoryPublicSignatureValid(55, time() - 10, App::inventoryPublicSignature(55, time() - 10)), 'Una firma vencida se rechaza.');

// Perfiles y cobro.
$local = InventorySchema::profile('Local');
$assert($local['labels']['habitacion_dos'] === 'Área u oficina 2' && in_array('bano_emergencia', $local['hidden'], true), 'Perfil comercial incorrecto.');
$assert(InventorySchema::profile('Apartaestudio')['max_rooms'] === 1, 'Perfil apartaestudio incorrecto.');
$assert(InventorySchema::profile('Lote')['labels']['elementos_principales'] === 'Cerramiento y accesos', 'Perfil lote incorrecto.');
$assert(InventorySchema::doubleCharge('Amoblado', 1) && InventorySchema::doubleCharge('No amoblado', 2) && !InventorySchema::doubleCharge('No amoblado', 1), 'Regla de doble cobro incorrecta.');

// Lo que se guarda conserva las claves de JetForm y el formato item-N.
$stored = unserialize(InventorySchema::rowsToStored([['d' => 'Puerta', 'c' => '1', 'm' => 'Madera', 'e' => 'Bueno', 'o' => '']], 'habitacion_dos'));
$assert(isset($stored['item-0']['descripcion_habitacion_dos'], $stored['item-0']['tipo_de_material_habitacion_dos']), 'Claves del repeater incorrectas.');

foreach (['@media print', 'break-inside', '@page', 'scm-inv-signature'] as $css) {
  $assert(str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/css/inventory-public.css'), $css), 'Falta en la impresión: ' . $css);
}
echo "Inventory public checks passed: document, escaping, branch hidden, voided, signature, profiles, double charge, repeater keys, print.\n";
