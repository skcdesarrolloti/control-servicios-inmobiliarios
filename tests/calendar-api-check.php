<?php

declare(strict_types=1);

// API propia del calendario sobre SQLite en memoria: no toca la base real ni encola notificaciones.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Modules\Calendar\CalendarApi;
use SCM\Modules\Calendar\CalendarMailer;
use SCM\Modules\Calendar\CalendarStore;

define('SCM_BASE_URL', 'https://panel.example.invalid');
define('SCM_APP_SECRET', str_repeat('q', 40));

$check = static function (bool $condition, string $message): void {
  if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
  echo "PASS: {$message}\n";
};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ([
  'CREATE TABLE categorias_calendario (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, color TEXT, descripcion TEXT, roles_notificar TEXT)',
  "CREATE TABLE calendario_actividades (id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT, descripcion TEXT, ubicacion TEXT, fecha_inicio TEXT, fecha_fin TEXT, id_empleado TEXT, creado_por TEXT, id_categoria INTEGER, id_ticket INTEGER, origen_app TEXT, external_ref TEXT, google_event_id TEXT, meta_json TEXT, estado TEXT DEFAULT 'No', observacion TEXT, fue_trasladado TEXT DEFAULT 'No')",
  "CREATE TABLE calendario_recordatorios (id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT, mensaje TEXT, recordatorio_at TEXT, canal TEXT, estado TEXT DEFAULT 'pendiente', id_empleado TEXT, creado_por TEXT, id_evento INTEGER, id_tarea INTEGER, id_ticket INTEGER, id_categoria INTEGER, notification_queue_id INTEGER, origen_app TEXT, external_ref TEXT, google_event_id TEXT, meta_json TEXT)",
  'CREATE TABLE wp_jet_cct_confi_sistema (_ID INTEGER PRIMARY KEY AUTOINCREMENT, funcion TEXT, valor TEXT, cct_status TEXT)',
  'CREATE TABLE registro_bloqueos (id INTEGER PRIMARY KEY AUTOINCREMENT, id_empleado TEXT, cantidad_pendientes INTEGER, fecha_registro TEXT)',
  'CREATE TABLE wp_jet_cct_funcionarios (_ID INTEGER PRIMARY KEY, nombre TEXT, correo TEXT, celular TEXT, rol TEXT, id_cargo TEXT, id_empleado TEXT, activo TEXT)',
  'CREATE TABLE wp_jet_cct_tickets (_ID INTEGER PRIMARY KEY, indicativo TEXT, solicitante TEXT, correo_solicitante TEXT, celular_solicitante TEXT, id_empleado TEXT, estado TEXT, estado_comercial TEXT, estado_administrativo TEXT, departamento TEXT, contrato TEXT, inmueble TEXT, direccion TEXT, fecha_actualizacion INTEGER, cct_modified TEXT, id_ticket TEXT)',
  'CREATE TABLE wp_jet_cct_historial_del_ticket (_ID INTEGER PRIMARY KEY AUTOINCREMENT, cct_status TEXT, cct_author_id TEXT, cct_created TEXT, cct_modified TEXT, id_ticket INTEGER, fecha INTEGER, nombre TEXT, correo TEXT, celular TEXT, respuesta TEXT, id_empleado TEXT, id_evento INTEGER, fue_editada TEXT)',
] as $sql) {
  $pdo->exec($sql);
}
$db = new Database($pdo, 'wp_');
$db->insert('categorias_calendario', ['nombre' => 'Inspección', 'color' => '#f97316', 'descripcion' => '', 'roles_notificar' => '900,']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 1, 'nombre' => 'Responsable QA', 'correo' => 'resp@example.invalid', 'celular' => '3000000001', 'id_cargo' => '3', 'id_empleado' => '100', 'activo' => 'Si']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 2, 'nombre' => 'Creador QA', 'correo' => 'crea@example.invalid', 'celular' => '3000000002', 'id_cargo' => '3', 'id_empleado' => '200', 'activo' => 'Si']);
$db->insert('wp_jet_cct_funcionarios', ['_ID' => 3, 'nombre' => 'Rol categoría QA', 'correo' => 'rol@example.invalid', 'celular' => '', 'id_cargo' => '11', 'id_empleado' => '900', 'activo' => 'Si']);
$db->insert('wp_jet_cct_tickets', ['_ID' => 55, 'id_ticket' => '7001', 'solicitante' => 'Cliente QA', 'correo_solicitante' => 'cliente@example.invalid', 'id_empleado' => '100', 'estado' => 'Nuevo', 'estado_comercial' => 'Comercial QA', 'estado_administrativo' => 'Nuevo']);

$mailer = new class(new CalendarStore($db)) extends CalendarMailer {
  public array $emails = [];
  public array $scheduled = [];
  public array $cancelled = [];
  protected function sendEmail(array $destinations, string $subject, string $title, string $body, string $module, string $dedupe, array $meta, array $buttons = []): void
  {
    $this->emails[] = compact('destinations', 'subject', 'module', 'buttons');
  }
  public function scheduleReminder(array $item): ?int { $this->scheduled[] = $item; return 77; }
  public function cancelReminder(array $item, string $reason): void { $this->cancelled[] = $item['id']; }
};
$_SESSION['scm_employee_id'] = '200';
$api = new CalendarApi($db, $mailer);
$future = (new DateTimeImmutable('+3 days'))->format('Y-m-d');

$res = $api->handle('crear_evento', ['titulo' => 'Visita QA', 'fecha_inicio' => $future . ' 09:00:00', 'fecha_fin' => $future . ' 10:00:00', 'id_empleado' => '100', 'id_categoria' => 1, 'id_ticket' => 55, 'es_cita' => 'si', 'estado_administrativo' => 'Por inspeccionar', 'creado_por' => '999', 'sincronizar_google' => '1', 'meta' => ['google_calendar_requested' => true]]);
$check($res['success'] && (int) $res['data']['id'] === 1, 'crear_evento devuelve el id como antes');
$event = $db->getRow('SELECT * FROM calendario_actividades WHERE id = 1');
$check($event['creado_por'] === '200' && $event['origen_app'] === CalendarStore::ORIGIN && $event['google_event_id'] === null && $event['meta_json'] === null, 'creado_por es el funcionario de la sesión y no queda rastro de Google');
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 55');
$check($ticket['estado'] === 'En proceso' && $ticket['estado_administrativo'] === 'Por inspeccionar' && $ticket['estado_comercial'] === 'Comercial QA' && $ticket['id_ticket'] === '7001', 'la cita pasa el caso a Por inspeccionar sin vaciar el estado comercial ni tocar el número del caso');
$history = $db->getRow('SELECT * FROM wp_jet_cct_historial_del_ticket');
$check((int) $history['id_evento'] === 1 && $history['cct_author_id'] === '200' && $history['nombre'] === 'Responsable QA', 'el historial del caso guarda el evento con cct_author_id del funcionario que agenda');
$modules = array_column($mailer->emails, 'module');
$check($modules === ['nuevo-evento-email', 'cita-solicitante-email'], 'avisa al funcionario y al solicitante');
$staff = $mailer->emails[0]['destinations'];
sort($staff);
$check($staff === ['crea@example.invalid', 'resp@example.invalid', 'rol@example.invalid'], 'el correo va al responsable, a quien creó y a los roles de la categoría');
$check(str_contains($mailer->emails[1]['buttons'][0]['url'] ?? '', '?scm_case=55.'), 'el solicitante recibe el enlace firmado del caso');

$sinCaso = $api->handle('crear_evento', ['titulo' => 'Sin caso', 'fecha_inicio' => $future . ' 11:00', 'fecha_fin' => $future . ' 12:00', 'id_empleado' => '100', 'id_categoria' => 1]);
$check($sinCaso['success'], 'crear_evento acepta fechas sin segundos');
$bad = $api->handle('crear_evento', ['titulo' => 'Mal', 'fecha_inicio' => $future . ' 12:00', 'fecha_fin' => $future . ' 11:00', 'id_empleado' => '100', 'id_categoria' => 1]);
$check(!$bad['success'] && str_contains($bad['message'], 'posterior'), 'rechaza fin antes del inicio');
$check(!$api->handle('crear_evento', ['titulo' => 'X', 'fecha_inicio' => $future . ' 09:00', 'fecha_fin' => $future . ' 10:00', 'id_empleado' => '100', 'id_categoria' => 99])['success'], 'rechaza categorías inexistentes');

$bulk = $api->handle('crear_eventos', ['eventos' => [
  ['titulo' => 'Ronda', 'fecha_inicio' => $future . ' 13:00:00', 'fecha_fin' => $future . ' 14:00:00', 'id_categoria' => 1],
  ['titulo' => 'Ronda', 'fecha_inicio' => $future . ' 15:00:00', 'fecha_fin' => $future . ' 16:00:00', 'id_categoria' => 1],
], 'empleados' => ['100', '900']]);
$check($bulk['success'] && (int) $db->getVar('SELECT COUNT(*) FROM calendario_actividades') === 6, 'crear_eventos crea una fila por fecha y funcionario');
$check(count(array_filter($mailer->emails, static fn($m) => $m['module'] === 'eventos-masivos-email')) === 2, 'un resumen por funcionario en eventos masivos');

$list = $api->handle('filtrar_eventos_admin', ['id_empleado' => '100', 'fecha_inicio' => $future, 'fecha_fin' => $future, 'pagina' => 1, 'limite' => 2]);
$check($list['success'] && count($list['data']['data']) === 2 && $list['data']['total'] === 4 && $list['data']['data'][0]['funcionario'] === 'Responsable QA', 'filtrar_eventos_admin pagina y devuelve {data,total}');

$moved = $api->handle('trasladar_evento', ['id_evento' => 1, 'fecha_inicio' => $future . ' 17:00:00', 'fecha_fin' => $future . ' 18:00:00', 'observacion' => 'Cliente pidió otra hora', 'es_cita' => 'si', 'descripcion' => 'Le reprogramamos la visita para las 5 p. m.']);
$event = $db->getRow('SELECT * FROM calendario_actividades WHERE id = 1');
$check($moved['success'] && $event['fue_trasladado'] === 'Si' && $event['fecha_inicio'] === $future . ' 17:00:00', 'trasladar_evento mueve la fecha y marca el traslado');
$check(in_array('cita-trasladada-solicitante-email', array_column($mailer->emails, 'module'), true) && (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket') === 2, 'el traslado de la cita avisa al solicitante y deja historial');
$check($db->getVar('SELECT respuesta FROM wp_jet_cct_historial_del_ticket ORDER BY _ID DESC LIMIT 1') === 'Le reprogramamos la visita para las 5 p. m.', 'el historial del traslado guarda el mensaje para el caso, no solo el motivo');

$done = $api->handle('cambiar_estado', ['id_evento' => 1, 'observacion' => 'Visita realizada']);
$check($done['success'] && $db->getVar('SELECT estado FROM calendario_actividades WHERE id = 1') === 'Si', 'cambiar_estado marca realizado');
$check(!$api->handle('cambiar_estado', ['id_evento' => 1, 'observacion' => ' '])['success'], 'cambiar_estado exige observación');

$db->insert('calendario_actividades', ['titulo' => 'Viejo', 'fecha_inicio' => '2020-01-01 08:00:00', 'fecha_fin' => '2020-01-01 09:00:00', 'id_empleado' => '100', 'id_categoria' => 1, 'estado' => 'No']);
$blocked = $api->handle('crear_evento', ['titulo' => 'Bloqueado', 'fecha_inicio' => $future . ' 07:00', 'fecha_fin' => $future . ' 07:30', 'id_empleado' => '100', 'id_categoria' => 1]);
$check(!$blocked['success'] && !empty($blocked['data']['bloqueado_vencidos']) && (int) $db->getVar('SELECT COUNT(*) FROM registro_bloqueos') === 1, 'bloquea por eventos vencidos y registra el bloqueo');
$check(str_contains($blocked['message'], 'Responsable QA tiene 1') && str_contains($blocked['message'], '«Viejo»') && str_contains($blocked['message'], '01/01/2020') && !str_contains($blocked['message'], 'Visita QA') && count($blocked['data']['eventos']) === 1, 'el bloqueo nombra al funcionario asignado y solo el evento vencido, no los de hoy o futuros');
$today = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')));
$db->insert('calendario_actividades', ['titulo' => 'Más tarde hoy', 'fecha_inicio' => $today->modify('+2 hours')->format('Y-m-d H:i:s'), 'fecha_fin' => $today->modify('+3 hours')->format('Y-m-d H:i:s'), 'id_empleado' => '900', 'id_categoria' => 1, 'estado' => 'No']);
$db->insert('calendario_actividades', ['titulo' => 'Ayer sin cerrar', 'fecha_inicio' => $today->modify('-1 day')->format('Y-m-d H:i:s'), 'fecha_fin' => $today->modify('-1 day +1 hour')->format('Y-m-d H:i:s'), 'id_empleado' => '900', 'id_categoria' => 1, 'estado' => 'No']);
$check($api->handle('crear_evento', ['titulo' => 'Otra hoy', 'fecha_inicio' => $future . ' 06:00', 'fecha_fin' => $future . ' 06:30', 'id_empleado' => '900', 'id_categoria' => 1])['success'], 'un evento de hoy aún no ocurrido o uno de ayer sin cerrar no bloquean crear otro');
$check($api->handle('crear_evento', ['titulo' => 'Forzado', 'fecha_inicio' => $future . ' 07:00', 'fecha_fin' => $future . ' 07:30', 'id_empleado' => '100', 'id_categoria' => 1, 'permitir_vencidos' => '1'])['success'], 'permitir_vencidos crea pese al bloqueo');
$overdue = $api->handle('listar_pendientes_vencidos', ['id_empleado' => '100']);
$check(count($overdue['data']) === 1 && $overdue['data'][0]['titulo'] === 'Viejo', 'listar_pendientes_vencidos devuelve solo lo vencido');

$reminder = $api->handle('crear_item_calendario', ['tipo_item' => 'recordatorio', 'titulo' => 'Llamar', 'fecha_inicio' => $future . ' 08:00:00', 'id_empleado' => '100', 'recordatorio_canal' => 'whatsapp', 'aviso_minutos_antes' => 15, 'origen_app' => 'qa', 'external_ref' => 'ref-1']);
$check($reminder['success'] && $reminder['data']['estado'] === 'programado' && $reminder['data']['aviso_programado'] === true && (int) $reminder['data']['meta']['aviso_minutos_antes'] === 15, 'crear recordatorio programa el aviso en la cola');
$items = $api->handle('listar_items_calendario', ['tipo_item' => 'recordatorio', 'id_empleado' => '100', 'origen_app' => 'qa', 'external_ref' => 'ref-1']);
$check(count($items['data']) === 1 && $items['data'][0]['tipo_item'] === 'recordatorio' && $items['data'][0]['fecha_inicio'] === $future . ' 08:00:00', 'listar_items_calendario encuentra el recordatorio por origen y referencia');
$both = $api->handle('listar_items_calendario', ['id_empleado' => '100', 'fecha_inicio' => $future, 'fecha_fin' => $future, 'tipos_item' => ['evento', 'recordatorio']]);
$check(count($both['data']) === 6 && $both['data'][0]['tipo_item'] === 'evento' && $both['data'][1]['tipo_item'] === 'recordatorio', 'listar_items_calendario mezcla eventos y recordatorios en orden');
$rid = (int) $reminder['data']['id'];
$updated = $api->handle('actualizar_recordatorio', ['id_recordatorio' => $rid, 'titulo' => 'Llamar de nuevo', 'recordatorio_at' => $future . ' 08:30:00', 'aviso_minutos_antes' => 0]);
$check($updated['success'] && $updated['data']['item']['titulo'] === 'Llamar de nuevo' && count($mailer->scheduled) === 2, 'actualizar_recordatorio reprograma el aviso');
$cancel = $api->handle('actualizar_recordatorio_estado', ['id_recordatorio' => $rid, 'estado' => 'cancelado']);
$check($cancel['success'] && $cancel['data']['estado'] === 'cancelado' && $mailer->cancelled === [$rid], 'cancelar recordatorio cancela su aviso en la cola');
$check(!$api->handle('actualizar_recordatorio', ['id_recordatorio' => $rid, 'titulo' => 'X', 'recordatorio_at' => $future . ' 09:00'])['success'], 'no se editan recordatorios cancelados');

$check($api->handle('actualizar_tarea_estado', [])['status'] === 410, 'las tareas siguen retiradas');
$check(count($api->handle('obtener_tickets_funcionario', ['id_empleado' => '100'])['data']) === 1, 'obtener_tickets_funcionario lista los casos abiertos');
$check(count($api->handle('listar_categorias', [])['data']) === 1 && count($api->handle('listar_funcionarios', [])['data']) === 3, 'listar categorías y funcionarios');
$check(!$api->handle('estado_google_oauth', [])['success'], 'las acciones de Google ya no existen');

$db->insert('wp_jet_cct_confi_sistema', ['funcion' => 'calendario_reportes_comerciales_cargos', 'valor' => '["11"]', 'cct_status' => 'publish']);
$db->insert('categorias_calendario', ['nombre' => 'Captación comercial', 'color' => '#000', 'descripcion' => '', 'roles_notificar' => '']);
$api->handle('crear_evento', ['titulo' => 'Captación', 'fecha_inicio' => $future . ' 19:00', 'fecha_fin' => $future . ' 20:00', 'id_empleado' => '900', 'id_categoria' => 2, 'permitir_vencidos' => '1']);
$commercialId = (int) $db->getVar('SELECT MAX(id) FROM calendario_actividades');
$closed = $api->handle('cambiar_estado', ['id_evento' => $commercialId, 'observacion' => 'Listo']);
$check(!$closed['success'] && !empty($closed['data']['requiere_reporte']) && $db->getVar('SELECT estado FROM calendario_actividades WHERE id = ?', [$commercialId]) === 'No', 'un evento comercial con reporte obligatorio no se cierra sin su reporte');

unset($_SESSION['scm_employee_id']);
$server = (new CalendarApi($db, $mailer))->handle('crear_recordatorio', ['titulo' => 'Proceso', 'recordatorio_at' => $future . ' 10:00:00', 'id_empleado' => '100', 'creado_por' => '900']);
$check($server['success'] && $server['data']['creado_por'] === '900', 'sin sesión (procesos del servidor) conserva el creador enviado');

// ── Con sesión: permisos y reglas de horario ─────────────────────────
$_SESSION['scm_employee_id'] = '100';
$own = new CalendarApi($db, $mailer, false);
$team = new CalendarApi($db, $mailer, true);
$other = $own->handle('filtrar_eventos_admin', ['id_empleado' => '900', 'fecha_inicio' => $future, 'fecha_fin' => $future, 'limite' => 100]);
$check($other['success'] && $other['data']['data'] !== [] && count(array_filter($other['data']['data'], static fn($r) => $r['titulo'] !== 'Ocupado' || empty($r['privado']) || isset($r['descripcion']))) === 0, 'sin Calendario equipo, la agenda de otro funcionario solo muestra Ocupado');
$mine = $own->handle('listar_items_calendario', ['fecha_inicio' => $future, 'fecha_fin' => $future]);
$check($mine['data'] !== [] && count(array_filter($mine['data'], static fn($r) => (string) $r['id_empleado'] !== '100')) === 0, 'sin funcionario en el filtro, solo se lee lo propio');
$check($own->handle('listar_pendientes_vencidos', ['id_empleado' => '900'])['data'] === [] && $own->handle('obtener_tickets_funcionario', ['id_empleado' => '900'])['data'] === [], 'no se leen vencidos ni casos de otro funcionario');
$otherEventId = (int) $db->getVar("SELECT MIN(id) FROM calendario_actividades WHERE id_empleado = '900' AND estado = 'No' AND (creado_por IS NULL OR creado_por <> '100')");
$denied = $own->handle('cambiar_estado', ['id_evento' => $otherEventId, 'observacion' => 'x']);
$check(!$denied['success'] && $denied['status'] === 403, 'no se modifican eventos de otros sin Calendario equipo');
$check($team->handle('trasladar_evento', ['id_evento' => $otherEventId, 'fecha_inicio' => $future . ' 09:00', 'fecha_fin' => $future . ' 09:30', 'observacion' => 'Equipo'])['success'], 'con Calendario equipo sí se modifican');
$forOther = $own->handle('crear_evento', ['titulo' => 'Para otro', 'fecha_inicio' => $future . ' 10:00', 'fecha_fin' => $future . ' 10:30', 'id_empleado' => '900', 'id_categoria' => 1, 'permitir_vencidos' => '1']);
$check(!$forOther['success'] && $forOther['status'] === 403, 'no se agenda a otro funcionario sin Calendario equipo');
$check($own->handle('crear_evento', ['titulo' => 'Cita del caso', 'fecha_inicio' => $future . ' 10:00', 'fecha_fin' => $future . ' 10:30', 'id_empleado' => '900', 'id_categoria' => 1, 'id_ticket' => 55, 'permitir_vencidos' => '1'])['success'], 'sí se agenda la cita de un caso abierto a su funcionario');
$past = $own->handle('crear_evento', ['titulo' => 'Pasado', 'fecha_inicio' => '2020-02-02 09:00', 'fecha_fin' => '2020-02-02 10:00', 'id_empleado' => '100', 'id_categoria' => 1, 'permitir_vencidos' => '1']);
$check(!$past['success'] && str_contains($past['message'], 'ya pasó'), 'con sesión no se agenda en el pasado');
$early = $own->handle('crear_evento', ['titulo' => 'Madrugada', 'fecha_inicio' => $future . ' 06:00', 'fecha_fin' => $future . ' 07:00', 'id_empleado' => '100', 'id_categoria' => 1, 'permitir_vencidos' => '1']);
$check(!$early['success'] && str_contains($early['message'], '8:00'), 'con sesión los eventos van de 8:00 a. m. a 9:00 p. m.');
$db->update('wp_jet_cct_tickets', ['estado' => 'Cerrado'], ['_ID' => 55]);
$closedTicket = $team->handle('crear_evento', ['titulo' => 'Caso cerrado', 'fecha_inicio' => $future . ' 11:00', 'fecha_fin' => $future . ' 11:30', 'id_empleado' => '100', 'id_categoria' => 1, 'id_ticket' => 55, 'permitir_vencidos' => '1']);
$check(!$closedTicket['success'] && str_contains($closedTicket['message'], 'cerrado') && $db->getVar('SELECT estado FROM wp_jet_cct_tickets WHERE _ID = 55') === 'Cerrado', 'no se agenda en un caso cerrado ni se reabre');
$doneMove = $team->handle('trasladar_evento', ['id_evento' => 1, 'fecha_inicio' => $future . ' 12:00', 'fecha_fin' => $future . ' 12:30', 'observacion' => 'x']);
$check(!$doneMove['success'] && $db->getVar('SELECT estado FROM calendario_actividades WHERE id = 1') === 'Si', 'un evento realizado no se traslada ni se reabre');
$before = (int) $db->getVar('SELECT COUNT(*) FROM calendario_recordatorios');
$bulkFail = $team->handle('crear_recordatorios', ['recordatorios' => [
  ['titulo' => 'R1', 'recordatorio_at' => $future . ' 09:00', 'id_empleado' => '100'],
  ['titulo' => 'R2', 'recordatorio_at' => 'no-es-fecha', 'id_empleado' => '100'],
]]);
$check(!$bulkFail['success'] && (int) $db->getVar('SELECT COUNT(*) FROM calendario_recordatorios') === $before, 'recordatorios en lote: si uno es inválido no se crea ninguno');
$bulkOk = $team->handle('crear_recordatorios', ['recordatorios' => [
  ['titulo' => 'R1', 'recordatorio_at' => $future . ' 09:00', 'id_empleado' => '100'],
  ['titulo' => 'R2', 'recordatorio_at' => $future . ' 09:00', 'id_empleado' => '900'],
]]);
$check($bulkOk['success'] && $bulkOk['data']['creados'] === 2 && (int) $db->getVar('SELECT COUNT(*) FROM calendario_recordatorios') === $before + 2, 'recordatorios en lote se crean juntos');
$db->insert('calendario_actividades', ['titulo' => 'Varios días', 'fecha_inicio' => '2030-03-30 09:00:00', 'fecha_fin' => '2030-04-02 18:00:00', 'id_empleado' => '100', 'id_categoria' => 1, 'estado' => 'No']);
$april = $team->handle('filtrar_eventos_admin', ['id_empleado' => '100', 'fecha_inicio' => '2030-04-01', 'fecha_fin' => '2030-04-30', 'limite' => 50]);
$check(in_array('Varios días', array_column($april['data']['data'], 'titulo'), true), 'un evento de varios días aparece en el mes donde continúa');
$check(!isset($team->handle('listar_funcionarios', [])['data'][0]['correo']), 'la lista de funcionarios no expone correos ni celulares');

echo "Calendar API checks passed; no real database or notifications used.\n";
