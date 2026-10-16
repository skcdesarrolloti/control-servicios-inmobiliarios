<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Support\FuncionarioOptions;

/**
 * Métricas de casos: estado actual de los casos según su fecha de creación.
 *
 * Solo cuenta los casos de los funcionarios configurados para los filtros del panel
 * (los mismos cargos que usan los filtros de funcionario); el resto se informa aparte.
 *
 * Devuelve todos los casos en una sola respuesta compacta (los textos repetidos van en
 * diccionarios) para que el panel filtre por mes, rango de fechas, tema, departamento y
 * funcionario sin volver a consultar.
 */
final class CaseMetricsReport
{
  public const COLUMNS = ['estado', 'estado_administrativo', 'tema', 'departamento', 'funcionario', 'fecha'];

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
    $table = $this->db->table('jet_cct_tickets');
    $rows = $this->db->getResults(
      "SELECT `estado`, `estado_administrativo`, `tema_ayuda`, `departamento`, `id_empleado`, `fecha`, `cct_created`
       FROM `{$table}`
       WHERE LOWER(TRIM(COALESCE(`cct_status`, 'publish'))) = 'publish'"
    );

    $dictionaries = ['estado' => [], 'estado_administrativo' => [], 'tema' => [], 'departamento' => []];
    $index = ['estado' => [], 'estado_administrativo' => [], 'tema' => [], 'departamento' => []];
    $encode = static function (string $dictionary, string $value) use (&$dictionaries, &$index): int {
      if (!isset($index[$dictionary][$value])) {
        $index[$dictionary][$value] = count($dictionaries[$dictionary]);
        $dictionaries[$dictionary][] = $value;
      }
      return $index[$dictionary][$value];
    };

    $employees = $this->employees();
    $out = [];
    $excluded = 0;
    foreach ($rows as $row) {
      $employeeId = trim((string) ($row['id_empleado'] ?? ''));
      if (!isset($employees[$employeeId])) {
        $excluded++;
        continue;
      }
      $out[] = [
        $encode('estado', self::clean($row['estado'] ?? '')),
        $encode('estado_administrativo', self::clean($row['estado_administrativo'] ?? '')),
        $encode('tema', self::clean($row['tema_ayuda'] ?? '')),
        $encode('departamento', self::clean($row['departamento'] ?? '')),
        $employeeId,
        $this->date($row['fecha'] ?? null, $row['cct_created'] ?? null),
      ];
    }

    return [
      'columns' => self::COLUMNS,
      'dictionaries' => $dictionaries,
      'rows' => $out,
      'funcionarios' => $employees,
      'excluidos' => $excluded,
      'generated_at' => time(),
    ];
  }

  /** Fecha del caso en Colombia: `fecha` (unix) o, si falta, `cct_created`. */
  public function date($timestamp, $created): ?string
  {
    $raw = trim((string) ($timestamp ?? ''));
    if (preg_match('/^\d+$/', $raw) === 1 && (int) $raw >= 1262304000) {
      return (new DateTimeImmutable('@' . (int) $raw))->setTimezone($this->timezone)->format('Y-m-d');
    }
    $created = trim((string) ($created ?? ''));
    return preg_match('/^(\d{4}-\d{2}-\d{2})/', $created, $match) === 1 && $match[1] >= '2010-01-01' ? $match[1] : null;
  }

  private static function clean($value): string
  {
    return trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
  }

  /**
   * Funcionarios configurados para los filtros del panel.
   *
   * @return array<string,array{name:string,active:bool}>
   */
  private function employees(): array
  {
    $out = [];
    foreach (FuncionarioOptions::panelFuncionarios($this->db) as $employee) {
      $id = trim((string) ($employee['id'] ?? ''));
      if ($id !== '') {
        $out[$id] = ['name' => (string) ($employee['name'] ?? ('Funcionario ' . $id)), 'active' => true];
      }
    }
    return $out;
  }
}
