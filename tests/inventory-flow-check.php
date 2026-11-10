<?php
declare(strict_types=1);
// Flujo del inventario contra SQLite en memoria: creación, dos inmuebles por contrato, cobro, edición y eliminación.
// Sin base de datos real ni escrituras externas.
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
require_once dirname(__DIR__) . '/src/Core/Helpers.php';
define('SCM_APP_SECRET', 'synthetic-only');
define('SCM_BASE_URL', 'https://panel.example');

use SCM\Modules\Inventory\InventoryBilling;
use SCM\Modules\Inventory\InventoryInput;
use SCM\Modules\Inventory\InventorySchema;
use SCM\Modules\Inventory\InventorySuggestions;
use SCM\Modules\Inventory\InventoryDraftStore;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

// Tarifa -------------------------------------------------------------------
$config = ['salario' => '1750905', 'dias_trabajo' => '30', 'porcentaje_smlmv_entre_rec' => '1.31', 'valor_transporte' => '4000'];
$assert(InventoryBilling::fee($config) === 76456, 'La tarifa no coincide con la actual.');
$assert(InventoryBilling::fee(['salario' => '2000000'] + $config) === 87333, 'La tarifa debe seguir al salario.');
$assert(InventoryBilling::fee(['porcentaje_smlmv_entre_rec' => '131'] + $config) === 76456, 'El porcentaje escrito como 131 también vale.');
$assert(InventoryBilling::amounts($config, 'Amoblado', 3, true) === ['base' => 76456, 'servicio' => 152912, 'transporte' => 8000, 'total' => 160912, 'doble' => true], 'Amoblado y varios pisos duplica una sola vez.');
$assert(InventoryBilling::amounts($config, 'No amoblado', 1, false)['total'] === 76456, 'Sin transporte.');

// Esquema SQLite con las columnas reales que usa el flujo --------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$inventoryColumns = ['cct_status','cct_author_id','cct_created','cct_modified','fecha','fecha_entrega','arrendador','se_envio','incluir_anexos','id_reporte_administrativo','reporte_realizado','creador','email_creador','celular_creador','id_empleado','coordinador','email_coordinador','celular_coordinador','sucursal','id_ticket','id_inmueble','id_contrato','id_revision_entrega','id_propietario','id_arrendatario','propietario','correo_propietario','celular_propietario','id_estudio_aseguradora','numero_solicitud','tipo_inventario','categoria_inventario','contrato','inmueble','direccion','tipo_de_inmueble','tipo_negocio','destinacion','registro_fotografico','arrendatario','cedula_arrendatario','correo_arrendatario','celular_arrendatario','tiene_parqueadero','tipo_parqueadero','parqueaderos','numero_parqueadero','tiene_deposito','depositos','tiene_delegado','delegado','cedula_delegado','correo_delegado','celular_delegado','fecha_linderos','conjunto_lin','torre_lin','inmueble_lin','parqueadero_lin','deposito_lin','cantidad_pisos','cantidad_habitaciones','cantidad_banos','observaciones','servicios_publicos','luz','agua','gas','medidor_luz','medidor_agua','medidor_gas','acuerdos_precontractuales'];
foreach (InventorySchema::sections() as $key => $section) {
  array_push($inventoryColumns, $key, $section['furniture']);
  if ($section['floor'] !== '') array_push($inventoryColumns, $section['floor'], $section['details']);
}
$inventoryColumns = array_values(array_unique(array_merge($inventoryColumns, array_values(InventorySchema::agreements()))));
$tables = [
  'wp_jet_cct_inventario' => $inventoryColumns,
  'wp_jet_cct_contratos_arrendamiento' => ['id_inventario','direccion','arrendatario','documento_arrendatario','correo_arrendatario','celular_arrendatario','tiene_delegado','delegado','documento_delegado','correo_delegado','celular_delegado','luz','agua','gas','medidor_luz','medidor_agua','medidor_gas','registro_fotografico','servicios_publicos','tiene_parqueadero','tipo_parqueadero','parqueaderos','numero_parqueadero','tiene_deposito','depositos','fecha_linderos','conjunto_lin','torre_lin','inmueble_lin','parqueadero_lin','deposito_lin','acuerdos_precontractuales','contrato','inmueble','id_inmueble','id_propietario'],
  'wp_jet_cct_tickets' => ['id_inventario','estado_inventario','estado_administrativo','fecha_actualizacion','nombre_empleado','correo_empleado','celular_empleado','reportes','tuvo_reporte','id_contrato','id_inmueble','id_revision_entrega','fecha'],
  'wp_jet_cct_reportes_administrativos' => ['cct_status','cct_author_id','cct_created','cct_modified','id_ticket','id_empleado','creador','fecha','fecha_revision','fecha_ticket','fue_pagado','exportado','categoria','descripcion','valor','transporte','valor_mantenimiento','valor_revision','sucursal','contrato','inmueble','arrendatario','id_contrato','id_inmueble','id_rev_entrega','id_estudio_aseguradora','numero_solicitud'],
  'wp_jet_cct_historial_del_ticket' => ['cct_status','cct_author_id','cct_created','cct_modified','id_ticket','fecha','id_empleado','nombre','correo','celular','respuesta','id_inventario'],
  'wp_jet_cct_historial_del_inmueble' => ['cct_status','cct_author_id','cct_created','cct_modified','id_ticket','fecha','id_empleado','id_inmueble','tipo_reporte','observacion','funcionario'],
  'wp_jet_cct_confi_sistema' => ['funcion','valor'],
];
foreach ($tables as $name => $columns) {
  $pdo->exec("CREATE TABLE `$name` (_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => "`$c` TEXT", $columns)) . ')');
}
$db = new \SCM\Core\Database($pdo);
foreach ($config as $name => $value) $db->insert('wp_jet_cct_confi_sistema', ['funcion' => $name, 'valor' => $value]);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['contrato' => '900', 'inmueble' => '77', 'id_inmueble' => '500', 'id_propietario' => '3', 'arrendatario' => 'Arrendatario']);
$db->insert('wp_jet_cct_tickets', ['id_contrato' => '1', 'id_inmueble' => '500', 'id_revision_entrega' => '44', 'fecha' => '1700000000', 'estado_administrativo' => 'Inspeccionado']);

$inspector = new \SCM\Support\SchemaInspector($db);
$columnsCache = [];
foreach ($tables as $name => $columns) $columnsCache[$name] = array_merge(['_ID'], $columns);
(new ReflectionProperty($inspector, 'tableExistsCache'))->setValue($inspector, array_fill_keys(array_keys($tables), true));
(new ReflectionProperty($inspector, 'tableColumnsCache'))->setValue($inspector, $columnsCache);

$app = new class($db, $inspector) {
  use \SCM\App\Concerns\HandlesInventoryActions;
  public array $meta = [];
  public function __construct(public \SCM\Core\Database $db, private \SCM\Support\SchemaInspector $inspector) {}
  private function inventorySchema(): \SCM\Support\SchemaInspector { return $this->inspector; }
  private function correctiveReviewTicket(int $id): array { return $this->db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID=?', [$id]) ?? []; }
  private function correctiveReviewContract(array $t): array { return $this->db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID=1') ?? []; }
  private function correctiveReviewProperty(array $t, array $c): array { return []; }
  private function correctiveReviewValidateContext(array $t, array $c, array $p): void {}
  private function correctiveReviewFirstText(array $v): string { foreach ($v as $x) { if (trim((string) $x) !== '') return trim((string) $x); } return ''; }
  private function correctiveReviewDigits($v): string { return preg_replace('/\D/', '', (string) $v) ?? ''; }
  private function correctiveReviewSplitIds(string $r): array { return array_values(array_filter(array_map('trim', explode(',', $r)))); }
  private function ticketCompletionActor(): array { return ['name' => 'Funcionario', 'email' => 'f@example.invalid', 'phone' => '300', 'employee_id' => '89']; }
  private function preventiveReviewBranch(array $t, array $c): array { return ['nombre_contractual' => 'Coordinadora']; }
  private function deliveryReviewSetPostMeta(int $id, string $key, $value): void { $this->meta[$id][$key] = $value; }
  private function format_cop_currency($v): string { return '$' . number_format((float) $v, 0, ',', '.'); }
  public function create(int $ticket): array { return $this->inventoryCreate($ticket); }
  public function update(int $ticket, int $id): array { return $this->inventoryUpdate($ticket, $id); }
  public function remove(int $ticket, int $id): array { return $this->inventoryDelete($ticket, $id); }
  public function state(array $row): array { return $this->inventoryState($row); }
};

$payload = static fn(array $over = [], array $sections = ['sala' => ['rows' => [['d' => 'Puertas', 'c' => '2', 'm' => 'Madera', 'e' => 'Bueno', 'o' => '']]]], array $furniture = []): string => json_encode([
  'data' => $over + ['tipo_inventario' => 'Nuevo', 'categoria_inventario' => 'No amoblado', 'contrato' => '900', 'inmueble' => '77', 'direccion' => 'Calle 1', 'registro_fotografico' => 'https://drive.google.com/x', 'arrendatario' => 'Arrendatario', 'correo_arrendatario' => 'a@example.invalid', 'celular_arrendatario' => '300', 'tiene_parqueadero' => 'No', 'tiene_deposito' => 'No', 'tiene_delegado' => 'No', 'fecha_linderos' => '2026-10-02', 'cantidad_pisos' => '1', 'cantidad_habitaciones' => '1', 'cantidad_banos' => '1', 'servicios' => ['Agua'], 'agua' => '111', 'acuerdos' => [], 'con_transporte' => 'Si'],
  'boundaries' => ['inmueble_lin' => [['norte' => 'Pasillo']]], 'sections' => $sections, 'furniture' => $furniture,
]);
$report = static fn(int $id) => $db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE _ID=?', [$id]);
$inv = static fn(int $id) => $db->getRow('SELECT * FROM wp_jet_cct_inventario WHERE _ID=?', [$id]);
$ticket = static fn() => $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID=1');
$contract = static fn() => $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID=1');

// Primer inventario: principal, con reporte de tarifa normal.
$_POST = ['payload' => $payload()];
$first = $app->create(1);
$assert(str_contains($first['message'], '$84.456'), 'Mensaje de cobro: ' . $first['message']);
$a = $inv(1);
$assert($a['cct_author_id'] === '89' && $a['id_empleado'] === '89', 'El autor debe ser el id real del funcionario.');
$assert($a['fecha_entrega'] !== null && $a['se_envio'] === 'No' && $a['incluir_anexos'] === 'Si', 'Valores iniciales incorrectos.');
$assert($contract()['id_inventario'] === '1' && $ticket()['id_inventario'] === '1', 'El principal se vincula al contrato y al caso.');
$assert($ticket()['estado_administrativo'] === 'En ejecucion', 'Debe usar el estado vigente, no "En ejecucion por …".');
$assert(unserialize($a['sala'])['item-0']['descripcion_sala'] === 'Puertas', 'Fila guardada con claves de JetForm.');
$assert($app->meta[500]['id_inventario'] === '1', 'Actualiza la ficha del inmueble.');
$r = $report(1);
$assert($r['categoria'] === InventoryBilling::REPORT_CATEGORY && (int) $r['valor'] === 84456 && (int) $r['transporte'] === 8000 && (int) $r['valor_revision'] === 76456 && (int) $r['valor_mantenimiento'] === 76456 && $r['id_rev_entrega'] === '44' && $r['id_empleado'] === '89' && $r['fue_pagado'] === 'No', 'Reporte incorrecto.');
$assert($ticket()['reportes'] === '1' && $ticket()['tuvo_reporte'] === 'Si', 'Contador del caso.');
$assert((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket') === 1 && (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble') === 1, 'Historiales al crear.');

// Segundo inmueble del contrato: no pisa al principal, cobra doble si es amoblado.
$_POST = ['payload' => $payload(['categoria_inventario' => 'Amoblado', 'inmueble' => '88', 'direccion' => 'Calle 2'], ['sala' => ['rows' => [['d' => 'Pisos']]]], ['sala' => [['d' => 'Sofá "grande"', 'c' => '1']]])];
$second = $app->create(1);
$assert($contract()['id_inventario'] === '1' && $ticket()['id_inventario'] === '1' && $contract()['direccion'] === 'Calle 1', 'El adicional no modifica al principal.');
$assert((int) $report(2)['valor'] === 160912 && (int) $report(2)['valor_revision'] === 152912, 'El amoblado cobra doble.');
$assert($inv(2)['id_inmueble'] === '', 'El adicional no toma el inmueble del contrato: ' . var_export($inv(2)['id_inmueble'], true));
$assert(unserialize($inv(2)['sala_muebles'])['item-0']['descripcion_sala_muebles'] === 'Sofá "grande"', 'Muebles del adicional: ' . var_export($inv(2)['sala_muebles'], true));
$assert(!isset(unserialize($inv(2)['sala_muebles'])['item-0']['tipo_de_material_sala_muebles']), 'Muebles sin material.');

// Tercer inventario: el caso ya tiene 2 reportes.
$_POST = ['payload' => $payload(['inmueble' => '99'])];
$third = $app->create(1);
$assert(str_contains($third['message'], 'No se generó reporte') && (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_reportes_administrativos') === 2 && ($inv(3)['id_reporte_administrativo'] ?? '') === '', 'Máximo 2 reportes por caso.');

// Tipo Anterior: sin reporte ni historial.
$historyBefore = (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket');
$_POST = ['payload' => $payload(['tipo_inventario' => 'Anterior', 'inmueble' => '100'])];
$anterior = $app->create(1);
$assert(!str_contains($anterior['message'], 'Reporte') && (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket') === $historyBefore, 'Anterior no cobra ni deja historial.');

// Edición: 2 pisos recalcula con el salario vigente; una versión vieja se rechaza.
$db->update('wp_jet_cct_confi_sistema', ['valor' => '2000000'], ['funcion' => 'salario']);
$_POST = ['payload' => $payload(['cantidad_pisos' => '2']), 'version' => 'vieja'];
try { $app->update(1, 1); $assert(false, 'Debe rechazar versión vieja.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'Otro funcionario'), $e->getMessage()); }
$_POST = ['payload' => $payload(['cantidad_pisos' => '2']), 'version' => (string) $inv(1)['cct_modified']];
$edited = $app->update(1, 1);
$assert((int) $report(1)['valor'] === 87333 * 2 + 8000, 'Recalcula con salario vigente y doble por pisos: ' . $report(1)['valor']);
$assert(str_contains($edited['message'], 'recalculado'), $edited['message']);
$assert($contract()['id_inventario'] === '1', 'Editar no cambia el vínculo.');

// Un reporte exportado no se recalcula ni se borra.
$db->update('wp_jet_cct_reportes_administrativos', ['exportado' => 'Si'], ['_ID' => 2]);
$_POST = ['payload' => $payload(['categoria_inventario' => 'No amoblado', 'inmueble' => '88', 'direccion' => 'Calle 2'], ['sala' => ['rows' => [['d' => 'Pisos']]]]), 'version' => (string) $inv(2)['cct_modified']];
$notes = $app->update(1, 2);
$assert(str_contains($notes['message'], 'ya fue exportado') && (int) $report(2)['valor'] === 160912, 'Reporte exportado intacto.');

// Eliminar el principal: pasa a papelera, promueve otro y borra su reporte pendiente.
$removed = $app->remove(1, 1);
$assert($inv(1)['cct_status'] === 'trash' && $contract()['id_inventario'] === '4' && $ticket()['id_inventario'] === '4' && $report(1) === null, 'Eliminación incorrecta.');
$assert(str_contains($removed['message'], 'eliminó su reporte'), $removed['message']);
$deletedExported = $app->remove(1, 2);
$assert($report(2) !== null && str_contains($deletedExported['message'], 'se conserva'), 'El exportado se conserva.');
try { $app->remove(1, 1); $assert(false, 'Ya eliminado.'); } catch (DomainException) {}

// Validaciones de entrada.
$bad = static function (array $data, string $needle) use ($payload, $assert): void {
  $input = json_decode($payload($data), true);
  try { InventoryInput::parse($input, ['types' => ['Nuevo', 'Anterior'], 'categories' => ['No amoblado', 'Amoblado']], true); $assert(false, 'Debía fallar: ' . $needle); }
  catch (DomainException $e) { $assert(str_contains($e->getMessage(), $needle), $e->getMessage()); }
};
$bad(['tipo_inventario' => 'Otro'], 'tipo de inventario');
$bad(['registro_fotografico' => 'no-url'], 'registro fotográfico');
$bad(['tiene_parqueadero' => 'Si'], 'tipo de parqueadero');
$bad(['tiene_delegado' => 'Si'], 'nombre del delegado');
$bad(['servicios' => ['Gas'], 'gas' => ''], 'gas');
$input = json_decode($payload(['cantidad_habitaciones' => '1']), true);
$input['sections']['habitacion_dos'] = ['rows' => [['d' => 'Puerta']]];
$input['sections']['sala']['rows'][0]['o'] = '<b>x</b>';
$parsed = InventoryInput::parse($input, ['types' => ['Nuevo'], 'categories' => ['No amoblado']], true);
$assert($parsed['columns']['habitacion_dos'] === '', 'Una habitación fuera de la cantidad no se guarda.');
$assert(unserialize($parsed['columns']['sala'])['item-0']['observaciones_sala'] === 'x', 'Limpia etiquetas.');
$assert($parsed['columns']['observaciones'] === 'Ninguna', 'Observaciones vacías se guardan como Ninguna.');

// Inventario antiguo → formulario: filas numéricas y cantidad deducida.
$legacy = $app->state(['habitacion_tres' => serialize([0 => ['descripcion_habitacion_tres' => 'Puerta', 'cantidad_habitacion_tres' => '1', 'tipo_de_material_habitacion_tres' => 'Madera', 'estado_habitacion_tres' => 'Bueno', 'observaciones_habitacion_tres' => 'Ninguna']]), 'cantidad_habitaciones' => '1']);
$assert($legacy['data']['cantidad_habitaciones'] === '3' && $legacy['sections']['habitacion_tres']['rows'][0]['m'] === 'Madera', 'Inventario antiguo mal cargado.');

// Sugerencias aprendidas.
$learned = InventorySuggestions::aggregate([
  ['habitacion_principal' => serialize([['descripcion_habitacion_principal' => 'puerta', 'cantidad_habitacion_principal' => '1', 'tipo_de_material_habitacion_principal' => 'Madera']]), 'sala' => serialize([['descripcion_sala' => 'Pisos', 'tipo_de_material_sala' => 'Cerámica', 'cantidad_sala' => 'Todo el inmueble']])],
  ['habitacion_dos' => serialize([['descripcion_habitacion_dos' => 'Puerta', 'cantidad_habitacion_dos' => '1', 'tipo_de_material_habitacion_dos' => 'Madera']]), 'sala' => serialize([['descripcion_sala' => 'Pisos', 'tipo_de_material_sala' => 'Cerámica', 'cantidad_sala' => 'Todo el inmueble']])],
], InventorySchema::sections());
$assert($learned['habitacion']['items'] === ['Puerta'] && $learned['habitacion']['materials']['Puerta'] === ['Madera'], 'Habitaciones comparten sugerencias.');
$assert($learned['sala']['quantities']['Pisos'] === 'Todo el inmueble', 'Cantidad más usada.');

// Borrador: una fila, conflicto entre dispositivos y sin escritura si no cambió.
$pdo->exec('CREATE TABLE wp_scm_inventory_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_key TEXT, ticket_id INTEGER, inventory_id INTEGER, revision INTEGER DEFAULT 1, payload_hash TEXT, payload BLOB, backup_payload BLOB, backup_at INTEGER DEFAULT 0, updated_at INTEGER)');
$store = new InventoryDraftStore($db);
(new ReflectionProperty($store, 'ready'))->setValue($store, true);
$large = json_encode(['state' => array_fill(0, 2000, ['descripcion' => 'Puerta', 'material' => 'Madera', 'estado' => 'Bueno'])]);
$one = $store->save('u', 1, 0, $large, 0);
$assert($one['status'] === 'saved' && $one['revision'] === 1, 'Primer guardado.');
$assert($store->save('u', 1, 0, $large, 1)['status'] === 'unchanged', 'Sin cambios no escribe.');
$assert($store->save('u', 1, 0, json_encode(['otro' => 1]), 99)['status'] === 'conflict', 'Detecta otro dispositivo.');
$two = $store->save('u', 1, 0, json_encode(['otro' => 2]), 1);
$assert($two['status'] === 'saved' && $two['revision'] === 2, 'Segundo guardado.');
$assert((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_inventory_drafts') === 1, 'Siempre una sola fila.');
$assert(strlen((string) $db->getVar('SELECT backup_payload FROM wp_scm_inventory_drafts')) < strlen($large) / 10, 'El borrador se guarda comprimido.');
$assert($store->get('u', 1, 0)['payload'] === ['otro' => 2], 'Lectura del borrador.');
$assert($store->get('otro', 1, 0) === null, 'Cada funcionario ve solo lo suyo.');
$store->delete('u', 1, 0);
$assert($store->get('u', 1, 0) === null, 'Borrado.');

// Listado de Proceso de entrega.
$list = new class($db) {
  use \SCM\App\Concerns\HandlesInventoryActions;
  public array $out = [];
  public function __construct(public \SCM\Core\Database $db) {}
  private function verifyCsrf(): void {}
  private function canAccessDashboardTab(string $tab): bool { return $tab !== 'revisiones_ocupacion'; }
  private function canUseDashboardAction(string $action): bool { return true; }
  private function jsonOk(array $data): void { $this->out = $data; }
  private function jsonFail(string $message): never { throw new DomainException($message); }
  public static function inventoryPublicUrl(int $id): string { return 'inventario.php?numero=' . $id; }
  public static function deliveryReviewPublicUrl(int $id): string { return 'revision-entrega.php?numero=' . $id; }
};
$_POST = ['kind' => 'inventarios', 'tipo' => 'Nuevo', 'q' => 'Calle', 'page' => '1'];
$list->ajax_handler_delivery_process_list();
$assert($list->out['total'] === 1 && str_contains($list->out['html'], 'inventario.php?numero=') && str_contains($list->out['html'], 'data-delivery-process-edit'), 'Listado de inventarios: ' . json_encode($list->out['total']));
$_POST = ['kind' => 'revisiones'];
try { $list->ajax_handler_delivery_process_list(); $assert(false, 'Debe validar el permiso de la pestaña.'); } catch (DomainException) {}
$assert(str_contains(\SCM\Modules\Inventory\DeliveryProcessView::shell('inventarios', 'x'), 'data-delivery-process="inventarios"'), 'Contenedor del listado.');

// Anexos: el inventario adicional va con enlace firmado, después del principal.
$annexes = \SCM\Modules\LeaseContracts\LeaseAnnexesDocument::annexes(['id_inventario' => '4', '_inventarios_adicionales' => [7]], 'propietario', 0);
$labels = array_column($annexes, 'label');
$position = array_search('Inventario del inmueble 2', $labels, true);
$assert($position !== false && $labels[$position - 1] === 'Inventario del inmueble', 'Orden de los anexos: ' . implode(', ', $labels));
$assert(str_contains($annexes[$position]['url'], 'inventario.php?numero=7&expires=') && str_contains($annexes[$position]['url'], '&sig='), 'El adicional va firmado.');

echo "Inventory flow checks passed: fee, principal, second property, report cap, Anterior, edit, exported report, delete, validation, legacy, suggestions, drafts.\n";
