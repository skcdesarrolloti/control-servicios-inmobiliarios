<?php

declare(strict_types=1);

namespace SCM\Modules\ServiciosInmobiliarios\Concerns;

use SCM\Support\DateFormatter;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimestampParser;
use SCM\Timeline\Definitions\ServiciosInmobiliariosTimelineDefinition;
use SCM\Timeline\TimelineEngine;

trait PaginationAndTimelineConcern
{
  public function renderPagination(array $pagination, int $rowsOnPage): string
  {
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($total <= 0) {
      return '';
    }

    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));

    if ($page > $totalPages) {
      $page = $totalPages;
    }

    $html = '<div class="scm-pagination-card card">';
    $html .= '<div class="scm-pagination-summary">Pagina ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | Total: ' . esc_html((string) $total) . '</div>';
    $html .= '<div class="scm-pagination-controls">';

    $html .= $this->renderPageButton(1, '&laquo;', $page <= 1);
    $html .= $this->renderPageButton($page - 1, '&lsaquo;', $page <= 1);

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
      $html .= $this->renderPageButton($i, (string) $i, false, $page === $i);
    }

    $html .= $this->renderPageButton($page + 1, '&rsaquo;', $page >= $totalPages);
    $html .= $this->renderPageButton($totalPages, '&raquo;', $page >= $totalPages);
    $html .= '</div>';
    $html .= '</div>';

    return $html;
  }

  private function renderTimeline(array $steps, array $stepLinks = []): string
  {
    $html = '<div class="scm-timeline">';

    $last = count($steps) - 1;
    foreach ($steps as $index => $step) {
      $status = (string) ($step['status'] ?? 'none');
      $ts = (int) ($step['ts'] ?? 0);
      $emptyText = trim((string) ($step['empty_text'] ?? 'Sin dato'));

      if ($status === 'done') {
        $dateHtml = $ts > 0
          ? '<small>' . esc_html($this->dateFormatter->formatDateTime($ts)) . '</small>'
          : '<small>' . esc_html($emptyText) . '</small>';
      } elseif ($status === 'pending') {
        $dateHtml = '<small class="scm-tl-pend">Pendiente</small>';
      } else {
        $dateHtml = '<small class="scm-tl-nohecho">' . esc_html($emptyText) . '</small>';
      }

      $subParts = [];
      $sub = trim((string) ($step['sub'] ?? ''));
      if ($sub !== '') {
        $subParts[] = $sub;
      }

      $elapsedLabel = trim((string) ($step['elapsed_label'] ?? ''));
      if ($elapsedLabel !== '') {
        $from = trim((string) ($step['elapsed_from'] ?? ''));
        if ($from !== '') {
          $subParts[] = 'Tardo ' . $elapsedLabel . ' desde ' . $from;
        } else {
          $subParts[] = 'Tardo ' . $elapsedLabel;
        }
      }

      $pendingLabel = trim((string) ($step['pending_label'] ?? ''));
      if ($pendingLabel !== '' && $status === 'pending') {
        $from = trim((string) ($step['pending_from'] ?? ''));
        if ($from !== '') {
          $subParts[] = 'Lleva ' . $pendingLabel . ' sin hacerse desde ' . $from;
        } else {
          $subParts[] = 'Lleva ' . $pendingLabel . ' sin hacerse';
        }
      }

      $subHtml = '';
      foreach ($subParts as $subPart) {
        $subHtml .= '<small class="scm-tl-sub">' . esc_html($subPart) . '</small>';
      }

      $label = trim((string) ($step['label'] ?? ''));
      $icon = trim((string) ($step['icon'] ?? ''));
      $source = trim((string) ($step['source'] ?? ''));
      $stepKey = trim((string) ($step['key'] ?? ''));
      $stepLink = trim((string) ($stepLinks[$stepKey] ?? ''));
      $iconHtml = '<span class="scm-tl-icon">' . esc_html($icon) . '</span>';
      if ($stepLink !== '') {
        $iconHtml = '<a class="scm-tl-icon scm-tl-icon-link" href="' . esc_url($stepLink) . '" target="_blank" rel="noopener noreferrer" title="Abrir registro relacionado">' . esc_html($icon) . '</a>';
      }

      $html .= '<div class="scm-tl-item scm-tl-' . esc_attr($status) . '" data-source="' . esc_attr($source) . '">';
      $html .= $iconHtml;
      $html .= '<span class="scm-tl-label">' . esc_html($label) . $dateHtml . $subHtml . '</span>';
      $html .= '</div>';

      if ($index < $last) {
        $html .= '<div class="scm-tl-line"></div>';
      }
    }

    $html .= '</div>';
    return $html;
  }

  private function renderPageButton(int $page, string $label, bool $disabled, bool $active = false): string
  {
    $classes = ['scm-page-btn', 'btn', 'btn-sm'];
    if ($active) {
      $classes[] = 'btn-primary';
      $classes[] = 'is-active';
    } else {
      $classes[] = 'btn-outline';
    }

    $attrs = ' type="button" class="' . esc_attr(implode(' ', $classes)) . '" data-page="' . esc_attr((string) max(1, $page)) . '"';
    if ($disabled) {
      $attrs .= ' disabled';
    }

    return '<button' . $attrs . '>' . wp_kses_post($label) . '</button>';
  }

  private function prioridadBadge(string $prioridad): string
  {
    $value = strtolower(trim($prioridad));
    $map = [
      'alta' => ['#dc2626', '#fef2f2', '#fecaca'],
      'media' => ['#b45309', '#fffbeb', '#fde68a'],
      'baja' => ['#15803d', '#f0fdf4', '#bbf7d0'],
    ];

    $style = $map[$value] ?? ['#475569', '#f8fafc', '#e2e8f0'];
    $label = $prioridad !== '' ? $prioridad : '-';

    return '<span style="background:' . $style[1] . ';color:' . $style[0] . ';border:1px solid ' . $style[2] . ';border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap;">' . esc_html($label) . '</span>';
  }

  private function slaBadge(string $slaStatus, ?int $daysOpen): string
  {
    $labelDays = $daysOpen !== null ? ' (' . $daysOpen . 'd)' : '';

    if ($slaStatus === 'vencido') {
      return '<span style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">Vencido' . esc_html($labelDays) . '</span>';
    }

    if ($slaStatus === 'en_riesgo') {
      return '<span style="background:#fffbeb;color:#b45309;border:1px solid #fde68a;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">En riesgo' . esc_html($labelDays) . '</span>';
    }

    if ($slaStatus === 'cerrado') {
      return '<span style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">Cerrado' . esc_html($labelDays) . '</span>';
    }

    if ($slaStatus === 'en_tiempo') {
      return '<span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">En tiempo' . esc_html($labelDays) . '</span>';
    }

    if ($daysOpen !== null) {
      return '<span style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">' . esc_html((string) $daysOpen) . 'd</span>';
    }

    return '<span style="color:#94a3b8;">-</span>';
  }

  private function estadoBadge(string $estado): string
  {
    $value = strtolower(trim($estado));
    $map = [
      'nuevo' => 'badge-success',
      'abierto' => 'badge-success',
      'pendiente' => 'badge-warning',
      'en proceso' => 'badge-warning',
      'en_proceso' => 'badge-warning',
      'postergado' => 'badge-info',
      'postergada' => 'badge-info',
      'postergados' => 'badge-info',
      'cerrado' => 'badge-error',
      'cerrada' => 'badge-error',
      'cerrados' => 'badge-error',
      'resuelto' => 'badge-error',
      'finalizado' => 'badge-error',
      'cancelado' => 'badge-neutral',
      'aprobado' => 'badge-accent',
      'rechazado' => 'badge-error',
    ];

    $class = $map[$value] ?? 'badge-ghost';
    $label = $estado !== '' ? $estado : '-';

    return '<span class="badge badge-sm ' . esc_attr($class) . '">'
      . esc_html($label)
      . '</span>';
  }

  private function stageBadge(string $label, string $status, int $stageSeconds, bool $isCompleted): string
  {
    $label = trim($label);
    if ($label === '') {
      $label = '-';
    }

    $statusKey = strtolower(trim($status));
    $class = 'scm-stage-none';

    if ($isCompleted || $statusKey === 'done') {
      $class = 'scm-stage-done';
    } elseif ($statusKey === 'pending') {
      if ($stageSeconds >= 72 * 3600) {
        $class = 'scm-stage-critical';
      } elseif ($stageSeconds >= 24 * 3600) {
        $class = 'scm-stage-risk';
      } else {
        $class = 'scm-stage-pending';
      }
    } elseif ($statusKey === 'none') {
      $class = 'scm-stage-none';
    }

    return '<span class="scm-stage-pill ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
  }

  private function timeBadge(string $label, int $seconds, string $context, bool $isCompleted): string
  {
    $label = trim($label);
    if ($label === '') {
      $label = '-';
    }

    $class = 'scm-time-muted';

    if ($label === '-' || $label === '0s') {
      $class = 'scm-time-muted';
    } elseif (stripos($label, 'completado') !== false) {
      $class = 'scm-time-done';
    } elseif ($context === 'stage') {
      if ($isCompleted) {
        $class = 'scm-time-done';
      } elseif ($seconds >= 72 * 3600) {
        $class = 'scm-time-critical';
      } elseif ($seconds >= 24 * 3600) {
        $class = 'scm-time-risk';
      } else {
        $class = 'scm-time-normal';
      }
    } else {
      if ($isCompleted) {
        $class = 'scm-time-done';
      } elseif ($seconds >= 120 * 3600) {
        $class = 'scm-time-critical';
      } elseif ($seconds >= 72 * 3600) {
        $class = 'scm-time-risk';
      } else {
        $class = 'scm-time-normal';
      }
    }

    return '<span class="scm-time-pill ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
  }

  private function firstIdValue($raw): string
  {
    $ids = $this->splitIds($raw);
    if (!empty($ids)) {
      return trim((string) $ids[0]);
    }

    return trim((string) $raw);
  }

  /** @return array<int,string> */
  private function splitIds($raw): array
  {
    $text = trim((string) $raw);
    if ($text === '') {
      return [];
    }

    $parts = preg_split('/[,\s;|]+/', $text);
    if (!is_array($parts)) {
      return [];
    }

    $ids = [];
    foreach ($parts as $part) {
      $id = trim((string) $part);
      if ($id === '') {
        continue;
      }
      $ids[$id] = true;
    }

    return array_keys($ids);
  }

  private function humanDurationSince(int $fromTs): string
  {
    if ($fromTs <= 0) {
      return '-';
    }

    $diff = time() - $fromTs;
    if ($diff < 0) {
      $diff = 0;
    }

    $days = (int) floor($diff / 86400);
    $hours = (int) floor(($diff % 86400) / 3600);
    $mins = (int) floor(($diff % 3600) / 60);

    if ($days > 0) {
      return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
      return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
  }
}
