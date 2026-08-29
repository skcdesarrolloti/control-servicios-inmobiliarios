<?php

declare(strict_types=1);

namespace SCM\Modules\CollectionManagement;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Modules\AdministrativeNotifications\AdministrativeNotificationsService;
use SCM\Modules\CanonInsuranceAudit\SpreadsheetReader;
use SCM\Support\EmailQueue;
use SCM\Support\SchemaInspector;

final class CollectionPortfolioService
{
  private const IMPORT_VERSION = 'auxiliar-1380-v1';
  private const MONEY_TOLERANCE = 0.005;

  private Database $db;
  private SpreadsheetReader $reader;
  private SchemaInspector $schema;

  public function __construct(Database $db, ?SpreadsheetReader $reader = null)
  {
    $this->db = $db;
    $this->reader = $reader ?? new SpreadsheetReader();
    $this->schema = new SchemaInspector($db);
  }

  public function migrateSchema(): void
  {
    $this->ensureSchema();
  }

  /** @param array<string,mixed> $file @return array<string,mixed> */
  public function import(array $file): array
  {
    $this->ensureSchema();
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      throw new \RuntimeException($this->uploadErrorMessage($error));
    }
    $path = (string) ($file['tmp_name'] ?? '');
    $name = basename(trim((string) ($file['name'] ?? 'auxiliar-1380.xls')));
    $size = (int) ($file['size'] ?? 0);
    $maxBytes = defined('SCM_UPLOAD_MAX_BYTES') ? (int) SCM_UPLOAD_MAX_BYTES : 10485760;
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($path === '' || !is_readable($path) || $size <= 0 || $size > $maxBytes) {
      throw new \RuntimeException('El archivo está vacío, no se puede leer o supera el límite permitido.');
    }
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
      throw new \RuntimeException('Formato no soportado. Sube el auxiliar 1380 en .xls o .xlsx.');
    }

    $fileHash = hash_file('sha256', $path);
    if (!is_string($fileHash)) {
      throw new \RuntimeException('No se pudo verificar el archivo subido.');
    }
    $sourceHash = hash('sha256', self::IMPORT_VERSION . '|' . $fileHash);
    $existing = (int) ($this->db->getVar(
      "SELECT `id` FROM `{$this->importsTable()}` WHERE `source_hash` = ? LIMIT 1",
      [$sourceHash]
    ) ?? 0);
    if ($existing > 0) {
      return ['duplicate' => true, 'import_id' => $existing] + $this->dashboard();
    }

    $rows = $this->reader->read($path, $name);
    $parsed = $this->parseAuxiliary($rows);
    $accounts = (array) ($parsed['accounts'] ?? []);
    if ($accounts === []) {
      throw new \RuntimeException('No se encontraron cuentas con inmueble, NIT y saldo en el auxiliar.');
    }

    $contracts = $this->activeContracts();
    $now = date('Y-m-d H:i:s');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->insert($this->importsTable(), [
        'source_filename' => mb_substr($name, 0, 255, 'UTF-8'),
        'source_hash' => $sourceHash,
        'source_date' => trim((string) ($parsed['source_date'] ?? '')) ?: null,
        'total_rows' => count($rows),
        'total_accounts' => count($accounts),
        'active_contracts' => count($contracts),
        'matched_contracts' => 0,
        'debtors' => 0,
        'current_accounts' => 0,
        'unmatched_contracts' => 0,
        'unmatched_accounts' => 0,
        'total_balance' => 0,
        'uploaded_by_id' => Auth::userId(),
        'uploaded_by_name' => mb_substr(Auth::user(), 0, 190, 'UTF-8'),
        'uploaded_at' => $now,
      ]);
      $importId = (int) $this->db->lastInsertId();
      $this->db->pdo()->exec("UPDATE `{$this->portfolioTable()}` SET `is_current` = 0");

      $accountsByProperty = [];
      foreach ($accounts as $key => $account) {
        $property = (string) ($account['property_code'] ?? '');
        if ($property !== '') {
          $accountsByProperty[$property][$key] = $account;
        }
      }

      // Reserva primero los cruces exactos. De este modo, el cruce auxiliar
      // por inmueble no puede tomar una cuenta que corresponde a otro
      // contrato activo por inmueble + documento.
      $reservedExactAccounts = [];
      foreach ($contracts as $contract) {
        $document = $this->digits($contract['documento_arrendatario'] ?? '');
        foreach ($this->contractPropertyCandidates($contract) as $property) {
          $candidateKey = $property . '|' . $document;
          if ($document !== '' && isset($accounts[$candidateKey])) {
            $reservedExactAccounts[$candidateKey] = true;
            break;
          }
        }
      }

      $usedAccounts = [];
      $stats = [
        'matched_contracts' => 0,
        'debtors' => 0,
        'current_accounts' => 0,
        'unmatched_contracts' => 0,
        'unmatched_accounts' => 0,
        'total_balance' => 0.0,
      ];

      foreach ($contracts as $contract) {
        $contractId = (int) ($contract['_ID'] ?? 0);
        if ($contractId <= 0) {
          continue;
        }
        $document = $this->digits($contract['documento_arrendatario'] ?? '');
        $propertyCandidates = $this->contractPropertyCandidates($contract);
        $accountKey = '';
        $matchStatus = 'sin_archivo';
        foreach ($propertyCandidates as $property) {
          $candidateKey = $property . '|' . $document;
          if ($document !== '' && isset($accounts[$candidateKey]) && !isset($usedAccounts[$candidateKey])) {
            $accountKey = $candidateKey;
            $matchStatus = 'exacto';
            break;
          }
        }
        if ($accountKey === '') {
          foreach ($propertyCandidates as $property) {
            $propertyAccounts = $accountsByProperty[$property] ?? [];
            if (count($propertyAccounts) === 1) {
              $fallbackKey = (string) array_key_first($propertyAccounts);
              if (isset($usedAccounts[$fallbackKey]) || isset($reservedExactAccounts[$fallbackKey])) {
                continue;
              }
              $accountKey = $fallbackKey;
              $matchStatus = 'solo_inmueble';
              break;
            }
          }
        }

        $account = $accountKey !== '' ? (array) ($accounts[$accountKey] ?? []) : [];
        if ($accountKey !== '') {
          $usedAccounts[$accountKey] = true;
          $stats['matched_contracts']++;
        } else {
          $stats['unmatched_contracts']++;
        }
        $balance = $account !== [] ? (float) ($account['balance'] ?? 0.0) : null;
        $status = $balance === null
          ? 'sin_dato'
          : ($balance > self::MONEY_TOLERANCE
            ? 'deuda'
            : ($balance < -self::MONEY_TOLERANCE ? 'saldo_favor' : 'al_dia'));
        if ($status === 'deuda') {
          $stats['debtors']++;
          $stats['total_balance'] += (float) $balance;
        } elseif (in_array($status, ['al_dia', 'saldo_favor'], true)) {
          $stats['current_accounts']++;
        }

        $this->upsertPortfolio('contract:' . $contractId, [
          'last_import_id' => $importId,
          'contract_id' => $contractId,
          'contract_number' => trim((string) ($contract['contrato'] ?? $contract['contrato_arrendamiento'] ?? $contractId)),
          'property_code' => trim((string) ($account['property_code'] ?? ($propertyCandidates[0] ?? ''))),
          'tenant_id' => (int) preg_replace('/\D+/', '', (string) ($contract['id_arrendatario'] ?? '0')),
          'tenant_document' => $document !== '' ? $document : (string) ($account['tenant_document'] ?? ''),
          'tenant_name' => trim((string) ($contract['arrendatario'] ?? '')),
          'tenant_email' => trim((string) ($contract['correo_arrendatario'] ?? '')),
          'tenant_phone' => trim((string) ($contract['celular_arrendatario'] ?? '')),
          'landlord_id' => (int) preg_replace('/\D+/', '', (string) ($contract['id_propietario'] ?? '0')),
          'landlord_name' => trim((string) ($contract['propietario'] ?? '')),
          'landlord_email' => trim((string) ($contract['correo_propietario'] ?? '')),
          'landlord_phone' => trim((string) ($contract['celular_propietario'] ?? '')),
          'property_address' => trim((string) ($contract['direccion'] ?? '')),
          'balance' => $balance,
          'status' => $status,
          'match_status' => $matchStatus,
          'source_detail' => (string) ($account['source_detail'] ?? ''),
          'source_date' => trim((string) ($account['last_date'] ?? ($parsed['source_date'] ?? ''))) ?: null,
          'is_current' => 1,
          'last_imported_at' => $now,
        ], $importId);
      }

      foreach ($accounts as $accountKey => $account) {
        if (isset($usedAccounts[$accountKey])) {
          continue;
        }
        $stats['unmatched_accounts']++;
        $balance = (float) ($account['balance'] ?? 0.0);
        $status = abs($balance) > self::MONEY_TOLERANCE ? 'sin_contrato' : 'sin_contrato';
        $this->upsertPortfolio('source:' . hash('sha256', $accountKey), [
          'last_import_id' => $importId,
          'contract_id' => null,
          'contract_number' => '',
          'property_code' => (string) ($account['property_code'] ?? ''),
          'tenant_id' => null,
          'tenant_document' => (string) ($account['tenant_document'] ?? ''),
          'tenant_name' => '',
          'tenant_email' => '',
          'tenant_phone' => '',
          'landlord_id' => null,
          'landlord_name' => '',
          'landlord_email' => '',
          'landlord_phone' => '',
          'property_address' => '',
          'balance' => $balance,
          'status' => $status,
          'match_status' => 'sin_contrato',
          'source_detail' => (string) ($account['source_detail'] ?? ''),
          'source_date' => trim((string) ($account['last_date'] ?? ($parsed['source_date'] ?? ''))) ?: null,
          'is_current' => 1,
          'last_imported_at' => $now,
        ], $importId);
      }

      $this->db->update($this->importsTable(), [
        'matched_contracts' => $stats['matched_contracts'],
        'debtors' => $stats['debtors'],
        'current_accounts' => $stats['current_accounts'],
        'unmatched_contracts' => $stats['unmatched_contracts'],
        'unmatched_accounts' => $stats['unmatched_accounts'],
        'total_balance' => round($stats['total_balance'], 2),
      ], ['id' => $importId]);
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    return ['duplicate' => false, 'import_id' => $importId] + $this->dashboard();
  }

  /** @param array<string,mixed> $filters @return array<string,mixed> */
  public function dashboard(array $filters = []): array
  {
    $this->ensureSchema();
    $latest = $this->db->getRow("SELECT * FROM `{$this->importsTable()}` ORDER BY `id` DESC LIMIT 1");
    $status = trim((string) ($filters['status'] ?? ''));
    $stage = trim((string) ($filters['stage'] ?? ''));
    $movement = trim((string) ($filters['movement'] ?? ''));
    $search = trim((string) ($filters['search'] ?? ''));
    if (!in_array($status, ['', 'deuda', 'al_dia', 'saldo_favor', 'sin_dato', 'sin_contrato'], true)) {
      $status = '';
    }
    if (!in_array($stage, ['', 'normal', 'prejuridico', 'siniestro'], true)) {
      $stage = '';
    }
    if (!in_array($movement, ['', 'paid_latest'], true)) {
      $movement = '';
    }
    $where = ['`is_current` = 1'];
    $args = [];
    if ($status !== '') {
      $where[] = '`status` = ?';
      $args[] = $status;
    }
    if ($stage !== '') {
      $where[] = '`collection_stage` = ?';
      $args[] = $stage;
    }
    if ($movement === 'paid_latest') {
      $latestImportId = is_array($latest) ? (int) ($latest['id'] ?? 0) : 0;
      if ($latestImportId > 0) {
        $where[] = '`id` IN (SELECT `portfolio_id` FROM `' . $this->eventsTable() . '` WHERE `import_id` = ? AND `event_type` = ?)';
        $args[] = $latestImportId;
        $args[] = 'payment_received';
      } else {
        $where[] = '1 = 0';
      }
    }
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = '(`tenant_name` LIKE ? OR `tenant_document` LIKE ? OR `contract_number` LIKE ? OR `property_code` LIKE ? OR `property_address` LIKE ?)';
      array_push($args, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(20, min(100, (int) ($filters['per_page'] ?? 40)));
    $total = (int) ($this->db->getVar("SELECT COUNT(*) FROM `{$this->portfolioTable()}` WHERE {$whereSql}", $args) ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $rows = $this->db->getResults(
      "SELECT * FROM `{$this->portfolioTable()}` WHERE {$whereSql}
        ORDER BY CASE `status` WHEN 'deuda' THEN 0 WHEN 'sin_contrato' THEN 1 WHEN 'sin_dato' THEN 2 WHEN 'saldo_favor' THEN 3 ELSE 4 END,
          ABS(COALESCE(`balance`, 0)) DESC, `tenant_name` ASC, `id` ASC
        LIMIT {$perPage} OFFSET {$offset}",
      $args
    );

    $summary = [
      'total' => 0,
      'debtors' => 0,
      'current' => 0,
      'credits' => 0,
      'without_data' => 0,
      'unmatched' => 0,
      'prejuridical' => 0,
      'claims' => 0,
      'paid' => 0,
      'balance' => 0.0,
    ];
    $summaryRows = $this->db->getResults(
      "SELECT `status`, `collection_stage`, COUNT(*) AS total, SUM(COALESCE(`balance`, 0)) AS balance
         FROM `{$this->portfolioTable()}` WHERE `is_current` = 1 GROUP BY `status`, `collection_stage`"
    );
    foreach ($summaryRows as $row) {
      $count = (int) ($row['total'] ?? 0);
      $rowStatus = (string) ($row['status'] ?? '');
      $rowStage = (string) ($row['collection_stage'] ?? 'normal');
      $summary['total'] += $count;
      if ($rowStatus === 'deuda') {
        $summary['debtors'] += $count;
        $summary['balance'] += (float) ($row['balance'] ?? 0);
      } elseif ($rowStatus === 'al_dia') {
        $summary['current'] += $count;
      } elseif ($rowStatus === 'saldo_favor') {
        $summary['credits'] += $count;
      } elseif ($rowStatus === 'sin_dato') {
        $summary['without_data'] += $count;
      } elseif ($rowStatus === 'sin_contrato') {
        $summary['unmatched'] += $count;
      }
      if ($rowStage === 'prejuridico') {
        $summary['prejuridical'] += $count;
      } elseif ($rowStage === 'siniestro') {
        $summary['claims'] += $count;
      }
    }
    if (is_array($latest)) {
      $summary['paid'] = (int) ($this->db->getVar(
        "SELECT COUNT(*) FROM `{$this->eventsTable()}` WHERE `import_id` = ? AND `event_type` = 'payment_received'",
        [(int) ($latest['id'] ?? 0)]
      ) ?? 0);
    }

    return [
      'latest_import' => $latest,
      'summary' => $summary,
      'rows' => $rows,
      'filters' => ['status' => $status, 'stage' => $stage, 'movement' => $movement, 'search' => $search],
      'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
    ];
  }

  /** @return array<string,mixed> */
  public function item(int $portfolioId): array
  {
    $this->ensureSchema();
    $row = $this->db->getRow("SELECT * FROM `{$this->portfolioTable()}` WHERE `id` = ? LIMIT 1", [$portfolioId]);
    if (!is_array($row)) {
      throw new \RuntimeException('Registro de cartera no encontrado.');
    }
    $row['codeudores'] = $this->codeudoresForItem($row);
    return $row;
  }

  /** @return array<string,mixed> */
  public function updateStage(int $portfolioId, string $stage, string $note = ''): array
  {
    $item = $this->item($portfolioId);
    if ($stage !== 'normal' && (string) ($item['status'] ?? '') !== 'deuda') {
      throw new \RuntimeException('Solo se puede escalar un contrato que tenga saldo pendiente en el último auxiliar.');
    }
    if (!in_array($stage, ['normal', 'prejuridico', 'siniestro'], true)) {
      throw new \RuntimeException('Estado de cobranza no válido.');
    }
    $now = date('Y-m-d H:i:s');
    $this->db->update($this->portfolioTable(), [
      'collection_stage' => $stage,
      'stage_changed_at' => $now,
      'stage_changed_by' => mb_substr(Auth::user(), 0, 190, 'UTF-8'),
      'last_action_type' => 'estado_' . $stage,
      'last_action_at' => $now,
      'notes' => mb_substr(trim($note), 0, 2000, 'UTF-8'),
      'updated_at' => $now,
    ], ['id' => $portfolioId]);
    $contractId = (int) ($item['contract_id'] ?? 0);
    if ($contractId > 0 && $this->schema->columnExists($this->contractsTable(), 'esta_sinestrado')) {
      $this->db->update($this->contractsTable(), [
        'esta_sinestrado' => $stage === 'siniestro' ? 'Si' : 'No',
        'cct_modified' => $now,
      ], ['_ID' => $contractId]);
    }
    $this->addEvent($portfolioId, null, 'stage_changed', $item['balance'] ?? null, $item['balance'] ?? null, $note !== '' ? $note : 'Estado actualizado a ' . $stage);
    return $this->item($portfolioId);
  }

  public function recordManagement(int $portfolioId, int $created, string $observation): void
  {
    if ($portfolioId <= 0 || $created <= 0) {
      return;
    }
    $now = date('Y-m-d H:i:s');
    $this->db->update($this->portfolioTable(), [
      'last_action_type' => 'gestion_cobro',
      'last_action_at' => $now,
      'notes' => mb_substr(trim($observation), 0, 2000, 'UTF-8'),
      'updated_at' => $now,
    ], ['id' => $portfolioId]);
    $this->addEvent($portfolioId, null, 'collection_management', null, null, trim($observation));
  }

  /** @return array<string,mixed> */
  public function generateLetter(int $portfolioId, string $letterType, bool $sendEmail): array
  {
    if (!in_array($letterType, ['prejuridico', 'siniestro'], true)) {
      throw new \RuntimeException('Tipo de carta no válido.');
    }
    $item = $this->item($portfolioId);
    if ((int) ($item['contract_id'] ?? 0) <= 0) {
      throw new \RuntimeException('Primero debes vincular esta cuenta con un contrato de arrendamiento.');
    }
    if ((string) ($item['status'] ?? '') !== 'deuda') {
      throw new \RuntimeException('El contrato no registra saldo pendiente en el último auxiliar.');
    }
    $sender = (new AdministrativeNotificationsService($this->db))->senderProfile();
    $generator = new CollectionLetterPdfGenerator();
    $document = $generator->generate($item, $letterType, $sender);
    $now = date('Y-m-d H:i:s');
    $stage = $letterType === 'siniestro' ? 'siniestro' : 'prejuridico';
    $this->db->update($this->portfolioTable(), [
      'collection_stage' => $stage,
      'stage_changed_at' => $now,
      'stage_changed_by' => mb_substr(Auth::user(), 0, 190, 'UTF-8'),
      'last_action_type' => $sendEmail ? 'carta_' . $letterType . '_enviada' : 'carta_' . $letterType . '_generada',
      'last_action_at' => $now,
      'updated_at' => $now,
    ], ['id' => $portfolioId]);
    if ($stage === 'siniestro' && $this->schema->columnExists($this->contractsTable(), 'esta_sinestrado')) {
      $this->db->update($this->contractsTable(), ['esta_sinestrado' => 'Si', 'cct_modified' => $now], ['_ID' => (int) $item['contract_id']]);
    }

    $queued = 0;
    $recipients = [];
    if ($sendEmail) {
      $recipients[] = trim((string) ($item['tenant_email'] ?? ''));
      if ($letterType === 'siniestro') {
        $recipients[] = trim((string) ($item['landlord_email'] ?? ''));
        foreach ((array) ($item['codeudores'] ?? []) as $codeudor) {
          $recipients[] = trim((string) ($codeudor['correo'] ?? ''));
        }
      }
      $recipients = array_values(array_unique(array_filter($recipients, static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
      if ($recipients !== []) {
        $subject = $letterType === 'siniestro'
          ? 'Aviso de siniestro por mora en contrato de arrendamiento'
          : 'Gestión prejurídica de cobro - contrato de arrendamiento';
        $html = '<p>Cordial saludo.</p><p>Adjuntamos mediante enlace seguro la carta relacionada con el contrato <strong>'
          . htmlspecialchars((string) ($item['contract_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
          . '</strong> y el inmueble <strong>' . htmlspecialchars((string) ($item['property_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
          . '</strong>.</p><p><a href="' . htmlspecialchars((string) $document['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
          . '">Abrir carta en PDF</a></p><p>Atentamente,<br>'
          . htmlspecialchars((string) $sender['signature_line'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $queued = (new EmailQueue($this->db))->enqueue($recipients, $subject, $html, [
          'source_module' => 'collection-management',
          'destination_name' => (string) ($item['tenant_name'] ?? ''),
          'dedupe_key' => 'collection-letter:' . $portfolioId . ':' . $letterType . ':' . date('YmdHi'),
          'meta' => ['portfolio_id' => $portfolioId, 'letter_type' => $letterType, 'document_url' => $document['url']],
        ]);
      }
    }
    $this->addEvent(
      $portfolioId,
      null,
      $sendEmail ? 'letter_sent_' . $letterType : 'letter_generated_' . $letterType,
      $item['balance'] ?? null,
      $item['balance'] ?? null,
      $sendEmail ? ('Correos encolados: ' . $queued) : 'Carta generada',
      (string) $document['url']
    );
    return $document + ['queued' => $queued, 'recipients' => count($recipients), 'stage' => $stage];
  }

  /** @param array<int,array<int,mixed>> $rows @return array{accounts:array<string,array<string,mixed>>,source_date:string} */
  private function parseAuxiliary(array $rows): array
  {
    if ($rows === []) {
      return ['accounts' => [], 'source_date' => ''];
    }
    $headers = array_map(fn($value): string => $this->headerKey($value), array_values((array) ($rows[0] ?? [])));
    $indices = [];
    foreach ($headers as $index => $header) {
      $indices[$header] = $index;
    }
    foreach (['nit', 'fecha', 'detalle', 'saldocontable', 'noinm'] as $required) {
      if (!array_key_exists($required, $indices)) {
        throw new \RuntimeException('El auxiliar no contiene la columna requerida: ' . $required . '.');
      }
    }
    $accounts = [];
    $currentProperty = '';
    $currentDocument = '';
    $sourceDate = '';
    foreach (array_slice($rows, 1) as $row) {
      $row = array_values((array) $row);
      $joined = trim(implode(' ', array_map('strval', array_slice($row, 0, 3))));
      if (preg_match('/No\.?\s*Inm\s*:\s*([0-9]+)/i', $joined, $match) === 1) {
        $currentProperty = $this->digits($match[1]);
      }
      if (preg_match('/Nit\s*:\s*([0-9.\-]+)/i', $joined, $match) === 1) {
        $currentDocument = $this->digits($match[1]);
      }
      $property = $this->digits($row[$indices['noinm']] ?? '') ?: $currentProperty;
      $document = $this->digits($row[$indices['nit']] ?? '') ?: $currentDocument;
      $rawBalance = trim((string) ($row[$indices['saldocontable']] ?? ''));
      $date = $this->spreadsheetDate((string) ($row[$indices['fecha']] ?? ''));
      if ($date !== '' && ($sourceDate === '' || strcmp($date, $sourceDate) > 0)) {
        $sourceDate = $date;
      }
      if ($property === '' || $document === '' || $rawBalance === '') {
        continue;
      }
      $key = $property . '|' . $document;
      $detail = trim((string) ($row[$indices['detalle']] ?? ''));
      $accounts[$key] = [
        'property_code' => $property,
        'tenant_document' => $document,
        'balance' => $this->money($rawBalance),
        'last_date' => $date !== '' ? $date : (string) ($accounts[$key]['last_date'] ?? ''),
        'source_detail' => $detail !== '' ? mb_substr($detail, 0, 500, 'UTF-8') : (string) ($accounts[$key]['source_detail'] ?? ''),
      ];
    }
    return ['accounts' => $accounts, 'source_date' => $sourceDate];
  }

  /** @return array<int,array<string,mixed>> */
  private function activeContracts(): array
  {
    $table = $this->contractsTable();
    if (!$this->schema->tableExists($table)) {
      throw new \RuntimeException('La tabla de contratos de arrendamiento no está disponible.');
    }
    $columns = ['_ID'];
    foreach ([
      'estado', 'contrato', 'contrato_arrendamiento', 'inmueble', 'id_inmueble', 'id_inmueble_data', 'direccion',
      'id_arrendatario', 'documento_arrendatario', 'arrendatario', 'correo_arrendatario', 'celular_arrendatario',
      'id_propietario', 'propietario', 'correo_propietario', 'celular_propietario', 'esta_sinestrado'
    ] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $columns[] = $column;
      }
    }
    return $this->db->getResults(
      'SELECT `' . implode('`, `', array_values(array_unique($columns))) . "` FROM `{$table}` WHERE LOWER(TRIM(COALESCE(`estado`, ''))) = 'entregado' ORDER BY `_ID` DESC"
    );
  }

  /** @param array<string,mixed> $contract @return string[] */
  private function contractPropertyCandidates(array $contract): array
  {
    $property = $this->digits($contract['inmueble'] ?? '');
    return $property !== '' ? [$property] : [];
  }

  /** @param array<string,mixed> $data */
  private function upsertPortfolio(string $accountKey, array $data, int $importId): void
  {
    $existing = $this->db->getRow("SELECT * FROM `{$this->portfolioTable()}` WHERE `account_key` = ? LIMIT 1", [$accountKey]);
    $now = date('Y-m-d H:i:s');
    $oldBalance = is_array($existing) && $existing['balance'] !== null ? (float) $existing['balance'] : null;
    $oldStatus = is_array($existing) ? (string) ($existing['status'] ?? '') : '';
    $newBalance = $data['balance'] !== null ? (float) $data['balance'] : null;
    $newStatus = (string) ($data['status'] ?? 'sin_dato');
    $payload = $data + ['account_key' => $accountKey];
    $payload['previous_balance'] = $oldBalance;
    $payload['updated_at'] = $now;
    if (!is_array($existing)) {
      $payload['collection_stage'] = 'normal';
      $payload['created_at'] = $now;
      $payload['paid_at'] = null;
      $this->db->insert($this->portfolioTable(), $payload);
      $portfolioId = (int) $this->db->lastInsertId();
      $this->addEvent($portfolioId, $importId, $newStatus === 'deuda' ? 'debt_detected' : 'imported', null, $newBalance, 'Cuenta incorporada desde el auxiliar 1380.');
      return;
    }

    $portfolioId = (int) ($existing['id'] ?? 0);
    if ($oldStatus === 'deuda' && in_array($newStatus, ['al_dia', 'saldo_favor'], true)) {
      $payload['paid_at'] = $now;
      $payload['collection_stage'] = 'normal';
      $payload['last_action_type'] = 'pago_detectado';
      $payload['last_action_at'] = $now;
      if ((int) ($existing['contract_id'] ?? 0) > 0 && $this->schema->columnExists($this->contractsTable(), 'esta_sinestrado')) {
        $this->db->update($this->contractsTable(), ['esta_sinestrado' => 'No', 'cct_modified' => $now], ['_ID' => (int) $existing['contract_id']]);
      }
      $this->addEvent($portfolioId, $importId, 'payment_received', $oldBalance, $newBalance, 'El nuevo auxiliar ya no reporta saldo positivo pendiente.');
    } elseif ($newStatus === 'deuda' && $oldStatus !== 'deuda') {
      $payload['paid_at'] = null;
      $this->addEvent($portfolioId, $importId, 'debt_detected', $oldBalance, $newBalance, 'El nuevo auxiliar reporta saldo pendiente.');
    } elseif ($oldBalance !== $newBalance || $oldStatus !== $newStatus) {
      $this->addEvent($portfolioId, $importId, 'balance_updated', $oldBalance, $newBalance, 'Saldo actualizado con el nuevo auxiliar.');
    }
    $this->db->update($this->portfolioTable(), $payload, ['id' => $portfolioId]);
  }

  /** @return array<int,array<string,string>> */
  private function codeudoresForItem(array $item): array
  {
    $contractId = (int) ($item['contract_id'] ?? 0);
    if ($contractId <= 0) {
      return [];
    }
    $table = $this->db->table('jet_cct_codeudores');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_contrato')) {
      return [];
    }
    $select = ['_ID', 'id_contrato'];
    foreach (['nombre', 'documento', 'correo', 'celular', 'direccion'] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = $column;
      }
    }
    $rows = $this->db->getResults(
      'SELECT `' . implode('`, `', $select) . "` FROM `{$table}` WHERE CAST(`id_contrato` AS UNSIGNED) = ? ORDER BY `_ID` ASC",
      [$contractId]
    );
    return array_map(static fn(array $row): array => [
      'nombre' => trim((string) ($row['nombre'] ?? '')),
      'documento' => trim((string) ($row['documento'] ?? '')),
      'correo' => trim((string) ($row['correo'] ?? '')),
      'celular' => trim((string) ($row['celular'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
    ], $rows);
  }

  private function addEvent(int $portfolioId, ?int $importId, string $eventType, mixed $previousBalance, mixed $balance, string $notes, string $documentUrl = ''): void
  {
    $this->db->insert($this->eventsTable(), [
      'portfolio_id' => $portfolioId,
      'import_id' => $importId,
      'event_type' => mb_substr($eventType, 0, 80, 'UTF-8'),
      'previous_balance' => $previousBalance,
      'balance' => $balance,
      'notes' => mb_substr(trim($notes), 0, 2000, 'UTF-8'),
      'document_url' => $documentUrl,
      'created_by_id' => Auth::userId(),
      'created_by_name' => mb_substr(Auth::user(), 0, 190, 'UTF-8'),
      'created_at' => date('Y-m-d H:i:s'),
    ]);
  }

  private function headerKey(mixed $value): string
  {
    $text = mb_strtolower(trim((string) $value), 'UTF-8');
    $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    return preg_replace('/[^a-z0-9]+/', '', $text) ?: '';
  }

  private function digits(mixed $value): string
  {
    return preg_replace('/\D+/', '', trim((string) $value)) ?: '';
  }

  private function money(mixed $value): float
  {
    $text = trim((string) $value);
    if ($text === '' || preg_match('/\d/', $text) !== 1) {
      return 0.0;
    }
    $negative = str_contains($text, '(') && str_contains($text, ')');
    $text = preg_replace('/[^0-9,\.\-]/', '', $text) ?: '0';
    $lastComma = strrpos($text, ',');
    $lastDot = strrpos($text, '.');
    if ($lastComma !== false && $lastDot !== false) {
      if ($lastComma > $lastDot) {
        $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
      } else {
        $text = str_replace(',', '', $text);
      }
    } elseif ($lastComma !== false) {
      $decimals = strlen($text) - $lastComma - 1;
      $text = $decimals === 2 ? str_replace(',', '.', $text) : str_replace(',', '', $text);
    } elseif ($lastDot !== false) {
      $decimals = strlen($text) - $lastDot - 1;
      if ($decimals !== 2) {
        $text = str_replace('.', '', $text);
      }
    }
    $number = is_numeric($text) ? (float) $text : 0.0;
    return $negative ? -abs($number) : $number;
  }

  private function spreadsheetDate(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
      if ($date instanceof \DateTimeImmutable) {
        return $date->format('Y-m-d');
      }
    }
    return '';
  }

  private function ensureSchema(): void
  {
    $charset = 'utf8mb4';
    $collation = 'utf8mb4_unicode_ci';
    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->importsTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `source_filename` VARCHAR(255) NOT NULL,
      `source_hash` CHAR(64) NOT NULL,
      `source_date` DATE NULL,
      `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `total_accounts` INT UNSIGNED NOT NULL DEFAULT 0,
      `active_contracts` INT UNSIGNED NOT NULL DEFAULT 0,
      `matched_contracts` INT UNSIGNED NOT NULL DEFAULT 0,
      `debtors` INT UNSIGNED NOT NULL DEFAULT 0,
      `current_accounts` INT UNSIGNED NOT NULL DEFAULT 0,
      `unmatched_contracts` INT UNSIGNED NOT NULL DEFAULT 0,
      `unmatched_accounts` INT UNSIGNED NOT NULL DEFAULT 0,
      `total_balance` DECIMAL(18,2) NOT NULL DEFAULT 0,
      `uploaded_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
      `uploaded_by_name` VARCHAR(190) NOT NULL DEFAULT '',
      `uploaded_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_source_hash` (`source_hash`),
      KEY `idx_uploaded_at` (`uploaded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->portfolioTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `account_key` VARCHAR(100) NOT NULL,
      `last_import_id` BIGINT UNSIGNED NULL,
      `contract_id` BIGINT UNSIGNED NULL,
      `contract_number` VARCHAR(120) NOT NULL DEFAULT '',
      `property_code` VARCHAR(80) NOT NULL DEFAULT '',
      `tenant_id` BIGINT UNSIGNED NULL,
      `tenant_document` VARCHAR(80) NOT NULL DEFAULT '',
      `tenant_name` VARCHAR(255) NOT NULL DEFAULT '',
      `tenant_email` VARCHAR(255) NOT NULL DEFAULT '',
      `tenant_phone` VARCHAR(80) NOT NULL DEFAULT '',
      `landlord_id` BIGINT UNSIGNED NULL,
      `landlord_name` VARCHAR(255) NOT NULL DEFAULT '',
      `landlord_email` VARCHAR(255) NOT NULL DEFAULT '',
      `landlord_phone` VARCHAR(80) NOT NULL DEFAULT '',
      `property_address` VARCHAR(500) NOT NULL DEFAULT '',
      `balance` DECIMAL(18,2) NULL,
      `previous_balance` DECIMAL(18,2) NULL,
      `status` VARCHAR(30) NOT NULL DEFAULT 'sin_dato',
      `match_status` VARCHAR(30) NOT NULL DEFAULT 'sin_archivo',
      `collection_stage` VARCHAR(30) NOT NULL DEFAULT 'normal',
      `source_detail` VARCHAR(500) NOT NULL DEFAULT '',
      `source_date` DATE NULL,
      `paid_at` DATETIME NULL,
      `stage_changed_at` DATETIME NULL,
      `stage_changed_by` VARCHAR(190) NOT NULL DEFAULT '',
      `last_action_type` VARCHAR(80) NOT NULL DEFAULT '',
      `last_action_at` DATETIME NULL,
      `notes` TEXT NULL,
      `is_current` TINYINT(1) NOT NULL DEFAULT 1,
      `last_imported_at` DATETIME NOT NULL,
      `created_at` DATETIME NOT NULL,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_account_key` (`account_key`),
      KEY `idx_current_status` (`is_current`, `status`),
      KEY `idx_stage` (`collection_stage`),
      KEY `idx_contract` (`contract_id`),
      KEY `idx_property_document` (`property_code`, `tenant_document`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");

    $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS `{$this->eventsTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `portfolio_id` BIGINT UNSIGNED NOT NULL,
      `import_id` BIGINT UNSIGNED NULL,
      `event_type` VARCHAR(80) NOT NULL,
      `previous_balance` DECIMAL(18,2) NULL,
      `balance` DECIMAL(18,2) NULL,
      `notes` TEXT NULL,
      `document_url` TEXT NULL,
      `created_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
      `created_by_name` VARCHAR(190) NOT NULL DEFAULT '',
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_portfolio_created` (`portfolio_id`, `created_at`),
      KEY `idx_import_event` (`import_id`, `event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
  }

  private function uploadErrorMessage(int $error): string
  {
    return match ($error) {
      UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El auxiliar de cartera supera el tamaño máximo permitido.',
      UPLOAD_ERR_PARTIAL => 'El auxiliar de cartera se subió parcialmente. Intenta cargarlo de nuevo.',
      UPLOAD_ERR_NO_FILE => 'No se recibió el archivo del auxiliar de cartera.',
      UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene disponible la carpeta temporal para recibir el archivo.',
      UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo guardar temporalmente el auxiliar de cartera.',
      UPLOAD_ERR_EXTENSION => 'Una extensión del servidor detuvo la carga del auxiliar de cartera.',
      default => 'No se pudo subir el auxiliar de cartera.',
    };
  }

  private function importsTable(): string { return $this->db->table('scm_collection_imports'); }
  private function portfolioTable(): string { return $this->db->table('scm_collection_portfolio'); }
  private function eventsTable(): string { return $this->db->table('scm_collection_events'); }
  private function contractsTable(): string { return $this->db->table('jet_cct_contratos_arrendamiento'); }
}
