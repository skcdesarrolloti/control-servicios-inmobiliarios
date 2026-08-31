<?php

declare(strict_types=1);

// Exercises the real bridge/queue and worker using TEMPORARY shadow tables and inert providers.
// NEVER invoke the configured worker or providers from this test.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });
$root = (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications');
$config = require $root . '/config.php';
require_once $root . '/autoload.php';
$db = \SCM\Core\App::db();
$queueTable = (string) ($config['queue']['queue_table'] ?? 'skc_notification_queue');
$attemptsTable = (string) ($config['queue']['attempts_table'] ?? 'skc_notification_attempts');
foreach ([$queueTable, $attemptsTable] as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Invalid table name.'); }
  // Read only the permanent structure. All subsequent operations use its empty connection-local shadow.
  $definition = $db->getRow('SHOW CREATE TABLE `' . $table . '`');
  $sql = (string) ($definition['Create Table'] ?? '');
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) { throw new RuntimeException('Unexpected queue schema.'); }
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++; echo 'PASS: ' . $label . PHP_EOL;
};
$base = ['destination_name' => 'QA', 'source_module' => 'ticket-completion', 'dedupe_key' => 'acta-qa-email', 'channel' => 'email', 'provider' => 'email_smtp', 'priority' => 200, 'meta' => ['event' => 'signature_otp', 'act_id' => 999], 'otp_code' => '123456', 'message_text' => 'Código ficticio 123456'];
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, 'qa@example.invalid', 'QA', '<p>Código ficticio 123456</p>', $base) === 1, 'real email bridge queues into temporary table');
putenv('SCM_ACTA_WHATSAPP_OTP_TEMPLATE=acta_qa_auth');
$whatsapp = array_replace($base, ['channel' => 'whatsapp', 'dedupe_key' => 'acta-qa-whatsapp']);
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $whatsapp) === 1, 'real WhatsApp bridge queues authentication template');
$invite = array_replace($whatsapp, ['dedupe_key' => 'acta-qa-invite', 'meta' => ['event' => 'signature_invitation', 'act_id' => 999], 'otp_code' => '', 'message_text' => 'Enlace de prueba https://example.invalid/acta']);
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $invite) === 1, 'WhatsApp invitation queues general template');
$rows = $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$assert(count($rows) === 3 && count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'pending')) === 3, 'three pending jobs, no live jobs included');
$otp = json_decode($rows[1]['payload_json'], true);
$assert($otp['template_name'] === 'acta_qa_auth' && $otp['components'][1]['sub_type'] === 'url' && $otp['components'][1]['parameters'][0]['text'] === '123456', 'authentication body and copy-code button agree');
$assert(json_decode($rows[2]['payload_json'], true)['template_name'] === 'scm_notificacion_general_v1', 'invitation reuses existing general template');
$assert(!str_contains($rows[0]['meta_json'], '123456') && !str_contains($rows[1]['meta_json'], '123456'), 'OTP is not duplicated in business metadata');
$assert((int) $rows[0]['priority'] === 200 && (int) $rows[1]['priority'] === 200, 'OTP prioritised above ordinary messages');
$registry = new \SharedNotifications\Providers\ProviderRegistry();
foreach (['email_smtp', 'whatsapp_official'] as $providerCode) {
  $registry->add(new class($providerCode) implements \SharedNotifications\Contracts\ProviderInterface {
    public function __construct(private string $providerCode) {}
    public function code(): string { return $this->providerCode; }
    public function send(array $notification): array { return ['ok' => true, 'http_code' => 200, 'response' => ['synthetic' => true]]; }
  });
}
$worker = new \SharedNotifications\NotificationWorker(new \SharedNotifications\Storage\PdoStorageAdapter($db->pdo()), $registry, new \SharedNotifications\Config\QueueConfig($queueTable, $attemptsTable), 'acta-isolated-qa');
$stats = $worker->run(3);
$assert($stats['sent'] === 3, 'worker processes all jobs using inert providers');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $attemptsTable . '` WHERE attempt_status = \'sent\'') === 3, 'delivery attempts recorded in temporary table');
echo "$checks checks passed. No external messages sent; all queue/attempt rows are temporary.\n";
