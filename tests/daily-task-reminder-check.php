<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';

use SCM\Support\DailyTaskReminder;

$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
  echo ($ok ? 'OK: ' : 'FAIL: ') . $message . PHP_EOL;
  if (!$ok) $failed++;
};
$at = static fn(string $local): DateTimeImmutable => new DateTimeImmutable($local, new DateTimeZone('America/Bogota'));

// Lunes a sábado desde las 9:00 de Colombia, sin festivos, una vez al día y no en la tarde.
$check(!DailyTaskReminder::shouldRun($at('2026-11-06 08:59:00'), ''), 'it does not run before 9:00');
$check(DailyTaskReminder::shouldRun($at('2026-11-06 09:00:00'), ''), 'it runs at 9:00 on a weekday');
$check(DailyTaskReminder::shouldRun($at('2026-11-07 09:00:00'), ''), 'it runs on Saturday');
$check(!DailyTaskReminder::shouldRun($at('2026-11-08 09:00:00'), ''), 'it does not run on Sunday');
$check(!DailyTaskReminder::shouldRun($at('2026-12-25 09:00:00'), ''), 'it does not run on a national holiday');
$check(!DailyTaskReminder::shouldRun($at('2026-11-16 09:00:00'), ''), 'it does not run on a moved holiday');
$check(!DailyTaskReminder::shouldRun($at('2026-11-06 09:15:00'), '2026-11-06'), 'it runs once a day');
$check(DailyTaskReminder::shouldRun($at('2026-11-06 11:59:00'), '2026-11-05'), 'a late cron still sends in the morning');
$check(!DailyTaskReminder::shouldRun($at('2026-11-06 12:00:00'), ''), 'it does not send the morning reminder at noon');
$check(DailyTaskReminder::shouldRun(new DateTimeImmutable('2026-11-06 14:00:00', new DateTimeZone('UTC')), ''), 'the hour is Colombia time even if the server runs in UTC');

$items = [
  ['grupo' => 'Solicitudes de crédito', 'titulo' => 'Crédito #1', 'dias_vencido' => 1],
  ['grupo' => 'Cotizaciones sin enviar', 'titulo' => 'Cotización #9', 'dias_vencido' => 0],
  ['grupo' => 'Solicitudes de crédito', 'titulo' => 'Crédito #2', 'dias_vencido' => 5],
];
$groups = DailyTaskReminder::groups($items);
$check($groups['Solicitudes de crédito'][0]['titulo'] === 'Crédito #2', 'the most overdue task goes first');
$summary = DailyTaskReminder::summary($groups);
$check(str_contains($summary, '2 Solicitudes de crédito') && str_contains($summary, '1 Cotizaciones sin enviar') && !str_contains($summary, "\n"), 'the WhatsApp summary is one line, as templates require');
$check(mb_strlen(DailyTaskReminder::summary(['Tarea ' . str_repeat('x', 400) => [[]]])) <= 300, 'the summary fits the template');
$body = DailyTaskReminder::emailBody('Ana <b>', $groups);
$check(str_contains($body, 'Ana &lt;b&gt;') && str_contains($body, '<b>3</b> tareas') && str_contains($body, '5 días de atraso') && str_contains($body, 'vence hoy'), 'the email escapes names and lists the tasks');

// Los filtros del calendario y el recordatorio salen de los permisos del cargo.
final class DueTypesProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public function types(array $tabs, bool $respond): array
  {
    return $this->adminDueAllowedTypes(static fn(string $tab): bool => in_array($tab, $tabs, true), $respond);
  }
  public function all(): array { return $this->adminDueAllTypes(); }
}
$probe = new DueTypesProbe();
$check($probe->types(['credito_propietario'], false) === ['credito_solicitud_pendiente', 'credito_contabilidad_pendiente'], 'a credits cargo only sees the credit types');
$check($probe->types([], false) === [], 'a cargo without those tabs sees no due types');
$check($probe->types([], true) === ['terminacion_contrato_pendiente', 'no_prorroga_contrato_pendiente'], 'who responds cases keeps the contract requests');
$check(count($probe->types(['contractual'], true)) === 2, 'the contract types are not duplicated');
$dashboard = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/RendersDashboard.php');
preg_match_all('/<\?php if \(in_array\(\'([a-z_]+)\', \$dueAllowedTypes, true\)\): \?><label class="scm-calendar-due-chip/', $dashboard, $chips);
$check(count($chips[1]) === 12 && array_diff($probe->all(), $chips[1]) === [] && array_diff($chips[1], $probe->all()) === [], 'every calendar filter depends on the permission map');

$template = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-recordatorio-tareas-template.json'), true);
$check($template['name'] === DailyTaskReminder::TEMPLATE && substr_count($template['components'][0]['text'], '{{') === 3, 'the template has the three body variables the panel sends');

if ($failed > 0) exit(1);
echo "Daily task reminder checks passed.\n";
