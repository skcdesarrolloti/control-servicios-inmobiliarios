<?php

namespace SCM\Support;

use SCM\Core\Database;

/**
 * Wrapper de compatibilidad para encolar correos en shared-notifications.
 */
final class EmailQueue
{
  private SharedNotificationsBridge $bridge;

  public function __construct(Database $db)
  {
    $this->bridge = new SharedNotificationsBridge($db);
  }

  /**
   * @param string|string[] $to
   */
  public function enqueue($to, string $subject, string $html, array $options = []): int
  {
    $queue = $this->bridge->queue();
    if (!$queue instanceof \SharedNotifications\NotificationQueue) {
      return 0;
    }

    $recipients = is_array($to) ? $to : [$to];
    $sourceModule = trim((string) ($options['source_module'] ?? $options['source'] ?? 'control-servicios-inmobiliarios-email'));
    $priority = (int) ($options['priority'] ?? 100);
    $maxAttempts = (int) ($options['max_attempts'] ?? 3);
    $provider = trim((string) ($options['provider'] ?? 'email_smtp'));
    $scheduledAt = trim((string) ($options['scheduled_at'] ?? ''));
    $destinationName = trim((string) ($options['destination_name'] ?? ''));
    $dedupeKey = trim((string) ($options['dedupe_key'] ?? ''));
    $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
    $meta = $this->buildMeta($options, [
      'source',
      'source_module',
      'priority',
      'max_attempts',
      'provider',
      'scheduled_at',
      'destination_name',
      'dedupe_key',
      'payload',
    ]);
    $queued = 0;

    foreach ($recipients as $email) {
      $email = trim((string) $email);
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }

      try {
        $queue->enqueue([
          'project_code' => $this->bridge->projectCode(),
          'source_module' => $sourceModule,
          'channel' => 'email',
          'provider' => $provider,
          'destination' => $email,
          'destination_name' => $destinationName,
          'subject' => $subject,
          'message_html' => $html,
          'message_text' => trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')),
          'payload' => $payload,
          'meta' => $meta,
          'priority' => $priority,
          'max_attempts' => $maxAttempts,
          'scheduled_at' => $scheduledAt,
          'dedupe_key' => $dedupeKey !== '' ? ($dedupeKey . ':' . strtolower($email)) : null,
          'created_by' => 'control-servicios-inmobiliarios',
        ]);
        $queued++;
      } catch (\Throwable $exception) {
        // No interrumpir el flujo principal si la cola falla.
      }
    }
    return $queued;
  }

  /**
   * Metodo legado. El procesamiento ahora lo hace el worker compartido.
   */
  public function processPending($mailer = null, int $limit = 20): int
  {
    return 0;
  }

  /**
   * @return array{pending:int,processing:int,sent:int,failed:int}
   */
  public function stats(): array
  {
    $stats = $this->bridge->statsByChannel('email');
    return [
      'pending' => $stats['pending'],
      'processing' => $stats['processing'],
      'sent' => $stats['sent'],
      'failed' => $stats['failed'],
    ];
  }

  /**
   * @param string[] $excludedKeys
   * @return array<string,mixed>
   */
  private function buildMeta(array $options, array $excludedKeys): array
  {
    $meta = $options['meta'] ?? $options;
    if (!is_array($meta)) {
      return [];
    }

    foreach ($excludedKeys as $key) {
      unset($meta[$key]);
    }

    return $meta;
  }
}
