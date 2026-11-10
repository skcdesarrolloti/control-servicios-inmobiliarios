<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Modules\AdministrativeReports\AdministrativeReportsService;

/**
 * Costo-beneficio del área de mantenimiento.
 *
 * - Ingreso: administración (sin IVA) de las cotizaciones `Aprobada`, en el mes de su fecha de respuesta;
 *   es la misma "Administración ganada" de Métricas › Cotizaciones.
 * - Gasto: lo que cobran los funcionarios de EXPENSE_EMPLOYEES en reportes administrativos (servicio +
 *   transporte), en el mes de la fecha del reporte, esté o no pagado: los reportes no guardan la fecha
 *   en que se pagaron. Los demás funcionarios no cuentan; su total se informa aparte.
 *
 * Devuelve todas las filas en una sola respuesta para calcular los periodos en el navegador.
 */
final class MaintenanceCostBenefitReport
{
  public const INCOME_COLUMNS = ['id', 'ticket', 'contrato', 'inmueble', 'categoria', 'fecha', 'total', 'admin'];
  public const EXPENSE_COLUMNS = ['id', 'ticket', 'inmueble', 'categoria', 'funcionario', 'fecha', 'servicio', 'transporte', 'total', 'estado'];
  /** Únicos funcionarios cuyos reportes cuentan como gasto del área. */
  public const EXPENSE_EMPLOYEES = ['Libia Correa Rivera', 'Alvaro Florez De Oro'];

  private DateTimeZone $timezone;

  public function __construct(private Database $db)
  {
    $this->timezone = new DateTimeZone('America/Bogota');
  }

  /** @return array<string,mixed> */
  public function snapshot(): array
  {
    return $this->build(
      (new QuoteMetricsReport($this->db))->snapshot(),
      (new AdministrativeReportsService($this->db))->allReports()
    );
  }

  /**
   * @param array{columns:list<string>,rows:list<list<mixed>>} $quotes snapshot de QuoteMetricsReport
   * @param list<array<string,mixed>> $reports reportes de AdministrativeReportsService::allReports()
   * @return array<string,mixed>
   */
  public function build(array $quotes, array $reports): array
  {
    $income = [];
    $expense = [];
    $undated = ['income_count' => 0, 'income_value' => 0, 'expense_count' => 0, 'expense_value' => 0];

    foreach ($quotes['rows'] as $values) {
      $quote = array_combine($quotes['columns'], $values);
      if ($quote['estado'] !== 'Aprobada') continue;
      if ($quote['respuesta'] === null) {
        $undated['income_count']++;
        $undated['income_value'] += (int) $quote['admin'];
        continue;
      }
      $income[] = [$quote['id'], $quote['ticket'], $quote['contrato'], $quote['inmueble'], $quote['categoria'], $quote['respuesta'], $quote['total'], $quote['admin']];
    }

    $pendingEmployees = array_combine(array_map([self::class, 'nameKey'], self::EXPENSE_EMPLOYEES), self::EXPENSE_EMPLOYEES);
    $allowedEmployees = $pendingEmployees;
    $excluded = ['count' => 0, 'value' => 0];
    foreach ($reports as $report) {
      $employeeKey = self::nameKey((string) $report['funcionario']);
      if (!isset($allowedEmployees[$employeeKey])) {
        $excluded['count']++;
        $excluded['value'] += (int) $report['total'];
        continue;
      }
      unset($pendingEmployees[$employeeKey]);
      $date = $this->date((int) $report['fecha']);
      if ($date === null) {
        $undated['expense_count']++;
        $undated['expense_value'] += (int) $report['total'];
        continue;
      }
      $expense[] = [
        $report['id'], $report['id_ticket'], $report['inmueble'], $report['categoria'] !== '' ? $report['categoria'] : 'Sin tipo',
        $report['funcionario'], $date,
        $report['servicio'], $report['transporte'], $report['total'], self::paymentState($report),
      ];
    }

    return [
      'income_columns' => self::INCOME_COLUMNS,
      'income' => $income,
      'expense_columns' => self::EXPENSE_COLUMNS,
      'expense' => $expense,
      'undated' => $undated,
      'employees' => self::EXPENSE_EMPLOYEES,
      'excluded' => $excluded,
      'employees_not_found' => array_values($pendingEmployees),
      'generated_at' => time(),
    ];
  }

  /** Compara nombres sin importar mayúsculas, tildes ni espacios de más. */
  private static function nameKey(string $name): string
  {
    $name = strtr(mb_strtolower($name, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim((string) preg_replace('/\s+/u', ' ', $name));
  }

  /** @param array<string,mixed> $report */
  private static function paymentState(array $report): string
  {
    if (!empty($report['pagado'])) return 'pagado';
    return !empty($report['exportado']) ? 'en_cobro' : 'sin_cobrar';
  }

  private function date(int $timestamp): ?string
  {
    if ($timestamp < 1262304000) return null;
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->format('Y-m-d');
  }
}
