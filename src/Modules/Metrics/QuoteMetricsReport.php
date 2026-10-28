<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Support\SchemaInspector;

/**
 * Métricas de cotizaciones de mantenimiento.
 *
 * - Creadas: por la fecha de la cotización (`fecha`, o `cct_created` si falta).
 * - Sin enviar: creadas que no tienen `se_envio = Si`.
 * - Aprobadas / administración ganada: estado `Aprobada` con `fecha_respuesta` en el mes.
 *   Si la cotización no tiene fecha de respuesta (respondidas fuera del panel), se usa la del
 *   caso vinculado (`fecha_respuesta_cotizacion_mantenimiento`); si tampoco, no cae en ningún mes.
 *
 * Para corregir datos, cada cotización trae una fecha de aprobación sugerida: la primera orden de
 * trabajo (una orden solo se hace con la cotización aprobada) o, si no hay, el acta de satisfacción;
 * y, si quedó sin inmueble ("No asignado"), el que se obtiene del caso: su campo `inmueble`, el
 * `id_inmueble_data` del contrato del caso o el código (`id_inmueble`) buscado en inmuebles.
 * Las fechas de respuesta que un funcionario corrige o confirma quedan registradas como revisadas
 * (`quote_response_reviews` en la configuración, con quién y cuándo) y dejan de marcarse como dudosas.
 *
 * Devuelve todas las cotizaciones en una sola respuesta para calcular el mes a mes en el navegador.
 */
final class QuoteMetricsReport
{
  public const COLUMNS = [
    'id', 'ticket', 'contrato', 'inmueble', 'direccion', 'destinatario', 'categoria', 'estado', 'enviada',
    'fecha', 'respuesta', 'respuesta_fuente', 'total', 'admin', 'sugerida', 'sugerida_fuente', 'inmueble_caso',
    'inmueble_fuente', 'contrato_caso', 'revisada',
  ];
  public const MAX_BATCH = 200;
  public const REVIEWS_SETTING = 'quote_response_reviews';
  public const EDITS_SETTING = 'quote_value_edits';

  private Database $db;
  private DateTimeZone $timezone;
  private ?Settings $settings;
  private ?SchemaInspector $schema = null;

  /** Sin `$settings` no se registran ni se leen las fechas revisadas. */
  public function __construct(Database $db, ?Settings $settings = null)
  {
    $this->db = $db;
    $this->settings = $settings;
    $this->timezone = new DateTimeZone('America/Bogota');
  }

  /** @return array<string,mixed> */
  public function snapshot(): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $schema = $this->schema();
    $rows = $this->db->getResults(
      "SELECT c.`_ID`, c.`id_ticket`, c.`contrato`, c.`inmueble`, c.`direccion`, c.`destinatario`, c.`categoria_cotizacion`,
              c.`estado`, c.`se_envio`, c.`fecha`, c.`cct_created`, c.`fecha_respuesta`, c.`total`, c.`total_admon`,
              c.`porcentaje_admon`, c.`total_materiales`, c.`total_mano_obra`, c.`total_maquinarias`, c.`total_otros_costos`,
              c.`id_acta_satisfaccion`, {$this->linkSelect($schema)}
       FROM `{$table}` c {$this->ticketJoin($schema)}
       WHERE LOWER(TRIM(COALESCE(c.`cct_status`, 'publish'))) = 'publish'
       ORDER BY c.`_ID` DESC"
    );
    $evidence = $this->approvalEvidence($schema);
    $resolved = $this->resolveProperties($rows, $schema);
    $reviews = $this->reviews();
    return [
      'columns' => self::COLUMNS,
      'rows' => array_map(function (array $row) use ($evidence, $resolved, $reviews): array {
        $id = (string) ($row['_ID'] ?? '');
        $row['resuelto'] = $resolved[$id] ?? null;
        $row['revisada'] = $reviews[$id]['fecha'] ?? null;
        return $this->mapRow($row, $evidence);
      }, $rows),
      'generated_at' => time(),
    ];
  }

  private function schema(): SchemaInspector
  {
    return $this->schema ??= new SchemaInspector($this->db);
  }

  private function ticketJoin(SchemaInspector $schema): string
  {
    $tickets = $this->db->table('jet_cct_tickets');
    return $schema->tableExists($tickets) ? "LEFT JOIN `{$tickets}` t ON CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_ticket`, ''))" : '';
  }

  /** Columnas de la cotización y del caso que enlazan con el inmueble y el contrato. */
  private function linkSelect(SchemaInspector $schema): string
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $tickets = $this->db->table('jet_cct_tickets');
    $hasTickets = $schema->tableExists($tickets);
    $fields = [];
    foreach (['id_contrato' => 'quote_id_contrato', 'id_inmueble' => 'quote_id_inmueble'] as $column => $alias) {
      $fields[] = ($schema->columnExists($table, $column) ? "c.`{$column}`" : "''") . " AS {$alias}";
    }
    foreach ([
      'fecha_respuesta_cotizacion_mantenimiento' => 'ticket_respuesta',
      'inmueble' => 'ticket_inmueble',
      'id_contrato' => 'ticket_id_contrato',
      'id_inmueble' => 'ticket_id_inmueble',
      'id_inmueble_data' => 'ticket_id_inmueble_data',
    ] as $column => $alias) {
      $fields[] = ($hasTickets && $schema->columnExists($tickets, $column) ? "t.`{$column}`" : "''") . " AS {$alias}";
    }
    return implode(', ', $fields);
  }

  /**
   * Inmueble (y contrato) asignable a las cotizaciones sin inmueble, en este orden: `inmueble` del
   * caso, `id_inmueble_data` del contrato del caso, `id_inmueble_data` del caso y, por último, el
   * código (`id_inmueble`) buscado en inmuebles cuando corresponde a uno solo.
   *
   * @param list<array<string,mixed>> $rows filas con las columnas de linkSelect()
   * @return array<string,array{inmueble:string,fuente:string,contrato:string,id_contrato:string}>
   */
  private function resolveProperties(array $rows, SchemaInspector $schema): array
  {
    $pending = array_values(array_filter($rows, static fn(array $row): bool => self::missingProperty((string) ($row['inmueble'] ?? ''))));
    if ($pending === []) {
      return [];
    }
    $contractOf = static fn(array $row): string => self::positiveId($row['ticket_id_contrato'] ?? '') ?: self::positiveId($row['quote_id_contrato'] ?? '');
    $contracts = [];
    $contractIds = array_values(array_unique(array_filter(array_map($contractOf, $pending))));
    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if ($contractIds !== [] && $schema->tableExists($contractsTable)) {
      foreach (array_chunk($contractIds, 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($this->db->getResults("SELECT `_ID`, `contrato`, `id_inmueble`, `id_inmueble_data` FROM `{$contractsTable}` WHERE `_ID` IN ({$marks})", array_map('intval', $chunk)) as $contract) {
          $contracts[(string) $contract['_ID']] = $contract;
        }
      }
    }
    $codesOf = static function (array $row, array $contract): array {
      return array_values(array_unique(array_filter(array_map(
        [self::class, 'positiveId'],
        [$row['quote_id_inmueble'] ?? '', $row['ticket_id_inmueble'] ?? '', $contract['id_inmueble'] ?? '']
      ))));
    };
    $codes = [];
    foreach ($pending as $row) {
      foreach ($codesOf($row, $contracts[$contractOf($row)] ?? []) as $code) {
        $codes[$code] = true;
      }
    }
    $byCode = [];
    $properties = $this->db->table('jet_cct_inmuebles');
    if ($codes !== [] && $schema->tableExists($properties)) {
      foreach (array_chunk(array_map('strval', array_keys($codes)), 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        foreach ($this->db->getResults(
          "SELECT `_ID`, TRIM(`codigo`) AS codigo FROM `{$properties}`
           WHERE `codigo` IN ({$marks}) AND LOWER(TRIM(COALESCE(`cct_status`, 'publish'))) = 'publish'",
          $chunk
        ) as $property) {
          $byCode[(string) $property['codigo']][(string) $property['_ID']] = true;
        }
      }
    }

    $out = [];
    foreach ($pending as $row) {
      $contractId = $contractOf($row);
      $contract = $contracts[$contractId] ?? [];
      $property = '';
      $source = '';
      if (self::positiveId($row['ticket_inmueble'] ?? '') !== '') {
        [$property, $source] = [self::positiveId($row['ticket_inmueble']), 'caso'];
      } elseif (self::positiveId($contract['id_inmueble_data'] ?? '') !== '') {
        [$property, $source] = [self::positiveId($contract['id_inmueble_data']), 'contrato'];
      } elseif (self::positiveId($row['ticket_id_inmueble_data'] ?? '') !== '') {
        [$property, $source] = [self::positiveId($row['ticket_id_inmueble_data']), 'caso'];
      } else {
        foreach ($codesOf($row, $contract) as $code) {
          // Códigos repetidos en inmuebles son ambiguos: no se asignan.
          if (count($byCode[$code] ?? []) === 1) {
            [$property, $source] = [(string) array_key_first($byCode[$code]), 'codigo'];
            break;
          }
        }
      }
      if ($property === '') {
        continue;
      }
      $contractNumber = self::text($contract['contrato'] ?? '');
      $out[(string) $row['_ID']] = [
        'inmueble' => $property,
        'fuente' => $source,
        'contrato' => self::missingContract($contractNumber) ? '' : $contractNumber,
        'id_contrato' => $contractId,
      ];
    }
    return $out;
  }

  private static function positiveId($value): string
  {
    $value = trim((string) ($value ?? ''));
    return preg_match('/^\d+$/', $value) === 1 && (int) $value > 0 ? $value : '';
  }

  private static function missingContract(string $value): bool
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return $value === '' || $value === '0' || $value === 'no asignado';
  }

  /** @return array<string,array{fecha:string,por:string,el:string}> */
  private function reviews(): array
  {
    $reviews = $this->settings ? $this->settings->get(self::REVIEWS_SETTING, []) : [];
    return is_array($reviews) ? $reviews : [];
  }

  /** @param list<array{id:int,respuesta:string}> $updated */
  private function recordReviews(array $updated, string $actorEmployeeId): void
  {
    if ($updated === [] || $this->settings === null) {
      return;
    }
    $this->settings->refresh();
    $reviews = $this->reviews();
    $now = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s');
    foreach ($updated as $item) {
      $reviews[(string) $item['id']] = ['fecha' => $item['respuesta'], 'por' => $actorEmployeeId, 'el' => $now];
    }
    $this->settings->set(self::REVIEWS_SETTING, $reviews, (int) $actorEmployeeId);
  }

  /**
   * @param array<string,mixed> $row
   * @param array{ordenes?:array<string,int>,historial?:array<string,int>,actas?:array<string,int>,actas_por_id?:array<string,int>} $evidence
   * @return list<int|string|null>
   */
  public function mapRow(array $row, array $evidence = []): array
  {
    $id = (string) ($row['_ID'] ?? '');
    $response = $this->date($row['fecha_respuesta'] ?? null);
    $responseSource = $response !== null ? 'cotizacion' : '';
    if ($response === null) {
      $response = $this->date($row['ticket_respuesta'] ?? null);
      $responseSource = $response !== null ? 'caso' : '';
    }
    $created = $this->date($row['fecha'] ?? null) ?? $this->createdDate($row['cct_created'] ?? null);

    // Fecha sugerida de aprobación: primera orden de trabajo; si no hay, el acta de satisfacción.
    $suggested = null;
    $suggestedSource = '';
    $orders = array_filter([$evidence['ordenes'][$id] ?? null, $evidence['historial'][$id] ?? null]);
    $candidates = [];
    if ($orders !== []) {
      $candidates[] = [min($orders), 'orden'];
    }
    $actaId = trim((string) ($row['id_acta_satisfaccion'] ?? ''));
    $acta = $evidence['actas'][$id] ?? ($actaId !== '' ? ($evidence['actas_por_id'][$actaId] ?? null) : null);
    if ($acta) {
      $candidates[] = [$acta, 'acta'];
    }
    foreach ($candidates as [$timestamp, $source]) {
      $date = $this->date($timestamp);
      if ($date !== null && ($created === null || $date >= $created)) {
        $suggested = $date;
        $suggestedSource = $source;
        break;
      }
    }

    $resolved = is_array($row['resuelto'] ?? null) ? $row['resuelto'] : null;
    $caseProperty = $resolved['inmueble'] ?? self::text($row['ticket_inmueble'] ?? '');

    return [
      (int) $id,
      self::text($row['id_ticket'] ?? ''),
      self::text($row['contrato'] ?? ''),
      self::text($row['inmueble'] ?? ''),
      self::text($row['direccion'] ?? ''),
      self::text($row['destinatario'] ?? ''),
      self::text($row['categoria_cotizacion'] ?? '') ?: 'Sin categoría',
      self::text($row['estado'] ?? '') ?: 'Sin estado',
      mb_strtolower(self::text($row['se_envio'] ?? ''), 'UTF-8') === 'si' ? 1 : 0,
      $created,
      $response,
      $responseSource,
      ContractMovementsReport::parseMoney($row['total'] ?? null),
      self::adminAmount($row),
      $suggested,
      $suggestedSource,
      preg_match('/^\d+$/', $caseProperty) === 1 ? $caseProperty : '',
      $resolved['fuente'] ?? (preg_match('/^\d+$/', $caseProperty) === 1 ? 'caso' : ''),
      $resolved['contrato'] ?? '',
      $response !== null && $responseSource === 'cotizacion' && ($row['revisada'] ?? null) === $response ? 1 : 0,
    ];
  }

  /** Cotización sin inmueble útil: vacío o "No asignado". */
  public static function missingProperty(string $value): bool
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return $value === '' || $value === 'no asignado' || preg_match('/^\d+$/', $value) !== 1;
  }

  /**
   * Compatibilidad: completa la fecha de una aprobada que no la tiene.
   *
   * @return array{id:int,respuesta:string}
   */
  public function setMissingResponseDate(int $quoteId, string $date, string $actorEmployeeId): array
  {
    return $this->setResponseDate($quoteId, $date, $actorEmployeeId, false);
  }

  /**
   * Fija la fecha de respuesta de una cotización aprobada. Sin `$overwrite` solo completa
   * fechas vacías; con `$overwrite` corrige una existente.
   *
   * @return array{id:int,respuesta:string,anterior:?string}
   */
  public function setResponseDate(int $quoteId, string $date, string $actorEmployeeId, bool $overwrite): array
  {
    if ($quoteId <= 0) {
      throw new \InvalidArgumentException('Cotización inválida.');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
      throw new \InvalidArgumentException('Escribe una fecha válida.');
    }
    if ($actorEmployeeId === '') {
      throw new \RuntimeException('Tu usuario no tiene un id_empleado registrado.');
    }
    $today = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d');
    if ($date > $today) {
      throw new \InvalidArgumentException('La fecha de respuesta no puede ser futura.');
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $quote = $this->db->getRow("SELECT `_ID`, `estado`, `fecha`, `cct_created`, `fecha_respuesta` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$quoteId]);
    if (!$quote) {
      throw new \InvalidArgumentException('La cotización ya no existe.');
    }
    if (trim((string) ($quote['estado'] ?? '')) !== 'Aprobada') {
      throw new \InvalidArgumentException('Solo se completa la fecha de cotizaciones aprobadas.');
    }
    $previous = $this->date($quote['fecha_respuesta'] ?? null);
    if ($previous !== null && !$overwrite) {
      throw new \InvalidArgumentException('La cotización ya tiene fecha de respuesta.');
    }
    $created = $this->date($quote['fecha'] ?? null) ?? $this->createdDate($quote['cct_created'] ?? null);
    if ($created !== null && $date < $created) {
      throw new \InvalidArgumentException('La fecha de respuesta no puede ser anterior a la creación (' . $created . ').');
    }
    // Mediodía en Colombia para que la fecha no cambie de día por zona horaria.
    $timestamp = (new DateTimeImmutable($date . ' 12:00:00', $this->timezone))->getTimestamp();
    $guard = $overwrite ? 'CAST(COALESCE(`fecha_respuesta`, 0) AS SIGNED) = ?' : 'CAST(COALESCE(`fecha_respuesta`, 0) AS SIGNED) = 0';
    $args = [$timestamp, (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s'), $quoteId];
    if ($overwrite) {
      $args[] = (int) ($quote['fecha_respuesta'] ?? 0);
    }
    $stmt = $this->db->pdo()->prepare(
      "UPDATE `{$table}` SET `fecha_respuesta` = ?, `cct_modified` = ?
       WHERE `_ID` = ? AND `estado` = 'Aprobada' AND {$guard}"
    );
    $stmt->execute($args);
    if ($stmt->rowCount() !== 1) {
      throw new \RuntimeException('Otra persona actualizó la cotización; recarga y vuelve a intentar.');
    }
    return ['id' => $quoteId, 'respuesta' => $date, 'anterior' => $previous];
  }

  /**
   * Varias fechas a la vez; cada cotización se valida por separado.
   *
   * @param list<array{id:int,date:string}> $changes
   * @return array{updated:list<array<string,mixed>>,errors:list<array{id:int,message:string}>}
   */
  public function setResponseDates(array $changes, string $actorEmployeeId, bool $overwrite): array
  {
    if ($changes === [] || count($changes) > self::MAX_BATCH) {
      throw new \InvalidArgumentException('Selecciona entre 1 y ' . self::MAX_BATCH . ' cotizaciones.');
    }
    $updated = [];
    $errors = [];
    foreach ($changes as $change) {
      $id = (int) ($change['id'] ?? 0);
      try {
        $updated[] = $this->setResponseDate($id, (string) ($change['date'] ?? ''), $actorEmployeeId, $overwrite);
      } catch (\InvalidArgumentException | \RuntimeException $error) {
        $errors[] = ['id' => $id, 'message' => $error->getMessage()];
      }
    }
    // Trazabilidad de quién revisó cada fecha; las revisadas dejan de marcarse como dudosas.
    $this->recordReviews($updated, $actorEmployeeId);
    return ['updated' => $updated, 'errors' => $errors];
  }

  /**
   * Asigna a la cotización sin inmueble (vacío o "No asignado") el que se obtiene de su caso o del
   * contrato del caso (ver resolveProperties()); también el contrato si le falta. No cambia inmuebles ya asignados.
   *
   * @param list<int> $quoteIds
   * @return array{updated:list<array{id:int,inmueble:string,fuente:string}>,skipped:list<int>}
   */
  public function assignCaseProperty(array $quoteIds, string $actorEmployeeId): array
  {
    $quoteIds = array_values(array_unique(array_filter(array_map('intval', $quoteIds), static fn(int $id): bool => $id > 0)));
    if ($quoteIds === [] || count($quoteIds) > self::MAX_BATCH) {
      throw new \InvalidArgumentException('Selecciona entre 1 y ' . self::MAX_BATCH . ' cotizaciones.');
    }
    if ($actorEmployeeId === '') {
      throw new \RuntimeException('Tu usuario no tiene un id_empleado registrado.');
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $schema = $this->schema();
    $marks = implode(',', array_fill(0, count($quoteIds), '?'));
    $rows = $this->db->getResults(
      "SELECT c.`_ID`, c.`inmueble`, c.`contrato`, {$this->linkSelect($schema)}
       FROM `{$table}` c {$this->ticketJoin($schema)} WHERE c.`_ID` IN ({$marks})",
      $quoteIds
    );
    $resolved = $this->resolveProperties($rows, $schema);
    $hasContractId = $schema->columnExists($table, 'id_contrato');
    $now = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s');
    $updated = [];
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $match = $resolved[(string) $id] ?? null;
      if ($match === null) {
        continue;
      }
      $data = ['inmueble' => $match['inmueble'], 'cct_modified' => $now];
      if ($match['contrato'] !== '' && self::missingContract((string) ($row['contrato'] ?? ''))) {
        $data['contrato'] = $match['contrato'];
      }
      if ($hasContractId && $match['id_contrato'] !== '' && self::positiveId($row['quote_id_contrato'] ?? '') === '') {
        $data['id_contrato'] = $match['id_contrato'];
      }
      $this->db->update($table, $data, ['_ID' => $id]);
      $updated[] = ['id' => $id, 'inmueble' => $match['inmueble'], 'fuente' => $match['fuente']];
    }
    $done = array_column($updated, 'id');
    $skipped = array_values(array_filter($quoteIds, static fn(int $id): bool => !in_array($id, $done, true)));
    return ['updated' => $updated, 'skipped' => $skipped];
  }

  /**
   * Corrige el valor total y/o la administración (sin IVA) de una cotización desde la conciliación.
   * Al cambiar la administración se recalculan su IVA (`iva_admon`, `total_admon_mas_iva`) y el
   * porcentaje; si no se envía un total nuevo, el total se ajusta por la diferencia de la
   * administración con IVA. Cada cambio queda en `quote_value_edits` (antes, después, quién, cuándo).
   *
   * @return array{id:int,total:int,admin:int}
   */
  public function updateValues(int $quoteId, ?int $total, ?int $admin, string $actorEmployeeId): array
  {
    if ($quoteId <= 0) {
      throw new \InvalidArgumentException('Cotización inválida.');
    }
    if ($actorEmployeeId === '') {
      throw new \RuntimeException('Tu usuario no tiene un id_empleado registrado.');
    }
    if (($total !== null && $total < 0) || ($admin !== null && $admin < 0)) {
      throw new \InvalidArgumentException('Los valores no pueden ser negativos.');
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $quote = $this->db->getRow(
      "SELECT `_ID`, `total`, `total_admon`, `porcentaje_admon`, `iva`, `iva_admon`, `total_admon_mas_iva`,
              `total_materiales`, `total_mano_obra`, `total_maquinarias`, `total_otros_costos`
       FROM `{$table}` WHERE `_ID` = ? LIMIT 1",
      [$quoteId]
    );
    if (!$quote) {
      throw new \InvalidArgumentException('La cotización ya no existe.');
    }
    $oldTotal = ContractMovementsReport::parseMoney($quote['total'] ?? null);
    $oldAdmin = self::adminAmount($quote);
    $ivaText = trim((string) ($quote['iva'] ?? ''));
    $ivaRate = $ivaText === '' ? 19.0 : (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $ivaText) ?? '19');
    $oldAdminWithIva = ContractMovementsReport::parseMoney($quote['total_admon_mas_iva'] ?? null) ?: $oldAdmin + (int) round($oldAdmin * $ivaRate / 100);

    $data = [];
    $newTotal = $total ?? $oldTotal;
    $newAdmin = $admin ?? $oldAdmin;
    if ($admin !== null && $admin !== $oldAdmin) {
      $ivaAdmin = (int) round($admin * $ivaRate / 100);
      $data['total_admon'] = (string) $admin;
      $data['iva_admon'] = (string) $ivaAdmin;
      $data['total_admon_mas_iva'] = (string) ($admin + $ivaAdmin);
      $subtotal = 0;
      foreach (['total_materiales', 'total_mano_obra', 'total_maquinarias', 'total_otros_costos'] as $field) {
        $subtotal += ContractMovementsReport::parseMoney($quote[$field] ?? null);
      }
      if ($subtotal > 0) {
        $data['porcentaje_admon'] = rtrim(rtrim(number_format($admin / $subtotal * 100, 2, '.', ''), '0'), '.') ?: '0';
      }
      if ($total === null) {
        $newTotal = max(0, $oldTotal - $oldAdminWithIva + $admin + $ivaAdmin);
      }
    }
    if ($newTotal !== $oldTotal) {
      $data['total'] = (string) $newTotal;
    }
    if ($newAdmin > $newTotal) {
      throw new \InvalidArgumentException('La administración no puede ser mayor que el valor total.');
    }
    if ($data === []) {
      return ['id' => $quoteId, 'total' => $oldTotal, 'admin' => $oldAdmin];
    }
    $now = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s');
    $this->db->update($table, $data + ['cct_modified' => $now], ['_ID' => $quoteId]);

    $this->logEdit($quoteId, ['total' => $oldTotal, 'admin' => $oldAdmin], ['total' => $newTotal, 'admin' => $newAdmin], $actorEmployeeId, $now);
    return ['id' => $quoteId, 'total' => $newTotal, 'admin' => $newAdmin];
  }

  public const STATES = ['Esperando respuesta', 'Aprobada', 'Desaprobada'];

  /**
   * Corrige estado, fecha de creación y/o fecha de respuesta de una cotización (corrección de datos:
   * no notifica al cliente ni toca el caso). "Esperando respuesta" deja la cotización sin fecha de
   * respuesta; "Aprobada" y "Desaprobada" la exigen. Las fechas no pueden ser futuras y la respuesta
   * no puede ser anterior a la creación. Queda registrado en `quote_value_edits`.
   *
   * @return array{id:int,estado:string,fecha:?string,respuesta:?string}
   */
  public function updateStatusAndDates(int $quoteId, ?string $state, ?string $created, ?string $response, string $actorEmployeeId): array
  {
    if ($quoteId <= 0) {
      throw new \InvalidArgumentException('Cotización inválida.');
    }
    if ($actorEmployeeId === '') {
      throw new \RuntimeException('Tu usuario no tiene un id_empleado registrado.');
    }
    if ($state !== null && !in_array($state, self::STATES, true)) {
      throw new \InvalidArgumentException('Estado no válido.');
    }
    $today = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d');
    foreach (['creación' => $created, 'respuesta' => $response] as $label => $date) {
      if ($date === null) continue;
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        throw new \InvalidArgumentException('La fecha de ' . $label . ' no es válida.');
      }
      if ($date > $today) {
        throw new \InvalidArgumentException('La fecha de ' . $label . ' no puede ser futura.');
      }
    }
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $quote = $this->db->getRow("SELECT `_ID`, `estado`, `fecha`, `cct_created`, `fecha_respuesta` FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$quoteId]);
    if (!$quote) {
      throw new \InvalidArgumentException('La cotización ya no existe.');
    }
    $oldState = self::text($quote['estado'] ?? '') ?: 'Sin estado';
    $oldCreated = $this->date($quote['fecha'] ?? null) ?? $this->createdDate($quote['cct_created'] ?? null);
    $oldResponse = $this->date($quote['fecha_respuesta'] ?? null);

    $newState = $state ?? $oldState;
    $newCreated = $created ?? $oldCreated;
    $newResponse = $newState === 'Esperando respuesta' ? null : ($response ?? $oldResponse);
    if (in_array($newState, ['Aprobada', 'Desaprobada'], true) && $newResponse === null) {
      throw new \InvalidArgumentException('Indica la fecha de respuesta de la cotización ' . strtolower($newState) . '.');
    }
    if ($newCreated !== null && $newResponse !== null && $newResponse < $newCreated) {
      throw new \InvalidArgumentException('La fecha de respuesta no puede ser anterior a la creación (' . $newCreated . ').');
    }

    // Mediodía en Colombia para que la fecha no cambie de día por zona horaria.
    $noon = fn(string $date): int => (new DateTimeImmutable($date . ' 12:00:00', $this->timezone))->getTimestamp();
    $data = [];
    if ($newState !== $oldState) {
      $data['estado'] = $newState;
      if ($this->schema()->columnExists($table, 'estado_respuesta_cotizacion_mantenimiento')) {
        $data['estado_respuesta_cotizacion_mantenimiento'] = $newState;
      }
    }
    if ($newCreated !== $oldCreated && $newCreated !== null) {
      $data['fecha'] = $noon($newCreated);
    }
    if ($newResponse !== $oldResponse) {
      $data['fecha_respuesta'] = $newResponse === null ? 0 : $noon($newResponse);
    }
    $result = ['id' => $quoteId, 'estado' => $newState, 'fecha' => $newCreated, 'respuesta' => $newResponse];
    if ($data === []) {
      return $result;
    }
    $now = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s');
    $this->db->update($table, $data + ['cct_modified' => $now], ['_ID' => $quoteId]);
    if ($newResponse !== null && $newResponse !== $oldResponse) {
      $this->recordReviews([['id' => $quoteId, 'respuesta' => $newResponse]], $actorEmployeeId);
    }
    $this->logEdit($quoteId, ['estado' => $oldState, 'fecha' => $oldCreated, 'respuesta' => $oldResponse], ['estado' => $newState, 'fecha' => $newCreated, 'respuesta' => $newResponse], $actorEmployeeId, $now);
    return $result;
  }

  /** @param array<string,mixed> $before @param array<string,mixed> $after */
  private function logEdit(int $quoteId, array $before, array $after, string $actorEmployeeId, string $now): void
  {
    if ($this->settings === null) {
      return;
    }
    $this->settings->refresh();
    $log = $this->settings->get(self::EDITS_SETTING, []);
    $log = is_array($log) ? $log : [];
    $log[] = ['id' => $quoteId, 'antes' => $before, 'despues' => $after, 'por' => $actorEmployeeId, 'el' => $now];
    $this->settings->set(self::EDITS_SETTING, array_slice($log, -2000), (int) $actorEmployeeId);
  }

  /** Administración sin IVA: `total_admon` o, si falta, subtotal × `porcentaje_admon`. */
  public static function adminAmount(array $row): int
  {
    $admin = ContractMovementsReport::parseMoney($row['total_admon'] ?? null);
    if ($admin > 0) {
      return $admin;
    }
    $subtotal = 0;
    foreach (['total_materiales', 'total_mano_obra', 'total_maquinarias', 'total_otros_costos'] as $field) {
      $subtotal += ContractMovementsReport::parseMoney($row[$field] ?? null);
    }
    $percentage = (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', (string) ($row['porcentaje_admon'] ?? '')) ?? '');
    return $subtotal > 0 && $percentage > 0 ? (int) round($subtotal * $percentage / 100) : 0;
  }

  /**
   * Primera orden de trabajo y acta de satisfacción por cotización (timestamps).
   *
   * @return array{ordenes:array<string,int>,historial:array<string,int>,actas:array<string,int>,actas_por_id:array<string,int>}
   */
  private function approvalEvidence(SchemaInspector $schema): array
  {
    $out = ['ordenes' => [], 'historial' => [], 'actas' => [], 'actas_por_id' => []];
    $orders = $this->db->table('jet_cct_ordenes');
    if ($schema->tableExists($orders) && $schema->columnExists($orders, 'id_cotizacion')) {
      foreach ($this->db->getResults("SELECT TRIM(`id_cotizacion`) AS id, MIN(NULLIF(`fecha`, 0)) AS ts FROM `{$orders}` WHERE TRIM(COALESCE(`id_cotizacion`, '')) <> '' GROUP BY TRIM(`id_cotizacion`)") as $row) {
        if ((int) $row['ts'] > 0) $out['ordenes'][(string) $row['id']] = (int) $row['ts'];
      }
    }
    $history = $this->db->table('jet_cct_historial_del_ticket');
    if ($schema->tableExists($history) && $schema->columnExists($history, 'id_cotizacion_mantenimiento')) {
      foreach ($this->db->getResults(
        "SELECT TRIM(`id_cotizacion_mantenimiento`) AS id, MIN(`fecha`) AS ts FROM `{$history}`
         WHERE TRIM(COALESCE(`id_cotizacion_mantenimiento`, '')) <> ''
           AND (TRIM(COALESCE(`id_orden`, '')) <> '' OR LOWER(COALESCE(`respuesta`, '')) LIKE '%elaborado orden%')
         GROUP BY TRIM(`id_cotizacion_mantenimiento`)"
      ) as $row) {
        if ((int) $row['ts'] > 0) $out['historial'][(string) $row['id']] = (int) $row['ts'];
      }
    }
    $acts = $this->db->table('jet_cct_actas_de_satisfaccion');
    if ($schema->tableExists($acts)) {
      foreach ($this->db->getResults("SELECT `_ID`, TRIM(COALESCE(`id_cotizacion`, '')) AS id, `fecha` FROM `{$acts}` WHERE COALESCE(`fecha`, 0) > 0") as $row) {
        $out['actas_por_id'][(string) $row['_ID']] = (int) $row['fecha'];
        $quoteId = (string) $row['id'];
        if ($quoteId !== '' && (!isset($out['actas'][$quoteId]) || (int) $row['fecha'] < $out['actas'][$quoteId])) {
          $out['actas'][$quoteId] = (int) $row['fecha'];
        }
      }
    }
    return $out;
  }

  public function date($value): ?string
  {
    $raw = trim((string) ($value ?? ''));
    if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1262304000) {
      return null;
    }
    return (new DateTimeImmutable('@' . (int) $raw))->setTimezone($this->timezone)->format('Y-m-d');
  }

  private function createdDate($value): ?string
  {
    return preg_match('/^(\d{4}-\d{2}-\d{2})/', trim((string) ($value ?? '')), $match) === 1 ? $match[1] : null;
  }

  private static function text($value): string
  {
    return trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
  }
}
