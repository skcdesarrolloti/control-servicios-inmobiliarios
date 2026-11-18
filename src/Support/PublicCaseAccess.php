<?php

declare(strict_types=1);

namespace SCM\Support;

final class PublicCaseAccess
{
  public const TTL = 30 * 86400;
  private const EMPLOYEE_PATTERN = '/^[0-9A-Za-z_-]{1,40}$/D';

  /**
   * Referencia firmada del caso. Con $employeeId (solo audiencia funcionario) la firma también ata al funcionario
   * y el enlace inicia su sesión: enviarla solo en mensajes individuales, nunca en correos con copias.
   */
  public static function reference(int $id, ?int $expires = null, string $audience = 'publico', string $employeeId = ''): string
  {
    if ($id < 1) throw new \InvalidArgumentException('Caso inválido.');
    if (!in_array($audience, ['publico', 'funcionario', 'propietario', 'arrendatario', 'copropiedad'], true)) throw new \InvalidArgumentException('Destinatario inválido.');
    $expires ??= time() + self::TTL;
    $employeeId = trim($employeeId);
    if ($employeeId !== '') {
      if ($audience !== 'funcionario' || !preg_match(self::EMPLOYEE_PATTERN, $employeeId)) throw new \InvalidArgumentException('Funcionario inválido.');
      return $id . '.' . $expires . '.funcionario.' . $employeeId . '.' . self::signature($id, $expires, 'funcionario', $employeeId);
    }
    return $id . '.' . $expires . '.' . $audience . '.' . self::signature($id, $expires, $audience);
  }

  public static function url(int $id, string $audience = 'publico'): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . self::reference($id, null, $audience);
  }

  /** Enlace de funcionario con autologin; sin id_empleado válido queda como enlace firmado de funcionario sin sesión. */
  public static function staffReference(int $id, string $employeeId): string
  {
    $employeeId = trim($employeeId);
    return self::reference($id, null, 'funcionario', preg_match(self::EMPLOYEE_PATTERN, $employeeId) ? $employeeId : '');
  }

  public static function staffUrl(int $id, string $employeeId): string
  {
    return rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . self::staffReference($id, $employeeId);
  }

  /** One numeric ID remains compatible with internal links; public access requires the signed form. */
  public static function route(string $reference, bool $loggedIn): array
  {
    $denied = ['mode' => 'denied', 'id' => 0, 'audience' => 'publico'];
    if (!preg_match('/^([1-9][0-9]{0,17})(?:\.([0-9]{10})\.(?:(publico|funcionario|propietario|arrendatario|copropiedad)\.(?:([0-9A-Za-z_-]{1,40})\.)?)?([a-f0-9]{64}))?$/D', $reference, $parts)) return $denied;
    $id = (int) $parts[1];
    $audience = ($parts[3] ?? '') ?: 'publico';
    $employeeId = $parts[4] ?? '';
    if ($employeeId !== '' && $audience !== 'funcionario') return $denied;
    if ($loggedIn) return ['mode' => 'panel', 'id' => $id, 'audience' => $audience];
    $expires = (int) ($parts[2] ?? 0);
    $signedAudience = ($parts[3] ?? '') !== '' ? $audience : null;
    if ($expires <= time() || !hash_equals(self::signature($id, $expires, $signedAudience, $employeeId), $parts[5] ?? '')) return $denied;
    if ($employeeId !== '') return ['mode' => 'login', 'id' => $id, 'audience' => 'funcionario', 'employee_id' => $employeeId];
    return ['mode' => 'public', 'id' => $id, 'audience' => $audience];
  }

  private static function signature(int $id, int $expires, ?string $audience = null, string $employeeId = ''): string
  {
    if ($employeeId !== '') $data = 'public_case_login_v1|' . $id . '|' . $expires . '|funcionario|' . $employeeId;
    else $data = $audience === null ? 'public_case_v1|' . $id . '|' . $expires : 'public_case_v2|' . $id . '|' . $expires . '|' . $audience;
    return hash_hmac('sha256', $data, (string) SCM_APP_SECRET);
  }
}
