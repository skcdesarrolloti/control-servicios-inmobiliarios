<?php
use SCM\Modules\Pending\PreventiveWorkspace as Workspace;
use SCM\Modules\Pending\PublicServicesUi as UI;
?>
<nav class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-mb-4 !sp-bg-slate-100 !sp-rounded-lg !sp-border-0 !sp-w-fit !sp-max-w-full !sp-font-sans" aria-label="Revisiones preventivas">
<?php foreach (Workspace::TABS as $key => $tab): ?>
<button type="button" class="sp-group !sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white aria-pressed:!sp-font-semibold focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow" data-preventive-tab="<?= $key ?>" aria-pressed="<?= $key === 'pending' ? 'true' : 'false' ?>"><?= UI::icon($tab[1], '!sp-w-3 !sp-h-3 group-aria-pressed:!sp-text-service-yellow') ?><span><?= $tab[0] ?></span><?php if ($key === 'pending' && $count !== null): ?><span class="!sp-rounded-full !sp-bg-slate-200 !sp-text-service-navy !sp-text-[8px] !sp-font-semibold !sp-px-1.5 !sp-py-0.5 group-aria-pressed:!sp-bg-white/20 group-aria-pressed:!sp-text-white" data-preventive-tab-count><?= max(0, (int) $count) ?></span><?php endif ?></button>
<?php endforeach ?>
</nav>
