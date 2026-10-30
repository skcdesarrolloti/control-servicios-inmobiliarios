<?php

declare(strict_types=1);

// Listado de hojas de cierre por estado, con accesos a ver, revisar servicios y responder. Solo con sesión del panel.
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;
use SCM\Modules\ClosingSheets\ClosingSheetWorkflow;

$flow = require __DIR__ . '/../includes/closing-sheet-flow.php';
$state = (string) ($_GET['estado'] ?? '');
$state = in_array($state, ClosingSheetWorkflow::LIST_STATES, true) ? $state : ClosingSheetWorkflow::STATUS_REVIEWED;
if (!Auth::isLoggedIn()) {
  header('Location: ' . $flow['base_url'] . '/login.php?next=' . rawurlencode('hojas-cierre.php?estado=' . $state), true, 302);
  exit;
}
$db = App::db();
$app = new SuCasaControlServiciosInmobiliarios($db);
$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120, 'UTF-8');
$listing = (new ClosingSheetWorkflow($db))->listing($state, $query);
$flow['render']('Hojas de cierre', [], ClosingSheetFlowView::listBody($listing['counts'], $listing['rows'], $state, $query, [
  'review' => $app->canUseClosingSheetAction('closing_sheet_review'),
  'respond' => $app->canUseClosingSheetAction('closing_sheet_respond'),
  'edit' => $app->canUseClosingSheetAction('closing_sheet_edit'),
], $flow['base_url']));
