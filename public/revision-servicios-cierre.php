<?php

declare(strict_types=1);

// Revisión de servicios públicos antes de la ocupación de una hoja de cierre (reemplaza el JetForm "Añadir revision
// de servicios publicos antes de entregar el inmueble"). Con sesión del panel: ?numero=<hoja>. Sin sesión: el enlace
// firmado que llega por correo o WhatsApp (&p=revision&f=<id_empleado>&expires=…&sig=…).
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
  $flow['json_guard']($gate, 'review', 'No tienes permiso para registrar la revisión de servicios de esta hoja.');
  try {
    $result = $workflow->saveReview($closingId, $flow['input'](), $gate['actor']);
  } catch (\DomainException $error) {
    $flow['json'](false, ['message' => $error->getMessage()]);
  } catch (\Throwable $error) {
    error_log('[closing_sheet_review] #' . $closingId . ': ' . $error->getMessage());
    $flow['json'](false, ['message' => 'No se pudo registrar la revisión. Intenta nuevamente.']);
  }
  $flow['json'](true, [
    'title' => 'Revisión #' . $result['review_id'] . ' registrada',
    'message' => $result['summary'] . '. La hoja de cierre #' . $closingId . ' quedó "' . $result['state'] . '" y se encolaron ' . $result['notified'] . ' avisos.',
    'links' => [['label' => 'Ver hoja de cierre', 'url' => $flow['link']('hoja-cierre-ver.php'), 'primary' => true]],
  ]);
}

$flow['page_guard']($gate, 'review', 'revisar los servicios de esta hoja de cierre');
try {
  $form = $workflow->reviewForm($closingId);
} catch (\DomainException $error) {
  $flow['render']('Revisión de servicios', [], ClosingSheetFlowView::message('Hoja no encontrada', $error->getMessage(), 'error'), 404);
}
$closing = $form['closing'];
if (!ClosingSheetWorkflow::canReview($closing)) {
  $flow['render']('Revisión de servicios · hoja #' . $closingId, ClosingSheetFlowView::facts($closing), ClosingSheetFlowView::message(
    'Esta hoja ya no admite revisión',
    'La hoja de cierre #' . $closingId . ' está "' . ClosingSheetWorkflow::state($closing) . '".',
    'info',
    [['label' => 'Ver hoja de cierre', 'url' => $flow['link']('hoja-cierre-ver.php'), 'primary' => true]]
  ), 200, ClosingSheetWorkflow::state($closing));
}
$flow['render'](
  'Revisión de servicios · hoja #' . $closingId,
  ClosingSheetFlowView::facts($closing),
  ClosingSheetFlowView::reviewBody($form, $flow['hidden']($gate), $gate['actor'], $flow['link']('hoja-cierre-ver.php')),
  200,
  ClosingSheetWorkflow::state($closing)
);
