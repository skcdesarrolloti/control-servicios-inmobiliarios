<?php

declare(strict_types=1);

// Piezas comunes de revision-servicios-cierre.php y hoja-cierre-ver.php: encabezados, acceso (sesión o enlace
// firmado), respuesta JSON de los POST y render de la página. Devuelve un arreglo de funciones.

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\ClosingSheets\ClosingSheetAccess;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
// Los enlaces firmados no deben viajar como Referer a otros sitios.
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$params = $isPost ? $_POST + $_GET : $_GET;
$closingId = preg_match('/^[1-9][0-9]{0,18}$/D', trim((string) ($params['numero'] ?? ''))) === 1 ? (int) $params['numero'] : 0;
$script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$baseUrl = rtrim((string) SCM_BASE_URL, '/');

$json = static function (bool $ok, array $data, int $status = 0): never {
  http_response_code($status > 0 ? $status : ($ok ? 200 : 422));
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['success' => $ok, 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
};

// Enlace a otra página del flujo para la misma hoja, conservando la firma si se entró con una.
$link = static function (string $page) use ($closingId, $params): string {
  $query = ['numero' => $closingId];
  foreach (['p', 'f', 'expires', 'sig'] as $key) {
    if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
      $query[$key] = $params[$key];
    }
  }
  return $page . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};

// Nombre del funcionario que actúa (sesión o enlace), para el saludo de la barra.
$actorName = '';
$render = static function (string $title, array $facts, string $body, int $status = 200, string $state = '', bool $hero = true) use ($baseUrl, &$actorName): never {
  http_response_code($status);
  $asset = static function (string $path) use ($baseUrl): string {
    $file = dirname(__DIR__) . '/public/' . $path;
    return $baseUrl . '/' . $path . '?v=' . rawurlencode(SCM_VERSION . '-' . (is_file($file) ? (string) filemtime($file) : '0'));
  };
  echo ClosingSheetFlowView::page([
    'base_url' => $baseUrl,
    'logo' => system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL),
    'favicon' => system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL),
    'css' => array_map($asset, ['assets/css/review-page.css', 'assets/css/closing-sheet.css', 'assets/css/closing-sheet-flow.css']),
    'js' => array_map($asset, ['assets/js/closing-sheet-flow.js']),
    'user' => $actorName !== '' ? $actorName : (Auth::isLoggedIn() ? Auth::user() : ''),
    'session' => Auth::isLoggedIn(),
  ], $title, 'Hoja de cierre de negocio', 'handshake', $facts, $body, $state, $hero);
  exit;
};

// GET: sin sesión ni enlace válido se pide iniciar sesión; sin permiso se explica.
$pageGuard = static function (array $gate, string $capability, string $what) use ($closingId, $script, $baseUrl, $render, &$actorName): void {
  if ($closingId <= 0) {
    $render('Hoja de cierre', [], ClosingSheetFlowView::message('Enlace incompleto', 'Falta el número de la hoja de cierre.', 'error'), 400);
  }
  if ($gate['actor'] === null && $gate['error'] === '' && !$gate['session']) {
    header('Location: ' . $baseUrl . '/login.php?next=' . rawurlencode($script . '?numero=' . $closingId), true, 302);
    exit;
  }
  if ($gate['actor'] === null) {
    $render('Hoja de cierre #' . $closingId, [], ClosingSheetFlowView::message('No se puede abrir', $gate['error'], 'lock', [['label' => 'Iniciar sesión en el panel', 'url' => $baseUrl . '/login.php?next=' . rawurlencode($script . '?numero=' . $closingId)]]), 403);
  }
  $actorName = $gate['actor']['name'];
  if (empty($gate['can'][$capability])) {
    $render('Hoja de cierre #' . $closingId, [], ClosingSheetFlowView::message('Sin permiso', 'Tu cargo no tiene permiso para ' . $what . '.', 'lock'), 403);
  }
};

// POST: con sesión exige el nonce del panel; con enlace firmado la firma se valida en ClosingSheetGate.
$jsonGuard = static function (array $gate, string $capability, string $message) use ($closingId, $json, $params): void {
  if ($closingId <= 0 || $gate['actor'] === null) {
    $json(false, ['message' => $gate['error'] !== '' ? $gate['error'] : 'Tu sesión terminó. Vuelve a abrir el enlace o inicia sesión.'], 401);
  }
  if ($gate['access'] === null && !App::csrf()->verify('scm_nonce', (string) ($params['nonce'] ?? ''), false)) {
    $json(false, ['message' => 'La verificación de seguridad venció. Recarga la página.'], 403);
  }
  if (empty($gate['can'][$capability])) {
    $json(false, ['message' => $message], 403);
  }
};

// Campos ocultos del formulario: la firma del enlace o el nonce de la sesión.
$hidden = static function (array $gate) use ($closingId, $params): array {
  return ['numero' => (string) $closingId] + ($gate['access'] !== null ? ClosingSheetAccess::hiddenFields($gate['access'], $params) : ['nonce' => App::csrf()->token('scm_nonce')]);
};

$input = static function (): array {
  $clean = static function ($value) use (&$clean) {
    return is_array($value) ? array_map($clean, $value) : trim(strip_tags(wp_unslash((string) $value)));
  };
  $out = [];
  foreach ($_POST as $key => $value) {
    if (is_string($key) && preg_match('/^[a-z0-9_]+$/', $key) === 1) {
      $out[$key] = $clean($value);
    }
  }
  return $out;
};

return [
  'closing_id' => $closingId,
  'params' => $params,
  'base_url' => $baseUrl,
  'json' => $json,
  'link' => $link,
  'render' => $render,
  'page_guard' => $pageGuard,
  'json_guard' => $jsonGuard,
  'hidden' => $hidden,
  'input' => $input,
];
