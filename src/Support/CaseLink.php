<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Dirección de un caso en el panel: `SCM_BASE_URL/?scm_case=<_ID>`. Con sesión abre el caso en su ventana; sin sesión
 * pasa por el login y vuelve al caso (public/index.php). Reemplaza la página de tickets anterior
 * (`sucasainmobiliaria.com.co/ticket/?id_ticket=`), que ya no existe.
 *
 * Es para enlaces internos (tarjetas del panel, correos y avisos a funcionarios con copias). A un destinatario externo se le
 * envía la consulta pública firmada (`PublicCaseAccess::url`) y a un solo funcionario su enlace individual (`staffUrl`).
 */
final class CaseLink
{
  /** Base a la que se agrega el número del caso (`base() . $id`), para los lugares que arman el enlace por partes. */
  public static function base(): string
  {
    return (defined('SCM_BASE_URL') ? rtrim((string) SCM_BASE_URL, '/') : '') . '/?scm_case=';
  }

  /** Enlace del caso; vacío si el número no es válido. */
  public static function panelUrl(int|string $id): string
  {
    $id = trim((string) $id);
    return preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1 ? self::base() . $id : '';
  }
}
