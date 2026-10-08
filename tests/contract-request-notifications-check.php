<?php
declare(strict_types=1);

// All writes target connection-local temporary shadows. Providers are inert.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
$db = SCM\Core\App::db();
$sharedRoot = getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications';
$config = require $sharedRoot . '/config.php';
$queueTable = $config['queue']['queue_table'];
$attemptsTable = $config['queue']['attempts_table'];
foreach ([$db->table('jet_cct_funcionarios'), $queueTable, $attemptsTable] as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid table');
  $definition = $db->getRow('SHOW CREATE TABLE `' . $table . '`');
  $sql = $definition['Create Table'] ?? '';
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) throw new RuntimeException('Missing fixture schema');
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
(new ReflectionProperty(SCM\Core\Settings::class, 'data'))->setValue(SCM\Core\App::settings(), [
  'internal_admin_notifications' => ['terminacion_contrato' => [901, 903], 'no_prorroga_contrato' => [902]],
  SCM\Support\FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY => ['3'],
]);
foreach ([[901, 'Terminación', 'termination@example.invalid', '3001112201', 'Si'], [902, 'No prórroga', 'renewal@example.invalid', '3001112202', 'Si'], [903, 'Inactivo', 'inactive@example.invalid', '3001112203', 'No']] as [$id, $name, $email, $phone, $active]) {
  $db->insert($db->table('jet_cct_funcionarios'), ['_ID' => $id, 'id_empleado' => (string) $id, 'nombre' => $name, 'correo' => $email, 'celular' => $phone, 'activo' => $active, 'id_cargo' => '3']);
}
$app = new SCM\App\SuCasaControlServiciosInmobiliarios($db);
$invoke = static fn(string $method, ...$args) => (new ReflectionMethod($app, $method))->invoke($app, ...$args);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++; echo 'PASS: ' . $label . PHP_EOL;
};
$ticket = ['id_ticket' => 'QA10916', 'contrato' => '2000', 'inmueble' => '204578', 'direccion' => 'Dirección de ejemplo', 'arrendatario' => 'Arrendatario', 'propietario' => 'Propietario', 'correo_arrendatario' => 'tenant@example.invalid', 'correo_propietario' => 'owner@example.invalid', 'celular_arrendatario' => '3001112233', 'celular_propietario' => '3001112244'];
$targets = ['arrendatario', 'propietario', 'admin'];
$rows = static fn() => $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$clear = static fn() => $db->pdo()->exec('DELETE FROM `' . $queueTable . '`');
$url = 'https://example.invalid/uploads/acta-qa-1.pdf';
foreach (['notifyContractTerminationActa' => 'terminacion_contrato', 'notifyContractNonRenewalActa' => 'no_prorroga_contrato'] as $method => $action) {
  $clear();
  $result = $invoke($method, $ticket, 'dentro', 'Respuesta de ejemplo', $url, $targets, 'Funcionario');
  $check($result['email'] === 3 && $result['whatsapp'] === 2, "$action preserves legacy routing: both parties on both channels, internal email only");
  $options = $invoke('contractTerminationRecipientOptions', $ticket, $action);
  $check($options[2]['name'] === ($action === 'terminacion_contrato' ? 'Terminación' : 'No prórroga') && $options[2]['default_channels'] === ['email'], "$action shows configured active staff and email default");
  $clear();
  $channels = ['arrendatario' => ['whatsapp'], 'propietario' => ['email'], 'admin' => ['email', 'whatsapp']];
  $result = $invoke($method, $ticket, 'dentro', 'Respuesta de ejemplo', $url, $targets, 'Funcionario', $channels);
  $check($result['email'] === 2 && $result['whatsapp'] === 2, "$action respects separate recipient channels including internal WhatsApp opt-in");
  $destinations = array_column($rows(), 'destination');
  $check(!in_array('tenant@example.invalid', $destinations) && !in_array('+573001112244', $destinations), "$action never queues excluded party channels");
  $check(in_array($action === 'terminacion_contrato' ? '+573001112201' : '+573001112202', $destinations), "$action resolves its own internal event, not the other event");
  $check(count(array_filter($rows(), static fn($r) => $r['status'] === 'pending')) === 4, "$action uses real shared pending queue");
  $originalKeys = array_column($rows(), 'dedupe_key');
  $invoke($method, $ticket, 'dentro', 'Respuesta nueva', str_replace('-1.', '-2.', $url), $targets, 'Funcionario', $channels);
  $check(count($rows()) === 8, "$action reopened case can notify a new act with same classification");
  $check(array_intersect($originalKeys, array_column(array_slice($rows(), 4), 'dedupe_key')) === [], "$action distinguishes a new act from its previous response");
  $clear();
  $invoke($method, $ticket, 'dentro', 'Respuesta', $url, ['none', 'arrendatario'], 'Funcionario', $channels);
  $check($rows() === [], "$action no-notification selection takes precedence");
}
$check($invoke('contractRequestSelectedChannels', $targets, null) === null, 'legacy API requests retain historical defaults');
$check($invoke('contractRequestSelectedChannels', ['none'], '{}') === [], 'none accepts an empty channel map');
foreach (['broken-json', '{"arrendatario":[]}', '{"arrendatario":["sms"]}'] as $raw) {
  try { $invoke('contractRequestSelectedChannels', ['arrendatario'], $raw); $check(false, 'invalid channels rejected'); }
  catch (InvalidArgumentException $e) { $check(true, 'invalid or empty channels rejected before business writes'); }
}
$dedupTicket = array_merge($ticket, ['celular_propietario' => '+57 300 111 2233']);
$check(count($invoke('contractTerminationNotificationPhones', $dedupTicket, ['arrendatario', 'propietario'])) === 1, 'local and international formats deduplicate to one WhatsApp destination');
$badOptions = $invoke('contractTerminationRecipientOptions', array_merge($ticket, ['correo_arrendatario' => 'bad', 'celular_arrendatario' => '12']));
$check(!$badOptions[0]['available'], 'invalid contacts are disabled in response UI');
try {
  $invoke('validateContractRequestNotificationChannels', array_merge($ticket, ['correo_arrendatario' => '']), ['arrendatario'], ['arrendatario' => ['email']], 'terminacion_contrato');
  $check(false, 'missing contact must reject selected channel');
} catch (InvalidArgumentException $e) { $check(true, 'contact removed after opening modal is rejected before generating act or closing case'); }
// Force one channel to fail in this connection's temporary queue only.
$db->pdo()->exec("ALTER TABLE `{$queueTable}` ADD CONSTRAINT qa_contract_email CHECK (channel <> 'email')");
$failure = $invoke('notifyContractTerminationActa', $ticket, 'dentro', 'Respuesta', $url, ['arrendatario'], 'Funcionario', ['arrendatario' => ['email', 'whatsapp']]);
$check($failure['failed'] === 1 && $failure['email'] === 0 && $failure['whatsapp'] === 1, 'partial enqueue failure is reported without losing the other channel');
$db->pdo()->exec("ALTER TABLE `{$queueTable}` DROP CONSTRAINT qa_contract_email");
$clear();

$invoke('notifyContractTerminationActa', $ticket, 'dentro', 'Respuesta', $url, ['arrendatario'], 'Funcionario', ['arrendatario' => ['email', 'whatsapp']]);
require_once $sharedRoot . '/autoload.php';
final class ContractRequestTestProvider implements SharedNotifications\Contracts\ProviderInterface {
  public function __construct(private string $provider) {}
  public function code(): string { return $this->provider; }
  public function send(array $notification): array { return ['ok' => true, 'http_code' => 200, 'response' => ['simulated' => true]]; }
}
$providers = (new SharedNotifications\Providers\ProviderRegistry())->add(new ContractRequestTestProvider('email_smtp'))->add(new ContractRequestTestProvider('whatsapp_official'));
$queueConfig = new SharedNotifications\Config\QueueConfig($queueTable, $attemptsTable);
$worker = new SharedNotifications\NotificationWorker(new SharedNotifications\Storage\PdoStorageAdapter($db->pdo()), $providers, $queueConfig);
// Avoid workstation/database clock skew; only these temporary fixture jobs.
$db->pdo()->exec("UPDATE `{$queueTable}` SET scheduled_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
$check($worker->run(10)['sent'] === 2, 'inert shared worker processes both response channels');
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $attemptsTable . '`') === 2, 'shared queue retains delivery attempts');
echo "OK: {$checks} contract notification checks; no external messages sent." . PHP_EOL;
