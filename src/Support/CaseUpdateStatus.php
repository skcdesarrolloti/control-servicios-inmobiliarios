<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\App;
use SCM\Core\Settings;
use SCM\Repositories\TicketsRepository;

require_once __DIR__ . '/ColombiaBusinessCalendar.php';

/**
 * Días hábiles sin actualizar y estado al día/atrasado de un caso.
 * Debe coincidir con "Casos al día" de Reportes (TicketsSla::limiteCaso): misma fecha
 * base, mismo calendario y límites de Configurar Vencimientos.
 */
final class CaseUpdateStatus
{
  private const DEFAULT_LIMITS = ['mantenimiento' => 7, 'preventiva' => 5];

  /** @var array{mantenimiento:int,preventiva:int}|null */
  private static ?array $limits = null;

  /** @return array{mantenimiento:int,preventiva:int} */
  private static function limits(): array
  {
    if (self::$limits !== null) {
      return self::$limits;
    }
    try {
      $days = (new Settings(App::db(), true))->get('admin_due_calendar_days', []);
    } catch (\Throwable $exception) {
      error_log('[case_update_status] ' . $exception->getMessage());
      $days = [];
    }
    $days = is_array($days) ? $days : [];
    return self::$limits = [
      'mantenimiento' => max(1, (int) ($days['casos_mantenimiento_actualizacion_dias'] ?? self::DEFAULT_LIMITS['mantenimiento'])),
      'preventiva' => max(1, (int) ($days['casos_preventiva_actualizacion_dias'] ?? self::DEFAULT_LIMITS['preventiva'])),
    ];
  }

  private static function limitForTopic(string $topic, array $limits): ?int
  {
    $topic = strtr(mb_strtolower(trim($topic), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    if (in_array($topic, TicketsRepository::MAINTENANCE_TOPICS, true)) {
      return $limits['mantenimiento'];
    }
    return str_starts_with($topic, 'revision preventiva') ? $limits['preventiva'] : null;
  }

  /**
   * @param array<string,mixed> $row fila del caso
   * @param array{mantenimiento:int,preventiva:int}|null $limits
   * @return array{label:string,status:string}
   */
  public static function evaluate(array $row, ?array $limits = null, mixed $now = null): array
  {
    $base = \SkcColombiaBusinessCalendar::parse($row['cct_modified'] ?? null)
      ?? \SkcColombiaBusinessCalendar::parse($row['cct_created'] ?? null);
    if ($base === null) {
      return ['label' => '', 'status' => ''];
    }
    $days = \SkcColombiaBusinessCalendar::elapsedDays($base, $now);
    $limit = self::limitForTopic((string) ($row['tema_ayuda'] ?? ''), $limits ?? self::limits());
    return [
      'label' => $days === 1 ? '1 día hábil' : $days . ' días hábiles',
      'status' => $limit === null ? '' : ($days <= $limit ? 'al_dia' : 'atrasado'),
    ];
  }

  /** @param array{label:string,status:string} $status */
  public static function pills(array $status): string
  {
    $html = '';
    if ($status['label'] !== '') {
      $html .= '<span class="scm-timing-badge scm-timing-inactivo" title="Días hábiles desde la última modificación"><span class="material-symbols-outlined text-[13px]">history</span> Sin act: ' . htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($status['status'] === 'al_dia') {
      $html .= '<span class="scm-timing-badge scm-timing-ok"><span class="material-symbols-outlined text-[13px]">verified</span> Al día</span>';
    } elseif ($status['status'] === 'atrasado') {
      $html .= '<span class="scm-timing-badge scm-timing-atrasado"><span class="material-symbols-outlined text-[13px]">schedule</span> Atrasado</span>';
    }
    return $html;
  }
}
