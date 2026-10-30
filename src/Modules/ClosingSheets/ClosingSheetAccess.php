<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

/**
 * Enlaces firmados de la hoja de cierre para funcionarios sin sesión del panel (correo y WhatsApp).
 * Cada enlace sirve solo para una hoja, una acción y un funcionario, y vence: así queda registrado quién revisó o
 * respondió, y cambiar ?numero= no abre otra hoja. Con sesión del panel basta el enlace sin firma.
 */
final class ClosingSheetAccess
{
  /** Revisión de servicios públicos antes de la ocupación. */
  public const REVIEW = 'revision';
  /** Ver la hoja y aprobarla o no aprobarla. */
  public const RESPOND = 'respuesta';
  /** Solo ver la hoja. */
  public const VIEW = 'ver';

  private const PAGES = [self::REVIEW => 'revision-servicios-cierre.php', self::RESPOND => 'hoja-cierre-ver.php', self::VIEW => 'hoja-cierre-ver.php'];
  private const DAYS = [self::REVIEW => 30, self::RESPOND => 30, self::VIEW => 180];

  /** Enlace a la página de la acción; con $employeeId lleva la firma de ese funcionario. */
  public static function url(string $purpose, int $closingId, string $employeeId = '', ?int $expires = null): string
  {
    $query = ['numero' => $closingId];
    $employeeId = trim($employeeId);
    if ($employeeId !== '') {
      $expires ??= time() + self::DAYS[$purpose] * 86400;
      $query += ['p' => $purpose, 'f' => $employeeId, 'expires' => $expires, 'sig' => self::signature($purpose, $closingId, $employeeId, $expires)];
    }
    return rtrim((string) SCM_BASE_URL, '/') . '/' . self::PAGES[$purpose] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  public static function signature(string $purpose, int $closingId, string $employeeId, int $expires): string
  {
    return hash_hmac('sha256', 'closing-sheet|' . $purpose . '|' . $closingId . '|' . $employeeId . '|' . $expires, (string) SCM_APP_SECRET);
  }

  /**
   * Firma recibida (GET o POST). Devuelve la acción y el funcionario, o null si falta, no corresponde o venció.
   * @param array<string,mixed> $params @return array{purpose:string,employee_id:string}|null
   */
  public static function verify(array $params, int $closingId): ?array
  {
    $purpose = (string) ($params['p'] ?? '');
    $employeeId = trim((string) ($params['f'] ?? ''));
    $expires = (int) ($params['expires'] ?? 0);
    $signature = (string) ($params['sig'] ?? '');
    if ($closingId <= 0 || !isset(self::PAGES[$purpose]) || preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $employeeId) !== 1 || $expires < time() || $signature === '') {
      return null;
    }
    return hash_equals(self::signature($purpose, $closingId, $employeeId, $expires), $signature) ? ['purpose' => $purpose, 'employee_id' => $employeeId] : null;
  }

  /** Campos ocultos para repetir la firma al enviar el formulario. @param array{purpose:string,employee_id:string}|null $access @param array<string,mixed> $params @return array<string,string> */
  public static function hiddenFields(?array $access, array $params): array
  {
    if ($access === null) {
      return [];
    }
    return ['p' => $access['purpose'], 'f' => $access['employee_id'], 'expires' => (string) (int) ($params['expires'] ?? 0), 'sig' => (string) ($params['sig'] ?? '')];
  }
}
