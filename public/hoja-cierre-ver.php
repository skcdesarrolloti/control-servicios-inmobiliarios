<?php

declare(strict_types=1);

// Hoja de cierre en solo lectura para aprobarla o no aprobarla (reemplaza la vista de WordPress /hoja-de-cierre/ y el
// JetForm "Responder hoja de cierre de negocio"). Con sesión del panel: ?numero=<hoja>. Sin sesión: el enlace firmado
// que llega por correo o WhatsApp.
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;
use SCM\Modules\ClosingSheets\ClosingSheetGate;
use SCM\Modules\ClosingSheets\ClosingSheetWorkflow;

$flow = require __DIR__ . '/../includes/closing-sheet-flow.php';
$closingId = $flow['closing_id'];
$db = App::db();
$gate = ClosingSheetGate::resolve($db, $flow['params'], $closingId);
$workflow = new ClosingSheetWorkflow($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $flow['json_guard']($gate, 'respond', 'No tienes permiso para responder esta hoja de cierre.');
  try {
    $result = $workflow->respond($closingId, $flow['input'](), $gate['actor']);
  } catch (\DomainException $error) {
    $flow['json'](false, ['message' => $error->getMessage()]);
  } catch (\Throwable $error) {
    error_log('[closing_sheet_respond] #' . $closingId . ': ' . $error->getMessage());
    $flow['json'](false, ['message' => 'No se pudo guardar la respuesta. Intenta nuevamente.']);
  }
  $flow['json'](true, [
    'title' => 'Hoja de cierre #' . $closingId . ' ' . mb_strtolower($result['state'], 'UTF-8'),
    'message' => 'Quedó en el mandato y se encolaron ' . $result['notified'] . ' avisos, incluido el de quien hizo la hoja.',
    'reload' => true,
  ]);
}

$flow['page_guard']($gate, 'view', 'ver esta hoja de cierre');
try {
  $sheet = $workflow->sheet($closingId);
} catch (\DomainException $error) {
  $flow['render']('Hoja de cierre', [], ClosingSheetFlowView::message('Hoja no encontrada', $error->getMessage(), 'error'), 404);
}
$closing = $sheet['closing'];
$flow['render'](
  'Hoja de cierre #' . $closingId,
  ClosingSheetFlowView::facts($closing),
  ClosingSheetFlowView::sheetBody($sheet, $gate['can'], $flow['hidden']($gate), [
    'review' => $flow['link']('revision-servicios-cierre.php'),
    'edit' => 'hoja-cierre.php?id_cierre=' . $closingId,
  ]),
  200,
  ClosingSheetWorkflow::state($closing)
);
