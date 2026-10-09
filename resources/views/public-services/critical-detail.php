<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
use SCM\Modules\Pending\PublicServicesDocument as Document;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
$e=[Document::class,'e'];
$labels=['pending'=>'Caso abierto','closed'=>'Caso cerrado','reported'=>'Antecedente histórico','verified'=>'Antecedente histórico cerrado'];
?>
<div class="!sp-font-sans !sp-text-service-navy !sp-space-y-4" data-services-critical-detail>
<h2 class="!sp-text-lg !sp-font-bold">Caso crítico · revisión #<?= (int)$case['review_id'] ?></h2>
<div class="!sp-bg-amber-50 !sp-rounded !sp-p-4 !sp-text-sm"><strong><?= $e($labels[$case['status']]??$case['status']) ?></strong><p>Plazo de atención del caso: <strong>72 horas</strong>. Fecha límite: <strong><?= date('d/m/Y H:i',(int)$case['deadline_at']) ?> (Colombia)</strong>. <?= $case['status']==='pending'&&time()>(int)$case['deadline_at']?'Plazo vencido.':'' ?></p></div>
<p class="!sp-text-xs">Contrato #<?= $e($payload['contract']['contrato']) ?> · <?= $e($payload['contract']['direccion']) ?><br>Arrendatario: <?= $e($payload['contract']['arrendatario']) ?></p>
<div class="!sp-flex !sp-flex-wrap !sp-gap-2"><button type="button" class="<?= UI::NAVY ?>" data-critical-preview="<?= $e(Document::url((int)$case['review_id'])) ?>" data-iframe-title="Revisión crítica">Ver revisión y actas</button><button type="button" class="<?= UI::SECONDARY ?>" data-services-copy-url="<?= $e(Critical::url((int)$case['review_id'])) ?>">Copiar enlace de revisión</button></div>
<iframe hidden data-critical-preview-frame title="Vista previa de revisión o comprobante" class="scm-critical-evidence-preview"></iframe><h3 class="!sp-font-bold !sp-text-sm">Soportes históricos conservados</h3>
<?php if(!$payments): ?><p class="!sp-text-xs">No hay soportes históricos. La atención y las respuestas se registran en el caso.</p><?php endif ?>
<?php foreach($payments as $payment): ?><section class="!sp-border !sp-border-slate-200 !sp-rounded !sp-p-3 !sp-text-xs"><strong>Comprobante #<?= (int)$payment['id'] ?> · <?= date('d/m/Y H:i',(int)$payment['created_at']) ?></strong><p><?= $e($payment['note']) ?></p><button type="button" class="<?= UI::SECONDARY ?>" data-critical-preview="<?= $e(Critical::evidenceUrl((int)$payment['id'])) ?>" data-iframe-title="Comprobante de pago">Ver PDF aquí</button></section><?php endforeach ?>
<details><summary class="!sp-text-xs !sp-cursor-pointer">Trazabilidad y estado de integraciones</summary><ul class="!sp-text-xs !sp-space-y-2">
<?php foreach($jobs as $job): ?><li><?= $e($job['kind'].' · '.$job['status'].' · intentos '.$job['attempts']) ?> <?= $e($job['last_error']??'') ?></li><?php endforeach ?>
<?php foreach($audit as $row): ?><li><?= date('d/m/Y H:i',(int)$row['created_at']) ?> · <?= $e($row['action'].' · '.$row['actor']) ?> · <?= $e($row['details_json']) ?></li><?php endforeach ?>
</ul></details><p class="!sp-text-xs !sp-text-service-muted">Realizado por: <?= $e($payload['employee']['nombre']) ?>. El caso conserva la revisión y las actas originales. Su gestión y cierre se realizan en Servicios inmobiliarios.</p>
</div>
