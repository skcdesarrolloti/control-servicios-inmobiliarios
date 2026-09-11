<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$router = (string) file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
$handler = (string) file_get_contents($root . '/src/App/Concerns/HandlesMaintenanceActions.php');
$runtimeJs = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');
$adminJs = (string) file_get_contents($root . '/public/assets/js/scm-admin.js');
$adminCss = (string) file_get_contents($root . '/public/assets/css/admin/04-dashboard-pending.css');
$publicOrder = (string) file_get_contents($root . '/public/orden-publica.php');
$publicOrderCss = (string) file_get_contents($root . '/public/assets/css/public-order-response.css');
$workflow = (string) file_get_contents($root . '/src/Modules/ServiciosInmobiliarios/Concerns/WorkflowCommandsConcern.php');
$summaryPos = strpos($handler, "\$pdf->heading('Resumen económico');");
$ordersPos = strpos($handler, "\$pdf->heading('Órdenes de mantenimiento');");

$checks = [
  'app defines approve quote action' => str_contains($app, "AJAX_APPROVE_COTIZACION") && str_contains($app, "scm_aprobar_cotizacion_mantenimiento"),
  'router maps approve quote action' => str_contains($router, "AJAX_APPROVE_COTIZACION") && str_contains($router, "ajax_handler_approve_cotizacion_mantenimiento"),
  'runtime exposes approve quote action' => str_contains($dashboard, "'approve_cotizacion' => self::AJAX_APPROVE_COTIZACION"),
  'app defines native quote order actions' => str_contains($app, "AJAX_COTIZACION_ORDER_CONTEXT") && str_contains($app, "AJAX_COTIZACION_ORDER_SAVE") && str_contains($app, "AJAX_COTIZACION_ORDER_RESPONSE"),
  'router maps native quote order actions' => str_contains($router, "ajax_handler_cotizacion_order_context") && str_contains($router, "ajax_handler_cotizacion_order_save") && str_contains($router, "ajax_handler_cotizacion_order_response"),
  'runtime exposes native quote order actions' => str_contains($dashboard, "'cotizacion_order_context' => self::AJAX_COTIZACION_ORDER_CONTEXT") && str_contains($dashboard, "'cotizacion_order_save' => self::AJAX_COTIZACION_ORDER_SAVE") && str_contains($dashboard, "'cotizacion_order_response' => self::AJAX_COTIZACION_ORDER_RESPONSE"),
  'app defines native maintenance quote form actions' => str_contains($app, 'AJAX_COTIZACION_FORM_CONTEXT') && str_contains($app, 'AJAX_COTIZACION_SAVE') && str_contains($app, 'scm_guardar_cotizacion_mantenimiento'),
  'router maps native maintenance quote form actions' => str_contains($router, 'ajax_handler_cotizacion_mantenimiento_form_context') && str_contains($router, 'ajax_handler_cotizacion_mantenimiento_save'),
  'runtime exposes native maintenance quote form actions' => str_contains($dashboard, "'cotizacion_form_context' => self::AJAX_COTIZACION_FORM_CONTEXT") && str_contains($dashboard, "'cotizacion_save' => self::AJAX_COTIZACION_SAVE"),
  'quote cards render quick approval button' => str_contains($dashboard, 'data-scm-approve-cotizacion') && str_contains($dashboard, 'Marcar como aprobada'),
  'quote cards show state-changing actions only while pending' => str_contains($dashboard, '$cotizacionSinResponder ?') && !str_contains($dashboard, '$cotizacionFinalizada'),
  'quote card approve and delete actions use semantic colors' => str_contains($dashboard, 'scm-cotizacion-approve-action') && str_contains($dashboard, 'scm-cotizacion-delete-action'),
  'quote approve and delete popups use semantic confirm colors' => str_contains($runtimeJs, 'confirmButtonColor: "#16a34a"') && str_contains($runtimeJs, 'confirmButtonColor: "#b91c1c"'),
  'quote panel omits finalized buckets' => !str_contains($dashboard, "'label' => 'Finalizadas'") && !str_contains($dashboard, "'key' => 'finalizadas'") && !str_contains($handler, 'kpi_finalizadas'),
  'quote panel omits sent buckets' => !str_contains($dashboard, "'label' => 'Enviadas'") && !str_contains($dashboard, "'key' => 'enviadas'") && !str_contains($handler, 'kpi_enviadas'),
  'quote contact uses real responsible instead of contractual coordinator default' => str_contains($dashboard, 'cotizacion_responsable_contact') && str_contains($handler, 'cotizacion_responsable_contact') && !str_contains($dashboard, 'Coordinador contractual') && !str_contains($handler, 'Coordinador contractual'),
  'recipient quote view hides internal financial control' => str_contains($dashboard, 'if ($isFuncionario)') && str_contains($dashboard, 'Control financiero') && str_contains($handler, 'if ($isFuncionario)') && str_contains($handler, "heading('Control de saldos')"),
  'native quote damage title uses any affected area slot' => str_contains($dashboard, '$areaTitle = $areas ?') && !str_contains($dashboard, "\$damage['area_afectada_1'] ?? 'Da&ntilde;o evaluado'"),
  'frontend submits exact quote approval action' => str_contains($runtimeJs, 'actionApproveCotizacion') && str_contains($runtimeJs, 'data-scm-approve-cotizacion') && str_contains($runtimeJs, 'fd.append("id_cotizacion", approveCotizacionId)'),
  'backend updates exact quote by id' => str_contains($handler, 'ajax_handler_approve_cotizacion_mantenimiento') && str_contains($handler, "\$this->db->update(\$table, \$update, ['_ID' => \$cotizacionId])"),
  'backend synchronizes linked ticket state' => str_contains($handler, "'estado_cotizacion_mantenimiento' => 'Aprobada'") && str_contains($handler, "'estado_respuesta_cotizacion_mantenimiento' => 'Aprobada'"),
  'quote pdf places economic summary after maintenance orders' => is_int($summaryPos) && is_int($ordersPos) && $summaryPos > $ordersPos,
  'case popup uses the quote selector instead of legacy iframe' => str_contains($adminJs, 'data-scm-view-case-cotizaciones') && !str_contains($adminJs, 'Abrir cotizaci&oacute;n</button>'),
  'case popup only shows quote selector when a quote id or url exists' => str_contains($adminJs, 'if (!isPublicPqr && (cotizacionUrl || cotizacionId))') && !str_contains($adminJs, 'calendarTicketPk || btn.dataset.ticket || cotizacionUrl || cotizacionId'),
  'case cards use quote id found by ticket lookup' => str_contains((string) file_get_contents($root . '/src/Modules/ServiciosInmobiliarios/Concerns/TableRowsConcern.php'), "\$row['id_cotizacion_mantenimiento'] ?? \$row['cot_id']") && str_contains((string) file_get_contents($root . '/src/Modules/Pending/PendingView.php'), "\$ticket['id_cotizacion_mantenimiento'] ?? \$ticket['cot_id']"),
  'quote ajax supports exact ticket lookup from case popup' => str_contains($dashboard, 'fTicketExact') && str_contains($dashboard, "TRIM(COALESCE(c.`id_ticket`, '')) = ?"),
  'case popup loads all quotes for the case instead of using one quote id' => str_contains($adminJs, 'data-scm-view-case-cotizaciones') && str_contains($runtimeJs, 'loadCotizacionCardsByTicket') && str_contains($runtimeJs, 'scmqt_ticket_exact'),
  'case quote selector delegates actions to the selected quote card' => str_contains($runtimeJs, 'scm-case-cotizaciones-modal') && str_contains($runtimeJs, 'triggerCotizacionRootAction') && str_contains($runtimeJs, 'Las acciones se aplican sobre el número de cotización elegido.'),
  'case popup exposes create quote only for eligible maintenance cases' => str_contains($adminJs, 'caseCanCreateMaintenanceQuote') && str_contains($adminJs, 'data-scm-create-cotizacion') && str_contains($adminJs, 'prevEncontroDanos'),
  'maintenance case cards carry preventive damage flag' => str_contains((string) file_get_contents($root . '/src/Modules/ServiciosInmobiliarios/Concerns/TableRowsConcern.php'), 'data-prev-encontro-danos') && str_contains((string) file_get_contents($root . '/src/Views/GenericTicketsCardView.php'), 'data-prev-encontro-danos') && str_contains((string) file_get_contents($root . '/src/Modules/Pending/PendingView.php'), 'data-prev-encontro-danos'),
  'case quote selector keeps modal cards styled outside app shell' => str_contains($runtimeJs, 'scm-case-cotizaciones-list') && str_contains($runtimeJs, 'scm-case-cotizacion-card') && str_contains($adminCss, '.scm-case-cotizaciones-modal .scm-cotizacion-card') && str_contains($adminCss, '.scm-case-cotizaciones-modal .scm-case-work-btn'),
  'case quote selector reopens after child modal actions' => str_contains($runtimeJs, 'makeCaseCotizacionesReturn') && str_contains($runtimeJs, 'reopenCaseCotizacionesAfter') && str_contains($runtimeJs, '_scmCaseCotizacionesReturn') && str_contains($runtimeJs, 'responseReturnContext.reopen') && str_contains($runtimeJs, 'approveReturnContext.reopen') && str_contains($runtimeJs, 'deleteReturnContext.reopen'),
  'case quote selector preserves ticket attributes across repeated reopens' => str_contains($runtimeJs, '"data-ticket-pk"') && str_contains($runtimeJs, '"data-ticket"') && str_contains($runtimeJs, 'attributes: Object.keys(attrs).map'),
  'quote cards expose native edit and note actions' => str_contains($dashboard, 'data-scm-edit-cotizacion') && str_contains($dashboard, 'data-cotizacion-mode="edit"') && str_contains($dashboard, 'data-cotizacion-mode="note"') && !str_contains($dashboard, 'anadir-nota-a-cotizacion-de-mantenimiento'),
  'native quote popup renders repeaters and totals without iframe' => str_contains($runtimeJs, 'openMaintenanceQuoteForm') && str_contains($runtimeJs, 'renderCotizacionRepeaterRows') && str_contains($runtimeJs, 'syncMaintenanceQuoteTotals') && str_contains($runtimeJs, 'items_mano_json'),
  'native quote popup uses styled responsive form classes' => str_contains($runtimeJs, 'scm-maint-quote-form') && str_contains($runtimeJs, 'scm-maint-quote-section') && str_contains($adminCss, '.scm-maint-quote-form') && str_contains($adminCss, '.scm-maint-quote-row'),
  'backend saves quote modes and revision quote flag' => str_contains($handler, 'maintenance_quote_mode') && str_contains($handler, "'categoria_cotizacion' => \$category") && str_contains($handler, 'maintenance_quote_update_revision_flag') && str_contains($handler, "'tiene_cotizacion' => 'Si'"),
  'backend creates administrative report on first maintenance quote' => str_contains($handler, 'maintenance_quote_ensure_admin_report') && str_contains($handler, "jet_cct_reportes_administrativos") && str_contains($handler, "'id_cotizacion_mantenimiento' => (string) \$quoteId"),
  'backend enqueues configurable quote notifications' => str_contains($app, 'cotizacion_mantenimiento_guardada') && str_contains($handler, 'maintenance_quote_enqueue_saved_notifications') && str_contains($handler, "'source_module' => 'cotizaciones_mantenimiento'") && str_contains($handler, 'EmailQueue'),
  'backend loads quote glossary options from JetEngine glossaries' => str_contains($handler, 'maintenance_quote_glossary_options(612') && str_contains($handler, 'maintenance_quote_glossary_options(853') && str_contains($handler, 'correctiveReviewGlossaryOptions'),
  'backend validates quote support images before saving' => str_contains($handler, 'maintenance_quote_store_media') && str_contains($handler, 'handleImageUploadsDetailed') && str_contains($handler, 'supera 1.5 MB'),
  'approved quote cards expose native add order action' => str_contains($dashboard, '$cotizacionAprobada ?') && str_contains($dashboard, 'data-scm-add-cotizacion-order') && !str_contains($dashboard, 'anadir-orden-de-mantenimiento'),
  'approved quote cards block add order when active act exists' => str_contains($dashboard, '$hasActiveActa') && str_contains($dashboard, 'Orden bloqueada por acta') && str_contains($dashboard, 'cotizacion_satisfaction_act_info($id, trim((string) ($row[\'id_acta_satisfaccion\'] ?? \'\')), $ticket)'),
  'native add order popup loads context and saves without iframe' => str_contains($runtimeJs, 'openCotizacionOrderFormModal') && str_contains($runtimeJs, 'actionCotizacionOrderContext') && str_contains($runtimeJs, 'actionCotizacionOrderSave') && str_contains($runtimeJs, '[data-scm-add-cotizacion-order]'),
  'native order response popup updates pending orders without iframe' => str_contains($runtimeJs, 'openCotizacionOrderResponseModal') && str_contains($runtimeJs, 'actionCotizacionOrderResponse') && str_contains($runtimeJs, '[data-scm-respond-cotizacion-order]') && str_contains($dashboard, 'Responder orden'),
  'native order response popup uses semantic approve/reject colors' => str_contains($runtimeJs, 'scm-cotizacion-order-response-confirm') && str_contains($runtimeJs, 'is-success') && str_contains($runtimeJs, 'is-danger') && str_contains($adminCss, '.scm-cotizacion-order-response-confirm.is-success') && str_contains($adminCss, '.scm-cotizacion-order-response-confirm.is-danger'),
  'backend blocks maintenance orders when quote or case has active act' => str_contains($handler, 'maintenance_order_active_satisfaction_act') && str_contains($handler, 'ya tiene un acta de satisfaccion activa'),
  'backend prevents orders above available balance' => str_contains($handler, '$value > ($currentBalance + 0.01)') && str_contains($handler, 'El valor supera el saldo disponible'),
  'backend creates and responds orders through shared notification queue' => str_contains($handler, 'maintenance_order_enqueue_created_notifications') && str_contains($handler, 'maintenance_order_enqueue_response_notifications') && str_contains($handler, "new \\SCM\\Support\\EmailQueue") && str_contains($handler, "'source_module' => 'ordenes_mantenimiento'"),
  'internal notification settings include maintenance order events' => str_contains($app, 'orden_mantenimiento_creada') && str_contains($app, 'respuesta_orden_mantenimiento') && str_contains($app, 'Email interno en cola'),
  'backend accepts responses only for pending orders' => str_contains($handler, 'ajax_handler_cotizacion_order_response') && str_contains($handler, "Solo puedes responder una orden que este esperando respuesta.") && str_contains($handler, 'maintenance_order_insert_response_histories'),
  'order notification email uses signed public response link' => str_contains($handler, 'orden-publica.php') && str_contains($handler, 'maintenance_order_public_signature') && str_contains($handler, 'public_cotizacion_order_signature_valid'),
  'public order response page approves without panel login' => str_contains($publicOrder, 'public_respond_cotizacion_order') && str_contains($publicOrder, 'Respuesta de orden de mantenimiento') && str_contains($publicOrder, 'Aprobar orden') && str_contains($publicOrder, 'Desaprobar orden'),
  'public order response page has dedicated responsive styles' => str_contains($publicOrderCss, '.scm-order-public-shell') && str_contains($publicOrderCss, '.scm-order-public-button') && str_contains($publicOrderCss, '@media (max-width: 760px)'),
  'frontend warns before submitting order above balance' => str_contains($runtimeJs, 'value > balance') && str_contains($runtimeJs, 'El valor supera el saldo disponible'),
  'frontend validates quote order amount live' => str_contains($runtimeJs, 'validateOrderAmount') && str_contains($runtimeJs, 'data-scm-order-value-error') && str_contains($runtimeJs, 'confirmButton.disabled = isOver') && str_contains($adminCss, '.scm-cotizacion-order-balance.is-over') && str_contains($adminCss, '.scm-cotizacion-order-value-error'),
  'backend creates maintenance orders from approved quotes' => str_contains($handler, 'ajax_handler_cotizacion_order_save') && str_contains($handler, "jet_cct_ordenes") && str_contains($handler, 'maintenance_order_update_cotizacion_balance') && str_contains($handler, 'maintenance_order_insert_histories') && str_contains($handler, 'maintenance_order_save_provider'),
  'native add order popup uses styled form classes' => str_contains($runtimeJs, 'scm-cotizacion-order-form') && str_contains($runtimeJs, 'scm-cotizacion-order-grid') && str_contains($adminCss, '.scm-cotizacion-order-form') && str_contains($adminCss, '.scm-cotizacion-order-grid'),
  'ticket response carries exact quote id when available' => str_contains($adminJs, 'name="id_cotizacion"') && str_contains($adminJs, 'caseBtn.dataset.cotizacionId'),
  'standalone quote response carries exact quote id' => str_contains($runtimeJs, 'fd.append(') && str_contains($runtimeJs, '"id_cotizacion"') && str_contains($runtimeJs, 'responseBtn.getAttribute("data-cotizacion-id")'),
  'backend validates targeted quote belongs to ticket' => str_contains($workflow, '$targetCotizacionId > 0') && str_contains($workflow, 'La cotizacion seleccionada no pertenece a este ticket.'),
  'backend rejects state changes for decided quotes' => str_contains($workflow, 'Solo se puede cambiar una cotizacion sin estado o en Esperando respuesta.') && str_contains($handler, 'Solo se puede marcar como aprobada una cotizacion sin estado o en Esperando respuesta.') && str_contains($handler, 'Solo se puede eliminar/desaprobar una cotizacion sin estado o en Esperando respuesta.'),
];

$failed = [];
foreach ($checks as $label => $ok) {
  if (!$ok) {
    $failed[] = $label;
  }
}

if ($failed) {
  fwrite(STDERR, "Cotizaciones mantenimiento checks failed:\n- " . implode("\n- ", $failed) . "\n");
  exit(1);
}

echo 'Cotizaciones mantenimiento checks passed: ' . count($checks) . PHP_EOL;
