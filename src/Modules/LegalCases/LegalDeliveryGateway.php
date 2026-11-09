<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\SharedNotificationsBridge;
use SCM\Support\SmsQueue;

/**
 * Salida de las comunicaciones por la cola compartida. Para sumar un proveedor con webhooks basta extender esta clase:
 * el resto del módulo solo conoce encolar y consultar el estado por `dedupe_key`.
 */
class LegalDeliveryGateway
{
  public function __construct(protected Database $db) {}

  /** Inicializa la cola: hay que llamarlo antes de abrir la transacción del envío. */
  public function ready(): bool
  {
    return (new SharedNotificationsBridge($this->db))->isAvailable();
  }

  /** @param array<string,mixed> $options */
  public function email(string $to, string $subject, string $html, array $options): bool
  {
    return (new EmailQueue($this->db))->enqueue($to, $subject, $html, $options) === 1;
  }

  /** @param array<string,mixed> $meta */
  public function whatsapp(string $phone, string $name, string $message, array $meta): bool
  {
    return (new SmsQueue($this->db))->enqueue($phone, $name, $message, $meta);
  }

  /** @return array{status:string,error:string,sent_at:int,provider_id:string}|null */
  public function lookup(string $dedupePrefix): ?array
  {
    $bridge = new SharedNotificationsBridge($this->db);
    if (!$bridge->isAvailable()) return null;
    $row = $this->db->getRow("SELECT `id`, `status`, `last_error`, `sent_at` FROM `{$bridge->queueTable()}` WHERE `project_code` = ? AND `dedupe_key` LIKE ? ORDER BY `id` DESC LIMIT 1", [$bridge->projectCode(), $dedupePrefix . ':%']);
    if ($row === null) return null;
    $attempt = $this->db->getVar("SELECT `response_payload` FROM `skc_notification_attempts` WHERE `notification_id` = ? AND `attempt_status` = 'sent' ORDER BY `id` DESC LIMIT 1", [(int) $row['id']]);
    $response = json_decode((string) $attempt, true);
    $sentAt = trim((string) ($row['sent_at'] ?? ''));
    return [
      'status' => (string) $row['status'],
      'error' => trim((string) ($row['last_error'] ?? '')),
      'sent_at' => $sentAt === '' ? 0 : (new \DateTimeImmutable($sentAt, new \DateTimeZone('UTC')))->getTimestamp(),
      'provider_id' => is_array($response) ? (string) ($response['messages'][0]['id'] ?? '') : '',
    ];
  }
}
