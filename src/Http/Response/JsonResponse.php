<?php

declare(strict_types=1);

namespace SCM\Http\Response;

final class JsonResponse
{
  /** @param array<string,mixed> $data */
  public static function success(array $data, int $status = 200): never
  {
    self::send(['success' => true, 'data' => $data], $status);
  }

  public static function error(string $message, int $status = 400): never
  {
    self::send(['success' => false, 'data' => ['message' => $message]], $status);
  }

  /** @param array<string,mixed> $payload */
  private static function send(array $payload, int $status): never
  {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}
