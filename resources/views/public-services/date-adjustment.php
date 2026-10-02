<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
$e=[SCM\Modules\Pending\PublicServicesDocument::class,'e'];
$lastTs=(int)($item['ultima']??0);
?>
<details class="!sp-mt-2" data-public-services-date-adjustment data-contract-id="<?= $e($pk) ?>" data-contract-code="<?= $e($code) ?>" data-request-token="<?= $e($item['adjustment_token']??'') ?>">
<summary class="<?= UI::SECONDARY ?> !sp-cursor-pointer"><?= UI::icon('calendar') ?>Ajustar fecha</summary>
<div class="<?= UI::CARD ?> !sp-p-3 !sp-mt-2 !sp-w-80 !sp-max-w-full">
<label><span class="<?= UI::LABEL ?>">Última revisión</span><input type="date" class="<?= UI::INPUT ?>" value="<?= $lastTs>0?$e(date('Y-m-d',$lastTs)):'' ?>" max="<?= $e(date('Y-m-d')) ?>" data-services-adjust-date></label>
<label class="!sp-block !sp-mt-3"><span class="<?= UI::LABEL ?>">Motivo del ajuste</span><input type="text" class="<?= UI::INPUT ?>" maxlength="1000" placeholder="Reorganización del atraso" data-services-adjust-reason></label>
<p class="!sp-text-xs !sp-my-3" data-services-adjust-preview aria-live="polite"><?= $lastTs>0?'Próxima revisión: '.$e(date('d/m/Y',SCM\Modules\Pending\PublicServicesSchedule::next($lastTs))):'' ?></p>
<p class="!sp-text-[10px] !sp-text-service-muted">Ajusta la programación y deja historial. Las revisiones y actas emitidas conservan sus fechas.</p>
<div class="!sp-text-xs !sp-text-rose-700" data-services-adjust-error role="alert" hidden></div>
<button type="button" class="<?= UI::PRIMARY ?> !sp-mt-3" data-services-adjust-save>Guardar ajuste</button>
</div></details>
