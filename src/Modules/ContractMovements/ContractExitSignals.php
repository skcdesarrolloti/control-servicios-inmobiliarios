<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Situación de los contratos que terminan: la fecha fin no dice si el inquilino se va,
 * porque la mayoría se prorroga. Cartera solo debe ver como salida lo que tiene un aviso real.
 *
 * - Con aviso de salida: estado Por recibir, o caso de recibo, terminación o no prórroga de este ciclo.
 * - Continúa: renovación al 100 % o no salida reportada en Contratos por terminar.
 * - Sin aviso: termina, pero nadie ha informado qué va a pasar.
 */
final class ContractExitSignals
{
  public const EXIT = 'salida';
  public const UNCONFIRMED = 'sin_aviso';
  public const STAYS = 'continua';

  /** En orden de prioridad: el recibo es la señal más firme de que el inmueble se entrega. */
  private const TOPICS = [
    'recibo de inmuebles' => 'Recibo de inmueble',
    'recibo de inmueble' => 'Recibo de inmueble',
    'terminacion de contrato' => 'Terminación solicitada',
    'terminación de contrato' => 'Terminación solicitada',
    'no prorroga de contrato' => 'No prórroga solicitada',
    'no prórroga de contrato' => 'No prórroga solicitada',
  ];

  /** Sin fecha fin en el caso, cuenta si se creó en los 180 días previos al fin: los anteriores son de otro ciclo. */
  private const CYCLE_SECONDS = 15552000;

  private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

  public function __construct(private Database $db) {}

  /**
   * @param list<array<string,mixed>> $rows Contratos con _ID, contrato, estado y fin_contrato.
   * @return array<int,array{situacion:string,detalle:string}>
   */
  public function forContracts(array $rows): array
  {
    $active = [];
    foreach ($rows as $row) {
      $state = mb_strtolower(trim((string) ($row['estado'] ?? '')), 'UTF-8');
      if (in_array($state, ['entregado', 'por recibir'], true) && self::timestamp($row['fin_contrato'] ?? null) > 0) {
        $active[(int) ($row['_ID'] ?? 0)] = $row + ['_state' => $state];
      }
    }
    if ($active === []) {
      return [];
    }

    $renewals = $this->renewals(array_keys($active));
    [$byId, $byCode] = $this->tickets();
    $out = [];
    foreach ($active as $id => $row) {
      $code = trim((string) ($row['contrato'] ?? ''));
      $tickets = array_merge($byId[(string) $id] ?? [], $code !== '' ? ($byCode[$code] ?? []) : []);
      [$situation, $detail] = self::classify($row['_state'], self::timestamp($row['fin_contrato']), $renewals[$id] ?? [], $tickets);
      $out[$id] = ['situacion' => $situation, 'detalle' => $detail];
    }
    return $out;
  }

  /**
   * @param array<string,mixed> $renewal
   * @param list<array<string,mixed>> $tickets Casos del contrato con tema, fecha y fecha_terminacion.
   * @return array{0:string,1:string}
   */
  public static function classify(string $state, int $endTs, array $renewal, array $tickets): array
  {
    if ($state === 'por recibir') {
      return [self::EXIT, 'Por recibir'];
    }
    if ($renewal !== [] && self::timestamp($renewal['end_ts'] ?? null) === $endTs) {
      if (!empty($renewal['no_exit'])) {
        return [self::STAYS, 'No saldrá del inmueble'];
      }
      if (($renewal['probability'] ?? null) !== null && (float) $renewal['probability'] >= 100) {
        return [self::STAYS, 'Renueva'];
      }
    }
    $found = [];
    foreach ($tickets as $ticket) {
      if (self::inCycle($ticket, $endTs)) {
        $found[self::TOPICS[(string) ($ticket['tema'] ?? '')] ?? ''] = true;
      }
    }
    foreach (array_unique(self::TOPICS) as $label) {
      if (isset($found[$label])) {
        return [self::EXIT, $label];
      }
    }
    return [self::UNCONFIRMED, 'Sin aviso de salida'];
  }

  /**
   * Grupos del popup: separa lo que sale de lo que solo termina, para no dejar de facturar por una fecha.
   * @param list<array<string,mixed>> $rows Filas de ContractMovementsReport::endingInMonth().
   * @return list<array<string,mixed>>
   */
  public static function alertGroups(array $rows, int $year, int $month): array
  {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['situacion'] !== self::STAYS));
    if ($rows === []) {
      return [];
    }
    $period = self::MONTHS[$month - 1] . ' de ' . $year;
    $note = count($rows) . (count($rows) === 1 ? ' contrato termina en ' : ' contratos terminan en ') . $period . '.';
    $row = static function (array $item, bool $exit): array {
      $days = (int) $item['dias'];
      $when = $days === 0 ? 'Termina hoy' : ($days > 0 ? 'Faltan ' . $days . ($days === 1 ? ' día' : ' días') : 'Terminó hace ' . abs($days) . (abs($days) === 1 ? ' día' : ' días'));
      $where = array_filter([$item['inmueble'] !== '' ? 'Inmueble ' . $item['inmueble'] : '', $item['direccion'], $exit ? $when : '']);
      return [
        'date' => date('d/m/Y', (int) strtotime($item['fin'])),
        'title' => 'Contrato #' . $item['contrato'] . ($item['arrendatario'] !== '' ? ' · ' . $item['arrendatario'] : ''),
        'meta' => implode(' · ', $where),
        'badge' => $exit ? $item['detalle'] : $when,
      ];
    };
    $groups = [];
    foreach ([
      [self::EXIT, 'contratos_por_desocupar', 'Por desocupar este mes', 'Con aviso de salida', 'logout'],
      [self::UNCONFIRMED, 'contratos_sin_aviso', 'Terminan sin aviso de salida', 'Pueden prorrogarse: confirma antes de dejar de facturar', 'event_upcoming'],
    ] as [$situation, $key, $label, $hint, $icon]) {
      $items = array_values(array_filter($rows, static fn(array $item): bool => $item['situacion'] === $situation));
      if ($items !== []) {
        $exit = $situation === self::EXIT;
        $groups[] = [
          'key' => $key, 'label' => $label, 'hint' => $hint, 'icon' => $icon, 'count' => count($items),
          'popup' => 'Contratos por desocupar', 'popup_note' => $note, 'show_all' => true,
          'rows' => array_map(static fn(array $item): array => $row($item, $exit), $items),
        ];
      }
    }
    return $groups;
  }

  /** @param array<string,mixed> $ticket */
  private static function inCycle(array $ticket, int $endTs): bool
  {
    $ticketEnd = self::timestamp($ticket['fecha_terminacion'] ?? null);
    if ($ticketEnd > 0) {
      return self::day($ticketEnd) === self::day($endTs);
    }
    return self::timestamp($ticket['fecha'] ?? null) >= $endTs - self::CYCLE_SECONDS;
  }

  private static function day(int $timestamp): string
  {
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d');
  }

  /** Las fechas CCT son segundos; algunas importadas quedaron en milisegundos. */
  private static function timestamp($value): int
  {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
      return 0;
    }
    $timestamp = (int) $raw;
    return $timestamp > 9999999999 ? intdiv($timestamp, 1000) : $timestamp;
  }

  /**
   * @param list<int> $ids
   * @return array<int,array<string,mixed>>
   */
  private function renewals(array $ids): array
  {
    $table = $this->db->table('scm_contract_renewal');
    if (!(new SchemaInspector($this->db))->tableExists($table)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach ($this->db->getResults("SELECT `contract_id`, `end_ts`, `probability`, `no_exit` FROM `{$table}` WHERE `contract_id` IN ({$placeholders})", $ids) as $row) {
      $out[(int) $row['contract_id']] = $row;
    }
    return $out;
  }

  /** @return array{0:array<string,list<array<string,mixed>>>,1:array<string,list<array<string,mixed>>>} Casos por id_contrato y por número de contrato. */
  private function tickets(): array
  {
    $table = $this->db->table('jet_cct_tickets');
    $schema = new SchemaInspector($this->db);
    if (!$schema->tableExists($table) || !$schema->columnExists($table, 'tema_ayuda')) {
      return [[], []];
    }
    $endColumn = $schema->columnExists($table, 'fecha_terminacion_contrato') ? '`fecha_terminacion_contrato`' : "''";
    $topics = implode(',', array_fill(0, count(self::TOPICS), '?'));
    $rows = $this->db->getResults(
      "SELECT TRIM(COALESCE(`id_contrato`, '')) AS id_contrato, TRIM(COALESCE(`contrato`, '')) AS contrato,
              LOWER(TRIM(`tema_ayuda`)) AS tema, `fecha`, {$endColumn} AS fecha_terminacion
       FROM `{$table}`
       WHERE LOWER(TRIM(COALESCE(`tema_ayuda`, ''))) IN ({$topics})
         AND LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN ('anulado', 'anulada')",
      array_keys(self::TOPICS)
    );
    $byId = [];
    $byCode = [];
    foreach ($rows as $row) {
      if ($row['id_contrato'] !== '') {
        $byId[$row['id_contrato']][] = $row;
      }
      if ($row['contrato'] !== '') {
        $byCode[$row['contrato']][] = $row;
      }
    }
    return [$byId, $byCode];
  }
}
