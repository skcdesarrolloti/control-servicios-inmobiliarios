<?php

declare(strict_types=1);

namespace SCM\Support;

require_once __DIR__ . '/ColombiaBusinessCalendar.php';

/**
 * Configuración › Popups y avisos: cómo salen los popups de cada cargo y cuándo sale el recordatorio diario.
 * Qué popups tiene cada cargo sigue en `dashboard_alert_permissions` / `admin_due_popup_cargo_ids`; aquí solo el
 * comportamiento. Los valores por defecto repiten lo que hacía el panel antes de existir esta configuración.
 */
final class DashboardPopupSettings
{
  public const CARGO_SETTING = 'dashboard_popup_behavior';
  public const GENERAL_SETTING = 'dashboard_popup_general';

  /** Qué lista muestran los popups y el recordatorio. */
  public const SCOPES = [
    'assigned' => 'Lo asignado a la persona y lo del cargo sin responsable',
    'cargo' => 'Todo lo del cargo',
  ];

  /** Cada cuánto vuelven a salir, además de al entrar a Inicio. */
  public const FREQUENCIES = [
    '60' => 'Al entrar a Inicio y cada hora',
    '120' => 'Al entrar a Inicio y cada 2 horas',
    '240' => 'Al entrar a Inicio y cada 4 horas',
    'entry' => 'Solo al entrar a Inicio',
    'daily' => 'Una vez al día',
  ];

  public const CLOSE_MODES = [
    'confirm' => 'Pedir confirmación al cerrar',
    'free' => 'Cerrar directo',
    'snooze' => 'Cerrar directo y permitir «No mostrar más hoy»',
  ];

  public const WEEKDAYS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

  public const DEFAULT_CARGO = [
    'scope' => 'assigned',
    'frequency' => '60',
    'close' => 'confirm',
    'work_hours_only' => false,
    'reminder_scope' => 'assigned',
  ];

  public const DEFAULT_GENERAL = [
    'work_start' => '08:00',
    'work_end' => '18:00',
    'work_days' => [1, 2, 3, 4, 5, 6],
    'reminder_hour' => 9,
    'reminder_days' => [1, 2, 3, 4, 5, 6],
  ];

  /** Si el cron se cae, el recordatorio no sale más de estas horas después de la hora elegida. */
  public const REMINDER_WINDOW_HOURS = 3;

  /**
   * @param mixed $raw cargo => opciones
   * @param list<string> $cargos cargos válidos
   * @return array<string,array{scope:string,frequency:string,close:string,work_hours_only:bool,reminder_scope:string}>
   */
  public static function sanitizeCargos($raw, array $cargos): array
  {
    $clean = [];
    foreach (is_array($raw) ? $raw : [] as $cargo => $options) {
      $cargo = trim((string) $cargo);
      if ($cargo === '' || !in_array($cargo, $cargos, true) || !is_array($options)) continue;
      $clean[$cargo] = self::sanitizeCargo($options);
    }
    ksort($clean);
    return $clean;
  }

  /** @param array<string,mixed> $options @return array{scope:string,frequency:string,close:string,work_hours_only:bool,reminder_scope:string} */
  public static function sanitizeCargo(array $options): array
  {
    $pick = static function (string $key, array $allowed) use ($options): string {
      $value = (string) ($options[$key] ?? '');
      return isset($allowed[$value]) ? $value : (string) self::DEFAULT_CARGO[$key];
    };
    return [
      'scope' => $pick('scope', self::SCOPES),
      'frequency' => $pick('frequency', self::FREQUENCIES),
      'close' => $pick('close', self::CLOSE_MODES),
      'work_hours_only' => filter_var($options['work_hours_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
      'reminder_scope' => $pick('reminder_scope', self::SCOPES),
    ];
  }

  /** @param array<string,mixed> $map @return array{scope:string,frequency:string,close:string,work_hours_only:bool,reminder_scope:string} */
  public static function forCargo(array $map, string $cargo): array
  {
    $options = $map[trim($cargo)] ?? null;
    return is_array($options) ? self::sanitizeCargo($options) : self::DEFAULT_CARGO;
  }

  /** @param mixed $raw @return array{work_start:string,work_end:string,work_days:list<int>,reminder_hour:int,reminder_days:list<int>} */
  public static function sanitizeGeneral($raw): array
  {
    $raw = is_array($raw) ? $raw : [];
    $time = static function ($value, string $fallback): string {
      return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value) ? (string) $value : $fallback;
    };
    $days = static function ($value, array $fallback): array {
      if (!is_array($value)) return $fallback;
      $out = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $day): bool => $day >= 1 && $day <= 7)));
      sort($out);
      return $out;
    };
    $start = $time($raw['work_start'] ?? null, self::DEFAULT_GENERAL['work_start']);
    $end = $time($raw['work_end'] ?? null, self::DEFAULT_GENERAL['work_end']);
    if ($end <= $start) {
      $start = self::DEFAULT_GENERAL['work_start'];
      $end = self::DEFAULT_GENERAL['work_end'];
    }
    $hour = isset($raw['reminder_hour']) && is_numeric($raw['reminder_hour']) ? (int) $raw['reminder_hour'] : self::DEFAULT_GENERAL['reminder_hour'];
    return [
      'work_start' => $start,
      'work_end' => $end,
      'work_days' => $days($raw['work_days'] ?? null, self::DEFAULT_GENERAL['work_days']),
      'reminder_hour' => max(5, min(20, $hour)),
      'reminder_days' => $days($raw['reminder_days'] ?? null, self::DEFAULT_GENERAL['reminder_days']),
    ];
  }

  /**
   * El recordatorio sale una vez al día, desde la hora elegida y durante unas horas (por si el cron estuvo caído),
   * en los días elegidos y nunca en festivo.
   * @param array{reminder_hour:int,reminder_days:list<int>} $general
   */
  public static function reminderShouldRun(\DateTimeImmutable $now, string $lastDate, array $general): bool
  {
    $local = $now->setTimezone(new \DateTimeZone('America/Bogota'));
    $date = $local->format('Y-m-d');
    $hour = (int) $local->format('G');
    $from = (int) $general['reminder_hour'];
    if ($date === $lastDate || $hour < $from || $hour >= $from + self::REMINDER_WINDOW_HOURS) return false;
    if (!in_array((int) $local->format('N'), $general['reminder_days'], true)) return false;
    return !isset(\SkcColombiaBusinessCalendar::holidays((int) $local->format('Y'))[$date]);
  }

  /**
   * Filtra la lista según el alcance. `responsable_actual` y `sin_responsable` los pone adminDueMarkAssigned.
   * @param list<array<string,mixed>> $items @return list<array<string,mixed>>
   */
  public static function itemsForScope(array $items, string $scope, string $employeeId): array
  {
    if ($scope === 'cargo') return array_values($items);
    return array_values(array_filter($items, static function (array $item) use ($employeeId): bool {
      if (!empty($item['sin_responsable'])) return true;
      $owner = trim((string) ($item['responsable_actual'] ?? ''));
      return $employeeId !== '' && $owner === $employeeId;
    }));
  }
}
