<?php
declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;
use SharedNotifications\Contracts\StorageAdapterInterface;

/** One boundary for EmailQueue and every module using the shared queue directly. */
final class ActorCopyNotificationStorage implements StorageAdapterInterface
{
  public function __construct(private StorageAdapterInterface $storage, private Database $db, private string $queueTable) {}
  public function ensureSchema(string $queueTable, string $attemptsTable): void { $this->storage->ensureSchema($queueTable, $attemptsTable); }
  public function insert(string $table, array $data): int { return $this->storage->insert($table, $table === $this->queueTable ? NotificationActorCopy::apply($this->db, $data) : $data); }
  public function fetchPending(string $table, int $limit, ?string $projectCode = null): array { return $this->storage->fetchPending($table, $limit, $projectCode); }
  public function fetchPendingFair(string $table, int $limit, ?string $projectCode = null, int $perProjectLimit = 5): array { return $this->storage->fetchPendingFair($table, $limit, $projectCode, $perProjectLimit); }
  public function claim(string $table, int $id, int $attempts, string $workerId, string $now): bool { return $this->storage->claim($table, $id, $attempts, $workerId, $now); }
  public function releaseStaleProcessing(string $table, string $now, int $staleMinutes = 15): int { return $this->storage->releaseStaleProcessing($table, $now, $staleMinutes); }
  public function cancelByDedupeKey(string $table, string $dedupeKey, string $now, string $reason): int { return $this->storage->cancelByDedupeKey($table, $dedupeKey, $now, $reason); }
  public function updateById(string $table, int $id, array $data): void { $this->storage->updateById($table, $id, $data); }
}
