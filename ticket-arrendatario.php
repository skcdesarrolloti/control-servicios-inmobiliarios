<?php

require_once __DIR__ . '/bootstrap.php';

$actor = 'arrendatario';
$config = \SCM\Modules\PublicTickets\PublicTicketsService::getActorConfig($actor);
if (empty($config)) {
  http_response_code(500);
  echo 'Configuracion de actor no disponible.';
  exit;
}
$castorMap = isset($scmConfig['castor_images']) && is_array($scmConfig['castor_images']) ? $scmConfig['castor_images'] : [];
$castorImage = trim((string) ($castorMap[$actor] ?? ''));
if ($castorImage === '') {
  $castorImage = rtrim((string) SCM_BASE_URL, '/') . '/assets/img/castor-' . $actor . '.png';
}
$config['mascot_image'] = $castorImage;

$service = new \SCM\Modules\PublicTickets\PublicTicketsService($scmDb, $scmConfig);
$options = $service->getFormOptions();
$nonce = \SCM\Core\App::csrf()->token('public_ticket_portal');
$apiUrl = rtrim((string) SCM_BASE_URL, '/') . '/public-tickets-api.php';

$view = new \SCM\Views\PublicTicketPortalView();
$view->render($config, $options, $nonce, $apiUrl, (string) SCM_BASE_URL);
