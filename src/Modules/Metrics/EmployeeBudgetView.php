<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use SCM\Modules\Metrics\MaintenanceMetricsView as V;
use SCM\Modules\Pending\PublicServicesUi as UI;

/** Métricas › Mis presupuestos y ejecuciones (Tailwind sp-). */
final class EmployeeBudgetView
{
  public const REPORT = 'mis_presupuestos';
  private const MONTHS = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

  public function shell(int $year, int $month): string
  {
    ob_start();
?>
    <div class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-maint-metrics="<?= V::e(self::REPORT) ?>">
      <header class="<?= UI::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
        <div>
          <h2 class="!sp-m-0 !sp-text-lg !sp-font-bold">Mis presupuestos y ejecuciones</h2>
          <p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Cómo vas frente a tus metas, mes a mes y en lo que va del año.</p>
        </div>
        <button type="button" class="<?= UI::SECONDARY ?>" data-maint-metrics-refresh><?= UI::icon('refresh') ?>Actualizar</button>
      </header>
      <form class="<?= UI::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-end !sp-gap-4" data-maint-metrics-form>
        <label><span class="<?= UI::LABEL ?>">Año</span><select class="<?= UI::INPUT ?> !sp-w-32" name="year"><?php for ($y = $year; $y >= $year - 1; $y--): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?></select></label>
        <label><span class="<?= UI::LABEL ?>">Mes</span><select class="<?= UI::INPUT ?> !sp-w-44" name="month"><?php foreach (self::MONTHS as $number => $label): ?><option value="<?= $number ?>"<?= $number === $month ? ' selected' : '' ?>><?= V::e($label) ?></option><?php endforeach; ?></select></label>
      </form>
      <div data-maint-metrics-content aria-live="polite"><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Abre esta métrica para cargar la información.</p></div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array{year:int,month:int,activities:array<int,array<string,mixed>>} $report */
  public function render(array $report): string
  {
    $activities = $report['activities'];
    $monthName = self::MONTHS[$report['month']];
    if ($activities === []) {
      return '<div class="' . UI::CARD . ' !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">No tienes presupuestos cargados para ' . $report['year'] . '.</div>';
    }

    $missing = count(array_filter($activities, static fn(array $item): bool => !$item['available']));
    $reached = static fn(string $period): int => count(array_filter(
      $activities,
      static fn(array $item): bool => $item['available'] && ($item[$period]['pct'] ?? 0) >= 100
    ));
    $total = count($activities);

    $summary = [];
    $history = [];
    foreach ($activities as $item) {
      $name = '<strong>' . V::e($item['name']) . '</strong>' . ($item['is_money'] ? '<small class="!sp-block !sp-text-service-muted">En valor</small>' : '');
      $summary[] = [
        $name,
        self::amount($item['month']['target'], $item),
        self::amount($item['month']['done'], $item, true),
        self::percentage($item['month']['pct'], $item),
        self::amount($item['accumulated']['target'], $item),
        self::amount($item['accumulated']['done'], $item, true),
        self::percentage($item['accumulated']['pct'], $item) . self::meter($item),
      ];
      $history[] = array_merge([$name], array_map(
        static fn(array $figures): string => self::percentage($figures['pct'], $item),
        array_values($item['months'])
      ));
    }

    $head = ['Actividad'];
    for ($m = 1; $m <= $report['month']; $m++) $head[] = mb_substr(self::MONTHS[$m], 0, 3, 'UTF-8');

    $html = '<div class="!sp-space-y-5">';
    $html .= '<div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">';
    $html .= V::card('Actividades con meta', (string) $total, (string) $report['year'], 'Actividades con presupuesto este año', 'document');
    $html .= V::card('Metas cumplidas en el mes', $reached('month') . ' de ' . $total, $monthName, 'Llegaron al 100 % de la meta del mes', 'check', '!sp-text-emerald-700');
    $html .= V::card('Metas cumplidas acumuladas', $reached('accumulated') . ' de ' . $total, 'Acumulado', 'Llegaron al 100 % de enero a ' . mb_strtolower($monthName, 'UTF-8'), 'check', '!sp-text-emerald-700');
    $html .= '</div>';
    $html .= V::table('Cómo vas en ' . mb_strtolower($monthName, 'UTF-8'), 'Meta y ejecución del mes y acumuladas desde enero.' . ($missing > 0 ? ' Sin dato: Reportes no entregó esa ejecución.' : ''), ['Actividad', 'Meta mes', 'Ejecutado mes', '% mes', 'Meta acumulada', 'Ejecutado acumulado', '% acumulado'], $summary);
    $html .= V::table('Mes a mes', 'Porcentaje de cumplimiento de cada mes.', $head, $history);
    return $html . '</div>';
  }

  private static function amount(float $value, array $item, bool $isExecution = false): string
  {
    if ($isExecution && !$item['available']) return '<span class="!sp-text-service-muted">Sin dato</span>';
    return $item['is_money'] ? V::money((int) round($value)) : number_format($value, $value == floor($value) ? 0 : 1, ',', '.');
  }

  private static function percentage(?float $pct, array $item): string
  {
    if ($pct === null || !$item['available']) return '<span class="!sp-text-service-muted">—</span>';
    $tone = $pct >= 100 ? '!sp-text-emerald-700' : ($pct >= 80 ? '!sp-text-amber-600' : '!sp-text-rose-700');
    return '<strong class="' . $tone . '">' . number_format($pct, 1, ',', '.') . ' %</strong>';
  }

  private static function meter(array $item): string
  {
    $pct = $item['accumulated']['pct'];
    if ($pct === null || !$item['available']) return '';
    $tone = $pct >= 100 ? '!sp-bg-emerald-500' : ($pct >= 80 ? '!sp-bg-amber-500' : '!sp-bg-rose-500');
    $width = (int) round(min(100, $pct));
    return '<span class="!sp-block !sp-h-2 !sp-mt-1 !sp-min-w-[140px] !sp-rounded-full !sp-bg-slate-100 !sp-overflow-hidden"><span class="!sp-block !sp-h-full !sp-rounded-full ' . $tone . '" style="width:' . $width . '%"></span></span>';
  }
}
