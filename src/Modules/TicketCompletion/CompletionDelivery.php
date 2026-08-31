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
    $name = trim((string) getenv('SCM_ACTA_WHATSAPP_OTP_TEMPLATE'));
    return preg_match('/^[a-z0-9_]{1,512}$/D', $name) ? $name : '';
  }

  public static function enqueue(Database $db, string $to, string $subject, string $html, array $options): int
  {
    if (($options['channel'] ?? 'email') === 'email') {
      return (new EmailQueue($db))->enqueue($to, $subject, $html, $options);
    }
    $message = (string) $options['message_text'];
    $otp = ($options['meta']['event'] ?? '') === 'signature_otp';
    if ($otp && self::otpTemplate() === '') { return 0; }
    $parameters = $otp ? [(string) $options['otp_code']] : [(string) $options['destination_name'], $message, 'SKC SuCasa Inmobiliaria'];
    $components = [['type' => 'body', 'parameters' => array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $parameters)]];
    if ($otp) {
      // Meta COPY_CODE authentication templates are sent as a dynamic URL button.
      $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $options['otp_code']]]];
    }
    $meta = $options['meta'] + [
      'source_module' => 'ticket-completion', 'dedupe_key' => $options['dedupe_key'],
      'template_name' => $otp ? self::otpTemplate() : 'scm_notificacion_general_v1',
      'template_language' => $otp ? ((string) getenv('SCM_ACTA_WHATSAPP_OTP_LANGUAGE') ?: 'es_CO') : 'es_CO',
      'template_components' => $components, 'priority' => $otp ? 200 : 100, 'max_attempts' => 3,
    ];
    return (new SmsQueue($db))->enqueue($to, (string) $options['destination_name'], $message, $meta) ? 1 : 0;
  }
}
