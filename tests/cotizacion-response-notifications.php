<?php

declare(strict_types=1);

// Exercise the real notification queue and wrappers with isolated in-memory
// storage. No production database, worker, or external provider is contacted.
namespace SCM\Core {
  final class Database {}
  final class Settings {
    public static array $values = [];
    public function get(string $key, $default = null) { return self::$values[$key] ?? $default; }
  }
  final class App {
    public static function settings(): Settings { return new Settings(); }
  }
}
namespace SCM\Support {
  final class SchemaInspector { public function __construct($db) {} }
  final class FuncionarioOptions {
    public static array $rows = [];
    public static function panelFuncionarios($db, $schema, $idMode): array { return self::$rows; }
  }
  final class SharedNotificationsBridge {
    public static \SharedNotifications\NotificationQueue $testQueue;
    public function __construct($db) {}
    public function queue(): \SharedNotifications\NotificationQueue { return self::$testQueue; }
    public function projectCode(): string { return 'control-servicios-inmobiliarios'; }
  }
}
namespace SCM\App {
  final class SuCasaControlServiciosInmobiliarios {
    public static function signedMaintenanceQuotePublicUrl(int $id): string {
      return 'https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/cotizacion-mantenimiento.php?numero=' . $id . '&expires=1790000000&sig=test';
    }
  }
}
namespace {
  $root = dirname(__DIR__);
  $sharedRoot = getenv('SHARED_NOTIFICATIONS_PATH') ?: dirname($root) . '/shared-notifications';
  require $sharedRoot . '/autoload.php';
  require $root . '/src/Support/InternalNotificationRecipients.php';
  require $root . '/src/Support/EmailQueue.php';
  require $root . '/src/Support/SmsQueue.php';
  require $root . '/src/Support/EmailTemplate.php';
  require $root . '/src/Modules/ServiciosInmobiliarios/Concerns/NotificationDeliveryConcern.php';
  function wp_kses_post(string $text): string { return strip_tags($text, '<p><strong>'); }
  function wp_strip_all_tags(string $text): string { return strip_tags($text); }

  final class MemoryNotificationStorage implements \SharedNotifications\Contracts\StorageAdapterInterface {
    public array $rows = [];
    public array $attempts = [];
    public bool $failEmail = false;
    public bool $failWhatsApp = false;
    public function ensureSchema(string $queueTable, string $attemptsTable): void {}
    public function insert(string $table, array $data): int {
      if (isset($data['notification_id'])) {
        $this->attempts[] = $data;
        return count($this->attempts);
      }
      if (($this->failEmail && $data['channel'] === 'email') || ($this->failWhatsApp && $data['channel'] === 'whatsapp')) throw new \RuntimeException('Simulated queue failure');
      foreach ($this->rows as $id => $row) {
        if ($row['dedupe_key'] === $data['dedupe_key']) return $id + 1;
      }
      $this->rows[] = array_merge($data, ['id' => count($this->rows) + 1]);
      return count($this->rows);
    }
    public function fetchPending(string $table, int $limit, ?string $projectCode = null): array {
      return array_slice(array_values(array_filter($this->rows, static fn($row) => $row['status'] === 'pending')), 0, $limit);
    }
    public function fetchPendingFair(string $table, int $limit, ?string $projectCode = null, int $perProjectLimit = 5): array { return $this->fetchPending($table, $limit, $projectCode); }
    public function claim(string $table, int $id, int $attempts, string $workerId, string $now): bool {
      if ($this->rows[$id - 1]['status'] !== 'pending') return false;
      $this->rows[$id - 1]['status'] = 'processing';
      $this->rows[$id - 1]['attempts']++;
      return true;
    }
    public function releaseStaleProcessing(string $table, string $now, int $staleMinutes = 15): int { return 0; }
    public function cancelByDedupeKey(string $table, string $dedupeKey, string $now, string $reason): int { return 0; }
    public function updateById(string $table, int $id, array $data): void { $this->rows[$id - 1] = array_merge($this->rows[$id - 1], $data); }
  }
  final class LocalTestProvider implements \SharedNotifications\Contracts\ProviderInterface {
    public function __construct(private string $providerCode) {}
    public function code(): string { return $this->providerCode; }
    public function send(array $notification): array { return ['ok' => true, 'http_code' => 200, 'response' => ['simulated' => true]]; }
  }
  final class ResponseNotificationProbe {
    use \SCM\Modules\ServiciosInmobiliarios\Concerns\NotificationDeliveryConcern;
    public \SCM\Core\Database $db;
    public ?\SCM\Support\EmailQueue $queue;
    public function __construct() {
      $this->db = new \SCM\Core\Database();
      $this->queue = new \SCM\Support\EmailQueue($this->db);
    }
    public function respond(array $quote, string $state = 'Aprobada', string $observation = 'Autorizado'): array {
      return $this->notifyCotizacionResponse([
        '_ID' => 10841, 'id_ticket' => 'CASO-10841',
        'correo_solicitante' => 'solicitante@example.test',
        'correo_arrendatario' => 'arrendatario@example.test',
        'correo_propietario' => 'propietario@example.test',
        'correo_empleado' => 'responsable@example.test',
      ], $quote, $state, $observation);
    }
  }
  function check(bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
  }
  $storage = new MemoryNotificationStorage();
  \SCM\Support\SharedNotificationsBridge::$testQueue = new \SharedNotifications\NotificationQueue($storage);
  \SCM\Core\Settings::$values = ['internal_admin_notifications' => ['respuesta_cotizacion_mantenimiento' => [11, 12, 13]]];
  \SCM\Support\FuncionarioOptions::$rows = [
    ['id' => '11', 'name' => 'Creador duplicado', 'email' => 'CREADOR@example.test', 'phone' => '+57 300 111 2233'],
    ['id' => '12', 'name' => 'Funcionario', 'email' => 'funcionario@example.test', 'phone' => '3001112244'],
    ['id' => '13', 'name' => 'Solo WhatsApp', 'email' => '', 'phone' => '3001112255'],
    ['id' => '14', 'name' => 'Sin seleccionar', 'email' => 'excluido@example.test', 'phone' => '3001112266'],
  ];
  $quote = [
    '_ID' => '570', 'creador' => 'Creador', 'email_creador' => 'creador@example.test', 'celular_creador' => '3001112233',
    'email_destinatario' => 'cliente@example.test', 'email_coordinador' => 'coordinador@example.test',
    'destinatario' => 'Cliente', 'contrato' => '2000', 'inmueble' => '204578', 'fecha_respuesta' => 1790000000,
    'financiacion' => 'No', 'motivo' => '',
  ];
  $probe = new ResponseNotificationProbe();
  $counts = $probe->respond($quote);
  check($counts === ['email' => 2, 'whatsapp' => 3], 'Recipients must deduplicate each channel and include phone-only officials');
  $allowed = ['creador@example.test', 'funcionario@example.test', '+573001112233', '+573001112244', '+573001112255'];
  $template = json_decode(file_get_contents($root . '/docs/whatsapp-cotizacion-mantenimiento-respuesta-template.json'), true, 512, JSON_THROW_ON_ERROR);
  foreach ($storage->rows as $row) {
    check(in_array($row['destination'], $allowed, true), 'Notification leaked to an unselected recipient');
    check($row['status'] === 'pending' && $row['source_module'] === 'respuesta_cotizacion_mantenimiento', 'Queue event must be pending and traceable');
    check(json_decode($row['meta_json'], true)['id_cotizacion'] === '570', 'Quote metadata missing');
    if ($row['channel'] !== 'whatsapp') continue;
    $payload = json_decode($row['payload_json'], true);
    check($row['provider'] === 'whatsapp_official' && $row['template_name'] === $template['name'], 'WhatsApp template mismatch');
    check(count($payload['components'][0]['parameters']) === 8, 'WhatsApp body parameters mismatch');
    $button = $payload['components'][1]['parameters'][0]['text'];
    check(str_starts_with($button, 'control-servicios-inmobiliarios/public/cotizacion-mantenimiento.php?') && str_contains($button, '&sig='), 'WhatsApp button must use signed dynamic suffix');
    check(str_contains($payload['components'][0]['parameters'][7]['text'], 'Financiación: No'), 'Approval financing missing');
  }
  $probe->respond($quote);
  check(count($storage->rows) === 5, 'Repeated response event must have stable dedupe keys');
  $providers = (new \SharedNotifications\Providers\ProviderRegistry())
    ->add(new LocalTestProvider('email_smtp'))->add(new LocalTestProvider('whatsapp_official'));
  $worker = new \SharedNotifications\NotificationWorker($storage, $providers);
  check($worker->run(10)['sent'] === 5, 'Shared worker must process both channels');
  check(count($storage->attempts) === 5, 'Shared worker must retain an attempt per notification');
  foreach ($storage->rows as $row) check($row['status'] === 'sent', 'Worker must mark isolated test notifications sent');
  $storage->rows = [];
  \SCM\Core\Settings::$values = [];
  check($probe->respond($quote) === ['email' => 1, 'whatsapp' => 1], 'With no configured officials only the creator must receive notifications');
  $storage->rows = [];
  $storage->failEmail = true;
  check($probe->respond($quote) === ['email' => 0, 'whatsapp' => 1], 'Email queue failure must not inflate counts or suppress WhatsApp');
  $storage->failEmail = false;
  $storage->rows = [];
  $storage->failWhatsApp = true;
  check($probe->respond($quote) === ['email' => 1, 'whatsapp' => 0], 'WhatsApp queue failure must not inflate counts or suppress email');
  $storage->failWhatsApp = false;
  $storage->rows = [];
  $quote['celular_creador'] = '';
  check($probe->respond($quote) === ['email' => 1, 'whatsapp' => 0], 'Missing creator phone must not notify a different person');
  $quote['celular_creador'] = '3001112233';
  $quote['motivo'] = 'Por costo';
  $probe->respond($quote, 'Desaprobada', "Muy alto\nRevisar opciones");
  $whatsApp = array_values(array_filter($storage->rows, static fn($row) => $row['channel'] === 'whatsapp'))[0];
  $detail = json_decode($whatsApp['payload_json'], true)['components'][0]['parameters'][7]['text'];
  check(str_contains($detail, 'Motivo: Por costo') && !str_contains($detail, 'Financiación:') && !str_contains($detail, "\n"), 'Rejection details must contain only applicable fields and be template-safe');
  echo "Quote response notifications passed: recipients, both channels, pending jobs, dedupe, signed links, failures and rejection details.\n";
}
