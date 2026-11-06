<?php
use SCM\Modules\Metrics\EmployeeBudgetView;
use SCM\Modules\Metrics\MaintenanceMetricsView as V;
use SCM\Modules\Metrics\MetricsBrief;
$r = $reviews; $p = $pending; $q = $quotes;
$queue = static fn(string $key): array => ($queues[$key] ?? []) + ['total' => 0, 'overdue' => 0];
$sinCita = $queue('sin_cita'); $cita = $queue('cita_sin_realizar'); $sinEnviar = $queue('sin_enviar');
$toDate = $p['overdue'] + $p['month'];
$answered = $q['aprobada'] + $q['desaprobada'];
$heading = static fn(string $title): string => '<h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">' . V::e($title) . '</h3>';
?>
<div class="!sp-space-y-5">
  <?= V::brief(MetricsBrief::preventive($data)) ?>
  <section class="!sp-space-y-3" aria-label="Cumplimiento">
    <?= $heading('Cumplimiento · mismas cifras de Mis presupuestos y ejecuciones') ?>
    <?= EmployeeBudgetView::reviewCompliance('preventiva', $stats, $statsYear, $statsMonth) ?>
  </section>
  <section class="!sp-space-y-3" aria-label="Situación actual">
    <?= $heading('Situación actual · dónde están las pendientes') ?>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Pendientes a la fecha', (string) $toDate, 'Año ' . date('Y'), $p['overdue'] . ' vencidas · ' . $p['month'] . ' de este mes · ' . $p['later'] . ' más antes de fin de año · ' . $p['without_case'] . ' sin caso', 'clock') ?>
      <?= V::card('Casos sin cita', (string) $sinCita['total'], $sinCita['overdue'] . ' vencidos', 'Casos preventivos abiertos sin cita en el calendario', 'calendar', '!sp-text-amber-600') ?>
      <?= V::card('Con cita sin realizar', (string) $cita['total'], $cita['overdue'] . ' vencidos', 'Cita preventiva agendada sin marcar como realizada', 'alert', '!sp-text-amber-600') ?>
      <?= V::card('Revisiones sin enviar', (string) $sinEnviar['total'], $sinEnviar['overdue'] . ' vencidas', 'Informe pendiente de enviar al destinatario', 'document', '!sp-text-rose-600') ?>
    </div>
  </section>
  <?= EmployeeBudgetView::reviewComplianceHistory('preventiva', $stats, $statsMonth) ?>
  <section class="!sp-space-y-3" aria-label="Periodo">
    <?= $heading('Periodo ' . $period) ?>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Revisiones realizadas', (string) $r['total'], 'Periodo', $r['sent'] . ' enviadas · ' . $r['unsent'] . ' sin enviar' . ($r['send_days'] !== null ? ' · se envían en ' . $r['send_days'] . ' días (mediana)' : ''), 'refresh') ?>
      <?= V::card('Con daños', (string) $r['damages'], V::pct($r['damages'], $r['total']), $r['no_damages'] . ' revisiones sin daños', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Daños con cotización', (string) $r['with_quote'], V::pct($r['with_quote'], $r['damages']), $r['without_quote'] . ' revisiones con daños aún sin cotizar', 'document', '!sp-text-amber-600') ?>
      <?= V::card('Administración ganada', V::money($q['admin']), $q['aprobada'] . ' aprobadas', 'Sin IVA, de las revisiones del periodo · por ganar ' . V::money($q['admin_pending']), 'check', '!sp-text-emerald-700') ?>
    </div>
  </section>
  <div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-2 !sp-gap-5 !sp-items-start">
    <?= V::funnel('De la revisión a la administración ganada', [
      ['Revisiones realizadas', $r['total'], ''],
      ['Encontraron daños', $r['damages'], V::pct($r['damages'], $r['total']) . ' de las revisiones'],
      ['Daños cotizados', $r['with_quote'], V::pct($r['with_quote'], $r['damages']) . ' de las que tienen daños'],
      ['Cotizaciones aprobadas', $q['aprobada'], $q['total'] . ' cotizaciones · ' . ($answered > 0 ? V::pct($q['aprobada'], $answered) . ' de las respondidas' : 'sin respuestas')],
    ]) ?>
    <?= V::bar('Estado de las cotizaciones de preventivas', [['Aprobadas', $q['aprobada'], '!sp-bg-emerald-500'], ['Esperando respuesta', $q['esperando'], '!sp-bg-sky-500'], ['Sin enviar', $q['sin_enviar'], '!sp-bg-amber-400'], ['Desaprobadas', $q['desaprobada'], '!sp-bg-rose-500']], $q['total'] . ' cotizaciones') ?>
  </div>
  <?php
  echo V::table('Valores de las cotizaciones', 'Cotizaciones vinculadas a revisiones preventivas del periodo.', ['Concepto', 'Valor'], [
    ['Total cotizado', '<strong>' . V::money($q['quoted']) . '</strong>'],
    ['Total aprobado', '<strong class="!sp-text-emerald-700">' . V::money($q['approved_value']) . '</strong>'],
    ['Administración ganada (aprobadas, sin IVA)', '<strong class="!sp-text-emerald-700">' . V::money($q['admin']) . '</strong>'],
    ['Administración por ganar (esperando respuesta o sin enviar)', '<strong class="!sp-text-amber-700">' . V::money($q['admin_pending']) . '</strong>'],
  ]);
  ?>
  <div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-2 !sp-gap-5">
    <?php
    $max = $trend ? max(array_column($trend, 'total')) : 0;
    $rows = [];
    foreach ($trend as $ym => $t) $rows[] = ['<strong>' . V::e(V::monthLabel($ym)) . '</strong>', V::meter($t['total'], $max), (string) $t['sent'], '<span class="!sp-text-rose-600 !sp-font-semibold">' . $t['damages'] . '</span>'];
    echo V::table('Revisiones por mes', 'Revisiones preventivas realizadas por mes.', ['Mes', 'Revisiones', 'Enviadas', 'Con daños'], $rows);
    $max = $staff ? max(array_column($staff, 'total')) : 0;
    $rows = [];
    foreach ($staff as $s) $rows[] = ['<strong>' . V::e($s['name']) . '</strong>', V::meter($s['total'], $max, '!sp-bg-amber-400'), '<span class="!sp-text-rose-600 !sp-font-semibold">' . $s['damages'] . '</span>', (string) $s['unsent']];
    echo V::table('Por funcionario', 'Quién realizó las revisiones del periodo.', ['Funcionario', 'Revisiones', 'Con daños', 'Sin enviar'], $rows);
    ?>
  </div>
</div>
