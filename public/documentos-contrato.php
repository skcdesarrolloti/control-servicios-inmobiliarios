<?php

declare(strict_types=1);

// Carpeta de documentos del negocio: los de la hoja de cierre (lista de chequeo y adicionales) y los del contrato de
// arrendamiento, también para contratos viejos sin hoja nativa. ?contrato=<contrato de arrendamiento> o ?cierre=<hoja>.
// Pide sesión del panel; embed=1 la muestra dentro de la ventana del panel.
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\ClosingSheets\ClosingSheetDocuments;
use SCM\Modules\ClosingSheets\ClosingSheetDocumentsView;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$digits = static fn($value): int => is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', trim($value)) === 1 ? (int) trim($value) : 0;
$contractId = $digits($_GET['contrato'] ?? '');
$closingId = $digits($_GET['cierre'] ?? '');
$embed = ($_GET['embed'] ?? '') === '1';
$baseUrl = rtrim((string) SCM_BASE_URL, '/');
$target = 'documentos-contrato.php?' . http_build_query(array_filter(['contrato' => $contractId, 'cierre' => $closingId, 'embed' => $embed ? '1' : '']), '', '&', PHP_QUERY_RFC3986);
if (!Auth::isLoggedIn()) {
  header('Location: ' . $baseUrl . '/login.php?next=' . rawurlencode($target), true, 302);
  exit;
}

$asset = static function (string $path) use ($baseUrl): string {
  $file = __DIR__ . '/' . $path;
  return $baseUrl . '/' . $path . '?v=' . rawurlencode(SCM_VERSION . '-' . (is_file($file) ? (string) filemtime($file) : '0'));
};
$render = static function (string $title, array $facts, string $body, int $status = 200) use ($asset, $baseUrl, $embed): never {
  http_response_code($status);
  echo ClosingSheetFlowView::page([
    'base_url' => $baseUrl,
    'logo' => system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL),
    'favicon' => system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL),
    'css' => array_map($asset, ['assets/css/review-page.css', 'assets/css/closing-sheet.css', 'assets/css/closing-sheet-flow.css', 'assets/css/closing-documents.css']),
    'js' => array_map($asset, ['assets/js/scm-photo-compress.js', 'assets/js/closing-documents.js']),
    'user' => Auth::user(),
    'session' => true,
    'embed' => $embed,
  ], $title, 'Carpeta de documentos', 'folder_open', $facts, $body);
  exit;
};

if ($contractId <= 0 && $closingId <= 0) {
  $render('Carpeta de documentos', [], ClosingSheetFlowView::message('Enlace incompleto', 'Abre la carpeta desde el contrato de arrendamiento o la hoja de cierre.', 'error'), 400);
}
$db = App::db();
$app = new SuCasaControlServiciosInmobiliarios($db);
$canView = $app->userCanAccessTab('contratos_arrendamiento') || $app->canOpenClosingSheet(true) || $app->canOpenClosingSheet(false)
  || $app->canUseClosingSheetAction('closing_sheet_review') || $app->canUseClosingSheetAction('closing_sheet_respond');
if (!$canView) {
  $render('Carpeta de documentos', [], ClosingSheetFlowView::message('Sin permiso', 'Tu cargo no tiene permiso para ver los documentos de los contratos.', 'lock'), 403);
}

$documents = new ClosingSheetDocuments($db);
try {
  $folder = $documents->folder($contractId, $closingId);
} catch (\DomainException $error) {
  $render('Carpeta de documentos', [], ClosingSheetFlowView::message('No encontrada', $error->getMessage(), 'error'), 404);
}
$contract = $folder['contract'];
$closing = $folder['closing'];
$canEdit = $app->canManageContractDocuments() || ($contract === [] && $app->canOpenClosingSheet(false));
if ($contract !== []) {
  // Contratos anteriores a la carpeta: el enlace queda en el contrato la primera vez que se abre.
  if (trim((string) ($contract[ClosingSheetDocuments::LINK_COLUMN] ?? '')) === '') {
    $documents->linkContract((int) $contract['_ID']);
  }
}
$closingNumber = (int) ($closing['_ID'] ?? 0);
$body = ClosingSheetDocumentsView::folder($folder, $canEdit ? [
  'api' => $baseUrl . '/api.php', 'nonce' => App::csrf()->token('scm_nonce'),
  'upload' => SuCasaControlServiciosInmobiliarios::AJAX_CLOSING_DOCUMENTS_UPLOAD, 'delete' => SuCasaControlServiciosInmobiliarios::AJAX_CLOSING_DOCUMENTS_DELETE,
  'scope' => $folder['scope'],
] : null, $closingNumber > 0 ? 'hoja-cierre-ver.php?numero=' . $closingNumber . ($embed ? '&embed=1' : '') : '');

$render(
  $contract !== [] ? 'Documentos del contrato #' . trim((string) ($contract['contrato'] ?? $contract['_ID'])) : 'Documentos de la hoja de cierre #' . $closingNumber,
  [
    'Hoja de cierre' => $closingNumber > 0 ? '#' . $closingNumber : '',
    'Dirección' => (string) ($contract['direccion'] ?? $closing['direccion'] ?? ''),
    'Propietario' => (string) ($contract['propietario'] ?? $closing['propietario'] ?? ''),
    'Arrendatario' => (string) ($contract['arrendatario'] ?? $closing['arrendatario'] ?? ''),
    'Archivos' => (string) count($folder['documents']),
  ],
  $body
);
