<?php

/**
 * La pagina de configuracion fue removida.
 * Los ajustes se definen directamente en codigo.
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';
\SCM\Core\Auth::requireLogin(SCM_BASE_URL . '/login.php');
header('Location: ' . SCM_BASE_URL . '/index.php');
exit;
