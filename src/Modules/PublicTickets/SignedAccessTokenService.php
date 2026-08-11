<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets;

final class SignedAccessTokenService
{
  private string $secret;
  private string $baseUrl;

  public function __construct(string $secret, string $baseUrl)
  {
    if (strlen($secret) < 32) {
      throw new \InvalidArgumentException('El secreto de enlaces firmados no es válido.');
    }
    $this->secret = $secret;
    $this->baseUrl = rtrim($baseUrl, '/');
  }

  public function buildUrl(string $employeeId, int $ttlSeconds, string $scope): string
  {
    $employeeId = $this->normalizeEmployeeId($employeeId);
    if ($employeeId === '' || $this->baseUrl === '') {
      return '';
    }

    $scope = $this->normalizeScope($scope);
    $expiresAt = time() + max(3600, $ttlSeconds);
    $token = $this->buildCompactToken($employeeId, $expiresAt, $scope);
    return $this->baseUrl . '/solicitudes-web.php?k=' . rawurlencode($token);
  }

  /** @return array{id_empleado:string,scope:string,exp:string,sig:string,token:string}|null */
  public function resolve(string $token): ?array
  {
    $token = trim($token);
    if ($token === '' || preg_match('/^[A-Za-z0-9._-]+$/', $token) !== 1) {
      return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 4) {
      return null;
    }

    [$employeeId, $scopeCode, $expiresCompact, $signature] = $parts;
    $employeeId = $this->normalizeEmployeeId((string) $employeeId);
    $scope = $scopeCode === 'g' ? 'all' : ($scopeCode === 'a' ? 'assigned' : '');
    $expiresAt = $this->decodeBase36Int((string) $expiresCompact);
    if ($employeeId === '' || $scope === '' || $signature === '' || $expiresAt <= time()) {
      return null;
    }
    if (!hash_equals($this->signCompact($employeeId, $expiresAt, $scope), (string) $signature)) {
      return null;
    }

    return [
      'id_empleado' => $employeeId,
      'scope' => $scope,
      'exp' => (string) $expiresAt,
      'sig' => $this->signLong($employeeId, $expiresAt, $scope),
      'token' => $token,
    ];
  }

  public function validate(string $employeeId, string $expiresAtRaw, string $signature, string $scope): bool
  {
    $employeeId = $this->normalizeEmployeeId($employeeId);
    $scope = $this->normalizeScope($scope);
    $expiresAt = (int) trim($expiresAtRaw);
    $signature = trim($signature);
    if ($employeeId === '' || $signature === '' || $expiresAt <= time()) {
      return false;
    }

    return hash_equals($this->signLong($employeeId, $expiresAt, $scope), $signature);
  }

  private function buildCompactToken(string $employeeId, int $expiresAt, string $scope): string
  {
    $scopeCode = $scope === 'all' ? 'g' : 'a';
    $expiresCompact = strtolower(base_convert((string) $expiresAt, 10, 36));
    return $employeeId . '.' . $scopeCode . '.' . $expiresCompact . '.'
      . $this->signCompact($employeeId, $expiresAt, $scope);
  }

  private function signLong(string $employeeId, int $expiresAt, string $scope): string
  {
    return hash_hmac('sha256', $employeeId . '|' . $expiresAt . '|' . $scope . '|public_pqr_access', $this->secret);
  }

  private function signCompact(string $employeeId, int $expiresAt, string $scope): string
  {
    $binary = hash_hmac(
      'sha256',
      $employeeId . '|' . $expiresAt . '|' . $scope . '|public_pqr_access_short',
      $this->secret,
      true
    );
    return rtrim(strtr(base64_encode(substr($binary, 0, 12)), '+/', '-_'), '=');
  }

  private function decodeBase36Int(string $value): int
  {
    if ($value === '' || preg_match('/^[0-9a-z]+$/i', $value) !== 1) {
      return 0;
    }
    $decoded = base_convert(strtolower($value), 36, 10);
    return (int) $decoded;
  }

  private function normalizeEmployeeId(string $employeeId): string
  {
    $normalized = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeId));
    return is_string($normalized) ? $normalized : '';
  }

  private function normalizeScope(string $scope): string
  {
    return mb_strtolower(trim($scope), 'UTF-8') === 'all' ? 'all' : 'assigned';
  }
}
