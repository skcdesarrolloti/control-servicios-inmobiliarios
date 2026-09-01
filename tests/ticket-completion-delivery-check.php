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
$whatsapp = array_replace($base, ['channel' => 'whatsapp', 'dedupe_key' => 'acta-qa-whatsapp']);
putenv('SCM_ACTA_WHATSAPP_OTP_TEMPLATE');
putenv('SCM_ACTA_WHATSAPP_OTP_LANGUAGE=es_CO');
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $whatsapp) === 1, 'default exact authentication template enqueued');
putenv('SCM_ACTA_WHATSAPP_INVITATION_TEMPLATE');
putenv('SCM_ACTA_WHATSAPP_RECEIPT_TEMPLATE');
putenv('SCM_ACTA_WHATSAPP_LANGUAGE=es_MX');
$invite = array_replace($whatsapp, ['dedupe_key' => 'acta-qa-invite', 'meta' => ['event' => 'signature_invitation', 'act_id' => 999], 'otp_code' => '', 'message_text' => 'Enlace de prueba https://example.invalid/acta', 'ticket_number' => '10368', 'act_url' => 'https://example.invalid/acta?id=999&token=FICTICIO']);
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $invite) === 1, 'default exact invitation template enqueued');
putenv('SCM_ACTA_WHATSAPP_INVITATION_TEMPLATE=scm_acta_solicitud_firma_v1');
$receipt = array_replace($invite, ['dedupe_key' => 'acta-qa-receipt', 'meta' => ['event' => 'signed_receipt', 'act_id' => 999], 'act_url' => $invite['act_url'] . '&format=pdf']);
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $receipt) === 1, 'default exact signed PDF receipt template enqueued');
$invite['dedupe_key'] .= '-dedicated';
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $invite) === 1, 'dedicated invitation enqueued');
putenv('SCM_ACTA_WHATSAPP_RECEIPT_TEMPLATE=scm_acta_firmada_v1');
$receipt['dedupe_key'] .= '-dedicated';
$assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', $receipt) === 1, 'dedicated signed PDF receipt enqueued');
foreach ([['act_url' => ''], ['act_url' => 'javascript:alert(1)'], ['ticket_number' => ''], ['meta' => ['event' => 'unknown']]] as $invalid) {
  $assert(\SCM\Modules\TicketCompletion\CompletionDelivery::enqueue($db, '+573000000000', 'QA', '', array_replace($invite, $invalid)) === 0, 'invalid dedicated payload rejected: ' . key($invalid));
}
$rows = $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$assert(count($rows) === 6 && count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'pending')) === 6, 'six pending jobs without invalid payloads or live jobs');
$otp = json_decode($rows[1]['payload_json'], true);
$assert($otp['template_name'] === 'scm_acta_firma_otp_v1' && $otp['components'][0]['parameters'][0]['text'] === '123456' && $otp['components'][1]['sub_type'] === 'url' && $otp['components'][1]['parameters'][0]['text'] === '123456', 'authentication body and copy-code button agree');
$assert($otp['template_language'] === 'es_CO', 'OTP keeps its independently configured language');
foreach ([2 => ['invitation', $invite], 3 => ['receipt', $receipt]] as $index => [$kind, $options]) {
  $payload = json_decode($rows[$index]['payload_json'], true);
  $definition = json_decode(file_get_contents(dirname(__DIR__) . '/docs/whatsapp-acta-' . $kind . '-template.json'), true, 512, JSON_THROW_ON_ERROR);
  $assert($payload['template_name'] === $definition['name'] && $payload['template_language'] === 'es_MX' && array_column($payload['components'][0]['parameters'], 'text') === ['QA', '10368', $options['act_url']], 'default ' . $kind . ' uses exact documented contract');
}
foreach ([4 => ['invitation', $invite], 5 => ['receipt', $receipt]] as $index => [$kind, $options]) {
  $payload = json_decode($rows[$index]['payload_json'], true);
  $definition = json_decode(file_get_contents(dirname(__DIR__) . '/docs/whatsapp-acta-' . $kind . '-template.json'), true, 512, JSON_THROW_ON_ERROR);
  $assert($payload['template_name'] === $definition['name'] && $payload['template_language'] === 'es_MX', 'dedicated ' . $kind . ' matches documented name and configured language');
  $assert(count($payload['components']) === 1 && array_column($payload['components'][0]['parameters'], 'text') === ['QA', '10368', $options['act_url']], 'dedicated ' . $kind . ' sends name, ticket and intact personal link without extra buttons');
  preg_match_all('/\{\{\d+\}\}/', $definition['components'][0]['text'], $placeholders);
  $assert($definition['category'] === 'UTILITY' && $placeholders[0] === ['{{1}}', '{{2}}', '{{3}}'] && count($definition['components'][0]['example']['body_text'][0]) === 3, 'documented ' . $kind . ' has matching positional variables and examples');
}
$assert(!str_contains($rows[0]['meta_json'], '123456') && !str_contains($rows[1]['meta_json'], '123456'), 'OTP is not duplicated in business metadata');
$assert(!str_contains($rows[4]['meta_json'], 'FICTICIO') && !str_contains($rows[5]['meta_json'], 'FICTICIO'), 'personal bearer link is not duplicated in business metadata');
$assert($rows[4]['dedupe_key'] === $invite['dedupe_key'] . ':+573000000000' && $rows[5]['source_module'] === 'ticket-completion', 'dedicated messages preserve event tracking keys and module');
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
$stats = $worker->run(6);
$assert($stats['sent'] === 6, 'worker processes all jobs using inert providers');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $attemptsTable . '` WHERE attempt_status = \'sent\'') === 6, 'delivery attempts recorded in temporary table');
echo "$checks checks passed. No external messages sent; all queue/attempt rows are temporary.\n";
