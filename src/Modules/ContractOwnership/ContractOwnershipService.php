<?php

declare(strict_types=1);

namespace SCM\Modules\ContractOwnership;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Funcionario responsable de los contratos de arrendamiento.
 *
 * La ayuda cruza dos documentos de origen:
 * - Hoja de cierre: su `id_empleado` es quien arrendó el inmueble. Es la fuente principal.
 * - Mandato: quien captó el inmueble. Su `id_empleado` y `cct_author_id` no son confiables en
 *   los datos, por eso el funcionario se identifica por el correo o el nombre de `creador`.
 * Si ambos coinciden la sugerencia es alta; si difieren prevalece la hoja de cierre.
 * Al asignar se actualizan id_empleado, realizado_por y cct_author_id del contrato.
 */
final class ContractOwnershipService
{
  public const MAX_CHANGES = 500;

  private Database $db;
  private SchemaInspector $schema;

  /** @var array<string,array{id:string,name:string,active:bool,email:string,cargo:string}> */
  private array $employees = [];
  /** @var array<string,string> */
  private array $byEmail = [];
  /** @var array<string,string> nombre normalizado => id_empleado */
  private array $byName = [];

  public function __construct(Database $db, ?SchemaInspector $schema = null)
  {
    $this->db = $db;
    $this->schema = $schema ?: new SchemaInspector($db);
  }

  /** @return array<string,mixed> */
  public function snapshot(): array
  {
    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $this->loadEmployees();
    $closings = $this->loadClosings();
    $mandates = $this->loadMandates();

    $rows = $this->db->getResults(
      "SELECT `_ID`, `contrato`, `estado`, `id_inmueble`, `id_inmueble_data`, `direccion`, `propietario`, `arrendatario`,
              `id_empleado`, `realizado_por`, `id_contrato_mandato`, `id_cierre`, `fecha_entrega`, `inicio_contrato`
       FROM `{$contractsTable}` ORDER BY `_ID` DESC"
    );

    $contracts = [];
    foreach ($rows as $row) {
      $contracts[] = $this->describe($row, $closings, $mandates);
    }

    $employees = array_values($this->employees);
    usort($employees, static fn(array $a, array $b): int => [$b['active'], $a['name']] <=> [$a['active'], $b['name']]);

    return [
      'contracts' => $contracts,
      'employees' => $employees,
      'generated_at' => time(),
    ];
  }

  /**
   * @param list<array{contract_id:int,employee_id:string}> $changes
   * @return array{updated:list<array<string,mixed>>,skipped:int}
   */
  public function assign(array $changes, string $reason): array
  {
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if ($changes === []) {
      throw new \InvalidArgumentException('Selecciona al menos un contrato.');
    }
    if (count($changes) > self::MAX_CHANGES) {
      throw new \InvalidArgumentException('Puedes cambiar hasta ' . self::MAX_CHANGES . ' contratos por vez.');
    }
    if (mb_strlen($reason, 'UTF-8') < 5) {
      throw new \InvalidArgumentException('Escribe el motivo del cambio (mínimo 5 caracteres).');
    }

    $actor = $this->actor();
    $this->loadEmployees();
    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $historyTable = $this->db->table('jet_cct_historial_del_inmueble');
    $pdo = $this->db->pdo();
    $ownsTransaction = !$pdo->inTransaction();
    $updated = [];
    $skipped = 0;
    $now = date('Y-m-d H:i:s');

    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }
    try {
      $seen = [];
      foreach ($changes as $change) {
        $contractId = (int) ($change['contract_id'] ?? 0);
        $employeeId = trim((string) ($change['employee_id'] ?? ''));
        if ($contractId <= 0 || isset($seen[$contractId])) {
          throw new \InvalidArgumentException('La selección contiene contratos inválidos o repetidos.');
        }
        $seen[$contractId] = true;
        $employee = $this->employees[$employeeId] ?? null;
        if ($employee === null || !$employee['active']) {
          throw new \InvalidArgumentException('Solo puedes asignar funcionarios activos.');
        }

        $contract = $this->db->getRow(
          "SELECT `_ID`, `contrato`, `id_inmueble`, `id_empleado`, `realizado_por`, `cct_author_id` FROM `{$contractsTable}` WHERE `_ID` = ? LIMIT 1 FOR UPDATE",
          [$contractId]
        );
        if (!$contract) {
          throw new \RuntimeException('El contrato #' . $contractId . ' ya no existe.');
        }
        $previousId = trim((string) ($contract['id_empleado'] ?? ''));
        $previousName = trim((string) ($contract['realizado_por'] ?? ''));
        if ($previousId === $employeeId && $previousName === $employee['name'] && trim((string) ($contract['cct_author_id'] ?? '')) === $employeeId) {
          $skipped++;
          continue;
        }

        $payload = $this->schema->filterTableData($contractsTable, [
          'id_empleado' => $employeeId,
          'realizado_por' => $employee['name'],
          'cct_author_id' => $employeeId,
          'cct_modified' => $now,
        ]);
        if ($this->db->update($contractsTable, $payload, ['_ID' => $contractId]) !== 1) {
          throw new \RuntimeException('No se pudo actualizar el contrato #' . $contractId . '.');
        }

        $previousLabel = $previousId === ''
          ? 'sin funcionario'
          : ($this->employees[$previousId]['name'] ?? ($previousName !== '' ? $previousName : 'funcionario ' . $previousId)) . ' (' . $previousId . ')';
        $history = $this->schema->filterTableData($historyTable, [
          'cct_status' => 'publish',
          'cct_author_id' => $actor['id'],
          'cct_created' => $now,
          'cct_modified' => $now,
          'id_empleado' => $actor['id'],
          'funcionario' => $actor['name'],
          'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
          'fecha' => time(),
          'tipo_reporte' => 'Contractual',
          'observacion' => 'Funcionario responsable del contrato #' . trim((string) ($contract['contrato'] ?? $contractId))
            . ' cambiado de ' . $previousLabel . ' a ' . $employee['name'] . ' (' . $employeeId . '). Motivo: ' . $reason,
        ]);
        if ($history === [] || !$this->db->insert($historyTable, $history)) {
          throw new \RuntimeException('No se pudo guardar el historial del contrato #' . $contractId . '.');
        }
        $updated[] = ['contract_id' => $contractId, 'employee_id' => $employeeId, 'employee_name' => $employee['name']];
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

    return ['updated' => $updated, 'skipped' => $skipped];
  }

  /**
   * @param array<string,mixed> $row
   * @param array{byId:array<string,array<string,mixed>>,byContract:array<string,array<string,mixed>>} $closings
   * @param array{byId:array<string,array<string,mixed>>,byProperty:array<string,array<string,mixed>>} $mandates
   * @return array<string,mixed>
   */
  public function describe(array $row, array $closings, array $mandates): array
  {
    $contractId = (string) ($row['_ID'] ?? '');
    $closingId = trim((string) ($row['id_cierre'] ?? ''));
    $closing = $closingId !== '' ? ($closings['byId'][$closingId] ?? null) : null;
    $closingVia = $closing ? 'id_cierre' : '';
    if (!$closing && isset($closings['byContract'][$contractId])) {
      $closing = $closings['byContract'][$contractId];
      $closingVia = 'contrato';
    }

    $mandateId = trim((string) ($row['id_contrato_mandato'] ?? ''));
    $mandate = $mandateId !== '' ? ($mandates['byId'][$mandateId] ?? null) : null;
    $mandateVia = $mandate ? 'id_contrato_mandato' : '';
    $propertyId = trim((string) ($row['id_inmueble'] ?? ''));
    if (!$mandate && $propertyId !== '' && isset($mandates['byProperty'][$propertyId])) {
      $mandate = $mandates['byProperty'][$propertyId];
      $mandateVia = 'inmueble';
    }

    $closingEvidence = null;
    if ($closing) {
      $closingEmployee = trim((string) ($closing['id_empleado'] ?? ''));
      $matchedBy = isset($this->employees[$closingEmployee]) ? 'id_empleado' : '';
      if ($matchedBy === '') {
        $closingEmployee = $this->matchName((string) ($closing['creador'] ?? ''));
        $matchedBy = $closingEmployee !== '' ? 'nombre' : '';
      }
      $closingEvidence = [
        'id' => (string) $closing['_ID'],
        'fecha' => $this->date($closing['fecha'] ?? null),
        'creador' => trim((string) ($closing['creador'] ?? '')),
        'aprobador' => trim((string) ($closing['aprobador'] ?? '')),
        'employee_id' => $closingEmployee,
        'matched_by' => $matchedBy,
        'via' => $closingVia,
      ];
    }

    $mandateEvidence = null;
    if ($mandate) {
      // Los correos son buzones del cargo y pasan de una persona a otra: primero el nombre.
      $mandateEmployee = $this->matchName((string) ($mandate['creador'] ?? ''));
      $matchedBy = $mandateEmployee !== '' ? 'nombre' : '';
      $email = mb_strtolower(trim((string) ($mandate['email_creador'] ?? '')), 'UTF-8');
      if ($mandateEmployee === '' && $email !== '' && isset($this->byEmail[$email])) {
        $mandateEmployee = $this->byEmail[$email];
        $matchedBy = 'correo';
      }
      $mandateEvidence = [
        'id' => (string) $mandate['_ID'],
        'fecha' => $this->date($mandate['fecha'] ?? null),
        'creador' => trim((string) ($mandate['creador'] ?? '')),
        'correo' => trim((string) ($mandate['email_creador'] ?? '')),
        'employee_id' => $mandateEmployee,
        'matched_by' => $matchedBy,
        'via' => $mandateVia,
      ];
    }

    $current = trim((string) ($row['id_empleado'] ?? ''));
    $inmueble = trim((string) ($row['id_inmueble_data'] ?? ''));

    return [
      'id' => (int) $contractId,
      'contrato' => trim((string) ($row['contrato'] ?? '')),
      'estado' => trim((string) ($row['estado'] ?? '')),
      'inmueble' => $inmueble !== '' ? $inmueble : $propertyId,
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'propietario' => trim((string) ($row['propietario'] ?? '')),
      'arrendatario' => trim((string) ($row['arrendatario'] ?? '')),
      'employee_id' => $current,
      'employee_name' => trim((string) ($row['realizado_por'] ?? '')),
      'cierre' => $closingEvidence,
      'mandato' => $mandateEvidence,
      'suggestion' => $this->suggest($closingEvidence['employee_id'] ?? '', $mandateEvidence['employee_id'] ?? '', $mandateEvidence !== null),
    ];
  }

  /** @return array{employee_id:string,source:string,confidence:string,reason:string} */
  public function suggest(string $closingEmployee, string $mandateEmployee, bool $hasMandate): array
  {
    if ($closingEmployee !== '' && $closingEmployee === $mandateEmployee) {
      return ['employee_id' => $closingEmployee, 'source' => 'ambos', 'confidence' => 'alta', 'reason' => 'La hoja de cierre y el mandato señalan al mismo funcionario.'];
    }
    if ($closingEmployee !== '' && $mandateEmployee !== '') {
      return ['employee_id' => $closingEmployee, 'source' => 'cierre', 'confidence' => 'media', 'reason' => 'La hoja de cierre (quien arrendó) y el mandato (quien captó) difieren; prevalece la hoja de cierre.'];
    }
    if ($closingEmployee !== '') {
      return [
        'employee_id' => $closingEmployee,
        'source' => 'cierre',
        'confidence' => $hasMandate ? 'media' : 'alta',
        'reason' => $hasMandate ? 'Solo la hoja de cierre identifica al funcionario; el creador del mandato no se pudo identificar.' : 'Funcionario que registró la hoja de cierre del contrato.',
      ];
    }
    if ($mandateEmployee !== '') {
      return ['employee_id' => $mandateEmployee, 'source' => 'mandato', 'confidence' => 'baja', 'reason' => 'No hay hoja de cierre; se sugiere quien creó el mandato del inmueble.'];
    }
    return ['employee_id' => '', 'source' => '', 'confidence' => 'ninguna', 'reason' => 'No hay hoja de cierre ni mandato con un funcionario identificable.'];
  }

  /** Normaliza un nombre para compararlo sin tildes, mayúsculas ni espacios extra. */
  public static function normalizeName(string $name): string
  {
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = strtr($name, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? '';
    return trim(preg_replace('/\s+/', ' ', $name) ?? '');
  }

  /**
   * Busca el funcionario por nombre exacto o, si el texto trae al menos nombre y apellido,
   * por el único funcionario cuyo nombre contenga todas esas palabras.
   */
  public function matchName(string $name): string
  {
    $normalized = self::normalizeName($name);
    if ($normalized === '') {
      return '';
    }
    if (isset($this->byName[$normalized])) {
      return $this->byName[$normalized];
    }
    $tokens = explode(' ', $normalized);
    if (count($tokens) < 2) {
      return '';
    }
    $found = [];
    foreach ($this->byName as $candidate => $employeeId) {
      if (array_diff($tokens, explode(' ', $candidate)) === []) {
        $found[$employeeId] = true;
      }
    }
    return count($found) === 1 ? (string) array_key_first($found) : '';
  }

  /**
   * @param list<array<string,mixed>> $employees
   * @param list<array<string,mixed>> $closings
   */
  public function useEmployees(array $employees, array $closings = []): void
  {
    $this->employees = [];
    $this->byEmail = [];
    $this->byName = [];
    $emails = [];
    foreach ($employees as $row) {
      $id = trim((string) ($row['id_empleado'] ?? ''));
      if ($id === '') {
        continue;
      }
      $name = trim(preg_replace('/\s+/u', ' ', (string) ($row['nombre'] ?? '')) ?? '');
      $this->employees[$id] = [
        'id' => $id,
        'name' => $name !== '' ? $name : 'Funcionario ' . $id,
        'active' => mb_strtolower(trim((string) ($row['activo'] ?? '')), 'UTF-8') === 'si',
        'email' => trim((string) ($row['correo'] ?? '')),
        'cargo' => trim((string) ($row['nombre_cargo'] ?? '')),
      ];
      $email = mb_strtolower(trim((string) ($row['correo'] ?? '')), 'UTF-8');
      if ($email !== '') {
        $emails[$email][$id] = true;
      }
      if ($name !== '') {
        $this->byName[self::normalizeName($name)] = $id;
      }
    }
    // Un correo compartido por varios funcionarios no identifica a nadie.
    foreach ($emails as $email => $ids) {
      if (count($ids) === 1) {
        $this->byEmail[$email] = (string) array_key_first($ids);
      }
    }
    // Las hojas de cierre guardan el nombre con que se registró cada funcionario aunque
    // después se renombrara (p. ej. "Asesor retirado"); sirven de alias para los mandatos.
    $aliases = [];
    foreach ($closings as $closing) {
      $id = trim((string) ($closing['id_empleado'] ?? ''));
      $alias = self::normalizeName((string) ($closing['creador'] ?? ''));
      if ($id !== '' && $alias !== '' && isset($this->employees[$id])) {
        $aliases[$alias][$id] = ($aliases[$alias][$id] ?? 0) + 1;
      }
    }
    foreach ($aliases as $alias => $ids) {
      if (!isset($this->byName[$alias]) && count($ids) === 1) {
        $this->byName[$alias] = (string) array_key_first($ids);
      }
    }
  }

  private function loadEmployees(): void
  {
    if ($this->employees !== []) {
      return;
    }
    $table = $this->db->table('jet_cct_funcionarios');
    $cargoTable = $this->db->table('jet_cct_cargos');
    $hasCargo = $this->schema->columnExists($table, 'id_cargo') && $this->schema->tableExists($cargoTable) && $this->schema->columnExists($cargoTable, 'nombre_cargo');
    $employees = $this->db->getResults(
      'SELECT f.`id_empleado`, f.`nombre`, f.`activo`, f.`correo`' . ($hasCargo ? ', c.`nombre_cargo`' : '')
      . " FROM `{$table}` f" . ($hasCargo ? " LEFT JOIN `{$cargoTable}` c ON c.`_ID` = f.`id_cargo`" : '')
      . " WHERE TRIM(COALESCE(f.`id_empleado`, '')) <> ''"
    );
    $closingsTable = $this->db->table('jet_cct_cierres');
    $closings = $this->schema->tableExists($closingsTable)
      ? $this->db->getResults("SELECT `id_empleado`, `creador` FROM `{$closingsTable}`")
      : [];
    $this->useEmployees($employees, $closings);
  }

  /** @return array{byId:array<string,array<string,mixed>>,byContract:array<string,array<string,mixed>>} */
  private function loadClosings(): array
  {
    $table = $this->db->table('jet_cct_cierres');
    $out = ['byId' => [], 'byContract' => []];
    if (!$this->schema->tableExists($table)) {
      return $out;
    }
    $rows = $this->db->getResults(
      "SELECT `_ID`, `id_contrato_arrendamiento`, `fecha`, `id_empleado`, `creador`, `aprobador` FROM `{$table}` ORDER BY `_ID` ASC"
    );
    foreach ($rows as $row) {
      $out['byId'][(string) $row['_ID']] = $row;
      $contractId = trim((string) ($row['id_contrato_arrendamiento'] ?? ''));
      if ($contractId !== '') {
        $out['byContract'][$contractId] = $row; // el más reciente gana
      }
    }
    return $out;
  }

  /** @return array{byId:array<string,array<string,mixed>>,byProperty:array<string,array<string,mixed>>} */
  private function loadMandates(): array
  {
    $table = $this->db->table('jet_cct_contrato_mandato');
    $out = ['byId' => [], 'byProperty' => []];
    if (!$this->schema->tableExists($table)) {
      return $out;
    }
    $rows = $this->db->getResults(
      "SELECT `_ID`, `id_inmueble`, `fecha`, `creador`, `email_creador` FROM `{$table}` ORDER BY `_ID` ASC"
    );
    foreach ($rows as $row) {
      $out['byId'][(string) $row['_ID']] = $row;
      $propertyId = trim((string) ($row['id_inmueble'] ?? ''));
      if ($propertyId !== '') {
        $out['byProperty'][$propertyId] = $row; // el más reciente gana
      }
    }
    return $out;
  }

  /** @return array{id:string,name:string} */
  private function actor(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $row = $this->db->getRow("SELECT `id_empleado`, `nombre` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) Auth::userId()]);
    $id = trim((string) ($row['id_empleado'] ?? ''));
    if ($id === '') {
      throw new \RuntimeException('Tu usuario no tiene un id_empleado registrado; no se puede dejar trazabilidad del cambio.');
    }
    return ['id' => $id, 'name' => trim((string) ($row['nombre'] ?? Auth::user()))];
  }

  private function date($value): string
  {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 631152000) {
      return '';
    }
    return (new \DateTimeImmutable('@' . (int) $raw))->setTimezone(new \DateTimeZone('America/Bogota'))->format('Y-m-d');
  }
}
