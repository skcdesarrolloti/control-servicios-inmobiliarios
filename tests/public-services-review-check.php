<?php

declare(strict_types=1);

// All DB writes use connection-local TEMPORARY shadows. Never run a real transport provider.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });

$db = \SCM\Core\App::db();
$package = (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications');
require_once $package . '/autoload.php';
$queueConfig = require $package . '/config.php';
$queueTable = (string) ($queueConfig['queue']['queue_table'] ?? 'skc_notification_queue');
$attemptsTable = (string) ($queueConfig['queue']['attempts_table'] ?? 'skc_notification_attempts');
$tables = array_map([$db, 'table'], ['jet_cct_contratos_arrendamiento', 'jet_cct_funcionarios', 'jet_cct_sucursales', 'jet_cct_revisiones_servicios', 'jet_cct_historial_del_inmueble', 'jet_cct_confi_sistema', 'posts', 'postmeta']);
$tables[] = $queueTable;
$tables[] = $attemptsTable;
foreach ($tables as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Invalid table name.'); }
  $definition = $db->getRow('SHOW CREATE TABLE `' . $table . '`');
  $sql = (string) ($definition['Create Table'] ?? '');
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) { throw new RuntimeException('Unexpected schema.'); }
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++; echo 'PASS: ' . $label . PHP_EOL;
};
$contractTable = $db->table('jet_cct_contratos_arrendamiento');
$employeeTable = $db->table('jet_cct_funcionarios');
$reviewTable = $db->table('jet_cct_revisiones_servicios');
$historyTable = $db->table('jet_cct_historial_del_inmueble');
$db->insert($employeeTable, ['_ID' => 70001, 'id_empleado' => '94001', 'nombre' => 'Funcionario autenticado QA', 'correo' => 'actor@example.invalid', 'activo' => 'Si']);
$db->insert($employeeTable, ['_ID' => 70002, 'id_empleado' => '70001', 'nombre' => 'Gloria QA - señuelo', 'correo' => 'decoy@example.invalid', 'activo' => 'Si']);
$db->insert($employeeTable, ['_ID' => 70003, 'id_empleado' => '', 'nombre' => 'Funcionario incompleto QA', 'activo' => 'Si']);
$db->insert($employeeTable, ['_ID' => 70004, 'id_empleado' => '94004', 'nombre' => 'Administracion configurada QA', 'correo' => 'admin-config@example.invalid', 'id_cargo' => '3', 'activo' => 'Si']);
\SCM\Core\App::settings()->set('internal_admin_notifications', ['acta_servicios_publicos' => ['70004']], 70001);
\SCM\Core\App::settings()->refresh();
$base = ['_ID' => 90001, 'contrato' => '2000', 'estado' => 'Entregado', 'id_inmueble' => '80001', 'inmueble' => '204578', 'direccion' => 'Dirección de prueba', 'arrendatario' => 'Arrendatario QA', 'propietario' => 'Propietario QA', 'correo_propietario' => 'owner@example.invalid', 'correo_arrendatario' => 'tenant@example.invalid', 'servicios_publicos' => '', 'mes_revision_servicios' => '11', 'revisiones_servicios' => '4', 'ultima_revision_servicios' => 1700000000];
$db->insert($contractTable, $base);
$db->insert($contractTable, array_replace($base, ['_ID' => 90002, 'contrato' => '90001', 'servicios_publicos' => serialize([]), 'gas' => 'OLD-ACCOUNT']));
$_SESSION['scm_user_id'] = 70001;
$_SESSION['scm_user'] = 'Session name must not replace database identity';
$repo = new \SCM\Modules\Pending\PendingRepository($db);
$service = new \SCM\Modules\Pending\PendingService($repo);
$view = new \SCM\Modules\Pending\PendingView();
$controller = new \SCM\Modules\Pending\PendingController($service, $view);
$context = $service->buildServiciosPublicosReviewContext(90001);
$assert(!empty($context['ok']) && $context['contract']['contrato'] === '2000', 'contract lookup uses exact _ID, not colliding business code');
$assert($context['employee']['id_empleado'] === '94001' && $context['employee']['nombre'] === 'Funcionario autenticado QA', 'actor resolves session _ID to its own id_empleado, not Gloria decoy');
$assert(count($context['services']) === 3 && !$context['has_services'], 'empty contract can configure all three services');
$html = $view->renderServiciosPublicosReviewForm($context);
$assert(str_contains($html, '94001') && !str_contains($html, 'Gloria QA') && !str_contains($html, '¿Qué deseas guardar?') && !str_contains($html, 'name="servicios[]" value="energia" checked'), 'form shows authenticated employee and infers configuration-only when no review is selected');
$listing = $controller->buildServiciosPublicosPayload([]);
$assert($listing['count'] === 0 && count($listing['configuration_items']) === 2, 'unconfigured and explicitly empty services are separate from pending KPI');
$assert(str_contains($view->renderServiciosPublicosTable($listing['items'], $listing['configuration_items']), 'Configurar servicios'), 'unconfigured contracts remain editable');
$input = ['configuration_present' => '1', 'servicios_configurados' => ['energia', 'agua'], 'nic' => 'NIC-QA', 'medidor_luz' => 'METER-QA', 'poliza' => 'POLIZA-QA', 'medidor_agua' => 'WATER-METER', 'id_empleado' => '70001', 'realizado_por' => 'FORGED'];
$saved = $service->saveServiciosPublicosConfiguration(90001, $input);
$assert(!empty($saved['ok']), 'configuration-only saves account and meter corrections');
$contract = $repo->getPublicServicesContract(90001);
$assert(unserialize($contract['servicios_publicos']) === ['Energia', 'Agua'] && $contract['agua'] === 'POLIZA-QA', 'configuration persists legacy-compatible service list and identifiers');
$assert((int) $contract['ultima_revision_servicios'] === 1700000000 && (int) $contract['mes_revision_servicios'] === 11 && (int) $contract['revisiones_servicios'] === 4, 'configuration does not advance review date, month or count');
$assert((int) $db->getVar("SELECT COUNT(*) FROM `{$reviewTable}`") === 0 && (int) $db->getVar("SELECT COUNT(*) FROM `{$queueTable}`") === 0, 'configuration creates neither reviews nor emails');
$history = $db->getRow("SELECT * FROM `{$historyTable}` ORDER BY `_ID` DESC LIMIT 1");
$assert($history['id_empleado'] === '94001' && $history['funcionario'] === 'Funcionario autenticado QA', 'configuration history ignores forged actor fields');
$listing = $controller->buildServiciosPublicosPayload([]);
$assert($listing['count'] === 1 && count($listing['configuration_items']) === 1, 'configured contract moves into review list');
$assert(empty($service->saveServiciosPublicosConfiguration(90001, [])['ok']), 'stale form cannot silently clear configuration');
$assert(empty($service->saveServiciosPublicosConfiguration(90001, array_replace($input, ['servicios_configurados' => ['invalid']]))['ok']), 'unknown configured service rejected');
$reviewInput = $input + ['servicios' => ['energia'], 'resultado_tiempo_luz' => 'Al dia', 'resultado_valores_luz' => '0'];
$assert(empty($service->createServiciosPublicosReview(90001, array_replace($reviewInput, ['servicios' => ['gas']]))['ok']), 'review cannot include a disabled service');
$assert(empty($service->createServiciosPublicosReview(90001, array_replace($reviewInput, ['medidor_luz' => '']))['ok']), 'review requires meter while configuration may remain incomplete');
$assert(empty($service->createServiciosPublicosReview(90001, array_replace($reviewInput, ['resultado_tiempo_luz' => '30 dias']))['ok']), 'overdue result requires positive amount');
$_SESSION['scm_user_id'] = 0;
$assert(empty($service->buildServiciosPublicosReviewContext(90001)['ok']), 'unknown actor fails closed');
$_SESSION['scm_user_id'] = 70003;
$assert(empty($service->buildServiciosPublicosReviewContext(90001)['ok']), 'actor without id_empleado fails closed');
$_SESSION['scm_user_id'] = 70001;
$db->pdo()->beginTransaction();
$assert(empty($service->createServiciosPublicosReview(90001, $reviewInput)['ok']), 'nested transaction cannot enqueue uncommitted reviews');
$db->pdo()->rollBack();
$generatedPaths = [];
try {
  $result = $service->createServiciosPublicosReview(90001, $reviewInput);
  foreach ($result['documents'] ?? [] as $document) {
    parse_str((string) parse_url($document['url'], PHP_URL_QUERY), $query);
    $path = \SCM\Support\StoredFileService::fromRuntime()->pathFor((string) ($query['n'] ?? ''));
    if ($path !== null) { $generatedPaths[] = $path; }
  }
  $assert(!empty($result['ok']) && count($generatedPaths) === 1, 'review generates only the selected service PDF');
  $review = $db->getRow("SELECT * FROM `{$reviewTable}` WHERE `_ID` = ?", [$result['review_id']]);
  $assert($review['id_empleado'] === '94001' && $review['realizado_por'] === 'Funcionario autenticado QA' && (string) $review['cct_author_id'] === '94001', 'review stores correct employee identity and CCT author');
  $assert($review['resultado_tiempo_agua'] === '' && $review['acta_felicitaciones_agua'] === '', 'unreviewed configured service gets no fake result or PDF');
  $contract = $repo->getPublicServicesContract(90001);
  $assert((int) $contract['mes_revision_servicios'] === 2 && (int) $contract['revisiones_servicios'] === 5 && (int) $contract['ultima_revision_servicios'] > 1700000000, 'review applies November-to-February formula and updates count/date');
  $assert($contract['id_empleado'] === '94001' && $contract['realizado_por'] === 'Funcionario autenticado QA', 'contract stores authenticated employee');
  $rows = $db->getResults("SELECT * FROM `{$queueTable}` ORDER BY id");
  $assert(count($rows) === 3 && count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'pending')) === 3, 'emails enqueue owner, tenant and configured internal recipient only');
  $queuedDestinations = array_map(static fn(array $row): string => strtolower((string) ($row['destination'] ?? '')), $rows);
  $assert(in_array('admin-config@example.invalid', $queuedDestinations, true) && !in_array('gcorrearivera@gmail.com', $queuedDestinations, true), 'internal review email uses configuration instead of fixed legacy addresses');
  $payload = json_decode($rows[0]['payload_json'], true);
  $assert($payload['reply_to'] === 'actor@example.invalid' && $payload['attachments'][0]['path'] === $generatedPaths[0], 'reply-to and attachment belong to correct actor/review');
  $registry = new \SharedNotifications\Providers\ProviderRegistry();
  $registry->add(new class implements \SharedNotifications\Contracts\ProviderInterface {
    public function code(): string { return 'email_smtp'; }
    public function send(array $notification): array { return ['ok' => true, 'http_code' => 200, 'response' => ['synthetic' => true]]; }
  });
  $worker = new \SharedNotifications\NotificationWorker(new \SharedNotifications\Storage\PdoStorageAdapter($db->pdo()), $registry, new \SharedNotifications\Config\QueueConfig($queueTable, $attemptsTable), 'public-services-isolated-qa');
  $stats = $worker->run(6);
  $assert($stats['sent'] === 3 && (int) $db->getVar("SELECT COUNT(*) FROM `{$attemptsTable}`") === 3, 'inert worker records successful attempts without sending messages');
  $saved = $service->saveServiciosPublicosConfiguration(90001, ['configuration_present' => '1', 'servicios_configurados' => []]);
  $context = $service->buildServiciosPublicosReviewContext(90001);
  $assert(!empty($saved['ok']) && !$context['has_services'] && $context['services']['energia']['account'] === 'NIC-QA', 'removing all services excludes pending but preserves historical identifiers');
  echo "$checks checks passed. Permanent rows unchanged; no external messages sent.\n";
} finally {
  foreach ($generatedPaths as $path) { if (is_file($path)) { unlink($path); } }
}
