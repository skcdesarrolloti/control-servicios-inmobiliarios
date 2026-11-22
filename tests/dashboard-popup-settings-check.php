<?php

declare(strict_types=1);

// Configuración › Popups y avisos: valores por defecto, saneo, horario del recordatorio y alcance de las listas.
// Solo clases puras: no usa base de datos ni envía mensajes.

require_once __DIR__ . '/../vendor/autoload.php';

use SCM\Modules\ContractMovements\ContractReceiptCases;
use SCM\Support\DashboardPopupSettings as Settings;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException($label);
  $checks++;
  echo "PASS: {$label}\n";
};
$at = static fn(string $local): DateTimeImmutable => new DateTimeImmutable($local, new DateTimeZone('America/Bogota'));

// Por defecto se comporta como antes de existir la configuración.
$check(Settings::forCargo([], '12') === Settings::DEFAULT_CARGO, 'a cargo never saved keeps the previous behaviour');
$check(Settings::DEFAULT_CARGO['frequency'] === '60' && Settings::DEFAULT_CARGO['close'] === 'confirm', 'default is every hour and confirm on close');

// Saneo por cargo: valores desconocidos vuelven al defecto y los cargos inexistentes se descartan.
$clean = Settings::sanitizeCargos([
  '12' => ['scope' => 'cargo', 'frequency' => 'daily', 'close' => 'snooze', 'work_hours_only' => true, 'reminder_scope' => 'cargo'],
  '13' => ['scope' => 'everyone', 'frequency' => '5', 'close' => 'x', 'work_hours_only' => 'no'],
  '99' => ['scope' => 'cargo'],
], ['12', '13']);
$check(array_map('strval', array_keys($clean)) === ['12', '13'], 'unknown cargos are dropped');
$check($clean['12'] === ['scope' => 'cargo', 'frequency' => 'daily', 'close' => 'snooze', 'work_hours_only' => true, 'reminder_scope' => 'cargo'], 'valid options are kept');
$check($clean['13']['scope'] === 'assigned' && $clean['13']['frequency'] === '60' && $clean['13']['close'] === 'confirm' && $clean['13']['work_hours_only'] === false, 'invalid options fall back to defaults');

// Horario general.
$general = Settings::sanitizeGeneral(['work_start' => '07:30', 'work_end' => '17:00', 'work_days' => [5, 1, 9, '3'], 'reminder_hour' => 30, 'reminder_days' => 'x']);
$check($general['work_start'] === '07:30' && $general['work_end'] === '17:00', 'work hours are kept');
$check($general['work_days'] === [1, 3, 5], 'work days are cleaned and sorted');
$check($general['reminder_hour'] === 20, 'reminder hour is clamped');
$check($general['reminder_days'] === Settings::DEFAULT_GENERAL['reminder_days'], 'invalid reminder days fall back to defaults');
$check(Settings::sanitizeGeneral(['work_start' => '18:00', 'work_end' => '08:00'])['work_start'] === '08:00', 'an inverted work schedule falls back to defaults');

// Recordatorio diario: por defecto 9:00 a. m., lunes a sábado, sin festivos, una vez al día.
$default = Settings::sanitizeGeneral([]);
$check(!Settings::reminderShouldRun($at('2026-11-06 08:59:00'), '', $default), 'it does not run before 9:00');
$check(Settings::reminderShouldRun($at('2026-11-06 09:00:00'), '', $default), 'it runs at 9:00 on a weekday');
$check(Settings::reminderShouldRun($at('2026-11-07 09:00:00'), '', $default), 'it runs on Saturday');
$check(!Settings::reminderShouldRun($at('2026-11-08 09:00:00'), '', $default), 'it does not run on Sunday');
$check(!Settings::reminderShouldRun($at('2026-12-25 09:00:00'), '', $default), 'it does not run on a national holiday');
$check(!Settings::reminderShouldRun($at('2026-11-16 09:00:00'), '', $default), 'it does not run on a moved holiday');
$check(!Settings::reminderShouldRun($at('2026-11-06 12:00:00'), '', $default), 'it does not run three hours after the chosen hour');
$check(!Settings::reminderShouldRun($at('2026-11-06 10:00:00'), '2026-11-06', $default), 'it runs once a day');
$custom = Settings::sanitizeGeneral(['reminder_hour' => 7, 'reminder_days' => [1, 2, 3, 4, 5]]);
$check(Settings::reminderShouldRun($at('2026-11-06 07:05:00'), '', $custom), 'a custom hour is honoured');
$check(!Settings::reminderShouldRun($at('2026-11-07 07:05:00'), '', $custom), 'days left out are skipped');

// Alcance de las listas.
$items = [
  ['titulo' => 'Mio', 'responsable_actual' => '55', 'sin_responsable' => false],
  ['titulo' => 'Ajeno', 'responsable_actual' => '77', 'sin_responsable' => false],
  ['titulo' => 'Credito', 'responsable_actual' => '', 'sin_responsable' => true],
];
$titles = static fn(array $rows): array => array_column($rows, 'titulo');
$check($titles(Settings::itemsForScope($items, 'assigned', '55')) === ['Mio', 'Credito'], 'assigned scope keeps own items and unowned ones');
$check($titles(Settings::itemsForScope($items, 'cargo', '55')) === ['Mio', 'Ajeno', 'Credito'], 'cargo scope keeps everything');
$check($titles(Settings::itemsForScope($items, 'assigned', '')) === ['Credito'], 'someone without employee id only gets unowned items');

// Contratos por desocupar: sin nada por recibir no hay popup.
$today = $at('2026-11-10 00:00:00');
$case = static fn(bool $open): array => ['contrato' => '10', 'caso' => '5', 'inmueble' => '', 'direccion' => '', 'arrendatario' => '', 'fin' => '2026-11-20', 'abierto' => $open, 'estado' => $open ? 'Por recibir' : 'Cerrado'];
$check(ContractReceiptCases::alertGroups([$case(false)], $today) === [], 'move-out popup stays quiet when everything is already closed');
$check(count(ContractReceiptCases::alertGroups([$case(true), $case(false)], $today)) === 2, 'closed cases still accompany pending ones');

echo "{$checks} popup settings checks passed.\n";
