<?php
use SCM\Modules\Pending\PreventiveWorkspace as Workspace;
use SCM\Modules\Pending\PublicServicesUi as UI;
$e = [SCM\Modules\Pending\PublicServicesDocument::class, 'e'];
$months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
?>
<div class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-preventive-ui>
  <header class="<?= UI::CARD ?> !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">
    <div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200"><?= UI::icon('check', '!sp-w-6 !sp-h-6') ?></span><div><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">Revisión Preventiva</h2><p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">Contratos entregados sin revisión en <?= (int) date('Y') ?> · Casos, citas, informes y envíos</p></div></div>
    <div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong id="spp-kpi-count" class="!sp-text-xl"><?= (int) $count ?></strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Contratos pendientes</p></div>
  </header>
  <?= Workspace::tabs($count) ?>
  <section data-preventive-section="pending" class="!sp-space-y-5">
    <div id="spp_kpis" class="!sp-grid !sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4"><?= $view->renderPreventivasKpis($count, $corte, $items) ?></div>
    <div class="<?= UI::CARD ?> !sp-p-5">
      <div class="!sp-flex !sp-justify-between !sp-items-center !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide"><?= UI::icon('filter') ?>Filtros de búsqueda avanzada</h3><span class="!sp-text-[10px] !sp-text-service-muted" data-preventive-filter-count><?= (int) $count ?> contratos encontrados</span></div>
      <form method="post" autocomplete="off" id="spp_form">
        <div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-6 !sp-gap-4">
          <label><span class="<?= UI::LABEL ?>">Mes de vencimiento</span><select class="<?= UI::INPUT ?>" id="spp_mes" name="spp_mes"><option value="0">Todos</option><?php foreach ($months as $m => $name): ?><option value="<?= $m ?>" <?= (int) ($filters['mes'] ?? 0) === $m ? 'selected' : '' ?>><?= $name ?></option><?php endforeach ?></select></label>
          <?php foreach (['inmueble' => ['Inmueble', 'Ej: 10156'], 'propietario' => ['Propietario', 'Buscar por nombre...'], 'arrendatario' => ['Arrendatario', 'Buscar por nombre...'], 'contrato' => ['Contrato', 'Código contrato...'], 'caso' => ['Caso', 'Ej: 10368']] as $key => $field): ?>
          <label><span class="<?= UI::LABEL ?>"><?= $field[0] ?></span><input type="text" class="<?= UI::INPUT ?>" id="spp_<?= $key ?>" name="spp_<?= $key ?>" value="<?= $e($filters[$key === 'caso' ? 'id_ticket' : $key] ?? '') ?>" placeholder="<?= $field[1] ?>"></label>
          <?php endforeach ?>
        </div>
        <div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5"><button class="<?= UI::PRIMARY ?>" type="submit"><?= UI::icon('filter') ?>Filtrar contratos</button><button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-cursor-pointer" type="button" data-pending-clear="spp_">Limpiar filtros</button><span class="scm-spinner" id="spp_spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span></div>
      </form>
    </div>
    <div id="spp_table"><?= $view->renderPreventivasTable($items) ?></div>
  </section>
  <?php foreach (array_keys(Workspace::TABS) as $section): if ($section === 'pending') continue; ?>
  <section data-preventive-section="<?= $section ?>" hidden><div data-preventive-workspace-content="<?= $section ?>"></div></section>
  <?php endforeach ?>
</div>
