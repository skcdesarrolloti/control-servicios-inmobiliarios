<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

/**
 * Herramientas › Calculadora de aseo: misma fórmula del plugin "Calculadora de Aseo Universal V6.5"
 * (calibrado con el Excel). Cambios: los m² entre dos tramos (p. ej. 40,5 con tramos 0–40 y 41–80) ya no
 * quedan en 0 horas, y el desglose para el cliente reparte la base para que las líneas sumen el subtotal.
 */
final class CleaningCalculator
{
  /** Recargo por jornada sobre la hora. */
  public const JORNADAS = [
    'Jornada Diurna' => 0.0,
    'Jornada Nocturna' => 0.35,
    'Dominical/Festivo Diurno' => 0.75,
    'Dominical/Festivo Nocturno' => 1.10,
  ];

  /** Recargo por grado de dificultad (0 a 6). */
  public const DIFICULTAD = [0 => 0.0, 1 => 0.05, 2 => 0.10, 3 => 0.20, 4 => 0.30, 5 => 0.45, 6 => 0.60];

  /** Grado desde el que se exige visita técnica o registro fotográfico. */
  public const PROTOCOL_LEVEL = 4;

  public const WORKERS = [
    'contratista' => 'Contratista (sin prestaciones · costos reales)',
    'funcionario' => 'Funcionario de planta (con prestaciones · factor prestacional)',
  ];

  /**
   * @param array{salario:float,auxilio_transporte:float,prestaciones_sociales:float,iva_servicios:float,margen_inmobiliaria:float,margen_contratista:float,factor_prestacional:float,valor_pasaje_trans:float,tramos:list<array{desde:float,hasta:float,horas:float}>,transporte:list<array{rango:string,valor:float}>,insumos:list<array{nombre:string,unidad:string,costo:float,consumo:float}>} $config
   * @param array<string,mixed> $input m2, jornada, distancia (rango), dificultad, trabajador
   * @return array<string,mixed>
   */
  public static function calculate(array $config, array $input): array
  {
    $m2 = (float) str_replace(',', '.', (string) ($input['m2'] ?? '0'));
    if ($m2 <= 0) throw new \InvalidArgumentException('Ingresa los metros cuadrados del inmueble.');
    $jornada = (string) ($input['jornada'] ?? 'Jornada Diurna');
    if (!array_key_exists($jornada, self::JORNADAS)) throw new \InvalidArgumentException('Selecciona una jornada válida.');
    $dificultad = (int) ($input['dificultad'] ?? 0);
    if ($dificultad < 0 || $dificultad > 6) throw new \InvalidArgumentException('La dificultad va de 0 a 6.');
    $trabajador = (string) ($input['trabajador'] ?? 'contratista');
    if (!array_key_exists($trabajador, self::WORKERS)) throw new \InvalidArgumentException('Selecciona el tipo de trabajador.');
    $rango = (string) ($input['distancia'] ?? '');
    $tarifa = null;
    foreach ($config['transporte'] as $row) {
      if ((string) $row['rango'] === $rango) $tarifa = (float) $row['valor'];
    }
    if ($tarifa === null) throw new \InvalidArgumentException('Selecciona la distancia.');
    if ((float) $config['margen_inmobiliaria'] >= 1) throw new \InvalidArgumentException('El margen de la inmobiliaria debe ser menor al 100 %. Revisa la configuración.');

    $horas = self::hours($config['tramos'], $m2);
    $valorHoraBase = (float) $config['salario'] / 240;
    $recargo = 1 + self::JORNADAS[$jornada] + self::DIFICULTAD[$dificultad];
    $valorHoraReal = $valorHoraBase * (1 + (float) $config['prestaciones_sociales']);
    $costoMO = self::ceilThousand($horas * $valorHoraReal * $recargo);
    // Auxilio de transporte del trabajador: 50 % de dos pasajes (ida y regreso).
    $auxilio = ((float) $config['valor_pasaje_trans'] * 2) * 0.50;
    // Como el plugin: al contratista se le cobra solo el auxilio; con funcionario, la tarifa por distancia ida y regreso.
    $costoTrans = $trabajador === 'contratista' ? $auxilio : $tarifa * 2;

    $insumoM2 = 0.0;
    foreach ($config['insumos'] as $insumo) $insumoM2 += (float) $insumo['costo'] * (float) $insumo['consumo'];
    $factorInsumos = $dificultad >= 5 ? 1.5 : 1.0;
    $costoIns = self::ceilThousand($m2 * $factorInsumos * $insumoM2);

    $costoDirecto = $costoMO + $costoTrans + $costoIns;
    $precioContratista = $costoDirecto * (1 + (float) $config['margen_contratista']);
    $base = self::ceilThousand($precioContratista / (1 - (float) $config['margen_inmobiliaria']));
    $iva = round($base * (float) $config['iva_servicios']);
    $total = $base + $iva;

    if ($trabajador === 'contratista') {
      $realMO = $costoMO;
      $realTrans = $auxilio;
    } else {
      $realMO = $costoMO / (float) $config['factor_prestacional'];
      $realTrans = $auxilio;
    }
    $costoReal = $trabajador === 'contratista' ? $costoMO + $costoTrans + $costoIns : $realMO + $realTrans + $costoIns;

    return [
      'input' => ['m2' => $m2, 'jornada' => $jornada, 'distancia' => $rango, 'dificultad' => $dificultad, 'trabajador' => $trabajador],
      'horas' => $horas,
      'valor_hora_base' => $valorHoraBase,
      'recargo' => $recargo,
      'protocolo' => $dificultad >= self::PROTOCOL_LEVEL,
      'mano_obra' => $costoMO,
      'transporte' => $costoTrans,
      'insumos' => $costoIns,
      'costo_directo' => $costoDirecto,
      'precio_contratista' => $precioContratista,
      'base' => $base,
      'utilidad_inmobiliaria' => $base - $precioContratista,
      'iva' => $iva,
      'total' => $total,
      'real_mano_obra' => $realMO,
      'real_transporte' => $realTrans,
      'utilidad_real' => $base - $costoReal,
      'cliente' => self::clientLines($costoMO, $costoTrans, $costoIns, $base),
    ];
  }

  /**
   * Horas por tramo de m²: el primer tramo cuyo "hasta" cubre el área (así 40,5 m² cae en 41–80 y no en 0 horas).
   * Por encima del último tramo se suma una hora por cada 50 m² adicionales, como en el plugin.
   * @param list<array{desde:float,hasta:float,horas:float}> $tramos
   */
  public static function hours(array $tramos, float $m2): float
  {
    if ($tramos === []) throw new \InvalidArgumentException('No hay tramos de m² configurados.');
    usort($tramos, static fn(array $a, array $b): int => [(float) $a['hasta'], (float) $a['desde']] <=> [(float) $b['hasta'], (float) $b['desde']]);
    foreach ($tramos as $tramo) {
      if ($m2 <= (float) $tramo['hasta']) return (float) $tramo['horas'];
    }
    $last = $tramos[count($tramos) - 1];
    return (float) $last['horas'] + (($m2 - (float) $last['hasta']) / 50);
  }

  /**
   * Desglose para el cliente: mano de obra, transporte e insumos con la base (que incluye los márgenes)
   * repartida en proporción a su costo, de modo que las tres líneas sumen el subtotal.
   * @return array{servicio:float,transporte:float,insumos:float}
   */
  public static function clientLines(float $mo, float $trans, float $ins, float $base): array
  {
    $direct = $mo + $trans + $ins;
    if ($direct <= 0) return ['servicio' => $base, 'transporte' => 0.0, 'insumos' => 0.0];
    $transporte = round($base * $trans / $direct);
    $insumos = round($base * $ins / $direct);
    return ['servicio' => $base - $transporte - $insumos, 'transporte' => $transporte, 'insumos' => $insumos];
  }

  private static function ceilThousand(float $value): float
  {
    return ceil($value / 1000) * 1000;
  }
}
