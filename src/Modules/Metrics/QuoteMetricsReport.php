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
 * Devuelve todas las cotizaciones en una sola respuesta para calcular el mes a mes en el navegador.
 */
final class QuoteMetricsReport
{
  public const COLUMNS = ['id', 'ticket', 'contrato', 'inmueble', 'direccion', 'destinatario', 'categoria', 'estado', 'enviada', 'fecha', 'respuesta', 'respuesta_fuente', 'total', 'admin'];

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
    $ticketJoin = $schema->tableExists($tickets) && $schema->columnExists($tickets, 'fecha_respuesta_cotizacion_mantenimiento')
      ? "LEFT JOIN `{$tickets}` t ON CAST(t.`_ID` AS CHAR) = TRIM(COALESCE(c.`id_ticket`, ''))"
      : '';
    $ticketSelect = $ticketJoin !== '' ? 't.`fecha_respuesta_cotizacion_mantenimiento` AS ticket_respuesta' : "'' AS ticket_respuesta";
    $rows = $this->db->getResults(
      "SELECT c.`_ID`, c.`id_ticket`, c.`contrato`, c.`inmueble`, c.`direccion`, c.`destinatario`, c.`categoria_cotizacion`,
              c.`estado`, c.`se_envio`, c.`fecha`, c.`cct_created`, c.`fecha_respuesta`, c.`total`, c.`total_admon`,
              c.`porcentaje_admon`, c.`total_materiales`, c.`total_mano_obra`, c.`total_maquinarias`, c.`total_otros_costos`,
              {$ticketSelect}
       FROM `{$table}` c {$ticketJoin}
       WHERE LOWER(TRIM(COALESCE(c.`cct_status`, 'publish'))) = 'publish'
       ORDER BY c.`_ID` DESC"
    );
    return [
      'columns' => self::COLUMNS,
      'rows' => array_map([$this, 'mapRow'], $rows),
      'generated_at' => time(),
    ];
  }

  /**
   * @param array<string,mixed> $row
   * @return list<int|string|null>
   */
  public function mapRow(array $row): array
  {
    $response = $this->date($row['fecha_respuesta'] ?? null);
    $responseSource = $response !== null ? 'cotizacion' : '';
    if ($response === null) {
      $response = $this->date($row['ticket_respuesta'] ?? null);
      $responseSource = $response !== null ? 'caso' : '';
    }
    return [
      (int) ($row['_ID'] ?? 0),
      self::text($row['id_ticket'] ?? ''),
      self::text($row['contrato'] ?? ''),
      self::text($row['inmueble'] ?? ''),
      self::text($row['direccion'] ?? ''),
      self::text($row['destinatario'] ?? ''),
      self::text($row['categoria_cotizacion'] ?? '') ?: 'Sin categoría',
      self::text($row['estado'] ?? '') ?: 'Sin estado',
      mb_strtolower(self::text($row['se_envio'] ?? ''), 'UTF-8') === 'si' ? 1 : 0,
      $this->date($row['fecha'] ?? null) ?? $this->createdDate($row['cct_created'] ?? null),
      $response,
      $responseSource,
      ContractMovementsReport::parseMoney($row['total'] ?? null),
      self::adminAmount($row),
    ];
  }

  /**
   * Completa la fecha de respuesta de una cotización aprobada que no la tiene.
   * Solo aplica si sigue en estado Aprobada y sin fecha (no reemplaza fechas existentes).
   *
   * @return array{id:int,respuesta:string}
   */
  public function setMissingResponseDate(int $quoteId, string $date, string $actorEmployeeId): array
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
    if ((int) ($quote['fecha_respuesta'] ?? 0) > 0) {
      throw new \InvalidArgumentException('La cotización ya tiene fecha de respuesta.');
    }
    $created = $this->date($quote['fecha'] ?? null) ?? $this->createdDate($quote['cct_created'] ?? null);
    if ($created !== null && $date < $created) {
      throw new \InvalidArgumentException('La fecha de respuesta no puede ser anterior a la creación (' . $created . ').');
    }
    // Mediodía en Colombia para que la fecha no cambie de día por zona horaria.
    $timestamp = (new DateTimeImmutable($date . ' 12:00:00', $this->timezone))->getTimestamp();
    $stmt = $this->db->pdo()->prepare(
      "UPDATE `{$table}` SET `fecha_respuesta` = ?, `cct_modified` = ?, `cct_author_id` = ?
       WHERE `_ID` = ? AND `estado` = 'Aprobada' AND CAST(COALESCE(`fecha_respuesta`, 0) AS SIGNED) = 0"
    );
    $stmt->execute([$timestamp, (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d H:i:s'), $actorEmployeeId, $quoteId]);
    if ($stmt->rowCount() !== 1) {
      throw new \RuntimeException('Otra persona actualizó la cotización; recarga y vuelve a intentar.');
    }
    return ['id' => $quoteId, 'respuesta' => $date];
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

  private function date($value): ?string
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
