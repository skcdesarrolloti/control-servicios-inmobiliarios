<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL', 'https://example.test/panel');
define('SCM_APP_SECRET', 'test-secret');

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetForm;
use SCM\Modules\ClosingSheets\ClosingSheetService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$fails = static function (callable $fn, string $needle) {
  try {
    $fn();
  } catch (\DomainException $error) {
    return str_contains($error->getMessage(), $needle) ? $error->getMessage() : 'otro mensaje: ' . $error->getMessage();
  }
  return 'no falló';
};

final class ClosingSheetTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare(str_replace([' FOR UPDATE', 'AS UNSIGNED'], ['', 'AS INTEGER'], $query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$pdo = new ClosingSheetTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $columns = array_values(array_unique(array_diff($columns, ['_ID'])));
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', $columns)) . ')');
};
$cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];

$closingColumns = array_column((new ClosingSheetService($db))->fields(), 'name');
foreach (ClosingSheetForm::CHECKLIST as $notes => [, , $items]) {
  $closingColumns[] = $notes;
  foreach ($items as [$column]) $closingColumns[] = $column;
}
$closingColumns = array_merge($closingColumns, $cct, ClosingSheetService::CONTEXT_IDS, ['id_empleado', 'creador', 'sucursal', 'fecha', 'estado', 'numero_solicitud', 'aseguradora', 'fecha_posible_entrega', 'ubicacion_llaves', 'persona_llaves', 'contacto_llaves', 'id_contrato_arrendamiento', 'medio', 'tarjeta_pph', 'nombre_pph', 'propietarios']);
for ($i = 1; $i <= 6; $i++) {
  $closingColumns[] = 'porcentaje_' . $i;
  foreach (ClosingSheetService::CO_OWNER_COLUMNS as $prefix) $closingColumns[] = $prefix . $i;
}
$table('wp_jet_cct_cierres', $closingColumns);

$mandateColumns = array_merge($cct, array_values(ClosingSheetService::MANDATE_SYNC), ['propietarios', 'id_propietario', 'id_inmueble', 'id_copropiedad', 'precio', 'administracion', 'incluye_iva', 'iva_total_precio', 'tasa_comision', 'incremento_pactado', 'duracion', 'id_hoja_cierre', 'estado_hoja_cierre', 'direccion']);
for ($i = 1; $i <= 6; $i++) array_push($mandateColumns, 'tipo_documento_' . $i, 'tipo_' . $i, 'nombre_' . $i, 'documento_' . $i, 'correo_' . $i, 'celular_' . $i, 'indicativo_' . $i, 'empresa_' . $i, 'nit_' . $i, 'id_propietario_nuevo_' . $i, 'porcentaje_' . $i);
$table('wp_jet_cct_contrato_mandato', $mandateColumns);
$table('wp_jet_cct_inmuebles', array_merge($cct, ['tipo_inmueble', 'tipo_negocio', 'destinacion', 'direccion_fisica', 'servicios_publicos', 'luz', 'agua', 'gas', 'pertenece_copropiedad', 'zonas_sociales', 'parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'deposito', 'depositos', 'id_arrendatario', 'arrendatario', 'id_hoja_cierre', 'estado', 'id_contrato_mandato', 'horario_mudanza_inicio_1']));
$table('wp_jet_cct_copropiedades', array_merge($cct, ['copropiedad', 'nit', 'administrador', 'correo', 'contacto', 'tipo_cuenta', 'numero_cuenta', 'banco']));
$table('wp_jet_cct_propietarios', array_merge($cct, ['nombre', 'documento', 'correo', 'celular']));
$table('wp_jet_cct_arrendatarios', array_merge($cct, ['nombre', 'documento', 'correo', 'celular', 'tipo_persona', 'tipo_documento', 'nombre_juridico', 'documento_juridico']));
$table('wp_jet_cct_estudios_aseguradoras', array_merge($cct, ['id_ticket', 'num_solicitud', 'aseguradora', 'fecha_posible_entrega', 'ubicacion_llaves', 'persona_llaves', 'contacto_llaves', 'id_contrato_mandato_arrendamiento']));
$table('wp_jet_cct_tickets', array_merge($cct, ['estado', 'estado_comercial', 'estado_administrativo', 'medio']));
$table('wp_jet_cct_historial_del_inmueble', array_merge($cct, ['id_inmueble', 'id_inmueble_data', 'fecha', 'funcionario', 'id_empleado', 'tipo_reporte', 'observacion']));
$table('wp_jet_cct_historial_del_ticket', array_merge($cct, ['id_ticket', 'id_empleado', 'nombre', 'correo', 'fecha', 'id_hoja_cierre', 'fue_editada', 'respuesta']));
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT)');
$pdo->exec('CREATE TABLE wp_posts(ID INTEGER PRIMARY KEY, post_type TEXT)');
$pdo->exec('CREATE TABLE wp_postmeta(meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
$pdo->exec('CREATE TABLE wp_jet_post_types(id INTEGER PRIMARY KEY, status TEXT, meta_fields TEXT)');
$glossary = static fn(array $values): string => serialize(array_map(static fn(string $v): array => ['value' => $v, 'label' => trim($v)], $values));
$db->insert('wp_jet_post_types', ['id' => 175, 'status' => 'glossary', 'meta_fields' => $glossary(['Si', 'No'])]);
$db->insert('wp_jet_post_types', ['id' => 1154, 'status' => 'glossary', 'meta_fields' => $glossary(['Cumple ', 'No cumple', 'No aplica'])]);
$db->insert('wp_jet_post_types', ['id' => 1003, 'status' => 'glossary', 'meta_fields' => $glossary(['Agua', 'Gas', 'Energia'])]);

// Mandato con dos propietarios: el 2 todavía no tiene ficha en Propietarios.
$db->insert('wp_jet_cct_contrato_mandato', [
  '_ID' => 636, 'propietarios' => '2', 'id_propietario' => '1970', 'id_inmueble' => '151187', 'id_copropiedad' => '273',
  'nombre_1' => 'Yesenis Morales', 'documento_1' => '3243894030', 'correo_1' => 'yesenis@example.invalid', 'celular_1' => '3243894030', 'id_propietario_nuevo_1' => '1970', 'tipo_1' => 'Natural', 'tipo_documento_1' => 'Cédula de Ciudadania', 'empresa_1' => 'no se borra',
  'nombre_2' => 'Inversiones X SAS', 'documento_2' => '900.123.456-7', 'correo_2' => 'x@example.invalid', 'celular_2' => '3000000002', 'tipo_2' => 'Juridico', 'empresa_2' => 'Juan Representante', 'nit_2' => '44444444',
  'precio' => '1.150.000', 'administracion' => '0', 'incluye_iva' => 'No', 'tasa_comision' => '10', 'incremento_pactado' => 'IPC', 'duracion' => '12 Meses',
  'dias_gracia' => '60', 'energia' => '1484926', 'agua' => '921763', 'gas' => '', 'servicios_publicos' => 'a:0:{}', 'direccion_fisica' => 'Dirección vieja', 'copropiedad' => 'Edificio viejo',
]);
$db->insert('wp_jet_cct_inmuebles', ['_ID' => 13037, 'tipo_inmueble' => 'apartamento', 'tipo_negocio' => 'Arriendo', 'destinacion' => 'Vivienda', 'direccion_fisica' => 'Carrera 32B #80-624', 'pertenece_copropiedad' => 'Si', 'parqueadero' => 'No', 'deposito' => 'No', 'servicios_publicos' => '', 'id_contrato_mandato' => '636']);
$db->insert('wp_jet_cct_copropiedades', ['_ID' => 273, 'copropiedad' => 'Salvador de Bahía', 'nit' => '902081791', 'administrador' => 'Daniel', 'correo' => 'admon@example.invalid', 'contacto' => '3229155720']);
$db->insert('wp_jet_cct_propietarios', ['_ID' => 1970, 'nombre' => 'Yesenis Morales', 'documento' => '3243894030', 'correo' => 'yesenis@example.invalid', 'celular' => '3243894030']);
$db->insert('wp_jet_cct_arrendatarios', ['_ID' => 764, 'nombre' => 'Juan Palacio', 'documento' => '15242928', 'correo' => 'juan@example.invalid', 'celular' => '3166611888', 'tipo_persona' => 'Natural', 'tipo_documento' => 'Cédula de Ciudadania']);
$db->insert('wp_jet_cct_estudios_aseguradoras', ['_ID' => 629, 'id_ticket' => '10999', 'num_solicitud' => '12485586', 'aseguradora' => 'El Libertador', 'id_contrato_mandato_arrendamiento' => '880']);
$db->insert('wp_jet_cct_tickets', ['_ID' => 10999, 'estado' => 'Abierto', 'estado_comercial' => 'Estudio', 'estado_administrativo' => 'Pendiente', 'medio' => 'Aviso']);
$db->insert('wp_posts', ['ID' => 151187, 'post_type' => 'inmuebles']);
$db->insert('wp_postmeta', ['post_id' => 151187, 'meta_key' => 'luz', 'meta_value' => 'viejo']);

$service = new ClosingSheetService($db);
$query = ['id_contrato' => '636', 'id_arrendatario' => '764', 'id_estudio_aseguradora' => '629', 'id_propietario' => '1970'];
$sender = ['employee_id' => '1508', 'name' => 'Kellys Roqueme', 'email' => 'kellys@example.invalid', 'branch' => '1'];

// Precarga: ids que faltan en el enlace salen del mandato; datos del mandato, inmueble, copropiedad, estudio y ticket.
$context = $service->context($query);
$v = $context['values'];
$check($context['mode'] === 'crear' && $context['ids']['id_inmueble'] === '151187' && $context['ids']['id_inmueble_data'] === '13037' && $context['ids']['id_copropiedad'] === '273', 'missing link ids are taken from the mandate');
$check($v['valor_canon'] === '1.150.000' && $v['valor_administracion'] === 'No aplica' && $v['iva_total_precio'] === 'No aplica' && $v['meses_contrato'] === '12' && $v['tasa_administracion'] === '10' && $v['porcentaje_incremento'] === 'IPC', 'business data comes from the mandate with the form rules');
$check($v['id_ticket'] === '10999' && $v['numero_solicitud'] === '12485586' && $v['medio'] === 'Aviso' && $v['arrendatario'] === 'Juan Palacio' && $v['copropiedad'] === 'Salvador de Bahía', 'study, ticket, tenant and building data are preloaded');
$check($v['luz'] === '1484926' && $v['servicios_publicos'] === ['Energia', 'Agua'], 'without a services list, the services with an account number are checked');
$check(array_column($context['owners'], 'name') === ['Yesenis Morales', 'Inversiones X SAS'] && $context['owners'][1]['document'] === '900.123.456-7' && $context['owners'][1]['type'] === 'Juridica', 'co-owners come from the mandate (company, not its representative) with their person type');
$check($v['tipo_persona_propietario'] === 'Natural' && $v['tipo_documento_propietario'] === 'Cédula de Ciudadania' && $v['tipo_persona_arrendatario'] === 'Natural' && ClosingSheetService::personType('Jurídica') === 'Juridica', 'person and document types are preloaded from the profile or the mandate');

$input = $query + [
  'id_ticket' => '10999', 'tipo_inmueble' => 'apartamento', 'gestion_inmueble' => 'Arriendo', 'destinacion_inmueble' => 'Vivienda',
  'valor_canon' => '$ 1200000', 'valor_administracion' => '', 'incluye_iva' => 'No', 'iva_total_precio' => '50.000', 'meses_contrato' => '12 meses',
  'tasa_administracion' => '10%', 'porcentaje_incremento' => 'IPC +2', 'derechos_inmobiliarios' => '20', 'dias_gracia' => '30', 'direccion' => 'Carrera 32B #80-624 Apto 308',
  'servicios_publicos' => ['Agua', 'Energia'], 'luz' => '1484926', 'agua' => '921763', 'gas' => 'no se envía',
  'propiedad_horizontal' => 'Si', 'copropiedad' => 'Salvador de Bahía', 'nit_copropiedad' => '902081791', 'administrador' => 'Daniel Ramírez',
  'correo_copropiedad' => 'admon@example.invalid', 'celular_copropiedad' => '3229155720',
  'horario_mudanza_inicio_1' => '08:00', 'horario_mudanza_final_1' => '12:00', 'horario_mudanza_inicio_2' => '14:00', 'horario_mudanza_final_2' => '17:00',
  'canon_supera_valor' => 'No', 'tiene_parqueadero' => 'No', 'tiene_deposito' => 'No',
  'tipo_persona_propietario' => 'Natural', 'tipo_documento_propietario' => 'Cédula de Ciudadania', 'representante_propietario' => 'oculto',
  'tipo_persona_arrendatario' => 'Juridica', 'tipo_documento_arrendatario' => 'Nit', 'representante_arrendatario' => 'Andrés Ordóñez', 'documento_representante_arrendatario' => '1047448935',
  'propietario' => 'Yesenis Morales', 'documento_propietario' => '3243894030', 'correo_propietario' => 'yesenis.nuevo@example.invalid', 'celular_propietario' => '3243894030',
  'arrendatario' => 'Juan Alberto Palacio', 'documento_arrendatario' => '15242928', 'correo_arrendatario' => 'juan@example.invalid', 'celular_arrendatario' => '3166611888',
  'tiene_mascotas' => 'Si', 'mascotas' => [['tipo' => 'Perro', 'raza' => 'Pincher'], ['tipo' => '', 'raza' => '']], 'tiene_vehiculo' => 'No', 'convive_personas' => 'No',
  'num_codeudores' => 'Ninguno', 'acuerdos_precontractuales' => ['Propietario'], 'acuerdos_precontractuales_pro' => 'Pinta antes', 'acuerdos_precontractuales_arre' => 'no marcado',
  'referido_pph' => 'No', 'porcentaje' => ['1' => '60', '2' => '40'], 'notas' => ['estado_certificado_tradicion' => 'No actualizado', 'estado_factura_predial' => "Factura\ndel garaje"],
];
foreach (ClosingSheetForm::CHECKLIST as [, $when, $items]) {
  foreach ($items as [$column]) $input[$column] = 'Cumple';
}

// Validación: porcentajes, requeridos y lista de chequeo.
$check($fails(fn() => $service->save(['porcentaje' => ['1' => '60', '2' => '30']] + $input, $sender), 'suman 90') !== 'no falló', 'shares that do not add up to 100 are rejected');
$missing = $input;
unset($missing['derechos_inmobiliarios'], $missing['estado_asegurable']);
$message = $fails(fn() => $service->save($missing, $sender), 'Derechos inmobiliarios');
$check(str_contains($message, 'Falta: Derechos inmobiliarios') && str_contains($message, 'Asegurable'), 'missing required fields and checklist items are listed');
$check($fails(fn() => $service->save(['propiedad_horizontal' => 'Tal vez'] + $input, $sender), 'no es una opción válida') !== 'no falló', 'values outside the glossary are rejected');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_cierres') === 0, 'nothing is saved when validation fails');

$result = $service->save($input, $sender);
$closing = $db->getRow('SELECT * FROM wp_jet_cct_cierres WHERE _ID = ?', [$result['id']]);
$check($result['mode'] === 'crear' && $closing['estado'] === ClosingSheetService::STATUS_NEW && $closing['id_empleado'] === '1508' && $closing['cct_author_id'] === '1508' && $closing['creador'] === 'Kellys Roqueme' && $closing['sucursal'] === '1', 'closing sheet is created with status, real employee id, creator and branch');
$check($closing['valor_canon'] === '1.200.000' && $closing['valor_administracion'] === 'No aplica' && $closing['iva_total_precio'] === 'No aplica' && $closing['meses_contrato'] === '12' && $closing['tasa_administracion'] === '10' && $closing['porcentaje_incremento'] === 'IPC +2', 'business terms are editable and saved in the sheet format');
$termsMandate = $db->getRow('SELECT precio, administracion, iva_total_precio, duracion, tasa_comision, incremento_pactado FROM wp_jet_cct_contrato_mandato WHERE _ID = 636');
$check(array_map('strval', $termsMandate) === ['precio' => '1.200.000', 'administracion' => '0', 'iva_total_precio' => '', 'duracion' => '12 Meses', 'tasa_comision' => '10', 'incremento_pactado' => 'IPC +2'], 'business terms go to the mandate in its own format');
$check($closing['numero_solicitud'] === '12485586' && $closing['id_contrato_arrendamiento'] === '880' && $closing['medio'] === 'Aviso', 'hidden study data come from the server, not the browser');
$terms = ['valor_canon' => 'mil', 'valor_administracion' => '0', 'incluye_iva' => 'Si', 'iva_total_precio' => '', 'meses_contrato' => '0', 'tasa_administracion' => '150'];
$termErrors = ClosingSheetService::normalizeTerms($terms);
$check(count($termErrors) === 4 && $terms['valor_administracion'] === 'No aplica', 'invalid canon, VAT, months and commission are reported; zero administration means not applicable');
$check($closing['gas'] === '' && $closing['servicios_publicos'] === serialize(['Agua', 'Energia']) && $closing['mascotas'] === serialize([['tipo' => 'Perro', 'raza' => 'Pincher']]), 'hidden fields are empty and lists are serialized like JetForm');
$check($closing['tipo_persona_arrendatario'] === 'Juridica' && $closing['representante_arrendatario'] === 'Andrés Ordóñez' && $closing['representante_propietario'] === '', 'person types are saved; the representative of a natural person is not');
$tenant = $db->getRow('SELECT * FROM wp_jet_cct_arrendatarios WHERE _ID = 764');
$check($tenant['tipo_persona'] === 'Juridica' && $tenant['tipo_documento'] === 'Nit' && $tenant['nombre_juridico'] === 'Andrés Ordóñez' && $tenant['documento_juridico'] === '1047448935', 'the tenant profile gets the person type and legal representative');
$check($fails(fn() => $service->save(['representante_arrendatario' => ''] + $input, $sender), 'Representante legal del arrendatario') !== 'no falló', 'a company requires its legal representative');
$check($closing['acuerdos_precontractuales_pro'] === 'Pinta antes' && $closing['acuerdos_precontractuales_arre'] === '', 'agreements of unchecked parties are not saved');
$check($closing['estado_certificado_tradicion'] === 'Cumple' && $closing['ob_documentos_juridicos'] === "<p><b>Certificado de Tradición y Libertad no mayor a treinta (30) días:</b><br />No actualizado</p>\n<p><b>Copia de escritura pública:</b></p>\n<p><b>Factura del predial:</b><br />Factura<br />\ndel garaje</p>\n" && $closing['ob_servicios_publicos'] === '', 'checklist states and notes keep the JetForm format');
$newOwner = $db->getRow("SELECT * FROM wp_jet_cct_propietarios WHERE documento = '900.123.456-7'");
$check($closing['propietarios'] === '2' && $closing['porcentaje_1'] === '60' && $closing['porcentaje_2'] === '40' && $closing['id_propietario_2'] === (string) $newOwner['_ID'] && $closing['propietario_2'] === 'Inversiones X SAS' && $newOwner['cct_author_id'] === '1508', 'co-owners and shares are saved and the missing owner is registered');
$check($closing['tipo_persona_propietario_2'] === 'Juridica' && $closing['representante_propietario_2'] === 'Juan Representante' && $closing['documento_representante_propietario_2'] === '44444444' && $closing['tipo_persona_propietario_3'] === '', 'each co-owner keeps person type and legal representative from the mandate');

$mandate = $db->getRow('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = 636');
$check($mandate['id_hoja_cierre'] === (string) $result['id'] && $mandate['estado_hoja_cierre'] === ClosingSheetService::STATUS_NEW, 'the mandate points to the new closing sheet');
$check($mandate['direccion_fisica'] === 'Carrera 32B #80-624 Apto 308' && $mandate['dias_gracia'] === '30' && $mandate['copropiedad'] === 'Salvador de Bahía' && $mandate['administrador_co'] === 'Daniel Ramírez' && $mandate['correo_1'] === 'yesenis.nuevo@example.invalid' && $mandate['servicios_publicos'] === serialize(['Agua', 'Energia']), 'closing sheet changes are written to the mandate');
$check($mandate['tipo_1'] === 'Natural' && $mandate['tipo_documento_1'] === 'Cédula de Ciudadania' && $mandate['empresa_1'] === 'no se borra', 'owner person type goes to the mandate; a hidden representative does not erase it');
$check($mandate['porcentaje_1'] === '60' && $mandate['porcentaje_2'] === '40' && $mandate['id_propietario_nuevo_2'] === (string) $newOwner['_ID'] && $mandate['precio'] === '1.200.000', 'shares, owner ids and the edited canon go to the mandate');
$check(in_array('dias_gracia', $result['synced'], true) && !in_array('id_hoja_cierre', $result['synced'], true), 'the result lists the mandate data that changed');

$property = $db->getRow('SELECT * FROM wp_jet_cct_inmuebles WHERE _ID = 13037');
$check($property['estado'] === 'En cierre' && $property['id_hoja_cierre'] === (string) $result['id'] && $property['arrendatario'] === 'Juan Alberto Palacio' && $property['horario_mudanza_inicio_1'] === '08:00', 'the property record is updated like the form did');
$meta = array_column($db->getResults('SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 151187'), 'meta_value', 'meta_key');
$check($meta['luz'] === '1484926' && $meta['pertenece-regimen'] === 'Si' && $meta['horario-mudanza-inicio-1'] === '08:00' && $meta['id_hoja_cierre'] === (string) $result['id'] && $meta['nit-cop'] === '902081791', 'the WordPress property post meta is updated');
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 10999');
$ticketHistory = $db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket');
$check($ticket['estado'] === 'En proceso' && $ticket['estado_comercial'] === 'En cierre' && $ticket['estado_administrativo'] === 'Pendiente' && str_contains($ticketHistory['respuesta'], 'Juan Alberto Palacio') && $ticketHistory['id_hoja_cierre'] === (string) $result['id'], 'the ticket moves to closing without touching its administrative status');
$check($db->getVar('SELECT tipo_reporte FROM wp_jet_cct_historial_del_inmueble') === 'En cierre' && $db->getVar('SELECT nombre FROM wp_jet_cct_arrendatarios WHERE _ID = 764') === 'Juan Alberto Palacio' && $db->getVar('SELECT administrador FROM wp_jet_cct_copropiedades WHERE _ID = 273') === 'Daniel Ramírez', 'property history, tenant and building are updated');
$check($fails(fn() => $service->save($input, $sender), 'hace un momento') !== 'no falló', 'a double submit does not create a second sheet');

// Editar: sin lista de chequeo, sin cambiar el estado; lo oculto no borra el mandato.
$db->update('wp_jet_cct_cierres', ['estado' => 'Aprobada'], ['_ID' => $result['id']]);
$edit = $service->context([], $result['id']);
$check($edit['mode'] === 'editar' && $edit['values']['dias_gracia'] === '30' && $edit['owners'][1]['percent'] === '40', 'edit loads the saved sheet and shares');
$check($edit['values']['estado_asegurable'] === 'Cumple' && $edit['values']['__notes']['estado_factura_predial'] === "Factura\ndel garaje" && $edit['values']['__notes']['estado_copia_escritura'] === '', 'edit loads the checklist states and each saved note');
$editInput = ['propiedad_horizontal' => 'No', 'dias_gracia' => '15', 'porcentaje' => ['1' => '50', '2' => '50'], 'estado_certificado_tradicion' => 'No cumple'] + $input;
$service->save($editInput, $sender, $result['id']);
$closing = $db->getRow('SELECT * FROM wp_jet_cct_cierres WHERE _ID = ?', [$result['id']]);
$mandate = $db->getRow('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = 636');
$check($closing['ob_documentos_juridicos'] === $edit['values']['ob_documentos_juridicos'] && $closing['estado_certificado_tradicion'] === 'No cumple', 'edit saves checklist states and keeps unchanged notes as they were');
$check($closing['estado'] === 'Aprobada' && $closing['dias_gracia'] === '15' && $closing['copropiedad'] === '' && $closing['porcentaje_2'] === '50', 'edit keeps the status and saves the visible data');
$check($mandate['dias_gracia'] === '15' && $mandate['propiedad_horizontal'] === 'No' && $mandate['copropiedad'] === 'Salvador de Bahía' && $mandate['porcentaje_2'] === '50' && $mandate['id_hoja_cierre'] === (string) $result['id'], 'edit updates the mandate without erasing hidden data');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket') === 1, 'edit does not repeat ticket history');

$check(ClosingSheetService::checklistNotes(['A:', 'B:'], ['', ' ']) === '', 'a group without notes stays empty');
$legacy = "<p><b>Nombre del Administrador</b></p>\n<p><b>Correo Electrónico</b><br />\nno tiene &amp; no responde</p>\n";
$check(ClosingSheetService::checklistNotesParse(['Nombre del Administrador', 'Correo Electrónico', 'Rut'], $legacy) === ['', 'no tiene & no responde', ''], 'notes saved by the WordPress form are read back');
$check(ClosingSheetService::checklistNotesParse(['A & B:', 'C:'], ClosingSheetService::checklistNotes(['A & B:', 'C:'], ["uno\ndos", 'tres'])) === ["uno\ndos", 'tres'], 'notes survive a save and a new edit');

// Hoja anterior a la lista de chequeo: se puede editar sin llenarla; una observación cambiada se vuelve a armar.
$old = $db->getRow('SELECT * FROM wp_jet_cct_cierres WHERE _ID = ?', [$result['id']]);
unset($old['_ID']);
foreach (ClosingSheetForm::CHECKLIST as $notesColumn => [, , $items]) {
  $old[$notesColumn] = '';
  foreach ($items as [$column]) $old[$column] = '';
}
$db->insert('wp_jet_cct_cierres', $old);
$oldId = (int) $db->lastInsertId();
$legacyInput = $editInput;
foreach (ClosingSheetForm::CHECKLIST as [, , $items]) {
  foreach ($items as [$column]) unset($legacyInput[$column]);
}
$legacyInput['estado_asegurable'] = 'Cumple';
$legacyInput['notas'] = ['estado_asegurable' => 'Sí asegurable'];
$service->save($legacyInput, $sender, $oldId);
$old = $db->getRow('SELECT * FROM wp_jet_cct_cierres WHERE _ID = ?', [$oldId]);
$check($old['estado_asegurable'] === 'Cumple' && $old['estado_cobertura'] === '' && str_contains($old['ob_documentos_aseguradora_cierre'], '<b>Asegurable</b><br />Sí asegurable') && $old['ob_documentos_juridicos'] === '', 'an old sheet without checklist can be edited and only what was filled is saved');
$check(ClosingSheetService::percents([['index' => 3, 'name' => 'Ana']], []) === [3 => '100'], 'a single owner always has 100 %');

echo "Closing sheet checks passed.\n";
