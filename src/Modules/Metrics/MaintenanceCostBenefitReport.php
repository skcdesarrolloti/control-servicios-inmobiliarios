<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use SCM\Core\Database;
use SCM\Modules\AdministrativeReports\AdministrativeReportsService;
use SCM\Modules\ContractMovements\ContractMovementsReport;

/**
 * Vistas de costo de Métricas › Mantenimiento. Cada una define su ingreso y qué reportes son su gasto.
 *
 * - Ingreso `cotizaciones`: administración (sin IVA) de las cotizaciones `Aprobada`, en el mes de su fecha
 *   de respuesta; es la misma "Administración ganada" de Métricas › Cotizaciones.
 * - Ingreso `contratos`: comisión (canon × % de administración) de cada mes vigente y derechos (canon × %
 *   de derechos) en el mes de entrega. Se calcula con el canon actual: el contrato no guarda su historial.
 * - Gasto: servicio + transporte de los reportes administrativos de la vista, en el mes de la fecha del
 *   reporte, esté o no pagado: los reportes no guardan la fecha en que se pagaron. Lo que no corresponde
 *   a la vista no cuenta; su total se informa aparte.
 *
 * Devuelve todas las filas en una sola respuesta para calcular los periodos en el navegador.
 */
final class MaintenanceCostBenefitReport
{
  public const INCOME_COLUMNS = ['id', 'ticket', 'contrato', 'inmueble', 'categoria', 'fecha', 'total', 'admin'];
  public const CONTRACT_COLUMNS = ['id', 'contrato', 'inmueble', 'ingreso', 'egreso', 'canon', 'tasa', 'derechos'];
  public const EXPENSE_COLUMNS = ['id', 'ticket', 'inmueble', 'categoria', 'funcionario', 'fecha', 'servicio', 'transporte', 'total', 'estado'];
  private const LIBIA = 'Libia Correa Rivera';
  private const ALVARO = 'Alvaro Florez De Oro';
  public const SCOPES = [
    'entrega_recibo' => [
      'tab' => 'metricas_costo_entrega_recibo', 'panel' => 'scm-panel-metricas-costo-entrega-recibo', 'income' => 'contratos',
      'employees' => [self::LIBIA], 'types' => ['entrega', 'recibo'], 'note' => 'Gastos de ' . self::LIBIA . ' en entregas y recibos.',
    ],
    'mantenimiento' => [
      'tab' => 'metricas_costo_beneficio', 'panel' => 'scm-panel-metricas-costo-beneficio', 'income' => 'cotizaciones',
      'employees' => [self::ALVARO], 'types' => ['correctiva', 'preventiva'], 'note' => 'Gastos de ' . self::ALVARO . ' en revisiones correctivas y preventivas.',
    ],
  ];

  private DateTimeZone $timezone;

  public function __construct(private Database $db)
  {
    $this->timezone = new DateTimeZone('America/Bogota');
  }

  /** @return array<string,mixed> */
  public function snapshot(string $scope): array
  {
    $income = self::SCOPES[$scope]['income'] === 'contratos'
      ? (new ContractMovementsReport($this->db))->snapshot()
      : (new QuoteMetricsReport($this->db))->snapshot();
    return $this->build($scope, $income, (new AdministrativeReportsService($this->db))->allReports());
  }

  /**
   * @param array{columns:list<string>,rows:list<list<mixed>>} $source snapshot de cotizaciones o de contratos, según la vista
   * @param list<array<string,mixed>> $reports reportes de AdministrativeReportsService::allReports()
   * @return array<string,mixed>
   */
  public function build(string $scope, array $source, array $reports): array
  {
    $config = self::SCOPES[$scope];
    $undated = ['income_count' => 0, 'income_value' => 0, 'expense_count' => 0, 'expense_value' => 0];
    $income = [];
    $contracts = [];
    $contractsWithoutRate = 0;

    foreach ($source['rows'] as $values) {
      $row = array_combine($source['columns'], $values);
      if ($config['income'] === 'contratos') {
        if ($row['ingreso'] === null) {
          $undated['income_count']++;
          continue;
        }
        if (empty($row['tasa_admin']) && empty($row['derechos'])) $contractsWithoutRate++;
        $contracts[] = [$row['id'], $row['contrato'], $row['inmueble'], $row['ingreso'], $row['egreso'], (int) $row['canon'], (float) $row['tasa_admin'], (float) $row['derechos']];
        continue;
      }
      if ($row['estado'] !== 'Aprobada') continue;
      if ($row['respuesta'] === null) {
        $undated['income_count']++;
        $undated['income_value'] += (int) $row['admin'];
        continue;
      }
      $income[] = [$row['id'], $row['ticket'], $row['contrato'], $row['inmueble'], $row['categoria'], $row['respuesta'], $row['total'], $row['admin']];
    }

    $allowedEmployees = array_combine(array_map([self::class, 'nameKey'], $config['employees']), $config['employees']);
    $pendingEmployees = $allowedEmployees;
    $expense = [];
    $excluded = ['count' => 0, 'value' => 0];
    foreach ($reports as $report) {
      $employeeKey = self::nameKey((string) $report['funcionario']);
      $allowed = isset($allowedEmployees[$employeeKey]);
      if ($allowed) unset($pendingEmployees[$employeeKey]);
      if (!$allowed || !self::matchesType($config['types'], (string) $report['categoria'])) {
        $excluded['count']++;
        $excluded['value'] += (int) $report['total'];
        continue;
      }
      $date = $this->date((int) $report['fecha']);
      if ($date === null) {
        $undated['expense_count']++;
        $undated['expense_value'] += (int) $report['total'];
        continue;
      }
      $expense[] = [
        $report['id'], $report['id_ticket'], $report['inmueble'], $report['categoria'], $report['funcionario'], $date,
        $report['servicio'], $report['transporte'], $report['total'], self::paymentState($report),
      ];
    }

    return [
      'income_source' => $config['income'],
      'income_columns' => self::INCOME_COLUMNS,
      'income' => $income,
      'contract_columns' => self::CONTRACT_COLUMNS,
      'contracts' => $contracts,
      'contracts_without_rate' => $contractsWithoutRate,
      'expense_columns' => self::EXPENSE_COLUMNS,
      'expense' => $expense,
      'undated' => $undated,
      'expense_note' => $config['note'],
      'excluded' => $excluded,
      'employees_not_found' => array_values($pendingEmployees),
      'generated_at' => time(),
    ];
  }

  /** @param list<string> $types fragmentos del tipo de reporte */
  private static function matchesType(array $types, string $category): bool
  {
    $category = self::nameKey($category);
    foreach ($types as $type) {
      if (str_contains($category, $type)) return true;
    }
    return false;
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
