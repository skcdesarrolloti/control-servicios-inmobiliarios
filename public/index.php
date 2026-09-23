<?php

/**
 * Panel principal - Control de Servicios Inmobiliarios
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';

$controller = new \SCM\Controllers\DashboardController($scmDb);
$controller->requireAuth();

$panelHtml = $controller->getPanelHtml();
$user = \SCM\Core\Auth::user();
$baseUrl = SCM_BASE_URL;
$standaloneFunction = (string) ($_GET['scm_standalone'] ?? '') === '1'
  || (string) ($_GET['scm_bridge'] ?? '') === '1'
  || trim((string) ($_GET['scm_bridge_action'] ?? '')) !== '';

$allowedTabs = $controller->getAllowedTabs();
$view = new \SCM\Views\DashboardView();
$view->render($baseUrl, $user, $panelHtml, $standaloneFunction, $allowedTabs);

