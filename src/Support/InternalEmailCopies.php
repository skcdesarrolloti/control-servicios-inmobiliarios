<?php

declare(strict_types=1);

namespace SCM\Support;

/** Keeps configured staff in CC and preserves a real To for internal-only events. */
final class InternalEmailCopies
{
  public static function emails(array $values): array
  {
    $out = [];
    foreach ($values as $value) {
      $email = strtolower(trim((string) $value));
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out[$email] = $email;
    }
    return array_values($out);
  }

  public static function plan(array $recipients, array $copies): array
  {
    $recipients = self::emails($recipients);
    $copies = self::emails($copies);
    $to = array_values(array_diff($recipients, $copies));
    $internalOnly = $to === [] && $copies !== [];
    if ($internalOnly) $to = [($recipients ?: $copies)[0]];
    return ['to' => $to, 'cc' => $copies, 'internal_only' => $internalOnly];
  }

  /** Preserve per-recipient documents, names and roles while removing standalone copies. */
  public static function recipients(array $recipients, array $copies): array
  {
    $plan = self::plan(array_column($recipients, 'email'), $copies);
    $out = [];
    foreach ($plan['to'] as $email) {
      $record = ['email' => $email, 'name' => 'Equipo interno', 'role' => 'admin'];
      foreach ($recipients as $recipient) {
        if (strtolower(trim((string) ($recipient['email'] ?? ''))) === $email) {
          $record = $recipient;
          break;
        }
      }
      $record['email'] = $email;
      $record['internal_cc'] = $copies;
      $out[] = $record;
    }
    return $out;
  }
}
