<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

(new \SCM\Modules\LegalCases\LegalCaseService(\SCM\Core\App::db(), \SCM\Core\App::settings()))->ensureSchema();

fwrite(STDOUT, "Esquema de casos jurídicos verificado.\n");
