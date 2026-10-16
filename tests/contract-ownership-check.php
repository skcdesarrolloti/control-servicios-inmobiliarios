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

// 2. Ayuda: identificación del funcionario y sugerencia.
$pdo = new OwnershipTestPdo();
$db = new \SCM\Core\Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios(_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, activo TEXT, correo TEXT, id_cargo TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_cierres(_ID INTEGER PRIMARY KEY, id_contrato_arrendamiento TEXT, fecha TEXT, id_empleado TEXT, creador TEXT, aprobador TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contrato_mandato(_ID INTEGER PRIMARY KEY, id_inmueble TEXT, fecha TEXT, creador TEXT, email_creador TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, estado TEXT, id_inmueble TEXT, id_inmueble_data TEXT, direccion TEXT, propietario TEXT, arrendatario TEXT, id_empleado TEXT, realizado_por TEXT, cct_author_id TEXT, id_contrato_mandato TEXT, id_cierre TEXT, fecha_entrega TEXT, inicio_contrato TEXT, cct_modified TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_historial_del_inmueble(_ID INTEGER PRIMARY KEY, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_empleado TEXT, funcionario TEXT, id_inmueble TEXT, fecha TEXT, tipo_reporte TEXT, observacion TEXT)');
foreach ([
  [70, '2999', 'Coordinadora QA', 'Si', 'coordinacion@example.invalid'],
  [1, '5', 'Jocabeth Cantillo Devoz', 'Si', 'buzon-arriendos@example.invalid'],
  [2, '1508', 'Kellys Roqueme Camacho', 'Si', 'buzon-arriendos@example.invalid'],
  [3, '4', 'Asesor de arriendo retirado 2', 'No', 'retirado@example.invalid'],
  [4, '2837', 'Monica Acuña Barrios', 'Si', 'monica@example.invalid'],
] as [$pk, $id, $name, $active, $mail]) {
  $db->insert('wp_jet_cct_funcionarios', ['_ID' => $pk, 'id_empleado' => $id, 'nombre' => $name, 'activo' => $active, 'correo' => $mail]);
}
// Hojas de cierre: el retirado (4) se registró como "Kelly Yepes Florian".
$db->insert('wp_jet_cct_cierres', ['_ID' => 10, 'id_contrato_arrendamiento' => '100', 'fecha' => '1735707600', 'id_empleado' => '5', 'creador' => 'Jocabeth Cantillo Devoz', 'aprobador' => '']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 11, 'id_contrato_arrendamiento' => '', 'fecha' => '1735707600', 'id_empleado' => '4', 'creador' => 'Kelly Yepes Florian', 'aprobador' => '']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 12, 'id_contrato_arrendamiento' => '103', 'fecha' => '1735707600', 'id_empleado' => '1508', 'creador' => 'Kellys Roqueme Camacho', 'aprobador' => '']);
// Mandatos: el correo es un buzón compartido, el nombre manda.
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 20, 'id_inmueble' => 'P1', 'fecha' => '1735707600', 'creador' => 'Jocabeth Cantillo', 'email_creador' => 'buzon-arriendos@example.invalid']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 21, 'id_inmueble' => 'P2', 'fecha' => '1735707600', 'creador' => 'Kelly Yepes', 'email_creador' => 'otro@example.invalid']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 22, 'id_inmueble' => 'P3', 'fecha' => '1735707600', 'creador' => 'Monica Acuña Barrios', 'email_creador' => 'monica@example.invalid']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 23, 'id_inmueble' => 'P4', 'fecha' => '1735707600', 'creador' => 'Persona desconocida', 'email_creador' => 'monica@example.invalid']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 24, 'id_inmueble' => 'P5', 'fecha' => '1735707600', 'creador' => 'Monica Acuna', 'email_creador' => '']);
foreach ([
  // id, contrato, inmueble, id_empleado actual, id_contrato_mandato, id_cierre
  [100, 'C100', 'P1', '10', '20', ''],    // cierre por contrato + mandato enlazado, ambos Jocabeth
  [101, 'C101', 'P2', '', '', '11'],      // cierre del retirado + mandato por inmueble (alias Kelly Yepes)
  [102, 'C102', 'P3', '5', '22', ''],     // solo mandato (Monica)
  [103, 'C103', 'P4', '1508', '23', ''],  // cierre Kellys; mandato no identificable por nombre -> correo único de Monica
  [104, 'C104', 'P9', '9', '', ''],       // sin evidencia
  [105, 'C105', 'P5', '', '24', ''],      // mandato sin tilde: "Monica Acuna"
] as [$id, $code, $property, $owner, $mandate, $closing]) {
  $db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => $id, 'contrato' => $code, 'estado' => 'Entregado', 'id_inmueble' => $property, 'id_empleado' => $owner, 'realizado_por' => $owner === '' ? '' : 'Nombre previo', 'cct_author_id' => '1', 'id_contrato_mandato' => $mandate, 'id_cierre' => $closing]);
}

$service = new ContractOwnershipService($db);
$snapshot = $service->snapshot();
$byId = [];
foreach ($snapshot['contracts'] as $contract) $byId[$contract['id']] = $contract;

$c = $byId[100];
$check($c['cierre']['id'] === '10' && $c['cierre']['via'] === 'contrato' && $c['cierre']['employee_id'] === '5', 'La hoja de cierre se encuentra por id_contrato_arrendamiento.');
$check($c['mandato']['employee_id'] === '5' && $c['mandato']['matched_by'] === 'nombre', 'El mandato se identifica por nombre parcial aunque el correo sea un buzón compartido.');
$check($c['suggestion'] === ['employee_id' => '5', 'source' => 'ambos', 'confidence' => 'alta', 'reason' => $c['suggestion']['reason']], 'Cierre y mandato coinciden: confianza alta.');

$c = $byId[101];
$check($c['cierre']['employee_id'] === '4' && $c['mandato']['via'] === 'inmueble' && $c['mandato']['employee_id'] === '4', 'El alias de la hoja de cierre identifica al funcionario renombrado en el mandato.');
$check($c['suggestion']['employee_id'] === '4' && $c['suggestion']['confidence'] === 'alta', 'Sugerencia del funcionario retirado (la UI exige elegir uno activo).');

$c = $byId[102];
$check($c['cierre'] === null && $c['suggestion']['employee_id'] === '2837' && $c['suggestion']['confidence'] === 'baja', 'Solo mandato: confianza baja.');

$c = $byId[103];
$check($c['mandato']['matched_by'] === 'correo' && $c['mandato']['employee_id'] === '2837', 'Sin nombre reconocible, el correo único identifica al creador del mandato.');
$check($c['suggestion']['employee_id'] === '1508' && $c['suggestion']['source'] === 'cierre' && $c['suggestion']['confidence'] === 'media', 'Si difieren, prevalece la hoja de cierre con confianza media.');

$check($byId[104]['suggestion']['confidence'] === 'ninguna' && $byId[104]['suggestion']['employee_id'] === '', 'Sin cierre ni mandato no hay sugerencia.');
$check($byId[105]['mandato']['employee_id'] === '2837', 'Los nombres se comparan sin tildes.');
$check($service->matchName('Kelly') === '' && $service->matchName('Cantillo Devoz') === '5', 'Una sola palabra no basta; nombre y apellido sí.');
$check(count(array_filter($snapshot['employees'], static fn(array $e): bool => !$e['active'])) === 1, 'Los funcionarios retirados se marcan como inactivos.');

// 3. Guardar: solo id_empleado y realizado_por, con historial y validaciones.
$fail = static function (callable $fn, string $expected, string $label) use ($check): void {
  try { $fn(); } catch (Throwable $e) { $check(str_contains($e->getMessage(), $expected), $label . ' (mensaje: ' . $e->getMessage() . ')'); return; }
  throw new RuntimeException($label . ' debía fallar.');
};
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '4']], 'Según hoja de cierre'), 'activos', 'No se puede asignar un funcionario retirado');
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '5']], 'ok'), 'motivo', 'El motivo es obligatorio');
$fail(fn() => $service->assign([['contract_id' => 101, 'employee_id' => '5'], ['contract_id' => 101, 'employee_id' => '5']], 'Duplicado de prueba'), 'repetidos', 'No se aceptan contratos repetidos');
$check($db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble') === 0 || $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble') === '0', 'Un lote inválido no deja historial.');

$result = $service->assign([
  ['contract_id' => 101, 'employee_id' => '5'],
  ['contract_id' => 100, 'employee_id' => '5'],
], 'Según hoja de cierre #11');
$check(count($result['updated']) === 2 && $result['skipped'] === 0, 'Se actualizan los contratos con cambios.');
$row = $db->getRow('SELECT id_empleado, realizado_por, cct_author_id FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 101');
$check($row === ['id_empleado' => '5', 'realizado_por' => 'Jocabeth Cantillo Devoz', 'cct_author_id' => '1'], 'Cambia id_empleado y realizado_por; cct_author_id se conserva.');
$history = $db->getRow('SELECT * FROM wp_jet_cct_historial_del_inmueble WHERE id_inmueble = ? ORDER BY _ID DESC LIMIT 1', ['P2']);
$check($history['cct_author_id'] === '2999' && $history['id_empleado'] === '2999' && $history['funcionario'] === 'Coordinadora QA', 'El historial registra el id_empleado real de quien hizo el cambio.');
$check(str_contains($history['observacion'], 'sin funcionario a Jocabeth Cantillo Devoz (5)') && str_contains($history['observacion'], 'Motivo: Según hoja de cierre #11'), 'El historial explica el cambio y el motivo.');
$again = $service->assign([['contract_id' => 101, 'employee_id' => '5']], 'Repetir sin cambios');
$check($again['updated'] === [] && $again['skipped'] === 1, 'Asignar el mismo funcionario no genera historial nuevo.');

echo "Contract ownership checks passed.\n";
