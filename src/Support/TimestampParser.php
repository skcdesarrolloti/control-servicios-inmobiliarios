<?php


namespace SCM\Support;

final class TimestampParser
{
  public function __construct() {}

  public function parse($value): int
  {
    if ($value === null || $value === '') {
      return 0;
    }

    if (is_numeric($value)) {
      $ts = (int) $value;
      if ($ts > 9999999999) {
        $ts = (int) floor($ts / 1000);
      }

      return $ts > 0 ? $ts : 0;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
      return 0;
    }

    // If value already carries timezone info (Z or +/-HH:MM), trust it.
    if (preg_match('/(Z|[+\-]\d{2}:\d{2})$/i', $raw) === 1) {
      try {
        $dt = new \DateTimeImmutable($raw);
        return $dt->getTimestamp();
      } catch (\Throwable $e) {
        return 0;
      }
    }

    // MySQL datetime sin timezone: ya viene en hora Colombia (zona horaria del servidor MySQL).
    // Se usa strtotime() que respeta date_default_timezone (America/Bogota).
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $raw) === 1) {
      $ts = strtotime(str_replace('T', ' ', $raw));
      return $ts !== false ? $ts : 0;
    }

    $ts = strtotime($raw);
    return $ts === false ? 0 : (int) $ts;
  }
}
