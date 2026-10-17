<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Auditoría del contrato de arrendamiento contra SIMI.
 *
 * Guarda `estado_simi` (Correcto/Incorrecto) y `observaciones_estado_simi` en el contrato y deja
 * constancia en el historial del inmueble. Solo toca esos dos campos y `cct_modified`: el
 * funcionario, el autor y demás datos del contrato se conservan.
 */
final class ContractSimiAudit
{
  public const STATES = ['Correcto', 'Incorrecto'];
  public const MAX_OBSERVATION = 2000;

  private Database $db;
  private SchemaInspector $schema;

  public function __construct(Database $db, ?SchemaInspector $schema = null)
  {
    $this->db = $db;
    $this->schema = $schema ?: new SchemaInspector($db);
  }

  public static function columnsExist(Database $db, SchemaInspector $schema): bool
  {
    $table = $db->table('jet_cct_contratos_arrendamiento');
    return $schema->columnExists($table, 'estado_simi') && $schema->columnExists($table, 'observaciones_estado_simi');
  }

  /** Acepta mayúsculas o espacios distintos; cualquier otro valor queda vacío (sin auditar). */
  public static function normalizeState($value): string
  {
    $raw = mb_strtolower(trim((string) ($value ?? '')), 'UTF-8');
    foreach (self::STATES as $state) {
      if ($raw === mb_strtolower($state, 'UTF-8')) {
        return $state;
      }
    }
    return '';
  }

  /** Texto que queda en el historial del inmueble. */
  public static function historyMessage(string $contract, string $state, string $observation): string
  {
    $message = 'Se ha realizado la auditoría del contrato de arrendamiento'
      . ($contract !== '' ? ' #' . $contract : '')
      . ' de su inmueble. Se encontró ' . mb_strtolower($state, 'UTF-8') . '.';
    return $observation !== '' ? $message . ' Observaciones: ' . $observation : $message;
  }

  /**
   * @param array{id_empleado:string,name:string} $actor
   * @return array{contract_id:int,estado_simi:string,observaciones_estado_simi:string,changed:bool}
   */
  public function save(int $contractId, string $state, string $observation, array $actor): array
  {
    $state = self::normalizeState($state);
    $observation = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", $observation)) ?? '');
    if ($contractId <= 0) {
      throw new \InvalidArgumentException('Contrato no válido.');
    }
    if ($state === '') {
      throw new \InvalidArgumentException('Selecciona si el contrato está Correcto o Incorrecto en SIMI.');
    }
    if (mb_strlen($observation, 'UTF-8') > self::MAX_OBSERVATION) {
      throw new \InvalidArgumentException('Las observaciones admiten hasta ' . self::MAX_OBSERVATION . ' caracteres.');
    }
    if (!self::columnsExist($this->db, $this->schema)) {
      throw new \DomainException('Faltan los campos estado_simi y observaciones_estado_simi en Contratos de arrendamiento (JetEngine).');
    }

    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $pdo = $this->db->pdo();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }
    try {
      $contract = $this->db->getRow(
        "SELECT `_ID`, `contrato`, `id_inmueble`, `id_inmueble_data`, `estado_simi`, `observaciones_estado_simi` FROM `{$table}` WHERE `_ID` = ? LIMIT 1 FOR UPDATE",
        [$contractId]
      );
      if (!$contract) {
        throw new \DomainException('El contrato ya no existe.');
      }
      $changed = self::normalizeState($contract['estado_simi'] ?? '') !== $state
        || trim((string) ($contract['observaciones_estado_simi'] ?? '')) !== $observation;
      if ($changed) {
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);
        $payload = $this->schema->filterTableData($table, [
          'estado_simi' => $state,
          'observaciones_estado_simi' => $observation,
          'cct_modified' => $nowSql,
        ]);
        $this->db->update($table, $payload, ['_ID' => $contractId]);
        $this->recordHistory($contract, $state, $observation, $actor, $now, $nowSql);
      }
      if ($ownsTransaction) {
        $pdo->commit();
      }
    } catch (\Throwable $error) {
      if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }

    return [
      'contract_id' => $contractId,
      'estado_simi' => $state,
      'observaciones_estado_simi' => $observation,
      'changed' => $changed,
    ];
  }

  /**
   * @param array<string,mixed> $contract
   * @param array{id_empleado:string,name:string} $actor
   */
  private function recordHistory(array $contract, string $state, string $observation, array $actor, int $now, string $nowSql): void
  {
    $table = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($table)) {
      return;
    }
    $message = self::historyMessage(trim((string) ($contract['contrato'] ?? '')), $state, $observation);
    $employeeId = trim((string) ($actor['id_empleado'] ?? ''));
    $name = trim((string) ($actor['name'] ?? ''));
    $payload = $this->schema->filterTableData($table, [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'id_empleado' => $employeeId,
      'nombre_empleado' => $name,
      'funcionario' => $name,
      'id_inmueble' => trim((string) ($contract['id_inmueble'] ?? '')),
      'id_inmueble_data' => trim((string) ($contract['id_inmueble_data'] ?? '')),
      'fecha' => $now,
      'tipo_de_reporte_his' => 'Auditoría de contrato',
      'tipo_reporte' => 'Auditoría de contrato',
      'observacion_his' => $message,
      'observacion' => $message,
    ]);
    if ($payload !== []) {
      $this->db->insert($table, $payload);
    }
  }
}
