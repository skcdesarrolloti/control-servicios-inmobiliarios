<?php

namespace SCM\Support;

use SCM\Core\Database;

final class SmsQueue
{
  private const PROVIDER = 'whatsapp_official';
  private const DEFAULT_TEMPLATE_LANGUAGE = 'es_CO';

  private SharedNotificationsBridge $bridge;
  private string $lastError = '';

  public function __construct(Database $db)
  {
    $this->bridge = new SharedNotificationsBridge($db);
  }

  /**
   * @param array<string,mixed> $meta
   */
  public function enqueue(string $phone, string $name, string $message, array $meta = []): bool
  {
    $this->lastError = '';
    $queue = $this->bridge->queue();
    if (!$queue instanceof \SharedNotifications\NotificationQueue) {
      $this->lastError = $this->bridge->lastError() ?: 'La cola compartida no esta disponible.';
      return false;
    }

    $phone = $this->normalizePhone($phone);
    $name = trim($name);
    $message = trim($message);
    if ($phone === '' || $message === '') {
      return false;
    }

    if (strlen($message) > 500) {
      $message = substr($message, 0, 497) . '...';
    }

    $sourceModule = trim((string) ($meta['source_module'] ?? $meta['source'] ?? $meta['campaign_tag'] ?? 'control-servicios-inmobiliarios-whatsapp'));
    $priority = (int) ($meta['priority'] ?? 1);
    $maxAttempts = (int) ($meta['max_attempts'] ?? 5);
    $scheduledAt = trim((string) ($meta['scheduled_at'] ?? ''));
    $dedupeKey = trim((string) ($meta['dedupe_key'] ?? ''));
    $templateName = trim((string) ($meta['template_name'] ?? ''));
    $templateLanguage = trim((string) ($meta['template_language'] ?? self::DEFAULT_TEMPLATE_LANGUAGE));
    $templateComponents = $meta['template_components'] ?? [];
    if ($templateName === '' || !is_array($templateComponents)) {
      $this->lastError = 'Falta template_name o template_components para WhatsApp.';
      return false;
    }

    $payload = $this->buildMeta($meta, [
      'source',
      'source_module',
      'priority',
      'max_attempts',
      'scheduled_at',
      'dedupe_key',
      'template_name',
      'template_language',
      'template_components',
    ]);
    $templatePayload = [
      'type' => 'template',
      'template_name' => $templateName,
      'template_language' => $templateLanguage !== '' ? $templateLanguage : self::DEFAULT_TEMPLATE_LANGUAGE,
      'components' => array_values($templateComponents),
    ];

    try {
      $queue->enqueue([
        'project_code' => $this->bridge->projectCode(),
        'source_module' => $sourceModule,
        'channel' => 'whatsapp',
        'provider' => self::PROVIDER,
        'destination' => $phone,
        'destination_name' => $name,
        'message_text' => $message,
        'template_name' => $templateName,
        'template_language' => $templatePayload['template_language'],
        'payload' => $templatePayload,
        'meta' => $payload,
        'priority' => $priority,
        'max_attempts' => $maxAttempts,
        'scheduled_at' => $scheduledAt,
        'dedupe_key' => $dedupeKey !== '' ? ($dedupeKey . ':' . $phone) : null,
        'created_by' => 'control-servicios-inmobiliarios',
      ]);
      return true;
    } catch (\Throwable $exception) {
      $this->lastError = $exception->getMessage();
      error_log('control-servicios-inmobiliarios: fallo encolando WhatsApp: ' . $exception->getMessage());
      return false;
    }
  }

  public function lastError(): string
  {
    return $this->lastError;
  }

  /**
   * Metodo legado. El procesamiento ahora lo hace el worker compartido.
   */
  public function processPending($client = null, int $limit = 20, int $minDelaySeconds = 5): int
  {
    return 0;
  }

  /**
   * @return array{pending:int,processing:int,sent:int,failed:int}
   */
  public function stats(): array
  {
    $stats = $this->bridge->statsByChannel('whatsapp');
    return [
      'pending' => $stats['pending'],
      'processing' => $stats['processing'],
      'sent' => $stats['sent'],
      'failed' => $stats['failed'],
    ];
  }

  private function normalizePhone(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (strpos($value, '@g.us') !== false || strpos($value, '@s.whatsapp.net') !== false) {
      return $value;
    }

    $hasPlus = isset($value[0]) && $value[0] === '+';
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits) || $digits === '') {
      return '';
    }

    if (!$hasPlus && strlen($digits) <= 10) {
      $digits = '57' . $digits;
    }

    return '+' . ltrim($digits, '+');
  }

  /**
   * @param string[] $excludedKeys
   * @return array<string,mixed>
   */
  private function buildMeta(array $meta, array $excludedKeys): array
  {
    foreach ($excludedKeys as $key) {
      unset($meta[$key]);
    }

    return $meta;
  }
}
