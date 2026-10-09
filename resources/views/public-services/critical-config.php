<?php
use SCM\Modules\Pending\PublicServicesUi as UI;
use SCM\Modules\Pending\PublicServicesDocument as Document;
use SCM\Modules\Pending\PublicServicesCritical as Critical;
$e=[Document::class,'e'];
?>
<details class="<?= UI::CARD ?> !sp-mt-5 !sp-font-sans !sp-p-5" data-services-critical-config><summary class="!sp-font-semibold !sp-cursor-pointer">Seguimiento crítico · WhatsApp, correo y Google Calendar</summary>
<form class="!sp-mt-4 !sp-space-y-4" data-services-critical-config-form>
<p class="!sp-text-xs !sp-leading-relaxed">Cada revisión crítica crea un caso con tema <strong>Servicio publico critico</strong>, asignado inicialmente al creador, y lo registra en el reporte del inmueble. El caso vence a las <strong>72 horas desde la revisión</strong>. El creador, el arrendatario y los funcionarios configurados reciben la revisión y las actas. Los funcionarios de esta lista reciben además el recordatorio interno y, si tienen cuenta conectada, la sincronización con Google Calendar. La atención y el cierre se gestionan desde el caso.</p>
<?php foreach([Critical::EVENT=>'Revisión crítica y caso · WhatsApp, correo y vencimiento en calendario'] as $key=>$label): ?>
<fieldset class="!sp-rounded-lg !sp-border !sp-border-slate-200 !sp-p-3"><legend class="<?= UI::LABEL ?>"><?= $e($label) ?></legend><div class="!sp-grid sm:!sp-grid-cols-2 !sp-gap-2">
<?php foreach($contacts as $r): ?><label class="!sp-flex !sp-gap-2 !sp-items-center !sp-text-xs"><input type="checkbox" name="<?= $key ?>[]" value="<?= $e($r['id']) ?>" <?= in_array((int)$r['id'],array_map('intval',(array)($events[$key]??[])),true)?'checked':'' ?>><span><?= $e($r['name']) ?><small class="!sp-block !sp-text-service-muted"><?= $e($r['cargo']) ?><?php if($key===Critical::EVENT && in_array((string)$r['employee_id'], $googleEmployeeIds??[], true)): ?> · Google conectado (se agenda si lo seleccionas)<?php endif ?></small></span></label><?php endforeach ?>
</div></fieldset><?php endforeach ?>
<div class="!sp-grid sm:!sp-grid-cols-3 !sp-gap-3"><?php foreach(['whatsapp_template'=>'Plantilla de revisión crítica y caso','language'=>'Idioma de Meta'] as $key=>$label): ?><label class="<?= UI::LABEL ?>"><?= $label ?><input class="<?= UI::INPUT ?>" name="<?= $key ?>" value="<?= $e($config[$key]) ?>" placeholder="<?= $key==='language'?'es':'scm_servicios_revision_critica' ?>"></label><?php endforeach ?></div>
<label class="!sp-flex !sp-items-center !sp-gap-2 !sp-text-xs"><input type="checkbox" name="whatsapp_enabled" value="1" <?= $config['whatsapp_enabled']?'checked':'' ?>>Activar WhatsApp: la plantilla de revisión crítica está aprobada en Meta</label>
<p class="!sp-text-xs">Los avisos pendientes se reintentan después de activar las plantillas. Las notificaciones y sus intentos se consultan en la cola compartida; los errores de calendario y configuración aparecen en el seguimiento.</p>
<details><summary class="!sp-text-xs !sp-cursor-pointer">Texto y estructura para crear las plantillas en Meta</summary><div class="!sp-text-xs !sp-leading-relaxed !sp-p-3 !sp-bg-slate-50">
<p>Configura <strong>una plantilla nueva</strong> con encabezado Documento (PDF), cinco variables de cuerpo y un botón URL dinámico <strong>Ver revisión</strong>. Base: <?= $e($config['button_base']) ?>{{1}}. El servidor añade la ruta firmada de la revisión. Las plantillas anteriores de pago ya no se usan; debes configurar y activar esta plantilla expresamente.</p>
<p><strong>Nombre sugerido: scm_servicios_revision_critica</strong></p>
<pre class="!sp-whitespace-pre-wrap">Hola {{1}}.
SKC SuCasa Inmobiliaria registró una revisión de servicios públicos con resultado crítico para el contrato #{{2}}.
Detalles de la revisión y del caso: {{3}}
Se creó un caso con el tema Servicio publico critico para gestionar esta situación. Su plazo de atención es de 72 horas desde el registro de la revisión. Vence: {{4}}.
Revisión realizada por: {{5}}.
Adjuntamos el acta. Consulta los servicios revisados y las actas con el botón Ver revisión.</pre>
<p>Variables: nombre del destinatario, contrato, resumen con número de caso y servicios, vencimiento del caso y creador. Botón: <strong>Ver revisión</strong>.</p>
<p>La aprobación y categoría de las plantillas se tramitan en Meta. No se envían mensajes reales desde esta pantalla.</p>
</div></details>
<button class="<?= UI::PRIMARY ?>" type="submit">Guardar configuración</button><p role="status" data-critical-config-status></p>
</form></details>
