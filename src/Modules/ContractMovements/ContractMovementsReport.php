<?php

declare(strict_types=1);

namespace SCM\Modules\ContractMovements;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Ocupaciones y desocupaciones de contratos de arrendamiento.
 *
 * Devuelve en una sola respuesta todos los contratos que alguna vez se
 * entregaron, con fechas ya normalizadas a Colombia. El panel filtra, agrupa y
 * compara en el navegador, así cambiar de vista o de filtro no consulta de nuevo.
 *
 * - Ocupación: contrato entregado (Entregado, Por recibir o Recibido) en su
 *   fecha de entrega o, si falta, en el inicio del contrato. Se cuentan también
 *   los ya recibidos porque su ocupación ocurrió igual.
 * - Desocupación: contrato Recibido en su fecha de recibo o, si falta, en el
 *   fin del contrato.
 * - Por desocupar: contrato Por recibir con su fin de contrato previsto.
 * Los desistidos y los que siguen por entregar nunca ocuparon el inmueble.
 */
final class ContractMovementsReport
{
  public const COLUMNS = [
    'id',
    'contrato',
    'estado',
    'ingreso',
    'egreso',
    'fin',
    'canon',
    'tipo',
    'destinacion',
    'gestion',
    'funcionario',
    'direccion',
    'arrendatario',
    'inmueble',
  ];

  private const STATES = [
    'entregado' => 'entregado',
    'por recibir' => 'por_recibir',
    'recibido' => 'recibido',
  ];

  /** Fechas anteriores son datos basura (p. ej. 1970) y se tratan como faltantes. */
  private const MIN_VALID_TS = 631152000; // 1990-01-01

  private Database $db;
  private DateTimeZone $timezone;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->timezone = new DateTimeZone('America/Bogota');
  }

  /**
   * @return array{columns:list<string>,rows:list<list<int|string|null>>,funcionarios:array<string,string>,generated_at:int}
   */
  public function snapshot(): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $rows = $this->db->getResults(
      "SELECT `_ID`, `contrato`, `estado`, `fecha_entrega`, `inicio_contrato`, `fecha_recibo`, `fin_contrato`,
              `valor_canon`, `tipo_inmueble`, `destinacion_inmueble`, `gestion_inmueble`, `id_empleado`,
              `direccion`, `arrendatario`, `id_inmueble_data`, `id_inmueble`
       FROM `{$table}`
       WHERE LOWER(TRIM(COALESCE(`estado`, ''))) IN ('entregado', 'por recibir', 'recibido')
       ORDER BY `_ID` ASC"
    );

    $out = [];
    $employeeIds = [];
    foreach ($rows as $row) {
      $mapped = $this->mapRow($row);
      if ($mapped === null) {
        continue;
      }
      $out[] = $mapped;
      if ($mapped[10] !== '') {
        $employeeIds[$mapped[10]] = true;
      }
    }

    return [
      'columns' => self::COLUMNS,
      'rows' => $out,
      'funcionarios' => $this->employeeNames(array_keys($employeeIds)),
      'generated_at' => time(),
    ];
  }

  /**
   * @param array<string,mixed> $row
   * @return list<int|string|null>|null
   */
  public function mapRow(array $row): ?array
  {
    $state = self::STATES[mb_strtolower(trim((string) ($row['estado'] ?? '')), 'UTF-8')] ?? null;
    if ($state === null) {
      return null;
    }

    $ingreso = $this->date($row['fecha_entrega'] ?? null) ?? $this->date($row['inicio_contrato'] ?? null);
    $egreso = $state === 'recibido'
      ? ($this->date($row['fecha_recibo'] ?? null) ?? $this->date($row['fin_contrato'] ?? null))
      : null;
    $fin = $state === 'recibido' ? null : $this->date($row['fin_contrato'] ?? null);
    $inmueble = trim((string) ($row['id_inmueble_data'] ?? ''));
    if ($inmueble === '') {
      $inmueble = trim((string) ($row['id_inmueble'] ?? ''));
    }

    return [
      (int) ($row['_ID'] ?? 0),
      $this->text($row['contrato'] ?? ''),
      $state,
      $ingreso,
      $egreso,
      $fin,
      self::parseMoney($row['valor_canon'] ?? null),
      $this->category($row['tipo_inmueble'] ?? ''),
      $this->category($row['destinacion_inmueble'] ?? ''),
      $this->category($row['gestion_inmueble'] ?? ''),
      preg_match('/^\d+$/', trim((string) ($row['id_empleado'] ?? ''))) === 1 ? trim((string) $row['id_empleado']) : '',
      $this->text($row['direccion'] ?? ''),
      $this->text($row['arrendatario'] ?? ''),
      $inmueble,
    ];
  }

  /** Convierte "$ 1.250.000", "1250000" o "1,250,000.50" en pesos enteros. */
  public static function parseMoney($value): int
  {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
      return 0;
    }
    $normalized = preg_replace('/[^0-9,.\-]/', '', str_ireplace('COP', '', $raw)) ?? '';
    if ($normalized === '' || in_array($normalized, ['-', '.', ','], true)) {
      return 0;
    }
    $lastDot = strrpos($normalized, '.');
    $lastComma = strrpos($normalized, ',');
    if ($lastDot !== false && $lastComma !== false) {
      $decimal = $lastComma > $lastDot ? ',' : '.';
      $thousands = $decimal === ',' ? '.' : ',';
      $normalized = str_replace([$thousands, $decimal], ['', '.'], $normalized);
    } elseif ($lastDot !== false || $lastComma !== false) {
      $separator = $lastDot !== false ? '.' : ',';
      $parts = explode($separator, $normalized);
      $normalized = count($parts) > 2 || strlen((string) end($parts)) === 3
        ? implode('', $parts)
        : implode('.', $parts);
    }
    if (!is_numeric($normalized)) {
      return 0;
    }
    return max(0, (int) round((float) $normalized));
  }

  private function date($value): ?string
  {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
      return null;
    }
    $ts = (int) $raw;
    if ($ts > 9999999999) {
      $ts = intdiv($ts, 1000);
    }
    if ($ts < self::MIN_VALID_TS) {
      return null;
    }
    return (new DateTimeImmutable('@' . $ts))->setTimezone($this->timezone)->format('Y-m-d');
  }

  private function text($value): string
  {
    return trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
  }

  private function category($value): string
  {
    $text = $this->text($value);
    if ($text === '' || preg_match('/^(a:0:\{\}|\d+|n\/?a|null|sin dato|-)$/i', $text) === 1) {
      return '';
    }
    return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
  }

  /**
   * @param list<string> $ids
   * @return array<string,string>
   */
  private function employeeNames(array $ids): array
  {
    if ($ids === []) {
      return [];
    }
    $table = $this->db->table('jet_cct_funcionarios');
    $schema = new SchemaInspector($this->db);
    if (!$schema->tableExists($table) || !$schema->columnExists($table, 'id_empleado')) {
      return [];
    }
    $nameColumn = $schema->detectFirstExistingColumn($table, ['nombre', 'empleado', 'nombre_empleado', 'nombre_funcionario']);
    if ($nameColumn === '') {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $this->db->getResults(
      "SELECT TRIM(`id_empleado`) AS id, TRIM(`{$nameColumn}`) AS name FROM `{$table}` WHERE TRIM(`id_empleado`) IN ({$placeholders})",
      $ids
    );
    $names = [];
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '');
      $name = $this->text($row['name'] ?? '');
      if ($id !== '' && $name !== '' && !isset($names[$id])) {
        $names[$id] = $name;
      }
    }
    return $names;
  }
}
