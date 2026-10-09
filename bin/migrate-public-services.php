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
$critical = new \SCM\Modules\Pending\PublicServicesCritical($db);
foreach ($critical->schema() as $sql) $db->pdo()->exec($sql);
// Native critical case + review + histories must commit or roll back together.
foreach (['jet_cct_tickets','jet_cct_historial_del_ticket','jet_cct_historial_del_inmueble'] as $suffix) {
  $table=$db->table($suffix);
  $definition=$db->getRow('SHOW CREATE TABLE `'.$table.'`');
  if(!preg_match('/\bENGINE=InnoDB\b/i',(string)($definition['Create Table']??''))) {
    echo 'Preparando transacciones en '.$table."...\n";
    $db->pdo()->exec('ALTER TABLE `'.$table.'` ENGINE=InnoDB');
  }
}
$critical->requireSchema();
echo "Esquema de revisiones nativas preparado. Se conservan contratos y revisiones históricas.\n";
