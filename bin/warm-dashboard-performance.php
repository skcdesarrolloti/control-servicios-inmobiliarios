<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$_SESSION['scm_logged_in'] = true;
$_SESSION['scm_user_id'] = 0;
$_SESSION['scm_user'] = 'Cache de despliegue';
$_SESSION['scm_user_login'] = 'cache-deploy';
$_SESSION['scm_user_rol'] = 'Sistema';
$_SESSION['scm_user_cargo'] = '14';
$_POST['nonce'] = \SCM\Core\App::csrf()->token(\SCM\App\SuCasaControlServiciosInmobiliarios::NONCE_KEY);

$application = new \SCM\App\SuCasaControlServiciosInmobiliarios(\SCM\Core\App::db());
$target = strtolower(trim((string) ($argv[1] ?? 'metrics')));
ob_start(static fn(string $buffer): string => '');
ob_start(static fn(string $buffer): string => '');
if ($target === 'filters') {
  $application->ajax_handler_dashboard_filter_options();
}
if ($target === 'maintenance') {
  $_POST['action'] = \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_ACTION;
  $application->ajax_handler();
}
$application->ajax_handler_dashboard_metrics();
