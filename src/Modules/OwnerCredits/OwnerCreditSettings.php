<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Core\Settings;

/** Variables de entrada del simulador (hoja "Variables de Entrada" del Excel), editables desde el panel. */
final class OwnerCreditSettings
{
  public const KEY = 'owner_credit_settings';
  public const PURPOSES = [
    'Pago de Impuestos',
    'Para Mejoras y/o Reparaciones al Inmueble',
    'Mejoras y/o Reparaciones a otro Inmueble',
    'Educación',
    'Libre Destinación',
    'Pago Expensas',
    'Pago Administración',
    'Pago de Servicios Públicos',
    'Compra de Electrodomésticos',
    'Equipos de Oficina',
  ];

  /** @return array<string,mixed> */
  public static function defaults(): array
  {
    return [
      'usury_auto' => true,
      'usury_annual' => 0.2859,
      'usury_updated_at' => '2026-10-01',
      'usury_valid_until' => '2026-10-31',
      'usury_bank_rate' => 0.1906,
      'usury_resolution' => '1472',
      'usury_synced_at' => 0,
      'usury_sync_error' => '',
      'no_mandate_deduction' => 0.15,
      'reference_rate' => 0.0242,
      'policy_discount' => 0.05,
      'income_months' => 3,
      'min_remaining_months' => 3,
      // Plazo máximo = cuotas que vencen antes del fin del contrato más largo menos este margen (n − 2).
      'term_margin_months' => 2,
      // Cobertura de pago: % del ingreso neto de cada contrato que se puede usar para la cuota y cuántas veces debe
      // alcanzar el disponible a la cuota (1 = alcanza exacto). Los define la empresa.
      'usable_income_factor' => 1.0,
      'min_coverage' => 1.0,
      'default_term' => 12,
      // Aprobar exige el pagaré en blanco y la carta de instrucciones firmados por cada firmante elegido.
      'require_promissory' => true,
      'notify_owner_request_email' => true,
      'notify_owner_request_whatsapp' => false,
      'notify_owner_email' => true,
      'notify_owner_whatsapp' => false,
      'notify_staff_whatsapp' => true,
    ];
  }

  /** @return array<string,mixed> */
  public static function read(Settings $settings): array
  {
    $stored = $settings->get(self::KEY, []);
    return self::normalize(is_array($stored) ? $stored : []);
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public static function normalize(array $input): array
  {
    $defaults = self::defaults();
    $rate = static function ($value, float $default, float $max): float {
      $text = str_replace(',', '.', trim((string) $value));
      if ($text === '' || !is_numeric(rtrim($text, '%'))) {
        return $default;
      }
      $number = (float) rtrim($text, '%');
      // Se acepta 33,3 o 0,333: los valores desde 1 se toman como porcentaje.
      if (str_ends_with($text, '%') || $number >= 1) {
        $number /= 100;
      }
      return max(0.0, min($max, $number));
    };
    // Porción de 0 a 1: "100" o "100 %" es todo el ingreso; 1 (o 1,0 guardado) también es el 100 %.
    $share = static function ($value, float $default): float {
      $text = str_replace(',', '.', trim((string) $value));
      if ($text === '' || !is_numeric(rtrim($text, '%'))) return $default;
      $number = (float) rtrim($text, '%');
      if (str_ends_with($text, '%') || $number > 1) $number /= 100;
      return max(0.0, min(1.0, $number));
    };
    // Veces la cuota (1,00 a 5,00), no porcentaje.
    $coverage = static function ($value, float $default): float {
      $text = str_replace(',', '.', trim((string) $value));
      return $text === '' || !is_numeric($text) ? $default : max(1.0, min(5.0, round((float) $text, 2)));
    };
    $int = static fn($value, int $default, int $min, int $max): int => is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    $bool = static fn($value, bool $default): bool => $value === null ? $default : in_array(strtolower(trim((string) $value)), ['1', 'true', 'si', 'sí', 'on', 'yes'], true);
    $date = trim((string) ($input['usury_updated_at'] ?? ''));
    $until = trim((string) ($input['usury_valid_until'] ?? ''));
    return [
      'usury_auto' => $bool($input['usury_auto'] ?? null, $defaults['usury_auto']),
      'usury_annual' => $rate($input['usury_annual'] ?? null, $defaults['usury_annual'], 1.0),
      'usury_updated_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $defaults['usury_updated_at'],
      'usury_valid_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 ? $until : '',
      'usury_bank_rate' => $rate($input['usury_bank_rate'] ?? null, 0.0, 1.0),
      'usury_resolution' => mb_substr(trim((string) ($input['usury_resolution'] ?? '')), 0, 20),
      'usury_synced_at' => max(0, (int) ($input['usury_synced_at'] ?? 0)),
      'usury_sync_error' => mb_substr(trim((string) ($input['usury_sync_error'] ?? '')), 0, 200),
      'no_mandate_deduction' => $rate($input['no_mandate_deduction'] ?? null, $defaults['no_mandate_deduction'], 1.0),
      'reference_rate' => $rate($input['reference_rate'] ?? null, $defaults['reference_rate'], 0.2),
      'policy_discount' => $rate($input['policy_discount'] ?? null, $defaults['policy_discount'], 1.0),
      'income_months' => $int($input['income_months'] ?? null, $defaults['income_months'], 1, 24),
      'min_remaining_months' => $int($input['min_remaining_months'] ?? null, $defaults['min_remaining_months'], 0, 60),
      'usable_income_factor' => $share($input['usable_income_factor'] ?? null, $defaults['usable_income_factor']),
      'min_coverage' => $coverage($input['min_coverage'] ?? null, $defaults['min_coverage']),
      'term_margin_months' => $int($input['term_margin_months'] ?? null, $defaults['term_margin_months'], 0, 12),
      'default_term' => $int($input['default_term'] ?? null, $defaults['default_term'], 1, 60),
      'require_promissory' => $bool($input['require_promissory'] ?? null, $defaults['require_promissory']),
      'notify_owner_request_email' => $bool($input['notify_owner_request_email'] ?? null, $defaults['notify_owner_request_email']),
      'notify_owner_request_whatsapp' => $bool($input['notify_owner_request_whatsapp'] ?? null, $defaults['notify_owner_request_whatsapp']),
      'notify_owner_email' => $bool($input['notify_owner_email'] ?? null, $defaults['notify_owner_email']),
      'notify_owner_whatsapp' => $bool($input['notify_owner_whatsapp'] ?? null, $defaults['notify_owner_whatsapp']),
      'notify_staff_whatsapp' => $bool($input['notify_staff_whatsapp'] ?? null, $defaults['notify_staff_whatsapp']),
    ];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  public static function save(Settings $settings, array $input, int $updatedBy): array
  {
    $normalized = self::normalize($input);
    $settings->set(self::KEY, $normalized, $updatedBy);
    return $normalized;
  }
}
