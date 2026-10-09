<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
use SCM\Modules\Pending\PublicServicesDocument as Document;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
$e = [Document::class,'e'];
$labels = ['pending'=>'Caso abierto','closed'=>'Caso cerrado','legacy'=>'Antecedente histórico'];
?>
<div class="!sp-font-sans !sp-text-service-navy !sp-space-y-5" data-services-critical-list>
  <div class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-3">
    <?php foreach(['pending'=>'Casos abiertos','closed'=>'Casos cerrados','overdue'=>'Casos vencidos','legacy'=>'Antecedentes históricos'] as $key=>$label): ?>
    <article class="<?= UI::CARD ?> !sp-p-4"><p class="<?= UI::LABEL ?>"><?= $label ?></p><strong class="!sp-text-2xl !sp-font-bold"><?= (int)($stats[$key]??0) ?></strong></article>
    <?php endforeach ?>
  </div>
  <div class="<?= UI::CARD ?> !sp-p-5">
    <h3 class="!sp-m-0 !sp-mb-4 !sp-text-sm !sp-font-bold !sp-flex !sp-items-center !sp-gap-2"><?= UI::icon('filter') ?>Buscar seguimientos críticos</h3>
    <form data-services-critical-list-form>
      <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-3">
        <?php foreach(['contrato'=>'Contrato','inmueble'=>'Inmueble SIMI','arrendatario'=>'Arrendatario'] as $key=>$label): ?>
        <label><span class="<?= UI::LABEL ?>"><?= $label ?></span><input name="<?= $key ?>" class="<?= UI::INPUT ?>" value="<?= $e($input[$key]??'') ?>"></label>
        <?php endforeach ?>
        <label><span class="<?= UI::LABEL ?>">Estado del seguimiento</span><select name="status" class="<?= UI::INPUT ?>">
          <?php foreach(['open'=>'Casos abiertos','overdue'=>'Casos vencidos','closed'=>'Casos cerrados','legacy'=>'Antecedentes históricos','all'=>'Todos'] as $key=>$label): ?>
          <option value="<?= $key ?>" <?= ($input['status']??'open')===$key?'selected':'' ?>><?= $label ?></option>
          <?php endforeach ?>
        </select></label>
      </div>
      <div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mt-4"><button class="<?= UI::PRIMARY ?>" type="submit"><?= UI::icon('filter') ?>Filtrar</button><button class="<?= UI::SECONDARY ?>" type="button" data-services-critical-list-clear>Limpiar filtros</button><button class="<?= UI::SECONDARY ?>" type="button" data-services-critical-list-refresh><?= UI::icon('refresh') ?>Actualizar</button></div>
      <input type="hidden" name="page" value="<?= $page ?>">
    </form>
  </div>
  <div class="<?= UI::CARD ?>">
    <header class="!sp-p-5 !sp-bg-slate-50/50"><h3 class="!sp-m-0 !sp-text-sm !sp-font-bold">Servicios públicos en estado crítico</h3><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted"><?= $total ?> registros · Casos con plazo de atención de 72 horas desde la revisión · Colombia</p></header>
    <div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-min-w-[1050px] !sp-border-collapse !sp-text-xs" data-services-critical-table>
      <thead class="!sp-bg-slate-50"><tr><?php foreach(['Revisión / registro','Contrato / inmueble','Dirección / arrendatario','Servicios / deuda reportada','Fecha límite / estado','Acciones'] as $label): ?><th class="!sp-px-4 !sp-py-3 !sp-text-left !sp-text-[10px] !sp-uppercase !sp-font-semibold !sp-text-service-muted !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200"><?= $label ?></th><?php endforeach ?></tr></thead>
      <tbody><?php foreach($rows as $row):
        $payload = json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);
        $contract = $payload['contract'];
        $late = $row['status']==='pending' && time()>(int)$row['deadline_at'];
        $cell = '!sp-px-4 !sp-py-4 !sp-border-0 !sp-align-top';
      ?>
      <tr class="!sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100 hover:!sp-bg-slate-50">
        <td class="<?= $cell ?>"><strong>Revisión #<?= (int)$row['review_id'] ?></strong><?php if(!empty($row['ticket_id'])): ?><small class="!sp-block !sp-mt-1">Caso #<?= (int)$row['ticket_id'] ?></small><?php endif ?><small class="!sp-block !sp-mt-1 !sp-text-service-muted"><?= date('d/m/Y H:i',(int)$row['created_at']) ?></small></td>
        <td class="<?= $cell ?>"><strong>#<?= $e($contract['contrato']) ?></strong><small class="!sp-block !sp-mt-1 !sp-text-service-muted">SIMI: <?= $e($contract['inmueble']??$contract['id_inmueble']??'') ?></small></td>
        <td class="<?= $cell ?> !sp-max-w-[260px]"><?= $e($contract['direccion']) ?><small class="!sp-block !sp-mt-1 !sp-text-service-muted"><?= $e($contract['arrendatario']) ?></small></td>
        <td class="<?= $cell ?>"><?php foreach($payload['services'] as $service): ?><div class="!sp-mb-2"><strong><?= $e($service['display_label']??$service['label']) ?></strong><small class="!sp-block !sp-text-service-muted">Ref. <?= $e($service['account']) ?></small><span>$<?= number_format((int)$service['amount'],0,',','.') ?> COP</span></div><?php endforeach ?></td>
        <td class="<?= $cell ?>"><strong class="!sp-whitespace-nowrap"><?= date('d/m/Y H:i',(int)$row['deadline_at']) ?></strong><span class="!sp-block !sp-mt-2 !sp-font-semibold"><?= $e($labels[$row['status']]??$row['status']) ?></span><?php if($late): ?><span class="!sp-inline-flex !sp-items-center !sp-gap-1 !sp-mt-2 !sp-rounded !sp-px-2 !sp-py-1 !sp-bg-rose-50 !sp-text-rose-700"><?= UI::icon('alert','!sp-w-3 !sp-h-3') ?>Plazo vencido</span><?php endif ?></td>
        <td class="<?= $cell ?>"><div class="!sp-flex !sp-flex-wrap !sp-gap-2"><button type="button" class="<?= UI::NAVY ?>" data-services-critical-open="<?= (int)$row['review_id'] ?>"><?= UI::icon('eye','!sp-w-3 !sp-h-3 !sp-text-service-yellow') ?>Ver caso · 72 horas</button><button type="button" class="<?= UI::SECONDARY ?>" data-services-copy-url="<?= $e(Critical::url((int)$row['review_id'])) ?>">Copiar enlace de revisión</button></div></td>
      </tr><?php endforeach ?>
      <?php if(!$rows): ?><tr><td colspan="6" class="!sp-p-8 !sp-text-center !sp-text-service-muted">No hay seguimientos críticos con estos filtros.</td></tr><?php endif ?></tbody>
    </table></div>
    <footer class="!sp-p-4 !sp-bg-slate-50/50 !sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-3 !sp-text-xs !sp-text-service-muted"><span>Mostrando <?= $total?($page-1)*30+1:0 ?> a <?= min($page*30,$total) ?> de <?= $total ?></span><div class="!sp-flex !sp-items-center !sp-gap-2"><button type="button" class="<?= UI::SECONDARY ?>" data-services-critical-list-page="<?= $page-1 ?>" <?= $page<=1?'disabled':'' ?>>Anterior</button><span>Página <?= $page ?> de <?= $pages ?></span><button type="button" class="<?= UI::SECONDARY ?>" data-services-critical-list-page="<?= $page+1 ?>" <?= $page>=$pages?'disabled':'' ?>>Siguiente</button></div></footer>
  </div>
</div>
