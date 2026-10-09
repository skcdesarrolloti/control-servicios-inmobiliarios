<?php

declare(strict_types=1);

namespace SCM\Support;

final class PublicCaseAccess
{
  public const TTL = 30 * 86400;

  public static function reference(int $id, ?int $expires = null): string
  {
    if ($id < 1) throw new \InvalidArgumentException('Caso inválido.');
    $expires ??= time() + self::TTL;
    return $id . '.' . $expires . '.' . self::signature($id, $expires);
  }

  public static function url(int $id): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . self::reference($id);
  }

  /** One numeric ID remains compatible with internal links; public access requires the signed form. */
  public static function route(string $reference, bool $loggedIn): array
  {
    if (!preg_match('/^([1-9][0-9]{0,17})(?:\.([0-9]{10})\.([a-f0-9]{64}))?$/D', $reference, $parts)) return ['mode' => 'denied', 'id' => 0];
    $id = (int) $parts[1];
    if ($loggedIn) return ['mode' => 'panel', 'id' => $id];
    $expires = (int) ($parts[2] ?? 0);
    if ($expires <= time() || !hash_equals(self::signature($id, $expires), $parts[3] ?? '')) return ['mode' => 'denied', 'id' => 0];
    return ['mode' => 'public', 'id' => $id];
  }

  private static function signature(int $id, int $expires): string
  {
    return hash_hmac('sha256', 'public_case_v1|' . $id . '|' . $expires, (string) SCM_APP_SECRET);
  }
}
