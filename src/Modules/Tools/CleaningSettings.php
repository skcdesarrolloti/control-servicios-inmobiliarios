<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Core\Database;

/**
 * Configuración de la calculadora de aseo en las mismas ubicaciones del plugin de WordPress, para que ambos
 * queden sincronizados mientras convivan: variables en wp_jet_cct_confi_sistema (funcion/valor) y tablas en
 * wp_options (aseo_tramos_m2, aseo_transporte, aseo_insumos, arreglos serializados).
 * "salario" es el SMLMV compartido con actas, reportes administrativos y revisiones del panel.
 */
final class CleaningSettings
{
  /** Variables: clave => [etiqueta, por defecto del plugin, porcentaje (se guarda como fracción)]. */
  public const GLOBALS = [
    'salario' => ['Salario base (SMLMV)', 1750905, false],
    'auxilio_transporte' => ['Auxilio de transporte (ley)', 162000, false],
    'prestaciones_sociales' => ['Prestaciones sociales', 0.40, true],
    'factor_prestacional' => ['Factor prestacional (funcionario)', 1.52, false],
    'valor_pasaje_trans' => ['Valor pasaje Transcaribe', 4000, false],
    'iva_servicios' => ['IVA servicios', 0.19, true],
    'margen_inmobiliaria' => ['Margen inmobiliaria', 0.15, true],
    'margen_contratista' => ['Margen contratista', 0.10, true],
  ];

  private const DEFAULT_TABLES = [
    'tramos' => [['desde' => 0, 'hasta' => 40, 'horas' => 2.0], ['desde' => 41, 'hasta' => 80, 'horas' => 3.5], ['desde' => 81, 'hasta' => 120, 'horas' => 4.5], ['desde' => 121, 'hasta' => 200, 'horas' => 6.0]],
    'transporte' => [['rango' => '0', 'valor' => 10000], ['rango' => '1-5', 'valor' => 30000], ['rango' => '5.1-9', 'valor' => 40000], ['rango' => '10-19', 'valor' => 60000], ['rango' => '20-25', 'valor' => 70000], ['rango' => '26-30', 'valor' => 80000], ['rango' => '30+', 'valor' => 90000]],
    'insumos' => [['nombre' => 'Detergente multiusos', 'unidad' => 'Litro', 'costo' => 24000, 'consumo' => 0.005], ['nombre' => 'Desengrasante', 'unidad' => 'Litro', 'costo' => 12000, 'consumo' => 0.003], ['nombre' => 'Desinfectante', 'unidad' => 'Litro', 'costo' => 13000, 'consumo' => 0.004], ['nombre' => 'Limpiavidrios', 'unidad' => 'Litro', 'costo' => 10000, 'consumo' => 0.002], ['nombre' => 'Bolsas de basura', 'unidad' => 'Unidad', 'costo' => 1500, 'consumo' => 0.004]],
  ];
  private const OPTION_NAMES = ['tramos' => 'aseo_tramos_m2', 'transporte' => 'aseo_transporte', 'insumos' => 'aseo_insumos'];

  public function __construct(private Database $db) {}

  /** @return array<string,mixed> */
  public function read(): array
  {
    $config = [];
    $stored = [];
    $table = $this->db->table('jet_cct_confi_sistema');
    $keys = array_keys(self::GLOBALS);
    foreach ($this->db->getResults('SELECT funcion, valor FROM `' . $table . '` WHERE funcion IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) as $row) {
      $stored[(string) $row['funcion']] = (string) $row['valor'];
    }
    foreach (self::GLOBALS as $key => [, $default]) {
      $config[$key] = isset($stored[$key]) && is_numeric($stored[$key]) ? (float) $stored[$key] : (float) $default;
    }
    foreach (self::OPTION_NAMES as $key => $option) {
      $row = $this->db->getRow('SELECT option_value FROM `' . $this->db->table('options') . '` WHERE option_name = ? LIMIT 1', [$option]);
      $value = is_array($row) ? @unserialize((string) $row['option_value'], ['allowed_classes' => false]) : false;
      $config[$key] = self::normalizeTable($key, is_array($value) && $value !== [] ? $value : self::DEFAULT_TABLES[$key]);
    }
    return $config;
  }

  /**
   * Valida y guarda; devuelve la configuración leída de nuevo.
   * @param array<string,mixed> $input variables (porcentajes en %) y tablas tal como llegan del formulario
   * @return array<string,mixed>
   */
  public function save(array $input, string $actorEmployeeId): array
  {
    $globals = [];
    foreach (self::GLOBALS as $key => [$label, , $percent]) {
      $raw = str_replace(['%', ' '], '', str_replace(',', '.', trim((string) ($input[$key] ?? ''))));
      if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) throw new \InvalidArgumentException('Revisa el valor de "' . $label . '".');
      $globals[$key] = $percent ? (float) $raw / 100 : (float) $raw;
    }
    if ($globals['margen_inmobiliaria'] >= 1) throw new \InvalidArgumentException('El margen de la inmobiliaria debe ser menor al 100 %.');
    if ($globals['factor_prestacional'] <= 0) throw new \InvalidArgumentException('El factor prestacional debe ser mayor a cero.');
    if ($globals['salario'] <= 0) throw new \InvalidArgumentException('El salario base debe ser mayor a cero.');

    $tables = [];
    foreach (array_keys(self::OPTION_NAMES) as $key) {
      $tables[$key] = self::normalizeTable($key, (array) ($input[$key] ?? []));
      if ($tables[$key] === []) throw new \InvalidArgumentException('La tabla de ' . $key . ' necesita al menos una fila.');
    }
    foreach ($tables['tramos'] as $row) {
      if ($row['hasta'] < $row['desde'] || $row['horas'] <= 0) throw new \InvalidArgumentException('Cada tramo necesita "hasta" mayor o igual a "desde" y horas mayores a cero.');
    }
    if (count(array_unique(array_column($tables['transporte'], 'rango'))) !== count($tables['transporte'])) throw new \InvalidArgumentException('Hay rangos de transporte repetidos.');

    $now = date('Y-m-d H:i:s');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $table = $this->db->table('jet_cct_confi_sistema');
      foreach ($globals as $key => $value) {
        $exists = $this->db->getRow('SELECT _ID FROM `' . $table . '` WHERE funcion = ? LIMIT 1', [$key]);
        if (is_array($exists)) {
          $this->run('UPDATE `' . $table . '` SET valor = ?, cct_modified = ? WHERE _ID = ?', [self::format($value), $now, (int) $exists['_ID']]);
        } else {
          $this->run('INSERT INTO `' . $table . '` (funcion, valor, cct_status, cct_author_id, cct_created, cct_modified) VALUES (?, ?, ?, ?, ?, ?)', [$key, self::format($value), 'publish', (int) $actorEmployeeId, $now, $now]);
        }
      }
      $options = $this->db->table('options');
      foreach (self::OPTION_NAMES as $key => $option) {
        $rows = array_map(static fn(array $row): array => array_map(static fn($v): string => is_float($v) || is_int($v) ? self::format((float) $v) : (string) $v, $row), $tables[$key]);
        $exists = $this->db->getRow('SELECT option_id FROM `' . $options . '` WHERE option_name = ? LIMIT 1', [$option]);
        if (is_array($exists)) {
          $this->run('UPDATE `' . $options . '` SET option_value = ? WHERE option_id = ?', [serialize($rows), (int) $exists['option_id']]);
        } else {
          $this->run('INSERT INTO `' . $options . '` (option_name, option_value, autoload) VALUES (?, ?, ?)', [$option, serialize($rows), 'yes']);
        }
      }
      $pdo->commit();
    } catch (\Throwable $error) {
      $pdo->rollBack();
      throw $error;
    }
    return $this->read();
  }

  /**
   * @param array<int|string,mixed> $rows
   * @return list<array<string,mixed>>
   */
  public static function normalizeTable(string $key, array $rows): array
  {
    $number = static fn($value): float => is_numeric($n = str_replace(',', '.', trim((string) $value))) ? (float) $n : 0.0;
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      if ($key === 'tramos') {
        if (trim((string) ($row['hasta'] ?? '')) === '') continue;
        $out[] = ['desde' => $number($row['desde'] ?? 0), 'hasta' => $number($row['hasta']), 'horas' => $number($row['horas'] ?? 0)];
      } elseif ($key === 'transporte') {
        $rango = trim((string) ($row['rango'] ?? ''));
        if ($rango === '') continue;
        $out[] = ['rango' => $rango, 'valor' => $number($row['valor'] ?? 0)];
      } else {
        $nombre = trim((string) ($row['nombre'] ?? ''));
        if ($nombre === '') continue;
        $out[] = ['nombre' => $nombre, 'unidad' => trim((string) ($row['unidad'] ?? '')), 'costo' => $number($row['costo'] ?? 0), 'consumo' => $number($row['consumo'] ?? 0)];
      }
    }
    if ($key === 'tramos') usort($out, static fn(array $a, array $b): int => $a['desde'] <=> $b['desde']);
    return $out;
  }

  /** @param array<int,mixed> $args */
  private function run(string $sql, array $args): void
  {
    $this->db->pdo()->prepare($sql)->execute($args);
  }

  private static function format(float $value): string
  {
    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
  }
}
