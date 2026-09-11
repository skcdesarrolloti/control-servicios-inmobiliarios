<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
set_time_limit(60);
ini_set('memory_limit', '256M');

$respondError = static function (string $message, int $status, string $code = ''): void {
  http_response_code($status);
  if ($code === 'AUTH_REQUIRED') {
    header('X-SCM-Auth: required');
  }
  $data = ['message' => $message];
  if ($code !== '') {
    $data['code'] = $code;
  }
  echo json_encode(
    ['success' => false, 'data' => $data],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  header('Allow: POST, OPTIONS');
  http_response_code(204);
  exit;
}
if (!\SCM\Core\Auth::isLoggedIn()) {
  $respondError('Tu sesión venció. Inicia sesión nuevamente.', 401, 'AUTH_REQUIRED');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST, OPTIONS');
  $respondError('Método no permitido.', 405);
}

$action = sanitize_key((string) ($_POST['action'] ?? ''));
$application = new \SCM\App\SuCasaControlServiciosInmobiliarios($scmDb);
$router = new \SCM\Http\Api\AuthenticatedActionRouter($application);

session_write_close();

try {
  if (!$router->dispatch($action)) {
    $respondError('Acción desconocida.', 400);
  }
} catch (\Throwable $exception) {
  $requestId = bin2hex(random_bytes(8));
  $actionForLog = $action !== '' ? $action : sanitize_key((string) ($_REQUEST['action'] ?? ''));
  error_log(sprintf(
    '[api:%s] action=%s method=%s %s in %s:%d',
    $requestId,
    $actionForLog !== '' ? $actionForLog : '(none)',
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    $exception->getMessage(),
    $exception->getFile(),
    $exception->getLine()
  ));
  $respondError('Error interno' . ($actionForLog !== '' ? ' en ' . $actionForLog : '') . '. Referencia: ' . $requestId, 500);
}
