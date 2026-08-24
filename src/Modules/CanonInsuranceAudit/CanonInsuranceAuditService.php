<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

use PDO;
use SCM\Core\Auth;
use SCM\Core\Database;

final class CanonInsuranceAuditService
{
  private const MONEY_TOLERANCE = 1.0;

  private Database $db;
  private SpreadsheetReader $reader;
  /** @var array<string,array{id:string,name:string}> */
  private array $employeeIdentityCache = [];

  public function __construct(Database $db, ?SpreadsheetReader $reader = null)
  {
    $this->db = $db;
    $this->reader = $reader ?? new SpreadsheetReader();
  }

  /** @param array<string,mixed> $file @return array<string,mixed> */
  public function import(array $file, string $period): array
  {
    $period = $this->validPeriod($period);
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      throw new \RuntimeException('No se pudo subir el archivo.');
    }
    $path = (string) ($file['tmp_name'] ?? '');
    $name = basename(AuditValueNormalizer::text($file['name'] ?? 'extracto.xls'));
    $size = (int) ($file['size'] ?? 0);
    $maxBytes = defined('SCM_UPLOAD_MAX_BYTES') ? (int) SCM_UPLOAD_MAX_BYTES : 10485760;
    if ($size <= 0 || $size > $maxBytes) {
      throw new \RuntimeException('El archivo esta vacio o supera el limite permitido.');
    }
    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xls', 'xlsx'], true)) {
      throw new \RuntimeException('Formato no soportado. Sube un archivo .xls o .xlsx.');
    }

    $this->ensureSchema();
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) {
      throw new \RuntimeException('No se pudo verificar el archivo subido.');
    }
    $existingId = (int) ($this->db->getVar(
      "SELECT `id` FROM `{$this->auditsTable()}` WHERE `period` = ? AND `source_hash` = ? LIMIT 1",
      [$period, $hash]
    ) ?? 0);
    if ($existingId > 0) {
      return ['audit_id' => $existingId, 'duplicate' => true] + $this->dashboard(['audit_id' => (string) $existingId]);
    }

    $rows = $this->reader->read($path, $name);
    $parsed = $this->parseSpreadsheet($rows);
    $contracts = $this->contractsByRequestNumber();
    $items = [];
    $insurerCounts = [];
    foreach ((array) ($parsed['records'] ?? []) as $record) {
      $item = $this->compareRecord($record, $contracts, (string) ($parsed['insurer'] ?? ''));
      $items[] = $item;
      $insurer = AuditValueNormalizer::text($item['insurer'] ?? '');
      if ($insurer !== '') {
        $insurerCounts[$insurer] = ($insurerCounts[$insurer] ?? 0) + 1;
      }
    }
    if ($items === []) {
      throw new \RuntimeException('No se encontraron filas de contratos para auditar.');
    }
    arsort($insurerCounts);
    $insurer = AuditValueNormalizer::text($parsed['insurer'] ?? '');
    if ($insurer === '') {
      $insurer = (string) (array_key_first($insurerCounts) ?? 'No identificada');
    }

    $stats = $this->summarize($items);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->insert($this->auditsTable(), [
        'period' => $period,
        'source_filename' => mb_substr($name, 0, 255),
        'source_hash' => $hash,
        'insurer' => mb_substr($insurer, 0, 160),
        'format_key' => (string) ($parsed['format_key'] ?? 'auto'),
        'total_rows' => $stats['total'],
        'matched_rows' => $stats['matched'],
        'compliant_rows' => $stats['compliant'],
        'difference_rows' => $stats['differences'],
        'unmatched_rows' => $stats['unmatched'],
        'incomplete_rows' => $stats['incomplete'],
        'uploaded_by_id' => Auth::userId(),
        'uploaded_by_name' => mb_substr(Auth::user(), 0, 190),
        'uploaded_at' => date('Y-m-d H:i:s'),
      ]);
      $auditId = (int) $this->db->lastInsertId();
      foreach ($items as $item) {
        $item['audit_id'] = $auditId;
        $this->db->insert($this->itemsTable(), $item);
      }
      $pdo->commit();
    } catch (\Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    return ['audit_id' => $auditId, 'duplicate' => false] + $this->dashboard(['audit_id' => (string) $auditId]);
  }

  /** @param array<string,string> $filters @return array<string,mixed> */
  public function dashboard(array $filters = []): array
  {
    $this->ensureSchema();
    $this->syncIncrementChanges();

    $audits = $this->db->getResults(
      "SELECT * FROM `{$this->auditsTable()}` ORDER BY `uploaded_at` DESC, `id` DESC LIMIT 36"
    );
    $auditId = max(0, (int) ($filters['audit_id'] ?? 0));
    $period = trim((string) ($filters['period'] ?? ''));
    if ($auditId <= 0 && $period !== '' && preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
      foreach ($audits as $audit) {
        if ((string) ($audit['period'] ?? '') === $period) {
          $auditId = (int) ($audit['id'] ?? 0);
          break;
        }
      }
    }
    if ($auditId <= 0) {
      $auditId = (int) ($audits[0]['id'] ?? 0);
    }

    $selectedAudit = null;
    foreach ($audits as $audit) {
      if ((int) ($audit['id'] ?? 0) === $auditId) {
        $selectedAudit = $audit;
        break;
      }
    }

    $items = [];
    if ($auditId > 0) {
      $where = ['`audit_id` = ?'];
      $args = [$auditId];
      $status = $this->sanitizeKey((string) ($filters['status'] ?? ''));
      if (in_array($status, ['coincide', 'diferencia', 'sin_contrato', 'sin_mandato', 'datos_incompletos'], true)) {
        $where[] = '`status` = ?';
        $args[] = $status;
      }
      $search = AuditValueNormalizer::text($filters['search'] ?? '');
      if ($search !== '') {
        $like = '%' . $this->db->escapeLike($search) . '%';
        $where[] = "(`request_number` LIKE ? OR `contract_number` LIKE ? OR `tenant` LIKE ? OR `property_address` LIKE ?)";
        array_push($args, $like, $like, $like, $like);
      }
      $items = $this->db->getResults(
        "SELECT * FROM `{$this->itemsTable()}` WHERE " . implode(' AND ', $where)
          . " ORDER BY FIELD(`status`, 'diferencia', 'datos_incompletos', 'sin_mandato', 'sin_contrato', 'coincide'), `source_row` ASC LIMIT 500",
        $args
      );
    }

    $incrementChanges = $this->db->getResults(
      "SELECT * FROM `{$this->incrementChangesTable()}` ORDER BY `changed_at` DESC, `id` DESC LIMIT 100"
    );
    foreach ($incrementChanges as &$incrementChange) {
      $identity = $this->employeeIdentity((string) ($incrementChange['changed_by_id'] ?? ''));
      $incrementChange['changed_by_id'] = $identity['id'];
      if ($identity['name'] !== '') {
        $incrementChange['changed_by_name'] = $identity['name'];
      }
    }
    unset($incrementChange);

    return [
      'audits' => $audits,
      'selected_audit' => $selectedAudit,
      'items' => $items,
      'increment_changes' => $incrementChanges,
      'filters' => [
        'audit_id' => $auditId,
        'status' => $this->sanitizeKey((string) ($filters['status'] ?? '')),
        'search' => AuditValueNormalizer::text($filters['search'] ?? ''),
      ],
    ];
  }

  public function syncIncrementChanges(): void
  {
    $tracked = $this->incrementFields();
    $columns = implode(', ', array_map(static fn(string $field): string => "`{$field}`", array_keys($tracked)));
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $rows = $this->db->getResults(
      "SELECT `_ID`, `contrato`, `cct_author_id`, `cct_modified`, {$columns} FROM `{$contractTable}` ORDER BY `_ID` ASC"
    );
    $snapshotRows = $this->db->getResults("SELECT * FROM `{$this->incrementSnapshotsTable()}`");
    $snapshots = [];
    foreach ($snapshotRows as $snapshot) {
      $snapshots[(int) ($snapshot['contract_id'] ?? 0)] = $snapshot;
    }

    foreach ($rows as $row) {
      $contractId = (int) ($row['_ID'] ?? 0);
      if ($contractId <= 0) {
        continue;
      }
      $current = [];
      $hasValue = false;
      foreach ($tracked as $field => $label) {
        $current[$field] = AuditValueNormalizer::text($row[$field] ?? '');
        $hasValue = $hasValue || $current[$field] !== '';
      }
      $currentJson = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
      $previousRow = $snapshots[$contractId] ?? null;
      if (!is_array($previousRow) && !$hasValue) {
        continue;
      }
      $previous = [];
      if (is_array($previousRow)) {
        $decoded = json_decode((string) ($previousRow['snapshot_json'] ?? '{}'), true);
        $previous = is_array($decoded) ? $decoded : [];
        if (hash_equals((string) ($previousRow['snapshot_hash'] ?? ''), hash('sha256', $currentJson))) {
          continue;
        }
      }

      $authorId = AuditValueNormalizer::text($row['cct_author_id'] ?? '');
      $authorIdentity = $this->employeeIdentity($authorId);
      $authorName = $authorIdentity['name'] !== '' ? $authorIdentity['name'] : 'No identificado';
      $changedAt = AuditValueNormalizer::text($row['cct_modified'] ?? '');
      if ($changedAt === '') {
        $changedAt = date('Y-m-d H:i:s');
      }
      foreach ($tracked as $field => $label) {
        $oldValue = AuditValueNormalizer::text($previous[$field] ?? '');
        $newValue = (string) $current[$field];
        if (is_array($previousRow) && $oldValue === $newValue) {
          continue;
        }
        if (!is_array($previousRow) && $newValue === '') {
          continue;
        }
        $fingerprint = hash('sha256', implode('|', [$contractId, $field, $oldValue, $newValue, $changedAt]));
        try {
          $this->db->insert($this->incrementChangesTable(), [
            'fingerprint' => $fingerprint,
            'contract_id' => $contractId,
            'contract_number' => AuditValueNormalizer::text($row['contrato'] ?? ''),
            'field_name' => $field,
            'field_label' => $label,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'changed_by_id' => $authorId,
            'changed_by_name' => $authorName,
            'changed_at' => $changedAt,
            'detected_at' => date('Y-m-d H:i:s'),
            'source_location' => 'jet_cct_contratos_arrendamiento.' . $field,
          ]);
        } catch (\Throwable $exception) {
          if (!str_contains(mb_strtolower($exception->getMessage()), 'duplicate')) {
            throw $exception;
          }
        }
      }

      $snapshotData = [
        'snapshot_hash' => hash('sha256', $currentJson),
        'snapshot_json' => $currentJson,
        'source_modified' => $changedAt,
        'updated_at' => date('Y-m-d H:i:s'),
      ];
      if (is_array($previousRow)) {
        $this->db->update($this->incrementSnapshotsTable(), $snapshotData, ['contract_id' => $contractId]);
      } else {
        $this->db->insert($this->incrementSnapshotsTable(), ['contract_id' => $contractId] + $snapshotData);
      }
    }
  }

  /** @param array<int,array<int,mixed>> $rows @return array<string,mixed> */
  private function parseSpreadsheet(array $rows): array
  {
    $aliases = $this->headerAliases();
    $best = ['score' => 0, 'row' => -1, 'columns' => []];
    foreach (array_slice($rows, 0, 25, true) as $rowIndex => $row) {
      $columns = [];
      foreach ($row as $columnIndex => $value) {
        $key = AuditValueNormalizer::key($value);
        foreach ($aliases as $canonical => $names) {
          if ($key !== '' && in_array($key, $names, true) && !isset($columns[$canonical])) {
            $columns[$canonical] = (int) $columnIndex;
          }
        }
      }
      $score = (isset($columns['request_number']) ? 5 : 0)
        + (isset($columns['canon']) ? 2 : 0)
        + (isset($columns['premium_canon']) ? 3 : 0)
        + (isset($columns['tenant']) ? 1 : 0);
      if ($score > $best['score']) {
        $best = ['score' => $score, 'row' => (int) $rowIndex, 'columns' => $columns];
      }
    }
    if ($best['score'] < 8 || $best['row'] < 0) {
      throw new \RuntimeException('No se reconocieron las columnas de solicitud, canon y prima del extracto.');
    }

    /** @var array<string,int> $columns */
    $columns = $best['columns'];
    $headerIndex = (int) $best['row'];
    if (isset($columns['request_number'])) {
      $candidate = $columns['request_number'];
      $bestRequestColumn = $candidate;
      $bestRequestScore = -1;
      foreach ([$candidate, $candidate + 1, $candidate + 2] as $requestColumn) {
        $score = 0;
        for ($sampleIndex = $headerIndex + 1; $sampleIndex < min(count($rows), $headerIndex + 12); $sampleIndex++) {
          $requestValue = AuditValueNormalizer::requestNumber($rows[$sampleIndex][$requestColumn] ?? '');
          if ($requestValue !== '' && preg_match('/^(?:[A-Z]{0,4})?\d{5,}$/', $requestValue) === 1) {
            $score++;
          }
        }
        if ($score > $bestRequestScore) {
          $bestRequestScore = $score;
          $bestRequestColumn = $requestColumn;
        }
      }
      $columns['request_number'] = $bestRequestColumn;
    }

    $insurer = '';
    foreach (array_slice($rows, 0, 15) as $row) {
      foreach ($row as $value) {
        $text = AuditValueNormalizer::text($value);
        if (preg_match('/aseguradora\s*:\s*(.+)$/iu', $text, $match) === 1) {
          $insurer = trim($match[1]);
        }
      }
    }

    $records = [];
    for ($rowIndex = $headerIndex + 1, $total = count($rows); $rowIndex < $total; $rowIndex++) {
      $row = $rows[$rowIndex];
      $request = AuditValueNormalizer::requestNumber($row[$columns['request_number']] ?? '');
      $oldRequest = isset($columns['old_request_number'])
        ? AuditValueNormalizer::requestNumber($row[$columns['old_request_number']] ?? '')
        : '';
      if ($request === '' || $request === '0' || preg_match('/^(?:[A-Z]{0,4})?\d{5,}$/', $request) !== 1) {
        continue;
      }
      $records[] = [
        'source_row' => $rowIndex + 1,
        'request_number' => $request,
        'old_request_number' => $oldRequest !== '0' ? $oldRequest : '',
        'tenant' => $this->columnText($row, $columns, 'tenant'),
        'property_address' => $this->columnText($row, $columns, 'property_address'),
        'excel_canon' => $this->columnMoney($row, $columns, 'canon'),
        'excel_administration' => $this->columnMoney($row, $columns, 'administration'),
        'excel_premium' => $this->sumColumns($row, $columns, ['premium_canon', 'premium_administration', 'premium_rent_vat']),
        'excel_service_premium' => $this->columnMoney($row, $columns, 'premium_services'),
        'excel_service_coverage' => $this->columnMoney($row, $columns, 'services_coverage'),
      ];
    }

    $formatKey = isset($columns['old_request_number']) ? 'fianza_detallada'
      : (isset($columns['total_insurance']) ? 'el_libertador' : 'extracto_fianzas');
    return ['records' => $records, 'insurer' => $insurer, 'format_key' => $formatKey];
  }

  /** @return array<string,array<int,string>> */
  private function headerAliases(): array
  {
    return [
      'request_number' => ['nosolicitud', 'numerosolicitud'],
      'old_request_number' => ['numerosolicitudantiguo', 'solicitudantigua'],
      'tenant' => ['arrendatario'],
      'property_address' => ['inmueble', 'direccion'],
      'canon' => ['canon'],
      'administration' => ['admon', 'admonph', 'administracion'],
      'premium_canon' => ['fianzacanon', 'primacanon'],
      'premium_administration' => ['fianzaadmon', 'primaadmonph', 'primaadministracion'],
      'premium_rent_vat' => ['fianzaivacomercial', 'primaivaarre', 'primaivaarrendamiento'],
      'premium_services' => ['fianzaservicios', 'primaservicios'],
      'services_coverage' => ['servicios'],
      'total_insurance' => ['totalseguro', 'grantotal'],
    ];
  }

  /** @return array<string,array<string,mixed>> */
  private function contractsByRequestNumber(): array
  {
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $rows = $this->db->getResults(
      "SELECT c.`_ID` AS contract_id, c.`contrato` AS contract_number, c.`numero_solicitud`,
              c.`arrendatario`, c.`direccion`, c.`id_contrato_mandato`, c.`valor_canon`,
              c.`valor_administracion`, c.`aseguradora` AS contract_insurer,
              m.`_ID` AS mandate_id, m.`aseguradora` AS mandate_insurer,
              m.`precio`, m.`administracion`, m.`precio_mes_garantia`,
              m.`precio_mes_servicios`, m.`precio_anual_servicios`,
              m.`tasa_servicios`, m.`pago_servicios`
         FROM `{$contracts}` c
         LEFT JOIN `{$mandates}` m ON CAST(m.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_contrato_mandato`, ''))
        WHERE TRIM(COALESCE(c.`numero_solicitud`, '')) <> ''
        ORDER BY (TRIM(COALESCE(c.`id_contrato_mandato`, '')) <> '') DESC,
                 (LOWER(TRIM(COALESCE(c.`contrato`, ''))) <> 'no asignado') DESC,
                 c.`_ID` DESC"
    );
    $map = [];
    foreach ($rows as $row) {
      $key = AuditValueNormalizer::requestNumber($row['numero_solicitud'] ?? '');
      if ($key !== '' && !isset($map[$key])) {
        $map[$key] = $row;
      }
    }
    return $map;
  }

  /** @param array<string,mixed> $record @param array<string,array<string,mixed>> $contracts @return array<string,mixed> */
  private function compareRecord(array $record, array $contracts, string $fileInsurer): array
  {
    $request = (string) ($record['request_number'] ?? '');
    $contract = $contracts[$request] ?? null;
    if (!is_array($contract) && preg_match('/^[A-Z]+(\d+)$/', $request, $match) === 1) {
      $contract = $contracts[$match[1]] ?? null;
    }
    if (!is_array($contract)) {
      $oldRequest = (string) ($record['old_request_number'] ?? '');
      $contract = $oldRequest !== '' ? ($contracts[$oldRequest] ?? null) : null;
    }

    $base = [
      'source_row' => (int) ($record['source_row'] ?? 0),
      'request_number' => $request,
      'insurer' => $fileInsurer,
      'tenant' => (string) ($record['tenant'] ?? ''),
      'property_address' => (string) ($record['property_address'] ?? ''),
      'contract_id' => null,
      'contract_number' => '',
      'mandate_id' => null,
      'status' => 'sin_contrato',
      'excel_canon' => $record['excel_canon'],
      'system_canon' => null,
      'difference_canon' => null,
      'excel_administration' => $record['excel_administration'],
      'system_administration' => null,
      'difference_administration' => null,
      'excel_premium' => $record['excel_premium'],
      'system_premium' => null,
      'difference_premium' => null,
      'excel_service_premium' => $record['excel_service_premium'],
      'system_service_premium' => null,
      'difference_service_premium' => null,
      'excel_service_coverage' => $record['excel_service_coverage'],
      'system_service_coverage' => null,
      'difference_service_coverage' => null,
      'differences_json' => json_encode(['No se encontro la solicitud en contratos de arrendamiento.'], JSON_UNESCAPED_UNICODE),
    ];
    if (!is_array($contract)) {
      return $base;
    }

    $base['contract_id'] = (int) ($contract['contract_id'] ?? 0) ?: null;
    $base['contract_number'] = AuditValueNormalizer::text($contract['contract_number'] ?? '');
    $base['mandate_id'] = (int) ($contract['mandate_id'] ?? 0) ?: null;
    $base['tenant'] = AuditValueNormalizer::text($contract['arrendatario'] ?? '') ?: $base['tenant'];
    $base['property_address'] = AuditValueNormalizer::text($contract['direccion'] ?? '') ?: $base['property_address'];
    $base['insurer'] = $this->firstNonEmptyText([
      $contract['contract_insurer'] ?? null,
      $contract['mandate_insurer'] ?? null,
      $fileInsurer,
    ]);

    $base['system_canon'] = AuditValueNormalizer::money($contract['valor_canon'] ?? null)
      ?? AuditValueNormalizer::money($contract['precio'] ?? null);
    $base['system_administration'] = AuditValueNormalizer::money($contract['valor_administracion'] ?? null)
      ?? AuditValueNormalizer::money($contract['administracion'] ?? null);
    $base['system_premium'] = AuditValueNormalizer::money($contract['precio_mes_garantia'] ?? null);
    $base['system_service_premium'] = AuditValueNormalizer::money($contract['precio_mes_servicios'] ?? null);
    $annualServicePremium = AuditValueNormalizer::money($contract['precio_anual_servicios'] ?? null);
    $serviceRate = AuditValueNormalizer::money($contract['tasa_servicios'] ?? null);
    $payments = AuditValueNormalizer::money($contract['pago_servicios'] ?? null);
    if ($annualServicePremium !== null && $serviceRate !== null && $serviceRate > 0) {
      $base['system_service_coverage'] = round($annualServicePremium * 100 / $serviceRate, 2);
    } elseif ($base['system_service_premium'] !== null && $payments !== null && $serviceRate !== null && $serviceRate > 0) {
      $base['system_service_coverage'] = round($base['system_service_premium'] * $payments * 100 / $serviceRate, 2);
    }

    if ($base['mandate_id'] === null) {
      $base['status'] = 'sin_mandato';
      $base['differences_json'] = json_encode(['El contrato no tiene un mandato asociado.'], JSON_UNESCAPED_UNICODE);
      return $base;
    }

    $differences = [];
    $missing = false;
    $assessed = 0;
    foreach ([
      ['Canon', 'excel_canon', 'system_canon', 'difference_canon'],
      ['Administracion', 'excel_administration', 'system_administration', 'difference_administration'],
      ['Prima del seguro', 'excel_premium', 'system_premium', 'difference_premium'],
      ['Prima de servicios publicos', 'excel_service_premium', 'system_service_premium', 'difference_service_premium'],
      ['Cobertura de servicios publicos', 'excel_service_coverage', 'system_service_coverage', 'difference_service_coverage'],
    ] as [$label, $excelKey, $systemKey, $differenceKey]) {
      $excelValue = $base[$excelKey];
      if ($excelValue === null) {
        continue;
      }
      $assessed++;
      $systemValue = $base[$systemKey];
      if ($systemValue === null) {
        $missing = true;
        $differences[] = $label . ': falta el valor en la pagina.';
        continue;
      }
      $difference = round((float) $excelValue - (float) $systemValue, 2);
      $base[$differenceKey] = $difference;
      if (abs($difference) > self::MONEY_TOLERANCE) {
        $differences[] = $label . ': diferencia de $' . number_format($difference, 2, ',', '.');
      }
    }

    if ($assessed === 0 || $missing) {
      $base['status'] = 'datos_incompletos';
    } elseif ($differences !== []) {
      $base['status'] = 'diferencia';
    } else {
      $base['status'] = 'coincide';
      $differences[] = 'Todos los valores disponibles coinciden.';
    }
    $base['differences_json'] = json_encode($differences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    return $base;
  }

  /** @param array<int, mixed> $values */
  private function firstNonEmptyText(array $values): string
  {
    foreach ($values as $value) {
      $text = AuditValueNormalizer::text($value);
      if ($text !== '') {
        return $text;
      }
    }

    return '';
  }

  /** @param array<int,array<string,mixed>> $items @return array<string,int> */
  private function summarize(array $items): array
  {
    $stats = ['total' => count($items), 'matched' => 0, 'compliant' => 0, 'differences' => 0, 'unmatched' => 0, 'incomplete' => 0];
    foreach ($items as $item) {
      $status = (string) ($item['status'] ?? '');
      if (!in_array($status, ['sin_contrato'], true)) {
        $stats['matched']++;
      }
      if ($status === 'coincide') {
        $stats['compliant']++;
      } elseif ($status === 'diferencia') {
        $stats['differences']++;
      } elseif ($status === 'sin_contrato') {
        $stats['unmatched']++;
      } else {
        $stats['incomplete']++;
      }
    }
    return $stats;
  }

  /** @param array<int,mixed> $row @param array<string,int> $columns */
  private function columnText(array $row, array $columns, string $key): string
  {
    return isset($columns[$key]) ? AuditValueNormalizer::text($row[$columns[$key]] ?? '') : '';
  }

  /** @param array<int,mixed> $row @param array<string,int> $columns */
  private function columnMoney(array $row, array $columns, string $key): ?float
  {
    return isset($columns[$key]) ? AuditValueNormalizer::money($row[$columns[$key]] ?? null) : null;
  }

  /** @param array<int,mixed> $row @param array<string,int> $columns @param array<int,string> $keys */
  private function sumColumns(array $row, array $columns, array $keys): ?float
  {
    $sum = 0.0;
    $found = false;
    foreach ($keys as $key) {
      $value = $this->columnMoney($row, $columns, $key);
      if ($value !== null) {
        $sum += $value;
        $found = true;
      }
    }
    return $found ? round($sum, 2) : null;
  }

  private function validPeriod(string $period): string
  {
    $period = trim($period);
    if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) !== 1
      || (int) $match[2] < 1 || (int) $match[2] > 12) {
      throw new \RuntimeException('Selecciona un periodo mensual valido.');
    }
    return $period;
  }

  private function sanitizeKey(string $value): string
  {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
  }

  /** @return array<string,string> */
  private function incrementFields(): array
  {
    return [
      'fecha_incremento_canon' => 'Fecha de incremento de canon',
      'porcentaje_incremento_canon' => 'Porcentaje de incremento de canon',
      'id_carta_aumento_canon' => 'Acta/carta de aumento de canon',
      'carta_aumento_canon' => 'Archivo de aumento de canon',
      'fecha_incremento_admin' => 'Fecha de incremento de administracion',
      'porcentaje_incremento_admin' => 'Porcentaje de incremento de administracion',
      'id_carta_aumento_admin' => 'Acta/carta de aumento de administracion',
      'carta_aumento_admin' => 'Archivo de aumento de administracion',
    ];
  }

  /** @return array{id:string,name:string} */
  private function employeeIdentity(string $internalId): array
  {
    if ($internalId === '') {
      return ['id' => '', 'name' => ''];
    }
    if (isset($this->employeeIdentityCache[$internalId])) {
      return $this->employeeIdentityCache[$internalId];
    }
    $table = $this->db->table('jet_cct_funcionarios');
    $row = $this->db->getRow(
      "SELECT TRIM(COALESCE(`id_empleado`, '')) AS id_empleado,
              TRIM(COALESCE(`nombre`, '')) AS nombre
         FROM `{$table}`
        WHERE CAST(`_ID` AS CHAR) = ?
        LIMIT 1",
      [$internalId]
    );
    $employeeId = AuditValueNormalizer::text($row['id_empleado'] ?? '');
    $name = AuditValueNormalizer::text($row['nombre'] ?? '');
    return $this->employeeIdentityCache[$internalId] = [
      'id' => $employeeId,
      'name' => $name,
    ];
  }

  private function ensureSchema(): void
  {
    $pdo = $this->db->pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->auditsTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `period` CHAR(7) NOT NULL,
      `source_filename` VARCHAR(255) NOT NULL,
      `source_hash` CHAR(64) NOT NULL,
      `insurer` VARCHAR(160) NOT NULL DEFAULT '',
      `format_key` VARCHAR(60) NOT NULL DEFAULT 'auto',
      `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `matched_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `compliant_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `difference_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `unmatched_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `incomplete_rows` INT UNSIGNED NOT NULL DEFAULT 0,
      `uploaded_by_id` BIGINT NOT NULL DEFAULT 0,
      `uploaded_by_name` VARCHAR(190) NOT NULL DEFAULT '',
      `uploaded_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_period_hash` (`period`, `source_hash`),
      KEY `idx_period_uploaded` (`period`, `uploaded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->itemsTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `audit_id` BIGINT UNSIGNED NOT NULL,
      `source_row` INT UNSIGNED NOT NULL DEFAULT 0,
      `request_number` VARCHAR(80) NOT NULL DEFAULT '',
      `insurer` VARCHAR(160) NOT NULL DEFAULT '',
      `tenant` VARCHAR(255) NOT NULL DEFAULT '',
      `property_address` VARCHAR(500) NOT NULL DEFAULT '',
      `contract_id` BIGINT NULL,
      `contract_number` VARCHAR(100) NOT NULL DEFAULT '',
      `mandate_id` BIGINT NULL,
      `status` VARCHAR(30) NOT NULL,
      `excel_canon` DECIMAL(18,2) NULL, `system_canon` DECIMAL(18,2) NULL, `difference_canon` DECIMAL(18,2) NULL,
      `excel_administration` DECIMAL(18,2) NULL, `system_administration` DECIMAL(18,2) NULL, `difference_administration` DECIMAL(18,2) NULL,
      `excel_premium` DECIMAL(18,2) NULL, `system_premium` DECIMAL(18,2) NULL, `difference_premium` DECIMAL(18,2) NULL,
      `excel_service_premium` DECIMAL(18,2) NULL, `system_service_premium` DECIMAL(18,2) NULL, `difference_service_premium` DECIMAL(18,2) NULL,
      `excel_service_coverage` DECIMAL(18,2) NULL, `system_service_coverage` DECIMAL(18,2) NULL, `difference_service_coverage` DECIMAL(18,2) NULL,
      `differences_json` LONGTEXT NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_audit_status` (`audit_id`, `status`),
      KEY `idx_request` (`request_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->incrementSnapshotsTable()}` (
      `contract_id` BIGINT NOT NULL,
      `snapshot_hash` CHAR(64) NOT NULL,
      `snapshot_json` LONGTEXT NOT NULL,
      `source_modified` DATETIME NULL,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`contract_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->incrementChangesTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `fingerprint` CHAR(64) NOT NULL,
      `contract_id` BIGINT NOT NULL,
      `contract_number` VARCHAR(100) NOT NULL DEFAULT '',
      `field_name` VARCHAR(100) NOT NULL,
      `field_label` VARCHAR(190) NOT NULL,
      `old_value` LONGTEXT NOT NULL,
      `new_value` LONGTEXT NOT NULL,
      `changed_by_id` VARCHAR(80) NOT NULL DEFAULT '',
      `changed_by_name` VARCHAR(190) NOT NULL DEFAULT '',
      `changed_at` DATETIME NOT NULL,
      `detected_at` DATETIME NOT NULL,
      `source_location` VARCHAR(255) NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_fingerprint` (`fingerprint`),
      KEY `idx_contract_changed` (`contract_id`, `changed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }

  private function auditsTable(): string { return $this->db->table('scm_canon_insurance_audits'); }
  private function itemsTable(): string { return $this->db->table('scm_canon_insurance_audit_items'); }
  private function incrementSnapshotsTable(): string { return $this->db->table('scm_increment_snapshots'); }
  private function incrementChangesTable(): string { return $this->db->table('scm_increment_change_audit'); }
}
