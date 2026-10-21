<?php

declare(strict_types=1);

// Recibo de inmuebles creado por un funcionario: Crear nuevo caso y Contratos usan el flujo con el aviso previo de revisión antes de la desocupación.
$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/src/App/Concerns/HandlesTicketWorkflowActions.php');
$panel = (string) file_get_contents($root . '/src/App/Concerns/HandlesPanelCaseCreation.php');
$app = (string) file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$runtime = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');
$create = (string) file_get_contents($root . '/public/assets/js/admin-case-create.js');
$notifications = (string) file_get_contents($root . '/src/Modules/Pending/Concerns/PendingNotificationsAndDatesConcern.php');
$creation = (string) file_get_contents($root . '/src/Modules/Pending/Concerns/AdministrativeTicketCreationConcern.php');
$whatsapp = (string) file_get_contents($root . '/src/Support/PanelCaseNotifications.php');

$checks = [
  'contract modal routes receipt topic to the receipt flow' => str_contains($workflow, "\$this->isReceiptTopic(\$input['tema_ayuda'] ?? '')\n      ? \$this->createManualReceiptCase("),
  'panel create routes receipt topic to the receipt flow' => str_contains($panel, "? \$this->createPanelReceiptCase(\$service)"),
  'receipt requires the letter and notifies employee, tenant and internal event' => str_contains($workflow, "'require_receipt_letter' => true,")
    && str_contains($workflow, "'internal_notification_action' => 'contrato_recibo_manual',")
    && str_contains($workflow, "['empleado', 'solicitante', 'admin']")
    && str_contains($workflow, "'solicitante_tipo' => 'arrendatario',"),
  'receipt is not duplicated for the same contract end date' => str_contains($workflow, '$existing = $this->contractEndingReceiptTicketId($contract, $endValue);'),
  'receipt asks for the delivery date when the contract has none' => str_contains($workflow, "'fecha_entrega_recibo',")
    && str_contains($workflow, 'indica la fecha prevista de recibo para la carta.')
    && str_contains($workflow, "'fecha_final_contrato' => \$endValue,")
    && str_contains($create, 'name="fecha_entrega_recibo"') && str_contains($runtime, 'name="fecha_entrega_recibo"'),
  'tenant gets its own receipt email without the internal case link' => str_contains($notifications, "\$jobSubject = \SCM\Modules\Pending\ReceiptNoticeDocument::TITLE . ' - Contrato '")
    && str_contains($notifications, '$this->receiptTenantEmailContent($ticketPayload)'),
  'receipt sends WhatsApp to the same recipients without blocking the case' => str_contains($creation, '->enqueueReceiptWhatsApp($ticketId, $ticketPayload,')
    && str_contains($whatsapp, "'label' => 'Responsable'") && str_contains($whatsapp, "'label' => 'Arrendatario'")
    && str_contains($whatsapp, 'InternalNotificationRecipients::contactsForAction($this->db, $internalAction)'),
  'tenant WhatsApp uses its own template with the notice PDF' => str_contains($whatsapp, "RECEIPT_TENANT_TEMPLATE = 'scm_aviso_revision_desocupacion_v1'")
    && str_contains($whatsapp, "['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['link' => \$letterUrl")
    && is_file($root . '/docs/crear-plantilla-whatsapp-aviso-revision-desocupacion.md'),
  'contract modal fills the receipt fields without the notice' => !str_contains($runtime, 'data-scm-admin-ticket-receipt-hint')
    && str_contains($runtime, 'asunto: "Recibo de inmueble contrato #"') && str_contains($runtime, 'department.value = "Servicio al arrendatario"'),
  'manual receipt internal event is configurable' => str_contains($app, "'contrato_recibo_manual' => ["),
  'contract modal always offers the receipt topic' => str_contains($runtime, 'if (!isPreventiva && topicOptions.indexOf("Recibo de inmuebles") < 0) topicOptions.push("Recibo de inmuebles");'),
  'panel form fills the receipt fields and hides optional notices' => !str_contains($create, 'data-receipt-hint')
    && str_contains($create, "asunto: 'Recibo de inmueble contrato #'") && str_contains($create, "form.elements.departamento.value = 'Servicio al arrendatario'")
    && str_contains($create, "form.elements.has_attachments.value = 'No';")
    && str_contains($create, "if (isReceipt && tenant && !input.checked) { input.checked = true;") && str_contains($create, 'input.disabled = isReceipt && !tenant;'),
  'unchecking Arrendatario skips the tenant email and WhatsApp' => str_contains($panel, "'notify_tenant' => in_array('arrendatario',")
    && str_contains($workflow, "\$notifyTenant ? ['empleado', 'solicitante', 'admin'] : ['empleado', 'admin']")
    && str_contains($whatsapp, "if (\$notifyTenant) \$people[] = ['label' => 'Arrendatario'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
  echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
}
exit($failed === [] ? 0 : 1);
