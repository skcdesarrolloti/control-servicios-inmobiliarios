<?php
declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;

/** Adds the action's employee to CC when an application email is first queued. */
final class NotificationActorCopy
{
  public const SETTING = 'email_actor_cc_enabled';
  private array $actors = [];
  private ?Settings $fallbackSettings = null;
  private static ?\WeakMap $policies = null;

  public function __construct(private Database $db) {}

  public static function apply(Database $db, array $row): array
  {
    self::$policies ??= new \WeakMap();
    $pdo = $db->pdo();
    self::$policies[$pdo] ??= new self($db);
    return self::$policies[$pdo]->enrich($row);
  }

  public function enabled(): bool
  {
    try {
      if (App::db()->pdo() === $this->db->pdo()) return (bool) App::settings()->get(self::SETTING, true);
    } catch (\Throwable) {}
    $this->fallbackSettings ??= new Settings($this->db, true);
    return (bool) $this->fallbackSettings->get(self::SETTING, true);
  }

  public function enrich(array $row): array
  {
    if (($row['project_code'] ?? '') !== 'control-servicios-inmobiliarios' || ($row['channel'] ?? '') !== 'email') return $row;
    $payload = json_decode((string) ($row['payload_json'] ?? '[]'), true);
    $meta = json_decode((string) ($row['meta_json'] ?? '[]'), true);
    if (!is_array($payload) || !is_array($meta)) return $row;
    $audit = ['status' => 'no_actor'];
    try {
      if (($meta['event'] ?? '') === 'signature_otp') {
        $audit['status'] = 'signer_only';
      } elseif (!$this->enabled()) {
        $audit['status'] = 'disabled';
      } else {
        $employeeId = trim((string) ($meta['notification_actor_employee_id'] ?? ''));
        $creatorEmail = strtolower(trim((string) ($meta['notification_actor_email'] ?? '')));
        $pk = 0;
        $loggedIn = $employeeId === '' && Auth::isLoggedIn();
        if ($loggedIn) {
          $pk = Auth::userId();
          // Session PK is only a lookup key; the audit always stores real id_empleado.
          if ($pk <= 0) $employeeId = trim((string) ($_SESSION['scm_employee_id'] ?? ''));
        }
        if ($employeeId !== '' || $pk > 0 || (!$loggedIn && filter_var($creatorEmail, FILTER_VALIDATE_EMAIL))) {
          $actor = $this->actor($employeeId, $pk, !$loggedIn ? $creatorEmail : '');
          $audit = ['status' => 'employee_unavailable', 'employee_id' => (string) ($actor['id_empleado'] ?? $employeeId)];
          if ($actor && trim((string) ($actor['id_empleado'] ?? '')) !== '' && mb_strtolower(trim((string) ($actor['activo'] ?? '')), 'UTF-8') === 'si') {
            $email = strtolower(trim((string) ($actor['correo'] ?? '')));
            $audit['status'] = 'email_missing';
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
              $to = strtolower(trim((string) ($row['destination'] ?? '')));
              $cc = self::emails($payload['cc'] ?? []);
              $cc[$email] = $email;
              unset($cc[$to]);
              $payload['cc'] = array_values($cc);
              if (isset($payload['bcc'])) {
                $bcc = self::emails($payload['bcc']);
                unset($bcc[$to]);
                $payload['bcc'] = array_values(array_diff_key($bcc, $cc));
              }
              $audit['status'] = $email === $to ? 'already_to' : 'cc';
              $audit['email'] = $email;
              $audit['name'] = (string) ($actor['nombre'] ?? '');
            }
          }
        }
      }
    } catch (\Throwable $error) {
      // A missing staff email/configuration must not lose the original notification.
      $audit = ['status' => 'lookup_failed'];
      error_log('[notification-actor-cc] No se pudo resolver la copia del funcionario: ' . get_class($error));
    }
    $meta['actor_email_copy'] = $audit;
    $row['payload_json'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $row['meta_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return $row;
  }

  private function actor(string $employeeId, int $pk, string $creatorEmail): array
  {
    $key = $employeeId !== '' ? 'employee:' . $employeeId : ($pk > 0 ? 'pk:' . $pk : 'email:' . $creatorEmail);
    $cached = $this->actors[$key] ?? [];
    if ($cached && $cached['until'] > microtime(true)) return $cached['row'];
    $table = $this->db->table('jet_cct_funcionarios');
    $column = $employeeId !== '' ? '`id_empleado`' : ($pk > 0 ? '`_ID`' : 'LOWER(TRIM(`correo`))');
    $value = $employeeId !== '' ? $employeeId : ($pk > 0 ? $pk : $creatorEmail);
    $row = $this->db->getRow("SELECT `_ID`, `id_empleado`, `nombre`, `correo`, `activo` FROM `{$table}` WHERE {$column} = ? LIMIT 1", [$value]) ?? [];
    $this->actors[$key] = ['until' => microtime(true) + 5, 'row' => $row];
    return $row;
  }

  private static function emails($values): array
  {
    $out = [];
    foreach (is_array($values) ? $values : (preg_split('/[,;]+/', (string) $values) ?: []) as $value) {
      $email = strtolower(trim((string) $value));
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out[$email] = $email;
    }
    return $out;
  }
}
