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

  /** Primera fecha que sigue al día: los días hábiles solo aumentan hacia atrás en el tiempo. */
  private static function cutoff(int $limit, int $now): string
  {
    $older = $now - (($limit + 1) * 86400 * 3);
    $newer = $now;
    while ($newer - $older > 1) {
      $middle = intdiv($older + $newer, 2);
      if (\SkcColombiaBusinessCalendar::elapsedDays($middle, $now) <= $limit) {
        $newer = $middle;
      } else {
        $older = $middle;
      }
    }
    return (new \DateTimeImmutable('@' . $newer))
      ->setTimezone(new \DateTimeZone(\SkcColombiaBusinessCalendar::TIMEZONE))
      ->format('Y-m-d H:i:s');
  }

  /**
   * Condición SQL del filtro Al día / Atrasado. Solo incluye casos de mantenimiento y preventivos.
   *
   * @param string $prefix alias de la tabla con punto, o vacío
   * @param string $placeholder '%s' o '?', según la consulta
   * @return array{0:string,1:array<int,string>}|null
   */
  public static function filterSql(string $status, string $prefix, string $placeholder, ?array $limits = null, ?int $now = null): ?array
  {
    if (!in_array($status, ['al_dia', 'atrasado'], true)) {
      return null;
    }
    $limits ??= self::limits();
    $now ??= time();
    $topic = "LOWER(TRIM(COALESCE({$prefix}tema_ayuda, '')))";
    $base = "COALESCE(NULLIF({$prefix}cct_modified, '0000-00-00 00:00:00'), {$prefix}cct_created)";
    $operator = $status === 'al_dia' ? '>=' : '<';
    $topics = TicketsRepository::MAINTENANCE_TOPICS;
    $marks = implode(', ', array_fill(0, count($topics), $placeholder));
    $sql = "(({$topic} IN ({$marks}) AND {$base} {$operator} {$placeholder})"
      . " OR (({$topic} LIKE {$placeholder} OR {$topic} LIKE {$placeholder}) AND {$base} {$operator} {$placeholder}))";
    $args = array_merge($topics, [
      self::cutoff($limits['mantenimiento'], $now),
      'revision preventiva%',
      'revisión preventiva%',
      self::cutoff($limits['preventiva'], $now),
    ]);
    return [$sql, $args];
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
