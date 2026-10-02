<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

// Default is read-only. --employee is the real CCT id_empleado, not the session _ID.
$options = getopt('', ['apply', 'employee:', 'contract:']);
$repo = new SCM\Modules\Pending\PendingRepository(SCM\Core\App::db());
$recovery = new SCM\Modules\Pending\PublicServicesDateRecovery($repo->getDb());
$rows = $repo->getContratosEntregados([]);
if (isset($options['contract'])) { $rows = array_values(array_filter($rows, static fn(array $row): bool => (int) $row['_ID'] === (int) $options['contract'])); }
$missing = array_values(array_filter($rows, static fn(array $row): bool => SCM\Modules\Pending\PublicServicesDateRecovery::timestamp($row['ultima_revision_servicios'] ?? null) === 0));
$evidence = $recovery->evidence($missing);
$report = ['mode' => isset($options['apply']) ? 'apply' : 'dry-run', 'missing' => count($missing), 'recoverable' => count($evidence), 'without_evidence' => count($missing) - count($evidence), 'repaired' => 0, 'contracts' => []];
foreach ($missing as $row) {
  $pk = (int) $row['_ID'];
  if (!isset($evidence[$pk])) { continue; }
  if (isset($options['apply']) && $recovery->repair($pk, (int) ($options['employee'] ?? 0))) { $report['repaired']++; }
  $report['contracts'][] = ['pk' => $pk, 'contract' => $row['contrato'], 'review_id' => $evidence[$pk]['review_id'], 'last' => date('Y-m-d', $evidence[$pk]['timestamp']), 'next' => date('Y-m-d', SCM\Modules\Pending\PublicServicesSchedule::next($evidence[$pk]['timestamp']))];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
