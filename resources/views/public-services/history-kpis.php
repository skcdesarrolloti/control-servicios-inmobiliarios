<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
$e = [SCM\Modules\Pending\PublicServicesDocument::class, 'e'];
$k = $kpis + ['total'=>0,'month'=>0,'al_dia'=>0,'m30'=>0,'m60'=>0,'critico'=>0,'sin_resultado'=>0,'open'=>0,'overdue'=>0];
$pct = static fn(int $value): string => $k['total'] > 0 ? round($value * 100 / $k['total']) . '%' : '0%';
$mora = $k['m30'] + $k['m60'];
$cards = [
  ['Revisiones realizadas', $k['total'], 'Filtro actual', $k['month'] . ' este mes' . ($k['sin_resultado'] ? ' · ' . $k['sin_resultado'] . ' sin resultado' : ''), 'refresh', '!sp-text-service-navy'],
  ['Al día', $k['al_dia'], $pct($k['al_dia']), 'Todos los servicios sin saldo vencido', 'check', '!sp-text-emerald-700'],
  ['En mora 30–60 días', $mora, $pct($mora), '30 días: ' . $k['m30'] . ' · 60 días: ' . $k['m60'], 'calendar', '!sp-text-amber-600'],
  ['Estado crítico', $k['critico'], $pct($k['critico']), 'Casos abiertos: ' . $k['open'] . ' · vencidos: ' . $k['overdue'], 'alert', '!sp-text-rose-600'],
];
?>
<div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4" data-services-history-kpis>
<?php foreach ($cards as $card): ?>
<div class="<?= UI::CARD ?> !sp-p-4"><div class="!sp-flex !sp-justify-between !sp-items-center"><span class="!sp-text-[10px] !sp-uppercase !sp-font-semibold <?= $card[5] ?>"><?= $e($card[0]) ?></span><span class="!sp-p-2 !sp-rounded-lg !sp-bg-slate-50"><?= UI::icon($card[4]) ?></span></div><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2 !sp-mt-2"><strong class="!sp-text-2xl !sp-font-extrabold <?= $card[5] ?>"><?= $e($card[1]) ?></strong><span class="!sp-text-[9px] !sp-rounded !sp-border !sp-border-solid !sp-border-slate-200 !sp-px-1.5 !sp-py-0.5 <?= $card[5] ?>"><?= $e($card[2]) ?></span></div><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?= $e($card[3]) ?></p></div>
<?php endforeach ?>
</div>
