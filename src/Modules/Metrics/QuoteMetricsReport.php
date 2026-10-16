<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
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
 * y el inmueble del caso, del que se copia el código cuando la cotización quedó "No asignado".
 *
 * Devuelve todas las cotizaciones en una sola respuesta para calcular el mes a mes en el navegador.
 */
final class QuoteMetricsReport
{
  public const COLUMNS = [
    'id', 'ticket', 'contrato', 'inmueble', 'direccion', 'destinatario', 'categoria', 'estado', 'enviada',
    'fecha', 'respuesta', 'respuesta_fuente', 'total', 'admin', 'sugerida', 'sugerida_fuente', 'inmueble_caso',
  ];
  public const MAX_BATCH = 200;

  private Database $db;
  private DateTimeZone $timezone;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->timezone = new DateTimeZone('America/Bogota');
  }

  /** @return array<string,mixed> */
  public function snapshot(): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    $tickets = $this->db->table('jet_cct_tickets');
    $schema = new SchemaInspector($this->db);
    $hasTickets = $schema->tableExists($tickets);
    $ticketJoin = $hasTickets ? "LEFT JOIN `{$tickets}` t ON CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_ticket`, ''))" : '';
    $ticketSelect = $hasTickets
      ? ($schema->columnExists($tickets, 'fecha_respuesta_cotizacion_mantenimiento') ? 't.`fecha_respuesta_cotizacion_mantenimiento`' : "''") . ' AS ticket_respuesta, '
        . ($schema->columnExists($tickets, 'inmueble') ? 't.`inmueble`' : "''") . ' AS ticket_inmueble'
      : "'' AS ticket_respuesta, '' AS ticket_inmueble";
    $rows = $this->db->getResults(
      "SELECT c.`_ID`, c.`id_ticket`, c.`contrato`, c.`inmueble`, c.`direccion`, c.`destinatario`, c.`categoria_cotizacion`,
              c.`estado`, c.`se_envio`, c.`fecha`, c.`cct_created`, c.`fecha_respuesta`, c.`total`, c.`total_admon`,
              c.`porcentaje_admon`, c.`total_materiales`, c.`total_mano_obra`, c.`total_maquinarias`, c.`total_otros_costos`,
              c.`id_acta_satisfaccion`, {$ticketSelect}
       FROM `{$table}` c {$ticketJoin}
       WHERE LOWER(TRIM(COALESCE(c.`cct_status`, 'publish'))) = 'publish'
       ORDER BY c.`_ID` DESC"
    );
    $evidence = $this->approvalEvidence($schema);
    return [
      'columns' => self::COLUMNS,
      'rows' => array_map(fn(array $row): array => $this->mapRow($row, $evidence), $rows),
      'generated_at' => time(),
    ];
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

    $caseProperty = self::text($row['ticket_inmueble'] ?? '');

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
    $args = [$timestamp, (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s'), $actorEmployeeId, $quoteId];
    if ($overwrite) {
      $args[] = (int) ($quote['fecha_respuesta'] ?? 0);
    }
    $stmt = $this->db->pdo()->prepare(
      "UPDATE `{$table}` SET `fecha_respuesta` = ?, `cct_modified` = ?, `cct_author_id` = ?
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
    return ['updated' => $updated, 'errors' => $errors];
  }

  /**
   * Copia a la cotización el código de inmueble de su caso cuando quedó vacío o "No asignado".
   *
   * @param list<int> $quoteIds
   * @return array{updated:list<array{id:int,inmueble:string}>,skipped:list<int>}
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
    $tickets = $this->db->table('jet_cct_tickets');
    $now = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s');
    $updated = [];
    $skipped = [];
    foreach ($quoteIds as $id) {
      $row = $this->db->getRow(
        "SELECT c.`inmueble`, t.`inmueble` AS ticket_inmueble FROM `{$table}` c
         LEFT JOIN `{$tickets}` t ON CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_ticket`, ''))
         WHERE c.`_ID` = ? LIMIT 1",
        [$id]
      );
      $caseProperty = trim((string) ($row['ticket_inmueble'] ?? ''));
      if (!$row || !self::missingProperty((string) ($row['inmueble'] ?? '')) || preg_match('/^\d+$/', $caseProperty) !== 1) {
        $skipped[] = $id;
        continue;
      }
      $this->db->update($table, ['inmueble' => $caseProperty, 'cct_modified' => $now, 'cct_author_id' => $actorEmployeeId], ['_ID' => $id]);
      $updated[] = ['id' => $id, 'inmueble' => $caseProperty];
    }
    return ['updated' => $updated, 'skipped' => $skipped];
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
