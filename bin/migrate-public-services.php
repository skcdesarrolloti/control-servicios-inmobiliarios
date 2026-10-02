<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$storage = new \SCM\Modules\Pending\PublicServicesReviewStorage(\SCM\Core\App::db());
$db = \SCM\Core\App::db();
$contracts = $db->table('jet_cct_contratos_arrendamiento');
if (!(new \SCM\Support\SchemaInspector($db))->columnExists($contracts, 'proxima_revision_servicios')) {
  $db->pdo()->exec("ALTER TABLE `{$contracts}` ADD COLUMN `proxima_revision_servicios` BIGINT NULL DEFAULT NULL");
}
\SCM\Core\App::db()->pdo()->exec($storage->schemaSql());
$storage->requireSchema();
echo "Esquema de revisiones nativas preparado. Se conservan contratos y revisiones históricas.\n";
