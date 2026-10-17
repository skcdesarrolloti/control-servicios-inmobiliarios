<?php

declare(strict_types=1);

// Aviso interno "acta_enviada": SQLite en memoria y configuración en memoria; no encola nada real.
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Core/Helpers.php';

use SCM\Core\App;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

final class SentNoticeTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }
}

$pdo = new SentNoticeTestPdo();
$db = new Database($pdo, 'wp_');
$pdo->exec('CREATE TABLE wp_jet_cct_funcionarios(_ID INTEGER PRIMARY KEY, id_empleado TEXT, nombre TEXT, correo TEXT, activo TEXT, id_cargo TEXT)');
foreach ([[101, '200', 'Coordinadora', 'coord@example.invalid', 'Si'], [102, '201', 'Gerencia', 'GERENCIA@example.invalid', 'Si'], [103, '202', 'No elegido', 'otro@example.invalid', 'Si']] as [$id, $employee, $name, $email, $active]) {
  $db->insert('wp_jet_cct_funcionarios', ['_ID' => $id, 'id_empleado' => $employee, 'nombre' => $name, 'correo' => $email, 'activo' => $active, 'id_cargo' => '3']);
}

$settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
$data = new ReflectionProperty(Settings::class, 'data');
$data->setValue($settings, ['internal_admin_notifications' => []]);
(new ReflectionProperty(App::class, 'settings'))->setValue(null, $settings);

$sent = [];
$service = new CompletionService(new CompletionRepository($db), str_repeat('x', 32), 'https://example.test/panel', static function ($to, $subject, $html, $options) use (&$sent): int {
  $sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'options' => $options];
  return 1;
});
$notify = new ReflectionMethod(CompletionService::class, 'tryNotifySentStaff');
$act = ['id' => 55, 'ticket_pk' => 9, 'token_nonce' => str_repeat('n', 64), 'expires_at' => strtotime('2026-11-30'), 'payload_json' => '{}'];
$payload = ['ticket_number' => 'QA9', 'contract' => '787', 'property' => '204578', 'signer' => ['name' => 'Firmante', 'email' => 'signer@example.invalid'], 'creator' => ['employee_id' => '200']];

$notify->invoke($service, $act, $payload, 1, false, ['email' => true]);
$check($sent === [], 'Sin funcionarios configurados no se envía aviso interno.');

$data->setValue($settings, ['internal_admin_notifications' => ['acta_enviada' => [101, 102]]]);
$notify->invoke($service, $act, $payload, 1, false, ['email' => true, 'whatsapp' => false]);
$check(count($sent) === 1, 'Debe encolar un único correo interno con copias.');
$mail = $sent[0];
$check($mail['to'] === 'coord@example.invalid' && $mail['options']['internal_cc'] === ['coord@example.invalid', 'gerencia@example.invalid'], 'Los configurados reciben el aviso; el no elegido no.');
$check($mail['options']['internal_action'] === 'acta_enviada' && $mail['options']['meta']['event'] === 'acta_enviada', 'El aviso usa el evento acta_enviada.');
$check(str_contains($mail['subject'], 'Acta #55 enviada a firma') && str_contains($mail['html'], 'Se envió el acta #55') && str_contains($mail['html'], 'correo y WhatsApp (no se pudo encolar)'), 'El aviso dice qué se envió y por qué canales.');
$check(!str_contains($mail['html'], 'token=') && !str_contains($mail['options']['message_text'], 'token=') && str_contains($mail['html'], 'ticket-acta.php?id=55'), 'El aviso interno nunca incluye el enlace personal del firmante.');

$notify->invoke($service, $act, $payload, 2, true, ['email' => true]);
$check(str_contains($sent[1]['subject'], 'reenviada') && str_contains($sent[1]['html'], 'Se reenvió'), 'Un reenvío se informa como reenvío.');
$check($sent[0]['options']['dedupe_key'] !== $sent[1]['options']['dedupe_key'], 'Cada reenvío genera su propio aviso.');

$catalog = (string) file_get_contents(dirname(__DIR__) . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$maintenanceStart = (int) strpos($catalog, "'acta_enviada' => [");
$preventiveStart = (int) strpos($catalog, "'preventivas' => [", $maintenanceStart);
$maintenance = substr($catalog, $maintenanceStart, $preventiveStart - $maintenanceStart);
$preventive = substr($catalog, $preventiveStart, 2500);
$check(str_contains($maintenance, "'acta_enviada'") && !str_contains($maintenance, 'revision_preventiva'), 'Mantenimiento tiene el aviso de envío y ya no las revisiones preventivas.');
$check(str_contains($preventive, "'revision_preventiva_creada'") && str_contains($preventive, "'revision_preventiva_enviada'"), 'Las revisiones preventivas quedan en el grupo Preventivas.');

echo "Ticket completion sent notification checks passed.\n";
