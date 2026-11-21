<?php

declare(strict_types=1);

// API propia del calendario del panel (reemplaza calendario-actividades): cuerpo JSON, respuesta {success,message,data}.

ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Modules\Calendar\CalendarApi;

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? '')) ?? '';

$respond = static function (array $payload): void {
  $status = (int) ($payload['status'] ?? 200);
  unset($payload['status']);
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
};
$failure = static fn(string $message, int $status): array => ['success' => false, 'message' => $message, 'data' => [], 'status' => $status];

if (!\SCM\Core\Auth::isLoggedIn()) {
  header('X-SCM-Auth: required');
  $respond($failure('Tu sesión terminó. Vuelve a iniciar sesión.', 401));
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true) || ($method === 'GET' && !in_array($action, ['listar_categorias', 'listar_funcionarios'], true))) {
  $respond($failure('Método no permitido.', 405));
}
if ($method === 'POST' && !$scmCsrf->verify(SuCasaControlServiciosInmobiliarios::NONCE_KEY, (string) ($_SERVER['HTTP_X_SCM_NONCE'] ?? ''), false)) {
  header('X-SCM-Auth: csrf-expired');
  $respond($failure('La verificación de seguridad venció. Recarga la página.', 403));
}
$input = json_decode((string) file_get_contents('php://input'), true);
session_write_close();
set_time_limit(60);

try {
  $respond((new CalendarApi($scmDb))->handle($action, is_array($input) ? $input : []));
} catch (\Throwable $exception) {
  $reference = bin2hex(random_bytes(6));
  error_log(sprintf('[calendar-api:%s] action=%s %s in %s:%d', $reference, $action, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
  $respond(['success' => false, 'message' => 'No se pudo completar la petición del calendario. Referencia: ' . $reference, 'data' => ['error_code' => 'calendar_api_failed', 'error_id' => $reference], 'status' => 500]);
}
