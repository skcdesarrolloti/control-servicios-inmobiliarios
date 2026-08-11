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

  /** Genera un token CSRF para una acción. */
  public function token(string $action): string
  {
    $salt  = bin2hex(random_bytes(8));
    $value = hash_hmac('sha256', $action . '|' . $salt, $this->secret);
    $token = $salt . '|' . $value;

    // Guardarlo en sesión para validación
    $_SESSION['scm_csrf'][$action] = $token;
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
    if ($token === '' || !isset($_SESSION['scm_csrf'][$action])) {
      return false;
    }

    $stored = $_SESSION['scm_csrf'][$action];
    $ok     = hash_equals($stored, $token);

    if ($ok && $singleUse) {
      unset($_SESSION['scm_csrf'][$action]);
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
