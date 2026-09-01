<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\SmsQueue;

final class CompletionDelivery
{
  public static function otpTemplate(): string
  {
    return self::configuredTemplate('SCM_ACTA_WHATSAPP_OTP_TEMPLATE', 'scm_acta_firma_otp_v1');
  }

  private static function configuredTemplate(string $key, string $default): string
  {
    $name = trim((string) getenv($key));
    return preg_match('/^[a-z0-9_]{1,512}$/D', $name) ? $name : $default;
  }

  public static function enqueue(Database $db, string $to, string $subject, string $html, array $options): int
  {
    if (($options['channel'] ?? 'email') === 'email') {
      return (new EmailQueue($db))->enqueue($to, $subject, $html, $options);
    }
    $message = (string) $options['message_text'];
    $event = (string) ($options['meta']['event'] ?? '');
    $otp = $event === 'signature_otp';
    if ($otp && self::otpTemplate() === '') { return 0; }
    $template = match ($event) {
      'signature_otp' => self::otpTemplate(),
      'signature_invitation' => self::configuredTemplate('SCM_ACTA_WHATSAPP_INVITATION_TEMPLATE', 'scm_acta_solicitud_firma_v1'),
      'signed_receipt' => self::configuredTemplate('SCM_ACTA_WHATSAPP_RECEIPT_TEMPLATE', 'scm_acta_firmada_v1'),
      default => null,
    };
    if ($template === null) { return 0; }
    if ($otp) {
      $parameters = [(string) $options['otp_code']];
    } else {
      // Pass structured values: extracting a personal URL from prose can truncate or corrupt it.
      $ticket = trim((string) ($options['ticket_number'] ?? ''));
      $url = trim((string) ($options['act_url'] ?? ''));
      if ($ticket === '' || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) { return 0; }
      $parameters = [(string) $options['destination_name'], $ticket, $url];
    }
    $components = [['type' => 'body', 'parameters' => array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $parameters)]];
    if ($otp) {
      // Meta COPY_CODE authentication templates are sent as a dynamic URL button.
      $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $options['otp_code']]]];
    }
    $languageKey = $otp ? 'SCM_ACTA_WHATSAPP_OTP_LANGUAGE' : 'SCM_ACTA_WHATSAPP_LANGUAGE';
    $language = trim((string) getenv($languageKey)) ?: 'es_CO';
    $meta = $options['meta'] + [
      'source_module' => 'ticket-completion', 'dedupe_key' => $options['dedupe_key'],
      'template_name' => $template,
      'template_language' => $language,
      'template_components' => $components, 'priority' => $otp ? 200 : 100, 'max_attempts' => 3,
    ];
    return (new SmsQueue($db))->enqueue($to, (string) $options['destination_name'], $message, $meta) ? 1 : 0;
  }
}
