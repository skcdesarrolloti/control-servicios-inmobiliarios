<?php
declare(strict_types=1);

// Connection-local shadows and inert enqueue only: never runs SMTP or a live worker.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });
use SCM\Core\App;
use SCM\Core\Settings;
use SCM\Modules\TicketCompletion\CompletionDelivery;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Support\FuncionarioOptions;

$db = App::db();
$sharedRoot = (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications');
$config = require $sharedRoot . '/config.php';
$queueTable = (string) $config['queue']['queue_table'];
foreach ([$db->table('jet_cct_funcionarios'), $db->table('jet_cct_historial_del_ticket'), $db->table('scm_ticket_completion_acts'), $queueTable] as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Invalid fixture table'); }
  $definition = $db->getRow('SHOW CREATE TABLE `' . $table . '`');
  $sql = (string) ($definition['Create Table'] ?? '');
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) { throw new RuntimeException('Unexpected fixture schema'); }
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
// Settings are replaced in memory only; no configuration rows are written.
$settingsData = new ReflectionProperty(Settings::class, 'data');
$settingsData->setValue(App::settings(), [
  'internal_admin_notifications' => ['acta_firmada' => [101, 102, 103, 105]],
  FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY => ['3'],
]);
$fixtures = [
  [101, '200', 'Creador', 'CREATOR@example.invalid', 'Si'],
  [102, '201', 'Configurado', 'staff@example.invalid', 'Si'],
  [103, '202', 'Inactivo', 'inactive@example.invalid', 'No'],
  [104, '203', 'No seleccionado', 'unselected@example.invalid', 'Si'],
  [105, '204', 'Sin correo', 'correo-invalido', 'Si'],
];
foreach ($fixtures as [$id, $employee, $name, $email, $active]) {
  $db->insert($db->table('jet_cct_funcionarios'), ['_ID' => $id, 'id_empleado' => $employee, 'nombre' => $name, 'correo' => $email, 'activo' => $active, 'id_cargo' => '3']);
}
$repo = new CompletionRepository($db);
$secret = str_repeat('isolated-test-', 4);
$payload = [
  'ticket_number' => 'QA9001', 'contract' => '787', 'property' => '204578',
  'creator' => ['name' => 'Creador', 'email' => 'creator@example.invalid', 'employee_id' => '200'],
  'actor' => ['name' => 'Editor', 'email' => 'editor@example.invalid', 'employee_id' => '999'],
  'signer' => ['name' => 'Firmante', 'email' => 'signer@example.invalid'],
  'channels' => ['whatsapp'],
];
$json = json_encode($payload, JSON_THROW_ON_ERROR);
$hash = hash('sha256', $json);
$signedAt = time();
$evidence = ['document_hash' => $hash, 'signed_at' => $signedAt];
$evidence['evidence_hmac'] = hash_hmac('sha256', json_encode($evidence, JSON_THROW_ON_ERROR), $secret);
$db->insert($repo->table(), [
  'id' => 900001, 'ticket_pk' => 900001, 'status' => 'signed', 'payload_json' => $json,
  'payload_hash' => $hash, 'signed_at' => $signedAt, 'signed_json' => json_encode($evidence, JSON_THROW_ON_ERROR),
  'token_nonce' => str_repeat('a', 64), 'created_at' => time(), 'expires_at' => time() + 86400,
]);
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++; echo 'PASS: ' . $label . PHP_EOL;
};
$failStaff = true;
$service = new CompletionService($repo, $secret, 'https://example.invalid/panel', static function ($to, $subject, $html, $options) use ($db, &$failStaff): int {
  if ($failStaff && $to === 'staff@example.invalid') { return 0; }
  return CompletionDelivery::enqueue($db, $to, $subject, $html, $options);
});
$notify = new ReflectionMethod($service, 'notifySignedStaff');
$repo->audit(900002, 'Acta de satisfacción #900002 generada.', 'Creador', '200');
$oldPayload = $payload;
unset($oldPayload['creator']);
$original = (new ReflectionMethod($service, 'originalCreator'))->invoke($service, ['id' => 900002, 'ticket_pk' => 900002], $oldPayload);
$assert($original['employee_id'] === '200' && strtolower($original['email']) === 'creator@example.invalid', 'older edited act recovers original creator from creation audit');
$act = $repo->act(900001);
$results = $notify->invoke($service, $act);
$assert($results === ['creator@example.invalid' => true, 'staff@example.invalid' => false], 'creator and active configured recipients only; email case deduplicated');
$rows = $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$assert(count($rows) === 1 && $rows[0]['destination'] === 'creator@example.invalid', 'original creator receives email independently of WhatsApp invitation');
$assert($rows[0]['status'] === 'pending' && $rows[0]['source_module'] === 'ticket-completion' && json_decode($rows[0]['meta_json'], true)['event'] === 'acta_firmada', 'real shared queue retains pending internal event');
$assert(str_contains($rows[0]['message_html'], '204578') && str_contains($rows[0]['message_html'], '787') && !str_contains($rows[0]['message_html'], 'token='), 'internal link requires panel login and shows contract next to SIMI');
$failStaff = false;
$notify->invoke($service, $repo->act(900001));
$rows = $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$assert(count($rows) === 2 && $rows[1]['destination'] === 'staff@example.invalid', 'partial failure retries only missing configured recipient');
$notify->invoke($service, $repo->act(900001));
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '`') === 2, 'duplicate signature or resend does not duplicate internal notices');
$delivery = json_decode($repo->act(900001)['delivery_json'], true);
$assert(count($delivery['internal_signed_receipt']) === 2 && $delivery['internal_signed_receipt']['staff@example.invalid']['queued'], 'delivery trace saved independently per recipient');
$db->update($repo->table(), ['status' => 'pending'], ['id' => 900001]);
$assert($notify->invoke($service, $repo->act(900001)) === [], 'pending act never announces a signature');
$db->pdo()->exec('DELETE FROM `' . $queueTable . '`'); // This connection's temporary shadow only.
$db->update($repo->table(), ['status' => 'signed', 'delivery_json' => null], ['id' => 900001]);
$defaultService = new CompletionService($repo, $secret, 'https://example.invalid/panel');
$defaultResults = $notify->invoke($defaultService, $repo->act(900001));
$assert($defaultResults === ['creator@example.invalid' => true, 'staff@example.invalid' => true]
  && (int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '` WHERE status = \'pending\'') === 2,
  'default production internal adapter enqueues only without running SMTP');

// Upgrade a signed act that already notified by email: preserve those delivery keys.
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '+57 300 111 1111'], ['_ID' => 101]);
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '3002222222'], ['_ID' => 102]);
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '3003333333'], ['_ID' => 105]);
$payload['creator']['phone'] = '3001111111';
$json = json_encode($payload, JSON_THROW_ON_ERROR);
$hash = hash('sha256', $json);
$evidence = ['document_hash' => $hash, 'signed_at' => $signedAt];
$evidence['evidence_hmac'] = hash_hmac('sha256', json_encode($evidence, JSON_THROW_ON_ERROR), $secret);
$db->update($repo->table(), ['payload_json' => $json, 'payload_hash' => $hash, 'signed_json' => json_encode($evidence, JSON_THROW_ON_ERROR)], ['id' => 900001]);
$failWs = true;
$wsService = new CompletionService($repo, $secret, 'https://example.invalid/panel', static function ($to, $subject, $html, $options) use ($db, &$failWs): int {
  if ($failWs && $to === '+573002222222') { return 0; }
  return CompletionDelivery::enqueue($db, $to, $subject, $html, $options);
});
$notify->invoke($wsService, $repo->act(900001));
$wsRows = $db->getResults('SELECT * FROM `' . $queueTable . '` WHERE channel = \'whatsapp\' ORDER BY id');
$assert(count($wsRows) === 2 && array_column($wsRows, 'destination') === ['+573001111111', '+573003333333'], 'WhatsApp deduplicates creator/configured phone and includes selected staff without email');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '` WHERE channel = \'email\'') === 2, 'adding WhatsApp preserves already queued emails');
$template = json_decode(file_get_contents(dirname(__DIR__) . '/docs/whatsapp-acta-internal-signed-template.json'), true, 512, JSON_THROW_ON_ERROR);
$wsPayload = json_decode($wsRows[0]['payload_json'], true);
$assert($wsPayload['template_name'] === $template['name'] && $wsPayload['template_language'] === $template['language']
  && array_column($wsPayload['components'][0]['parameters'], 'text') === ['Creador', '900001', 'QA9001', 'Firmante', '787', '204578', 'https://example.invalid/panel/ticket-acta.php?id=900001'],
  'internal WhatsApp uses exact seven-variable template and authenticated staff URL');
$assert(!str_contains($wsRows[0]['payload_json'], 'token=') && $wsRows[0]['provider'] === 'whatsapp_official', 'official WhatsApp queue contains no public signing token');
$failWs = false;
$notify->invoke($wsService, $repo->act(900001));
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '` WHERE channel = \'whatsapp\'') === 3, 'failed WhatsApp retry queues only missing phone');
$notify->invoke($wsService, $repo->act(900001));
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '`') === 5, 'repeat notification does not duplicate either channel');
$wsDelivery = json_decode($repo->act(900001)['delivery_json'], true);
$assert($wsDelivery['internal_signed_receipt']['whatsapp:+573002222222']['queued'], 'WhatsApp delivery status tracked independently from email');
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '3004444444'], ['_ID' => 105]);
$notify->invoke($defaultService, $repo->act(900001));
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '` WHERE channel = \'whatsapp\' AND destination = \'+573004444444\' AND status = \'pending\'') === 1,
  'default production internal adapter queues official WhatsApp without dispatching a provider');
echo "$checks checks passed; temporary tables only, no real messages sent.\n";
