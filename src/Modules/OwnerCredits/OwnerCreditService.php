<?php

declare(strict_types=1);

namespace SCM\Modules\OwnerCredits;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

/**
 * Créditos a propietarios (tabla propia `scm_owner_credits`, independiente de los créditos de garantía de
 * servicios públicos de JetEngine).
 *
 * Flujo: la cotización queda con financiación "Sí" → se crea la solicitud (pendiente) y se avisa al evento
 * interno `credito_propietario_solicitado`. El funcionario abre el liquidador, revisa las políticas (contrato
 * vigente, siniestro, préstamos activos, tope por ingreso neto, usura) y aprueba o rechaza; si aprueba
 * incumpliendo alguna política debe justificarlo. La decisión queda en el historial del crédito, del caso y
 * del contrato y del inmueble, se avisa al propietario según la configuración y al evento `credito_propietario_decidido`.
 * Un crédito aprobado se marca después como pagado o se anula.
 */
final class OwnerCreditService
{
  /** Aviso en todo valor que no es una aprobación (cupo, simulación): no genera promesas ni expectativas. */
  public const DISCLAIMER = 'Valores de referencia sujetos a las políticas de crédito y a los términos y condiciones de SKC SuCasa Inmobiliaria. No constituyen una aprobación ni un compromiso de desembolso: el crédito queda sujeto a estudio, verificación y aprobación.';

  public const STATUSES = [
    'pendiente' => 'Pendiente',
    'aprobado' => 'Aprobado',
    'rechazado' => 'Rechazado',
    'pagado' => 'Pagado',
    'anulado' => 'Anulado',
  ];
  /**
   * A quién se le hace el crédito. Hoy el flujo solo crea créditos a propietarios (la evaluación usa el canon
   * y la cuota se descuenta del giro); los demás tipos quedan listos en la tabla para cuando tengan su flujo.
   */
  public const BENEFICIARY_TYPES = [
    'propietario' => 'Propietario',
    'arrendatario' => 'Arrendatario',
    'codeudor' => 'Codeudor',
    'externo' => 'Externo',
    'funcionario' => 'Funcionario',
  ];
  /** Tipos que ya tienen flujo; los demás se muestran en la pregunta como "Próximamente". */
  public const AVAILABLE_BENEFICIARY_TYPES = ['propietario'];
  /** En la cotización el crédito sale del contrato del caso: no aplica a funcionarios. */
  public const QUOTE_BENEFICIARY_TYPES = ['propietario', 'arrendatario', 'codeudor', 'externo'];
  public const DEDUCTIONS = [
    'comision' => 'Comisión de administración + IVA',
    'garantia' => 'Garantía inmobiliaria',
    'poliza_servicios' => 'Póliza de servicios públicos',
    'otros' => 'Otros descuentos',
  ];
  public const EVENT_REQUESTED = 'credito_propietario_solicitado';
  public const EVENT_DECIDED = 'credito_propietario_decidido';
  private const MIN_NOTE = 10;

  private SchemaInspector $schema;

  /** Sin $settings se usa la configuración de la app al momento de necesitarla (la sincronización nunca debe fallar). */
  public function __construct(private Database $db, private ?Settings $settings = null, ?SchemaInspector $schema = null)
  {
    $this->schema = $schema ?: new SchemaInspector($db);
  }

  /** @return array<string,mixed> */
  private function config(): array
  {
    return OwnerCreditSettings::read($this->settings ?? \SCM\Core\App::settings());
  }

  public function table(): string { return $this->db->table('scm_owner_credits'); }
  private function eventsTable(): string { return $this->db->table('scm_owner_credit_events'); }
  private function sourcesTable(): string { return $this->db->table('scm_owner_credit_sources'); }

  /** Tablas ya verificadas en esta petición (las tarjetas de cotización consultan el estado una por una). */
  private static array $schemaReady = [];

  public function ensureSchema(): void
  {
    if (isset(self::$schemaReady[$this->table()])) return;
    self::$schemaReady[$this->table()] = true;
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->table()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      quote_id BIGINT UNSIGNED NULL, ticket_id VARCHAR(40) NOT NULL DEFAULT '',
      contract_id BIGINT UNSIGNED NOT NULL DEFAULT 0, contract_code VARCHAR(60) NOT NULL DEFAULT '',
      property VARCHAR(60) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '',
      owner_id VARCHAR(60) NOT NULL DEFAULT '', owner_name VARCHAR(190) NOT NULL DEFAULT '',
      owner_email VARCHAR(190) NOT NULL DEFAULT '', owner_phone VARCHAR(40) NOT NULL DEFAULT '', owner_document VARCHAR(40) NOT NULL DEFAULT '',
      beneficiary_type VARCHAR(20) NOT NULL DEFAULT 'propietario',
      purpose VARCHAR(120) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
      requested_amount DECIMAL(14,2) NOT NULL DEFAULT 0, requested_term INT NOT NULL DEFAULT 0,
      vacancy VARCHAR(10) NOT NULL DEFAULT 'Baja', gross_income DECIMAL(14,2) NOT NULL DEFAULT 0,
      deductions_json TEXT NULL, net_income DECIMAL(14,2) NOT NULL DEFAULT 0,
      max_amount DECIMAL(14,2) NOT NULL DEFAULT 0, max_months INT NOT NULL DEFAULT 0,
      approved_amount DECIMAL(14,2) NULL, approved_term INT NULL, approved_rate DECIMAL(10,6) NULL,
      monthly_payment DECIMAL(14,2) NULL, total_interest DECIMAL(14,2) NULL, disbursed_at BIGINT NULL, first_payment_ts BIGINT NULL,
      policy_json MEDIUMTEXT NULL, evaluation_json MEDIUMTEXT NULL, policy_override TINYINT NOT NULL DEFAULT 0,
      decision_note TEXT NULL, decided_by_id VARCHAR(40) NOT NULL DEFAULT '', decided_by_name VARCHAR(190) NOT NULL DEFAULT '',
      decided_at BIGINT NULL, closed_at BIGINT NULL,
      created_by_id VARCHAR(40) NOT NULL DEFAULT '', created_by_name VARCHAR(190) NOT NULL DEFAULT '',
      created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
      UNIQUE KEY uniq_quote (quote_id), KEY idx_status (status), KEY idx_owner (owner_id), KEY idx_contract (contract_id), KEY idx_beneficiary_type (beneficiary_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->eventsTable()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credit_id BIGINT UNSIGNED NOT NULL,
      action VARCHAR(40) NOT NULL, actor_id VARCHAR(40) NOT NULL DEFAULT '', actor_name VARCHAR(190) NOT NULL DEFAULT '',
      details_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL, KEY idx_credit (credit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Contratos que respaldan un crédito aprobado: de ellos se descuenta la cuota (el crédito es de la persona).
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->sourcesTable()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credit_id BIGINT UNSIGNED NOT NULL, contract_id BIGINT UNSIGNED NOT NULL,
      contract_code VARCHAR(60) NOT NULL DEFAULT '', property VARCHAR(60) NOT NULL DEFAULT '', net_income DECIMAL(14,2) NOT NULL DEFAULT 0,
      end_ts BIGINT NULL, is_primary TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, KEY idx_credit (credit_id), KEY idx_contract (contract_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$this->schema->columnExists($this->table(), 'owner_document')) {
      $this->db->pdo()->exec("ALTER TABLE `{$this->table()}` ADD COLUMN owner_document VARCHAR(40) NOT NULL DEFAULT ''");
      // El inspector guarda las columnas en caché: uno nuevo ya ve la columna agregada.
      $this->schema = new SchemaInspector($this->db);
    }
    if (!$this->schema->columnExists($this->table(), 'disbursed_at')) {
      $this->db->pdo()->exec("ALTER TABLE `{$this->table()}` ADD COLUMN disbursed_at BIGINT NULL");
      $this->schema = new SchemaInspector($this->db);
    }
    // Los créditos existentes quedan como "propietario" por el valor por defecto.
    if (!$this->schema->columnExists($this->table(), 'beneficiary_type')) {
      $this->db->pdo()->exec("ALTER TABLE `{$this->table()}` ADD COLUMN beneficiary_type VARCHAR(20) NOT NULL DEFAULT 'propietario'");
      $this->db->pdo()->exec("CREATE INDEX idx_beneficiary_type ON `{$this->table()}` (beneficiary_type)");
      $this->schema = new SchemaInspector($this->db);
    }
  }

  /**
   * Llamado cada vez que cambia la financiación de una cotización. "Sí" crea (o reabre si estaba anulada)
   * la solicitud y avisa; "No" anula la solicitud pendiente. Nunca lanza: la respuesta de la cotización manda.
   * @param array{employee_id?:string,name?:string} $actor
   */
  public function syncFromQuote(int $quoteId, string $financing, array $actor): void
  {
    try {
      $this->ensureSchema();
      $financing = strtolower(trim($financing));
      $credit = $this->byQuote($quoteId);
      if ($financing === 'si') {
        if ($credit === null) {
          $id = $this->createFromQuote($quoteId, $actor);
          if ($id > 0) $this->notifyRequested($id);
        } elseif ($credit['status'] === 'anulado') {
          $this->setStatus((int) $credit['id'], 'pendiente', ['closed_at' => null]);
          $this->event((int) $credit['id'], 'reabierto', $actor, ['motivo' => 'La cotización volvió a quedar con financiación Sí.']);
          $this->recordRequestHistory((int) $credit['id'], $actor, true);
          $this->notifyRequested((int) $credit['id']);
        }
      } elseif ($financing === 'no' && $credit !== null && $credit['status'] === 'pendiente') {
        $this->setStatus((int) $credit['id'], 'anulado', ['closed_at' => time()]);
        $this->event((int) $credit['id'], 'anulado', $actor, ['motivo' => 'La cotización quedó sin financiación.']);
        $this->recordHistories($credit, 'Crédito anulado', 'Crédito #' . $credit['id'] . ' anulado: la cotización #' . (int) $credit['quote_id'] . ' quedó sin financiación.', $actor);
      }
    } catch (\Throwable $error) {
      error_log('[owner-credit-sync] ' . $error->getMessage());
    }
  }

  /** Abre el crédito de una cotización desde su tarjeta; si no existe lo crea sin notificar (cotizaciones previas). */
  public function ensureForQuote(int $quoteId, array $actor): int
  {
    $this->ensureSchema();
    $credit = $this->byQuote($quoteId);
    if ($credit !== null) return (int) $credit['id'];
    $id = $this->createFromQuote($quoteId, $actor);
    if ($id <= 0) throw new \DomainException('La cotización no existe o no tiene contrato vinculado.');
    return $id;
  }

  /** Crédito sin cotización, a partir del número de contrato. */
  public function createManual(int $contractId, float $amount, int $term, string $purpose, array $actor, string $contractEnd = '', string $ownerKey = '', string $beneficiaryType = 'propietario'): int
  {
    $this->ensureSchema();
    if (!isset(self::BENEFICIARY_TYPES[$beneficiaryType])) throw new \DomainException('Indica para quién es el crédito.');
    if (!in_array($beneficiaryType, self::AVAILABLE_BENEFICIARY_TYPES, true)) throw new \DomainException('Los créditos a ' . strtolower(self::BENEFICIARY_TYPES[$beneficiaryType]) . ' todavía no están disponibles.');
    $contract = $this->contractById($contractId);
    if ($contract === null) throw new \DomainException('Selecciona un contrato de la búsqueda.');
    $owners = $this->contractOwners($contractId);
    $owner = null;
    foreach ($owners as $candidate) if ($candidate['key'] === $ownerKey) $owner = $candidate;
    if ($owner === null) {
      if (count($owners) > 1) throw new \DomainException('Selecciona a quién se le hace el crédito.');
      $owner = $owners[0] ?? null;
    }
    if ($amount <= 0) throw new \DomainException('Indica el valor solicitado.');
    $storedEnd = self::timestamp($contract['fin_contrato'] ?? '');
    $endTs = self::dateInput($contractEnd) ?: $storedEnd;
    if ($term <= 0) throw new \DomainException('Indica el plazo solicitado en meses.');
    $this->assertRequestTerm($term, $this->ownerDocument($owner, $contract));
    $id = $this->insertCredit($contract, null, '', $amount, $purpose, $actor, $term, $owner, $beneficiaryType);
    // La fecha fin corregida en el formulario se guarda en el contrato (con historial).
    if ($endTs !== $storedEnd) $this->saveContractEnd($this->find($id) ?? [], $endTs, $actor);
    $this->notifyRequested($id);
    return $id;
  }

  /**
   * Simulación desde "Nueva solicitud", sin registrar la solicitud: el mismo cálculo del liquidador con el contrato
   * elegido y los demás contratos del propietario. Sin valor o sin plazo se simula con el cupo preaprobado y el
   * plazo máximo.
   * @return array<string,mixed> workspace con estado "simulacion", más preapproval y portfolio
   */
  public function simulation(string $document, int $contractId, float $amount, int $term, string $purpose, string $ownerKey, array $actor): array
  {
    $this->ensureSchema();
    $portfolio = $this->ownerPortfolio($document);
    $row = null;
    foreach ($portfolio as $candidate) if ($candidate['id'] === $contractId) $row = $candidate;
    $contract = $row !== null ? $this->contractById($contractId) : null;
    if ($row === null || $contract === null) throw new \DomainException('Busca al propietario por documento y elige el contrato.');
    $owner = null;
    foreach ($this->contractOwners($contractId) as $candidate) if ($candidate['key'] === ($ownerKey !== '' ? $ownerKey : $row['owner']['key'])) $owner = $candidate;
    if ($owner !== null && $owner['document'] === '') $owner['document'] = (string) $row['owner']['document'];
    $preapproval = OwnerCreditCalculator::preapproval($portfolio, $this->config());
    if ($amount <= 0) $amount = round($preapproval['amount']);
    if ($term <= 0) $term = $preapproval['max_term'];
    $this->assertRequestTerm($term, $document);
    if (!in_array($purpose, OwnerCreditSettings::PURPOSES, true)) $purpose = OwnerCreditSettings::PURPOSES[0];
    $credit = ['id' => 0, 'status' => 'simulacion', 'evaluation_json' => null, 'policy_override' => 0, 'decision_note' => '', 'decided_by_name' => '', 'decided_at' => 0]
      + $this->creditRow($contract, null, '', $amount, $purpose, $actor, $term, $owner, 'propietario');
    $credit['status'] = 'simulacion';
    return $this->workspaceFor($credit, ['amount' => $amount, 'term' => $term]) + ['preapproval' => $preapproval, 'portfolio' => $portfolio];
  }

  /** La simulación generada queda en el historial de cada contrato del propietario y de su inmueble. @param array<string,mixed> $work simulation() */
  public function recordSimulation(array $work, array $actor): void
  {
    $credit = $work['credit'];
    $c = $work['conditions'];
    $pre = $work['preapproval'];
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.');
    $message = 'Simulación de crédito para el propietario ' . $credit['owner_name'] . ((string) $credit['owner_document'] !== '' ? ' (documento ' . $credit['owner_document'] . ')' : '') . ': '
      . $money($c['amount']) . ' a ' . (int) $c['term'] . ' meses, tasa ' . number_format($c['rate'] * 100, 2, ',', '.') . ' % N.M.V, cuota ' . $money($c['payment']) . '. '
      . 'Cupo preaprobado ' . $money($pre['amount']) . ' con ' . $pre['contracts'] . ' contrato(s), hasta ' . $pre['max_term'] . ' cuotas. '
      . 'Cuotas a descontar del contrato #' . $credit['contract_code'] . '. Es una simulación: no se registró solicitud.';
    foreach ($work['portfolio'] as $row) {
      $this->recordHistories(['id' => 0, 'quote_id' => 0, 'ticket_id' => '', 'contract_id' => $row['id'], 'contract_code' => $row['contrato']], 'Simulación de crédito', $message, $actor);
    }
  }

  /**
   * "Nueva solicitud": contratos Entregado o Por entregar donde el documento (cédula o NIT) es de un propietario, con esa persona ya
   * resuelta como beneficiaria. Busca el documento exacto (sin puntos ni espacios) en los propietarios del
   * mandato, en documento_propietario del contrato y en la ficha del propietario.
   * @return list<array{contract:array<string,mixed>,owner:array{key:string,name:string,document:string,email:string,phone:string}}>
   */
  public function contractsByOwnerDocument(string $document): array
  {
    $document = self::document($document);
    if (preg_match('/^[0-9A-Z-]{4,20}$/', $document) !== 1) throw new \InvalidArgumentException('Escribe el documento o NIT del propietario (mínimo 4 caracteres).');
    $clean = static fn(string $column): string => "UPPER(REPLACE(REPLACE({$column}, '.', ''), ' ', '')) = ?";
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $where = [];
    $args = [];
    if ($this->schema->columnExists($contracts, 'documento_propietario')) {
      $where[] = $clean('`documento_propietario`');
      $args[] = $document;
    }
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $owners = $this->db->table('jet_cct_propietarios');
    foreach ([['id_contrato_mandato', $mandates, ['documento_1', 'nit_1', 'documento_2', 'nit_2', 'documento_3', 'nit_3', 'documento_4', 'nit_4', 'documento_5', 'nit_5', 'documento_6', 'nit_6'], ['_ID']], ['id_propietario', $owners, ['documento', 'documento_juridico'], ['_ID', 'id_propietario']]] as [$link, $source, $documentColumns, $idColumns]) {
      if (!$this->schema->columnExists($contracts, $link) || !$this->schema->tableExists($source)) continue;
      $documentColumns = array_values(array_filter($documentColumns, fn(string $column): bool => $this->schema->columnExists($source, $column)));
      $idColumns = array_values(array_filter($idColumns, fn(string $column): bool => $this->schema->columnExists($source, $column)));
      if ($documentColumns === [] || $idColumns === []) continue;
      $ids = [];
      $rows = $this->db->getResults('SELECT `' . implode('`, `', $idColumns) . "` FROM `{$source}` WHERE " . implode(' OR ', array_map(static fn(string $column): string => $clean("`{$column}`"), $documentColumns)) . ' LIMIT 100', array_fill(0, count($documentColumns), $document));
      foreach ($rows as $row) foreach ($idColumns as $column) if (trim((string) ($row[$column] ?? '')) !== '') $ids[trim((string) $row[$column])] = true;
      if ($ids === []) continue;
      $where[] = "`{$link}` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
      array_push($args, ...array_map('strval', array_keys($ids)));
    }
    if ($where === []) return [];
    $results = [];
    // Solo contratos vigentes o por iniciar: de los demás no hay canon del cual descontar.
    foreach ($this->db->getResults("SELECT * FROM `{$contracts}` WHERE (" . implode(' OR ', $where) . ") AND LOWER(TRIM(`estado`)) IN ('entregado', 'por entregar') ORDER BY (LOWER(TRIM(`estado`)) = 'entregado') DESC, `_ID` DESC LIMIT 30", $args) as $contract) {
      $candidates = $this->contractOwners((int) $contract['_ID']);
      $owner = null;
      foreach ($candidates as $candidate) if (self::document($candidate['document']) === $document) $owner = $candidate;
      // El documento estaba en el contrato o en la ficha del propietario: es el propietario principal.
      if ($owner === null) $owner = $candidates[0] ?? null;
      if ($owner === null) continue;
      if ($owner['document'] === '') $owner['document'] = $document;
      $results[] = ['contract' => $contract, 'owner' => array_intersect_key($owner, array_flip(['key', 'name', 'document', 'email', 'phone', 'owner_id']))];
    }
    return $results;
  }

  /**
   * Contratos del propietario con su ingreso neto y vigencia, ordenados para preseleccionar: primero el de vigencia
   * más larga (en empate, Entregado). Con ellos se calcula el cupo preaprobado y el plazo máximo.
   * @return list<array<string,mixed>>
   */
  public function ownerPortfolio(string $document, int $fromTs = 0): array
  {
    $rows = [];
    foreach ($this->contractsByOwnerDocument($document) as $index => ['contract' => $contract, 'owner' => $owner]) {
      $end = self::timestamp($contract['fin_contrato'] ?? '');
      $income = $this->defaultIncome($contract);
      $deductions = array_sum($income['deductions']);
      $rows[] = [
        'id' => (int) ($contract['_ID'] ?? 0), 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
        'direccion' => (string) ($contract['direccion'] ?? ''), 'estado' => (string) ($contract['estado'] ?? ''), 'arrendatario' => (string) ($contract['arrendatario'] ?? ''),
        'fin' => $end > 0 ? date('d/m/Y', $end) : '', 'end_ts' => $end, 'max_months' => OwnerCreditCalculator::monthsUntil($fromTs ?: time(), $end),
        'canon' => $income['gross'], 'deductions' => $deductions, 'net_income' => max(0.0, $income['gross'] - $deductions),
        'siniestrado' => self::isYes($contract['esta_sinestrado'] ?? ''), 'owner' => $owner, 'order' => $index,
      ];
    }
    usort($rows, static fn(array $a, array $b): int => [$b['max_months'], $a['order']] <=> [$a['max_months'], $b['order']]);
    return array_map(static function (array $row): array { unset($row['order']); return $row; }, $rows);
  }

  /**
   * Demás contratos del propietario del crédito (por su documento), para el tope y el plazo consolidados. Un
   * crédito ya decidido usa los que quedaron guardados en su evaluación.
   * @return list<array<string,mixed>>
   */
  private function otherContracts(array $credit, int $fromTs): array
  {
    if (!in_array($credit['status'], ['pendiente', 'simulacion'], true)) {
      $stored = json_decode((string) ($credit['evaluation_json'] ?? ''), true);
      // Los decididos antes de consolidar contratos no tienen la lista: se evaluaron solo con su contrato.
      return is_array($stored) && isset($stored['other_contracts']) && is_array($stored['other_contracts']) ? $stored['other_contracts'] : [];
    }
    $document = trim((string) ($credit['owner_document'] ?? ''));
    if ($document === '') return [];
    try {
      $rows = $this->ownerPortfolio($document, $fromTs);
    } catch (\InvalidArgumentException) {
      return [];
    }
    $others = [];
    foreach ($rows as $row) {
      if ($row['id'] === (int) $credit['contract_id']) continue;
      $others[] = array_intersect_key($row, array_flip(['id', 'contrato', 'inmueble', 'estado', 'fin', 'end_ts', 'max_months', 'canon', 'deductions', 'net_income', 'siniestrado']));
    }
    return $others;
  }

  private static function document(string $value): string
  {
    return strtoupper(preg_replace('/[\s.]+/', '', trim($value)) ?? '');
  }

  /** @return array<string,mixed>|null */
  public function find(int $id): ?array
  {
    $this->ensureSchema();
    $row = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `id` = ? LIMIT 1", [$id]);
    return is_array($row) ? $row : null;
  }

  /**
   * Todo lo que necesita el liquidador. $input trae lo que el funcionario está editando (simulación);
   * sin $input se usan los valores guardados o los sugeridos.
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function workspace(int $id, array $input = []): array
  {
    $this->settleFinished($id);
    $credit = $this->find($id);
    if ($credit === null) throw new \DomainException('El crédito ya no existe.');
    if ($credit['status'] === 'pendiente' && trim((string) ($credit['owner_document'] ?? '')) === '') $credit = $this->fillOwnerDocument($credit);
    return $this->workspaceFor($credit, $input);
  }

  /** @param array<string,mixed> $credit fila guardada o simulación sin guardar @param array<string,mixed> $input */
  private function workspaceFor(array $credit, array $input = []): array
  {
    $id = (int) $credit['id'];
    $contract = $this->contractById((int) $credit['contract_id']) ?? [];
    $settings = $this->config();
    $stored = json_decode((string) ($credit['deductions_json'] ?? ''), true);
    $defaults = $this->defaultIncome($contract);
    $deductions = [];
    foreach (array_keys(self::DEDUCTIONS) as $key) {
      $deductions[$key] = array_key_exists('ded_' . $key, $input)
        ? self::money($input['ded_' . $key])
        : (is_array($stored) && array_key_exists($key, $stored) ? (float) $stored[$key] : (float) ($defaults['deductions'][$key] ?? 0));
    }
    $gross = array_key_exists('gross', $input) ? self::money($input['gross']) : ((float) $credit['gross_income'] > 0 ? (float) $credit['gross_income'] : $defaults['gross']);
    $requested = array_key_exists('requested_amount', $input) ? self::money($input['requested_amount']) : (float) $credit['requested_amount'];
    $requestedTerm = array_key_exists('requested_term', $input) ? max(0, (int) $input['requested_term']) : (int) $credit['requested_term'];
    $pending = in_array($credit['status'], ['pendiente', 'simulacion'], true);
    $requestTs = (int) $credit['created_at'];
    $storedEndTs = self::timestamp($contract['fin_contrato'] ?? '');
    $inputEndTs = self::dateInput($input['contract_end'] ?? '');
    $endTs = $inputEndTs > 0 ? $inputEndTs : $storedEndTs;
    $evaluationTs = in_array($credit['status'], ['pendiente', 'simulacion'], true) ? time() : $requestTs;
    // Tope y plazo con todos los contratos del propietario, no solo con el que se descuentan las cuotas.
    $others = $this->otherContracts($credit, $evaluationTs);
    $vacancySignal = $this->vacancySignal(array_merge([['id' => (int) $credit['contract_id'], 'contrato' => (string) ($contract['contrato'] ?? $credit['contract_code']), 'end_ts' => $storedEndTs]], $others));
    $vacancy = in_array($input['vacancy'] ?? null, ['Alta', 'Media', 'Baja'], true) ? (string) $input['vacancy'] : ($pending ? $vacancySignal['level'] : ((string) ($credit['vacancy'] ?? '') ?: 'Baja'));
    $evaluation = OwnerCreditCalculator::evaluate([
      'gross' => $gross, 'deductions' => $deductions, 'requested_amount' => $requested, 'requested_term' => $requestedTerm,
      'request_ts' => $evaluationTs, 'contract_end_ts' => $endTs, 'vacancy' => $vacancy,
      'other_net_income' => array_sum(array_map(static fn(array $row): float => (float) $row['net_income'], $others)),
      'longest_months' => $others === [] ? 0 : max(array_map(static fn(array $row): int => (int) $row['max_months'], $others)),
    ], $settings);
    $evaluation['other_contracts'] = $others;
    $evaluation['vacancy_signal'] = $vacancySignal;

    $decided = in_array($credit['status'], ['aprobado', 'pagado'], true);
    $suggested = $evaluation['suggested'];
    $amount = array_key_exists('amount', $input) ? self::money($input['amount']) : ($decided ? (float) $credit['approved_amount'] : (float) $suggested['amount']);
    $term = array_key_exists('term', $input) ? max(0, (int) $input['term']) : ($decided ? (int) $credit['approved_term'] : (int) $suggested['term']);
    $rate = array_key_exists('rate', $input) ? self::rate($input['rate']) : ($decided ? (float) $credit['approved_rate'] : round((float) $suggested['rate'], 6));
    $payment = OwnerCreditCalculator::payment($rate, $term, $amount);
    $schedule = OwnerCreditCalculator::schedule($rate, $term, $amount);
    // La primera cuota se descuenta un mes después del desembolso; el crédito se da por pagado al vencer la última.
    $disbursedTs = $decided
      ? ((int) ($credit['disbursed_at'] ?? 0) ?: (int) strtotime(date('Y-m-d', (int) $credit['decided_at'])))
      : (self::dateInput($input['disbursed_at'] ?? '') ?: (int) strtotime('today'));
    $firstPaymentTs = $decided && (int) ($credit['first_payment_ts'] ?? 0) > 0 ? (int) $credit['first_payment_ts'] : (int) strtotime('+1 month', $disbursedTs);
    foreach ($schedule as $index => $line) $schedule[$index]['fecha'] = self::installmentTs($firstPaymentTs, $index + 1);
    $sources = $this->discountSources($credit, $contract, $evaluation, $endTs);
    $plan = OwnerCreditCalculator::discountPlan(array_map(fn(array $source): array => $source + ['covers' => $this->coveredInstallments($source, $firstPaymentTs, $term)], $sources), $payment, $term, (int) $credit['contract_id']);
    // Todos los contratos de la persona: las políticas de estado, vigencia y siniestro se revisan sobre todos.
    $all = array_merge([[
      'id' => (int) $credit['contract_id'], 'contrato' => (string) ($contract['contrato'] ?? $credit['contract_code']), 'estado' => (string) ($contract['estado'] ?? ''),
      'siniestrado' => self::isYes($contract['esta_sinestrado'] ?? ''), 'max_months' => (int) $evaluation['contract_months'], 'fin' => $endTs > 0 ? date('d/m/Y', $endTs) : '',
    ]], $others);
    $approved = array_values(array_filter($this->ownerCredits($credit), static fn(array $row): bool => $row['status'] === 'aprobado'));
    $outstanding = array_sum(array_map(fn(array $row): float => $this->balance($row), $approved));
    // Siniestro y créditos vigentes los propone el sistema; el analista los corrige (Sí/No) tras verificar con Cartera.
    $stored = in_array($credit['status'], ['pendiente', 'simulacion'], true) ? [] : (json_decode((string) ($credit['evaluation_json'] ?? ''), true)['analyst'] ?? []);
    // Solo se conserva lo que el analista cambió frente al sistema; lo demás sigue al sistema.
    $overrides = [];
    $choice = static function (string $key, bool $system) use ($input, $stored, &$overrides): bool {
      $value = in_array($input[$key] ?? null, ['si', 'no'], true) ? $input[$key] === 'si'
        : (is_array($stored) && in_array($key, (array) ($stored['overrides'] ?? []), true) ? (bool) ($stored[$key] ?? $system) : $system);
      if ($value !== $system) $overrides[] = $key;
      return $value;
    };
    $siniestrados = array_values(array_map(static fn(array $row): string => '#' . $row['contrato'], array_filter($all, static fn(array $row): bool => !empty($row['siniestrado']))));
    $evaluation['analyst'] = ['siniestro' => $choice('siniestro', $siniestrados !== []), 'siniestrados' => $siniestrados, 'has_credits' => $choice('has_credits', $approved !== [])];
    if ($pending && $evaluation['vacancy'] !== $vacancySignal['level']) $overrides[] = 'vacancy';
    $evaluation['analyst']['overrides'] = $overrides;
    // Cupo preaprobado de la persona con todos sus contratos, menos el saldo de sus créditos aprobados.
    $counted = $evaluation['analyst']['has_credits'] ? $outstanding : 0.0;
    $preapproval = [
      'amount' => (float) $evaluation['max_amount'], 'contracts' => count($all), 'net_income' => (float) $evaluation['total_net_income'],
      'outstanding' => $counted, 'system_outstanding' => $outstanding, 'available' => max(0.0, (float) $evaluation['max_amount'] - $counted), 'max_term' => (int) $evaluation['max_months'],
      'credits' => array_map(static fn(array $row): int => (int) $row['id'], $approved), 'requested' => $requested,
    ];
    $policies = $this->policies($all, $evaluation, $settings, $amount, $term, $rate, $payment, $plan, $preapproval);
    // El plazo solicitado nunca puede pasar de los meses que faltan entre la solicitud y el fin del contrato.
    $termError = $requestedTerm <= 0 ? 'Escribe el plazo solicitado (meses) para evaluar el crédito.' : ($requestedTerm > $evaluation['max_months'] ? self::termMessage($requestedTerm, $evaluation) : '');

    return [
      'credit' => $credit,
      'contract' => [
        'id' => (int) ($contract['_ID'] ?? 0), 'code' => (string) ($contract['contrato'] ?? $credit['contract_code']),
        'estado' => (string) ($contract['estado'] ?? ''), 'inicio_ts' => self::timestamp($contract['inicio_contrato'] ?? ''),
        'fin_ts' => $endTs, 'fin_saved_ts' => $storedEndTs, 'canon' => self::money($contract['valor_canon'] ?? ''), 'siniestrado' => self::isYes($contract['esta_sinestrado'] ?? ''),
        'aseguradora' => (string) ($contract['aseguradora'] ?? ''),
      ],
      'income_source' => $defaults['source'],
      'term_error' => $termError,
      'settings' => $settings,
      'evaluation' => $evaluation,
      'conditions' => ['amount' => $amount, 'term' => $term, 'rate' => $rate, 'payment' => $payment, 'total_interest' => array_sum(array_column($schedule, 'interes'))],
      'schedule' => $schedule,
      'disbursed_ts' => $disbursedTs,
      'first_payment_ts' => $firstPaymentTs,
      'last_payment_ts' => $term > 0 ? self::installmentTs($firstPaymentTs, $term) : 0,
      'sources' => $sources,
      'discount_plan' => $plan,
      'preapproval' => $preapproval,
      'owners' => $credit['status'] === 'pendiente' ? $this->ownerChoices($credit) : [],
      'policies' => $policies,
      'policies_ok' => !in_array(false, array_column($policies, 'ok'), true),
      // Decidido: lo que quedó guardado con la decisión; pendiente: se recalcula con lo que está editando.
      'adjustments' => $pending ? $this->adjustments($evaluation, $defaults, $policies, ['amount' => $amount, 'term' => $term, 'rate' => $rate], $storedEndTs, $endTs)
        : array_values((array) (json_decode((string) ($credit['evaluation_json'] ?? ''), true)['adjustments'] ?? [])),
      'other_credits' => $this->ownerCredits($credit),
      'events' => $id > 0 ? $this->events($id) : [],
    ];
  }

  /**
   * Aprueba o rechaza. Los valores se recalculan aquí; lo que llega del navegador solo son entradas.
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function decide(int $id, string $decision, array $input, array $actor): array
  {
    if (!in_array($decision, ['aprobado', 'rechazado'], true)) throw new \DomainException('Decisión no válida.');
    $note = trim((string) ($input['note'] ?? ''));
    $purpose = trim((string) ($input['purpose'] ?? ''));
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $locked = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `id` = ? LIMIT 1 FOR UPDATE", [$id]);
      if (!is_array($locked)) throw new \DomainException('El crédito ya no existe.');
      if ($locked['status'] !== 'pendiente') throw new \DomainException('Este crédito ya fue ' . strtolower(self::STATUSES[$locked['status']] ?? $locked['status']) . '.');
      $work = $this->workspace($id, $input);
      if ($decision === 'aprobado' && $work['term_error'] !== '') throw new \DomainException($work['term_error']);
      // La usura es un tope legal: no se puede aprobar por encima ni con justificación.
      if ($decision === 'aprobado' && $work['conditions']['rate'] > $work['evaluation']['usury_monthly_legal'] + 1e-9) {
        throw new \DomainException('La tasa supera la usura (' . number_format($work['evaluation']['usury_monthly_legal'] * 100, 4, ',', '.') . ' % mensual). Bájala para poder aprobar.');
      }
      if ($work['contract']['fin_ts'] !== $work['contract']['fin_saved_ts']) $this->saveContractEnd($locked, $work['contract']['fin_ts'], $actor);
      $conditions = $work['conditions'];
      $evaluation = $work['evaluation'];
      if ($decision === 'rechazado' && mb_strlen($note) < self::MIN_NOTE) throw new \DomainException('Escribe el motivo del rechazo (mínimo ' . self::MIN_NOTE . ' caracteres).');
      if ($decision === 'aprobado') {
        if ($conditions['amount'] <= 0 || $conditions['term'] <= 0) throw new \DomainException('Indica el valor y el plazo a aprobar.');
        if (!$work['policies_ok'] && mb_strlen($note) < self::MIN_NOTE) throw new \DomainException('La solicitud incumple políticas: escribe la justificación de la aprobación (mínimo ' . self::MIN_NOTE . ' caracteres).');
      }
      $now = time();
      $data = [
        'status' => $decision,
        'purpose' => $purpose !== '' ? mb_substr($purpose, 0, 120) : (string) $locked['purpose'],
        'requested_amount' => self::money($input['requested_amount'] ?? $locked['requested_amount']),
        'requested_term' => max(0, (int) ($input['requested_term'] ?? $locked['requested_term'])),
        'vacancy' => $evaluation['vacancy'],
        'gross_income' => $evaluation['gross'],
        'deductions_json' => json_encode($evaluation['deductions'], JSON_THROW_ON_ERROR),
        'net_income' => $evaluation['net_income'],
        'max_amount' => $evaluation['max_amount'],
        'max_months' => $evaluation['max_months'],
        'policy_json' => json_encode($work['policies'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'evaluation_json' => json_encode($evaluation + ['settings' => $work['settings'], 'adjustments' => $work['adjustments']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'policy_override' => $decision === 'aprobado' && !$work['policies_ok'] ? 1 : 0,
        'decision_note' => $note,
        'decided_by_id' => (string) ($actor['employee_id'] ?? ''),
        'decided_by_name' => (string) ($actor['name'] ?? ''),
        'decided_at' => $now,
        'updated_at' => $now,
      ];
      if ($decision === 'aprobado') {
        $data += [
          'approved_amount' => round($conditions['amount'], 2), 'approved_term' => $conditions['term'],
          'approved_rate' => round($conditions['rate'], 6), 'monthly_payment' => round($conditions['payment'], 2),
          'total_interest' => round($conditions['total_interest'], 2), 'disbursed_at' => $work['disbursed_ts'], 'first_payment_ts' => $work['first_payment_ts'],
        ];
      }
      $this->db->update($this->table(), $this->schema->filterTableData($this->table(), $data), ['id' => $id]);
      if ($decision === 'aprobado') $this->saveSources($id, (int) $locked['contract_id'], $work['sources']);
      $this->event($id, $decision, $actor, ['nota' => $note, 'condiciones' => $decision === 'aprobado' ? $conditions : null, 'incumple' => array_values(array_map(static fn(array $p): string => $p['label'], array_filter($work['policies'], static fn(array $p): bool => !$p['ok']))), 'ajustes' => array_map(static fn(array $item): string => $item['label'] . ': ' . $item['detail'], $work['adjustments'])]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }

    $credit = $this->find($id) ?? [];
    $summary = $this->decisionSummary($credit);
    $this->recordHistories($credit, $decision === 'aprobado' ? 'Crédito aprobado' : 'Crédito rechazado', $summary, $actor);
    // Los demás contratos que respaldan el crédito también lo registran (sin repetir el historial del caso).
    if ($decision === 'aprobado') {
      foreach ($this->storedSources($id) as $source) {
        if ((int) $source['contract_id'] === (int) $credit['contract_id']) continue;
        $this->recordHistories(['ticket_id' => '', 'contract_id' => (int) $source['contract_id'], 'contract_code' => (string) $source['contract_code']] + $credit, 'Crédito aprobado', $summary . ' Este contrato respalda el crédito (contrato principal #' . $credit['contract_code'] . ').', $actor);
      }
    }
    $queued = $this->notifyDecision($credit);
    return ['credit' => $credit, 'queued' => $queued];
  }

  /** Crédito aprobado → pagado; pendiente o aprobado → anulado. Siempre con nota. */
  public function changeStatus(int $id, string $status, string $note, array $actor): array
  {
    $allowed = ['pagado' => ['aprobado'], 'anulado' => ['pendiente', 'aprobado']];
    if (!isset($allowed[$status])) throw new \DomainException('Estado no válido.');
    $note = trim($note);
    if (mb_strlen($note) < self::MIN_NOTE) throw new \DomainException('Escribe una observación (mínimo ' . self::MIN_NOTE . ' caracteres).');
    $credit = $this->find($id);
    if ($credit === null) throw new \DomainException('El crédito ya no existe.');
    if (!in_array($credit['status'], $allowed[$status], true)) throw new \DomainException('No se puede marcar como ' . strtolower(self::STATUSES[$status]) . ' un crédito ' . strtolower(self::STATUSES[$credit['status']] ?? $credit['status']) . '.');
    $this->setStatus($id, $status, ['closed_at' => time(), 'decision_note' => trim((string) $credit['decision_note'] . "\n" . self::STATUSES[$status] . ': ' . $note)]);
    $this->event($id, $status, $actor, ['nota' => $note, 'estado_anterior' => $credit['status']]);
    $credit = $this->find($id) ?? $credit;
    $this->recordHistories($credit, 'Crédito ' . strtolower(self::STATUSES[$status]), 'Crédito #' . $id . ' marcado como ' . strtolower(self::STATUSES[$status]) . '. ' . $note, $actor);
    return $credit;
  }

  /**
   * Anula una solicitud pendiente desde el listado: solo cambia el estado. No escribe eventos ni historiales
   * y deja de contar en el informe (solo se ve filtrando por "Anulado").
   */
  public function voidRequest(int $id): void
  {
    $credit = $this->find($id);
    if ($credit === null) throw new \DomainException('La solicitud ya no existe.');
    if ($credit['status'] !== 'pendiente') throw new \DomainException('Solo se pueden anular solicitudes pendientes.');
    $this->setStatus($id, 'anulado', ['closed_at' => time()]);
  }

  /**
   * Listado y totales de Actividades administrativas › Créditos. Los anulados no cuentan salvo que se filtren.
   * @param array<string,mixed> $filters
   * @return array<string,mixed>
   */
  public function report(array $filters): array
  {
    $this->ensureSchema();
    $this->settleFinished();
    $where = ['1=1'];
    $args = [];
    $status = (string) ($filters['status'] ?? '');
    if (isset(self::STATUSES[$status])) { $where[] = '`status` = ?'; $args[] = $status; }
    else $where[] = "`status` <> 'anulado'";
    $type = (string) ($filters['beneficiary_type'] ?? '');
    if (isset(self::BENEFICIARY_TYPES[$type]) && $this->schema->columnExists($this->table(), 'beneficiary_type')) { $where[] = '`beneficiary_type` = ?'; $args[] = $type; }
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
      $ts = self::timestamp($filters[$key] ?? '');
      if ($ts > 0) { $where[] = "`created_at` {$op} ?"; $args[] = $key === 'to' ? $ts + 86399 : $ts; }
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = '(`contract_code` LIKE ? OR `owner_name` LIKE ? OR `property` LIKE ? OR CAST(`quote_id` AS CHAR) = ?)';
      array_push($args, $like, $like, $like, $search);
    }
    $rows = $this->db->getResults("SELECT * FROM `{$this->table()}` WHERE " . implode(' AND ', $where) . ' ORDER BY `created_at` DESC, `id` DESC LIMIT 500', $args);
    $totals = array_fill_keys(array_keys(self::STATUSES), 0);
    $approvedAmount = 0.0;
    $outstanding = 0.0;
    $interest = 0.0;
    foreach ($rows as &$row) {
      $totals[$row['status']] = ($totals[$row['status']] ?? 0) + 1;
      $row['balance'] = null;
      if (in_array($row['status'], ['aprobado', 'pagado'], true)) {
        $approvedAmount += (float) $row['approved_amount'];
        $interest += (float) $row['total_interest'];
      }
      if ($row['status'] === 'aprobado') {
        $row['balance'] = $this->balance($row);
        $outstanding += $row['balance'];
      }
    }
    unset($row);
    $decided = $totals['aprobado'] + $totals['pagado'] + $totals['rechazado'];
    return [
      'rows' => $rows,
      'totals' => $totals + [
        'count' => count($rows),
        'approved_amount' => $approvedAmount,
        'outstanding' => $outstanding,
        'interest' => $interest,
        'approval_rate' => $decided > 0 ? ($totals['aprobado'] + $totals['pagado']) / $decided : null,
      ],
    ];
  }

  /** Fecha de la cuota $number (1 = primera). */
  public static function installmentTs(int $firstPaymentTs, int $number): int
  {
    return $firstPaymentTs > 0 ? (int) strtotime('+' . max(0, $number - 1) . ' month', $firstPaymentTs) : 0;
  }

  /**
   * Un crédito aprobado queda pagado solo cuando vence su última cuota (primera cuota = desembolso + 1 mes). Sin $id
   * revisa todos los aprobados. Devuelve cuántos cerró.
   */
  public function settleFinished(?int $id = null): int
  {
    $this->ensureSchema();
    $rows = $this->db->getResults("SELECT * FROM `{$this->table()}` WHERE `status` = 'aprobado' AND `first_payment_ts` > 0" . ($id !== null ? ' AND `id` = ?' : ''), $id !== null ? [$id] : []);
    $system = ['employee_id' => '', 'name' => 'Sistema'];
    $closed = 0;
    foreach ($rows as $credit) {
      $last = self::installmentTs((int) $credit['first_payment_ts'], (int) $credit['approved_term']);
      if ($last <= 0 || $last > time()) continue;
      $note = 'Pagado automáticamente: la cuota ' . (int) $credit['approved_term'] . ' de ' . (int) $credit['approved_term'] . ' venció el ' . date('d/m/Y', $last) . '.';
      // Solo uno cierra el crédito aunque dos peticiones lleguen a la vez.
      $changed = $this->db->update($this->table(), ['status' => 'pagado', 'closed_at' => $last, 'updated_at' => time(), 'decision_note' => trim((string) $credit['decision_note'] . "\n" . $note)], ['id' => (int) $credit['id'], 'status' => 'aprobado']);
      if ($changed < 1) continue;
      $this->event((int) $credit['id'], 'pagado', $system, ['nota' => $note, 'estado_anterior' => 'aprobado']);
      $this->recordHistories($credit, 'Crédito pagado', 'Crédito #' . $credit['id'] . ' ' . lcfirst($note), $system);
      $closed++;
    }
    return $closed;
  }

  /**
   * Saldo de capital de los créditos aprobados de la persona: por documento o por cualquiera de sus fichas de
   * propietario (un crédito guardado sin documento se encuentra por la ficha).
   * @param list<string> $ownerIds
   */
  public function outstandingByDocument(string $document, array $ownerIds = []): float
  {
    $this->ensureSchema();
    $document = self::document($document);
    $ownerIds = array_values(array_unique(array_filter(array_map(static fn($id): string => trim((string) $id), $ownerIds), static fn(string $id): bool => $id !== '' && !str_starts_with($id, 'doc:'))));
    $where = [];
    $args = [];
    if ($document !== '') { $where[] = "UPPER(REPLACE(REPLACE(`owner_document`, '.', ''), ' ', '')) = ?"; $args[] = $document; }
    if ($ownerIds !== []) { $where[] = '`owner_id` IN (' . implode(',', array_fill(0, count($ownerIds), '?')) . ')'; array_push($args, ...$ownerIds); }
    if ($where === []) return 0.0;
    $this->settleFinished();
    $total = 0.0;
    foreach ($this->db->getResults("SELECT * FROM `{$this->table()}` WHERE `status` = 'aprobado' AND (" . implode(' OR ', $where) . ')', $args) as $credit) $total += $this->balance($credit);
    return $total;
  }

  /**
   * Cambia a quién es el crédito, entre los propietarios del contrato principal, mientras está pendiente (en las
   * solicitudes de cotización se toma el primero del mandato). Queda en el historial.
   * @return array<string,mixed>
   */
  public function changeOwner(int $id, string $ownerKey, array $actor): array
  {
    $credit = $this->find($id);
    if ($credit === null) throw new \DomainException('El crédito ya no existe.');
    if ($credit['status'] !== 'pendiente') throw new \DomainException('Solo se puede cambiar a quién es el crédito mientras está pendiente.');
    $owner = null;
    foreach ($this->contractOwners((int) $credit['contract_id']) as $candidate) if ($candidate['key'] === $ownerKey) $owner = $candidate;
    if ($owner === null) throw new \DomainException('Selecciona uno de los propietarios del contrato.');
    $document = $this->ownerDocument($owner, $this->contractById((int) $credit['contract_id']) ?? []);
    if ($owner['owner_id'] === (string) $credit['owner_id'] && self::document($document) === self::document((string) $credit['owner_document'])) return $credit;
    $this->db->update($this->table(), $this->schema->filterTableData($this->table(), [
      'owner_id' => $owner['owner_id'], 'owner_name' => mb_substr($owner['name'], 0, 190), 'owner_email' => mb_substr($owner['email'], 0, 190),
      'owner_phone' => mb_substr($owner['phone'], 0, 40), 'owner_document' => mb_substr($document, 0, 40), 'updated_at' => time(),
    ]), ['id' => $id]);
    $label = static fn(string $name, string $doc): string => $name . ($doc !== '' ? ' (' . $doc . ')' : '');
    $change = $label((string) $credit['owner_name'], (string) $credit['owner_document']) . ' → ' . $label($owner['name'], $document);
    $this->event($id, 'beneficiario', $actor, ['nota' => 'Beneficiario: ' . $change]);
    $updated = $this->find($id) ?? $credit;
    $this->recordHistories($updated, 'Cambio de beneficiario del crédito', 'Crédito #' . $id . ': beneficiario cambiado de ' . $change . '.', $actor);
    return $updated;
  }

  /**
   * Cambia el contrato principal (del que se descuenta primero la cuota) por otro contrato de la persona mientras la
   * solicitud está pendiente. El ingreso se toma de nuevo del contrato elegido. Queda en el historial.
   * @return array<string,mixed>
   */
  public function changeContract(int $id, int $contractId, array $actor): array
  {
    $credit = $this->find($id);
    if ($credit === null) throw new \DomainException('El crédito ya no existe.');
    if ($credit['status'] !== 'pendiente') throw new \DomainException('Solo se puede cambiar el contrato principal mientras el crédito está pendiente.');
    if ($contractId === (int) $credit['contract_id']) return $credit;
    $document = trim((string) ($credit['owner_document'] ?? ''));
    if ($document === '' || !in_array($contractId, array_column($this->ownerPortfolio($document), 'id'), true)) throw new \DomainException('Elige uno de los contratos Entregado o Por entregar del propietario.');
    $contract = $this->contractById($contractId);
    if ($contract === null) throw new \DomainException('El contrato ya no existe.');
    $income = $this->defaultIncome($contract);
    $this->db->update($this->table(), $this->schema->filterTableData($this->table(), [
      'contract_id' => $contractId, 'contract_code' => (string) ($contract['contrato'] ?? ''), 'property' => (string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''),
      'address' => mb_substr((string) ($contract['direccion'] ?? ''), 0, 255), 'gross_income' => $income['gross'],
      'deductions_json' => json_encode($income['deductions'], JSON_THROW_ON_ERROR), 'updated_at' => time(),
    ]), ['id' => $id]);
    $change = '#' . $credit['contract_code'] . ' → #' . ($contract['contrato'] ?? '');
    $this->event($id, 'contrato_principal', $actor, ['nota' => 'Contrato principal: ' . $change]);
    $updated = $this->find($id) ?? $credit;
    $this->recordHistories($updated, 'Cambio de contrato principal del crédito', 'Crédito #' . $id . ': contrato principal cambiado de ' . $change . '; la cuota se descontará primero de este contrato.', $actor);
    return $updated;
  }

  /**
   * Lo que el analista cambió frente a lo que propone el sistema (o lo que incumple), para guardarlo con la decisión.
   * Solo se registran (con la decisión); aprobar exige justificación únicamente cuando incumple políticas.
   * @param array<string,mixed> $evaluation @param array<string,mixed> $defaults defaultIncome()
   * @param list<array<string,mixed>> $policies @param array{amount:float,term:int,rate:float} $conditions
   * @return list<array{key:string,label:string,detail:string,note:bool}>
   */
  private function adjustments(array $evaluation, array $defaults, array $policies, array $conditions, int $storedEndTs, int $endTs): array
  {
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $percent = static fn(float $value): string => number_format($value * 100, 2, ',', '.') . ' %';
    $yes = static fn(bool $value): string => $value ? 'Sí' : 'No';
    $items = [];
    $failed = array_values(array_filter($policies, static fn(array $policy): bool => !$policy['ok']));
    if ($failed !== []) $items[] = ['key' => 'politicas', 'label' => 'Políticas incumplidas', 'detail' => implode(', ', array_column($failed, 'label')), 'note' => true];
    if (abs((float) $evaluation['gross'] - (float) $defaults['gross']) >= 1) $items[] = ['key' => 'canon', 'label' => 'Canon', 'detail' => $money((float) $defaults['gross']) . ' → ' . $money((float) $evaluation['gross']), 'note' => true];
    foreach (self::DEDUCTIONS as $key => $label) {
      $from = (float) ($defaults['deductions'][$key] ?? 0);
      $to = (float) ($evaluation['deductions'][$key] ?? 0);
      if (abs($to - $from) >= 1) $items[] = ['key' => 'ded_' . $key, 'label' => $label, 'detail' => $money($from) . ' → ' . $money($to), 'note' => true];
    }
    $analyst = $evaluation['analyst'] ?? [];
    $overrides = (array) ($analyst['overrides'] ?? []);
    if (in_array('siniestro', $overrides, true)) $items[] = ['key' => 'siniestro', 'label' => 'Siniestro', 'detail' => 'sistema ' . $yes(!$analyst['siniestro']) . ' → analista ' . $yes((bool) $analyst['siniestro']), 'note' => true];
    if (in_array('has_credits', $overrides, true)) $items[] = ['key' => 'has_credits', 'label' => 'Créditos vigentes', 'detail' => 'sistema ' . $yes(!$analyst['has_credits']) . ' → analista ' . $yes((bool) $analyst['has_credits']), 'note' => true];
    if (in_array('vacancy', $overrides, true)) $items[] = ['key' => 'vacancy', 'label' => 'Probabilidad de desocupación', 'detail' => 'sistema ' . $evaluation['vacancy_signal']['level'] . ' → analista ' . $evaluation['vacancy'], 'note' => true];
    $suggested = $evaluation['suggested'];
    if (abs((float) $conditions['rate'] - (float) $suggested['rate']) > 1e-7) $items[] = ['key' => 'tasa', 'label' => 'Tasa', 'detail' => 'sugerida ' . $percent((float) $suggested['rate']) . ' → ' . $percent((float) $conditions['rate']) . ' mensual', 'note' => true];
    if (abs((float) $conditions['amount'] - (float) $suggested['amount']) >= 1) $items[] = ['key' => 'valor', 'label' => 'Valor', 'detail' => 'sugerido ' . $money((float) $suggested['amount']) . ' → ' . $money((float) $conditions['amount']), 'note' => false];
    if ((int) $conditions['term'] !== (int) $suggested['term']) $items[] = ['key' => 'plazo', 'label' => 'Plazo', 'detail' => 'sugerido ' . (int) $suggested['term'] . ' → ' . (int) $conditions['term'] . ' meses', 'note' => false];
    if ($endTs !== $storedEndTs) $items[] = ['key' => 'fecha_fin', 'label' => 'Fecha fin del contrato', 'detail' => ($storedEndTs > 0 ? date('d/m/Y', $storedEndTs) : 'sin fecha') . ' → ' . ($endTs > 0 ? date('d/m/Y', $endTs) : 'sin fecha'), 'note' => false];
    return $items;
  }

  /**
   * Plazo de una solicitud manual: hasta el plazo máximo del preaprobado (vigencia más larga − margen, sin pasar del
   * tope). Si sus contratos no dan cuotas se permite hasta el tope, para que el analista revise la fecha fin.
   */
  public function requestTermLimit(array $preapproval): int
  {
    return (int) $preapproval['max_term'] > 0 ? (int) $preapproval['max_term'] : (int) ($preapproval['max_term_cap'] ?? OwnerCreditCalculator::termCap($this->config()));
  }

  private function assertRequestTerm(int $term, string $document): void
  {
    try {
      $portfolio = $this->ownerPortfolio($document);
    } catch (\InvalidArgumentException) {
      return;
    }
    if ($portfolio === []) return;
    $limit = $this->requestTermLimit(OwnerCreditCalculator::preapproval($portfolio, $this->config()));
    if ($term > $limit) throw new \DomainException('El plazo solicitado (' . $term . ' meses) supera el máximo de ' . $limit . ' cuotas del propietario. Corrige el plazo.');
  }

  /**
   * Probabilidad de desocupación sugerida con el seguimiento de renovación de los contratos: una no prórroga o
   * terminación respondida dentro del término deja la renovación en 0 % (desocupación del 100 %). Decide el contrato
   * principal (el primero); los demás en riesgo alto se avisan. Renovación < 50 % → Alta, < 80 % → Media, ≥ 80 % o
   * "no salida" reportada → Baja. Sin seguimiento del ciclo vigente se sugiere Baja.
   * @param list<array<string,mixed>> $contracts id, contrato, end_ts
   * @return array{level:string,reason:string,alerts:list<string>}
   */
  public function vacancySignal(array $contracts): array
  {
    $result = ['level' => 'Baja', 'reason' => 'Sin seguimiento de renovación del contrato principal: se sugiere Baja.', 'alerts' => []];
    $table = $this->db->table('scm_contract_renewal');
    $ids = array_values(array_filter(array_map(static fn(array $row): int => (int) $row['id'], $contracts)));
    if ($ids === [] || !$this->schema->tableExists($table)) return $result;
    $states = [];
    foreach ($this->db->getResults("SELECT `contract_id`, `end_ts`, `probability`, `no_exit` FROM `{$table}` WHERE `contract_id` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) $states[(int) $row['contract_id']] = $row;
    foreach (array_values($contracts) as $index => $contract) {
      $state = $states[(int) $contract['id']] ?? null;
      // La probabilidad es del ciclo de la fecha fin vigente: la de un ciclo anterior ya no cuenta.
      if ($state === null || (int) $state['end_ts'] !== (int) ($contract['end_ts'] ?? 0)) continue;
      $probability = $state['probability'] === null || $state['probability'] === '' ? null : (float) $state['probability'];
      if ((int) $state['no_exit'] === 1) {
        [$level, $why] = ['Baja', 'el arrendatario reportó que no sale'];
      } elseif ($probability === null) {
        continue;
      } else {
        $percent = rtrim(rtrim(number_format($probability, 2, ',', ''), '0'), ',') . ' %';
        $case = $probability <= 0 ? $this->withinTermCase((int) $contract['id']) : '';
        $level = $probability < 50 ? 'Alta' : ($probability < 80 ? 'Media' : 'Baja');
        $why = 'renovación ' . $percent . ($case !== '' ? ' (no prórroga o terminación respondida dentro del término, caso #' . $case . ')' : '');
      }
      if ($index === 0) {
        $result['level'] = $level;
        $result['reason'] = $level . ': contrato #' . $contract['contrato'] . ' con ' . $why . '.';
      } elseif ($level === 'Alta') {
        $result['alerts'][] = 'Contrato #' . $contract['contrato'] . ' con ' . $why . ': su parte de la cuota deja de descontarse en su fecha fin.';
      }
    }
    return $result;
  }

  /** Caso de la última respuesta dentro del término que dejó la renovación del contrato en 0 %. */
  private function withinTermCase(int $contractId): string
  {
    $table = $this->db->table('scm_contract_renewal_events');
    if (!$this->schema->tableExists($table)) return '';
    foreach ($this->db->getResults("SELECT `details_json` FROM `{$table}` WHERE `contract_id` = ? AND `action` = 'renewal_saved' ORDER BY `id` DESC LIMIT 10", [$contractId]) as $row) {
      $details = json_decode((string) $row['details_json'], true);
      if (is_array($details) && ($details['term'] ?? '') === 'dentro') return (string) ($details['case_number'] ?? '');
    }
    return '';
  }

  /**
   * Revisión previa que ve quien registra la solicitud: las reglas del liquidador que no dependen del valor pedido.
   * @param list<array<string,mixed>> $rows ownerPortfolio() @param array<string,mixed> $preapproval
   * @return list<array{label:string,ok:bool,detail:string}>
   */
  public function requestChecks(array $rows, array $preapproval): array
  {
    if ($rows === []) return [];
    $minMonths = (int) $this->config()['min_remaining_months'];
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $active = array_filter($rows, static fn(array $row): bool => strtolower(trim((string) $row['estado'])) === 'entregado');
    $siniestrados = array_map(static fn(array $row): string => '#' . $row['contrato'], array_filter($rows, static fn(array $row): bool => !empty($row['siniestrado'])));
    $longest = $rows[0];
    $outstanding = (float) ($preapproval['outstanding'] ?? 0);
    $vacancy = $this->vacancySignal($rows);
    return [
      ['label' => 'Contrato activo', 'ok' => $active !== [], 'detail' => count($active) . ' de ' . count($rows) . ' contrato' . (count($rows) === 1 ? '' : 's') . ' Entregado.'],
      ['label' => 'Contrato no próximo a vencer', 'ok' => (int) $longest['max_months'] >= $minMonths && (int) $longest['max_months'] > 0, 'detail' => 'El más largo (#' . $longest['contrato'] . ') tiene ' . (int) $longest['max_months'] . ' meses de vigencia. Mínimo: ' . $minMonths . '.'],
      ['label' => 'Sin siniestro', 'ok' => $siniestrados === [], 'detail' => $siniestrados === [] ? 'Ningún contrato marcado como siniestrado por Cartera.' : 'Cartera marcó como siniestrado: ' . implode(', ', $siniestrados) . '.'],
      ['label' => 'Sin créditos vigentes', 'ok' => $outstanding < 0.5, 'detail' => $outstanding < 0.5 ? 'No tiene créditos aprobados en el sistema.' : 'Debe ' . $money($outstanding) . ' de créditos vigentes; queda disponible ' . $money((float) ($preapproval['available'] ?? 0)) . '.'],
      ['label' => 'Plazo disponible', 'ok' => (int) ($preapproval['max_term'] ?? 0) > 0, 'detail' => 'Hasta ' . (int) ($preapproval['max_term'] ?? 0) . ' cuotas.'],
      ['label' => 'Probabilidad de desocupación', 'ok' => $vacancy['level'] !== 'Alta' && $vacancy['alerts'] === [], 'detail' => trim($vacancy['reason'] . ' ' . implode(' ', $vacancy['alerts']))],
    ];
  }

  /** Propietarios del contrato principal para elegir a quién es el crédito, marcando el actual. @return list<array<string,mixed>> */
  private function ownerChoices(array $credit): array
  {
    $document = self::document((string) ($credit['owner_document'] ?? ''));
    $owners = $this->contractOwners((int) $credit['contract_id']);
    $current = '';
    foreach ($owners as $owner) if ($document !== '' && self::document($owner['document']) === $document) $current = $owner['key'];
    if ($current === '') foreach ($owners as $owner) if ($owner['owner_id'] === (string) $credit['owner_id'] && $owner['name'] === (string) $credit['owner_name']) $current = $owner['key'];
    return array_map(static fn(array $owner): array => array_intersect_key($owner, array_flip(['key', 'name', 'document'])) + ['current' => $owner['key'] === $current], $owners);
  }

  /** Solicitud pendiente guardada sin documento (contrato sin mandato): se completa desde la ficha del propietario. */
  private function fillOwnerDocument(array $credit): array
  {
    $document = $this->ownerDocument(['owner_id' => (string) $credit['owner_id'], 'document' => ''], $this->contractById((int) $credit['contract_id']) ?? []);
    if ($document === '') return $credit;
    $this->db->update($this->table(), $this->schema->filterTableData($this->table(), ['owner_document' => mb_substr($document, 0, 40)]), ['id' => (int) $credit['id']]);
    return ['owner_document' => $document] + $credit;
  }

  /**
   * Documento del beneficiario: el del mandato o, si falta, el de su ficha de propietario o (si es el propietario
   * principal) el del contrato. Sin documento el crédito no se restaría del cupo de la persona.
   * @param array<string,string>|null $owner
   */
  private function ownerDocument(?array $owner, array $contract): string
  {
    $document = trim((string) ($owner['document'] ?? ''));
    if ($document !== '') return $document;
    $ownerId = trim((string) ($owner['owner_id'] ?? ($contract['id_propietario'] ?? '')));
    $table = $this->db->table('jet_cct_propietarios');
    if ($ownerId !== '' && !str_starts_with($ownerId, 'doc:') && $this->schema->tableExists($table)) {
      $columns = array_values(array_filter(['documento', 'documento_juridico'], fn(string $column): bool => $this->schema->columnExists($table, $column)));
      $ids = array_values(array_filter(['_ID', 'id_propietario'], fn(string $column): bool => $this->schema->columnExists($table, $column)));
      if ($columns !== [] && $ids !== []) {
        $row = $this->db->getRow('SELECT `' . implode('`, `', $columns) . "` FROM `{$table}` WHERE " . implode(' OR ', array_map(static fn(string $column): string => "`{$column}` = ?", $ids)) . ' LIMIT 1', array_fill(0, count($ids), $ownerId));
        foreach ($columns as $column) if (is_array($row) && trim((string) ($row[$column] ?? '')) !== '') return trim((string) $row[$column]);
      }
    }
    return $ownerId === '' || $ownerId === (string) ($contract['id_propietario'] ?? '') ? trim((string) ($contract['documento_propietario'] ?? '')) : '';
  }

  /**
   * Contratos de los que se puede descontar la cuota: los guardados al aprobar o, mientras se evalúa, el contrato
   * principal y los demás contratos del propietario. El estado se lee del contrato: si ya no está Entregado o Por
   * entregar, deja de cubrir cuotas y su parte pasa a los demás.
   * @return list<array{id:int,contrato:string,inmueble:string,net_income:float,end_ts:int,estado:string}>
   */
  private function discountSources(array $credit, array $contract, array $evaluation, int $endTs): array
  {
    $stored = (int) $credit['id'] > 0 && in_array($credit['status'], ['aprobado', 'pagado'], true) ? $this->storedSources((int) $credit['id']) : [];
    if ($stored !== []) {
      return array_map(function (array $row): array {
        $current = $this->contractById((int) $row['contract_id']) ?? [];
        return ['id' => (int) $row['contract_id'], 'contrato' => (string) $row['contract_code'], 'inmueble' => (string) $row['property'], 'net_income' => (float) $row['net_income'], 'end_ts' => (int) $row['end_ts'], 'estado' => (string) ($current['estado'] ?? '')];
      }, $stored);
    }
    $sources = [[
      'id' => (int) $credit['contract_id'], 'contrato' => (string) ($contract['contrato'] ?? $credit['contract_code']), 'inmueble' => (string) $credit['property'],
      'net_income' => (float) $evaluation['net_income'], 'end_ts' => $endTs, 'estado' => (string) ($contract['estado'] ?? ''),
    ]];
    foreach ($evaluation['other_contracts'] ?? [] as $row) {
      $sources[] = [
        'id' => (int) $row['id'], 'contrato' => (string) $row['contrato'], 'inmueble' => (string) $row['inmueble'], 'net_income' => (float) $row['net_income'],
        'end_ts' => (int) ($row['end_ts'] ?? 0) ?: self::timestamp(str_replace('/', '-', (string) ($row['fin'] ?? ''))), 'estado' => (string) ($row['estado'] ?? ''),
      ];
    }
    return $sources;
  }

  /** Cuotas del plazo que vencen antes del fin del contrato (o antes de hoy, si el contrato ya no está vigente). */
  private function coveredInstallments(array $source, int $firstPaymentTs, int $term): int
  {
    $end = (int) $source['end_ts'];
    if (!in_array(strtolower(trim((string) $source['estado'])), ['entregado', 'por entregar'], true)) $end = $end > 0 ? min($end, time()) : time();
    $covered = 0;
    for ($number = 1; $number <= $term; $number++) {
      if (self::installmentTs($firstPaymentTs, $number) > $end) break;
      $covered++;
    }
    return $covered;
  }

  /** @return list<array<string,mixed>> */
  private function storedSources(int $creditId): array
  {
    return array_values($this->db->getResults("SELECT * FROM `{$this->sourcesTable()}` WHERE `credit_id` = ? ORDER BY `is_primary` DESC, `id` ASC", [$creditId]));
  }

  /** @param list<array<string,mixed>> $sources */
  private function saveSources(int $creditId, int $primaryId, array $sources): void
  {
    $this->db->delete($this->sourcesTable(), ['credit_id' => $creditId]);
    foreach ($sources as $source) {
      $this->db->insert($this->sourcesTable(), [
        'credit_id' => $creditId, 'contract_id' => (int) $source['id'], 'contract_code' => mb_substr((string) $source['contrato'], 0, 60), 'property' => mb_substr((string) $source['inmueble'], 0, 60),
        'net_income' => round((float) $source['net_income'], 2), 'end_ts' => (int) $source['end_ts'] ?: null, 'is_primary' => (int) $source['id'] === $primaryId ? 1 : 0, 'created_at' => time(),
      ]);
    }
  }

  /** Saldo de capital estimado según las cuotas vencidas desde la primera fecha de pago. */
  public function balance(array $credit): float
  {
    $schedule = OwnerCreditCalculator::schedule((float) $credit['approved_rate'], (int) $credit['approved_term'], (float) $credit['approved_amount']);
    $first = (int) ($credit['first_payment_ts'] ?? 0);
    if ($first <= 0 || time() < $first) return (float) $credit['approved_amount'];
    $paid = 0;
    for ($ts = $first; $ts <= time() && $paid < count($schedule); $ts = (int) strtotime('+1 month', $ts)) $paid++;
    return $paid >= count($schedule) ? 0.0 : (float) $schedule[$paid - 1]['saldo'];
  }

  /**
   * Guarda en el contrato la fecha fin corregida en el liquidador; se llama al aprobar o rechazar (y al crear
   * un crédito manual). Queda en el historial del crédito, del caso, del contrato y del inmueble.
   */
  private function saveContractEnd(array $credit, int $ts, array $actor): void
  {
    $contract = $this->contractById((int) $credit['contract_id']);
    if ($contract === null) throw new \DomainException('El crédito no tiene contrato vinculado.');
    $previous = self::timestamp($contract['fin_contrato'] ?? '');
    $start = self::timestamp($contract['inicio_contrato'] ?? '');
    if ($start > 0 && $ts <= $start) throw new \DomainException('La fecha fin debe ser posterior al inicio del contrato (' . date('d/m/Y', $start) . ').');
    if ($previous === $ts) return;
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $this->db->update($table, $this->schema->filterTableData($table, ['fin_contrato' => (string) $ts, 'cct_modified' => date('Y-m-d H:i:s')]), ['_ID' => (int) $contract['_ID']]);
    $change = ($previous > 0 ? date('d/m/Y', $previous) : 'sin fecha') . ' → ' . date('d/m/Y', $ts);
    $this->event((int) $credit['id'], 'fecha_fin', $actor, ['nota' => 'Fecha fin del contrato: ' . $change]);
    $this->recordHistories($credit, 'Actualización de fecha fin', 'Fecha fin del contrato #' . ($contract['contrato'] ?? $credit['contract_code']) . ' actualizada desde el liquidador de créditos (crédito #' . $credit['id'] . '): ' . $change . '.', $actor);
  }

  /** @param array<string,mixed> $evaluation */
  private static function termMessage(int $term, array $evaluation): string
  {
    return 'El plazo solicitado (' . $term . ' meses) no puede superar el máximo de ' . $evaluation['max_months'] . ' meses (' . self::termBasis($evaluation) . '). Corrige el plazo o la fecha fin.';
  }

  /** "12 meses de vigencia del contrato más largo − 2 de margen". @param array<string,mixed> $evaluation */
  public static function termBasis(array $evaluation): string
  {
    $basis = (int) $evaluation['longest_months'] . ' meses de vigencia ' . (($evaluation['other_contracts'] ?? []) !== [] ? 'del contrato más largo' : 'del contrato') . ((int) $evaluation['margin_months'] > 0 ? ' − ' . (int) $evaluation['margin_months'] . ' de margen' : '');
    // Con vigencia de sobra manda el tope de plazo de política.
    $cap = (int) ($evaluation['max_term_cap'] ?? 0);
    return $cap > 0 && (int) $evaluation['longest_months'] - (int) $evaluation['margin_months'] > $cap ? 'tope de política de ' . $cap . ' meses; ' . $basis . ' daría ' . ((int) $evaluation['longest_months'] - (int) $evaluation['margin_months']) : $basis;
  }

  /** Fecha del formulario (AAAA-MM-DD) a la medianoche, como se guarda fin_contrato. */
  private static function dateInput($value): int
  {
    $text = trim((string) $value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? (int) (strtotime($text . ' 00:00:00') ?: 0) : 0;
  }

  /** @return array<string,mixed>|null */
  public function byQuote(int $quoteId): ?array
  {
    if ($quoteId <= 0) return null;
    $row = $this->db->getRow("SELECT * FROM `{$this->table()}` WHERE `quote_id` = ? LIMIT 1", [$quoteId]);
    return is_array($row) ? $row : null;
  }

  /** Estado del crédito por cotización, para las tarjetas. @param list<int> $quoteIds @return array<int,string> */
  public function statusesForQuotes(array $quoteIds): array
  {
    $ids = array_values(array_filter(array_map('intval', $quoteIds)));
    if ($ids === []) return [];
    try {
      $this->ensureSchema();
      $rows = $this->db->getResults("SELECT `quote_id`, `status` FROM `{$this->table()}` WHERE `quote_id` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    } catch (\Throwable $error) {
      error_log('[owner-credit-status] ' . $error->getMessage());
      return [];
    }
    $map = [];
    foreach ($rows as $row) $map[(int) $row['quote_id']] = (string) $row['status'];
    return $map;
  }

  private function createFromQuote(int $quoteId, array $actor): int
  {
    $quote = $this->db->getRow("SELECT * FROM `{$this->db->table('jet_cct_cotizacion_mantenimiento')}` WHERE `_ID` = ? LIMIT 1", [$quoteId]);
    if (!is_array($quote)) return 0;
    $contract = $this->contractById((int) ($quote['id_contrato'] ?? 0)) ?? $this->contractByRef((string) ($quote['contrato'] ?? ''));
    if ($contract === null) return 0;
    return $this->insertCredit($contract, $quoteId, (string) ($quote['id_ticket'] ?? ''), self::money($quote['total'] ?? ''), 'Para Mejoras y/o Reparaciones al Inmueble', $actor);
  }

  /** @param array<string,string>|null $owner beneficiario elegido (contractOwners); sin él, el propietario del contrato */
  private function insertCredit(array $contract, ?int $quoteId, string $ticketId, float $amount, string $purpose, array $actor, int $term = 0, ?array $owner = null, string $beneficiaryType = 'propietario'): int
  {
    $this->db->insert($this->table(), $this->schema->filterTableData($this->table(), $this->creditRow($contract, $quoteId, $ticketId, $amount, $purpose, $actor, $term, $owner, $beneficiaryType)));
    $id = (int) $this->db->lastInsertId();
    $this->event($id, 'solicitado', $actor, ['cotizacion' => $quoteId, 'valor' => $amount]);
    $this->recordRequestHistory($id, $actor, false);
    return $id;
  }

  /** Fila de una solicitud nueva (también la de una simulación, que no se guarda). @return array<string,mixed> */
  private function creditRow(array $contract, ?int $quoteId, string $ticketId, float $amount, string $purpose, array $actor, int $term, ?array $owner, string $beneficiaryType): array
  {
    $owner ??= $this->contractOwners((int) ($contract['_ID'] ?? 0))[0] ?? null;
    // Sin plazo por defecto ($term = 0): lo escribe el funcionario según lo que pidió el propietario.
    $income = $this->defaultIncome($contract);
    $now = time();
    $phone = $owner !== null ? $owner['phone'] : self::phone((string) ($contract['celular_propietario'] ?? ''), (string) ($contract['indicativo_propietario'] ?? ''));
    return [
      'quote_id' => $quoteId, 'ticket_id' => $ticketId,
      'contract_id' => (int) ($contract['_ID'] ?? 0), 'contract_code' => (string) ($contract['contrato'] ?? ''),
      'property' => (string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''), 'address' => mb_substr((string) ($contract['direccion'] ?? ''), 0, 255),
      'owner_id' => $owner !== null ? $owner['owner_id'] : (string) ($contract['id_propietario'] ?? ''),
      'owner_name' => mb_substr($owner !== null ? $owner['name'] : (string) ($contract['propietario'] ?? ''), 0, 190),
      'owner_email' => mb_substr($owner !== null ? $owner['email'] : trim((string) ($contract['correo_propietario'] ?? '')), 0, 190),
      'owner_phone' => mb_substr($phone, 0, 40), 'owner_document' => mb_substr($this->ownerDocument($owner, $contract), 0, 40),
      'beneficiary_type' => $beneficiaryType,
      'purpose' => $purpose, 'status' => 'pendiente', 'requested_amount' => round($amount, 2), 'requested_term' => $term,
      'vacancy' => 'Baja', 'gross_income' => $income['gross'], 'deductions_json' => json_encode($income['deductions'], JSON_THROW_ON_ERROR),
      'created_by_id' => (string) ($actor['employee_id'] ?? ''), 'created_by_name' => (string) ($actor['name'] ?? ''),
      'created_at' => $now, 'updated_at' => $now,
    ];
  }

  /** La solicitud (o su reapertura) también queda en los historiales del caso, del contrato y del inmueble. */
  private function recordRequestHistory(int $id, array $actor, bool $reopened): void
  {
    $credit = $this->find($id);
    if ($credit === null) return;
    $message = 'Crédito #' . $id . ($reopened ? ' reabierto' : ' solicitado') . ' por el propietario ' . $credit['owner_name'] . ': $'
      . number_format((float) $credit['requested_amount'], 0, ',', '.')
      . ((int) $credit['quote_id'] > 0 ? ' para la cotización #' . (int) $credit['quote_id'] : '')
      . '. Pendiente de evaluar en el liquidador.';
    $this->recordHistories($credit, 'Solicitud de crédito', $message, $actor);
  }

  /**
   * Ingreso del propietario: canon del contrato y descuentos del contrato de mandato (comisión con IVA,
   * garantía y póliza de servicios públicos). Sin mandato se descuenta el porcentaje configurado del canon;
   * el funcionario puede corregirlos en el liquidador.
   * @return array{gross:float,deductions:array<string,float>,source:string}
   */
  public function defaultIncome(array $contract): array
  {
    $canon = self::money($contract['valor_canon'] ?? '');
    $letterCanon = $this->increasedCanon($contract);
    $canonNote = '';
    if ($letterCanon > $canon) {
      $canonNote = ' Canon con el aumento de la carta #' . (int) $contract['id_carta_aumento_canon'] . ' ($' . number_format($letterCanon, 0, ',', '.') . '; el contrato dice $' . number_format($canon, 0, ',', '.') . ').';
      $canon = $letterCanon;
    }
    $deductions = array_fill_keys(array_keys(self::DEDUCTIONS), 0.0);
    $mandateId = (int) ($contract['id_contrato_mandato'] ?? 0);
    $mandateTable = $this->db->table('jet_cct_contrato_mandato');
    $mandate = $mandateId > 0 && $this->schema->tableExists($mandateTable)
      ? $this->db->getRow("SELECT * FROM `{$mandateTable}` WHERE `_ID` = ? LIMIT 1", [$mandateId])
      : null;
    if (is_array($mandate) && self::money($mandate['total_comision'] ?? '') > 0) {
      $deductions['comision'] = self::money($mandate['total_comision'] ?? '');
      $deductions['garantia'] = self::isYes($mandate['incluye_garantia'] ?? '') ? self::money($mandate['precio_mes_garantia'] ?? '') : 0.0;
      $deductions['poliza_servicios'] = self::isYes($mandate['incluye_garantia_servicios'] ?? '') ? self::money($mandate['precio_mes_servicios'] ?? '') : 0.0;
      return ['gross' => $canon, 'deductions' => $deductions, 'source' => 'Contrato de mandato #' . $mandateId . '.' . $canonNote];
    }
    // Sin mandato (o sin comisión registrada) se descuenta un porcentaje fijo del canon, configurable (15 % por defecto).
    $percent = (float) $this->config()['no_mandate_deduction'];
    $deductions['otros'] = round($canon * $percent);
    return ['gross' => $canon, 'deductions' => $deductions, 'source' => 'Estimado: el contrato no tiene mandato con comisión registrada; se descuenta el ' . rtrim(rtrim(number_format($percent * 100, 2, ',', ''), '0'), ',') . ' % del canon. Corrige los descuentos si conoces los valores reales.' . $canonNote];
  }

  /** Canon de la última carta de aumento de canon del contrato (0 si no hay). */
  private function increasedCanon(array $contract): float
  {
    $letterId = (int) ($contract['id_carta_aumento_canon'] ?? 0);
    $table = $this->db->table('jet_cct_cartas_aumento');
    if ($letterId <= 0 || !$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'canon')) return 0.0;
    return self::money($this->db->getVar("SELECT `canon` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$letterId]) ?? '');
  }

  /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
  /** @param list<array{from:int,to:int,parts:list<array<string,mixed>>,direct:float}> $plan */
  /**
   * @param list<array<string,mixed>> $all contrato principal y demás contratos de la persona
   * @param array<string,mixed> $preapproval cupo disponible = tope − saldo de los créditos vigentes de la persona
   */
  private function policies(array $all, array $evaluation, array $settings, float $amount, int $term, float $rate, float $payment, array $plan, array $preapproval): array
  {
    $minMonths = (int) $settings['min_remaining_months'];
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $available = (float) $preapproval['available'];
    $others = $preapproval['credits'];
    $analyst = $evaluation['analyst'];
    $credits = implode(', ', array_map(static fn(int $id): string => '#' . $id, $others));
    $active = array_filter($all, static fn(array $row): bool => strtolower(trim((string) $row['estado'])) === 'entregado');
    $longest = $all[0];
    foreach ($all as $row) if ((int) $row['max_months'] > (int) $longest['max_months']) $longest = $row;
    $siniestrados = implode(', ', $analyst['siniestrados']);
    $siniestroDetail = $analyst['siniestro']
      ? ($siniestrados !== '' ? 'Cartera marcó como siniestrado: ' . $siniestrados . '.' : 'El analista indicó que hay un siniestro.')
      : ($siniestrados !== '' ? 'Cartera marcó como siniestrado ' . $siniestrados . ', pero el analista indicó que no (verificado con Cartera).' : 'Sin siniestro registrado en ' . (count($all) > 1 ? 'ninguno de sus ' . count($all) . ' contratos' : 'cartera') . '; por verificar con Cartera.');
    $creditsDetail = match (true) {
      !$analyst['has_credits'] && $others !== [] => 'El analista indicó que no tiene créditos vigentes (el sistema registra ' . $credits . ' por ' . $money((float) $preapproval['system_outstanding']) . '); cupo disponible ' . $money($available) . '.',
      !$analyst['has_credits'] => 'Sin créditos vigentes: cupo disponible ' . $money($available) . '.',
      $others === [] => 'El analista indicó créditos vigentes por fuera del sistema; el cupo disponible (' . $money($available) . ') no los descuenta: para aprobar escribe la justificación.',
      default => 'Saldo de créditos vigentes ' . $money((float) $preapproval['outstanding']) . ' (' . $credits . '); cupo disponible ' . $money($available) . '.',
    };
    $direct = array_values(array_filter($plan, static fn(array $segment): bool => $segment['direct'] >= 0.5));
    $contractsUsed = [];
    foreach ($plan as $segment) foreach ($segment['parts'] as $part) $contractsUsed['#' . $part['contrato']] = true;
    $percent = static fn(float $value): string => number_format($value * 100, 2, ',', '.') . ' %';
    return [
      ['key' => 'contrato_activo', 'label' => 'Contrato activo', 'ok' => $active !== [], 'detail' => count($all) === 1 ? 'Estado del contrato: ' . ((string) $all[0]['estado'] ?: 'sin contrato') . '.' : count($active) . ' de ' . count($all) . ' contratos Entregado (' . implode(', ', array_map(static fn(array $row): string => '#' . $row['contrato'] . ' ' . ($row['estado'] ?: 'sin estado'), $all)) . ').'],
      ['key' => 'vigencia', 'label' => 'Contrato no próximo a vencer', 'ok' => (int) $evaluation['longest_months'] >= $minMonths && (int) $evaluation['longest_months'] > 0, 'detail' => (int) $evaluation['longest_months'] > 0 ? $evaluation['longest_months'] . ' meses hasta el fin del contrato ' . (count($all) > 1 ? 'más largo, #' . $longest['contrato'] . ' ' : '') . '(' . ($longest['fin'] ?: 'sin fecha') . '). Mínimo: ' . $minMonths . '.' : 'Ningún contrato tiene meses de vigencia.'],
      ['key' => 'plazo', 'label' => 'Plazo dentro de la vigencia', 'ok' => $term > 0 && $term <= $evaluation['max_months'], 'detail' => 'Plazo ' . $term . ' meses; máximo ' . $evaluation['max_months'] . ' (' . self::termBasis($evaluation) . ').'],
      ['key' => 'siniestro', 'label' => 'Contrato no siniestrado', 'ok' => !$analyst['siniestro'], 'detail' => $siniestroDetail],
      ['key' => 'prestamos', 'label' => 'Valor dentro del cupo disponible', 'ok' => $amount <= $available + 0.5 && !($analyst['has_credits'] && $others === []), 'detail' => $creditsDetail],
      ['key' => 'monto', 'label' => 'Valor dentro del tope', 'ok' => $amount > 0 && $amount <= $evaluation['max_amount'] + 0.5, 'detail' => $money($amount) . ' de un tope de ' . $money($evaluation['max_amount']) . ' (' . (int) $settings['income_months'] . ' × ingreso neto' . (($evaluation['other_contracts'] ?? []) !== [] ? ' de ' . (count($evaluation['other_contracts']) + 1) . ' contratos, ' . $money($evaluation['total_net_income']) : '') . ').'],
      ['key' => 'tasa_minima', 'label' => 'Tasa dentro del descuento máximo', 'ok' => $rate >= (float) $evaluation['policy_rate'] - 1e-9, 'detail' => 'Tasa ' . $percent($rate) . ' mensual; referencia ' . $percent((float) $settings['reference_rate']) . ' con descuento máximo de ' . $percent((float) $settings['policy_discount']) . ' = mínimo ' . $percent((float) $evaluation['policy_rate']) . '.'],
      ['key' => 'usura', 'label' => 'Tasa por debajo de la usura', 'ok' => $rate <= $evaluation['usury_monthly_legal'] + 1e-9, 'detail' => 'Tasa ' . $percent($rate) . ' mes vencido (' . $percent((1 + $rate) ** 12 - 1) . ' E.A.); usura ' . $percent((float) $settings['usury_annual']) . ' E.A. = ' . $percent($evaluation['usury_monthly_legal']) . ' mensual' . ((string) $settings['usury_updated_at'] !== '' ? ', vigente desde ' . date('d/m/Y', (int) strtotime((string) $settings['usury_updated_at'])) : '') . '.'],
      ['key' => 'cuota', 'label' => 'Cuota cubierta por los contratos', 'ok' => $payment > 0 && $direct === [], 'detail' => $payment <= 0 ? 'Sin cuota.' : ($direct === []
        ? 'Cuota ' . $money($payment) . ' descontada ' . (count($contractsUsed) === 1 ? 'del contrato ' : 'de los contratos ') . implode(', ', array_keys($contractsUsed)) . '.'
        : 'Los contratos no alcanzan a cubrir ' . implode('; ', array_map(static fn(array $segment): string => $money($segment['direct']) . ' en las cuotas ' . $segment['from'] . '–' . $segment['to'], $direct)) . ': esa parte la pagaría el propietario directo.')],
    ];
  }

  /** Otros créditos de la misma persona (documento o id de propietario; sin ninguno, del mismo contrato). @return list<array<string,mixed>> */
  private function ownerCredits(array $credit): array
  {
    $owner = trim((string) $credit['owner_id']);
    $document = self::document((string) ($credit['owner_document'] ?? ''));
    $where = [];
    $args = [];
    if ($document !== '') { $where[] = "UPPER(REPLACE(REPLACE(`owner_document`, '.', ''), ' ', '')) = ?"; $args[] = $document; }
    if ($owner !== '') { $where[] = '`owner_id` = ?'; $args[] = $owner; }
    if ($where === []) { $where[] = '`contract_id` = ?'; $args[] = (int) $credit['contract_id']; }
    $args[] = (int) $credit['id'];
    return array_values($this->db->getResults("SELECT `id`, `status`, `contract_code`, `approved_amount`, `approved_term`, `approved_rate`, `first_payment_ts`, `requested_amount`, `created_at` FROM `{$this->table()}` WHERE (" . implode(' OR ', $where) . ') AND `id` <> ? ORDER BY `id` DESC', $args));
  }

  /** @return list<array<string,mixed>> */
  private function events(int $id): array
  {
    return array_values(array_map(static function (array $row): array {
      $row['details'] = json_decode((string) $row['details_json'], true) ?: [];
      unset($row['details_json']);
      return $row;
    }, $this->db->getResults("SELECT * FROM `{$this->eventsTable()}` WHERE `credit_id` = ? ORDER BY `id` DESC", [$id])));
  }

  private function event(int $id, string $action, array $actor, array $details): void
  {
    $this->db->insert($this->eventsTable(), [
      'credit_id' => $id, 'action' => $action, 'actor_id' => (string) ($actor['employee_id'] ?? ''),
      'actor_name' => (string) ($actor['name'] ?? ''), 'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => time(),
    ]);
  }

  private function setStatus(int $id, string $status, array $extra = []): void
  {
    $this->db->update($this->table(), ['status' => $status, 'updated_at' => time()] + $extra, ['id' => $id]);
  }

  private function decisionSummary(array $credit): string
  {
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.');
    if ($credit['status'] !== 'aprobado') {
      return 'Crédito #' . $credit['id'] . ' rechazado (solicitado ' . $money($credit['requested_amount']) . '). Motivo: ' . $credit['decision_note'];
    }
    return 'Crédito #' . $credit['id'] . ' aprobado: ' . $money($credit['approved_amount']) . ' a ' . $credit['approved_term'] . ' meses, tasa '
      . number_format((float) $credit['approved_rate'] * 100, 2, ',', '.') . ' % N.M.V, cuota ' . $money($credit['monthly_payment']) . '.'
      . ((int) $credit['policy_override'] === 1 ? ' Aprobado fuera de política: ' . $credit['decision_note'] : ((string) $credit['decision_note'] !== '' ? ' ' . $credit['decision_note'] : ''));
  }

  /** Historial del caso (si viene de una cotización), del contrato y del inmueble. */
  private function recordHistories(array $credit, string $type, string $message, array $actor): void
  {
    $employee = (string) ($actor['employee_id'] ?? '');
    $now = date('Y-m-d H:i:s');
    try {
      $ticketTable = $this->db->table('jet_cct_historial_del_ticket');
      if ((string) $credit['ticket_id'] !== '' && $this->schema->tableExists($ticketTable)) {
        $row = $this->schema->filterTableData($ticketTable, [
          'cct_status' => 'publish', 'cct_author_id' => $employee, 'id_empleado' => $employee, 'cct_created' => $now, 'cct_modified' => $now,
          'fecha' => time(), 'id_ticket' => (string) $credit['ticket_id'], 'id_cotizacion_mantenimiento' => (string) ($credit['quote_id'] ?? ''),
          'nombre' => (string) ($actor['name'] ?? ''), 'respuesta' => $message, 'respuesta_cct_ticket' => $message, 'fue_editada' => 'Si',
        ]);
        if ($row !== []) $this->db->insert($ticketTable, $row);
      }
      $contractTable = $this->db->table('jet_cct_historial_contrato_arrendamiento');
      $contract = $this->contractById((int) $credit['contract_id']);
      if ($contract !== null && $this->schema->tableExists($contractTable)) {
        $row = $this->schema->filterTableData($contractTable, [
          'cct_status' => 'publish', 'cct_author_id' => $employee, 'cct_created' => $now, 'cct_modified' => $now,
          'id_empleado' => $employee, 'funcionario' => (string) ($actor['name'] ?? ''),
          'id_contrato' => (string) $contract['_ID'], 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
          'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? ''),
          'fecha' => time(), 'tipo_reporte' => $type, 'observacion' => $message,
        ]);
        if ($row !== []) $this->db->insert($contractTable, $row);
      }
      // La misma gestión queda en el historial del inmueble, indicando el contrato.
      $propertyTable = $this->db->table('jet_cct_historial_del_inmueble');
      if ($contract !== null && $this->schema->tableExists($propertyTable)) {
        $note = 'Contrato #' . trim((string) ($contract['contrato'] ?? $credit['contract_code'])) . ': ' . $message;
        $row = $this->schema->filterTableData($propertyTable, [
          'cct_status' => 'publish', 'cct_author_id' => $employee, 'cct_created' => $now, 'cct_modified' => $now,
          'id_empleado' => $employee, 'funcionario' => (string) ($actor['name'] ?? ''), 'nombre_empleado' => (string) ($actor['name'] ?? ''),
          'id_contrato' => (string) $contract['_ID'], 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
          'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? ''),
          'fecha' => time(), 'tipo_reporte' => $type, 'tipo_de_reporte_his' => $type, 'observacion' => $note, 'observacion_his' => $note,
        ]);
        if ($row !== []) $this->db->insert($propertyTable, $row);
      }
    } catch (\Throwable $error) {
      error_log('[owner-credit-history] ' . $error->getMessage());
    }
  }

  private function notifyRequested(int $id): void
  {
    $credit = $this->find($id);
    if ($credit === null) return;
    $money = '$' . number_format((float) $credit['requested_amount'], 0, ',', '.');
    $this->notifyInternal(self::EVENT_REQUESTED, 'Solicitud de crédito de propietario #' . $id,
      'El propietario ' . $credit['owner_name'] . ' solicitó financiar ' . $money . ' (contrato ' . $credit['contract_code'] . ', inmueble ' . $credit['property'] . ')'
      . ((int) $credit['quote_id'] > 0 ? ' para la cotización #' . $credit['quote_id'] . '.' : '.') . ' Revísalo en el liquidador de créditos.',
      'solicitado:' . $id);
    $this->notifyStaffWhatsapp(self::EVENT_REQUESTED, $credit, 'Nueva solicitud de crédito',
      'Solicita ' . $money . ((int) $credit['quote_id'] > 0 ? ' para la cotización #' . $credit['quote_id'] : '') . '. Revísala en el liquidador');
  }

  /** @return array{email:int,whatsapp:int,internal:int,internal_whatsapp:int} */
  private function notifyDecision(array $credit): array
  {
    $settings = $this->config();
    $approved = $credit['status'] === 'aprobado';
    $title = $approved ? 'Crédito aprobado' : 'Solicitud de crédito no aprobada';
    $summary = $this->decisionSummary($credit);
    $queued = ['email' => 0, 'whatsapp' => 0, 'internal' => 0, 'internal_whatsapp' => 0];
    $queued['internal'] = $this->notifyInternal(self::EVENT_DECIDED, $title . ' #' . $credit['id'] . ' · contrato ' . $credit['contract_code'], $summary . ' Decidió: ' . $credit['decided_by_name'] . '.', 'decidido:' . $credit['id']);
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.');
    $queued['internal_whatsapp'] = $this->notifyStaffWhatsapp(self::EVENT_DECIDED, $credit, $approved ? 'Crédito aprobado' : 'Crédito no aprobado',
      ($approved
        ? $money($credit['approved_amount']) . ' a ' . $credit['approved_term'] . ' meses, cuota ' . $money($credit['monthly_payment']) . ((int) $credit['policy_override'] === 1 ? ', fuera de política' : '')
        : 'Motivo: ' . trim((string) $credit['decision_note']))
      . '. Decidió ' . ($credit['decided_by_name'] ?: 'el panel'));
    try {
      if ($settings['notify_owner_email'] && filter_var($credit['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $queued['email'] = (new EmailQueue($this->db))->enqueue([$credit['owner_email']], $title . ' · Contrato ' . $credit['contract_code'], EmailTemplate::render($title, $this->ownerEmailBody($credit)), [
          'source_module' => 'creditos_propietarios', 'destination_name' => $credit['owner_name'],
          'dedupe_key' => 'owner-credit-decision:' . $credit['id'], 'meta' => ['credit_id' => (int) $credit['id'], 'status' => $credit['status']],
        ]);
      }
      if ($settings['notify_owner_whatsapp'] && trim((string) $credit['owner_phone']) !== '') {
        $detail = $approved
          ? '$' . number_format((float) $credit['approved_amount'], 0, ',', '.') . ' a ' . $credit['approved_term'] . ' meses, cuota de $' . number_format((float) $credit['monthly_payment'], 0, ',', '.')
          : 'Comunícate con nosotros para revisar alternativas';
        $params = [$credit['owner_name'] ?: 'Propietario', $approved ? 'aprobada' : 'no aprobada', (string) $credit['contract_code'], $detail];
        $ok = (new SmsQueue($this->db))->enqueue((string) $credit['owner_phone'], (string) $credit['owner_name'], $title . ': ' . $detail, [
          'source_module' => 'creditos_propietarios', 'dedupe_key' => 'owner-credit-decision-wa:' . $credit['id'],
          'template_name' => 'scm_credito_propietario_decision_v1', 'template_language' => 'es_CO',
          'template_components' => [['type' => 'body', 'parameters' => array_map(static fn(string $text): array => ['type' => 'text', 'text' => mb_substr(trim($text), 0, 300)], $params)]],
          'credit_id' => (int) $credit['id'],
        ]);
        $queued['whatsapp'] = $ok ? 1 : 0;
      }
    } catch (\Throwable $error) {
      error_log('[owner-credit-notify] ' . $error->getMessage());
    }
    return $queued;
  }

  private function ownerEmailBody(array $credit): string
  {
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $money = static fn($value): string => '$' . number_format((float) $value, 0, ',', '.');
    $html = '<p style="margin:0 0 14px;font-weight:600;">Apreciado(a) ' . $e($credit['owner_name'] ?: 'propietario') . ':</p>';
    if ($credit['status'] !== 'aprobado') {
      return $html . '<p style="margin:0 0 14px;line-height:1.65;">Revisamos su solicitud de crédito por ' . $e($money($credit['requested_amount'])) . ' asociada al contrato <b>#' . $e($credit['contract_code']) . '</b> y en esta oportunidad no fue aprobada.</p>'
        . '<p style="margin:0;line-height:1.65;">Si desea conocer el detalle o revisar alternativas, comuníquese con nosotros.</p>';
    }
    $rows = '';
    foreach (OwnerCreditCalculator::schedule((float) $credit['approved_rate'], (int) $credit['approved_term'], (float) $credit['approved_amount']) as $row) {
      $rows .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;">' . $row['mes'] . '</td><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $e($money($row['cuota'])) . '</td><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $e($money($row['interes'])) . '</td><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $e($money($row['abono'])) . '</td><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $e($money($row['saldo'])) . '</td></tr>';
    }
    return $html . '<p style="margin:0 0 14px;line-height:1.65;">Su solicitud de crédito asociada al contrato <b>#' . $e($credit['contract_code']) . '</b> fue <b>aprobada</b> con las siguientes condiciones:</p>'
      . '<p style="margin:0 0 14px;line-height:1.8;">Valor: <b>' . $e($money($credit['approved_amount'])) . '</b><br>Plazo: <b>' . (int) $credit['approved_term'] . ' meses</b><br>Tasa: <b>' . $e(number_format((float) $credit['approved_rate'] * 100, 2, ',', '.')) . ' % nominal mes vencido</b><br>Cuota mensual: <b>' . $e($money($credit['monthly_payment'])) . '</b></p>'
      . '<p style="margin:0 0 8px;line-height:1.65;">Las cuotas se descontarán de los giros de arrendamiento del inmueble.</p>'
      . '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f1f5f9;"><th style="padding:4px 8px;text-align:left;">Mes</th><th style="padding:4px 8px;text-align:right;">Cuota</th><th style="padding:4px 8px;text-align:right;">Intereses</th><th style="padding:4px 8px;text-align:right;">Abono a capital</th><th style="padding:4px 8px;text-align:right;">Saldo</th></tr></thead><tbody>' . $rows . '</tbody></table>'
      . '<p style="margin:14px 0 0;line-height:1.65;">Cordialmente,<br><b>SKC SuCasa Inmobiliaria</b></p>';
  }

  private function notifyInternal(string $action, string $title, string $details, string $reference): int
  {
    try {
      $copies = InternalNotificationRecipients::emailsForAction($this->db, $action);
      if ($copies === []) return 0;
      $url = (defined('SCM_BASE_URL') ? SCM_BASE_URL : '') . '/index.php?tab=credito_propietario';
      return (new EmailQueue($this->db))->enqueue($copies, $title, EmailTemplate::render($title, '<p>' . EmailTemplate::e($details) . '</p>', ['buttons' => [['url' => $url, 'label' => 'Abrir créditos']]]), [
        'source_module' => $action, 'internal_action' => $action, 'internal_cc' => $copies,
        'dedupe_key' => 'internal-admin:' . $action . ':' . $reference, 'meta' => ['event' => $action],
      ]);
    } catch (\Throwable $error) {
      error_log('[owner-credit-internal:' . $action . '] ' . $error->getMessage());
      return 0;
    }
  }

  /**
   * WhatsApp a los funcionarios del evento interno (plantilla scm_credito_propietario_funcionario_v2),
   * activable en la configuración de créditos. Un mensaje por celular.
   */
  private function notifyStaffWhatsapp(string $action, array $credit, string $event, string $detail): int
  {
    try {
      if (!$this->config()['notify_staff_whatsapp']) return 0;
      $base = parse_url((defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : '') . '/index.php?tab=credito_propietario');
      $suffix = ltrim((string) ($base['path'] ?? ''), '/') . (isset($base['query']) ? '?' . $base['query'] : '');
      $clean = static fn($value, int $limit): string => mb_substr(preg_replace('/\s+/u', ' ', trim((string) $value)) ?: 'Sin dato', 0, $limit);
      $sms = new SmsQueue($this->db);
      $sent = [];
      foreach (InternalNotificationRecipients::contactsForAction($this->db, $action) as $contact) {
        $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? '')) ?? '';
        if ($phone === '' || isset($sent[$phone])) continue;
        $values = [$clean($contact['name'] ?? 'Funcionario', 80), $clean($event, 60), (string) (int) $credit['id'], $clean($credit['contract_code'], 40), $clean($credit['owner_name'], 120), $clean($detail, 400)];
        $ok = $sms->enqueue((string) $contact['phone'], (string) ($contact['name'] ?? ''), $event . ' #' . $credit['id'] . ': ' . $detail, [
          'source_module' => $action, 'dedupe_key' => 'owner-credit-staff-wa:' . $action . ':' . $credit['id'] . ':' . $credit['status'] . ':' . $phone,
          'template_name' => 'scm_credito_propietario_funcionario_v2', 'template_language' => 'es_CO',
          'template_components' => [
            ['type' => 'body', 'parameters' => array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $values)],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
          ],
          'credit_id' => (int) $credit['id'],
        ]);
        if ($ok) $sent[$phone] = true;
      }
      return count($sent);
    } catch (\Throwable $error) {
      error_log('[owner-credit-staff-wa:' . $action . '] ' . $error->getMessage());
      return 0;
    }
  }

  /**
   * Personas a las que se les puede hacer el crédito: los propietarios del contrato de mandato (1 a 6) o,
   * sin mandato, el propietario del contrato de arrendamiento.
   * @return list<array{key:string,name:string,document:string,email:string,phone:string,owner_id:string}>
   */
  public function contractOwners(int $contractId): array
  {
    $contract = $this->contractById($contractId);
    if ($contract === null) return [];
    $owners = [];
    $mandateTable = $this->db->table('jet_cct_contrato_mandato');
    $mandateId = (int) ($contract['id_contrato_mandato'] ?? 0);
    $mandate = $mandateId > 0 && $this->schema->tableExists($mandateTable) ? $this->db->getRow("SELECT * FROM `{$mandateTable}` WHERE `_ID` = ? LIMIT 1", [$mandateId]) : null;
    if (is_array($mandate)) {
      $count = max(1, min(6, (int) ($mandate['propietarios'] ?? 1)));
      for ($i = 1; $i <= $count; $i++) {
        $company = strcasecmp(trim((string) ($mandate['tipo_' . $i] ?? '')), 'Natural') !== 0 && trim((string) ($mandate['empresa_' . $i] ?? '')) !== '';
        $name = trim((string) ($company ? $mandate['empresa_' . $i] : ($mandate['nombre_' . $i] ?? '')));
        if ($name === '') continue;
        $document = trim((string) ($company ? ($mandate['nit_' . $i] ?? '') : ($mandate['documento_' . $i] ?? '')));
        $ownerId = trim((string) ($mandate['id_propietario_nuevo_' . $i] ?? ''));
        if ($ownerId === '') $ownerId = $i === 1 ? (string) ($contract['id_propietario'] ?? '') : 'doc:' . $document;
        $owners[] = [
          'key' => 'mandato_' . $i, 'name' => $name, 'document' => $document,
          'email' => trim((string) ($mandate['correo_' . $i] ?? '')),
          'phone' => self::phone((string) ($mandate['celular_' . $i] ?? ''), (string) ($mandate['indicativo_' . $i] ?? '')),
          'owner_id' => $ownerId,
        ];
      }
    }
    if ($owners === []) {
      $owners[] = [
        'key' => 'contrato', 'name' => trim((string) ($contract['propietario'] ?? '')), 'document' => '',
        'email' => trim((string) ($contract['correo_propietario'] ?? '')),
        'phone' => self::phone((string) ($contract['celular_propietario'] ?? ''), (string) ($contract['indicativo_propietario'] ?? '')),
        'owner_id' => (string) ($contract['id_propietario'] ?? ''),
      ];
    }
    return $owners;
  }

  /** Celular con indicativo en dígitos (+57 314 5106244 → 573145106244). */
  private static function phone(string $phone, string $prefix): string
  {
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    $prefixDigits = preg_replace('/\D/', '', $prefix) ?? '';
    if ($digits === '') return '';
    if (str_starts_with(trim($phone), '+') || $prefixDigits === '' || str_starts_with($digits, $prefixDigits)) return $digits;
    return $prefixDigits . $digits;
  }

  /** @return array<string,mixed>|null */
  private function contractById(int $id): ?array
  {
    if ($id <= 0) return null;
    $row = $this->db->getRow("SELECT * FROM `{$this->db->table('jet_cct_contratos_arrendamiento')}` WHERE `_ID` = ? LIMIT 1", [$id]);
    return is_array($row) ? $row : null;
  }

  /** @return array<string,mixed>|null */
  private function contractByRef(string $ref): ?array
  {
    $ref = trim(ltrim(trim($ref), '#'));
    if ($ref === '' || strcasecmp($ref, 'No asignado') === 0) return null;
    $row = $this->db->getRow("SELECT * FROM `{$this->db->table('jet_cct_contratos_arrendamiento')}` WHERE TRIM(`contrato`) = ? ORDER BY CASE WHEN LOWER(TRIM(`estado`)) = 'entregado' THEN 0 ELSE 1 END, `_ID` DESC LIMIT 1", [$ref]);
    return is_array($row) ? $row : null;
  }

  public static function beneficiaryLabel(array $credit): string
  {
    return self::BENEFICIARY_TYPES[(string) ($credit['beneficiary_type'] ?? '')] ?? self::BENEFICIARY_TYPES['propietario'];
  }

  public static function money($value): float
  {
    if (is_int($value) || is_float($value)) return max(0.0, (float) $value);
    $text = preg_replace('/[^\d,.\-]/', '', trim((string) $value)) ?? '';
    if ($text === '') return 0.0;
    if (str_contains($text, ',') && str_contains($text, '.')) {
      $text = strrpos($text, ',') > strrpos($text, '.') ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
    } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $text) === 1) {
      $text = str_replace('.', '', $text);
    } elseif (str_contains($text, ',')) {
      $text = preg_match('/^\d{1,3}(?:,\d{3})+$/', $text) === 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
    }
    return is_numeric($text) ? max(0.0, (float) $text) : 0.0;
  }

  /** El liquidador escribe la tasa en porcentaje N.M.V (2,3 = 0,023). */
  public static function rate($value): float
  {
    $text = str_replace(',', '.', trim(rtrim(trim((string) $value), '%')));
    return is_numeric($text) ? max(0.0, (float) $text / 100) : 0.0;
  }

  public static function timestamp($value): int
  {
    $text = trim((string) $value);
    if ($text === '') return 0;
    if (ctype_digit($text)) {
      $number = (int) $text;
      return $number > 9999999999 ? intdiv($number, 1000) : $number;
    }
    return (int) (strtotime($text) ?: 0);
  }

  private static function isYes($value): bool
  {
    return in_array(mb_strtolower(trim((string) $value), 'UTF-8'), ['si', 'sí', '1', 'yes'], true);
  }
}
