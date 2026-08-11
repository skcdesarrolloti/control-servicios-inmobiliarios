<?php

/**
 * Logout — Control de Servicios Inmobiliarios
 */
require_once __DIR__ . '/bootstrap.php';

$scmAuth->logout();

header('Location: ' . SCM_BASE_URL . '/login.php');
exit;
