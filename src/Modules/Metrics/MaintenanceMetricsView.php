<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Métricas › Servicios públicos y Métricas › Mantenimiento › Revisión preventiva (Tailwind sp-). */
final class MaintenanceMetricsView
{
  private const PENDING_NOTE = 'El periodo aplica a revisiones, resultados, daños, cotizaciones y casos creados. Pendientes, sin cita y sin enviar muestran la situación actual.';
  /** report => [title, subtitle, icon, period note, template]. */
  public const REPORTS = [
    'servicios' => ['Revisiones de servicios públicos', 'Pendientes, revisiones realizadas por resultado, deuda reportada y casos críticos de 72 horas.', 'drop', self::PENDING_NOTE, 'public-services'],
    'preventiva' => ['Revisión preventiva', 'Pendientes, citas, revisiones realizadas, daños, cotizaciones y administración ganada.', 'check', self::PENDING_NOTE, 'preventive'],
    'crecimiento' => ['Crecimiento de la empresa', 'Aumentos de canon y de administración generados con las cartas de aumento.', 'refresh', 'El periodo aplica a la fecha de las cartas de aumento. La cartera administrada muestra los contratos entregados hoy.', 'growth'],
  ];

  public static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  public static function money(int $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  public static function pct(int $value, int $total): string
  {
    return $total > 0 ? round($value * 100 / $total) . '%' : '0%';
  }

  /** KPI card: [label, value, badge, hint, icon, tone]. */
  public static function card(string $label, string $value, string $badge, string $hint, string $icon, string $tone = '!sp-text-service-navy'): string
  {
    return '<div class="' . UI::CARD . ' !sp-p-4"><div class="!sp-flex !sp-justify-between !sp-items-center"><span class="!sp-text-[10px] !sp-uppercase !sp-font-semibold ' . $tone . '">' . self::e($label) . '</span><span class="!sp-p-2 !sp-rounded-lg !sp-bg-slate-50">' . UI::icon($icon) . '</span></div>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2 !sp-mt-2"><strong class="!sp-text-2xl !sp-font-extrabold ' . $tone . '">' . self::e($value) . '</strong>' . ($badge !== '' ? '<span class="!sp-text-[9px] !sp-rounded !sp-border !sp-border-solid !sp-border-slate-200 !sp-px-1.5 !sp-py-0.5 ' . $tone . '">' . self::e($badge) . '</span>' : '') . '</div>'
      . '<p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">' . self::e($hint) . '</p></div>';
  }

  /** Stacked distribution bar with legend. @param array<int,array{0:string,1:int,2:string}> $parts [label, value, bg class] */
  public static function bar(string $title, array $parts, string $note = ''): string
  {
    $total = array_sum(array_column($parts, 1));
    $html = '<div class="' . UI::CARD . ' !sp-p-5"><div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-gap-2 !sp-mb-3"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">' . self::e($title) . '</h3><span class="!sp-text-[10px] !sp-text-service-muted">' . self::e($note !== '' ? $note : $total . ' en total') . '</span></div>';
    $html .= '<div class="!sp-flex !sp-h-3 !sp-w-full !sp-overflow-hidden !sp-rounded-full !sp-bg-slate-100" role="img" aria-label="' . self::e($title) . '">';
    foreach ($parts as [$label, $value, $bg]) {
      if ($value > 0 && $total > 0) $html .= '<span class="!sp-block !sp-h-full ' . $bg . '" style="width:' . round($value * 100 / $total, 2) . '%" title="' . self::e($label . ': ' . $value) . '"></span>';
    }
    $html .= '</div><ul class="!sp-list-none !sp-m-0 !sp-mt-4 !sp-p-0 !sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-3">';
    foreach ($parts as [$label, $value, $bg]) {
      $html .= '<li class="!sp-flex !sp-items-center !sp-gap-2 !sp-text-[10px]"><span class="!sp-w-2.5 !sp-h-2.5 !sp-rounded-full ' . $bg . '" aria-hidden="true"></span><span class="!sp-text-service-muted">' . self::e($label) . '</span><strong class="!sp-ml-auto">' . (int) $value . ' · ' . self::pct((int) $value, $total) . '</strong></li>';
    }
    return $html . '</ul></div>';
  }

  /** Simple table card. @param array<int,string> $head @param array<int,array<int,string>> $rows already-escaped cells */
  public static function table(string $title, string $subtitle, array $head, array $rows, string $empty = 'Sin datos en el periodo.'): string
  {
    $th = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-4 !sp-py-3 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
    $html = '<div class="' . UI::CARD . '"><div class="!sp-p-4"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">' . self::e($title) . '</h3>' . ($subtitle !== '' ? '<p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">' . self::e($subtitle) . '</p>' : '') . '</div>';
    $html .= '<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy" data-maint-metrics-table><thead class="!sp-bg-slate-50"><tr>';
    foreach ($head as $label) $html .= '<th class="' . $th . '">' . self::e($label) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $html .= '<tr class="!sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 hover:!sp-bg-slate-50">';
      foreach ($row as $cell) $html .= '<td class="!sp-border-x-0 !sp-border-y-0 !sp-px-4 !sp-py-3">' . $cell . '</td>';
      $html .= '</tr>';
    }
    if (!$rows) $html .= '<tr><td colspan="' . count($head) . '" class="!sp-p-6 !sp-text-center !sp-text-service-muted">' . self::e($empty) . '</td></tr>';
    return $html . '</tbody></table></div></div>';
  }

  /** Inline proportional bar for table cells. */
  public static function meter(int $value, int $max, string $bg = '!sp-bg-service-navy'): string
  {
    $width = $max > 0 ? max(2, (int) round($value * 100 / $max)) : 0;
    return '<div class="!sp-flex !sp-items-center !sp-gap-2 !sp-min-w-[140px]"><span class="!sp-block !sp-h-2 !sp-flex-1 !sp-rounded-full !sp-bg-slate-100 !sp-overflow-hidden"><span class="!sp-block !sp-h-full !sp-rounded-full ' . $bg . '" style="width:' . $width . '%"></span></span><strong>' . $value . '</strong></div>';
  }

  public static function monthLabel(string $ym): string
  {
    $months = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
    return ($months[substr($ym, 5, 2)] ?? substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4);
  }

  public function shell(string $report): string
  {
    [$title, $subtitle, $icon, $note] = self::REPORTS[$report];
    $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota'));
    $month = $today->format('Y-m');
    $year = (int) $today->format('Y');
    $modes = ['actual' => 'Mes actual', 'mes' => 'Por mes', 'anual' => 'Anual', 'acumulado' => 'Acumulado'];
    ob_start();
?>
    <div class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-maint-metrics="<?= self::e($report) ?>">
      <header class="<?= UI::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
        <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?= UI::icon($icon, '!sp-w-6 !sp-h-6') ?></span><div><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Métricas</p><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold"><?= self::e($title) ?></h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted"><?= self::e($subtitle) ?></p></div></div>
        <div class="!sp-flex !sp-flex-wrap !sp-gap-2"><button type="button" class="<?= UI::SECONDARY ?>" data-maint-metrics-export><?= UI::icon('download') ?>Exportar Excel</button><button type="button" class="<?= UI::NAVY ?>" data-maint-metrics-refresh><?= UI::icon('refresh') ?>Actualizar</button></div>
      </header>
      <form class="<?= UI::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-end !sp-gap-4" data-maint-metrics-form>
        <input type="hidden" name="mode" value="actual">
        <div><span class="<?= UI::LABEL ?>">Periodo</span><div class="!sp-flex !sp-flex-wrap !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg" role="radiogroup" aria-label="Periodo">
          <?php foreach ($modes as $key => $label): ?><button type="button" role="radio" class="!sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-checked:!sp-bg-service-navy aria-checked:!sp-text-white aria-checked:!sp-font-semibold" aria-checked="<?= $key === 'actual' ? 'true' : 'false' ?>" data-maint-metrics-mode="<?= $key ?>"><?= $label ?></button><?php endforeach ?>
        </div></div>
        <label class="[&[hidden]]:!sp-hidden" data-maint-metrics-mode-field="mes" hidden><span class="<?= UI::LABEL ?>">Mes</span><input class="<?= UI::INPUT ?> !sp-w-44" type="month" name="month" value="<?= $month ?>" max="<?= $month ?>"></label>
        <label class="[&[hidden]]:!sp-hidden" data-maint-metrics-mode-field="anual" hidden><span class="<?= UI::LABEL ?>">Año</span><select class="<?= UI::INPUT ?> !sp-w-32" name="year"><?php for ($y = $year; $y >= 2015; $y--): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor ?></select></label>
        <label class="[&[hidden]]:!sp-hidden" data-maint-metrics-mode-field="acumulado" hidden><span class="<?= UI::LABEL ?>">Desde</span><input class="<?= UI::INPUT ?> !sp-w-44" type="month" name="from_month" value="<?= $year ?>-01" max="<?= $month ?>"></label>
        <label class="[&[hidden]]:!sp-hidden" data-maint-metrics-mode-field="acumulado" hidden><span class="<?= UI::LABEL ?>">Hasta</span><input class="<?= UI::INPUT ?> !sp-w-44" type="month" name="to_month" value="<?= $month ?>" max="<?= $month ?>"></label>
        <button type="submit" class="<?= UI::PRIMARY ?> [&[hidden]]:!sp-hidden" data-maint-metrics-apply hidden><?= UI::icon('filter') ?>Aplicar</button>
        <p class="!sp-m-0 !sp-w-full !sp-text-[10px] !sp-text-service-muted"><?= self::e($note) ?></p>
      </form>
      <div data-maint-metrics-content aria-live="polite"><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Abre esta métrica para cargar la información.</p></div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  public function render(string $report, array $data, string $from, string $to): string
  {
    extract($data, EXTR_SKIP);
    $period = date('d/m/Y', (int) strtotime($from)) . ' – ' . date('d/m/Y', (int) strtotime($to));
    ob_start();
    require dirname(__DIR__, 3) . '/resources/views/metrics/' . self::REPORTS[$report][4] . '.php';
    return (string) ob_get_clean();
  }
}
