<?php

declare(strict_types=1);

namespace SCM\Support;

require_once __DIR__ . '/ColombiaBusinessCalendar.php';

/**
 * Reglas del recordatorio diario de tareas: cuándo sale (días hábiles desde las 9:00 a. m. de Colombia, una vez al día)
 * y cómo se resume lo pendiente. El envío lo hace HandlesDailyTaskReminder por las colas compartidas.
 */
final class DailyTaskReminder
{
  public const HOUR = 9;
  /** Después de esta hora ya no sale: si el cron estuvo caído, no llega un recordatorio de las 9:00 en la tarde. */
  public const LAST_HOUR = 12;
  public const LAST_DATE_SETTING = 'daily_task_reminder_last_date';
  public const TEMPLATE = 'scm_recordatorio_tareas_v1';

  public static function shouldRun(\DateTimeImmutable $now, string $lastDate): bool
  {
    $local = $now->setTimezone(new \DateTimeZone('America/Bogota'));
    $hour = (int) $local->format('G');
    $date = $local->format('Y-m-d');
    return $date !== $lastDate && $hour >= self::HOUR && $hour < self::LAST_HOUR && \SkcColombiaBusinessCalendar::isBusinessDay($date);
  }

  /**
   * Tareas agrupadas por tipo, con las más atrasadas primero.
   * @param list<array<string,mixed>> $items eventos de adminDueEvent()
   * @return array<string,list<array<string,mixed>>> nombre del grupo => tareas
   */
  public static function groups(array $items): array
  {
    usort($items, static fn(array $a, array $b): int => (int) ($b['dias_vencido'] ?? 0) <=> (int) ($a['dias_vencido'] ?? 0));
    $groups = [];
    foreach ($items as $item) {
      $label = trim((string) ($item['grupo'] ?? '')) ?: 'Otras tareas';
      $groups[$label][] = $item;
    }
    return $groups;
  }

  /** Una sola línea, porque las variables de WhatsApp no admiten saltos: "2 Solicitudes de crédito; 1 Cotizaciones sin enviar". */
  public static function summary(array $groups): string
  {
    $parts = [];
    foreach ($groups as $label => $items) $parts[] = count($items) . ' ' . $label;
    $text = implode('; ', $parts);
    return mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 297)) . '...' : $text;
  }

  /** @param array<string,list<array<string,mixed>>> $groups */
  public static function emailBody(string $name, array $groups): string
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $total = array_sum(array_map('count', $groups));
    $html = '<p style="margin:0 0 14px;font-weight:600;">Hola ' . $e($name) . ':</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;">Hoy tienes <b>' . $total . '</b> tarea' . ($total === 1 ? '' : 's') . ' pendiente' . ($total === 1 ? '' : 's') . ' vencida' . ($total === 1 ? '' : 's') . ' o por vencer:</p>';
    foreach ($groups as $label => $items) {
      $html .= '<p style="margin:0 0 6px;font-weight:700;">' . $e($label) . ' (' . count($items) . ')</p><ul style="margin:0 0 14px;padding-left:18px;line-height:1.6;">';
      foreach (array_slice($items, 0, 10) as $item) {
        $late = (int) ($item['dias_vencido'] ?? 0);
        $html .= '<li>' . $e($item['titulo'] ?? 'Tarea') . ($late > 0 ? ' · ' . $late . ' día' . ($late === 1 ? '' : 's') . ' de atraso' : ' · vence hoy') . '</li>';
      }
      if (count($items) > 10) $html .= '<li>y ' . (count($items) - 10) . ' más en el calendario.</li>';
      $html .= '</ul>';
    }
    return $html . '<p style="margin:0;line-height:1.65;">Son las mismas tareas de tu calendario de vencimientos en el panel.</p>';
  }
}
