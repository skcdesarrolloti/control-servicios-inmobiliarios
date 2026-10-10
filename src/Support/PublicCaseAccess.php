<?php

declare(strict_types=1);

namespace SCM\Support;

final class PublicCaseAccess
{
  public const TTL = 30 * 86400;

  public static function reference(int $id, ?int $expires = null, string $audience = 'publico'): string
  {
    if ($id < 1) throw new \InvalidArgumentException('Caso inválido.');
    if (!in_array($audience, ['publico', 'funcionario', 'propietario', 'arrendatario', 'copropiedad'], true)) throw new \InvalidArgumentException('Destinatario inválido.');
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . $audience . '.' . self::signature($id, $expires, $audience);
  }

  public static function url(int $id, string $audience = 'publico'): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . self::reference($id, null, $audience);
  }

  /** One numeric ID remains compatible with internal links; public access requires the signed form. */
  public static function route(string $reference, bool $loggedIn): array
  {
    $denied = ['mode' => 'denied', 'id' => 0, 'audience' => 'publico'];
    if (!preg_match('/^([1-9][0-9]{0,17})(?:\.([0-9]{10})\.(?:(publico|funcionario|propietario|arrendatario|copropiedad)\.)?([a-f0-9]{64}))?$/D', $reference, $parts)) return $denied;
    $id = (int) $parts[1];
    $audience = ($parts[3] ?? '') ?: 'publico';
    if ($loggedIn) return ['mode' => 'panel', 'id' => $id, 'audience' => $audience];
    $expires = (int) ($parts[2] ?? 0);
    $signedAudience = ($parts[3] ?? '') !== '' ? $audience : null;
    if ($expires <= time() || !hash_equals(self::signature($id, $expires, $signedAudience), $parts[4] ?? '')) return $denied;
    return ['mode' => 'public', 'id' => $id, 'audience' => $audience];
  }

  private static function signature(int $id, int $expires, ?string $audience = null): string
  {
    $data = $audience === null ? 'public_case_v1|' . $id . '|' . $expires : 'public_case_v2|' . $id . '|' . $expires . '|' . $audience;
    return hash_hmac('sha256', $data, (string) SCM_APP_SECRET);
  }
}
