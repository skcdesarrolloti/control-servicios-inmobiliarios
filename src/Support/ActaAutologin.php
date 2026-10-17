<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Autologin de funcionarios desde WordPress (`&id_empleado=…&token=…`).
 *
 * El token recomendado es una firma HMAC del id_empleado con ACTA_AUTOLOGIN_SECRET: solo sirve para
 * ese funcionario, así un enlace compartido no permite entrar como otro cambiando el id_empleado.
 * Por compatibilidad se sigue aceptando el secreto completo como token en los enlaces ya publicados.
 */
final class ActaAutologin
{
  public static function tokenFor(string $secret, string $employeeId): string
  {
    return hash_hmac('sha256', 'acta-autologin|' . trim($employeeId), $secret);
  }

  public static function valid(string $secret, string $employeeId, string $token): bool
  {
    $secret = trim($secret);
    $employeeId = trim($employeeId);
    $token = trim($token);
    if ($secret === '' || $employeeId === '' || $token === '') {
      return false;
    }
    return hash_equals(self::tokenFor($secret, $employeeId), $token) || hash_equals($secret, $token);
  }

  /** Agrega id_empleado y token firmado a un enlace del puente o de crear-acta.php. */
  public static function link(string $secret, string $url, string $employeeId): string
  {
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query([
      'id_empleado' => trim($employeeId),
      'token' => self::tokenFor($secret, $employeeId),
    ], '', '&', PHP_QUERY_RFC3986);
  }
}
