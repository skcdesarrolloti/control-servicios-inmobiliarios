<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Core\App;
use SCM\Modules\PublicTickets\PublicTicketsService;
use SCM\Views\PublicTicketHubView;
use SCM\Views\PublicTicketPortalView;

final class PublicTicketPageController
{
  /** @var array<string,mixed> */
  private array $config;

  /** @param array<string,mixed> $config */
  public function __construct(array $config)
  {
    $this->config = $config;
  }

  public function renderHub(): void
  {
    $baseUrl = rtrim((string) SCM_BASE_URL, '/');
    $homeUrl = $this->resolveHomeUrl($baseUrl);
    $castorMap = is_array($this->config['castor_images'] ?? null)
      ? $this->config['castor_images']
      : [];

    $taglines = [
      'propietario' => 'Gestiona solicitudes de tus inmuebles',
      'arrendatario' => 'Reporta novedades de tu contrato',
      'copropiedad' => 'Canal para administraciones y edificios',
    ];
    $welcome = [
      'propietario' => 'Te acompañamos en la administración de tus inmuebles y solicitudes.',
      'arrendatario' => 'Estamos listos para ayudarte con reportes, consultas y solicitudes.',
      'copropiedad' => 'Centraliza la comunicación de tu copropiedad con nuestro equipo.',
    ];

    $roles = [];
    foreach (array_keys($taglines) as $actor) {
      $actorConfig = PublicTicketsService::getActorConfig($actor);
      if ($actorConfig === []) {
        continue;
      }
      $label = (string) ($actorConfig['label'] ?? ucfirst($actor));
      $roles[$actor] = [
        'label' => $label,
        'tagline' => $taglines[$actor],
        'description' => 'Flujo de consulta y radicación para ' . mb_strtolower($label, 'UTF-8') . '.',
        'welcome' => $welcome[$actor],
        'url' => $baseUrl . '/ticket-' . $actor,
        'mascot' => (string) ($castorMap[$actor] ?? ($baseUrl . '/assets/img/castor-' . $actor . '.png')),
      ];
    }

    if ($roles === []) {
      http_response_code(500);
      echo 'No hay perfiles disponibles.';
      return;
    }

    (new PublicTicketHubView())->render($roles, $baseUrl, $homeUrl);
  }

  public function renderActor(string $actor): void
  {
    $allowedActors = ['propietario', 'arrendatario', 'copropiedad'];
    if (!in_array($actor, $allowedActors, true)) {
      http_response_code(404);
      echo 'Perfil no disponible.';
      return;
    }

    $actorConfig = PublicTicketsService::getActorConfig($actor);
    if ($actorConfig === []) {
      http_response_code(500);
      echo 'Configuración de perfil no disponible.';
      return;
    }

    $castorMap = is_array($this->config['castor_images'] ?? null)
      ? $this->config['castor_images']
      : [];
    $actorConfig['mascot_image'] = (string) ($castorMap[$actor]
      ?? (rtrim((string) SCM_BASE_URL, '/') . '/assets/img/castor-' . $actor . '.png'));

    $service = new PublicTicketsService(App::db(), $this->config);
    $nonce = App::csrf()->token('public_ticket_portal');
    $apiUrl = rtrim((string) SCM_BASE_URL, '/') . '/public-tickets-api.php';

    (new PublicTicketPortalView())->render(
      $actorConfig,
      $service->getFormOptions(),
      $nonce,
      $apiUrl,
      (string) SCM_BASE_URL
    );
  }

  private function resolveHomeUrl(string $baseUrl): string
  {
    $parts = parse_url($baseUrl);
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
      return $parts['scheme'] . '://' . $parts['host'] . '/';
    }
    return '/';
  }
}
