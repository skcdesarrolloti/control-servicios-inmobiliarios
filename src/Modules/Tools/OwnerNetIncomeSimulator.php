<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

/**
 * Herramientas › Simulador de ingreso neto del propietario (igual al del panel comercial). Simulador del ingreso neto de un inmueble con las tarifas de `jet_cct_confi_sistema`. Replica el plugin "Calculadora
 * de comisión, garantía inmobiliaria y seguro de servicios públicos" (herramientas/calculadora-seguros-comisiones):
 * comisión sobre el canon total (canon + administración) con IVA, garantía según la aseguradora y la póliza de Berkley o Seguros Mundial.
 * owner-net-income.js hace el mismo cálculo en pantalla; aquí se repite para el PDF sin confiar en los totales del navegador.
 *
 * El resultado usa las unidades de la herramienta: comisión y garantía por mes, seguro e intereses por sus 12 meses.
 */
final class OwnerNetIncomeSimulator
{
  /** Tipo de garantía: con una de las aseguradoras de SuCasa (clave `defecto` del plugin) o con la póliza de Berkley o Seguros Mundial. */
  public const MODALITIES = ['defecto' => 'Aseguradora', 'berkley' => 'Berkley', 'mundial' => 'Seguros Mundial'];
  /** Aseguradoras de la garantía con aseguradora: [nombre, clave de la tasa de garantía, clave de la tasa de servicios]. */
  public const INSURERS = [
    ['El Libertador', 'tasa_libertador_seguro', 'tasa_libertador_servicios'],
    ['Unifianza', 'tasa_unifianza_seguro', 'tasa_unifianza_servicios'],
    ['Suramericana', 'tasa_suramericana_seguro', ''],
    ['Fianza Bogotá', 'tasa_fianza_bogota_seguro', 'tasa_fianza_bogota_servicios'],
    ['Seguro propio', 'tasa_seguro_propio_seguro', ''],
    ['Afiancol', 'tasa_afiancol_seguro', 'tasa_afiancol_servicios'],
    ['Zurich', 'tasa_zurich_seguro', 'tasa_zurich_servicios'],
  ];
  /** Filas de `jet_cct_confi_sistema` que lee el simulador (las mismas del plugin). */
  public const RATE_KEYS = [
    'iva', 'tasa_administracion_arriendo', 'tasa_berkley', 'gasto_expedicion_berkley', 'tasa_seguros_mundial', 'gasto_expedicion_seguros_mundial', 'tasa_prestamo_garantias_servicios',
    'tasa_libertador_seguro', 'tasa_unifianza_seguro', 'tasa_suramericana_seguro', 'tasa_fianza_bogota_seguro', 'tasa_seguro_propio_seguro', 'tasa_afiancol_seguro', 'tasa_zurich_seguro',
    'tasa_libertador_servicios', 'tasa_unifianza_servicios', 'tasa_fianza_bogota_servicios', 'tasa_afiancol_servicios', 'tasa_zurich_servicios',
  ];

  /**
   * Tarifas listas para el simulador a partir de las filas `funcion => valor`.
   * @param array<string,string> $rows
   * @return array{iva:float,comision:float,prestamo:float,berkley:array{tasa:float,expedicion:float},mundial:array{tasa:float,expedicion:float},aseguradoras:array<string,array{seguro:float,servicios:float}>}
   */
  public static function rates(array $rows): array
  {
    $rate = static fn(string $key): float => self::rate($rows[$key] ?? '');
    $insurers = [];
    foreach (self::INSURERS as [$name, $guarantee, $services]) $insurers[$name] = ['seguro' => $rate($guarantee), 'servicios' => $services !== '' ? $rate($services) : 0.0];
    return [
      'iva' => $rate('iva'), 'comision' => $rate('tasa_administracion_arriendo'), 'prestamo' => $rate('tasa_prestamo_garantias_servicios'),
      'berkley' => ['tasa' => $rate('tasa_berkley'), 'expedicion' => OwnerPreapprovalService::money($rows['gasto_expedicion_berkley'] ?? '')],
      'mundial' => ['tasa' => $rate('tasa_seguros_mundial'), 'expedicion' => OwnerPreapprovalService::money($rows['gasto_expedicion_seguros_mundial'] ?? '')],
      'aseguradoras' => $insurers,
    ];
  }

  /**
   * @param array<string,mixed> $input canon, administracion, iva_canon, tasa_comision, modalidad, aseguradora, garantia, servicios, valor_asegurado, cuotas
   * @param array<string,mixed> $rates self::rates()
   * @return array{canon:float,administracion:float,comision:float,garantia:float,seguro:float,intereses:float,cuota:float,cuotas:int,modalidad:string,aseguradora:string,tasa_comision:float,detail:array<string,string>,warnings:list<string>}
   */
  public static function simulate(array $input, array $rates): array
  {
    $money = static fn(string $key): float => self::round(min(9999999999.0, OwnerPreapprovalService::money($input[$key] ?? 0)));
    $yes = static fn(string $key): bool => !empty($input[$key]) && $input[$key] !== 'No';
    $modality = array_key_exists((string) ($input['modalidad'] ?? ''), self::MODALITIES) ? (string) $input['modalidad'] : 'defecto';
    $iva = (float) $rates['iva'];
    $canon = $money('canon');
    $admin = $money('administracion');
    $commissionRate = isset($input['tasa_comision']) && trim((string) $input['tasa_comision']) !== '' ? max(0.0, min(100.0, self::rate($input['tasa_comision']))) : (float) $rates['comision'];
    $ivaCanon = $yes('iva_canon') ? self::round($canon * $iva / 100) : 0.0;
    $canonTotal = self::round($canon + $admin);
    $commission = self::round($canonTotal * $commissionRate / 100);
    $commission = self::round($commission + self::round($commission * $iva / 100));
    $result = ['canon' => $canon, 'administracion' => $admin, 'comision' => $commission, 'garantia' => 0.0, 'seguro' => 0.0, 'intereses' => 0.0, 'cuota' => 0.0, 'cuotas' => 0, 'modalidad' => self::MODALITIES[$modality], 'aseguradora' => self::MODALITIES[$modality], 'tasa_comision' => $commissionRate, 'detail' => [], 'warnings' => []];
    $result['detail']['Comisión de administración'] = self::percent($commissionRate) . ' sobre canon + administración' . ($iva > 0 ? ', más IVA ' . self::percent($iva) : '');
    if ($ivaCanon > 0) $result['detail']['IVA del canon'] = self::moneyText($ivaCanon);
    $installments = max(1, min($modality === 'berkley' ? 4 : 12, (int) ($input['cuotas'] ?? 1)));
    $insured = $money('valor_asegurado');
    $services = $yes('servicios');

    if ($modality === 'defecto') {
      $names = array_keys($rates['aseguradoras']);
      $name = in_array($input['aseguradora'] ?? '', $names, true) ? (string) $input['aseguradora'] : ($names[0] ?? '');
      $insurer = $rates['aseguradoras'][$name] ?? ['seguro' => 0.0, 'servicios' => 0.0];
      $result['aseguradora'] = $name;
      if ($yes('garantia')) {
        $result['garantia'] = self::round(($canonTotal + $ivaCanon) * $insurer['seguro'] / 100);
        $result['detail']['Garantía inmobiliaria'] = self::percent($insurer['seguro']) . ' de ' . $name . ' sobre canon + administración';
      }
      if ($services) {
        if ($insured <= 0) $result['warnings'][] = 'Indica el valor asegurado de servicios públicos.';
        elseif ($insurer['servicios'] <= 0) $result['warnings'][] = $name . ' no tiene tasa de servicios públicos configurada.';
        else {
          $premium = self::round($insured * $insurer['servicios'] / 100);
          // Hasta 4 cuotas se dividen sin intereses; desde 5 se financian con la tasa de préstamo de garantías.
          $loan = $installments <= 4 ? ['cuota' => self::round($premium / $installments), 'intereses' => 0.0] : self::loan($premium, $installments, (float) $rates['prestamo']);
          $result = ['seguro' => $premium, 'intereses' => $loan['intereses'], 'cuota' => $loan['cuota'], 'cuotas' => $installments] + $result;
          $result['detail']['Seguro de servicios públicos'] = self::percent($insurer['servicios']) . ' sobre ' . self::moneyText($insured) . ' asegurados';
        }
      }
    } elseif ($modality === 'berkley') {
      $rate = (float) $rates['berkley']['tasa'];
      $premiumCanon = self::round(self::round($canonTotal * 12) * $rate / 100);
      $premiumServices = 0.0;
      if ($services) {
        if ($insured <= 0) $result['warnings'][] = 'Indica el valor asegurado de servicios para Berkley.';
        else $premiumServices = self::round($insured * $rate / 100);
      }
      $premium = self::round($premiumCanon + $premiumServices + (float) $rates['berkley']['expedicion']);
      $result = ['garantia' => self::round($premiumCanon / 12), 'seguro' => $premium, 'cuota' => self::round($premium / $installments), 'cuotas' => $installments] + $result;
      $result['detail']['Póliza Berkley'] = self::percent($rate) . ' sobre el canon anual' . ($premiumServices > 0 ? ' y los servicios asegurados' : '') . ' + expedición ' . self::moneyText((float) $rates['berkley']['expedicion']);
    } else {
      $rate = (float) $rates['mundial']['tasa'];
      $premiumCanon = self::round(self::round(($canonTotal + $ivaCanon) * 12) * $rate / 100);
      $withoutIva = self::round($premiumCanon + (float) $rates['mundial']['expedicion']);
      $premium = self::round($withoutIva + self::round($withoutIva * $iva / 100));
      $result['garantia'] = self::round($premiumCanon / 12);
      $result['detail']['Póliza Seguros Mundial'] = self::percent($rate) . ' sobre el canon anual + expedición ' . self::moneyText((float) $rates['mundial']['expedicion']) . ' + IVA';
      if ($services) {
        $loan = self::loan($premium, $installments, (float) $rates['prestamo']);
        $result = ['seguro' => $premium, 'intereses' => $loan['intereses'], 'cuota' => $loan['cuota'], 'cuotas' => $installments] + $result;
      }
    }
    return $result;
  }

  /** Cuota fija (sistema francés) y los intereses que genera; sin tasa, la prima se divide en partes iguales. @return array{cuota:float,intereses:float} */
  public static function loan(float $amount, int $installments, float $monthlyRate): array
  {
    $installments = max(1, $installments);
    $rate = $monthlyRate / 100;
    $factor = $rate > 0 ? $rate * (1 + $rate) ** $installments / ((1 + $rate) ** $installments - 1) : 1 / $installments;
    $installment = self::round($amount * $factor);
    return ['cuota' => $installment, 'intereses' => self::round($installment * $installments - $amount)];
  }

  /** Porcentaje escrito como 10, "10 %", "2,5" o "0.8". */
  public static function rate($value): float
  {
    $text = str_replace(['%', ' ', "\u{a0}"], '', trim((string) $value));
    if (str_contains($text, ',') && str_contains($text, '.')) $text = str_replace(',', '.', str_replace('.', '', $text));
    else $text = str_replace(',', '.', $text);
    return is_numeric($text) ? max(0.0, (float) $text) : 0.0;
  }

  private static function round(float $value): float
  {
    return round($value, 2);
  }

  private static function percent(float $value): string
  {
    return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',') . ' %';
  }

  private static function moneyText(float $value): string
  {
    return '$' . number_format(round($value), 0, ',', '.');
  }
}
