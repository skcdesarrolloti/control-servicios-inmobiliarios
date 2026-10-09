<?php

/**
 * Panel principal - Control de Servicios Inmobiliarios
 */
require_once dirname(__DIR__) . '/bootstrap/app.php';

if (isset($_GET['scm_case'])) {
  $reference = is_string($_GET['scm_case']) ? $_GET['scm_case'] : '';
  $loggedIn = \SCM\Core\Auth::isLoggedIn();
  // Old staff emails contain only the numeric ID. Preserve their login return path.
  if (!$loggedIn && preg_match('/^[1-9][0-9]{0,17}$/D', $reference)) {
    (new \SCM\Controllers\DashboardController($scmDb))->requireAuth();
    exit;
  }
  $route = \SCM\Support\PublicCaseAccess::route($reference, $loggedIn);
  if ($route['mode'] !== 'panel') {
    require __DIR__ . '/caso.php';
    exit;
  }
  if ($reference !== (string) $route['id']) {
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/?scm_case=' . $route['id'], true, 302);
    exit;
  }
}

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
