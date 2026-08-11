<?php

declare(strict_types=1);

namespace SCM\Http\Controller;

use SCM\Core\Csrf;
use SCM\Http\Response\JsonResponse;
use SCM\Modules\PublicTickets\PublicTicketsService;
use SCM\Support\FileRateLimiter;

final class PublicTicketsApiController
{
  private PublicTicketsService $service;
  private Csrf $csrf;
  private FileRateLimiter $limiter;

  public function __construct(PublicTicketsService $service, Csrf $csrf, FileRateLimiter $limiter)
  {
    $this->service = $service;
    $this->csrf = $csrf;
    $this->limiter = $limiter;
  }

  /** @param array<string,mixed> $input */
  public function dispatch(array $input, string $ipAddress): never
  {
    $action = sanitize_key((string) ($input['action'] ?? ''));
    $rules = [
      'public_ticket_lookup' => [30, 300],
      'public_ticket_requests' => [30, 300],
      'public_ticket_create' => [10, 600],
    ];
    if (!isset($rules[$action])) {
      JsonResponse::error('Acción desconocida.', 400);
    }

    [$maximumAttempts, $windowSeconds] = $rules[$action];
    if (!$this->limiter->consume($action . '|' . $ipAddress, $maximumAttempts, $windowSeconds)) {
      JsonResponse::error('Demasiados intentos. Espera unos minutos e inténtalo nuevamente.', 429);
    }

    $nonce = (string) ($input['nonce'] ?? '');
    if (!$this->csrf->verify('public_ticket_portal', $nonce, false)) {
      JsonResponse::error('Verificación de seguridad fallida.', 403);
    }

    try {
      if ($action === 'public_ticket_lookup') {
        $this->lookup($input);
      }
      if ($action === 'public_ticket_requests') {
        $this->requests($input);
      }
      $this->create($input);
    } catch (\Throwable $exception) {
      $requestId = bin2hex(random_bytes(8));
      error_log(sprintf(
        '[public-api:%s] %s in %s:%d',
        $requestId,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
      ));
      JsonResponse::error('Error interno. Referencia: ' . $requestId, 500);
    }
  }

  /** @param array<string,mixed> $input */
  private function lookup(array $input): never
  {
    $result = $this->service->lookup(
      sanitize_key((string) ($input['actor'] ?? '')),
      sanitize_text_field((string) ($input['lookup_value'] ?? ''))
    );
    $this->respondWithServiceResult($result, 'No se pudo realizar la consulta.');
  }

  /** @param array<string,mixed> $input */
  private function requests(array $input): never
  {
    $result = $this->service->lookupRequests(
      sanitize_key((string) ($input['actor'] ?? '')),
      sanitize_text_field((string) ($input['selected_id'] ?? '')),
      sanitize_text_field((string) ($input['lookup_value'] ?? ''))
    );
    $this->respondWithServiceResult($result, 'No se pudieron consultar las solicitudes.');
  }

  /** @param array<string,mixed> $input */
  private function create(array $input): never
  {
    $email = sanitize_text_field((string) ($input['correo_solicitante'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      JsonResponse::error('El correo del solicitante no es válido.');
    }

    $payload = [
      'actor_id' => sanitize_text_field((string) ($input['actor_id'] ?? '')),
      'solicitante' => sanitize_text_field((string) ($input['solicitante'] ?? '')),
      'correo_solicitante' => $email,
      'celular_solicitante' => sanitize_text_field((string) ($input['celular_solicitante'] ?? '')),
      'indicativo' => sanitize_text_field((string) ($input['indicativo'] ?? '+57')),
      'asunto' => sanitize_text_field((string) ($input['asunto'] ?? '')),
      'tema_ayuda' => sanitize_text_field((string) ($input['tema_ayuda'] ?? $input['tipo_pqrs'] ?? '')),
      'descripcion' => sanitize_textarea_field((string) ($input['descripcion'] ?? '')),
    ];

    $result = $this->service->createTicket(
      sanitize_key((string) ($input['actor'] ?? '')),
      sanitize_text_field((string) ($input['selected_id'] ?? '')),
      sanitize_text_field((string) ($input['lookup_value'] ?? '')),
      $payload
    );
    $this->respondWithServiceResult($result, 'No se pudo crear la solicitud.');
  }

  /** @param array<string,mixed> $result */
  private function respondWithServiceResult(array $result, string $fallback): never
  {
    if (empty($result['ok'])) {
      JsonResponse::error((string) ($result['message'] ?? $fallback));
    }
    JsonResponse::success($result);
  }
}
