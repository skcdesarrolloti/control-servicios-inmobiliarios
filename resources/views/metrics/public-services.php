<?php
use SCM\Modules\Metrics\EmployeeBudgetView;
use SCM\Modules\Metrics\MaintenanceMetricsView as V;
use SCM\Modules\Metrics\MetricsBrief;
$r = $reviews; $p = $pending; $c = $critical;
$mora = $r['m30'] + $r['m60'];
$heading = static fn(string $title): string => '<h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">' . V::e($title) . '</h3>';
?>
<div class="!sp-space-y-5">
  <?= V::brief(MetricsBrief::services($data)) ?>
  <section class="!sp-space-y-3" aria-label="Cumplimiento">
    <?= $heading('Cumplimiento · mismas cifras de Mis presupuestos y ejecuciones') ?>
    <?= EmployeeBudgetView::reviewCompliance('servicios', $stats, $statsYear, $statsMonth) ?>
  </section>
  <section class="!sp-space-y-3" aria-label="Situación actual">
    <?= $heading('Situación actual · qué hay que atender hoy') ?>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Revisiones vencidas', (string) $p['overdue'], V::pct($p['overdue'], $p['base']) . ' de la cartera', $p['overdue_year'] . ' de este año · ' . $p['overdue_old'] . ' de años anteriores (no cuentan en el cumplimiento)', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Vencen este mes', (string) $p['month'], date('m/Y'), 'A tiempo si se hacen antes del cierre del mes · de ' . $p['base'] . ' contratos con servicios', 'calendar', '!sp-text-amber-600') ?>
      <?= V::card('Críticos abiertos', (string) $c['open_now'], $c['overdue_now'] . ' vencidos', 'Casos de 72 horas sin cerrar', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Sin servicios configurados', (string) $p['unconfigured'], 'Contratos', 'No se pueden revisar hasta configurarlos', 'clock', '!sp-text-amber-600') ?>
    </div>
    <?php if ($p['overdue'] > 0): ?>
      <div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-2 !sp-gap-5 !sp-items-start">
        <?= V::bar('Antigüedad de las vencidas', [['Hasta 30 días', $aging['d30'], '!sp-bg-amber-300'], ['31 a 60 días', $aging['d60'], '!sp-bg-amber-500'], ['61 a 90 días', $aging['d90'], '!sp-bg-orange-500'], ['Más de 90 días', $aging['older'], '!sp-bg-rose-600']], $p['overdue'] . ' vencidas') ?>
        <?php
        $rows = [];
        foreach ($late as $item) $rows[] = ['<strong>' . V::e($item['contract']) . '</strong>', V::e($item['address']), V::e($item['tenant']), V::e(date('d/m/Y', (int) $item['due'])), '<span class="!sp-text-rose-600 !sp-font-semibold">' . (int) $item['days'] . '</span>'];
        echo V::table('Los 10 contratos más atrasados', 'Por dónde empezar.', ['Contrato', 'Dirección', 'Arrendatario', 'Vencía', 'Días'], $rows);
        ?>
      </div>
    <?php endif ?>
  </section>
  <?= EmployeeBudgetView::reviewComplianceHistory('servicios', $stats, $statsMonth) ?>
  <section class="!sp-space-y-3" aria-label="Periodo">
    <?= $heading('Periodo ' . $period) ?>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-5 !sp-gap-4">
      <?= V::card('Revisiones realizadas', (string) $r['total'], 'Periodo', $r['during'] . ' durante la ocupación · ' . ($r['total'] - $r['during']) . ' antes' . ($r['none'] ? ' · ' . $r['none'] . ' sin resultado' : ''), 'refresh') ?>
      <?= V::card('Al día', (string) $r['al_dia'], V::pct($r['al_dia'], $r['total']), 'Todos los servicios sin saldo vencido', 'check', '!sp-text-emerald-700') ?>
      <?= V::card('En mora 30–60 días', (string) $mora, V::pct($mora, $r['total']), '30 días: ' . $r['m30'] . ' · 60 días: ' . $r['m60'], 'calendar', '!sp-text-amber-600') ?>
      <?= V::card('Críticas', (string) $r['critico'], V::pct($r['critico'], $r['total']), $c['created'] . ' casos creados · ' . $c['closed'] . ' cerrados · ' . $c['open'] . ' abiertos', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Deuda reportada', V::money($r['debt']), 'COP', 'Último saldo en mora o crítico de cada contrato revisado', 'document', '!sp-text-rose-600') ?>
    </div>
  </section>
  <?= V::bar('Resultado de las revisiones', [['Al día', $r['al_dia'], '!sp-bg-emerald-500'], ['30 días', $r['m30'], '!sp-bg-amber-400'], ['60 días', $r['m60'], '!sp-bg-orange-500'], ['Crítico', $r['critico'], '!sp-bg-rose-600']], 'Cada revisión cuenta por su peor servicio') ?>
  <?php
  $rows = [];
  foreach ($services as $s) {
    $rows[] = ['<strong>' . V::e($s['label']) . '</strong>', (string) $s['reviewed'], '<span class="!sp-text-emerald-700 !sp-font-semibold">' . $s['al_dia'] . '</span>', (string) $s['m30'], (string) $s['m60'], '<span class="!sp-text-rose-600 !sp-font-semibold">' . $s['critico'] . '</span>', V::money($s['debt'])];
  }
  echo V::table('Resultado por servicio', 'Cada servicio revisado cuenta por separado.', ['Servicio', 'Revisados', 'Al día', '30 días', '60 días', 'Crítico', 'Deuda reportada'], $rows);
  ?>
  <div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-2 !sp-gap-5">
    <?php
    $max = $trend ? max(array_column($trend, 'total')) : 0;
    $rows = [];
    foreach ($trend as $ym => $t) $rows[] = ['<strong>' . V::e(V::monthLabel($ym)) . '</strong>', V::meter($t['total'], $max), (string) $t['mora'], '<span class="!sp-text-rose-600 !sp-font-semibold">' . $t['critico'] . '</span>'];
    echo V::table('Revisiones por mes', 'Revisiones realizadas por mes del periodo.', ['Mes', 'Revisiones', 'En mora', 'Críticas'], $rows);
    $max = $staff ? max(array_column($staff, 'total')) : 0;
    $rows = [];
    foreach ($staff as $s) $rows[] = ['<strong>' . V::e($s['name']) . '</strong>', V::meter($s['total'], $max, '!sp-bg-amber-400'), (string) $s['mora'], '<span class="!sp-text-rose-600 !sp-font-semibold">' . $s['critico'] . '</span>'];
    echo V::table('Por funcionario', 'Quién realizó las revisiones del periodo y qué encontró.', ['Funcionario', 'Revisiones', 'En mora', 'Críticas'], $rows);
    ?>
  </div>
</div>
