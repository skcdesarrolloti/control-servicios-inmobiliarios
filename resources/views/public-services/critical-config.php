<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
use SCM\Modules\Pending\PublicServicesDocument as Document;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
$e=[Document::class,'e'];
?>
<details class="<?= UI::CARD ?> !sp-mt-5 !sp-font-sans !sp-p-5" data-services-critical-config><summary class="!sp-font-semibold !sp-cursor-pointer">Seguimiento crítico · WhatsApp, correo y Google Calendar</summary>
<form class="!sp-mt-4 !sp-space-y-4" data-services-critical-config-form>
<p class="!sp-text-xs !sp-leading-relaxed">Cada revisión con mora superior a 90 días tiene un plazo de <strong>72 horas desde su registro</strong>. El creador y el arrendatario reciben el requerimiento. Selecciona los funcionarios internos y, por separado, las personas cuya agenda recibirá el recordatorio. Cada persona debe tener conectada su cuenta Google en el calendario.</p>
<?php foreach([Critical::EVENT=>'Aviso de revisión crítica · WhatsApp y correo',Critical::RESPONSE_EVENT=>'Respuesta del arrendatario · WhatsApp y correo (también se avisa a la lista anterior y al creador)',Critical::CALENDAR_EVENT=>'Recordatorio en calendario y Google Calendar'] as $key=>$label): ?>
<fieldset class="!sp-rounded-lg !sp-border !sp-border-slate-200 !sp-p-3"><legend class="<?= UI::LABEL ?>"><?= $e($label) ?></legend><div class="!sp-grid sm:!sp-grid-cols-2 !sp-gap-2">
<?php foreach($contacts as $r): ?><label class="!sp-flex !sp-gap-2 !sp-items-center !sp-text-xs"><input type="checkbox" name="<?= $key ?>[]" value="<?= $e($r['id']) ?>" <?= in_array((int)$r['id'],array_map('intval',(array)($events[$key]??[])),true)?'checked':'' ?>><span><?= $e($r['name']) ?><small class="!sp-block !sp-text-service-muted"><?= $e($r['cargo']) ?></small></span></label><?php endforeach ?>
</div></fieldset><?php endforeach ?>
<div class="!sp-grid sm:!sp-grid-cols-3 !sp-gap-3"><?php foreach(['whatsapp_template'=>'Plantilla de acta crítica','response_template'=>'Plantilla de pago reportado','language'=>'Idioma de Meta'] as $key=>$label): ?><label class="<?= UI::LABEL ?>"><?= $label ?><input class="<?= UI::INPUT ?>" name="<?= $key ?>" value="<?= $e($config[$key]) ?>" placeholder="<?= $key==='language'?'es_CO':($key==='whatsapp_template'?'scm_servicios_critico_72h':'scm_servicios_pago_reportado') ?>"></label><?php endforeach ?></div>
<label class="!sp-flex !sp-items-center !sp-gap-2 !sp-text-xs"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $config['whatsapp_enabled']?'checked':'' ?>>Activar WhatsApp: las dos plantillas ya están aprobadas en Meta</label>
<p class="!sp-text-xs">Los avisos pendientes se reintentan después de activar las plantillas. Las notificaciones y sus intentos se consultan en la cola compartida; los errores de calendario y configuración aparecen en el seguimiento.</p>
<details><summary class="!sp-text-xs !sp-cursor-pointer">Texto y estructura para crear las plantillas en Meta</summary><div class="!sp-text-xs !sp-leading-relaxed !sp-p-3 !sp-bg-slate-50">
<p>Las dos plantillas llevan <strong>encabezado Documento (PDF)</strong>, cinco variables de cuerpo y <strong>un botón URL dinámico</strong>. Base del botón: <?= $e($config['button_base']) ?>{{1}}. El sistema proporciona la ruta y parámetros firmados como sufijo.</p>
<p><strong>scm_servicios_critico_72h</strong><br>Hola {{1}}. SKC SuCasa Inmobiliaria informa una revisión crítica del contrato #{{2}}.<br>{{3}}<br>El plazo máximo de pago es de 72 horas. Fecha límite: {{4}}.<br>Realizado por: {{5}}.<br>Adjuntamos el acta. Reporta tu pago con el botón.<br><strong>Botón: Realicé el pago</strong></p>
<p><strong>scm_servicios_pago_reportado</strong><br>Hola {{1}}. Recibimos un comprobante del contrato #{{2}}.<br>{{3}}<br>Fecha límite del requerimiento: {{4}}.<br>Creador de la revisión: {{5}}.<br>El pago está pendiente de verificación. Adjuntamos el soporte.<br><strong>Botón: Ver revisión</strong></p>
<p>La aprobación y categoría de las plantillas se tramitan en Meta. No se envían mensajes reales desde esta pantalla.</p>
</div></details>
<button class="<?= UI::PRIMARY ?>" type="submit">Guardar configuración</button><p role="status" data-critical-config-status></p>
</form></details>
