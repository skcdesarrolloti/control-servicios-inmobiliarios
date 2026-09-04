<?php

declare(strict_types=1);

ob_start();
require dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

if (!Auth::isLoggedIn()) {
  http_response_code(401);
  header('X-SCM-Auth: required');
  echo json_encode([
    'success' => false,
    'data' => [
      'message' => 'Tu sesión venció. Inicia sesión nuevamente.',
      'code' => 'AUTH_REQUIRED',
    ],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

echo json_encode([
  'success' => true,
  'data' => [
    'nonce' => App::csrf()->token(SuCasaControlServiciosInmobiliarios::NONCE_KEY),
    'idle_timeout_seconds' => defined('SCM_SESSION_IDLE_TIMEOUT') ? (int) SCM_SESSION_IDLE_TIMEOUT : 28800,
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
