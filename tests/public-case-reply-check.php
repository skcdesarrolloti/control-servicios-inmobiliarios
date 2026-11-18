<?php
declare(strict_types=1);

// Connection-local temporary shadows: no business records or real messages are created.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });

use SCM\Support\CaseReplyNotifications;
use SCM\Support\PublicCaseAccess;
use SCM\Support\PublicCaseReply;

$db = SCM\Core\App::db();
$sharedRoot = getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications';
$config = require $sharedRoot . '/config.php';
$queueTable = $config['queue']['queue_table'];
$attemptsTable = $config['queue']['attempts_table'];
foreach (array_merge(array_map(fn($name) => $db->table($name), ['jet_cct_tickets', 'jet_cct_funcionarios', 'jet_cct_historial_del_ticket']), [$queueTable, $attemptsTable]) as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid fixture table');
  $sql = $db->getRow('SHOW CREATE TABLE `' . $table . '`')['Create Table'] ?? '';
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) throw new RuntimeException('Missing fixture schema');
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
$settings = static function (bool $replyEnabled): void {
  (new ReflectionProperty(SCM\Core\Settings::class, 'data'))->setValue(SCM\Core\App::settings(), [
    'internal_admin_notifications' => [CaseReplyNotifications::INTERNAL_ACTION => [903]],
    SCM\Support\FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY => ['3'],
    SCM\Support\PanelCaseNotifications::SETTINGS_KEY => ['enabled' => true, 'reply_enabled' => $replyEnabled],
  ]);
};
$settings(false);
foreach ([[902, '9002', 'Responsable', 'assignee@example.invalid', '3001112202'], [903, '9003', 'Copia', 'copy@example.invalid', '3001112203']] as [$pk, $employee, $name, $email, $phone]) {
  $db->insert($db->table('jet_cct_funcionarios'), ['_ID' => $pk, 'id_empleado' => $employee, 'nombre' => $name, 'correo' => $email, 'celular' => $phone, 'activo' => 'Si', 'id_cargo' => '3']);
}
$ticketRow = [
  '_ID' => 70501, 'id_ticket' => '70501', 'asunto' => 'Fuga en baño', 'estado' => 'En proceso', 'estado_administrativo' => 'En gestión',
  'id_empleado' => '9002', 'nombre_empleado' => 'Responsable', 'correo_empleado' => 'assignee@example.invalid', 'celular_empleado' => '3001112202',
  'propietario' => 'Propietaria Ejemplo', 'correo_propietario' => 'owner@example.invalid', 'celular_propietario' => '3001112210',
  'arrendatario' => 'Arrendatario Ejemplo', 'correo_arrendatario' => 'tenant@example.invalid', 'celular_arrendatario' => '3001112211',
];
$db->insert($db->table('jet_cct_tickets'), $ticketRow);
$ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = 70501');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$queue = static fn(): array => $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$reply = new PublicCaseReply($db, new SCM\Support\SchemaInspector($db));

$check(PublicCaseReply::canReply('propietario', $ticket) && PublicCaseReply::canReply('publico', $ticket), 'external audiences can reply to an open case');
$check(!PublicCaseReply::canReply('funcionario', $ticket), 'staff links never get the public reply form');
$check(!PublicCaseReply::canReply('propietario', ['estado' => 'Cerrado'] + $ticket), 'closed cases do not accept replies');
foreach (['  ', str_repeat('a', PublicCaseReply::MAX_TEXT + 1)] as $invalid) {
  try { $reply->submit($ticket, 'propietario', $invalid, []); $check(false, 'rejects invalid text'); }
  catch (InvalidArgumentException) { $check(true, 'rejects empty or oversized reply before writing'); }
}
try { $reply->submit(['estado' => 'Cerrado'] + $ticket, 'propietario', 'Hola', []); $check(false, 'rejects closed'); }
catch (DomainException) { $check(true, 'closed case rejects replies on the server too'); }
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_ticket') . '`') === 0, 'rejected replies write nothing');

// 1) Respuesta del propietario con WhatsApp aún apagado: solo correos individuales.
$result = $reply->submit($ticket, 'propietario', "Ya revisé.\n<script>alert(1)</script>", []);
$history = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_historial_del_ticket') . '`');
$check($history['nombre'] === 'Propietaria Ejemplo (Propietario)' && (string) $history['cct_author_id'] === '0' && $history['id_empleado'] === '', 'history records the external actor without a staff author');
$check(!str_contains($history['respuesta'], '<script>') && str_contains($history['respuesta'], '<br>'), 'reply text is escaped and keeps line breaks');
$after = $db->getRow('SELECT `estado`, `estado_administrativo` FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = 70501');
$check($after['estado'] === 'En proceso' && $after['estado_administrativo'] === 'En gestión', 'reply does not change case states');
$rows = $queue();
$check($result === ['email' => 2, 'whatsapp' => 0] && count($rows) === 2 && array_unique(array_column($rows, 'channel')) === ['email'], 'assignee and configured copy get one email each; WhatsApp waits for activation');
$check(array_column($rows, 'destination') === ['assignee@example.invalid', 'copy@example.invalid'], 'assignee is notified first, then the configured copy');
foreach ($rows as $row) {
  $payload = json_decode((string) $row['payload_json'], true);
  $check(($payload['cc'] ?? []) === [], 'autologin email to ' . $row['destination'] . ' has no copies');
  preg_match('/scm_case=([^"&]+)/', html_entity_decode((string) $row['message_html']), $match);
  $employee = $row['destination'] === 'assignee@example.invalid' ? '9002' : '9003';
  $check(($match[1] ?? '') !== '' && PublicCaseAccess::route($match[1], false) === ['mode' => 'login', 'id' => 70501, 'audience' => 'funcionario', 'employee_id' => $employee], 'email button logs in its own recipient ' . $employee);
  $check(!str_contains((string) $row['message_html'], '<script>') && str_contains((string) $row['message_html'], 'Propietaria Ejemplo'), 'email shows the escaped reply and who wrote it');
}

// 2) Con la plantilla activa: WhatsApp individual con autologin.
$settings(true);
$db->pdo()->exec('DELETE FROM `' . $queueTable . '`');
$result = $reply->submit($ticket, 'arrendatario', "Segunda\nrespuesta", []);
$whatsapps = array_values(array_filter($queue(), fn($row) => $row['channel'] !== 'email'));
$check($result === ['email' => 2, 'whatsapp' => 2] && count($whatsapps) === 2, 'with the reply template enabled, assignee and copy also get WhatsApp');
foreach ($whatsapps as $row) {
  $payload = json_decode((string) $row['payload_json'], true);
  $flat = json_encode($payload);
  $check(str_contains($flat, 'scm_caso_respuesta_v1') && !str_contains($flat, '\n'), 'WhatsApp uses the reply template without line breaks in variables');
  preg_match('/"text":"(\d+\.\d{10}\.funcionario\.\d+\.[a-f0-9]{64})"/', $flat, $match);
  $check(($match[1] ?? '') !== '' && PublicCaseAccess::route($match[1], false)['mode'] === 'login', 'WhatsApp button carries the signed autologin reference');
}

// 3) Respuesta desde el panel: WhatsApp a los actores marcados.
$db->pdo()->exec('DELETE FROM `' . $queueTable . '`');
$notifier = new CaseReplyNotifications($db);
$count = $notifier->notifyPanelResponse($ticket, 70501, '<p>Programamos la visita.</p>', 'Copia', 'En proceso', ['propietario', 'empleado', 'admin'], '9003', 'qa-panel-1');
$rows = $queue();
$destinations = array_column($rows, 'destination'); sort($destinations);
$check($count === 2 && $destinations === ['+573001112202', '+573001112210'], 'panel reply reaches the selected owner and the assignee (when someone else answers), not admin copies');
foreach ($rows as $row) {
  $flat = json_encode(json_decode((string) $row['payload_json'], true));
  $owner = $row['destination'] === '+573001112210';
  preg_match('/"text":"(\d+\.\d{10}\.[a-z]+\.(?:\d+\.)?[a-f0-9]{64})"/', $flat, $match);
  $route = PublicCaseAccess::route($match[1] ?? '', false);
  $check($owner ? $route === ['mode' => 'public', 'id' => 70501, 'audience' => 'propietario'] : $route['mode'] === 'login', $owner ? 'owner button opens the public view as propietario' : 'assignee button logs in');
  $check(str_contains($flat, 'Programamos la visita.') && !str_contains($flat, '<p>'), 'panel HTML response is sent as plain text');
}
$db->pdo()->exec('DELETE FROM `' . $queueTable . '`');
$check($notifier->notifyPanelResponse($ticket, 70501, 'Hola', 'Responsable', 'En proceso', ['empleado'], '9002', 'qa-panel-2') === 0, 'the assignee does not get a WhatsApp for their own reply');
$check($notifier->notifyPanelResponse($ticket, 70501, 'Hola', 'Responsable', 'En proceso', ['none'], '9003', 'qa-panel-3') === 0, 'no recipients selected sends nothing');
$settings(false);
$check($notifier->notifyPanelResponse($ticket, 70501, 'Hola', 'Responsable', 'En proceso', ['propietario'], '9003', 'qa-panel-4') === 0, 'disabled template sends no panel WhatsApp');

// 4) Historial público: incluye la respuesta del actor guardada arriba, sin contactos ni HTML ejecutable.
$publicHistory = SCM\Support\PublicCaseData::history($db, $ticket);
$check(count($publicHistory) === 2 && $publicHistory[0]['author'] === 'Propietaria Ejemplo (Propietario)' && $publicHistory[0]['external'], 'public history lists replies in order and marks external authors');
$check(str_contains($publicHistory[0]['text'], "Ya revisé.\n") && str_contains($publicHistory[0]['text'], '<script>'), 'stored reply is decoded back to the original plain text (escaped again by the view)');
$entries = SCM\Support\PublicCaseData::historyFromRows([
  ['nombre' => 'Funcionario', 'fecha' => '1790000000', 'correo' => 'SECRET_EMAIL', 'celular' => 'SECRET_PHONE', 'respuesta' => '<p>Hola</p><script>alert(1)</script>', 'imagen' => serialize(['https://evil.invalid/x.png', 'https://sucasainmobiliaria.com.co/wp-content/uploads/ok.png']), 'archivos' => serialize([['archivo' => 'https://evil.invalid/x.pdf']])],
  ['nombre' => '', 'respuesta' => '   ', 'imagen' => ''],
]);
$check(count($entries) === 1 && !str_contains(json_encode($entries), 'SECRET_') && !str_contains($entries[0]['text'], 'alert'), 'history omits contacts, scripts and empty rows');
$check(!$entries[0]['external'] && $entries[0]['images'] === ['https://sucasainmobiliaria.com.co/wp-content/uploads/ok.png'] && $entries[0]['documents'] === [], 'history attachments use the public allowlist');

// 5) Enlaces de los correos con copias y vista pública.
$check(PublicCaseAccess::route(explode('scm_case=', CaseReplyNotifications::emailCaseUrl(70501, 'arrendatario'))[1], false)['audience'] === 'arrendatario', 'tenant email link opens the tenant public view');
$check(PublicCaseAccess::route(explode('scm_case=', CaseReplyNotifications::emailCaseUrl(70501, 'admin'))[1], false) === ['mode' => 'public', 'id' => 70501, 'audience' => 'funcionario'], 'copied staff emails keep a plain staff link without autologin');
$render = static function (array $state, string $audience = 'propietario'): string {
  ob_start();
  (new SCM\Views\PublicCaseView())->render(SCM\Support\PublicCaseData::fromTicket(['_ID' => 70501, 'asunto' => 'Fuga', 'estado' => 'En proceso']), '', $audience, $state);
  return (string) ob_get_clean();
};
$html = $render(['enabled' => true, 'token' => 'tok"en', 'action' => 'https://example.invalid/?scm_case=1', 'error' => '<b>x</b>', 'text' => '</textarea><script>']);
$check(str_contains($html, 'data-reply-form') && str_contains($html, 'value="tok&quot;en"') && str_contains($html, 'enctype="multipart/form-data"'), 'public view renders the reply form with its CSRF token');
$check(!str_contains($html, '</textarea><script>') && !str_contains($html, '<b>x</b>'), 'reply form escapes previous text and errors');
$check(!str_contains($render(['enabled' => false, 'closed' => true]), 'data-reply-form') && str_contains($render(['enabled' => false, 'closed' => true]), 'Este caso está cerrado'), 'closed cases show a notice instead of the form');
$check(str_contains($render(['sent' => true]), 'Recibimos tu respuesta'), 'confirmation is shown after sending');
echo $checks . ' checks passed' . PHP_EOL;
