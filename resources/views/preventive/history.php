<?php
use SCM\Modules\Pending\PreventiveWorkspace as Workspace;
use SCM\Modules\Pending\PublicServicesUi as UI;
$e = [SCM\Modules\Pending\PublicServicesDocument::class, 'e'];
$k = $kpis + ['total' => 0, 'month' => 0, 'sent' => 0, 'damages' => 0];
$pct = static fn(int $value): string => $k['total'] > 0 ? round($value * 100 / $k['total']) . '%' : '0%';
$cards = [
  ['Revisiones realizadas', $k['total'], 'Filtro actual', $k['month'] . ' este mes', 'refresh', '!sp-text-service-navy'],
  ['Enviadas', $k['sent'], $pct($k['sent']), 'Informe enviado a propietario o arrendatario', 'check', '!sp-text-emerald-700'],
  ['Sin enviar', $k['total'] - $k['sent'], $pct($k['total'] - $k['sent']), 'Pendientes de enviar al destinatario', 'document', '!sp-text-amber-600'],
  ['Con daños', $k['damages'], $pct($k['damages']), 'Revisiones que encontraron daños', 'alert', '!sp-text-rose-600'],
];
$th = '!sp-px-4 !sp-py-3 !sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-semibold !sp-text-service-muted !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
$td = '!sp-border-x-0 !sp-border-y-0 !sp-px-4 !sp-py-4';
?>
<div class="!sp-font-sans !sp-text-service-navy !sp-space-y-5" data-preventive-history>
<div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">
<?php foreach ($cards as $card): ?>
<div class="<?= UI::CARD ?> !sp-p-4"><div class="!sp-flex !sp-justify-between !sp-items-center"><span class="!sp-text-[10px] !sp-uppercase !sp-font-semibold <?= $card[5] ?>"><?= $e($card[0]) ?></span><span class="!sp-p-2 !sp-rounded-lg !sp-bg-slate-50"><?= UI::icon($card[4]) ?></span></div><div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2 !sp-mt-2"><strong class="!sp-text-2xl !sp-font-extrabold <?= $card[5] ?>"><?= $e($card[1]) ?></strong><span class="!sp-text-[9px] !sp-rounded !sp-border !sp-border-solid !sp-border-slate-200 !sp-px-1.5 !sp-py-0.5 <?= $card[5] ?>"><?= $e($card[2]) ?></span></div><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted"><?= $e($card[3]) ?></p></div>
<?php endforeach ?>
</div>
<div class="<?= UI::CARD ?> !sp-p-5"><header class="!sp-flex !sp-justify-between !sp-items-center !sp-gap-3 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 !sp-pb-3 !sp-mb-4"><h3 class="!sp-m-0 !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-flex !sp-gap-2 !sp-items-center"><?= UI::icon('filter') ?>Filtros de búsqueda de revisiones</h3><span class="!sp-text-[10px] !sp-text-service-muted">Refina tus resultados aplicando uno o más filtros</span></header>
<form data-preventive-workspace-form="history"><div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-3">
<?php foreach (['contrato' => 'Contrato', 'inmueble' => 'Inmueble SIMI', 'id_ticket' => 'Caso', 'arrendatario' => 'Arrendatario', 'propietario' => 'Propietario', 'from' => 'Desde', 'to' => 'Hasta'] as $key => $label): ?><label><span class="<?= UI::LABEL ?>"><?= $label ?></span><input class="<?= UI::INPUT ?>" name="<?= $key ?>" type="<?= in_array($key, ['from', 'to'], true) ? 'date' : 'text' ?>" value="<?= $e($input[$key] ?? '') ?>" placeholder="<?= in_array($key, ['contrato', 'inmueble', 'id_ticket'], true) ? 'Ej: 780' : 'Buscar por nombre...' ?>"></label><?php endforeach ?>
<label><span class="<?= UI::LABEL ?>">Envío</span><select class="<?= UI::INPUT ?>" name="envio"><?php foreach (['' => 'Todas', 'enviadas' => 'Enviadas', 'sin_enviar' => 'Sin enviar'] as $value => $label): ?><option value="<?= $value ?>" <?= ($input['envio'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label></div>
<div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-gap-3 !sp-mt-4"><div class="!sp-flex !sp-gap-2"><button type="submit" class="<?= UI::PRIMARY ?>"><?= UI::icon('filter') ?>Filtrar</button><button type="button" class="<?= UI::SECONDARY ?>" data-preventive-clear>Limpiar filtros</button></div><button type="button" class="<?= UI::SECONDARY ?>" data-preventive-export="revisiones-preventivas-pagina-actual" title="Exportar la página actual a CSV compatible con Excel"><?= UI::icon('download') ?>Exportar Excel</button></div>
<input type="hidden" name="order" value="<?= $e($input['order'] ?? 'desc') ?>"><input type="hidden" name="page" value="<?= (int) $page ?>"></form></div>
<div class="<?= UI::CARD ?>"><header class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-p-5 !sp-bg-slate-50/50"><div><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">Historial de revisiones preventivas</h3><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Mostrando <?= (int) $total ?> revisiones · Página <?= (int) $page ?> de <?= (int) $pages ?></p></div><label class="!sp-flex !sp-items-center !sp-gap-2 !sp-text-[10px] !sp-text-service-muted">Ordenar por: <select class="<?= UI::INPUT ?> !sp-w-44" data-preventive-order><option value="desc" <?= ($input['order'] ?? 'desc') === 'desc' ? 'selected' : '' ?>>Más recientes primero</option><option value="asc" <?= ($input['order'] ?? 'desc') === 'asc' ? 'selected' : '' ?>>Más antiguas primero</option></select></label></header>
<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-min-w-[1100px] !sp-border-collapse !sp-text-[10px]" data-preventive-table><thead class="!sp-bg-slate-50"><tr><?php foreach (['Revisión', 'Fecha', 'Contrato / Inmueble', 'Dirección / Arrendatario', 'Realizado por', 'Envío', 'Acciones'] as $label): ?><th class="<?= $th ?>"><?= $label ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($rows as $row): $id = (int) $row['_ID']; $url = Workspace::reviewUrl($id); $ticket = trim((string) ($row['id_ticket'] ?? '')); $sent = !empty($row['_scm_sent']); $sentTs = (int) ($row['fecha_envio'] ?? 0); $damages = in_array(mb_strtolower(trim((string) ($row['encontro_danos'] ?? ''))), ['si', 'sí'], true); ?>
<tr class="!sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100 hover:!sp-bg-slate-50">
<td class="<?= $td ?>"><span class="!sp-bg-slate-100 !sp-border !sp-border-solid !sp-border-slate-200 !sp-rounded !sp-px-2 !sp-py-1 !sp-font-bold">#<?= $id ?></span><?php if ($ticket !== ''): ?><small class="!sp-block !sp-mt-2 !sp-text-service-muted">Caso #<?= $e($ticket) ?></small><?php endif ?></td>
<td class="<?= $td ?> !sp-whitespace-nowrap"><?= UI::date($row['_scm_fecha'] ?? '') ?><?php if ($damages): ?><small class="!sp-block !sp-mt-1 !sp-text-rose-600 !sp-font-semibold">Con daños</small><?php endif ?></td>
<td class="<?= $td ?>"><strong class="!sp-block">#Contrato: <?= $e(($row['contrato'] ?? '') ?: 'Sin registrar') ?></strong><small class="!sp-text-service-muted">SIMI: <?= $e(($row['inmueble'] ?? '') ?: 'Sin registrar') ?></small></td>
<td class="<?= $td ?> !sp-max-w-[260px]"><span class="!sp-block"><?= $e($row['direccion'] ?? '') ?></span><small class="!sp-block !sp-mt-1 !sp-text-service-muted"><?= $e($row['arrendatario'] ?? '') ?></small></td>
<td class="<?= $td ?>"><div class="!sp-flex !sp-gap-2 !sp-items-center"><span class="!sp-p-2 !sp-bg-slate-100 !sp-rounded-full"><?= UI::icon('user', '!sp-w-3 !sp-h-3') ?></span><strong class="!sp-max-w-[150px]"><?= $e(($row['creador'] ?? '') ?: 'Sin registrar') ?></strong></div></td>
<td class="<?= $td ?> !sp-whitespace-nowrap"><?php if ($sent): ?><span class="!sp-rounded !sp-bg-emerald-50 !sp-text-emerald-700 !sp-px-2 !sp-py-1 !sp-font-semibold">Enviada<?= $sentTs > 0 ? ' · ' . UI::date($sentTs) : '' ?></span><?php else: ?><span class="!sp-rounded !sp-bg-amber-50 !sp-text-amber-900 !sp-px-2 !sp-py-1 !sp-font-semibold">Sin enviar</span><?php endif ?></td>
<td class="<?= $td ?>"><div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-2">
<button type="button" class="<?= UI::NAVY ?>" data-scm-open-iframe data-iframe-url="<?= $e($url) ?>" data-iframe-title="Revisión preventiva #<?= $id ?>"><?= UI::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') ?>Ver informe</button>
<?php if ($ticket !== '' && !$sent && $canSend): ?><button type="button" class="<?= UI::PRIMARY ?> !sp-py-2 !sp-text-[10px]" data-preventive-open-case data-preventive-send data-due-type="calendar_ticket" data-ticket-pk="<?= $e($ticket) ?>" data-id-revision-preventiva="<?= $id ?>">Enviar</button><?php endif ?>
<?php if ($ticket !== ''): ?><button type="button" class="<?= UI::SECONDARY ?> !sp-text-[10px]" data-preventive-open-case data-due-type="calendar_ticket" data-ticket-pk="<?= $e($ticket) ?>" data-id-revision-preventiva="<?= $id ?>">Ver caso</button><?php endif ?>
<button type="button" class="<?= UI::SECONDARY ?> !sp-text-[10px]" aria-label="Copiar enlace público" data-services-copy-url="<?= $e($url) ?>"><?= UI::icon('copy', '!sp-w-3 !sp-h-3') ?>Copiar enlace</button>
</div></td></tr>
<?php endforeach ?>
<?php if (!$rows): ?><tr><td colspan="7" class="!sp-p-8 !sp-text-center !sp-text-service-muted">No hay revisiones preventivas con estos filtros.</td></tr><?php endif ?></tbody></table></div>
<?php require __DIR__ . '/pager.php'; ?></div></div>
