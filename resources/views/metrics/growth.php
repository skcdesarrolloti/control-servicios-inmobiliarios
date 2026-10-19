<?php
use SCM\Modules\Metrics\MaintenanceMetricsView as V;
$canon = $types['canon']; $admin = $types['admin']; $pf = $portfolio;
$avg = static fn(array $t): string => $t['pct_count'] > 0 ? number_format($t['pct_sum'] / $t['pct_count'], 2, ',', '.') . '%' : '—';
$growth = static fn(int $increase, int $base): string => $base > 0 ? number_format($increase * 100 / $base, 2, ',', '.') . '%' : '—';
$unknown = $canon['unknown'] + $admin['unknown'];
?>
<div class="!sp-space-y-5">
  <section class="!sp-space-y-3" aria-label="Periodo">
    <h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">Periodo <?= V::e($period) ?></h3>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Aumento mensual en canon', V::money($canon['increase']), '+' . $growth($canon['increase'], $canon['before']), V::money($canon['increase'] * 12) . ' al año · ' . $canon['letters'] . ' cartas', 'refresh', '!sp-text-emerald-700') ?>
      <?= V::card('Aumento mensual en administración', V::money($admin['increase']), '+' . $growth($admin['increase'], $admin['before']), V::money($admin['increase'] * 12) . ' al año · ' . $admin['letters'] . ' cartas', 'building', '!sp-text-emerald-700') ?>
      <?= V::card('Incremento promedio canon', $avg($canon), 'Porcentaje', 'Promedio del porcentaje aplicado en las cartas', 'check') ?>
      <?= V::card('Incremento promedio administración', $avg($admin), 'Porcentaje', 'Promedio del porcentaje aplicado en las cartas', 'check') ?>
    </div>
  </section>
  <section class="!sp-space-y-3" aria-label="Cartera administrada">
    <h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">Cartera administrada hoy</h3>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Contratos entregados', (string) $pf['contracts'], 'Activos', 'Contratos de arrendamiento en estado Entregado', 'document') ?>
      <?= V::card('Canon mensual administrado', V::money($pf['canon']), 'Mensual', 'Suma del canon vigente de los contratos entregados', 'refresh') ?>
      <?= V::card('Administración mensual', V::money($pf['admin']), 'Mensual', 'Suma de la administración vigente', 'building') ?>
      <?= V::card('Peso del aumento en la cartera', $growth($canon['increase'], $pf['canon']), 'Canon', 'Aumento de canon del periodo frente al canon administrado', 'alert', '!sp-text-amber-600') ?>
    </div>
  </section>
  <?php
  echo V::table('Antes y después de los aumentos', $unknown ? $unknown . ' cartas sin porcentaje ni carta anterior no suman al aumento.' : 'Valores mensuales de los contratos con carta en el periodo.', ['Concepto', 'Cartas', 'Valor antes', 'Valor después', 'Aumento mensual', 'Aumento anual'], [
    ['<strong>Canon</strong>', (string) $canon['letters'], V::money($canon['before']), V::money($canon['after']), '<strong class="!sp-text-emerald-700">' . V::money($canon['increase']) . '</strong>', V::money($canon['increase'] * 12)],
    ['<strong>Administración</strong>', (string) $admin['letters'], V::money($admin['before']), V::money($admin['after']), '<strong class="!sp-text-emerald-700">' . V::money($admin['increase']) . '</strong>', V::money($admin['increase'] * 12)],
  ]);
  $max = $trend ? max(array_map(static fn($t) => $t['canon_increase'] + $t['admin_increase'], $trend)) : 0;
  $rows = [];
  foreach ($trend as $ym => $t) $rows[] = ['<strong>' . V::e(V::monthLabel($ym)) . '</strong>', (string) $t['canon'], V::money($t['canon_increase']), (string) $t['admin'], V::money($t['admin_increase']), V::meter($t['canon_increase'] + $t['admin_increase'], $max, '!sp-bg-emerald-500')];
  echo V::table('Mes a mes', 'Cartas generadas y aumento mensual que agregan.', ['Mes', 'Cartas canon', 'Aumento canon', 'Cartas admin.', 'Aumento admin.', 'Crecimiento'], $rows);
  $rows = [];
  foreach ($top as $item) $rows[] = ['<strong>#' . V::e($item['contract']) . '</strong><small class="!sp-block !sp-text-service-muted">' . V::e($item['property']) . '</small>', $item['type'] === 'canon' ? 'Canon' : 'Administración', V::e($item['tenant']), date('d/m/Y', $item['date']), $item['pct'] > 0 ? number_format($item['pct'], 2, ',', '.') . '%' : '—', V::money($item['before']) . ' → ' . V::money($item['after']), '<strong class="!sp-text-emerald-700">+' . V::money($item['increase']) . '</strong>'];
  echo V::table('Mayores aumentos del periodo', 'Las 15 cartas que más aumentaron el valor mensual.', ['Contrato', 'Tipo', 'Arrendatario', 'Fecha', '%', 'Antes → después', 'Aumento'], $rows);
  ?>
</div>
