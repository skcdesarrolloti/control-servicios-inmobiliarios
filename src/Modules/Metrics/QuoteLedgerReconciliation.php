<?php

declare(strict_types=1);

namespace SCM\Modules\Metrics;

use SCM\Modules\ContractMovements\ContractMovementsReport;
use SCM\Support\LegacyXlsReader;
use Shuchkin\SimpleXLSX;

/**
 * Concilia el auxiliar contable de administración (AUI) con las cotizaciones del sistema.
 *
 * Cada movimiento del auxiliar (crédito por inmueble) se busca, en este orden:
 * 1. Cotización del mismo inmueble con la misma administración (sin IVA).
 * 2. Cotización aprobada con el mismo valor aunque el inmueble no coincida (sin inmueble u otro),
 *    con respuesta cercana a la fecha contable (±120 días).
 * 3. Suma de los movimientos del inmueble (cuotas o abonos) contra una cotización.
 * 4. Si nada cuadra, la aprobada del inmueble más parecida (diferencia) o "sin cotización".
 * También lista las aprobadas del periodo del auxiliar que no tienen movimiento.
 */
final class QuoteLedgerReconciliation
{
  public const MAX_ROWS = 5000;
  private const WINDOW_DAYS = 120;

  /**
   * @return list<array{fila:int,transaccion:string,fecha:string,inmueble:string,valor:int}>
   */
  public function parse(string $path, string $originalName): array
  {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'xls') {
      $book = new LegacyXlsReader($path);
      if (!$book->success()) {
        throw new \RuntimeException('No se pudo leer el archivo XLS: ' . (string) $book->error());
      }
      $rows = $book->rows(0, self::MAX_ROWS + 10);
    } elseif ($extension === 'xlsx') {
      $book = SimpleXLSX::parseFile($path);
      if (!$book instanceof SimpleXLSX) {
        throw new \RuntimeException('No se pudo leer el archivo XLSX: ' . (string) SimpleXLSX::parseError());
      }
      $rows = $book->rows(0, self::MAX_ROWS + 10);
    } else {
      throw new \RuntimeException('Sube el auxiliar en formato .xls o .xlsx.');
    }

    $columns = null;
    $entries = [];
    foreach ($rows as $index => $row) {
      $row = array_map(static fn($cell): string => trim((string) $cell), (array) $row);
      if ($columns === null) {
        $normalized = array_map(static fn(string $cell): string => mb_strtolower(preg_replace('/[^a-z0-9]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $cell) ?: $cell) ?? '', 'UTF-8'), $row);
        $inm = array_search('noinm', $normalized, true);
        $credit = array_search('credito', $normalized, true);
        $date = array_search('fecha', $normalized, true);
        if ($inm !== false && $credit !== false && $date !== false) {
          $tx = array_search('notransaccion', $normalized, true);
          $columns = ['inm' => $inm, 'credito' => $credit, 'fecha' => $date, 'tx' => $tx];
        }
        continue;
      }
      $property = trim((string) ($row[$columns['inm']] ?? ''));
      $value = ContractMovementsReport::parseMoney($row[$columns['credito']] ?? '');
      $date = self::parseDate((string) ($row[$columns['fecha']] ?? ''));
      if ($property === '' || $value <= 0 || $date === null) {
        continue;
      }
      $entries[] = [
        'fila' => $index + 1,
        'transaccion' => $columns['tx'] !== false ? trim((string) ($row[$columns['tx']] ?? '')) : '',
        'fecha' => $date,
        'inmueble' => $property,
        'valor' => $value,
      ];
    }
    if ($columns === null) {
      throw new \RuntimeException('No se encontraron las columnas "Fecha", "Credito" y "No. Inm" del auxiliar.');
    }
    if ($entries === []) {
      throw new \RuntimeException('El auxiliar no tiene movimientos con inmueble, fecha y valor.');
    }
    return $entries;
  }

  /** Acepta d/m/Y, Y-m-d (con o sin hora) y el número de serie de Excel. */
  public static function parseDate(string $raw): ?string
  {
    $raw = trim($raw);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $raw, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
      return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
      return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^\d{5}(\.\d+)?$/', $raw) === 1) {
      return gmdate('Y-m-d', ((int) $raw - 25569) * 86400);
    }
    return null;
  }

  /**
   * @param list<array{fila:int,transaccion:string,fecha:string,inmueble:string,valor:int}> $entries
   * @param list<array<string,mixed>> $quotes filas del snapshot de QuoteMetricsReport (asociativas)
   * @return array<string,mixed>
   */
  public function reconcile(array $entries, array $quotes): array
  {
    $byProperty = [];
    foreach ($quotes as $quote) {
      foreach (array_unique(array_filter([(string) $quote['inmueble'], (string) ($quote['inmueble_caso'] ?? '')])) as $property) {
        $byProperty[$property][] = $quote;
      }
    }
    $used = [];
    $results = [];

    // 1. Mismo inmueble y misma administración.
    foreach ($entries as $key => $entry) {
      foreach ($byProperty[$entry['inmueble']] ?? [] as $quote) {
        if (!isset($used[$quote['id']]) && (int) $quote['admin'] === $entry['valor']) {
          $used[$quote['id']] = true;
          $results[$key] = ['estado' => (string) $quote['inmueble'] === $entry['inmueble'] ? 'coincide' : 'coincide_inmueble_caso', 'quote' => $quote];
          break;
        }
      }
    }
    // 2. Mismo valor en una aprobada sin inmueble o de otro inmueble, cerca de la fecha contable.
    foreach ($entries as $key => $entry) {
      if (isset($results[$key])) continue;
      $best = null;
      foreach ($quotes as $quote) {
        if (isset($used[$quote['id']]) || $quote['estado'] !== 'Aprobada' || (int) $quote['admin'] !== $entry['valor']) continue;
        $reference = $quote['respuesta'] ?: ($quote['sugerida'] ?: $quote['fecha']);
        if (!$reference) continue;
        $days = abs(strtotime($reference) - strtotime($entry['fecha'])) / 86400;
        if ($days <= self::WINDOW_DAYS && ($best === null || $days < $best[1])) $best = [$quote, $days];
      }
      if ($best !== null) {
        $used[$best[0]['id']] = true;
        $results[$key] = ['estado' => QuoteMetricsReport::missingProperty((string) $best[0]['inmueble']) ? 'coincide_sin_inmueble' : 'coincide_otro_inmueble', 'quote' => $best[0]];
      }
    }
    // 3. Suma de movimientos del inmueble (cuotas/abonos). 4. Diferencia o sin cotización.
    $pending = [];
    foreach ($entries as $key => $entry) {
      if (!isset($results[$key])) $pending[$entry['inmueble']][] = $key;
    }
    foreach ($pending as $property => $keys) {
      $sum = array_sum(array_map(static fn(int $key): int => $entries[$key]['valor'], $keys));
      $candidates = array_values(array_filter($byProperty[$property] ?? [], static fn(array $quote): bool => !isset($used[$quote['id']])));
      $match = null;
      foreach ($candidates as $quote) {
        if ((int) $quote['admin'] === $sum && count($keys) > 1) { $match = $quote; break; }
      }
      if ($match !== null) {
        $used[$match['id']] = true;
        foreach ($keys as $key) $results[$key] = ['estado' => 'coincide_suma', 'quote' => $match, 'suma' => $sum];
        continue;
      }
      $approved = array_values(array_filter($candidates, static fn(array $quote): bool => $quote['estado'] === 'Aprobada'));
      usort($approved, static fn(array $a, array $b): int => abs((int) $a['admin'] - $sum) <=> abs((int) $b['admin'] - $sum));
      foreach ($keys as $key) {
        $results[$key] = $approved !== []
          ? ['estado' => 'diferencia', 'quote' => $approved[0], 'suma' => $sum]
          : ['estado' => 'sin_cotizacion', 'quote' => null, 'suma' => $sum];
      }
      if ($approved !== []) $used[$approved[0]['id']] = true;
    }

    $rows = [];
    foreach ($entries as $key => $entry) {
      $result = $results[$key];
      $quote = $result['quote'];
      $rows[] = $entry + [
        'estado' => $result['estado'],
        'suma_inmueble' => $result['suma'] ?? null,
        'cotizacion' => $quote['id'] ?? null,
        'ticket' => $quote['ticket'] ?? '',
        'inmueble_sistema' => $quote['inmueble'] ?? '',
        'inmueble_caso' => $quote['inmueble_caso'] ?? '',
        'estado_cotizacion' => $quote['estado'] ?? '',
        'admin_sistema' => isset($quote['admin']) ? (int) $quote['admin'] : null,
        'respuesta' => $quote['respuesta'] ?? null,
        'sugerida' => $quote['sugerida'] ?? null,
        'sugerida_fuente' => $quote['sugerida_fuente'] ?? '',
      ];
    }

    // Aprobadas con respuesta en los meses del auxiliar que no tienen movimiento.
    $months = array_unique(array_map(static fn(array $entry): string => substr($entry['fecha'], 0, 7), $entries));
    $missing = array_values(array_filter($quotes, static fn(array $quote): bool => $quote['estado'] === 'Aprobada'
      && !isset($used[$quote['id']]) && $quote['respuesta'] && in_array(substr((string) $quote['respuesta'], 0, 7), $months, true)));
    usort($missing, static fn(array $a, array $b): int => strcmp((string) $b['respuesta'], (string) $a['respuesta']));

    $counts = array_count_values(array_column($rows, 'estado'));
    return [
      'resumen' => [
        'movimientos' => count($entries),
        'total_auxiliar' => array_sum(array_column($entries, 'valor')),
        'conciliados' => ($counts['coincide'] ?? 0) + ($counts['coincide_inmueble_caso'] ?? 0) + ($counts['coincide_sin_inmueble'] ?? 0) + ($counts['coincide_otro_inmueble'] ?? 0) + ($counts['coincide_suma'] ?? 0),
        'por_estado' => $counts,
        'desde' => min(array_column($entries, 'fecha')),
        'hasta' => max(array_column($entries, 'fecha')),
      ],
      'filas' => $rows,
      'aprobadas_sin_movimiento' => array_map(static fn(array $quote): array => [
        'cotizacion' => $quote['id'], 'ticket' => $quote['ticket'], 'inmueble' => $quote['inmueble'], 'respuesta' => $quote['respuesta'],
        'admin' => (int) $quote['admin'], 'destinatario' => $quote['destinatario'], 'categoria' => $quote['categoria'],
      ], $missing),
    ];
  }
}
