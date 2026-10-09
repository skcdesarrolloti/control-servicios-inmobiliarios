<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$caseNotificationConfig = SCM\Support\PanelCaseNotifications::validateConfig([]);
$source = file_get_contents(dirname(__DIR__) . '/src/App/Concerns/RendersDashboard.php');
preg_match('/<section data-case-notifications[\s\S]+?<\/section>/', $source, $match);
if (!$match) throw new RuntimeException('Notification settings section missing.');
echo '<div id="root"><button id="scm-open-internal-notifications">Configuración</button><div id="scm-internal-notifications-modal"><button id="scm-close-internal-notifications">Cerrar</button><form id="scm-internal-notifications-form">';
eval('?>' . $match[0]);
echo '<small id="scm-internal-notifications-msg"></small><button type="submit">Guardar</button></form></div></div>';
