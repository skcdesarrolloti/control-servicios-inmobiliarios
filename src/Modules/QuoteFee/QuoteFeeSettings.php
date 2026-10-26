<?php

declare(strict_types=1);

namespace SCM\Modules\QuoteFee;

use SCM\Core\Database;

/**
 * % de administración sugerido al enviar una cotización de mantenimiento (Simulacion_Honorarios_SKC.xlsx).
 * honorario = R·p + adicional, con x = máx(0, 1 − R/T):
 *   elipse   adicional = F·(1 − √(1 − k²x²)) / (1 − √(1 − k²))
 *   parábola adicional = F·x²
 * y % sugerido = honorario / R. F se guarda como fracción del SMLMV ("salario", compartido con actas, aseo
 * y reportes) para que suba sola cuando se actualiza el salario.
 * Variables en wp_jet_cct_confi_sistema (funcion/valor).
 */
final class QuoteFeeSettings
{
  public const CURVES = ['elipse' => 'Elipse', 'parabola' => 'Parábola'];
  /** Valores de reparación de la tabla de ejemplo en la configuración. */
  public const PREVIEW_VALUES = [100000, 250000, 500000, 1000000, 2000000, 5000000, 10000000, 15000000];

  private const DEFAULT_F = 30000.0;
  private const DEFAULT_SALARY = 1750905.0;
  private const DEFAULTS = ['honorario_tarifa' => 0.10, 'honorario_transicion' => 10000000.0, 'honorario_forma_k' => 0.8];

  public function __construct(private Database $db) {}

  /** @return array{salario:float,tarifa:float,f_smlmv:float,f:float,transicion:float,k:float,curva:string} */
  public function read(): array
  {
    $keys = ['salario', 'honorario_tarifa', 'honorario_f_smlmv', 'honorario_transicion', 'honorario_forma_k', 'honorario_curva'];
    $stored = [];
    foreach ($this->db->getResults('SELECT funcion, valor FROM `' . $this->db->table('jet_cct_confi_sistema') . '` WHERE funcion IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) as $row) {
      $stored[(string) $row['funcion']] = trim((string) $row['valor']);
    }
    $number = static fn(string $key, float $default): float => isset($stored[$key]) && is_numeric($stored[$key]) ? (float) $stored[$key] : $default;
    $salary = $number('salario', self::DEFAULT_SALARY);
    // Sin configurar todavía: F = $30.000 con el salario vigente.
    $fShare = $number('honorario_f_smlmv', $salary > 0 ? self::DEFAULT_F / $salary : 0.0);
    return self::normalize([
      'salario' => $salary,
      'tarifa' => $number('honorario_tarifa', self::DEFAULTS['honorario_tarifa']),
      'f_smlmv' => $fShare,
      'transicion' => $number('honorario_transicion', self::DEFAULTS['honorario_transicion']),
      'k' => $number('honorario_forma_k', self::DEFAULTS['honorario_forma_k']),
      'curva' => (string) ($stored['honorario_curva'] ?? 'elipse'),
    ]);
  }

  /**
   * Valida lo que llega del formulario (tarifa en %, F y salario en pesos) sin guardar.
   * @param array<string,mixed> $input
   * @return array{salario:float,tarifa:float,f_smlmv:float,f:float,transicion:float,k:float,curva:string}
   */
  public static function fromInput(array $input): array
  {
    $number = static function (string $key, string $label) use ($input): float {
      $raw = str_replace(['%', ' ', '$'], '', trim((string) ($input[$key] ?? '')));
      // Pesos con separador de miles (1.750.905) y decimales con coma (0,8).
      $raw = preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) ? str_replace('.', '', $raw) : str_replace(',', '.', $raw);
      if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) throw new \InvalidArgumentException('Revisa el valor de "' . $label . '".');
      return (float) $raw;
    };
    $salary = $number('salario', 'Salario base (SMLMV)');
    $tarifa = $number('tarifa', 'Tarifa proporcional') / 100;
    $f = $number('f', 'Componente inicial (F)');
    $transicion = $number('transicion', 'Transición (T)');
    $k = $number('k', 'Forma elíptica (k)');
    $curva = (string) ($input['curva'] ?? '');
    if ($salary <= 0) throw new \InvalidArgumentException('El salario base debe ser mayor a cero.');
    if ($tarifa >= 1) throw new \InvalidArgumentException('La tarifa proporcional debe ser menor al 100 %.');
    if ($transicion <= 0) throw new \InvalidArgumentException('La transición (T) debe ser mayor a cero.');
    if ($k <= 0 || $k > 1) throw new \InvalidArgumentException('La forma elíptica (k) debe estar entre 0 y 1 (sin incluir el 0).');
    if (!isset(self::CURVES[$curva])) throw new \InvalidArgumentException('Elige la curva: elipse o parábola.');
    return self::normalize(['salario' => $salary, 'tarifa' => $tarifa, 'f_smlmv' => $f / $salary, 'transicion' => $transicion, 'k' => $k, 'curva' => $curva]);
  }

  /**
   * @param array{salario:float,tarifa:float,f_smlmv:float,transicion:float,k:float,curva:string} $config
   * @return array{salario:float,tarifa:float,f_smlmv:float,f:float,transicion:float,k:float,curva:string}
   */
  public function save(array $config, string $actorEmployeeId): array
  {
    $values = [
      'salario' => $config['salario'],
      'honorario_tarifa' => $config['tarifa'],
      'honorario_f_smlmv' => $config['f_smlmv'],
      'honorario_transicion' => $config['transicion'],
      'honorario_forma_k' => $config['k'],
      'honorario_curva' => $config['curva'],
    ];
    $table = $this->db->table('jet_cct_confi_sistema');
    $now = date('Y-m-d H:i:s');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      foreach ($values as $key => $value) {
        $text = is_float($value) ? rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') : (string) $value;
        $exists = $this->db->getRow('SELECT _ID FROM `' . $table . '` WHERE funcion = ? LIMIT 1', [$key]);
        if (is_array($exists)) {
          $pdo->prepare('UPDATE `' . $table . '` SET valor = ?, cct_modified = ? WHERE _ID = ?')->execute([$text, $now, (int) $exists['_ID']]);
        } else {
          $pdo->prepare('INSERT INTO `' . $table . '` (funcion, valor, cct_status, cct_author_id, cct_created, cct_modified) VALUES (?, ?, ?, ?, ?, ?)')->execute([$key, $text, 'publish', (int) $actorEmployeeId, $now, $now]);
        }
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      $pdo->rollBack();
      throw $error;
    }
    return $this->read();
  }

  /** Honorario en pesos para un valor de reparación (sin IVA). */
  public static function fee(array $config, float $repair): float
  {
    if ($repair <= 0) return 0.0;
    $x = max(0.0, 1 - $repair / $config['transicion']);
    if ($config['curva'] === 'parabola') {
      $extra = $config['f'] * $x ** 2;
    } else {
      $k2 = $config['k'] ** 2;
      $extra = $config['f'] * (1 - sqrt(1 - $k2 * $x ** 2)) / (1 - sqrt(1 - $k2));
    }
    return $repair * $config['tarifa'] + $extra;
  }

  /** % de administración sugerido (2 decimales); null sin valor de reparación. */
  public static function suggestedPercent(array $config, float $repair): ?float
  {
    return $repair > 0 ? round(min(100.0, self::fee($config, $repair) / $repair * 100), 2) : null;
  }

  /** El honorario en pesos nunca baja al subir la reparación (misma condición que la celda D8 del Excel). */
  public static function isIncreasing(array $config): bool
  {
    $p = $config['tarifa'];
    $f = $config['f'];
    $t = $config['transicion'];
    if ($config['curva'] === 'parabola') return $p >= 2 * $f / $t;
    $k2 = $config['k'] ** 2;
    if ($k2 >= 1) return $f <= 0;
    return $p >= $f * $k2 / ($t * sqrt(1 - $k2) * (1 - sqrt(1 - $k2)));
  }

  /** @param array<string,mixed> $config */
  private static function normalize(array $config): array
  {
    if (!isset(self::CURVES[$config['curva']])) $config['curva'] = 'elipse';
    if ($config['transicion'] <= 0) $config['transicion'] = self::DEFAULTS['honorario_transicion'];
    if ($config['k'] <= 0 || $config['k'] > 1) $config['k'] = self::DEFAULTS['honorario_forma_k'];
    $config['f'] = $config['f_smlmv'] * $config['salario'];
    return $config;
  }
}
