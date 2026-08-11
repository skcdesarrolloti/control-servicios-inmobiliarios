<?php

declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/bootstrap/app.php';
ob_end_clean();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  \SCM\Http\Response\JsonResponse::error('Método no permitido.', 405);
}

$service = new \SCM\Modules\PublicTickets\PublicTicketsService($scmDb, $scmConfig);
$limiter = new \SCM\Support\FileRateLimiter(SCM_STORAGE_PATH . '/data/rate-limits');
$controller = new \SCM\Http\Controller\PublicTicketsApiController($service, $scmCsrf, $limiter);
$controller->dispatch($_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
