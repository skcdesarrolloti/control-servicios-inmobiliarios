<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/**
 * Métricas de casos: estado actual de los casos según su fecha de creación.
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

    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        $encode('estado', self::clean($row['estado'] ?? '')),
        $encode('estado_administrativo', self::clean($row['estado_administrativo'] ?? '')),
        $encode('tema', self::clean($row['tema_ayuda'] ?? '')),
        $encode('departamento', self::clean($row['departamento'] ?? '')),
        preg_match('/^\d+$/', trim((string) ($row['id_empleado'] ?? ''))) === 1 ? trim((string) $row['id_empleado']) : '',
        $this->date($row['fecha'] ?? null, $row['cct_created'] ?? null),
      ];
    }

    return [
      'columns' => self::COLUMNS,
      'dictionaries' => $dictionaries,
      'rows' => $out,
      'funcionarios' => $this->employees(),
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

  /** @return array<string,array{name:string,active:bool}> */
  private function employees(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $schema = new SchemaInspector($this->db);
    if (!$schema->tableExists($table) || !$schema->columnExists($table, 'id_empleado')) {
      return [];
    }
    $out = [];
    foreach ($this->db->getResults("SELECT `id_empleado`, `nombre`, `activo` FROM `{$table}` WHERE TRIM(COALESCE(`id_empleado`, '')) <> ''") as $row) {
      $id = trim((string) $row['id_empleado']);
      $out[$id] = [
        'name' => self::clean($row['nombre'] ?? '') ?: 'Funcionario ' . $id,
        'active' => mb_strtolower(trim((string) ($row['activo'] ?? '')), 'UTF-8') === 'si',
      ];
    }
    return $out;
  }
}
