<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Auditoría del contrato de arrendamiento contra SIMI.
 *
 * Guarda `estado_simi` (Correcto/Incorrecto) y `observaciones_estado_simi` en el contrato y deja
 * constancia en el historial del inmueble y en el historial del contrato. En la misma auditoría se
 * pueden corregir las condiciones del contrato (CORRECTABLE); solo se escriben las que cambian y el
 * historial registra el valor anterior y el nuevo. El funcionario y el autor del contrato se conservan.
 */
final class ContractSimiAudit
{
  public const STATES = ['Correcto', 'Incorrecto'];
  public const MAX_OBSERVATION = 2000;
  /** Condiciones que se pueden corregir al auditar: clave => [columna, etiqueta, tipo]. */
  public const CORRECTABLE = [
    'canon' => ['valor_canon', 'Canon', 'money'],
    'admin' => ['valor_administracion', 'Administración', 'money'],
    'meses' => ['meses_contrato', 'Duración', 'months'],
    'derechos' => ['derechos_inmobiliarios', '% derechos', 'percent'],
    'tasa_admin' => ['tasa_administracion', '% administración', 'percent'],
    'dias_gracia' => ['dias_gracia', 'Días de gracia', 'days'],
  ];

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

  /**
   * Texto que queda en el historial del inmueble y del contrato.
   *
   * @param list<string> $adjustments
   */
  public static function historyMessage(string $contract, string $state, string $observation, array $adjustments = []): string
  {
    $message = 'Se ha realizado la auditoría del contrato de arrendamiento'
      . ($contract !== '' ? ' #' . $contract : '')
      . ' de su inmueble. Se encontró ' . mb_strtolower($state, 'UTF-8') . '.';
    if ($adjustments !== []) {
      $message .= ' Ajustes: ' . implode('; ', $adjustments) . '.';
    }
    return $observation !== '' ? $message . ' Observaciones: ' . $observation : $message;
  }

  /** Columnas corregibles que existen en la tabla de contratos. @return list<string> */
  public static function correctableKeys(Database $db, SchemaInspector $schema): array
  {
    $table = $db->table('jet_cct_contratos_arrendamiento');
    $keys = [];
    foreach (self::CORRECTABLE as $key => [$column]) {
      if ($schema->columnExists($table, $column)) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  /** "10", "10%", "8,5" → 10.0 / 8.5; vacío o ilegible → null. */
  public static function parsePercent($value): ?float
  {
    $raw = str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', (string) ($value ?? '')) ?? '');
    return $raw === '' || !is_numeric($raw) ? null : round((float) $raw, 2);
  }

  /** Valor actual de una condición del contrato, normalizado para comparar. */
  private static function currentValue(string $kind, $raw)
  {
    return match ($kind) {
      'money' => trim((string) ($raw ?? '')) === '' ? null : ContractMovementsReport::parseMoney($raw),
      'months' => ($months = (int) preg_replace('/\D/', '', (string) ($raw ?? ''))) > 0 ? $months : null,
      'percent' => self::parsePercent($raw),
      default => ContractMovementsReport::parseGraceDays($raw),
    };
  }

  /** Valida el valor que escribió el funcionario. */
  private static function inputValue(string $kind, string $label, $raw)
  {
    $text = trim((string) ($raw ?? ''));
    if ($text === '') {
      throw new \InvalidArgumentException($label . ': escribe un valor.');
    }
    switch ($kind) {
      case 'money':
        $value = ContractMovementsReport::parseMoney($text);
        if (preg_match('/\d/', $text) !== 1 || $value > 100000000000) {
          throw new \InvalidArgumentException($label . ': valor no válido.');
        }
        return $value;
      case 'months':
        if (preg_match('/^\d{1,3}$/', $text) !== 1 || (int) $text < 1 || (int) $text > 120) {
          throw new \InvalidArgumentException($label . ': escribe entre 1 y 120 meses.');
        }
        return (int) $text;
      case 'percent':
        $value = self::parsePercent($text);
        if ($value === null || $value < 0 || $value > 100) {
          throw new \InvalidArgumentException($label . ': escribe un porcentaje entre 0 y 100.');
        }
        return $value;
      default:
        if (preg_match('/^\d{1,3}$/', $text) !== 1 || (int) $text > 365) {
          throw new \InvalidArgumentException($label . ': escribe entre 0 y 365 días.');
        }
        return (int) $text;
    }
  }

  private static function databaseValue(string $kind, $value): string
  {
    return match ($kind) {
      'money' => number_format((float) $value, 2, '.', ''),
      'percent' => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'),
      default => (string) (int) $value,
    };
  }

  private static function displayValue(string $kind, $value): string
  {
    if ($value === null) {
      return 'sin dato';
    }
    return match ($kind) {
      'money' => '$' . number_format((float) $value, 0, ',', '.'),
      'months' => (int) $value . ((int) $value === 1 ? ' mes' : ' meses'),
      'percent' => str_replace('.', ',', rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.')) . '%',
      default => (int) $value . ((int) $value === 1 ? ' día' : ' días'),
    };
  }

  /**
   * @param array{id_empleado:string,name:string} $actor
   * @param array<string,mixed> $corrections solo las condiciones que el funcionario cambió (claves de CORRECTABLE)
   * @return array{contract_id:int,estado_simi:string,observaciones_estado_simi:string,changed:bool,values:array<string,int|float>,adjustments:list<string>}
   */
  public function save(int $contractId, string $state, string $observation, array $actor, array $corrections = []): array
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
    $wanted = [];
    foreach ($corrections as $key => $raw) {
      if (!isset(self::CORRECTABLE[$key])) {
        throw new \InvalidArgumentException('Campo no corregible: ' . $key . '.');
      }
      [$column, $label, $kind] = self::CORRECTABLE[$key];
      if (!$this->schema->columnExists($table, $column)) {
        throw new \DomainException('El contrato no tiene el campo ' . $column . '.');
      }
      $wanted[$key] = self::inputValue($kind, $label, $raw);
    }
    $columns = array_unique(array_merge(['_ID', 'contrato', 'inmueble', 'id_inmueble', 'id_inmueble_data', 'estado_simi', 'observaciones_estado_simi'], array_map(static fn(string $key): string => self::CORRECTABLE[$key][0], array_keys($wanted))));
    $pdo = $this->db->pdo();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }
    try {
      $contract = $this->db->getRow(
        'SELECT `' . implode('`, `', $columns) . "` FROM `{$table}` WHERE `_ID` = ? LIMIT 1 FOR UPDATE",
        [$contractId]
      );
      if (!$contract) {
        throw new \DomainException('El contrato ya no existe.');
      }
      $updates = [];
      $adjustments = [];
      $values = [];
      foreach ($wanted as $key => $value) {
        [$column, $label, $kind] = self::CORRECTABLE[$key];
        $before = self::currentValue($kind, $contract[$column] ?? null);
        $values[$key] = $value;
        if ($before !== null && (float) $before === (float) $value) {
          continue;
        }
        $updates[$column] = self::databaseValue($kind, $value);
        $adjustments[] = $label . ' de ' . self::displayValue($kind, $before) . ' a ' . self::displayValue($kind, $value);
      }
      $changed = $adjustments !== []
        || self::normalizeState($contract['estado_simi'] ?? '') !== $state
        || trim((string) ($contract['observaciones_estado_simi'] ?? '')) !== $observation;
      if ($changed) {
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);
        $payload = $this->schema->filterTableData($table, $updates + [
          'estado_simi' => $state,
          'observaciones_estado_simi' => $observation,
          'cct_modified' => $nowSql,
        ]);
        $this->db->update($table, $payload, ['_ID' => $contractId]);
        $this->recordHistory($contract, $state, $observation, $actor, $now, $nowSql, $adjustments);
        $this->recordContractHistory($contract, $state, $observation, $actor, $now, $nowSql, $adjustments);
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
      'values' => $values,
      'adjustments' => $adjustments,
    ];
  }

  /**
   * @param array<string,mixed> $contract
   * @param array{id_empleado:string,name:string} $actor
   */
  private function recordContractHistory(array $contract, string $state, string $observation, array $actor, int $now, string $nowSql, array $adjustments = []): void
  {
    $table = $this->db->table('jet_cct_historial_contrato_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      return;
    }
    $employeeId = trim((string) ($actor['id_empleado'] ?? ''));
    $payload = $this->schema->filterTableData($table, [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'id_empleado' => $employeeId,
      'funcionario' => trim((string) ($actor['name'] ?? '')),
      'id_contrato' => (string) ($contract['_ID'] ?? ''),
      'contrato' => trim((string) ($contract['contrato'] ?? '')),
      'inmueble' => trim((string) ($contract['inmueble'] ?? '')),
      'id_inmueble' => trim((string) ($contract['id_inmueble'] ?? '')),
      'id_inmueble_data' => trim((string) ($contract['id_inmueble_data'] ?? '')),
      'fecha' => $now,
      'tipo_reporte' => 'Auditoría de contrato',
      'observacion' => self::historyMessage(trim((string) ($contract['contrato'] ?? '')), $state, $observation, $adjustments),
    ]);
    if ($payload !== []) {
      $this->db->insert($table, $payload);
    }
  }

  /**
   * @param array<string,mixed> $contract
   * @param array{id_empleado:string,name:string} $actor
   */
  private function recordHistory(array $contract, string $state, string $observation, array $actor, int $now, string $nowSql, array $adjustments = []): void
  {
    $table = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($table)) {
      return;
    }
    $message = self::historyMessage(trim((string) ($contract['contrato'] ?? '')), $state, $observation, $adjustments);
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
