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
  'trait stores combined affected area on corrective review' => is_string($trait) && str_contains($trait, "'area_afectada' => \$areaAfectada") && str_contains($trait, 'correctiveReviewCombinedAreas'),
  'trait reads JetEngine glossaries from wp_options' => is_string($trait) && str_contains($trait, 'correctiveReviewGlossaryOptions') && str_contains($trait, 'jet_engine_glossaries'),
  'trait renders dynamic affected area fields' => is_string($trait) && str_contains($trait, 'data-corrective-area-group') && str_contains($trait, 'area_afectada_1') && str_contains($trait, 'area_afectada_4'),
  'trait keeps JetForm affected-area select fallbacks' => is_string($trait) && str_contains($trait, 'Muros de fachada o antepechos') && str_contains($trait, 'Vigas, columnas'),
  'trait keeps JetForm damage metadata fallbacks' => is_string($trait) && str_contains($trait, "'Muy leve' => 'Muy leve'") && str_contains($trait, "'2 dias' => '2 dias'") && str_contains($trait, "'Fabricante' => 'Fabricante'"),
  'trait resolves corrective contract by exact internal id first' => is_string($trait) && str_contains($trait, "WHERE `_ID` = ? LIMIT 1") && !str_contains($trait, "WHERE `_ID` = ? OR `contrato` = ?"),
  'trait validates corrective context before saving' => is_string($trait) && str_contains($trait, 'correctiveReviewValidateContext($ticket, $contract, $property)') && str_contains($trait, 'El número de contrato encontrado no coincide'),
  'trait prioritizes ticket owner as corrective recipient' => is_string($trait) && str_contains($trait, "'destinatario' => \$this->correctiveReviewFirstText([\$ticket['propietario'] ?? '', \$contract['propietario'] ?? ''"),
  'trait does not look up properties by database id using web code' => is_string($trait) && str_contains($trait, "WHERE `codigo` = ? OR `id_ticket` = ? LIMIT 1") && !str_contains($trait, "WHERE `_ID` = ? OR `id_ticket` = ?"),
  'trait supports editing and deleting corrective reviews' => is_string($trait) && str_contains($trait, 'correctiveReviewUpdate') && str_contains($trait, 'correctiveReviewDelete'),
  'trait updates ticket revision field' => is_string($trait) && str_contains($trait, "'id_revision_correctiva'"),
  'trait records ticket history' => is_string($trait) && str_contains($trait, "jet_cct_historial_del_ticket"),
  'trait records property history' => is_string($trait) && str_contains($trait, "jet_cct_historial_del_inmueble"),
  'app registers AJAX constant' => is_string($app) && str_contains($app, "AJAX_CORRECTIVE_REVIEW"),
  'router maps AJAX action' => is_string($router) && str_contains($router, "ajax_handler_corrective_review"),
  'dashboard exposes runtime action' => is_string($dashboard) && str_contains($dashboard, "'revision_correctiva' => self::AJAX_CORRECTIVE_REVIEW"),
  'dashboard supports direct media refs for compressed uploads' => is_string($dashboard) && str_contains($dashboard, 'cotizacion_split_media_refs'),
  'case modal has corrective review button' => is_string($js) && str_contains($js, 'data-scm-open-corrective-review'),
  'case modal changes corrective review button label when review exists' => is_string($js) && str_contains($js, 'hasCorrectiveReview') && str_contains($js, 'Gestionar revisi'),
  'corrective review modal exposes edit and delete actions' => is_string($js) && str_contains($js, 'data-corrective-edit-review') && str_contains($js, 'data-corrective-delete-review') && str_contains($js, 'data-corrective-review-edit'),
  'corrective review modal syncs dynamic affected area fields' => is_string($js) && str_contains($js, 'syncCorrectiveAreaFields') && str_contains($js, 'data-corrective-indice'),
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
