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
$rejects(static fn() => Policy::number('-100'), 'negative fee rejected');
$rejects(static fn() => Policy::items([['damage' => 'Humedad', 'solution' => '']]), 'solution required for every damage');
$rejects(static fn() => Policy::signature(['signature_name' => 'Ana Pérez', 'document' => '123456'], 'Ana Pérez'), 'opening a link never constitutes consent');
$rejects(static fn() => Policy::signature(['signature_name' => 'Otra persona', 'document' => '123456', 'accepted' => '1'], 'Ana Pérez'), 'signature must match selected name');

if (!in_array('--database', $argv, true)) { echo "$checks domain checks passed. Use --database for isolated SQL integration checks.\n"; exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1); });
$prefix = 'scmqa_' . bin2hex(random_bytes(5)) . '_';
$db = new Database(\SCM\Core\App::db()->pdo(), $prefix);
$tables = ['jet_cct_tickets', 'jet_cct_actas_de_satisfaccion', 'jet_cct_reportes_administrativos', 'jet_cct_historial_del_ticket', 'jet_cct_confi_sistema', 'jet_cct_copropiedades'];
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
foreach (['salario' => '1300000', 'dias_trabajo' => '30', 'porcentaje_smlmv_co_pre' => '0.10'] as $key => $value) {
  $db->insert($db->table('jet_cct_confi_sistema'), ['funcion' => $key, 'valor' => $value]);
}
$seedTicket = static function (int $id) use ($db): void {
  $db->insert($db->table('jet_cct_tickets'), ['_ID' => $id, 'id_ticket' => (string) (9000 + $id), 'estado' => 'En proceso', 'estado_administrativo' => 'En ejecucion por propietario', 'id_acta_satisfaccion' => '', 'estado_acta_satisfaccion' => 'No', 'propietario' => 'Ana Pérez', 'correo_propietario' => 'ana@example.invalid', 'arrendatario' => 'Juan Prueba', 'correo_arrendatario' => 'juan@example.invalid', 'inmueble' => '10001', 'direccion' => 'Dirección de prueba', 'contrato' => '51']);
};
$notifications = [];
$service = new Service($repo, str_repeat('test-secret-', 4), 'http://127.0.0.1:8097', static function ($to, $subject, $html, $options) use (&$notifications): int {
  $notifications[] = compact('to', 'subject', 'html', 'options'); return 1;
});
$actor = ['user_id' => 1, 'employee_id' => '200', 'name' => 'Funcionario Prueba'];
$input = ['signer_role' => 'propietario', 'signer_name' => 'Ana Pérez', 'executor' => 'propietario', 'items' => [['damage' => 'Fuga en tubería de cocina', 'solution' => 'Se reemplazó el tramo y se verificó presión.']], 'observations' => 'Sin filtración en la prueba final.', 'transport' => '12000', 'confirm' => '1'];
$signInput = static fn(array $act): array => ['document' => '123456789', 'signature_name' => 'Ana Pérez', 'accepted' => '1', 'document_hash' => $act['payload_hash']];
$seedTicket(1);
$created = $service->create(1, $input, $actor);
$act = $repo->act($created['act_id']);
$token = $service->token($act);
$assert($repo->ticket(1)['estado'] === 'En proceso' && $repo->ticket(1)['estado_administrativo'] === Policy::WAITING, 'creating an act does not close ticket');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_actas_de_satisfaccion') . '`') === 0, 'no premature legacy timeline completion');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '`') === 0, 'no charge before signature');
$assert($notifications[0]['to'] === 'ana@example.invalid' && $notifications[0]['options']['source_module'] === 'ticket-completion', 'invitation uses selected contact and existing queue contract');
$rejects(static fn() => $service->create(1, $input, $actor), 'double create rejected');
$assert(Repository::workflowError($db, $repo->schema, 1, true) !== '', 'manual close blocked while pending');
$assert(Repository::workflowError($db, $repo->schema, 1, false, 'Finalizado') !== '', 'administrative completion bypass blocked');
$assert(Repository::workflowError($db, $repo->schema, 1, false) === '', 'ordinary followups remain available');
$rejects(static fn() => $service->publicAct((int) $act['id'], str_repeat('0', 64)), 'invalid bearer token rejected');
$rejects(static fn() => $service->sign((int) $act['id'], $token, array_replace($signInput($act), ['accepted' => '0']), '', ''), 'missing consent rejected without changing ticket');
$rejects(static fn() => $service->sign((int) $act['id'], $token, array_replace($signInput($act), ['document_hash' => str_repeat('0', 64)]), '', ''), 'wrong document version rejected');
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
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '`') === 0, 'late failure rolls back administrative charge');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_actas_de_satisfaccion') . '`') === 0, 'late failure rolls back legacy completion milestone');
$db->pdo()->exec('ALTER TABLE `' . $db->table('jet_cct_historial_del_ticket') . '` CHANGE COLUMN unavailable_response respuesta TEXT');
$signed = $service->sign((int) $act['id'], $token, $signInput($act), '127.0.0.1', 'QA');
$assert($signed['status'] === 'signed' && $repo->ticket(1)['estado'] === 'Cerrado' && $repo->ticket(1)['estado_administrativo'] === 'Finalizado', 'signature closes ticket and finalizes workflow');
$report = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_reportes_administrativos') . '` WHERE _ID = ?', [$signed['report_id']]);
$assert((int) $report['valor'] === 16333 && $report['exportado'] === 'No' && $report['fue_pagado'] === 'No', 'administrative report contains configured fee plus transport, unpaid and unexported');
$assert((int) $report['id_ticket'] === 1, 'report links to exact internal ticket ID, not a coinciding display number');
$service->sign((int) $act['id'], $token, $signInput($act), '127.0.0.1', 'QA');
$assert((int) $db->getVar('SELECT COUNT(*) FROM `' . $db->table('jet_cct_reportes_administrativos') . '`') === 1, 'repeated signature cannot duplicate charge');
$rejects(static fn() => $service->cancel((int) $act['id'], 'corrección', $actor), 'signed document cannot be cancelled');
$publicHtml = (new View())->document($signed, $service->payload($signed), false);
$staffHtml = (new View())->document($signed, $service->payload($signed), true);
$assert(!str_contains($publicHtml, 'Reporte administrativo') && str_contains($staffHtml, 'Reporte administrativo'), 'public document excludes internal charge');
$assert(str_contains($publicHtml, 'Fuga en tubería') && str_contains($publicHtml, 'reemplazó') && str_contains($publicHtml, 'Firma electrónica registrada'), 'document includes damage, solution and signature');
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
$tampered = $repo->act($notQueued['act_id']);
$db->update($repo->table(), ['payload_json' => '{}'], ['id' => $tampered['id']]);
$rejects(static fn() => $service->publicAct((int) $tampered['id'], $service->token($tampered)), 'document tampering prevents signature');
echo "$checks checks passed. All SQL test rows were in connection-scoped temporary tables; no real notifications were sent.\n";
