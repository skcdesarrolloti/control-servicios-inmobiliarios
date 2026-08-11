<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');
set_time_limit(60);
ini_set('memory_limit', '256M');

$respondError = static function (string $message, int $status): void {
  http_response_code($status);
  echo json_encode(
    ['success' => false, 'data' => ['message' => $message]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  exit;
};

if (!\SCM\Core\Auth::isLoggedIn()) {
  $respondError('No autenticado.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
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
  error_log(sprintf(
    '[api:%s] %s in %s:%d',
    $requestId,
    $exception->getMessage(),
    $exception->getFile(),
    $exception->getLine()
  ));
  $respondError('Error interno. Referencia: ' . $requestId, 500);
}
