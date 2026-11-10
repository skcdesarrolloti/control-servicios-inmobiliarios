<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Core\Database;

/**
 * Autoguardado del formulario en servidor. Una sola fila por funcionario, caso e inventario, con los datos comprimidos
 * y una copia de respaldo. El espacio queda acotado: guardar otra vez reemplaza la fila en lugar de agregar versiones.
 */
final class InventoryDraftStore
{
  public const MAX_BYTES = 3145728;
  public const EXPIRY_DAYS = 30;
  /** Antes de sobrescribir, el respaldo se renueva solo si tiene más de este tiempo. */
  private const BACKUP_SECONDS = 1800;

  private bool $ready = false;

  public function __construct(private Database $db)
  {
  }

  private function table(): string
  {
    return $this->db->table('scm_inventory_drafts');
  }

  private function ensure(): void
  {
    if ($this->ready) return;
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_key` VARCHAR(64) NOT NULL,
      `ticket_id` BIGINT UNSIGNED NOT NULL,
      `inventory_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
      `revision` INT UNSIGNED NOT NULL DEFAULT 1,
      `payload_hash` CHAR(64) NOT NULL,
      `payload` MEDIUMBLOB NOT NULL,
      `backup_payload` MEDIUMBLOB NULL,
      `backup_at` INT UNSIGNED NOT NULL DEFAULT 0,
      `updated_at` INT UNSIGNED NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `user_case_inventory` (`user_key`, `ticket_id`, `inventory_id`),
      KEY `updated_at` (`updated_at`)
    ) DEFAULT CHARSET=utf8mb4");
    $this->ready = true;
  }

  /** @return array{revision:int,updated_at:int,payload:array<string,mixed>}|null */
  public function get(string $user, int $ticketId, int $inventoryId): ?array
  {
    $this->ensure();
    $row = $this->db->getRow("SELECT `revision`, `payload`, `updated_at` FROM `{$this->table()}` WHERE `user_key` = ? AND `ticket_id` = ? AND `inventory_id` = ? LIMIT 1", [$user, $ticketId, $inventoryId]);
    if (!$row) return null;
    if ((int) $row['updated_at'] < time() - self::EXPIRY_DAYS * 86400) {
      $this->delete($user, $ticketId, $inventoryId);
      return null;
    }
    $payload = json_decode((string) @gzinflate((string) $row['payload']), true);
    return is_array($payload) ? ['revision' => (int) $row['revision'], 'updated_at' => (int) $row['updated_at'], 'payload' => $payload] : null;
  }

  /**
   * Guarda si el borrador no cambió desde la revisión que tiene el navegador; si otro dispositivo guardó después,
   * responde conflicto y no escribe.
   *
   * @return array{status:'saved'|'unchanged'|'conflict',revision:int,updated_at:int}
   */
  public function save(string $user, int $ticketId, int $inventoryId, string $json, int $baseRevision): array
  {
    $this->ensure();
    if (strlen($json) > self::MAX_BYTES || !is_array(json_decode($json, true))) {
      throw new \DomainException('El borrador no es válido o es demasiado grande.');
    }
    $now = time();
    $hash = hash('sha256', $json);
    $current = $this->db->getRow("SELECT `id`, `revision`, `payload`, `payload_hash`, `backup_at`, `updated_at` FROM `{$this->table()}` WHERE `user_key` = ? AND `ticket_id` = ? AND `inventory_id` = ? LIMIT 1", [$user, $ticketId, $inventoryId]);
    $compressed = (string) gzdeflate($json, 6);
    if (!$current) {
      $this->db->insert($this->table(), ['user_key' => $user, 'ticket_id' => $ticketId, 'inventory_id' => $inventoryId, 'revision' => 1, 'payload_hash' => $hash, 'payload' => $compressed, 'updated_at' => $now]);
      if (random_int(1, 50) === 1) $this->purgeExpired();
      return ['status' => 'saved', 'revision' => 1, 'updated_at' => $now];
    }
    $revision = (int) $current['revision'];
    if ($baseRevision !== $revision) {
      return ['status' => 'conflict', 'revision' => $revision, 'updated_at' => (int) $current['updated_at']];
    }
    if (hash_equals((string) $current['payload_hash'], $hash)) {
      return ['status' => 'unchanged', 'revision' => $revision, 'updated_at' => (int) $current['updated_at']];
    }
    $update = ['revision' => $revision + 1, 'payload_hash' => $hash, 'payload' => $compressed, 'updated_at' => $now];
    if ((int) $current['backup_at'] < $now - self::BACKUP_SECONDS) {
      $update['backup_payload'] = (string) $current['payload'];
      $update['backup_at'] = $now;
    }
    $this->db->update($this->table(), $update, ['id' => (int) $current['id']]);
    return ['status' => 'saved', 'revision' => $revision + 1, 'updated_at' => $now];
  }

  public function delete(string $user, int $ticketId, int $inventoryId): void
  {
    $this->ensure();
    $this->db->delete($this->table(), ['user_key' => $user, 'ticket_id' => $ticketId, 'inventory_id' => $inventoryId]);
  }

  public function purgeExpired(): int
  {
    $this->ensure();
    $stmt = $this->db->pdo()->prepare("DELETE FROM `{$this->table()}` WHERE `updated_at` < ?");
    $stmt->execute([time() - self::EXPIRY_DAYS * 86400]);
    return $stmt->rowCount();
  }
}
