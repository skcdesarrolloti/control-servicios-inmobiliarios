<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$trait = file_get_contents($root . '/src/App/Concerns/HandlesCorrectiveReviewActions.php');
$app = file_get_contents($root . '/src/App/SuCasaControlServiciosInmobiliarios.php');
$router = file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
$dashboard = file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
$maintenanceRows = file_get_contents($root . '/src/Modules/ServiciosInmobiliarios/Concerns/TableRowsConcern.php');
$js = file_get_contents($root . '/public/assets/js/scm-admin.js');

$checks = [
  'trait exists and defines ajax handler' => is_string($trait) && str_contains($trait, 'ajax_handler_corrective_review'),
  'trait does not call administrative report hook' => is_string($trait) && !str_contains($trait, 'reporte-administrativos-unificado'),
  'trait writes revision_correctiva CCT' => is_string($trait) && str_contains($trait, "jet_cct_revision_correctiva"),
  'trait updates ticket revision field' => is_string($trait) && str_contains($trait, "'id_revision_correctiva'"),
  'trait records ticket history' => is_string($trait) && str_contains($trait, "jet_cct_historial_del_ticket"),
  'trait records property history' => is_string($trait) && str_contains($trait, "jet_cct_historial_del_inmueble"),
  'app registers AJAX constant' => is_string($app) && str_contains($app, "AJAX_CORRECTIVE_REVIEW"),
  'router maps AJAX action' => is_string($router) && str_contains($router, "ajax_handler_corrective_review"),
  'dashboard exposes runtime action' => is_string($dashboard) && str_contains($dashboard, "'revision_correctiva' => self::AJAX_CORRECTIVE_REVIEW"),
  'dashboard supports direct media refs for compressed uploads' => is_string($dashboard) && str_contains($dashboard, 'cotizacion_split_media_refs'),
  'case modal has corrective review button' => is_string($js) && str_contains($js, 'data-scm-open-corrective-review'),
  'maintenance rows mark their source tab' => is_string($maintenanceRows) && str_contains($maintenanceRows, 'data-tab-key="mantenimiento"'),
  'case modal limits corrective review to maintenance tab' => is_string($js) && str_contains($js, 'function isMaintenanceCase') && str_contains($js, '&& isMaintenanceForActions'),
  'case actions are separated into groups' => is_string($js) && str_contains($js, 'renderActionGroup("Complementarias"') && str_contains($js, 'renderActionGroup("Cotización"'),
  'JS compresses corrective photos' => is_string($js) && str_contains($js, 'function compressPhoto(file)'),
];

$failed = [];
foreach ($checks as $label => $ok) {
  if (!$ok) {
    $failed[] = $label;
  }
}

if ($failed) {
  fwrite(STDERR, "Corrective review checks failed:\n- " . implode("\n- ", $failed) . "\n");
  exit(1);
}

echo 'Corrective review checks passed: ' . count($checks) . PHP_EOL;
