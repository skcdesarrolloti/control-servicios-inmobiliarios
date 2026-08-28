<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$service = new \SCM\Modules\CanonInsuranceAudit\CanonInsuranceAuditService(\SCM\Core\App::db());
$service->migrateSchema();
$service->syncIncrementChanges();

fwrite(STDOUT, "Cambios de incrementos sincronizados.\n");
