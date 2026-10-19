<?php
use SCM\Modules\Metrics\MaintenanceMetricsView as V;
$r = $reviews; $p = $pending; $q = $quotes;
$queue = static fn(string $key): array => ($queues[$key] ?? []) + ['total' => 0, 'overdue' => 0];
$sinCita = $queue('sin_cita'); $cita = $queue('cita_sin_realizar'); $sinEnviar = $queue('sin_enviar');
?>
<div class="!sp-space-y-5">
  <section class="!sp-space-y-3" aria-label="Situación actual">
    <h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">Situación actual</h3>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Pendientes de revisión', (string) $p['total'], 'Año ' . date('Y'), $p['without_case'] . ' todavía sin caso preventivo', 'clock') ?>
      <?= V::card('Casos sin cita', (string) $sinCita['total'], $sinCita['overdue'] . ' vencidos', 'Casos preventivos abiertos sin cita en el calendario', 'calendar', '!sp-text-amber-600') ?>
      <?= V::card('Con cita sin realizar', (string) $cita['total'], $cita['overdue'] . ' vencidos', 'Cita preventiva agendada sin marcar como realizada', 'alert', '!sp-text-amber-600') ?>
      <?= V::card('Revisiones sin enviar', (string) $sinEnviar['total'], $sinEnviar['overdue'] . ' vencidas', 'Informe pendiente de enviar al destinatario', 'document', '!sp-text-rose-600') ?>
    </div>
  </section>
  <section class="!sp-space-y-3" aria-label="Periodo">
    <h3 class="!sp-m-0 !sp-text-[10px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted">Periodo <?= V::e($period) ?></h3>
    <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
      <?= V::card('Revisiones realizadas', (string) $r['total'], 'Periodo', $r['sent'] . ' enviadas · ' . $r['unsent'] . ' sin enviar', 'refresh') ?>
      <?= V::card('Con daños', (string) $r['damages'], V::pct($r['damages'], $r['total']), $r['no_damages'] . ' revisiones sin daños', 'alert', '!sp-text-rose-600') ?>
      <?= V::card('Daños con cotización', (string) $r['with_quote'], V::pct($r['with_quote'], $r['damages']), $r['without_quote'] . ' revisiones con daños aún sin cotizar', 'document', '!sp-text-amber-600') ?>
      <?= V::card('Administración ganada', V::money($q['admin']), $q['aprobada'] . ' aprobadas', 'Sin IVA, de cotizaciones aprobadas · por ganar ' . V::money($q['admin_pending']), 'check', '!sp-text-emerald-700') ?>
    </div>
  </section>
  <div class="!sp-grid !sp-grid-cols-1 xl:!sp-grid-cols-2 !sp-gap-5">
    <?= V::bar('Daños encontrados', [['Con daños', $r['damages'], '!sp-bg-rose-500'], ['Sin daños', $r['no_damages'], '!sp-bg-emerald-500']], $r['total'] . ' revisiones') ?>
    <?= V::bar('Revisiones con daños', [['Con cotización', $r['with_quote'], '!sp-bg-service-navy'], ['Sin cotización', $r['without_quote'], '!sp-bg-amber-400']], $r['damages'] . ' con daños') ?>
  </div>
  <?= V::bar('Estado de las cotizaciones de preventivas', [['Aprobadas', $q['aprobada'], '!sp-bg-emerald-500'], ['Esperando respuesta', $q['esperando'], '!sp-bg-sky-500'], ['Sin enviar', $q['sin_enviar'], '!sp-bg-amber-400'], ['Desaprobadas', $q['desaprobada'], '!sp-bg-rose-500']], $q['total'] . ' cotizaciones') ?>
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
    echo V::table('Mes a mes', 'Revisiones preventivas realizadas por mes.', ['Mes', 'Revisiones', 'Enviadas', 'Con daños'], $rows);
    $max = $staff ? max(array_column($staff, 'total')) : 0;
    $rows = [];
    foreach ($staff as $s) $rows[] = ['<strong>' . V::e($s['name']) . '</strong>', V::meter($s['total'], $max, '!sp-bg-amber-400'), '<span class="!sp-text-rose-600 !sp-font-semibold">' . $s['damages'] . '</span>', (string) $s['unsent']];
    echo V::table('Por funcionario', 'Quién realizó las revisiones del periodo.', ['Funcionario', 'Revisiones', 'Con daños', 'Sin enviar'], $rows);
    ?>
  </div>
</div>
