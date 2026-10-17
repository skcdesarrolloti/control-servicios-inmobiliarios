<?php

namespace SCM\Core;

/**
 * Tokens CSRF simples (reemplaza wp_nonce_field/wp_verify_nonce).
 */
final class Csrf
{
  private string $secret;

  public function __construct(string $secret)
  {
    $this->secret = $secret;
  }

  /** Tokens recientes que siguen siendo válidos por acción (una pestaña o página por token). */
  private const POOL_SIZE = 30;

  /**
   * Genera un token CSRF para una acción.
   *
   * Cada pestaña o página del panel recibe su propio token. Los anteriores siguen siendo válidos
   * (hasta POOL_SIZE por acción): abrir el panel en otra pestaña no invalida las que ya están abiertas.
   */
  public function token(string $action): string
  {
    $salt  = bin2hex(random_bytes(8));
    $value = hash_hmac('sha256', $action . '|' . $salt, $this->secret);
    $token = $salt . '|' . $value;

    // El último token queda en scm_csrf (algunas páginas lo reutilizan) y todos en el pool.
    $_SESSION['scm_csrf'][$action] = $token;
    $pool = is_array($_SESSION['scm_csrf_pool'][$action] ?? null) ? $_SESSION['scm_csrf_pool'][$action] : [];
    $pool[] = $token;
    $_SESSION['scm_csrf_pool'][$action] = array_slice(array_values(array_unique($pool)), -self::POOL_SIZE);
    return $token;
  }

  /**
   * Verifica un token CSRF.
   *
   * @param bool $singleUse true (defecto) para formularios: invalida el token tras el primer uso.
   *                        false para nonces AJAX: permite múltiples verificaciones con el mismo token.
   */
  public function verify(string $action, string $token, bool $singleUse = true): bool
  {
    if ($token === '') {
      return false;
    }

    $latest = is_string($_SESSION['scm_csrf'][$action] ?? null) ? $_SESSION['scm_csrf'][$action] : '';
    $pool = is_array($_SESSION['scm_csrf_pool'][$action] ?? null) ? $_SESSION['scm_csrf_pool'][$action] : [];
    $ok = $latest !== '' && hash_equals($latest, $token);
    foreach ($pool as $candidate) {
      $ok = $ok || (is_string($candidate) && hash_equals($candidate, $token));
    }

    if ($ok && $singleUse) {
      if ($latest !== '' && hash_equals($latest, $token)) {
        unset($_SESSION['scm_csrf'][$action]);
      }
      $_SESSION['scm_csrf_pool'][$action] = array_values(array_filter($pool, static fn($candidate): bool => !is_string($candidate) || !hash_equals($candidate, $token)));
    }

    return $ok;
  }

  /** Renderiza un campo hidden con el token. */
  public function field(string $action): string
  {
    $token = $this->token($action);
    $esc   = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $act   = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    return "<input type=\"hidden\" name=\"_csrf_token\" value=\"{$esc}\">"
      . "<input type=\"hidden\" name=\"_csrf_action\" value=\"{$act}\">";
  }

  /** Verifica el token del POST actual. */
  public function check(string $action): bool
  {
    $token = (string) ($_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $this->verify($action, $token);
  }
}
