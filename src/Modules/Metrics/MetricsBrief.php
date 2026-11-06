<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

/**
 * "Lectura rápida" de cada informe: lo que el gerente debe saber en frases, sin interpretar tablas.
 * @phpstan-type Line array{tone:string,text:string}
 */
final class MetricsBrief
{
  /** @return array<int,array{tone:string,text:string}> */
  public static function services(array $data): array
  {
    $p = $data['pending'];
    $r = $data['reviews'];
    $c = $data['critical'];
    $lines = [self::compliance('servicios', $data)];

    if ($p['overdue'] > 0) {
      $text = self::count($p['overdue'], 'contrato con revisión vencida', 'contratos con revisión vencida') . ' de meses anteriores';
      if ($p['overdue_old'] > 0) $text .= '; ' . $p['overdue_old'] . ' de ' . ($p['overdue_old'] === 1 ? 'ellos viene' : 'ellos vienen') . ' de años anteriores y no ' . ($p['overdue_old'] === 1 ? 'cuenta' : 'cuentan') . ' en el cumplimiento';
      $lines[] = self::line('bad', $text . '. ' . self::count($p['month'], 'más vence', 'más vencen') . ' este mes.');
    } elseif ($p['month'] > 0) {
      $lines[] = self::line('warn', self::count($p['month'], 'contrato vence', 'contratos vencen') . ' este mes; no hay vencidos de meses anteriores.');
    }

    $lines[] = $c['open_now'] > 0
      ? self::line($c['overdue_now'] > 0 ? 'bad' : 'warn', self::count($c['open_now'], 'caso crítico abierto', 'casos críticos abiertos') . ', ' . $c['overdue_now'] . ' fuera de las 72 horas.')
      : self::line('good', 'No hay casos críticos abiertos.');

    if ($r['total'] > 0) {
      $mora = $r['m30'] + $r['m60'] + $r['critico'];
      $text = 'En el periodo se hicieron ' . self::count($r['total'], 'revisión', 'revisiones') . ': ' . $mora . ' con mora o crítica';
      if ($r['debt'] > 0) $text .= ' y ' . MaintenanceMetricsView::money((int) $r['debt']) . ' de deuda, la mayor en ' . self::largestDebt($data['services']);
      $lines[] = self::line($mora > 0 ? 'warn' : 'good', $text . '.');
    }

    if ($p['unconfigured'] > 0) {
      $lines[] = self::line('warn', self::count($p['unconfigured'], 'contrato sin', 'contratos sin') . ' servicios configurados: no se pueden revisar hasta configurarlos.');
    }
    return $lines;
  }

  /** @return array<int,array{tone:string,text:string}> */
  public static function preventive(array $data): array
  {
    $p = $data['pending'];
    $r = $data['reviews'];
    $q = $data['quotes'];
    $lines = [self::compliance('preventiva', $data)];

    $toDate = $p['overdue'] + $p['month'];
    if ($toDate > 0) {
      $lines[] = self::line($p['overdue'] > 0 ? 'bad' : 'warn', self::count($toDate, 'revisión pendiente', 'revisiones pendientes') . ' a la fecha: ' . $p['overdue'] . ' de meses anteriores y ' . $p['month'] . ' de este mes.');
    }

    $stuck = self::largestQueue((array) ($data['queues'] ?? []));
    if ($stuck !== null) $lines[] = self::line('warn', 'Donde más se frena: ' . $stuck . '.');

    if ($r['total'] > 0) {
      $text = 'De ' . self::count($r['total'], 'revisión', 'revisiones') . ' del periodo, ' . $r['damages'] . ' con daños';
      if ($r['without_quote'] > 0) $text .= ' y ' . $r['without_quote'] . ' de esas sin cotizar';
      $lines[] = self::line($r['without_quote'] > 0 ? 'warn' : 'good', $text . '.');
    }

    if ($q['admin'] > 0 || $q['admin_pending'] > 0) {
      $lines[] = self::line('good', 'Administración ganada ' . MaintenanceMetricsView::money((int) $q['admin']) . ' y ' . MaintenanceMetricsView::money((int) $q['admin_pending']) . ' por ganar.');
    }
    return $lines;
  }

  /** @return array{tone:string,text:string} */
  private static function compliance(string $type, array $data): array
  {
    $figures = $data['stats'][$type]['acumulado'] ?? null;
    if (!is_array($figures)) return self::line('warn', 'Reportes no entregó el cumplimiento; el resto del informe sí está actualizado.');

    $minimum = EmployeeBudgetView::reviewMinimum($type);
    $done = (int) ($figures['realizadas'] ?? 0);
    $missing = EmployeeBudgetView::missingForMinimum($done, (int) ($figures['total'] ?? 0), $minimum);
    $text = 'Cumplimiento acumulado ' . $data['statsYear'] . ': ' . number_format((float) ($figures['porcentaje'] ?? 0), 1, '.', '') . '% frente a un mínimo de ' . $minimum . '%.';
    if ($missing === 0) return self::line('good', $text . ' Cumple.');
    return self::line('bad', $text . ($missing === 1 ? ' Falta 1 revisión.' : " Faltan {$missing} revisiones."));
  }

  private static function largestDebt(array $services): string
  {
    usort($services, static fn(array $a, array $b): int => $b['debt'] <=> $a['debt']);
    return mb_strtolower((string) $services[0]['label'], 'UTF-8');
  }

  private static function largestQueue(array $queues): ?string
  {
    $labels = ['sin_cita' => ['caso sin cita', 'casos sin cita'], 'cita_sin_realizar' => ['cita sin realizar', 'citas sin realizar'], 'sin_enviar' => ['revisión sin enviar', 'revisiones sin enviar']];
    $largest = null;
    $size = 0;
    foreach ($labels as $key => $label) {
      $total = (int) ($queues[$key]['total'] ?? 0);
      if ($total > $size) [$largest, $size] = [$label, $total];
    }
    return $largest === null ? null : self::count($size, $largest[0], $largest[1]);
  }

  private static function count(int $value, string $one, string $many): string
  {
    return $value . ' ' . ($value === 1 ? $one : $many);
  }

  /** @return array{tone:string,text:string} */
  private static function line(string $tone, string $text): array
  {
    return ['tone' => $tone, 'text' => $text];
  }
}
