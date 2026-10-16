<?php

declare(strict_types=1);

namespace SCM\Modules\ContractOwnership;

use SCM\Core\Database;
use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Support\SchemaInspector;

/**
 * Funcionario responsable y días de gracia de los contratos de arrendamiento.
 *
 * La ayuda cruza dos documentos de origen:
 * - Hoja de cierre: su `id_empleado` es quien arrendó el inmueble. Es la fuente principal.
 * - Mandato: quien captó el inmueble. Su `id_empleado` y `cct_author_id` no son confiables en
 *   los datos, por eso el funcionario se identifica por el nombre de `creador` (o su correo).
 * Solo se sugieren consultores: si el documento lo registró un administrativo, no cuenta.
 * Si ambos coinciden la sugerencia es alta; si difieren prevalece la hoja de cierre.
 * Los días de gracia casi nunca están en el contrato: se sugieren desde la hoja de cierre y,
 * si no, desde el mandato.
 *
 * Los consultores retirados también se pueden asignar para conservar el histórico de quién arrendó;
 * en ese caso `realizado_por` guarda el nombre con que firmaban (el funcionario fue renombrado).
 *
 * Es una herramienta de corrección: actualiza id_empleado, realizado_por, cct_author_id y
 * dias_gracia del contrato sin dejar historial en el inmueble.
 */
final class ContractOwnershipService
{
  public const MAX_CHANGES = 500;
  public const MAX_GRACE_DAYS = 365;

  private Database $db;
  private SchemaInspector $schema;

  /** @var array<string,array{id:string,name:string,active:bool,email:string,cargo:string,consultant:bool,real_name:string}> */
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
              `id_empleado`, `realizado_por`, `id_contrato_mandato`, `id_cierre`, `dias_gracia`
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
   * Cada cambio trae `contract_id` y, al menos, `employee_id` o `dias_gracia`.
   *
   * @param list<array<string,mixed>> $changes
   * @return array{updated:list<array<string,mixed>>,skipped:int}
   */
  public function assign(array $changes): array
  {
    if ($changes === []) {
      throw new \InvalidArgumentException('Selecciona al menos un contrato.');
    }
    if (count($changes) > self::MAX_CHANGES) {
      throw new \InvalidArgumentException('Puedes cambiar hasta ' . self::MAX_CHANGES . ' contratos por vez.');
    }
    $this->loadEmployees();
    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
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
        if ($contractId <= 0 || isset($seen[$contractId])) {
          throw new \InvalidArgumentException('La selección contiene contratos inválidos o repetidos.');
        }
        $seen[$contractId] = true;

        $employeeId = trim((string) ($change['employee_id'] ?? ''));
        $employee = null;
        if ($employeeId !== '') {
          $employee = $this->employees[$employeeId] ?? null;
          if ($employee === null || !$employee['consultant']) {
            throw new \InvalidArgumentException('Solo puedes asignar consultores (activos o retirados).');
          }
        }
        $grace = null;
        $setGrace = array_key_exists('dias_gracia', $change) && $change['dias_gracia'] !== null && $change['dias_gracia'] !== '';
        if ($setGrace) {
          if (!is_numeric($change['dias_gracia']) || (int) $change['dias_gracia'] != $change['dias_gracia']) {
            throw new \InvalidArgumentException('Los días de gracia deben ser un número entero.');
          }
          $grace = (int) $change['dias_gracia'];
          if ($grace < 0 || $grace > self::MAX_GRACE_DAYS) {
            throw new \InvalidArgumentException('Los días de gracia deben estar entre 0 y ' . self::MAX_GRACE_DAYS . '.');
          }
        }
        if ($employee === null && !$setGrace) {
          throw new \InvalidArgumentException('Indica el funcionario o los días de gracia del contrato #' . $contractId . '.');
        }

        $contract = $this->db->getRow(
          "SELECT `_ID`, `id_empleado`, `realizado_por`, `cct_author_id`, `dias_gracia` FROM `{$contractsTable}` WHERE `_ID` = ? LIMIT 1 FOR UPDATE",
          [$contractId]
        );
        if (!$contract) {
          throw new \RuntimeException('El contrato #' . $contractId . ' ya no existe.');
        }

        $payload = [];
        $ownerName = $employee !== null ? self::ownerName($employee) : '';
        if ($employee !== null && (
          trim((string) ($contract['id_empleado'] ?? '')) !== $employeeId
          || trim((string) ($contract['realizado_por'] ?? '')) !== $ownerName
          || trim((string) ($contract['cct_author_id'] ?? '')) !== $employeeId
        )) {
          $payload['id_empleado'] = $employeeId;
          $payload['realizado_por'] = $ownerName;
          $payload['cct_author_id'] = $employeeId;
        }
        if ($setGrace && trim((string) ($contract['dias_gracia'] ?? '')) !== (string) $grace) {
          $payload['dias_gracia'] = (string) $grace;
        }
        if ($payload === []) {
          $skipped++;
          continue;
        }
        $payload['cct_modified'] = $now;
        $payload = $this->schema->filterTableData($contractsTable, $payload);
        if ($this->db->update($contractsTable, $payload, ['_ID' => $contractId]) !== 1) {
          throw new \RuntimeException('No se pudo actualizar el contrato #' . $contractId . '.');
        }

        $result = ['contract_id' => $contractId];
        if (isset($payload['id_empleado'])) {
          $result['employee_id'] = $employeeId;
          $result['employee_name'] = $ownerName;
        }
        if (isset($payload['dias_gracia'])) {
          $result['dias_gracia'] = $grace;
        }
        $updated[] = $result;
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
        'eligible' => $this->isEligible($closingEmployee),
        'matched_by' => $matchedBy,
        'via' => $closingVia,
        'dias_gracia' => ContractMovementsReport::parseGraceDays($closing['dias_gracia'] ?? null),
        'dias_gracia_raw' => trim((string) ($closing['dias_gracia'] ?? '')),
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
        'eligible' => $this->isEligible($mandateEmployee),
        'matched_by' => $matchedBy,
        'via' => $mandateVia,
        'dias_gracia' => ContractMovementsReport::parseGraceDays($mandate['dias_gracia'] ?? null),
        'dias_gracia_raw' => trim((string) ($mandate['dias_gracia'] ?? '')),
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
      'dias_gracia' => ContractMovementsReport::parseGraceDays($row['dias_gracia'] ?? null),
      'cierre' => $closingEvidence,
      'mandato' => $mandateEvidence,
      'suggestion' => $this->suggest(
        $closingEvidence && $closingEvidence['eligible'] ? $closingEvidence['employee_id'] : '',
        $mandateEvidence && $mandateEvidence['eligible'] ? $mandateEvidence['employee_id'] : '',
        $mandateEvidence !== null
      ),
      'grace_suggestion' => $this->suggestGrace($closingEvidence['dias_gracia'] ?? null, $mandateEvidence['dias_gracia'] ?? null),
    ];
  }

  /** @return array{employee_id:string,source:string,confidence:string,reason:string} */
  public function suggest(string $closingEmployee, string $mandateEmployee, bool $hasMandate): array
  {
    if ($closingEmployee !== '' && $closingEmployee === $mandateEmployee) {
      return ['employee_id' => $closingEmployee, 'source' => 'ambos', 'confidence' => 'alta', 'reason' => 'La hoja de cierre y el mandato señalan al mismo consultor.'];
    }
    if ($closingEmployee !== '' && $mandateEmployee !== '') {
      return ['employee_id' => $closingEmployee, 'source' => 'cierre', 'confidence' => 'media', 'reason' => 'La hoja de cierre (quien arrendó) y el mandato (quien captó) difieren; prevalece la hoja de cierre.'];
    }
    if ($closingEmployee !== '') {
      return [
        'employee_id' => $closingEmployee,
        'source' => 'cierre',
        'confidence' => $hasMandate ? 'media' : 'alta',
        'reason' => $hasMandate ? 'Solo la hoja de cierre identifica a un consultor; el mandato no.' : 'Consultor que registró la hoja de cierre del contrato.',
      ];
    }
    if ($mandateEmployee !== '') {
      return ['employee_id' => $mandateEmployee, 'source' => 'mandato', 'confidence' => 'baja', 'reason' => 'La hoja de cierre no identifica a un consultor; se sugiere quien creó el mandato del inmueble.'];
    }
    return ['employee_id' => '', 'source' => '', 'confidence' => 'ninguna', 'reason' => 'Ni la hoja de cierre ni el mandato identifican a un consultor.'];
  }

  /**
   * Nombre que queda en `realizado_por`: el de la ficha del funcionario o, si es un retirado
   * renombrado ("Asesor de arriendo retirado 2"), el nombre con que firmaba.
   *
   * @param array{name:string,real_name:string} $employee
   */
  public static function ownerName(array $employee): string
  {
    return $employee['real_name'] !== '' ? $employee['real_name'] : $employee['name'];
  }

  /** @return array{value:?int,source:string} */
  public function suggestGrace(?int $closing, ?int $mandate): array
  {
    if ($closing !== null) {
      return ['value' => $closing, 'source' => 'cierre'];
    }
    if ($mandate !== null) {
      return ['value' => $mandate, 'source' => 'mandato'];
    }
    return ['value' => null, 'source' => ''];
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
   * Un contrato de arrendamiento lo lleva un consultor: cargo "Consultor de arriendo/venta"
   * o un asesor que se retiró (al retirarse se renombra como "Asesor de arriendo retirado").
   */
  public static function isConsultant(string $cargo, string $name): bool
  {
    return preg_match('/^consultora? de (arriendo|venta)/', self::normalizeName($cargo)) === 1
      || preg_match('/^asesora? de (arriendo|venta)/', self::normalizeName($name)) === 1;
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
      $cargo = trim((string) ($row['nombre_cargo'] ?? ''));
      $this->employees[$id] = [
        'id' => $id,
        'name' => $name !== '' ? $name : 'Funcionario ' . $id,
        'active' => mb_strtolower(trim((string) ($row['activo'] ?? '')), 'UTF-8') === 'si',
        'email' => trim((string) ($row['correo'] ?? '')),
        'cargo' => $cargo,
        'consultant' => self::isConsultant($cargo, $name),
        'real_name' => '',
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
    // después se renombrara (p. ej. "Asesor de arriendo retirado 2"); sirven de alias para
    // los mandatos y para mostrar quién era la persona retirada.
    $aliases = [];
    $spellings = [];
    foreach ($closings as $closing) {
      $id = trim((string) ($closing['id_empleado'] ?? ''));
      $creator = trim(preg_replace('/\s+/u', ' ', (string) ($closing['creador'] ?? '')) ?? '');
      $alias = self::normalizeName($creator);
      if ($id === '' || $alias === '' || !isset($this->employees[$id])) {
        continue;
      }
      $aliases[$alias][$id] = ($aliases[$alias][$id] ?? 0) + 1;
      $spellings[$id][$creator] = ($spellings[$id][$creator] ?? 0) + 1;
    }
    foreach ($aliases as $alias => $ids) {
      if (!isset($this->byName[$alias]) && count($ids) === 1) {
        $this->byName[$alias] = (string) array_key_first($ids);
      }
    }
    foreach ($spellings as $id => $names) {
      $employee = $this->employees[$id];
      if ($employee['active']) {
        continue;
      }
      // El nombre completo más usado (a igualdad, el más largo) identifica a la persona retirada.
      uksort($names, static fn(string $a, string $b): int => [$names[$b], strlen($b)] <=> [$names[$a], strlen($a)]);
      $realName = (string) array_key_first($names);
      if (self::normalizeName($realName) !== self::normalizeName($employee['name'])) {
        $this->employees[$id]['real_name'] = $realName;
      }
    }
  }

  private function isEligible(string $employeeId): bool
  {
    return $employeeId !== '' && !empty($this->employees[$employeeId]['consultant']);
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
      "SELECT `_ID`, `id_contrato_arrendamiento`, `fecha`, `id_empleado`, `creador`, `aprobador`, `dias_gracia` FROM `{$table}` ORDER BY `_ID` ASC"
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
      "SELECT `_ID`, `id_inmueble`, `fecha`, `creador`, `email_creador`, `dias_gracia` FROM `{$table}` ORDER BY `_ID` ASC"
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

  private function date($value): string
  {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 631152000) {
      return '';
    }
    return (new \DateTimeImmutable('@' . (int) $raw))->setTimezone(new \DateTimeZone('America/Bogota'))->format('Y-m-d');
  }
}
