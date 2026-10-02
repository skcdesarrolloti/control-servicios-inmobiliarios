<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/** Immutable native review snapshots and durable idempotency; CCT remains authoritative. */
final class PublicServicesReviewStorage
{
  /** Schema is stable during a request; reuse verification across service instances. */
  private static ?\WeakMap $verified = null;
  public function __construct(private Database $db) {}

  public function table(): string { return $this->db->table('scm_public_services_reviews'); }

  public function schemaSql(): string
  {
    return 'CREATE TABLE IF NOT EXISTS `' . $this->table() . '` (
      request_key CHAR(64) NOT NULL PRIMARY KEY,
      contract_pk BIGINT UNSIGNED NOT NULL,
      review_id BIGINT UNSIGNED NOT NULL,
      snapshot_json MEDIUMTEXT NOT NULL,
      created_at BIGINT NOT NULL,
      UNIQUE KEY review_id (review_id), KEY contract_pk (contract_pk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  }

  public function requireSchema(): void
  {
    self::$verified ??= new \WeakMap();
    if (!empty((self::$verified[$this->db->pdo()] ?? [])[$this->table()])) return;
    $schema = new SchemaInspector($this->db);
    if (array_diff(['request_key','contract_pk','review_id','snapshot_json','created_at'], $schema->getTableColumns($this->table()))) {
      throw new \DomainException('Prepara el módulo ejecutando bin/migrate-public-services.php.');
    }
    $tables = [$this->table(), $this->db->table('jet_cct_contratos_arrendamiento'), $this->db->table('jet_cct_revisiones_servicios'), $this->db->table('jet_cct_historial_del_inmueble')];
    $engines = $this->db->getCol('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0,count($tables),'?')) . ')', $tables);
    if (count($engines) !== count($tables) || array_filter($engines, static fn($engine): bool => strcasecmp((string)$engine,'InnoDB') !== 0)) throw new \DomainException('Las tablas de revisiones, contratos e historial deben usar InnoDB para guardar la operación completa.');
    $verifiedTables = self::$verified[$this->db->pdo()] ?? [];
    $verifiedTables[$this->table()] = true;
    self::$verified[$this->db->pdo()] = $verifiedTables;
  }

  public function byRequest(string $key): ?array
  {
    return $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE request_key = ? FOR UPDATE', [$key]);
  }

  public function snapshot(int $reviewId): ?array
  {
    if (!(new SchemaInspector($this->db))->tableExists($this->table())) return null;
    $json = $this->db->getVar('SELECT snapshot_json FROM `' . $this->table() . '` WHERE review_id = ?', [$reviewId]);
    return $json ? json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR) : null;
  }

  public function insert(string $key, int $contractId, int $reviewId, array $snapshot): void
  {
    $this->db->insert($this->table(), ['request_key' => $key, 'contract_pk' => $contractId, 'review_id' => $reviewId,
      'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => time()]);
  }

  public static function formToken(array $contract, int $actor): string
  {
    $body = implode('|', [(int) $contract['_ID'], $actor, (int) ($contract['ultima_revision_servicios'] ?? 0), (int) ($contract['revisiones_servicios'] ?? 0), time() + 86400, bin2hex(random_bytes(16))]);
    return $body . '|' . hash_hmac('sha256', 'services-form|' . $body, (string) SCM_APP_SECRET);
  }

  public static function validateToken(string $token, array $contract, int $actor, bool $checkVersion = true): void
  {
    $parts = explode('|', $token);
    if (count($parts) !== 7) throw new \DomainException('Recarga el formulario de revisión.');
    $body = implode('|', array_slice($parts, 0, 6));
    if (!hash_equals(hash_hmac('sha256', 'services-form|' . $body, (string) SCM_APP_SECRET), $parts[6]) || (int) $parts[0] !== (int) $contract['_ID'] || (int) $parts[1] !== $actor || (int) $parts[4] <= time()) {
      throw new \DomainException('El formulario venció o no corresponde a este contrato y funcionario. Recárgalo.');
    }
    if ($checkVersion && ((int) $parts[2] !== (int) ($contract['ultima_revision_servicios'] ?? 0) || (int) $parts[3] !== (int) ($contract['revisiones_servicios'] ?? 0))) {
      throw new \DomainException('Ya se registró otra revisión de este contrato. Recarga el listado antes de continuar.');
    }
  }
}
