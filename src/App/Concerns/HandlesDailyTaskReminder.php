<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\App;
use SCM\Support\DailyTaskReminder;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\FuncionarioOptions;
use SCM\Support\SmsQueue;

/**
 * Recordatorio diario por cargo (Permisos por cargo › Alertas): correo y WhatsApp con las tareas del calendario de
 * vencimientos de cada cargo que ya vencieron o vencen hoy. Lo ejecutan bin/send-daily-task-reminder.php y el worker.
 */
trait HandlesDailyTaskReminder
{
  /** @return array{sent:bool,email:int,whatsapp:int,skipped_empty:int} */
  public function processDailyTaskReminder(?\DateTimeImmutable $now = null): array
  {
    $result = ['sent' => false, 'email' => 0, 'whatsapp' => 0, 'skipped_empty' => 0];
    $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('America/Bogota'));
    $settings = App::settings();
    if (!DailyTaskReminder::shouldRun($now, (string) $settings->get(DailyTaskReminder::LAST_DATE_SETTING, ''))) return $result;
    // Se reserva el día antes de encolar: si dos procesos coinciden, o uno falla a medias, nadie recibe el mensaje dos veces.
    $today = $now->format('Y-m-d');
    $settings->set(DailyTaskReminder::LAST_DATE_SETTING, $today, 0);
    $result['sent'] = true;

    $channels = [];
    foreach ($this->dashboardAlertPermissions() as $cargo => $keys) {
      $wanted = array_intersect($keys, [self::ALERT_REMINDER_EMAIL, self::ALERT_REMINDER_WHATSAPP]);
      if ($wanted !== []) $channels[(string) $cargo] = $wanted;
    }
    if ($channels === []) return $result;

    $fromTs = strtotime($today . ' 00:00:00') ?: time();
    // Lo que vence hoy y lo vencido, que el calendario acumula en el día de hoy; cada cargo ve solo sus tipos.
    $items = $this->adminDueCalendarItems($this->adminDueCalendarSettings(), $fromTs, $fromTs + 86399, $this->adminDueAllTypes());
    $base = parse_url(rtrim(defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : '', '/') . '/index.php?tab=due');
    $suffix = ltrim((string) ($base['path'] ?? ''), '/') . (isset($base['query']) ? '?' . $base['query'] : '');
    $url = rtrim(defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : '', '/') . '/index.php?tab=due';
    $emails = new EmailQueue($this->db);
    $sms = new SmsQueue($this->db);

    foreach (FuncionarioOptions::activeFuncionarios($this->db, null, 'employee', true) as $person) {
      $cargo = trim((string) ($person['id_cargo'] ?? ''));
      $personChannels = $channels[$cargo] ?? [];
      if ($personChannels === []) continue;
      $types = $this->adminDueAllowedTypes(fn(string $tab): bool => in_array($tab, $this->dashboardTabsForCargo($cargo), true), in_array('case_respond', $this->dashboardActionsForCargo($cargo), true));
      $types = $this->adminDueTypesConfiguredFor(self::ADMIN_DUE_REMINDER_SETTING, $cargo, $this->adminDueTypesConfiguredFor(self::ADMIN_DUE_TYPE_SETTING, $cargo, $types));
      $groups = DailyTaskReminder::groups(array_values(array_filter($items, static fn(array $item): bool => in_array((string) $item['tipo_vencimiento'], $types, true))));
      if ($groups === []) { $result['skipped_empty']++; continue; }
      $name = (string) $person['name'];
      $total = array_sum(array_map('count', $groups));
      $key = 'daily-task-reminder:' . $today . ':' . ($person['employee_id'] ?: $person['id']);
      try {
        if (in_array(self::ALERT_REMINDER_EMAIL, $personChannels, true) && filter_var($person['email'], FILTER_VALIDATE_EMAIL)) {
          $title = 'Tus tareas pendientes de hoy';
          $result['email'] += $emails->enqueue([$person['email']], $title . ' (' . $total . ')', EmailTemplate::render($title, DailyTaskReminder::emailBody($name, $groups), ['buttons' => [['url' => $url, 'label' => 'Ver mis tareas']]]), [
            'source_module' => 'recordatorio_tareas', 'destination_name' => $name, 'dedupe_key' => $key . ':email', 'meta' => ['event' => 'recordatorio_tareas', 'date' => $today],
          ]);
        }
        if (in_array(self::ALERT_REMINDER_WHATSAPP, $personChannels, true) && trim((string) $person['phone']) !== '') {
          $summary = DailyTaskReminder::summary($groups);
          $body = [$name, (string) $total, $summary];
          $ok = $sms->enqueue((string) $person['phone'], $name, 'Hoy tienes ' . $total . ' tarea(s) pendiente(s): ' . $summary . '.', [
            'source_module' => 'recordatorio_tareas', 'dedupe_key' => $key . ':whatsapp', 'template_name' => DailyTaskReminder::TEMPLATE, 'template_language' => 'es_CO',
            'template_components' => [
              ['type' => 'body', 'parameters' => array_map(static fn(string $value): array => ['type' => 'text', 'text' => $value], $body)],
              ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
            ],
          ]);
          $result['whatsapp'] += $ok ? 1 : 0;
        }
      } catch (\Throwable $error) {
        error_log('[daily-task-reminder] ' . $key . ': ' . $error->getMessage());
      }
    }
    return $result;
  }
}
