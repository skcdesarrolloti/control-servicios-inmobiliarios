<?php

declare(strict_types=1);

$env = static function (string $key, ?string $default = null): ?string {
  $value = getenv($key);
  return $value === false ? $default : $value;
};

$envBool = static function (string $key, bool $default = false) use ($env): bool {
  $value = $env($key);
  if ($value === null) {
    return $default;
  }
  return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};

return [
  'environment' => $env('APP_ENV', 'production'),
  'debug' => $envBool('APP_DEBUG', false),
  'secure_cookies' => $envBool('APP_SECURE_COOKIES', true),
  'session_idle_timeout' => max(900, (int) $env('SESSION_IDLE_TIMEOUT', '7200')),
  'timezone' => $env('APP_TIMEZONE', 'America/Bogota'),
  'base_url' => $env('APP_URL', ''),
  'app_secret' => $env('APP_SECRET', ''),

  'db_host' => $env('DB_HOST', ''),
  'db_port' => (int) $env('DB_PORT', '3306'),
  'db_name' => $env('DB_DATABASE', ''),
  'db_user' => $env('DB_USERNAME', ''),
  'db_pass' => $env('DB_PASSWORD', ''),
  'db_prefix' => $env('DB_PREFIX', 'wp_'),
  'db_charset' => $env('DB_CHARSET', 'utf8mb4'),

  'sla_risk_days' => (int) $env('SLA_RISK_DAYS', '8'),
  'sla_overdue_days' => (int) $env('SLA_OVERDUE_DAYS', '15'),
  'default_per_page' => (int) $env('DEFAULT_PER_PAGE', '10'),

  'ticket_url' => $env('TICKET_URL', ''),
  'preventiva_url' => $env('PREVENTIVA_URL', ''),
  'correctiva_url' => $env('CORRECTIVA_URL', ''),
  'cotizacion_url' => $env('COTIZACION_URL', ''),
  'group_comercial' => $env('GROUP_COMERCIAL', ''),
  'shared_notifications_path' => $env('SHARED_NOTIFICATIONS_PATH', ''),
  'allow_legacy_passwords' => $envBool('AUTH_ALLOW_LEGACY_PASSWORDS', false),

  'upload_max_bytes' => (int) $env('UPLOAD_MAX_BYTES', '10485760'),
  'upload_max_files' => (int) $env('UPLOAD_MAX_FILES', '10'),

  'cliente_notifications_override' => [
    'enabled' => $envBool('CLIENTE_NOTIFICATION_OVERRIDE_ENABLED', false),
    'email' => $env('CLIENTE_NOTIFICATION_OVERRIDE_EMAIL', ''),
    'whatsapp' => $env('CLIENTE_NOTIFICATION_OVERRIDE_WHATSAPP', ''),
    'name' => $env('CLIENTE_NOTIFICATION_OVERRIDE_NAME', ''),
  ],
];
