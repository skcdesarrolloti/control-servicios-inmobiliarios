<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$storage = new \SCM\Modules\Pending\PublicServicesReviewStorage(\SCM\Core\App::db());
\SCM\Core\App::db()->pdo()->exec($storage->schemaSql());
$storage->requireSchema();
echo "Esquema de revisiones nativas preparado. Se conservan contratos y revisiones históricas.\n";
