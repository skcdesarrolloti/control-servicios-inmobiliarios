<?php

declare(strict_types=1);

// Página propia para crear, editar o enviar una cotización y crear su orden, sin navegar el panel.
require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Views\QuotePageView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$action = is_string($_GET['accion'] ?? null) ? strtolower(trim($_GET['accion'])) : '';
if (!isset(QuotePageView::ACTIONS[$action])) {
  http_response_code(400);
  exit('Enlace incompleto: usa accion=crear, editar, enviar u orden.');
}
$param = QuotePageView::ACTIONS[$action][4];
$recordId = is_string($_GET[$param] ?? null) && ctype_digit($_GET[$param]) ? (int) $_GET[$param] : 0;
if ($recordId <= 0) {
  http_response_code(400);
  exit($param === 'ticket_pk' ? 'Falta el ticket_pk interno del caso.' : 'Falta el id_cotizacion.');
}

$relativeTarget = 'cotizacion.php?' . http_build_query(['accion' => $action, $param => $recordId], '', '&', PHP_QUERY_RFC3986);
$tryEmployeeTokenLogin = static function (): bool {
  if (Auth::isLoggedIn()) {
    return true;
  }
  $employeeId = trim((string) ($_GET['id_empleado'] ?? ''));
  $token = trim((string) ($_GET['token'] ?? ''));
  $secret = defined('SCM_ACTA_AUTOLOGIN_SECRET') ? trim((string) SCM_ACTA_AUTOLOGIN_SECRET) : '';
  if (!\SCM\Support\ActaAutologin::valid($secret, $employeeId, $token)) {
    return false;
  }
  return (new Auth(App::db()))->loginByEmployeeId($employeeId);
};
// El token nunca se queda en la barra de direcciones ni en el historial del navegador.
if (Auth::isLoggedIn() && (isset($_GET['token']) || isset($_GET['id_empleado']))) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/' . $relativeTarget, true, 302);
  exit;
}
if (!Auth::isLoggedIn() && $tryEmployeeTokenLogin()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/' . $relativeTarget, true, 302);
  exit;
}
if (!Auth::isLoggedIn()) {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/login.php?next=' . rawurlencode($relativeTarget), true, 302);
  exit;
}

$db = App::db();
$app = new SuCasaControlServiciosInmobiliarios($db);
if (!$app->canOpenQuoteFunction($action)) {
  http_response_code(403);
  exit('Tu cargo no tiene permiso para ' . mb_strtolower(QuotePageView::ACTIONS[$action][0], 'UTF-8') . '.');
}

$ticket = null;
$quote = null;
if ($param === 'ticket_pk') {
  $ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$recordId]);
  if (!$ticket) {
    http_response_code(404);
    exit('El caso no existe.');
  }
} else {
  $quote = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_cotizacion_mantenimiento') . '` WHERE `_ID` = ? LIMIT 1', [$recordId]);
  if (!$quote) {
    http_response_code(404);
    exit('La cotización no existe.');
  }
  $ticketRef = trim((string) ($quote['id_ticket'] ?? ''));
  if ($ticketRef !== '') {
    $ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ? OR `id_ticket` = ? ORDER BY `_ID` = ? DESC LIMIT 1', [(int) $ticketRef, $ticketRef, (int) $ticketRef]);
  }
}

$baseUrl = rtrim((string) SCM_BASE_URL, '/');
$asset = static function (string $path) use ($baseUrl): string {
  $file = __DIR__ . '/' . $path;
  return $baseUrl . '/' . $path . '?v=' . rawurlencode(SCM_VERSION . '-' . (is_file($file) ? (string) filemtime($file) : '0'));
};

echo (new QuotePageView())->render([
  'action' => $action,
  'record_id' => $recordId,
  'case' => $ticket ? (trim((string) ($ticket['id_ticket'] ?? '')) ?: (string) $ticket['_ID']) : '',
  'quote' => $quote ? (string) $quote['_ID'] : '',
  'address' => trim((string) ($quote['direccion'] ?? $ticket['direccion'] ?? '')),
  'user' => Auth::user(),
  'base_url' => $baseUrl,
  'logo' => system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL),
  'favicon' => system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL),
  // El panel trae su runtime, estilos y scripts; aquí solo funciona como motor de los formularios.
  'panel_html' => $app->renderPanel(),
  'css' => array_map($asset, ['assets/css/tailwind-admin.css', 'assets/css/tailwind-services.css', 'assets/css/modern-ui.css', 'assets/css/review-page.css']),
  'js' => array_map($asset, ['assets/js/quote-page.js']),
]);
