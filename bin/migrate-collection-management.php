<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$service = new \SCM\Modules\CollectionManagement\CollectionPortfolioService(\SCM\Core\App::db());
$service->migrateSchema();

fwrite(STDOUT, "Esquema de control de cartera y gestiones de cobro verificado.\n");
