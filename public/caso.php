<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Support\PublicCaseAccess;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' https:; script-src 'self'; frame-src 'self' https://sucasainmobiliaria.com.co; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
$reference = is_string($_GET['scm_case'] ?? null) ? $_GET['scm_case'] : '';
$route = PublicCaseAccess::route($reference, Auth::isLoggedIn());
if ($route['mode'] === 'panel') {
  header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . $route['id'], true, 302);
  exit;
}
$case = null;
$error = 'Este enlace no es válido o está vencido. Solicita a SKC SuCasa Inmobiliaria un nuevo enlace del caso.';
http_response_code(403);
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  $error = 'Método no permitido.';
} elseif ($route['mode'] === 'public') {
  $limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
  if (!$limiter->consume('public-case:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 90, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    $error = 'Espera un momento y vuelve a abrir el enlace.';
  } else {
    try {
      $db = App::db();
      $ticket = $db->getRow('SELECT * FROM `' . $db->table('jet_cct_tickets') . '` WHERE `_ID` = ? LIMIT 1', [$route['id']]);
      if (!$ticket) { http_response_code(404); $error = 'El caso no está disponible.'; }
      else { $case = \SCM\Support\PublicCaseData::fromTicket($ticket); http_response_code(200); }
    } catch (Throwable $e) {
      http_response_code(500);
      $error = 'No se pudo cargar el caso. Intenta nuevamente más tarde.';
      error_log('[public-case] Error al consultar caso #' . $route['id']);
    }
  }
}
session_write_close();
(new \SCM\Views\PublicCaseView())->render($case, $error);
