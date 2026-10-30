<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Modules\OwnerCredits\OwnerCreditPromissoryService;

/**
 * Propietarios de un contrato de arrendamiento y su porcentaje de participación. Es la única lectura de copropietarios:
 * créditos, creación del contrato y avisos la comparten.
 *
 * - Propietarios: los del contrato de mandato (1 a 6, columnas `_N`); sin mandato, el propietario del contrato y, si
 *   existen, los `id_propietario_2..6` del contrato con su ficha de propietarios.
 * - Porcentaje (`porcentaje_N`): el del contrato, si cuadra con los propietarios del mandato; si no, el del mandato; si
 *   ninguno suma 100 %, partes iguales (queda marcado para que el liquidador lo muestre en rojo).
 * - Las columnas nuevas pueden no existir todavía: se leen de filas `SELECT *` y, si faltan, se cae a la siguiente fuente.
 */
final class ContractCoOwners
{
  public const MAX = 6;
  /** Tolerancia al leer: 33,33 × 3 = 99,99 cargado a mano también cuenta como 100 %. */
  private const TOLERANCE = 0.05;
  public const SOURCES = [
    'unico' => 'Único propietario',
    'contrato' => 'Contrato de arrendamiento',
    'mandato' => 'Contrato de mandato',
    'partes_iguales' => 'Partes iguales (sin porcentaje registrado)',
  ];

  /**
   * @param array<string,mixed> $contract fila de jet_cct_contratos_arrendamiento
   * @param array<string,mixed>|null $mandate fila de jet_cct_contrato_mandato
   * @param array<string,array<string,mixed>> $profiles fichas de jet_cct_propietarios por `_ID` (para un contrato sin mandato)
   * @return list<array{key:string,index:int,name:string,document:string,email:string,phone:string,owner_id:string,share:float,share_source:string,share_detail:string}>
   */
  public static function resolve(array $contract, ?array $mandate, array $profiles = []): array
  {
    $owners = [];
    if (is_array($mandate)) {
      $count = max(1, min(self::MAX, (int) ($mandate['propietarios'] ?? 1)));
      for ($i = 1; $i <= $count; $i++) {
        // También en persona jurídica el propietario es nombre_N/documento_N (la empresa y su NIT); empresa_N/nit_N
        // son el representante legal, que no es propietario.
        $name = trim((string) ($mandate['nombre_' . $i] ?? ''));
        if ($name === '') continue;
        $document = trim((string) ($mandate['documento_' . $i] ?? ''));
        $ownerId = trim((string) ($mandate['id_propietario_nuevo_' . $i] ?? ''));
        if ($ownerId === '') $ownerId = $i === 1 ? (string) ($contract['id_propietario'] ?? '') : 'doc:' . $document;
        $owners[] = [
          'key' => 'mandato_' . $i, 'index' => $i, 'name' => $name, 'document' => $document,
          'email' => trim((string) ($mandate['correo_' . $i] ?? '')),
          'phone' => self::phone((string) ($mandate['celular_' . $i] ?? ''), (string) ($mandate['indicativo_' . $i] ?? '')),
          'owner_id' => $ownerId,
        ];
      }
    }
    if ($owners === []) {
      $owners[] = [
        'key' => 'contrato', 'index' => 1, 'name' => trim((string) ($contract['propietario'] ?? '')), 'document' => '',
        'email' => trim((string) ($contract['correo_propietario'] ?? '')),
        'phone' => self::phone((string) ($contract['celular_propietario'] ?? ''), (string) ($contract['indicativo_propietario'] ?? '')),
        'owner_id' => (string) ($contract['id_propietario'] ?? ''),
      ];
      // Sin mandato: los copropietarios registrados en el contrato, con los datos de su ficha.
      for ($i = 2; $i <= self::MAX; $i++) {
        $id = trim((string) ($contract['id_propietario_' . $i] ?? ''));
        $profile = $id !== '' ? ($profiles[$id] ?? null) : null;
        if (!is_array($profile)) continue;
        $name = trim((string) ($profile['nombre'] ?? '')) ?: trim((string) ($profile['nombre_juridico'] ?? ''));
        if ($name === '') continue;
        $owners[] = [
          'key' => 'contrato_' . $i, 'index' => $i, 'name' => $name,
          'document' => trim((string) ($profile['documento'] ?? '')) ?: trim((string) ($profile['documento_juridico'] ?? '')),
          'email' => trim((string) ($profile['correo'] ?? '')),
          'phone' => self::phone((string) ($profile['celular'] ?? ''), (string) ($profile['indicativo'] ?? '')),
          'owner_id' => $id,
        ];
      }
    }
    return self::withShares($owners, $contract, $mandate);
  }

  /**
   * Agrega `share` (0..1), `share_source` y `share_detail` a cada propietario.
   * @param list<array<string,mixed>> $owners
   * @return list<array<string,mixed>>
   */
  private static function withShares(array $owners, array $contract, ?array $mandate): array
  {
    $count = count($owners);
    if ($count === 1) return [$owners[0] + ['share' => 1.0, 'share_source' => 'unico', 'share_detail' => '']];
    $indexes = array_map(static fn(array $owner): int => (int) $owner['index'], $owners);
    $reasons = [];
    $reason = '';
    $shares = null;
    $source = 'partes_iguales';
    if (self::contractMatches($contract, $owners)) {
      $shares = self::percentages($contract, $indexes, $reason);
      if ($shares !== null) $source = 'contrato';
      elseif ($reason !== '') $reasons[] = 'contrato: ' . $reason;
    }
    if ($shares === null && is_array($mandate)) {
      $shares = self::percentages($mandate, $indexes, $reason);
      if ($shares !== null) $source = 'mandato';
      elseif ($reason !== '') $reasons[] = 'mandato: ' . $reason;
    }
    if ($shares === null) {
      $shares = array_fill_keys($indexes, 1 / $count);
      $reasons = $reasons ?: ['sin porcentaje registrado'];
    }
    return array_map(static fn(array $owner): array => $owner + [
      'share' => (float) $shares[(int) $owner['index']],
      'share_source' => $source,
      'share_detail' => $source === 'partes_iguales' ? implode('; ', $reasons) : '',
    ], $owners);
  }

  /**
   * El porcentaje del contrato vale si sus propietarios son los mismos del mandato: misma cantidad (si la registra) y
   * mismos ids de ficha donde ambos los tienen.
   * @param list<array<string,mixed>> $owners
   */
  private static function contractMatches(array $contract, array $owners): bool
  {
    $count = trim((string) ($contract['propietarios'] ?? ''));
    if ($count !== '' && (int) $count !== count($owners)) return false;
    foreach ($owners as $owner) {
      $index = (int) $owner['index'];
      $id = trim((string) ($contract[$index === 1 ? 'id_propietario' : 'id_propietario_' . $index] ?? ''));
      $ownerId = (string) $owner['owner_id'];
      if ($id !== '' && $ownerId !== '' && !str_starts_with($ownerId, 'doc:') && $id !== $ownerId) return false;
    }
    return true;
  }

  /**
   * Porcentajes `porcentaje_N` de la fila para los índices dados, como fracción (0..1). Acepta "50", "50 %", "33,34" y
   * fracciones (0.5) si todas lo son. Cada uno debe ser mayor que 0 y la suma 100 % (± 0,05).
   * @param list<int> $indexes
   * @return array<int,float>|null índice => fracción
   */
  public static function percentages(array $row, array $indexes, ?string &$reason = null): ?array
  {
    $reason = '';
    // Sin ningún porcentaje cargado no hay nada que explicar: simplemente no está registrado.
    $any = array_filter($indexes, static fn(int $i): bool => trim((string) ($row['porcentaje_' . $i] ?? '')) !== '') !== [];
    if (!$any || $indexes === []) return null;
    $values = [];
    foreach ($indexes as $index) {
      $value = self::number($row['porcentaje_' . $index] ?? '');
      if ($value === null) {
        $reason = 'falta el porcentaje del propietario ' . $index;
        return null;
      }
      $values[$index] = $value;
    }
    $sum = array_sum($values);
    // Fracciones (0,5 + 0,5): se pasan a porcentaje.
    if (max($values) <= 1.0 && abs($sum - 1.0) <= self::TOLERANCE / 100) {
      $values = array_map(static fn(float $value): float => $value * 100, $values);
      $sum = array_sum($values);
    }
    if (min($values) <= 0) {
      $reason = 'hay un porcentaje en 0';
      return null;
    }
    if (abs($sum - 100) > self::TOLERANCE) {
      $reason = 'los porcentajes suman ' . rtrim(rtrim(number_format($sum, 2, ',', '.'), '0'), ',') . ' %';
      return null;
    }
    return array_map(static fn(float $value): float => $value / $sum, $values);
  }

  /** "33,34" o "33.34 %" → 33.34; vacío o inválido → null. */
  public static function number($value): ?float
  {
    $text = str_replace(['%', ' '], '', trim((string) $value));
    if ($text === '') return null;
    if (str_contains($text, ',')) $text = str_replace(['.', ','], ['', '.'], $text);
    return is_numeric($text) ? (float) $text : null;
  }

  /** Reparto en partes iguales con 2 decimales; el centavo que sobra va al primero (33,34 / 33,33 / 33,33). @return list<float> */
  public static function equalSplit(int $count): array
  {
    if ($count <= 0) return [];
    $base = floor(10000 / $count) / 100;
    $split = array_fill(0, $count, $base);
    $split[0] = round(100 - $base * ($count - 1), 2);
    return $split;
  }

  /** Mismo documento sin espacios, puntos, guiones ni dígito de verificación del NIT. */
  public static function sameDocument(string $a, string $b): bool
  {
    $a = OwnerCreditPromissoryService::normalizeDocument($a);
    return $a !== '' && $a === OwnerCreditPromissoryService::normalizeDocument($b);
  }

  /** Celular con indicativo en dígitos (+57 314 5106244 → 573145106244). */
  public static function phone(string $phone, string $prefix): string
  {
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    $prefixDigits = preg_replace('/\D/', '', $prefix) ?? '';
    if ($digits === '') return '';
    if (str_starts_with(trim($phone), '+') || $prefixDigits === '' || str_starts_with($digits, $prefixDigits)) return $digits;
    return $prefixDigits . $digits;
  }

  /** "30 %" o "33,34 %". */
  public static function percentLabel(float $share): string
  {
    return rtrim(rtrim(number_format($share * 100, 2, ',', '.'), '0'), ',') . ' %';
  }
}
