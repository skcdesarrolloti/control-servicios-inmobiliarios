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
$workflow = (string) file_get_contents($root . '/src/Modules/ServiciosInmobiliarios/Concerns/WorkflowCommandsConcern.php');
$summaryPos = strpos($handler, "\$pdf->heading('Resumen económico');");
$ordersPos = strpos($handler, "\$pdf->heading('Órdenes de mantenimiento');");

$checks = [
  'app defines approve quote action' => str_contains($app, "AJAX_APPROVE_COTIZACION") && str_contains($app, "scm_aprobar_cotizacion_mantenimiento"),
  'router maps approve quote action' => str_contains($router, "AJAX_APPROVE_COTIZACION") && str_contains($router, "ajax_handler_approve_cotizacion_mantenimiento"),
  'runtime exposes approve quote action' => str_contains($dashboard, "'approve_cotizacion' => self::AJAX_APPROVE_COTIZACION"),
  'quote cards render quick approval button' => str_contains($dashboard, 'data-scm-approve-cotizacion') && str_contains($dashboard, 'Marcar como aprobada'),
  'quote cards show state-changing actions only while pending' => str_contains($dashboard, '$cotizacionSinResponder ?') && !str_contains($dashboard, '$cotizacionFinalizada'),
  'quote card approve and delete actions use semantic colors' => str_contains($dashboard, 'scm-cotizacion-approve-action') && str_contains($dashboard, 'scm-cotizacion-delete-action'),
  'quote approve and delete popups use semantic confirm colors' => str_contains($runtimeJs, 'confirmButtonColor: "#16a34a"') && str_contains($runtimeJs, 'confirmButtonColor: "#b91c1c"'),
  'quote panel omits finalized buckets' => !str_contains($dashboard, "'label' => 'Finalizadas'") && !str_contains($dashboard, "'key' => 'finalizadas'") && !str_contains($handler, 'kpi_finalizadas'),
  'quote panel omits sent buckets' => !str_contains($dashboard, "'label' => 'Enviadas'") && !str_contains($dashboard, "'key' => 'enviadas'") && !str_contains($handler, 'kpi_enviadas'),
  'quote contact uses real responsible instead of contractual coordinator default' => str_contains($dashboard, 'cotizacion_responsable_contact') && str_contains($handler, 'cotizacion_responsable_contact') && !str_contains($dashboard, 'Coordinador contractual') && !str_contains($handler, 'Coordinador contractual'),
  'recipient quote view hides internal financial control' => str_contains($dashboard, 'if ($isFuncionario)') && str_contains($dashboard, 'Control financiero') && str_contains($handler, 'if ($isFuncionario)') && str_contains($handler, "heading('Control de saldos')"),
  'frontend submits exact quote approval action' => str_contains($runtimeJs, 'actionApproveCotizacion') && str_contains($runtimeJs, 'data-scm-approve-cotizacion') && str_contains($runtimeJs, 'fd.append("id_cotizacion", approveCotizacionId)'),
  'backend updates exact quote by id' => str_contains($handler, 'ajax_handler_approve_cotizacion_mantenimiento') && str_contains($handler, "\$this->db->update(\$table, \$update, ['_ID' => \$cotizacionId])"),
  'backend synchronizes linked ticket state' => str_contains($handler, "'estado_cotizacion_mantenimiento' => 'Aprobada'") && str_contains($handler, "'estado_respuesta_cotizacion_mantenimiento' => 'Aprobada'"),
  'quote pdf places economic summary after maintenance orders' => is_int($summaryPos) && is_int($ordersPos) && $summaryPos > $ordersPos,
  'case popup uses the quote selector instead of legacy iframe' => str_contains($adminJs, 'data-scm-view-case-cotizaciones') && !str_contains($adminJs, 'Abrir cotizaci&oacute;n</button>'),
  'quote ajax supports exact ticket lookup from case popup' => str_contains($dashboard, 'fTicketExact') && str_contains($dashboard, "TRIM(COALESCE(c.`id_ticket`, '')) = ?"),
  'case popup loads all quotes for the case instead of using one quote id' => str_contains($adminJs, 'data-scm-view-case-cotizaciones') && str_contains($runtimeJs, 'loadCotizacionCardsByTicket') && str_contains($runtimeJs, 'scmqt_ticket_exact'),
  'case quote selector delegates actions to the selected quote card' => str_contains($runtimeJs, 'scm-case-cotizaciones-modal') && str_contains($runtimeJs, 'triggerCotizacionRootAction') && str_contains($runtimeJs, 'Las acciones se aplican sobre el número de cotización elegido.'),
  'case quote selector keeps modal cards styled outside app shell' => str_contains($runtimeJs, 'scm-case-cotizaciones-list') && str_contains($runtimeJs, 'scm-case-cotizacion-card') && str_contains($adminCss, '.scm-case-cotizaciones-modal .scm-cotizacion-card') && str_contains($adminCss, '.scm-case-cotizaciones-modal .scm-case-work-btn'),
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
