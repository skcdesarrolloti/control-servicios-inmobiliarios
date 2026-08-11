<?php

/**
 * Panel principal - Control de Servicios Inmobiliarios
 */
require_once __DIR__ . '/bootstrap.php';

$controller = new \SCM\Controllers\DashboardController($scmDb);
$controller->requireAuth();

$panelHtml = $controller->getPanelHtml();
$user = \SCM\Core\Auth::user();
$baseUrl = SCM_BASE_URL;

$view = new \SCM\Views\DashboardView();
$view->render($baseUrl, $user, $panelHtml);

