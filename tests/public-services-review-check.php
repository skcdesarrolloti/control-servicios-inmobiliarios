<?php

declare(strict_types=1);

// All DB writes use connection-local TEMPORARY shadows. Never run a real transport provider.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });

$db = \SCM\Core\App::db();
// Validate permanent schemas before TEMPORARY shadows hide engine metadata in MySQL.
(new \SCM\Modules\Pending\PublicServicesReviewStorage($db))->requireSchema();
(new \SCM\Modules\Pending\PublicServicesCritical($db))->requireSchema();
$package = (string) (getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications');
require_once $package . '/autoload.php';
$queueConfig = require $package . '/config.php';
$queueTable = (string) ($queueConfig['queue']['queue_table'] ?? 'skc_notification_queue');
$attemptsTable = (string) ($queueConfig['queue']['attempts_table'] ?? 'skc_notification_attempts');
$tables = array_map([$db, 'table'], ['jet_cct_contratos_arrendamiento', 'jet_cct_tickets', 'jet_cct_historial_del_ticket', 'jet_cct_funcionarios', 'jet_cct_cargos', 'jet_cct_sucursales', 'jet_cct_revisiones_servicios', 'jet_cct_historial_del_inmueble', 'jet_cct_confi_sistema', 'posts', 'postmeta', 'scm_public_services_reviews']);
foreach (['cases','payments','audit','jobs'] as $suffix) $tables[] = $db->table('scm_services_critical_' . $suffix);
$tables[] = $queueTable;
$tables[] = $attemptsTable;
$tables[] = 'calendario_google_accounts';
foreach ($tables as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) { throw new RuntimeException('Invalid table name.'); }
  $definition = $db->getRow('SHOW CREATE TABLE `' . $table . '`');
  $sql = (string) ($definition['Create Table'] ?? '');
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) { throw new RuntimeException('Unexpected schema.'); }
  $sql=preg_replace('/ENGINE=MyISAM/i','ENGINE=InnoDB',$sql); // Simulate the required production migration in TEMPORARY shadows only.
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
$db->insert('calendario_google_accounts', ['id_empleado'=>'94004','google_email'=>'google@example.invalid','refresh_token_enc'=>'synthetic-test-token']);
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
  $assert(str_contains($reviewHtml,'Funcionario autenticado QA') && !str_contains($reviewHtml,'Sucursal') && !str_contains($reviewHtml,'Propietario QA') && !str_contains($reviewHtml,'<th scope="row">Propietario</th>') && str_contains($reviewHtml,'Arrendatario QA') && str_contains($reviewHtml,'data-services-preview'), 'native public document hides owner and branch while preserving tenant, actor and inline act previews');
  $assert(str_contains($workspace->history(['contrato'=>'2000']),'Copiar enlace público') && str_contains($workspace->templates('critico'),'setenta y dos (72)'), 'history offers signed links and critical template contains supplied 90-day letter');
  $templates = new \SCM\Modules\Pending\PublicServicesActTemplates($db);
  $actHtml = \SCM\Modules\Pending\PublicServicesDocument::act($viewData['context'],$viewData['services']['energia'],$viewData['context']['templates']['al_dia']);
  $assert(!str_contains($actHtml,'class="scm-acta-company"') && str_contains($actHtml,'scm-services-act') && str_contains($actHtml,'SKC SuCasa Inmobiliaria · NIT'), 'act header omits repeated company name and prioritizes title while keeping footer identity');
  $formatted = \SCM\Modules\Pending\PublicServicesDocument::paragraph('**Pago** *oportuno* __verificado__ <img src=x onerror=alert(1)>');
  $assert(str_contains($formatted,'<strong>Pago</strong>') && str_contains($formatted,'<em>oportuno</em>') && str_contains($formatted,'<u>verificado</u>') && !str_contains($formatted,'<img'), 'editor formatting supports bold italic underline while escaping source HTML');
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
  $schedule = \SCM\Modules\Pending\PublicServicesSchedule::class;
  $assert(date('Y-m-d', $schedule::initial(strtotime('2026-10-02'), 1)) === '2027-01-02', 'missing-date January schedule cannot precede October delivery');
  $assert(date('Y-m-d', $schedule::initial(strtotime('2024-10-31'), 2)) === '2025-02-28', 'legacy overdue schedule retains original year and clamps day');
  $assert(date('Y-m-d', $schedule::initial(strtotime('2026-10-02'), 10)) === '2026-10-02' && $schedule::initial(strtotime('2026-10-02'), 0) === strtotime('2026-10-02'), 'without historical evidence same-month and unset schedules remain due at delivery');
  $recovery = new \SCM\Modules\Pending\PublicServicesDateRecovery($db);
  $historicalDate = strtotime('2026-09-25 15:43:38');
  foreach ([91001,91002,91003,91004,91005] as $pk) {
    $db->insert($contractTable, array_replace($base, ['_ID'=>$pk,'contrato'=>'B'.$pk,'id_inmueble'=>'80101','ultima_revision_servicios'=>null,'fecha_entrega'=>strtotime('2026-10-02'),'mes_revision_servicios'=>'1','luz'=>'NIC','servicios_publicos'=>serialize(['Energia'])]));
  }
  $legacyReview = ['id_contrato'=>'91001','id_inmueble'=>'80101','contrato'=>'Contrato sin entregar','cct_status'=>'publish','fecha'=>$historicalDate,'tipo'=>'Antes de la ocupacion'];
  $db->insert($reviewTable, $legacyReview + ['_ID'=>92001]);
  $db->insert($reviewTable, array_replace($legacyReview, ['_ID'=>92002,'fecha'=>strtotime('2026-08-25')]));
  $db->insert($reviewTable, array_replace($legacyReview, ['_ID'=>92003,'id_contrato'=>'91002','id_inmueble'=>'WRONG']));
  $db->insert($reviewTable, array_replace($legacyReview, ['_ID'=>92004,'id_contrato'=>'91003','contrato'=>'OTHER-CONTRACT']));
  $db->insert($reviewTable, array_replace($legacyReview, ['_ID'=>92005,'id_contrato'=>'91004','fecha'=>time()+86400]));
  $db->insert($reviewTable, array_replace($legacyReview, ['_ID'=>92006,'id_contrato'=>'91005','fecha'=>null,'fecha_revision_luz'=>$historicalDate]));
  $candidates = $recovery->evidence($repo->getContratosEntregados([]));
  $assert(count($candidates)===2 && $candidates[91001]['review_id']===92001 && $candidates[91005]['timestamp']===$historicalDate, 'recovery finds latest exact pre-occupation evidence and actual service date fallback');
  $assert(!isset($candidates[91002]) && !isset($candidates[91003]) && !isset($candidates[91004]), 'recovery rejects other properties, contradictory codes and future reviews');
  $beforeRecovery = $controller->buildServiciosPublicosPayload(['rsp_contrato'=>'B91001','rsp_mes'=>12]);
  $assert($beforeRecovery['count']===1 && date('Y-m-d',$beforeRecovery['items'][0]['due'])==='2026-12-25' && $repo->getPublicServicesContract(91001)['ultima_revision_servicios']===null, 'listing uses recovered evidence without mutating database and filters recovered due month');
  $queuesBefore=(int)$db->getVar("SELECT COUNT(*) FROM `{$queueTable}`");
  $reviewsBefore=(int)$db->getVar("SELECT COUNT(*) FROM `{$reviewTable}`");
  try { $recovery->repair(91001,70003); $assert(false,'invalid repair actor'); } catch (\RuntimeException $e) { $assert($repo->getPublicServicesContract(91001)['ultima_revision_servicios']===null,'repair rejects an ID without a real active employee'); }
  $assert($recovery->repair(91001,94001), 'audited historical date repair succeeds');
  $repaired=$repo->getPublicServicesContract(91001);
  $repairHistory=$db->getRow("SELECT * FROM `{$historyTable}` ORDER BY `_ID` DESC LIMIT 1");
  $assert((int)$repaired['ultima_revision_servicios']===$historicalDate && (int)$repaired['mes_revision_servicios']===12 && (int)$repaired['revisiones_servicios']===4 && (int)$repaired['cct_author_id']===94001, 'repair updates actual date and next month while retaining original review count');
  $assert((int)$repairHistory['id_empleado']===94001 && str_contains($repairHistory['observacion'],'92001') && str_contains($repairHistory['observacion'],'previous'), 'repair audit retains original fields and evidence under real employee identity');
  $historiesAfter=(int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`");
  $assert(!$recovery->repair(91001,94001) && !$recovery->repair(91002,94001) && (int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`")===$historiesAfter, 'repair is idempotent and leaves undocumented contracts unchanged');
  $assert((int)$db->getVar("SELECT COUNT(*) FROM `{$queueTable}`")===$queuesBefore && (int)$db->getVar("SELECT COUNT(*) FROM `{$reviewTable}`")===$reviewsBefore, 'date recovery creates no reviews, acts or notifications');
  $adjustContext=$service->buildServiciosPublicosReviewContext(91001);
  $adjustInput=['request_token'=>$adjustContext['request_token'],'last_review_date'=>'2026-07-02','adjustment_reason'=>'Reorganización administrativa del atraso','id_empleado'=>'70001'];
  $_SESSION['scm_user_cargo']='11';
  $nonAdminList=$service->buildServiciosPublicos(['contrato'=>'B91001']);
  $assert(!str_contains($view->renderServiciosPublicosTable($nonAdminList['items']),'data-services-adjust-save') && empty($adjustContext['can_adjust_schedule']) && !str_contains($view->renderServiciosPublicosReviewForm($adjustContext),'data-services-adjust-save') && empty($service->adjustServiciosPublicosReviewDate(91001,$adjustInput)['ok']), 'non-admin cannot see or call date adjustment even with forged session cargo');
  $db->update($employeeTable,['id_cargo'=>'11'],['_ID'=>70001]);
  $adjustContext=$service->buildServiciosPublicosReviewContext(91001);
  $adjustInput['request_token']=$adjustContext['request_token'];
  $adminList=$service->buildServiciosPublicos(['contrato'=>'B91001']);
  $assert(!str_contains($view->renderServiciosPublicosReviewForm($adjustContext),'data-services-adjust-save') && str_contains($view->renderServiciosPublicosTable($adminList['items']),'data-services-adjust-save'), 'admin date editor is in table Actions and absent from review popup');
  \SCM\Modules\Pending\PublicServicesReviewStorage::validateToken($adminList['items'][0]['adjustment_token'],$repo->getPublicServicesContract(91001),70001);
  $assert(!empty($adminList['items'][0]['can_adjust_schedule']), 'table token carries actual last date and review count');
  $assert(empty($service->adjustServiciosPublicosReviewDate(91001,array_replace($adjustInput,['last_review_date'=>'2026-02-30']))['ok']) && empty($service->adjustServiciosPublicosReviewDate(91001,array_replace($adjustInput,['last_review_date'=>date('Y-m-d',time()+86400)]))['ok']) && empty($service->adjustServiciosPublicosReviewDate(91001,array_replace($adjustInput,['adjustment_reason'=>'']))['ok']), 'admin date adjustment rejects impossible dates, future dates and missing reasons');
  $assert(empty($service->adjustServiciosPublicosReviewDate(91001,array_replace($adjustInput,['request_token'=>'forged']))['ok']), 'date adjustment requires signed token');
  $adjusted=$service->adjustServiciosPublicosReviewDate(91001,$adjustInput);
  $adjustedContract=$repo->getPublicServicesContract(91001);
  $adjustHistory=$db->getRow("SELECT * FROM `{$historyTable}` ORDER BY `_ID` DESC LIMIT 1");
  $assert(!empty($adjusted['ok']) && $adjusted['next_review_date']==='2026-10-02' && (int)$adjustedContract['mes_revision_servicios']===10 && (int)$adjustedContract['cct_author_id']===94001 && (int)$adjustedContract['revisiones_servicios']===4, 'admin adjustment recalculates next date/month under actual actor without changing review counts');
  $assert((int)$adjustHistory['id_empleado']===94001 && str_contains($adjustHistory['observacion'],$adjustInput['adjustment_reason']) && str_contains($adjustHistory['observacion'],'previous') && str_contains($adjustHistory['observacion'],'no acredita una nueva revisión'), 'administrative adjustment audit preserves before/after and reason without claiming performed review');
  $adjustedListing=$service->buildServiciosPublicos(['contrato'=>'B91001','mes'=>12]);
  $assert(count($adjustedListing['items'])===0 && count($service->buildServiciosPublicos(['contrato'=>'B91001','mes'=>10])['items'])===1, 'date adjustment removes contract from old month and moves it to target month');
  $adjustHistories=(int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`");
  $assert(!empty($service->adjustServiciosPublicosReviewDate(91001,$adjustInput)['ok']) && (int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`")===$adjustHistories && empty($service->adjustServiciosPublicosReviewDate(91001,array_replace($adjustInput,['last_review_date'=>'2026-08-02']))['ok']), 'adjustment retry is idempotent while stale forms cannot overwrite a different date');
  $assert((int)$db->getVar("SELECT COUNT(*) FROM `{$queueTable}`")===$queuesBefore && (int)$db->getVar("SELECT COUNT(*) FROM `{$reviewTable}`")===$reviewsBefore, 'admin date adjustment creates no reviews, acts or notifications');
  $db->update($employeeTable,['activo'=>'No'],['_ID'=>70001]);
  $assert(empty($service->adjustServiciosPublicosReviewDate(91001,array_replace($adjustInput,['request_token'=>$adjusted['request_token']]))['ok']), 'inactive admin cannot adjust dates');
  $db->update($employeeTable,['activo'=>'Si'],['_ID'=>70001]);
  $batchRows=[];
  foreach ([91001,91005] as $pk) { $batchRows[]=['id'=>(string)$pk,'token'=>\SCM\Modules\Pending\PublicServicesReviewStorage::formToken($repo->getPublicServicesContract($pk),70001)]; }
  $batchInput=['contracts'=>$batchRows,'target_month'=>'2027-02','reason'=>'Redistribución de revisiones para el próximo año'];
  $priorBatch=[$repo->getPublicServicesContract(91001),$repo->getPublicServicesContract(91005)];
  $assert(empty($service->scheduleServiciosPublicosMonth(array_replace($batchInput,['target_month'=>'2027-13']))['ok']) && empty($service->scheduleServiciosPublicosMonth(array_replace($batchInput,['target_month'=>'2025-01']))['ok']), 'month scheduling validates destination year/month and rejects past months');
  $badBatch=$batchRows; $badBatch[1]['token']='forged';
  $histBeforeBatch=(int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`");
  $assert(empty($service->scheduleServiciosPublicosMonth(array_replace($batchInput,['contracts'=>$badBatch]))['ok']) && empty($repo->getPublicServicesContract(91001)['proxima_revision_servicios']) && (int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`")===$histBeforeBatch, 'invalid second token rolls back entire batch and its first history');
  $batchSaved=$service->scheduleServiciosPublicosMonth($batchInput);
  $batchFirst=$repo->getPublicServicesContract(91001);$batchSecond=$repo->getPublicServicesContract(91005);
  $assert(!empty($batchSaved['ok']) && $batchSaved['updated']===2 && date('Y-m-d',(int)$batchFirst['proxima_revision_servicios'])==='2027-02-02' && date('Y-m-d',(int)$batchSecond['proxima_revision_servicios'])==='2027-02-25', 'bulk scheduling preserves each due day in explicit next-year destination');
  $assert($batchFirst['ultima_revision_servicios']===$priorBatch[0]['ultima_revision_servicios'] && $batchSecond['ultima_revision_servicios']===$priorBatch[1]['ultima_revision_servicios'] && $batchFirst['revisiones_servicios']===$priorBatch[0]['revisiones_servicios'] && (int)$batchFirst['mes_revision_servicios']===2, 'bulk month scheduling preserves actual review dates and counters and updates configured month');
  $assert($service->scheduleServiciosPublicosMonth($batchInput)['updated']===0 && (int)$db->getVar("SELECT COUNT(*) FROM `{$historyTable}`")===$histBeforeBatch+2, 'batch retry does not duplicate audit');
  $batchListing=$service->buildServiciosPublicos(['contrato'=>'B91001','mes'=>2]);
  $assert(count($batchListing['items'])===1 && date('Y-m-d',$batchListing['items'][0]['due'])==='2027-02-02' && count($service->buildServiciosPublicos(['contrato'=>'B91001','mes'=>10])['items'])===0, 'listing and month filter honor explicit override with full year');
  $assert(empty($service->scheduleServiciosPublicosMonth(array_replace($batchInput,['target_month'=>'2027-03']))['ok']), 'stale batch cannot overwrite newer manual scheduling');
  $db->insert($contractTable,array_replace($base,['_ID'=>91006,'contrato'=>'B91006','id_inmueble'=>'80101','ultima_revision_servicios'=>strtotime('2026-10-31'),'mes_revision_servicios'=>'1','servicios_publicos'=>serialize(['Energia'])]));
  $clampInput=['contracts'=>[['id'=>'91006','token'=>\SCM\Modules\Pending\PublicServicesReviewStorage::formToken($repo->getPublicServicesContract(91006),70001)]],'target_month'=>'2027-02','reason'=>'Ajuste del cierre de mes'];
  $assert(!empty($service->scheduleServiciosPublicosMonth($clampInput)['ok']) && date('Y-m-d',(int)$repo->getPublicServicesContract(91006)['proxima_revision_servicios'])==='2027-02-28', 'destination month clamps day 31 to February end');
  $assert((int)$db->getVar("SELECT COUNT(*) FROM `{$queueTable}`")===$queuesBefore && (int)$db->getVar("SELECT COUNT(*) FROM `{$reviewTable}`")===$reviewsBefore, 'bulk scheduling generates no reviews, acts or notifications');
  $newAdjustment=$adjustInput; $newAdjustment['request_token']=\SCM\Modules\Pending\PublicServicesReviewStorage::formToken($batchFirst,70001);
  $assert(!empty($service->adjustServiciosPublicosReviewDate(91001,$newAdjustment)['ok']) && empty($repo->getPublicServicesContract(91001)['proxima_revision_servicios']), 'adjusting actual review date clears manual scheduling override');
  $nativeContext=$service->buildServiciosPublicosReviewContext(91006);
  $nativeInput=['request_token'=>$nativeContext['request_token'],'configuration_present'=>'1','servicios_configurados'=>['energia'],'servicios'=>['energia'],'nic'=>'NIC-QA','medidor_luz'=>'METER-QA','resultado_tiempo_luz'=>'Al dia','resultado_valores_luz'=>'0'];
  $nativeReview=$service->createServiciosPublicosReview(91006,$nativeInput);
  foreach ((array)($nativeReview['documents']??[]) as $doc) { parse_str((string)parse_url($doc['url'],PHP_URL_QUERY),$qaQuery); $qaPath=\SCM\Support\StoredFileService::fromRuntime()->pathFor((string)($qaQuery['n']??'')); if($qaPath!==null)$generatedPaths[]=$qaPath; }
  $assert(!empty($nativeReview['ok']) && empty($repo->getPublicServicesContract(91006)['proxima_revision_servicios']), 'actual new review clears override and resumes quarterly schedule');
  $db->update($employeeTable,['id_cargo'=>'3'],['_ID'=>70001]);
  $assert(empty($service->scheduleServiciosPublicosMonth($batchInput)['ok']), 'non-admin cannot invoke bulk scheduling');

  $db->insert($cargoTable,['_ID'=>11,'nombre_cargo'=>'Director QA']);
  $critical = new \SCM\Modules\Pending\PublicServicesCritical($db);
  $db->update($employeeTable,['id_cargo'=>'11'],['_ID'=>70001]);
  $db->update($employeeTable,['celular'=>'3007654321'],['_ID'=>70004]);
  $actor=$repo->getFuncionarioByUserId(70001);
  $critical->saveConfig([
    'whatsapp_template'=>'qa_critical_document','response_template'=>'qa_payment_document','language'=>'es_CO',
    'servicios_publicos_critico'=>['70004'], 'servicios_publicos_pago_reportado'=>[], 'servicios_publicos_critico_calendario'=>['70004']
  ],$actor);
  $db->update($contractTable,['celular_arrendatario'=>'3008889999'],['_ID'=>91006]);
  $criticalContext=$service->buildServiciosPublicosReviewContext(91006);
  $criticalInput=array_replace($nativeInput,['request_token'=>$criticalContext['request_token'],'resultado_tiempo_luz'=>'Estado critico','resultado_valores_luz'=>'350000']);
  $criticalResult=$service->createServiciosPublicosReview(91006,$criticalInput);
  $assert(!empty($criticalResult['ok']),'critical review atomically creates native case: '.($criticalResult['message']??''));
  foreach ($criticalResult['documents'] as $doc) { parse_str((string)parse_url($doc['url'],PHP_URL_QUERY),$q);$path=\SCM\Support\StoredFileService::fromRuntime()->pathFor((string)$q['n']);if($path)$generatedPaths[]=$path; }
  $id=(int)$criticalResult['review_id'];$case=$critical->case($id);$ticketId=(int)$case['ticket_id'];
  $assert($ticketId===(int)$criticalResult['critical_ticket_id'] && $case['ticket']['tema_ayuda']==='Servicio publico critico','critical case is a real CCT ticket with exact topic');
  $assert((int)$case['deadline_at']-(int)$case['created_at']===72*3600,'case expires exactly 72 calendar hours from review registration');
  $assert((string)$case['ticket']['cct_author_id']==='94001' && $case['ticket']['id_empleado']==='94001' && $case['ticket']['estado_administrativo']==='Nuevo','case author and assignee use real creator id; new administrative state initialized');
  $propertyId=json_decode($case['payload_json'],true)['contract']['id_inmueble'];
  $propertyHistory=$db->getRow('SELECT * FROM `'.$historyTable.'` WHERE id_ticket=?',[$ticketId]);
  $assert($propertyHistory && (string)$propertyHistory['id_inmueble']===(string)$propertyId && (string)$propertyHistory['cct_author_id']==='94001' && str_contains($propertyHistory['observacion'],'Servicio publico critico'),'case creation is registered in property report with real actor, topic and deadline');
  $assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$db->table('jet_cct_historial_del_ticket').'` WHERE id_ticket=?',[$ticketId])===1,'native case also has creation history');
  $criticalData=$workspace->review($id);
  $html=\SCM\Modules\Pending\PublicServicesDocument::review($criticalData['review'],$criticalData['context'],$criticalData['services'],$criticalData['documents'],'');
  $assert(!str_contains($html,'Realicé el pago') && !str_contains($html,'pago-servicios-publicos.php') && str_contains($html,'atención en 72 horas'),'public review displays case deadline without payment actions');
  $assert(str_contains($workspace->history([]),'data-services-critical-open'),'review history can open linked critical case');
  $assert(str_contains($workspace->critical([]),'Caso #'.$ticketId) && !str_contains($workspace->critical([]),'Copiar enlace de pago'),'critical list shows real case without payment workflow');
  $replayed=$service->createServiciosPublicosReview(91006,$criticalInput);
  $assert(!empty($replayed['replayed']) && (int)$replayed['critical_ticket_id']===$ticketId && (int)$db->getVar('SELECT COUNT(*) FROM `'.$db->table('jet_cct_tickets').'`')===1,'duplicate review submission returns same case and never creates duplicate');
  $assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'` WHERE kind=? AND review_id=?',['whatsapp',$id])===3,'WhatsApp review jobs target tenant creator and configured employee');
  $calendarJob=json_decode($db->getVar('SELECT payload_json FROM `'.$critical->table('jobs').'` WHERE kind=?',['calendar']),true);
  $assert($calendarJob['id_empleado']==='94004' && $calendarJob['sincronizar_google'] && $calendarJob['meta']['ticket_id']===$ticketId,'calendar targets configured employee and carries linked case');
  $count=(int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'`');$critical->plan($id);
  $assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'`')===$count,'planning does not duplicate notifications or reminder');
  $critical->run(30,static fn()=>['id'=>100,'google_event_id'=>'qa-google-only']);
  $assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'` WHERE kind=? AND status=?',['whatsapp','pending'])===3,'WhatsApp awaits new approved template durably');
  $critical->saveConfig(['whatsapp_template'=>'qa_revision_critica','language'=>'es','whatsapp_enabled'=>'1','servicios_publicos_critico'=>['70004']],$actor);
  $critical->run(30,static fn()=>['id'=>100,'google_event_id'=>'qa-google-only']);
  $wa=$db->getResults('SELECT * FROM `'.$queueTable.'` WHERE source_module=? AND channel=?',['public-services-critical','whatsapp']);
  $assert(count($wa)===3 && count(array_unique(array_column($wa,'destination')))===3,'approved WhatsApp enqueues once per intended recipient');
  $wp=json_decode($wa[0]['payload_json'],true);
  $assert($wp['components'][0]['parameters'][0]['type']==='document' && str_contains($wp['components'][2]['parameters'][0]['text'],'revision-servicios-publicos.php?numero=') && !str_contains($wp['components'][2]['parameters'][0]['text'],'pago-servicios'),'WhatsApp contains act PDF and signed review URL, never payment URL');
  $assert(str_contains($wp['components'][1]['parameters'][2]['text'],'Caso #'.$ticketId),'WhatsApp details identify the created case');
  $queueCount=(int)$db->getVar('SELECT COUNT(*) FROM `'.$queueTable.'`');$critical->run(30,static fn()=>[]);
  $assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$queueTable.'`')===$queueCount,'retry does not duplicate shared notifications');
  $failed=false;try{$critical->report($id,[],'',str_repeat('a',64));}catch(DomainException){$failed=true;}
  $assert($failed && (int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('payments').'`')===0,'retired payment report cannot accept new evidence');
  $failed=false;try{$critical->verify($id,'verified','Intento de pago QA',$actor);}catch(DomainException){$failed=true;}
  $assert($failed && $critical->case($id)['status']==='pending','old payment verification cannot close a native case');
  $failed=false;try{$critical->saveConfig(['whatsapp_template'=>'scm_servicios_critico_72h','language'=>'es','whatsapp_enabled'=>1],$actor);}catch(DomainException){$failed=true;}
  $assert($failed,'old paid-button template cannot accidentally be enabled');
  $failed=false;try{$critical->saveConfig([],array_replace($actor,['id_cargo'=>'3']));}catch(DomainException){$failed=true;}
  $assert($failed,'non-admin cannot change notification settings');

  // The due calendar uses the linked native case and exact deadline, not payment status.
  $app=new \SCM\App\SuCasaControlServiciosInmobiliarios($db);
  $method=(new ReflectionClass($app))->getMethod('adminDueCalendarItems');
  $dueSettings=(new ReflectionClass($app))->getMethod('adminDueCalendarSettings')->invoke($app);
  $nativeCase=(new ReflectionClass($app))->getMethod('adminDueNativeTicketCasePayload')->invoke($app,$ticketId,'abiertos');
  $assert(!empty($nativeCase['case_source_html']) && str_contains($nativeCase['tema']??'','Servicio publico critico'),'linked case renders through the complete native case presenter');
  $items=$method->invoke($app,$dueSettings,strtotime(date('Y-m-01')),strtotime(date('Y-m-t').' 23:59:59'));
  $due=array_values(array_filter($items,static fn($r)=>$r['tipo_vencimiento']==='servicios_publicos_critico'));
  $assert(count($due)===1 && $due[0]['case']['ticket_pk']===(string)$ticketId && str_contains($due[0]['titulo'],'Servicio publico critico'),'due calendar and popup receive native case reference');
  $db->update($critical->table(),['deadline_at'=>time()-1],['review_id'=>$id]);
  $items=$method->invoke($app,$dueSettings,strtotime(date('Y-m-01')),strtotime(date('Y-m-t').' 23:59:59'));
  $due=array_values(array_filter($items,static fn($r)=>$r['tipo_vencimiento']==='servicios_publicos_critico'));
  $assert($due[0]['estado']==='Vencido','case becomes overdue at exact hour even during same day');
  $db->update($db->table('jet_cct_tickets'),['estado'=>'Finalizado'],['_ID'=>$ticketId]);
  $assert($critical->case($id)['status']==='closed' && str_contains($workspace->critical([]),'No hay seguimientos') && str_contains($workspace->critical(['status'=>'closed']),'Caso #'.$ticketId),'native closure immediately removes case from open critical list');
  $items=$method->invoke($app,$dueSettings,strtotime(date('Y-m-01')),strtotime(date('Y-m-t').' 23:59:59'));
  $assert(!array_filter($items,static fn($r)=>$r['tipo_vencimiento']==='servicios_publicos_critico'),'closed case disappears from due calendar and popup');
  $critical->syncTicketClosures();
  $assert((int)$db->getVar('SELECT COUNT(*) FROM `'.$critical->table('jobs').'` WHERE kind=?',['calendar_close'])===1 && $critical->case($id)['ticket']['estado_administrativo']==='Nuevo','closure cancels reminder without changing administrative status');
  $critical->run(30,static fn()=>['cancelled'=>true]);

  // Legacy open follow-up converts once, retaining original deadline and evidence.
  $legacy=json_decode($case['payload_json'],true);unset($legacy['ticket_id'],$legacy['workflow']);
  $legacyId=$id+900000;$deadline=time()-86400;
  $db->insert($critical->table(),['review_id'=>$legacyId,'contract_id'=>91006,'created_at'=>$deadline-72*3600,'deadline_at'=>$deadline,'planned'=>1,'status'=>'reported','payload_json'=>json_encode($legacy)]);
  $assert($critical->upgradeOpenCases($legacyId)===1 && $critical->upgradeOpenCases($legacyId)===0,'open legacy follow-up converts idempotently to one native case');
  $assert((int)$critical->case($legacyId)['deadline_at']===$deadline && $critical->case($legacyId)['ticket']['tema_ayuda']==='Servicio publico critico','legacy conversion never extends deadline');
  $critical->plan($legacyId);
  $legacyCase=$critical->case($legacyId);$legacyP=json_decode($legacyCase['payload_json'],true);

  // Failure before case insertion rolls the whole review back.
  $before=(int)$db->getVar('SELECT COUNT(*) FROM `'.$reviewTable.'`');
  $db->update($contractTable,['id_inmueble'=>''],['_ID'=>91006]);
  $ctx=$service->buildServiciosPublicosReviewContext(91006);
  $failedResult=$service->createServiciosPublicosReview(91006,array_replace($criticalInput,['request_token'=>$ctx['request_token']]));
  $assert(empty($failedResult['ok']) && (int)$db->getVar('SELECT COUNT(*) FROM `'.$reviewTable.'`')===$before,'missing case property rolls back review and case atomically');
  $db->update($contractTable,['id_inmueble'=>$propertyId],['_ID'=>91006]);

  if(!is_dir(dirname(__DIR__).'/tmp/services-qa'))mkdir(dirname(__DIR__).'/tmp/services-qa',0777,true);
  $simulation=['review_id'=>$id,'case_id'=>$ticketId,'topic'=>$case['ticket']['tema_ayuda'],'created_at'=>date('c',(int)$case['created_at']),'deadline'=>date('c',(int)$case['created_at']+72*3600),'whatsapp_queued'=>count($wa),'property_report'=>true,'overdue_at_exact_time'=>true,'closed_removed_from_due'=>true,'payment_upload_retired'=>true,'legacy_case_id'=>$legacyCase['ticket_id']];
  file_put_contents(dirname(__DIR__).'/tmp/services-qa/critical-case-simulation.json',json_encode($simulation,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  echo "$checks checks passed. Permanent rows unchanged; no external messages sent.\n";
} finally {
  foreach ($generatedPaths as $path) { if (is_file($path)) { unlink($path); } }
}
