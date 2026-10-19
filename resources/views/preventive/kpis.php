<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
$e = [SCM\Modules\Pending\PublicServicesDocument::class, 'e'];
$month = date('Y-m');
$withCase = 0; $dueThisMonth = 0; $overdue = 0; $todayTs = strtotime('today');
foreach ($items as $item) {
  if (!empty($item['ticket'])) $withCase++;
  $due = (int) ($item['due'] ?? 0);
  if ($due > 0 && date('Y-m', $due) === $month) $dueThisMonth++;
  if ($due > 0 && $due < $todayTs) $overdue++;
}
$cards = [
  ['Contratos pendientes', $count, 'Sin revisión ' . date('Y'), 'Contratos entregados con revisión anual pendiente', 'document', '!sp-text-service-navy', 'spp-kpi-count2'],
  ['Sin caso preventivo', max(0, count($items) - $withCase), 'Crear caso', $withCase . ' ya tienen caso activo', 'alert', '!sp-text-amber-600', ''],
  ['Corte operativo', $corte, 'Vigencia activa', 'Año de la revisión preventiva', 'calendar', '!sp-text-service-navy', 'spp-kpi-corte'],
  ['Vencen este mes', $dueThisMonth, 'Prioridad alta', $overdue . ' ya vencidas · ' . date('m/Y') . ' en curso', 'clock', '!sp-text-rose-600', ''],
];
foreach ($cards as $i => $card): ?>
<div class="<?= str_replace('!sp-border-slate-200', $i === 1 ? '!sp-border-service-yellow' : '!sp-border-slate-200', UI::CARD) ?> !sp-p-4 <?= $i === 1 ? '!sp-bg-amber-50/30' : '' ?>"><div class="!sp-flex !sp-justify-between !sp-items-center"><span class="!sp-text-[10px] !sp-uppercase !sp-font-semibold <?= $card[5] ?>"><?= $e($card[0]) ?></span><span class="!sp-p-2 !sp-rounded-lg <?= $i === 1 ? '!sp-bg-amber-100' : '!sp-bg-slate-50' ?>"><?= UI::icon($card[4]) ?></span></div><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2 !sp-mt-2"><strong <?= $card[6] ? 'id="' . $card[6] . '"' : '' ?> class="!sp-text-2xl !sp-font-extrabold <?= $card[5] ?>"><?= $e($card[1]) ?></strong><span class="!sp-text-[9px] !sp-rounded !sp-border !sp-border-solid !sp-border-slate-200 !sp-px-1.5 !sp-py-0.5 <?= $card[5] ?>"><?= $e($card[2]) ?></span></div><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?= $e($card[3]) ?></p></div>
<?php endforeach ?>
