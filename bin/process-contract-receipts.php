<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
try {
  $stats = (new \SCM\App\SuCasaControlServiciosInmobiliarios(\SCM\Core\App::db()))->processAutomaticContractReceipts();
  echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
  if ($stats['errors']) exit(1);
} catch (Throwable $exception) {
  fwrite(STDERR, $exception->getMessage() . PHP_EOL);
  exit(1);
}
