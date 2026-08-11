<?php

/**
 * API publica para crear PQR desde portales de propietario/arrendatario/copropiedad/cliente.
 */

ob_start();
require_once __DIR__ . '/bootstrap.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/data/php-error.log');
set_time_limit(60);
ini_set('memory_limit', '256M');
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'data' => ['message' => 'Metodo no permitido.']]);
  exit;
}

$action = sanitize_key((string) ($_POST['action'] ?? ''));
$nonce = (string) ($_POST['nonce'] ?? '');
if (!$scmCsrf->verify('public_ticket_portal', $nonce, false)) {
  http_response_code(403);
  echo json_encode(['success' => false, 'data' => ['message' => 'Verificacion de seguridad fallida.']]);
  exit;
}

$service = new \SCM\Modules\PublicTickets\PublicTicketsService($scmDb, $scmConfig);

$jsonOk = static function (array $data): void {
  echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
};
$jsonFail = static function (string $message, int $status = 400): void {
  http_response_code($status);
  echo json_encode(['success' => false, 'data' => ['message' => $message]], JSON_UNESCAPED_UNICODE);
  exit;
};

try {
  if ($action === 'public_ticket_lookup') {
    $actor = sanitize_key((string) ($_POST['actor'] ?? ''));
    $lookupValue = sanitize_text_field((string) ($_POST['lookup_value'] ?? ''));
    $result = $service->lookup($actor, $lookupValue);

    if (empty($result['ok'])) {
      $jsonFail((string) ($result['message'] ?? 'No se pudo realizar la consulta.'));
    }
    $jsonOk($result);
  }

  if ($action === 'public_ticket_create') {
    $actor = sanitize_key((string) ($_POST['actor'] ?? ''));
    $selectedId = sanitize_text_field((string) ($_POST['selected_id'] ?? ''));
    $lookupValue = sanitize_text_field((string) ($_POST['lookup_value'] ?? ''));

    $payload = [
      'actor_id' => sanitize_text_field((string) ($_POST['actor_id'] ?? '')),
      'solicitante' => sanitize_text_field((string) ($_POST['solicitante'] ?? '')),
      'correo_solicitante' => sanitize_text_field((string) ($_POST['correo_solicitante'] ?? '')),
      'celular_solicitante' => sanitize_text_field((string) ($_POST['celular_solicitante'] ?? '')),
      'indicativo' => sanitize_text_field((string) ($_POST['indicativo'] ?? '+57')),
      'asunto' => sanitize_text_field((string) ($_POST['asunto'] ?? '')),
      'tema_ayuda' => sanitize_text_field((string) ($_POST['tema_ayuda'] ?? $_POST['tipo_pqrs'] ?? '')),
      'descripcion' => sanitize_textarea_field((string) ($_POST['descripcion'] ?? '')),
    ];

    if ($payload['correo_solicitante'] !== '' && !filter_var($payload['correo_solicitante'], FILTER_VALIDATE_EMAIL)) {
      $jsonFail('El correo del solicitante no es valido.');
    }

    $result = $service->createTicket($actor, $selectedId, $lookupValue, $payload);
    if (empty($result['ok'])) {
      $jsonFail((string) ($result['message'] ?? 'No se pudo crear el PQR.'));
    }
    $jsonOk($result);
  }

  if ($action === 'public_ticket_requests') {
    $actor = sanitize_key((string) ($_POST['actor'] ?? ''));
    $selectedId = sanitize_text_field((string) ($_POST['selected_id'] ?? ''));
    $lookupValue = sanitize_text_field((string) ($_POST['lookup_value'] ?? ''));

    $result = $service->lookupRequests($actor, $selectedId, $lookupValue);
    if (empty($result['ok'])) {
      $jsonFail((string) ($result['message'] ?? 'No se pudieron consultar las solicitudes.'));
    }
    $jsonOk($result);
  }

  $jsonFail('Accion desconocida: ' . $action);
} catch (\Throwable $e) {
  $jsonFail('Error interno: ' . $e->getMessage(), 500);
}
