<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require dirname(__DIR__) . '/bootstrap/app.php';
$repo = new \SCM\Modules\TicketCompletion\CompletionRepository(\SCM\Core\App::db());
$repo->db->pdo()->exec($repo->schemaSql());
$repo->requireSchema();
fwrite(STDOUT, "Esquema de actas de satisfacción preparado. No se modificaron tickets ni cobros existentes.\n");
