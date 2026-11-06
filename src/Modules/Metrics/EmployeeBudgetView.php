<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use SCM\Modules\Metrics\MaintenanceMetricsView as V;
use SCM\Modules\Pending\PublicServicesUi as UI;

/** Métricas › Mis presupuestos y ejecuciones (Tailwind sp-). */
final class EmployeeBudgetView
{
  public const REPORT = 'mis_presupuestos';
  private const REVIEWS = [
    'preventiva' => ['Cumplimiento revisiones preventivas', 80, 'Cada contrato entregado debe tener una revisión preventiva al año. Son pendientes los que vencen hasta el cierre del mes de corte; los entregados este año no cuentan.'],
    'servicios' => ['Cumplimiento revisión servicios públicos', 95, 'Cada contrato con servicios configurados se revisa cada 3 meses. Son pendientes los que vencen de enero al cierre del mes de corte, y solo cuentan las revisiones durante la ocupación.'],
  ];
  private const CASES_MINIMUM = 85;
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
        <label><span class="<?= UI::LABEL ?>">Vista</span><select class="<?= UI::INPUT ?> !sp-w-36" name="vista"><option value="mes">Mes</option><option value="acumulado">Acumulado</option></select></label>
        <label><span class="<?= UI::LABEL ?>">Año</span><select class="<?= UI::INPUT ?> !sp-w-32" name="year"><?php for ($y = $year; $y >= $year - 1; $y--): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?></select></label>
        <label><span class="<?= UI::LABEL ?>">Mes</span><select class="<?= UI::INPUT ?> !sp-w-44" name="month"><?php foreach (self::MONTHS as $number => $label): ?><option value="<?= $number ?>"<?= $number === $month ? ' selected' : '' ?>><?= V::e($label) ?></option><?php endforeach; ?></select></label>
      </form>
      <div data-maint-metrics-content aria-live="polite"><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Abre esta métrica para cargar la información.</p></div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array{year:int,month:int,activities:array<int,array<string,mixed>>} $report */
  public function render(array $report, string $vista = 'mes'): string
  {
    $compliance = $report['compliance'] ?? [];
    $sections = '';
    if (!empty($compliance['reviews'])) {
      foreach (array_keys(self::REVIEWS) as $type) $sections .= self::reviewCard($type, $compliance['stats'] ?? null, $vista, $report['year'], $report['month'], true);
    }
    // Casos al día es una foto del corte: no tiene acumulado.
    if (!empty($compliance['cases']) && $vista !== 'acumulado') $sections .= self::casesCard($compliance['case_summary'] ?? null);

    $activities = $report['activities'];
    if ($activities === []) {
      if ($sections !== '') return '<div class="!sp-space-y-5">' . $sections . '</div>';
      return '<div class="' . UI::CARD . ' !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">No tienes presupuestos cargados para ' . $report['year'] . '.</div>';
    }
    $monthName = self::MONTHS[$report['month']];

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

    $html = '<div class="!sp-space-y-5">' . $sections;
    $html .= '<div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">';
    $html .= V::card('Actividades con meta', (string) $total, (string) $report['year'], 'Actividades con presupuesto este año', 'document');
    $html .= V::card('Metas cumplidas en el mes', $reached('month') . ' de ' . $total, $monthName, 'Llegaron al 100 % de la meta del mes', 'check', '!sp-text-emerald-700');
    $html .= V::card('Metas cumplidas acumuladas', $reached('accumulated') . ' de ' . $total, 'Acumulado', 'Llegaron al 100 % de enero a ' . mb_strtolower($monthName, 'UTF-8'), 'check', '!sp-text-emerald-700');
    $html .= '</div>';
    $html .= V::table('Cómo vas en ' . mb_strtolower($monthName, 'UTF-8'), 'Meta y ejecución del mes y acumuladas desde enero.' . ($missing > 0 ? ' Sin dato: Reportes no entregó esa ejecución.' : ''), ['Actividad', 'Meta mes', 'Ejecutado mes', '% mes', 'Meta acumulada', 'Ejecutado acumulado', '% acumulado'], $summary);
    $html .= V::table('Mes a mes', 'Porcentaje de cumplimiento de cada mes.', $head, $history);
    return $html . '</div>';
  }

  /** Vista del gerente: acumulado y mes lado a lado. */
  public static function reviewCompliance(string $type, ?array $stats, int $year, int $month): string
  {
    $cards = self::reviewCard($type, $stats, 'acumulado', $year, $month, false);
    if (!is_array($stats[$type] ?? null)) return $cards;
    return '<div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-2 !sp-gap-4">' . $cards . self::reviewCard($type, $stats, 'mes', $year, $month, false) . '</div>';
  }

  public static function reviewComplianceHistory(string $type, ?array $stats, int $month): string
  {
    return is_array($stats[$type] ?? null) ? self::reviewHistory((array) ($stats[$type]['serie'] ?? []), $month, self::REVIEWS[$type][1]) : '';
  }

  public static function reviewMinimum(string $type): int
  {
    return self::REVIEWS[$type][1];
  }

  public static function missingForMinimum(int $done, int $total, int $minimum): int
  {
    return max(0, (int) ceil($total * $minimum / 100) - $done);
  }

  private static function reviewCard(string $type, ?array $stats, string $vista, int $year, int $month, bool $withHistory): string
  {
    [$title, $minimum, $rule] = self::REVIEWS[$type];
    $open = '<section class="' . UI::CARD . ' !sp-p-5 !sp-space-y-4"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">' . V::e($title) . '</h3>';
    $data = $stats[$type] ?? null;
    if (!is_array($data)) return $open . '<p class="!sp-m-0 !sp-text-xs !sp-text-service-muted">Reportes no entregó este cumplimiento.</p></section>';

    $cut = (string) ($stats['fecha_corte'] ?? '');
    $cutLabel = $cut !== '' ? $cut : '-';
    $monthly = $vista !== 'acumulado';
    $figures = (array) ($monthly ? ($data['mes'] ?? []) : ($data['acumulado'] ?? []));
    $done = (int) ($figures['realizadas'] ?? 0);
    $late = (int) ($figures['tarde'] ?? 0);
    $pending = (int) ($figures['pendientes'] ?? 0);
    $total = (int) ($figures['total'] ?? 0);
    $pct = (float) ($figures['porcentaje'] ?? 0);
    $closed = sprintf('%04d-%02d', $year, $month) < (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m');
    $pctLabel = number_format($pct, 1, '.', '') . '%';

    $cells = $monthly
      ? ['A tiempo' => $done, 'Hechas tarde' => $late, 'Pendientes' => $pending, 'Total a realizar' => $total]
      : ['Realizadas' => $done, 'Pendientes' => $pending, 'Total a realizar' => $total];
    $formula = $monthly
      ? "{$done} a tiempo ÷ {$total} a realizar ({$done} a tiempo + {$late} tarde + {$pending} pendientes) = {$pctLabel}"
      : "{$done} realizadas ÷ {$total} a realizar ({$done} realizadas + {$pending} pendientes) = {$pctLabel}";
    $note = $monthly
      ? "Las pendientes coinciden con los vencimientos del mes en Control de Servicios Inmobiliarios. Corte {$cutLabel}."
      : "Entre menos pendientes, mayor cumplimiento. No incluye meses posteriores ni depende del mes seleccionado. Corte {$cutLabel}.";
    $explanation = $monthly ? 'Cada revisión cuenta en el mes en que vencía y solo suma si se hizo a tiempo. Las hechas tarde suman en el acumulado.' : $rule;
    $period = $monthly ? self::MONTHS[$month] . ' ' . $year : 'Acumulado ' . $year;

    $html = $open . self::summaryHead($period, $pct >= $minimum ? 'ok' : ($monthly && !$closed ? 'running' : 'miss'));
    $html .= '<div><strong class="!sp-block !sp-text-3xl !sp-font-extrabold">' . V::e($pctLabel) . '</strong><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">' . V::e(self::shortfall($done, $total, $minimum, $monthly && $closed)) . '</p></div>';
    $html .= self::thresholdBar($pct, $minimum);
    $html .= '<div class="!sp-flex !sp-flex-wrap !sp-gap-3">';
    foreach ($cells as $label => $value) {
      $html .= '<div class="!sp-flex-1 !sp-min-w-[110px] !sp-rounded-lg !sp-bg-slate-50 !sp-px-4 !sp-py-3"><span class="!sp-block !sp-text-[10px] !sp-text-service-muted">' . V::e($label) . '</span><strong class="!sp-text-xl !sp-font-bold">' . $value . '</strong></div>';
    }
    $html .= '</div>';
    $html .= '<div class="!sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200 !sp-px-4 !sp-py-3 !sp-text-xs !sp-text-service-muted !sp-space-y-1"><strong class="!sp-block !sp-text-service-navy">Cómo se calcula</strong><p class="!sp-m-0">' . V::e($formula) . '</p><p class="!sp-m-0">' . V::e($explanation) . '</p><p class="!sp-m-0 !sp-text-[10px]">' . V::e($note) . '</p></div>';
    if ($monthly && $withHistory) $html .= self::reviewHistory((array) ($data['serie'] ?? []), $month, $minimum);
    return $html . '</section>';
  }

  /** Un mes abierto todavía no incumple: las revisiones cuentan a tiempo hasta su cierre. */
  private static function summaryHead(string $period, string $state): string
  {
    [$badge, $label] = [
      'ok' => ['!sp-bg-emerald-50 !sp-text-emerald-800', '✓ Cumple'],
      'running' => ['!sp-bg-amber-50 !sp-text-amber-800', '◷ Mes en curso'],
      'miss' => ['!sp-bg-rose-50 !sp-text-rose-800', '✕ No cumple'],
    ][$state];
    return '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-2"><strong class="!sp-text-sm !sp-font-semibold">' . V::e($period) . '</strong><span class="!sp-rounded-full !sp-px-3 !sp-py-1 !sp-text-xs !sp-font-semibold ' . $badge . '">' . $label . '</span></div>';
  }

  private static function shortfall(int $done, int $total, int $minimum, bool $closed): string
  {
    if ($total === 0) return 'No hay revisiones por realizar.';
    $missing = self::missingForMinimum($done, $total, $minimum);
    if ($missing === 0) return "Se alcanzó el mínimo de {$minimum}%.";
    if ($closed) return "Mes cerrado por debajo del mínimo de {$minimum}%.";
    return $missing === 1 ? "Falta 1 revisión para llegar al {$minimum}%." : "Faltan {$missing} revisiones para llegar al {$minimum}%.";
  }

  private static function thresholdBar(float $pct, int $minimum): string
  {
    $tone = $pct >= $minimum ? '!sp-bg-emerald-600' : '!sp-bg-rose-600';
    $width = max(0, min(100, $pct));
    return '<div role="img" aria-label="Cumplimiento ' . V::e((string) $pct) . '%, mínimo ' . $minimum . '%">'
      . '<div class="!sp-relative !sp-h-4 !sp-text-[10px] !sp-text-service-muted"><span class="!sp-absolute" style="left:' . $minimum . '%;transform:translateX(-50%);white-space:nowrap">Mínimo ' . $minimum . '%</span></div>'
      . '<div class="!sp-relative !sp-h-2 !sp-rounded-full !sp-bg-slate-200"><div class="!sp-h-2 !sp-rounded-full ' . $tone . '" style="width:' . $width . '%"></div><div class="!sp-absolute !sp-h-4 !sp-bg-service-navy" style="left:' . $minimum . '%;top:-4px;width:2px"></div></div></div>';
  }

  private static function reviewHistory(array $series, int $month, int $minimum): string
  {
    $rows = [];
    foreach ($series as $item) {
      $number = (int) ($item['mes'] ?? 0);
      if ($number < 1 || $number > $month) continue;
      $pct = (float) ($item['porcentaje'] ?? 0);
      $tone = $pct >= $minimum ? '!sp-text-emerald-700' : '!sp-text-rose-700';
      $label = mb_substr(self::MONTHS[$number], 0, 3, 'UTF-8');
      $rows[] = [$number === $month ? '<strong>' . V::e($label) . '</strong>' : V::e($label), (string) (int) ($item['total'] ?? 0), (string) (int) ($item['realizadas'] ?? 0), (string) (int) ($item['tarde'] ?? 0), (string) (int) ($item['pendientes'] ?? 0), '<strong class="' . $tone . '">' . number_format($pct, 1, '.', '') . '%</strong>'];
    }
    return V::table('Cumplimiento mes a mes', '', ['Mes', 'Total a realizar', 'A tiempo', 'Tarde', 'Pendientes', 'Cumplimiento'], $rows);
  }

  /** @param bool $collapsed en Casos el detalle se pliega para no alejar los filtros */
  public static function casesCard(?array $summary, bool $collapsed = false): string
  {
    $open = '<section class="' . UI::CARD . ' !sp-p-5 !sp-space-y-4"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">Cumplimiento casos al día</h3>';
    if ($summary === null) return $open . '<p class="!sp-m-0 !sp-text-xs !sp-text-service-muted">Reportes no entregó este cumplimiento.</p></section>';

    $pct = (float) $summary['pct'];
    $missing = self::missingForMinimum((int) $summary['on_time'], (int) $summary['total'], self::CASES_MINIMUM);
    $gap = $missing === 0 ? 'Se alcanzó el mínimo de ' . self::CASES_MINIMUM . '%.' : ($missing === 1 ? 'Falta 1 caso por poner al día' : "Faltan {$missing} casos por poner al día") . ' para llegar al ' . self::CASES_MINIMUM . '%.';
    $cut = (string) ($summary['cut'] ?? '');
    $html = $open . self::summaryHead('Casos al día con actualización', $pct >= self::CASES_MINIMUM ? 'ok' : 'miss');
    $html .= '<div><strong class="!sp-block !sp-text-3xl !sp-font-extrabold">' . V::e(number_format($pct, 1, '.', '') . '%') . '</strong><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">' . (int) $summary['on_time'] . ' de ' . (int) $summary['total'] . ' casos de mantenimiento y preventivos, de todos los funcionarios. ' . V::e($gap) . '</p></div>';
    $html .= self::thresholdBar($pct, self::CASES_MINIMUM);
    $html .= '<p class="!sp-m-0 !sp-text-[10px] !sp-text-service-muted">Un caso está al día si se actualizó dentro del límite de Configurar Vencimientos.' . ($cut !== '' ? ' Corte ' . V::e($cut) . '.' : '') . '</p>';

    $employees = [];
    foreach ($summary['by_employee'] as $employee) {
      $employees[] = self::caseRow($employee);
      foreach ($employee['topics'] as $topic) $employees[] = self::caseRow($topic, true);
    }
    $topics = array_map(static fn(array $topic): array => self::caseRow($topic), $summary['by_topic']);
    $head = ['Casos', 'Al día', 'Atrasados', 'Cumplimiento'];
    $tables = '<div class="!sp-grid !sp-grid-cols-1 lg:!sp-grid-cols-2 !sp-gap-4 !sp-mt-3">' . V::table('Por funcionario', '', array_merge(['Funcionario'], $head), $employees) . V::table('Por tema', '', array_merge(['Tema'], $head), $topics) . '</div>';
    $html .= $collapsed ? '<details><summary class="!sp-cursor-pointer !sp-text-xs !sp-font-semibold">Ver por funcionario y por tema</summary>' . $tables . '</details>' : $tables;
    return $html . '</section>';
  }

  private static function caseRow(array $line, bool $nested = false): array
  {
    $tone = $line['pct'] >= self::CASES_MINIMUM ? '!sp-text-emerald-700' : '!sp-text-rose-700';
    $name = $nested ? '<span class="!sp-text-service-muted" style="padding-left:16px">' . V::e($line['name']) . '</span>' : '<strong>' . V::e($line['name']) . '</strong>';
    return [$name, (string) $line['total'], (string) $line['on_time'], (string) $line['late'], '<strong class="' . $tone . '">' . number_format((float) $line['pct'], 1, '.', '') . '%</strong>'];
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
