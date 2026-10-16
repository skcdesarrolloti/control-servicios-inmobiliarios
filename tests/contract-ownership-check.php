<?php

declare(strict_types=1);

// SQLite en memoria: no toca la base de datos de producción.
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('America/Bogota');
$_SESSION = ['scm_user_id' => 70, 'scm_user' => 'Coordinadora QA', 'scm_logged_in' => true];

use SCM\Modules\ContractOwnership\ContractOwnerFields;
use SCM\Modules\ContractOwnership\ContractOwnershipService;

final class OwnershipTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    $query = str_replace(' FOR UPDATE', '', $query);
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

// 1. Las acciones del panel ya no escriben el responsable del contrato.
$check(ContractOwnerFields::strip(['id_empleado' => '1', 'realizado_por' => 'X', 'cct_author_id' => '1', 'estado' => 'Recibido']) === ['estado' => 'Recibido'], 'strip debe quitar los tres campos protegidos.');
$root = dirname(__DIR__);
$source = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$between = static function (string $text, string $start, string $end): string {
  $from = strpos($text, $start);
  if ($from === false) throw new RuntimeException('No se encontró: ' . $start);
  $to = strpos($text, $end, $from + strlen($start));
  return substr($text, $from, ($to === false ? strlen($text) : $to) - $from);
};
$noOwner = static function (string $snippet, string $label) use ($check): void {
  foreach (['id_empleado', 'realizado_por', 'cct_author_id'] as $field) {
    $check(!str_contains($snippet, "'" . $field . "'"), $label . ' no debe escribir ' . $field . ' en el contrato.');
  }
};
$reviews = $source('src/Modules/Pending/Concerns/PublicServicesReviewConcern.php');
$noOwner($between($reviews, '$contractPayload = $configuration', '];'), 'La revisión de servicios públicos');
$noOwner($between($reviews, "\$payload = ['ultima_revision_servicios' => \$timestamp", '];'), 'El ajuste de fecha de servicios públicos');
$noOwner($between($reviews, "\$changes['cct_modified'] = \$now;", 'updateContratoArrendamiento'), 'La configuración de servicios públicos');
$noOwner($between($source('src/Modules/Pending/PendingService.php'), "'estado' => 'Recibido', 'tipo' => 'Ex'", '])'), 'Marcar contrato como recibido');
$noOwner($between($source('src/Modules/AdministrativeNotifications/AdministrativeNotificationsService.php'), '$contractUpdate = [', '];'), 'La gestión de cobro');
$noOwner($between($source('src/Modules/Pending/PublicServicesDateRecovery.php'), "\$payload = ['ultima_revision_servicios' => \$evidence", '];'), 'La reparación de fechas de servicios públicos');
$workflow = $source('src/App/Concerns/HandlesTicketWorkflowActions.php');
$noOwner($between($workflow, "\$payload = ['fin_contrato' => (string) \$newTs];", 'throw new \RuntimeException(\'No se pudo actualizar el contrato #'), 'La importación de fin de contrato');
$noOwner($between($workflow, "if (\$dateUpdated) {", "No se pudo guardar la fecha fin del contrato."), 'La respuesta de terminación');
$check(str_contains($source('src/Modules/Pending/PendingRepository.php'), 'ContractOwnerFields::strip($data)'), 'updateContratoArrendamiento debe filtrar los campos protegidos.');

// 2. Ayuda: identificación del consultor, días de gracia y sugerencias.
$pdo = new OwnershipTestPdo();
$db = new \SCM\Core\Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_cargos(_ID INTEGER PRIMARY KEY, nombre_cargo TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios(_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, activo TEXT, correo TEXT, id_cargo TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_cierres(_ID INTEGER PRIMARY KEY, id_contrato_arrendamiento TEXT, fecha TEXT, id_empleado TEXT, creador TEXT, aprobador TEXT, dias_gracia TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contrato_mandato(_ID INTEGER PRIMARY KEY, id_inmueble TEXT, fecha TEXT, creador TEXT, email_creador TEXT, dias_gracia TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, estado TEXT, id_inmueble TEXT, id_inmueble_data TEXT, direccion TEXT, propietario TEXT, arrendatario TEXT, id_empleado TEXT, realizado_por TEXT, cct_author_id TEXT, id_contrato_mandato TEXT, id_cierre TEXT, dias_gracia TEXT, cct_modified TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, funcionario TEXT, id_inmueble TEXT, fecha TEXT, tipo_reporte TEXT, observacion TEXT)');
foreach ([9 => 'Consultor de Arriendo', 10 => 'Consultor de Venta', 11 => 'Gerencia Administrativa', 17 => 'Proveedor de Servicios de Consultoría Comercial Inmobiliaria', 4 => 'Asistente de Servicios al Arrendatario'] as $cargoId => $cargo) {
  $db->insert('wp_jet_cct_cargos', ['_ID' => $cargoId, 'nombre_cargo' => $cargo]);
}
foreach ([
  [70, '2999', 'Coordinadora QA', 'Si', 'coordinacion@example.invalid', '4'],
  [1, '5', 'Jocabeth Cantillo Devoz', 'Si', 'buzon-arriendos@example.invalid', '9'],
  [2, '1508', 'Kellys Roqueme Camacho', 'Si', 'buzon-arriendos@example.invalid', '9'],
  [3, '4', 'Asesor de arriendo retirado 2', 'No', 'retirado@example.invalid', '9'],
  [4, '2837', 'Monica Acuña Barrios', 'Si', 'monica@example.invalid', '9'],
  [5, '8', 'Gloria Correa Rivera', 'Si', 'gerencia@example.invalid', '11'],
  [6, '2448', 'Asesor de arriendo retirado', 'No', '', ''],
  [7, '1225', 'Nancy Pizarro Escobar', 'Si', 'nancy@example.invalid', '17'],
  [8, '10', 'Dilsa Babilonia Tejedor', 'Si', 'dilsa@example.invalid', '4'],
] as [$pk, $id, $name, $active, $mail, $cargoId]) {
  $db->insert('wp_jet_cct_funcionarios', ['_ID' => $pk, 'id_empleado' => $id, 'nombre' => $name, 'activo' => $active, 'correo' => $mail, 'id_cargo' => $cargoId]);
}
// Hojas de cierre: el retirado (4) se registró como "Kelly Yepes Florian"; el 2448 como "Karen Posada Beltran".
$db->insert('wp_jet_cct_cierres', ['_ID' => 10, 'id_contrato_arrendamiento' => '100', 'fecha' => '1735707600', 'id_empleado' => '5', 'creador' => 'Jocabeth Cantillo Devoz', 'aprobador' => '', 'dias_gracia' => '15']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 11, 'id_contrato_arrendamiento' => '', 'fecha' => '1735707600', 'id_empleado' => '4', 'creador' => 'Kelly Yepes Florian', 'aprobador' => '', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 12, 'id_contrato_arrendamiento' => '103', 'fecha' => '1735707600', 'id_empleado' => '1508', 'creador' => 'Kellys Roqueme Camacho', 'aprobador' => '', 'dias_gracia' => 'NO']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 13, 'id_contrato_arrendamiento' => '107', 'fecha' => '1735707600', 'id_empleado' => '8', 'creador' => 'Gloria Maria Correa Rivera', 'aprobador' => '', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 14, 'id_contrato_arrendamiento' => '', 'fecha' => '1735707600', 'id_empleado' => '2448', 'creador' => 'Karen Posada', 'aprobador' => '', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 15, 'id_contrato_arrendamiento' => '', 'fecha' => '1735707600', 'id_empleado' => '2448', 'creador' => 'Karen Posada Beltran', 'aprobador' => '', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 16, 'id_contrato_arrendamiento' => '', 'fecha' => '1735707600', 'id_empleado' => '2448', 'creador' => 'Karen Posada Beltran', 'aprobador' => '', 'dias_gracia' => '']);
// Mandatos: el correo es un buzón compartido, el nombre manda.
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 20, 'id_inmueble' => 'P1', 'fecha' => '1735707600', 'creador' => 'Jocabeth Cantillo', 'email_creador' => 'buzon-arriendos@example.invalid', 'dias_gracia' => '10']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 21, 'id_inmueble' => 'P2', 'fecha' => '1735707600', 'creador' => 'Kelly Yepes', 'email_creador' => 'otro@example.invalid', 'dias_gracia' => '1 MES']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 22, 'id_inmueble' => 'P3', 'fecha' => '1735707600', 'creador' => 'Monica Acuña Barrios', 'email_creador' => 'monica@example.invalid', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 23, 'id_inmueble' => 'P4', 'fecha' => '1735707600', 'creador' => 'Persona desconocida', 'email_creador' => 'monica@example.invalid', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 24, 'id_inmueble' => 'P5', 'fecha' => '1735707600', 'creador' => 'Monica Acuna', 'email_creador' => '', 'dias_gracia' => '']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 25, 'id_inmueble' => 'P7', 'fecha' => '1735707600', 'creador' => 'Monica Acuña Barrios', 'email_creador' => '', 'dias_gracia' => '7 dias']);
foreach ([
  // id, contrato, inmueble, id_empleado actual, id_contrato_mandato, id_cierre, dias_gracia
  [100, 'C100', 'P1', '10', '20', '', ''],    // cierre por contrato + mandato enlazado, ambos Jocabeth
  [101, 'C101', 'P2', '', '', '11', ''],      // cierre del retirado + mandato por inmueble (alias Kelly Yepes)
  [102, 'C102', 'P3', '5', '22', '', ''],     // solo mandato (Monica)
  [103, 'C103', 'P4', '1508', '23', '', ''],  // cierre Kellys; mandato no identificable por nombre -> correo único de Monica
  [104, 'C104', 'P9', '9', '', '', ''],       // sin evidencia
  [105, 'C105', 'P5', '', '24', '', ''],      // mandato sin tilde: "Monica Acuna"
  [107, 'C107', 'P7', '10', '25', '', '7'],   // cierre de una gerente (no consultora) + mandato de Monica; días ya en el contrato
] as [$id, $code, $property, $owner, $mandate, $closing, $grace]) {
  $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => $id, 'contrato' => $code, 'estado' => 'Entregado', 'id_inmueble' => $property, 'id_empleado' => $owner, 'realizado_por' => $owner === '' ? '' : 'Nombre previo', 'cct_author_id' => '1', 'id_contrato_mandato' => $mandate, 'id_cierre' => $closing, 'dias_gracia' => $grace]);
}

$check(ContractOwnershipService::isConsultant('Consultor de Arriendo', 'X') && ContractOwnershipService::isConsultant('Consultor de Venta', 'X'), 'Los consultores de arriendo y venta son elegibles.');
$check(ContractOwnershipService::isConsultant('', 'Asesor de arriendo retirado 2') && ContractOwnershipService::isConsultant('', 'Asesor de venta 1 retirado'), 'Los asesores retirados siguen siendo consultores.');
$check(!ContractOwnershipService::isConsultant('Gerencia Administrativa', 'Gloria') && !ContractOwnershipService::isConsultant('Proveedor de Servicios de Consultoría Comercial Inmobiliaria', 'Nancy'), 'Administrativos y proveedores no son consultores.');

$service = new ContractOwnershipService($db);
$snapshot = $service->snapshot();
$byId = [];
foreach ($snapshot['contracts'] as $contract) $byId[$contract['id']] = $contract;
$employees = [];
foreach ($snapshot['employees'] as $employee) $employees[$employee['id']] = $employee;

$check($employees['4']['real_name'] === 'Kelly Yepes Florian' && $employees['2448']['real_name'] === 'Karen Posada Beltran', 'Los retirados muestran el nombre completo con que firmaban sus hojas de cierre.');
$check($employees['5']['real_name'] === '' && $employees['8']['consultant'] === false && $employees['4']['consultant'] === true, 'Solo los retirados tienen nombre real; la gerencia no es consultora.');

$c = $byId[100];
$check($c['cierre']['id'] === '10' && $c['cierre']['via'] === 'contrato' && $c['cierre']['employee_id'] === '5', 'La hoja de cierre se encuentra por id_contrato_arrendamiento.');
$check($c['mandato']['employee_id'] === '5' && $c['mandato']['matched_by'] === 'nombre', 'El mandato se identifica por nombre parcial aunque el correo sea un buzón compartido.');
$check($c['suggestion']['employee_id'] === '5' && $c['suggestion']['source'] === 'ambos' && $c['suggestion']['confidence'] === 'alta', 'Cierre y mandato coinciden: confianza alta.');
$check($c['dias_gracia'] === null && $c['grace_suggestion'] === ['value' => 15, 'source' => 'cierre'], 'Los días de gracia del cierre prevalecen sobre los del mandato.');

$c = $byId[101];
$check($c['cierre']['employee_id'] === '4' && $c['mandato']['via'] === 'inmueble' && $c['mandato']['employee_id'] === '4', 'El alias de la hoja de cierre identifica al funcionario renombrado en el mandato.');
$check($c['suggestion']['employee_id'] === '4' && $c['suggestion']['confidence'] === 'alta', 'Sugerencia del consultor retirado (la UI exige elegir uno activo).');
$check($c['grace_suggestion'] === ['value' => 30, 'source' => 'mandato'], 'Sin días en el cierre se toman del mandato ("1 MES" = 30).');

$c = $byId[102];
$check($c['cierre'] === null && $c['suggestion']['employee_id'] === '2837' && $c['suggestion']['confidence'] === 'baja', 'Solo mandato: confianza baja.');
$check($c['grace_suggestion']['value'] === null, 'Sin días en ningún documento no hay sugerencia de días.');

$c = $byId[103];
$check($c['mandato']['matched_by'] === 'correo' && $c['mandato']['employee_id'] === '2837', 'Sin nombre reconocible, el correo único identifica al creador del mandato.');
$check($c['suggestion']['employee_id'] === '1508' && $c['suggestion']['source'] === 'cierre' && $c['suggestion']['confidence'] === 'media', 'Si difieren, prevalece la hoja de cierre con confianza media.');
$check($c['grace_suggestion'] === ['value' => 0, 'source' => 'cierre'], '"NO" en la hoja de cierre son 0 días.');

$c = $byId[107];
$check($c['cierre']['employee_id'] === '8' && $c['cierre']['eligible'] === false, 'La hoja de cierre de una gerente se identifica pero no es elegible.');
$check($c['suggestion']['employee_id'] === '2837' && $c['suggestion']['source'] === 'mandato', 'Un administrativo nunca se sugiere: se usa el consultor del mandato.');
$check($c['dias_gracia'] === 7 && $c['grace_suggestion']['value'] === 7, 'Los días que ya tiene el contrato se leen igual que en los documentos.');

$check($byId[104]['suggestion']['confidence'] === 'ninguna' && $byId[104]['suggestion']['employee_id'] === '', 'Sin cierre ni mandato no hay sugerencia.');
$check($byId[105]['mandato']['employee_id'] === '2837', 'Los nombres se comparan sin tildes.');
$check($service->matchName('Kelly') === '' && $service->matchName('Cantillo Devoz') === '5', 'Una sola palabra no basta; nombre y apellido sí.');

// 3. Guardar: corrige funcionario y días de gracia sin dejar historial.
$fail = static function (callable $fn, string $expected, string $label) use ($check): void {
  try { $fn(); } catch (Throwable $e) { $check(str_contains($e->getMessage(), $expected), $label . ' (mensaje: ' . $e->getMessage() . ')'); return; }
  throw new RuntimeException($label . ' debía fallar.');
};
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '4']]), 'consultores activos', 'No se puede asignar un consultor retirado');
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '8']]), 'consultores activos', 'No se puede asignar a una gerente');
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '10']]), 'consultores activos', 'No se puede asignar a una asistente');
$fail(fn() => $service->assign([['contract_id' => 101]]), 'Indica el funcionario o los días', 'Un cambio vacío se rechaza');
$fail(fn() => $service->assign([['contract_id' => 101, 'dias_gracia' => 400]]), 'entre 0 y 365', 'Días de gracia fuera de rango');
$fail(fn() => $service->assign([['contract_id' => 101, 'dias_gracia' => '2.5']]), 'número entero', 'Días de gracia no enteros');
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '5'], ['contract_id' => 101, 'employee_id' => '5']]), 'repetidos', 'No se aceptan contratos repetidos');
$fail(fn() => $service->assign([['contract_id' => 100, 'employee_id' => '5'], ['contract_id' => 999, 'employee_id' => '5']]), 'ya no existe', 'Un contrato inexistente falla el lote');
$check($db->getVar('SELECT id_empleado FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 100') === '10', 'Un lote fallido no deja cambios a medias.');

$result = $service->assign([
  ['contract_id' => 101, 'employee_id' => '5', 'dias_gracia' => 30],
  ['contract_id' => 100, 'employee_id' => '5'],
  ['contract_id' => 102, 'dias_gracia' => '0'],
]);
$check(count($result['updated']) === 3 && $result['skipped'] === 0, 'Se actualizan los contratos con cambios.');
$row = $db->getRow('SELECT id_empleado, realizado_por, cct_author_id, dias_gracia FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 101');
$check($row === ['id_empleado' => '5', 'realizado_por' => 'Jocabeth Cantillo Devoz', 'cct_author_id' => '5', 'dias_gracia' => '30'], 'Cambia funcionario (id_empleado, realizado_por, cct_author_id) y días de gracia.');
$row = $db->getRow('SELECT id_empleado, dias_gracia FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 102');
$check($row === ['id_empleado' => '5', 'dias_gracia' => '0'], 'Se pueden corregir solo los días de gracia sin tocar el funcionario.');
$check($result['updated'][2] === ['contract_id' => 102, 'dias_gracia' => 0], 'La respuesta indica solo lo que cambió.');
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 106, 'contrato' => 'C106', 'estado' => 'Entregado', 'id_inmueble' => 'P6', 'id_empleado' => '5', 'realizado_por' => 'Jocabeth Cantillo Devoz', 'cct_author_id' => '1']);
$author = $service->assign([['contract_id' => 106, 'employee_id' => '5']]);
$check(count($author['updated']) === 1 && $db->getVar('SELECT cct_author_id FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 106') === '5', 'Si solo difiere cct_author_id, también se alinea.');
$again = $service->assign([['contract_id' => 101, 'employee_id' => '5', 'dias_gracia' => 30]]);
$check($again['updated'] === [] && $again['skipped'] === 1, 'Aplicar los mismos datos no cambia nada.');
$check((string) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble') === '0', 'Es una corrección: no escribe en el historial del inmueble.');
$check(!str_contains((string) file_get_contents($root . '/src/Modules/ContractOwnership/ContractOwnershipService.php'), 'historial_del_inmueble'), 'El servicio no referencia el historial.');

echo "Contract ownership checks passed.\n";
