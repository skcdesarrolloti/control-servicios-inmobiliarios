<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
use SCM\Modules\Pending\PublicServicesWorkspace;
$e = [SCM\Modules\Pending\PublicServicesDocument::class, 'e'];
$months = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
?>
<div class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-services-ui>
  <header class="<?= UI::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
    <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?= UI::icon('document','!sp-w-6 !sp-h-6') ?></span><div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy" data-services-title>Servicios Públicos — Pendientes</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Contratos entregados · Revisión trimestral desde la fecha real · Actas e historial</p></div></div>
    <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-2"><button type="button" class="<?= UI::SECONDARY ?> [&[hidden]]:!sp-hidden" data-services-export="pending" data-services-header-pending title="Exportar el resultado filtrado a CSV compatible con Excel"><?= UI::icon('download') ?>Exportar Excel</button><button type="button" class="<?= UI::NAVY ?> [&[hidden]]:!sp-hidden" data-services-refresh data-services-header-pending><?= UI::icon('refresh') ?>Sincronizar revisiones</button><div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong id="rsp-kpi-count" class="!sp-text-xl"><?= $e($count) ?></strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Contratos pendientes</p></div></div>
  </header>
  <?= PublicServicesWorkspace::tabs($count) ?>
  <section data-services-section="pending" class="!sp-space-y-5">
    <div id="rsp_kpis" class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4"><?= $view->renderServiciosPublicosKpis($count,$corte,$items,$configurationItems) ?></div>
    <div class="<?= UI::CARD ?> !sp-p-5">
      <div class="!sp-flex !sp-justify-between !sp-items-center !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide"><?= UI::icon('filter') ?>Filtros de búsqueda avanzada</h3><span class="!sp-text-[10px] !sp-text-service-muted" data-services-filter-count><?= $e($count) ?> contratos encontrados</span></div>
      <form method="post" autocomplete="off" id="rsp_form">
        <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-5 !sp-gap-4">
          <label><span class="<?= UI::LABEL ?>">Mes de vencimiento</span><select class="<?= UI::INPUT ?>" id="rsp_mes" name="rsp_mes"><option value="0">Todos</option><?php foreach($months as $m=>$name): ?><option value="<?= $m ?>" <?= (int)($filters['mes']??0)===$m?'selected':'' ?>><?= $name ?></option><?php endforeach ?></select></label>
          <?php foreach(['inmueble'=>['Inmueble','Ej: 10156'],'propietario'=>['Propietario','Buscar por nombre...'],'arrendatario'=>['Arrendatario','Buscar por nombre...'],'contrato'=>['Contrato','Código contrato...']] as $key=>$field): ?>
          <label><span class="<?= UI::LABEL ?>"><?= $field[0] ?></span><input type="text" class="<?= UI::INPUT ?>" id="rsp_<?= $key ?>" name="rsp_<?= $key ?>" value="<?= $e($filters[$key]??'') ?>" placeholder="<?= $field[1] ?>"></label>
          <?php endforeach ?>
        </div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-mt-5"><div class="!sp-flex !sp-gap-3 !sp-items-center"><button class="<?= UI::PRIMARY ?>" type="submit"><?= UI::icon('filter') ?>Filtrar contratos</button><button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="button" data-pending-clear="rsp_">Limpiar filtros</button><span class="scm-spinner" id="rsp_spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span></div><div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-text-service-muted">Filtro rápido: <button type="button" class="<?= UI::SECONDARY ?> aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white" aria-pressed="false" data-services-quick="overdue">Vencidos</button><button type="button" class="<?= UI::SECONDARY ?> aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white" aria-pressed="false" data-services-quick="week">Esta semana</button><button type="button" class="<?= UI::SECONDARY ?> aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white" aria-pressed="false" data-services-quick="next">Próximo mes</button></div></div>
      </form>
    </div>
    <div id="rsp_table"><?= $view->renderServiciosPublicosTable($items,$configurationItems) ?></div>
  </section>
  <section data-services-section="templates" hidden><div data-services-workspace-content="templates"></div></section>
  <section data-services-section="history" hidden><div data-services-workspace-content="history"></div></section>
</div>
