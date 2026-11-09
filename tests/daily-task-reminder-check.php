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
  public function labels(): array { return $this->adminDueTypeLabels(); }
  public function configured(string $setting, string $cargo, array $types): array { return $this->adminDueTypesConfiguredFor($setting, $cargo, $types); }
  public function sanitize($raw): array { return $this->sanitizeAdminDueTypeMap($raw); }
  public function items(array $types): array { return array_column($this->adminDueCalendarItems([], 0, 1, $types), 'tipo_vencimiento'); }
  private function getDashboardCargoOptions(): array { return [['id' => '3'], ['id' => '13']]; }
  private function adminDueQuoteItems(array $s, int $f, int $t): array { return [['tipo_vencimiento' => 'cotizacion_sin_enviar'], ['tipo_vencimiento' => 'cotizacion_enviada_sin_respuesta']]; }
  private function adminDuePreventivaItems(array $s, int $f, int $t): array { return [['tipo_vencimiento' => 'preventiva_sin_enviar']]; }
  private function adminDuePreventivaTicketItems(array $s, int $f, int $t): array { return [['tipo_vencimiento' => 'ticket_preventiva_sin_cita'], ['tipo_vencimiento' => 'preventiva_cita_sin_realizar']]; }
  private function adminDueContractRequestItems(array $s, int $f, int $t): array { return [['tipo_vencimiento' => 'terminacion_contrato_pendiente'], ['tipo_vencimiento' => 'no_prorroga_contrato_pendiente']]; }
  private function adminDueMaintenanceOrderItems(array $s, int $f, int $t): array { return []; }
  private function adminDueLeaseAnnexItems(array $s, int $f, int $t): array { return []; }
  private function adminDueLegalCaseItems(int $f, int $t): array { return [['tipo_vencimiento' => 'caso_juridico_fecha_limite']]; }
  private function adminDueOwnerCreditItems(array $s, int $f, int $t): array { return [['tipo_vencimiento' => 'credito_solicitud_pendiente'], ['tipo_vencimiento' => 'credito_contabilidad_pendiente']]; }
}
$probe = new DueTypesProbe();

// Vencimientos por cargo: un cargo sin guardar conserva todo; lo guardado manda, también si quedó vacío.
$settings = (new ReflectionClass(\SCM\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\SCM\Core\App::class, 'settings'))->setValue(null, $settings);
(new ReflectionProperty(\SCM\Core\Settings::class, 'data'))->setValue($settings, ['due' => ['3' => ['cotizacion_sin_enviar'], '4' => []]]);
$both = ['cotizacion_sin_enviar', 'preventiva_sin_enviar'];
$check($probe->configured('due', '13', $both) === $both, 'a cargo never saved keeps the types of its tabs');
$check($probe->configured('due', '3', $both) === ['cotizacion_sin_enviar'], 'a saved cargo only keeps what is checked');
$check($probe->configured('due', '4', $both) === [], 'a saved cargo with nothing checked sees nothing');
$check($probe->configured('due', '3', ['preventiva_sin_enviar']) === [], 'checking a type does not grant it without its tab');
$check($probe->sanitize(['3' => ['cotizacion_sin_enviar', 'inventado'], '99' => ['cotizacion_sin_enviar'], '13' => []]) === ['3' => ['cotizacion_sin_enviar'], '13' => []], 'saving drops unknown cargos and types and keeps empty lists');
$check(array_keys($probe->labels()) !== [] && array_diff($probe->all(), array_keys($probe->labels())) === [] && array_diff(array_keys($probe->labels()), $probe->all()) === [], 'every due type has a label in the permissions modal');

// Cada tipo se puede desmarcar por separado, aunque salga de la misma consulta que otro.
$check($probe->items(['cotizacion_enviada_sin_respuesta']) === ['cotizacion_enviada_sin_respuesta'], 'a quote type works without its sibling');
$check($probe->items(['preventiva_cita_sin_realizar']) === ['preventiva_cita_sin_realizar'], 'a preventive type works without its siblings');
$check($probe->items(['no_prorroga_contrato_pendiente']) === ['no_prorroga_contrato_pendiente'], 'a contract type works without its sibling');
$check($probe->items(['credito_contabilidad_pendiente']) === ['credito_contabilidad_pendiente'], 'a credit type works without its sibling');
$check($probe->items([]) === [], 'no types means no tasks');
$check($probe->types(['credito_propietario'], false) === ['credito_solicitud_pendiente', 'credito_contabilidad_pendiente'], 'a credits cargo only sees the credit types');
$check($probe->types([], false) === [], 'a cargo without those tabs sees no due types');
$check($probe->types([], true) === ['terminacion_contrato_pendiente', 'no_prorroga_contrato_pendiente'], 'who responds cases keeps the contract requests');
$check(count($probe->types(['contractual'], true)) === 2, 'the contract types are not duplicated');
$dashboard = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/RendersDashboard.php');
preg_match_all('/<\?php if \(in_array\(\'([a-z_]+)\', \$dueAllowedTypes, true\)\): \?><label class="scm-calendar-due-chip/', $dashboard, $chips);
$check(count($chips[1]) === 13 && array_diff($probe->all(), $chips[1]) === [] && array_diff($chips[1], $probe->all()) === [], 'every calendar filter depends on the permission map');

$template = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/whatsapp-recordatorio-tareas-template.json'), true);
$check($template['name'] === DailyTaskReminder::TEMPLATE && substr_count($template['components'][0]['text'], '{{') === 3, 'the template has the three body variables the panel sends');

if ($failed > 0) exit(1);
echo "Daily task reminder checks passed.\n";
