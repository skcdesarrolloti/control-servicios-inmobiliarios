<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;

/**
 * Casos de "Recibo de inmuebles": ahí el área contractual registra la fecha en que termina el contrato,
 * así que son la lista real de inmuebles que se desocupan. Popup y métrica salen de aquí.
 */
final class ContractReceiptCases
{
  private const CLOSED_STATES = ['cerrado', 'cerrada', 'resuelto', 'resuelta', 'finalizado', 'finalizada'];
  private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

  public function __construct(private Database $db) {}

  /** @return list<array<string,mixed>> */
  public function all(): array
  {
    return self::normalize($this->query('', []));
  }

  /** @return list<array<string,mixed>> Casos cuya fecha de terminación cae en el mes en hora Colombia. */
  public function inMonth(int $year, int $month): array
  {
    $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('America/Bogota'));
    $fromTs = $from->getTimestamp();
    $toTs = $from->modify('first day of next month')->getTimestamp() - 1;
    return self::normalize($this->query(' AND (`fecha_terminacion_contrato` BETWEEN ? AND ? OR `fecha_terminacion_contrato` BETWEEN ? AND ?)', [$fromTs, $toTs, $fromTs * 1000, $toTs * 1000]));
  }

  /**
   * Un contrato con el mismo caso repetido para la misma fecha cuenta una vez: se queda el más reciente.
   * @param list<array<string,mixed>> $rows
   * @return list<array<string,mixed>>
   */
  public static function normalize(array $rows): array
  {
    usort($rows, static fn(array $a, array $b): int => (int) ($a['_ID'] ?? 0) <=> (int) ($b['_ID'] ?? 0));
    $out = [];
    foreach ($rows as $row) {
      $end = ContractMovementsReport::isoDate($row['fecha_terminacion_contrato'] ?? null);
      if ($end === null) {
        continue;
      }
      $state = mb_strtolower(trim((string) ($row['estado'] ?? '')), 'UTF-8');
      $admin = trim((string) ($row['estado_administrativo'] ?? ''));
      $open = !in_array($state, self::CLOSED_STATES, true);
      $contract = trim((string) ($row['contrato'] ?? ''));
      $contractId = trim((string) ($row['id_contrato'] ?? ''));
      $out[($contractId !== '' ? $contractId : $contract) . '|' . $end] = [
        'caso' => trim((string) ($row['id_ticket'] ?? '')) ?: (string) ($row['_ID'] ?? ''),
        'id_contrato' => $contractId,
        'contrato' => $contract,
        'inmueble' => trim((string) ($row['inmueble'] ?? '')),
        'direccion' => trim((string) preg_replace('/\s+/u', ' ', (string) ($row['direccion'] ?? ''))),
        'arrendatario' => trim((string) preg_replace('/\s+/u', ' ', (string) ($row['arrendatario'] ?? ''))),
        'fin' => $end,
        'abierto' => $open,
        'estado' => $open ? 'Por recibir' : (in_array(mb_strtolower($admin, 'UTF-8'), ['recibido', 'finalizado'], true) ? $admin : 'Cerrado'),
      ];
    }
    $out = array_values($out);
    usort($out, static fn(array $a, array $b): int => [$a['fin'], $a['contrato']] <=> [$b['fin'], $b['contrato']]);
    return $out;
  }

  /**
   * @param list<array<string,mixed>> $cases
   * @return list<array<string,mixed>> Grupos del popup: lo que falta por recibir y lo que ya se cerró en el mes.
   */
  public static function alertGroups(array $cases, DateTimeImmutable $today): array
  {
    if ($cases === []) {
      return [];
    }
    $year = (int) $today->format('Y');
    $month = (int) $today->format('n');
    $count = count($cases);
    $note = $count . ($count === 1 ? ' caso de recibo' : ' casos de recibo') . ' con fecha en ' . self::MONTHS[$month - 1] . ' de ' . $year . '.';
    $row = static function (array $case) use ($today): array {
      $days = (int) round(((new DateTimeImmutable($case['fin'], $today->getTimezone()))->getTimestamp() - $today->getTimestamp()) / 86400);
      $when = $days === 0 ? 'Termina hoy' : ($days > 0 ? 'Faltan ' . $days . ($days === 1 ? ' día' : ' días') : 'Terminó hace ' . abs($days) . (abs($days) === 1 ? ' día' : ' días'));
      return [
        'date' => substr($case['fin'], 8, 2) . '/' . substr($case['fin'], 5, 2) . '/' . substr($case['fin'], 0, 4),
        'title' => 'Contrato #' . $case['contrato'] . ($case['arrendatario'] !== '' ? ' · ' . $case['arrendatario'] : ''),
        'meta' => implode(' · ', array_filter(['Caso #' . $case['caso'], $case['inmueble'] !== '' ? 'Inmueble ' . $case['inmueble'] : '', $case['direccion']])),
        'badge' => $case['abierto'] ? $when : $case['estado'],
      ];
    };
    $groups = [];
    foreach ([
      [true, 'contratos_por_recibir', 'Por recibir este mes', 'Caso de recibo abierto', 'logout'],
      [false, 'contratos_recibidos', 'Ya cerrados este mes', 'Caso de recibo cerrado', 'task_alt'],
    ] as [$open, $key, $label, $hint, $icon]) {
      $items = array_values(array_filter($cases, static fn(array $case): bool => $case['abierto'] === $open));
      if ($items !== []) {
        $groups[] = [
          'key' => $key, 'label' => $label, 'hint' => $hint, 'icon' => $icon, 'count' => count($items),
          'popup' => 'Contratos por desocupar', 'popup_note' => $note, 'show_all' => true,
          'rows' => array_map($row, $items),
        ];
      }
    }
    return $groups;
  }

  /**
   * @param list<int> $args
   * @return list<array<string,mixed>>
   */
  private function query(string $whereExtra, array $args): array
  {
    $table = $this->db->table('jet_cct_tickets');
    return $this->db->getResults(
      "SELECT `_ID`, `id_ticket`, `id_contrato`, `contrato`, `inmueble`, `direccion`, `arrendatario`, `estado`, `estado_administrativo`, `fecha_terminacion_contrato`
       FROM `{$table}`
       WHERE LOWER(TRIM(COALESCE(`tema_ayuda`, ''))) IN ('recibo de inmuebles', 'recibo de inmueble')
         AND LOWER(TRIM(COALESCE(`estado`, ''))) NOT IN ('anulado', 'anulada')
         AND `fecha_terminacion_contrato` > 0{$whereExtra}",
      $args
    );
  }
}
