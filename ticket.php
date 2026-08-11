<?php

require_once __DIR__ . '/bootstrap.php';

$service = new \SCM\Modules\PublicTickets\PublicTicketsService($scmDb, $scmConfig);
$actors = ['propietario', 'arrendatario', 'copropiedad'];
$baseUrl = rtrim((string) SCM_BASE_URL, '/');

$homeUrl = 'https://sucasainmobiliaria.com.co/';
if ($baseUrl !== '') {
  $parts = parse_url($baseUrl);
  if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
    $homeUrl = $parts['scheme'] . '://' . $parts['host'] . '/';
  }
}

$castorMap = [];
if (isset($scmConfig['castor_images']) && is_array($scmConfig['castor_images'])) {
  foreach ($scmConfig['castor_images'] as $actorId => $imgUrl) {
    $actorId = sanitize_key((string) $actorId);
    $imgUrl = trim((string) $imgUrl);
    if ($actorId !== '' && $imgUrl !== '') {
      $castorMap[$actorId] = $imgUrl;
    }
  }
}

$defaultTagline = [
  'propietario' => 'Gestiona solicitudes de tus inmuebles',
  'arrendatario' => 'Reporta novedades de tu contrato',
  'copropiedad' => 'Canal para administraciones y edificios',
];

$defaultWelcome = [
  'propietario' => 'Te acompañamos en la administración de tus inmuebles y solicitudes.',
  'arrendatario' => 'Estamos listos para ayudarte con reportes, consultas y solicitudes.',
  'copropiedad' => 'Centraliza la comunicación de tu copropiedad con nuestro equipo.',
];

$roles = [];
foreach ($actors as $actor) {
  $cfg = \SCM\Modules\PublicTickets\PublicTicketsService::getActorConfig($actor);
  if (empty($cfg)) {
    continue;
  }

  $actorLabel = (string) ($cfg['label'] ?? ucfirst($actor));
  $roles[$actor] = [
    'label' => $actorLabel,
    'tagline' => (string) ($defaultTagline[$actor] ?? 'Portal especializado'),
    'description' => 'Flujo de consulta y radicación para ' . mb_strtolower($actorLabel, 'UTF-8') . '.',
    'welcome' => (string) ($defaultWelcome[$actor] ?? 'Selecciona este perfil para continuar.'),
    'url' => $baseUrl . '/ticket-' . $actor,
    'mascot' => (string) ($castorMap[$actor] ?? ($baseUrl . '/assets/img/castor-' . $actor . '.png')),
  ];
}

if (empty($roles)) {
  http_response_code(500);
  echo 'No hay perfiles disponibles.';
  exit;
}

$view = new \SCM\Views\PublicTicketHubView();
$view->render($roles, $baseUrl, $homeUrl);
