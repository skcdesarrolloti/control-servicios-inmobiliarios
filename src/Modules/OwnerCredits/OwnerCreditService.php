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
  public const STATUSES = [
    'pendiente' => 'Pendiente',
    'aprobado' => 'Aprobado',
    'rechazado' => 'Rechazado',
    'pagado' => 'Pagado',
    'anulado' => 'Anulado',
  ];
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
      purpose VARCHAR(120) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
      requested_amount DECIMAL(14,2) NOT NULL DEFAULT 0, requested_term INT NOT NULL DEFAULT 0,
      vacancy VARCHAR(10) NOT NULL DEFAULT 'Baja', gross_income DECIMAL(14,2) NOT NULL DEFAULT 0,
      deductions_json TEXT NULL, net_income DECIMAL(14,2) NOT NULL DEFAULT 0,
      max_amount DECIMAL(14,2) NOT NULL DEFAULT 0, max_months INT NOT NULL DEFAULT 0,
      approved_amount DECIMAL(14,2) NULL, approved_term INT NULL, approved_rate DECIMAL(10,6) NULL,
      monthly_payment DECIMAL(14,2) NULL, total_interest DECIMAL(14,2) NULL, first_payment_ts BIGINT NULL,
      policy_json MEDIUMTEXT NULL, evaluation_json MEDIUMTEXT NULL, policy_override TINYINT NOT NULL DEFAULT 0,
      decision_note TEXT NULL, decided_by_id VARCHAR(40) NOT NULL DEFAULT '', decided_by_name VARCHAR(190) NOT NULL DEFAULT '',
      decided_at BIGINT NULL, closed_at BIGINT NULL,
      created_by_id VARCHAR(40) NOT NULL DEFAULT '', created_by_name VARCHAR(190) NOT NULL DEFAULT '',
      created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
      UNIQUE KEY uniq_quote (quote_id), KEY idx_status (status), KEY idx_owner (owner_id), KEY idx_contract (contract_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->eventsTable()}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credit_id BIGINT UNSIGNED NOT NULL,
      action VARCHAR(40) NOT NULL, actor_id VARCHAR(40) NOT NULL DEFAULT '', actor_name VARCHAR(190) NOT NULL DEFAULT '',
      details_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL, KEY idx_credit (credit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$this->schema->columnExists($this->table(), 'owner_document')) {
      $this->db->pdo()->exec("ALTER TABLE `{$this->table()}` ADD COLUMN owner_document VARCHAR(40) NOT NULL DEFAULT ''");
      // El inspector guarda las columnas en caché: uno nuevo ya ve la columna agregada.
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
  public function createManual(int $contractId, float $amount, int $term, string $purpose, array $actor, string $contractEnd = '', string $ownerKey = ''): int
  {
    $this->ensureSchema();
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
    $maxMonths = OwnerCreditCalculator::monthsUntil(time(), $endTs);
    if ($term <= 0) throw new \DomainException('Indica el plazo solicitado en meses.');
    if ($term > $maxMonths) throw new \DomainException(self::termMessage($term, $maxMonths));
    $id = $this->insertCredit($contract, null, '', $amount, $purpose, $actor, $term, $owner);
    // La fecha fin corregida en el formulario se guarda en el contrato (con historial).
    if ($endTs !== $storedEnd) $this->saveContractEnd($this->find($id) ?? [], $endTs, $actor);
    $this->notifyRequested($id);
    return $id;
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
    $credit = $this->find($id);
    if ($credit === null) throw new \DomainException('El crédito ya no existe.');
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
    $vacancy = (string) ($input['vacancy'] ?? $credit['vacancy'] ?? 'Baja');
    $requestTs = (int) $credit['created_at'];
    $storedEndTs = self::timestamp($contract['fin_contrato'] ?? '');
    $inputEndTs = self::dateInput($input['contract_end'] ?? '');
    $endTs = $inputEndTs > 0 ? $inputEndTs : $storedEndTs;
    $evaluation = OwnerCreditCalculator::evaluate([
      'gross' => $gross, 'deductions' => $deductions, 'requested_amount' => $requested, 'requested_term' => $requestedTerm,
      'request_ts' => $credit['status'] === 'pendiente' ? time() : $requestTs, 'contract_end_ts' => $endTs, 'vacancy' => $vacancy,
    ], $settings);

    $decided = in_array($credit['status'], ['aprobado', 'pagado'], true);
    $suggested = $evaluation['suggested'];
    $amount = array_key_exists('amount', $input) ? self::money($input['amount']) : ($decided ? (float) $credit['approved_amount'] : (float) $suggested['amount']);
    $term = array_key_exists('term', $input) ? max(0, (int) $input['term']) : ($decided ? (int) $credit['approved_term'] : (int) $suggested['term']);
    $rate = array_key_exists('rate', $input) ? self::rate($input['rate']) : ($decided ? (float) $credit['approved_rate'] : round((float) $suggested['rate'], 6));
    $payment = OwnerCreditCalculator::payment($rate, $term, $amount);
    $schedule = OwnerCreditCalculator::schedule($rate, $term, $amount);
    $policies = $this->policies($credit, $contract, $evaluation, $settings, $amount, $term, $rate, $payment, $endTs);
    // El plazo solicitado nunca puede pasar de los meses que faltan entre la solicitud y el fin del contrato.
    $termError = $requestedTerm <= 0 ? 'Escribe el plazo solicitado (meses) para evaluar el crédito.' : ($requestedTerm > $evaluation['max_months'] ? self::termMessage($requestedTerm, $evaluation['max_months']) : '');

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
      'policies' => $policies,
      'policies_ok' => !in_array(false, array_column($policies, 'ok'), true),
      'other_credits' => $this->ownerCredits($credit),
      'events' => $this->events($id),
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
        'evaluation_json' => json_encode($evaluation + ['settings' => $work['settings']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
          'total_interest' => round($conditions['total_interest'], 2), 'first_payment_ts' => (int) strtotime('+1 month', $now),
        ];
      }
      $this->db->update($this->table(), $data, ['id' => $id]);
      $this->event($id, $decision, $actor, ['nota' => $note, 'condiciones' => $decision === 'aprobado' ? $conditions : null, 'incumple' => array_values(array_map(static fn(array $p): string => $p['label'], array_filter($work['policies'], static fn(array $p): bool => !$p['ok'])))]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }

    $credit = $this->find($id) ?? [];
    $summary = $this->decisionSummary($credit);
    $this->recordHistories($credit, $decision === 'aprobado' ? 'Crédito aprobado' : 'Crédito rechazado', $summary, $actor);
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
   * Listado y totales para Métricas › Créditos.
   * @param array<string,mixed> $filters
   * @return array<string,mixed>
   */
  public function report(array $filters): array
  {
    $this->ensureSchema();
    $where = ['1=1'];
    $args = [];
    $status = (string) ($filters['status'] ?? '');
    if (isset(self::STATUSES[$status])) { $where[] = '`status` = ?'; $args[] = $status; }
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

  private static function termMessage(int $term, int $maxMonths): string
  {
    return 'El plazo solicitado (' . $term . ' meses) no puede superar los ' . $maxMonths . ' meses que faltan para el fin del contrato. Corrige el plazo o la fecha fin.';
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
  private function insertCredit(array $contract, ?int $quoteId, string $ticketId, float $amount, string $purpose, array $actor, int $term = 0, ?array $owner = null): int
  {
    $owner ??= $this->contractOwners((int) ($contract['_ID'] ?? 0))[0] ?? null;
    // Sin plazo por defecto ($term = 0): lo escribe el funcionario según lo que pidió el propietario.
    $income = $this->defaultIncome($contract);
    $now = time();
    $phone = $owner !== null ? $owner['phone'] : self::phone((string) ($contract['celular_propietario'] ?? ''), (string) ($contract['indicativo_propietario'] ?? ''));
    $this->db->insert($this->table(), $this->schema->filterTableData($this->table(), [
      'quote_id' => $quoteId, 'ticket_id' => $ticketId,
      'contract_id' => (int) ($contract['_ID'] ?? 0), 'contract_code' => (string) ($contract['contrato'] ?? ''),
      'property' => (string) ($contract['inmueble'] ?? $contract['id_inmueble'] ?? ''), 'address' => mb_substr((string) ($contract['direccion'] ?? ''), 0, 255),
      'owner_id' => $owner !== null ? $owner['owner_id'] : (string) ($contract['id_propietario'] ?? ''),
      'owner_name' => mb_substr($owner !== null ? $owner['name'] : (string) ($contract['propietario'] ?? ''), 0, 190),
      'owner_email' => mb_substr($owner !== null ? $owner['email'] : trim((string) ($contract['correo_propietario'] ?? '')), 0, 190),
      'owner_phone' => mb_substr($phone, 0, 40), 'owner_document' => mb_substr($owner !== null ? $owner['document'] : '', 0, 40),
      'purpose' => $purpose, 'status' => 'pendiente', 'requested_amount' => round($amount, 2), 'requested_term' => $term,
      'vacancy' => 'Baja', 'gross_income' => $income['gross'], 'deductions_json' => json_encode($income['deductions'], JSON_THROW_ON_ERROR),
      'created_by_id' => (string) ($actor['employee_id'] ?? ''), 'created_by_name' => (string) ($actor['name'] ?? ''),
      'created_at' => $now, 'updated_at' => $now,
    ]));
    $id = (int) $this->db->lastInsertId();
    $this->event($id, 'solicitado', $actor, ['cotizacion' => $quoteId, 'valor' => $amount]);
    $this->recordRequestHistory($id, $actor, false);
    return $id;
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
  private function policies(array $credit, array $contract, array $evaluation, array $settings, float $amount, int $term, float $rate, float $payment, int $endTs): array
  {
    $minMonths = (int) $settings['min_remaining_months'];
    $others = array_filter($this->ownerCredits($credit), static fn(array $row): bool => $row['status'] === 'aprobado');
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $percent = static fn(float $value): string => number_format($value * 100, 2, ',', '.') . ' %';
    return [
      ['key' => 'contrato_activo', 'label' => 'Contrato activo', 'ok' => strtolower(trim((string) ($contract['estado'] ?? ''))) === 'entregado', 'detail' => 'Estado del contrato: ' . ((string) ($contract['estado'] ?? '') ?: 'sin contrato') . '.'],
      ['key' => 'vigencia', 'label' => 'Contrato no próximo a vencer', 'ok' => $endTs > 0 && $evaluation['max_months'] >= $minMonths, 'detail' => $endTs > 0 ? $evaluation['max_months'] . ' meses hasta el fin (' . date('d/m/Y', $endTs) . '). Mínimo: ' . $minMonths . '.' : 'El contrato no tiene fecha fin.'],
      ['key' => 'plazo', 'label' => 'Plazo dentro de la vigencia', 'ok' => $term > 0 && $term <= $evaluation['max_months'], 'detail' => 'Plazo ' . $term . ' meses; máximo ' . $evaluation['max_months'] . '.'],
      ['key' => 'siniestro', 'label' => 'Contrato no siniestrado', 'ok' => !self::isYes($contract['esta_sinestrado'] ?? ''), 'detail' => self::isYes($contract['esta_sinestrado'] ?? '') ? 'Cartera marcó el contrato como siniestrado.' : 'Sin siniestro registrado en cartera.'],
      ['key' => 'prestamos', 'label' => 'Sin otros créditos vigentes', 'ok' => $others === [], 'detail' => $others === [] ? 'El propietario no tiene créditos aprobados vigentes.' : 'Créditos vigentes: ' . implode(', ', array_map(static fn(array $row): string => '#' . $row['id'] . ' (contrato ' . $row['contract_code'] . ')', $others)) . '.'],
      ['key' => 'monto', 'label' => 'Valor dentro del tope', 'ok' => $amount > 0 && $amount <= $evaluation['max_amount'] + 0.5, 'detail' => $money($amount) . ' de un tope de ' . $money($evaluation['max_amount']) . ' (' . (int) $settings['income_months'] . ' × ingreso neto).'],
      ['key' => 'usura', 'label' => 'Tasa por debajo de la usura', 'ok' => $rate <= $evaluation['usury_monthly_legal'] + 1e-9, 'detail' => 'Tasa ' . $percent($rate) . ' mes vencido (' . $percent((1 + $rate) ** 12 - 1) . ' E.A.); usura ' . $percent((float) $settings['usury_annual']) . ' E.A. = ' . $percent($evaluation['usury_monthly_legal']) . ' mensual' . ((string) $settings['usury_updated_at'] !== '' ? ', vigente desde ' . date('d/m/Y', (int) strtotime((string) $settings['usury_updated_at'])) : '') . '.'],
      ['key' => 'cuota', 'label' => 'Cuota cubierta por el ingreso neto', 'ok' => $payment > 0 && $payment <= $evaluation['net_income'], 'detail' => 'Cuota ' . $money($payment) . ' frente a ingreso neto de ' . $money($evaluation['net_income']) . '.'],
    ];
  }

  /** Otros créditos del mismo propietario (o del mismo contrato si no hay id de propietario). @return list<array<string,mixed>> */
  private function ownerCredits(array $credit): array
  {
    $owner = trim((string) $credit['owner_id']);
    $rows = $owner !== ''
      ? $this->db->getResults("SELECT `id`, `status`, `contract_code`, `approved_amount`, `requested_amount`, `created_at` FROM `{$this->table()}` WHERE `owner_id` = ? AND `id` <> ? ORDER BY `id` DESC", [$owner, (int) $credit['id']])
      : $this->db->getResults("SELECT `id`, `status`, `contract_code`, `approved_amount`, `requested_amount`, `created_at` FROM `{$this->table()}` WHERE `contract_id` = ? AND `id` <> ? ORDER BY `id` DESC", [(int) $credit['contract_id'], (int) $credit['id']]);
    return array_values($rows);
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
