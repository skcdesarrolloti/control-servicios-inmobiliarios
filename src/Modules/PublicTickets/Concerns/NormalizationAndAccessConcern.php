<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait NormalizationAndAccessConcern
{
  private function getDistinctTicketValues(string $column, array $fallback): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable) || !$this->schema->columnExists($ticketsTable, $column)) {
      return $fallback;
    }

    $sql = "SELECT TRIM(COALESCE(`{$column}`, '')) AS value
              FROM `{$ticketsTable}`
             WHERE TRIM(COALESCE(`{$column}`, '')) <> ''
             GROUP BY value
             ORDER BY COUNT(*) DESC
             LIMIT 60";
    $rows = $this->db->getCol($sql);
    $out = [];
    foreach ($rows as $value) {
      $text = trim((string) $value);
      if ($text !== '') {
        $out[] = $text;
      }
    }

    if (empty($out)) {
      return $fallback;
    }

    foreach ($fallback as $candidate) {
      if (!in_array($candidate, $out, true)) {
        $out[] = $candidate;
      }
    }

    return array_values(array_unique($out));
  }

  private function normalizeIndicativo(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '+57';
    }
    $clean = preg_replace('/[^0-9+]/', '', $value);
    if (!is_string($clean) || $clean === '') {
      return '+57';
    }
    if ($clean[0] !== '+') {
      $clean = '+' . ltrim($clean, '+');
    }
    return $clean;
  }

  private function normalizeAlphaNum(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^a-z0-9]/u', '', $value);
    return is_string($value) ? $value : '';
  }

  private function normalizeDigits(string $value): string
  {
    $value = preg_replace('/\D+/', '', $value);
    return is_string($value) ? $value : '';
  }

  private function normalizePqrTheme(string $value): string
  {
    return self::normalizePqrsTypeValue($value);
  }

  /** @return array{enabled:bool,email:string,phone:string,name:string,id_empleado:string} */
  private function resolveClienteNotificationsOverride(string $actor): array
  {
    $isCliente = mb_strtolower(trim($actor), 'UTF-8') === 'cliente';
    if (!$isCliente) {
      return ['enabled' => false, 'email' => '', 'phone' => '', 'name' => '', 'id_empleado' => ''];
    }

    $email = trim((string) ($this->clienteNotificationsOverride['email'] ?? ''));
    $phone = trim((string) ($this->clienteNotificationsOverride['whatsapp'] ?? $this->clienteNotificationsOverride['phone'] ?? ''));
    $name = trim((string) ($this->clienteNotificationsOverride['name'] ?? 'Monitoreo temporal cliente'));
    $employeeId = trim((string) ($this->clienteNotificationsOverride['id_empleado'] ?? ''));
    $enabled = !empty($this->clienteNotificationsOverride['enabled']) && ($email !== '' || $phone !== '' || $employeeId !== '');

    return [
      'enabled' => $enabled,
      'email' => $email,
      'phone' => $phone,
      'name' => $name,
      'id_empleado' => $employeeId,
    ];
  }

  private function normalizePublicPqrTheme(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $needle = mb_strtolower($value, 'UTF-8');
    foreach (self::getPqrThemes() as $theme) {
      if (mb_strtolower($theme, 'UTF-8') === $needle) {
        return $theme;
      }
    }
    return '';
  }

  private static function normalizePqrsTypeValue(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $needle = mb_strtolower($value, 'UTF-8');
    foreach (self::PQR_THEMES as $theme) {
      if (mb_strtolower($theme, 'UTF-8') === $needle) {
        return $theme;
      }
    }
    return '';
  }

  private function normalizePhoneForDedup(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $isPlus = isset($value[0]) && $value[0] === '+';
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits) || $digits === '') {
      return '';
    }

    if (strlen($digits) <= 10 && !$isPlus) {
      $digits = '57' . $digits;
    }

    return '+' . ltrim($digits, '+');
  }

  /** @return array<int,string> */
  private function buildPhoneTerms(string $value): array
  {
    $digits = $this->normalizeDigits($value);
    if ($digits === '') {
      return [];
    }

    $terms = [$digits];
    if (strlen($digits) > 10) {
      $terms[] = substr($digits, -10);
    }
    if (strlen($digits) > 7) {
      $terms[] = substr($digits, -7);
    }

    return array_values(array_unique(array_filter($terms, static fn($term) => is_string($term) && $term !== '')));
  }

  /**
   * @param array<string,mixed> $client
   * @param array<string,array<int,string>> $columns
   * @return array{0:array<int,string>,1:array<int,string>}
   */
  private function buildClientLookupConditions(string $table, array $client, array $columns): array
  {
    $where = [];
    $args = [];
    $physicalId = trim((string) ($client['_ID'] ?? ''));

    foreach ($columns as $column => $modes) {
      if (!$this->schema->columnExists($table, $column)) {
        continue;
      }

      foreach ($modes as $mode) {
        if ($mode === 'exact') {
          if ($physicalId !== '') {
            $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
            $args[] = $physicalId;
          }
        }
      }
    }

    return [$where, $args];
  }

  public function buildResponsiblePublicPqrUrl(string $employeeId, int $ttlSeconds = 2592000, string $accessScope = 'assigned'): string
  {
    return $this->signedAccess->buildUrl($employeeId, $ttlSeconds, $accessScope);
  }

  /** @return array{id_empleado:string,scope:string,exp:string,sig:string,token:string}|null */
  public function resolveResponsiblePublicPqrAccessToken(string $token): ?array
  {
    return $this->signedAccess->resolve($token);
  }

  public function validateResponsiblePublicPqrAccess(string $employeeId, string $expiresAtRaw, string $signature, string $accessScope = 'assigned'): bool
  {
    return $this->signedAccess->validate($employeeId, $expiresAtRaw, $signature, $accessScope);
  }

  /** @param array<string,mixed> $config */
  private function getPqrCreatedByLabel(string $actor, array $config): string
  {
    $label = trim((string) ($config['label'] ?? ''));
    if ($label !== '') {
      return $label;
    }

    return ucfirst($actor);
  }
}
