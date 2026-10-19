<?php
use SCM\Modules\Pending\PreventiveWorkspace as Workspace;
use SCM\Modules\Pending\PublicServicesUi as UI;
$e = [SCM\Modules\Pending\PublicServicesDocument::class, 'e'];
$titles = [
  'sin_cita' => ['Casos preventivos sin cita', 'Casos preventivos abiertos que todavía no tienen una cita preventiva en el calendario.'],
  'cita_sin_realizar' => ['Preventivas con cita sin realizar', 'Casos con revisión asociada cuya cita preventiva sigue sin marcarse como realizada.'],
  'sin_enviar' => ['Revisiones preventivas sin enviar', 'Revisiones registradas que aún no se han enviado al propietario o arrendatario.'],
][$section];
$cards = [
  ['En control', $kpis['total'], 'Total de la cola', 'document', '!sp-text-service-navy'],
  ['Vencidos', $kpis['overdue'], 'Superaron el plazo configurado', 'alert', '!sp-text-rose-600'],
  ['Vencen hoy', $kpis['today'], 'Atención prioritaria', 'clock', '!sp-text-amber-600'],
];
$th = '!sp-px-4 !sp-py-3 !sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
$td = '!sp-border-x-0 !sp-border-y-0 !sp-px-4 !sp-py-4';
$baseLabel = ['sin_cita' => 'Caso creado', 'cita_sin_realizar' => 'Cita', 'sin_enviar' => 'Revisión creada'][$section];
?>
<div class="!sp-font-sans !sp-text-service-navy !sp-space-y-5" data-preventive-agenda="<?= $e($section) ?>">
<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-3 !sp-gap-4">
<?php foreach ($cards as $card): ?>
<div class="<?= UI::CARD ?> !sp-p-4"><div class="!sp-flex !sp-justify-between !sp-items-center"><span class="!sp-text-[10px] !sp-uppercase !sp-font-semibold <?= $card[4] ?>"><?= $e($card[0]) ?></span><span class="!sp-p-2 !sp-rounded-lg !sp-bg-slate-50"><?= UI::icon($card[3]) ?></span></div><strong class="!sp-block !sp-mt-2 !sp-text-2xl !sp-font-extrabold <?= $card[4] ?>"><?= (int) $card[1] ?></strong><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?= $e($card[2]) ?></p></div>
<?php endforeach ?>
</div>
<div class="<?= UI::CARD ?> !sp-p-5"><form data-preventive-workspace-form="<?= $e($section) ?>" class="!sp-flex !sp-flex-wrap !sp-items-end !sp-gap-3">
<label class="!sp-flex-1 !sp-min-w-[220px]"><span class="<?= UI::LABEL ?>">Buscar</span><input class="<?= UI::INPUT ?>" name="q" type="search" value="<?= $e($input['q'] ?? '') ?>" placeholder="Caso, contrato, inmueble, dirección, arrendatario o responsable"></label>
<label class="!sp-w-44"><span class="<?= UI::LABEL ?>">Estado</span><select class="<?= UI::INPUT ?>" name="estado"><?php foreach (['' => 'Todos', 'vencido' => 'Vencidos', 'pendiente' => 'Pendientes'] as $value => $label): ?><option value="<?= $value ?>" <?= ($input['estado'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
<button type="submit" class="<?= UI::PRIMARY ?>"><?= UI::icon('filter') ?>Filtrar</button><button type="button" class="<?= UI::SECONDARY ?>" data-preventive-clear>Limpiar</button><button type="button" class="<?= UI::SECONDARY ?>" data-preventive-export="preventivas-<?= $e($section) ?>-pagina-actual"><?= UI::icon('download') ?>Exportar Excel</button>
<input type="hidden" name="page" value="<?= (int) $page ?>"></form></div>
<div class="<?= UI::CARD ?>"><header class="!sp-p-5 !sp-bg-slate-50/50"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold"><?= $e($titles[0]) ?></h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?= $e($titles[1]) ?> El plazo se configura en Vencimientos administrativos.</p></header>
<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-min-w-[1000px] !sp-border-collapse !sp-text-[10px]" data-preventive-table><thead class="!sp-bg-slate-50"><tr><?php foreach (['Vencimiento', $section === 'sin_enviar' ? 'Revisión / Caso' : 'Caso', 'Contrato / Inmueble', 'Dirección', 'Responsable', $baseLabel, 'Acciones'] as $label): ?><th class="<?= $th ?>"><?= $e($label) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($items as $item): $case = (array) ($item['case'] ?? []); $overdue = strtolower((string) ($item['estado'] ?? '')) === 'vencido'; $ticketPk = trim((string) ($case['ticket_pk'] ?? '')); $reviewId = (int) ($case['id_revision_preventiva'] ?? 0); ?>
<tr class="!sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 hover:!sp-bg-slate-50">
<td class="<?= $td ?> !sp-whitespace-nowrap"><strong class="!sp-block"><?= UI::date($item['fecha_vencimiento'] ?? '') ?></strong><span class="!sp-inline-block !sp-mt-1 !sp-rounded !sp-px-2 !sp-py-0.5 !sp-font-semibold <?= $overdue ? '!sp-bg-rose-50 !sp-text-rose-700' : '!sp-bg-amber-50 !sp-text-amber-900' ?>"><?= $overdue ? 'Vencido · ' . (int) ($item['dias_vencido'] ?? 0) . ' día(s)' : 'Pendiente' ?></span></td>
<td class="<?= $td ?>"><?php if ($section === 'sin_enviar'): ?><span class="!sp-bg-slate-100 !sp-border !sp-border-solid !sp-border-slate-200 !sp-rounded !sp-px-2 !sp-py-1 !sp-font-bold">#<?= $reviewId ?></span><small class="!sp-block !sp-mt-2 !sp-text-service-muted">Caso #<?= $e($ticketPk ?: '-') ?></small><?php else: ?><strong>Caso #<?= $e($case['ticket'] ?? '-') ?></strong><small class="!sp-block !sp-mt-1 !sp-text-service-muted"><?= $e($case['estado'] ?? '') ?><?= ($case['admin'] ?? '-') !== '-' ? ' · ' . $e($case['admin']) : '' ?></small><?php endif ?></td>
<td class="<?= $td ?>"><strong class="!sp-block"><?= $e($case['contrato'] ?? '-') ?></strong><small class="!sp-text-service-muted">SIMI: <?= $e($case['inmueble'] ?? '-') ?></small></td>
<td class="<?= $td ?> !sp-max-w-[260px]"><span class="!sp-block"><?= $e($case['direccion'] ?? '-') ?></span><?php if (!empty($case['arrendatario'])): ?><small class="!sp-block !sp-mt-1 !sp-text-service-muted"><?= $e($case['arrendatario']) ?></small><?php endif ?></td>
<td class="<?= $td ?>"><?= $e($case['empleado'] ?? '-') ?></td>
<td class="<?= $td ?> !sp-whitespace-nowrap"><?= UI::date($item['fecha_base'] ?? '') ?></td>
<td class="<?= $td ?>"><div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-2">
<?php if ($section === 'sin_enviar' && $reviewId > 0): $url = Workspace::reviewUrl($reviewId); ?>
<button type="button" class="<?= UI::NAVY ?>" data-scm-open-iframe data-iframe-url="<?= $e($url) ?>" data-iframe-title="Revisión preventiva #<?= $reviewId ?>"><?= UI::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') ?>Ver informe</button>
<?php if ($ticketPk !== '' && $canSend): ?><button type="button" class="<?= UI::PRIMARY ?> !sp-py-2 !sp-text-[10px]" data-preventive-open-case data-preventive-send data-due-type="calendar_ticket" data-ticket-pk="<?= $e($ticketPk) ?>" data-id-revision-preventiva="<?= $reviewId ?>">Enviar</button><?php endif ?>
<?php endif ?>
<?php if ($ticketPk !== '' || $reviewId > 0): ?><button type="button" class="<?= UI::SECONDARY ?> !sp-text-[10px]" data-preventive-open-case data-due-type="<?= $e($type) ?>" data-ticket-pk="<?= $e($ticketPk) ?>" data-id-revision-preventiva="<?= $reviewId ?: '' ?>">Ver caso</button><?php else: ?><span class="!sp-text-service-muted">Sin caso asociado</span><?php endif ?>
</div></td></tr>
<?php endforeach ?>
<?php if (!$items): ?><tr><td colspan="7" class="!sp-p-8 !sp-text-center !sp-text-service-muted">No hay registros en esta cola con los filtros actuales.</td></tr><?php endif ?></tbody></table></div>
<?php require __DIR__ . '/pager.php'; ?></div></div>
