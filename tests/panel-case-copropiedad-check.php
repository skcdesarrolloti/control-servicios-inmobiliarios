<?php
declare(strict_types=1);

// Casos de copropiedad (panel, Guardian y portal) con tablas temporales de la conexión: no crea registros ni mensajes reales.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
$db = SCM\Core\App::db();
$sharedRoot = getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname(__DIR__, 2) . '/shared-notifications';
$config = require $sharedRoot . '/config.php';
$queueTable = $config['queue']['queue_table'];
$tables = ['jet_cct_tickets', 'jet_cct_contratos_arrendamiento', 'jet_cct_funcionarios', 'jet_cct_historial_del_ticket', 'jet_cct_historial_del_inmueble', 'jet_cct_propietarios', 'jet_cct_arrendatarios', 'jet_cct_copropiedades'];
foreach (array_merge(array_map(fn($name) => $db->table($name), $tables), [$queueTable, $config['queue']['attempts_table']]) as $table) {
  if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid fixture table');
  $sql = $db->getRow('SHOW CREATE TABLE `' . $table . '`')['Create Table'] ?? '';
  if (!str_starts_with($sql, 'CREATE TABLE `' . $table . '`')) throw new RuntimeException('Missing fixture schema');
  $db->pdo()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql));
}
(new ReflectionProperty(SCM\Core\Settings::class, 'data'))->setValue(SCM\Core\App::settings(), [
  SCM\Support\FuncionarioOptions::PANEL_CARGO_IDS_SETTING_KEY => ['3'],
  'dashboard_tab_permissions' => ['999' => ['mis_tickets']],
]);
foreach ([[901, '9001', 'Creador', 'creator@example.invalid'], [902, '9002', 'Responsable', 'assignee@example.invalid']] as [$pk, $employee, $name, $email]) {
  $db->insert($db->table('jet_cct_funcionarios'), ['_ID' => $pk, 'id_empleado' => $employee, 'nombre' => $name, 'correo' => $email, 'celular' => '3001112201', 'activo' => 'Si', 'id_cargo' => $employee === '9002' ? '3' : '999']);
}
$db->insert($db->table('jet_cct_copropiedades'), ['_ID' => 61, 'cct_status' => 'publish', 'copropiedad' => 'Edificio Torres del Mar', 'nit' => '900.123.456-7', 'administrador' => 'Ana Administradora', 'correo' => 'admin@torres.invalid', 'contacto' => '3001112230', 'direccion' => 'Calle 5 # 1-20', 'barrio' => 'Bocagrande']);
$db->insert($db->table('jet_cct_copropiedades'), ['_ID' => 62, 'cct_status' => 'publish', 'copropiedad' => 'Conjunto Sin Contratos', 'nit' => '800999111', 'administrador' => '', 'correo' => 'conjunto@example.invalid', 'contacto' => '3001112240', 'direccion' => 'Carrera 9']);
// Contrato de la copropiedad con contacto vacío (la ficha lo completa) y otro contrato ajeno.
$db->insert($db->table('jet_cct_contratos_arrendamiento'), ['_ID' => 811, 'contrato' => 'QA811', 'inmueble' => 'SIMI811', 'direccion' => 'Calle 5 # 1-20 Apto 301', 'propietario' => 'Dueña', 'arrendatario' => 'Inquilino', 'estado' => 'Entregado', 'id_copropiedad' => '61', 'copropiedad' => 'Edificio Torres del Mar', 'nit_copropiedad' => '900123456', 'administrador' => '', 'correo_copropiedad' => '', 'celular_copropiedad' => '']);
$db->insert($db->table('jet_cct_contratos_arrendamiento'), ['_ID' => 812, 'contrato' => 'QA812', 'inmueble' => 'SIMI812', 'direccion' => 'Otra', 'estado' => 'Entregado', 'id_copropiedad' => '99', 'copropiedad' => 'Otra copropiedad']);
$_SESSION['scm_logged_in'] = true;
$_SESSION['scm_employee_id'] = '9001';
$_SESSION['scm_user_id'] = 901;
$_SESSION['scm_last_activity'] = time();
$_FILES = [];
$service = new SCM\Modules\PublicTickets\PublicTicketsService($db, ['app_secret' => SCM_APP_SECRET]);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$ticket = static fn(int $id): array => $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ?', [$id]);

// Panel: búsqueda.
$byName = $service->searchPanelCopropiedades('torres');
$check(count($byName) === 1 && $byName[0]['_ID'] === '61' && array_map('strval', array_column($byName[0]['contracts'], '_ID')) === ['811'], 'panel finds the community by name with only its own contracts');
// Contrato ligado solo por NIT: la ficha lo guarda con dígito de verificación y el contrato sin él.
$db->insert($db->table('jet_cct_contratos_arrendamiento'), ['_ID' => 813, 'contrato' => 'QA813', 'inmueble' => 'SIMI813', 'direccion' => 'Calle 5 Apto 502', 'estado' => 'Entregado', 'id_copropiedad' => '', 'copropiedad' => 'Torres del Mar', 'nit_copropiedad' => '900123456']);
$check(in_array('813', array_map('strval', array_column($service->searchPanelCopropiedades('torres')[0]['contracts'], '_ID')), true), 'contracts linked only by NIT match a record NIT with check digit');
$byNit = $service->searchPanelCopropiedades('900123456');
$check(count($byNit) === 1 && $byNit[0]['copropiedad'] === 'Edificio Torres del Mar', 'panel finds the community by NIT without check digit');
$check($service->searchPanelCopropiedades('Sin Contratos')[0]['contracts'] === [], 'community without contracts is still listed');

// Panel: creación.
$base = ['asunto' => 'Filtración en zona común', 'descripcion' => 'Humedad en el lobby', 'tema_ayuda' => 'Reparaciones necesarias', 'departamento' => 'Servicio a la copropiedad', 'id_empleado' => '9002', 'has_attachments' => 'No'];
foreach ([[['contract_id' => 0], 'nothing selected'], [['copropiedad_id' => 999], 'unknown community'], [['copropiedad_id' => 61, 'contract_id' => 812], 'contract of another community'], [['copropiedad_id' => 61, 'notify_roles' => ['propietario']], 'owner notice without contract']] as [$invalid, $label]) {
  try { $service->createPanelTicket(array_merge($base, $invalid)); $check(false, 'reject ' . $label); }
  catch (InvalidArgumentException) { $check(true, 'rejects ' . $label . ' before writing'); }
}
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_tickets') . '`') === 0, 'rejected cases write nothing');
$general = $service->createPanelTicket(array_merge($base, ['copropiedad_id' => 61, 'notify_roles' => ['copropiedad']]));
$row = $ticket((int) $general['ticket_id']);
$check($row['id_copropiedad'] === '61' && $row['copropiedad'] === 'Edificio Torres del Mar' && $row['correo_copropiedad'] === 'admin@torres.invalid' && $row['celular_copropiedad'] === '3001112230', 'general case stores the community from its record');
$check(trim((string) $row['contrato']) === '' && trim((string) $row['id_contrato']) === '' && $row['direccion'] === 'Calle 5 # 1-20' && $row['departamento'] === 'Servicio a la copropiedad', 'general case has no contract and uses the community address');
$check((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_inmueble') . '`') === 0, 'general case does not write property history without a property');
$queue = $db->getResults('SELECT * FROM `' . $queueTable . '` ORDER BY id');
$destinations = array_column(array_filter($queue, fn($r) => $r['channel'] === 'email'), 'destination');
$check(in_array('admin@torres.invalid', $destinations, true) && in_array('assignee@example.invalid', $destinations, true), 'assignee and the community administrator are emailed');
$communityMail = array_values(array_filter($queue, fn($r) => $r['destination'] === 'admin@torres.invalid'))[0];
preg_match('/scm_case=([^"&]+)/', html_entity_decode((string) $communityMail['message_html']), $match);
$check(SCM\Support\PublicCaseAccess::route($match[1] ?? '', false)['audience'] === 'copropiedad' && str_contains((string) $communityMail['message_html'], 'Sin contrato'), 'community email links its public view and states there is no contract');
$withContract = $service->createPanelTicket(array_merge($base, ['copropiedad_id' => 61, 'contract_id' => 811]));
$row = $ticket((int) $withContract['ticket_id']);
$check($row['contrato'] === 'QA811' && $row['id_copropiedad'] === '61' && $row['correo_copropiedad'] === 'admin@torres.invalid', 'community case with contract keeps both');
$plain = $service->createPanelTicket(array_merge($base, ['contract_id' => 812, 'departamento' => 'Servicio al propietario']));
$check(trim((string) $ticket((int) $plain['ticket_id'])['id_copropiedad']) === '', 'ordinary contract cases are unchanged');

// Guardian y portal: búsqueda compartida.
$lookup = $service->lookup('copropiedad', 'Torres del Mar');
$rows = $lookup['rows'] ?? [];
$check(($rows[0]['id'] ?? '') === 'copropiedad:61' && ($rows[0]['type'] ?? '') === 'copropiedad' && str_contains($rows[0]['contrato'], 'sin contrato'), 'requester lookup offers the general case first');
$contractRow = array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') === '811'))[0] ?? [];
$check(($contractRow['contact_email'] ?? '') === 'admin@torres.invalid' && ($contractRow['contact_name'] ?? '') === 'Ana Administradora', 'contract row takes missing contact data from the community record');
$check(($service->lookup('copropiedad', '800999111')['rows'][0]['id'] ?? '') === 'copropiedad:62', 'community without contracts can be found by NIT');
$input = ['asunto' => 'Cuota de administración', 'descripcion' => 'Solicitamos paz y salvo', 'tema_ayuda' => '', 'copropiedad_record' => ['_ID' => 999, 'correo' => 'attacker@example.invalid']];
$created = $service->createTicket('copropiedad', 'copropiedad:61', 'Torres del Mar', $input);
$check(!empty($created['ok']), 'requester creates a general community case: ' . ($created['message'] ?? ''));
$row = $ticket((int) $created['ticket_id']);
$check($row['id_solicitante'] === '61' && $row['id_copropiedad'] === '61' && $row['correo_solicitante'] === 'admin@torres.invalid' && $row['solicitante'] === 'Ana Administradora', 'requester contact and community columns come from the record');
$check($row['correo_copropiedad'] !== 'attacker@example.invalid' && trim((string) $row['contrato']) === '' && $row['departamento'] === 'Servicio a la copropiedad', 'a forged community record is ignored');
$check(empty($service->createTicket('copropiedad', 'copropiedad:61', 'Otra cosa', $input)['ok']), 'general case must match the lookup');
$check(empty($service->createTicket('copropiedad', 'copropiedad:abc', 'Torres', $input)['ok']), 'malformed community id is rejected');
$viaContract = $service->createTicket('copropiedad', '811', 'Torres del Mar', $input);
$row = $ticket((int) $viaContract['ticket_id']);
$check(!empty($viaContract['ok']) && $row['contrato'] === 'QA811' && $row['id_copropiedad'] === '61' && $row['correo_solicitante'] === 'admin@torres.invalid', 'contract case of a community fills the contact from the record');
$requests = $service->lookupRequests('copropiedad', 'copropiedad:61', 'Torres del Mar');
$listed = array_values(array_filter($requests['rows'] ?? [], fn($r) => (string) $r['ticket_id'] === (string) $created['ticket_id']))[0] ?? [];
$check(!empty($requests['ok']) && $listed !== [] && $listed['origen'] === 'Copropiedad', 'general community row lists its own requests labeled Copropiedad');
$check(SCM\Support\PublicCaseAccess::route(explode('scm_case=', (string) $listed['ticket_url'])[1] ?? '', false)['audience'] === 'copropiedad', 'listed request links the new signed community view');
echo $checks . ' checks passed' . PHP_EOL;
