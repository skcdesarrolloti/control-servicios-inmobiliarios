<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
(new \SCM\Modules\Contracts\ContractRenewalService(\SCM\Core\App::db()))->ensureSchema();
echo "Esquema de renovación, recordatorios e historial preparado.\n";
