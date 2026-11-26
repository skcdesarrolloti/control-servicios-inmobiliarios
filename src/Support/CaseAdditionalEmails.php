<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;

/**
 * Correos adicionales (CC) que un funcionario usó al responder o hacer seguimiento de un caso.
 * Se guardan en `correos_adicionales` del caso para volver a elegirlos sin escribirlos de nuevo.
 */
final class CaseAdditionalEmails
{
  public const COLUMN = 'correos_adicionales';
  public const MAX_SAVED = 50;

  public function __construct(private Database $db, private SchemaInspector $schema) {}

  /** @return string[] */
  public static function decode($raw): array
  {
    $emails = [];
    foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
      $email = strtolower(trim($email));
      if (strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[$email] = $email;
    }
    return array_values($emails);
  }

  /** @return string[] */
  public function saved(int $ticketPk): array
  {
    $table = $this->table();
    if ($ticketPk <= 0 || !$this->schema->columnExists($table, self::COLUMN)) return [];
    return self::decode($this->db->getVar('SELECT `' . self::COLUMN . '` FROM `' . $table . '` WHERE `_ID` = ? LIMIT 1', [$ticketPk]) ?? '');
  }

  /**
   * Agrega los correos usados al caso (sin duplicar) y devuelve la lista guardada.
   * @param string[] $emails
   * @return string[]
   */
  public function remember(int $ticketPk, array $emails): array
  {
    $current = $this->saved($ticketPk);
    $new = self::decode(implode(';', $emails));
    if ($new === [] || !$this->schema->columnExists($this->table(), self::COLUMN)) return $current;
    // Los más recientes quedan primero y se conserva un máximo para no crecer sin límite.
    $merged = array_slice(array_values(array_unique(array_merge($new, $current))), 0, self::MAX_SAVED);
    if ($merged !== $current) $this->store($ticketPk, $merged);
    return $merged;
  }

  /** @return string[] */
  public function forget(int $ticketPk, string $email): array
  {
    $current = $this->saved($ticketPk);
    $email = strtolower(trim($email));
    $remaining = array_values(array_filter($current, static fn(string $saved): bool => $saved !== $email));
    if ($remaining !== $current) $this->store($ticketPk, $remaining);
    return $remaining;
  }

  /** @param string[] $emails */
  private function store(int $ticketPk, array $emails): void
  {
    $table = $this->table();
    $update = [self::COLUMN => implode('; ', $emails)];
    if ($this->schema->columnExists($table, 'cct_modified')) $update['cct_modified'] = date('Y-m-d H:i:s');
    $this->db->update($table, $update, ['_ID' => $ticketPk]);
  }

  private function table(): string
  {
    return $this->db->table('jet_cct_tickets');
  }
}
