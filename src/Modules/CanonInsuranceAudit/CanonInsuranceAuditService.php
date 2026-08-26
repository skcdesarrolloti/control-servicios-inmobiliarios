<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

use PDO;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailQueue;

final class CanonInsuranceAuditService
{
  private const MONEY_TOLERANCE = 1.0;
  private const COLOMBIA_IVA_FACTOR = 1.19;
  private const IMPORT_VERSION = 'canon-admin-iva-consolidado-v6';

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
    $source = AuditSourceCatalog::identify($name, $period);

    $this->ensureSchema();
    $fileHash = hash_file('sha256', $path);
    if (!is_string($fileHash)) {
      throw new \RuntimeException('No se pudo verificar el archivo subido.');
    }
    $hash = hash('sha256', $fileHash . '|' . self::IMPORT_VERSION . '|' . $source['key']);
    $existingId = (int) ($this->db->getVar(
      "SELECT `id` FROM `{$this->auditsTable()}` WHERE `period` = ? AND `source_hash` = ? AND `format_key` = ? LIMIT 1",
      [$period, $hash, $source['key']]
    ) ?? 0);
    if ($existingId > 0) {
      return ['audit_id' => $existingId, 'duplicate' => true] + $this->dashboard(['period' => $period]);
    }

    $rows = $this->reader->read($path, $name);
    $parsed = $this->parseSpreadsheet($rows, $source['key']);
    $contracts = $this->contractsByRequestNumber();
    $items = [];
    foreach ((array) ($parsed['records'] ?? []) as $record) {
      $item = $this->compareRecord($record, $contracts, $source['key'], $source['label']);
      $items[] = $item;
    }
    if ($items === []) {
      throw new \RuntimeException('No se encontraron filas de contratos para auditar.');
    }
    $insurer = $source['label'];

    $stats = $this->summarize($items);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $this->db->insert($this->auditsTable(), [
        'period' => $period,
        'source_filename' => mb_substr($name, 0, 255),
        'source_hash' => $hash,
        'insurer' => mb_substr($insurer, 0, 160),
        'format_key' => $source['key'],
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

    return ['audit_id' => $auditId, 'duplicate' => false] + $this->dashboard(['period' => $period]);
  }

  /** @param array<string,string> $filters @return array<string,mixed> */
  public function dashboard(array $filters = []): array
  {
    $this->ensureSchema();
    $this->syncIncrementChanges();

    $audits = $this->db->getResults(
      "SELECT * FROM `{$this->auditsTable()}`
        WHERE `format_key` IN ('simi', 'libertador', 'fianza_bogota', 'unifianza')
        ORDER BY `uploaded_at` DESC, `id` DESC LIMIT 80"
    );
    $period = trim((string) ($filters['period'] ?? ''));
    $auditId = max(0, (int) ($filters['audit_id'] ?? 0));
    if (($period === '' || preg_match('/^\d{4}-\d{2}$/', $period) !== 1) && $auditId > 0) {
      foreach ($audits as $audit) {
        if ((int) ($audit['id'] ?? 0) === $auditId) {
          $period = (string) ($audit['period'] ?? '');
          break;
        }
      }
    }
    if ($period === '' || preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
      $period = (string) ($audits[0]['period'] ?? date('Y-m'));
    }

    $periods = [];
    $sourceAudits = [];
    foreach ($audits as $audit) {
      $auditPeriod = (string) ($audit['period'] ?? '');
      if ($auditPeriod !== '') {
        $periods[$auditPeriod] = $auditPeriod;
      }
      $sourceKey = (string) ($audit['format_key'] ?? '');
      if ($auditPeriod === $period && isset(AuditSourceCatalog::labels()[$sourceKey]) && !isset($sourceAudits[$sourceKey])) {
        $sourceAudits[$sourceKey] = $audit;
      }
    }
    krsort($periods);
    $allRows = $this->monthlyComparisonRows($sourceAudits);
    $summary = $this->summarize($allRows);
    $status = $this->sanitizeKey((string) ($filters['status'] ?? ''));
    $search = mb_strtolower(AuditValueNormalizer::text($filters['search'] ?? ''), 'UTF-8');
    $items = array_values(array_filter($allRows, static function (array $row) use ($status, $search): bool {
      if (in_array($status, ['correcto', 'incorrecto', 'anomalia'], true) && (string) ($row['status'] ?? '') !== $status) {
        return false;
      }
      if ($search === '') {
        return true;
      }
      $haystack = mb_strtolower(implode(' ', [
        (string) ($row['request_number'] ?? ''),
        (string) ($row['contract_number'] ?? ''),
        (string) ($row['tenant'] ?? ''),
        (string) ($row['property_address'] ?? ''),
      ]), 'UTF-8');
      return str_contains($haystack, $search);
    }));
    $items = array_slice($items, 0, 500);

    $sourceAuditIds = array_values(array_filter(array_map(static fn(array $audit): int => (int) ($audit['id'] ?? 0), $sourceAudits)));
    $reports = [];
    if ($sourceAuditIds !== []) {
      $placeholders = implode(',', array_fill(0, count($sourceAuditIds), '?'));
      $reports = $this->db->getResults(
        "SELECT * FROM `{$this->reportsTable()}` WHERE `audit_id` IN ({$placeholders}) ORDER BY `sent_at` DESC, `id` DESC LIMIT 10",
        $sourceAuditIds
      );
    }

    return [
      'audits' => $audits,
      'periods' => array_values($periods),
      'selected_period' => $period,
      'source_audits' => $sourceAudits,
      'summary' => $summary,
      'items' => $items,
      'reports' => $reports,
      'filename_examples' => AuditSourceCatalog::examples($period),
      'filters' => [
        'period' => $period,
        'status' => $status,
        'search' => AuditValueNormalizer::text($filters['search'] ?? ''),
      ],
    ];
  }

  /** @param array<string,array<string,mixed>> $sourceAudits @return array<int,array<string,mixed>> */
  private function monthlyComparisonRows(array $sourceAudits): array
  {
    if ($sourceAudits === []) {
      return [];
    }
    $auditSources = [];
    foreach ($sourceAudits as $sourceKey => $audit) {
      $auditId = (int) ($audit['id'] ?? 0);
      if ($auditId > 0) {
        $auditSources[$auditId] = $sourceKey;
      }
    }
    if ($auditSources === []) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($auditSources), '?'));
    $items = $this->db->getResults(
      "SELECT * FROM `{$this->itemsTable()}` WHERE `audit_id` IN ({$placeholders}) ORDER BY `source_row` ASC, `id` ASC",
      array_keys($auditSources)
    );
    $grouped = [];
    foreach ($items as $item) {
      $sourceKey = $auditSources[(int) ($item['audit_id'] ?? 0)] ?? '';
      if ($sourceKey === '') {
        continue;
      }
      $item = $this->normalizedComparisonItem($item, $sourceKey);
      $contractId = (int) ($item['contract_id'] ?? 0);
      $request = AuditValueNormalizer::requestNumber($item['request_number'] ?? '');
      $groupKey = $contractId > 0 ? 'contract:' . $contractId : 'request:' . $request;
      if (!isset($grouped[$groupKey])) {
        $grouped[$groupKey] = [
          'request_number' => $request,
          'contract_id' => $contractId > 0 ? $contractId : null,
          'contract_number' => (string) ($item['contract_number'] ?? ''),
          'mandate_id' => $item['mandate_id'] ?? null,
          'tenant' => (string) ($item['tenant'] ?? ''),
          'property_address' => (string) ($item['property_address'] ?? ''),
          'insurer' => (string) ($item['insurer'] ?? ''),
          'platform' => [
            'canon' => $item['system_canon'] ?? null,
            'administration' => $item['system_administration'] ?? null,
            'iva' => $item['system_iva'] ?? null,
          ],
          'sources' => [],
        ];
      }
      if ($sourceKey === 'simi') {
        $grouped[$groupKey]['request_number'] = $request;
        $grouped[$groupKey]['insurer'] = (string) ($item['insurer'] ?? '');
      }
      foreach (['canon' => 'system_canon', 'administration' => 'system_administration', 'iva' => 'system_iva'] as $metric => $column) {
        if (($grouped[$groupKey]['platform'][$metric] ?? null) === null && ($item[$column] ?? null) !== null) {
          $grouped[$groupKey]['platform'][$metric] = $item[$column];
        }
      }
      $grouped[$groupKey]['sources'][$sourceKey] = [
        'item_id' => (int) ($item['id'] ?? 0),
        'status' => (string) ($item['status'] ?? 'anomalia'),
        'canon' => $item['excel_canon'] ?? null,
        'administration' => $item['excel_administration'] ?? null,
        'iva' => $item['excel_iva'] ?? null,
        'metric_statuses' => [
          'canon' => $this->metricStatus($item['excel_canon'] ?? null, $item['system_canon'] ?? null, $item['difference_canon'] ?? null),
          'administration' => $this->metricStatus($item['excel_administration'] ?? null, $item['system_administration'] ?? null, $item['difference_administration'] ?? null),
          'iva' => $this->metricStatus($item['excel_iva'] ?? null, $item['system_iva'] ?? null, $item['difference_iva'] ?? null),
        ],
        'findings' => $this->cleanFindings((string) ($item['differences_json'] ?? '[]')),
        'observation' => (string) ($item['observation'] ?? ''),
        'observation_by_name' => (string) ($item['observation_by_name'] ?? ''),
        'observation_at' => (string) ($item['observation_at'] ?? ''),
      ];
    }

    $existingContractIds = [];
    foreach ($grouped as $row) {
      $contractId = (int) ($row['contract_id'] ?? 0);
      if ($contractId > 0) {
        $existingContractIds[$contractId] = true;
      }
    }

    $rows = [];
    foreach ($grouped as $row) {
      $sources = (array) ($row['sources'] ?? []);
      $status = 'correcto';
      $findings = [];
      $expectedInsurer = $this->insurerSourceKey((string) ($row['insurer'] ?? ''));
      foreach ($sources as $sourceKey => $source) {
        $sourceStatus = (string) ($source['status'] ?? 'anomalia');
        if ($sourceStatus === 'incorrecto') {
          $status = 'incorrecto';
        } elseif ($sourceStatus !== 'correcto' && $status === 'correcto') {
          $status = 'anomalia';
        }
        if ($expectedInsurer === '' && $sourceKey !== 'simi') {
          $expectedInsurer = $sourceKey;
        }
        foreach ((array) ($source['findings'] ?? []) as $finding) {
          if ($finding !== 'Canon, administracion e IVA coinciden.') {
            $findings[] = AuditSourceCatalog::label((string) $sourceKey) . ': ' . $finding;
          }
        }
      }
      if (!isset($sources['simi'])) {
        if ($status === 'correcto') {
          $status = 'anomalia';
        }
        $findings[] = 'SIMI: no aparece la solicitud en el archivo del periodo.';
      }
      if ($expectedInsurer === '') {
        if ($status === 'correcto') {
          $status = 'anomalia';
        }
        $findings[] = 'Plataforma: no se pudo identificar la aseguradora asignada.';
      } elseif (!isset($sources[$expectedInsurer])) {
        if ($status === 'correcto') {
          $status = 'anomalia';
        }
        $findings[] = AuditSourceCatalog::label($expectedInsurer) . ': no aparece la solicitud en el archivo del periodo.';
      }
      if ($findings === [] && $status === 'correcto') {
        $findings[] = 'Los valores de Plataforma, SIMI y la aseguradora asignada coinciden.';
      }

      $observationSource = null;
      foreach ($sources as $source) {
        if ((string) ($source['observation'] ?? '') !== '') {
          $observationSource = $source;
          break;
        }
      }
      if ($observationSource === null) {
        foreach ($sources as $source) {
          if ((string) ($source['status'] ?? '') === 'incorrecto') {
            $observationSource = $source;
            break;
          }
        }
      }
      $observationSource ??= $sources['simi'] ?? reset($sources) ?: null;
      $row['status'] = $status;
      $row['expected_insurer'] = $expectedInsurer;
      $row['differences_json'] = json_encode(array_values(array_unique($findings)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
      $row['observation_item_id'] = (int) ($observationSource['item_id'] ?? 0);
      $row['observation'] = (string) ($observationSource['observation'] ?? '');
      $row['observation_by_name'] = (string) ($observationSource['observation_by_name'] ?? '');
      $row['observation_at'] = (string) ($observationSource['observation_at'] ?? '');
      $rows[] = $row;
    }
    foreach ($this->platformIdentityGapRows(array_keys($existingContractIds)) as $gapRow) {
      $rows[] = $gapRow;
    }
    $priority = ['incorrecto' => 0, 'anomalia' => 1, 'correcto' => 2];
    usort($rows, static fn(array $a, array $b): int => ($priority[(string) ($a['status'] ?? '')] ?? 9) <=> ($priority[(string) ($b['status'] ?? '')] ?? 9));
    return $rows;
  }

  /** @return array<int,string> */
  private function cleanFindings(string $json): array
  {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
      return [];
    }
    return array_values(array_filter(array_map('strval', $decoded), static function (string $message): bool {
      $key = AuditValueNormalizer::key($message);
      return !str_contains($key, 'prima')
        && !str_contains($key, 'serviciospublicos')
        && !str_contains($key, 'coberturaservicios');
    }));
  }

  private function insurerSourceKey(string $insurer): string
  {
    $key = AuditValueNormalizer::key($insurer);
    if (str_contains($key, 'libertador')) {
      return 'libertador';
    }
    if (str_contains($key, 'fianzabogota')) {
      return 'fianza_bogota';
    }
    if (str_contains($key, 'unifianza')) {
      return 'unifianza';
    }
    return '';
  }

  /** @param array<int,int> $excludeContractIds @return array<int,array<string,mixed>> */
  private function platformIdentityGapRows(array $excludeContractIds): array
  {
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $where = [
      "LOWER(TRIM(COALESCE(c.`estado`, ''))) = 'entregado'",
      "(TRIM(COALESCE(c.`numero_solicitud`, '')) = ''
        OR TRIM(COALESCE(c.`aseguradora`, '')) = ''
        OR TRIM(COALESCE(c.`id_contrato_mandato`, '')) = ''
        OR TRIM(COALESCE(c.`id_contrato_mandato`, '')) = '0')",
    ];
    $args = [];
    if ($excludeContractIds !== []) {
      $where[] = 'c.`_ID` NOT IN (' . implode(',', array_fill(0, count($excludeContractIds), '?')) . ')';
      $args = array_values($excludeContractIds);
    }
    $rows = $this->db->getResults(
      "SELECT c.`_ID` AS contract_id, c.`contrato` AS contract_number, c.`numero_solicitud`,
              c.`arrendatario`, c.`direccion`, c.`id_contrato_mandato`, c.`valor_canon`,
              c.`valor_administracion`, c.`aseguradora` AS contract_insurer,
              m.`_ID` AS mandate_id, m.`aseguradora` AS mandate_insurer,
              m.`precio`, m.`administracion`, m.`incluye_iva`,
              m.`iva_precio`, m.`iva_total_precio`
         FROM `{$contracts}` c
         LEFT JOIN `{$mandates}` m ON CAST(m.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_contrato_mandato`, ''))
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.`_ID` DESC
        LIMIT 300",
      $args
    );

    $items = [];
    foreach ($rows as $row) {
      $contractId = (int) ($row['contract_id'] ?? 0);
      if ($contractId <= 0) {
        continue;
      }
      $insurer = $this->firstNonEmptyText([
        $row['contract_insurer'] ?? null,
        $row['mandate_insurer'] ?? null,
      ]);
      $expectedInsurer = $this->insurerSourceKey($insurer);
      $request = AuditValueNormalizer::requestNumber($row['numero_solicitud'] ?? '');
      $contractNumber = AuditValueNormalizer::text($row['contract_number'] ?? '');
      $mandateId = (int) ($row['mandate_id'] ?? 0) ?: null;
      $platform = $this->platformValuesFromContract($row);
      $findings = [];
      if ($request === '') {
        $findings[] = 'Plataforma: el contrato no tiene numero de solicitud, por eso no se puede cruzar contra SIMI ni aseguradora.';
      }
      if ($expectedInsurer === '') {
        $findings[] = 'Plataforma: no se pudo identificar la aseguradora asignada.';
      }
      if ($mandateId === null) {
        $findings[] = 'Plataforma: el contrato no tiene mandato asociado para validar canon, administracion e IVA.';
      }
      foreach (['canon' => 'Canon', 'administration' => 'Administracion', 'iva' => 'IVA'] as $key => $label) {
        if (($platform[$key] ?? null) === null) {
          $findings[] = 'Plataforma: falta el valor de ' . $label . '.';
        }
      }
      if ($findings === []) {
        continue;
      }
      $items[] = [
        'request_number' => $request,
        'contract_id' => $contractId,
        'contract_number' => $contractNumber,
        'mandate_id' => $mandateId,
        'tenant' => AuditValueNormalizer::text($row['arrendatario'] ?? ''),
        'property_address' => AuditValueNormalizer::text($row['direccion'] ?? ''),
        'insurer' => $insurer,
        'platform' => $platform,
        'sources' => [],
        'status' => 'anomalia',
        'expected_insurer' => $expectedInsurer,
        'differences_json' => json_encode(array_values(array_unique($findings)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        'observation_item_id' => 0,
        'observation' => '',
        'observation_by_name' => '',
        'observation_at' => '',
      ];
    }
    return $items;
  }

  /** @param array<string,mixed> $contract @return array<string,float|null> */
  private function platformValuesFromContract(array $contract): array
  {
    $canon = $this->databaseMoney($contract['valor_canon'] ?? null)
      ?? $this->databaseMoney($contract['precio'] ?? null);
    $administration = $this->databaseMoney($contract['valor_administracion'] ?? null)
      ?? $this->databaseMoney($contract['administracion'] ?? null)
      ?? 0.0;
    $iva = null;
    $includesVat = AuditValueNormalizer::key($contract['incluye_iva'] ?? '');
    if ($includesVat === 'si') {
      $iva = $this->databaseMoney($contract['iva_total_precio'] ?? null);
      if ($iva === null) {
        $rate = AuditValueNormalizer::money($contract['iva_precio'] ?? null);
        if ($canon !== null && $rate !== null && $rate > 0) {
          $iva = round((float) $canon * $rate / 100, 2);
        }
      }
    } elseif ($includesVat === 'no') {
      $iva = 0.0;
    }
    return ['canon' => $canon, 'administration' => $administration, 'iva' => $iva];
  }

  private function metricStatus(mixed $sourceValue, mixed $platformValue, mixed $difference): string
  {
    if ($sourceValue === null || $sourceValue === '' || $platformValue === null || $platformValue === '') {
      return 'anomalia';
    }
    $amountDifference = $difference === null || $difference === ''
      ? (float) $sourceValue - (float) $platformValue
      : (float) $difference;
    return abs($amountDifference) > self::MONEY_TOLERANCE ? 'incorrecto' : 'correcto';
  }

  /** @param array<string,mixed> $item @return array<string,mixed> */
  private function normalizedComparisonItem(array $item, string $sourceKey): array
  {
    if ($sourceKey !== 'libertador' || ($item['excel_canon'] ?? null) === null) {
      return $item;
    }
    $excelIva = $this->databaseMoney($item['excel_iva'] ?? null);
    if ($this->shouldSplitLibertadorIncludedIva($item)) {
      if ($excelIva !== null && abs($excelIva) > self::MONEY_TOLERANCE) {
        return $item;
      }
      $split = $this->splitIncludedIva((float) $item['excel_canon']);
      $item['excel_canon'] = $split['base'];
      $item['excel_iva'] = $split['iva'];
    } else {
      if ($excelIva !== null && abs($excelIva) > self::MONEY_TOLERANCE) {
        $item['excel_canon'] = round((float) $item['excel_canon'] + $excelIva, 2);
      }
      $item['excel_iva'] = 0.0;
    }

    foreach ([
      'canon' => ['excel_canon', 'system_canon', 'difference_canon'],
      'administration' => ['excel_administration', 'system_administration', 'difference_administration'],
      'iva' => ['excel_iva', 'system_iva', 'difference_iva'],
    ] as [$excelKey, $systemKey, $differenceKey]) {
      $excel = $item[$excelKey] ?? null;
      $system = $item[$systemKey] ?? null;
      $item[$differenceKey] = ($excel === null || $system === null) ? null : round((float) $excel - (float) $system, 2);
    }
    [$item['status'], $item['differences_json']] = $this->statusAndDifferencesForComparedItem($item);
    return $item;
  }

  /** @param array<string,mixed> $item */
  private function shouldSplitLibertadorIncludedIva(array $item): bool
  {
    $systemIva = $this->databaseMoney($item['system_iva'] ?? null);
    return $systemIva !== null && abs($systemIva) > self::MONEY_TOLERANCE;
  }

  /** @return array<string,mixed> */
  public function mandateLinkingRows(string $search = ''): array
  {
    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $mandatesTable = $this->db->table('jet_cct_contrato_mandato');
    $search = trim(preg_replace('/\s+/u', ' ', AuditValueNormalizer::text($search)) ?? '');
    $where = [
      "LOWER(TRIM(COALESCE(`estado`, ''))) = 'entregado'",
      "(TRIM(COALESCE(`id_contrato_mandato`, '')) = '' OR TRIM(COALESCE(`id_contrato_mandato`, '')) = '0')",
      "TRIM(COALESCE(`id_inmueble`, '')) <> ''",
    ];
    $args = [];
    if ($search !== '') {
      $like = '%' . $this->db->escapeLike($search) . '%';
      $where[] = "(COALESCE(`contrato`, '') LIKE ? OR COALESCE(`numero_solicitud`, '') LIKE ? OR COALESCE(`arrendatario`, '') LIKE ? OR COALESCE(`direccion`, '') LIKE ? OR COALESCE(`id_inmueble`, '') LIKE ?)";
      $args = [$like, $like, $like, $like, $like];
    }

    $contracts = $this->db->getResults(
      "SELECT `_ID` AS contract_id, `contrato` AS contract_number, `numero_solicitud`,
              `arrendatario`, `direccion`, `id_inmueble`, `aseguradora`,
              `valor_canon`, `valor_administracion`
         FROM `{$contractsTable}`
        WHERE " . implode(' AND ', $where) . "
        ORDER BY `_ID` DESC
        LIMIT 100",
      $args
    );

    $propertyIds = [];
    foreach ($contracts as $contract) {
      $propertyId = $this->propertyKey($contract['id_inmueble'] ?? '');
      if ($propertyId !== '') {
        $propertyIds[$propertyId] = AuditValueNormalizer::text($contract['id_inmueble'] ?? '');
      }
    }

    $mandatesByProperty = [];
    if ($propertyIds !== []) {
      $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
      $mandates = $this->db->getResults(
        "SELECT `_ID` AS mandate_id, `id_inmueble`, `aseguradora`, `precio`,
                `administracion`, `incluye_iva`, `iva_precio`, `iva_total_precio`
           FROM `{$mandatesTable}`
          WHERE TRIM(COALESCE(`id_inmueble`, '')) IN ({$placeholders})
          ORDER BY `_ID` DESC
          LIMIT 400",
        array_values($propertyIds)
      );
      foreach ($mandates as $mandate) {
        $propertyKey = $this->propertyKey($mandate['id_inmueble'] ?? '');
        if ($propertyKey === '') {
          continue;
        }
        $values = $this->platformValuesFromContract([
          'precio' => $mandate['precio'] ?? null,
          'administracion' => $mandate['administracion'] ?? null,
          'incluye_iva' => $mandate['incluye_iva'] ?? null,
          'iva_precio' => $mandate['iva_precio'] ?? null,
          'iva_total_precio' => $mandate['iva_total_precio'] ?? null,
        ]);
        $mandatesByProperty[$propertyKey][] = [
          'mandate_id' => (int) ($mandate['mandate_id'] ?? 0),
          'property_id' => AuditValueNormalizer::text($mandate['id_inmueble'] ?? ''),
          'insurer' => AuditValueNormalizer::text($mandate['aseguradora'] ?? ''),
          'canon' => $values['canon'],
          'administration' => $values['administration'],
          'iva' => $values['iva'],
          'includes_iva' => AuditValueNormalizer::text($mandate['incluye_iva'] ?? ''),
        ];
      }
    }

    $rows = [];
    foreach ($contracts as $contract) {
      $propertyKey = $this->propertyKey($contract['id_inmueble'] ?? '');
      $rows[] = [
        'contract_id' => (int) ($contract['contract_id'] ?? 0),
        'contract_number' => AuditValueNormalizer::text($contract['contract_number'] ?? ''),
        'request_number' => AuditValueNormalizer::requestNumber($contract['numero_solicitud'] ?? ''),
        'tenant' => AuditValueNormalizer::text($contract['arrendatario'] ?? ''),
        'property_address' => AuditValueNormalizer::text($contract['direccion'] ?? ''),
        'property_id' => AuditValueNormalizer::text($contract['id_inmueble'] ?? ''),
        'insurer' => AuditValueNormalizer::text($contract['aseguradora'] ?? ''),
        'canon' => $this->databaseMoney($contract['valor_canon'] ?? null),
        'administration' => $this->databaseMoney($contract['valor_administracion'] ?? null),
        'candidates' => $mandatesByProperty[$propertyKey] ?? [],
      ];
    }

    return [
      'rows' => $rows,
      'total' => count($rows),
      'search' => $search,
    ];
  }

  /** @return array<string,mixed> */
  public function linkMandateToContract(int $contractId, int $mandateId, string $search = ''): array
  {
    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $mandatesTable = $this->db->table('jet_cct_contrato_mandato');
    if ($contractId <= 0 || $mandateId <= 0) {
      throw new \RuntimeException('Selecciona un contrato y un mandato validos.');
    }
    $row = $this->db->getRow(
      "SELECT c.`_ID` AS contract_id, c.`contrato`, c.`estado`, c.`id_inmueble` AS contract_property,
              c.`id_contrato_mandato`, m.`_ID` AS mandate_id, m.`id_inmueble` AS mandate_property
         FROM `{$contractsTable}` c
         INNER JOIN `{$mandatesTable}` m ON m.`_ID` = ?
        WHERE c.`_ID` = ?
        LIMIT 1",
      [$mandateId, $contractId]
    );
    if (!is_array($row)) {
      throw new \RuntimeException('No se encontro el contrato o el mandato seleccionado.');
    }
    if (AuditValueNormalizer::key($row['estado'] ?? '') !== 'entregado') {
      throw new \RuntimeException('Solo se pueden vincular contratos en estado Entregado.');
    }
    $currentMandate = AuditValueNormalizer::text($row['id_contrato_mandato'] ?? '');
    if ($currentMandate !== '' && $currentMandate !== '0') {
      throw new \RuntimeException('Este contrato ya tiene un mandato vinculado.');
    }
    if ($this->propertyKey($row['contract_property'] ?? '') === '' || $this->propertyKey($row['contract_property'] ?? '') !== $this->propertyKey($row['mandate_property'] ?? '')) {
      throw new \RuntimeException('El mandato no corresponde al mismo id_inmueble del contrato.');
    }

    $this->db->update($contractsTable, [
      'id_contrato_mandato' => (string) $mandateId,
    ], ['_ID' => $contractId]);

    return [
      'message' => 'Mandato ' . $mandateId . ' vinculado al contrato ' . AuditValueNormalizer::text($row['contrato'] ?? ('#' . $contractId)) . '.',
    ] + $this->mandateLinkingRows($search);
  }

  private function propertyKey(mixed $value): string
  {
    return mb_strtolower(trim((string) $value), 'UTF-8');
  }

  /** @return array<string,mixed> */
  public function saveObservation(int $itemId, string $observation): array
  {
    $this->ensureSchema();
    if ($itemId <= 0) {
      throw new \RuntimeException('No se identifico la fila de auditoria.');
    }
    $item = $this->db->getRow(
      "SELECT i.`id`, i.`audit_id`, i.`status`, a.`period`
         FROM `{$this->itemsTable()}` i
         INNER JOIN `{$this->auditsTable()}` a ON a.`id` = i.`audit_id`
        WHERE i.`id` = ? LIMIT 1",
      [$itemId]
    );
    if (!is_array($item)) {
      throw new \RuntimeException('La fila de auditoria ya no existe.');
    }
    $observation = trim(preg_replace('/\s+/u', ' ', $observation) ?? '');
    if (mb_strlen($observation) > 1000) {
      throw new \RuntimeException('La observacion no puede superar 1.000 caracteres.');
    }
    $author = $this->employeeIdentity((string) Auth::userId());
    $this->db->update($this->itemsTable(), [
      'observation' => $observation,
      'observation_by_id' => $author['id'],
      'observation_by_name' => mb_substr($author['name'] !== '' ? $author['name'] : Auth::user(), 0, 190),
      'observation_at' => date('Y-m-d H:i:s'),
    ], ['id' => $itemId]);
    return $this->dashboard(['period' => (string) ($item['period'] ?? '')]);
  }

  /** @return array<string,mixed> */
  public function sendReport(string $period): array
  {
    $this->ensureSchema();
    $period = $this->validPeriod($period);
    $audits = $this->db->getResults(
      "SELECT * FROM `{$this->auditsTable()}`
        WHERE `period` = ? AND `format_key` IN ('simi', 'libertador', 'fianza_bogota', 'unifianza')
        ORDER BY `uploaded_at` DESC, `id` DESC",
      [$period]
    );
    $sourceAudits = [];
    foreach ($audits as $audit) {
      $sourceKey = (string) ($audit['format_key'] ?? '');
      if (!isset($sourceAudits[$sourceKey])) {
        $sourceAudits[$sourceKey] = $audit;
      }
    }
    $items = array_values(array_filter(
      $this->monthlyComparisonRows($sourceAudits),
      static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['incorrecto', 'anomalia'], true)
    ));
    if ($items === []) {
      throw new \RuntimeException('Este periodo no tiene diferencias ni anomalias para informar.');
    }
    $recipients = $this->contractCoordinatorRecipients();
    if ($recipients === []) {
      throw new \RuntimeException('No se encontro un correo activo para el cargo Coordinador Contractual.');
    }
    $auditId = (int) ($audits[0]['id'] ?? 0);
    if ($auditId <= 0) {
      throw new \RuntimeException('No hay archivos auditados para el periodo seleccionado.');
    }
    $reportHash = hash('sha256', json_encode(array_map(static fn(array $item): array => [
      $item['contract_id'] ?? 0,
      $item['request_number'] ?? '',
      $item['status'] ?? '',
      $item['differences_json'] ?? '',
      $item['observation'] ?? '',
      $item['sources'] ?? [],
    ], $items), JSON_UNESCAPED_UNICODE) ?: '');
    $html = $this->reportHtml($period, $items, $sourceAudits);
    $subject = 'Informe consolidado de auditoria de canon - ' . $period;
    $emails = array_column($recipients, 'email');
    $queued = (new EmailQueue($this->db))->enqueue($emails, $subject, $html, [
      'source_module' => 'auditoria-canon-aseguradoras',
      'dedupe_key' => 'canon-insurance-audit:' . $auditId . ':' . $reportHash,
      'meta' => [
        'audit_id' => $auditId,
        'period' => $period,
        'source_filenames' => array_values(array_map(static fn(array $audit): string => (string) ($audit['source_filename'] ?? ''), $sourceAudits)),
        'report_hash' => $reportHash,
      ],
    ]);
    if ($queued <= 0) {
      throw new \RuntimeException('No fue posible encolar el informe. Verifica el servicio compartido de notificaciones.');
    }
    $author = $this->employeeIdentity((string) Auth::userId());
    $this->db->insert($this->reportsTable(), [
      'audit_id' => $auditId,
      'recipients_json' => json_encode($recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
      'queued_count' => $queued,
      'sent_by_id' => $author['id'],
      'sent_by_name' => mb_substr($author['name'] !== '' ? $author['name'] : Auth::user(), 0, 190),
      'sent_at' => date('Y-m-d H:i:s'),
      'report_hash' => $reportHash,
    ]);
    return ['queued' => $queued] + $this->dashboard(['period' => $period]);
  }

  /** @return array<int,array{name:string,email:string}> */
  private function contractCoordinatorRecipients(): array
  {
    $officials = $this->db->table('jet_cct_funcionarios');
    $positions = $this->db->table('jet_cct_cargos');
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(f.`nombre`, '')) AS name, TRIM(COALESCE(f.`correo`, '')) AS email
         FROM `{$officials}` f
         INNER JOIN `{$positions}` c ON CAST(c.`_ID` AS CHAR) = TRIM(COALESCE(f.`id_cargo`, ''))
        WHERE LOWER(TRIM(COALESCE(c.`nombre_cargo`, ''))) LIKE '%ordinador%contractual%'
          AND LOWER(TRIM(COALESCE(f.`activo`, ''))) = 'si'"
    );
    $recipients = [];
    foreach ($rows as $row) {
      $email = trim((string) ($row['email'] ?? ''));
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $recipients[strtolower($email)] = ['name' => (string) ($row['name'] ?? ''), 'email' => $email];
      }
    }
    return array_values($recipients);
  }

  /** @param array<int,array<string,mixed>> $items @param array<string,array<string,mixed>> $sourceAudits */
  private function reportHtml(string $period, array $items, array $sourceAudits): string
  {
    $h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $money = static fn(mixed $value): string => ($value === null || $value === '') ? 'Sin dato' : '$' . number_format((float) $value, 2, ',', '.');
    $formatValues = static function (mixed $source) use ($h, $money): string {
      if (!is_array($source)) {
        return 'No aparece';
      }
      return '<strong>Canon</strong><br>' . $h($money($source['canon'] ?? null))
        . '<br><strong>Administracion</strong><br>' . $h($money($source['administration'] ?? null))
        . '<br><strong>IVA</strong><br>' . $h($money($source['iva'] ?? null));
    };
    $grouped = [];
    foreach ($items as $item) {
      $key = (string) ($item['expected_insurer'] ?? '');
      $key = in_array($key, ['libertador', 'fianza_bogota', 'unifianza'], true) ? $key : 'sin_aseguradora';
      $grouped[$key][] = $item;
    }
    $order = ['libertador', 'fianza_bogota', 'unifianza', 'sin_aseguradora'];
    uksort($grouped, static function (string $a, string $b) use ($order): int {
      $aIndex = array_search($a, $order, true);
      $bIndex = array_search($b, $order, true);
      return ($aIndex === false ? 99 : $aIndex) <=> ($bIndex === false ? 99 : $bIndex);
    });
    $sections = '';
    foreach ($grouped as $groupKey => $groupItems) {
      $label = $groupKey === 'sin_aseguradora' ? 'Sin aseguradora asignada' : AuditSourceCatalog::label((string) $groupKey);
      $rows = '';
      foreach ($groupItems as $item) {
        $findings = json_decode((string) ($item['differences_json'] ?? '[]'), true);
        $findingText = is_array($findings) ? implode(' ', array_map('strval', $findings)) : '';
        $sourceValues = (array) ($item['sources'] ?? []);
        $assignedSource = in_array($groupKey, ['libertador', 'fianza_bogota', 'unifianza'], true) ? ($sourceValues[$groupKey] ?? null) : null;
        $rows .= '<tr><td>' . $h(($item['request_number'] ?? '') ?: 'Sin solicitud') . '</td><td>' . $h($item['contract_number'] ?? '') . '</td>'
          . '<td>' . $h($item['tenant'] ?? '') . '<br>' . $h($item['property_address'] ?? '') . '</td>'
          . '<td>' . $formatValues($item['platform'] ?? null) . '</td>'
          . '<td>' . $formatValues($sourceValues['simi'] ?? null) . '</td>'
          . '<td>' . $formatValues($assignedSource) . '</td>'
          . '<td>' . $h($findingText) . '</td><td>' . $h($item['observation'] ?? '') . '</td>'
          . '<td>' . $h((string) ($item['status'] ?? '') === 'incorrecto' ? 'Rojo - Incorrecto' : 'Amarillo - Anomalia') . '</td></tr>';
      }
      $sections .= '<h3 style="margin:22px 0 8px;color:#123b68">' . $h($label) . ' (' . count($groupItems) . ')</h3>'
        . '<table cellpadding="7" cellspacing="0" border="1" style="border-collapse:collapse;font-size:12px;width:100%;margin-bottom:14px"><thead><tr>'
        . '<th>Solicitud</th><th>Contrato</th><th>Arrendatario e inmueble</th><th>Plataforma</th><th>SIMI</th><th>' . $h($label) . '</th><th>Hallazgo</th><th>Observacion</th><th>Semaforo</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }
    $files = implode(', ', array_map(static fn(array $audit): string => (string) ($audit['source_filename'] ?? ''), $sourceAudits));
    return '<div style="font-family:Arial,sans-serif;color:#243b53"><h2>Informe de auditoria de canon y aseguradoras</h2>'
      . '<p><strong>Periodo:</strong> ' . $h($period) . '<br><strong>Archivos:</strong> ' . $h($files) . '</p>'
      . '<p>Se informan las diferencias y anomalias encontradas al comparar canon, administracion e IVA entre Plataforma, SIMI y la aseguradora asignada.</p>'
      . $sections . '</div>';
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
  private function parseSpreadsheet(array $rows, string $sourceKey): array
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
        + (isset($columns['canon']) ? 3 : 0)
        + (isset($columns['administration']) ? 1 : 0)
        + (isset($columns['vat']) ? 1 : 0)
        + (isset($columns['tenant']) ? 1 : 0);
      if ($score > $best['score']) {
        $best = ['score' => $score, 'row' => (int) $rowIndex, 'columns' => $columns];
      }
    }
    if ($best['score'] < 8 || $best['row'] < 0) {
      throw new \RuntimeException('La estructura no coincide con el formato esperado de ' . AuditSourceCatalog::label($sourceKey) . '. Debe incluir solicitud y canon.');
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
        'excel_administration' => $this->columnMoneyOrZero($row, $columns, 'administration'),
        'excel_vat' => $this->columnMoneyOrZero($row, $columns, 'vat'),
      ];
    }
    return ['records' => $records, 'format_key' => $sourceKey];
  }

  /** @return array<string,array<int,string>> */
  private function headerAliases(): array
  {
    return [
      'request_number' => ['solicitud', 'nosolicitud', 'numerosolicitud'],
      'old_request_number' => ['numerosolicitudantiguo', 'solicitudantigua'],
      'tenant' => ['arrendatario'],
      'property_address' => ['inmueble', 'direccion'],
      'canon' => ['canon'],
      'administration' => ['admon', 'admonph', 'administracion'],
      'vat' => ['iva', 'ivacomercial', 'ivaarrendamiento', 'ivaarriendamiento'],
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
              m.`precio`, m.`administracion`, m.`incluye_iva`,
              m.`iva_precio`, m.`iva_total_precio`
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
  private function compareRecord(array $record, array $contracts, string $sourceKey, string $sourceLabel): array
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
      'insurer' => $sourceLabel,
      'tenant' => (string) ($record['tenant'] ?? ''),
      'property_address' => (string) ($record['property_address'] ?? ''),
      'contract_id' => null,
      'contract_number' => '',
      'mandate_id' => null,
      'status' => 'anomalia',
      'canon_includes_iva' => $sourceKey === 'libertador' ? 1 : 0,
      'excel_canon' => $record['excel_canon'],
      'system_canon' => null,
      'difference_canon' => null,
      'excel_administration' => $record['excel_administration'],
      'system_administration' => null,
      'difference_administration' => null,
      'excel_iva' => $record['excel_vat'],
      'system_iva' => null,
      'difference_iva' => null,
      'observation' => '',
      'observation_by_id' => '',
      'observation_by_name' => '',
      'observation_at' => null,
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
      $sourceLabel,
    ]);

    $base['system_canon'] = $this->databaseMoney($contract['valor_canon'] ?? null)
      ?? $this->databaseMoney($contract['precio'] ?? null);
    $base['system_administration'] = $this->databaseMoney($contract['valor_administracion'] ?? null)
      ?? $this->databaseMoney($contract['administracion'] ?? null)
      ?? 0.0;
    $includesVat = AuditValueNormalizer::key($contract['incluye_iva'] ?? '');
    if ($includesVat === 'si') {
      $base['system_iva'] = $this->databaseMoney($contract['iva_total_precio'] ?? null);
      if ($base['system_iva'] === null) {
        $rate = AuditValueNormalizer::money($contract['iva_precio'] ?? null);
        if ($base['system_canon'] !== null && $rate !== null && $rate > 0) {
          $base['system_iva'] = round((float) $base['system_canon'] * $rate / 100, 2);
        }
      }
    } elseif ($includesVat === 'no') {
      $base['system_iva'] = 0.0;
    }
    if ($sourceKey === 'libertador' && $base['excel_canon'] !== null && $this->shouldSplitLibertadorIncludedIva($base)) {
      $split = $this->splitIncludedIva((float) $base['excel_canon']);
      $base['excel_canon'] = $split['base'];
      $base['excel_iva'] = $split['iva'];
    } elseif ($sourceKey === 'libertador' && $base['excel_canon'] !== null && $base['excel_iva'] === null) {
      $base['excel_iva'] = 0.0;
    }

    [$base['status'], $base['differences_json']] = $this->statusAndDifferencesForComparedItem($base);
    return $base;
  }

  /** @param array<string,mixed> $item @return array{0:string,1:string} */
  private function statusAndDifferencesForComparedItem(array &$item): array
  {
    $differences = [];
    $missing = false;
    $assessed = 0;
    $hasDifference = false;
    if (($item['mandate_id'] ?? null) === null) {
      $missing = true;
      $differences[] = 'El contrato no tiene un mandato asociado para validar el IVA.';
    }
    foreach ([
      ['Canon', 'excel_canon', 'system_canon', 'difference_canon'],
      ['Administracion', 'excel_administration', 'system_administration', 'difference_administration'],
      ['IVA', 'excel_iva', 'system_iva', 'difference_iva'],
    ] as [$label, $excelKey, $systemKey, $differenceKey]) {
      $excelValue = $item[$excelKey] ?? null;
      if ($excelValue === null) {
        $missing = true;
        $differences[] = $label . ': falta el valor en el archivo.';
        continue;
      }
      $assessed++;
      $systemValue = $item[$systemKey] ?? null;
      if ($systemValue === null) {
        $missing = true;
        $differences[] = $label . ': falta el valor en la pagina.';
        continue;
      }
      $difference = round((float) $excelValue - (float) $systemValue, 2);
      $item[$differenceKey] = $difference;
      if (abs($difference) > self::MONEY_TOLERANCE) {
        $hasDifference = true;
        $differences[] = $label . ': diferencia de $' . number_format($difference, 2, ',', '.');
      }
    }

    if ($hasDifference) {
      $status = 'incorrecto';
    } elseif ($assessed < 3 || $missing) {
      $status = 'anomalia';
    } else {
      $status = 'correcto';
      $differences[] = 'Canon, administracion e IVA coinciden.';
    }
    return [$status, json_encode($differences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'];
  }

  /** @return array{base:float,iva:float} */
  private function splitIncludedIva(float $total): array
  {
    $base = round($total / self::COLOMBIA_IVA_FACTOR, 2);
    return ['base' => $base, 'iva' => round($total - $base, 2)];
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
      if (($item['contract_id'] ?? null) !== null) {
        $stats['matched']++;
      }
      if ($status === 'correcto') {
        $stats['compliant']++;
      } elseif ($status === 'incorrecto') {
        $stats['differences']++;
      } else {
        $stats['incomplete']++;
        if (($item['contract_id'] ?? null) === null) {
          $stats['unmatched']++;
        }
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

  /** @param array<int,mixed> $row @param array<string,int> $columns */
  private function columnMoneyOrZero(array $row, array $columns, string $key): ?float
  {
    if (!isset($columns[$key])) {
      return null;
    }
    return AuditValueNormalizer::money($row[$columns[$key]] ?? null) ?? 0.0;
  }

  private function databaseMoney(mixed $value): ?float
  {
    if (is_int($value) || is_float($value)) {
      return (float) $value;
    }
    $text = trim((string) $value);
    if ($text === '') {
      return null;
    }
    if (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $text) === 1) {
      return (float) str_replace('.', '', $text);
    }
    if (preg_match('/^(\d{3,})\.(\d{1,2})$/', $text, $match) === 1) {
      return (float) ($match[1] . str_pad($match[2], 3, '0'));
    }
    return AuditValueNormalizer::money($text);
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
      `canon_includes_iva` TINYINT(1) NOT NULL DEFAULT 0,
      `excel_canon` DECIMAL(18,2) NULL, `system_canon` DECIMAL(18,2) NULL, `difference_canon` DECIMAL(18,2) NULL,
      `excel_administration` DECIMAL(18,2) NULL, `system_administration` DECIMAL(18,2) NULL, `difference_administration` DECIMAL(18,2) NULL,
      `excel_iva` DECIMAL(18,2) NULL, `system_iva` DECIMAL(18,2) NULL, `difference_iva` DECIMAL(18,2) NULL,
      `observation` VARCHAR(1000) NOT NULL DEFAULT '',
      `observation_by_id` VARCHAR(80) NOT NULL DEFAULT '',
      `observation_by_name` VARCHAR(190) NOT NULL DEFAULT '',
      `observation_at` DATETIME NULL,
      `differences_json` LONGTEXT NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_audit_status` (`audit_id`, `status`),
      KEY `idx_request` (`request_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $this->ensureColumn($this->itemsTable(), 'canon_includes_iva', 'TINYINT(1) NOT NULL DEFAULT 0');
    $this->ensureColumn($this->itemsTable(), 'excel_iva', 'DECIMAL(18,2) NULL');
    $this->ensureColumn($this->itemsTable(), 'system_iva', 'DECIMAL(18,2) NULL');
    $this->ensureColumn($this->itemsTable(), 'difference_iva', 'DECIMAL(18,2) NULL');
    $this->ensureColumn($this->itemsTable(), 'observation', "VARCHAR(1000) NOT NULL DEFAULT ''");
    $this->ensureColumn($this->itemsTable(), 'observation_by_id', "VARCHAR(80) NOT NULL DEFAULT ''");
    $this->ensureColumn($this->itemsTable(), 'observation_by_name', "VARCHAR(190) NOT NULL DEFAULT ''");
    $this->ensureColumn($this->itemsTable(), 'observation_at', 'DATETIME NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->reportsTable()}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `audit_id` BIGINT UNSIGNED NOT NULL,
      `recipients_json` LONGTEXT NOT NULL,
      `queued_count` INT UNSIGNED NOT NULL DEFAULT 0,
      `sent_by_id` VARCHAR(80) NOT NULL DEFAULT '',
      `sent_by_name` VARCHAR(190) NOT NULL DEFAULT '',
      `sent_at` DATETIME NOT NULL,
      `report_hash` CHAR(64) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_audit_sent` (`audit_id`, `sent_at`)
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

  private function ensureColumn(string $table, string $column, string $definition): void
  {
    $exists = (int) ($this->db->getVar(
      'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
      [$table, $column]
    ) ?? 0);
    if ($exists === 0) {
      $this->db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
  }

  private function auditsTable(): string { return $this->db->table('scm_canon_insurance_audits'); }
  private function itemsTable(): string { return $this->db->table('scm_canon_insurance_audit_items'); }
  private function reportsTable(): string { return $this->db->table('scm_canon_insurance_reports'); }
  private function incrementSnapshotsTable(): string { return $this->db->table('scm_increment_snapshots'); }
  private function incrementChangesTable(): string { return $this->db->table('scm_increment_change_audit'); }
}
