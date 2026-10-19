<?php
use SCM\Modules\Metrics\MaintenanceMetricsView as V;
$r = $reviews; $p = $pending; $c = $critical;
$mora = $r['m30'] + $r['m60'];
?>
<div class="!sp-space-y-5">
  <section class="!sp-space-y-3" aria-label="Situación actual">
    <h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">Situación actual</h3>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Pendientes de revisión', (string) $p['total'], 'Ciclo trimestral', 'Contratos entregados con servicios configurados', 'clock') ?>
      <?= V::card('Revisión vencida', (string) $p['overdue'], V::pct($p['overdue'], $p['total']), 'Pasaron la fecha de su siguiente revisión', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Vencen este mes', (string) $p['month'], date('m/Y'), 'Programadas para el mes en curso', 'calendar', '!sp-text-amber-600') ?>
      <?= V::card('Críticos abiertos', (string) $c['open_now'], $c['overdue_now'] . ' vencidos', 'Casos de 72 horas sin cerrar · ' . $p['unconfigured'] . ' contratos sin servicios configurados', 'alert', '!sp-text-rose-600') ?>
    </div>
  </section>
  <section class="!sp-space-y-3" aria-label="Periodo">
    <h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">Periodo <?= V::e($period) ?></h3>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-5 !sp-gap-4">
      <?= V::card('Revisiones realizadas', (string) $r['total'], 'Periodo', $r['none'] ? $r['none'] . ' sin resultado registrado' : 'Una revisión por contrato y fecha', 'refresh') ?>
      <?= V::card('Al día', (string) $r['al_dia'], V::pct($r['al_dia'], $r['total']), 'Todos los servicios sin saldo vencido', 'check', '!sp-text-emerald-700') ?>
      <?= V::card('En mora 30–60 días', (string) $mora, V::pct($mora, $r['total']), '30 días: ' . $r['m30'] . ' · 60 días: ' . $r['m60'], 'calendar', '!sp-text-amber-600') ?>
      <?= V::card('Críticas', (string) $r['critico'], V::pct($r['critico'], $r['total']), $c['created'] . ' casos creados · ' . $c['closed'] . ' cerrados · ' . $c['open'] . ' abiertos', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Deuda reportada', V::money($r['debt']), 'COP', 'Suma de saldos en mora y críticos del periodo', 'document', '!sp-text-rose-600') ?>
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
    echo V::table('Mes a mes', 'Revisiones realizadas por mes del periodo.', ['Mes', 'Revisiones', 'En mora', 'Críticas'], $rows);
    $max = $staff ? max(array_column($staff, 'total')) : 0;
    $rows = [];
    foreach ($staff as $s) $rows[] = ['<strong>' . V::e($s['name']) . '</strong>', V::meter($s['total'], $max, '!sp-bg-amber-400'), (string) $s['mora'], '<span class="!sp-text-rose-600 !sp-font-semibold">' . $s['critico'] . '</span>'];
    echo V::table('Por funcionario', 'Quién realizó las revisiones del periodo.', ['Funcionario', 'Revisiones', 'En mora', 'Críticas'], $rows);
    ?>
  </div>
</div>
