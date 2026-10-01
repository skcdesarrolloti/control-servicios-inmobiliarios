<?php
declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\Database;
use SharedNotifications\Contracts\StorageAdapterInterface;

/** Runs the shared worker for one notification without draining other projects. */
final class TargetedNotificationStorage implements StorageAdapterInterface
{
  public function __construct(private StorageAdapterInterface $storage, private Database $db, private int $id, private string $project) {}
  public function ensureSchema(string $queueTable, string $attemptsTable): void { $this->storage->ensureSchema($queueTable, $attemptsTable); }
  public function insert(string $table, array $data): int { return $this->storage->insert($table, $data); }
  public function fetchPending(string $table, int $limit, ?string $projectCode = null): array
  {
    if ($projectCode !== null && $projectCode !== $this->project) return [];
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `id` = ? AND `project_code` = ? AND `status` = 'pending'
      AND `scheduled_at` <= UTC_TIMESTAMP() AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= UTC_TIMESTAMP())
      AND (`locked_at` IS NULL OR `locked_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))", [$this->id, $this->project]);
    return $row ? [$row] : [];
  }
  public function fetchPendingFair(string $table, int $limit, ?string $projectCode = null, int $perProjectLimit = 5): array { return $this->fetchPending($table, $limit, $projectCode); }
  public function claim(string $table, int $id, int $attempts, string $workerId, string $now): bool { return $id === $this->id && $this->storage->claim($table, $id, $attempts, $workerId, $now); }
  public function releaseStaleProcessing(string $table, string $now, int $staleMinutes = 15): int { return 0; }
  public function cancelByDedupeKey(string $table, string $dedupeKey, string $now, string $reason): int
  {
    $matches = $this->db->getVar("SELECT `id` FROM `{$table}` WHERE `id` = ? AND `project_code` = ? AND `dedupe_key` = ?", [$this->id, $this->project, $dedupeKey]);
    return $matches ? $this->storage->cancelByDedupeKey($table, $dedupeKey, $now, $reason) : 0;
  }
  public function updateById(string $table, int $id, array $data): void { if ($id !== $this->id) throw new \LogicException('Notification outside immediate dispatch scope.'); $this->storage->updateById($table, $id, $data); }
}
