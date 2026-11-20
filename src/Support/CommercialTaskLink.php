<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Enlaces a una tarea del panel comercial (control-servicios-comerciales, dashboard-comercial) para los casos que este
 * panel crea pero gestiona el comercial, como la retención de contrato (estado_comercial = Nuevo).
 *
 * Las firmas son copia de las del panel comercial y deben quedar iguales:
 * - staffUrl: SCM\Support\StaffTaskLink::reference (`commercial_task_login_v1|id|expira|id_empleado`), inicia la sesión del
 *   funcionario y abre la tarea; si no es válido, pasa por el login y vuelve a la tarea.
 * - publicUrl: SCM\Support\PublicTaskAccess::reference (`public_task_v1|id|expira`), vista pública de la tarea (tarea.php).
 * Se firman con COMMERCIAL_APP_SECRET o, si no se configura, con APP_SECRET: los dos paneles deben usar el mismo valor.
 */
final class CommercialTaskLink
{
  public const DEFAULT_BASE = 'https://sucasainmobiliaria.com.co/dashboard-comercial/public';
  private const STAFF_TTL = 30 * 86400;
  private const PUBLIC_TTL = 90 * 86400;
  private const EMPLOYEE = '/^[0-9A-Za-z_-]{1,40}$/D';

  public static function base(): string
  {
    $base = defined('SCM_COMMERCIAL_URL') ? trim((string) SCM_COMMERCIAL_URL) : '';
    return rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
  }

  /** Abre la tarea en el panel comercial, sin iniciar sesión (correos con copias). */
  public static function openUrl(int $id): string
  {
    return self::base() . '/index.php?tab=abiertos&ticket_id=' . $id . '&abrir_tarea=' . $id;
  }

  /** Enlace individual del funcionario: inicia su sesión en el panel comercial y abre la tarea. */
  public static function staffUrl(int $id, string $employeeId): string
  {
    $employeeId = trim($employeeId);
    if ($id < 1 || preg_match(self::EMPLOYEE, $employeeId) !== 1) {
      return self::openUrl(max(1, $id));
    }
    $expires = time() + self::STAFF_TTL;
    $signature = hash_hmac('sha256', 'commercial_task_login_v1|' . $id . '|' . $expires . '|' . $employeeId, self::secret());
    return self::base() . '/index.php?tab=abiertos&ticket_id=' . $id . '.' . $expires . '.' . $employeeId . '.' . $signature;
  }

  /** Vista pública de la tarea para el cliente: ve su historial y responde con fotos y PDF. */
  public static function publicUrl(int $id): string
  {
    return self::base() . '/tarea.php?t=' . self::publicReference($id);
  }

  /** Solo la referencia firmada: el botón de scm_tarea_comercial_registrada_v1 ya tiene fijo `.../tarea.php?t=`. */
  public static function publicReference(int $id): string
  {
    $expires = time() + self::PUBLIC_TTL;
    return $id . '.' . $expires . '.' . hash_hmac('sha256', 'public_task_v1|' . $id . '|' . $expires, self::secret());
  }

  private static function secret(): string
  {
    $secret = defined('SCM_COMMERCIAL_SECRET') ? (string) SCM_COMMERCIAL_SECRET : '';
    return $secret !== '' ? $secret : (string) SCM_APP_SECRET;
  }
}
