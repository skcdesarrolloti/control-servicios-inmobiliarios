<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

/**
 * Herramientas › Generar PSE: arma los dos archivos planos de recaudo (primer y segundo periodo del mes)
 * desde el Excel de facturación. Mismo cálculo y formato que herramientas/cargarpse/generar.php:
 *   total por factura = valor + IVA + Admin PH − retención − ReteIVA − ReteICA,
 *   valor con recargo  = valor + round(valor × % / 100) + (total − valor).
 */
final class PsePaymentFile
{
  /** Encabezado fijo del convenio: "01,<NIT>,0,<convenio>,<fecha publicación>,<total líneas>". */
  public const HEADER_PREFIX = '01,900028884,0,48686,';

  /** Columnas de la hoja (0 = A): Anulada, No Factura, …, Valor (E) a AdminPh (J). */
  private const COL_ANULADA = 0;
  private const COL_FACTURA = 1;
  private const COL_VALOR = 4;
  private const COL_IVA = 5;
  private const COL_RETENCION = 6;
  private const COL_RETEIVA = 7;
  private const COL_RETEICA = 8;
  private const COL_ADMINPH = 9;

  /**
   * @param array<int,array<int,mixed>> $rows primera hoja completa; la fila 0 son los encabezados
   * @param array{first:array{pay1:string,pay2:string,pct1:mixed,pct2:mixed,published:string},second:array{pay1:string,pay2:string,pct1:mixed,pct2:mixed,published:string}} $periods fechas Ymd
   * @return array{records:int,annulled:int,without_value:int,files:list<array{name:string,content:string,period:int,published:string,total_first:int,total_second:int}>}
   */
  public static function build(array $rows, array $periods): array
  {
    $first = self::period($periods['first'] ?? [], 'primer');
    $second = self::period($periods['second'] ?? [], 'segundo');

    $lines = [1 => '', 2 => ''];
    $totals = [1 => [0, 0], 2 => [0, 0]];
    // Como el original: el contador arranca en 1 (la línea de encabezado) y suma una por factura.
    $lineCount = 1;
    $annulled = 0;
    $withoutValue = 0;
    foreach (array_slice($rows, 1) as $row) {
      $row = array_values((array) $row);
      if (!self::isFalse($row[self::COL_ANULADA] ?? '')) {
        if (trim((string) ($row[self::COL_ANULADA] ?? '')) !== '') $annulled++;
        continue;
      }
      $invoice = self::text($row[self::COL_FACTURA] ?? '');
      $value = self::pesos($row[self::COL_VALOR] ?? '');
      $extra = self::pesos($row[self::COL_IVA] ?? '') + self::pesos($row[self::COL_ADMINPH] ?? '')
        - self::pesos($row[self::COL_RETENCION] ?? '') - self::pesos($row[self::COL_RETEIVA] ?? '') - self::pesos($row[self::COL_RETEICA] ?? '');

      $amount1 = self::withSurcharge($value, $first['pct1']) + $extra;
      $amount2 = self::withSurcharge($value, $first['pct2']) + $extra;
      $amount3 = self::withSurcharge($value, $second['pct1']) + $extra;
      $amount4 = self::withSurcharge($value, $second['pct2']) + $extra;
      if ($amount1 <= 0) {
        $withoutValue++;
        continue;
      }
      $lineCount++;
      $lines[1] .= '02,' . $invoice . ',' . $invoice . ',' . $first['pay1'] . ',' . $first['pay2'] . ',' . $amount1 . '.00,' . $amount2 . ".00\r\n";
      $lines[2] .= '02,' . $invoice . ',' . $invoice . ',' . $second['pay1'] . ',' . $second['pay2'] . ',' . $amount3 . '.00,' . $amount4 . ".00\r\n";
      $totals[1][0] += $amount1;
      $totals[1][1] += $amount2;
      $totals[2][0] += $amount3;
      $totals[2][1] += $amount4;
    }

    if ($lineCount === 1) {
      throw new \InvalidArgumentException('El Excel no tiene facturas sin anular con valor a pagar. Revisa que la columna A diga "False" y que la columna E tenga el valor.');
    }

    $files = [];
    foreach ([1 => $first, 2 => $second] as $number => $period) {
      $files[] = [
        'name' => $period['published'] . '_' . $number . 'Periodo.txt',
        'content' => self::HEADER_PREFIX . $period['published'] . ',' . $lineCount . "\r\n" . $lines[$number],
        'period' => $number,
        'published' => $period['published'],
        'total_first' => $totals[$number][0],
        'total_second' => $totals[$number][1],
      ];
    }

    return ['records' => $lineCount - 1, 'annulled' => $annulled, 'without_value' => $withoutValue, 'files' => $files];
  }

  /** Días habituales del archivador (cargarpse/descargas) cuando aún no hay una generación previa; 0 = último día. */
  private const DEFAULT_DAYS = ['p1_published' => 1, 'p1_pay1' => 15, 'p1_pay2' => 20, 'p2_published' => 2, 'p2_pay1' => 25, 'p2_pay2' => 0];
  private const DEFAULT_PERCENTS = ['p1_pct1' => '0', 'p1_pct2' => '1', 'p2_pct1' => '2', 'p2_pct2' => '3'];

  /**
   * Valores sugeridos del formulario: los días y porcentajes de la última generación llevados al mes de $today
   * (si la fecha anterior era fin de mes, se usa el fin del mes actual).
   * @param array<string,string> $last campos del formulario guardados en la última generación
   * @return array<string,string>
   */
  public static function suggested(array $last, \DateTimeImmutable $today): array
  {
    $monthEnd = (int) $today->format('t');
    $out = [];
    foreach (self::DEFAULT_DAYS as $key => $defaultDay) {
      $day = $defaultDay;
      $previous = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($last[$key] ?? ''));
      if ($previous instanceof \DateTimeImmutable) {
        $day = (int) $previous->format('j') === (int) $previous->format('t') ? 0 : (int) $previous->format('j');
      }
      $out[$key] = $today->format('Y-m-') . str_pad((string) ($day === 0 ? $monthEnd : min($day, $monthEnd)), 2, '0', STR_PAD_LEFT);
    }
    foreach (self::DEFAULT_PERCENTS as $key => $default) {
      $value = trim((string) ($last[$key] ?? ''));
      $out[$key] = $value !== '' && self::percentValid($value) ? $value : $default;
    }
    return $out;
  }

  private static function percentValid(string $value): bool
  {
    return is_numeric(str_replace(',', '.', str_replace(['%', ' '], '', $value)));
  }

  /** Fecha "Y-m-d" o "Ymd" del formulario a "Ymd". */
  public static function ymd(string $value): string
  {
    $value = trim($value);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: \DateTimeImmutable::createFromFormat('!Ymd', $value);
    if (!$date instanceof \DateTimeImmutable) return '';
    $errors = \DateTimeImmutable::getLastErrors();
    if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return '';
    return $date->format('Ymd');
  }

  /** @param array<string,mixed> $input @return array{pay1:string,pay2:string,pct1:float,pct2:float,published:string} */
  private static function period(array $input, string $label): array
  {
    $out = [
      'pay1' => self::ymd((string) ($input['pay1'] ?? '')),
      'pay2' => self::ymd((string) ($input['pay2'] ?? '')),
      'published' => self::ymd((string) ($input['published'] ?? '')),
      'pct1' => self::percent($input['pct1'] ?? 0),
      'pct2' => self::percent($input['pct2'] ?? 0),
    ];
    foreach (['pay1' => 'la primera fecha de pago', 'pay2' => 'la segunda fecha de pago', 'published' => 'la fecha de publicación'] as $key => $name) {
      if ($out[$key] === '') throw new \InvalidArgumentException('Falta ' . $name . ' del ' . $label . ' periodo.');
    }
    return $out;
  }

  private static function withSurcharge(int $value, float $percent): int
  {
    return $value + (int) round($value * $percent / 100, 0);
  }

  private static function isFalse(mixed $value): bool
  {
    return $value === false || strtolower(trim((string) $value)) === 'false';
  }

  private static function text(mixed $value): string
  {
    if (is_float($value) && floor($value) === $value) return (string) (int) $value;
    return trim((string) $value);
  }

  /**
   * Pesos sin centavos (el original quitaba los tres últimos caracteres: ",00"). Acepta celdas numéricas
   * y textos como "$1834920.00", "$ 1.834.920,00" o "1834920,00".
   */
  private static function pesos(mixed $value): int
  {
    if (is_int($value)) return $value;
    if (is_float($value)) return (int) $value;
    $raw = str_replace(['$', ' ', "\u{00A0}"], '', trim((string) $value));
    if ($raw === '') return 0;
    $lastComma = strrpos($raw, ',');
    $lastDot = strrpos($raw, '.');
    if ($lastComma !== false && $lastDot !== false) {
      $decimal = $lastComma > $lastDot ? ',' : '.';
      $raw = str_replace($decimal === ',' ? '.' : ',', '', $raw);
      $raw = str_replace(',', '.', $raw);
    } elseif ($lastComma !== false) {
      $raw = substr_count($raw, ',') > 1 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
    } elseif ($lastDot !== false && (substr_count($raw, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $raw) === 1)) {
      $raw = str_replace('.', '', $raw);
    }
    return is_numeric($raw) ? (int) (float) $raw : 0;
  }

  private static function percent(mixed $value): float
  {
    $raw = str_replace([' ', '%'], '', trim((string) $value));
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? (float) $raw : 0.0;
  }
}
