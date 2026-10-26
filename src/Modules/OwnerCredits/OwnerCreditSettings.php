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
      'preapproval_margin_months' => 2,
      'default_term' => 12,
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
      'preapproval_margin_months' => $int($input['preapproval_margin_months'] ?? null, $defaults['preapproval_margin_months'], 0, 24),
      'default_term' => $int($input['default_term'] ?? null, $defaults['default_term'], 1, 60),
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
