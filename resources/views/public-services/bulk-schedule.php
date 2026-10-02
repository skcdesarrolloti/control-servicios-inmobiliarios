<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
$e=[SCM\Modules\Pending\PublicServicesDocument::class,'e'];
?>
<details class="!sp-mx-4 !sp-mb-4 !sp-border !sp-border-solid !sp-border-slate-200 !sp-rounded-lg !sp-p-3" data-services-bulk-panel>
<summary class="<?= UI::SECONDARY ?> !sp-cursor-pointer"><?= UI::icon('calendar') ?>Programar seleccionados <span data-services-selected-count>0</span></summary>
<p class="!sp-text-xs !sp-text-service-muted">Escoge mes y año para la próxima revisión. Se conserva el día cuando existe en ese mes y la fecha real de última revisión. La selección incluye otras páginas del listado.</p>
<div class="!sp-flex !sp-flex-wrap !sp-gap-3 !sp-items-end"><label><span class="<?= UI::LABEL ?>">Mes y año de destino</span><input type="month" class="<?= UI::INPUT ?>" min="<?= $e(date('Y-m')) ?>" value="<?= $e(date('Y-m')) ?>" data-services-target-month></label><label class="!sp-flex-1"><span class="<?= UI::LABEL ?>">Motivo</span><input type="text" class="<?= UI::INPUT ?>" maxlength="1000" placeholder="Reorganización de revisiones" data-services-schedule-reason></label><button type="button" class="<?= UI::PRIMARY ?>" data-services-schedule-save>Confirmar programación</button></div>
<div class="!sp-flex !sp-flex-wrap !sp-gap-2 !sp-mt-3"><button type="button" class="<?= UI::SECONDARY ?>" data-services-select-all-results>Seleccionar todos los resultados</button><button type="button" class="<?= UI::SECONDARY ?>" data-services-select-clear>Limpiar selección</button></div>
<p class="!sp-text-xs !sp-text-rose-700" data-services-schedule-error role="alert" hidden></p></details>
