<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Core\Settings;

/**
 * Configuración › Hoja de cierre: qué avisos salen además de los correos internos (los funcionarios se eligen en
 * Notificaciones internas). Los WhatsApp empiezan apagados hasta que Meta apruebe las plantillas.
 */
final class ClosingSheetSettings
{
  public const KEY = 'closing_sheet_notifications';

  /** Opción => [título, descripción, valor por defecto]. */
  public const OPTIONS = [
    'owner_email' => ['Correo al propietario con la revisión de servicios', 'Al registrar la revisión antes de la ocupación: resultado por servicio y enlace a la revisión. Va al propietario y a los copropietarios con correo.', true],
    'owner_whatsapp' => ['WhatsApp al propietario con la revisión de servicios', 'Plantilla scm_revision_servicios_entrega_propietario_v1.', false],
    'staff_whatsapp' => ['WhatsApp a funcionarios', 'Hoja creada (scm_hoja_cierre_revision_servicios_v1), hoja por responder (scm_hoja_cierre_por_responder_v1) y respuesta (scm_hoja_cierre_respuesta_v1). Cada uno lleva su enlace para actuar sin iniciar sesión.', false],
  ];

  public function __construct(private Database $db)
  {
  }

  /** @return array<string,bool> */
  public function read(): array
  {
    $stored = (new Settings($this->db, true))->get(self::KEY, []);
    $stored = is_array($stored) ? $stored : [];
    $out = [];
    foreach (self::OPTIONS as $key => [, , $default]) {
      $out[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : $default;
    }
    return $out;
  }

  /** @param array<string,mixed> $input @return array<string,bool> */
  public function save(array $input, int $updatedBy): array
  {
    $out = [];
    foreach (array_keys(self::OPTIONS) as $key) {
      $out[$key] = in_array((string) ($input[$key] ?? ''), ['1', 'true', 'on', 'si', 'Si'], true);
    }
    (new Settings($this->db))->set(self::KEY, $out, $updatedBy);
    return $out;
  }
}
