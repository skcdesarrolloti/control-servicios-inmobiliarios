<?php

declare(strict_types=1);

/** php tests/ticket-completion-check.php [--database]
 * Domain checks are offline. Integration checks use connection-scoped TEMPORARY tables only.
 * They never create tickets, charges or notifications in the application's tables.
 */
use SCM\Core\Database;
use SCM\Modules\TicketCompletion\CompletionPolicy as Policy;
use SCM\Modules\TicketCompletion\CompletionRepository as Repository;
use SCM\Modules\TicketCompletion\CompletionService as Service;
use SCM\Modules\TicketCompletion\CompletionView as View;
use SCM\Support\SchemaInspector;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++;
  echo 'PASS: ' . $label . PHP_EOL;
};
$rejects = static function (callable $fn, string $label) use ($assert): void {
  try { $fn(); } catch (DomainException) { $assert(true, $label); return; }
  $assert(false, $label);
};
$assert(Policy::number('0.10') === 0.1, 'decimal percentage is not multiplied by ten');
$assert(Policy::number('1.300.000,50') === 1300000.5, 'Colombian amount normalization');
$assert(Policy::fee(['salario' => '1300000', 'dias_trabajo' => '30', 'porcentaje_smlmv_co_pre' => '0.10']) === 4333, 'configured fee uses decimal percentage');
$assert(Policy::fee(['salario' => '1300000', 'dias_trabajo' => '30', 'porcentaje_smlmv_co_pre' => '10']) === 4333, 'percentage points supported');
$assert(Policy::fee(['salario' => '1300000', 'dias_trabajo' => '0']) === null, 'missing configuration requires explicit value');
$assert(Policy::transportBase(['valor_transporte' => '4.000']) === 4000 && Policy::transportMaximum(['valor_transporte' => '4.000']) === 8000, 'transport defaults and caps at twice configured value');
$assert(Policy::transportMaximum(['valor_transporte' => '']) === null, 'missing transport configuration has no chargeable maximum');
$rejects(static fn() => Policy::number('-100'), 'negative fee rejected');
$rejects(static fn() => Policy::items([['damage' => 'Humedad', 'solution' => '']]), 'solution required for every damage');
$photoDescriptor = ['name' => str_repeat('a', 24) . '_123.jpg', 'mime' => 'image/jpeg', 'width' => 1200, 'height' => 900, 'bytes' => 350000, 'sha256' => str_repeat('b', 64)];
$assert(Policy::items([['damage' => 'Humedad', 'solution' => 'Sellado', 'photos' => [$photoDescriptor]]])[0]['photos'][0]['width'] === 1200, 'compressed photo metadata accepted');
$rejects(static fn() => Policy::items([['damage' => 'Humedad', 'solution' => 'Sellado', 'photos' => [array_replace($photoDescriptor, ['bytes' => 1500001])]]]), 'oversized photo evidence rejected');
$rejects(static fn() => Policy::items([['damage' => 'Humedad', 'solution' => 'Sellado', 'photos' => [array_replace($photoDescriptor, ['name' => '../otro.jpg'])]]]), 'unsafe photo path rejected');
$rejects(static fn() => Policy::signature(['signature_name' => 'Ana Pérez'], 'Ana Pérez'), 'opening a link never constitutes consent');
$rejects(static fn() => Policy::signature(['signature_name' => 'Otra persona', 'accepted' => '1'], 'Ana Pérez'), 'signature must match selected name');
$drawing = json_encode([[[120, 200], [160, 90], [190, 190], [130, 170], [230, 140], [240, 210], [280, 140], [310, 200], [360, 160]]]);
$assert(count(Policy::strokes($drawing)[0]) === 9, 'numeric signature strokes accepted');
$rejects(static fn() => Policy::strokes('[]'), 'blank signature rejected');
$rejects(static fn() => Policy::strokes('[[[0,0],[99999,2]]]'), 'out of bounds signature rejected');
$rejects(static fn() => Policy::strokes('<svg onload=alert(1)>'), 'executable drawing data rejected');
$assert(Policy::phone('300 123 4567') === '+573001234567', 'Colombian phone normalized');
$assert(Policy::phone('120363@g.us') === '', 'group WhatsApp recipients prohibited for personal signature');
$assert(Policy::executionState('inmobiliaria') === 'En ejecucion por inmobiliaria', 'executor maps to an allowed administrative execution state');
$adminJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/scm-admin.js');
$assert(str_contains($adminJs, 'data-acta-remove-photo') && str_contains($adminJs, 'form.addEventListener("paste"'), 'act photo UI supports individual removal and pasted clipboard images');
$assert(str_contains($adminJs, 'MAX_PHOTOS_PER_DAMAGE = 4') && str_contains($adminJs, 'MAX_PHOTOS_PER_ACT = 12'), 'act photo UI enforces visible client limits');

if (!in_array('--database', $argv, true)) { echo "$checks domain checks passed. Use --database for isolated SQL integration checks.\n"; exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1); });
$prefix = 'scmqa_' . bin2hex(random_bytes(5)) . '_';
$db = new Database(\SCM\Core\App::db()->pdo(), $prefix);
$tables = ['jet_cct_tickets', 'jet_cct_actas_de_satisfaccion', 'jet_cct_reportes_administrativos', 'jet_cct_historial_del_ticket', 'jet_cct_confi_sistema', 'jet_cct_copropiedades', 'jet_cct_cotizacion_mantenimiento'];
foreach ($tables as $name) {
  $source = \SCM\Core\App::db()->table($name);
  $db->pdo()->exec('CREATE TEMPORARY TABLE `' . $db->table($name) . '` LIKE `' . $source . '`');
}
$repo = new Repository($db);
$db->pdo()->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $repo->schemaSql()));
$tables[] = 'scm_ticket_completion_acts';
// information_schema intentionally does not expose TEMPORARY tables; seed only the test inspector.
$exists = $columns = [];
foreach ($tables as $name) {
  $table = $db->table($name);
  $exists[$table] = true;
  $columns[$table] = $db->getCol('DESCRIBE `' . $table . '`');
}
foreach (['tableExistsCache' => $exists, 'tableColumnsCache' => $columns] as $property => $value) {
  (new ReflectionProperty(SchemaInspector::class, $property))->setValue($repo->schema, $value);
}
foreach (['salario' => '1300000', 'dias_trabajo' => '30', 'porcentaje_smlmv_co_pre' => '0.10', 'valor_transporte' => '4000'] as $key => $value) {
  $db->insert($db->table('jet_cct_confi_sistema'), ['funcion' => $key, 'valor' => $value]);
}
$seedTicket = static function (int $id) use ($db): void {
  $db->insert($db->table('jet_cct_tickets'), ['_ID' => $id, 'id_ticket' => (string) (9000 + $id), 'estado' => 'En proceso', 'estado_administrativo' => 'En ejecucion por propietario', 'id_acta_satisfaccion' => '', 'estado_acta_satisfaccion' => 'No', 'propietario' => 'Ana Pérez', 'correo_propietario' => 'ana@example.invalid', 'arrendatario' => 'Juan Prueba', 'correo_arrendatario' => 'juan@example.invalid', 'inmueble' => '10001', 'direccion' => 'Dirección de prueba', 'contrato' => '51']);
};
$notifications = [];
$service = new Service($repo, str_repeat('test-secret-', 4), 'http://127.0.0.1:8097', static function ($to, $subject, $html, $options) use (&$notifications): int {
  $notifications[] = compact('to', 'subject', 'html', 'options'); return 1;
});
$actor = ['user_id' => 1, 'employee_id' => '200', 'name' => 'Funcionario Prueba', 'cargo' => 'Coordinador QA', 'email' => 'actor@example.invalid', 'phone' => '3001234567'];
$input = ['signer_role' => 'propietario', 'signer_name' => 'Ana Pérez', 'executor' => 'inmobiliaria', 'items' => [['damage' => 'Fuga en tubería de cocina', 'solution' => 'Se reemplazó el tramo y se verificó presión.']], 'observations' => 'Sin filtración en la prueba final.', 'transport' => '8000', 'confirm' => '1'];
$photoName = bin2hex(random_bytes(12)) . '_' . time() . '.jpg';
$photoPath = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $photoName;
if (!is_dir((string) SCM_UPLOAD_PATH)) { mkdir((string) SCM_UPLOAD_PATH, 0750, true); }
if (!copy(dirname(__DIR__) . '/resources/assets/membrete-sucasa.jpg', $photoPath)) { throw new RuntimeException('Unable to create isolated photo fixture.'); }
register_shutdown_function(static function () use ($photoPath): void { if (is_file($photoPath)) { unlink($photoPath); } });
$photoInfo = getimagesize($photoPath);
$input['items'][0]['photos'] = [['name' => $photoName, 'mime' => 'image/jpeg', 'width' => $photoInfo[0], 'height' => $photoInfo[1], 'bytes' => filesize($photoPath), 'sha256' => hash_file('sha256', $photoPath)]];
$codes = [];
$signInput = static function (array $act) use (&$codes): array { return ['signature_name' => 'Ana Pérez', 'accepted' => '1', 'consent_version' => '3', 'otp_code' => $codes[$act['id']] ?? '', 'document_hash' => $act['payload_hash']]; };
$requestCode = static function (array $act, string $channel = 'email') use ($service, &$notifications, &$codes): array {
  $result = $service->requestCode((int) $act['id'], $service->token($act), $channel);
  $codes[$act['id']] = end($notifications)['options']['otp_code'];
  return $result;
};
$seedTicket(1);
$db->insert($db->table('jet_cct_cotizacion_mantenimiento'), $repo->schema->filterTableData($db->table('jet_cct_cotizacion_mantenimiento'), [
  '_ID' => 7001, 'id_ticket' => '9001', 'estado' => 'Pendiente', 'motivo' => '',
]));
$db->update($db->table('jet_cct_tickets'), ['id_cotizacion_mantenimiento' => '7001'], ['_ID' => 1]);
$createPanel = (new View())->panel($service->context(1), $service);
$assert(str_contains($createPanel, 'data-acta-photo-paste') && str_contains($createPanel, 'Máximo 4 fotos por daño y 12 en toda el acta'), 'act form explains limits and exposes the clipboard paste target');
$assert(str_contains($createPanel, 'type="hidden" name="transport" value="8000" data-acta-transport') && str_contains($createPanel, 'class="scm-acta-readonly-value"'), 'act form shows fixed configured transport without an editable number control');
$createdWithTamperedTransport = $service->create(1, array_replace($input, ['transport' => '1']), $actor);
$tamperedPayload = $service->payload($repo->act($createdWithTamperedTransport['act_id']));
$assert((int) $tamperedPayload['report']['transport'] === 8000, 'backend ignores tampered transport and uses configured fixed value');
$service->cancel($createdWithTamperedTransport['act_id'], 'QA vuelve a generar el acta principal', $actor);
$seedTicket(8);
$archived = $service->create(8, $input, $actor);
$archivedAct = $repo->act($archived['act_id']);
$service->archive((int) $archivedAct['id'], 'Acta creada durante pruebas', $actor);
$archivedAct = $repo->act((int) $archivedAct['id']);
$assert($archivedAct['status'] === 'archived' && (string) $archivedAct['active_slot'] === '' && $repo->ticket(8)['estado'] === 'En proceso', 'archiving a test act removes it from pending without closing the ticket');
$rejects(static fn() => $service->publicAct((int) $archivedAct['id'], $service->token($archivedAct)), 'archived act public link cannot be signed');
$dashboardArchived = $service->dashboardList(['sacta_estado' => 'archived', 'sacta_caso' => '9008']);
$assert($dashboardArchived['count'] === 1 && ($dashboardArchived['stats']['archived'] ?? 0) === 1, 'archived acts dashboard filter and KPI include the archived test act');
$notifications = [];
$created = $service->create(1, $input, $actor);
$act = $repo->act($created['act_id']);
$token = $service->token($act);
$assert($repo->ticket(1)['estado'] === 'En proceso' && $repo->ticket(1)['estado_administrativo'] === 'En ejecucion por inmobiliaria', 'creating an act keeps the selected administrative execution state');
$dashboardList = $service->dashboardList(['sacta_estado' => 'pending', 'sacta_caso' => '9001']);
$assert($dashboardList['count'] === 1 && ($dashboardList['items'][0]['_payload']['ticket_number'] ?? '') === '9001', 'unsigned acts dashboard lists the pending act by ticket without changing the administrative state');
$assert($db->getVar('SELECT estado FROM `' . $db->table('jet_cct_cotizacion_mantenimiento') . '` WHERE _ID = 7001') === 'Pendiente', 'creating or sending an act does not disapprove its maintenance quote');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_actas_de_satisfaccion') . '`') === 0, 'no premature legacy timeline completion');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '`') === 0, 'no charge before signature');
$assert($notifications[0]['to'] === 'ana@example.invalid' && $notifications[0]['options']['source_module'] === 'ticket-completion', 'invitation uses selected contact and existing queue contract');
$assert($notifications[0]['options']['ticket_number'] === '9001' && $notifications[0]['options']['act_url'] === $service->viewUrl((int) $act['id']) . '&token=' . $token, 'invitation supplies exact structured ticket number and signing link');
$rejects(static fn() => $service->create(1, $input, $actor), 'double create rejected');
$assert(Repository::workflowError($db, $repo->schema, 1, true) !== '', 'manual close blocked while pending');
$assert(Repository::workflowError($db, $repo->schema, 1, false, 'Finalizado') !== '', 'administrative completion bypass blocked');
$assert(Repository::workflowError($db, $repo->schema, 1, false) === '', 'ordinary followups remain available');
$rejects(static fn() => $service->publicAct((int) $act['id'], str_repeat('0', 64)), 'invalid bearer token rejected');
$rejects(static fn() => $service->sign((int) $act['id'], $token, array_replace($signInput($act), ['accepted' => '0']), '', ''), 'missing consent rejected without changing ticket');
$rejects(static fn() => $service->sign((int) $act['id'], $token, array_replace($signInput($act), ['document_hash' => str_repeat('0', 64)]), '', ''), 'wrong document version rejected');
$rejects(static fn() => $service->sign((int) $act['id'], $token, $signInput($act), '', ''), 'possession of invitation alone cannot sign');
$assert($requestCode($act)['queued'], 'verification code queued to registered contact');
$otpState = json_decode($repo->act((int) $act['id'])['otp_json'], true);
$assert(!str_contains(json_encode($otpState), $codes[$act['id']]) && strlen($otpState['hash']) === 64, 'only keyed code hash persisted in act');
$rejects(static fn() => $requestCode($act), 'OTP resend throttled');
$rejects(static fn() => $service->requestCode((int) $act['id'], $token, 'sms'), 'arbitrary verification channel rejected');
$rejects(static fn() => $service->sign((int) $act['id'], $token, array_replace($signInput($act), ['otp_code' => '000000']), '', ''), 'incorrect code rejected');
$assert(json_decode($repo->act((int) $act['id'])['otp_json'], true)['failures'] === 1, 'failed attempt survives signature rollback');
$db->update($db->table('jet_cct_tickets'), ['estado_administrativo' => 'En ejecucion por propietario'], ['_ID' => 1]);
$rejects(static fn() => $service->sign((int) $act['id'], $token, $signInput($act), '', ''), 'signature is blocked if execution responsibility changed after creating the act');
$db->update($db->table('jet_cct_tickets'), ['estado_administrativo' => 'En ejecucion por inmobiliaria'], ['_ID' => 1]);
$historyBefore = (int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_ticket') . '`');
// Force an SQL failure only in the test connection's temporary report table.
$db->pdo()->exec('ALTER TABLE `' . $db->table('jet_cct_reportes_administrativos') . '` CHANGE COLUMN descripcion unavailable_description TEXT');
try {
  $service->sign((int) $act['id'], $token, $signInput($act), '127.0.0.1', 'QA');
  $assert(false, 'report failure must throw');
} catch (PDOException) { $assert(true, 'SQL failure reported'); }
$assert($repo->act((int) $act['id'])['status'] === 'pending' && $repo->ticket(1)['estado'] === 'En proceso', 'report failure rolls back signature and closure');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_historial_del_ticket') . '`') === $historyBefore, 'report failure does not append a false closure history');
$db->pdo()->exec('ALTER TABLE `' . $db->table('jet_cct_reportes_administrativos') . '` CHANGE COLUMN unavailable_description descripcion TEXT');
$db->pdo()->exec('ALTER TABLE `' . $db->table('jet_cct_historial_del_ticket') . '` CHANGE COLUMN respuesta unavailable_response TEXT');
try {
  $service->sign((int) $act['id'], $token, $signInput($act), '127.0.0.1', 'QA');
  $assert(false, 'late history failure must throw');
} catch (PDOException) { $assert(true, 'failure after signature and charge writes is reported'); }
$assert($repo->act((int) $act['id'])['status'] === 'pending' && $repo->ticket(1)['estado'] === 'En proceso', 'late failure rolls back ticket and signature');
$assert($db->getVar('SELECT estado FROM `' . $db->table('jet_cct_cotizacion_mantenimiento') . '` WHERE _ID = 7001') === 'Pendiente', 'late failure also rolls back quote disapproval');
$assert($repo->act((int) $act['id'])['signed_pdf'] === null, 'late failure also rolls back the signed PDF');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '`') === 0, 'late failure rolls back administrative charge');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_actas_de_satisfaccion') . '`') === 0, 'late failure rolls back legacy completion milestone');
$db->pdo()->exec('ALTER TABLE `' . $db->table('jet_cct_historial_del_ticket') . '` CHANGE COLUMN unavailable_response respuesta TEXT');
$signed = $service->sign((int) $act['id'], $token, $signInput($act), '127.0.0.1', 'QA');
$assert($signed['status'] === 'signed' && $repo->ticket(1)['estado'] === 'Cerrado' && $repo->ticket(1)['estado_administrativo'] === 'Finalizado', 'signature closes ticket and finalizes workflow');
$signedQuote = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_cotizacion_mantenimiento') . '` WHERE _ID = 7001');
$assert($signedQuote['estado'] === 'Desaprobada' && $signedQuote['motivo'] === 'Ejecucción por cuenta propia', 'signature disapproves linked maintenance quote as work executed without approval');
$assert($repo->ticket(1)['estado_cotizacion_mantenimiento'] === 'Desaprobada' && $repo->ticket(1)['estado_respuesta_cotizacion_mantenimiento'] === 'Desaprobada', 'signature synchronizes maintenance quote state on ticket');
$assert(str_starts_with($signed['signed_pdf'], '%PDF-1.4') && hash('sha256', $signed['signed_pdf']) === $signed['pdf_hash'], 'immutable signed PDF saved with closure');
$assert(str_contains($signed['signed_pdf'], '/Subtype /Image'), 'signed PDF embeds immutable photographic evidence');
$assert(str_contains($signed['signed_pdf'], 'Evidencias del da'), 'signed PDF labels photographic evidence by damage');
$assert($signed['otp_json'] === null && !empty(json_decode($signed['signed_json'], true)['verification']), 'code consumed and verification evidence recorded');
$assert($service->pdf($signed) === $signed['signed_pdf'], 'recipient receives exact persisted PDF');
$badPdf = $signed; $badPdf['signed_pdf'] .= 'tamper';
$rejects(static fn() => $service->pdf($badPdf), 'tampered PDF cannot be downloaded');
$missingPdf = $signed; $missingPdf['signed_pdf'] = null;
$rejects(static fn() => $service->pdf($missingPdf), 'missing signed original is not silently regenerated');
$assert(!str_contains($signed['signed_pdf'], 'Reporte administrativo') && !str_contains($service->pdf($signed, true), 'Reporte administrativo'), 'PDF acta never exposes internal administrative charge');
$receiptCount = count($notifications);
$report = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_reportes_administrativos') . '` WHERE _ID = ?', [$signed['report_id']]);
$assert((int) $report['valor'] === 12333 && (int) $report['transporte'] === 8000 && $report['exportado'] === 'No' && $report['fue_pagado'] === 'No', 'administrative report contains configured fee plus capped transport, unpaid and unexported');
$assert((int) $report['id_ticket'] === 1, 'report links to exact internal ticket ID, not a coinciding display number');
$service->sign((int) $act['id'], $token, $signInput($act), '127.0.0.1', 'QA');
$assert(count($notifications) === $receiptCount, 'duplicate signature does not duplicate receipt');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '`') === 1, 'repeated signature cannot duplicate charge');
$rejects(static fn() => $service->cancel((int) $act['id'], 'corrección', $actor), 'signed document cannot be cancelled');
$publicHtml = (new View())->document($signed, $service->payload($signed), false);
$staffHtml = (new View())->document($signed, $service->payload($signed), true);
$assert(!str_contains($publicHtml, 'Reporte administrativo') && !str_contains($staffHtml, 'Reporte administrativo'), 'act document excludes internal charge for every audience');
$assert(str_contains($publicHtml, 'Acta de satisfacción del caso #9001') && !str_contains($publicHtml, 'Acta de satisfacción del ticket'), 'act document names the public number as case, not ticket');
$assert(str_contains($publicHtml, 'Elaborada por') && str_contains($publicHtml, 'Funcionario Prueba') && str_contains($publicHtml, 'Coordinador QA') && str_contains($publicHtml, 'actor@example.invalid') && str_contains($publicHtml, 'Celular: 3001234567'), 'act document includes prepared-by signature block with role email and phone');
$assert(str_contains($publicHtml, 'Fuga en tubería') && str_contains($publicHtml, 'reemplazó') && str_contains($publicHtml, 'Firma electrónica registrada'), 'document includes damage, solution and signature');
$assert(str_contains($publicHtml, 'Evidencias del daño #1') && str_contains($publicHtml, rawurlencode($photoName)), 'recipient document displays lazy photographic evidence');
$badSignature = $signed;
$badSignature['signed_json'] = str_replace('Ana Pérez', 'Otra persona', (string) $signed['signed_json']);
$rejects(static fn() => $service->payload($badSignature), 'altered signature evidence fails integrity check');
$seedTicket(2);
$pending2 = $service->create(2, $input, $actor);
$act2 = $repo->act($pending2['act_id']);
$token2 = $service->token($act2);
$rejects(static fn() => $service->publicAct((int) $act2['id'], $token), 'token from another act cannot grant access');
$db->update($repo->table(), ['expires_at' => time() - 1, 'invitation_queued_at' => null], ['id' => $act2['id']]);
$rejects(static fn() => $service->publicAct((int) $act2['id'], $token2), 'expired link cannot sign');
$service->resend((int) $act2['id']);
$renewed = $repo->act((int) $act2['id']);
$assert($service->token($renewed) !== $token2 && (int) $renewed['expires_at'] > time(), 'resend renews expired link');
$service->cancel((int) $act2['id'], 'Cambiar firmante', $actor);
$rejects(static fn() => $service->publicAct((int) $act2['id'], $service->token($renewed)), 'cancelled link cannot sign');
$assert($repo->ticket(2)['estado_administrativo'] === 'En ejecucion por propietario' && $repo->ticket(2)['estado'] === 'En proceso', 'cancellation restores previous stage without closure');
$replacement = $service->create(2, array_replace($input, ['signer_role' => 'arrendatario', 'signer_name' => 'Juan Prueba']), $actor);
$assert($replacement['act_id'] !== $act2['id'], 'cancelled act remains in audit while replacement is allowed');
$seedTicket(3);
$rejects(static fn() => $service->create(3, array_replace($input, ['signer_role' => 'copropiedad']), $actor), 'missing community contact is rejected');
$db->insert($db->table('jet_cct_copropiedades'), ['_ID' => 7, 'administrador' => 'Administrador Prueba', 'correo' => 'comunidad@example.invalid']);
$db->update($db->table('jet_cct_tickets'), ['id_copropiedad' => 7], ['_ID' => 3]);
$assert($service->context(3)['contacts']['copropiedad']['available'] === true, 'community signer resolved from exact linked community ID');
$noQueue = new Service($repo, str_repeat('test-secret-', 4), 'http://127.0.0.1:8097', static fn() => 0);
$notQueued = $noQueue->create(3, array_replace($input, ['signer_role' => 'copropiedad', 'signer_name' => 'Administrador Prueba']), $actor);
$assert($notQueued['queued'] === false && $repo->ticket(3)['estado'] === 'En proceso', 'queue failure preserves pending act and reports retryable warning');
$pending3 = $repo->act($notQueued['act_id']);
$failedCode = $noQueue->requestCode((int) $pending3['id'], $noQueue->token($pending3), 'email');
$assert(!$failedCode['queued'] && empty(json_decode($repo->act((int) $pending3['id'])['otp_json'], true)['queued']), 'queue failure cannot activate a verification code');
$tampered = $repo->act($notQueued['act_id']);
$db->update($repo->table(), ['payload_json' => '{}'], ['id' => $tampered['id']]);
$rejects(static fn() => $service->publicAct((int) $tampered['id'], $service->token($tampered)), 'document tampering prevents signature');

$seedTicket(4);
$db->update($db->table('jet_cct_tickets'), ['celular_propietario' => '3001234567'], ['_ID' => 4]);
$both = $service->create(4, array_replace($input, ['channels' => ['both']]), $actor);
$bothAct = $repo->act($both['act_id']);
$assert($both['channels'] === ['email' => true, 'whatsapp' => true], 'both channels queue independently');
$assert(end($notifications)['to'] === '+573001234567' && end($notifications)['options']['channel'] === 'whatsapp', 'WhatsApp uses selected registered personal phone');
$requestCode($bothAct);
for ($i = 0; $i < 5; $i++) {
  $rejects(static fn() => $service->sign((int) $bothAct['id'], $service->token($bothAct), array_replace($signInput($bothAct), ['otp_code' => '000000']), '', ''), 'wrong OTP attempt ' . ($i + 1));
}
$rejects(static fn() => $service->sign((int) $bothAct['id'], $service->token($bothAct), $signInput($bothAct), '', ''), 'correct code blocked after five wrong attempts');
$lockedState = json_decode($repo->act((int) $bothAct['id'])['otp_json'], true);
$lockedState['last_request'] = time() - 61;
$db->update($repo->table(), ['otp_json' => json_encode($lockedState)], ['id' => $bothAct['id']]);
$rejects(static fn() => $requestCode($bothAct), 'resend cannot reset brute force attempt budget');
$lockedState['window_start'] = time() - 3601;
$db->update($repo->table(), ['otp_json' => json_encode($lockedState)], ['id' => $bothAct['id']]);
$oldCode = $codes[$bothAct['id']];
$requestCode($bothAct);
$expiredState = json_decode($repo->act((int) $bothAct['id'])['otp_json'], true);
$expiredState['expires_at'] = time() - 1;
$db->update($repo->table(), ['otp_json' => json_encode($expiredState)], ['id' => $bothAct['id']]);
$rejects(static fn() => $service->sign((int) $bothAct['id'], $service->token($bothAct), $signInput($bothAct), '', ''), 'expired OTP rejected');
$expiredState['expires_at'] = time() + 600;
$db->update($repo->table(), ['otp_json' => json_encode($expiredState)], ['id' => $bothAct['id']]);
$signed4 = $noQueue->sign((int) $bothAct['id'], $noQueue->token($bothAct), $signInput($bothAct), '', 'QA');
$assert($signed4['status'] === 'signed' && !$signed4['receipt']['queued'], 'receipt failure cannot undo a valid signed closure');
$db->update($repo->table(), ['invitation_queued_at' => null], ['id' => $bothAct['id']]);
$assert($service->resend((int) $bothAct['id'])['queued'], 'staff can resend signed receipt without another charge');
$receiptOptions = end($notifications)['options'];
$assert($receiptOptions['meta']['event'] === 'signed_receipt' && $receiptOptions['ticket_number'] === '9004' && $receiptOptions['act_url'] === $service->viewUrl((int) $bothAct['id']) . '&token=' . $service->token($repo->act((int) $bothAct['id'])) . '&format=pdf', 'signed receipt supplies its current personal PDF link');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '` WHERE id_ticket = 4') === 1, 'receipt retry creates no duplicate report');

$seedTicket(5);
$db->update($db->table('jet_cct_tickets'), ['celular_propietario' => '3001234567'], ['_ID' => 5]);
$partialService = new Service($repo, str_repeat('test-secret-', 4), 'http://127.0.0.1:8097', static fn($to, $subject, $html, $options): int => $options['channel'] === 'email' ? 1 : 0);
$partial = $partialService->create(5, array_replace($input, ['channels' => ['both']]), $actor);
$assert($partial['channels'] === ['email' => true, 'whatsapp' => false] && !$partial['queued'], 'partial delivery failure is reported per channel');
$db->update($repo->table(), ['invitation_queued_at' => null], ['id' => $partial['act_id']]);
$beforeRetry = count($notifications);
$assert($service->resend($partial['act_id'])['queued'], 'partial invitation retry succeeds');
$assert(count($notifications) === $beforeRetry + 1 && end($notifications)['options']['channel'] === 'whatsapp', 'partial retry skips the already queued email');
$oldEvidence = json_decode($signed['signed_json'], true);
unset($oldEvidence['strokes'], $oldEvidence['verification'], $oldEvidence['evidence_hmac']);
$oldEvidence['method'] = 'typed-name-and-explicit-consent'; $oldEvidence['consent_version'] = '1'; $oldEvidence['consent_text'] = Policy::CONSENT;
$oldEvidence['evidence_hmac'] = hash_hmac('sha256', json_encode($oldEvidence, JSON_THROW_ON_ERROR), str_repeat('test-secret-', 4));
$historical = array_replace($signed, ['signed_json' => json_encode($oldEvidence), 'signed_pdf' => null]);
$assert(str_starts_with($service->pdf($historical), '%PDF-1.4') && !str_contains((new View())->document($historical, $service->payload($historical), false), '<svg'), 'historical signatures remain readable without inventing drawing or OTP');

$oldTemplate = getenv('SCM_ACTA_WHATSAPP_OTP_TEMPLATE');
putenv('SCM_ACTA_WHATSAPP_OTP_TEMPLATE=qa_authentication');
$requestCode($repo->act($partial['act_id']), 'whatsapp');
$assert(end($notifications)['options']['meta']['event'] === 'signature_otp' && end($notifications)['to'] === '+573001234567', 'configured WhatsApp OTP targets registered phone');
putenv($oldTemplate === false ? 'SCM_ACTA_WHATSAPP_OTP_TEMPLATE' : 'SCM_ACTA_WHATSAPP_OTP_TEMPLATE=' . $oldTemplate);

if (in_array('--pdf-fixture', $argv, true)) {
  $dir = dirname(__DIR__) . '/tmp/pdfs';
  if (!is_dir($dir)) { mkdir($dir, 0750, true); }
  file_put_contents($dir . '/acta-firmada-qa.pdf', $signed['signed_pdf']);
  $longPayload = $service->payload($signed);
  $longPayload['items'] = array_fill(0, 3, ['damage' => str_repeat('Daño largo de prueba: humedad y filtración. ', 65), 'solution' => str_repeat('Reparación verificada, sellado y prueba de presión. ', 60)]);
  $longPayload['observations'] = str_repeat('Observaciones de prueba para validar paginación y márgenes. ', 90);
  file_put_contents($dir . '/acta-larga-qa.pdf', (new \SCM\Modules\TicketCompletion\CompletionPdf())->render($signed, $longPayload));
}
echo "$checks checks passed. All SQL test rows were in connection-scoped temporary tables; no real notifications were sent.\n";
