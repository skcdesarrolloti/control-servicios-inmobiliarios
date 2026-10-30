<?php

declare(strict_types=1);

// Flujo de la hoja de cierre: enlaces firmados, revisión de servicios antes de la ocupación, respuesta y corrección.
require dirname(__DIR__) . '/vendor/autoload.php';
define('SCM_BASE_URL', 'https://example.test/panel');
define('SCM_APP_SECRET', 'test-secret');

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetAccess;
use SCM\Modules\ClosingSheets\ClosingSheetService;
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

final class WorkflowTestPdo extends PDO
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

$pdo = new WorkflowTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', array_unique($columns))) . ')');
};
$cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];
$serviceColumns = static function (array $accounts): array {
  $out = $accounts;
  foreach (['luz', 'agua', 'gas'] as $suffix) {
    array_push($out, 'medidor_' . $suffix, 'fecha_revision_' . $suffix, 'fecha_corte_' . $suffix, 'resultado_tiempo_' . $suffix, 'resultado_valores_' . $suffix);
  }
  return $out;
};
$table('wp_jet_cct_cierres', array_merge($cct, $serviceColumns(['luz', 'agua', 'gas']), [
  'estado', 'fecha', 'id_inmueble', 'id_inmueble_data', 'id_contrato', 'id_contrato_arrendamiento', 'id_estudio_aseguradora', 'numero_solicitud',
  'direccion', 'arrendatario', 'propietario', 'correo_propietario', 'celular_propietario', 'propietarios', 'servicios_publicos', 'id_empleado', 'creador',
  'id_verificador', 'verificador', 'id_aprobador', 'aprobador', 'fecha_repuesta', 'observacion_respuesta', 'id_revision_servicios_publicos',
  'tipo_inmueble', 'gestion_inmueble', 'destinacion_inmueble',
]));
$table('wp_jet_cct_revisiones_servicios', array_merge($cct, $serviceColumns(['nic', 'poliza', 'numero_contrato']), [
  'id_inmueble', 'id_contrato', 'id_contrato_mandato', 'id_cierre', 'id_estudio_aseguradora', 'numero_solicitud', 'fecha', 'fecha_formateada', 'contrato', 'inmueble',
  'direccion', 'arrendatario', 'propietario', 'id_empleado', 'realizado_por', 'sucursal', 'revisiones_servicios', 'tipo_inmueble', 'tipo_negocio', 'destinacion', 'tipo',
]));
$table('wp_jet_cct_contrato_mandato', array_merge($cct, ['energia', 'agua', 'gas', 'estado_hoja_cierre']));
$table('wp_jet_cct_contratos_arrendamiento', array_merge($cct, ['ultima_revision_servicios', 'fecha_corte_luz', 'fecha_corte_agua', 'fecha_corte_gas']));
$table('wp_jet_cct_historial_del_inmueble', array_merge($cct, ['id_inmueble', 'id_inmueble_data', 'id_empleado', 'funcionario', 'fecha', 'tipo_reporte', 'observacion', 'id_revision_servicios_publicos', 'id_revision_sp']));
$table('wp_jet_cct_funcionarios', ['id_empleado', 'nombre', 'correo', 'celular', 'id_cargo', 'id_sucursal', 'activo']);
$table('wp_jet_cct_cargos', ['nombre_cargo']);
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, departamento TEXT)');
$pdo->exec('CREATE TABLE wp_posts(ID INTEGER PRIMARY KEY, post_type TEXT)');
$pdo->exec('CREATE TABLE wp_postmeta(meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
$pdo->exec('CREATE TABLE wp_jet_post_types(id INTEGER PRIMARY KEY, status TEXT, meta_fields TEXT)');
$db->insert('wp_jet_post_types', ['id' => 998, 'status' => 'glossary', 'meta_fields' => serialize(array_map(static fn(string $v): array => ['value' => $v, 'label' => $v], ['Al dia', '30 dias', '60 dias', 'Estado critico']))]);

$db->insert('wp_jet_cct_cargos', ['_ID' => 7, 'nombre_cargo' => 'Coordinadora de servicios']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 40, 'id_empleado' => '2871', 'nombre' => 'Elsie Perez', 'correo' => 'elsie@example.invalid', 'celular' => '3001112233', 'id_cargo' => '7', 'id_sucursal' => '1', 'activo' => 'Si']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 41, 'id_empleado' => '1001', 'nombre' => 'Said Abuita', 'correo' => 'said@example.invalid', 'id_cargo' => '7', 'activo' => 'Si']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 42, 'id_empleado' => '9999', 'nombre' => 'Retirado', 'activo' => 'No']);
$db->insert('wp_jet_cct_contrato_mandato', ['_ID' => 636, 'energia' => '111', 'agua' => '921763', 'gas' => '', 'estado_hoja_cierre' => ClosingSheetService::STATUS_NEW]);
$db->insert('wp_jet_cct_contratos_arrendamiento', ['_ID' => 880]);
$db->insert('wp_posts', ['ID' => 151187, 'post_type' => 'inmuebles']);
$db->insert('wp_postmeta', ['post_id' => 151187, 'meta_key' => 'revisiones-servicios', 'meta_value' => '1']);
$db->insert('wp_jet_cct_cierres', [
  '_ID' => 430, 'estado' => ClosingSheetService::STATUS_NEW, 'id_inmueble' => '151187', 'id_inmueble_data' => '13037', 'id_contrato' => '636', 'id_contrato_arrendamiento' => '880',
  'direccion' => 'Carrera 32B #80-624', 'arrendatario' => 'Juan Palacio', 'propietario' => 'Yesenis Morales', 'correo_propietario' => 'yesenis@example.invalid', 'propietarios' => '1',
  'servicios_publicos' => serialize(['Energia', 'Agua']), 'luz' => '111', 'agua' => '921763', 'id_empleado' => '1508', 'creador' => 'Kellys Roqueme', 'fecha' => '1793300000',
]);

$workflow = new ClosingSheetWorkflow($db);
$elsie = $workflow->actor('2871');
$said = $workflow->actorByUser(41);

// Enlaces firmados: una hoja, una acción, un funcionario, con vencimiento.
$url = ClosingSheetAccess::url(ClosingSheetAccess::REVIEW, 430, '2871');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
$check(str_starts_with($url, 'https://example.test/panel/revision-servicios-cierre.php?numero=430&p=revision&f=2871') && ClosingSheetAccess::verify($query, 430) === ['purpose' => 'revision', 'employee_id' => '2871'], 'a signed link opens its sheet for its employee');
$check(ClosingSheetAccess::verify($query, 431) === null && ClosingSheetAccess::verify(['f' => '1001'] + $query, 430) === null && ClosingSheetAccess::verify(['p' => 'respuesta'] + $query, 430) === null, 'changing the sheet, employee or action breaks the signature');
$expired = ClosingSheetAccess::url(ClosingSheetAccess::RESPOND, 430, '1001', time() - 1);
parse_str((string) parse_url($expired, PHP_URL_QUERY), $old);
$check(ClosingSheetAccess::verify($old, 430) === null, 'an expired link is rejected');
$check(ClosingSheetAccess::url(ClosingSheetAccess::VIEW, 430) === 'https://example.test/panel/hoja-cierre-ver.php?numero=430', 'without employee the link needs a panel session');
$check($fails(static fn() => $workflow->actor('9999'), 'ya no está activo') === 'ok' && $elsie['cargo'] === 'Coordinadora de servicios' && $said['employee_id'] === '1001', 'only active employees act; the session maps to the real id_empleado');
$check(ClosingSheetWorkflow::signature($elsie) === 'Elsie Perez - Coordinadora de servicios - Cel. 3001112233', 'signature uses name - cargo - Cel.');

// Formulario: servicios de la hoja marcados y cuentas precargadas.
$form = $workflow->reviewForm(430);
$check($form['services']['energia']['checked'] && $form['services']['agua']['checked'] && !$form['services']['gas']['checked'] && $form['services']['agua']['account'] === '921763', 'the review form preloads the sheet services and accounts');
$check($form['results'] === ['Al dia', '30 dias', '60 dias', 'Estado critico'], 'results come from glossary 998');

// Validaciones.
$base = ['revisar' => ['energia' => '1', 'agua' => '1'], 'cuenta_energia' => '1484926', 'medidor_energia' => 'M-1', 'corte_energia' => '2026-10-15', 'resultado_energia' => 'Al dia', 'valor_energia' => '0',
  'cuenta_agua' => '921763', 'medidor_agua' => 'H26', 'corte_agua' => '2026-10-20', 'resultado_agua' => '30 dias', 'valor_agua' => '$32.295'];
$check($fails(static fn() => $workflow->saveReview(430, ['revisar' => []] + $base, $elsie), 'al menos un servicio') === 'ok', 'at least one service is required');
$check($fails(static fn() => $workflow->saveReview(430, ['medidor_agua' => '', 'corte_agua' => '2026-02-31'] + $base, $elsie), 'Acueducto y alcantarillado: falta medidor, fecha de corte') === 'ok', 'missing meter and an invalid date are reported per service');
$check($fails(static fn() => $workflow->saveReview(430, ['valor_agua' => '0'] + $base, $elsie), 'valor adeudado') === 'ok', 'a late service needs the amount owed');
$check($fails(static fn() => $workflow->respond(430, ['estado' => 'Aprobada'], $said), 'todavía no tiene la revisión') === 'ok', 'a sheet cannot be answered before the services review');

// Revisión.
$result = $workflow->saveReview(430, $base, $elsie);
$review = $db->getRow('SELECT * FROM wp_jet_cct_revisiones_servicios WHERE _ID = ?', [$result['review_id']]);
$closing = $workflow->closing(430);
$cutoff = (new DateTimeImmutable('2026-10-20 00:00:00', new DateTimeZone('America/Bogota')))->getTimestamp();
$check($review['tipo'] === 'Antes de la ocupacion' && $review['id_cierre'] === '430' && $review['id_contrato'] === '880' && $review['id_contrato_mandato'] === '636' && $review['contrato'] === 'Contrato sin entregar' && $review['cct_author_id'] === '2871' && $review['realizado_por'] === 'Elsie Perez' && $review['sucursal'] === '1', 'the review is stored like the JetForm, authored by the real id_empleado');
$check($review['nic'] === '1484926' && $review['poliza'] === '921763' && $review['resultado_valores_agua'] === '32295' && (int) $review['fecha_corte_agua'] === $cutoff && $review['resultado_tiempo_gas'] === null, 'only the reviewed services are stored, amounts as pesos and cut-off at Colombian midnight');
$check($closing['estado'] === ClosingSheetWorkflow::STATUS_REVIEWED && $closing['verificador'] === 'Elsie Perez' && $closing['id_verificador'] === '2871' && $closing['luz'] === '1484926' && $closing['resultado_tiempo_agua'] === '30 dias' && $closing['id_revision_servicios_publicos'] === (string) $result['review_id'], 'the sheet keeps the results and waits for an answer');
$mandate = $db->getRow('SELECT * FROM wp_jet_cct_contrato_mandato WHERE _ID = 636');
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 880');
$check($mandate['energia'] === '1484926' && $mandate['gas'] === '' && $mandate['estado_hoja_cierre'] === ClosingSheetService::STATUS_NEW, 'the mandate gets the new account numbers and keeps its state');
$check((int) $contract['fecha_corte_agua'] === $cutoff && (int) $contract['ultima_revision_servicios'] > 0, 'the lease contract gets cut-off dates and the review date');
$check((int) $db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 151187 AND meta_key = 'revisiones-servicios'") === 2 && $review['revisiones_servicios'] === '2', 'the property review counter goes up');
$history = $db->getRow('SELECT * FROM wp_jet_cct_historial_del_inmueble ORDER BY _ID DESC LIMIT 1');
$check($history['id_revision_servicios_publicos'] === (string) $result['review_id'] && $history['cct_author_id'] === '2871' && $history['tipo_reporte'] === 'Contractual', 'the property history links the review');
$check($result['summary'] === 'Energía: Al día · Acueducto y alcantarillado: 30 días ($32.295)', 'the summary reads well for emails and WhatsApp');

// Respuesta.
$check($fails(static fn() => $workflow->respond(430, ['estado' => 'No aprobada', 'observacion' => ' '], $said), 'Escribe por qué') === 'ok', 'not approving needs an observation');
$check($fails(static fn() => $workflow->respond(430, ['estado' => 'Quizás'], $said), 'aprobada o no aprobada') === 'ok', 'only the two answers are accepted');
$workflow->respond(430, ['estado' => 'No aprobada', 'observacion' => 'Falta el certificado de tradición.'], $said);
$closing = $workflow->closing(430);
$check($closing['estado'] === 'No aprobada' && $closing['aprobador'] === 'Said Abuita' && $closing['id_aprobador'] === '1001' && $closing['observacion_respuesta'] === 'Falta el certificado de tradición.' && (int) $closing['fecha_repuesta'] > 0, 'the answer and its observation stay in the sheet');
$check($db->getVar('SELECT estado_hoja_cierre FROM wp_jet_cct_contrato_mandato WHERE _ID = 636') === 'No aprobada', 'the mandate mirrors the answer');
$check(ClosingSheetWorkflow::canReview($closing) && ClosingSheetWorkflow::canRespond($closing), 'a rejected sheet can be reviewed again or answered again');

// Corrección: vuelve a quedar por responder.
$check($workflow->resubmitIfRejected($closing, date('Y-m-d H:i:s')) && $workflow->closing(430)['estado'] === ClosingSheetWorkflow::STATUS_REVIEWED && $db->getVar('SELECT estado_hoja_cierre FROM wp_jet_cct_contrato_mandato WHERE _ID = 636') === ClosingSheetWorkflow::STATUS_REVIEWED, 'editing a rejected sheet sends it back to be answered');
$check(!$workflow->resubmitIfRejected($workflow->closing(430), date('Y-m-d H:i:s')), 'only rejected sheets are sent back');

$workflow->respond(430, ['estado' => 'Aprobada', 'observacion' => ''], $said);
$closing = $workflow->closing(430);
$check($closing['estado'] === 'Aprobada' && $closing['observacion_respuesta'] === '', 'the sheet is approved');
$check($fails(static fn() => $workflow->respond(430, ['estado' => 'No aprobada', 'observacion' => 'x'], $said), 'ya está "Aprobada"') === 'ok' && $fails(static fn() => $workflow->saveReview(430, $base, $elsie), 'ya no admite revisión') === 'ok', 'an approved sheet cannot be reviewed or answered again');

// Listado.
$listing = $workflow->listing('Aprobada', 'Carrera 32B');
$check(count($listing['rows']) === 1 && $listing['counts']['Aprobada'] === 1 && $workflow->listing('Aprobada', '999')['rows'] === [], 'the list filters by state and search');

// Avisos: con funcionarios en Notificaciones internas, los mensajes se arman hasta la cola (en SQLite la cola
// compartida no se puede crear, así que el primer envío falla justo ahí).
(new \SCM\Core\Settings($db))->set('internal_admin_notifications', ['hoja_cierre_creada' => [40], 'hoja_cierre_por_responder' => [41], 'hoja_cierre_respondida' => [41]]);
$reached = static function (callable $send): bool {
  try {
    $send();
  } catch (\Throwable $error) {
    echo '   (cola: ' . $error->getMessage() . ")\n";
    return str_contains($error->getMessage(), 'unsigned');
  }
  return true;
};
$check($reached(static fn() => $workflow->notifyCreated(430, ['employee_id' => '1508', 'name' => 'Kellys Roqueme', 'email' => 'kellys@example.invalid'])), 'the created-sheet notice builds each employee message');
$notify = new ReflectionMethod(ClosingSheetWorkflow::class, 'notifyResponse');
$check($reached(static fn() => $notify->invoke($workflow, 430, $said)), 'the answer notice builds each employee message');

echo "Closing sheet workflow checks passed.\n";
