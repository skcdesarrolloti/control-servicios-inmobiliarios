<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$router = (string) file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
$handler = (string) file_get_contents($root . '/src/App/Concerns/HandlesMaintenanceActions.php');
$runtimeJs = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');

$checks = [
  'app defines approve quote action' => str_contains($app, "AJAX_APPROVE_COTIZACION") && str_contains($app, "scm_aprobar_cotizacion_mantenimiento"),
  'router maps approve quote action' => str_contains($router, "AJAX_APPROVE_COTIZACION") && str_contains($router, "ajax_handler_approve_cotizacion_mantenimiento"),
  'runtime exposes approve quote action' => str_contains($dashboard, "'approve_cotizacion' => self::AJAX_APPROVE_COTIZACION"),
  'quote cards render quick approval button' => str_contains($dashboard, 'data-scm-approve-cotizacion') && str_contains($dashboard, 'Marcar como aprobada'),
  'quote cards show quick approval for every non-approved quote' => str_contains($dashboard, '!$cotizacionAprobada ?') && !str_contains($dashboard, '$cotizacionFinalizada'),
  'quote card approve and delete actions use semantic colors' => str_contains($dashboard, 'scm-cotizacion-approve-action') && str_contains($dashboard, 'scm-cotizacion-delete-action'),
  'quote approve and delete popups use semantic confirm colors' => str_contains($runtimeJs, 'confirmButtonColor: "#16a34a"') && str_contains($runtimeJs, 'confirmButtonColor: "#b91c1c"'),
  'quote panel omits finalized buckets' => !str_contains($dashboard, "'label' => 'Finalizadas'") && !str_contains($dashboard, "'key' => 'finalizadas'") && !str_contains($handler, 'kpi_finalizadas'),
  'quote panel omits sent buckets' => !str_contains($dashboard, "'label' => 'Enviadas'") && !str_contains($dashboard, "'key' => 'enviadas'") && !str_contains($handler, 'kpi_enviadas'),
  'quote contact uses real responsible instead of contractual coordinator default' => str_contains($dashboard, 'cotizacion_responsable_contact') && str_contains($handler, 'cotizacion_responsable_contact') && !str_contains($dashboard, 'Coordinador contractual') && !str_contains($handler, 'Coordinador contractual'),
  'recipient quote view hides internal financial control' => str_contains($dashboard, 'if ($isFuncionario)') && str_contains($dashboard, 'Control financiero') && str_contains($handler, 'if ($isFuncionario)') && str_contains($handler, "heading('Control de saldos')"),
  'frontend submits exact quote approval action' => str_contains($runtimeJs, 'actionApproveCotizacion') && str_contains($runtimeJs, 'data-scm-approve-cotizacion') && str_contains($runtimeJs, 'fd.append("id_cotizacion", approveCotizacionId)'),
  'backend updates exact quote by id' => str_contains($handler, 'ajax_handler_approve_cotizacion_mantenimiento') && str_contains($handler, "\$this->db->update(\$table, \$update, ['_ID' => \$cotizacionId])"),
  'backend synchronizes linked ticket state' => str_contains($handler, "'estado_cotizacion_mantenimiento' => 'Aprobada'") && str_contains($handler, "'estado_respuesta_cotizacion_mantenimiento' => 'Aprobada'"),
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
