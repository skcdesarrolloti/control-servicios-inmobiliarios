<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Core\Database;

/** Recover actual historical review dates; never infer completion from delivery or a month. */
final class PublicServicesDateRecovery
{
  private PendingRepository $repo;

  public function __construct(private Database $db)
  {
    $this->repo = new PendingRepository($db);
  }

  public static function timestamp(mixed $value): int
  {
    if ($value === null || trim((string) $value) === '') { return 0; }
    if (is_numeric($value)) {
      $timestamp = (int) $value;
      return $timestamp > 9999999999 ? (int) floor($timestamp / 1000) : max(0, $timestamp);
    }
    return max(0, (int) strtotime((string) $value));
  }

  /** @return array<int,array{timestamp:int,review_id:int}> */
  public function evidence(array $contracts, ?int $now = null): array
  {
    $now ??= time();
    $missing = [];
    foreach ($contracts as $contract) {
      if (self::timestamp($contract['ultima_revision_servicios'] ?? null) === 0 && (int) ($contract['_ID'] ?? 0) > 0) {
        $missing[(int) $contract['_ID']] = $contract;
      }
    }
    if (!$missing) { return []; }
    $table = $this->db->table('jet_cct_revisiones_servicios');
    $marks = implode(',', array_fill(0, count($missing), '?'));
    $reviews = $this->db->getResults("SELECT `_ID`,`id_contrato`,`id_inmueble`,`contrato`,`fecha`,`fecha_revision_luz`,`fecha_revision_agua`,`fecha_revision_gas` FROM `{$table}` WHERE `id_contrato` IN ({$marks}) AND `cct_status` = 'publish'", array_keys($missing));
    $out = [];
    foreach ($reviews as $review) {
      $pk = (int) $review['id_contrato'];
      $contract = $missing[$pk] ?? null;
      // Exact CCT contract and property identity are required, including pre-delivery placeholders.
      if (!$contract || trim((string) $review['id_contrato']) !== (string) $pk
        || trim((string) ($contract['id_inmueble'] ?? '')) === ''
        || (string) $review['id_inmueble'] !== (string) $contract['id_inmueble']) { continue; }
      $code = trim((string) $review['contrato']);
      if ($code !== trim((string) ($contract['contrato'] ?? '')) && !in_array($code, ['', 'Contrato sin entregar'], true)) { continue; }
      $timestamp = self::timestamp($review['fecha']);
      if ($timestamp === 0) {
        $dates = array_filter(array_map([self::class, 'timestamp'], [$review['fecha_revision_luz'], $review['fecha_revision_agua'], $review['fecha_revision_gas']]));
        $timestamp = $dates ? max($dates) : 0;
      }
      // Reject placeholders, future records and undocumented creation-time guesses.
      if ($timestamp < 946702800 || $timestamp > $now) { continue; }
      if ($timestamp > ($out[$pk]['timestamp'] ?? 0)
        || ($timestamp === ($out[$pk]['timestamp'] ?? 0) && (int) $review['_ID'] > $out[$pk]['review_id'])) {
        $out[$pk] = ['timestamp' => $timestamp, 'review_id' => (int) $review['_ID']];
      }
    }
    return $out;
  }

  /** Atomic, audited and idempotent; does not generate reviews, acts or notifications. */
  public function repair(int $contractPk, int $employeeId): bool
  {
    (new PublicServicesReviewStorage($this->db))->requireSchema();
    $employee = $this->db->getRow('SELECT `id_empleado`,`nombre` FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE `id_empleado` = ? AND `activo` = ? LIMIT 1', [(string) $employeeId, 'Si']);
    if (!$employee || $employeeId <= 0) { throw new \RuntimeException('Se requiere id_empleado de un funcionario activo.'); }
    $pdo = $this->db->pdo();
    if ($pdo->inTransaction()) { throw new \RuntimeException('La reparación requiere una transacción propia.'); }
    $pdo->beginTransaction();
    try {
      $repo = $this->repo;
      $contract = $repo->getPublicServicesContract($contractPk, true);
      if (!$contract || strtolower(trim((string) $contract['estado'])) !== 'entregado' || self::timestamp($contract['ultima_revision_servicios'] ?? null) > 0) {
        $pdo->rollBack(); return false;
      }
      $evidence = $this->evidence([$contract])[$contractPk] ?? null;
      if (!$evidence) { $pdo->rollBack(); return false; }
      $month = (int) date('n', (int)($contract['proxima_revision_servicios'] ?? 0) > 0 ? (int)$contract['proxima_revision_servicios'] : PublicServicesSchedule::next($evidence['timestamp']));
      $now = date('Y-m-d H:i:s');
      $payload = ['ultima_revision_servicios' => $evidence['timestamp'], 'mes_revision_servicios' => $month, 'cct_modified' => $now, 'cct_author_id' => $employeeId];
      if ($repo->updateContratoArrendamiento($contractPk, $payload) !== 1) { throw new \RuntimeException('No se actualizó el contrato.'); }
      $audit = ['contract_pk' => $contractPk, 'review_id' => $evidence['review_id'], 'previous' => ['ultima_revision_servicios' => $contract['ultima_revision_servicios'] ?? null, 'mes_revision_servicios' => $contract['mes_revision_servicios'] ?? null], 'recovered' => ['ultima_revision_servicios' => $evidence['timestamp'], 'mes_revision_servicios' => $month]];
      if (!$repo->insertHistorialInmueble([
        'cct_status' => 'publish', 'cct_author_id' => $employeeId, 'cct_created' => $now, 'cct_modified' => $now,
        'id_empleado' => $employeeId, 'funcionario' => $employee['nombre'], 'id_inmueble' => $contract['id_inmueble'],
        'fecha' => time(), 'tipo_reporte' => 'Contractual', 'id_revision_servicios_publicos' => (string) $evidence['review_id'],
        'observacion' => 'Recuperación de fecha histórica de servicios públicos del contrato #' . $contract['contrato'] . '. No se creó una nueva revisión. Auditoría: ' . json_encode($audit, JSON_UNESCAPED_UNICODE),
      ])) { throw new \RuntimeException('No se guardó la auditoría.'); }
      $pdo->commit(); return true;
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      throw $e;
    }
  }
}
