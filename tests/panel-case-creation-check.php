<?php
declare(strict_types=1);

// Connection-local temporary shadows: no business records or real messages are created.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
$db = SCM\Core\App::db();
$sharedRoot = getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications';
$config = require $sharedRoot . '/config.php';
$queueTable = $config['queue']['queue_table'];
$attemptsTable = $config['queue']['attempts_table'];
$tables = ['jet_cct_tickets', 'jet_cct_contratos_arrendamiento', 'jet_cct_funcionarios', 'jet_cct_historial_del_ticket', 'jet_cct_historial_del_inmueble', 'jet_cct_propietarios', 'jet_cct_arrendatarios', 'jet_cct_copropiedades'];
foreach (array_merge(array_map(fn($name) => $db->table($name), $tables), [$queueTable, $attemptsTable]) as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid fixture table');
  $definition = $db->getRow('SHOW CREATE TABLE `' . $table . '`');
  $sql = $definition['Create Table'] ?? '';
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) throw new RuntimeException('Missing fixture schema');
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
(new ReflectionProperty(SCM\Core\Settings::class, 'data'))->setValue(SCM\Core\App::settings(), [
  'internal_admin_notifications' => ['nuevo_caso_panel' => [902, 903, 904]],
  SCM\Support\FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY => ['3'],
  'dashboard_tab_permissions' => ['999' => ['mis_tickets']],
]);
foreach ([[901, '9001', 'Creador', 'creator@example.invalid', 'Si'], [902, '9002', 'Responsable comercial', 'assignee@example.invalid', 'Si'], [903, '9003', 'Copia comercial', 'copy@example.invalid', 'Si'], [904, '9004', 'Inactivo', 'inactive@example.invalid', 'No'], [905, '9005', 'Sin correo', '', 'Si']] as [$pk, $employee, $name, $email, $active]) {
  $db->insert($db->table('jet_cct_funcionarios'), ['_ID' => $pk, 'id_empleado' => $employee, 'nombre' => $name, 'correo' => $email, 'celular' => '3001112201', 'activo' => $active, 'id_cargo' => in_array($employee, ['9002', '9004', '9005'], true) ? '3' : '999']);
}
$db->insert($db->table('jet_cct_propietarios'), ['_ID' => 71, 'id_propietario' => 'P71', 'documento' => 'QAOWNER123']);
$db->insert($db->table('jet_cct_arrendatarios'), ['_ID' => 72, 'id_arrendatario' => 'A72', 'documento' => 'QATENANT456']);
$db->insert($db->table('jet_cct_contratos_arrendamiento'), ['_ID' => 801, 'contrato' => 'QA801', 'inmueble' => 'SIMI8001', 'id_inmueble' => '8001', 'direccion' => 'Dirección de prueba', 'propietario' => 'Propietario Ejemplo', 'arrendatario' => 'Arrendatario Ejemplo', 'id_propietario' => 'P71', 'id_arrendatario' => 'A72', 'estado' => 'Entregado']);
$_SESSION['scm_logged_in'] = true;
$_SESSION['scm_employee_id'] = '9001';
$_SESSION['scm_user_id'] = 901;
$_SESSION['scm_last_activity'] = time();
$_FILES = [];
$service = new SCM\Modules\PublicTickets\PublicTicketsService($db, ['app_secret' => SCM_APP_SECRET]);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
foreach (['contrato' => 'QA801', 'inmueble' => 'SIMI8001', 'propietario' => 'Propietario', 'arrendatario' => 'Arrendatario'] as $by => $query) {
  $check(count($service->searchPanelContracts($query, $by)) === 1, 'finds contract by ' . $by);
}
$check(count($service->searchPanelContracts('QAOWNER123', 'propietario')) === 1, 'owner document follows contract relation');
$check(count($service->searchPanelContracts('QATENANT456', 'arrendatario')) === 1, 'tenant document follows contract relation');
$check($service->searchPanelContracts("%' OR 1=1 --", 'contrato') === [], 'query is escaped and parameterized');
$options = $service->panelCaseOptions();
$assignableIds = array_column($options['employees'], 'employee_id'); sort($assignableIds);
$check($assignableIds === ['9002', '9005'], 'only active employees in configured panel cargos are assignable');
$criticalTheme = SCM\Modules\Pending\PublicServicesCriticalTicket::TOPIC;
$check(in_array($criticalTheme, $options['themes'], true) && $options['theme_departments'][$criticalTheme] === 'Servicio al arrendatario', 'panel offers canonical critical public service topic and department');
$check($options['themes'] === ['Reparaciones necesarias', 'Reparaciones locativas', 'Mejoras utiles', 'Reparaciones voluntarias', 'Contable y tributaria', 'Certificaciones tributarias', 'Procesos juridicos', 'Solicitud contractual', 'Solicitud de servicios publicos', 'Otros servicios', 'Reparaciones antes de la entrega', 'Reparaciones antes del recibo', $criticalTheme], 'panel exposes only the thirteen requested topics');
$check($options['departments'] === ['Servicio al propietario', 'Servicio al arrendatario', 'Servicio a la copropiedad'], 'panel exposes only the three requested departments');
$input = ['asunto' => 'Revisar fuga <script>texto</script>', 'descripcion' => 'Descripción de prueba', 'tema_ayuda' => 'Reparaciones necesarias', 'departamento' => 'Servicio al propietario', 'id_empleado' => '9002', 'contract_id' => 801, 'has_attachments' => 'No'];
foreach ([['contract_id' => 999999], ['id_empleado' => '9003'], ['id_empleado' => '9004'], ['id_empleado' => '9005'], ['tema_ayuda' => 'Tema inventado'], ['tema_ayuda' => 'Arriendo'], ['departamento' => 'Departamento inventado'], ['departamento' => 'Mantenimiento'], ['departamento' => 'Servicio al cliente'], ['has_attachments' => 'Si'], ['asunto' => '']] as $invalid) {
  try { $service->createPanelTicket(array_merge($input, $invalid)); $check(false, 'reject invalid input'); }
  catch (InvalidArgumentException $e) { $check(true, 'rejects invalid ' . array_key_first($invalid) . ' before writing'); }
}
$result = $service->createPanelTicket($input);
$id = (int) $result['ticket_id'];
$ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ?', [$id]);
$check((string) $ticket['cct_author_id'] === '9001' && $ticket['id_empleado'] === '9002', 'real creator employee ID differs from assigned employee and session PK');
$check($ticket['id_ticket'] === (string) $id && $ticket['contrato'] === 'QA801' && $ticket['inmueble'] === 'SIMI8001', 'canonical ticket ID and contract snapshot');
$check($ticket['estado'] === 'Nuevo' && $ticket['estado_administrativo'] === 'Nuevo', 'new case follows existing initial-state rule');
$check($ticket['medio'] === 'Panel administrativo' && ($ticket['creado_por'] ?? $ticket['creador_por'] ?? '') === 'Funcionario', 'panel origin is recorded');
foreach (['jet_cct_historial_del_inmueble'] as $name) {
  $history = $db->getRow('SELECT * FROM `' . $db->table($name) . '` WHERE `id_ticket` = ?', [$id]);
  $check($history && (string) $history['cct_author_id'] === '9001' && $history['id_empleado'] === '9001', 'history uses actor employee ID: ' . $name);
}
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_ticket') . '`') === 0, 'new case does not create an automatic consultant response');
$check(str_contains($history['observacion'], 'Caso creado desde el panel. Asignado a Responsable comercial.'), 'property audit describes creation and assignment');
$rows = $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$check(count($rows) === 2 && $result['queued'] === 2, 'assignee and configured copy deduplicate; inactive copy excluded');
$destinations = array_column($rows, 'destination'); sort($destinations);
$check($destinations === ['assignee@example.invalid', 'copy@example.invalid'], 'internal recipients follow configuration across cargos');
$check(count(array_filter($rows, fn($row) => $row['status'] === 'pending' && $row['channel'] === 'email' && $row['source_module'] === 'nuevo_caso_panel')) === 2, 'real shared queue contains pending email events');
$check(!str_contains($rows[0]['message_html'], '<script>') && str_contains($rows[0]['message_html'], 'scm_case=' . $id), 'email safely escapes content and links to authenticated case popup');
$app = new SCM\App\SuCasaControlServiciosInmobiliarios($db);
$read = static fn() => (new ReflectionMethod($app, 'panelCaseReadPayload'))->invoke($app, $id);
$_SESSION['scm_user_cargo'] = '999';
$_SESSION['scm_employee_id'] = '9002';
$check(!empty($read()['case_source_html']), 'assignee email opens native popup even without metrics access');
$_SESSION['scm_employee_id'] = '9003';
$check(!empty($read()['case_source_html']), 'configured active copy can open panel case popup');
$_SESSION['scm_employee_id'] = '9005';
try { $read(); $check(false, 'unrelated employee must not read case'); }
catch (InvalidArgumentException $e) { $check(true, 'unrelated employee cannot enumerate case IDs'); }
$_SESSION['scm_logged_in'] = false;
try { $read(); $check(false, 'anonymous read must fail'); }
catch (InvalidArgumentException $e) { $check(true, 'anonymous case read rejected'); }
$_SESSION['scm_logged_in'] = true;
$_SESSION['scm_employee_id'] = '9001';
$before = (int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_tickets') . '`');
$db->pdo()->exec("ALTER TABLE `{$queueTable}` ADD CONSTRAINT qa_panel_copy CHECK (destination <> 'copy@example.invalid' OR subject LIKE 'Nuevo caso #{$id}:%')");
try { $service->createPanelTicket($input); $check(false, 'partial queue failure must fail'); }
catch (RuntimeException $e) { $check(true, 'partial queue failure is surfaced'); }
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_tickets') . '`') === $before, 'queue failure rolls back case');
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '`') === 2, 'queue failure rolls back first recipient too');
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_ticket') . '`') === 0 && (int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_inmueble') . '`') === 1, 'queue failure rolls back creation audit without generating responses');
$db->pdo()->exec("ALTER TABLE `{$queueTable}` DROP CONSTRAINT qa_panel_copy");
require_once $sharedRoot . '/autoload.php';
final class PanelCaseTestProvider implements SharedNotifications\Contracts\ProviderInterface {
  public function __construct(private string $providerCode = 'email_smtp') {}
  public function code(): string { return $this->providerCode; }
  public function send(array $notification): array { return ['ok' => true, 'http_code' => 200, 'response' => ['simulated' => true]]; }
}
$providers = (new SharedNotifications\Providers\ProviderRegistry())->add(new PanelCaseTestProvider())->add(new PanelCaseTestProvider('whatsapp_official'));
$worker = new SharedNotifications\NotificationWorker(new SharedNotifications\Storage\PdoStorageAdapter($db->pdo()), $providers, new SharedNotifications\Config\QueueConfig($queueTable, $attemptsTable));
$db->pdo()->exec("UPDATE `{$queueTable}` SET scheduled_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
$worker->run(10, 'control-servicios-inmobiliarios');
$check((int) $db->getVar("SELECT COUNT(*) FROM `{$queueTable}` WHERE status = 'sent'") === 2, 'inert provider worker completes shared emails');
$check((int) $db->getVar("SELECT COUNT(*) FROM `{$attemptsTable}`") === 2, 'worker logs attempts without contacting real recipients');

$criticalResult = $service->createPanelTicket(array_replace($input, ['tema_ayuda' => $criticalTheme, 'departamento' => 'Servicio al arrendatario']));
$criticalTicket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ?', [(int) $criticalResult['ticket_id']]);
$check($criticalTicket['tema_ayuda'] === $criticalTheme && $criticalTicket['departamento'] === 'Servicio al arrendatario', 'critical public service topic persists with canonical value');
$criticalMail = $db->getRow('SELECT * FROM `' . $queueTable . '` WHERE dedupe_key LIKE ? LIMIT 1', ['nuevo_caso_panel:' . $criticalResult['ticket_id'] . '%']);
$check($criticalResult['queued'] === 2 && $criticalMail && str_contains($criticalMail['message_html'], $criticalTheme), 'critical topic appears in queued assignment email');
$check($criticalResult['whatsapp_queued'] === 0 && str_contains($criticalResult['message'], 'WhatsApp pendiente'), 'disabled WhatsApp is explicit without claiming delivery');

$settingsProperty = new ReflectionProperty(SCM\Core\Settings::class, 'data');
$settingsData = $settingsProperty->getValue(SCM\Core\App::settings());
$settingsData[SCM\Support\PanelCaseNotifications::SETTINGS_KEY] = ['enabled' => true, 'assigned_template' => 'qa_caso_asignado', 'external_template' => 'qa_caso_externo', 'language' => 'es_CO'];
$settingsProperty->setValue(SCM\Core\App::settings(), $settingsData);
$check($service->panelCaseOptions()['whatsapp_enabled'] === true, 'popup reads enabled WhatsApp configuration');
foreach ([['assigned_template' => 'Invalid name'], ['language' => 'Spanish']] as $invalidConfig) {
  try { SCM\Support\PanelCaseNotifications::validateConfig($invalidConfig); $check(false, 'invalid template config must fail'); }
  catch (InvalidArgumentException $e) { $check(true, 'invalid WhatsApp template setting rejected'); }
}
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '3001112202'], ['_ID' => 902]);
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '3001112203'], ['_ID' => 903]);
foreach ([['notify_roles' => ['invitado']], ['notify_roles' => 'propietario'], ['notify_roles' => [['propietario']]], ['notify_roles' => ['copropiedad']]] as $invalid) {
  try { $service->createPanelTicket(array_replace($input, $invalid)); $check(false, 'invalid external notification must fail'); }
  catch (InvalidArgumentException $e) { $check(true, 'invalid or incomplete external recipient rejected'); }
}
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '123'], ['_ID' => 902]);
try { $service->createPanelTicket($input); $check(false, 'invalid assignee phone must fail'); }
catch (InvalidArgumentException $e) { $check(true, 'enabled WhatsApp requires a valid assignee phone'); }
$db->update($db->table('jet_cct_funcionarios'), ['celular' => '3001112202'], ['_ID' => 902]);
// Owner and copropiedad resolve from their linked records; tenant uses the contract snapshot.
$db->update($db->table('jet_cct_propietarios'), ['nombre' => 'Propietario QA', 'correo' => 'owner@example.invalid', 'celular' => '3001113301', 'indicativo' => '+57'], ['_ID' => 71]);
$db->insert($db->table('jet_cct_copropiedades'), ['_ID' => 73, 'copropiedad' => 'Copropiedad QA', 'correo' => 'copro@example.invalid', 'contacto' => '3001113303', 'indicativo' => '+57']);
$db->update($db->table('jet_cct_contratos_arrendamiento'), ['id_copropiedad' => '73', 'correo_arrendatario' => 'tenant@example.invalid', 'celular_arrendatario' => '3001113302', 'indicativo_arrendatario' => '+57'], ['_ID' => 801]);
$notifyInput = $input + ['notify_roles' => ['propietario', 'arrendatario', 'copropiedad']];
$notifyResult = $service->createPanelTicket($notifyInput);
$notifyId = (int) $notifyResult['ticket_id'];
$jobs = $db->getResults('SELECT * FROM `' . $queueTable . '` WHERE dedupe_key LIKE ? ORDER BY id', ['nuevo_caso_panel:' . $notifyId . ':%']);
$emails = array_values(array_filter($jobs, fn($r) => $r['channel'] === 'email'));
$whatsapps = array_values(array_filter($jobs, fn($r) => $r['channel'] === 'whatsapp'));
$check($notifyResult['queued'] === 5 && $notifyResult['whatsapp_queued'] === 5 && count($emails) === 5 && count($whatsapps) === 5, 'assignee, configured copy and three selected external recipients get both queued channels');
$check(count(array_filter($jobs, fn($r) => $r['status'] === 'pending')) === 10, 'both channels remain pending for shared worker');
foreach ($whatsapps as $job) {
  $payload = json_decode($job['payload_json'], true);
  $meta = json_decode($job['meta_json'], true);
  $body = $payload['components'][0]['parameters'];
  $check($job['provider'] === 'whatsapp_official' && count($body) === 6 && $body[1]['text'] === (string) $notifyId && $body[5]['text'] === 'Responsable comercial', 'official template carries six ordered case variables');
  $internal = $meta['recipient_role'] === 'funcionario';
  $reference = $payload['components'][1]['parameters'][0]['text'] ?? '';
  $publicRoute = SCM\Support\PublicCaseAccess::route($reference, false);
  $check($job['template_name'] === ($internal ? 'qa_caso_asignado' : 'qa_caso_externo') && count($payload['components']) === 2 && $publicRoute === ['mode' => 'public', 'id' => $notifyId, 'audience' => $meta['recipient_role']], 'internal and external templates receive signed case button bound to recipient audience');
}
$externalEmails = array_filter($emails, fn($r) => str_contains($r['dedupe_key'], ':externo:'));
$check(count($externalEmails) === 3 && !array_filter($externalEmails, fn($r) => !str_contains($r['message_html'], 'scm_case=' . $notifyId . '.') || str_contains($r['message_html'], '<script>')), 'external emails escape content and include signed case links');
$beforeTickets = (int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_tickets') . '`');
$beforeJobs = (int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '`');
$db->pdo()->exec("ALTER TABLE `{$queueTable}` ADD CONSTRAINT qa_panel_whatsapp CHECK (channel <> 'whatsapp' OR dedupe_key LIKE 'nuevo_caso_panel:{$notifyId}:%')");
try { $service->createPanelTicket($notifyInput); $check(false, 'WhatsApp queue failure must fail'); }
catch (PDOException $e) { $check(true, 'WhatsApp queue failure is surfaced'); }
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_tickets') . '`') === $beforeTickets && (int) $db->getVar('SELECT COUNT(*) FROM `' . $queueTable . '`') === $beforeJobs, 'WhatsApp failure rolls back case and every email');
$db->pdo()->exec("ALTER TABLE `{$queueTable}` DROP CONSTRAINT qa_panel_whatsapp");
$db->update($db->table('jet_cct_contratos_arrendamiento'), ['correo_arrendatario' => 'owner@example.invalid', 'celular_arrendatario' => '3001113301'], ['_ID' => 801]);
$dedupResult = $service->createPanelTicket($notifyInput);
$check($dedupResult['queued'] === 4 && $dedupResult['whatsapp_queued'] === 4, 'duplicate external emails and normalized phones enqueue once per channel');
$check(count($dedupResult['notification_details']) === 5 && count(array_filter($dedupResult['notification_details'], fn($text) => str_contains($text, 'comparte celular'))) === 1, 'receipt explains every WhatsApp recipient and phone deduplication');
$internalOnly = $service->createPanelTicket($input);
$check($internalOnly['queued'] === 2 && $internalOnly['whatsapp_queued'] === 2, 'assigned consultant and configured copy get WhatsApp even with no external recipients selected');
$db->pdo()->exec("UPDATE `{$queueTable}` SET scheduled_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
$worker->run(100, 'control-servicios-inmobiliarios');
$check((int) $db->getVar("SELECT COUNT(*) FROM `{$queueTable}` WHERE status <> 'sent'") === 0, 'inert providers process both email and official WhatsApp without real sends');
$check((int) $db->getVar("SELECT COUNT(*) FROM `{$attemptsTable}`") === (int) $db->getVar("SELECT COUNT(*) FROM `{$queueTable}`"), 'both channels leave shared attempt traces');
echo $checks . " checks passed.\n";
