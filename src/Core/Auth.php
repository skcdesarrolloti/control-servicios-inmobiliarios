<?php

namespace SCM\Core;

/**
 * Autenticación basada en sesiones PHP.
 * Autentica contra la tabla wp_jet_cct_funcionarios del sistema.
 */
final class Auth
{
  private Database $db;

  public function __construct(Database $db)
  {
    $this->db = $db;
  }

  /**
   * Intenta autenticar con usuario (user_others_apss) y contraseña (pass_others_apss).
   * Solo funcionarios con activo = 'Si' pueden ingresar.
   */
  public function attempt(string $user, string $pass): bool
  {
    if ($user === '' || $pass === '') {
      return false;
    }

    $table = $this->db->table('jet_cct_funcionarios');
    $row = $this->db->getRow(
      "SELECT `_ID`, `nombre`, `rol`, `user_others_apss`, `pass_others_apss`,
              TRIM(COALESCE(`id_cargo`, '')) AS id_cargo
         FROM `{$table}`
        WHERE `user_others_apss` = ?
          AND `activo` = 'Si'
        LIMIT 1",
      [$user]
    );

    if (!is_array($row) || $row === []) {
      return false;
    }

    $storedPass = (string) ($row['pass_others_apss'] ?? '');

    // Soporta hash bcrypt (password_hash) y texto plano
    $passwordOk = strncmp($storedPass, '$2', 2) === 0
      ? password_verify($pass, $storedPass)
      : hash_equals($storedPass, $pass);

    if (!$passwordOk) {
      return false;
    }

    session_regenerate_id(true);
    $_SESSION['scm_logged_in']  = true;
    $_SESSION['scm_user_id']    = (int) $row['_ID'];
    $_SESSION['scm_user']       = trim((string) ($row['nombre'] ?? $user));
    $_SESSION['scm_user_login'] = $user;
    $_SESSION['scm_user_rol']   = (string) ($row['rol'] ?? '');
    $_SESSION['scm_user_cargo'] = trim((string) ($row['id_cargo'] ?? ''));

    return true;
  }

  public function logout(): void
  {
    $_SESSION = [];
    session_destroy();
  }

  public static function isLoggedIn(): bool
  {
    return !empty($_SESSION['scm_logged_in']);
  }

  /** Devuelve el nombre completo del funcionario autenticado. */
  public static function user(): string
  {
    return (string) ($_SESSION['scm_user'] ?? '');
  }

  /** Devuelve el login (user_others_apss) del funcionario autenticado. */
  public static function userLogin(): string
  {
    return (string) ($_SESSION['scm_user_login'] ?? '');
  }

  /** Devuelve el _ID del funcionario autenticado. */
  public static function userId(): int
  {
    return (int) ($_SESSION['scm_user_id'] ?? 0);
  }

  /** Devuelve el rol del funcionario autenticado. */
  public static function userRol(): string
  {
    return (string) ($_SESSION['scm_user_rol'] ?? '');
  }

  /** Devuelve el id_cargo del funcionario autenticado. */
  public static function userCargo(): string
  {
    return (string) ($_SESSION['scm_user_cargo'] ?? '');
  }

  /** Redirige a login si no está autenticado. */
  public static function requireLogin(string $loginUrl = '/login.php'): void
  {
    if (!static::isLoggedIn()) {
      header('Location: ' . $loginUrl);
      exit;
    }
  }
}
