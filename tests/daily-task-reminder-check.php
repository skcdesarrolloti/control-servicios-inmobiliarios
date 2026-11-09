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
  public bool $seeAll = false;
  public function calendarTab(): array { return $this->adminDueTypesForCalendarTab(); }
  public function summaryTypes(array $types, bool $ignoreTabs = false): array { return $this->adminDueSummaryTypes($types, $ignoreTabs); }
  private function canAccessDashboardTab(string $tab): bool { return false; }
  public function themeLabels(): array { return $this->adminDueTypeLabelsByTheme(); }
  public function themeGroups(array $keys, array $items, string $employee, bool $canOpenTab): array { return $this->adminDueThemeGroups(array_intersect_key(self::ADMIN_DUE_THEME_POPUPS, array_flip($keys)), $items, $employee, $canOpenTab); }
  private function adminDueCanSeeAllTypes(): bool { return $this->seeAll; }
  private function adminDueTypesForCurrentCargo(): array { return ['cotizacion_sin_enviar']; }
  public function assigned(array $items, string $employee): array { return array_column($this->adminDueMarkAssigned($items, $employee), 'asignado'); }
  private function adminDueTicketOwners(array $refs): array { return array_intersect_key(['10' => '77', '11' => '88', '12' => ''], array_flip($refs)); }
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

// Popups por tema: cada tipo en un solo tema; lo futuro no sale y la lista es de quien entra, con el total del cargo.
$themed = array_merge(...array_values(array_map('array_keys', $probe->themeLabels())));
$check(count($themed) === count(array_unique($themed)) && array_diff(array_keys($probe->labels()), $themed) === [], 'every due type belongs to exactly one theme');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$themeItems = [
  ['tipo_vencimiento' => 'cotizacion_sin_enviar', 'titulo' => 'Mía', 'fecha_vencimiento' => '2026-01-05', 'dias_vencido' => 9, 'responsable_id' => '77'],
  ['tipo_vencimiento' => 'cotizacion_sin_enviar', 'titulo' => 'Ajena', 'fecha_vencimiento' => '2026-01-06', 'dias_vencido' => 8, 'responsable_id' => '88'],
  ['tipo_vencimiento' => 'cotizacion_sin_enviar', 'titulo' => 'Futura', 'fecha_vencimiento' => $tomorrow, 'dias_vencido' => 0, 'responsable_id' => '77'],
  ['tipo_vencimiento' => 'servicios_publicos_pendientes', 'titulo' => 'Revisión', 'fecha_vencimiento' => $tomorrow, 'dias_vencido' => 0, 'cuenta_pendiente' => 1],
  ['tipo_vencimiento' => 'preventiva_sin_enviar', 'titulo' => 'Otro tema', 'fecha_vencimiento' => '2026-01-05', 'dias_vencido' => 9, 'responsable_id' => '77'],
];
$themeGroups = $probe->themeGroups(['tema_mantenimiento', 'tema_servicios_publicos'], $themeItems, '77', true);
$check(array_column($themeGroups, 'popup') === ['Servicios públicos', 'Mantenimiento'], 'only the active themes come back, each as its own popup');
$check($themeGroups[1]['count'] === 2 && array_column($themeGroups[1]['rows'], 'title') === ['Mía'], 'the count is the cargo total and the list only the assigned tasks, without future ones');
$check($themeGroups[1]['rows'][0]['badge'] === '9 días vencido' && $themeGroups[1]['popup_action']['tab'] === 'vencimientos', 'a row says how late it is and the popup opens the due tab');
$check($themeGroups[0]['count'] === 1 && $themeGroups[0]['rows'] === [], 'a pending public service counts even if nobody owns it');
$check($probe->themeGroups(['tema_mantenimiento'], $themeItems, '77', false)[0]['popup_action'] === null, 'without the due tab the popup has no button');

// Ver todos solo amplía la pestaña Vencimientos; el popup pide lo suyo y la casilla no enciende popups.
$check($probe->summaryTypes(['preventiva_sin_enviar', 'servicios_publicos_critico'], true) === ['preventiva_pendiente', 'servicios_publicos_pendientes'], 'the summary groups follow the types they belong to');
$check($probe->summaryTypes(['cotizacion_sin_enviar'], true) === [], 'a cargo without those types does not list the summary groups');
$check($probe->summaryTypes(['preventiva_sin_enviar', 'servicios_publicos_critico']) === [], 'without see-all the summary groups still need their tab');
$check($probe->calendarTab() === ['cotizacion_sin_enviar'], 'without the permission the tab shows what the cargo attends');
$probe->seeAll = true;
$check($probe->calendarTab() === $probe->all(), 'with the permission the tab shows every type');
$app = (string) file_get_contents(dirname(__DIR__) . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$workflow = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php');
$runtime = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-dashboard-runtime.js');
$check(str_contains($app, 'self::ALERT_REMINDER_WHATSAPP, self::ALERT_DUE_VIEW_ALL]') , 'the see-all box does not switch on the alerts popup');
$check(str_contains($workflow, "\$forPopup ? \$this->adminDueTypesForCurrentCargo() : \$this->adminDueTypesForCalendarTab()") && substr_count($runtime, 'popup: "1"') === 1, 'only the popup and its counter ask for the marked types');

// Lista del popup: el caso manda; sin caso o sin funcionario en el caso, su responsable directo.
$assignedItems = [
  ['case' => ['ticket_pk' => '10']],
  ['case' => ['ticket_pk' => '11'], 'responsable_id' => '77'],
  ['case' => [], 'ticket_ref' => '10'],
  ['case' => ['ticket_pk' => '12', 'empleado_id' => '77']],
  ['case' => [], 'responsable_id' => '77'],
  ['case' => [], 'responsable_id' => ''],
];
$check($probe->assigned($assignedItems, '77') === [true, false, true, true, true, false], 'only the cases of the person are marked as assigned');
$check($probe->assigned([['case' => ['ticket_pk' => '12']], ['case' => [], 'responsable_id' => '']], '') === [false, false], 'a session without employee id owns nothing, even unassigned cases');

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
