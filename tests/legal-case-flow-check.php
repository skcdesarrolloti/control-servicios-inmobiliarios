<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\LegalCases\LegalCaseService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};
$fails = static function (callable $work, string $expected) use ($check): void {
  try {
    $work();
  } catch (\DomainException $error) {
    $check(str_contains($error->getMessage(), $expected), 'Rechaza: ' . $expected);
    return;
  }
  $check(false, 'Debió rechazar: ' . $expected);
};

/** SQLite con el DDL real de MySQL traducido, para comprobar las columnas que crea ensureSchema(). */
final class LegalCaseTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }

  public function exec(string $statement): int|false
  {
    if ($this->inTransaction() && preg_match('/^\s*(CREATE|ALTER|DROP)\s/i', $statement) === 1) throw new RuntimeException('DDL inside a transaction: ' . substr(trim($statement), 0, 60));
    if (str_contains($statement, 'ENGINE=InnoDB')) {
      $statement = str_replace(['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['INTEGER PRIMARY KEY AUTOINCREMENT', ''], $statement);
      $statement = (string) preg_replace('/,\s*UNIQUE KEY \w+ \((\w+)\)/', ', UNIQUE ($1)', $statement);
      $statement = (string) preg_replace('/,\s*KEY \w+ \(\w+\)/', '', $statement);
    }
    return parent::exec($statement);
  }
}

$pdo = new LegalCaseTestPdo();
$db = new Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_confi_sistema(_ID INTEGER PRIMARY KEY, funcion TEXT, valor TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios(_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, correo TEXT, celular TEXT, id_cargo TEXT, activo TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_funcionarios VALUES (7, '5007', 'Ana Contractual', 'ana@example.com', '3001112222', '7', 'Si'), (8, '5008', 'Retirado', 'r@example.com', '3001112223', '7', 'No')");
$pdo->exec('CREATE TABLE wp_jet_cct_contratos_arrendamiento(_ID INTEGER PRIMARY KEY, contrato TEXT, inmueble TEXT, direccion TEXT, propietario TEXT, id_propietario TEXT, correo_propietario TEXT, celular_propietario TEXT, indicativo_propietario TEXT, arrendatario TEXT, id_arrendatario TEXT, correo_arrendatario TEXT, celular_arrendatario TEXT, indicativo_arrendatario TEXT, id_contrato_mandato TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_contratos_arrendamiento VALUES (9, 'C-100', '401', 'Calle 1 # 2-3', 'Pedro Dueño', '', 'pedro@example.com', '3101234567', '57', 'Laura Inquilina', '', 'laura@example.com', '3117654321', '57', '')");
$pdo->exec('CREATE TABLE wp_jet_cct_inmuebles(_ID INTEGER PRIMARY KEY, codigo TEXT, direccion TEXT)');
$pdo->exec("INSERT INTO wp_jet_cct_inmuebles VALUES (1, 'INM-55', 'Carrera 9 # 8-7')");

$settings = new Settings($db);
$service = new LegalCaseService($db, $settings);
$service->ensureSchema();
$service->ensureSchema();
$actor = ['id' => '5007', 'name' => 'Ana Contractual', 'email' => 'ana@example.com', 'phone' => '3001112222', 'cargo' => 'Contractual'];

$types = $service->types(true);
$check(count($types) === 6 && in_array('Siniestro', array_column($types, 'name'), true), 'Crea los tipos iniciales una sola vez.');
$typeId = $types[0]['id'];
$check(array_column($service->responsibles(), 'id') === ['5007'], 'Solo ofrece funcionarios activos con su id_empleado real.');

$parties = $service->contractParties(9);
$check(count($parties) === 2 && $parties[0]['role'] === 'propietario' && $parties[1]['role'] === 'arrendatario' && $parties[1]['phone'] === '+573117654321', 'Precarga propietario y arrendatario del contrato.');

$base = ['name' => 'Caso incendio apto 401', 'type_id' => $typeId, 'description' => 'Hechos', 'contract_id' => 9, 'responsible_id' => '5007', 'opened_date' => '2026-11-01', 'due_date' => '2026-11-20', 'next_action' => 'Llamar'];
$id = $service->create($base, $parties, $actor);
$check($id === 1 && LegalCaseService::code($id) === 'CASO-0001', 'El consecutivo sale del id.');
$detail = $service->detail($id);
$check($detail['case']['contract_code'] === 'C-100' && $detail['case']['property'] === '401' && $detail['case']['responsible_name'] === 'Ana Contractual', 'Toma contrato, inmueble y responsable del servidor.');
$check(count($detail['parties']) === 2 && $detail['events'][0]['action'] === 'caso_creado', 'Guarda partes y deja el evento de creación.');

$fails(static fn() => $service->create(['name' => ''] + $base, [], $actor), 'nombre del caso');
$fails(static fn() => $service->create(['type_id' => 999] + $base, [], $actor), 'tipo de caso');
$fails(static fn() => $service->create(['responsible_id' => '5008'] + $base, [], $actor), 'responsable activo');
$fails(static fn() => $service->create(['due_date' => '2026-10-01'] + $base, [], $actor), 'anterior a la apertura');
$fails(static fn() => $service->create(['opened_date' => '2026-13-40'] + $base, [], $actor), 'no es válida');
$fails(static fn() => $service->create($base, [['role' => 'tercero', 'name' => 'X', 'email' => 'malo']], $actor), 'correo de X');
$fails(static fn() => $service->create($base, [['role' => 'jefe', 'name' => 'X']], $actor), 'Rol de la parte');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_cases') === 1, 'Lo rechazado no deja casos.');

$service->update($id, ['next_action' => 'Enviar carta'] + $base, $actor);
$service->update($id, ['next_action' => 'Enviar carta'] + $base, $actor);
$edited = array_values(array_filter($service->detail($id)['events'], static fn(array $event): bool => $event['action'] === 'caso_editado'));
$check(count($edited) === 1 && $edited[0]['details']['cambios'][0]['despues'] === 'Enviar carta', 'Registra el cambio y no repite eventos si no hubo cambios.');

$service->changeStatus($id, 'en_tramite', 'Inicia gestión', $actor);
$fails(static fn() => $service->changeStatus($id, 'en_tramite', '', $actor), 'ya está en ese estado');
$fails(static fn() => $service->changeStatus($id, 'perdido', '', $actor), 'Estado no válido');
$service->changeStatus($id, 'cerrado', '', $actor);
$check((int) $db->getVar('SELECT closed_at FROM wp_scm_legal_cases WHERE id = 1') > 0, 'Registra la fecha de cierre.');
$service->changeStatus($id, 'abierto', 'Se reabre', $actor);
$check($db->getVar('SELECT closed_at FROM wp_scm_legal_cases WHERE id = 1') === null, 'Reabrir limpia la fecha de cierre.');

$partyId = $service->addParty($id, ['role' => 'tercero', 'name' => 'Gigante del Hogar', 'company' => 'Gigante SAS', 'email' => 'GERENCIA@example.com', 'phone' => '3205556666'], $actor);
$check($service->detail($id)['parties'][2]['email'] === 'gerencia@example.com', 'Normaliza el correo de la parte.');
$service->removeParty($id, $partyId, $actor);
$check(count($service->detail($id)['parties']) === 2 && (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_case_parties') === 3, 'Retirar una parte conserva el registro.');
$fails(static fn() => $service->removeParty($id, $partyId, $actor), 'ya no está');

$second = $service->create(['name' => 'Reclamación ascensor', 'contract_id' => 0, 'property' => 'INM-55', 'address' => 'Carrera 9 # 8-7', 'due_date' => '2020-01-01', 'opened_date' => '2020-01-01'] + $base, [['role' => 'tercero', 'name' => 'Ascensores Ltda', 'email' => 'a@example.com']], $actor);
$check($service->searchProperties('INM-5')[0]['code'] === 'INM-55', 'Busca inmuebles sin contrato.');
$check($service->list(['search' => 'Ascensores'])['rows'][0]['id'] === $second, 'Busca por parte.');
$check($service->list(['search' => 'caso-0002'])['rows'][0]['id'] === $second, 'Busca por código.');
$check($service->list(['search' => '0001'])['rows'][0]['id'] === $id, 'Busca por número.');
$check($service->list(['status' => 'abierto', 'property' => 'INM-55'])['total'] === 1, 'Filtra por estado e inmueble.');
$check($service->list(['type_id' => '999'])['total'] === 0 && $service->list(['responsible' => '5007'])['total'] === 2, 'Filtra por tipo y responsable.');
$list = $service->list([]);
$check($list['kpis']['abierto'] === 2 && $list['kpis']['vencidos'] >= 1 && $list['rows'][0]['overdue'] === true, 'Calcula indicadores y marca vencidos.');
for ($i = 0; $i < 30; $i++) $service->create(['name' => 'Masivo ' . $i, 'contract_id' => 0, 'property' => '', 'address' => ''] + $base, [], $actor);
$paged = $service->list([], 2);
$check($paged['total'] === 32 && $paged['pages'] === 2 && count($paged['rows']) === 7, 'Pagina los resultados.');
$check($service->list([], 99)['page'] === 2, 'Una página inexistente cae en la última.');

$service->addNote($id, 'Nota privada', $actor);
$fails(static fn() => $service->addNote($id, '   ', $actor), 'Escribe la nota');
$fails(static fn() => $service->detail(9999), 'no existe');

$file = ['original_name' => 'carta.pdf', 'name' => 'abc_1.pdf', 'mime' => 'application/pdf', 'bytes' => 2048, 'sha256' => str_repeat('a', 64)];
$reply = ['channel' => 'Correo', 'from' => 'Gigante', 'subject' => 'Re: daño', 'received_date' => date('Y-m-d'), 'body' => 'Aceptamos'];
$service->addManualReply($id, $reply, [$file], $actor);
$detail = $service->detail($id);
$last = $detail['events'][count($detail['events']) - 1];
$check($last['action'] === 'respuesta_registrada' && $last['details']['texto'] === 'Aceptamos' && $detail['documents'][0]['event_id'] === $last['id'] && $detail['documents'][0]['sha256'] === str_repeat('a', 64), 'La respuesta queda en la línea de tiempo con su adjunto y hash.');
$fails(static fn() => $service->addManualReply($id, ['channel' => 'Paloma'] + $reply, [], $actor), 'por dónde llegó');
$fails(static fn() => $service->addManualReply($id, ['from' => ''] + $reply, [], $actor), 'quién respondió');
$fails(static fn() => $service->addManualReply($id, ['received_date' => date('Y-m-d', strtotime('+2 days'))] + $reply, [], $actor), 'futura');
$fails(static fn() => $service->addManualReply($id, ['body' => ''] + $reply, [], $actor), 'contenido');
$service->addDocuments($id, [$file], $actor);
$detail = $service->detail($id);
$last = $detail['events'][count($detail['events']) - 1];
$check($last['action'] === 'documento' && $last['details']['archivos'] === ['carta.pdf'] && count($detail['documents']) === 2, 'Los documentos cargados dejan evento.');
$fails(static fn() => $service->addDocuments($id, [], $actor), 'al menos un archivo');

// --- Comunicaciones
define('SCM_APP_SECRET', 'secreto-de-prueba');
define('SCM_BASE_URL', 'https://sucasainmobiliaria.com.co/panel');

/** Cola en memoria: comprueba qué se encola sin enviar nada. */
final class FakeGateway extends \SCM\Modules\LegalCases\LegalDeliveryGateway
{
  public array $emails = [];
  public array $whatsapp = [];
  public bool $failWhatsApp = false;
  public array $queue = [];
  public function ready(): bool { return true; }
  public function email(string $to, string $subject, string $html, array $options): bool { $this->emails[] = compact('to', 'subject', 'html', 'options'); return true; }
  public function whatsapp(string $phone, string $name, string $message, array $meta): bool { if ($this->failWhatsApp) return false; $this->whatsapp[] = compact('phone', 'name', 'message', 'meta'); return true; }
  public function lookup(string $dedupePrefix): ?array { return $this->queue[$dedupePrefix] ?? null; }
}

use SCM\Modules\LegalCases\LegalCommunicationService;
use SCM\Modules\LegalCases\LegalHtmlSanitizer;

$clean = LegalHtmlSanitizer::clean('<p onclick="x()">Hola&nbsp;<script>alert(1)</script><strong style="color:red">ñandú</strong><img src=x onerror=alert(1)><a href="javascript:alert(1)">malo</a> <a href="https://x.co/a">bien</a></p><div>suelto</div>');
$check(!preg_match('/script|onclick|onerror|style=|javascript|<img|<div/i', $clean) && str_contains($clean, '<a href="https://x.co/a" rel="noopener noreferrer" target="_blank">bien</a>') && str_contains($clean, 'malo') && str_contains($clean, 'suelto'), 'El sanitizador deja solo lo permitido.');
$check(str_contains(html_entity_decode($clean, ENT_QUOTES, 'UTF-8'), 'ñandú') && str_contains($clean, 'Hola '), 'Conserva acentos y normaliza espacios duros.');
$check(LegalHtmlSanitizer::isEmpty('<p> <br></p>') && !LegalHtmlSanitizer::isEmpty('<p>x</p>'), 'Detecta contenido vacío.');

$uploadDir = sys_get_temp_dir() . '/legal-case-test-' . bin2hex(random_bytes(4));
mkdir($uploadDir);
$stored = str_repeat('b', 24) . '_1.pdf';
file_put_contents($uploadDir . '/' . $stored, '%PDF-prueba');
$hash = hash_file('sha256', $uploadDir . '/' . $stored);
$files = new \SCM\Support\StoredFileService($uploadDir, SCM_BASE_URL, SCM_APP_SECRET, 1048576);
$gateway = new FakeGateway($db);
$comms = new LegalCommunicationService($db, $service, $files, $gateway);

$caseId = $service->create($base, $service->contractParties(9), $actor);
$service->addDocuments($caseId, [['original_name' => 'acta.pdf', 'name' => $stored, 'mime' => 'application/pdf', 'bytes' => 11, 'sha256' => $hash]], $actor);
$detail = $service->detail($caseId);
$owner = $detail['parties'][0];
$tenant = $detail['parties'][1];
$docId = $detail['documents'][0]['id'];
$send = ['channels' => ['email', 'whatsapp'], 'subject' => 'Aviso {{codigo_caso}} para {{nombre_destinatario}}', 'body' => '<p>Señor(a) {{nombre_destinatario}}, contrato {{numero_contrato}} en {{direccion_inmueble}}.<script>x</script></p>', 'template_id' => 0, 'document_ids' => [$docId],
  'recipients' => [['party_id' => $owner['id'], 'kind' => 'to', 'email' => 'falso@example.com'], ['party_id' => $tenant['id'], 'kind' => 'to'], ['name' => 'Abogado <b>', 'email' => 'abogado@example.com', 'kind' => 'cc'], ['name' => '', 'email' => 'ADICIONAL@example.com', 'kind' => 'to']]];

$preview = $comms->preview($caseId, $send, $actor);
$check($gateway->emails === [] && (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_communications') === 0, 'La vista previa no guarda ni encola.');
$check($preview['subject'] === 'Aviso ' . LegalCaseService::code($caseId) . ' para Pedro Dueño' && $preview['email_count'] === 3 && $preview['whatsapp_count'] === 2 && str_contains($preview['html'], 'contrato C-100'), 'La vista previa muestra el resultado real.');

$result = $comms->send($caseId, $send, [], $actor);
$check($result['email'] === 3 && $result['whatsapp'] === 2, 'Encola un correo y un WhatsApp por destinatario.');
$check($gateway->emails[0]['to'] === 'pedro@example.com', 'Usa el correo de la parte guardada, no el que manda el navegador.');
$check($gateway->emails[0]['options']['payload']['reply_to'] === 'ana@example.com', 'Las respuestas van al correo del funcionario.');
$check($gateway->emails[0]['options']['payload']['cc'] === ['abogado@example.com'] && $gateway->emails[1]['options']['payload']['cc'] === [], 'La copia va una sola vez.');
$check($gateway->emails[0]['options']['payload']['attachments'][0]['name'] === 'acta.pdf' && is_readable($gateway->emails[0]['options']['payload']['attachments'][0]['path']), 'Adjunta el archivo real.');
$check(!str_contains($gateway->emails[0]['html'], '<script') && str_contains($gateway->emails[0]['html'], 'Señor(a) Pedro Dueño') || str_contains(html_entity_decode($gateway->emails[0]['html'], ENT_QUOTES, 'UTF-8'), 'Señor(a) Pedro Dueño'), 'Personaliza el contenido por destinatario sin scripts.');
$check($gateway->emails[1]['subject'] === 'Aviso ' . LegalCaseService::code($caseId) . ' para Laura Inquilina', 'El asunto también se personaliza.');
$check($gateway->emails[2]['to'] === 'adicional@example.com', 'Acepta destinatarios escritos a mano.');

$wa = $gateway->whatsapp[0]['meta'];
$bodyParams = array_column($wa['template_components'][0]['parameters'], 'text');
$check($wa['template_name'] === 'scm_comunicacion_caso_v1' && count($bodyParams) === 4 && $bodyParams[3] === 'Ana Contractual - Contractual - Cel. 3001112222', 'WhatsApp usa la plantilla y la firma con guiones y celular nacional.');
$suffix = $wa['template_components'][1]['parameters'][0]['text'];
$check(str_starts_with($suffix, 'panel/comunicacion-caso.php?numero='), 'El botón lleva la ruta relativa al dominio de la plantilla.');
parse_str((string) parse_url($suffix, PHP_URL_QUERY), $query);
$check(LegalCommunicationService::validLink((int) $query['numero'], (int) $query['expires'], $query['sig']), 'El enlace del botón es válido.');
$check(!LegalCommunicationService::validLink((int) $query['numero'] + 1, (int) $query['expires'], $query['sig']), 'Cambiar el número invalida el enlace.');
$check(!LegalCommunicationService::validLink((int) $query['numero'], time() - 5, LegalCommunicationService::linkSignature((int) $query['numero'], time() - 5)), 'Un enlace vencido no sirve.');
$public = $comms->publicView((int) $query['numero']);
$check(str_contains(html_entity_decode($public['html'], ENT_QUOTES, 'UTF-8'), 'Pedro Dueño') && $public['attachments'][0]['name'] === 'acta.pdf', 'La vista pública muestra el contenido de ese destinatario.');
$check(!str_contains(json_encode($public), 'sucursal'), 'La vista pública no incluye sucursal.');
$check(str_contains(html_entity_decode($public['html'], ENT_QUOTES, 'UTF-8'), 'SKC SuCasa Inmobiliaria'), 'La firma usa el nombre corporativo.');

$communicationId = $result['communication_id'];
$row = $db->getRow('SELECT * FROM wp_scm_legal_communications WHERE id = ?', [$communicationId]);
$check($row['sender_id'] === '5007' && $row['whatsapp_template'] === 'scm_comunicacion_caso_v1' && json_decode($row['attachments_json'], true)[0]['sha256'] === $hash, 'Guarda quién envió, la plantilla y el hash de los adjuntos.');
$recipients = $db->getResults('SELECT * FROM wp_scm_legal_communication_recipients WHERE communication_id = ? ORDER BY id', [$communicationId]);
$check(count($recipients) === 6 && count(array_filter($recipients, static fn($r) => $r['kind'] === 'cc')) === 1 && $recipients[0]['sent_html'] !== '' && json_decode($recipients[2]['whatsapp_params'], true)['template'] === 'scm_comunicacion_caso_v1', 'Guarda cada destinatario con el contenido exacto.');
$events = $service->detail($caseId)['events'];
$last = $events[count($events) - 1];
$check($last['action'] === 'comunicacion_enviada' && $last['details']['communication_id'] === $communicationId, 'La comunicación entra a la línea de tiempo.');

$prefix = $recipients[0]['dedupe_key'];
$gateway->queue[$prefix] = ['status' => 'sent', 'error' => '', 'sent_at' => 1762000000, 'provider_id' => ''];
$waPrefix = $recipients[2]['dedupe_key'];
$gateway->queue[$waPrefix] = ['status' => 'failed', 'error' => 'Número inválido', 'sent_at' => 0, 'provider_id' => ''];
$view = $comms->communication($communicationId);
$check($view['recipients'][0]['status'] === 'enviado' && $view['recipients'][0]['status_label'] === 'Aceptado por el servidor' && $view['recipients'][1]['status'] === 'enviado', 'El estado llega de la cola y alcanza también a la copia.');
$check($view['recipients'][2]['status'] === 'fallido' && $view['recipients'][2]['error'] === 'Número inválido', 'Registra los fallos.');
$before = $db->getRow('SELECT rendered_html, sent_html, destination FROM wp_scm_legal_communication_recipients WHERE id = ?', [$recipients[0]['id']]);
$gateway->queue[$prefix]['status'] = 'failed';
$comms->communication($communicationId);
$check($db->getVar('SELECT status FROM wp_scm_legal_communication_recipients WHERE id = ?', [$recipients[0]['id']]) === 'enviado' && $db->getRow('SELECT rendered_html, sent_html, destination FROM wp_scm_legal_communication_recipients WHERE id = ?', [$recipients[0]['id']]) === $before, 'Un estado final no se reescribe y el contenido no cambia.');

$counts = static fn(): array => [(int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_communications'), (int) $db->getVar('SELECT COUNT(*) FROM wp_scm_legal_case_events')];
$snapshot = $counts();
$gateway->failWhatsApp = true;
$fails(static fn() => $comms->send($caseId, $send, [], $actor), 'No se envió nada');
$gateway->failWhatsApp = false;
$check($counts() === $snapshot, 'Si un destinatario falla al encolar, se revierte todo.');

$fails(static fn() => $comms->send($caseId, ['channels' => []] + $send, [], $actor), 'al menos un canal');
$fails(static fn() => $comms->send($caseId, ['subject' => ''] + $send, [], $actor), 'asunto');
$fails(static fn() => $comms->send($caseId, ['body' => '<p> </p>'] + $send, [], $actor), 'contenido');
$fails(static fn() => $comms->send($caseId, ['body' => '<p>{{inventada}}</p>'] + $send, [], $actor), 'Variable no reconocida');
$fails(static fn() => $comms->send($caseId, ['recipients' => []] + $send, [], $actor), 'destinatario principal');
$fails(static fn() => $comms->send($caseId, ['recipients' => [['party_id' => 99999]]] + $send, [], $actor), 'ya no está');
$fails(static fn() => $comms->send($caseId, ['recipients' => [['email' => 'x@example.com', 'kind' => 'cc']]] + $send, [], $actor), 'destinatario principal');
$fails(static fn() => $comms->send($caseId, ['recipients' => [['phone' => '3001112222', 'kind' => 'to'], ['email' => 'x@example.com', 'kind' => 'cc']], 'channels' => ['whatsapp']] + $send, [], $actor), 'no se puede poner en copia');
$fails(static fn() => $comms->send($caseId, ['recipients' => [['name' => 'Solo correo', 'email' => 'x@example.com']], 'channels' => ['whatsapp']] + $send, [], $actor), 'no tiene datos de contacto');
$fails(static fn() => $comms->send($caseId, ['recipients' => [['email' => 'malo']]] + $send, [], $actor), 'no es válido');
$fails(static fn() => $comms->send($caseId, ['document_ids' => [99999]] + $send, [], $actor), 'no pertenece al caso');
$fails(static fn() => $comms->send($caseId, $send, [], ['email' => ''] + $actor), 'correo válido');
$noContract = $service->create(['contract_id' => 0, 'property' => '', 'address' => ''] + $base, [['role' => 'tercero', 'name' => 'T', 'email' => 't@example.com']], $actor);
$fails(static fn() => $comms->send($noContract, ['document_ids' => [], 'recipients' => [['email' => 't@example.com']]] + $send, [], $actor), 'no tiene contrato');
file_put_contents($uploadDir . '/' . $stored, 'alterado');
$fails(static fn() => $comms->send($caseId, $send, [], $actor), 'cambió desde que se cargó');
unlink($uploadDir . '/' . $stored);
$fails(static fn() => $comms->send($caseId, $send, [], $actor), 'No se encontró el archivo');
$check($counts()[0] === $snapshot[0], 'Lo rechazado no deja comunicaciones.');

$soloWa = $comms->send($caseId, ['channels' => ['whatsapp'], 'document_ids' => [], 'recipients' => [['party_id' => $tenant['id']]]] + $send, [], ['email' => ''] + $actor);
$check($soloWa['email'] === 0 && $soloWa['whatsapp'] === 1, 'Solo WhatsApp no exige correo del funcionario.');

$check($comms->list(['recipient' => 'abogado'])['total'] === 1 && $comms->list(['recipient' => 'nadie'])['total'] === 0, 'Busca comunicaciones por destinatario.');
$check($comms->list(['case' => (string) $caseId])['total'] === 2 && $comms->list(['channel' => 'email'])['total'] === 1 && $comms->list(['channel' => 'whatsapp'])['total'] === 2, 'Filtra por caso y canal.');
$check($comms->list(['from' => date('Y-m-d', strtotime('+2 days'))])['total'] === 0 && $comms->list(['to' => date('Y-m-d', strtotime('+2 days'))])['total'] === 2, 'Filtra por fechas.');
$fails(static fn() => $comms->list(['from' => 'ayer']), 'fecha del filtro');
$listed = $comms->list(['channel' => 'email'])['rows'][0];
$check($listed['failed'] === 1 && $listed['recipients'] === ['Pedro Dueño', 'Laura Inquilina', 'adicional@example.com'], 'Resume destinatarios y fallos.');

$templateId = $comms->saveTemplate(0, ['name' => 'Requerimiento', 'subject' => 'Caso {{codigo_caso}}', 'body' => '<p onclick="x">{{fecha}}</p>', 'active' => true], $actor);
$check(!str_contains($comms->templates(true)[0]['body_html'], 'onclick'), 'Las plantillas se sanitizan.');
$comms->saveTemplate($templateId, ['name' => 'Requerimiento', 'subject' => 'x', 'body' => '<p>y</p>', 'active' => false], $actor);
$check($comms->templates(true) === [] && count($comms->templates()) === 1, 'Una plantilla inactiva deja de ofrecerse.');
$fails(static fn() => $comms->saveTemplate(0, ['name' => 'X', 'subject' => '', 'body' => '<p>{{otra}}</p>'], $actor), 'Variable no reconocida');
$service->saveType(0, 'Tutela', true);
$fails(static fn() => $service->saveType(0, 'tutela', true), 'Ya existe');
$tutela = array_values(array_filter($service->types(), static fn($t) => $t['name'] === 'Tutela'))[0];
$service->saveType($tutela['id'], 'Tutela', false);
$check(!in_array('Tutela', array_column($service->types(true), 'name'), true), 'Un tipo inactivo deja de ofrecerse.');
$handlersSource = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/HandlesLegalCases.php');
$check(!preg_match('/ajax_handler_legal_case_(?:communication_)?(?:edit|delete|update)\b/', $handlersSource), 'No existe acción para editar o borrar comunicaciones.');
// --- Constancias
$fullComm = $comms->communication($communicationId);
$pdfHtml = \SCM\Modules\LegalCases\LegalCasePdf::communicationHtml($fullComm, 'Ana <Contractual>');
$check(str_contains($pdfHtml, 'Aceptado por el servidor') && str_contains($pdfHtml, 'no los certifica') && str_contains($pdfHtml, 'Número inválido') && str_contains($pdfHtml, $hash) && str_contains($pdfHtml, 'abogado@example.com'), 'La constancia trae estados, destinatarios, hash y la aclaración de entrega.');
$check(str_contains($pdfHtml, 'Ana &lt;Contractual&gt;') && !str_contains($pdfHtml, '<script') && substr_count($pdfHtml, 'Contenido enviado a') === 3, 'Escapa los datos y muestra el contenido de cada destinatario una sola vez.');
$casePdf = \SCM\Modules\LegalCases\LegalCasePdf::caseHtml($service->detail($caseId), $comms->forCase($caseId), 'Ana');
$check(str_contains($casePdf, 'Línea de tiempo') && str_contains($casePdf, 'Comunicación enviada') && str_contains($casePdf, 'SKC SuCasa Inmobiliaria') && !str_contains($casePdf, 'ucursal'), 'La constancia del caso es completa y no muestra sucursal.');

// --- Recordatorios
$dueId = $service->create(['name' => 'Vence pronto', 'due_date' => '2026-12-10', 'opened_date' => '2026-12-01', 'contract_id' => 0, 'property' => '', 'address' => ''] + $base, [], $actor);
$forDue = static fn(string $today): array => array_values(array_filter($service->pendingDueReminders($today), static fn(array $item): bool => $item['case']['id'] === $dueId));
$check($forDue('2026-12-06') === [], 'Más de tres días antes no avisa.');
$soon = $forDue('2026-12-07');
$check(count($soon) === 1 && $soon[0]['stage'] === 'proxima', 'Avisa tres días antes.');
$service->event($dueId, 'recordatorio_fecha_limite', ['id' => '', 'name' => 'Sistema'], ['fecha_limite' => '2026-12-10', 'etapa' => 'proxima']);
$check($forDue('2026-12-08') === [], 'No repite el aviso.');
$check($forDue('2026-12-10')[0]['stage'] === 'vencimiento' && $forDue('2026-12-15')[0]['stage'] === 'vencimiento', 'Avisa al cumplirse, también si ese día no corrió.');
$service->event($dueId, 'recordatorio_fecha_limite', ['id' => '', 'name' => 'Sistema'], ['fecha_limite' => '2026-12-10', 'etapa' => 'vencimiento']);
$check($forDue('2026-12-11') === [], 'El aviso de vencimiento sale una vez.');
$service->update($dueId, ['name' => 'Vence pronto', 'due_date' => '2026-12-20', 'opened_date' => '2026-12-01', 'contract_id' => 0, 'property' => '', 'address' => ''] + $base, $actor);
$check(count($forDue('2026-12-18')) === 1, 'Si cambia la fecha vuelve a avisar.');
$service->changeStatus($dueId, 'cerrado', '', $actor);
$check($forDue('2026-12-18') === [] && !in_array($dueId, array_column(array_column($service->pendingDueReminders('2026-12-30'), 'case'), 'id'), true), 'Un caso cerrado no avisa.');

array_map('unlink', glob($uploadDir . '/*') ?: []);
rmdir($uploadDir);

$root = dirname(__DIR__);
foreach (['legal_case_manage', 'legal_case_send', 'legal_case_config', 'legal_case_export'] as $action) {
  $check(str_contains((string) file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php'), "'{$action}' =>"), "La acción {$action} está en el catálogo.");
}
$handlers = (string) file_get_contents($root . '/src/App/Concerns/HandlesLegalCases.php');
$router = (string) file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
preg_match_all('/function (ajax_handler_legal_case_\w+)/', $handlers, $matches);
foreach ($matches[1] as $handler) $check(str_contains($router, "'{$handler}'"), "La ruta de {$handler} está registrada.");

echo "Casos jurídicos: verificación completa.\n";
