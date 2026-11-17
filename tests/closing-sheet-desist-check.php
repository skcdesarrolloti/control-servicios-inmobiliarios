<?php

declare(strict_types=1);

// Negocio desistido en la hoja de cierre: desistir desde la hoja (replica el JetForm "Desistir negocio") y marcar
// como desistidas las hojas cuyo contrato o estudio se desistió por fuera.
require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL', 'https://example.test/panel');
define('SCM_APP_SECRET', 'test-secret');

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetDesist;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;
use SCM\Modules\ClosingSheets\ClosingSheetWorkflow;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$fails = static function (callable $fn, string $needle): string {
  try {
    $fn();
  } catch (\DomainException $error) {
    return str_contains($error->getMessage(), $needle) ? 'ok' : 'otro mensaje: ' . $error->getMessage();
  }
  return 'no falló';
};

final class DesistTestPdo extends PDO
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
    return parent::prepare(str_replace([' FOR UPDATE', 'AS UNSIGNED', 'AS CHAR'], ['', 'AS INTEGER', 'AS TEXT'], $query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$pdo = new DesistTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', array_unique($columns))) . ')');
};
$cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];
$table('wp_jet_cct_cierres', array_merge($cct, ['estado', 'fecha', 'id_inmueble', 'id_inmueble_data', 'id_contrato', 'id_contrato_arrendamiento', 'id_estudio_aseguradora',
  'direccion', 'arrendatario', 'id_empleado', 'creador', 'razon_desistido', 'fecha_desistido', 'desistido_por']));
$table('wp_jet_cct_contrato_mandato', array_merge($cct, ['id_hoja_cierre', 'estado_hoja_cierre']));
$table('wp_jet_cct_contratos_arrendamiento', array_merge($cct, ['estado', 'contrato', 'razon_desistido', 'id_inmueble', 'id_inmueble_data', 'inmueble']));
$table('wp_jet_cct_estudios_aseguradoras', array_merge($cct, ['estado', 'estado_contrato', 'razon_desistido', 'id_contrato_mandato_arrendamiento', 'id_ticket_entrega']));
$table('wp_jet_cct_tickets', array_merge($cct, ['estado', 'estado_administrativo', 'fecha_actualizacion']));
$table('wp_jet_cct_historial_del_ticket', array_merge($cct, ['id_ticket', 'id_empleado', 'nombre', 'correo', 'fecha', 'id_hoja_cierre', 'respuesta']));
$table('wp_jet_cct_historial_del_inmueble', array_merge($cct, ['id_inmueble', 'id_inmueble_data', 'id_empleado', 'funcionario', 'fecha', 'tipo_reporte', 'observacion']));
$table('wp_jet_cct_historial_contrato_arrendamiento', array_merge($cct, ['id_inmueble', 'id_inmueble_data', 'id_empleado', 'funcionario', 'fecha', 'tipo_reporte', 'observacion', 'inmueble', 'contrato', 'id_contrato']));
$table('wp_jet_cct_inmuebles', array_merge($cct, ['estado']));
$table('wp_jet_cct_funcionarios', ['id_empleado', 'nombre', 'correo', 'celular', 'id_cargo', 'id_sucursal', 'activo']);
$table('wp_jet_cct_cargos', ['nombre_cargo']);
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, departamento TEXT)');
$pdo->exec('CREATE TABLE wp_posts(ID INTEGER PRIMARY KEY, post_type TEXT)');
$pdo->exec('CREATE TABLE wp_postmeta(meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');

$db->insert('wp_jet_cct_cargos', ['_ID' => 12, 'nombre_cargo' => 'Consultor comercial']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 40, 'id_empleado' => '1508', 'nombre' => 'Kellys Roqueme', 'correo' => 'kellys@example.invalid', 'id_cargo' => '12', 'activo' => 'Si']);
$db->insert('wp_posts', ['ID' => 151187, 'post_type' => 'inmuebles']);
$db->insert('wp_postmeta', ['post_id' => 151187, 'meta_key' => 'estado', 'meta_value' => 'En cierre']);
$db->insert('wp_jet_cct_inmuebles', ['_ID' => 13037, 'estado' => 'En cierre']);
$db->insert('wp_jet_cct_tickets', ['_ID' => 55, 'estado' => 'En proceso', 'estado_administrativo' => 'Pendiente']);

// Hoja aprobada con su contrato "No asignado", estudio y caso de entrega.
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 636, 'id_hoja_cierre' => '500', 'estado_hoja_cierre' => 'Aprobada']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 900, 'estado' => 'No enviado', 'contrato' => 'No asignado', 'id_inmueble' => '151187', 'id_inmueble_data' => '13037']);
$db->insert('wp_jet_cct_estudios_aseguradoras', ['_ID' => 70, 'estado' => 'Aprobado', 'estado_contrato' => '', 'id_contrato_mandato_arrendamiento' => '900', 'id_ticket_entrega' => '55']);
$sheet = ['id_inmueble' => '151187', 'id_inmueble_data' => '13037', 'id_contrato' => '636', 'direccion' => 'Carrera 32B #80-624', 'arrendatario' => 'Juan Palacio', 'id_empleado' => '1508', 'creador' => 'Kellys Roqueme', 'fecha' => '1793300000'];
$db->insert('wp_jet_cct_cierres', ['_ID' => 500, 'estado' => 'Aprobada', 'id_contrato_arrendamiento' => '900', 'id_estudio_aseguradora' => '70'] + $sheet);

$workflow = new ClosingSheetWorkflow($db);
$actor = $workflow->actor('1508');
$desist = new ClosingSheetDesist($db);

$check(in_array('Arrendatario desistio', $desist->reasons(), true) && in_array(ClosingSheetDesist::CHANGE_PROPERTY, $desist->reasons(), true), 'the reasons come from the "Razón de desistir" glossary');
$check($fails(static fn() => $desist->desist(500, 'Porque sí', '', $actor), 'motivo') === 'ok', 'a reason outside the glossary is rejected');

$result = $desist->desist(500, 'Arrendatario desistio', 'El arrendatario consiguió otro inmueble.', $actor);
$closing = $workflow->closing(500);
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 900');
$study = $db->getRow('SELECT * FROM wp_jet_cct_estudios_aseguradoras WHERE _ID = 70');
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 55');
$check($closing['estado'] === ClosingSheetWorkflow::DESISTED && $closing['desistido_por'] === 'Kellys Roqueme' && str_starts_with($closing['razon_desistido'], 'Arrendatario desistio') && (int) $closing['fecha_desistido'] > 0, 'the sheet is desisted with reason, date and who');
$check($contract['estado'] === 'Desistido' && $contract['razon_desistido'] === 'Arrendatario desistio', 'the "No asignado" contract is desisted');
$check($db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 151187 AND meta_key = 'estado'") === 'En borrador' && $db->getVar('SELECT estado FROM wp_jet_cct_inmuebles WHERE _ID = 13037') === 'En borrador', 'the property goes back to draft (post and CCT)');
$check($study['estado'] === 'Aprobado' && $study['estado_contrato'] === 'Desistido' && $study['razon_desistido'] === 'Arrendatario desistio', 'the insurance study stays approved with the contract desisted');
$check($ticket['estado'] === 'Cerrado' && $ticket['estado_administrativo'] === 'Desistido' && (int) $ticket['fecha_actualizacion'] > 0 && $db->getVar('SELECT id_hoja_cierre FROM wp_jet_cct_historial_del_ticket WHERE id_ticket = 55') === '500', 'the delivery case is closed as desisted, with its history');
$check($db->getVar('SELECT estado_hoja_cierre FROM wp_jet_cct_contrato_mandato WHERE _ID = 636') === ClosingSheetWorkflow::DESISTED, 'the mandate mirrors its current sheet');
$check($db->getVar('SELECT cct_author_id FROM wp_jet_cct_historial_del_inmueble') === '1508' && $db->getVar('SELECT tipo_reporte FROM wp_jet_cct_historial_contrato_arrendamiento WHERE id_contrato = 900') === 'Contrato desistido', 'property and contract history are authored by the real id_empleado');
$check($result['contract_id'] === 900 && $result['study_id'] === 70 && $result['ticket_id'] === 55, 'the result names what changed');
$check($fails(static fn() => $desist->desist(500, 'Arrendatario desistio', '', $actor), 'ya está desistida') === 'ok' && !ClosingSheetWorkflow::canReview($closing) && !ClosingSheetWorkflow::canRespond($closing), 'a desisted sheet cannot be desisted, reviewed or answered again');

// Con el contrato ya creado se desiste en Contratos; una hoja realizada tampoco se desiste aquí.
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 901, 'estado' => 'Por entregar', 'contrato' => '745']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 501, 'estado' => 'Aprobada', 'id_contrato_arrendamiento' => '901'] + $sheet);
$db->insert('wp_jet_cct_cierres', ['_ID' => 502, 'estado' => 'Realizada', 'id_contrato_arrendamiento' => '901'] + $sheet);
$check($fails(static fn() => $desist->desist(501, 'Propietario desistio', '', $actor), 'ya se creó') === 'ok' && $db->getVar('SELECT estado FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 901') === 'Por entregar', 'a sheet whose contract already has a number is desisted from Contratos');
$check($fails(static fn() => $desist->desist(502, 'Propietario desistio', '', $actor), 'ya tiene contrato') === 'ok', 'a done sheet is desisted from Contratos');

// Cambio de inmueble: el estudio vuelve a "Esperando respuesta" sin contrato desistido (como el formulario).
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 637, 'id_hoja_cierre' => '999', 'estado_hoja_cierre' => 'Aprobada']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 902, 'estado' => '', 'contrato' => 'No asignado']);
$db->insert('wp_jet_cct_estudios_aseguradoras', ['_ID' => 71, 'estado' => 'Aprobado', 'estado_contrato' => '', 'id_contrato_mandato_arrendamiento' => '902']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 503, 'estado' => 'En verificacion de servicios', 'id_estudio_aseguradora' => '71', 'id_contrato' => '637'] + array_diff_key($sheet, ['id_contrato' => 1]));
$desist->desist(503, ClosingSheetDesist::CHANGE_PROPERTY, '', $actor);
$study = $db->getRow('SELECT * FROM wp_jet_cct_estudios_aseguradoras WHERE _ID = 71');
$check($study['estado'] === 'Esperando respuesta' && $study['estado_contrato'] === '' && $db->getVar('SELECT estado FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 902') === 'Desistido', 'a change of property sends the study back to waiting (the contract is desisted, as in the form)');
$check($db->getVar('SELECT estado_hoja_cierre FROM wp_jet_cct_contrato_mandato WHERE _ID = 637') === 'Aprobada', 'the mandate is not touched when its current sheet is another one');

// Sincronización: negocios desistidos por fuera de la hoja.
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 910, 'estado' => 'Desistido', 'contrato' => 'No asignado', 'razon_desistido' => 'Propietario desistio']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 911, 'estado' => 'Entregado', 'contrato' => '698']);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 912, 'estado' => 'Desistido', 'contrato' => '708']);
$db->insert('wp_jet_cct_estudios_aseguradoras', ['_ID' => 80, 'estado_contrato' => 'Desistido', 'razon_desistido' => 'Demora operativa']);
$db->insert('wp_jet_cct_estudios_aseguradoras', ['_ID' => 81, 'estado_contrato' => 'Desistido']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 640, 'id_hoja_cierre' => '510', 'estado_hoja_cierre' => 'Aprobada']);
$db->insert('wp_jet_cct_cierres', ['_ID' => 510, 'estado' => 'Aprobada', 'id_contrato_arrendamiento' => '910', 'id_contrato' => '640'] + array_diff_key($sheet, ['id_contrato' => 1]));
$db->insert('wp_jet_cct_cierres', ['_ID' => 511, 'estado' => 'Realizada', 'id_contrato_arrendamiento' => '911', 'id_estudio_aseguradora' => '81'] + $sheet);
$db->insert('wp_jet_cct_cierres', ['_ID' => 512, 'estado' => 'Aprobada', 'id_contrato_arrendamiento' => '', 'id_estudio_aseguradora' => '80'] + $sheet);
$db->insert('wp_jet_cct_cierres', ['_ID' => 513, 'estado' => 'Realizada', 'id_contrato_arrendamiento' => '912'] + $sheet);
$check($desist->sync(0, 511) === 0 && $desist->sync(910, 0) === 1 && $workflow->closing(510)['estado'] === ClosingSheetWorkflow::DESISTED && $workflow->closing(512)['estado'] === 'Aprobada', 'sync can be limited to one sheet or one contract');
$check($desist->sync() === 2, 'sync marks the remaining sheets once');
$check($desist->sync() === 0, 'sync is idempotent');
$s510 = $workflow->closing(510);
$s512 = $workflow->closing(512);
$check($s510['desistido_por'] === ClosingSheetDesist::AUTO_BY_CONTRACT && $s510['razon_desistido'] === 'Propietario desistio' && $db->getVar('SELECT estado_hoja_cierre FROM wp_jet_cct_contrato_mandato WHERE _ID = 640') === ClosingSheetWorkflow::DESISTED, 'a desisted contract marks its sheet and the mandate');
$check($s512['estado'] === ClosingSheetWorkflow::DESISTED && $s512['desistido_por'] === ClosingSheetDesist::AUTO_BY_STUDY && $s512['razon_desistido'] === 'Demora operativa', 'without a contract, the study decides');
$check($workflow->closing(511)['estado'] === 'Realizada', 'with a delivered contract the study state is ignored');
$check($workflow->closing(513)['estado'] === ClosingSheetWorkflow::DESISTED, 'a done sheet whose contract was desisted afterwards is desisted too');
$check(in_array(ClosingSheetWorkflow::DESISTED, ClosingSheetWorkflow::LIST_STATES, true) && ClosingSheetFlowView::badge(ClosingSheetWorkflow::DESISTED) === '<span class="csf-badge is-off">Desistida</span>', 'the list has a Desistida tab and badge');

// Aviso: Notificaciones internas › Negocio desistido; el mensaje se arma hasta la cola (en SQLite la cola compartida
// no se puede crear, así que el envío falla justo ahí).
(new \SCM\Core\Settings($db))->set('internal_admin_notifications', [ClosingSheetDesist::INTERNAL_ACTION => [40]]);
$notify = new ReflectionMethod(ClosingSheetDesist::class, 'notify');
try {
  $notify->invoke($desist, 500, 'Arrendatario desistio', 'Nota', ['employee_id' => '2000', 'name' => 'Otro', 'email' => 'otro@example.invalid'], false);
  $reached = true;
} catch (\Throwable $error) {
  echo '   (cola: ' . $error->getMessage() . ")\n";
  $reached = str_contains($error->getMessage(), 'unsigned');
}
$check($reached, 'the desist notice builds each employee message');

echo "Closing sheet desist checks passed.\n";
