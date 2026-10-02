<?php

declare(strict_types=1);

// All DB writes use connection-local TEMPORARY shadows. Never run a real transport provider.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });

$db = \SCM\Core\App::db();
// Validate permanent schemas before TEMPORARY shadows hide engine metadata in MySQL.
(new \SCM\Modules\Pending\PublicServicesReviewStorage($db))->requireSchema();
$package = (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications');
require_once $package . '/autoload.php';
$queueConfig = require $package . '/config.php';
$queueTable = (string) ($queueConfig['queue']['queue_table'] ?? 'skc_notification_queue');
$attemptsTable = (string) ($queueConfig['queue']['attempts_table'] ?? 'skc_notification_attempts');
$tables = array_map([$db, 'table'], ['jet_cct_contratos_arrendamiento', 'jet_cct_funcionarios', 'jet_cct_cargos', 'jet_cct_sucursales', 'jet_cct_revisiones_servicios', 'jet_cct_historial_del_inmueble', 'jet_cct_confi_sistema', 'posts', 'postmeta', 'scm_public_services_reviews']);
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
$cargoTable = $db->table('jet_cct_cargos');
$reviewTable = $db->table('jet_cct_revisiones_servicios');
$historyTable = $db->table('jet_cct_historial_del_inmueble');
$db->insert($cargoTable, ['_ID' => 3, 'nombre_cargo' => 'Coordinador QA']);
$db->insert($employeeTable, ['_ID' => 70001, 'id_empleado' => '94001', 'nombre' => 'Funcionario autenticado QA', 'correo' => 'actor@example.invalid', 'celular' => '3001234567', 'id_cargo' => '3', 'activo' => 'Si']);
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
$input = ['request_token' => $context['request_token'], 'configuration_present' => '1', 'servicios_configurados' => ['energia', 'agua'], 'nic' => 'NIC-QA', 'medidor_luz' => 'METER-QA', 'poliza' => 'POLIZA-QA', 'medidor_agua' => 'WATER-METER', 'id_empleado' => '70001', 'realizado_por' => 'FORGED'];
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
  if (empty($result['ok'])) fwrite(STDERR, (string)($result['message']??'Review failed') . "\n");
  foreach ($result['documents'] ?? [] as $document) {
    parse_str((string) parse_url($document['url'], PHP_URL_QUERY), $query);
    $path = \SCM\Support\StoredFileService::fromRuntime()->pathFor((string) ($query['n'] ?? ''));
    if ($path !== null) { $generatedPaths[] = $path; }
  }
  $assert(!empty($result['ok']) && count($generatedPaths) === 1, 'review generates only the selected service PDF');
  $pdfText = (new \Smalot\PdfParser\Parser())->parseFile($generatedPaths[0])->getText();
  $assert(str_contains($pdfText, 'Coordinador QA') && str_contains($pdfText, '3001234567'), 'review PDF includes reviewer cargo and phone');
  $review = $db->getRow("SELECT * FROM `{$reviewTable}` WHERE `_ID` = ?", [$result['review_id']]);
  $assert($review['id_empleado'] === '94001' && $review['realizado_por'] === 'Funcionario autenticado QA' && (string) $review['cct_author_id'] === '94001', 'review stores correct employee identity and CCT author');
  $assert($review['resultado_tiempo_agua'] === '' && $review['acta_felicitaciones_agua'] === '', 'unreviewed configured service gets no fake result or PDF');
  $contract = $repo->getPublicServicesContract(90001);
  $expectedDue = \SCM\Modules\Pending\PublicServicesSchedule::next((int) $contract['ultima_revision_servicios']);
  $assert((int) $contract['mes_revision_servicios'] === (int) date('n', $expectedDue) && (int) $contract['revisiones_servicios'] === 5 && (int) $contract['ultima_revision_servicios'] > 1700000000, 'review schedules three months from actual date despite stale configured November');
  $assert($contract['id_empleado'] === '94001' && $contract['realizado_por'] === 'Funcionario autenticado QA', 'contract stores authenticated employee');
  $rows = $db->getResults("SELECT * FROM `{$queueTable}` ORDER BY id");
  $assert(count($rows) === 3 && count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'pending')) === 3, 'emails enqueue owner, tenant and configured internal recipient only');
  $queuedDestinations = array_map(static fn(array $row): string => strtolower((string) ($row['destination'] ?? '')), $rows);
  $assert(in_array('admin-config@example.invalid', $queuedDestinations, true) && !in_array('gcorrearivera@gmail.com', $queuedDestinations, true), 'internal review email uses configuration instead of fixed legacy addresses');
  $payload = json_decode($rows[0]['payload_json'], true);
  $assert($payload['reply_to'] === 'actor@example.invalid' && $payload['attachments'][0]['path'] === $generatedPaths[0], 'reply-to and attachment belong to correct actor/review');
  $assert(str_contains($rows[0]['message_html'], 'revision-servicios-publicos.php?numero=') && str_contains($rows[0]['message_html'], 'expires='), 'email uses native expiring public review URL');
  $replay = $service->createServiciosPublicosReview(90001, $reviewInput);
  $assert(!empty($replay['ok']) && !empty($replay['replayed']) && $replay['review_id'] === $result['review_id'] && (int)$db->getVar("SELECT COUNT(*) FROM `{$reviewTable}`") === 1 && (int)$db->getVar("SELECT COUNT(*) FROM `{$queueTable}`") === 3, 'same request replays original review without duplicate PDFs rows counts or emails');
  $staleInput = $reviewInput;
  $staleInput['request_token'] = \SCM\Modules\Pending\PublicServicesReviewStorage::formToken($base, 70001);
  $assert(empty($service->createServiciosPublicosReview(90001, $staleInput)['ok']), 'second form with old review version is rejected after contract row lock');
  $tamperedInput = $reviewInput; $tamperedInput['request_token'] .= 'tampered';
  $assert(empty($service->createServiciosPublicosReview(90001, $tamperedInput)['ok']), 'tampered request token cannot write or replay a review');
  $workspace = new \SCM\Modules\Pending\PublicServicesWorkspace($db);
  $viewData = $workspace->review($result['review_id']);
  $reviewHtml = \SCM\Modules\Pending\PublicServicesDocument::review($viewData['review'],$viewData['context'],$viewData['services'],$viewData['documents'],'');
  $assert(str_contains($reviewHtml,'Funcionario autenticado QA') && !str_contains($reviewHtml,'Sucursal') && str_contains($reviewHtml,'data-services-preview'), 'native public document closes with real actor hides branch and previews original PDF inline');
  $assert(str_contains($workspace->history(['contrato'=>'2000']),'Copiar enlace público') && str_contains($workspace->templates('critico'),'cuarenta y ocho (48)'), 'history offers signed links and critical template contains supplied 90-day letter');
  $templates = new \SCM\Modules\Pending\PublicServicesActTemplates($db);
  $actHtml = \SCM\Modules\Pending\PublicServicesDocument::act($viewData['context'],$viewData['services']['energia'],$viewData['context']['templates']['al_dia']);
  $assert(!str_contains($actHtml,'class="scm-acta-company"') && str_contains($actHtml,'scm-services-act') && str_contains($actHtml,'SKC SuCasa Inmobiliaria · NIT'), 'act header omits repeated company name and prioritizes title while keeping footer identity');
  $beforeTemplate = $templates->all()['al_dia'];
  $templates->save('al_dia',['title'=>'Texto nuevo {{servicio}}','body'=>'Contenido nuevo para {{arrendatario}}.','version'=>$beforeTemplate['version']],94001,'Funcionario autenticado QA');
  $assert($templates->all()['al_dia']['title']==='Texto nuevo {{servicio}}' && $workspace->review($result['review_id'])['context']['templates']['al_dia']['title']===$beforeTemplate['title'], 'editor persists changes with actor while existing review snapshot keeps issued wording');
  try { $templates->save('al_dia',['title'=>'Conflicto','body'=>'No sobrescribir','version'=>$beforeTemplate['version']],94001,'QA'); $assert(false,'stale template version'); } catch (\DomainException $error) { $assert(true,'stale template version cannot overwrite newer content'); }
  try { \SCM\Modules\Pending\PublicServicesActTemplates::validate('critico',['title'=>'Prueba','body'=>'{{variable_invalida}}']); $assert(false,'unknown placeholder'); } catch (\DomainException $error) { $assert(true,'editor rejects unsupported variables'); }
  $expiry = time()+600;
  $signature = \SCM\Modules\Pending\PublicServicesDocument::signature($result['review_id'],$expiry);
  $assert(\SCM\Modules\Pending\PublicServicesDocument::valid($result['review_id'],$expiry,$signature) && !\SCM\Modules\Pending\PublicServicesDocument::valid($result['review_id']+1,$expiry,$signature) && !\SCM\Modules\Pending\PublicServicesDocument::valid($result['review_id'],time()-1,$signature), 'public signature binds review ID and expiration');
  $assert(date('Y-m-d', \SCM\Modules\Pending\PublicServicesSchedule::next(strtotime('2026-11-30 12:00:00'))) === '2027-02-28' && date('Y-m-d', \SCM\Modules\Pending\PublicServicesSchedule::next(strtotime('2026-01-31 12:00:00'))) === '2026-04-30', 'quarterly schedule clamps end of month and crosses year');
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
  $received = $service->markContratoRecibido(90001,'2026-10-02');
  $receivedRow = $repo->getPublicServicesContract(90001);
  $receivedHistory = $db->getRow("SELECT * FROM `{$historyTable}` ORDER BY _ID DESC LIMIT 1");
  $assert(!empty($received['ok']) && $receivedRow['estado']==='Recibido' && $receivedRow['tipo']==='Ex' && (string)$receivedRow['cct_author_id']==='94001' && str_contains($receivedHistory['observacion'],'Estado anterior: Entregado') && (string)$receivedHistory['id_empleado']==='94001', 'received action writes contract and before-after audit under authenticated employee');
  $historyCount=(int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`");
  $assert(!empty($service->markContratoRecibido(90001,'2026-10-03')['ok']) && (int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`")===$historyCount && $repo->getPublicServicesContract(90001)['fecha_recibo']===$receivedRow['fecha_recibo'], 'received retry preserves original date and avoids duplicate history');
  $assert(empty($service->markContratoRecibido(90002,'2026-02-30')['ok']) && empty($service->markContratoRecibido(99999,'2026-10-02')['ok']), 'invalid receipt dates and missing exact contract PK fail closed');
  echo "$checks checks passed. Permanent rows unchanged; no external messages sent.\n";
} finally {
  foreach ($generatedPaths as $path) { if (is_file($path)) { unlink($path); } }
}
