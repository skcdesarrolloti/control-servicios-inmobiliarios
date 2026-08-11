<?php

/**
 * Logout — Control de Servicios Inmobiliarios
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  exit('Método no permitido.');
}

if (!$scmCsrf->check('logout')) {
  http_response_code(403);
  exit('Solicitud inválida.');
}

$scmAuth->logout();

header('Location: ' . SCM_BASE_URL . '/login.php');
exit;
