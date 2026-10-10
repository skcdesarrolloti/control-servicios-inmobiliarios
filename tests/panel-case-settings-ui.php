<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$app = new SCM\App\SuCasaControlServiciosInmobiliarios(SCM\Core\App::db());
$render = new ReflectionMethod($app, 'renderDashboardInternalNotificationsModal');
echo '<div id="scm-app"><button id="scm-open-internal-notifications">Configuración</button>' . $render->invoke($app) . '</div>';
