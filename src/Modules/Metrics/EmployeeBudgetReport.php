<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use SCM\Core\Database;

/**
 * Presupuesto y ejecución del funcionario autenticado.
 * Las metas y la ejecución salen de Reportes: Control no replica esa lógica.
 */
final class EmployeeBudgetReport
{
  private const API_URL = 'https://sucasainmobiliaria.com.co/reportesgerenciales/index.php';
  private const TIMEOUT_SECONDS = 25;
  // La API de Reportes atiende mal las ráfagas: su propio frontend limita a dos lecturas.
  private const CONCURRENCY = 2;

  /** @param \Closure(array<int,array<string,scalar>>):array<int,?array> $fetch */
  public function __construct(private \Closure $fetch)
  {
  }

  public static function remote(): self
  {
    return new self(static fn(array $requests): array => self::fetchAll($requests));
  }

  public static function hasBudget(Database $db, string $employeeId, int $year): bool
  {
    if ($employeeId === '') return false;
    $table = $db->table('jet_cct_presupuestos');
    return (bool) $db->getRow("SELECT 1 AS found FROM `{$table}` WHERE `year` = ? AND `id_empleado` = ? LIMIT 1", [$year, $employeeId]);
  }

  /** @return array{year:int,month:int,activities:array<int,array<string,mixed>>} */
  public function build(string $employeeId, int $year, int $month): array
  {
    // Sin id, Reportes devolvería los presupuestos de todos los funcionarios.
    if (trim($employeeId) === '') return ['year' => $year, 'month' => $month, 'activities' => []];
    $budget = ($this->fetch)([['route' => 'presupuestos/list', 'year' => $year, 'id_empleado' => $employeeId]])[0] ?? null;
    if (!is_array($budget) || empty($budget['success'])) {
      throw new \RuntimeException('Reportes no entregó los presupuestos.');
    }
    $groups = self::groupBudget((array) ($budget['presupuestos'] ?? []));

    $requests = [];
    $owners = [];
    foreach ($groups as $key => $group) {
      foreach (self::executionRequests($group, $employeeId, $year, $month) as $request) {
        $requests[] = $request;
        $owners[] = $key;
      }
    }
    $responses = $requests ? ($this->fetch)($requests) : [];

    $execution = [];
    foreach ($owners as $index => $key) {
      $execution[$key][] = ['request' => $requests[$index], 'response' => $responses[$index] ?? null];
    }

    $activities = [];
    foreach ($groups as $key => $group) {
      $activities[] = self::activity($group, $execution[$key] ?? [], $month);
    }
    return ['year' => $year, 'month' => $month, 'activities' => $activities];
  }

  /** @param array<int,array<string,mixed>> $rows @return array<string,array<string,mixed>> */
  private static function groupBudget(array $rows): array
  {
    $groups = [];
    foreach ($rows as $row) {
      $type = trim((string) ($row['tipo_presupuesto'] ?? ''));
      $month = (int) ($row['month'] ?? 0);
      $value = (float) ($row['value'] ?? 0);
      if ($type === '' || $month < 1 || $month > 12 || $value <= 0) continue;
      $valueType = strtolower(trim((string) ($row['tipo_valor'] ?? ''))) === 'valor' ? 'valor' : 'cantidad';
      $key = $type . '|' . $valueType;
      $groups[$key] ??= ['type' => $type, 'value_type' => $valueType, 'targets' => array_fill(1, 12, 0.0)];
      $groups[$key]['targets'][$month] += $value;
    }
    return $groups;
  }

  /** La oferta en cantidad es un inventario: se lee al cierre de cada mes, no se suma. */
  private static function isStock(array $group): bool
  {
    return mb_strtolower($group['type'], 'UTF-8') === 'oferta inmobiliaria' && $group['value_type'] !== 'valor';
  }

  /** @return array<int,array<string,scalar>> */
  private static function executionRequests(array $group, string $employeeId, int $year, int $month): array
  {
    $type = $group['type'];
    if ($type === 'Desocupaciones del Mes') {
      $type = $group['value_type'] === 'valor' ? 'Desocupaciones del Mes en Valores' : 'Desocupaciones del Mes en Numeros';
    }
    $base = ['year' => $year, 'tipo' => $type, 'tipo_valor' => $group['value_type'], 'id_empleado' => $employeeId];
    if (!self::isStock($group)) {
      return [$base + ['route' => 'ejecucion/funcionario-mensual']];
    }
    $requests = [];
    for ($m = 1; $m <= $month; $m++) {
      $requests[] = $base + ['route' => 'ejecucion/snapshot-mes', 'mes' => $m];
    }
    return $requests;
  }

  /** @param array<int,array{request:array,response:?array}> $results */
  private static function activity(array $group, array $results, int $month): array
  {
    $stock = self::isStock($group);
    $done = array_fill(1, 12, 0.0);
    $available = $results !== [];
    foreach ($results as $result) {
      $response = $result['response'];
      if (!is_array($response) || (empty($response['success']) && !isset($response['ejecutado']))) {
        $available = false;
        continue;
      }
      if ($stock) {
        $done[(int) $result['request']['mes']] = (float) ($response['ejecutado'] ?? 0);
        continue;
      }
      foreach ((array) ($response['items'] ?? []) as $item) {
        $itemMonth = (int) ($item['month'] ?? 0);
        if ($itemMonth >= 1 && $itemMonth <= 12) $done[$itemMonth] += (float) ($item['ejecutado'] ?? 0);
      }
    }

    $months = [];
    $accumulatedTarget = 0.0;
    $accumulatedDone = 0.0;
    for ($m = 1; $m <= $month; $m++) {
      $accumulatedTarget = $stock ? $group['targets'][$m] : $accumulatedTarget + $group['targets'][$m];
      $accumulatedDone = $stock ? $done[$m] : $accumulatedDone + $done[$m];
      $months[$m] = self::figures($group['targets'][$m], $done[$m]);
    }

    return [
      'name' => $group['type'],
      'is_money' => $group['value_type'] === 'valor',
      'available' => $available,
      'month' => $months[$month],
      'accumulated' => self::figures($accumulatedTarget, $accumulatedDone),
      'months' => $months,
    ];
  }

  /** @return array{target:float,done:float,pct:?float} */
  private static function figures(float $target, float $done): array
  {
    return ['target' => $target, 'done' => $done, 'pct' => $target > 0 ? round($done * 100 / $target, 1) : null];
  }

  /**
   * @param array<int,array<string,scalar>> $requests
   * @return array<int,?array>
   */
  private static function fetchAll(array $requests): array
  {
    $responses = [];
    foreach (array_chunk($requests, self::CONCURRENCY, true) as $chunk) {
      $multi = curl_multi_init();
      $handles = [];
      foreach ($chunk as $index => $params) {
        $handle = curl_init(self::API_URL . '?' . http_build_query($params));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS, CURLOPT_CONNECTTIMEOUT => 5]);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
      }
      do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
      } while ($running && $status === CURLM_OK);
      foreach ($handles as $index => $handle) {
        $ok = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200;
        $decoded = $ok ? json_decode((string) curl_multi_getcontent($handle), true) : null;
        $responses[$index] = is_array($decoded) ? $decoded : null;
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
      }
      curl_multi_close($multi);
    }
    return $responses;
  }
}
