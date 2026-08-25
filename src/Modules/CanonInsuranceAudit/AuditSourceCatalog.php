<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

final class AuditSourceCatalog
{
  /** @return array<string,string> */
  public static function labels(): array
  {
    return [
      'simi' => 'SIMI',
      'libertador' => 'El Libertador',
      'fianza_bogota' => 'Fianza Bogotá',
      'unifianza' => 'Unifianza',
    ];
  }

  public static function label(string $key): string
  {
    return self::labels()[$key] ?? $key;
  }

  /** @return array{key:string,label:string,period:string,extension:string} */
  public static function identify(string $filename, string $selectedPeriod): array
  {
    $filename = mb_strtolower(basename(trim($filename)), 'UTF-8');
    if (preg_match('/^(simi|libertador|fianza_bogota|unifianza)_(0[1-9]|1[0-2])_(\d{4})\.(xls|xlsx)$/', $filename, $match) !== 1) {
      throw new \RuntimeException(
        'Nombre de archivo no valido. Usa: simi_mes_ano, libertador_mes_ano, fianza_bogota_mes_ano o unifianza_mes_ano.'
      );
    }

    $filePeriod = $match[3] . '-' . $match[2];
    if ($filePeriod !== $selectedPeriod) {
      throw new \RuntimeException(
        'El mes y el ano del archivo no coinciden con el periodo auditado ' . $selectedPeriod . '.'
      );
    }

    return [
      'key' => $match[1],
      'label' => self::label($match[1]),
      'period' => $filePeriod,
      'extension' => $match[4],
    ];
  }

  /** @return array<int,string> */
  public static function examples(string $period): array
  {
    $parts = explode('-', $period);
    $year = preg_match('/^\d{4}$/', (string) ($parts[0] ?? '')) === 1 ? (string) $parts[0] : date('Y');
    $month = preg_match('/^(0[1-9]|1[0-2])$/', (string) ($parts[1] ?? '')) === 1 ? (string) $parts[1] : date('m');
    return [
      "simi_{$month}_{$year}.xls",
      "libertador_{$month}_{$year}.xlsx",
      "fianza_bogota_{$month}_{$year}.xlsx",
      "unifianza_{$month}_{$year}.xls",
    ];
  }
}
