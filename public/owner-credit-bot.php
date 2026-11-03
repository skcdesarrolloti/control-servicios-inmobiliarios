<?php

declare(strict_types=1);

// API de créditos a propietarios para el bot de WhatsApp (cupo, radicar y consultar). Ver OwnerCreditBotApi.
ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
session_write_close();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$body = (string) file_get_contents('php://input', false, null, 0, 65536);
$api = new \SCM\Modules\OwnerCredits\OwnerCreditBotApi(new \SCM\Modules\OwnerCredits\OwnerCreditService(\SCM\Core\App::db(), \SCM\Core\App::settings()), (string) SCM_APP_SECRET);
$result = $api->handle($body, (string) ($_SERVER['HTTP_X_SCM_SIGNATURE'] ?? ''));
http_response_code($result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
